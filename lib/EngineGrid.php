<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Binance.php';
require_once __DIR__ . '/Exchange.php';
require_once __DIR__ . '/Risk.php';
require_once __DIR__ . '/EngineOrders.php';

/**
 * Grid engine (docs/DESIGN-ENGINES.md §7).
 *
 * A ladder of resting limit buys below an anchor price; every filled rung gets a
 * matching sell one rung up. The engine earns the rung spacing minus the round
 * trip fee, which is why Risk::validateConfig() refuses a spacing that cannot
 * clear it. Price trending out of the configured range ends the grid: everything
 * is cancelled, the inventory is optionally liquidated, and the bot pauses with
 * pause_reason 'grid_range_exit' until a human re-anchors from the panel.
 *
 * State keys: grid_anchor, grid_anchor_at, grid_symbol, grid_paused_reason.
 *
 * Order discipline (§7): at most ONE new order per side per tick, never more
 * than min(engine_max_orders, MAX_NUM_ORDERS − 2) live orders, buys strictly
 * below the bid and sells strictly above the ask so a post-only quote can never
 * take, and every price checked against PERCENT_PRICE_BY_SIDE.
 *
 * tick() is called by Bot AFTER EngineOrders::sync() has reconciled and booked
 * fills; it never syncs by itself.
 */
final class EngineGrid
{
    /** pause_reason written on a range exit. */
    const PAUSE_REASON = 'grid_range_exit';
    /** "Far future": only a manual re-anchor clears the pause. */
    const PAUSE_SECONDS = 315360000;   // 10 years
    /** Live orders kept free below the exchange's MAX_NUM_ORDERS. */
    const ORDER_HEADROOM = 2;
    /** Quantities/notionals below this are treated as zero. */
    const EPS = 1.0e-12;

    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var ExchangeInterface */
    private $ex;
    /** @var EngineOrders */
    private $orders;
    /** @var array parsed symbol info (flat shape) for $symbol */
    private $info;
    /** @var string */
    private $symbol;
    /** @var string */
    private $mode;
    /** @var string */
    private $tickSize;
    /** @var string */
    private $stepSize;
    /** @var float */
    private $minQty;
    /** @var float */
    private $minNotional;
    /** @var int */
    private $maxNumOrders;

    public function __construct(array $cfg, Db $db, ExchangeInterface $ex, EngineOrders $orders, array $info)
    {
        $this->cfg    = $cfg;
        $this->db     = $db;
        $this->ex     = $ex;
        $this->orders = $orders;
        $this->symbol = self::symbolOf($cfg);
        $this->mode   = self::modeOf($cfg);
        $this->info   = self::flatInfo($info, $this->symbol);

        $this->tickSize     = isset($this->info['tickSize']) && is_string($this->info['tickSize']) && $this->info['tickSize'] !== ''
            ? $this->info['tickSize'] : '0.00000001';
        $this->stepSize     = isset($this->info['stepSize']) && is_string($this->info['stepSize']) && $this->info['stepSize'] !== ''
            ? $this->info['stepSize'] : '0.00000001';
        $this->minQty       = isset($this->info['minQty']) && is_numeric($this->info['minQty']) ? (float) $this->info['minQty'] : 0.0;
        $this->minNotional  = isset($this->info['minNotional']) && is_numeric($this->info['minNotional']) ? (float) $this->info['minNotional'] : 5.0;
        $this->maxNumOrders = isset($this->info['maxNumOrders']) && is_numeric($this->info['maxNumOrders']) ? (int) $this->info['maxNumOrders'] : 200;
    }

    /* ----------------------------------------------------------------- api */

    /** Current anchor price, or 0.0 when the grid has never been anchored (or was anchored on another symbol). */
    public function anchor(): float
    {
        if ((string) $this->db->getState('grid_symbol', '') !== $this->symbol) {
            return 0.0;
        }
        $raw = $this->db->getState('grid_anchor', null);
        if ($raw === null || !is_numeric($raw)) {
            return 0.0;
        }
        $v = (float) $raw;
        return ($v > 0 && is_finite($v)) ? $v : 0.0;
    }

    /**
     * (Re-)anchor the ladder at $mid and clear a range-exit pause. Called on the
     * first tick of a fresh grid and by the panel's "Re-anchor grid" action.
     */
    public function reanchor(float $mid): void
    {
        if (!is_finite($mid) || $mid <= 0) {
            return;
        }
        $this->db->setState('grid_anchor', $mid);
        $this->db->setState('grid_anchor_at', Util::nowIso());
        $this->db->setState('grid_symbol', $this->symbol);
        $wasGridPause = (string) $this->db->getState('grid_paused_reason', '') === self::PAUSE_REASON;
        $this->db->setState('grid_paused_reason', '');
        if ($wasGridPause || (string) $this->db->getState('pause_reason', '') === self::PAUSE_REASON) {
            $this->db->setState('paused_until', '');
            $this->db->setState('pause_reason', '');
        }
        Log::info('Grid anchored', ['symbol' => $this->symbol, 'anchor' => $mid]);
    }

    /**
     * One grid tick, run after EngineOrders::sync().
     *
     * $quoteFree is the free quote balance measured at the top of the tick. It is
     * optional so the documented tick(bid, ask) call still works; when it is given,
     * a rung the wallet cannot fund is skipped instead of being sent to the exchange
     * and bounced with -2010 (which would also cost this tick its sell).
     * @return array{action:string, detail:string}
     */
    public function tick(float $bid, float $ask, ?float $quoteFree = null): array
    {
        if (!is_finite($bid) || !is_finite($ask) || $bid <= 0 || $ask <= 0 || $ask < $bid) {
            return self::res('skipped', 'no book for ' . $this->symbol);
        }
        $mid = ($bid + $ask) / 2.0;

        // a range exit stays in force until somebody re-anchors: never re-cancel or re-liquidate
        if ((string) $this->db->getState('grid_paused_reason', '') === self::PAUSE_REASON) {
            return self::res('paused', 'grid_range_exit: re-anchor to resume');
        }

        // 1. anchor
        $anchored = false;
        $anchor   = $this->anchor();
        if ($anchor <= 0) {
            $this->reanchor($mid);
            $anchor   = $mid;
            $anchored = true;
        }

        // 2. range exit
        $up   = $anchor * (1.0 + $this->pct('grid_range_up_pct', 4.0) / 100.0);
        $down = $anchor * (1.0 - $this->pct('grid_range_down_pct', 6.0) / 100.0);
        if ($mid > $up || $mid < $down) {
            return $this->rangeExit($mid, $anchor, $bid);
        }

        // book state
        $spacing = $this->pct('grid_spacing_pct', 0.60) / 100.0;
        if ($spacing <= 0) {
            return self::res('skipped', 'grid_spacing_pct must be positive');
        }
        $levels = (int) Util::clamp((float) $this->int('grid_levels', 6), 1.0, (float) Risk::GRID_LEVELS_MAX);
        $live   = $this->db->openEngineOrders($this->symbol, $this->mode);
        $lots   = $this->db->openLots($this->symbol, $this->mode, 'grid');

        $liveBuy   = [];
        $liveSell  = [];
        $liveCount = 0;
        foreach ($live as $o) {
            $liveCount++;
            $lvl = self::levelOf($o);
            if (strtoupper((string) ($o['side'] ?? '')) === 'SELL') {
                $liveSell[$lvl] = true;
            } else {
                $liveBuy[$lvl] = true;
            }
        }
        $held = [];
        foreach ($lots as $lot) {
            $held[self::levelOf($lot)] = true;
        }
        $cap   = $this->orderCap();
        $notes = [];

        // 3. one new buy rung
        $placedBuy = $this->placeBuy($anchor, $spacing, $levels, $bid, $mid, $liveBuy, $held, $liveCount, $cap, $quoteFree, $notes);
        if ($placedBuy !== '') {
            $liveCount++;
        }

        // 4. one new sell for a held lot
        $placedSell = $this->placeSell($anchor, $spacing, $lots, $ask, $mid, $liveSell, $liveCount, $cap, $notes);
        if ($placedSell !== '') {
            $liveCount++;
        }

        $parts = [];
        if ($placedBuy !== '') {
            $parts[] = $placedBuy;
        }
        if ($placedSell !== '') {
            $parts[] = $placedSell;
        }
        $detail = 'anchor=' . Util::trimZeros(Util::roundToTick($anchor, $this->tickSize, 'nearest'))
            . ' live=' . $liveCount . '/' . $cap . ' lots=' . count($lots);
        if ($parts !== []) {
            $detail = implode('; ', $parts) . ' | ' . $detail;
        }
        if ($notes !== []) {
            $detail .= ' | ' . implode(', ', array_slice($notes, 0, 4));
        }

        if ($parts !== []) {
            return self::res('place', $detail);
        }
        if ($anchored) {
            return self::res('anchor', $detail);
        }
        foreach ($notes as $n) {
            if (strpos($n, 'grid_dust') === 0) {
                return self::res('grid_dust', $detail);
            }
        }
        return self::res('idle', $detail);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Post the first rung that has neither a live buy nor a held lot. At most one
     * attempt per tick: an attempt that the filters or the post-only rule reject is
     * still an attempt, so the engine never hammers the book.
     * @return string '' when nothing was posted, else a short description
     */
    private function placeBuy(float $anchor, float $spacing, int $levels, float $bid, float $mid, array $liveBuy, array $held, int $liveCount, int $cap, ?float $quoteFree, array &$notes): string
    {
        if ($liveCount >= $cap) {
            $notes[] = 'order cap ' . $cap . ' reached';
            return '';
        }
        $quote = $this->f('grid_order_usdt', Risk::ENGINE_ORDER_USDT_DEFAULT);
        if ($quoteFree !== null && is_finite($quoteFree) && $quote > $quoteFree) {
            // the wallet cannot fund a rung: skip the buy rather than have the exchange
            // reject it, so the sell side of this tick still runs
            $notes[] = 'quote free short for a buy rung';
            return '';
        }
        for ($i = 1; $i <= $levels; $i++) {
            if (isset($liveBuy[$i]) || isset($held[$i])) {
                continue;
            }
            $target = $anchor * (1.0 - $i * $spacing);
            if ($target <= 0) {
                break;
            }
            $priceStr = Util::roundToTick($target, $this->tickSize, 'down');
            $price    = (float) $priceStr;
            if ($price <= 0) {
                break;
            }
            if ($price >= $bid) {
                // post-only safety: a buy at or above the bid would take, not make.
                // Nothing was sent, so the next rung down may still be posted this tick.
                $notes[] = 'buy L' . $i . ' not below bid';
                continue;
            }
            if (!Binance::priceAllowed($price, 'BUY', $mid, $this->info)) {
                $notes[] = 'buy L' . $i . ' outside PERCENT_PRICE_BY_SIDE';
                continue;
            }
            $qty = (float) Util::floorToStep($quote / $price, $this->stepSize);
            if ($qty <= self::EPS || $qty < $this->minQty || $qty * $price < $this->minNotional - 1e-9) {
                $notes[] = 'buy L' . $i . ' below filters';
                continue;
            }
            $res = $this->tryPlace('BUY', $price, $quote, 'grid_buy', $i);
            return $res === null ? '' : 'buy L' . $i . ' @' . Util::trimZeros($priceStr);
        }
        return '';
    }

    /**
     * Post a sell for the first held lot that has no live sell. Price is one rung
     * above the lot, never below the rung the lot belongs to:
     * max(lot.price × (1+s), anchor × (1 − (level−1) × s)), rounded up.
     * @return string '' when nothing was posted, else a short description
     */
    private function placeSell(float $anchor, float $spacing, array $lots, float $ask, float $mid, array $liveSell, int $liveCount, int $cap, array &$notes): string
    {
        foreach ($lots as $lot) {
            $lvl = self::levelOf($lot);
            if (isset($liveSell[$lvl])) {
                continue;
            }
            $remaining = isset($lot['remaining']) && is_numeric($lot['remaining']) ? (float) $lot['remaining'] : 0.0;
            $lotPrice  = isset($lot['price']) && is_numeric($lot['price']) ? (float) $lot['price'] : 0.0;
            $qtyStr    = Util::floorToStep($remaining, $this->stepSize);
            $qty       = (float) $qtyStr;
            $rung      = $anchor * (1.0 - max(0, $lvl - 1) * $spacing);
            $target    = max($lotPrice * (1.0 + $spacing), $rung);
            $priceStr  = Util::roundToTick($target, $this->tickSize, 'up');
            $price     = (float) $priceStr;
            if ($price <= 0) {
                continue;
            }
            if ($qty <= self::EPS || $qty < $this->minQty || $qty * $price < $this->minNotional - 1e-9) {
                // below the filters: the remainder stays in the wallet and is reported, never sold at a loss
                $notes[] = 'grid_dust L' . $lvl . ' qty=' . Util::trimZeros($qtyStr);
                continue;
            }
            if ($liveCount >= $cap) {
                $notes[] = 'order cap ' . $cap . ' reached';
                return '';
            }
            if ($price <= $ask) {
                // post-only safety: a sell at or below the ask would take, not make
                $notes[] = 'sell L' . $lvl . ' not above ask';
                continue;
            }
            if (!Binance::priceAllowed($price, 'SELL', $mid, $this->info)) {
                $notes[] = 'sell L' . $lvl . ' outside PERCENT_PRICE_BY_SIDE';
                continue;
            }
            // EngineOrders sizes from the quote; pad by a hair so the float division
            // floors back to exactly $qty instead of one step below it
            $quote = $price * $qty * (1.0 + 1e-9);
            $res   = $this->tryPlace('SELL', $price, $quote, 'grid_sell', $lvl > 0 ? $lvl : null);
            return $res === null ? '' : 'sell L' . $lvl . ' @' . Util::trimZeros($priceStr);
        }
        return '';
    }

    /**
     * EngineOrders::place() guarded. A BinanceException is re-thrown: the Bot error
     * policy (-2015, 429/418, -1013, network errors) owns those. Anything else costs
     * this quote, never the tick.
     */
    private function tryPlace(string $side, float $price, float $quote, string $purpose, ?int $level): ?array
    {
        try {
            return $this->orders->place($side, $price, $quote, $purpose, $level);
        } catch (BinanceException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warn('Grid: order rejected', [
                'symbol' => $this->symbol, 'side' => $side, 'purpose' => $purpose,
                'level' => $level, 'price' => $price, 'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Range exit (§7.2): cancel everything, optionally liquidate, pause far into the
     * future with pause_reason 'grid_range_exit' and warn. Only a manual re-anchor resumes.
     */
    private function rangeExit(float $mid, float $anchor, float $bid): array
    {
        $cancelled = 0;
        try {
            $cancelled = $this->orders->cancelAll($this->symbol, self::PAUSE_REASON);
        } catch (Throwable $e) {
            Log::error('Grid range exit: cancelAll failed', ['symbol' => $this->symbol, 'error' => $e->getMessage()]);
        }

        $sold = '';
        if ($this->bool('grid_exit_liquidates', false)) {
            $sold = $this->liquidate($bid);
        }

        // `paused_until`/`pause_reason` are the GLOBAL keys Risk::entryBlockReason() reads for
        // every engine, so in portfolio mode writing them here would stop the signal and pmm
        // sleeves as well - the opposite of the isolation of DESIGN-PORTFOLIO.md §6.4. The
        // sleeve-local `grid_paused_reason` below already stops this grid until a re-anchor.
        if (!$this->bool('portfolio_enabled', false)) {
            $this->db->setState('paused_until', Util::nowIso(time() + self::PAUSE_SECONDS));
            $this->db->setState('pause_reason', self::PAUSE_REASON);
        }
        $this->db->setState('grid_paused_reason', self::PAUSE_REASON);

        $side = $mid > $anchor ? 'above' : 'below';
        Log::warn('Grid range exit: price left the range, grid paused', [
            'symbol' => $this->symbol, 'mid' => $mid, 'anchor' => $anchor,
            'side' => $side, 'cancelled' => $cancelled, 'liquidated' => $sold,
        ]);

        $detail = 'mid=' . Util::trimZeros(Util::roundToTick($mid, $this->tickSize, 'nearest'))
            . ' ' . $side . ' anchor=' . Util::trimZeros(Util::roundToTick($anchor, $this->tickSize, 'nearest'))
            . ', cancelled ' . $cancelled;
        if ($sold !== '') {
            $detail .= ', ' . $sold;
        }
        return self::res('range_exit', $detail);
    }

    /**
     * Free wallet balance of the base asset, or null when it cannot be read (the
     * liquidation then falls back to the lot quantity, exactly as before).
     */
    private function freeBase(): ?float
    {
        $base = isset($this->info['base']) ? (string) $this->info['base'] : '';
        if ($base === '') {
            return null;
        }
        try {
            $acct = $this->ex->account();
        } catch (Throwable $e) {
            Log::warn('Grid range exit: account() failed, sizing from the lots', [
                'symbol' => $this->symbol, 'error' => $e->getMessage(),
            ]);
            return null;
        }
        if (!isset($acct['balances'][$base]) || !is_array($acct['balances'][$base])) {
            return 0.0;
        }
        $free = $acct['balances'][$base]['free'];
        return is_numeric($free) ? (float) $free : null;
    }

    /**
     * Market-sell the whole grid inventory at the exit, book the cycles FIFO and
     * record the trade. Returns a short description ('' when nothing was sold).
     */
    private function liquidate(float $bid): string
    {
        $lots  = $this->db->openLots($this->symbol, $this->mode, 'grid');
        $total = 0.0;
        foreach ($lots as $lot) {
            $total += isset($lot['remaining']) && is_numeric($lot['remaining']) ? (float) $lot['remaining'] : 0.0;
        }
        // Never ask for more base than the wallet has free: a rung whose cancel did not
        // take still locks its base, and an over-sized market sell is rejected whole (-2010).
        $free = $this->freeBase();
        if ($free !== null && $free + 1e-12 < $total) {
            Log::warn('Grid range exit: inventory exceeds the free balance, selling what is free', [
                'symbol' => $this->symbol, 'inventory' => $total, 'free' => $free,
            ]);
            $total = $free;
        }
        $qtyStr = Util::floorToStep($total, $this->stepSize);
        $qty    = (float) $qtyStr;
        if ($qty <= self::EPS || $qty < $this->minQty || $qty * $bid < $this->minNotional - 1e-9) {
            if ($total > 0) {
                Log::warn('Grid range exit: inventory below the filters, left in the wallet', [
                    'symbol' => $this->symbol, 'qty' => $total,
                ]);
                return 'inventory ' . Util::trimZeros(Util::floorToStep($total, $this->stepSize)) . ' left as dust';
            }
            return '';
        }

        $cid = substr('gx-' . preg_replace('/[^A-Za-z0-9]/', '', $this->symbol) . '-' . time(), 0, 36);
        try {
            $r = $this->ex->marketSell($this->symbol, $qtyStr, $this->info, $cid);
        } catch (Throwable $e) {
            Log::error('Grid range exit: liquidation failed', ['symbol' => $this->symbol, 'error' => $e->getMessage()]);
            return 'liquidation failed';
        }

        $filled = isset($r['qty']) && is_numeric($r['qty']) ? (float) $r['qty'] : 0.0;
        $price  = isset($r['price']) && is_numeric($r['price']) ? (float) $r['price'] : $bid;
        $net    = isset($r['quote']) && is_numeric($r['quote']) ? (float) $r['quote'] : $filled * $price;
        $fee    = isset($r['fee_usdt']) && is_numeric($r['fee_usdt']) ? (float) $r['fee_usdt'] : 0.0;
        // normalizeOrder() nets a SELL commission out of 'quote' only when it was charged in the
        // quote asset; a BNB (or any other outside) commission leaves 'quote' gross, so take it
        // off here or the cycle pnl would keep the whole exit fee as profit.
        $feeAsset = isset($r['fee_asset']) ? (string) $r['fee_asset'] : '';
        if ($fee > 0.0 && $feeAsset !== '' && $feeAsset !== $this->quoteAsset()) {
            $net = max(0.0, $net - $fee);
        }
        if ($filled <= self::EPS) {
            return 'liquidation filled nothing';
        }

        $this->db->insertTrade([
            'position_id' => null,
            'mode'        => $this->mode,
            'symbol'      => $this->symbol,
            'side'        => 'SELL',
            'order_id'    => isset($r['order_id']) ? (string) $r['order_id'] : null,
            'client_id'   => $cid,
            'qty'         => $filled,
            'price'       => $price,
            'quote'       => $net,
            'fee_usdt'    => $fee,
            'fee_asset'   => isset($r['fee_asset']) ? (string) $r['fee_asset'] : null,
            'raw'         => json_encode(isset($r['raw']) && is_array($r['raw']) ? $r['raw'] : $r),
            'created_at'  => Util::nowIso(),
        ]);
        $this->db->insertEngineOrder([
            'client_id'    => $cid,
            'order_id'     => isset($r['order_id']) ? (string) $r['order_id'] : null,
            'mode'         => $this->mode,
            'engine'       => 'grid',
            'symbol'       => $this->symbol,
            'side'         => 'SELL',
            'status'       => 'FILLED',
            'price'        => $price,
            'qty'          => $filled,
            'quote'        => $net,
            'filled_qty'   => $filled,
            'filled_quote' => $net,
            'fee_usdt'     => $fee,
            'fee_asset'    => isset($r['fee_asset']) ? (string) $r['fee_asset'] : null,
            'level'        => null,
            'purpose'      => 'grid_exit',
            'created_at'   => Util::nowIso(),
            'updated_at'   => Util::nowIso(),
        ]);
        $this->bookLiquidation($filled, $price, $net, $fee);

        return 'liquidated ' . Util::trimZeros(Util::floorToStep($filled, $this->stepSize))
            . ' for ' . Util::money($net, 4);
    }

    /** FIFO cycles for a liquidation fill: pnl = net proceeds − lot cost (the entry fee is already in the lot's price). */
    private function bookLiquidation(float $filled, float $price, float $net, float $fee): void
    {
        $slices = $this->db->consumeLots($this->symbol, $filled, $this->mode, 'grid');
        $sum    = 0.0;
        foreach ($slices as $s) {
            $sum += (float) $s['qty'];
        }
        if ($sum <= self::EPS) {
            return;
        }
        foreach ($slices as $s) {
            $lot      = $s['lot'];
            $qty      = (float) $s['qty'];
            $share    = $qty / $sum;
            $lotQty   = isset($lot['qty']) && is_numeric($lot['qty']) && (float) $lot['qty'] > 0 ? (float) $lot['qty'] : $qty;
            $lotFee   = isset($lot['fee_usdt']) && is_numeric($lot['fee_usdt']) ? (float) $lot['fee_usdt'] : 0.0;
            $buyFee   = $lotFee * ($qty / $lotQty);
            $proceeds = $net * $share;
            $gross    = $qty * $price;
            $cost     = (float) $s['cost'];
            $this->db->insertCycle([
                'mode'       => $this->mode,
                'engine'     => 'grid',
                'symbol'     => $this->symbol,
                'level'      => isset($lot['level']) && $lot['level'] !== null ? (int) $lot['level'] : null,
                'qty'        => $qty,
                'buy_price'  => isset($lot['price']) && is_numeric($lot['price']) ? (float) $lot['price'] : 0.0,
                'sell_price' => $price,
                'gross_usdt' => $gross,
                'fee_usdt'   => $fee * $share + $buyFee,
                'pnl_usdt'   => $proceeds - $cost,
                'opened_at'  => isset($lot['created_at']) ? (string) $lot['created_at'] : null,
                'closed_at'  => Util::nowIso(),
            ]);
        }
    }

    /** min(engine_max_orders, MAX_NUM_ORDERS − 2), never below 1. */
    private function orderCap(): int
    {
        $cfgCap = (int) Util::clamp((float) $this->int('engine_max_orders', 12), 1.0, (float) Risk::ENGINE_MAX_ORDERS_CAP);
        $exCap  = $this->maxNumOrders > 0 ? $this->maxNumOrders - self::ORDER_HEADROOM : $cfgCap;
        return (int) max(1, min($cfgCap, $exCap));
    }

    /* --------------------------------------------------------------- helpers */

    /** Quote asset of the engine symbol (symbol info first, then config, then USDT). */
    private function quoteAsset(): string
    {
        $q = isset($this->info['quote']) ? (string) $this->info['quote'] : '';
        if ($q === '') {
            $q = isset($this->cfg['quote_asset']) ? (string) $this->cfg['quote_asset'] : '';
        }
        return $q === '' ? 'USDT' : strtoupper($q);
    }

    private static function res(string $action, string $detail): array
    {
        return ['action' => $action, 'detail' => $detail];
    }

    /** Grid rung of an order or lot row; 0 when it carries none. */
    private static function levelOf(array $row): int
    {
        return isset($row['level']) && is_numeric($row['level']) ? (int) $row['level'] : 0;
    }

    /** Accepts either a flat symbol-info array or a map keyed by symbol. */
    private static function flatInfo(array $info, string $symbol): array
    {
        if (isset($info[$symbol]) && is_array($info[$symbol])) {
            return $info[$symbol];
        }
        if (isset($info['tickSize']) || isset($info['stepSize'])) {
            return $info;
        }
        if (count($info) === 1) {
            foreach ($info as $v) {
                if (is_array($v) && (isset($v['tickSize']) || isset($v['stepSize']))) {
                    return $v;
                }
            }
        }
        return [];
    }

    private static function symbolOf(array $cfg): string
    {
        $s = strtoupper(trim((string) ($cfg['engine_symbol'] ?? '')));
        return $s === '' ? 'DOGEUSDT' : $s;
    }

    private static function modeOf(array $cfg): string
    {
        $m = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
        return in_array($m, ['paper', 'demo', 'testnet', 'live'], true) ? $m : 'paper';
    }

    private function f(string $key, float $default): float
    {
        $v = isset($this->cfg[$key]) && is_numeric($this->cfg[$key]) ? (float) $this->cfg[$key] : $default;
        return is_finite($v) ? $v : $default;
    }

    /** A percentage config value; negatives and non-finite values fall back to the default. */
    private function pct(string $key, float $default): float
    {
        $v = $this->f($key, $default);
        return $v > 0 ? $v : $default;
    }

    private function int(string $key, int $default): int
    {
        return isset($this->cfg[$key]) && is_numeric($this->cfg[$key]) ? (int) $this->cfg[$key] : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->cfg)) {
            return $default;
        }
        $v = $this->cfg[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'on', 'yes'], true);
    }
}
