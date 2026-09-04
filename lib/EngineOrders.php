<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Exchange.php';

/**
 * Limit-order bookkeeping shared by the grid and pmm engines
 * (docs/DESIGN-ENGINES.md §6). Run at the top of every engine tick.
 *
 * The engine owns the order book of its symbol: every resting order is mirrored
 * in `engine_orders`, every BUY fill becomes a FIFO `lots` row, every SELL fill
 * consumes lots and writes one `cycles` row per consumed slice.
 *
 * Idempotency is the whole point of this class. Booking a fill twice would
 * invent inventory and profit out of nothing, so:
 *   - `place()` writes the `engine_orders` row with status SENDING *before* the
 *     HTTP call and updates it afterwards (same discipline as Bot::placeOrder),
 *     so a crash mid-send leaves a row `sync()` can resolve;
 *   - `bookFill()` treats the fill it is handed as the *cumulative* state of the
 *     order and books only the delta against the `filled_qty` / `filled_quote`
 *     already recorded. Re-running `sync()` on the same fill therefore writes
 *     the same numbers and creates no second lot, cycle or trade.
 *
 * Fees follow DESIGN.md §6: a base-asset commission reduces the lot quantity
 * (the cost basis stays the full quote spent, so `lots.price` rises), a quote
 * commission reduces the proceeds, and a commission paid outside the pair (BNB)
 * is valued in USDT and subtracted from the cycle pnl.
 */
final class EngineOrders
{
    /** Statuses a fill/getOrder response may carry through to the row. */
    const TERMINAL_STATUSES = ['FILLED', 'CANCELED', 'REJECTED', 'EXPIRED'];

    /** Exchange method names this class can drive, most preferred first. */
    const M_LIMIT      = ['limitOrder', 'placeLimitOrder', 'limit'];
    const M_CANCEL     = ['cancelOrder', 'cancel'];
    const M_CANCEL_ALL = ['cancelAllOrders', 'cancelOpenOrders', 'cancelAll'];
    const M_OPEN       = ['openOrders', 'getOpenOrders'];

    /**
     * Canonical argument name => accepted parameter-name spellings (lowercased,
     * underscores removed). Lets this class drive whichever signature the
     * exchange implementation chose without guessing a positional order.
     */
    const ARG_ALIASES = [
        'symbol'   => ['symbol', 'pair'],
        'side'     => ['side'],
        'qty'      => ['qty', 'qtystr', 'quantity', 'quantitystr', 'basequantity', 'amount'],
        'price'    => ['price', 'pricestr', 'limitprice'],
        'info'     => ['info', 'symbolinfo', 'filters'],
        'clientid' => ['clientid', 'clientorderid', 'cid', 'origclientorderid'],
        'postonly' => ['postonly', 'maker', 'ismaker'],
        'quote'    => ['quote', 'quoteusdt', 'quoteorderqty'],
    ];

    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var ExchangeInterface */
    private $ex;
    /** @var array parsed symbol info of the engine symbol (flat, Binance::exchangeInfo shape) */
    private $info;
    /** @var string */
    private $symbol;
    /** @var string */
    private $mode;
    /** @var string */
    private $engine;
    /** @var string */
    private $quoteAsset;
    /** @var int|null injectable clock (tests) */
    private $nowMs;
    /** @var bool logged once when the exchange cannot list open orders */
    private $warnedNoOpenOrders = false;
    /**
     * Quote this engine's sleeve may still commit to BUY orders (DESIGN-PORTFOLIO.md §3),
     * or null for the single-engine mode of DESIGN-ENGINES.md, which has no sleeve budget.
     * @var float|null
     */
    private $availableQuote = null;

    /**
     * @param array $info parsed info for the engine symbol; either the flat row
     *                    (['stepSize'=>…]) or the whole [SYMBOL => row] map.
     * @param int|null $nowMs injectable clock for tests (optional, additive)
     */
    public function __construct(array $cfg, Db $db, ExchangeInterface $ex, array $info, ?int $nowMs = null)
    {
        $this->cfg   = $cfg;
        $this->db    = $db;
        $this->ex    = $ex;
        $this->nowMs = $nowMs;

        $this->mode       = isset($cfg['mode']) ? strtolower(trim((string) $cfg['mode'])) : 'paper';
        $this->engine     = isset($cfg['engine']) ? strtolower(trim((string) $cfg['engine'])) : 'grid';
        $this->quoteAsset = isset($cfg['quote_asset']) && (string) $cfg['quote_asset'] !== ''
            ? (string) $cfg['quote_asset'] : 'USDT';
        $this->symbol = isset($cfg['engine_symbol']) ? strtoupper(trim((string) $cfg['engine_symbol'])) : '';
        $this->info   = self::flattenInfo($info, $this->symbol);
        if ($this->symbol === '') {
            $this->symbol = strtoupper((string) ($this->info['symbol'] ?? ''));
        }
    }

    /** The symbol this instance quotes (config `engine_symbol`). */
    public function symbol(): string
    {
        return $this->symbol;
    }

    /* ============================================================= sync */

    /**
     * Reconcile the local live rows of $symbol against the exchange, book every
     * new fill exactly once and hand back a summary.
     *
     * @return array{filled: array, open: array, cancelled: int}
     *         `filled` are the rows whose booked quantity grew during THIS call
     *         (so a repeated sync returns an empty list), `open` the rows still
     *         resting, `cancelled` the number of orders that left the book here
     *         (local rows that turned terminal plus foreign orders cancelled).
     */
    public function sync(string $symbol): array
    {
        $symbol = $this->normSymbol($symbol);
        $exOpen = $this->exchangeOpenOrders($symbol);
        $local  = $this->ownMode($this->db->openEngineOrders($symbol, $this->mode));

        $filled    = [];
        $open      = [];
        $cancelled = 0;
        $known     = [];

        foreach ($local as $row) {
            $cid = (string) $row['client_id'];
            $known[$cid] = true;
            $before = (float) $row['filled_qty'];

            if ($exOpen !== null && isset($exOpen[$cid])) {
                // still resting: only spend a getOrder when the book says more was executed
                $exRow = $exOpen[$cid];
                $exec  = (float) $exRow['executed_qty'];
                if ($exec > $before + self::eps($exec)) {
                    $this->resolve($symbol, $cid, null);
                } else {
                    $patch = [];
                    $st    = strtoupper((string) $exRow['status']);
                    if ($st !== '' && $st !== strtoupper((string) $row['status'])) {
                        $patch['status'] = $st;
                    }
                    if ((string) $exRow['order_id'] !== '' && (string) $row['order_id'] !== (string) $exRow['order_id']) {
                        $patch['order_id'] = (string) $exRow['order_id'];
                    }
                    if ($patch !== []) {
                        $this->db->updateEngineOrder($cid, $patch);
                    }
                }
            } else {
                // absent from the book (or the book is unavailable): ask the exchange directly
                $this->resolve($symbol, $cid, $exOpen === null ? null : 'CANCELED');
            }

            $after = $this->db->engineOrder($cid);
            if ($after === null) {
                continue;
            }
            if ((float) $after['filled_qty'] > $before + self::eps((float) $after['filled_qty'])) {
                $filled[] = $after;
            }
            $st = strtoupper((string) $after['status']);
            if (in_array($st, Db::ENGINE_LIVE_STATUSES, true)) {
                $open[] = $after;
            } elseif ($st !== 'FILLED') {
                $cancelled++;
            }
        }

        // The engine owns the book: anything resting that we do not track is cancelled.
        if ($exOpen !== null) {
            foreach ($exOpen as $cid => $exRow) {
                $cid = (string) $cid;
                if (isset($known[$cid])) {
                    continue;
                }
                Log::warn('engine sync: untracked order on ' . $symbol . ' — cancelling', [
                    'client_id' => $cid,
                    'order_id'  => $exRow['order_id'],
                    'side'      => $exRow['side'],
                    'price'     => $exRow['price'],
                    'orig_qty'  => $exRow['orig_qty'],
                ]);
                if ($this->cancelOnExchange($symbol, $cid)) {
                    $cancelled++;
                }
            }
        }

        return ['filled' => $filled, 'open' => $open, 'cancelled' => $cancelled];
    }

    /**
     * Ask the exchange for one order, book whatever it reports and store its
     * status. $fallbackStatus is used when the response names no terminal state.
     *
     * @return bool true when the row ended in a terminal status
     */
    private function resolve(string $symbol, string $clientId, ?string $fallbackStatus): bool
    {
        $row = $this->db->engineOrder($clientId);
        if ($row === null) {
            return false;
        }
        try {
            $fill = $this->ex->getOrder($symbol, $clientId);
        } catch (BinanceException $e) {
            if ($e->binanceCode !== -2013) {
                // transient: leave the row live, the next sync retries
                Log::warn('engine sync: getOrder ' . $symbol . ' failed: ' . $e->getMessage(), [
                    'client_id' => $clientId, 'code' => $e->binanceCode, 'http' => $e->httpStatus,
                ]);
                return false;
            }
            $fill = null;
        }
        if (!is_array($fill)) {
            // the exchange never saw it (or it has aged out): nothing was filled
            $this->db->updateEngineOrder($clientId, [
                'status'     => $fallbackStatus !== null ? $fallbackStatus : 'CANCELED',
                'updated_at' => $this->nowIso(),
            ]);
            Log::warn('engine sync: order not found on the exchange — marked '
                . ($fallbackStatus !== null ? $fallbackStatus : 'CANCELED'), [
                    'client_id' => $clientId, 'symbol' => $symbol,
                ]);
            return true;
        }
        $this->bookFill($row, $fill, $fallbackStatus);
        $after = $this->db->engineOrder($clientId);
        return $after !== null && !in_array(strtoupper((string) $after['status']), Db::ENGINE_LIVE_STATUSES, true);
    }

    /* ============================================================= place */

    /**
     * Post one maker order. $price and $quote are the *intended* level and
     * notional; the price is rounded to the tick away from the mid (buys down,
     * sells up) and the quantity floored to the step, so a post-only quote stays
     * passive and every string sent to Binance is exponent-free.
     *
     * Returns the stored `engine_orders` row, or null when nothing was placed:
     * the exchange post-only-rejected the quote (normal, logged at debug), the
     * open-order cap is reached, or the rung fails the exchange filters.
     *
     * @throws BinanceException on any error that is not a post-only rejection
     */
    /**
     * The sleeve budget rule of DESIGN-PORTFOLIO.md §3: place() refuses a BUY whose quote
     * exceeds what the sleeve still has available. Additive and off by default - null (the
     * single-engine mode of DESIGN-ENGINES.md) places exactly as it does today. SELLs are
     * never budget-blocked: reducing inventory returns capital to the sleeve.
     */
    public function setAvailableQuote(?float $available): void
    {
        $this->availableQuote = ($available === null || !is_finite($available)) ? null : max(0.0, $available);
    }

    /** @return float|null the cap set by setAvailableQuote(), or null when uncapped */
    public function availableQuote(): ?float
    {
        return $this->availableQuote;
    }

    public function place(string $side, float $price, float $quote, string $purpose, ?int $level): ?array
    {
        $side   = strtoupper(trim($side)) === 'SELL' ? 'SELL' : 'BUY';
        $symbol = $this->symbol;
        if ($symbol === '') {
            Log::warn('engine place: no engine_symbol configured');
            return null;
        }
        if (!is_finite($price) || $price <= 0.0 || !is_finite($quote) || $quote <= 0.0) {
            return null;
        }

        $live = count($this->db->openEngineOrders($symbol, $this->mode));
        $cap  = $this->orderCap();
        if ($live >= $cap) {
            Log::debug('engine place: order cap reached, skipping ' . $purpose, [
                'symbol' => $symbol, 'live' => $live, 'cap' => $cap,
            ]);
            return null;
        }

        $step     = $this->stepSize();
        $priceStr = $this->priceString($price, $side === 'BUY' ? 'down' : 'up');
        $priceF   = (float) $priceStr;
        if ($priceF <= 0.0) {
            return null;
        }
        // a hair of slack absorbs binary representation error so a quantity that
        // is exactly on a step boundary does not floor a whole step down
        $qtyStr = Util::floorToStep(($quote / $priceF) * (1.0 + 1.0e-9), $step);
        $qtyF   = (float) $qtyStr;
        if ($qtyF <= 0.0) {
            Log::debug('engine place: quantity floors to zero, skipping ' . $purpose, [
                'symbol' => $symbol, 'quote' => $quote, 'price' => $priceStr, 'step' => $step,
            ]);
            return null;
        }
        $minQty = (float) ($this->info['minQty'] ?? 0);
        if ($minQty > 0.0 && $qtyF + self::eps($qtyF) < $minQty) {
            Log::debug('engine place: below minQty, skipping ' . $purpose, [
                'symbol' => $symbol, 'qty' => $qtyStr, 'min_qty' => $minQty,
            ]);
            return null;
        }
        $notional    = $qtyF * $priceF;
        $minNotional = (float) ($this->info['minNotional'] ?? 0);
        if ($minNotional > 0.0 && $notional + self::eps($notional) < $minNotional) {
            Log::debug('engine place: below minNotional, skipping ' . $purpose, [
                'symbol' => $symbol, 'notional' => $notional, 'min_notional' => $minNotional,
            ]);
            return null;
        }
        // sleeve budget cap (DESIGN-PORTFOLIO.md §3): a BUY may never commit more than the
        // sleeve still has available. Uncapped in single-engine mode; SELLs are never blocked.
        if ($side === 'BUY' && $this->availableQuote !== null
            && $notional > $this->availableQuote + self::eps($notional)) {
            Log::debug('engine place: over the sleeve budget, skipping ' . $purpose, [
                'symbol' => $symbol, 'notional' => $notional, 'available' => $this->availableQuote,
            ]);
            return null;
        }

        $cid = $this->newClientId($side, $symbol);
        if ($cid === null) {
            Log::warn('engine place: no free client id this minute', ['symbol' => $symbol, 'side' => $side]);
            return null;
        }

        // row first, HTTP second: a crash in between leaves a SENDING row that sync() resolves
        $this->db->insertEngineOrder([
            'client_id'    => $cid,
            'order_id'     => null,
            'mode'         => $this->mode,
            'engine'       => $this->engine,
            'symbol'       => $symbol,
            'side'         => $side,
            'status'       => 'SENDING',
            'price'        => $priceF,
            'qty'          => $qtyF,
            'quote'        => $notional,
            'filled_qty'   => 0.0,
            'filled_quote' => 0.0,
            'fee_usdt'     => 0.0,
            'level'        => $level,
            'purpose'      => $purpose,
            'created_at'   => $this->nowIso(),
        ]);

        $postOnly = !isset($this->cfg['post_only']) || (bool) $this->cfg['post_only'];
        try {
            $res = $this->callEx(self::M_LIMIT, [
                'symbol'   => $symbol,
                'side'     => $side,
                'qty'      => $qtyStr,
                'price'    => $priceStr,
                'info'     => $this->info,
                'clientid' => $cid,
                'postonly' => $postOnly,
                'quote'    => $notional,
            ]);
        } catch (BinanceException $e) {
            if (self::isPostOnlyReject($e)) {
                $this->db->updateEngineOrder($cid, [
                    'status'     => 'REJECTED',
                    'updated_at' => $this->nowIso(),
                    'raw'        => self::json(['post_only_reject' => $e->getMessage(), 'code' => $e->binanceCode]),
                ]);
                Log::debug('engine place: post-only rejected (would match immediately), skipping ' . $purpose, [
                    'symbol' => $symbol, 'side' => $side, 'price' => $priceStr, 'client_id' => $cid,
                ]);
                return null;
            }
            $unknown = $e->binanceCode === -1007;
            $this->db->updateEngineOrder($cid, [
                'status'     => $unknown ? 'UNKNOWN' : 'REJECTED',
                'updated_at' => $this->nowIso(),
                'raw'        => self::json(['error' => $e->getMessage(), 'code' => $e->binanceCode, 'http' => $e->httpStatus]),
            ]);
            Log::error('engine order ' . $side . ' ' . $symbol . ' ' . ($unknown ? 'UNKNOWN' : 'REJECTED')
                . ': ' . $e->getMessage(), ['client_id' => $cid, 'code' => $e->binanceCode, 'http' => $e->httpStatus]);
            throw $e;
        }

        $fill  = $this->normalizeResult(is_array($res) ? $res : [], $side);
        $saved = $this->db->engineOrder($cid);
        $this->bookFill($saved !== null ? $saved : ['client_id' => $cid], $fill, 'NEW');
        $row = $this->db->engineOrder($cid);
        Log::info('engine ' . $purpose . ': ' . $side . ' ' . $qtyStr . ' ' . $symbol . ' @ ' . $priceStr, [
            'client_id' => $cid, 'level' => $level, 'quote' => $notional,
            'status'    => $row !== null ? $row['status'] : 'NEW',
        ]);
        return $row;
    }

    /* ============================================================= cancel */

    /** Cancel one live local order. Returns true when it is no longer on the book. */
    public function cancel(string $clientId): bool
    {
        $row = $this->db->engineOrder($clientId);
        if ($row === null) {
            Log::warn('engine cancel: unknown order', ['client_id' => $clientId]);
            return false;
        }
        // `engineOrder()` looks a client_id up across every mode, and the panel's
        // cancel_order action hands us whatever id was in the form. Cancelling a
        // foreign-mode row would send a DELETE for a live order from a paper run.
        $rowMode = strtolower(trim((string) ($row['mode'] ?? '')));
        if ($rowMode !== $this->mode) {
            Log::warn('engine cancel: order belongs to another mode, refused', [
                'client_id' => $clientId, 'row_mode' => $rowMode, 'mode' => $this->mode,
            ]);
            return false;
        }
        if (!in_array(strtoupper((string) $row['status']), Db::ENGINE_LIVE_STATUSES, true)) {
            return false;   // already terminal, nothing to do
        }
        $symbol = (string) $row['symbol'];
        $this->cancelOnExchange($symbol, $clientId);
        // whatever the cancel said, the exchange is the truth: resolve and book any fill
        return $this->resolve($symbol, $clientId, 'CANCELED');
    }

    /**
     * Cancel every live local order of $symbol. Prefers one cancelAllOrders()
     * call (weight 1) and then reconciles each row, falling back to per-order
     * cancels when the exchange cannot do it in one shot.
     *
     * @return int number of orders that left the book
     */
    public function cancelAll(string $symbol, string $reason): int
    {
        $symbol = $this->normSymbol($symbol);
        $live   = $this->ownMode($this->db->openEngineOrders($symbol, $this->mode));
        if ($live === []) {
            return 0;
        }
        // one DELETE /openOrders (weight 1) when the exchange offers it, single cancels otherwise
        $bulk = false;
        if ($this->method(self::M_CANCEL_ALL) !== null) {
            try {
                $this->callEx(self::M_CANCEL_ALL, ['symbol' => $symbol]);
                $bulk = true;
            } catch (BinanceException $e) {
                // -2011 CANCEL_REJECTED / -2013 unknown order: there was nothing left to cancel
                $bulk = ($e->binanceCode === -2011 || $e->binanceCode === -2013);
                if (!$bulk) {
                    Log::warn('engine cancelAll: bulk cancel failed, falling back to single cancels: ' . $e->getMessage(), [
                        'symbol' => $symbol, 'code' => $e->binanceCode, 'http' => $e->httpStatus,
                    ]);
                }
            }
        }

        $n = 0;
        foreach ($live as $row) {
            $cid = (string) $row['client_id'];
            if (!$bulk) {
                $this->cancelOnExchange($symbol, $cid);
            }
            if ($this->resolve($symbol, $cid, 'CANCELED')) {
                $n++;
            }
        }
        Log::info('engine cancelAll ' . $symbol . ' (' . $reason . '): ' . $n . ' order(s) cancelled', [
            'reason' => $reason, 'symbol' => $symbol, 'live' => count($live), 'bulk' => $bulk,
        ]);
        return $n;
    }

    /* ============================================================= booking */

    /**
     * Book the state of one order. $fill is the *cumulative* normalised order
     * state (Binance::normalizeOrder shape: qty, dust_qty, price, quote,
     * fee_usdt, fee_asset, order_id, status, raw); only the part that is not
     * already recorded in the row's filled_qty / filled_quote is booked, so
     * calling this twice with the same fill is a no-op beyond rewriting the
     * same status. The row is always brought up to date.
     *
     * A BUY delta inserts a `lots` row (base commission taken off the quantity,
     * a commission outside the pair folded into the cost basis); a SELL delta
     * consumes lots FIFO and writes one `cycles` row per consumed slice with
     * pnl = net proceeds − lot cost − fees paid outside the pair.
     *
     * @param array       $order          engine_orders row (only client_id is required)
     * @param array       $fill           cumulative normalised order state
     * @param string|null $fallbackStatus status to store when the fill names none
     */
    public function bookFill(array $order, array $fill, ?string $fallbackStatus = null): void
    {
        $cid = (string) ($order['client_id'] ?? ($fill['client_id'] ?? ''));
        if ($cid === '') {
            return;
        }
        $fresh = $this->db->engineOrder($cid);
        if ($fresh !== null) {
            $order = $fresh;   // never trust a caller's stale copy for the idempotency guard
        }
        $symbol = strtoupper((string) ($order['symbol'] ?? $this->symbol));
        $side   = strtoupper((string) ($order['side'] ?? '')) === 'SELL' ? 'SELL' : 'BUY';
        $base   = $this->baseAsset($symbol);
        $raw    = isset($fill['raw']) && is_array($fill['raw']) ? $fill['raw'] : [];

        $fillQty  = (float) ($fill['qty'] ?? 0.0);
        $fillDust = (float) ($fill['dust_qty'] ?? 0.0);
        $cumExec  = isset($raw['executedQty']) ? (float) $raw['executedQty'] : ($fillQty + $fillDust);
        if ($cumExec <= 0.0) {
            $cumExec = $fillQty + $fillDust;
        }
        $cumQuote = 0.0;
        if (isset($raw['cummulativeQuoteQty'])) {
            $cumQuote = (float) $raw['cummulativeQuoteQty'];
        } elseif (isset($raw['cumulativeQuoteQty'])) {
            $cumQuote = (float) $raw['cumulativeQuoteQty'];
        }
        if ($cumQuote <= 0.0) {
            $cumQuote = (float) ($fill['quote'] ?? 0.0);
            if ($side === 'SELL' && ($fill['fee_asset'] ?? '') === $this->quoteAsset) {
                $cumQuote += (float) ($fill['fee_usdt'] ?? 0.0);   // undo the netting: we track gross here
            }
        }
        $cumFee   = max(0.0, (float) ($fill['fee_usdt'] ?? 0.0));
        $feeAsset = (string) ($fill['fee_asset'] ?? '');

        $prevExec  = (float) ($order['filled_qty'] ?? 0.0);
        $prevQuote = (float) ($order['filled_quote'] ?? 0.0);
        $prevFee   = (float) ($order['fee_usdt'] ?? 0.0);

        $dQty = $cumExec - $prevExec;
        // One transaction: the lot / cycle / trade rows and the filled_qty guard
        // that stops them being booked twice must land together or not at all.
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            if ($dQty > self::eps($cumExec)) {
                $dQuote = max(0.0, $cumQuote - $prevQuote);
                $dFee   = max(0.0, $cumFee - $prevFee);
                $ratio  = $cumExec > 0.0 ? $dQty / $cumExec : 1.0;
                $avg    = $dQty > 0.0 ? $dQuote / $dQty : (float) ($fill['price'] ?? 0.0);

                if ($side === 'BUY') {
                    $this->bookBuy($order, $symbol, $base, $cid, $dQty, $dQuote, $dFee, $ratio, $avg,
                        $fillQty + $fillDust, $feeAsset, $fill);
                } else {
                    $this->bookSell($order, $symbol, $cid, $dQty, $dQuote, $dFee, $avg, $feeAsset, $fill);
                }
            }

            $orderId = (string) ($fill['order_id'] ?? '');
            if ($orderId === '') {
                $orderId = (string) ($order['order_id'] ?? '');
            }
            $this->db->updateEngineOrder($cid, [
                'order_id'     => $orderId !== '' ? $orderId : null,
                'status'       => $this->statusFor($order, $fill, $cumExec, $fallbackStatus),
                'filled_qty'   => max($prevExec, $cumExec),
                'filled_quote' => max($prevQuote, $cumQuote),
                'fee_usdt'     => max($prevFee, $cumFee),
                'fee_asset'    => $feeAsset !== '' ? $feeAsset : (isset($order['fee_asset']) ? $order['fee_asset'] : null),
                'updated_at'   => $this->nowIso(),
                'raw'          => self::json($raw !== [] ? $raw : $fill),
            ]);
            if ($own) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** BUY delta → one FIFO lot + one trades row. */
    private function bookBuy(
        array $order,
        string $symbol,
        string $base,
        string $cid,
        float $dQty,
        float $dQuote,
        float $dFee,
        float $ratio,
        float $avg,
        float $netCum,
        string $feeAsset,
        array $fill
    ): void {
        // normalizeOrder() already took the base-asset commission off: qty + dust
        // is what actually landed in the wallet for the whole order so far.
        if ($netCum <= 0.0) {
            $netCum = $dQty;
        }
        $dNet = $netCum * $ratio;
        if ($dNet <= 0.0) {
            $dNet = $dQty;
        }
        // a commission paid outside the pair (BNB) was not deducted anywhere yet:
        // fold it into the cost basis so the cycle pnl carries it
        $feeBase    = array_key_exists('fee_base_usdt', $fill)
            ? max(0.0, (float) $fill['fee_base_usdt']) * $ratio
            : (($feeAsset !== '' && $feeAsset === $base) ? $dFee : 0.0);
        $feeOutside = max(0.0, $dFee - $feeBase);
        $cost       = $dQuote + $feeOutside;
        $lotPrice   = $dNet > 0.0 ? $cost / $dNet : $avg;

        $this->db->insertLot([
            'mode'       => $this->mode,
            'engine'     => $this->engine,
            'symbol'     => $symbol,
            'qty'        => $dNet,
            'remaining'  => $dNet,
            'price'      => $lotPrice,
            'fee_usdt'   => $dFee,
            'level'      => isset($order['level']) ? $order['level'] : null,
            'client_id'  => $cid,
            'created_at' => $this->nowIso(),
        ]);
        $this->insertTrade($symbol, 'BUY', $cid, $dNet, $avg, $dQuote, $dFee, $feeAsset, $fill);
        Log::info('engine fill: BUY ' . Util::fmtQty($dNet, 8) . ' ' . $symbol . ' @ ' . Util::fmtQty($avg, 8), [
            'client_id' => $cid, 'quote' => $dQuote, 'fee_usdt' => $dFee, 'lot_price' => $lotPrice,
        ]);
    }

    /** SELL delta → FIFO lot consumption, one cycle per slice + one trades row. */
    private function bookSell(
        array $order,
        string $symbol,
        string $cid,
        float $dQty,
        float $dQuote,
        float $dFee,
        float $avg,
        string $feeAsset,
        array $fill
    ): void {
        $feeIsQuote  = ($feeAsset === $this->quoteAsset);
        $proceedsNet = $dQuote - ($feeIsQuote ? $dFee : 0.0);
        if ($proceedsNet < 0.0) {
            $proceedsNet = 0.0;
        }
        $feeOutside = $feeIsQuote ? 0.0 : $dFee;   // BNB and friends: not netted anywhere

        // a grid sell was placed against the lot of its own rung: consume that lot
        // first, so the lot whose sell is still resting is not burnt from under it.
        // Level-less orders (pmm) keep the plain FIFO path.
        $lvl    = isset($order['level']) && $order['level'] !== null && $order['level'] !== ''
            ? (int) $order['level'] : null;
        $slices = $lvl !== null
            ? $this->db->consumeLotsAtLevel($symbol, $dQty, $this->mode, $this->engine, $lvl)
            : [];
        $taken  = 0.0;
        foreach ($slices as $s) {
            $taken += (float) $s['qty'];
        }
        if ($taken + self::eps($dQty) < $dQty) {
            $slices = array_merge(
                $slices,
                $this->db->consumeLots($symbol, $dQty - $taken, $this->mode, $this->engine)
            );
        }
        $matched = 0.0;
        $now     = $this->nowIso();
        foreach ($slices as $s) {
            $q = (float) $s['qty'];
            if ($q <= 0.0) {
                continue;
            }
            $matched += $q;
            $share    = $dQty > 0.0 ? $q / $dQty : 1.0;
            $lot      = $s['lot'];
            $lotQty   = (float) $lot['qty'];
            $buyFee   = $lotQty > 0.0 ? (float) $lot['fee_usdt'] * ($q / $lotQty) : 0.0;
            $proceeds = $proceedsNet * $share;
            $pnl      = $proceeds - (float) $s['cost'] - $feeOutside * $share;
            $this->db->insertCycle([
                'mode'       => $this->mode,
                'engine'     => $this->engine,
                'symbol'     => $symbol,
                'level'      => isset($lot['level']) ? $lot['level'] : (isset($order['level']) ? $order['level'] : null),
                'qty'        => $q,
                'buy_price'  => (float) $lot['price'],
                'sell_price' => $avg,
                'gross_usdt' => $dQuote * $share,
                'fee_usdt'   => $buyFee + $dFee * $share,
                'pnl_usdt'   => $pnl,
                'opened_at'  => isset($lot['created_at']) ? (string) $lot['created_at'] : null,
                'closed_at'  => $now,
            ]);
            Log::info('engine cycle: ' . $symbol . ' ' . Util::fmtQty($q, 8) . ' @ '
                . Util::fmtQty((float) $lot['price'], 8) . ' → ' . Util::fmtQty($avg, 8)
                . ' pnl ' . sprintf('%+.6f', $pnl), [
                    'client_id' => $cid, 'level' => $lot['level'], 'fee_usdt' => $buyFee + $dFee * $share,
                ]);
        }
        if ($matched + self::eps($dQty) < $dQty) {
            Log::warn('engine sell exceeded tracked inventory — untracked quantity left unmatched', [
                'symbol' => $symbol, 'client_id' => $cid, 'sold' => $dQty, 'matched' => $matched,
            ]);
        }
        $this->insertTrade($symbol, 'SELL', $cid, $dQty, $avg, $proceedsNet, $dFee, $feeAsset, $fill);
    }

    private function insertTrade(
        string $symbol,
        string $side,
        string $cid,
        float $qty,
        float $price,
        float $quote,
        float $fee,
        string $feeAsset,
        array $fill
    ): void {
        $this->db->insertTrade([
            'position_id' => null,
            'mode'        => $this->mode,
            'symbol'      => $symbol,
            'side'        => $side,
            'order_id'    => isset($fill['order_id']) ? (string) $fill['order_id'] : null,
            'client_id'   => $cid,
            'qty'         => $qty,
            'price'       => $price,
            'quote'       => $quote,
            'fee_usdt'    => $fee,
            'fee_asset'   => $feeAsset !== '' ? $feeAsset : null,
            'raw'         => self::json(isset($fill['raw']) && is_array($fill['raw']) ? $fill['raw'] : $fill),
            'created_at'  => $this->nowIso(),
        ]);
    }

    /* ============================================================= helpers */

    /** Status to store: what the exchange said, else derived from the quantities, else the fallback. */
    private function statusFor(array $order, array $fill, float $cumExec, ?string $fallback): string
    {
        $st = strtoupper(trim((string) ($fill['status'] ?? '')));
        if ($st === '' && isset($fill['raw']['status'])) {
            $st = strtoupper(trim((string) $fill['raw']['status']));
        }
        $allowed = array_merge(Db::ENGINE_LIVE_STATUSES, self::TERMINAL_STATUSES);
        if ($st !== '' && in_array($st, $allowed, true)) {
            return $st;
        }
        $target = (float) ($order['qty'] ?? 0.0);
        if ($cumExec > 0.0 && ($target <= 0.0 || $cumExec + self::eps($target) >= $target)) {
            return 'FILLED';
        }
        if ($cumExec > 0.0) {
            return 'PARTIALLY_FILLED';
        }
        if ($fallback !== null && $fallback !== '') {
            return strtoupper($fallback);
        }
        $cur = strtoupper((string) ($order['status'] ?? 'NEW'));
        return $cur === 'SENDING' ? 'NEW' : $cur;
    }

    /** engine_max_orders, also held under the symbol's MAX_NUM_ORDERS − 2 (DESIGN-ENGINES §7.5). */
    private function orderCap(): int
    {
        $cap = isset($this->cfg['engine_max_orders']) ? (int) $this->cfg['engine_max_orders'] : 12;
        if ($cap < 1) {
            $cap = 1;
        }
        if ($cap > 20) {
            $cap = 20;
        }
        $maxNum = isset($this->info['maxNumOrders']) ? (int) $this->info['maxNumOrders'] : 0;
        if ($maxNum > 2 && $cap > $maxNum - 2) {
            $cap = $maxNum - 2;
        }
        return $cap;
    }

    /** Free client id for this tick minute; null when 99 ids are already taken. */
    private function newClientId(string $side, string $symbol): ?string
    {
        $minute = intdiv($this->nowMs(), 60000) * 60;
        $base   = Util::clientOrderId($side, $symbol, $minute);
        $cid    = $base;
        for ($n = 2; $n < 100; $n++) {
            if (!$this->clientIdTaken($cid)) {
                return $cid;
            }
            $cid = substr($base . '-' . $n, 0, 36);
        }
        return null;
    }

    private function clientIdTaken(string $cid): bool
    {
        if ($this->db->engineOrder($cid) !== null) {
            return true;
        }
        $st = $this->db->pdo()->prepare('SELECT 1 FROM orders WHERE client_id = ? LIMIT 1');
        $st->execute([$cid]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Tick-rounded price string. Uses Util::roundToTick (DESIGN-ENGINES §4) and
     * falls back to exact string maths on Util::floorToStep when running against
     * an older Util, so no code path can ever produce exponent notation.
     */
    private function priceString(float $price, string $dir): string
    {
        $tick = $this->tickSize();
        if (method_exists('Util', 'roundToTick')) {
            return Util::roundToTick($price, $tick, $dir);
        }
        $down = Util::floorToStep($price, $tick);
        if ($dir === 'down') {
            return $down;
        }
        $downF = (float) $down;
        if (abs($downF - $price) <= self::eps($price)) {
            return $down;
        }
        $d   = Util::decimalsOf($tick);
        $sum = (string) ((int) Util::scaleToInt($down, $d) + (int) Util::scaleToInt(Util::toDecimalString($tick), $d));
        $up  = Util::unscaleInt($sum, $d);
        if ($dir === 'up') {
            return $up;
        }
        return ($price - $downF) <= ((float) $up - $price) ? $down : $up;   // nearest
    }

    private function stepSize(): string
    {
        $s = (string) ($this->info['stepSize'] ?? '');
        return $s !== '' && $s !== '0' ? $s : '0.00000001';
    }

    private function tickSize(): string
    {
        $s = (string) ($this->info['tickSize'] ?? '');
        return $s !== '' && $s !== '0' ? $s : '0.00000001';
    }

    private function baseAsset(string $symbol): string
    {
        $b = (string) ($this->info['base'] ?? '');
        if ($b !== '') {
            return $b;
        }
        $q = $this->quoteAsset;
        if (strlen($symbol) > strlen($q) && substr($symbol, -strlen($q)) === $q) {
            return substr($symbol, 0, -strlen($q));
        }
        return $symbol;
    }

    /**
     * Keep only the rows belonging to THIS mode.
     *
     * `Db::openEngineOrders()` is already asked for `$this->mode`, so in practice
     * this filter removes nothing; it exists because the very next thing
     * `cancelAll()` does is a bulk `DELETE /api/v3/openOrders` that cannot be
     * scoped per order. A single foreign-mode row reaching that point would fire
     * a real-account cancel from a paper run (or the reverse), so the list is
     * re-checked here, in the one place where being wrong costs money.
     *
     * @param  array $rows engine_orders rows
     * @return array the subset whose `mode` column equals $this->mode
     */
    private function ownMode(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string) ($row['mode'] ?? ''))) === $this->mode) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function normSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        return $symbol !== '' ? $symbol : $this->symbol;
    }

    private function nowMs(): int
    {
        return $this->nowMs !== null ? $this->nowMs : Util::nowMs();
    }

    private function nowIso(): string
    {
        return $this->nowMs !== null ? Util::nowIso(intdiv($this->nowMs, 1000)) : Util::nowIso();
    }

    /** Relative epsilon: quantities and notionals differing by less than this are the same number. */
    private static function eps(float $scale): float
    {
        $a = abs($scale);
        return $a > 1.0 ? $a * 1.0e-9 : 1.0e-9;
    }

    private static function json($v): string
    {
        $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $s === false ? '{}' : $s;
    }

    /** A post-only (LIMIT_MAKER) rejection is normal, not an error (DESIGN-ENGINES §3). */
    private static function isPostOnlyReject(BinanceException $e): bool
    {
        if (method_exists($e, 'isPostOnlyReject')) {
            return (bool) $e->isPostOnlyReject();
        }
        return $e->binanceCode === -2010 && stripos($e->getMessage(), 'immediately match') !== false;
    }

    /** Accepts either the flat symbol-info row or the [SYMBOL => row] map. */
    private static function flattenInfo(array $info, string $symbol): array
    {
        if (isset($info['stepSize']) || isset($info['tickSize']) || isset($info['minNotional'])) {
            return $info;
        }
        if ($symbol !== '' && isset($info[$symbol]) && is_array($info[$symbol])) {
            return $info[$symbol];
        }
        foreach ($info as $k => $row) {
            if (is_array($row) && (isset($row['stepSize']) || isset($row['tickSize']))) {
                $row['symbol'] = isset($row['symbol']) ? $row['symbol'] : (string) $k;
                return $row;
            }
        }
        return $info;
    }

    /* -------------------------------------------------- exchange adapters */

    /**
     * Open orders of $symbol as [clientOrderId => ['order_id','symbol','side',
     * 'price','orig_qty','executed_qty','status','time']], or null when this
     * exchange cannot list them (every live row is then resolved one by one).
     */
    private function exchangeOpenOrders(string $symbol): ?array
    {
        if ($this->method(self::M_OPEN) === null) {
            if (!$this->warnedNoOpenOrders) {
                $this->warnedNoOpenOrders = true;
                Log::debug('engine sync: exchange cannot list open orders, resolving each order individually', [
                    'exchange' => get_class($this->ex),
                ]);
            }
            return null;
        }
        try {
            $raw = $this->callEx(self::M_OPEN, ['symbol' => $symbol]);
        } catch (BinanceException $e) {
            Log::warn('engine sync: openOrders failed: ' . $e->getMessage(), [
                'symbol' => $symbol, 'code' => $e->binanceCode, 'http' => $e->httpStatus,
            ]);
            return null;
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $cid = (string) ($row['clientOrderId'] ?? ($row['client_id'] ?? (is_string($key) ? $key : '')));
            if ($cid === '') {
                continue;
            }
            $out[$cid] = [
                'order_id'     => (string) ($row['order_id'] ?? ($row['orderId'] ?? '')),
                'symbol'       => strtoupper((string) ($row['symbol'] ?? $symbol)),
                'side'         => strtoupper((string) ($row['side'] ?? '')),
                'price'        => (float) ($row['price'] ?? 0),
                'orig_qty'     => (float) ($row['orig_qty'] ?? ($row['origQty'] ?? 0)),
                'executed_qty' => (float) ($row['executed_qty'] ?? ($row['executedQty'] ?? 0)),
                'status'       => strtoupper((string) ($row['status'] ?? 'NEW')),
                'time'         => (int) ($row['time'] ?? 0),
            ];
        }
        return $out;
    }

    /** Cancel one order id on the exchange; -2011/-2013 (already gone) count as success. */
    private function cancelOnExchange(string $symbol, string $clientId): bool
    {
        try {
            $this->callEx(self::M_CANCEL, ['symbol' => $symbol, 'clientid' => $clientId]);
            return true;
        } catch (BinanceException $e) {
            if ($e->binanceCode === -2011 || $e->binanceCode === -2013) {
                return true;   // filled or already gone; resolve() reads the real state
            }
            Log::warn('engine cancel failed: ' . $e->getMessage(), [
                'symbol' => $symbol, 'client_id' => $clientId, 'code' => $e->binanceCode, 'http' => $e->httpStatus,
            ]);
            return false;
        }
    }

    /** First implemented method name of $candidates, or null. */
    private function method(array $candidates): ?string
    {
        foreach ($candidates as $m) {
            if (method_exists($this->ex, $m)) {
                return $m;
            }
        }
        return null;
    }

    /**
     * Call one exchange method, filling its parameters by NAME from $named
     * (canonical keys of ARG_ALIASES). The limit-order surface is added to the
     * exchange by another file; matching on names rather than a guessed
     * positional order keeps this class working with any documented spelling.
     *
     * @param string[] $candidates
     * @return mixed
     * @throws BinanceException when no candidate exists or an argument is missing
     */
    private function callEx(array $candidates, array $named)
    {
        $method = $this->method($candidates);
        if ($method === null) {
            throw new BinanceException(
                get_class($this->ex) . ' has no ' . $candidates[0] . '(): limit orders are not available in this mode',
                -1000,
                0
            );
        }
        $rm   = new ReflectionMethod($this->ex, $method);
        $args = [];
        foreach ($rm->getParameters() as $p) {
            $key   = str_replace('_', '', strtolower($p->getName()));
            $canon = null;
            foreach (self::ARG_ALIASES as $c => $spellings) {
                if (in_array($key, $spellings, true)) {
                    $canon = $c;
                    break;
                }
            }
            if ($canon !== null && array_key_exists($canon, $named)) {
                $args[] = self::coerce($named[$canon], $p);
                continue;
            }
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }
            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }
            throw new BinanceException(
                get_class($this->ex) . '::' . $method . '(): cannot supply argument $' . $p->getName(),
                -1000,
                0
            );
        }
        return $rm->invokeArgs($this->ex, $args);
    }

    /**
     * Match a value to the declared parameter type (a quantity may be wanted as
     * a string or as a float). Decimal strings stay exact either way.
     *
     * @param mixed $v
     * @return mixed
     */
    private static function coerce($v, ReflectionParameter $p)
    {
        $t = $p->getType();
        if (!($t instanceof ReflectionNamedType)) {
            return $v;
        }
        $name = strtolower($t->getName());
        if ($name === 'string' && !is_string($v)) {
            return is_float($v) || is_int($v) ? Util::toDecimalString($v) : $v;
        }
        if ($name === 'float' && is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        if ($name === 'bool' && !is_bool($v)) {
            return (bool) $v;
        }
        return $v;
    }

    /**
     * Normalise whatever the exchange returned for a limit order into the
     * standard fill shape. Implementations that already return the normalised
     * array are passed through; a raw Binance response is normalised here.
     */
    private function normalizeResult(array $res, string $side): array
    {
        if (array_key_exists('qty', $res) && array_key_exists('quote', $res)) {
            if (!isset($res['raw']) || !is_array($res['raw'])) {
                $res['raw'] = [];
            }
            if (!isset($res['fee_asset'])) {
                $res['fee_asset'] = '';
            }
            if (!isset($res['dust_qty'])) {
                $res['dust_qty'] = 0.0;
            }
            return $res;
        }
        if (!isset($res['side'])) {
            $res['side'] = $side;
        }
        return Binance::normalizeOrder($res, $this->info, null);
    }
}
