<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Binance.php';

/**
 * Market data abstraction (docs/DESIGN.md §6).
 */
interface MarketDataInterface
{
    /** @return array rows [openTime(int), open, high, low, close, volume (floats), closeTime(int)] */
    public function klines(string $symbol, string $interval, int $limit): array;
    /** @return array [symbol => float] */
    public function prices(array $symbols): array;
    /** @return array{bid: float, ask: float} */
    public function bookTicker(string $symbol): array;
    /** @return array parsed symbol info keyed by symbol (Binance::exchangeInfo shape) */
    public function symbolInfo(array $symbols): array;
    /** @return int time offset in ms (server - local) */
    public function syncTime(): int;
    public function serverTimeMs(): int;
    /**
     * 24 h rolling window statistics, normalised by Binance::normalizeTicker24h():
     * ['symbol','last','bid','ask','change_pct','quote_vol','high','low'].
     *
     * WEIGHT: 1 symbol = 2, 2..20 = 2, 21..100 = 40, more than 100 OR NONE = 80.
     * Never call this on a per-tick path; the hourly Scanner::refresh(), guarded by
     * Scanner::due(), is the only caller that passes an empty list.
     *
     * @param array $symbols concrete symbols, or [] for every symbol on the exchange
     * @return array list of normalised rows
     */
    public function ticker24h(array $symbols = []): array;
}

/**
 * Exchange abstraction: market data + account + market orders.
 */
interface ExchangeInterface extends MarketDataInterface
{
    public function mode(): string;
    /** Same shape as Binance::account(). */
    public function account(): array;
    /**
     * @return array{qty:float, dust_qty:float, price:float, quote:float, fee_usdt:float, fee_asset:string, order_id:string, raw:array}
     *  qty = SELLABLE base (floored to stepSize) after commission; quote = USDT spent (cummulativeQuoteQty)
     */
    public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array;
    /** @return array same shape; quote = USDT received NET of USDT commission; qty = executed qty */
    public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array;
    /** Normalised like marketBuy/marketSell, or null if the order is not found. */
    public function getOrder(string $symbol, string $clientId): ?array;
}

/**
 * Public market data through the Binance client (no keys needed).
 */
final class BinanceMarketData implements MarketDataInterface
{
    /** @var Binance */
    private $api;

    /** @param bool|string $network see Binance::normalizeNetwork() ('prod'|'demo'|'testnet') */
    public function __construct(?Binance $api = null, $network = false, int $recvWindow = 10000)
    {
        $this->api = $api !== null ? $api : new Binance('', '', $network, $recvWindow);
    }

    public function api(): Binance
    {
        return $this->api;
    }

    public function klines(string $symbol, string $interval, int $limit): array
    {
        return $this->api->klines($symbol, $interval, $limit);
    }

    public function prices(array $symbols): array
    {
        return $this->api->prices($symbols);
    }

    public function bookTicker(string $symbol): array
    {
        return $this->api->bookTicker($symbol);
    }

    public function symbolInfo(array $symbols): array
    {
        return $this->api->exchangeInfo($symbols);
    }

    public function syncTime(): int
    {
        return $this->api->syncTime();
    }

    public function serverTimeMs(): int
    {
        return $this->api->serverTimeMs();
    }

    /** @see MarketDataInterface::ticker24h() - weight 80 with no symbol list. */
    public function ticker24h(array $symbols = []): array
    {
        return $this->api->ticker24h($symbols);
    }
}

/**
 * Real exchange (demo, testnet or live) backed by a keyed Binance client.
 */
final class LiveExchange implements ExchangeInterface
{
    /** @var Binance */
    private $api;
    /** @var string */
    private $mode;
    /** @var float|null lazily fetched BNBUSDT price (only when a BNB commission shows up) */
    private $bnbPrice = null;
    /** @var array in-memory symbol info cache (filled by symbolInfo()/setSymbolInfo()) */
    private $infoCache = [];

    public function __construct(Binance $api, string $mode)
    {
        $this->api  = $api;
        $this->mode = $mode;
    }

    public function api(): Binance
    {
        return $this->api;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /** Optional: hand over cached symbol info (state `symbol_info`) so getOrder() needs no extra request. */
    public function setSymbolInfo(array $info): void
    {
        foreach ($info as $sym => $row) {
            if (is_array($row)) {
                $this->infoCache[(string)$sym] = $row;
            }
        }
    }

    // ---- market data

    public function klines(string $symbol, string $interval, int $limit): array
    {
        return $this->api->klines($symbol, $interval, $limit);
    }

    public function prices(array $symbols): array
    {
        return $this->api->prices($symbols);
    }

    public function bookTicker(string $symbol): array
    {
        return $this->api->bookTicker($symbol);
    }

    public function symbolInfo(array $symbols): array
    {
        $info = $this->api->exchangeInfo($symbols);
        $this->setSymbolInfo($info);
        return $info;
    }

    public function syncTime(): int
    {
        return $this->api->syncTime();
    }

    public function serverTimeMs(): int
    {
        return $this->api->serverTimeMs();
    }

    /** @see MarketDataInterface::ticker24h() - weight 80 with no symbol list. */
    public function ticker24h(array $symbols = []): array
    {
        return $this->api->ticker24h($symbols);
    }

    // ---- account / orders

    public function account(): array
    {
        return $this->api->account();
    }

    public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array
    {
        $quoteStr = Util::fmtQuote($quoteUsdt);
        $raw = $this->api->marketBuyQuote($symbol, $quoteStr, $clientId);
        return Binance::normalizeOrder($raw, $info, $this->bnbPriceFor($raw, $info));
    }

    public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array
    {
        $raw = $this->api->marketSellQty($symbol, $qtyStr, $clientId);
        return Binance::normalizeOrder($raw, $info, $this->bnbPriceFor($raw, $info));
    }

    public function getOrder(string $symbol, string $clientId): ?array
    {
        try {
            $raw = $this->api->getOrder($symbol, $clientId);
        } catch (BinanceException $e) {
            if ($e->binanceCode === -2013) {
                return null;   // Order does not exist
            }
            throw $e;
        }
        if (!is_array($raw) || !isset($raw['orderId'])) {
            return null;
        }
        $info = $this->infoFor($symbol);
        // GET /order carries no fills → recover commissions from myTrades (best effort)
        if ((!isset($raw['fills']) || !is_array($raw['fills'])) && (float)($raw['executedQty'] ?? 0) > 0.0) {
            try {
                $trades = $this->api->myTrades($symbol, (string)$raw['orderId']);
                $fills  = [];
                foreach ($trades as $t) {
                    if (!is_array($t)) {
                        continue;
                    }
                    if (isset($t['orderId']) && (string)$t['orderId'] !== (string)$raw['orderId']) {
                        continue;
                    }
                    $fills[] = [
                        'price'           => (string)($t['price'] ?? '0'),
                        'qty'             => (string)($t['qty'] ?? '0'),
                        'commission'      => (string)($t['commission'] ?? '0'),
                        'commissionAsset' => (string)($t['commissionAsset'] ?? ''),
                        'tradeId'         => $t['id'] ?? null,
                    ];
                }
                if (count($fills) > 0) {
                    $raw['fills'] = $fills;
                }
            } catch (BinanceException $e) {
                // proceed without commission details
            }
        }
        return Binance::normalizeOrder($raw, $info, $this->bnbPriceFor($raw, $info));
    }

    // ---- limit orders (DESIGN-ENGINES.md §3)

    /** LIMIT (GTC) or LIMIT_MAKER when $postOnly; $qtyStr/$priceStr are pre-rounded exact strings. */
    public function limitOrder(string $symbol, string $side, string $qtyStr, string $priceStr, array $info, string $clientId, bool $postOnly): array
    {
        $raw = $this->api->limitOrder($symbol, $side, $qtyStr, $priceStr, $clientId, $postOnly);
        if (count($info) === 0) {
            $info = $this->infoFor($symbol);
        }
        return Binance::normalizeOrder($raw, $info, $this->bnbPriceFor($raw, $info));
    }

    /** DELETE /api/v3/order. A -2011 (already filled or gone) is reported, not thrown. */
    public function cancelOrder(string $symbol, string $clientId): array
    {
        return $this->api->cancelOrder($symbol, $clientId);
    }

    /** DELETE /api/v3/openOrders: cancels every resting order of $symbol. */
    public function cancelAllOrders(string $symbol): array
    {
        return $this->api->cancelAllOrders($symbol);
    }

    /** GET /api/v3/openOrders?symbol=… (symbol is always sent: unfiltered costs weight 80). */
    public function openOrders(string $symbol): array
    {
        return $this->api->openOrders($symbol);
    }

    // ---- helpers

    private function infoFor(string $symbol): array
    {
        if (isset($this->infoCache[$symbol])) {
            return $this->infoCache[$symbol];
        }
        $all = $this->api->exchangeInfo([$symbol]);
        $this->setSymbolInfo($all);
        if (isset($all[$symbol])) {
            return $all[$symbol];
        }
        // unknown symbol: derive a permissive default so normalisation still works
        $quote = 'USDT';
        $base  = $symbol;
        if (strlen($symbol) > 4 && substr($symbol, -4) === 'USDT') {
            $base = substr($symbol, 0, -4);
        }
        return ['base' => $base, 'quote' => $quote, 'stepSize' => '0.00000001'];
    }

    /** Returns the BNBUSDT price only if the response carries a BNB commission that is not the base asset. */
    private function bnbPriceFor(array $raw, array $info): ?float
    {
        $base = (string)($info['base'] ?? '');
        if ($base === 'BNB') {
            return null;   // commission in base asset, valued at fill price by normalizeOrder
        }
        $fills = isset($raw['fills']) && is_array($raw['fills']) ? $raw['fills'] : [];
        $needs = false;
        foreach ($fills as $f) {
            if (is_array($f) && isset($f['commissionAsset']) && (string)$f['commissionAsset'] === 'BNB'
                && (float)($f['commission'] ?? 0) > 0.0) {
                $needs = true;
                break;
            }
        }
        if (!$needs) {
            return null;
        }
        if ($this->bnbPrice === null) {
            try {
                $p = $this->api->prices(['BNBUSDT']);
                if (isset($p['BNBUSDT']) && $p['BNBUSDT'] > 0.0) {
                    $this->bnbPrice = (float)$p['BNBUSDT'];
                }
            } catch (BinanceException $e) {
                // leave null; fee_usdt for the BNB part becomes 0
            }
        }
        return $this->bnbPrice;
    }
}

/**
 * Simulated exchange: live market data, simulated fills at ask/bid, balances in state `paper_balances`.
 */
final class PaperExchange implements ExchangeInterface
{
    const STATE_KEY = 'paper_balances';

    /** Resting limit orders, persisted so a ladder survives between cron ticks. */
    const ORDERS_KEY = 'paper_orders';

    /** Quantities and notionals below this are treated as zero. */
    const EPS = 1.0e-12;

    /** Terminal orders retained for getOrder() before the oldest are dropped. */
    const KEEP_TERMINAL = 300;

    /** @var MarketDataInterface */
    private $md;
    /** @var Db */
    private $db;
    /** @var float fraction, e.g. 0.001 */
    private $fee;
    /** @var float */
    private $startUsdt;
    /** @var string */
    private $quote;
    /** @var array [asset => free] */
    private $balances = [];
    /** @var int */
    private $seq = 0;
    /** @var array clientId => resting/terminal limit order row */
    private $orders = [];
    /** @var bool reentrancy guard: matching reads the book, the book triggers matching */
    private $matching = false;

    public function __construct(MarketDataInterface $md, Db $db, float $feePct, float $startUsdt, string $quote = 'USDT')
    {
        $this->md        = $md;
        $this->db        = $db;
        $this->fee       = max(0.0, $feePct) / 100.0;
        $this->startUsdt = $startUsdt;
        $this->quote     = $quote;
        $this->load();
    }

    public function mode(): string
    {
        return 'paper';
    }

    /**
     * Underlying Binance client of the wrapped market-data source, when there is one.
     * Fixture-backed market data (tests) has none, so callers must tolerate null.
     */
    public function api(): ?Binance
    {
        return method_exists($this->md, 'api') ? $this->md->api() : null;
    }

    public function feePct(): float
    {
        return $this->fee * 100.0;
    }

    /** Resets balances to the starting quote amount and forgets every simulated order. */
    public function reset(): void
    {
        $this->balances = [$this->quote => $this->startUsdt];
        $this->orders   = [];
        $this->save();
        $this->saveOrders();
    }

    /** @return array [asset => free] */
    public function balances(): array
    {
        return $this->balances;
    }

    // ---- market data (delegated)

    public function klines(string $symbol, string $interval, int $limit): array
    {
        return $this->md->klines($symbol, $interval, $limit);
    }

    public function prices(array $symbols): array
    {
        return $this->md->prices($symbols);
    }

    public function bookTicker(string $symbol): array
    {
        return $this->md->bookTicker($symbol);
    }

    public function symbolInfo(array $symbols): array
    {
        return $this->md->symbolInfo($symbols);
    }

    public function syncTime(): int
    {
        return $this->md->syncTime();
    }

    public function serverTimeMs(): int
    {
        return $this->md->serverTimeMs();
    }

    /**
     * @see MarketDataInterface::ticker24h() - delegated, never simulated: the 24 h window is
     * market data, not account state, so paper mode reports the real market.
     */
    public function ticker24h(array $symbols = []): array
    {
        return $this->md->ticker24h($symbols);
    }

    // ---- account / orders

    public function account(): array
    {
        $this->matchOrders();
        $balances = [];
        $assets   = array_keys($this->balances);
        foreach (array_keys($this->lockedAll()) as $a) {
            if (!in_array($a, $assets, true)) {
                $assets[] = $a;
            }
        }
        foreach ($assets as $asset) {
            $free   = $this->balanceOf((string)$asset);
            $locked = $this->lockedOf((string)$asset);
            if ($free > 0.0 || $locked > 0.0) {
                $balances[(string)$asset] = ['free' => $free, 'locked' => $locked];
            }
        }
        return [
            'balances'      => $balances,
            'taker_fee_pct' => $this->fee * 100.0,
            'can_trade'     => true,
        ];
    }

    public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array
    {
        if ($quoteUsdt <= 0.0) {
            throw new BinanceException('Paper BUY ' . $symbol . ': quote amount must be positive', -1013, 400);
        }
        $base  = $this->baseOf($symbol, $info);
        $step  = (string)($info['stepSize'] ?? '0.00000001');
        $quoteUsdt = (float)Util::fmtQuote($quoteUsdt);
        $free  = $this->balanceOf($this->quote);
        if ($free + 1e-9 < $quoteUsdt) {
            throw new BinanceException('Paper BUY ' . $symbol . ': insufficient ' . $this->quote . ' balance', -2010, 400);
        }
        $book = $this->md->bookTicker($symbol);
        $ask  = (float)($book['ask'] ?? 0);
        if ($ask <= 0.0) {
            throw new BinanceException('Paper BUY ' . $symbol . ': no ask price available', -1000, 400);
        }
        $gross      = $quoteUsdt / $ask;
        $commission = $gross * $this->fee;
        $net        = $gross - $commission;
        $qty        = (float)Util::floorToStep(self::roundStr($net), $step);
        $dust       = max(0.0, $net - $qty);
        $feeUsdt    = $commission * $ask;

        $this->balances[$this->quote] = $free - $quoteUsdt;
        $this->balances[$base] = $this->balanceOf($base) + $net;
        $this->save();

        $orderId = $this->nextOrderId();
        $raw = [
            'symbol'              => $symbol,
            'orderId'             => $orderId,
            'clientOrderId'       => $clientId,
            'transactTime'        => Util::nowMs(),
            'price'               => '0',
            'origQty'             => self::roundStr($gross),
            'executedQty'         => self::roundStr($gross),
            'cummulativeQuoteQty' => Util::fmtQuote($quoteUsdt),
            'status'              => 'FILLED',
            'type'                => 'MARKET',
            'side'                => 'BUY',
            'paper'               => true,
            'fills'               => [[
                'price'           => self::roundStr($ask),
                'qty'             => self::roundStr($gross),
                'commission'      => self::roundStr($commission),
                'commissionAsset' => $base,
            ]],
        ];
        $result = [
            'qty'       => $qty,
            'dust_qty'  => $dust,
            'price'     => $ask,
            'quote'     => $quoteUsdt,
            'fee_usdt'  => $feeUsdt,
            'fee_asset' => $base,
            'order_id'  => $orderId,
            'status'    => 'FILLED',
            'raw'       => $raw,
        ];
        $this->recordOrder($clientId, $symbol, 'BUY', $result);
        return $result;
    }

    public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array
    {
        $base = $this->baseOf($symbol, $info);
        $qty  = (float)$qtyStr;
        if ($qty <= 0.0) {
            throw new BinanceException('Paper SELL ' . $symbol . ': quantity must be positive', -1013, 400);
        }
        $have = $this->balanceOf($base);
        if ($have + 1e-9 < $qty) {
            throw new BinanceException('Paper SELL ' . $symbol . ': insufficient ' . $base . ' balance', -2010, 400);
        }
        $book = $this->md->bookTicker($symbol);
        $bid  = (float)($book['bid'] ?? 0);
        if ($bid <= 0.0) {
            throw new BinanceException('Paper SELL ' . $symbol . ': no bid price available', -1000, 400);
        }
        $grossQuote = $qty * $bid;
        $feeUsdt    = $grossQuote * $this->fee;
        $quote      = $grossQuote - $feeUsdt;

        $newBase = $have - $qty;
        if ($newBase < 1e-12) {
            $newBase = 0.0;
        }
        $this->balances[$base] = $newBase;
        $this->balances[$this->quote] = $this->balanceOf($this->quote) + $quote;
        $this->save();

        $orderId = $this->nextOrderId();
        $raw = [
            'symbol'              => $symbol,
            'orderId'             => $orderId,
            'clientOrderId'       => $clientId,
            'transactTime'        => Util::nowMs(),
            'price'               => '0',
            'origQty'             => $qtyStr,
            'executedQty'         => $qtyStr,
            'cummulativeQuoteQty' => self::roundStr($grossQuote),
            'status'              => 'FILLED',
            'type'                => 'MARKET',
            'side'                => 'SELL',
            'paper'               => true,
            'fills'               => [[
                'price'           => self::roundStr($bid),
                'qty'             => $qtyStr,
                'commission'      => self::roundStr($feeUsdt),
                'commissionAsset' => $this->quote,
            ]],
        ];
        $result = [
            'qty'       => $qty,
            'dust_qty'  => 0.0,
            'price'     => $bid,
            'quote'     => $quote,
            'fee_usdt'  => $feeUsdt,
            'fee_asset' => $this->quote,
            'order_id'  => $orderId,
            'status'    => 'FILLED',
            'raw'       => $raw,
        ];
        $this->recordOrder($clientId, $symbol, 'SELL', $result);
        return $result;
    }

    /** Returns the state of a paper order (limit orders first), or null when this exchange never saw it. */
    public function getOrder(string $symbol, string $clientId): ?array
    {
        $this->matchOrders();
        if (isset($this->orders[$clientId])) {
            return $this->normalise($this->orders[$clientId]);
        }
        try {
            $st = $this->db->pdo()->prepare('SELECT status, raw FROM orders WHERE client_id = ? AND mode = ? LIMIT 1');
            $st->execute([$clientId, 'paper']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($row) || !isset($row['raw']) || (string)$row['raw'] === '') {
            return null;
        }
        $decoded = json_decode((string)$row['raw'], true);
        if (!is_array($decoded) || !isset($decoded['paper_result']) || !is_array($decoded['paper_result'])) {
            return null;
        }
        $result = $decoded['paper_result'];
        $result['raw'] = isset($decoded['raw']) && is_array($decoded['raw']) ? $decoded['raw'] : [];
        return $result;
    }

    // ---- limit orders (DESIGN-ENGINES.md §11: a resting order fills when the book crosses it)

    /**
     * Post one LIMIT (GTC) or LIMIT_MAKER order. $qtyStr/$priceStr are the exact pre-rounded
     * decimal strings (Util::floorToStep / Util::roundToTick) the real API is sent.
     *
     * A post-only order that would cross the book is rejected with -2010 "would immediately
     * match and take" — the rejection DESIGN-ENGINES.md §3 calls normal. A plain LIMIT that
     * crosses fills at once. Everything else rests until the book reaches it, and the funds it
     * needs are locked exactly as the exchange locks them.
     */
    public function limitOrder(string $symbol, string $side, string $qtyStr, string $priceStr, array $info, string $clientId, bool $postOnly): array
    {
        $symbol = strtoupper($symbol);
        $side   = strtoupper(trim($side)) === 'SELL' ? 'SELL' : 'BUY';
        $qty    = (float)$qtyStr;
        $price  = (float)$priceStr;
        $base   = $this->baseOf($symbol, $info);
        if ($qty <= 0.0 || $price <= 0.0) {
            throw new BinanceException('Paper ' . $side . ' ' . $symbol . ': quantity and price must be positive', -1013, 400);
        }
        if (isset($this->orders[$clientId])) {
            throw new BinanceException('Duplicate order sent.', -2010, 400);
        }
        $minQty = (float)($info['minQty'] ?? 0);
        if ($minQty > 0.0 && $qty + self::EPS < $minQty) {
            throw new BinanceException('Filter failure: LOT_SIZE', -1013, 400);
        }
        $minNotional = (float)($info['minNotional'] ?? 0);
        if ($minNotional > 0.0 && $qty * $price + 1e-9 < $minNotional) {
            throw new BinanceException('Filter failure: NOTIONAL', -1013, 400);
        }

        $book    = $this->bookOf($symbol);
        $crosses = $book !== null
            && ($side === 'BUY' ? ($book['ask'] <= $price + self::EPS) : ($book['bid'] + self::EPS >= $price));
        if ($crosses && $postOnly) {
            throw new BinanceException('Order would immediately match and take.', -2010, 400);
        }

        // lock the funds the resting order needs, exactly like the exchange does
        if ($side === 'BUY') {
            $need = $qty * $price;
            if ($this->balanceOf($this->quote) + 1e-9 < $need) {
                throw new BinanceException('Account has insufficient balance for requested action.', -2010, 400);
            }
            $this->balances[$this->quote] = $this->balanceOf($this->quote) - $need;
        } else {
            if ($this->balanceOf($base) + 1e-9 < $qty) {
                throw new BinanceException('Account has insufficient balance for requested action.', -2010, 400);
            }
            $this->balances[$base] = $this->balanceOf($base) - $qty;
        }
        $this->save();

        $now = Util::nowMs();
        $this->orders[$clientId] = [
            'client_id'    => $clientId,
            'order_id'     => $this->nextOrderId(),
            'symbol'       => $symbol,
            'base'         => $base,
            'side'         => $side,
            'type'         => $postOnly ? 'LIMIT_MAKER' : 'LIMIT',
            'price'        => $price,
            'price_str'    => $priceStr,
            'qty'          => $qty,
            'qty_str'      => $qtyStr,
            'step'         => (string)($info['stepSize'] ?? '0.00000001'),
            'status'       => 'NEW',
            'executed_qty' => 0.0,
            'cum_quote'    => 0.0,
            'net_qty'      => 0.0,
            'dust_qty'     => 0.0,
            'fee'          => 0.0,
            'fee_asset'    => '',
            'proceeds'     => 0.0,
            'time'         => $now,
        ];
        if ($crosses) {
            $this->fillOrder($clientId);   // a plain LIMIT that crosses takes immediately
        }
        $this->saveOrders();
        return $this->normalise($this->orders[$clientId]);
    }

    /** DELETE /api/v3/order. An order that already filled or vanished answers -2011, like Binance. */
    public function cancelOrder(string $symbol, string $clientId): array
    {
        $this->matchOrders();
        if (!isset($this->orders[$clientId]) || $this->orders[$clientId]['status'] !== 'NEW') {
            throw new BinanceException('Unknown order sent.', -2011, 400);
        }
        $this->releaseOrder($clientId);
        $this->orders[$clientId]['status'] = 'CANCELED';
        $this->saveOrders();
        return $this->rawOrder($this->orders[$clientId]);
    }

    /** DELETE /api/v3/openOrders: cancels every resting order of $symbol. */
    public function cancelAllOrders(string $symbol): array
    {
        $this->matchOrders();
        $symbol = strtoupper($symbol);
        $out    = [];
        foreach ($this->orders as $cid => $o) {
            if ($o['status'] !== 'NEW' || $o['symbol'] !== $symbol) {
                continue;
            }
            $this->releaseOrder((string)$cid);
            $this->orders[$cid]['status'] = 'CANCELED';
            $out[] = $this->rawOrder($this->orders[$cid]);
        }
        if (count($out) > 0) {
            $this->saveOrders();
        }
        return $out;
    }

    /**
     * GET /api/v3/openOrders?symbol=… normalised to the DESIGN-ENGINES.md §3 shape:
     * [clientOrderId => ['order_id','symbol','side','price','orig_qty','executed_qty','status','time']].
     */
    public function openOrders(string $symbol): array
    {
        $this->matchOrders();
        $symbol = strtoupper($symbol);
        $out    = [];
        foreach ($this->orders as $o) {
            if ($o['symbol'] !== $symbol || ($o['status'] !== 'NEW' && $o['status'] !== 'PARTIALLY_FILLED')) {
                continue;
            }
            $out[(string)$o['client_id']] = [
                'order_id'     => (string)$o['order_id'],
                'symbol'       => (string)$o['symbol'],
                'side'         => (string)$o['side'],
                'price'        => (float)$o['price'],
                'orig_qty'     => (float)$o['qty'],
                'executed_qty' => (float)$o['executed_qty'],
                'status'       => (string)$o['status'],
                'time'         => (int)$o['time'],
            ];
        }
        return $out;
    }

    /**
     * Fill every resting order the book has crossed: BUY when ask <= price, SELL when bid >= price.
     * Idempotent — a filled order is never matched twice. Runs lazily from the market-data and
     * account calls, so a caller only has to move the price and tick again.
     *
     * @return int number of orders filled by this pass
     */
    public function matchOrders(): int
    {
        if ($this->matching) {
            return 0;
        }
        $this->matching = true;
        $filled = 0;
        try {
            foreach ($this->orders as $cid => $o) {
                if ($o['status'] !== 'NEW') {
                    continue;
                }
                $book = $this->bookOf((string)$o['symbol']);
                if ($book === null) {
                    continue;
                }
                if ($o['side'] === 'BUY' ? ($book['ask'] <= $o['price'] + self::EPS) : ($book['bid'] + self::EPS >= $o['price'])) {
                    $this->fillOrder((string)$cid);
                    $filled++;
                }
            }
        } finally {
            $this->matching = false;
        }
        if ($filled > 0) {
            $this->saveOrders();
        }
        return $filled;
    }

    /** Number of orders still resting (optionally for one symbol only). */
    public function openOrderCount(string $symbol = ''): int
    {
        $symbol = strtoupper($symbol);
        $n = 0;
        foreach ($this->orders as $o) {
            if ($o['status'] === 'NEW' && ($symbol === '' || $o['symbol'] === $symbol)) {
                $n++;
            }
        }
        return $n;
    }

    /** Quote/base funds currently locked by resting orders. */
    public function lockedOf(string $asset): float
    {
        $all = $this->lockedAll();
        return isset($all[$asset]) ? $all[$asset] : 0.0;
    }

    // ---- internals

    private function load(): void
    {
        $stored = $this->db->getStateJson(self::ORDERS_KEY, null);
        if (is_array($stored)) {
            foreach ($stored as $cid => $row) {
                if (is_array($row) && isset($row['client_id'])) {
                    $this->orders[(string)$cid] = $row;
                }
            }
        }
        $stored = $this->db->getStateJson(self::STATE_KEY, null);
        if (is_array($stored) && count($stored) > 0) {
            $this->balances = [];
            foreach ($stored as $asset => $free) {
                $this->balances[(string)$asset] = (float)$free;
            }
            return;
        }
        $this->reset();
    }

    private function save(): void
    {
        $clean = [];
        foreach ($this->balances as $asset => $free) {
            $free = (float)$free;
            if ($free > 0.0 || (string)$asset === $this->quote) {
                $clean[(string)$asset] = $free;
            }
        }
        $this->balances = $clean;
        $this->db->setState(self::STATE_KEY, $clean);
    }

    /** Persists the simulated order book, keeping the resting orders and the newest terminal ones. */
    private function saveOrders(): void
    {
        $live = [];
        $done = [];
        foreach ($this->orders as $cid => $o) {
            if ($o['status'] === 'NEW' || $o['status'] === 'PARTIALLY_FILLED') {
                $live[(string)$cid] = $o;
            } else {
                $done[(string)$cid] = $o;
            }
        }
        if (count($done) > self::KEEP_TERMINAL) {
            $done = array_slice($done, -self::KEEP_TERMINAL, null, true);
        }
        $this->orders = $live + $done;
        $this->db->setState(self::ORDERS_KEY, $this->orders);
    }

    /**
     * Fill a resting order completely at its own price, with the fee and dust accounting of the
     * market fills: a BUY pays its commission in the base asset (so the sellable quantity is
     * floored to the step and the remainder is dust), a SELL pays it in the quote asset.
     */
    private function fillOrder(string $clientId): void
    {
        $o = $this->orders[$clientId];
        if ($o['status'] !== 'NEW') {
            return;
        }
        $qty   = (float)$o['qty'];
        $price = (float)$o['price'];
        if ($o['side'] === 'BUY') {
            // the quote was taken out of free at placement and is spent here
            $spent      = $qty * $price;
            $commission = $qty * $this->fee;
            $net        = $qty - $commission;
            $sellable   = (float)Util::floorToStep(self::roundStr($net), (string)$o['step']);
            $this->balances[$o['base']] = $this->balanceOf((string)$o['base']) + $net;
            $o['cum_quote'] = $spent;
            $o['net_qty']   = $sellable;
            $o['dust_qty']  = max(0.0, $net - $sellable);
            $o['fee']       = $commission * $price;
            $o['fee_asset'] = (string)$o['base'];
            $o['proceeds']  = $spent;
        } else {
            // the base was taken out of free at placement and is delivered here
            $gross   = $qty * $price;
            $feeUsdt = $gross * $this->fee;
            $this->balances[$this->quote] = $this->balanceOf($this->quote) + ($gross - $feeUsdt);
            $o['cum_quote'] = $gross;
            $o['net_qty']   = $qty;
            $o['dust_qty']  = 0.0;
            $o['fee']       = $feeUsdt;
            $o['fee_asset'] = $this->quote;
            $o['proceeds']  = $gross - $feeUsdt;
        }
        $o['executed_qty'] = $qty;
        $o['status']       = 'FILLED';
        $this->orders[$clientId] = $o;
        $this->save();
    }

    /** Give the funds a resting order still locks back to the free balance. */
    private function releaseOrder(string $clientId): void
    {
        $o = $this->orders[$clientId];
        if ($o['side'] === 'BUY') {
            $this->balances[$this->quote] = $this->balanceOf($this->quote) + (float)$o['qty'] * (float)$o['price'];
        } else {
            $this->balances[$o['base']] = $this->balanceOf((string)$o['base']) + (float)$o['qty'];
        }
        $this->save();
    }

    /**
     * Funds locked by the resting orders, derived rather than stored so the locked amounts can
     * never drift away from the orders that cause them.
     *
     * @return array [asset => locked]
     */
    private function lockedAll(): array
    {
        $out = [];
        foreach ($this->orders as $o) {
            if ($o['status'] !== 'NEW' && $o['status'] !== 'PARTIALLY_FILLED') {
                continue;
            }
            if ($o['side'] === 'BUY') {
                $asset  = $this->quote;
                $amount = (float)$o['qty'] * (float)$o['price'];
            } else {
                $asset  = (string)$o['base'];
                $amount = (float)$o['qty'];
            }
            $out[$asset] = (isset($out[$asset]) ? $out[$asset] : 0.0) + $amount;
        }
        return $out;
    }

    /** Normalised order state in the Binance::normalizeOrder() shape EngineOrders books. */
    private function normalise(array $o): array
    {
        return [
            'qty'       => (float)$o['net_qty'],
            'dust_qty'  => (float)$o['dust_qty'],
            'price'     => (float)$o['price'],
            'quote'     => (float)$o['proceeds'],
            'fee_usdt'  => (float)$o['fee'],
            'fee_asset' => (string)$o['fee_asset'],
            'order_id'  => (string)$o['order_id'],
            'status'    => (string)$o['status'],
            'raw'       => $this->rawOrder($o),
        ];
    }

    /** Raw Binance order row (every number an exact decimal string, never an exponent). */
    private function rawOrder(array $o): array
    {
        return [
            'symbol'              => (string)$o['symbol'],
            'orderId'             => (string)$o['order_id'],
            'clientOrderId'       => (string)$o['client_id'],
            'price'               => (string)$o['price_str'],
            'origQty'             => (string)$o['qty_str'],
            'executedQty'         => self::roundStr((float)$o['executed_qty']),
            'cummulativeQuoteQty' => self::roundStr((float)$o['cum_quote']),
            'status'              => (string)$o['status'],
            'timeInForce'         => 'GTC',
            'type'                => (string)$o['type'],
            'side'                => (string)$o['side'],
            'time'                => (int)$o['time'],
            'updateTime'          => (int)$o['time'],
            'isWorking'           => true,
            'paper'               => true,
        ];
    }

    /** Book of $symbol, or null when there is no usable price for it. */
    private function bookOf(string $symbol): ?array
    {
        try {
            $b = $this->md->bookTicker($symbol);
        } catch (Throwable $e) {
            return null;
        }
        $bid = (float)($b['bid'] ?? 0);
        $ask = (float)($b['ask'] ?? 0);
        return ($bid > 0.0 && $ask > 0.0) ? ['bid' => $bid, 'ask' => $ask] : null;
    }

    private function balanceOf(string $asset): float
    {
        return isset($this->balances[$asset]) ? (float)$this->balances[$asset] : 0.0;
    }

    private function baseOf(string $symbol, array $info): string
    {
        if (isset($info['base']) && (string)$info['base'] !== '') {
            return (string)$info['base'];
        }
        $q = $this->quote;
        if (strlen($symbol) > strlen($q) && substr($symbol, -strlen($q)) === $q) {
            return substr($symbol, 0, -strlen($q));
        }
        return $symbol;
    }

    private function nextOrderId(): string
    {
        $this->seq++;
        return 'paper-' . Util::nowMs() . '-' . $this->seq;
    }

    /** Stores the fill in the orders table as DONE (updates a pre-inserted SENDING row, inserts otherwise). */
    private function recordOrder(string $clientId, string $symbol, string $side, array $result): void
    {
        $now = Util::nowIso();
        $payload = $result;
        $raw = $payload['raw'];
        unset($payload['raw']);
        $json = json_encode(['paper_result' => $payload, 'raw' => $raw]);
        if ($json === false) {
            $json = '{}';
        }
        try {
            $st = $this->db->pdo()->prepare('SELECT 1 FROM orders WHERE client_id = ? LIMIT 1');
            $st->execute([$clientId]);
            $exists = $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            $exists = false;
        }
        if ($exists) {
            $this->db->updateOrder($clientId, ['status' => 'DONE', 'updated_at' => $now, 'raw' => $json]);
            return;
        }
        $this->db->insertOrder([
            'client_id'   => $clientId,
            'position_id' => null,
            'mode'        => 'paper',
            'symbol'      => $symbol,
            'side'        => $side,
            'status'      => 'DONE',
            'created_at'  => $now,
            'updated_at'  => $now,
            'raw'         => $json,
        ]);
    }

    /** Float → plain decimal string (10 dp, trimmed, no exponent) for simulated raw fields and exact flooring. */
    private static function roundStr(float $v): string
    {
        $s = sprintf('%.10F', $v);
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s === '' || $s === '-0' ? '0' : $s;
    }
}

/**
 * Chooses the exchange implementation from config.
 */
final class Exchange
{
    /**
     * @throws RuntimeException when live/testnet/demo keys are missing or the mode is unknown
     */
    public static function factory(array $cfg, Db $db): ExchangeInterface
    {
        $mode  = isset($cfg['mode']) ? strtolower(trim((string)$cfg['mode'])) : 'paper';
        $recv  = isset($cfg['recv_window']) ? (int)$cfg['recv_window'] : 10000;
        $quote = isset($cfg['quote_asset']) && (string)$cfg['quote_asset'] !== '' ? (string)$cfg['quote_asset'] : 'USDT';

        if ($mode === 'paper') {
            $api = new Binance('', '', false, $recv);
            self::applyStoredOffset($api, $db);
            $md  = new BinanceMarketData($api);
            $fee   = isset($cfg['fee_pct']) ? (float)$cfg['fee_pct'] : 0.1;
            $start = isset($cfg['paper_start_usdt']) ? (float)$cfg['paper_start_usdt'] : 10.0;
            return new PaperExchange($md, $db, $fee, $start, $quote);
        }

        if ($mode === 'live' || $mode === 'testnet' || $mode === 'demo') {
            $key    = isset($cfg['api_key']) ? trim((string)$cfg['api_key']) : '';
            $secret = isset($cfg['api_secret']) ? trim((string)$cfg['api_secret']) : '';
            if ($key === '' || $secret === '') {
                throw new RuntimeException(
                    'Mode "' . $mode . '" requires a Binance API key and secret — enter them in Settings or switch to paper mode.'
                );
            }
            $api = new Binance($key, $secret, Binance::normalizeNetwork($mode), $recv);
            self::applyStoredOffset($api, $db);
            return new LiveExchange($api, $mode);
        }

        throw new RuntimeException('Unknown trading mode "' . $mode . '" (expected paper, demo, testnet or live).');
    }

    private static function applyStoredOffset(Binance $api, Db $db): void
    {
        try {
            $off = $db->getState('time_offset_ms', null);
            if ($off !== null && is_numeric($off)) {
                $api->setTimeOffset((int)$off);
            }
        } catch (Throwable $e) {
            // state not available yet (fresh install) — offset stays 0 until syncTime()
        }
    }
}
