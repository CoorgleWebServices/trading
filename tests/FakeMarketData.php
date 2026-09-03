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
            $row = self::infoRow($s);
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
