<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/../lib/Db.php';
require_once __DIR__ . '/../lib/Binance.php';
require_once __DIR__ . '/../lib/Exchange.php';

/**
 * Offline MarketDataInterface backed by the JSON fixtures in tests/fixtures/
 * (DESIGN.md §2, §13). No network, no clock: everything is scripted.
 *
 *  $md = new FakeMarketData([
 *      'SOLUSDT'  => ['15m' => 'klines_15m_oversold',   '1h' => 'klines_1h_uptrend'],
 *      'DOGEUSDT' => ['15m' => 'klines_15m_overbought', '1h' => '/abs/path/klines_1h_uptrend.json'],
 *      'XRPUSDT'  => ['15m' => [[openTime, "open", "high", "low", "close", "volume", closeTime, ...], ...]],
 *  ]);
 *  $md->setPrice('SOLUSDT', 129.75, 129.80);   // bid / ask used by bookTicker() and prices()
 *
 * Fixed clock (documented here and in the fixture generator):
 *   serverTimeMs() = 1788437100000 = 2026-09-03T12:05:00Z
 * Every fixture ends with the forming candle that opened at 12:00:00Z, so its closeTime
 * (12:14:59.999Z for 15m, 12:59:59.999Z for 1h) lies in the future relative to that clock and
 * Indicators::closed() drops it. Advance the clock by 15 minutes (advanceMs(900000)) and the
 * 15m forming candle counts as closed while the 1h one still does not.
 *
 * Fixture facts (see tests/run.php for the assertions built on them):
 *   klines_15m_oversold.json   330 rows, last closed candle RSI14 ≈ 24.3, close below the lower
 *                              Bollinger(20,2) band, low pierces the band, close > open, volume
 *                              2.6× SMA20(volume); the forming candle repeats the pattern
 *   klines_15m_overbought.json 330 rows, last closed candle RSI14 ≈ 78, close above the upper band
 *   klines_1h_uptrend.json     270 rows, EMA50 > EMA200, ATR14 ≈ 0.9 % of close
 *   klines_1h_downtrend.json   270 rows, EMA50 < EMA200 and falling (Strategy gate trend_down)
 */
final class FakeMarketData implements MarketDataInterface
{
    /** 2026-09-03T12:05:00Z */
    const SERVER_TIME_MS = 1788437100000;

    /** Relative spread used when bookTicker() has to derive a price from the last closed candle. */
    const DERIVED_SPREAD = 0.0002;

    /**
     * Realistic parsed symbol info in the Binance::exchangeInfo() shape (DESIGN.md §6).
     * SOLUSDT and DOGEUSDT are the ones the design calls out; the others make the default
     * watchlist and the BTCUSDT "unaffordable" case testable.
     */
    const SYMBOL_INFO = [
        'SOLUSDT' => ['base' => 'SOL', 'stepSize' => '0.00100000', 'minQty' => '0.00100000', 'maxQty' => '90000.00000000', 'minNotional' => 5.0, 'tickSize' => '0.01000000'],
        'DOGEUSDT' => ['base' => 'DOGE', 'stepSize' => '1.00000000', 'minQty' => '1.00000000', 'maxQty' => '9000000.00000000', 'minNotional' => 1.0, 'tickSize' => '0.00001000'],
        'ETHUSDT' => ['base' => 'ETH', 'stepSize' => '0.00010000', 'minQty' => '0.00010000', 'maxQty' => '9000.00000000', 'minNotional' => 5.0, 'tickSize' => '0.01000000'],
        'XRPUSDT' => ['base' => 'XRP', 'stepSize' => '1.00000000', 'minQty' => '1.00000000', 'maxQty' => '9000000.00000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'BNBUSDT' => ['base' => 'BNB', 'stepSize' => '0.00100000', 'minQty' => '0.00100000', 'maxQty' => '9000.00000000', 'minNotional' => 5.0, 'tickSize' => '0.10000000'],
        'ADAUSDT' => ['base' => 'ADA', 'stepSize' => '0.10000000', 'minQty' => '0.10000000', 'maxQty' => '900000.00000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'BTCUSDT' => ['base' => 'BTC', 'stepSize' => '0.00001000', 'minQty' => '0.00001000', 'maxQty' => '9000.00000000', 'minNotional' => 5.0, 'tickSize' => '0.01000000'],
    ];

    /**
     * Fixture for `GET /api/v3/ticker/24hr` (docs/DESIGN-PORTFOLIO.md §5), built so the scanner
     * ranking can be pinned down exactly. One entry per case the scanner has to get right:
     *
     *   liquid_volatile   SOLUSDT, DOGEUSDT, ETHUSDT - deep books, tight spreads, ATR inside the
     *                     band. These are the rows that must come out on top, in that order.
     *   name_only_lev     JUPUSDT - the base merely ENDS in "UP" with a two-letter underlying,
     *                     so it is a real coin and must NOT be dropped as a leveraged token.
     *   illiquid          ILLIQUSDT - 0.4 M quote volume with a 3.5 % ATR: the most volatile
     *                     tradeable-looking row in the set, and it must still score zero.
     *   wide_spread       WIDEUSDT - 0.2 % spread, 3 % ATR: same lesson from the other side.
     *   atr_high/atr_low  NOISEUSDT (6.5 %) and FLATUSDT (0.2 %) - outside the ATR band.
     *   dust_step         DUSTUSDT - one step costs 5 USDT, far over the dust limit.
     *   stablecoin        USDCUSDT, FDUSDUSDT - in `scanner_exclude`, dropped outright.
     *   leveraged         BTCUPUSDT, BTCDOWNUSDT, ETHBULLUSDT, XRPBEARUSDT - dropped outright.
     *   not_trading       HALTUSDT (status BREAK) and NOSPOTUSDT (spot not allowed) - dropped.
     *   other_quote       ETHBTC - does not end in the quote asset, dropped.
     *
     * `atr_pct` is carried on the row itself: `Scanner::rank()` reads it there, which is exactly
     * how `Scanner::refresh()` hands the second pass the ATRs it fetched. The rows are listed in
     * a deliberately scrambled order so a test that asserts the ranking is testing the sort.
     */
    const TICKER_CASES = [
        'ILLIQUSDT' => ['case' => 'illiquid', 'base' => 'ILLIQ',
            'last' => 3.0, 'bid' => 2.9997, 'ask' => 3.0003, 'change_pct' => 9.5,
            'quote_vol' => 400000.0, 'atr_pct' => 3.5,
            'stepSize' => '0.01000000', 'minNotional' => 5.0, 'tickSize' => '0.00100000'],
        'SOLUSDT' => ['case' => 'liquid_volatile', 'base' => 'SOL',
            'last' => 130.0, 'bid' => 129.995, 'ask' => 130.005, 'change_pct' => 4.2,
            'quote_vol' => 400000000.0, 'atr_pct' => 1.8,
            'stepSize' => '0.00100000', 'minNotional' => 5.0, 'tickSize' => '0.01000000'],
        'USDCUSDT' => ['case' => 'stablecoin', 'base' => 'USDC',
            'last' => 1.0, 'bid' => 0.9999, 'ask' => 1.0001, 'change_pct' => 0.01,
            'quote_vol' => 900000000.0, 'atr_pct' => 0.02,
            'stepSize' => '0.10000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'BTCUPUSDT' => ['case' => 'leveraged', 'base' => 'BTCUP',
            'last' => 12.0, 'bid' => 11.999, 'ask' => 12.001, 'change_pct' => 11.0,
            'quote_vol' => 30000000.0, 'atr_pct' => 3.2,
            'stepSize' => '0.01000000', 'minNotional' => 5.0, 'tickSize' => '0.00100000'],
        'NOISEUSDT' => ['case' => 'atr_high', 'base' => 'NOISE',
            'last' => 0.5, 'bid' => 0.49995, 'ask' => 0.50005, 'change_pct' => 18.0,
            'quote_vol' => 80000000.0, 'atr_pct' => 6.5,
            'stepSize' => '0.00100000', 'minNotional' => 5.0, 'tickSize' => '0.00001000'],
        'ETHUSDT' => ['case' => 'liquid_volatile', 'base' => 'ETH',
            'last' => 3000.0, 'bid' => 2999.99, 'ask' => 3000.01, 'change_pct' => 1.1,
            'quote_vol' => 1200000000.0, 'atr_pct' => 0.7,
            'stepSize' => '0.00010000', 'minNotional' => 5.0, 'tickSize' => '0.01000000'],
        'WIDEUSDT' => ['case' => 'wide_spread', 'base' => 'WIDE',
            'last' => 1.001, 'bid' => 1.0, 'ask' => 1.002, 'change_pct' => 7.0,
            'quote_vol' => 20000000.0, 'atr_pct' => 3.0,
            'stepSize' => '0.10000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'JUPUSDT' => ['case' => 'name_only_lev', 'base' => 'JUP',
            'last' => 1.0, 'bid' => 0.9999, 'ask' => 1.0001, 'change_pct' => 2.5,
            'quote_vol' => 30000000.0, 'atr_pct' => 0.9,
            'stepSize' => '0.00100000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'FDUSDUSDT' => ['case' => 'stablecoin', 'base' => 'FDUSD',
            'last' => 1.0, 'bid' => 0.9999, 'ask' => 1.0001, 'change_pct' => 0.0,
            'quote_vol' => 500000000.0, 'atr_pct' => 0.03,
            'stepSize' => '0.10000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'DOGEUSDT' => ['case' => 'liquid_volatile', 'base' => 'DOGE',
            'last' => 0.2, 'bid' => 0.19999, 'ask' => 0.20001, 'change_pct' => 3.0,
            'quote_vol' => 150000000.0, 'atr_pct' => 1.0,
            'stepSize' => '1.00000000', 'minNotional' => 1.0, 'tickSize' => '0.00001000'],
        'FLATUSDT' => ['case' => 'atr_low', 'base' => 'FLAT',
            'last' => 10.0, 'bid' => 9.9995, 'ask' => 10.0005, 'change_pct' => 0.2,
            'quote_vol' => 60000000.0, 'atr_pct' => 0.2,
            'stepSize' => '0.00100000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'ETHBULLUSDT' => ['case' => 'leveraged', 'base' => 'ETHBULL',
            'last' => 4.0, 'bid' => 3.999, 'ask' => 4.001, 'change_pct' => 9.0,
            'quote_vol' => 25000000.0, 'atr_pct' => 2.9,
            'stepSize' => '0.01000000', 'minNotional' => 5.0, 'tickSize' => '0.00100000'],
        'DUSTUSDT' => ['case' => 'dust_step', 'base' => 'DUST',
            'last' => 5.0, 'bid' => 4.9995, 'ask' => 5.0005, 'change_pct' => 5.0,
            'quote_vol' => 9000000.0, 'atr_pct' => 1.5,
            'stepSize' => '1.00000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'XRPBEARUSDT' => ['case' => 'leveraged', 'base' => 'XRPBEAR',
            'last' => 0.8, 'bid' => 0.7999, 'ask' => 0.8001, 'change_pct' => 8.0,
            'quote_vol' => 15000000.0, 'atr_pct' => 2.7,
            'stepSize' => '0.01000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'HALTUSDT' => ['case' => 'not_trading', 'base' => 'HALT', 'status' => 'BREAK',
            'last' => 7.0, 'bid' => 6.9993, 'ask' => 7.0007, 'change_pct' => 6.0,
            'quote_vol' => 40000000.0, 'atr_pct' => 2.0,
            'stepSize' => '0.00100000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'BTCDOWNUSDT' => ['case' => 'leveraged', 'base' => 'BTCDOWN',
            'last' => 2.0, 'bid' => 1.9995, 'ask' => 2.0005, 'change_pct' => 12.0,
            'quote_vol' => 18000000.0, 'atr_pct' => 3.1,
            'stepSize' => '0.01000000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'NOSPOTUSDT' => ['case' => 'not_trading', 'base' => 'NOSPOT', 'spotAllowed' => false,
            'last' => 2.0, 'bid' => 1.9998, 'ask' => 2.0002, 'change_pct' => 3.0,
            'quote_vol' => 50000000.0, 'atr_pct' => 1.5,
            'stepSize' => '0.00100000', 'minNotional' => 5.0, 'tickSize' => '0.00010000'],
        'ETHBTC' => ['case' => 'other_quote', 'base' => 'ETH', 'quote' => 'BTC',
            'last' => 0.05, 'bid' => 0.049995, 'ask' => 0.050005, 'change_pct' => 1.5,
            'quote_vol' => 20000.0, 'atr_pct' => 1.4,
            'stepSize' => '0.00010000', 'minNotional' => 0.0001, 'tickSize' => '0.00000100'],
    ];

    /** @var array symbol => interval => parsed rows [openTime, open, high, low, close, volume, closeTime] */
    private $klines = [];
    /** @var array symbol => ['bid' => float, 'ask' => float, 'last' => float] */
    private $prices = [];
    /** @var int */
    private $serverTimeMs;
    /** @var int */
    private $timeOffsetMs = 0;
    /** @var array method => number of calls */
    private $calls = [];
    /** @var array method => BinanceException thrown once on the next call */
    private $failNext = [];
    /** @var array extra/overridden symbol info rows */
    private $extraInfo = [];
    /** @var array|null scripted /ticker/24hr rows; null uses the built-in fixture */
    private $tickers = null;

    /**
     * @param array    $klines       symbol => interval => fixture name | absolute path | array of raw or parsed rows
     * @param int|null $serverTimeMs fixed exchange clock (default SERVER_TIME_MS)
     */
    public function __construct(array $klines = [], ?int $serverTimeMs = null)
    {
        $this->serverTimeMs = $serverTimeMs === null ? self::SERVER_TIME_MS : $serverTimeMs;
        foreach ($klines as $symbol => $byInterval) {
            if (!is_array($byInterval)) {
                continue;
            }
            foreach ($byInterval as $interval => $source) {
                $this->setKlines((string) $symbol, (string) $interval, $source);
            }
        }
    }

    /* ------------------------------------------------------------ fixtures */

    /** Absolute path of a fixture: 'klines_15m_oversold' or 'klines_15m_oversold.json' or an absolute path. */
    public static function fixturePath(string $name): string
    {
        if ($name !== '' && ($name[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $name) === 1)) {
            return $name;
        }
        if (substr($name, -5) !== '.json') {
            $name .= '.json';
        }
        return __DIR__ . '/fixtures/' . $name;
    }

    /**
     * Load rows from a fixture name, a path or an array and normalise them to the Binance::klines()
     * shape: [openTime(int), open, high, low, close, volume (floats), closeTime(int)].
     * @param string|array $source
     * @throws RuntimeException when the file is missing or malformed
     */
    public static function loadFixture($source): array
    {
        if (is_string($source)) {
            $path = self::fixturePath($source);
            if (!is_file($path)) {
                throw new RuntimeException('Fixture not found: ' . $path);
            }
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                throw new RuntimeException('Fixture is not a JSON array: ' . $path);
            }
            $source = $decoded;
        }
        if (!is_array($source)) {
            throw new RuntimeException('Fixture source must be a name, a path or an array');
        }
        $rows = [];
        foreach ($source as $k) {
            if (!is_array($k) || count($k) < 7) {
                continue;
            }
            $k = array_values($k);
            $rows[] = [(int) $k[0], (float) $k[1], (float) $k[2], (float) $k[3], (float) $k[4], (float) $k[5], (int) $k[6]];
        }
        return $rows;
    }

    /** @param string|array $source fixture name | path | rows */
    public function setKlines(string $symbol, string $interval, $source): void
    {
        $this->klines[strtoupper($symbol)][$interval] = self::loadFixture($source);
    }

    /** Append one candle (raw or parsed row) to a series, e.g. to simulate a new closed candle. */
    public function appendKline(string $symbol, string $interval, array $row): void
    {
        $rows = self::loadFixture([$row]);
        if ($rows === []) {
            throw new RuntimeException('appendKline: malformed row');
        }
        $this->klines[strtoupper($symbol)][$interval][] = $rows[0];
    }

    /* ------------------------------------------------------------ scripting */

    /** Script the live price. $last (for prices()) defaults to the mid price. */
    public function setPrice(string $symbol, float $bid, float $ask, ?float $last = null): void
    {
        $this->prices[strtoupper($symbol)] = [
            'bid'  => $bid,
            'ask'  => $ask,
            'last' => $last === null ? ($bid + $ask) / 2.0 : $last,
        ];
    }

    public function clearPrice(string $symbol): void
    {
        unset($this->prices[strtoupper($symbol)]);
    }

    public function setServerTimeMs(int $ms): void
    {
        $this->serverTimeMs = $ms;
    }

    public function advanceMs(int $ms): void
    {
        $this->serverTimeMs += $ms;
    }

    /** Value returned by syncTime() (server − local, ms). */
    public function setTimeOffsetMs(int $ms): void
    {
        $this->timeOffsetMs = $ms;
    }

    /** Override or add a parsed symbol info row (merged over the built-in table). */
    public function setSymbolInfo(string $symbol, array $info): void
    {
        $this->extraInfo[strtoupper($symbol)] = $info;
    }

    /** Throw $e once on the next call of $method (klines | prices | bookTicker | symbolInfo | syncTime | serverTimeMs). */
    public function failNext(string $method, BinanceException $e): void
    {
        $this->failNext[$method] = $e;
    }

    public function callCount(string $method): int
    {
        return isset($this->calls[$method]) ? $this->calls[$method] : 0;
    }

    public function resetCalls(): void
    {
        $this->calls = [];
    }


    /* ------------------------------------------------------- 24h ticker (scanner) */

    /**
     * Script the rows `ticker24h()` answers with. Pass [] to go back to the built-in fixture.
     * @param array $rows rows in the Binance::normalizeTicker24h() shape
     */
    public function setTicker24h(array $rows): void
    {
        $this->tickers = $rows === [] ? null : array_values($rows);
    }

    /**
     * `GET /api/v3/ticker/24hr` (weight 80 with no symbol). Declared by MarketDataInterface —
     * `Scanner` still discovers it with method_exists(), so it stays safe either way.
     *
     * @param array $symbols [] for every symbol, else the subset to return
     * @return array rows ['symbol','last','bid','ask','change_pct','quote_vol','high','low']
     *               plus the fixture's `atr_pct`
     */
    public function ticker24h(array $symbols = []): array
    {
        $this->hit('ticker24h');
        $rows = $this->tickers === null ? self::ticker24hFixture() : $this->tickers;
        if ($symbols === []) {
            return $rows;
        }
        $want = [];
        foreach ($symbols as $s) {
            $want[strtoupper(trim((string) $s))] = true;
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($want[strtoupper((string) ($row['symbol'] ?? ''))])) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * The whole TICKER_CASES table as normalised /ticker/24hr rows, in the scrambled order the
     * const declares them (so a ranking assertion really tests the sort).
     *
     * @param bool $withAtr false strips `atr_pct`, which is how the FIRST pass of
     *                      Scanner::refresh() sees the rows: every survivor gates `atr_unknown`
     * @param array $only   optional list of `case` tags to keep ([] keeps everything)
     */
    public static function ticker24hFixture(bool $withAtr = true, array $only = []): array
    {
        $keep = [];
        foreach ($only as $tag) {
            $keep[(string) $tag] = true;
        }
        $rows = [];
        foreach (self::TICKER_CASES as $symbol => $c) {
            if ($keep !== [] && !isset($keep[(string) $c['case']])) {
                continue;
            }
            $row = [
                'symbol'     => (string) $symbol,
                'last'       => (float) $c['last'],
                'bid'        => (float) $c['bid'],
                'ask'        => (float) $c['ask'],
                'change_pct' => (float) $c['change_pct'],
                'quote_vol'  => (float) $c['quote_vol'],
                'high'       => (float) $c['last'] * (1.0 + (float) $c['atr_pct'] / 100.0),
                'low'        => (float) $c['last'] * (1.0 - (float) $c['atr_pct'] / 100.0),
            ];
            if ($withAtr) {
                $row['atr_pct'] = (float) $c['atr_pct'];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** Parsed symbol info for every TICKER_CASES symbol, ready to pass to Scanner::rank(). */
    public static function ticker24hInfo(): array
    {
        $out = [];
        foreach (self::TICKER_CASES as $symbol => $c) {
            $out[(string) $symbol] = self::caseInfoRow($c);
        }
        return $out;
    }

    /** Symbols of the TICKER_CASES rows carrying one `case` tag. */
    public static function ticker24hCase(string $case): array
    {
        $out = [];
        foreach (self::TICKER_CASES as $symbol => $c) {
            if ((string) $c['case'] === $case) {
                $out[] = (string) $symbol;
            }
        }
        return $out;
    }

    /** One TICKER_CASES entry as a parsed exchangeInfo row (DESIGN.md §6 shape). */
    private static function caseInfoRow(array $c): array
    {
        $step = (string) $c['stepSize'];
        return [
            'base'                 => (string) $c['base'],
            'quote'                => isset($c['quote']) ? (string) $c['quote'] : 'USDT',
            'status'               => isset($c['status']) ? (string) $c['status'] : 'TRADING',
            'spotAllowed'          => !array_key_exists('spotAllowed', $c) || (bool) $c['spotAllowed'],
            'quoteOrderQtyAllowed' => true,
            'stepSize'             => $step,
            'minQty'               => $step,
            'maxQty'               => '9000000.00000000',
            'marketStepSize'       => '0.00000000',
            'marketMinQty'         => '0.00000000',
            'minNotional'          => (float) $c['minNotional'],
            'applyMinToMarket'     => true,
            'tickSize'             => (string) $c['tickSize'],
            'basePrecision'        => 8,
            'quotePrecision'       => 8,
            'bidMultiplierUp'      => 0.0,
            'bidMultiplierDown'    => 0.0,
            'askMultiplierUp'      => 0.0,
            'askMultiplierDown'    => 0.0,
            'avgPriceMins'         => 0,
            'maxNumOrders'         => 200,
        ];
    }

    /* ------------------------------------------------------------ interface */

    public function klines(string $symbol, string $interval, int $limit): array
    {
        $this->hit('klines');
        $symbol = strtoupper($symbol);
        if (!isset($this->klines[$symbol][$interval])) {
            throw new BinanceException('Invalid symbol/interval (fake): ' . $symbol . ' ' . $interval, -1121, 400);
        }
        $rows  = $this->klines[$symbol][$interval];
        $limit = max(1, min(1000, $limit));
        if (count($rows) > $limit) {
            $rows = array_slice($rows, -$limit);
        }
        return array_values($rows);
    }

    public function prices(array $symbols): array
    {
        $this->hit('prices');
        $out = [];
        foreach ($symbols as $s) {
            $s = strtoupper((string) $s);
            if (isset($this->prices[$s])) {
                $out[$s] = (float) $this->prices[$s]['last'];
                continue;
            }
            $derived = $this->derivedPrice($s);
            if ($derived !== null) {
                $out[$s] = $derived;
            }
        }
        return $out;
    }

    public function bookTicker(string $symbol): array
    {
        $this->hit('bookTicker');
        $symbol = strtoupper($symbol);
        if (isset($this->prices[$symbol])) {
            return ['bid' => (float) $this->prices[$symbol]['bid'], 'ask' => (float) $this->prices[$symbol]['ask']];
        }
        $p = $this->derivedPrice($symbol);
        if ($p === null) {
            throw new BinanceException('Invalid symbol (fake): ' . $symbol, -1121, 400);
        }
        $half = $p * self::DERIVED_SPREAD / 2.0;
        return ['bid' => $p - $half, 'ask' => $p + $half];
    }

    public function symbolInfo(array $symbols): array
    {
        $this->hit('symbolInfo');
        $out = [];
        foreach ($symbols as $s) {
            $s = strtoupper((string) $s);
            $row = $this->infoFor($s);
            if ($row !== null) {
                $out[$s] = $row;
            }
        }
        return $out;
    }

    public function syncTime(): int
    {
        $this->hit('syncTime');
        return $this->timeOffsetMs;
    }

    public function serverTimeMs(): int
    {
        $this->hit('serverTimeMs');
        return $this->serverTimeMs;
    }

    /* ------------------------------------------------------------ helpers */

    /** Full parsed info for a symbol, or null when unknown. */
    public static function infoRow(string $symbol): ?array
    {
        $symbol = strtoupper($symbol);
        if (!isset(self::SYMBOL_INFO[$symbol])) {
            return null;
        }
        $s = self::SYMBOL_INFO[$symbol];
        return [
            'base'                 => $s['base'],
            'quote'                => 'USDT',
            'status'               => 'TRADING',
            'spotAllowed'          => true,
            'quoteOrderQtyAllowed' => true,
            'stepSize'             => $s['stepSize'],
            'minQty'               => $s['minQty'],
            'maxQty'               => $s['maxQty'],
            'marketStepSize'       => '0.00000000',
            'marketMinQty'         => '0.00000000',
            'minNotional'          => $s['minNotional'],
            'applyMinToMarket'     => true,
            'tickSize'             => $s['tickSize'],
            'basePrecision'        => 8,
            'quotePrecision'       => 8,
            // DESIGN-ENGINES.md §3: the engine filters. 0.0 multipliers mean
            // "PERCENT_PRICE_BY_SIDE absent", which Binance::priceAllowed() reads as "allowed".
            'bidMultiplierUp'      => 0.0,
            'bidMultiplierDown'    => 0.0,
            'askMultiplierUp'      => 0.0,
            'askMultiplierDown'    => 0.0,
            'avgPriceMins'         => 0,
            'maxNumOrders'         => isset($s['maxNumOrders']) ? (int) $s['maxNumOrders'] : 200,
        ];
    }

    /** Info for $symbol including instance overrides. */
    private function infoFor(string $symbol): ?array
    {
        if (isset($this->extraInfo[$symbol])) {
            $base = self::infoRow($symbol);
            return $base === null ? $this->extraInfo[$symbol] : array_merge($base, $this->extraInfo[$symbol]);
        }
        return self::infoRow($symbol);
    }

    /** Last closed close of the shortest series available for $symbol, or null. */
    private function derivedPrice(string $symbol): ?float
    {
        if (!isset($this->klines[$symbol])) {
            return null;
        }
        foreach (['15m', '5m', '1m', '30m', '1h', '4h', '1d'] as $iv) {
            if (!isset($this->klines[$symbol][$iv])) {
                continue;
            }
            $rows = $this->klines[$symbol][$iv];
            for ($i = count($rows) - 1; $i >= 0; $i--) {
                if ($rows[$i][6] <= $this->serverTimeMs) {
                    return (float) $rows[$i][4];
                }
            }
        }
        foreach ($this->klines[$symbol] as $rows) {
            if ($rows !== []) {
                return (float) $rows[count($rows) - 1][4];
            }
        }
        return null;
    }

    private function hit(string $method): void
    {
        $this->calls[$method] = (isset($this->calls[$method]) ? $this->calls[$method] : 0) + 1;
        if (isset($this->failNext[$method])) {
            $e = $this->failNext[$method];
            unset($this->failNext[$method]);
            throw $e;
        }
    }
}

/**
 * Offline exchange with a resting LIMIT-order book (docs/DESIGN-ENGINES.md §11).
 *
 * `PaperExchange` lives in lib/Exchange.php, is `final` and only knows market orders, so it
 * cannot be subclassed or extended from here. This is the test double the engine groups use
 * instead: it implements `ExchangeInterface` with exactly the market-order accounting of
 * `PaperExchange` (BUY fills at the ask, commission in the base asset, sellable quantity
 * floored to the step, the remainder reported as dust; SELL fills at the bid with the
 * commission taken in the quote asset) and adds the engine order surface
 * `limitOrder / cancelOrder / cancelAllOrders / openOrders / getOrder`, so `EngineOrders`
 * drives it unchanged.
 *
 *   $md = new FakeMarketData(['DOGEUSDT' => ['15m' => 'klines_15m_oversold']]);
 *   $md->setPrice('DOGEUSDT', 0.20000, 0.20010);
 *   $ex = new FakePaperExchange($md, 0.1, 50.0);
 *
 * A resting order fills when the *scripted* book crosses its price: a BUY when
 * `ask <= price`, a SELL when `bid >= price`. Matching is lazy — it runs on every
 * `bookTicker()`, `prices()`, `account()`, `openOrders()` and `getOrder()` — so a test only
 * has to script a price path with `FakeMarketData::setPrice()` and run the next tick.
 * `matchOrders()` forces the same pass explicitly.
 *
 * Resting orders lock their funds exactly like Binance does (a BUY locks quote, a SELL locks
 * base), so `account()` reports free and locked separately and the engine's equity, which
 * counts both, stays right while the ladder is on the book.
 */
final class FakePaperExchange implements ExchangeInterface
{
    /** Quantities and notionals below this are treated as zero. */
    const EPS = 1.0e-12;

    /** @var MarketDataInterface */
    private $md;
    /** @var float commission as a fraction (0.001 for 0.1 %) */
    private $fee;
    /** @var string */
    private $quoteAsset;
    /** @var string paper | demo | testnet | live — Bot reads its mode from here */
    private $mode = 'paper';
    /** @var array asset => ['free' => float, 'locked' => float] */
    private $wallet = [];
    /** @var array clientId => order row */
    private $orders = [];
    /** @var int */
    private $seq = 0;
    /** @var int resting orders that filled so far */
    public $fillCount = 0;
    /** @var int post-only orders rejected because they would have crossed */
    public $postOnlyRejects = 0;
    /** @var int limitOrder() calls (accepted or rejected) */
    public $limitCalls = 0;
    /** @var int marketBuy() calls, counted before any validation */
    public $marketBuyCalls = 0;
    /** @var int marketSell() calls, counted before any validation */
    public $marketSellCalls = 0;
    /** @var int cancelOrder() + cancelAllOrders() cancellations */
    public $cancelCount = 0;
    /** @var bool false makes a crossing post-only order fill instead of being rejected */
    private $rejectCrossingPostOnly = true;

    public function __construct(MarketDataInterface $md, float $feePct = 0.1, float $startUsdt = 100.0, string $quoteAsset = 'USDT')
    {
        $this->md         = $md;
        $this->fee        = max(0.0, $feePct) / 100.0;
        $this->quoteAsset = $quoteAsset;
        $this->wallet     = [$quoteAsset => ['free' => $startUsdt, 'locked' => 0.0]];
    }

    /* ------------------------------------------------------------ scripting */

    /** Bot reads its mode from the exchange: set 'live' to exercise the engine live guard. */
    public function setMode(string $mode): void
    {
        $this->mode = strtolower(trim($mode));
    }

    /** Set a free balance (locked is left untouched). */
    public function setBalance(string $asset, float $free): void
    {
        $this->wallet[$asset] = ['free' => $free, 'locked' => $this->locked($asset)];
    }

    public function free(string $asset): float
    {
        return isset($this->wallet[$asset]) ? (float) $this->wallet[$asset]['free'] : 0.0;
    }

    public function locked(string $asset): float
    {
        return isset($this->wallet[$asset]) ? (float) $this->wallet[$asset]['locked'] : 0.0;
    }

    /** @return array asset => ['free' => float, 'locked' => float] */
    public function wallet(): array
    {
        return $this->wallet;
    }

    public function feePct(): float
    {
        return $this->fee * 100.0;
    }

    /** When false a post-only order that would cross is accepted and fills instead of being rejected. */
    public function setRejectCrossingPostOnly(bool $on): void
    {
        $this->rejectCrossingPostOnly = $on;
    }

    /** Local (client-side) view of a resting order, or null. */
    public function order(string $clientId): ?array
    {
        return isset($this->orders[$clientId]) ? $this->orders[$clientId] : null;
    }

    /** Number of orders still resting (optionally for one symbol only). */
    public function openOrderCount(string $symbol = ''): int
    {
        $n = 0;
        foreach ($this->orders as $o) {
            if ($o['status'] === 'NEW' && ($symbol === '' || $o['symbol'] === strtoupper($symbol))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Fill every resting order the scripted book has crossed: BUY when ask <= price,
     * SELL when bid >= price. Idempotent — a filled order is never matched twice.
     * @return int number of orders filled by this pass
     */
    public function matchOrders(): int
    {
        $filled = 0;
        foreach ($this->orders as $cid => $o) {
            if ($o['status'] !== 'NEW') {
                continue;
            }
            $book = $this->bookOf($o['symbol']);
            if ($book === null) {
                continue;
            }
            if ($o['side'] === 'BUY' ? ($book['ask'] <= $o['price'] + self::EPS) : ($book['bid'] + self::EPS >= $o['price'])) {
                $this->fill($cid);
                $filled++;
            }
        }
        return $filled;
    }

    /* ------------------------------------------------------------ market data */

    public function klines(string $symbol, string $interval, int $limit): array
    {
        return $this->md->klines($symbol, $interval, $limit);
    }

    public function prices(array $symbols): array
    {
        $this->matchOrders();
        return $this->md->prices($symbols);
    }

    public function bookTicker(string $symbol): array
    {
        $this->matchOrders();
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
     * `GET /api/v3/ticker/24hr`, forwarded to the scripted market data when it has one.
     * MarketDataInterface declares it, and the method_exists() guard mirrors how Scanner
     * probes for it, so an exchange without one simply reports nothing to scan.
     */
    public function ticker24h(array $symbols = []): array
    {
        if (!method_exists($this->md, 'ticker24h')) {
            return [];
        }
        return $this->md->ticker24h($symbols);
    }

    /* ------------------------------------------------------------ account */

    public function mode(): string
    {
        return $this->mode;
    }

    public function account(): array
    {
        $this->matchOrders();
        $balances = [];
        foreach ($this->wallet as $asset => $b) {
            $free   = (float) $b['free'];
            $locked = (float) $b['locked'];
            if ($free > 0.0 || $locked > 0.0) {
                $balances[(string) $asset] = ['free' => $free, 'locked' => $locked];
            }
        }
        return ['balances' => $balances, 'taker_fee_pct' => $this->fee * 100.0, 'can_trade' => true];
    }

    /* ------------------------------------------------------------ market orders */

    public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array
    {
        $this->marketBuyCalls++;
        $symbol = strtoupper($symbol);
        $base   = $this->baseOf($symbol, $info);
        $step   = (string) ($info['stepSize'] ?? '0.00000001');
        $quoteUsdt = (float) Util::fmtQuote($quoteUsdt);
        if ($quoteUsdt <= 0.0) {
            throw new BinanceException('Fake BUY ' . $symbol . ': quote amount must be positive', -1013, 400);
        }
        if ($this->free($this->quoteAsset) + 1e-9 < $quoteUsdt) {
            throw new BinanceException('Fake BUY ' . $symbol . ': insufficient ' . $this->quoteAsset . ' balance', -2010, 400);
        }
        $ask = $this->askOf($symbol);
        $gross      = $quoteUsdt / $ask;
        $commission = $gross * $this->fee;
        $net        = $gross - $commission;
        $qty        = (float) Util::floorToStep(self::dec($net), $step);
        $dust       = max(0.0, $net - $qty);

        $this->add($this->quoteAsset, -$quoteUsdt);
        $this->add($base, $net);

        return $this->result($clientId, $symbol, 'BUY', 'MARKET', $ask, $gross, $quoteUsdt,
            $qty, $dust, $commission * $ask, $base, $commission, $quoteUsdt);
    }

    public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array
    {
        $this->marketSellCalls++;
        $symbol = strtoupper($symbol);
        $base   = $this->baseOf($symbol, $info);
        $qty    = (float) $qtyStr;
        if ($qty <= 0.0) {
            throw new BinanceException('Fake SELL ' . $symbol . ': quantity must be positive', -1013, 400);
        }
        if ($this->free($base) + 1e-9 < $qty) {
            throw new BinanceException('Fake SELL ' . $symbol . ': insufficient ' . $base . ' balance', -2010, 400);
        }
        $bid        = $this->bidOf($symbol);
        $grossQuote = $qty * $bid;
        $feeUsdt    = $grossQuote * $this->fee;
        $net        = $grossQuote - $feeUsdt;

        $this->add($base, -$qty);
        $this->add($this->quoteAsset, $net);

        return $this->result($clientId, $symbol, 'SELL', 'MARKET', $bid, $qty, $grossQuote,
            $qty, 0.0, $feeUsdt, $this->quoteAsset, $feeUsdt, $net);
    }

    /* ------------------------------------------------------------ limit orders */

    /**
     * Post one LIMIT (GTC) or LIMIT_MAKER order. $qtyStr/$priceStr are pre-rounded exact
     * decimal strings (never exponent notation) exactly as Binance is sent them.
     *
     * A post-only order that would cross the book is rejected with -2010 "would immediately
     * match and take", the rejection DESIGN-ENGINES.md §3 calls normal; a plain LIMIT that
     * crosses fills at once. Everything else rests until the scripted book reaches it.
     */
    public function limitOrder(string $symbol, string $side, string $qtyStr, string $priceStr, array $info, string $clientId, bool $postOnly): array
    {
        $this->limitCalls++;
        $symbol = strtoupper($symbol);
        $side   = strtoupper(trim($side)) === 'SELL' ? 'SELL' : 'BUY';
        $qty    = (float) $qtyStr;
        $price  = (float) $priceStr;
        $base   = $this->baseOf($symbol, $info);
        if ($qty <= 0.0 || $price <= 0.0) {
            throw new BinanceException('Fake ' . $side . ' ' . $symbol . ': quantity and price must be positive', -1013, 400);
        }
        if (isset($this->orders[$clientId])) {
            throw new BinanceException('Duplicate order sent.', -2010, 400);
        }
        $minQty = (float) ($info['minQty'] ?? 0);
        if ($minQty > 0.0 && $qty + self::EPS < $minQty) {
            throw new BinanceException('Filter failure: LOT_SIZE', -1013, 400);
        }
        $minNotional = (float) ($info['minNotional'] ?? 0);
        if ($minNotional > 0.0 && $qty * $price + 1e-9 < $minNotional) {
            throw new BinanceException('Filter failure: NOTIONAL', -1013, 400);
        }

        $book = $this->bookOf($symbol);
        $crosses = $book !== null
            && ($side === 'BUY' ? ($book['ask'] <= $price + self::EPS) : ($book['bid'] + self::EPS >= $price));
        if ($crosses && $postOnly && $this->rejectCrossingPostOnly) {
            $this->postOnlyRejects++;
            throw new BinanceException('Order would immediately match and take.', -2010, 400);
        }

        // lock the funds the resting order needs, exactly like the exchange does
        if ($side === 'BUY') {
            $need = $qty * $price;
            if ($this->free($this->quoteAsset) + 1e-9 < $need) {
                throw new BinanceException('Account has insufficient balance for requested action.', -2010, 400);
            }
            $this->add($this->quoteAsset, -$need);
            $this->addLocked($this->quoteAsset, $need);
        } else {
            if ($this->free($base) + 1e-9 < $qty) {
                throw new BinanceException('Account has insufficient balance for requested action.', -2010, 400);
            }
            $this->add($base, -$qty);
            $this->addLocked($base, $qty);
        }

        $this->seq++;
        $this->orders[$clientId] = [
            'client_id'    => $clientId,
            'order_id'     => 'fake-' . $this->seq,
            'symbol'       => $symbol,
            'base'         => $base,
            'side'         => $side,
            'type'         => $postOnly ? 'LIMIT_MAKER' : 'LIMIT',
            'price'        => $price,
            'price_str'    => $priceStr,
            'qty'          => $qty,
            'qty_str'      => $qtyStr,
            'step'         => (string) ($info['stepSize'] ?? '0.00000001'),
            'status'       => 'NEW',
            'executed_qty' => 0.0,
            'cum_quote'    => 0.0,
            'net_qty'      => 0.0,
            'dust_qty'     => 0.0,
            'fee'          => 0.0,
            'fee_asset'    => '',
            'proceeds'     => 0.0,
            'time'         => $this->md->serverTimeMs(),
        ];
        if ($crosses) {
            $this->fill($clientId);   // a plain LIMIT that crosses takes immediately
        }
        return $this->normalise($this->orders[$clientId]);
    }

    /** DELETE /api/v3/order. An order that already filled or vanished answers -2011, like Binance. */
    public function cancelOrder(string $symbol, string $clientId): array
    {
        $this->matchOrders();
        if (!isset($this->orders[$clientId]) || $this->orders[$clientId]['status'] !== 'NEW') {
            throw new BinanceException('Unknown order sent.', -2011, 400);
        }
        $this->release($clientId);
        $this->orders[$clientId]['status'] = 'CANCELED';
        $this->cancelCount++;
        return $this->raw($this->orders[$clientId]);
    }

    /** DELETE /api/v3/openOrders: cancels every resting order of $symbol. */
    public function cancelAllOrders(string $symbol): array
    {
        $this->matchOrders();
        $symbol = strtoupper($symbol);
        $out = [];
        foreach ($this->orders as $cid => $o) {
            if ($o['status'] !== 'NEW' || $o['symbol'] !== $symbol) {
                continue;
            }
            $this->release($cid);
            $this->orders[$cid]['status'] = 'CANCELED';
            $this->cancelCount++;
            $out[] = $this->raw($this->orders[$cid]);
        }
        return $out;
    }

    /** GET /api/v3/openOrders?symbol=… — raw Binance rows, keyed numerically. */
    public function openOrders(string $symbol): array
    {
        $this->matchOrders();
        $symbol = strtoupper($symbol);
        $out = [];
        foreach ($this->orders as $o) {
            if ($o['symbol'] === $symbol && ($o['status'] === 'NEW' || $o['status'] === 'PARTIALLY_FILLED')) {
                $out[] = $this->raw($o);
            }
        }
        return $out;
    }

    /** Normalised cumulative state of one order (null when this exchange never saw it). */
    public function getOrder(string $symbol, string $clientId): ?array
    {
        $this->matchOrders();
        if (!isset($this->orders[$clientId])) {
            return null;
        }
        return $this->normalise($this->orders[$clientId]);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * Fill a resting order completely at its own price, with the fee and dust accounting of
     * the market fills: a BUY pays its commission in the base asset (so the sellable quantity
     * is floored to the step and the remainder is dust), a SELL pays it in the quote asset.
     */
    private function fill(string $clientId): void
    {
        $o = $this->orders[$clientId];
        if ($o['status'] !== 'NEW') {
            return;
        }
        $qty   = (float) $o['qty'];
        $price = (float) $o['price'];
        if ($o['side'] === 'BUY') {
            $spent      = $qty * $price;
            $commission = $qty * $this->fee;
            $net        = $qty - $commission;
            $sellable   = (float) Util::floorToStep(self::dec($net), (string) $o['step']);
            $this->addLocked($this->quoteAsset, -$spent);
            $this->add($o['base'], $net);
            $o['cum_quote'] = $spent;
            $o['net_qty']   = $sellable;
            $o['dust_qty']  = max(0.0, $net - $sellable);
            $o['fee']       = $commission * $price;
            $o['fee_asset'] = $o['base'];
            $o['proceeds']  = $spent;
        } else {
            $gross   = $qty * $price;
            $feeUsdt = $gross * $this->fee;
            $this->addLocked($o['base'], -$qty);
            $this->add($this->quoteAsset, $gross - $feeUsdt);
            $o['cum_quote'] = $gross;
            $o['net_qty']   = $qty;
            $o['dust_qty']  = 0.0;
            $o['fee']       = $feeUsdt;
            $o['fee_asset'] = $this->quoteAsset;
            $o['proceeds']  = $gross - $feeUsdt;
        }
        $o['executed_qty'] = $qty;
        $o['status']       = 'FILLED';
        $this->orders[$clientId] = $o;
        $this->fillCount++;
    }

    /** Give the funds a resting order still locks back to the free balance. */
    private function release(string $clientId): void
    {
        $o = $this->orders[$clientId];
        if ($o['side'] === 'BUY') {
            $amount = $o['qty'] * $o['price'];
            $this->addLocked($this->quoteAsset, -$amount);
            $this->add($this->quoteAsset, $amount);
        } else {
            $this->addLocked($o['base'], -$o['qty']);
            $this->add($o['base'], $o['qty']);
        }
    }

    /** Normalised order state in the Binance::normalizeOrder() shape EngineOrders books. */
    private function normalise(array $o): array
    {
        return [
            'qty'       => (float) $o['net_qty'],
            'dust_qty'  => (float) $o['dust_qty'],
            'price'     => (float) $o['price'],
            'quote'     => (float) $o['proceeds'],
            'fee_usdt'  => (float) $o['fee'],
            'fee_asset' => (string) $o['fee_asset'],
            'order_id'  => (string) $o['order_id'],
            'status'    => (string) $o['status'],
            'raw'       => $this->raw($o),
        ];
    }

    /** Raw Binance order row (every number an exact decimal string, never an exponent). */
    private function raw(array $o): array
    {
        return [
            'symbol'              => $o['symbol'],
            'orderId'             => $o['order_id'],
            'clientOrderId'       => $o['client_id'],
            'price'               => (string) $o['price_str'],
            'origQty'             => (string) $o['qty_str'],
            'executedQty'         => self::dec((float) $o['executed_qty']),
            'cummulativeQuoteQty' => self::dec((float) $o['cum_quote']),
            'status'              => (string) $o['status'],
            'timeInForce'         => $o['type'] === 'LIMIT' ? 'GTC' : 'GTC',
            'type'                => (string) $o['type'],
            'side'                => (string) $o['side'],
            'time'                => (int) $o['time'],
            'updateTime'          => (int) $o['time'],
            'isWorking'           => true,
            'paper'               => true,
        ];
    }

    /** Normalised result of a market order (also remembered so getOrder() can answer). */
    private function result(
        string $clientId,
        string $symbol,
        string $side,
        string $type,
        float $price,
        float $executed,
        float $cumQuote,
        float $qty,
        float $dust,
        float $feeUsdt,
        string $feeAsset,
        float $commission,
        float $proceeds
    ): array {
        $this->seq++;
        $this->orders[$clientId] = [
            'client_id'    => $clientId,
            'order_id'     => 'fake-' . $this->seq,
            'symbol'       => $symbol,
            'base'         => $this->baseOf($symbol, []),
            'side'         => $side,
            'type'         => $type,
            'price'        => $price,
            'price_str'    => self::dec($price),
            'qty'          => $executed,
            'qty_str'      => self::dec($executed),
            'step'         => '0.00000001',
            'status'       => 'FILLED',
            'executed_qty' => $executed,
            'cum_quote'    => $cumQuote,
            'net_qty'      => $qty,
            'dust_qty'     => $dust,
            'fee'          => $feeUsdt,
            'fee_asset'    => $feeAsset,
            'proceeds'     => $proceeds,
            'time'         => $this->md->serverTimeMs(),
        ];
        return $this->normalise($this->orders[$clientId]);
    }

    /** Scripted book of $symbol, or null when the fake has no price for it. */
    private function bookOf(string $symbol): ?array
    {
        try {
            $b = $this->md->bookTicker($symbol);
        } catch (Throwable $e) {
            return null;
        }
        $bid = (float) ($b['bid'] ?? 0);
        $ask = (float) ($b['ask'] ?? 0);
        return ($bid > 0.0 && $ask > 0.0) ? ['bid' => $bid, 'ask' => $ask] : null;
    }

    private function askOf(string $symbol): float
    {
        $b = $this->bookOf($symbol);
        if ($b === null) {
            throw new BinanceException('Fake ' . $symbol . ': no ask price available', -1000, 400);
        }
        return $b['ask'];
    }

    private function bidOf(string $symbol): float
    {
        $b = $this->bookOf($symbol);
        if ($b === null) {
            throw new BinanceException('Fake ' . $symbol . ': no bid price available', -1000, 400);
        }
        return $b['bid'];
    }

    private function baseOf(string $symbol, array $info): string
    {
        if (isset($info['base']) && (string) $info['base'] !== '') {
            return (string) $info['base'];
        }
        $row = FakeMarketData::infoRow($symbol);
        if ($row !== null) {
            return (string) $row['base'];
        }
        $q = $this->quoteAsset;
        if (strlen($symbol) > strlen($q) && substr($symbol, -strlen($q)) === $q) {
            return substr($symbol, 0, -strlen($q));
        }
        return $symbol;
    }

    private function add(string $asset, float $delta): void
    {
        $free = $this->free($asset) + $delta;
        if ($free > -1e-9 && $free < self::EPS) {
            $free = 0.0;
        }
        $this->wallet[$asset] = ['free' => $free, 'locked' => $this->locked($asset)];
    }

    private function addLocked(string $asset, float $delta): void
    {
        $locked = $this->locked($asset) + $delta;
        if ($locked > -1e-9 && $locked < self::EPS) {
            $locked = 0.0;
        }
        $this->wallet[$asset] = ['free' => $this->free($asset), 'locked' => $locked];
    }

    /** Float → plain decimal string (10 dp, trimmed): never exponent notation, never "-0". */
    private static function dec(float $v): string
    {
        $s = sprintf('%.10F', $v);
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return ($s === '' || $s === '-0') ? '0' : $s;
    }
}

/**
 * Labelled observation fixtures for the learning groups (docs/DESIGN-LEARNING.md §8).
 *
 * `set()` generates a realistic observation set (the `observations` rows of
 * DESIGN-LEARNING.md §2, ready for `Db::insertObservation()`) with a KNOWN embedded
 * relationship, so a test can assert both halves of the claim:
 *
 *   * `Learn::insights()` / `Learn::adjustments()` FIND the relationship that was put
 *     there on purpose - by default "low RSI genuinely wins more": every row whose
 *     `rsi` is at or below 30 wins at `win_rate_good`, every other row at
 *     `win_rate_bad`, and nothing else in the row correlates with the outcome;
 *   * and they REJECT a feature that carries none. `spread_pct`, `hour_utc` and the
 *     neutral `rsi_up` alternate on the row index, which is coprime with the win
 *     pattern, so each of their buckets holds exactly the same win rate and average
 *     PnL as every other - the honest answer there is "no conclusion", and a test
 *     that sees `confident => true` for one of them has caught a real bug.
 *
 * Everything is deterministic: no clock, no randomness, no shuffle. Wins are spread
 * evenly across the indices by the Bresenham rule
 * `intdiv(($i+1)*$wins, $n) > intdiv($i*$wins, $n)`, which puts the win/loss pattern on
 * a period of `n / gcd(n, wins)` - 5 for the default 80 % / 20 % rates - while the
 * decorative features cycle on 2, 7 and 24. The two never line up, so a feature only
 * separates the outcomes when this class was asked to make it separate them.
 *
 * `measure()` recomputes the outcome statistics of a slice straight from the rows,
 * with no help from `Learn`, so a test can pin the fixture down before trusting
 * anything the learner says about it.
 */
final class FakeObservations
{
    /**
     * The score components of DESIGN-LEARNING.md §4, the captured feature that stands
     * for each one (DESIGN-LEARNING.md §2 plus the two 1/0 flags Bot captures for the
     * components no continuous feature covers), and the value that makes the
     * component's condition hold ('good') or fail ('bad').
     *
     * Naming a component in `carriers` is what embeds a relationship for it: its
     * condition then holds on exactly the winning-biased side of the set.
     */
    const CARRIERS = [
        'rsi'      => ['feature' => 'rsi',       'good' => 26.0, 'bad' => 55.0, 'spread' => 0.9],
        'bb'       => ['feature' => 'bb_pos',    'good' => 0.0,  'bad' => 0.5,  'spread' => 0.0],
        'vol'      => ['feature' => 'vol_ratio', 'good' => 1.6,  'bad' => 0.9,  'spread' => 0.0],
        'rsi_up'   => ['feature' => 'rsi_up',    'good' => 1.0,  'bad' => 0.0,  'spread' => 0.0],
        'reversal' => ['feature' => 'reversal',  'good' => 1.0,  'bad' => 0.0,  'spread' => 0.0],
        'trend'    => ['feature' => 'trend_up',  'good' => 1.0,  'bad' => 0.0,  'spread' => 0.0],
    ];

    /**
     * The neutral feature vector. `rsi` 45 fires no component condition, `bb_pos` 0.5 is
     * mid-band, `vol_ratio` 1.0 is under the 1.2 the `vol` component wants, `trend_up` 1
     * is the same for every row (so the `trend` side is never split), and `reversal` 0
     * never fires. Anything not listed here is derived from the row index.
     */
    const NEUTRAL = [
        'rsi' => 45.0, 'atr_pct' => 0.90, 'atr1h_pct' => 1.20, 'bb_pos' => 0.50,
        'vol_ratio' => 1.00, 'trend_up' => 1.0, 'reversal' => 0.0, 'step_value_pct' => 0.10,
    ];

    /** The two spread buckets the decorative `spread_pct` alternates between. */
    const SPREADS = [0.005, 0.025];

    /**
     * A labelled observation set.
     *
     * @param array $opts
     *   n              int    rows per side (default 120); n_good / n_bad override one side
     *   carriers       array  component names from CARRIERS whose condition holds on the
     *                         good side only (default ['rsi'] - "low RSI genuinely wins more")
     *   win_rate_good  float  win rate of the good side (default 0.80)
     *   win_rate_bad   float  win rate of the bad side  (default 0.20)
     *   pnl_win        float  PnL of a win  (default +0.30 USDT)
     *   pnl_loss       float  PnL of a loss (default -0.20 USDT)
     *   mode/engine/symbol    scoping columns (default paper / signal / SOLUSDT)
     *   end            string ISO instant every row is resolved strictly BEFORE
     *                         (default one hour ago), oldest row first
     *   spacing_sec    int    seconds between consecutive resolutions (default 60)
     *   ts_offset_sec  int    ts − resolved_at, i.e. how long the trade was held in the
     *                         record (default −1800; make it more negative to place `ts`
     *                         before a recompute instant while `resolved_at` is after it)
     *   hold_minutes   float  held_minutes column (default 30)
     *   skipped        int    extra `skipped` control rows resolved to `not_taken`
     *   flat           int    extra RESOLVED rows per side whose outcome is `flat`: they
     *                         carry the side's conditions and a zero PnL, so they swell `n`
     *                         without adding a single win or loss - the shape that proves
     *                         the sample floor is counted on DECIDED outcomes, not on rows
     *   open           int    extra `entered` rows per side that have NOT resolved yet (no
     *                         outcome, no resolved_at): never evidence, but reported as a
     *                         bucket's `open_now` censoring
     *   score/threshold/entry_quote  the remaining columns
     * @return array rows for Db::insertObservation(), oldest first
     */
    public static function set(array $opts = []): array
    {
        $o = array_merge([
            'n'             => 120,
            'n_good'        => null,
            'n_bad'         => null,
            'carriers'      => ['rsi'],
            'win_rate_good' => 0.80,
            'win_rate_bad'  => 0.20,
            'pnl_win'       => 0.30,
            'pnl_loss'      => -0.20,
            'mode'          => 'paper',
            'engine'        => 'signal',
            'symbol'        => 'SOLUSDT',
            'end'           => null,
            'spacing_sec'   => 60,
            'ts_offset_sec' => -1800,
            'hold_minutes'  => 30.0,
            'skipped'       => 0,
            'flat'          => 0,
            'open'          => 0,
            'score'         => 70,
            'threshold'     => 60,
            'entry_quote'   => 6.5,
        ], $opts);

        $nGood = $o['n_good'] === null ? (int) $o['n'] : (int) $o['n_good'];
        $nBad  = $o['n_bad'] === null ? (int) $o['n'] : (int) $o['n_bad'];
        $nGood = $nGood > 0 ? $nGood : 0;
        $nBad  = $nBad > 0 ? $nBad : 0;

        $carriers = [];
        foreach ((array) $o['carriers'] as $c) {
            $c = (string) $c;
            if (isset(self::CARRIERS[$c])) {
                $carriers[] = $c;
            }
        }

        $good = self::side(true, $nGood, (float) $o['win_rate_good'], $carriers, $o);
        $bad  = self::side(false, $nBad, (float) $o['win_rate_bad'], $carriers, $o);

        // interleave the two sides so neither owns a contiguous block of the timeline
        $rows = [];
        $max  = $nGood > $nBad ? $nGood : $nBad;
        for ($j = 0; $j < $max; $j++) {
            if (isset($good[$j])) {
                $rows[] = $good[$j];
            }
            if (isset($bad[$j])) {
                $rows[] = $bad[$j];
            }
        }
        // resolved-but-undecided rows: they are evidence rows (TRADED_OUTCOMES holds
        // 'flat') and so they count in `n`, but they are never a win or a loss
        $nFlat = (int) $o['flat'] > 0 ? (int) $o['flat'] : 0;
        for ($j = 0; $j < $nFlat; $j++) {
            $rows[] = self::undecidedRow($j, true, $carriers, $o, 'flat');
            $rows[] = self::undecidedRow($j, false, $carriers, $o, 'flat');
        }
        // still-open entries: no outcome at all, so they are excluded from every figure
        // and only ever surface as a bucket's `open_now`
        $nOpen = (int) $o['open'] > 0 ? (int) $o['open'] : 0;
        for ($j = 0; $j < $nOpen; $j++) {
            $rows[] = self::undecidedRow($j, true, $carriers, $o, '');
            $rows[] = self::undecidedRow($j, false, $carriers, $o, '');
        }
        for ($j = 0; $j < (int) $o['skipped']; $j++) {
            $rows[] = self::skippedRow($j, $o);
        }

        return self::stamp($rows, $o);
    }

    /** Insert a generated set through Db::insertObservation(); returns the number of rows. */
    public static function seed(Db $db, array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            if (is_array($row) && $db->insertObservation($row) > 0) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Outcome statistics of the rows whose $feature satisfies $op $value, computed here
     * and NOT by Learn, so a test can pin the fixture down independently.
     *
     * @param string $op 'lte' | 'lt' | 'gte' | 'gt' | 'eq' | 'any'
     * @return array{n:int,wins:int,losses:int,win_rate:float,avg_pnl:float,total_pnl:float}
     */
    public static function measure(array $rows, string $feature = '', string $op = 'any', float $value = 0.0): array
    {
        $n = 0;
        $wins = 0;
        $losses = 0;
        $sum = 0.0;
        foreach ($rows as $row) {
            if (!is_array($row) || (string) ($row['decision'] ?? '') !== 'entered') {
                continue;
            }
            if ($feature !== '') {
                $f = isset($row['features']) && is_array($row['features']) ? $row['features'] : [];
                if (!array_key_exists($feature, $f) || !is_numeric($f[$feature])) {
                    continue;
                }
                if (!self::holds((float) $f[$feature], $op, $value)) {
                    continue;
                }
            }
            $n++;
            $outcome = (string) ($row['outcome'] ?? '');
            if ($outcome === 'win') {
                $wins++;
            } elseif ($outcome === 'loss') {
                $losses++;
            }
            if (isset($row['pnl_usdt']) && is_numeric($row['pnl_usdt'])) {
                $sum += (float) $row['pnl_usdt'];
            }
        }
        $decided = $wins + $losses;
        return [
            'n'         => $n,
            'wins'      => $wins,
            'losses'    => $losses,
            'win_rate'  => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
            'avg_pnl'   => $n > 0 ? $sum / $n : 0.0,
            'total_pnl' => $sum,
        ];
    }

    /** The distinct values one feature takes across a generated set, sorted ascending. */
    public static function values(array $rows, string $feature): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $f = (is_array($row) && isset($row['features']) && is_array($row['features'])) ? $row['features'] : [];
            if (array_key_exists($feature, $f) && is_numeric($f[$feature])) {
                $seen[(string) (float) $f[$feature]] = (float) $f[$feature];
            }
        }
        $out = array_values($seen);
        sort($out);
        return $out;
    }

    /* ------------------------------------------------------------ internals */

    /** One side of the set: $good true = the side every carrier condition holds on. */
    private static function side(bool $good, int $n, float $winRate, array $carriers, array $o): array
    {
        if ($n <= 0) {
            return [];
        }
        $wins = (int) round(Util::clamp($winRate, 0.0, 1.0) * $n);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            // Bresenham: the wins are spread evenly over the indices instead of sitting in
            // one block, so no decorative feature can accidentally correlate with them
            $isWin = intdiv(($i + 1) * $wins, $n) > intdiv($i * $wins, $n);
            $rows[] = self::row($i, $good, $isWin, $carriers, $o);
        }
        return $rows;
    }

    /** One `entered` observation. */
    private static function row(int $i, bool $good, bool $isWin, array $carriers, array $o): array
    {
        $f = self::NEUTRAL;
        $f['spread_pct'] = self::SPREADS[$i % count(self::SPREADS)];
        $f['hour_utc']   = (float) ($i % 24);
        $f['dow']        = (float) ($i % 7);
        $f['rsi_up']     = (float) ($i % 2);
        foreach ($carriers as $c) {
            $spec = self::CARRIERS[$c];
            $base = $good ? (float) $spec['good'] : (float) $spec['bad'];
            $f[(string) $spec['feature']] = $base + (float) $spec['spread'] * (float) ($i % 4);
        }
        $pnl   = $isWin ? (float) $o['pnl_win'] : (float) $o['pnl_loss'];
        $quote = (float) $o['entry_quote'];
        return [
            'mode'         => (string) $o['mode'],
            'engine'       => (string) $o['engine'],
            'symbol'       => (string) $o['symbol'],
            'decision'     => 'entered',
            'skip_reason'  => null,
            'score'        => (int) $o['score'],
            'threshold'    => (int) $o['threshold'],
            'features'     => $f,
            // deliberately no position_id: these rows stand for closed trades whose
            // positions are not part of the fixture, and a dangling id would invite
            // Bot's resolution sweep to look for a row that never existed
            'position_id'  => null,
            'outcome'      => $isWin ? 'win' : 'loss',
            'pnl_usdt'     => $pnl,
            'pnl_pct'      => $quote > 0.0 ? $pnl / $quote * 100.0 : 0.0,
            'exit_reason'  => $isWin ? 'take_profit' : 'stop_loss',
            'held_minutes' => (float) $o['hold_minutes'],
        ];
    }

    /**
     * One `entered` row that is NOT a win and NOT a loss: `flat` when $outcome is 'flat'
     * (resolved, zero PnL) and still open when $outcome is '' (no outcome column at all,
     * which is what makes stamp() leave `resolved_at` off it).
     */
    private static function undecidedRow(int $i, bool $good, array $carriers, array $o, string $outcome): array
    {
        $row = self::row($i, $good, false, $carriers, $o);
        $row['pnl_usdt'] = 0.0;
        $row['pnl_pct']  = 0.0;
        if ($outcome === 'flat') {
            $row['outcome']     = 'flat';
            $row['exit_reason'] = 'max_hold';
            return $row;
        }
        unset($row['outcome'], $row['pnl_usdt'], $row['pnl_pct'], $row['exit_reason'], $row['held_minutes']);
        return $row;
    }

    /** One `skipped` control row: the control group of DESIGN-LEARNING.md §2, never a win or a loss. */
    private static function skippedRow(int $i, array $o): array
    {
        $f = self::NEUTRAL;
        $f['spread_pct'] = self::SPREADS[$i % count(self::SPREADS)];
        $f['hour_utc']   = (float) ($i % 24);
        $f['dow']        = (float) ($i % 7);
        $f['rsi_up']     = (float) ($i % 2);
        $f['rsi']        = 26.0;         // the control group saw the winning condition and still did not trade
        return [
            'mode'        => (string) $o['mode'],
            'engine'      => (string) $o['engine'],
            'symbol'      => (string) $o['symbol'],
            'decision'    => 'skipped',
            'skip_reason' => 'below_threshold',
            'score'       => 10,
            'threshold'   => (int) $o['threshold'],
            'features'    => $f,
            'outcome'     => 'not_taken',
        ];
    }

    /**
     * Stamp `ts` and `resolved_at` on the rows: the last row resolves one `spacing_sec`
     * before `end`, every earlier row one step before that, and `ts` sits
     * `ts_offset_sec` from its own resolution.
     */
    private static function stamp(array $rows, array $o): array
    {
        $endTs = null;
        if ($o['end'] !== null && (string) $o['end'] !== '') {
            $endTs = Util::isoToTs((string) $o['end']);
        }
        if ($endTs === null) {
            $endTs = time() - 3600;
        }
        $spacing = (int) $o['spacing_sec'] > 0 ? (int) $o['spacing_sec'] : 60;
        $offset  = (int) $o['ts_offset_sec'];
        $m       = count($rows);
        $out     = [];
        foreach (array_values($rows) as $k => $row) {
            $resolved = $endTs - ($m - $k) * $spacing;
            $row['ts'] = Util::nowIso($resolved + $offset);
            if (array_key_exists('outcome', $row) && $row['outcome'] !== null) {
                $row['resolved_at'] = Util::nowIso($resolved);
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function holds(float $v, string $op, float $value): bool
    {
        if ($op === 'lte') {
            return $v <= $value;
        }
        if ($op === 'lt') {
            return $v < $value;
        }
        if ($op === 'gte') {
            return $v >= $value;
        }
        if ($op === 'gt') {
            return $v > $value;
        }
        if ($op === 'eq') {
            return abs($v - $value) < 1e-12;
        }
        return true;
    }
}
