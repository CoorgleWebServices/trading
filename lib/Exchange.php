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

    public function __construct(?Binance $api = null, bool $testnet = false, int $recvWindow = 10000)
    {
        $this->api = $api !== null ? $api : new Binance('', '', $testnet, $recvWindow);
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
}

/**
 * Real exchange (testnet or live) backed by a keyed Binance client.
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

    /** Resets balances to the starting quote amount. */
    public function reset(): void
    {
        $this->balances = [$this->quote => $this->startUsdt];
        $this->save();
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

    // ---- account / orders

    public function account(): array
    {
        $balances = [];
        foreach ($this->balances as $asset => $free) {
            $free = (float)$free;
            if ($free > 0.0) {
                $balances[(string)$asset] = ['free' => $free, 'locked' => 0.0];
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

    /** Returns the recorded fill for a paper order, or null when the order was never filled here. */
    public function getOrder(string $symbol, string $clientId): ?array
    {
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

    // ---- internals

    private function load(): void
    {
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
     * @throws RuntimeException when live/testnet keys are missing or the mode is unknown
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

        if ($mode === 'live' || $mode === 'testnet') {
            $key    = isset($cfg['api_key']) ? trim((string)$cfg['api_key']) : '';
            $secret = isset($cfg['api_secret']) ? trim((string)$cfg['api_secret']) : '';
            if ($key === '' || $secret === '') {
                throw new RuntimeException(
                    'Mode "' . $mode . '" requires a Binance API key and secret — enter them in Settings or switch to paper mode.'
                );
            }
            $api = new Binance($key, $secret, $mode === 'testnet', $recv);
            self::applyStoredOffset($api, $db);
            return new LiveExchange($api, $mode);
        }

        throw new RuntimeException('Unknown trading mode "' . $mode . '" (expected paper, testnet or live).');
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
