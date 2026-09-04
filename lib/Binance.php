<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * Exception thrown for any Binance API / transport failure.
 *
 *  - $binanceCode : Binance error code (e.g. -1021, -2010), -1007 for "order status unknown"
 *                   (5xx / cURL failure on POST /api/v3/order), 0 for a plain network error.
 *  - $httpStatus  : HTTP status (0 when the request never completed).
 *  - $retryAfter  : seconds to wait, set for HTTP 429/418 only.
 *
 * The message never contains the secret, the signature, the full URL or request headers.
 */
final class BinanceException extends RuntimeException
{
    /** @var int */
    public $binanceCode = 0;
    /** @var int */
    public $httpStatus = 0;
    /** @var int|null */
    public $retryAfter = null;

    public function __construct(string $message, int $binanceCode = 0, int $httpStatus = 0, ?int $retryAfter = null)
    {
        parent::__construct($message, $binanceCode);
        $this->binanceCode = $binanceCode;
        $this->httpStatus  = $httpStatus;
        $this->retryAfter  = $retryAfter;
    }

    /**
     * True for the LIMIT_MAKER rejection "Order would immediately match and take"
     * (-2010). A post-only quote that would cross the book is refused by the
     * exchange; that is a normal, expected outcome for a maker quote, not an error:
     * the caller logs at debug and skips that quote for this tick.
     */
    public function isPostOnlyReject(): bool
    {
        if ($this->binanceCode !== -2010) {
            return false;
        }
        $m = strtolower($this->getMessage());
        return strpos($m, 'would immediately match') !== false
            || strpos($m, 'immediately match') !== false;
    }

    public function isNetworkError(): bool
    {
        return $this->httpStatus === 0;
    }
}

/**
 * Minimal signed/unsigned Binance SPOT REST client (prod, demo, testnet, data-api).
 * See docs/DESIGN.md §6.
 */
final class Binance
{
    const URL_PROD    = 'https://api.binance.com';
    const URL_TESTNET = 'https://testnet.binance.vision';
    const URL_DEMO    = 'https://demo-api.binance.com';
    const URL_DATA    = 'https://data-api.binance.vision';

    /** Networks this client can talk to. Paths and signing are identical on all of them. */
    const NET_PROD    = 'prod';
    const NET_TESTNET = 'testnet';
    const NET_DEMO    = 'demo';

    const CONNECT_TIMEOUT = 8;
    const TIMEOUT         = 15;

    /** @var string */
    private $apiKey;
    /** @var string */
    private $apiSecret;
    /** @var string one of NET_PROD, NET_TESTNET, NET_DEMO */
    private $network;
    /** @var int */
    private $recvWindow;
    /** @var array<string,bool> symbols the exchange rejected with -1121 in this process */
    private $invalidSymbols = [];
    /** @var int */
    private $timeOffset = 0;
    /** @var int */
    private $usedWeight = 0;
    /** @var bool  once true, public endpoints go to tradeUrl() instead of data-api */
    private $dataFallback = false;

    /**
     * @param bool|string $network true/'testnet' for the Spot testnet, 'demo' for Binance Demo
     *                             Trading (demo.binance.com keys), false/'prod' for the live
     *                             exchange. Bools are accepted for backward compatibility.
     */
    public function __construct(string $apiKey, string $apiSecret, $network = false, int $recvWindow = 10000)
    {
        $this->apiKey     = $apiKey;
        $this->apiSecret  = $apiSecret;
        $this->network    = self::normalizeNetwork($network);
        $this->recvWindow = max(1000, min(60000, $recvWindow));
    }

    /**
     * Accepts a trading mode ('paper'|'live'|'testnet'|'demo'), a network name, or a legacy bool.
     * Anything unrecognised falls back to the live network, which is what an unknown mode used to do.
     *
     * @param bool|string $network
     */
    public static function normalizeNetwork($network): string
    {
        if (is_bool($network)) {
            return $network ? self::NET_TESTNET : self::NET_PROD;
        }
        $n = strtolower(trim((string) $network));
        if ($n === self::NET_TESTNET) {
            return self::NET_TESTNET;
        }
        if ($n === self::NET_DEMO) {
            return self::NET_DEMO;
        }
        return self::NET_PROD;
    }

    // ------------------------------------------------------------------ urls

    public function tradeUrl(): string
    {
        if ($this->network === self::NET_TESTNET) {
            return self::URL_TESTNET;
        }
        if ($this->network === self::NET_DEMO) {
            return self::URL_DEMO;
        }
        return self::URL_PROD;
    }

    /**
     * Public market data. Only the live network has a separate read-only host; testnet and demo
     * must answer their own market data or the prices would not match their order books.
     */
    public function dataUrl(): string
    {
        if ($this->network !== self::NET_PROD || $this->dataFallback) {
            return $this->tradeUrl();
        }
        return self::URL_DATA;
    }

    public function network(): string
    {
        return $this->network;
    }

    public function isTestnet(): bool
    {
        return $this->network === self::NET_TESTNET;
    }

    /** True when orders are simulated by the exchange itself (testnet or demo), i.e. no real money. */
    public function isSimulated(): bool
    {
        return $this->network !== self::NET_PROD;
    }

    public function hasKeys(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    // ------------------------------------------------------------------ time

    /** Fetches server time from the data host (trade host as fallback), stores and returns the offset (ms). */
    public function syncTime(): int
    {
        $before = Util::nowMs();
        try {
            $r = $this->request('GET', '/api/v3/time', [], false, true, false);
        } catch (BinanceException $e) {
            if ($this->dataUrl() === $this->tradeUrl()) {
                throw $e;   // already on the trade host, nothing left to try
            }
            $before = Util::nowMs();
            $r = $this->request('GET', '/api/v3/time', [], false, false, false);
        }
        $after  = Util::nowMs();
        $server = isset($r['serverTime']) ? (int)$r['serverTime'] : $after;
        // account for half the round trip
        $local  = (int)floor(($before + $after) / 2);
        $this->timeOffset = $server - $local;
        return $this->timeOffset;
    }

    public function setTimeOffset(int $ms): void
    {
        $this->timeOffset = $ms;
    }

    public function timeOffset(): int
    {
        return $this->timeOffset;
    }

    public function serverTimeMs(): int
    {
        return Util::nowMs() + $this->timeOffset;
    }

    public function lastUsedWeight(): int
    {
        return $this->usedWeight;
    }

    // ------------------------------------------------------------------ public market data

    /**
     * @param string[] $symbols
     * @return array parsed shape, see DESIGN.md §6
     */
    public function exchangeInfo(array $symbols): array
    {
        $symbols = $this->dropInvalid(array_values(array_unique(array_map('strval', $symbols))));
        if ($symbols === []) {
            return [];
        }
        $params = [];
        if (count($symbols) === 1) {
            $params['symbol'] = $symbols[0];
        } else {
            $params['symbols'] = json_encode($symbols);
        }
        try {
            $r = $this->request('GET', '/api/v3/exchangeInfo', $params, false, true, false);
        } catch (BinanceException $e) {
            if ($e->binanceCode !== -1121) {
                throw $e;
            }
            if (count($symbols) === 1) {
                $this->markInvalid($symbols[0]);
                return [];
            }
            $out = [];
            foreach ($symbols as $s) {
                foreach ($this->exchangeInfo([$s]) as $k => $v) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }
        $out = [];
        $list = isset($r['symbols']) && is_array($r['symbols']) ? $r['symbols'] : [];
        foreach ($list as $s) {
            if (!is_array($s) || !isset($s['symbol'])) {
                continue;
            }
            $out[(string)$s['symbol']] = self::parseSymbolInfo($s);
        }
        return $out;
    }

    /** @param array $s one entry of exchangeInfo.symbols */
    public static function parseSymbolInfo(array $s): array
    {
        $info = [
            'base'                 => (string)($s['baseAsset'] ?? ''),
            'quote'                => (string)($s['quoteAsset'] ?? ''),
            'status'               => (string)($s['status'] ?? ''),
            'spotAllowed'          => (bool)($s['isSpotTradingAllowed'] ?? false),
            'quoteOrderQtyAllowed' => (bool)($s['quoteOrderQtyMarketAllowed'] ?? false),
            'stepSize'             => '0.00000001',
            'minQty'               => '0.00000000',
            'maxQty'               => '9000000000.00000000',
            'marketStepSize'       => '0',
            'marketMinQty'         => '0',
            'minNotional'          => 5.0,
            'applyMinToMarket'     => true,
            'tickSize'             => '0.00000001',
            'basePrecision'        => (int)($s['baseAssetPrecision'] ?? 8),
            'quotePrecision'       => (int)($s['quoteAssetPrecision'] ?? ($s['quotePrecision'] ?? 8)),
            // PRICE_FILTER bounds (0 = no bound), PERCENT_PRICE_BY_SIDE and MAX_NUM_ORDERS (DESIGN-ENGINES.md §3)
            'minPrice'             => '0.00000000',
            'maxPrice'             => '0.00000000',
            'bidMultiplierUp'      => 0.0,
            'bidMultiplierDown'    => 0.0,
            'askMultiplierUp'      => 0.0,
            'askMultiplierDown'    => 0.0,
            'avgPriceMins'         => 0,
            'maxNumOrders'         => 200,
        ];
        $filters = isset($s['filters']) && is_array($s['filters']) ? $s['filters'] : [];
        $sawNotional = false;
        foreach ($filters as $f) {
            if (!is_array($f) || !isset($f['filterType'])) {
                continue;
            }
            switch ((string)$f['filterType']) {
                case 'LOT_SIZE':
                    if (isset($f['stepSize'])) { $info['stepSize'] = (string)$f['stepSize']; }
                    if (isset($f['minQty']))   { $info['minQty']   = (string)$f['minQty']; }
                    if (isset($f['maxQty']))   { $info['maxQty']   = (string)$f['maxQty']; }
                    break;
                case 'MARKET_LOT_SIZE':
                    if (isset($f['stepSize'])) { $info['marketStepSize'] = (string)$f['stepSize']; }
                    if (isset($f['minQty']))   { $info['marketMinQty']   = (string)$f['minQty']; }
                    break;
                case 'PRICE_FILTER':
                    if (isset($f['tickSize'])) { $info['tickSize'] = (string)$f['tickSize']; }
                    if (isset($f['minPrice'])) { $info['minPrice'] = (string)$f['minPrice']; }
                    if (isset($f['maxPrice'])) { $info['maxPrice'] = (string)$f['maxPrice']; }
                    break;
                case 'NOTIONAL':
                    // newer filter; takes precedence over MIN_NOTIONAL when both are present
                    if (isset($f['minNotional'])) { $info['minNotional'] = (float)$f['minNotional']; }
                    $info['applyMinToMarket'] = isset($f['applyMinToMarket']) ? (bool)$f['applyMinToMarket'] : true;
                    $sawNotional = true;
                    break;
                case 'MIN_NOTIONAL':
                    if ($sawNotional) { break; }
                    if (isset($f['minNotional'])) { $info['minNotional'] = (float)$f['minNotional']; }
                    $info['applyMinToMarket'] = isset($f['applyToMarket']) ? (bool)$f['applyToMarket'] : true;
                    break;
                case 'PERCENT_PRICE_BY_SIDE':
                    if (isset($f['bidMultiplierUp']))   { $info['bidMultiplierUp']   = (float)$f['bidMultiplierUp']; }
                    if (isset($f['bidMultiplierDown'])) { $info['bidMultiplierDown'] = (float)$f['bidMultiplierDown']; }
                    if (isset($f['askMultiplierUp']))   { $info['askMultiplierUp']   = (float)$f['askMultiplierUp']; }
                    if (isset($f['askMultiplierDown'])) { $info['askMultiplierDown'] = (float)$f['askMultiplierDown']; }
                    if (isset($f['avgPriceMins']))      { $info['avgPriceMins']      = (int)$f['avgPriceMins']; }
                    break;
                case 'MAX_NUM_ORDERS':
                    if (isset($f['maxNumOrders'])) { $info['maxNumOrders'] = (int)$f['maxNumOrders']; }
                    break;
                default:
                    break;
            }
        }
        return $info;
    }

    /**
     * PERCENT_PRICE_BY_SIDE check (DESIGN-ENGINES.md §3): a BUY price must sit inside
     * [reference × bidMultiplierDown, reference × bidMultiplierUp] and a SELL price inside
     * [reference × askMultiplierDown, reference × askMultiplierUp].
     *
     * $reference is the exchange's average price over `avgPriceMins` minutes (avgPrice(), or the
     * mid when that is what the caller has). Returns TRUE when the symbol has no such filter
     * (both multipliers for that side are 0/absent) or when no usable reference is available —
     * the filter can only reject, never invent a constraint. A non-positive price is rejected.
     *
     * @param array $info parsed symbol info from parseSymbolInfo()
     */
    public static function priceAllowed(float $price, string $side, float $reference, array $info): bool
    {
        $isSell = strtoupper(trim($side)) === 'SELL';
        $up     = (float)($isSell ? ($info['askMultiplierUp']   ?? 0) : ($info['bidMultiplierUp']   ?? 0));
        $down   = (float)($isSell ? ($info['askMultiplierDown'] ?? 0) : ($info['bidMultiplierDown'] ?? 0));
        if ($up <= 0.0 && $down <= 0.0) {
            return true;    // filter absent
        }
        if (!is_finite($price) || $price <= 0.0) {
            return false;
        }
        if (!is_finite($reference) || $reference <= 0.0) {
            return true;    // nothing to compare against
        }
        if ($up > 0.0 && $price > $reference * $up) {
            return false;
        }
        if ($down > 0.0 && $price < $reference * $down) {
            return false;
        }
        return true;
    }

    /** @return array rows [openTime(int), open, high, low, close, volume (floats), closeTime(int)] */
    public function klines(string $symbol, string $interval, int $limit = 320): array
    {
        $limit = max(1, min(1000, $limit));
        $r = $this->request('GET', '/api/v3/klines', [
            'symbol'   => $symbol,
            'interval' => $interval,
            'limit'    => $limit,
        ], false, true, false);
        $rows = [];
        foreach ($r as $k) {
            if (!is_array($k) || count($k) < 7) {
                continue;
            }
            $rows[] = [
                (int)$k[0],
                (float)$k[1],
                (float)$k[2],
                (float)$k[3],
                (float)$k[4],
                (float)$k[5],
                (int)$k[6],
            ];
        }
        return $rows;
    }

    /** @return array{bid:float, ask:float} */
    public function bookTicker(string $symbol): array
    {
        $r = $this->request('GET', '/api/v3/ticker/bookTicker', ['symbol' => $symbol], false, true, false);
        return [
            'bid' => (float)($r['bidPrice'] ?? 0),
            'ask' => (float)($r['askPrice'] ?? 0),
        ];
    }

    /** @return array [symbol => float] */
    public function prices(array $symbols): array
    {
        $symbols = array_values(array_unique(array_map('strval', $symbols)));
        if (count($symbols) === 0) {
            return [];
        }
        $symbols = $this->dropInvalid($symbols);
        if ($symbols === []) {
            return [];
        }
        $params = count($symbols) === 1 ? ['symbol' => $symbols[0]] : ['symbols' => json_encode($symbols)];
        try {
            $r = $this->request('GET', '/api/v3/ticker/price', $params, false, true, false);
        } catch (BinanceException $e) {
            if ($e->binanceCode !== -1121) {
                throw $e;
            }
            if (count($symbols) === 1) {
                $this->markInvalid($symbols[0]);
                return [];
            }
            $out = [];
            foreach ($symbols as $s) {
                foreach ($this->prices([$s]) as $k => $v) {
                    $out[$k] = $v;
                }
            }
            return $out;
        }
        $out = [];
        if (isset($r['symbol'])) {
            $r = [$r];
        }
        foreach ($r as $row) {
            if (is_array($row) && isset($row['symbol'], $row['price'])) {
                $out[(string)$row['symbol']] = (float)$row['price'];
            }
        }
        return $out;
    }

    /** Symbols the exchange rejected with -1121 in this process are skipped in later batches. */
    private function dropInvalid(array $symbols): array
    {
        if ($this->invalidSymbols === []) {
            return $symbols;
        }
        $out = [];
        foreach ($symbols as $s) {
            if (!isset($this->invalidSymbols[$s])) {
                $out[] = $s;
            }
        }
        return $out;
    }

    private function markInvalid(string $symbol): void
    {
        if (isset($this->invalidSymbols[$symbol])) {
            return;
        }
        $this->invalidSymbols[$symbol] = true;
        if (class_exists('Log')) {
            Log::warn('market data: ' . $symbol . ' rejected by the exchange (-1121 invalid symbol); skipped this run');
        }
    }

    public function avgPrice(string $symbol): float
    {
        $r = $this->request('GET', '/api/v3/avgPrice', ['symbol' => $symbol], false, true, false);
        return (float)($r['price'] ?? 0);
    }

    /**
     * GET /api/v3/ticker/24hr — 24 hour rolling window statistics.
     *
     * **WEIGHT 80 WITH NO SYMBOLS.** Called with an empty $symbols this asks the exchange for
     * EVERY listed symbol (thousands of rows, ~1 MB of JSON) and costs **80** of the 1200
     * request weight the account gets per minute. NEVER call it on a per-tick path and never
     * in a loop: the whole point of the hourly `Scanner::refresh()` (guarded by
     * `Scanner::due()` / `scanner_refresh_min`) is that this call happens once an hour.
     * Binance's weights for this endpoint:
     *
     *     1 symbol .................. 2
     *     2 … 20 symbols ............ 2
     *     21 … 100 symbols .......... 40
     *     more than 100, or none .... 80
     *
     * Rows are normalised to the shape below (missing fields become 0.0). The bid and ask come
     * from this same payload, so a caller that already has the tickers needs no extra
     * bookTicker weight to compute spreads:
     *
     *     ['symbol'=>string, 'last'=>float, 'bid'=>float, 'ask'=>float,
     *      'change_pct'=>float, 'quote_vol'=>float, 'high'=>float, 'low'=>float]
     *
     * `change_pct` is the 24 h price change in percent, `quote_vol` the 24 h volume in the
     * quote asset (USDT), which is the liquidity measure the scanner filters on.
     *
     * Like exchangeInfo()/prices(), an explicit symbol list that trips `-1121 invalid symbol`
     * is retried per symbol so one typo costs one symbol instead of the whole batch.
     *
     * @param array $symbols concrete symbols, or [] for every symbol on the exchange (weight 80)
     * @return array list of normalised rows, in the order the exchange returned them
     */
    public function ticker24h(array $symbols = []): array
    {
        $requested = array_values(array_unique(array_map('strval', $symbols)));
        $wanted    = $this->dropInvalid($requested);
        if ($requested !== [] && $wanted === []) {
            return [];      // every requested symbol is known-invalid; do NOT fall through to "all symbols"
        }
        $params = [];
        if (count($wanted) === 1) {
            $params['symbol'] = $wanted[0];
        } elseif (count($wanted) > 1) {
            $params['symbols'] = json_encode($wanted);
        }
        try {
            $r = $this->request('GET', '/api/v3/ticker/24hr', $params, false, true, false);
        } catch (BinanceException $e) {
            if ($e->binanceCode !== -1121 || $wanted === []) {
                throw $e;
            }
            if (count($wanted) === 1) {
                $this->markInvalid($wanted[0]);
                return [];
            }
            $out = [];
            foreach ($wanted as $s) {
                foreach ($this->ticker24h([$s]) as $row) {
                    $out[] = $row;
                }
            }
            return $out;
        }
        if (isset($r['symbol'])) {
            $r = [$r];
        }
        $out = [];
        foreach ($r as $row) {
            if (!is_array($row) || !isset($row['symbol'])) {
                continue;
            }
            $out[] = self::normalizeTicker24h($row);
        }
        return $out;
    }

    /**
     * Normalise one raw /api/v3/ticker/24hr row to the scanner shape.
     *
     * @param array $t raw row
     * @return array{symbol:string,last:float,bid:float,ask:float,change_pct:float,quote_vol:float,high:float,low:float}
     */
    public static function normalizeTicker24h(array $t): array
    {
        return [
            'symbol'     => (string) ($t['symbol'] ?? ''),
            'last'       => self::numField($t, 'lastPrice'),
            'bid'        => self::numField($t, 'bidPrice'),
            'ask'        => self::numField($t, 'askPrice'),
            'change_pct' => self::numField($t, 'priceChangePercent'),
            'quote_vol'  => self::numField($t, 'quoteVolume'),
            'high'       => self::numField($t, 'highPrice'),
            'low'        => self::numField($t, 'lowPrice'),
        ];
    }

    /** Finite float from a raw API row (Binance sends numbers as strings). */
    private static function numField(array $row, string $key): float
    {
        if (!isset($row[$key]) || !is_numeric($row[$key])) {
            return 0.0;
        }
        $v = (float) $row[$key];
        return is_finite($v) ? $v : 0.0;
    }


    // ------------------------------------------------------------------ signed

    /**
     * @return array{balances: array<string, array{free: float, locked: float}>, taker_fee_pct: float, can_trade: bool}
     */
    public function account(): array
    {
        $r = $this->request('GET', '/api/v3/account', ['omitZeroBalances' => 'true'], true, false, false);
        $balances = [];
        $list = isset($r['balances']) && is_array($r['balances']) ? $r['balances'] : [];
        foreach ($list as $b) {
            if (!is_array($b) || !isset($b['asset'])) {
                continue;
            }
            $free   = (float)($b['free'] ?? 0);
            $locked = (float)($b['locked'] ?? 0);
            if ($free <= 0.0 && $locked <= 0.0) {
                continue;
            }
            $balances[(string)$b['asset']] = ['free' => $free, 'locked' => $locked];
        }
        $taker = null;
        if (isset($r['commissionRates']) && is_array($r['commissionRates']) && isset($r['commissionRates']['taker'])) {
            $taker = (float)$r['commissionRates']['taker'] * 100.0;
        } elseif (isset($r['takerCommission'])) {
            // legacy field in basis points (10 => 0.10 %)
            $taker = (float)$r['takerCommission'] / 100.0;
        }
        if ($taker === null || $taker < 0.0) {
            $taker = 0.1;
        }
        return [
            'balances'      => $balances,
            'taker_fee_pct' => $taker,
            'can_trade'     => (bool)($r['canTrade'] ?? false),
        ];
    }

    /** MARKET BUY by quote amount; returns the raw FULL response. */
    /**
     * Whether the account pays SPOT trading fees in BNB, which is a 25 % discount
     * (0.075 % instead of 0.1 % per side). Wallet endpoint, weight 1.
     *
     * This lives under /sapi, which not every environment serves — the Spot Demo and
     * testnet hosts may not — so an unavailable endpoint returns null instead of
     * throwing, and the caller tells the operator to use the Binance UI instead.
     *
     * @return array|null ['spot'=>bool, 'interest'=>bool], or null when unsupported
     */
    public function bnbBurnStatus(): ?array
    {
        try {
            $r = $this->request('GET', '/sapi/v1/bnbBurn', [], true, false, false);
        } catch (BinanceException $e) {
            Log::debug('bnbBurn status unavailable', ['code' => $e->binanceCode, 'http' => $e->httpStatus]);
            return null;
        }
        if (!is_array($r)) {
            return null;
        }
        return [
            'spot'     => self::truthy($r['spotBNBBurn'] ?? null),
            'interest' => self::truthy($r['interestBNBBurn'] ?? null),
        ];
    }

    /**
     * Turn the BNB fee discount on or off for SPOT trading. Weight 1.
     * Returns null when the endpoint is not served by this host (demo/testnet);
     * any other failure throws so the panel can report why it did not take effect.
     *
     * @return array|null ['spot'=>bool, 'interest'=>bool]
     */
    public function setBnbBurn(bool $spotBurn): ?array
    {
        try {
            $r = $this->request('POST', '/sapi/v1/bnbBurn', ['spotBNBBurn' => $spotBurn ? 'true' : 'false'], true, false, false);
        } catch (BinanceException $e) {
            if ($e->httpStatus === 404 || $e->httpStatus === 405) {
                Log::debug('bnbBurn toggle unavailable on this host', ['http' => $e->httpStatus]);
                return null;
            }
            throw $e;
        }
        if (!is_array($r)) {
            return null;
        }
        return [
            'spot'     => self::truthy($r['spotBNBBurn'] ?? null),
            'interest' => self::truthy($r['interestBNBBurn'] ?? null),
        ];
    }

    /** Binance returns these flags as real booleans or as the strings "true"/"false". */
    private static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return strtolower(trim((string) $v)) === 'true';
    }

    public function marketBuyQuote(string $symbol, string $quoteStr, string $clientId): array
    {
        return $this->request('POST', '/api/v3/order', [
            'symbol'           => $symbol,
            'side'             => 'BUY',
            'type'             => 'MARKET',
            'quoteOrderQty'    => $quoteStr,
            'newClientOrderId' => $clientId,
            'newOrderRespType' => 'FULL',
        ], true, false, true);
    }

    /** MARKET SELL by base quantity; returns the raw FULL response. */
    public function marketSellQty(string $symbol, string $qtyStr, string $clientId): array
    {
        return $this->request('POST', '/api/v3/order', [
            'symbol'           => $symbol,
            'side'             => 'SELL',
            'type'             => 'MARKET',
            'quantity'         => $qtyStr,
            'newClientOrderId' => $clientId,
            'newOrderRespType' => 'FULL',
        ], true, false, true);
    }

    /** GET /api/v3/order by client id (raw). Throws BinanceException -2013 when the order does not exist. */
    public function getOrder(string $symbol, string $clientId): array
    {
        return $this->request('GET', '/api/v3/order', [
            'symbol'            => $symbol,
            'origClientOrderId' => $clientId,
        ], true, false, false);
    }

    /** GET /api/v3/myTrades for one order (raw list). Used to recover commissions for orders fetched via getOrder(). */
    public function myTrades(string $symbol, string $orderId, int $limit = 100): array
    {
        $params = ['symbol' => $symbol, 'limit' => max(1, min(1000, $limit))];
        if ($orderId !== '') {
            $params['orderId'] = $orderId;
        }
        $r = $this->request('GET', '/api/v3/myTrades', $params, true, false, false);
        return is_array($r) ? $r : [];
    }

    // ------------------------------------------------------------------ limit orders (DESIGN-ENGINES.md §3)

    /**
     * Places a resting limit order. $qtyStr and $priceStr are pre-rounded exact decimal strings
     * (Util::floorToStep / Util::roundToTick) — never a plain float cast.
     *
     * $postOnly sends LIMIT_MAKER (quantity + price, no timeInForce); the exchange rejects it with
     * -2010 "Order would immediately match and take" when the quote would cross the book, which
     * BinanceException::isPostOnlyReject() identifies as the normal outcome it is. Otherwise a
     * plain LIMIT with timeInForce=GTC is sent.
     *
     * @return array raw FULL order response
     */
    public function limitOrder(string $symbol, string $side, string $qtyStr, string $priceStr, string $clientId, bool $postOnly): array
    {
        $params = [
            'symbol' => $symbol,
            'side'   => strtoupper(trim($side)) === 'SELL' ? 'SELL' : 'BUY',
            'type'   => $postOnly ? 'LIMIT_MAKER' : 'LIMIT',
        ];
        if (!$postOnly) {
            $params['timeInForce'] = 'GTC';
        }
        $params['quantity']         = $qtyStr;
        $params['price']            = $priceStr;
        $params['newClientOrderId'] = $clientId;
        $params['newOrderRespType'] = 'FULL';
        return $this->request('POST', '/api/v3/order', $params, true, false, true);
    }

    /**
     * DELETE /api/v3/order by client id (weight 1).
     *
     * -2011 CANCEL_REJECTED means the order already left the book (filled, expired or cancelled
     * elsewhere), which is a soft success: a synthetic response with status CANCEL_REJECTED and
     * 'cancel_rejected' => true is returned instead of throwing, and the caller resolves the real
     * state with getOrder(). Every other error still throws.
     *
     * @return array raw cancel response
     */
    public function cancelOrder(string $symbol, string $clientId): array
    {
        try {
            return $this->request('DELETE', '/api/v3/order', [
                'symbol'            => $symbol,
                'origClientOrderId' => $clientId,
            ], true, false, false);
        } catch (BinanceException $e) {
            if ($e->binanceCode !== -2011) {
                throw $e;
            }
            return [
                'symbol'            => $symbol,
                'origClientOrderId' => $clientId,
                'clientOrderId'     => $clientId,
                'status'            => 'CANCEL_REJECTED',
                'code'              => -2011,
                'msg'               => $e->getMessage(),
                'cancel_rejected'   => true,
            ];
        }
    }

    /**
     * DELETE /api/v3/openOrders for one symbol (weight 1). -2011 (nothing open to cancel) is a
     * soft success and yields an empty list.
     *
     * @return array raw list of cancel reports
     */
    public function cancelAllOrders(string $symbol): array
    {
        try {
            $r = $this->request('DELETE', '/api/v3/openOrders', ['symbol' => $symbol], true, false, false);
        } catch (BinanceException $e) {
            if ($e->binanceCode !== -2011) {
                throw $e;
            }
            return [];
        }
        return is_array($r) ? $r : [];
    }

    /**
     * GET /api/v3/openOrders for ONE symbol (weight 6). The symbol is REQUIRED: without it the
     * endpoint costs weight 80, which this client never spends.
     *
     * @return array [clientOrderId => ['order_id','symbol','side','price','orig_qty','executed_qty','status','time']]
     */
    public function openOrders(string $symbol): array
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            throw new InvalidArgumentException('Binance::openOrders() requires a symbol (an unfiltered call costs weight 80)');
        }
        $r = $this->request('GET', '/api/v3/openOrders', ['symbol' => $symbol], true, false, false);
        $out = [];
        foreach ($r as $o) {
            if (!is_array($o)) {
                continue;
            }
            $cid = isset($o['clientOrderId']) ? (string)$o['clientOrderId'] : '';
            if ($cid === '') {
                $cid = 'orderId:' . (isset($o['orderId']) ? (string)$o['orderId'] : '');
            }
            $time = isset($o['time']) ? (int)$o['time'] : (isset($o['updateTime']) ? (int)$o['updateTime'] : (isset($o['transactTime']) ? (int)$o['transactTime'] : 0));
            $out[$cid] = [
                'order_id'     => isset($o['orderId']) ? (string)$o['orderId'] : '',
                'symbol'       => isset($o['symbol']) ? (string)$o['symbol'] : $symbol,
                'side'         => strtoupper((string)($o['side'] ?? '')),
                'price'        => (float)($o['price'] ?? 0),
                'orig_qty'     => (float)($o['origQty'] ?? 0),
                'executed_qty' => (float)($o['executedQty'] ?? 0),
                'status'       => (string)($o['status'] ?? ''),
                'time'         => $time,
            ];
        }
        return $out;
    }

    /** POST /api/v3/order/test. Returns true on success, throws BinanceException on rejection. */
    public function testOrder(array $params): bool
    {
        if (!isset($params['type'])) {
            $params['type'] = 'MARKET';
        }
        if (!isset($params['newOrderRespType'])) {
            $params['newOrderRespType'] = 'ACK';
        }
        $this->request('POST', '/api/v3/order/test', $params, true, false, false);
        return true;
    }

    // ------------------------------------------------------------------ order normalisation

    /**
     * Turns a FULL order response (or a GET /order response with synthetic fills) into the
     * ExchangeInterface::marketBuy / marketSell return shape.
     *
     * @param array      $raw      order response
     * @param array      $info     parsed symbol info (needs 'base', 'quote', 'stepSize')
     * @param float|null $bnbPrice BNBUSDT price, only needed when a BNB commission is present
     * @return array{qty:float, dust_qty:float, price:float, quote:float, fee_usdt:float, fee_asset:string, fee_base_usdt:float, order_id:string, status:string, raw:array}
     *         fee_asset names the FIRST commission asset of the order; fee_base_usdt is the
     *         base-asset part of fee_usdt (already netted off qty), so a mixed-asset order
     *         (BNB balance running out mid-order) can still be booked exactly.
     */
    public static function normalizeOrder(array $raw, array $info, ?float $bnbPrice): array
    {
        $side        = strtoupper((string)($raw['side'] ?? ''));
        $base        = (string)($info['base'] ?? '');
        $quoteAsset  = (string)($info['quote'] ?? 'USDT');
        $step        = (string)($info['stepSize'] ?? '0.00000001');
        $executedStr = self::decStr($raw['executedQty'] ?? '0');
        $executed    = (float)$executedStr;
        $cumQuote    = (float)($raw['cummulativeQuoteQty'] ?? ($raw['cumulativeQuoteQty'] ?? 0));

        // commission per asset (exact decimal strings)
        $commissions = [];
        $feeAsset    = '';
        $fills = isset($raw['fills']) && is_array($raw['fills']) ? $raw['fills'] : [];
        foreach ($fills as $f) {
            if (!is_array($f) || !isset($f['commissionAsset'])) {
                continue;
            }
            $asset = (string)$f['commissionAsset'];
            $amt   = self::decStr($f['commission'] ?? '0');
            $commissions[$asset] = isset($commissions[$asset]) ? self::decAdd($commissions[$asset], $amt) : $amt;
            if ($feeAsset === '' && (float)$amt > 0.0) {
                $feeAsset = $asset;
            }
        }
        if ($feeAsset === '' && count($commissions) > 0) {
            $feeAsset = (string)array_key_first($commissions);
        }

        $price = $executed > 0.0 ? $cumQuote / $executed : 0.0;
        if ($price <= 0.0 && count($fills) > 0 && isset($fills[0]['price'])) {
            $price = (float)$fills[0]['price'];
        }

        // fee in quote (USDT) terms
        $feeUsdt = 0.0;
        foreach ($commissions as $asset => $amtStr) {
            $amt = (float)$amtStr;
            if ($amt <= 0.0) {
                continue;
            }
            if ($asset === $quoteAsset) {
                $feeUsdt += $amt;
            } elseif ($asset === $base) {
                $feeUsdt += $amt * $price;
            } elseif ($asset === 'BNB') {
                $feeUsdt += $amt * ($bnbPrice !== null ? $bnbPrice : 0.0);
            }
            // any other asset: unknown value, counted as 0
        }

        if ($side === 'BUY') {
            $baseCommission = isset($commissions[$base]) ? $commissions[$base] : '0';
            $netStr = self::decSub($executedStr, $baseCommission);
            if ((float)$netStr < 0.0) {
                $netStr = '0';
            }
            $qtyStr = Util::floorToStep($netStr, $step);
            $qty    = (float)$qtyStr;
            $dust   = (float)$netStr - $qty;
            if ($dust < 0.0) {
                $dust = 0.0;
            }
            $quote = $cumQuote;
        } else {
            $qty   = $executed;
            $dust  = 0.0;
            $quote = $cumQuote - (isset($commissions[$quoteAsset]) ? (float)$commissions[$quoteAsset] : 0.0);
            if ($quote < 0.0) {
                $quote = 0.0;
            }
        }

        return [
            'qty'       => $qty,
            'dust_qty'  => $dust,
            'price'     => $price,
            'quote'     => $quote,
            'fee_usdt'  => $feeUsdt,
            'fee_asset' => $feeAsset,
            'fee_base_usdt' => isset($commissions[$base]) ? (float)$commissions[$base] * $price : 0.0,
            'order_id'  => isset($raw['orderId']) ? (string)$raw['orderId'] : '',
            'status'    => (string)($raw['status'] ?? ''),
            'raw'       => $raw,
        ];
    }

    // ------------------------------------------------------------------ exact decimal helpers (strings)

    /** Normalises a numeric value to a plain decimal string (no exponent). */
    private static function decStr($v): string
    {
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || !is_numeric($v)) {
                return '0';
            }
            if (stripos($v, 'e') === false) {
                return $v;
            }
            $v = (float)$v;
        }
        if (is_int($v)) {
            return (string)$v;
        }
        return rtrim(rtrim(sprintf('%.12F', (float)$v), '0'), '.');
    }

    private static function decScale(string $a, string $b): int
    {
        $da = ($p = strpos($a, '.')) === false ? 0 : strlen($a) - $p - 1;
        $db = ($p = strpos($b, '.')) === false ? 0 : strlen($b) - $p - 1;
        return min(12, max($da, $db));
    }

    private static function decAdd(string $a, string $b): string
    {
        $s = self::decScale($a, $b);
        if (function_exists('bcadd')) {
            return bcadd($a, $b, $s);
        }
        return self::intToDec(self::decToInt($a, $s) + self::decToInt($b, $s), $s);
    }

    private static function decSub(string $a, string $b): string
    {
        $s = self::decScale($a, $b);
        if (function_exists('bcsub')) {
            return bcsub($a, $b, $s);
        }
        return self::intToDec(self::decToInt($a, $s) - self::decToInt($b, $s), $s);
    }

    private static function decToInt(string $v, int $scale): int
    {
        $neg = strlen($v) > 0 && $v[0] === '-';
        if ($neg) {
            $v = substr($v, 1);
        }
        $parts = explode('.', $v, 2);
        $ip = $parts[0] === '' ? '0' : $parts[0];
        $fp = isset($parts[1]) ? $parts[1] : '';
        $fp = substr(str_pad($fp, $scale, '0'), 0, $scale);
        $n  = (int)$ip * (int)pow(10, $scale) + ($scale > 0 ? (int)$fp : 0);
        return $neg ? -$n : $n;
    }

    private static function intToDec(int $n, int $scale): string
    {
        $neg = $n < 0;
        $n   = abs($n);
        $s   = (string)$n;
        if ($scale > 0) {
            $s = str_pad($s, $scale + 1, '0', STR_PAD_LEFT);
            $s = substr($s, 0, -$scale) . '.' . substr($s, -$scale);
        }
        return ($neg ? '-' : '') . $s;
    }

    // ------------------------------------------------------------------ transport

    /** Booleans become 'true'/'false'; ints become strings; floats are formatted without exponent (safety net). */
    private static function normalizeParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_bool($v)) {
                $out[$k] = $v ? 'true' : 'false';
            } elseif (is_int($v)) {
                $out[$k] = (string)$v;
            } elseif (is_float($v)) {
                $out[$k] = rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');
            } else {
                $out[$k] = (string)$v;
            }
        }
        return $out;
    }

    /**
     * @param bool $signed  add timestamp/recvWindow and HMAC signature
     * @param bool $public  route through dataUrl() (market data)
     * @param bool $isOrder POST /api/v3/order semantics (-1007 on unknown outcome)
     */
    private function request(string $method, string $path, array $params, bool $signed, bool $public, bool $isOrder): array
    {
        if ($signed && !$this->hasKeys()) {
            throw new BinanceException('Binance ' . $method . ' ' . $path . ': API key/secret not configured', -2015, 0);
        }
        $timeRetried = false;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $base = $public ? $this->dataUrl() : $this->tradeUrl();
            $p = self::normalizeParams($params);
            if ($signed) {
                $p['recvWindow'] = (string)$this->recvWindow;
                $p['timestamp']  = (string)(Util::nowMs() + $this->timeOffset);
            }
            $qs = http_build_query($p, '', '&', PHP_QUERY_RFC3986);
            if ($signed) {
                $qs .= '&signature=' . hash_hmac('sha256', $qs, $this->apiSecret);
            }

            $res = $this->transport($method, $base . $path, $qs);
            $status  = $res['status'];
            $body    = $res['body'];
            $tag     = 'Binance ' . $method . ' ' . $path;

            if ($res['errno'] !== 0) {
                if ($public && $this->network === self::NET_PROD && !$this->dataFallback) {
                    // one-time fallback from data-api to the trade host
                    $this->dataFallback = true;
                    continue;
                }
                if ($isOrder) {
                    throw new BinanceException($tag . ': transport failure, order status UNKNOWN (' . $res['error'] . ')', -1007, 0);
                }
                throw new BinanceException($tag . ': network error (' . $res['error'] . ')', 0, 0);
            }

            $data = json_decode($body, true);
            $code = (is_array($data) && isset($data['code']) && is_numeric($data['code'])) ? (int)$data['code'] : 0;
            $msg  = (is_array($data) && isset($data['msg'])) ? (string)$data['msg'] : '';

            if ($status === 429 || $status === 418) {
                $ra = $res['retryAfter'];
                if ($ra === null || $ra <= 0) {
                    $ra = $status === 429 ? 120 : 3600;
                }
                throw new BinanceException(
                    $tag . ': HTTP ' . $status . ' rate limited, retry after ' . $ra . 's' . ($msg !== '' ? ' (' . $msg . ')' : ''),
                    $code !== 0 ? $code : -1003,
                    $status,
                    $ra
                );
            }

            if ($status >= 500) {
                if ($isOrder) {
                    throw new BinanceException($tag . ': HTTP ' . $status . ', order status UNKNOWN' . ($msg !== '' ? ' (' . $msg . ')' : ''), -1007, $status);
                }
                throw new BinanceException($tag . ': HTTP ' . $status . ($msg !== '' ? ' ' . $code . ' ' . $msg : ''), $code !== 0 ? $code : -1000, $status);
            }

            $isErrorBody = is_array($data) && isset($data['code'], $data['msg']) && $code < 0;
            if ($status >= 400 || $isErrorBody) {
                if ($code === -1021 && $signed && !$timeRetried) {
                    $timeRetried = true;
                    try {
                        $this->syncTime();
                    } catch (BinanceException $e) {
                        // fall through and report the original -1021
                        throw new BinanceException($tag . ': HTTP ' . $status . ' ' . $code . ' ' . $msg . ' (time resync failed)', -1021, $status);
                    }
                    continue;
                }
                $st = $status > 0 ? $status : 400;
                throw new BinanceException($tag . ': HTTP ' . $st . ' ' . $code . ' ' . ($msg !== '' ? $msg : 'error'), $code !== 0 ? $code : -1000, $st);
            }

            if ($status < 200 || $status >= 300) {
                throw new BinanceException($tag . ': unexpected HTTP ' . $status, -1000, $status);
            }
            if (!is_array($data)) {
                if ($isOrder) {
                    throw new BinanceException($tag . ': unreadable response, order status UNKNOWN', -1007, $status);
                }
                throw new BinanceException($tag . ': invalid JSON response', -1000, $status);
            }
            return $data;
        }
        throw new BinanceException('Binance ' . $method . ' ' . $path . ': too many retries', $isOrder ? -1007 : -1000, 0);
    }

    /**
     * Performs the HTTP call. Returns ['errno'=>int,'error'=>string,'status'=>int,'body'=>string,'retryAfter'=>?int].
     * Also updates $this->usedWeight from X-MBX-USED-WEIGHT-1M.
     */
    private function transport(string $method, string $url, string $qs): array
    {
        $ch = curl_init();
        if ($ch === false) {
            return ['errno' => 1, 'error' => 'curl_init failed', 'status' => 0, 'body' => '', 'retryAfter' => null];
        }
        $headers = ['Accept: application/json', 'User-Agent: micro-trader/1.0 (php)'];
        if ($this->apiKey !== '') {
            $headers[] = 'X-MBX-APIKEY: ' . $this->apiKey;
        }
        $retryAfter = null;
        $usedWeight = null;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter, &$usedWeight): int {
                $len = strlen($line);
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name  = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    if ($name === 'x-mbx-used-weight-1m') {
                        $usedWeight = (int)$value;
                    } elseif ($name === 'retry-after') {
                        $retryAfter = (int)$value;
                    }
                }
                return $len;
            },
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_URL]        = $url;
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $qs;
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_URL]           = $qs !== '' ? $url . '?' . $qs : $url;
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        } else {
            $opts[CURLOPT_URL]     = $qs !== '' ? $url . '?' . $qs : $url;
            $opts[CURLOPT_HTTPGET] = true;
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = $errno !== 0 ? 'cURL ' . $errno . ': ' . curl_error($ch) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($usedWeight !== null) {
            $this->usedWeight = $usedWeight;
        }
        return [
            'errno'      => $errno,
            'error'      => $error,
            'status'     => $status,
            'body'       => is_string($body) ? $body : '',
            'retryAfter' => $retryAfter,
        ];
    }
}
