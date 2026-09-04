<?php
declare(strict_types=1);

/**
 * Offline test suite (DESIGN.md §13).
 *
 *   php tests/run.php        prints one "ok"/"FAIL" line per assertion
 *   php tests/run.php -q     prints only failures and the summary
 *
 * Exit code = number of failed assertions (0 = success, capped at 250);
 * 251 when nothing could run because lib files are missing.
 *
 * Nothing here touches the network. TRADER_ROOT is pointed at a fresh temporary
 * directory so data/ (config.json, SQLite files, bot.log, tick.lock) never
 * touches the real installation. Each group gets its own SQLite file.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('html_errors', '0');
date_default_timezone_set('UTC');
set_time_limit(0);

$TEST_QUIET = in_array('-q', $argv ?? [], true);
$PROJECT    = dirname(__DIR__);

/* ------------------------------------------------------------ temp root */

$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/trader-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
if (!@mkdir($tmpRoot . '/data', 0750, true) && !is_dir($tmpRoot . '/data')) {
    fwrite(STDERR, "Cannot create temp dir $tmpRoot\n");
    exit(251);
}
define('TRADER_ROOT', $tmpRoot);

register_shutdown_function(static function () use ($tmpRoot): void {
    // best-effort cleanup of the temp root (never fatal)
    $it = @scandir($tmpRoot . '/data');
    if (is_array($it)) {
        foreach ($it as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($tmpRoot . '/data/' . $f);
            }
        }
    }
    @rmdir($tmpRoot . '/data');
    @rmdir($tmpRoot);
});

/* ------------------------------------------------------------ lib loading */

$libs = ['Util', 'Db', 'Log', 'Binance', 'Indicators', 'Strategy', 'Risk', 'Exchange', 'Sleeve', 'Scanner', 'EngineOrders', 'EngineGrid', 'EnginePmm', 'Bot', 'Panel'];
$missing = [];
foreach ($libs as $lib) {
    $file = $PROJECT . '/lib/' . $lib . '.php';
    if (!is_file($file)) {
        $missing[] = 'lib/' . $lib . '.php';
        continue;
    }
    require_once $file;
}
if ($missing !== []) {
    echo 'skip: missing ' . implode(', ', $missing) . " - groups that need them are skipped\n";
}
if (class_exists('Util', false) && is_file($PROJECT . '/config.php')) {
    require_once $PROJECT . '/config.php';   // Bot/Risk persist enabled=false through it when present (needs lib/Util.php)
}
$fakeMd = __DIR__ . '/FakeMarketData.php';
if (is_file($fakeMd) && interface_exists('MarketDataInterface', false)) {
    require_once $fakeMd;
}
if (class_exists('Log', false)) {
    Log::setPath($tmpRoot . '/data/bot.log');
}

/* ------------------------------------------------------------ assert helper */

final class T
{
    public static $pass = 0;
    public static $fail = 0;
    public static $skipped = 0;
    public static $groups = 0;
    public static $quiet = false;
    public static $group = '';

    public static function ok(bool $cond, string $name, string $detail = ''): bool
    {
        if ($cond) {
            self::$pass++;
            if (!self::$quiet) {
                echo 'ok   - ' . self::$group . ': ' . $name . "\n";
            }
        } else {
            self::$fail++;
            echo 'FAIL - ' . self::$group . ': ' . $name . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
        }
        return $cond;
    }

    /** Strict comparison for ints/strings/bools/null; floats within 1e-9 relative. */
    public static function eq($expected, $actual, string $name): bool
    {
        if (is_float($expected) || is_float($actual)) {
            return self::near((float) $expected, (float) $actual, 1e-9 * max(1.0, abs((float) $expected)), $name);
        }
        return self::ok($expected === $actual, $name, 'expected ' . self::dump($expected) . ' got ' . self::dump($actual));
    }

    public static function near(float $expected, float $actual, float $tol, string $name): bool
    {
        return self::ok(is_finite($actual) && abs($expected - $actual) <= $tol, $name, 'expected ' . $expected . ' ±' . $tol . ' got ' . $actual);
    }

    public static function contains(array $haystack, $needle, string $name): bool
    {
        return self::ok(in_array($needle, $haystack, true), $name, self::dump($needle) . ' not in ' . self::dump($haystack));
    }

    public static function strContains(string $haystack, string $needle, string $name): bool
    {
        return self::ok(strpos($haystack, $needle) !== false, $name, '"' . $needle . '" not in "' . $haystack . '"');
    }

    /** @param string[] $needs class/interface/function names that must exist, else the group is skipped */
    public static function group(string $name, array $needs, callable $fn): void
    {
        self::$groups++;
        self::$group = $name;
        foreach ($needs as $n) {
            if (!class_exists($n, false) && !interface_exists($n, false) && !function_exists($n)) {
                self::$skipped++;
                echo 'skip - ' . $name . ': needs ' . $n . " (file missing or not loadable)\n";
                return;
            }
        }
        try {
            $fn();
        } catch (Throwable $e) {
            self::$fail++;
            echo 'FAIL - ' . $name . ': uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    public static function dump($v): string
    {
        if (is_array($v)) {
            $j = json_encode($v);
            return $j === false ? 'array' : (strlen($j) > 160 ? substr($j, 0, 157) . '...' : $j);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return '"' . $v . '"';
        }
        return (string) $v;
    }
}
T::$quiet = $TEST_QUIET;

/* ------------------------------------------------------------ shared helpers */

/** Design §3 defaults (kept inline so the suite does not depend on config.php). */
function cfg(array $over = []): array
{
    $d = [
        'panel_password_hash' => '', 'cron_key' => 'ab', 'api_key' => '', 'api_secret' => '',
        'mode' => 'paper', 'enabled' => false, 'force_https' => true,
        'symbols' => ['SOLUSDT', 'ETHUSDT', 'XRPUSDT', 'DOGEUSDT', 'BNBUSDT', 'ADAUSDT'], 'quote_asset' => 'USDT',
        'trade_usdt' => 6.5, 'equity_floor_usdt' => 7.0, 'hwm_drawdown_pct' => 20.0,
        'daily_loss_cap_pct' => 2.0, 'weekly_loss_cap_pct' => 5.0, 'max_trades_per_day' => 3, 'max_orders_per_hour' => 2,
        'max_consecutive_losses' => 3, 'cooldown_after_loss_min' => 45, 'cooldown_after_2_losses_min' => 180,
        'take_profit_pct' => 1.0, 'take_profit_max_pct' => 2.0, 'stop_loss_pct' => 0.7,
        'trailing_activate_pct' => 0.6, 'trailing_distance_pct' => 0.4, 'trailing_floor_pct' => 0.25,
        'max_hold_minutes' => 240, 'entry_threshold' => 60, 'adaptive' => true,
        'candle_interval' => '15m', 'trend_interval' => '1h',
        'atr_min_pct' => 0.30, 'atr_max_pct' => 1.5, 'atr1h_min_pct' => 0.5, 'atr1h_max_pct' => 3.0,
        'max_spread_pct' => 0.05, 'fee_pct' => 0.1, 'paper_start_usdt' => 10.0, 'recv_window' => 10000, 'timezone' => 'UTC',
    ];
    return array_merge($d, $over);
}

/** Fresh SQLite database for a group (drops the singleton first). */
function freshDb(string $tag): Db
{
    Db::reset();
    $path = TRADER_ROOT . '/data/test-' . preg_replace('/[^a-z0-9]+/i', '-', $tag) . '-' . bin2hex(random_bytes(2)) . '.sqlite';
    return Db::get($path);
}

function fixture(string $name): array
{
    return FakeMarketData::loadFixture($name);
}

function closedRows(string $name, ?int $serverMs = null): array
{
    return Indicators::closed(fixture($name), $serverMs === null ? FakeMarketData::SERVER_TIME_MS : $serverMs);
}

function col(array $rows, int $i): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = (float) $r[$i];
    }
    return $out;
}

function countRows(Db $db, string $sql, array $params = []): int
{
    $st = $db->pdo()->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

function isoAgo(int $seconds): string
{
    return Util::nowIso(time() - $seconds);
}

/** Insert a CLOSED position row (defaults are sane; override anything). */
function closedPosition(Db $db, float $pnl, array $over = []): int
{
    $row = array_merge([
        'mode' => 'paper', 'symbol' => 'SOLUSDT', 'status' => 'CLOSED', 'qty' => 0.05, 'dust_qty' => 0.0,
        'entry_price' => 130.0, 'entry_eff' => 130.0, 'entry_quote' => 6.5, 'entry_fee_usdt' => 0.0065,
        'exit_price' => 130.0, 'exit_quote' => 6.5 + $pnl, 'exit_fee_usdt' => 0.0065,
        'pnl_usdt' => $pnl, 'pnl_pct' => $pnl / 6.5 * 100.0,
        'stop_price' => 129.09, 'take_profit_price' => 131.3, 'trail_high' => 130.0, 'trailing_armed' => 0,
        'score' => 80, 'entry_reason' => 'test', 'exit_reason' => $pnl >= 0 ? 'take_profit' : 'stop_loss',
        'opened_at' => isoAgo(3600), 'closed_at' => isoAgo(1800),
    ], $over);
    return $db->insertPosition($row);
}

/**
 * Exchange decorator that makes the NEXT marketBuy behave like a timed-out POST /order:
 * the inner exchange fills the order, but the caller sees BinanceException(-1007).
 */
if (interface_exists('ExchangeInterface', false)) {
    final class TimeoutOnceExchange implements ExchangeInterface
    {
        /** @var ExchangeInterface */
        private $inner;
        /** @var bool */
        public $armed = true;
        /** @var array clientId => normalised fill — what the real exchange remembers regardless of our DB */
        private $fills = [];

        public function __construct(ExchangeInterface $inner)
        {
            $this->inner = $inner;
        }

        public function mode(): string { return $this->inner->mode(); }
        public function account(): array { return $this->inner->account(); }
        public function klines(string $symbol, string $interval, int $limit): array { return $this->inner->klines($symbol, $interval, $limit); }
        public function prices(array $symbols): array { return $this->inner->prices($symbols); }
        public function bookTicker(string $symbol): array { return $this->inner->bookTicker($symbol); }
        public function symbolInfo(array $symbols): array { return $this->inner->symbolInfo($symbols); }
        public function syncTime(): int { return $this->inner->syncTime(); }
        public function serverTimeMs(): int { return $this->inner->serverTimeMs(); }
        public function ticker24h(array $symbols = []): array { return $this->inner->ticker24h($symbols); }

        public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array
        {
            $r = $this->inner->marketBuy($symbol, $quoteUsdt, $info, $clientId);   // the exchange DID fill it
            $this->fills[$clientId] = $r;
            if ($this->armed) {
                $this->armed = false;
                throw new BinanceException('Timeout waiting for response of POST /api/v3/order (simulated)', -1007, 504);
            }
            return $r;
        }

        public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array
        {
            $r = $this->inner->marketSell($symbol, $qtyStr, $info, $clientId);
            $this->fills[$clientId] = $r;
            return $r;
        }

        public function getOrder(string $symbol, string $clientId): ?array
        {
            if (isset($this->fills[$clientId])) {
                return $this->fills[$clientId];
            }
            return $this->inner->getOrder($symbol, $clientId);
        }
    }
}

/**
 * Exchange decorator whose bookTicker() throws for ONE symbol, the way a 5xx from
 * /api/v3/ticker/bookTicker reaches the bot. Everything else is forwarded, including the
 * engine order surface, so a portfolio tick runs normally around the sleeve that fails.
 */
if (interface_exists('ExchangeInterface', false)) {
    final class BookFailExchange implements ExchangeInterface
    {
        /** @var ExchangeInterface */
        private $inner;
        /** @var string */
        private $symbol;
        /** @var int bookTicker() calls that threw */
        public $failures = 0;

        public function __construct(ExchangeInterface $inner, string $symbol)
        {
            $this->inner  = $inner;
            $this->symbol = strtoupper($symbol);
        }

        public function bookTicker(string $symbol): array
        {
            if (strtoupper($symbol) === $this->symbol) {
                $this->failures++;
                throw new BinanceException('Internal error; unable to process your request. (simulated)', -1000, 500);
            }
            return $this->inner->bookTicker($symbol);
        }

        public function mode(): string { return $this->inner->mode(); }
        public function account(): array { return $this->inner->account(); }
        public function klines(string $symbol, string $interval, int $limit): array { return $this->inner->klines($symbol, $interval, $limit); }
        public function prices(array $symbols): array { return $this->inner->prices($symbols); }
        public function symbolInfo(array $symbols): array { return $this->inner->symbolInfo($symbols); }
        public function syncTime(): int { return $this->inner->syncTime(); }
        public function serverTimeMs(): int { return $this->inner->serverTimeMs(); }
        public function ticker24h(array $symbols = []): array { return $this->inner->ticker24h($symbols); }
        public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array { return $this->inner->marketBuy($symbol, $quoteUsdt, $info, $clientId); }
        public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array { return $this->inner->marketSell($symbol, $qtyStr, $info, $clientId); }
        public function getOrder(string $symbol, string $clientId): ?array { return $this->inner->getOrder($symbol, $clientId); }
        public function limitOrder(string $symbol, string $side, string $qtyStr, string $priceStr, array $info, string $clientId, bool $postOnly): array { return $this->inner->limitOrder($symbol, $side, $qtyStr, $priceStr, $info, $clientId, $postOnly); }
        public function cancelOrder(string $symbol, string $clientId): array { return $this->inner->cancelOrder($symbol, $clientId); }
        public function cancelAllOrders(string $symbol): array { return $this->inner->cancelAllOrders($symbol); }
        public function openOrders(string $symbol): array { return $this->inner->openOrders($symbol); }
    }
}

/* ============================================================ 1. Util */

T::group('util', ['Util'], static function (): void {
    // floorToStep
    T::eq('1.234', Util::floorToStep('1.2345', '0.00100000'), 'floorToStep string qty, 8-digit step');
    T::eq('1.234', Util::floorToStep(1.2345, '0.001'), 'floorToStep float qty');
    T::eq('0.043', Util::floorToStep('0.043290123', '0.00100000'), 'floorToStep typical SOL net qty');
    T::eq('123.45678', Util::floorToStep('123.456789', '0.00001'), 'floorToStep 0.00001 step');
    T::eq('0.00005', Util::floorToStep(0.00005, '0.00001'), 'floorToStep tiny float has no exponent');
    T::eq('0.00001', Util::floorToStep(1.0E-5, '0.00001'), 'floorToStep 1.0E-5 float');
    T::eq('5', Util::floorToStep('5.9', '1'), 'floorToStep step 1');
    T::eq('5', Util::floorToStep('5.9', '1.00000000'), 'floorToStep step 1.00000000');
    T::eq('7', Util::floorToStep('7', '0.1'), 'floorToStep integer qty, fractional step');
    T::eq('0', Util::floorToStep('0.0009', '0.001'), 'floorToStep below one step is 0');
    T::eq('0', Util::floorToStep(0, '0.001'), 'floorToStep zero');
    T::eq('0', Util::floorToStep(-1.5, '0.001'), 'floorToStep negative is 0');
    T::eq('12345678.9', Util::floorToStep('12345678.9123', '0.1'), 'floorToStep large qty');
    T::eq('2.5', Util::floorToStep('2.5000000001', '0.5'), 'floorToStep 0.5 step');
    T::ok(strpos(Util::floorToStep(0.00000123, '0.00000001'), 'E') === false, 'floorToStep 1.23E-6 has no exponent');
    T::eq('0.00000123', Util::floorToStep(0.00000123, '0.00000001'), 'floorToStep 8-decimal step exact');

    // bcmath vs integer fallback: both public, both must agree
    $cases = [['1.2345', '0.001'], ['0.05002696', '0.001'], ['123.456789', '0.00001'], ['5.9', '1'], ['0.00005', '0.00001'], ['999999.123456789', '0.000001'], ['3', '0.7']];
    foreach ($cases as $c) {
        $int = Util::floorToStepInteger($c[0], $c[1]);
        $expected = Util::floorToStep($c[0], $c[1]);
        T::eq($expected, $int, 'integer fallback ' . $c[0] . ' step ' . $c[1]);
        if (extension_loaded('bcmath')) {
            T::eq($int, Util::floorToStepBcmath($c[0], $c[1]), 'bcmath agrees ' . $c[0] . ' step ' . $c[1]);
        }
    }
    if (!extension_loaded('bcmath')) {
        T::ok(true, 'bcmath not loaded here: integer fallback is the live path (bcmath comparison skipped)');
    }

    // decimalsOf
    T::eq(3, Util::decimalsOf('0.00100000'), 'decimalsOf 0.00100000');
    T::eq(0, Util::decimalsOf('1.00000000'), 'decimalsOf 1.00000000');
    T::eq(1, Util::decimalsOf('0.5'), 'decimalsOf 0.5');
    T::eq(5, Util::decimalsOf('0.00001000'), 'decimalsOf 0.00001000');
    T::eq(5, Util::decimalsOf('1E-5'), 'decimalsOf 1E-5');
    T::eq(8, Util::decimalsOf('0.00000001'), 'decimalsOf 0.00000001');

    // fmtQty / fmtQuote
    T::eq('0.00005', Util::fmtQty(0.00005, 5), 'fmtQty 0.00005');
    T::eq('0.00005', Util::fmtQty(5.0E-5, 8), 'fmtQty 5.0E-5 never exponent');
    T::eq('1.5', Util::fmtQty(1.5, 3), 'fmtQty trims zeros');
    T::eq('2', Util::fmtQty(2.0, 0), 'fmtQty 0 decimals');
    T::eq('0.043', Util::fmtQty(0.043, 3), 'fmtQty 0.043');
    T::eq('1234.5', Util::fmtQty(1234.5, 8), 'fmtQty large');
    T::eq('0', Util::fmtQty(0.0, 3), 'fmtQty zero');
    T::eq('6.50', Util::fmtQuote(6.5), 'fmtQuote 6.5');
    T::eq('6.99', Util::fmtQuote(6.999), 'fmtQuote floors, never rounds up');
    T::eq('0.00', Util::fmtQuote(0.0), 'fmtQuote zero');
    T::eq('10.00', Util::fmtQuote(10.0), 'fmtQuote 10');

    // misc
    T::eq('b-SOLUSDT-1788425760', Util::clientOrderId('BUY', 'SOLUSDT', 1788425760), 'clientOrderId buy');
    T::eq('s-SOLUSDT-1788425760', Util::clientOrderId('SELL', 'SOLUSDT', 1788425760), 'clientOrderId sell');
    T::ok(strlen(Util::clientOrderId('BUY', 'VERYLONGSYMBOLNAMEUSDT', 1788425760)) <= 36, 'clientOrderId <= 36 chars');
    T::eq(5.0, Util::clamp(7.0, 1.0, 5.0), 'clamp high');
    T::eq(1.0, Util::clamp(-7.0, 1.0, 5.0), 'clamp low');
    T::eq(3.0, Util::clamp(3.0, 1.0, 5.0), 'clamp inside');
    T::eq('2026-09-03T09:27:00Z', Util::isoAddMinutes('2026-09-03T08:42:00Z', 45), 'isoAddMinutes');
    T::eq(45.0, Util::isoDiffMinutes('2026-09-03T08:42:00Z', '2026-09-03T09:27:00Z'), 'isoDiffMinutes');
    T::ok(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', Util::nowIso()) === 1, 'nowIso format');
    T::eq('2026-09-03T08:42:00Z', Util::nowIso(1788424920), 'nowIso fixed ts');
    T::eq(2, strlen(Util::randomHex(1)), 'randomHex length');
    T::eq('9.9935', Util::money(9.9935), 'money 4 decimals');
});

/* ============================================================ 2. Indicators */

T::group('indicators', ['Indicators', 'FakeMarketData'], static function (): void {
    $sma = Indicators::sma([1, 2, 3, 4, 5], 3);
    T::eq([null, null, 2.0, 3.0, 4.0], $sma, 'sma aligned with nulls');

    $const = array_fill(0, 40, 100.0);
    $ema = Indicators::ema($const, 10);
    T::eq(null, $ema[8], 'ema null before warm-up');
    T::eq(100.0, $ema[9], 'ema seeded with SMA');
    T::eq(100.0, $ema[39], 'ema of constant series is constant');
    $ramp = range(1, 60);
    $e = Indicators::ema($ramp, 10);
    T::ok($e[59] < 60.0 && $e[59] > 50.0, 'ema lags a ramp', (string) $e[59]);
    T::ok(abs($e[9] - 5.5) < 1e-9, 'ema first value equals SMA(10) of 1..10');

    $up = range(1, 30);
    $rsi = Indicators::rsi($up, 14);
    T::eq(null, $rsi[13], 'rsi null before index n');
    T::near(100.0, (float) $rsi[29], 1e-9, 'rsi of monotonic rise is 100');
    $down = array_reverse($up);
    T::near(0.0, (float) Indicators::last(Indicators::rsi($down, 14)), 1e-9, 'rsi of monotonic fall is 0');
    T::near(50.0, (float) Indicators::last(Indicators::rsi(array_fill(0, 30, 5.0), 14)), 1e-9, 'rsi of a flat series is 50');
    T::eq(30, count($rsi), 'rsi output aligned to input length');

    $h = array_fill(0, 30, 11.0);
    $l = array_fill(0, 30, 10.0);
    $c = array_fill(0, 30, 10.5);
    $atr = Indicators::atr($h, $l, $c, 14);
    T::eq(null, $atr[13], 'atr null before index n');
    T::near(1.0, (float) $atr[29], 1e-9, 'atr of constant 1.0 range');
    T::near(1.0, (float) $atr[14], 1e-9, 'atr first value');

    $bb = Indicators::bollinger($const, 20, 2.0);
    T::near(100.0, (float) $bb['upper'][39], 1e-9, 'bollinger constant upper=mid');
    T::near(100.0, (float) $bb['lower'][39], 1e-9, 'bollinger constant lower=mid');
    $series = [2.0, 4.0, 4.0, 4.0, 5.0, 5.0, 7.0, 9.0];
    $sd = Indicators::stddev($series, 8);
    T::near(2.0, (float) $sd[7], 1e-9, 'stddev population');
    $bb2 = Indicators::bollinger($series, 8, 2.0);
    T::near(5.0, (float) $bb2['mid'][7], 1e-9, 'bollinger mid');
    T::near(9.0, (float) $bb2['upper'][7], 1e-9, 'bollinger upper = mid + 2σ');
    T::near(1.0, (float) $bb2['lower'][7], 1e-9, 'bollinger lower = mid − 2σ');

    T::eq(3, Indicators::last([1, 2, 3]), 'last');
    T::eq(2, Indicators::last([1, 2, 3], 1), 'last back=1');
    T::eq(null, Indicators::last([], 0), 'last of empty');
    T::eq(null, Indicators::last([1], 5), 'last out of range');

    $rows = [[1, 1.0, 1.0, 1.0, 1.0, 1.0, 1000], [2, 1.0, 1.0, 1.0, 1.0, 1.0, 2000]];
    T::eq(1, count(Indicators::closed($rows, 1500)), 'closed drops the forming candle');
    T::eq(2, count(Indicators::closed($rows, 2000)), 'closed keeps a candle whose closeTime == server time');
    T::eq([], Indicators::closed([], 1), 'closed of empty');

    // fixture ties
    $os = closedRows('klines_15m_oversold');
    T::eq(329, count($os), 'oversold fixture: 329 closed rows at the fixed clock');
    T::eq(330, count(fixture('klines_15m_oversold')), 'oversold fixture: 330 rows in total');
    $r = (float) Indicators::last(Indicators::rsi(col($os, 4), 14));
    T::ok($r >= 20.0 && $r <= 30.0, 'oversold fixture RSI14 ≈ 25', (string) $r);
    $ob = closedRows('klines_15m_overbought');
    $r2 = (float) Indicators::last(Indicators::rsi(col($ob, 4), 14));
    T::ok($r2 >= 72.0 && $r2 <= 84.0, 'overbought fixture RSI14 ≈ 78', (string) $r2);
    $bbOb = Indicators::bollinger(col($ob, 4), 20, 2.0);
    T::ok(Indicators::last(col($ob, 4)) >= Indicators::last($bbOb['upper']), 'overbought fixture closes above the upper band');
    $bbOs = Indicators::bollinger(col($os, 4), 20, 2.0);
    T::ok(Indicators::last(col($os, 4)) <= Indicators::last($bbOs['lower']), 'oversold fixture closes below the lower band');
    $up1h = closedRows('klines_1h_uptrend');
    T::eq(269, count($up1h), 'uptrend fixture: 269 closed rows');
    $cl = col($up1h, 4);
    T::ok(Indicators::last(Indicators::ema($cl, 50)) > Indicators::last(Indicators::ema($cl, 200)), 'uptrend fixture EMA50 > EMA200');
    $atr1h = (float) Indicators::last(Indicators::atr(col($up1h, 2), col($up1h, 3), $cl, 14)) / (float) Indicators::last($cl) * 100.0;
    T::ok($atr1h >= 0.5 && $atr1h <= 1.6, 'uptrend fixture ATR ≈ 1%', (string) $atr1h);
    $dn1h = closedRows('klines_1h_downtrend');
    $cd = col($dn1h, 4);
    T::ok(Indicators::last(Indicators::ema($cd, 50)) < Indicators::last(Indicators::ema($cd, 200)), 'downtrend fixture EMA50 < EMA200');
    foreach (['klines_15m_oversold', 'klines_15m_overbought', 'klines_1h_uptrend', 'klines_1h_downtrend'] as $f) {
        $all = fixture($f);
        $last = $all[count($all) - 1];
        T::ok($last[6] > FakeMarketData::SERVER_TIME_MS && $last[0] <= FakeMarketData::SERVER_TIME_MS, $f . ' last row is the forming candle');
        $mono = true;
        for ($i = 1; $i < count($all); $i++) {
            if ($all[$i][0] <= $all[$i - 1][0] || $all[$i][2] < $all[$i][3] || $all[$i][2] < max($all[$i][1], $all[$i][4]) || $all[$i][3] > min($all[$i][1], $all[$i][4])) {
                $mono = false;
                break;
            }
        }
        T::ok($mono, $f . ' rows are chronological with sane OHLC');
    }
});

/* ============================================================ 3. Strategy */

T::group('strategy', ['Strategy', 'Indicators', 'FakeMarketData'], static function (): void {
    $c15os = closedRows('klines_15m_oversold');
    $c15ob = closedRows('klines_15m_overbought');
    $c1hUp = closedRows('klines_1h_uptrend');
    $c1hDn = closedRows('klines_1h_downtrend');
    $cfg = cfg();

    $s = Strategy::evaluate($c15os, $c1hUp, $cfg, ['bid' => 128.90, 'ask' => 128.93]);
    T::ok($s['eligible'] === true, 'oversold+uptrend eligible', json_encode($s['gates']));
    T::ok($s['score'] >= 60, 'oversold+uptrend score >= 60', (string) $s['score']);
    T::eq([], $s['gates'], 'oversold+uptrend no gates');
    foreach (['rsi<=30', 'rsi<=25', 'bb_lower', 'reversal_candle', 'vol_high'] as $tag) {
        T::contains($s['reasons'], $tag, 'oversold reasons include ' . $tag);
    }
    T::ok($s['rsi'] >= 20.0 && $s['rsi'] <= 30.0, 'oversold rsi ≈ 25', (string) $s['rsi']);
    T::ok($s['atr_pct'] >= 0.30 && $s['atr_pct'] <= 1.5, 'oversold atr_pct within gate', (string) $s['atr_pct']);
    T::ok($s['atr1h_pct'] >= 0.5 && $s['atr1h_pct'] <= 3.0, 'oversold atr1h_pct within gate', (string) $s['atr1h_pct']);
    T::ok($s['tp_pct'] >= 1.0 && $s['tp_pct'] <= 2.0, 'tp_pct clamped to [tp, tp_max]', (string) $s['tp_pct']);
    T::near(1.5 * $s['atr_pct'] < 1.0 ? 1.0 : min(2.0, 1.5 * $s['atr_pct']), $s['tp_pct'], 1e-9, 'tp_pct = clamp(1.5×ATR%)');
    T::near((float) $c15os[count($c15os) - 1][4], $s['price'], 1e-9, 'price = last closed close');

    $s2 = Strategy::evaluate($c15ob, $c1hUp, $cfg, null);
    T::ok($s2['score'] <= 20, 'overbought score <= 20', (string) $s2['score']);
    T::contains($s2['reasons'], 'overbought', 'overbought reason tag');
    T::ok($s2['rsi'] >= 70.0, 'overbought rsi >= 70', (string) $s2['rsi']);

    $s3 = Strategy::evaluate($c15os, $c1hDn, $cfg, null);
    T::eq(false, $s3['eligible'], 'downtrend 1h makes the signal ineligible');
    T::contains($s3['gates'], 'trend_down', 'downtrend gate trend_down');
    T::ok($s3['score'] >= 60, 'score is still computed behind a gate', (string) $s3['score']);

    $s4 = Strategy::evaluate($c15os, $c1hUp, $cfg, ['bid' => 100.0, 'ask' => 100.2]);
    T::contains($s4['gates'], 'spread_wide', 'spread_wide gate');
    T::eq(false, $s4['eligible'], 'spread_wide blocks eligibility');

    $s5 = Strategy::evaluate(array_slice($c15os, -30), $c1hUp, $cfg, null);
    T::contains($s5['gates'], 'data_short', 'data_short gate for 30 candles');
    $s6 = Strategy::evaluate($c15os, array_slice($c1hUp, -100), $cfg, null);
    T::contains($s6['gates'], 'data_short', 'data_short gate for short 1h series');
    $s7 = Strategy::evaluate([], [], $cfg, null);
    T::eq(false, $s7['eligible'], 'empty input never eligible');

    // crash guard: last four 1h candles each close 1% lower
    $crash = $c1hUp;
    $n = count($crash);
    for ($i = $n - 4; $i < $n; $i++) {
        $prev = (float) $crash[$i - 1][4];
        $crash[$i][4] = $prev * 0.99;
        $crash[$i][3] = min((float) $crash[$i][3], $crash[$i][4]);
    }
    $s8 = Strategy::evaluate($c15os, $crash, $cfg, null);
    T::contains($s8['gates'], 'crash_guard', 'crash_guard gate');

    // ATR gates via config
    $s9 = Strategy::evaluate($c15os, $c1hUp, cfg(['atr_min_pct' => 5.0, 'atr_max_pct' => 10.0]), null);
    T::contains($s9['gates'], 'atr_low', 'atr_low gate');
    $s10 = Strategy::evaluate($c15os, $c1hUp, cfg(['atr_min_pct' => 0.01, 'atr_max_pct' => 0.05]), null);
    T::contains($s10['gates'], 'atr_high', 'atr_high gate');

    // exitSignal
    $pos = ['entry_eff' => 129.0];
    T::eq('rsi_overbought', Strategy::exitSignal($c15ob, $pos, 131.0, $cfg), 'exitSignal rsi_overbought on a profitable position');
    T::eq('', Strategy::exitSignal($c15ob, ['entry_eff' => 132.0], 131.0, $cfg), 'exitSignal never on a losing position');
    T::eq('', Strategy::exitSignal($c15ob, ['entry_eff' => 130.9], 131.0, $cfg), 'exitSignal needs >= 0.3% gain');
    T::eq('', Strategy::exitSignal($c15os, ['entry_eff' => 120.0], 128.9, $cfg), 'exitSignal empty when not overbought');
    $bb = Indicators::bollinger(col($c15os, 4), 20, 2.0);
    $upper = (float) Indicators::last($bb['upper']);
    T::eq('bb_upper', Strategy::exitSignal($c15os, ['entry_eff' => $upper * 0.98], $upper + 0.01, $cfg), 'exitSignal bb_upper when bid >= upper band');
});

/* ============================================================ 4. Risk */

T::group('risk', ['Risk', 'Db', 'Util'], static function (): void {
    $cfg = cfg(['enabled' => true]);

    // floor / drawdown
    $db = freshDb('risk-floor');
    T::eq(true, Risk::floorBreached($cfg, $db, 6.9), 'floorBreached below floor');
    T::eq(true, Risk::floorBreached($cfg, $db, 7.0), 'floorBreached at floor (<=)');
    T::eq(false, Risk::floorBreached($cfg, $db, 7.5), 'floorBreached above floor, no hwm');
    $db->setState('equity_hwm', 10.0);
    T::eq(true, Risk::floorBreached($cfg, $db, 7.9), 'floorBreached hwm drawdown 20%');
    T::eq(false, Risk::floorBreached($cfg, $db, 8.1), 'floorBreached inside drawdown');

    // survivalCheck bookkeeping + kill switch
    $db = freshDb('risk-survival');
    $r = Risk::survivalCheck($cfg, $db, 10.0, false, false);
    T::eq('none', $r['action'], 'survivalCheck none at start');
    T::eq('10', $db->getState('equity_hwm'), 'survivalCheck sets equity_hwm');
    T::eq('10', $db->getState('day_start_equity'), 'survivalCheck sets day_start_equity');
    T::eq(Util::todayUtc(), $db->getState('day_start_date'), 'survivalCheck sets day_start_date');
    Risk::survivalCheck($cfg, $db, 10.5, false, false);
    T::eq('10.5', $db->getState('equity_hwm'), 'hwm rises');
    Risk::survivalCheck($cfg, $db, 9.0, false, false);
    T::eq('10.5', $db->getState('equity_hwm'), 'hwm never falls');
    $r = Risk::survivalCheck($cfg, $db, 6.5, true, true);
    T::eq('halt', $r['action'], 'survivalCheck halts below floor');
    T::eq('equity_floor', $r['reason'], 'halt reason equity_floor');
    T::eq('1', $db->getState('halted'), 'halted state set');
    $r = Risk::survivalCheck($cfg, $db, 6.5, true, true);
    T::eq('halt', $r['action'], 'still halt while a position is open');
    $r = Risk::survivalCheck($cfg, $db, 12.0, false, false);
    T::eq('no_entry', $r['action'], 'halted without position => no_entry');
    T::eq('halted', $r['reason'], 'halted reason');
    T::eq('halted', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason halted');

    $db = freshDb('risk-drawdown');
    Risk::survivalCheck($cfg, $db, 10.0, false, false);
    $r = Risk::survivalCheck($cfg, $db, 7.5, false, false);
    T::eq('halt', $r['action'], 'hwm drawdown halts');
    T::eq('drawdown', $r['reason'], 'halt reason drawdown');

    // daily cap
    $db = freshDb('risk-daily');
    Risk::survivalCheck($cfg, $db, 10.0, false, false);
    closedPosition($db, -0.25, ['closed_at' => Util::nowIso()]);
    $r = Risk::survivalCheck($cfg, $db, 9.75, false, false);
    T::eq('no_entry', $r['action'], 'daily cap => no_entry');
    T::eq('daily_cap', $r['reason'], 'daily cap reason');
    T::eq('daily_cap', Risk::entryBlockReason($cfg, $db, 9.75, 9.75), 'entryBlockReason daily_cap');
    $db = freshDb('risk-daily-ok');
    Risk::survivalCheck($cfg, $db, 10.0, false, false);
    closedPosition($db, -0.1, ['closed_at' => Util::nowIso()]);
    T::eq('none', Risk::survivalCheck($cfg, $db, 9.9, false, false)['action'], 'a 1% loss does not hit the 2% daily cap');

    // weekly cap
    $db = freshDb('risk-weekly');
    Risk::survivalCheck($cfg, $db, 10.0, false, false);
    closedPosition($db, -0.6, ['closed_at' => isoAgo(3 * 86400), 'opened_at' => isoAgo(3 * 86400 + 600)]);
    $r = Risk::survivalCheck($cfg, $db, 9.4, false, false);
    T::eq('weekly_cap', $r['reason'], 'weekly cap reason');
    T::eq('weekly_cap', $db->getState('pause_reason'), 'weekly cap sets pause_reason');
    $until = Util::isoToTs((string) $db->getState('paused_until'));
    T::ok($until !== null && $until > time() + 6 * 86400, 'weekly cap pauses for 7 days');
    T::ok(strpos(Risk::entryBlockReason($cfg, $db, 9.4, 9.4), 'paused:') === 0 || Risk::entryBlockReason($cfg, $db, 9.4, 9.4) === 'weekly_cap', 'entryBlockReason paused/weekly_cap');

    // entry block basics
    $db = freshDb('risk-block');
    T::eq('disabled', Risk::entryBlockReason(cfg(['enabled' => false]), $db, 10.0, 10.0), 'entryBlockReason disabled');
    T::eq('', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason empty when all clear');
    $db->setState('paused_until', isoAgo(-3600));
    $db->setState('pause_reason', 'manual');
    T::eq('paused:manual', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason paused:manual');
    $db->setState('paused_until', '');
    $db->setState('api_paused_until', isoAgo(-600));
    T::eq('api_paused', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason api_paused');
    $db->setState('api_paused_until', '');
    T::eq('insufficient_quote', Risk::entryBlockReason($cfg, $db, 1.2, 1.2), 'entryBlockReason insufficient_quote');
    T::eq('', Risk::entryBlockReason($cfg, $db, 6.0, 6.0), 'entryBlockReason ok with 6 USDT');

    // cooldown ladder
    $db = freshDb('risk-cooldown');
    $now = Util::nowIso();
    Risk::recordOutcome($cfg, $db, -0.05, $now);
    T::eq('1', $db->getState('consecutive_losses'), 'one loss counted');
    T::eq($now, $db->getState('last_loss_at'), 'last_loss_at');
    T::eq(Util::isoAddMinutes($now, 45), $db->getState('cooldown_until'), 'cooldown 45 min after 1 loss');
    T::eq('cooldown', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason cooldown');
    Risk::recordOutcome($cfg, $db, -0.05, $now);
    T::eq('2', $db->getState('consecutive_losses'), 'two losses counted');
    T::eq(Util::isoAddMinutes($now, 180), $db->getState('cooldown_until'), 'cooldown 180 min after 2 losses');
    Risk::recordOutcome($cfg, $db, -0.05, $now);
    T::eq('3', $db->getState('consecutive_losses'), 'three losses counted');
    T::eq(substr(Util::isoAddMinutes(substr($now, 0, 10) . 'T00:00:00Z', 1440), 0, 20), $db->getState('cooldown_until'), 'cooldown until next UTC day after max losses');
    $db->setState('cooldown_until', '');
    T::eq('consecutive_losses', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason consecutive_losses');
    Risk::recordOutcome($cfg, $db, 0.08, $now);
    T::eq('0', $db->getState('consecutive_losses'), 'a win resets the counter');
    Risk::recordOutcome($cfg, $db, 0.0, $now);
    T::eq('0', $db->getState('consecutive_losses'), 'flat outcome leaves the counter');

    // max trades / orders
    $db = freshDb('risk-maxtrades');
    for ($i = 0; $i < 3; $i++) {
        closedPosition($db, 0.01, ['opened_at' => Util::nowIso(), 'closed_at' => Util::nowIso()]);
    }
    T::eq('max_trades', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason max_trades');
    T::eq(3, Risk::effectiveMaxTrades($cfg, $db), 'effectiveMaxTrades default');
    $db->setState('effective_max_trades', '1');
    T::eq(1, Risk::effectiveMaxTrades($cfg, $db), 'effectiveMaxTrades adaptive state');
    $db->setState('effective_max_trades', '9');
    T::eq(4, Risk::effectiveMaxTrades($cfg, $db), 'effectiveMaxTrades capped at 4');
    T::eq(3, Risk::effectiveMaxTrades(cfg(['adaptive' => false]), $db), 'effectiveMaxTrades ignores state when adaptive off');
    $db = freshDb('risk-maxorders');
    foreach (['a', 'b'] as $k) {
        $db->insertOrder(['client_id' => 'b-SOLUSDT-' . $k, 'mode' => 'paper', 'symbol' => 'SOLUSDT', 'side' => 'BUY', 'status' => 'DONE', 'created_at' => Util::nowIso()]);
    }
    T::eq('max_orders_hour', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entryBlockReason max_orders_hour');

    // sizing
    $sol = FakeMarketData::infoRow('SOLUSDT');
    $btc = FakeMarketData::infoRow('BTCUSDT');
    $doge = FakeMarketData::infoRow('DOGEUSDT');
    T::near((5.0 * 1.15 + 0.001 * 130.0) / 0.999, Risk::requiredSize($sol, 130.0, 0.1), 1e-9, 'requiredSize SOL @130');
    T::near((5.0 * 1.15 + 0.00001 * 110000.0) / 0.999, Risk::requiredSize($btc, 110000.0, 0.1), 1e-9, 'requiredSize BTC @110k');
    T::ok(Risk::requiredSize($btc, 110000.0, 0.1) > 6.5, 'BTCUSDT needs more than 6.5 USDT at 110k (unaffordable on 10 USDT)');
    T::near((1.0 * 1.15 + 1.0 * 0.2) / 0.999, Risk::requiredSize($doge, 0.2, 0.1), 1e-9, 'requiredSize DOGE @0.2');
    T::eq(6.5, Risk::entrySize($cfg, $sol, 130.0, 10.0, 0.1), 'entrySize trade_usdt with 10 USDT');
    T::eq(6.5, Risk::entrySize($cfg, $sol, 130.0, 100.0, 0.1), 'entrySize never above trade_usdt');
    T::eq(0.0, Risk::entrySize($cfg, $sol, 130.0, 8.0, 0.1), 'entrySize 0 when 65% of balance < required size');
    T::near(5.98, Risk::entrySize($cfg, $sol, 130.0, 9.2, 0.1), 1e-9, 'entrySize 65% of balance, floored to cents');
    T::eq(0.0, Risk::entrySize($cfg, $btc, 110000.0, 10.0, 0.1), 'entrySize 0 for BTCUSDT on 10 USDT');
    T::eq(0.0, Risk::entrySize($cfg, $doge, 0.2, 1.2, 0.1), 'entrySize 0 for a tiny balance');
    T::eq(0.0, Risk::entrySize($cfg, $sol, 130.0, 0.0, 0.1), 'entrySize 0 with no balance');
    T::near(1.95, Risk::entrySize(cfg(['trade_usdt' => 2.0]), $doge, 0.2, 3.0, 0.1), 1e-9, 'entrySize DOGE small trade');

    // exitDecision
    $now = Util::nowIso();
    $p = ['entry_eff' => 100.0, 'stop_price' => 99.3, 'take_profit_price' => 101.0, 'trail_high' => 100.0, 'trailing_armed' => 0, 'opened_at' => $now];
    T::eq('stop_loss', Risk::exitDecision($p, 99.3, $cfg, $now)['reason'], 'exitDecision stop_loss at stop');
    T::eq('stop_loss', Risk::exitDecision($p, 99.0, $cfg, $now)['reason'], 'exitDecision stop_loss below stop');
    $d = Risk::exitDecision($p, 100.5, $cfg, $now);
    T::eq('', $d['reason'], 'exitDecision hold at +0.5%');
    T::eq(100.5, $d['trail_high'], 'trail_high follows bid');
    T::eq(0, $d['trailing_armed'], 'not armed below activate');
    $d = Risk::exitDecision($p, 100.7, $cfg, $now);
    T::eq('', $d['reason'], 'exitDecision hold at +0.7%');
    T::eq(1, $d['trailing_armed'], 'armed at +0.7%');
    T::near(max(100.7 * 0.996, 100.25), $d['stop_price'], 1e-9, 'trailing stop = max(high×(1−d), floor)');
    $p2 = array_merge($p, ['trail_high' => 100.7, 'trailing_armed' => 1, 'stop_price' => $d['stop_price']]);
    T::eq('trailing_stop', Risk::exitDecision($p2, 100.2, $cfg, $now)['reason'], 'exitDecision trailing_stop');
    T::eq('take_profit', Risk::exitDecision($p, 101.0, $cfg, $now)['reason'], 'exitDecision take_profit');
    T::eq('take_profit', Risk::exitDecision($p, 101.5, $cfg, $now)['reason'], 'exitDecision take_profit above');
    $old = array_merge($p, ['opened_at' => Util::isoAddMinutes($now, -241)]);
    T::eq('max_hold', Risk::exitDecision($old, 100.1, $cfg, $now)['reason'], 'exitDecision max_hold');
    $young = array_merge($p, ['opened_at' => Util::isoAddMinutes($now, -10)]);
    T::eq('', Risk::exitDecision($young, 100.1, $cfg, $now)['reason'], 'exitDecision hold when young');
    T::eq('stop_loss', Risk::exitDecision(['entry_eff' => 100.0, 'opened_at' => $now], 99.2, $cfg, $now)['reason'], 'exitDecision derives stop from entry_eff when missing');
    T::eq('', Risk::exitDecision(['entry_eff' => 0.0], 99.2, $cfg, $now)['reason'], 'exitDecision no entry => no decision');

    // adaptive steps
    $db = freshDb('risk-adaptive');
    T::eq(60, Risk::effectiveThreshold($cfg, $db), 'effectiveThreshold default');
    for ($i = 0; $i < 20; $i++) {
        closedPosition($db, -0.05, ['closed_at' => isoAgo(7200 - $i * 60), 'opened_at' => isoAgo(7300 - $i * 60)]);
    }
    Risk::recordOutcome($cfg, $db, -0.05, Util::nowIso());
    T::eq('80', $db->getState('effective_threshold'), 'adaptive raises threshold by 20 after a losing window');
    T::eq('2', $db->getState('effective_max_trades'), 'adaptive lowers max trades by 1');
    T::eq(Util::todayUtc(), $db->getState('last_adapt_date'), 'last_adapt_date today');
    T::eq(80, Risk::effectiveThreshold($cfg, $db), 'effectiveThreshold reads adaptive state');
    Risk::recordOutcome($cfg, $db, -0.05, Util::nowIso());
    T::eq('80', $db->getState('effective_threshold'), 'at most one adaptive step per day');
    $db->setState('last_adapt_date', '2000-01-01');
    for ($i = 0; $i < 20; $i++) {
        closedPosition($db, 0.07, ['closed_at' => isoAgo(1200 - $i * 30), 'opened_at' => isoAgo(1300 - $i * 30)]);
    }
    Risk::recordOutcome($cfg, $db, 0.07, Util::nowIso());
    T::eq('60', $db->getState('effective_threshold'), 'adaptive lowers threshold back after a winning window');
    T::eq('3', $db->getState('effective_max_trades'), 'adaptive raises max trades back');
    $db->setState('effective_threshold', '100');
    T::eq(100, Risk::effectiveThreshold($cfg, $db), 'effectiveThreshold clamps to 100');
    $db->setState('effective_threshold', '10');
    T::eq(60, Risk::effectiveThreshold($cfg, $db), 'effectiveThreshold never below entry_threshold');
    T::eq(60, Risk::effectiveThreshold(cfg(['adaptive' => false]), $db), 'effectiveThreshold ignores state when adaptive off');
    Risk::recordOutcome(cfg(['adaptive' => false]), $db, -1.0, Util::nowIso());
    T::eq('10', $db->getState('effective_threshold'), 'adaptive off never touches the state');

    // adaptive stop: threshold pinned at 100 and still negative after 20 more trades
    $db = freshDb('risk-adaptive-stop');
    for ($i = 0; $i < 40; $i++) {
        closedPosition($db, -0.05, ['closed_at' => isoAgo(4000 - $i * 60), 'opened_at' => isoAgo(4100 - $i * 60)]);
    }
    $db->setState('effective_threshold', '100');
    $db->setState('adapt_max_since_closed', '20');
    Risk::recordOutcome($cfg, $db, -0.05, Util::nowIso());
    T::eq('adaptive_stop', $db->getState('pause_reason'), 'adaptive stop sets pause_reason');

    // validateConfig
    $cur = cfg();
    list($c, $errs) = Risk::validateConfig(['take_profit_pct' => 0.3], $cur);
    T::ok($errs !== [], 'validateConfig rejects TP below 3× round-trip fee');
    list($c, $errs) = Risk::validateConfig(['stop_loss_pct' => 0.2], $cur);
    T::ok($errs !== [], 'validateConfig rejects stop_loss 0.2 (exclusive)');
    list($c, $errs) = Risk::validateConfig(['stop_loss_pct' => 5], $cur);
    T::ok($errs !== [], 'validateConfig rejects stop_loss 5 (exclusive)');
    list($c, $errs) = Risk::validateConfig(['stop_loss_pct' => '0.9'], $cur);
    T::eq([], $errs, 'validateConfig accepts stop_loss 0.9');
    T::eq(0.9, $c['stop_loss_pct'], 'validateConfig casts stop_loss to float');
    list($c, $errs) = Risk::validateConfig(['equity_floor_usdt' => 0.5], $cur);
    T::ok($errs !== [], 'validateConfig rejects floor < 1');
    list($c, $errs) = Risk::validateConfig(['symbols' => 'solusdt, ethusdt'], $cur);
    T::eq([], $errs, 'validateConfig accepts a symbol list string');
    T::eq(['SOLUSDT', 'ETHUSDT'], $c['symbols'], 'validateConfig uppercases symbols');
    list($c, $errs) = Risk::validateConfig(['symbols' => ['ETHBTC']], $cur);
    T::ok($errs !== [], 'validateConfig rejects a symbol not ending in the quote asset');
    list($c, $errs) = Risk::validateConfig(['mode' => 'foo'], $cur);
    T::ok($errs !== [], 'validateConfig rejects unknown mode');
    list($c, $errs) = Risk::validateConfig(['mode' => 'live', 'max_trades_per_day' => '2'], $cur);
    T::eq([], $errs, 'validateConfig accepts live + int');
    T::eq('live', $c['mode'], 'validateConfig mode live');
    T::eq(2, $c['max_trades_per_day'], 'validateConfig int cast');
    list($c, $errs) = Risk::validateConfig(['api_secret' => ''], cfg(['api_secret' => 'KEEPME1234567890']));
    T::eq('KEEPME1234567890', $c['api_secret'], 'validateConfig blank secret keeps the current one');
});

/* ============================================================ 5. Db */

T::group('db', ['Db', 'Util'], static function (): void {
    $db = freshDb('db');
    T::eq(null, $db->getState('nope'), 'getState missing => default null');
    T::eq('x', $db->getState('nope', 'x'), 'getState missing => given default');
    $db->setState('b', true);
    T::eq('1', $db->getState('b'), 'setState bool true => 1');
    $db->setState('b', false);
    T::eq('0', $db->getState('b'), 'setState bool false => 0');
    $db->setState('f', 0.00005);
    T::eq('0.00005', $db->getState('f'), 'setState float has no exponent');
    $db->setState('a', ['USDT' => 10.0, 'SOL' => 0.05]);
    T::eq(['USDT' => 10.0, 'SOL' => 0.05], $db->getStateJson('a'), 'getStateJson round-trip');
    $db->setState('a', null);
    T::eq(null, $db->getState('a'), 'setState null deletes');
    T::eq('d', $db->getStateJson('a', 'd'), 'getStateJson default');

    $id = $db->insertPosition(['mode' => 'paper', 'symbol' => 'SOLUSDT', 'qty' => 0.05, 'entry_price' => 130.0, 'entry_eff' => 130.0, 'entry_quote' => 6.5, 'stop_price' => 129.0, 'take_profit_price' => 131.0]);
    $open = $db->openPosition();
    T::ok($open !== null && $open['id'] === $id, 'openPosition returns the OPEN row');
    T::eq('OPEN', $open['status'], 'insertPosition defaults status OPEN');
    T::eq(130.0, $open['trail_high'], 'insertPosition defaults trail_high to entry_eff');
    T::ok(is_float($open['qty']) && is_int($open['id']), 'rows are type-normalised');
    $db->updatePosition($id, ['status' => 'CLOSED', 'pnl_usdt' => 0.05, 'closed_at' => Util::nowIso()]);
    T::eq(null, $db->openPosition(), 'no OPEN row after close');
    T::eq(1, count($db->closedPositions()), 'closedPositions');
    T::near(0.05, $db->realisedPnl(isoAgo(60)), 1e-9, 'realisedPnl');
    T::eq(1, $db->entriesSince(isoAgo(60)), 'entriesSince');
    $st = $db->stats();
    T::eq(1, $st['wins'], 'stats wins');
    T::eq(100.0, $st['win_rate'], 'stats win_rate');
    T::near(0.05, $st['expectancy'], 1e-9, 'stats expectancy');

    $db->insertOrder(['client_id' => 'b-X-1', 'mode' => 'paper', 'symbol' => 'SOLUSDT', 'side' => 'BUY', 'status' => 'SENDING']);
    T::eq(1, count($db->pendingOrders()), 'pendingOrders includes SENDING');
    $db->updateOrder('b-X-1', ['status' => 'DONE']);
    T::eq(0, count($db->pendingOrders()), 'pendingOrders excludes DONE');
    T::eq(1, $db->ordersSince(isoAgo(60)), 'ordersSince');

    $db->insertSignal('solusdt', 42, false, 1.0, ['atr_low']);
    $db->insertSignal('SOLUSDT', 77, true, 1.1, ['bb_lower']);
    $latest = $db->latestSignals();
    T::eq(77, $latest['SOLUSDT']['score'], 'latestSignals newest per symbol');
    T::eq(['atr_low' => 1], $db->noTradeReasons(1), 'noTradeReasons counts ineligible tags');

    $db->insertEquity(10.0, 3.5, 6.4, 0.1);
    $db->insertEquity(10.1, 3.5, 6.5, 0.1);
    $series = $db->equitySeries(5);
    T::eq(2, count($series), 'equitySeries count');
    T::ok($series[0]['equity_usdt'] < $series[1]['equity_usdt'], 'equitySeries oldest first');

    $db->log('info', 'hello', ['k' => 'v']);
    T::eq('hello', $db->logs(1)[0]['message'], 'logs newest first');

    for ($i = 0; $i < 5; $i++) {
        $db->loginFailed('1.2.3.4', 5, 15);
    }
    T::eq(true, $db->loginAttempt('1.2.3.4')['locked'], 'login lockout after 5 failures');
    $db->loginOk('1.2.3.4');
    T::eq(false, $db->loginAttempt('1.2.3.4')['locked'], 'loginOk clears the lock');
});

/* ============================================================ 6. PaperExchange */

T::group('paper', ['PaperExchange', 'FakeMarketData', 'Db'], static function (): void {
    $db = freshDb('paper');
    $md = new FakeMarketData(['SOLUSDT' => ['15m' => 'klines_15m_oversold', '1h' => 'klines_1h_uptrend']]);
    $md->setPrice('SOLUSDT', 129.75, 129.80);
    $ex = new PaperExchange($md, $db, 0.1, 10.0);
    $info = FakeMarketData::infoRow('SOLUSDT');

    T::eq('paper', $ex->mode(), 'mode paper');
    $acct = $ex->account();
    T::eq(10.0, $acct['balances']['USDT']['free'], 'starts with paper_start_usdt');
    T::eq(0.1, $acct['taker_fee_pct'], 'account taker fee');
    T::eq(['USDT' => 10.0], $db->getStateJson('paper_balances'), 'balances persisted in state');

    $r = $ex->marketBuy('SOLUSDT', 6.5, $info, 'b-SOLUSDT-1');
    $gross = 6.5 / 129.80;
    $commission = $gross * 0.001;
    $net = $gross - $commission;
    T::eq(0.05, $r['qty'], 'buy qty floored to stepSize');
    T::near($net - 0.05, $r['dust_qty'], 1e-12, 'buy dust = net − qty');
    T::eq(129.80, $r['price'], 'buy fills at ask');
    T::eq(6.5, $r['quote'], 'buy quote spent');
    T::near($commission * 129.80, $r['fee_usdt'], 1e-12, 'buy fee_usdt = commission × ask');
    T::eq('SOL', $r['fee_asset'], 'buy fee in base asset');
    T::ok($r['order_id'] !== '' && is_array($r['raw']), 'buy returns order_id and raw');
    $bal = $ex->account()['balances'];
    T::near(3.5, $bal['USDT']['free'], 1e-9, 'USDT balance after buy');
    T::near($net, $bal['SOL']['free'], 1e-12, 'SOL balance = net (dust stays in wallet)');
    T::eq('DONE', $db->order('b-SOLUSDT-1')['status'], 'buy recorded DONE');
    $g = $ex->getOrder('SOLUSDT', 'b-SOLUSDT-1');
    T::ok($g !== null && abs($g['qty'] - 0.05) < 1e-12, 'getOrder returns the recorded fill');
    T::eq(null, $ex->getOrder('SOLUSDT', 'nope'), 'getOrder unknown => null');

    $md->setPrice('SOLUSDT', 131.50, 131.55);
    $s = $ex->marketSell('SOLUSDT', '0.05', $info, 's-SOLUSDT-1');
    T::eq(0.05, $s['qty'], 'sell qty');
    T::eq(131.50, $s['price'], 'sell fills at bid');
    T::near(0.05 * 131.50 * 0.001, $s['fee_usdt'], 1e-12, 'sell fee_usdt');
    T::near(0.05 * 131.50 * 0.999, $s['quote'], 1e-12, 'sell quote net of fee');
    T::eq('USDT', $s['fee_asset'], 'sell fee in quote');
    $bal = $ex->account()['balances'];
    T::near(3.5 + 0.05 * 131.50 * 0.999, $bal['USDT']['free'], 1e-9, 'USDT balance after sell');
    T::near($net - 0.05, $bal['SOL']['free'], 1e-12, 'dust remains after sell');
    T::eq('DONE', $db->order('s-SOLUSDT-1')['status'], 'sell recorded DONE');

    $caught = null;
    try {
        $ex->marketBuy('SOLUSDT', 50.0, $info, 'b-SOLUSDT-2');
    } catch (BinanceException $e) {
        $caught = $e;
    }
    T::ok($caught !== null && $caught->binanceCode === -2010, 'insufficient balance => -2010');
    $caught = null;
    try {
        $ex->marketSell('SOLUSDT', '1', $info, 's-SOLUSDT-2');
    } catch (BinanceException $e) {
        $caught = $e;
    }
    T::ok($caught !== null && $caught->binanceCode === -2010, 'oversell => -2010');

    $ex2 = new PaperExchange($md, $db, 0.1, 10.0);
    T::near($bal['USDT']['free'], $ex2->account()['balances']['USDT']['free'], 1e-9, 'a new instance reloads persisted balances');
    $ex2->reset();
    T::eq(['USDT' => 10.0], $db->getStateJson('paper_balances'), 'reset restores the start balance');
});

/* ============================================================ 7. Bot tick sequence */

function botCfg(array $over = []): array
{
    return cfg(array_merge([
        'enabled' => true, 'mode' => 'paper', 'symbols' => ['SOLUSDT', 'DOGEUSDT'],
        'trade_usdt' => 6.5, 'paper_start_usdt' => 10.0, 'fee_pct' => 0.1,
        'max_trades_per_day' => 10, 'max_orders_per_hour' => 20,   // caps count against the real clock; keep them out of the way
    ], $over));
}

function botMd(): FakeMarketData
{
    $md = new FakeMarketData([
        'SOLUSDT'  => ['15m' => 'klines_15m_oversold',   '1h' => 'klines_1h_uptrend'],
        'DOGEUSDT' => ['15m' => 'klines_15m_overbought', '1h' => 'klines_1h_uptrend'],
    ]);
    $md->setPrice('SOLUSDT', 129.75, 129.80);   // spread 0.039% < 0.05%; net qty 0.05003 SOL → 0.050 + tiny dust
    return $md;
}

T::group('bot', ['Bot', 'PaperExchange', 'FakeMarketData', 'Risk', 'Strategy'], static function (): void {
    $db  = freshDb('bot');
    $md  = botMd();
    $cfg = botCfg();
    $ex  = new PaperExchange($md, $db, 0.1, 10.0);
    $t0  = FakeMarketData::SERVER_TIME_MS;

    // (1) enters on the strong signal
    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'tick1 status ok', $r['summary']);
    T::strContains($r['summary'], 'entered:SOLUSDT', 'tick1 entered SOLUSDT');
    $pos = $db->openPosition();
    T::ok($pos !== null, 'tick1 opened a position');
    if ($pos !== null) {
        T::eq('SOLUSDT', $pos['symbol'], 'position symbol');
        T::eq(0.05, $pos['qty'], 'position qty floored to step');
        // 6.5 USDT at ask 129.80: gross 0.05007704 SOL, fee 0.1% in base, net 0.05002696,
        // sellable 0.050 and 0.00002696 SOL dust. The dust is recovered by a later SELL, so it is
        // NOT charged to the sellable qty: entry_eff = (6.5 − dust×129.80) / 0.05 = 6.4965 / 0.05.
        T::near(129.93, $pos['entry_eff'], 1e-9, 'entry_eff = (quote − dust × fill) / qty');
        T::eq(129.80, $pos['entry_price'], 'entry_price = ask');
        T::near(129.93 * 0.993, $pos['stop_price'], 1e-9, 'stop = entry_eff × (1 − sl)');
        T::near(129.93 * 1.01, $pos['take_profit_price'], 1e-9, 'tp = entry_eff × (1 + tp_pct)');
        T::near(129.93, $pos['trail_high'], 1e-9, 'trail_high = entry_eff');
        T::ok($pos['dust_qty'] > 0 && $pos['dust_qty'] < 0.001, 'dust recorded');
        T::ok($pos['score'] >= 60, 'score stored', (string) $pos['score']);
        T::eq(Util::nowIso(intdiv($t0, 1000)), $pos['opened_at'], 'opened_at uses the injected clock');
    }
    T::eq(1, countRows($db, 'SELECT COUNT(*) FROM orders'), 'tick1 one order');
    $o = $db->order(Util::clientOrderId('BUY', 'SOLUSDT', intdiv($t0, 60000) * 60));
    T::ok($o !== null && $o['status'] === 'DONE' && $o['side'] === 'BUY', 'tick1 deterministic client id, DONE');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM trades WHERE side = 'BUY'"), 'tick1 BUY trade recorded');
    T::near(3.5, $ex->account()['balances']['USDT']['free'], 1e-9, 'tick1 spent 6.5 USDT');
    $sig = $db->latestSignals();
    T::ok(isset($sig['SOLUSDT']) && $sig['SOLUSDT']['eligible'] === 1 && $sig['SOLUSDT']['score'] >= 60, 'tick1 SOL signal stored eligible');
    T::ok(isset($sig['DOGEUSDT']) && $sig['DOGEUSDT']['eligible'] === 0, 'tick1 DOGE signal stored ineligible');
    if (isset($sig['DOGEUSDT'])) {
        T::contains($sig['DOGEUSDT']['reasons_list'], 'size_unaffordable', 'DOGE at 130 with stepSize 1 is size_unaffordable');
    }
    T::eq('1788435900000', $db->getState('last_eval_candle_SOLUSDT'), 'last_eval_candle recorded');
    T::eq(1, countRows($db, 'SELECT COUNT(*) FROM equity'), 'equity sampled');
    T::eq('0', (string) $db->getState('halted', '0'), 'not halted');
    T::ok((string) $db->getState('symbol_info_at', '') !== '', 'symbol info cached');
    T::eq('entered:SOLUSDT', $db->getState('no_trade_reason'), 'no_trade_reason = entered');

    // (2) same candle: no second order, position kept
    $r = (new Bot($cfg, $db, $ex, $t0 + 60000))->tick();
    T::eq('ok', $r['status'], 'tick2 status ok', $r['summary']);
    T::eq(1, countRows($db, 'SELECT COUNT(*) FROM orders'), 'tick2 no second order');
    T::eq('position_open', $db->getState('no_trade_reason'), 'tick2 reason position_open');
    T::ok($db->openPosition() !== null, 'tick2 position still open');

    // (3) take-profit when the bid is raised
    $md->setPrice('SOLUSDT', 131.50, 131.55);
    $r = (new Bot($cfg, $db, $ex, $t0 + 120000))->tick();
    T::eq('ok', $r['status'], 'tick3 status ok', $r['summary']);
    T::eq(null, $db->openPosition(), 'tick3 position closed');
    $closed = $db->closedPositions(1);
    T::ok($closed !== [] && $closed[0]['exit_reason'] === 'take_profit', 'tick3 exit_reason take_profit', $closed !== [] ? (string) $closed[0]['exit_reason'] : 'none');
    if ($closed !== []) {
        T::ok($closed[0]['pnl_usdt'] > 0.0, 'tick3 pnl positive', (string) $closed[0]['pnl_usdt']);
        T::near(0.05 * 131.50 * 0.999 - 6.5, $closed[0]['pnl_usdt'], 1e-9, 'tick3 pnl = exit_quote − entry_quote');
        T::eq(131.50, $closed[0]['exit_price'], 'tick3 sold at bid');
    }
    T::eq(2, countRows($db, 'SELECT COUNT(*) FROM orders'), 'tick3 sell order');
    T::eq(2, countRows($db, "SELECT COUNT(*) FROM orders WHERE status = 'DONE'"), 'tick3 both orders DONE');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM trades WHERE side = 'SELL'"), 'tick3 SELL trade');
    T::eq('0', $db->getState('consecutive_losses'), 'tick3 win keeps consecutive_losses 0');
    T::strContains($r['summary'], 'exited:take_profit', 'tick3 summary');
    T::near(3.5 + 0.05 * 131.50 * 0.999, $ex->account()['balances']['USDT']['free'], 1e-9, 'tick3 USDT after the round trip');

    // (2b) still the same candle: no re-entry even though the signal is strong
    $md->setPrice('SOLUSDT', 129.75, 129.80);
    $r = (new Bot($cfg, $db, $ex, $t0 + 180000))->tick();
    T::eq('ok', $r['status'], 'tick4 status ok', $r['summary']);
    T::eq(null, $db->openPosition(), 'tick4 no re-entry on the same candle');
    T::eq(2, countRows($db, 'SELECT COUNT(*) FROM orders'), 'tick4 no new order');
    T::eq('no_new_candle', $db->getState('no_trade_reason'), 'tick4 reason no_new_candle');

    // next candle closes 15 minutes later: fresh evaluation, new entry
    $t1 = $t0 + 15 * 60000;
    $r = (new Bot($cfg, $db, $ex, $t1))->tick();
    T::eq('ok', $r['status'], 'tick5 status ok', $r['summary']);
    T::strContains($r['summary'], 'entered:SOLUSDT', 'tick5 re-entered on the new candle');
    T::eq('1788436800000', $db->getState('last_eval_candle_SOLUSDT'), 'tick5 last_eval_candle advanced');
    T::eq(3, countRows($db, 'SELECT COUNT(*) FROM orders'), 'tick5 third order');
    $pos = $db->openPosition();
    T::ok($pos !== null && abs($pos['qty'] - 0.05) < 1e-12, 'tick5 position qty');

    // (4) stop-loss when the bid is dropped
    $md->setPrice('SOLUSDT', 128.90, 128.95);
    $r = (new Bot($cfg, $db, $ex, $t1 + 60000))->tick();
    T::eq('ok', $r['status'], 'tick6 status ok', $r['summary']);
    T::eq(null, $db->openPosition(), 'tick6 position closed');
    $closed = $db->closedPositions(1);
    T::ok($closed !== [] && $closed[0]['exit_reason'] === 'stop_loss', 'tick6 exit_reason stop_loss', $closed !== [] ? (string) $closed[0]['exit_reason'] : 'none');
    if ($closed !== []) {
        T::ok($closed[0]['pnl_usdt'] < 0.0, 'tick6 pnl negative', (string) $closed[0]['pnl_usdt']);
        T::eq(128.90, $closed[0]['exit_price'], 'tick6 sold at bid');
    }
    T::eq(4, countRows($db, 'SELECT COUNT(*) FROM orders'), 'tick6 fourth order');
    T::eq('1', $db->getState('consecutive_losses'), 'tick6 loss counted');
    T::ok((string) $db->getState('cooldown_until', '') !== '', 'tick6 cooldown set');
    T::eq(2, count($db->closedPositions()), 'two closed positions in total');
    T::eq(6, countRows($db, 'SELECT COUNT(*) FROM equity'), 'equity sampled every tick');
    T::eq('0', (string) $db->getState('halted', '0'), 'never halted in the normal sequence');
    $sol = $ex->account()['balances']['SOL']['free'] ?? 0.0;
    T::ok($sol < 0.001, 'only dust remains after selling the floored balance', (string) $sol);
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM orders WHERE status IN ('SENDING','UNKNOWN')"), 'no pending orders');
});

T::group('bot-killswitch', ['Bot', 'PaperExchange', 'FakeMarketData'], static function (): void {
    $db  = freshDb('bot-kill');
    $md  = botMd();
    $cfg = botCfg(['symbols' => ['SOLUSDT'], 'equity_floor_usdt' => 9.0]);
    $ex  = new PaperExchange($md, $db, 0.1, 10.0);
    $t0  = FakeMarketData::SERVER_TIME_MS;
    $persist = function_exists('trader_save_config') && function_exists('trader_config');
    if ($persist) {
        trader_save_config($cfg);   // data/config.json (temp root) says enabled=true, like a running install
        T::eq(true, (bool) trader_config(true)['enabled'], 'precondition: enabled=true persisted');
    }

    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'entered', $r['summary']);
    T::ok($db->openPosition() !== null, 'position open');
    T::eq('10', $db->getState('equity_hwm'), 'hwm set from the first tick');

    // (5) equity drops below the floor: halt, close, disable
    $md->setPrice('SOLUSDT', 108.00, 108.05);   // position worth ≈ 5.4 USDT → equity ≈ 8.9 < 9.0, notional still ≥ 5
    $r = (new Bot($cfg, $db, $ex, $t0 + 60000))->tick();
    T::eq('halted', $r['status'], 'tick status halted', $r['summary']);
    T::eq('1', $db->getState('halted'), 'halted state');
    T::eq('equity_floor', $db->getState('halt_reason'), 'halt_reason equity_floor');
    T::eq(null, $db->openPosition(), 'position was closed');
    $closed = $db->closedPositions(1);
    T::ok($closed !== [] && $closed[0]['exit_reason'] === 'equity_floor', 'exit_reason equity_floor', $closed !== [] ? (string) $closed[0]['exit_reason'] : 'none');
    T::eq(2, countRows($db, 'SELECT COUNT(*) FROM orders'), 'sell order sent');
    T::eq('halted', Risk::entryBlockReason($cfg, $db, 10.0, 10.0), 'entries blocked while halted');
    if ($persist) {
        T::eq(false, (bool) trader_config(true)['enabled'], 'halt persists enabled=false to data/config.json (DESIGN §10 step 4)');
    }

    // still halted on the next candle: nothing is bought, manual reset required
    $md->setPrice('SOLUSDT', 129.75, 129.80);
    $r = (new Bot($cfg, $db, $ex, $t0 + 15 * 60000))->tick();
    T::eq('halted', $r['status'], 'next tick still halted', $r['summary']);
    T::eq(null, $db->openPosition(), 'no new position while halted');
    T::eq(2, countRows($db, 'SELECT COUNT(*) FROM orders'), 'no new order while halted');

    // manual reset (what the panel's reset_halt action does): entries possible again
    $db->setState('halted', '0');
    $db->setState('halt_reason', '');
    $reason = Risk::entryBlockReason($cfg, $db, 10.0, 10.0);
    T::ok($reason !== 'halted', 'reset_halt clears the halted block (the loss still leaves its cooldown)', $reason);
});

T::group('bot-idempotency', ['Bot', 'PaperExchange', 'FakeMarketData', 'TimeoutOnceExchange'], static function (): void {
    // (6a) a SENDING order left behind by a crashed tick is reconciled, not re-sent
    $db   = freshDb('bot-idem-a');
    $md   = botMd();
    $cfg  = botCfg(['symbols' => ['SOLUSDT']]);
    $ex   = new PaperExchange($md, $db, 0.1, 10.0);
    $t0   = FakeMarketData::SERVER_TIME_MS;
    $info = FakeMarketData::infoRow('SOLUSDT');
    $cid  = Util::clientOrderId('BUY', 'SOLUSDT', intdiv($t0, 60000) * 60);
    $db->insertOrder(['client_id' => $cid, 'position_id' => null, 'mode' => 'paper', 'symbol' => 'SOLUSDT', 'side' => 'BUY', 'status' => 'SENDING', 'created_at' => Util::nowIso(intdiv($t0, 1000))]);
    $ex->marketBuy('SOLUSDT', 6.5, $info, $cid);            // the exchange filled it ...
    $db->updateOrder($cid, ['status' => 'SENDING']);         // ... but the tick died before writing DONE
    T::eq(1, count($db->pendingOrders()), 'one pending order before the tick');

    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'tick status ok', $r['summary']);
    T::eq(1, countRows($db, 'SELECT COUNT(*) FROM orders'), 'no second order was sent');
    T::eq('DONE', $db->order($cid)['status'], 'pending order reconciled to DONE');
    $pos = $db->openPosition();
    T::ok($pos !== null, 'position created from the exchange fill');
    if ($pos !== null) {
        T::eq('reconciled', $pos['entry_reason'], 'entry_reason reconciled');
        T::eq(0.05, $pos['qty'], 'reconciled qty');
        T::eq((int) $db->order($cid)['position_id'], $pos['id'], 'order linked to the position');
    }
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM trades WHERE side = 'BUY'"), 'exactly one BUY trade');
    T::near(3.5, $ex->account()['balances']['USDT']['free'], 1e-9, 'USDT spent exactly once');

    // (6b) -1007 timeout on POST /order: order goes UNKNOWN, next tick reconciles the fill
    $db  = freshDb('bot-idem-b');
    $md  = botMd();
    $ex  = new TimeoutOnceExchange(new PaperExchange($md, $db, 0.1, 10.0));
    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('error', $r['status'], 'timeout tick reports error', $r['summary']);
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM orders WHERE status = 'UNKNOWN'"), 'order marked UNKNOWN after -1007');
    T::eq(null, $db->openPosition(), 'no position yet (fill unknown)');
    T::eq(0, countRows($db, 'SELECT COUNT(*) FROM trades'), 'no trade recorded yet');

    $r = (new Bot($cfg, $db, $ex, $t0 + 60000))->tick();
    T::eq('ok', $r['status'], 'next tick ok', $r['summary']);
    T::eq(1, countRows($db, 'SELECT COUNT(*) FROM orders'), 'still exactly one order');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM orders WHERE status = 'DONE'"), 'UNKNOWN order reconciled to DONE');
    $pos = $db->openPosition();
    T::ok($pos !== null && $pos['entry_reason'] === 'reconciled' && abs($pos['qty'] - 0.05) < 1e-12, 'position created from the reconciled fill');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM trades WHERE side = 'BUY'"), 'one BUY trade');
    T::near(3.5, $ex->account()['balances']['USDT']['free'], 1e-9, 'USDT spent exactly once (-1007 path)');
    T::eq(0, count($db->pendingOrders()), 'nothing pending');
});

/* ============================================================ 7b. Bot regressions */

/** Multiplies OHLC by $k, keeping openTime/closeTime/volume (ATR% and EMA ratios are scale-free). */
function scaleKlines(array $rows, float $k): array
{
    foreach ($rows as $i => $r) {
        foreach ([1, 2, 3, 4] as $c) {
            $rows[$i][$c] = (float) $r[$c] * $k;
        }
    }
    return $rows;
}

/**
 * Regression: entry_eff must NOT charge the retained dust to the sellable quantity.
 * BNBUSDT at ~600 with stepSize 0.001 makes one step worth 0.60 USDT, i.e. 9 % of a 6.5 USDT
 * entry: with the old `entry_eff = quote / qty` the stop landed ABOVE the fill price and the
 * position was stopped out on the very next tick.
 */
T::group('bot-dust', ['Bot', 'PaperExchange', 'FakeMarketData', 'Risk'], static function (): void {
    $db  = freshDb('bot-dust');
    $ask = 600.00;
    $bid = 599.76;   // spread 0.040 % < max_spread_pct 0.05

    $k15 = fixture('klines_15m_oversold');
    $k1h = fixture('klines_1h_uptrend');
    $md  = new FakeMarketData();
    $md->setKlines('BNBUSDT', '15m', scaleKlines($k15, $ask / (float) $k15[count($k15) - 2][4]));
    $md->setKlines('BNBUSDT', '1h', scaleKlines($k1h, $ask / (float) $k1h[count($k1h) - 2][4]));
    $md->setPrice('BNBUSDT', $bid, $ask);

    $cfg = botCfg(['symbols' => ['BNBUSDT']]);
    $ex  = new PaperExchange($md, $db, 0.1, 10.0);
    $t0  = FakeMarketData::SERVER_TIME_MS;
    $now = Util::nowIso(intdiv($t0, 1000));

    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'dust: tick status ok', $r['summary']);
    $pos = $db->openPosition();
    T::ok($pos !== null, 'dust: position opened on BNBUSDT', $r['summary']);
    if ($pos === null) {
        return;
    }
    // 6.5 / 600 = 0.01083333 gross, 0.1 % fee in base -> 0.01082250 net, 0.010 sellable,
    // 0.00082250 BNB dust (0.4935 USDT) that a later SELL recovers.
    T::near(0.010, (float) $pos['qty'], 1e-12, 'dust: qty floored to the 0.001 step');
    T::ok((float) $pos['dust_qty'] > 0.0008, 'dust: a big dust remainder was recorded', (string) $pos['dust_qty']);
    T::near(600.65, (float) $pos['entry_eff'], 1e-9, 'dust: entry_eff = (quote - dust x fill) / qty');
    T::ok((float) $pos['entry_eff'] < $ask * 1.002, 'dust: entry_eff stays within a hair of the fill price');
    // the bug this locks in: the naive basis would have put the stop above the market
    T::ok((float) $pos['entry_quote'] / (float) $pos['qty'] * 0.993 > $bid, 'dust: quote/qty would put the stop ABOVE the bid (the old bug)');

    T::ok((float) $pos['stop_price'] < $bid, 'dust: stop sits below the bid', Util::money((float) $pos['stop_price'], 6));
    T::eq('', Risk::exitDecision($pos, $ask * 0.9996, $cfg, $now)['reason'], 'dust: a fresh position is not stopped out on the next tick');
    T::eq('', Risk::exitDecision($pos, $bid, $cfg, $now)['reason'], 'dust: no exit at the entry bid either');
});

/**
 * Regression: an OPEN position whose symbol was taken off the watchlist must still be valued in
 * equity. When it was not, equity collapsed to the free quote and the equity_floor kill switch
 * fired on a perfectly healthy account.
 */
T::group('bot-offlist', ['Bot', 'PaperExchange', 'FakeMarketData', 'Risk'], static function (): void {
    $db  = freshDb('bot-offlist');
    $md  = botMd();
    $ex  = new PaperExchange($md, $db, 0.1, 10.0);
    $t0  = FakeMarketData::SERVER_TIME_MS;

    $r = (new Bot(botCfg(), $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'offlist: tick1 status ok', $r['summary']);
    $pos = $db->openPosition();
    T::ok($pos !== null && $pos['symbol'] === 'SOLUSDT', 'offlist: SOLUSDT position open');
    // baseline: the next tick samples equity with the position on the watchlist
    $r = (new Bot(botCfg(), $db, $ex, $t0 + 60000))->tick();
    T::eq('ok', $r['status'], 'offlist: baseline tick status ok', $r['summary']);
    $eq1 = $db->lastEquity();
    T::ok($eq1 !== null && (float) $eq1['position_value'] > 6.0, 'offlist: the position is valued while on the watchlist');

    // SOLUSDT is dropped from the watchlist while the position is still open
    $r = (new Bot(botCfg(['symbols' => ['DOGEUSDT']]), $db, $ex, $t0 + 120000))->tick();
    T::eq('ok', $r['status'], 'offlist: tick2 status ok (no kill switch)', $r['summary']);
    T::eq('0', (string) $db->getState('halted', '0'), 'offlist: not halted');
    T::eq('', (string) $db->getState('halt_reason', ''), 'offlist: no halt reason');
    T::ok($db->openPosition() !== null, 'offlist: the position off the watchlist is still open');
    $eq2 = $db->lastEquity();
    T::ok($eq2 !== null && (float) $eq2['position_value'] > 6.0, 'offlist: the off-watchlist position is still valued');
    T::ok($eq2 !== null && (float) $eq2['equity_usdt'] > 9.0, 'offlist: equity still counts the position', $eq2 === null ? '' : Util::money((float) $eq2['equity_usdt'], 4));
});

/** A STUCK position blocks new entries exactly like an OPEN one (DESIGN §10 step 6). */
T::group('bot-stuck', ['Bot', 'PaperExchange', 'FakeMarketData'], static function (): void {
    $db = freshDb('bot-stuck');
    $md = botMd();
    $ex = new PaperExchange($md, $db, 0.1, 10.0);
    $t0 = FakeMarketData::SERVER_TIME_MS;

    $r = (new Bot(botCfg(), $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'stuck: tick1 status ok', $r['summary']);
    $pos = $db->openPosition();
    T::ok($pos !== null, 'stuck: position opened');
    if ($pos === null) {
        return;
    }
    // Shrink it below the 5 USDT NOTIONAL filter and mark it STUCK, the way a fast crash would.
    // The wallet still holds the 0.05 SOL, so reconcile() has no reason to close it.
    $db->updatePosition((int) $pos['id'], ['status' => 'STUCK', 'qty' => 0.003, 'exit_reason' => 'stuck_notional']);
    $db->setState('last_eval_candle_SOLUSDT', '0');   // a new candle IS available for an entry
    $db->setState('stuck_retry_at', null);            // and the 15-minute retry window is open
    $ordersBefore = countRows($db, 'SELECT COUNT(*) FROM orders');

    $r = (new Bot(botCfg(), $db, $ex, $t0 + 120000))->tick();
    T::eq('ok', $r['status'], 'stuck: tick2 status ok', $r['summary']);
    T::eq('position_stuck', $db->getState('no_trade_reason'), 'stuck: no_trade_reason = position_stuck');
    T::eq($ordersBefore, countRows($db, 'SELECT COUNT(*) FROM orders'), 'stuck: no new order was sent');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM orders WHERE side = 'BUY'"), 'stuck: still exactly one BUY order');
    T::eq(null, $db->openPosition(), 'stuck: no OPEN position was created');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM positions WHERE status = 'STUCK'"), 'stuck: the position is still STUCK');
});

/**
 * Panel telemetry: state `symbol_metrics` carries a price for every watchlist symbol on every
 * tick (from the prices call the tick already makes), so the symbols table keeps showing the
 * step value and required size while a position is open, and the no-trade reason names the
 * balance when it - and not the market - gated every symbol.
 */
T::group('bot-metrics', ['Bot', 'PaperExchange', 'FakeMarketData', 'Util'], static function (): void {
    $db = freshDb('bot-metrics');
    $md = botMd();
    $ex = new PaperExchange($md, $db, 0.1, 10.0);
    $t0 = FakeMarketData::SERVER_TIME_MS;

    (new Bot(botCfg(), $db, $ex, $t0))->tick();
    $m = $db->getStateJson('symbol_metrics', []);
    T::ok(is_array($m) && isset($m['SOLUSDT']['price'], $m['DOGEUSDT']['price']), 'metrics: every watchlist symbol has a price');
    T::near(129.80, (float) $m['SOLUSDT']['price'], 1e-9, 'metrics: an evaluated symbol reports the ask it was sized against');
    T::near(130.793078, (float) $m['DOGEUSDT']['price'], 1e-6, 'metrics: DOGEUSDT priced from its own candles');
    T::ok(isset($m['SOLUSDT']['atr_pct']) && (float) $m['SOLUSDT']['atr_pct'] > 0.0, 'metrics: atr_pct stored from the evaluation');
    T::ok(isset($m['SOLUSDT']['spread_pct']) && (float) $m['SOLUSDT']['spread_pct'] > 0.0, 'metrics: spread_pct stored from the book');
    T::eq(1, (int) $m['SOLUSDT']['eligible'], 'metrics: eligible flag stored');
    T::ok(isset($m['SOLUSDT']['at']) && (string) $m['SOLUSDT']['at'] !== '', 'metrics: timestamped');

    // the position is open, so nothing is evaluated - the price must still be refreshed
    $md->setPrice('SOLUSDT', 130.10, 130.15);
    $r = (new Bot(botCfg(), $db, $ex, $t0 + 60000))->tick();
    T::eq('position_open', $db->getState('no_trade_reason'), 'metrics: nothing was evaluated on this tick');
    $m2 = $db->getStateJson('symbol_metrics', []);
    T::near(130.125, (float) $m2['SOLUSDT']['price'], 1e-9, 'metrics: price refreshed while a position is open');
    T::near((float) $m['SOLUSDT']['atr_pct'], (float) $m2['SOLUSDT']['atr_pct'], 1e-12, 'metrics: the un-refreshed keys keep their previous value');

    // a balance too small to fund any entry must say so instead of "no_eligible_signal"
    $db2 = freshDb('bot-metrics-poor');
    $md2 = botMd();
    $ex2 = new PaperExchange($md2, $db2, 0.1, 8.0);
    $r = (new Bot(botCfg(['symbols' => ['SOLUSDT'], 'equity_floor_usdt' => 1.0]), $db2, $ex2, $t0))->tick();
    T::eq('ok', $r['status'], 'metrics: poor-account tick still runs', $r['summary']);
    T::eq(null, $db2->openPosition(), 'metrics: nothing entered on 8 USDT');
    T::eq('size_unaffordable(quote_free=8.00)', $db2->getState('no_trade_reason'), 'metrics: the balance, not the market, is reported');
    T::eq('0', (string) $db2->getState('halted', '0'), 'metrics: a too-small account is not a halt');
});

/* ============================================================ 7c. Risk regressions */

/** Risk queries are scoped to the configured mode: paper history must not gate live. */
T::group('risk-mode-scope', ['Risk', 'Db', 'Util'], static function (): void {
    $db  = freshDb('risk-mode-scope');
    $now = Util::nowIso();
    for ($i = 0; $i < 3; $i++) {
        closedPosition($db, -0.25, ['mode' => 'paper', 'opened_at' => $now, 'closed_at' => $now]);
    }
    $db->setState('day_start_equity', 10.0);
    $db->setState('day_start_date', Util::todayUtc());

    $todayStart = Util::todayUtc() . 'T00:00:00Z';
    T::near(-0.75, $db->realisedPnl($todayStart), 1e-9, 'mode scope: realisedPnl(null) sees every mode');
    T::near(-0.75, $db->realisedPnl($todayStart, 'paper'), 1e-9, 'mode scope: realisedPnl(paper) sees the paper losses');
    T::near(0.0, $db->realisedPnl($todayStart, 'live'), 1e-9, 'mode scope: realisedPnl(live) sees none of them');
    T::eq(3, $db->entriesSince($todayStart, 'paper'), 'mode scope: entriesSince(paper)');
    T::eq(0, $db->entriesSince($todayStart, 'live'), 'mode scope: entriesSince(live)');
    T::eq(3, count($db->lastClosed(10, 'paper')), 'mode scope: lastClosed(paper)');
    T::eq(0, count($db->lastClosed(10, 'live')), 'mode scope: lastClosed(live)');
    T::eq(3, $db->stats('paper')['closed'], 'mode scope: stats(paper)');
    T::eq(0, $db->stats('live')['closed'], 'mode scope: stats(live)');
    T::eq(3, $db->stats()['closed'], 'mode scope: stats(null) is the panel view');

    T::eq('daily_cap', Risk::entryBlockReason(cfg(['enabled' => true]), $db, 10.0, 10.0), 'mode scope: paper history still caps paper');
    T::eq('', Risk::entryBlockReason(cfg(['mode' => 'live', 'enabled' => true]), $db, 10.0, 10.0), 'mode scope: paper history does not block live');
});

/**
 * Clearing a halt must re-base the high-water mark down to the current equity, otherwise the
 * drawdown kill switch re-fires on the very next tick and the reset is a no-op.
 * (Mirrors index.php handle_action() case 'reset_halt'.)
 */
T::group('risk-hwm-rebase', ['Risk', 'Db', 'Util'], static function (): void {
    $db  = freshDb('risk-hwm-rebase');
    $cfg = cfg(['equity_floor_usdt' => 7.0, 'hwm_drawdown_pct' => 20.0]);
    $db->setState('equity_hwm', 20.0);
    $db->insertEquity(10.0, 10.0, 0.0, 0.0);

    $sv = Risk::survivalCheck($cfg, $db, 10.0, false, false);
    T::eq('halt', $sv['action'], 'hwm rebase: drawdown halts');
    T::eq('drawdown', $sv['reason'], 'hwm rebase: reason drawdown (not the absolute floor)');
    T::eq('1', (string) $db->getState('halted', '0'), 'hwm rebase: halted persisted');

    // control: clearing the flag alone leaves the stale HWM in place, so the halt re-fires
    $db->setState('halted', '0');
    $db->setState('halt_reason', null);
    T::eq('halt', Risk::survivalCheck($cfg, $db, 10.0, false, false)['action'], 'hwm rebase: without the re-base the halt re-fires immediately');

    // the real reset: clear the flag AND re-base the HWM to the last recorded equity
    $db->setState('halted', '0');
    $db->setState('halt_reason', null);
    $eqRow = $db->lastEquity();
    $eqNow = $eqRow !== null && is_numeric($eqRow['equity_usdt']) ? (float) $eqRow['equity_usdt'] : 0.0;
    $hwmOld = is_numeric($db->getState('equity_hwm', null)) ? (float) $db->getState('equity_hwm', null) : 0.0;
    T::near(10.0, $eqNow, 1e-9, 'hwm rebase: last equity read back');
    if ($eqNow > 0.0 && $eqNow < $hwmOld) {
        $db->setState('equity_hwm', $eqNow);
    }
    T::near(10.0, (float) $db->getState('equity_hwm'), 1e-9, 'hwm rebase: equity_hwm re-based to the current equity');
    T::eq('none', Risk::survivalCheck($cfg, $db, 10.0, false, false)['action'], 'hwm rebase: the next tick runs normally');
    T::eq('0', (string) $db->getState('halted', '0'), 'hwm rebase: still not halted');

    // the absolute floor is untouched by the re-base and must keep re-halting
    $db->insertEquity(6.0, 6.0, 0.0, 0.0);
    $sv = Risk::survivalCheck($cfg, $db, 6.0, false, false);
    T::eq('halt', $sv['action'], 'hwm rebase: the absolute equity_floor still halts');
    T::eq('equity_floor', $sv['reason'], 'hwm rebase: reason equity_floor');
});
// ---------------------------------------------------------------- networks --
// Binance Demo Trading (demo.binance.com) is a separate host from the old Spot
// testnet. Sending demo keys to testnet.binance.vision is what produces
// "-2015 Invalid API-key, IP, or permissions for action".
T::group('networks', ['Binance', 'Exchange', 'Db'], static function (): void {
    T::eq(Binance::NET_PROD, Binance::normalizeNetwork(false), 'legacy false -> prod');
    T::eq(Binance::NET_TESTNET, Binance::normalizeNetwork(true), 'legacy true -> testnet');
    T::eq(Binance::NET_PROD, Binance::normalizeNetwork('live'), 'live -> prod');
    T::eq(Binance::NET_PROD, Binance::normalizeNetwork('paper'), 'paper -> prod (public data only)');
    T::eq(Binance::NET_DEMO, Binance::normalizeNetwork('demo'), 'demo -> demo');
    T::eq(Binance::NET_DEMO, Binance::normalizeNetwork(' DEMO '), 'demo is case/space insensitive');
    T::eq(Binance::NET_TESTNET, Binance::normalizeNetwork('testnet'), 'testnet -> testnet');
    T::eq(Binance::NET_PROD, Binance::normalizeNetwork('nonsense'), 'unknown -> prod');

    $prod = new Binance('k', 's', 'live');
    $demo = new Binance('k', 's', 'demo');
    $test = new Binance('k', 's', 'testnet');

    T::eq('https://api.binance.com', $prod->tradeUrl(), 'prod trade url');
    T::eq('https://demo-api.binance.com', $demo->tradeUrl(), 'demo trade url');
    T::eq('https://testnet.binance.vision', $test->tradeUrl(), 'testnet trade url');

    // Only the live network has a separate read-only market-data host; demo and
    // testnet must serve their own data or prices would not match their books.
    T::eq('https://data-api.binance.vision', $prod->dataUrl(), 'prod data url is data-api');
    T::eq('https://demo-api.binance.com', $demo->dataUrl(), 'demo data url is the demo host');
    T::eq('https://testnet.binance.vision', $test->dataUrl(), 'testnet data url is the testnet host');

    T::eq(false, $prod->isSimulated(), 'live is real money');
    T::eq(true, $demo->isSimulated(), 'demo is simulated');
    T::eq(true, $test->isSimulated(), 'testnet is simulated');
    T::eq(false, $demo->isTestnet(), 'demo is not the testnet');

    // factory: demo behaves like live/testnet (needs keys, yields a LiveExchange)
    $db = freshDb('networks');
    $threw = '';
    try {
        Exchange::factory(['mode' => 'demo', 'api_key' => '', 'api_secret' => ''], $db);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    T::strContains($threw, 'requires a Binance API key', 'demo mode without keys is refused');

    $ex = Exchange::factory(['mode' => 'demo', 'api_key' => 'k', 'api_secret' => 's'], $db);
    T::eq('demo', $ex->mode(), 'factory returns a demo exchange');
    T::ok($ex instanceof LiveExchange, 'demo uses the keyed LiveExchange');
    T::eq('https://demo-api.binance.com', $ex->api()->tradeUrl(), 'demo exchange points at the demo host');

    $threw = '';
    try {
        Exchange::factory(['mode' => 'sandbox', 'api_key' => 'k', 'api_secret' => 's'], $db);
    } catch (Throwable $e) {
        $threw = $e->getMessage();
    }
    T::strContains($threw, 'paper, demo, testnet or live', 'unknown mode names the valid ones');

    // config + settings validation must both accept demo
    if (function_exists('trader_config_merge')) {
        $m = trader_config_merge(['mode' => 'demo']);
        T::eq('demo', $m['mode'], 'config keeps mode demo');
        $m = trader_config_merge(['mode' => ' DEMO ']);
        T::eq('demo', $m['mode'], 'config normalises mode case/space');
        $m = trader_config_merge(['mode' => 'bogus']);
        T::eq('paper', $m['mode'], 'config falls back to paper on an unknown mode');
    }
    if (class_exists('Risk', false)) {
        list($cfg, $errors) = Risk::validateConfig(['mode' => 'demo'], ['mode' => 'paper']);
        T::eq('demo', $cfg['mode'], 'validateConfig accepts demo');
        T::eq(0, count(array_filter($errors, static function ($e) { return strpos($e, 'mode') !== false; })), 'no mode error for demo');
    }
});

/* ============================================================ engines (DESIGN-ENGINES.md §11) */

/** Config for the grid / pmm engines on SOLUSDT (step 0.001, tick 0.01, minNotional 5). */
function engineCfg(array $over = []): array
{
    return cfg(array_merge([
        'enabled'              => true,
        'mode'                 => 'paper',
        'symbols'              => ['SOLUSDT'],
        'engine'               => 'grid',
        'engine_symbol'        => 'SOLUSDT',
        'allow_live_engines'   => false,
        'post_only'            => true,
        'engine_max_orders'    => 12,
        'grid_levels'          => 3,
        'grid_spacing_pct'     => 0.60,
        'grid_order_usdt'      => 6.5,
        'grid_range_up_pct'    => 4.0,
        'grid_range_down_pct'  => 6.0,
        'grid_exit_liquidates' => false,
        'pmm_spread_pct'       => 0.25,
        'pmm_order_usdt'       => 6.5,
        'pmm_refresh_sec'      => 600,
        'pmm_target_base_pct'  => 50,
        'pmm_max_base_pct'     => 80,
        'paper_start_usdt'     => 200.0,
        'adaptive'             => false,
        'max_trades_per_day'   => 50,
        'max_orders_per_hour'  => 500,
    ], $over));
}

/**
 * Scripted book for the engine groups: mid 130.00 on SOLUSDT, no klines (the engines never
 * look at candles). The clock is aligned with the wall clock because EnginePmm ages its
 * quotes against time(): $ms is "now minus a few seconds", so a quote it writes really is
 * that many seconds old.
 */
function engineMd(?int $serverMs = null): FakeMarketData
{
    $md = new FakeMarketData([], $serverMs === null ? (time() - 10) * 1000 : $serverMs);
    $md->setPrice('SOLUSDT', 129.98, 130.02);
    return $md;
}

/** Grid rung price: anchor × (1 − i × spacing), rounded down to the tick. */
function gridRung(float $anchor, int $i, float $spacingPct = 0.60): float
{
    return (float) Util::roundToTick($anchor * (1.0 - $i * $spacingPct / 100.0), '0.01000000', 'down');
}

T::group('util-tick', ['Util'], static function (): void {
    // tickSize 0.00001 (DOGEUSDT): exact strings at the tick's precision, never an exponent
    T::eq('0.12345', Util::roundToTick(0.123456, '0.00001000', 'down'), 'roundToTick down @0.00001');
    T::eq('0.12346', Util::roundToTick(0.123456, '0.00001000', 'up'), 'roundToTick up @0.00001');
    T::eq('0.12346', Util::roundToTick(0.123456, '0.00001000', 'nearest'), 'roundToTick nearest rounds up @0.00001');
    T::eq('0.12345', Util::roundToTick(0.123451, '0.00001000', 'nearest'), 'roundToTick nearest rounds down @0.00001');
    T::eq('0.12345', Util::roundToTick(0.12345, '0.00001000', 'down'), 'a price already on the tick is unchanged (down)');
    T::eq('0.12345', Util::roundToTick(0.12345, '0.00001000', 'up'), 'a price already on the tick is unchanged (up)');
    T::eq('0.20000', Util::roundToTick(0.2, '0.00001000', 'down'), 'trailing zeros pad to the tick precision');
    T::eq('0.00005', Util::roundToTick(0.00005, '0.00001000', 'down'), 'a tiny price never becomes an exponent');

    // tickSize 1: integer prices, no decimal point at all
    T::eq('123', Util::roundToTick(123.4, '1', 'down'), 'roundToTick down @1');
    T::eq('124', Util::roundToTick(123.4, '1', 'up'), 'roundToTick up @1');
    T::eq('123', Util::roundToTick(123.4, '1', 'nearest'), 'roundToTick nearest down @1');
    T::eq('124', Util::roundToTick(123.6, '1', 'nearest'), 'roundToTick nearest up @1');
    T::eq('123', Util::roundToTick(123.0, '1.00000000', 'up'), 'an exact integer price is unchanged @1');

    // 0.01 (SOLUSDT) — the tick the grid group prices against
    T::eq('129.22', Util::roundToTick(130.0 * 0.994, '0.01000000', 'down'), 'grid rung 1 rounds down to the tick');
    T::eq('130.11', Util::roundToTick(130.1052, '0.01000000', 'up'), 'a sell price rounds up to the tick');

    // exponent-free output is the whole point (PHP prints 5.0E-5 for a plain cast)
    foreach ([0.00005, 0.0000001, 1.0e-8, 123456789.12345678] as $p) {
        foreach (['0.00000001', '0.00001000', '1'] as $tick) {
            foreach (['down', 'up', 'nearest'] as $dir) {
                $s = Util::roundToTick((float) $p, $tick, $dir);
                if (stripos($s, 'e') !== false) {
                    T::ok(false, 'roundToTick never emits exponent notation', $p . ' ' . $tick . ' ' . $dir . ' => ' . $s);
                    return;
                }
                if (Util::decimalsOf($tick) !== (strpos($s, '.') === false ? 0 : strlen(substr($s, strpos($s, '.') + 1)))) {
                    T::ok(false, 'roundToTick keeps the tick precision', $p . ' ' . $tick . ' ' . $dir . ' => ' . $s);
                    return;
                }
            }
        }
    }
    T::ok(true, 'roundToTick never emits exponent notation and always keeps the tick precision');

    // degenerate inputs must not produce garbage that could reach the API
    T::eq('0.00', Util::roundToTick(0.0, '0.01000000', 'down'), 'a zero price yields zero at the tick precision');
    T::eq('0.00', Util::roundToTick(-1.0, '0.01000000', 'up'), 'a negative price yields zero at the tick precision');
    T::eq('0.00', Util::roundToTick(INF, '0.01000000', 'nearest'), 'a non-finite price yields zero at the tick precision');
    // buys round down and sells round up: a post-only quote always stays passive
    T::ok((float) Util::roundToTick(129.229, '0.01000000', 'down') <= 129.229, 'a BUY price never rounds up into the book');
    T::ok((float) Util::roundToTick(130.101, '0.01000000', 'up') >= 130.101, 'a SELL price never rounds down into the book');
});

T::group('engine-orders', ['EngineOrders', 'FakePaperExchange', 'FakeMarketData', 'Db'], static function (): void {
    $db   = freshDb('engine-orders');
    $md   = engineMd(FakeMarketData::SERVER_TIME_MS);
    $ex   = new FakePaperExchange($md, 0.1, 200.0);
    $info = FakeMarketData::infoRow('SOLUSDT');
    $cfg  = engineCfg();
    $t0   = FakeMarketData::SERVER_TIME_MS;
    $o    = new EngineOrders($cfg, $db, $ex, $info, $t0);

    // ---- place
    $row = $o->place('BUY', 129.22, 6.5, 'grid_buy', 1);
    T::ok($row !== null, 'place returns the stored row');
    if ($row === null) {
        return;
    }
    T::eq('NEW', (string) $row['status'], 'a resting buy is NEW');
    T::eq('BUY', (string) $row['side'], 'side stored');
    T::eq(1, (int) $row['level'], 'level stored');
    T::eq('grid_buy', (string) $row['purpose'], 'purpose stored');
    T::near(129.22, (float) $row['price'], 1e-9, 'price rounded down to the tick');
    T::near(0.05, (float) $row['qty'], 1e-9, 'quantity floored to the step');
    T::eq(1, count($ex->openOrders('SOLUSDT')), 'the order rests on the exchange');
    T::near(6.461, $ex->locked('USDT'), 1e-9, 'the resting buy locks its quote');
    T::near(193.539, $ex->free('USDT'), 1e-9, 'the locked quote left the free balance');

    // ---- sync while nothing has crossed
    $s = $o->sync('SOLUSDT');
    T::eq(0, count($s['filled']), 'sync books no fill while the order rests');
    T::eq(1, count($s['open']), 'sync reports the resting order');
    T::eq(0, (int) $s['cancelled'], 'sync cancels nothing');

    // ---- the scripted book crosses the rung: the fill books a lot
    $md->setPrice('SOLUSDT', 129.10, 129.15);
    $s = $o->sync('SOLUSDT');
    T::eq(1, count($s['filled']), 'sync books the fill once the ask crosses the rung');
    T::eq(0, count($s['open']), 'nothing rests any more');
    $lots = $db->openLots('SOLUSDT', 'paper', 'grid');
    T::eq(1, count($lots), 'a BUY fill inserts one FIFO lot');
    if ($lots !== []) {
        // 0.05 SOL bought at 129.22 = 6.461 USDT, 0.1 % commission in SOL leaves 0.04995 SOL;
        // the cost basis stays the full quote spent, so lot.price = 6.461 / 0.04995.
        T::near(0.04995, (float) $lots[0]['qty'], 1e-12, 'the lot holds the quantity net of the base commission');
        T::near(6.461 / 0.04995, (float) $lots[0]['price'], 1e-9, 'lot.price is the fee-inclusive cost basis');
        T::eq(1, (int) $lots[0]['level'], 'the lot keeps its rung');
    }
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM trades WHERE side = 'BUY'"), 'the fill writes one BUY trade');
    T::near(0.04995, $ex->free('SOL'), 1e-12, 'the base landed in the wallet');
    T::near(0.0, $ex->locked('USDT'), 1e-12, 'the lock was consumed by the fill');
    $stored = $db->engineOrder((string) $row['client_id']);
    T::ok($stored !== null && (string) $stored['status'] === 'FILLED', 'the engine order is FILLED');

    // ---- idempotency: a repeated sync books nothing more
    $s = $o->sync('SOLUSDT');
    T::eq(0, count($s['filled']), 'a repeated sync books no second fill');
    T::eq(1, count($db->openLots('SOLUSDT', 'paper', 'grid')), 'a repeated sync creates no second lot');
    T::eq(1, countRows($db, 'SELECT COUNT(*) FROM trades'), 'a repeated sync creates no second trade');

    // ---- a second rung fills, so FIFO has two lots to consume
    $r2 = $o->place('BUY', 128.44, 6.5, 'grid_buy', 2);
    T::ok($r2 !== null, 'second rung placed');
    $md->setPrice('SOLUSDT', 128.30, 128.35);
    $s = $o->sync('SOLUSDT');
    T::eq(1, count($s['filled']), 'the second rung fills when the ask reaches it');
    T::eq(2, count($db->openLots('SOLUSDT', 'paper', 'grid')), 'two lots in inventory');

    // ---- a SELL consumes the lots FIFO and writes one cycle per slice
    $inv = 0.0;
    foreach ($db->openLots('SOLUSDT', 'paper', 'grid') as $l) {
        $inv += (float) $l['remaining'];
    }
    $sellQty = (float) Util::floorToStep($inv, '0.00100000');
    $r3 = $o->place('SELL', 130.11, 130.11 * $sellQty * (1.0 + 1e-9), 'grid_sell', 1);
    T::ok($r3 !== null && (float) $r3['qty'] === $sellQty, 'the sell is sized from the inventory');
    $md->setPrice('SOLUSDT', 130.20, 130.25);
    $s = $o->sync('SOLUSDT');
    T::eq(1, count($s['filled']), 'the sell fills when the bid reaches it');
    $cycles = $db->cycles(10, 'paper', 'grid');
    T::eq(2, count($cycles), 'one cycle per consumed lot slice (FIFO)');
    if (count($cycles) === 2) {
        // cycles() is newest first, and both closed in the same call: the FIFO order is by id
        $first = $cycles[1];
        T::near(6.461 / 0.04995, (float) $first['buy_price'], 1e-9, 'the first cycle consumed the oldest lot');
        T::eq(1, (int) $first['level'], 'the cycle carries the lot rung');
        foreach ($cycles as $c) {
            // pnl = net proceeds − lot cost: the sell fee is taken in USDT, the buy fee is
            // already inside lot.price, so the identity below is exact.
            $expect = (float) $c['qty'] * ((float) $c['sell_price'] * 0.999 - (float) $c['buy_price']);
            T::near($expect, (float) $c['pnl_usdt'], 1e-9, 'cycle pnl = qty × (sell × (1 − fee) − fee-inclusive buy)');
            T::ok((float) $c['pnl_usdt'] > 0.0, 'a rung sold one spacing up is profitable', (string) $c['pnl_usdt']);
        }
    }
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM trades WHERE side = 'SELL'"), 'one SELL trade for the whole fill');
    $left = 0.0;
    foreach ($db->openLots('SOLUSDT', 'paper', 'grid') as $l) {
        $left += (float) $l['remaining'];
    }
    T::ok($left < 0.001, 'only sub-step dust is left in inventory', (string) $left);

    // ---- cancel
    $r4 = $o->place('BUY', 127.00, 6.5, 'grid_buy', 3);
    T::ok($r4 !== null, 'a rung to cancel was placed');
    $lockedBefore = $ex->locked('USDT');
    T::ok($lockedBefore > 0.0, 'the rung locks its quote');
    T::ok($o->cancel((string) $r4['client_id']), 'cancel reports the order left the book');
    $after = $db->engineOrder((string) $r4['client_id']);
    T::ok($after !== null && (string) $after['status'] === 'CANCELED', 'the cancelled order is CANCELED locally');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'nothing rests on the exchange after the cancel');
    T::near(0.0, $ex->locked('USDT'), 1e-12, 'cancelling releases the locked quote');

    // ---- an order on the exchange that we do not track is cancelled: the engine owns the book
    $ex->limitOrder('SOLUSDT', 'BUY', '0.050', '120.00', $info, 'foreign-order-1', true);
    T::eq(1, $ex->openOrderCount('SOLUSDT'), 'the foreign order rests');
    $s = $o->sync('SOLUSDT');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'sync cancels the untracked exchange order');
    T::ok((int) $s['cancelled'] >= 1, 'sync counts the untracked order it cancelled');
    T::eq(null, $db->engineOrder('foreign-order-1'), 'the untracked order is never adopted locally');

    // ---- cancelAll
    $o->place('BUY', 127.00, 6.5, 'grid_buy', 3);
    $o->place('BUY', 126.00, 6.5, 'grid_buy', 4);
    T::eq(2, count($db->openEngineOrders('SOLUSDT')), 'two rungs live before cancelAll');
    T::eq(2, $o->cancelAll('SOLUSDT', 'test'), 'cancelAll reports both orders');
    T::eq(0, count($db->openEngineOrders('SOLUSDT')), 'no live rows after cancelAll');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'no resting orders after cancelAll');
});

T::group('engine-grid', ['Bot', 'EngineGrid', 'FakePaperExchange', 'FakeMarketData'], static function (): void {
    $db  = freshDb('engine-grid');
    $md  = engineMd(FakeMarketData::SERVER_TIME_MS);
    $ex  = new FakePaperExchange($md, 0.1, 200.0);
    $cfg = engineCfg();
    $t0  = FakeMarketData::SERVER_TIME_MS;

    // ---- the ladder is built below the mid, one rung per tick
    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'grid tick1 ok', $r['summary']);
    T::near(130.0, (float) $db->getState('grid_anchor', '0'), 1e-9, 'the first tick anchors on the mid');
    T::eq('SOLUSDT', (string) $db->getState('grid_symbol', ''), 'the anchor records its symbol');
    for ($i = 1; $i <= 2; $i++) {
        (new Bot($cfg, $db, $ex, $t0 + $i * 60000))->tick();
    }
    $live = $db->openEngineOrders('SOLUSDT');
    T::eq(3, count($live), 'three ticks build three rungs (one order per side per tick)');
    $prices = [];
    foreach ($live as $l) {
        T::eq('BUY', (string) $l['side'], 'every rung of a fresh ladder is a buy');
        T::ok((float) $l['price'] < 129.98, 'a rung is posted strictly below the bid', (string) $l['price']);
        $prices[(int) $l['level']] = (float) $l['price'];
    }
    T::near(gridRung(130.0, 1), isset($prices[1]) ? $prices[1] : 0.0, 1e-9, 'rung 1 = anchor × (1 − 1 × spacing)');
    T::near(gridRung(130.0, 2), isset($prices[2]) ? $prices[2] : 0.0, 1e-9, 'rung 2 = anchor × (1 − 2 × spacing)');
    T::near(gridRung(130.0, 3), isset($prices[3]) ? $prices[3] : 0.0, 1e-9, 'rung 3 = anchor × (1 − 3 × spacing)');
    T::eq(3, $ex->openOrderCount('SOLUSDT'), 'the whole ladder rests on the exchange');

    // ---- a buy fill produces a sell one rung up
    $md->setPrice('SOLUSDT', 129.15, 129.20);
    $r = (new Bot($cfg, $db, $ex, $t0 + 3 * 60000))->tick();
    T::eq('ok', $r['status'], 'grid tick4 ok', $r['summary']);
    T::strContains($r['summary'], 'engine fill(s) booked', 'the rung fill is booked by the tick');
    T::eq(1, count($db->openLots('SOLUSDT', 'paper', 'grid')), 'the filled rung became a lot');
    $sell = null;
    foreach ($db->openEngineOrders('SOLUSDT') as $l) {
        if ((string) $l['side'] === 'SELL') {
            $sell = $l;
        }
    }
    T::ok($sell !== null, 'a sell was posted for the new lot');
    if ($sell !== null) {
        $lot = $db->openLots('SOLUSDT', 'paper', 'grid')[0];
        $want = max((float) $lot['price'] * 1.006, 130.0);
        T::near((float) Util::roundToTick($want, '0.01000000', 'up'), (float) $sell['price'], 1e-9,
            'the sell sits one spacing above the lot, never below its own rung');
        T::ok((float) $sell['price'] > 129.20, 'the sell is posted strictly above the ask', (string) $sell['price']);
    }

    // ---- the round trip: the completed cycle earns the spacing minus the round-trip fees
    $md->setPrice('SOLUSDT', 130.20, 130.25);
    $r = (new Bot($cfg, $db, $ex, $t0 + 4 * 60000))->tick();
    T::eq('ok', $r['status'], 'grid tick5 ok', $r['summary']);
    $cycles = $db->cycles(5, 'paper', 'grid');
    T::eq(1, count($cycles), 'the round trip wrote one cycle');
    if ($cycles !== []) {
        $c   = $cycles[0];
        $rel = (float) $c['pnl_usdt'] / ((float) $c['qty'] * (float) $c['buy_price']) * 100.0;
        T::ok((float) $c['pnl_usdt'] > 0.0, 'the cycle is profitable', (string) $c['pnl_usdt']);
        // spacing 0.60 %, round-trip fee 0.20 %. The engine sells one spacing above the
        // FEE-INCLUSIVE lot cost, so the realised margin lands between spacing − 2 × fee and
        // spacing − fee; the tick rounding moves it by another 0.01 / 130 = 0.008 %.
        T::near(0.60 - 0.20, $rel, 0.15, 'cycle pnl ≈ spacing − round-trip fees (percent of cost)');
        T::ok($rel >= 0.60 - 0.20 - 1e-9, 'the round trip clears the fee floor', (string) $rel);
        T::ok($rel <= 0.60 + 1e-9, 'the round trip never earns more than the spacing', (string) $rel);
    }
    $stats = $db->engineStats('paper', 'grid');
    T::eq(1, (int) $stats['cycles'], 'engineStats counts the cycle');
    T::eq(1, (int) $stats['wins'], 'engineStats counts the win');

    // ---- the order cap holds
    $db2  = freshDb('engine-grid-cap');
    $md2  = engineMd(FakeMarketData::SERVER_TIME_MS);
    $ex2  = new FakePaperExchange($md2, 0.1, 200.0);
    $cfg2 = engineCfg(['engine_max_orders' => 2]);
    for ($i = 0; $i < 4; $i++) {
        (new Bot($cfg2, $db2, $ex2, $t0 + $i * 60000))->tick();
    }
    T::eq(2, count($db2->openEngineOrders('SOLUSDT')), 'the ladder stops at engine_max_orders');
    T::eq(2, $ex2->openOrderCount('SOLUSDT'), 'the exchange never sees more than the cap');
    T::eq('grid:idle', (string) $db2->getState('no_trade_reason', ''), 'a capped tick is idle, not an error');

    // ---- range exit cancels everything and pauses until a manual re-anchor
    $md->setPrice('SOLUSDT', 136.00, 136.05);   // mid 136.025 > 130 × 1.04
    $r = (new Bot($cfg, $db, $ex, $t0 + 5 * 60000))->tick();
    T::eq('ok', $r['status'], 'grid range-exit tick ok', $r['summary']);
    T::strContains($r['summary'], 'range_exit', 'the tick reports the range exit');
    T::eq(0, count($db->openEngineOrders('SOLUSDT')), 'the range exit cancelled every local row');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'the range exit cleared the exchange book');
    T::eq('grid_range_exit', (string) $db->getState('pause_reason', ''), 'pause_reason is grid_range_exit');
    T::eq('grid_range_exit', (string) $db->getState('grid_paused_reason', ''), 'the grid records its own pause');
    T::ok(Util::isoToTs((string) $db->getState('paused_until', '')) > time() + 86400, 'the pause runs far into the future');

    // a paused grid quotes nothing at all
    $r = (new Bot($cfg, $db, $ex, $t0 + 6 * 60000))->tick();
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'a paused grid posts nothing');
    T::strContains((string) $db->getState('no_trade_reason', ''), 'grid_range_exit', 'the pause reason is reported');

    // ---- re-anchor resumes
    $bot = new Bot($cfg, $db, $ex, $t0 + 7 * 60000);
    $bot->reanchorGrid();
    T::near(136.025, (float) $db->getState('grid_anchor', '0'), 1e-6, 're-anchor re-centres on the current mid');
    T::eq('', (string) $db->getState('grid_paused_reason', ''), 're-anchor clears the grid pause');
    T::eq('', (string) $db->getState('paused_until', ''), 're-anchor clears paused_until');
    $r = (new Bot($cfg, $db, $ex, $t0 + 8 * 60000))->tick();
    T::eq('ok', $r['status'], 'the tick after a re-anchor is ok', $r['summary']);
    T::ok(count($db->openEngineOrders('SOLUSDT')) > 0, 'the grid quotes again after a re-anchor');
});

T::group('engine-pmm', ['Bot', 'EnginePmm', 'FakePaperExchange', 'FakeMarketData'], static function (): void {
    // EnginePmm ages its quotes against time(), so this group runs on a wall-clock-aligned fake clock.
    $t0 = (time() - 10) * 1000;

    // ---- a bid and an ask around the mid at the configured spread
    $db  = freshDb('engine-pmm');
    $md  = engineMd($t0);
    $ex  = new FakePaperExchange($md, 0.1, 130.0);
    $ex->setBalance('SOL', 1.0);                     // 130 USDT of base ⇒ basePct = 50 % = the target
    $cfg = engineCfg(['engine' => 'pmm']);
    $r   = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'pmm tick1 ok', $r['summary']);
    $live = [];
    foreach ($db->openEngineOrders('SOLUSDT') as $o) {
        $live[(string) $o['side']] = $o;
    }
    T::eq(2, count($live), 'pmm quotes both sides in one tick');
    T::ok(isset($live['BUY']), 'a bid is posted');
    T::ok(isset($live['SELL']), 'an ask is posted');
    if (isset($live['BUY'])) {
        T::near((float) Util::roundToTick(130.0 * 0.9975, '0.01000000', 'down'), (float) $live['BUY']['price'], 1e-9,
            'the bid sits half the spread below the mid, rounded down');
        T::eq('pmm_bid', (string) $live['BUY']['purpose'], 'the bid is tagged pmm_bid');
        T::ok((float) $live['BUY']['price'] < 129.98, 'the bid stays below the book bid (post-only)');
    }
    if (isset($live['SELL'])) {
        T::near((float) Util::roundToTick(130.0 * 1.0025, '0.01000000', 'up'), (float) $live['SELL']['price'], 1e-9,
            'the ask sits half the spread above the mid, rounded up');
        T::eq('pmm_ask', (string) $live['SELL']['purpose'], 'the ask is tagged pmm_ask');
        T::ok((float) $live['SELL']['price'] > 130.02, 'the ask stays above the book ask (post-only)');
    }
    T::eq(2, $ex->openOrderCount('SOLUSDT'), 'both quotes rest on the exchange');

    // ---- a young quote is kept, not re-posted
    $bidCid = isset($live['BUY']) ? (string) $live['BUY']['client_id'] : '';
    $before = countRows($db, 'SELECT COUNT(*) FROM engine_orders');
    (new Bot($cfg, $db, $ex, $t0 + 1000))->tick();
    T::eq($before, countRows($db, 'SELECT COUNT(*) FROM engine_orders'), 'a quote younger than pmm_refresh_sec is left alone');
    T::eq(2, count($db->openEngineOrders('SOLUSDT')), 'both quotes are still live');

    // ---- a quote older than pmm_refresh_sec is cancelled and replaced
    $r = (new Bot(engineCfg(['engine' => 'pmm', 'pmm_refresh_sec' => 5]), $db, $ex, $t0 + 2000))->tick();
    T::eq('ok', $r['status'], 'pmm refresh tick ok', $r['summary']);
    T::strContains($r['summary'], 'refreshed=', 'the tick reports the refresh');
    $old = $bidCid !== '' ? $db->engineOrder($bidCid) : null;
    T::ok($old !== null && (string) $old['status'] === 'CANCELED', 'the stale bid was cancelled');
    $stillBid = false;
    foreach ($db->openEngineOrders('SOLUSDT') as $o) {
        if ((string) $o['side'] === 'BUY') {
            $stillBid = true;
            T::ok((string) $o['client_id'] !== $bidCid, 'the stale bid was replaced by a fresh order');
        }
    }
    T::ok($stillBid, 'pmm keeps a bid on the book after the refresh');

    // ---- inventory skew: above pmm_max_base_pct the engine stops bidding
    $db2 = freshDb('engine-pmm-max');
    $md2 = engineMd($t0);
    $ex2 = new FakePaperExchange($md2, 0.1, 20.0);
    $ex2->setBalance('SOL', 1.0);                    // 130 of base vs 20 quote ⇒ basePct ≈ 86.7 % > 80 %
    $r = (new Bot(engineCfg(['engine' => 'pmm']), $db2, $ex2, $t0))->tick();
    T::eq('ok', $r['status'], 'pmm max-inventory tick ok', $r['summary']);
    T::strContains($r['summary'], 'not bidding', 'the tick says why it is not bidding');
    $sides = [];
    foreach ($db2->openEngineOrders('SOLUSDT') as $o) {
        $sides[] = (string) $o['side'];
    }
    T::eq(['SELL'], $sides, 'above pmm_max_base_pct only the ask is quoted');

    // ---- inventory below the exchange filters: no ask, whatever the skew says
    $db3 = freshDb('engine-pmm-dust');
    $md3 = engineMd($t0);
    $ex3 = new FakePaperExchange($md3, 0.1, 10.0);
    $ex3->setBalance('SOL', 0.03);                   // 3.9 USDT of base: over the min ratio, under minNotional 5
    $r = (new Bot(engineCfg(['engine' => 'pmm']), $db3, $ex3, $t0))->tick();
    T::eq('ok', $r['status'], 'pmm dust-inventory tick ok', $r['summary']);
    T::strContains($r['summary'], 'below the filters', 'the tick says the inventory is below the filters');
    $sides = [];
    foreach ($db3->openEngineOrders('SOLUSDT') as $o) {
        $sides[] = (string) $o['side'];
    }
    T::eq(['BUY'], $sides, 'an inventory below the filters is never offered');
});

T::group('engine-guard', ['Bot', 'EngineGrid', 'FakePaperExchange', 'FakeMarketData', 'Risk'], static function (): void {
    $t0 = FakeMarketData::SERVER_TIME_MS;

    // ---- live mode without allow_live_engines places nothing at all
    foreach (['grid', 'pmm'] as $engine) {
        $db = freshDb('engine-guard-live-' . $engine);
        $md = engineMd($t0);
        $ex = new FakePaperExchange($md, 0.1, 200.0);
        $ex->setMode('live');
        $cfg = engineCfg(['engine' => $engine, 'mode' => 'live', 'api_key' => 'k', 'api_secret' => 's']);
        $r   = (new Bot($cfg, $db, $ex, $t0))->tick();
        T::eq('ok', $r['status'], $engine . ' live tick still completes', $r['summary']);
        T::eq('engine_live_blocked', (string) $db->getState('no_trade_reason', ''), $engine . ' reports engine_live_blocked');
        T::eq(0, $ex->limitCalls, $engine . ' sent zero orders to the exchange in live mode');
        T::eq(0, countRows($db, 'SELECT COUNT(*) FROM engine_orders'), $engine . ' wrote no engine order in live mode');
        T::eq(0, $ex->openOrderCount(), $engine . ' left nothing on the book in live mode');
        // the same config with the flag on does quote
        $db2 = freshDb('engine-guard-allow-' . $engine);
        $md2 = engineMd($t0);
        $ex2 = new FakePaperExchange($md2, 0.1, 200.0);
        $ex2->setMode('live');
        $ex2->setBalance('SOL', 1.0);
        (new Bot(engineCfg(['engine' => $engine, 'mode' => 'live', 'api_key' => 'k', 'api_secret' => 's', 'allow_live_engines' => true]), $db2, $ex2, $t0))->tick();
        T::ok($ex2->limitCalls > 0, $engine . ' quotes in live mode once allow_live_engines is on');
    }

    // ---- the equity floor cancels every resting order and halts
    $db = freshDb('engine-guard-floor');
    $md = engineMd($t0);
    $ex = new FakePaperExchange($md, 0.1, 200.0);
    for ($i = 0; $i < 2; $i++) {
        (new Bot(engineCfg(), $db, $ex, $t0 + $i * 60000))->tick();
    }
    T::eq(2, $ex->openOrderCount('SOLUSDT'), 'two rungs rest before the kill switch');
    $r = (new Bot(engineCfg(['equity_floor_usdt' => 500.0]), $db, $ex, $t0 + 2 * 60000))->tick();
    T::eq('halted', $r['status'], 'the equity floor halts the tick', $r['summary']);
    T::eq('1', (string) $db->getState('halted', '0'), 'the bot is halted');
    T::eq('equity_floor', (string) $db->getState('halt_reason', ''), 'halt_reason is equity_floor');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'the kill switch cancelled every resting order');
    T::eq(0, count($db->openEngineOrders('SOLUSDT')), 'no live engine rows are left after the halt');

    // ---- a daily-cap pause takes the ladder off the book too
    $db = freshDb('engine-guard-cap');
    $md = engineMd($t0);
    $ex = new FakePaperExchange($md, 0.1, 200.0);
    for ($i = 0; $i < 2; $i++) {
        (new Bot(engineCfg(), $db, $ex, $t0 + $i * 60000))->tick();
    }
    T::eq(2, $ex->openOrderCount('SOLUSDT'), 'two rungs rest before the cap');
    // −5 USDT today is over the 2 % daily cap of a 200 USDT account and under the 5 % weekly one
    closedPosition($db, -5.0, ['closed_at' => Util::nowIso(), 'opened_at' => Util::nowIso(time() - 600)]);
    $r = (new Bot(engineCfg(), $db, $ex, $t0 + 2 * 60000))->tick();
    T::eq('ok', $r['status'], 'the capped tick completes', $r['summary']);
    T::eq('daily_cap', (string) $db->getState('no_trade_reason', ''), 'the tick reports daily_cap');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'the daily cap cancelled every resting order');
    T::eq(0, count($db->openEngineOrders('SOLUSDT')), 'no live engine rows are left while the cap holds');
});

/* ------------------------------------------------------- engine-demo-only
 * The demo-only guarantee, end to end (DESIGN-ENGINES.md §1). With mode=live,
 * engine=grid and allow_live_engines=false the bot must not place a SINGLE order —
 * not through the cron tick, and not through any of the panel actions of §10
 * (cancel_order, cancel_all, reanchor_grid, flatten_inventory, run_tick).
 *
 * The state is deliberately built FIRST with the flag on, so a ladder really rests and
 * a lot is really held when the flag is switched off. A guard tested over empty state
 * proves nothing: every action would be a no-op anyway. Cancelling is not placing —
 * an action is allowed to take orders OFF the book, and the last assertion is that the
 * blocked run ends with an empty book, untouched inventory, and all three order
 * counters (limit, market buy, market sell) still at zero.
 */
T::group('engine-demo-only', ['Bot', 'EngineGrid', 'EngineOrders', 'FakePaperExchange'], static function (): void {
    $t0 = FakeMarketData::SERVER_TIME_MS;
    $db = freshDb('engine-demo-only');
    $md = engineMd($t0);
    $ex = new FakePaperExchange($md, 0.1, 200.0);
    $ex->setMode('live');
    $info = FakeMarketData::infoRow('SOLUSDT');
    $keys = ['engine' => 'grid', 'mode' => 'live', 'api_key' => 'k', 'api_secret' => 's'];

    // ---- setup: three rungs rest and one of them fills into a lot, all with the flag ON
    $allowed = engineCfg(array_merge($keys, ['allow_live_engines' => true]));
    for ($i = 0; $i < 3; $i++) {
        (new Bot($allowed, $db, $ex, $t0 + $i * 60000))->tick();
    }
    $md->setPrice('SOLUSDT', 129.15, 129.20);
    (new Bot($allowed, $db, $ex, $t0 + 3 * 60000))->tick();
    $lotsBefore = $db->openLots('SOLUSDT', 'live', 'grid');
    $invBefore  = 0.0;
    foreach ($lotsBefore as $l) {
        $invBefore += (float) $l['remaining'];
    }
    T::ok($invBefore > 0.0, 'setup: the allowed engine really holds inventory', T::dump($invBefore));
    T::ok($ex->openOrderCount('SOLUSDT') > 0, 'setup: the allowed engine really has orders resting');
    T::ok($ex->limitCalls > 0, 'setup: the allowed engine really placed orders');
    // free + locked: the resting grid_sell holds part of the base, and cancelling it (which a
    // blocked action IS allowed to do) moves base between the two without selling anything
    $baseBefore = $ex->free('SOL') + $ex->locked('SOL');

    // ---- flip the flag off; from here NOTHING may be placed by any path
    $blocked = engineCfg(array_merge($keys, ['allow_live_engines' => false]));
    $ex->limitCalls      = 0;
    $ex->marketBuyCalls  = 0;
    $ex->marketSellCalls = 0;

    // panel action "Re-anchor grid": moves the anchor, must not quote against it
    (new Bot($blocked, $db, $ex, $t0 + 4 * 60000))->reanchorGrid();
    T::eq(0, $ex->limitCalls, 'blocked: reanchor_grid placed no limit order');
    T::eq(0, $ex->marketBuyCalls + $ex->marketSellCalls, 'blocked: reanchor_grid placed no market order');

    // panel action "Cancel" on one resting order: allowed (it only takes risk off)
    $live = $db->openEngineOrders('SOLUSDT', 'live');
    T::ok(count($live) > 0, 'blocked: the ladder from the allowed run is still on the book');
    if ($live !== []) {
        $cid  = (string) $live[0]['client_id'];
        $cfgO = array_merge($blocked, ['engine_symbol' => 'SOLUSDT']);
        $ok   = (new EngineOrders($cfgO, $db, $ex, $info, $t0 + 4 * 60000))->cancel($cid);
        T::ok($ok, 'blocked: cancel_order still takes an order off the book');
        $after = $db->engineOrder($cid);
        T::eq('CANCELED', $after === null ? '' : strtoupper((string) $after['status']), 'blocked: the cancelled row is CANCELED');
    }
    T::eq(0, $ex->limitCalls, 'blocked: cancel_order placed no limit order');

    // panel action "Flatten inventory": the one action that ends in a real market SELL
    $flat = (new Bot($blocked, $db, $ex, $t0 + 5 * 60000))->flattenInventory();
    T::ok(empty($flat['ok']), 'blocked: flatten_inventory reports that it sold nothing');
    T::strContains((string) $flat['message'], 'blocked in live mode', 'blocked: flatten_inventory says why');
    T::eq(0, $ex->marketSellCalls, 'blocked: flatten_inventory sent no market SELL');
    T::near($baseBefore, $ex->free('SOL') + $ex->locked('SOL'), 1e-12, 'blocked: the base balance is untouched');
    $invAfter = 0.0;
    foreach ($db->openLots('SOLUSDT', 'live', 'grid') as $l) {
        $invAfter += (float) $l['remaining'];
    }
    T::near($invBefore, $invAfter, 1e-12, 'blocked: the inventory lots are untouched');

    // panel action "Cancel all orders"
    (new Bot($blocked, $db, $ex, $t0 + 6 * 60000))->cancelAllEngineOrders('panel_cancel_all');
    T::eq(0, $ex->openOrderCount('SOLUSDT'), 'blocked: cancel_all cleared the exchange book');
    T::eq(0, $ex->limitCalls, 'blocked: cancel_all placed no limit order');

    // panel action "Run tick" / the cron tick itself
    $r = (new Bot($blocked, $db, $ex, $t0 + 7 * 60000))->tick();
    T::eq('ok', $r['status'], 'blocked: the tick still completes', $r['summary']);
    T::eq('engine_live_blocked', (string) $db->getState('no_trade_reason', ''), 'blocked: the tick reports engine_live_blocked');

    // ---- the whole blocked run, summed up
    T::eq(0, $ex->limitCalls, 'blocked: zero limit orders across every path');
    T::eq(0, $ex->marketBuyCalls, 'blocked: zero market BUYs across every path');
    T::eq(0, $ex->marketSellCalls, 'blocked: zero market SELLs across every path');
    T::eq(0, $ex->openOrderCount(), 'blocked: nothing is left resting on the exchange');
    T::eq(0, count($db->openEngineOrders('SOLUSDT', 'live')), 'blocked: no live engine row is left');
    $invEnd = 0.0;
    foreach ($db->openLots('SOLUSDT', 'live', 'grid') as $l) {
        $invEnd += (float) $l['remaining'];
    }
    T::near($invBefore, $invEnd, 1e-12, 'blocked: the inventory survived every action');

    // ---- and the guard, not an empty state, is what stopped it: flip the flag back on
    $flat2 = (new Bot($allowed, $db, $ex, $t0 + 8 * 60000))->flattenInventory();
    T::ok(!empty($flat2['ok']), 'allowed: flatten_inventory sells the same inventory', (string) $flat2['message']);
    T::eq(1, $ex->marketSellCalls, 'allowed: exactly one market SELL was sent');
    // the sold quantity is the inventory floored to stepSize (0.001 on SOLUSDT), so it is the
    // whole inventory bar at most one step of dust
    $sold = (float) $flat2['qty'];
    T::ok($sold > 0.0 && $sold <= $invBefore + 1e-12 && $invBefore - $sold < 0.001,
        'allowed: the whole inventory was sold bar sub-step dust', T::dump(['inv' => $invBefore, 'sold' => $sold]));
    $invLeft = 0.0;
    foreach ($db->openLots('SOLUSDT', 'live', 'grid') as $l) {
        $invLeft += (float) $l['remaining'];
    }
    T::ok($invLeft < 0.001, 'allowed: the flatten consumed the lots down to sub-step dust', T::dump($invLeft));

    // ---- pmm is guarded by the very same rule
    $db2 = freshDb('engine-demo-only-pmm');
    $md2 = engineMd($t0);
    $ex2 = new FakePaperExchange($md2, 0.1, 200.0);
    $ex2->setMode('live');
    $ex2->setBalance('SOL', 1.0);
    $pmm = engineCfg(array_merge($keys, ['engine' => 'pmm', 'allow_live_engines' => false]));
    (new Bot($pmm, $db2, $ex2, $t0))->tick();
    (new Bot($pmm, $db2, $ex2, $t0 + 60000))->flattenInventory();
    T::eq(0, $ex2->limitCalls, 'blocked: pmm placed no limit order');
    T::eq(0, $ex2->marketSellCalls, 'blocked: pmm flatten sent no market SELL');
    T::eq(0, countRows($db2, 'SELECT COUNT(*) FROM engine_orders'), 'blocked: pmm wrote no engine order at all');
});

/* ------------------------------------------------------------ paper-limit
 * The shipped paper exchange, not the scriptable double. grid/pmm refuse to run in
 * live mode unless allow_live_engines is set (DESIGN-ENGINES.md §1), so PaperExchange
 * IS the engine's normal home and its limit surface (§11) has to hold up end to end:
 * rest, lock funds, survive the gap between cron processes, fill when the book crosses,
 * and book a lot -> cycle through the very same EngineOrders the live path uses.
 */
T::group('paper-limit', ['PaperExchange', 'EngineOrders', 'EngineGrid', 'FakeMarketData', 'Db'], static function (): void {
    $db   = freshDb('paper-limit');
    $md   = engineMd(FakeMarketData::SERVER_TIME_MS);
    $path = $db->pdo();
    $ex   = new PaperExchange($md, $db, 0.1, 200.0, 'USDT');
    $info = FakeMarketData::infoRow('SOLUSDT');
    $cfg  = engineCfg();
    $t0   = FakeMarketData::SERVER_TIME_MS;

    T::ok(method_exists('PaperExchange', 'limitOrder'), 'PaperExchange has the limit-order surface');
    T::ok(method_exists('PaperExchange', 'cancelOrder'), 'PaperExchange can cancel one order');
    T::ok(method_exists('PaperExchange', 'cancelAllOrders'), 'PaperExchange can cancel every order');
    T::ok(method_exists('PaperExchange', 'openOrders'), 'PaperExchange reports its open orders');
    T::ok(method_exists('LiveExchange', 'limitOrder'), 'LiveExchange has the limit-order surface too');

    $o = new EngineOrders($cfg, $db, $ex, $info, $t0);

    // ---- a rung rests, and locks exactly its notional
    $row = $o->place('BUY', 129.22, 6.5, 'grid_buy', 1);
    T::ok($row !== null, 'the engine places a resting buy on the paper exchange');
    if ($row === null) {
        return;
    }
    T::eq('NEW', (string) $row['status'], 'the paper rung is NEW');
    T::eq(1, count($ex->openOrders('SOLUSDT')), 'the rung rests on the paper book');
    T::near(6.461, $ex->lockedOf('USDT'), 1e-9, 'the resting buy locks its quote');
    $acct = $ex->account();
    T::near(6.461, (float) $acct['balances']['USDT']['locked'], 1e-9, 'account() reports the locked quote');
    T::near(193.539, (float) $acct['balances']['USDT']['free'], 1e-9, 'the locked quote left the free balance');
    T::near(200.0, (float) $acct['balances']['USDT']['free'] + (float) $acct['balances']['USDT']['locked'], 1e-9,
        'free + locked still equals the starting balance');

    // ---- a post-only rung that would cross is rejected the way Binance rejects it (§3)
    $rejected = false;
    try {
        $o2 = new EngineOrders($cfg, $db, $ex, $info, $t0);
        $o2->place('BUY', 131.00, 6.5, 'grid_buy', 9);   // above the ask: would take
    } catch (Throwable $e) {
        $rejected = true;
    }
    T::eq(1, count($ex->openOrders('SOLUSDT')), 'a crossing post-only rung never joins the book');
    T::ok(!$rejected, 'a post-only reject is a skipped quote, not an exception');

    // ---- the ladder survives the gap between two cron processes
    $ex2 = new PaperExchange($md, $db, 0.1, 200.0, 'USDT');
    T::eq(1, count($ex2->openOrders('SOLUSDT')), 'a fresh process still sees the resting rung');
    T::near(6.461, $ex2->lockedOf('USDT'), 1e-9, 'and still accounts for the funds it locks');

    // ---- the book crosses the rung: it fills, and the fill books a lot exactly once
    $md->setPrice('SOLUSDT', 129.19, 129.21);
    $o3 = new EngineOrders($cfg, $db, $ex2, $info, $t0);
    $s  = $o3->sync('SOLUSDT');
    T::eq(1, count($s['filled']), 'the crossed rung fills and is booked');
    T::eq(0, count($ex2->openOrders('SOLUSDT')), 'the filled rung has left the book');
    $lots = $db->openLots('SOLUSDT');
    T::eq(1, count($lots), 'the buy fill produced one lot');
    if (count($lots) > 0) {
        T::eq(1, (int) $lots[0]['level'], 'the lot remembers its rung');
        // the base-asset commission raises the cost basis above the rung price (DESIGN.md §6)
        T::ok((float) $lots[0]['price'] > 129.22, 'the lot basis is fee-inclusive');
        T::near(129.3493, (float) $lots[0]['price'], 1e-3, 'the fee-inclusive basis is one commission above the rung');
    }
    $again = $o3->sync('SOLUSDT');
    T::eq(0, count($again['filled']), 'a second sync books nothing again');
    T::eq(1, count($db->openLots('SOLUSDT')), 'and creates no second lot');

    // ---- the grid answers the fill with a sell one rung up, which fills into a cycle
    $g = new EngineGrid($cfg, $db, $ex2, $o3, $info);
    $b = $md->bookTicker('SOLUSDT');
    $g->tick((float) $b['bid'], (float) $b['ask']);
    $sell = null;
    foreach ($db->openEngineOrders('SOLUSDT') as $eo) {
        if ((string) $eo['side'] === 'SELL') {
            $sell = $eo;
        }
    }
    T::ok($sell !== null, 'the grid answers the filled rung with a resting sell');
    if ($sell !== null) {
        T::ok((float) $sell['price'] > (float) $b['ask'], 'the sell rests above the ask');
    }
    $md->setPrice('SOLUSDT', 131.99, 132.01);
    $o3->sync('SOLUSDT');
    $cycles = $db->cycles(10);
    T::eq(1, count($cycles), 'the round trip wrote one cycle');
    if (count($cycles) > 0) {
        $c   = $cycles[0];
        $qty = (float) $c['qty'];
        $net = $qty * (float) $c['sell_price'] * (1.0 - 0.001) - $qty * (float) $c['buy_price'];
        T::near($net, (float) $c['pnl_usdt'], 1e-9, 'cycle pnl is proceeds net of fees minus the lot cost');
        T::ok((float) $c['pnl_usdt'] > 0.0, 'a rung round trip at 0.60 % spacing clears the 0.2 % round trip');
        // the sell is priced a full spacing above the FEE-INCLUSIVE basis and rounded up to the tick,
        // so the gross step is a spacing plus at most one tick, and the margin left after the sell-side
        // fee is the 'spacing - fees' DESIGN-ENGINES.md §11 asks for.
        $rel = ((float) $c['sell_price'] / (float) $c['buy_price'] - 1.0) * 100.0;
        $tickPct = 0.01 / (float) $c['buy_price'] * 100.0;
        T::ok($rel >= 0.60 - 1e-9 && $rel <= 0.60 + $tickPct + 1e-9, 'the sell is one spacing (plus at most a tick) above the basis');
        $netPct = (float) $c['pnl_usdt'] / ($qty * (float) $c['buy_price']) * 100.0;
        T::ok($netPct >= 0.60 - 2 * 0.1 && $netPct <= 0.60, 'the realised margin sits between spacing - 2 x fee and spacing');
    }
    // the same tick that answered the fill also laddered a fresh buy rung (one new order per side
    // per tick), so clear the book deliberately rather than assuming it emptied itself.
    $o3->cancelAll('SOLUSDT', 'reset');
    T::eq(0, count($ex2->openOrders('SOLUSDT')), 'cancelAll empties the paper book');
    $wallet = $ex2->account();
    T::near(0.0, (float) ($wallet['balances']['USDT']['locked'] ?? 0.0), 1e-9, 'nothing stays locked once the book is empty');
    T::near(0.0, $ex2->lockedOf('USDT'), 1e-9, 'and the derived lock agrees');

    // ---- cancel paths: one order, then the whole book
    $o4  = new EngineOrders($cfg, $db, $ex2, $info, $t0);
    $r1  = $o4->place('BUY', 129.22, 6.5, 'grid_buy', 1);
    $r2  = $o4->place('BUY', 128.44, 6.5, 'grid_buy', 2);
    T::eq(2, count($ex2->openOrders('SOLUSDT')), 'two rungs rest');
    if ($r1 !== null) {
        T::ok($o4->cancel((string) $r1['client_id']), 'a single rung cancels');
        T::eq(1, count($ex2->openOrders('SOLUSDT')), 'and leaves the book');
        T::near(6.422, $ex2->lockedOf('USDT'), 1e-3, 'its locked quote came back');
    }
    T::eq(1, $o4->cancelAll('SOLUSDT', 'test'), 'cancelAll clears the rest of the book');
    T::eq(0, count($ex2->openOrders('SOLUSDT')), 'the paper book is empty');
    T::near(0.0, $ex2->lockedOf('USDT'), 1e-9, 'and nothing is left locked');

    // ---- cancelling a vanished order is the -2011 soft success, not a failure
    $threw = false;
    try {
        $ex2->cancelOrder('SOLUSDT', 'no-such-order');
    } catch (BinanceException $e) {
        $threw = $e->binanceCode === -2011;
    }
    T::ok($threw, 'cancelling an unknown order answers -2011 like Binance');

    T::ok(strpos((string) $db->getState('paper_orders', ''), 'SOLUSDT') !== false,
        'the simulated book is persisted in state for the next cron process');
});

T::group('engine-fees', ['Risk'], static function (): void {
    $db   = freshDb('engine-fees');
    $base = engineCfg();

    // spacing must clear the round trip: 2 × fee_pct + 0.1 = 0.30 % at VIP0
    list($cfg, $errors) = Risk::validateConfig(['engine' => 'grid', 'grid_spacing_pct' => '0.25', 'fee_pct' => '0.1'], $base);
    $hit = '';
    foreach ($errors as $e) {
        if (strpos((string) $e, 'grid_spacing_pct') !== false) {
            $hit = (string) $e;
        }
    }
    T::ok($hit !== '', 'a spacing below the fee floor is rejected', T::dump($errors));
    T::strContains($hit, '0.3', 'the error names the 2 × fee + 0.1 floor');
    T::ok((float) $cfg['grid_spacing_pct'] >= 0.3 - 1e-9, 'the rejected spacing is not stored');

    // exactly on the floor is accepted, and so is the default
    list($cfg2, $errors2) = Risk::validateConfig(['engine' => 'grid', 'grid_spacing_pct' => '0.30', 'fee_pct' => '0.1'], $base);
    T::eq(0, count(array_filter($errors2, static function ($e) { return strpos((string) $e, 'grid_spacing_pct') !== false; })),
        'a spacing exactly on the floor is accepted');
    T::near(0.30, (float) $cfg2['grid_spacing_pct'], 1e-9, 'the accepted spacing is stored');
    list($cfg3, $errors3) = Risk::validateConfig(['engine' => 'grid', 'grid_spacing_pct' => '0.60', 'fee_pct' => '0.1'], $base);
    T::eq(0, count(array_filter($errors3, static function ($e) { return strpos((string) $e, 'grid_spacing_pct') !== false; })),
        'the default 0.60 % spacing is accepted');

    // a higher fee raises the floor with it
    list($cfg4, $errors4) = Risk::validateConfig(['engine' => 'grid', 'grid_spacing_pct' => '0.60', 'fee_pct' => '0.5'], $base);
    T::ok(count(array_filter($errors4, static function ($e) { return strpos((string) $e, 'grid_spacing_pct') !== false; })) === 1,
        'at 0.5 % fees a 0.60 % spacing no longer clears the round trip');

    // pmm must carry the expected-loss warning wherever it is selected (DESIGN-ENGINES.md §8.6)
    $v = Risk::validateConfig(['engine' => 'pmm'], $base);
    $warnings = isset($v[2]) && is_array($v[2]) ? $v[2] : [];
    $lose = false;
    foreach ($warnings as $w) {
        if (stripos((string) $w, 'lose') !== false) {
            $lose = true;
        }
    }
    T::ok($lose, 'selecting pmm warns that it is expected to lose money at VIP0 fees', T::dump($warnings));

    // grid/pmm in live mode without allow_live_engines saves, but warns
    $v = Risk::validateConfig(['engine' => 'grid', 'mode' => 'live', 'api_key' => 'k', 'api_secret' => 's'],
        array_merge($base, ['api_key' => 'k', 'api_secret' => 's']));
    $warned = false;
    foreach ((isset($v[2]) && is_array($v[2]) ? $v[2] : []) as $w) {
        if (strpos((string) $w, 'allow_live_engines') !== false) {
            $warned = true;
        }
    }
    T::eq('grid', (string) $v[0]['engine'], 'grid in live mode is still saved');
    T::ok($warned, 'grid in live mode without allow_live_engines is flagged', T::dump(isset($v[2]) ? $v[2] : []));
    T::ok($db instanceof Db, 'engine-fees ran against its own database');
});

T::group('panel-engine', ['Panel', 'Db', 'Util'], static function (): void {
    $db  = freshDb('panel-engine');
    $cfg = engineCfg();

    // state the tick would have written
    $db->setState('symbol_info', ['SOLUSDT' => FakeMarketData::infoRow('SOLUSDT')]);
    $db->setState('symbol_metrics', ['SOLUSDT' => ['price' => 130.0]]);
    $db->setState('grid_anchor', 130.0);
    $db->setState('grid_symbol', 'SOLUSDT');
    $db->setState('grid_anchor_at', Util::nowIso(time() - 600));
    $hostile = 'b-SOLUSDT-1"><script>alert(1)</script>';
    $db->insertEngineOrder([
        'client_id' => $hostile, 'order_id' => '1', 'mode' => 'paper', 'engine' => 'grid',
        'symbol' => 'SOLUSDT', 'side' => 'BUY', 'status' => 'NEW', 'price' => 129.22, 'qty' => 0.05,
        'quote' => 6.461, 'filled_qty' => 0.0, 'filled_quote' => 0.0, 'fee_usdt' => 0.0,
        'level' => 1, 'purpose' => 'grid_buy', 'created_at' => Util::nowIso(time() - 120),
    ]);
    $db->insertLot(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'SOLUSDT', 'qty' => 0.04995,
        'remaining' => 0.04995, 'price' => 129.349, 'fee_usdt' => 0.0065, 'level' => 1,
        'client_id' => 'x', 'created_at' => Util::nowIso(time() - 300)]);
    $db->insertCycle(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'SOLUSDT', 'level' => 1,
        'qty' => 0.049, 'buy_price' => 129.349, 'sell_price' => 130.13, 'gross_usdt' => 6.376,
        'fee_usdt' => 0.0127, 'pnl_usdt' => 0.0319, 'opened_at' => Util::nowIso(time() - 900),
        'closed_at' => Util::nowIso(time() - 60)]);

    $s = Panel::status($cfg, $db);
    T::ok(!empty($s['show']['engine']), 'status reveals the engine block for grid');
    T::ok(!empty($s['show']['grid_engine']), 'status marks the grid sub-block');
    T::ok(empty($s['show']['pmm_engine']), 'the pmm sub-block stays hidden for grid');
    T::ok(empty($s['show']['signal_engine']), 'the signal marker is off for grid');
    T::eq('GRID', (string) $s['text']['eng_name'], 'engine name');
    T::eq('SOLUSDT', (string) $s['text']['eng_symbol'], 'engine symbol');
    T::eq('1 / 12', (string) $s['text']['eng_orders'], 'live orders / cap');
    T::strContains((string) $s['text']['eng_anchor'], '130.000000', 'the anchor is shown');
    T::strContains((string) $s['text']['eng_range_up'], '135.200000', 'the upper range edge is shown');
    T::strContains((string) $s['text']['eng_range_down'], '122.200000', 'the lower range edge is shown');
    T::strContains((string) $s['text']['eng_inventory'], '0.04995', 'the inventory quantity is shown');
    T::strContains((string) $s['text']['eng_inv_cost'], '6.4610', 'the inventory cost is shown');
    T::strContains((string) $s['text']['eng_unreal'], '+0.03', 'the unrealised at bid is shown');
    T::eq('1', (string) $s['text']['eng_cycles_today'], 'cycles today');
    T::strContains((string) $s['text']['eng_win_rate'], '1W/0L', 'cycle win rate');
    T::near(0.0319, (float) $s['engine']['pnl'], 1e-9, 'the engine payload carries the realised pnl');
    T::eq(1, (int) $s['engine']['open_orders'], 'the engine payload counts the resting orders');
    T::near(0.04995, (float) $s['engine']['inventory_qty'], 1e-12, 'the engine payload carries the inventory');

    // tables and the per-order Cancel button
    T::eq(1, count($s['tables']['engine_orders']['rows']), 'one open-order row');
    T::eq(8, (int) $s['tables']['engine_orders']['cols'], 'the open-order table has 8 columns');
    $cell = $s['tables']['engine_orders']['rows'][0][7];
    T::eq('cancel_order', (string) $cell['btn']['action'], 'the row carries a cancel_order button');
    T::eq($hostile, (string) $cell['btn']['fields']['client_id'], 'the button carries the client id');
    T::eq(1, count($s['tables']['cycles']['rows']), 'one cycle row');
    T::eq(6, (int) $s['tables']['cycles']['cols'], 'the cycles table has 6 columns');

    // every dynamic value is escaped on the way into HTML
    $html = Panel::tableRows($s['tables']['engine_orders']);
    T::ok(strpos($html, '<script>') === false, 'a hostile client id is escaped in the rendered row');
    T::strContains($html, 'name="client_id"', 'the rendered row posts the client id');
    T::strContains($html, 'name="action" value="cancel_order"', 'the rendered row posts cancel_order');

    // signal keeps the engine block hidden and the signal cards on
    $s2 = Panel::status(engineCfg(['engine' => 'signal']), $db);
    T::ok(empty($s2['show']['engine']), 'the engine block is hidden for the signal engine');
    T::ok(!empty($s2['show']['signal_engine']), 'the signal marker is on for the signal engine');
    T::ok(isset($s2['tables']['symbols']) && isset($s2['tables']['closed']), 'the signal tables are still published');

    // live mode without allow_live_engines is flagged in the payload
    $s3 = Panel::status(engineCfg(['mode' => 'live']), $db);
    T::ok(!empty($s3['show']['engine_live_blocked']), 'live mode without allow_live_engines is flagged');
    T::strContains((string) $s3['text']['eng_state'], 'BLOCKED', 'the engine state says BLOCKED');
    $s4 = Panel::status(engineCfg(['mode' => 'live', 'allow_live_engines' => true]), $db);
    T::ok(empty($s4['show']['engine_live_blocked']), 'allow_live_engines clears the block flag');
});

/* ============================================================ portfolio (DESIGN-PORTFOLIO.md §8) */

/**
 * Three sleeves, one per method, on three DISTINCT symbols - the shape §1 calls the only safe
 * one, because two sleeves sharing a symbol would have one sell what the other bought.
 */
function pfSleeves(array $over = []): array
{
    return array_merge([
        'signal' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['SOLUSDT']],
        'grid'   => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['DOGEUSDT']],
        'pmm'    => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['XRPUSDT']],
    ], $over);
}

/**
 * The keys DESIGN-PORTFOLIO.md §2 ADDS, and nothing else. Layered on top of an existing config
 * this is exactly what an upgrade writes into config.json, which is what `portfolio-off` needs
 * to prove is inert while `portfolio_enabled` is false.
 */
function pfBlock(array $over = []): array
{
    return array_merge([
        'portfolio_enabled'       => false,
        'sleeves'                 => pfSleeves(),
        'sleeve_reserve_pct'      => 5.0,
        'sleeve_max_drawdown_pct' => 25.0,
        'scanner_enabled'         => true,
        'scanner_refresh_min'     => 60,
        'scanner_min_quote_vol'   => 5000000.0,
        'scanner_max_spread_pct'  => 0.06,
        'scanner_min_atr_pct'     => 0.5,
        'scanner_max_atr_pct'     => 4.0,
        'scanner_top_n'           => 10,
        'scanner_exclude'         => ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT'],
    ], $over);
}

/**
 * Portfolio config (DESIGN-PORTFOLIO.md §2). The account-wide survival caps are deliberately
 * wide open so the only thing that can stop a sleeve in these groups is the sleeve rule under
 * test; `equity_floor_usdt` is raised back in the kill-switch case. The scanner is off here:
 * it costs weight 80 and has its own group.
 */
function pfCfg(array $over = []): array
{
    return cfg(array_merge(pfBlock(['portfolio_enabled' => true, 'scanner_enabled' => false]), [
        'enabled'                 => true,
        'mode'                    => 'paper',
        'symbols'                 => ['SOLUSDT'],
        // the sleeves carry the engine; the single-engine keys stay out of the way
        'engine'                  => 'signal',
        'engine_symbol'           => '',
        'allow_live_engines'      => false,
        'post_only'               => true,
        'engine_max_orders'       => 12,
        'grid_levels'             => 3,
        'grid_spacing_pct'        => 0.60,
        'grid_order_usdt'         => 6.5,
        'grid_range_up_pct'       => 4.0,
        'grid_range_down_pct'     => 6.0,
        'grid_exit_liquidates'    => false,
        'pmm_spread_pct'          => 0.25,
        'pmm_order_usdt'          => 6.5,
        'pmm_refresh_sec'         => 864000,   // never age a quote out inside one test
        'pmm_target_base_pct'     => 50,
        'pmm_max_base_pct'        => 80,
        'paper_start_usdt'        => 200.0,
        'adaptive'                => false,
        'max_trades_per_day'      => 50,
        'max_orders_per_hour'     => 500,
        'daily_loss_cap_pct'      => 50.0,
        'weekly_loss_cap_pct'     => 90.0,
        'equity_floor_usdt'       => 1.0,
        'hwm_drawdown_pct'        => 90.0,
    ], $over));
}

/** Book for the three sleeve symbols; only SOLUSDT has candles (only the signal sleeve reads any). */
function pfMd(): FakeMarketData
{
    $md = new FakeMarketData([
        'SOLUSDT' => ['15m' => 'klines_15m_oversold', '1h' => 'klines_1h_uptrend'],
    ]);
    $md->setPrice('SOLUSDT', 129.75, 129.80);
    $md->setPrice('DOGEUSDT', 0.19990, 0.20010);
    $md->setPrice('XRPUSDT', 1.99900, 2.00100);
    return $md;
}

/** Distinct symbols an engine touched in `engine_orders`. */
function pfEngineSymbols(Db $db, string $engine): array
{
    $st = $db->pdo()->prepare('SELECT DISTINCT symbol FROM engine_orders WHERE engine = ? ORDER BY symbol');
    $st->execute([$engine]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $out[] = (string) $s;
    }
    return $out;
}

/** Distinct values of one column of one table, sorted (used to prove a sleeve stayed in its lane). */
function pfDistinct(Db $db, string $table, string $column): array
{
    $st = $db->pdo()->query('SELECT DISTINCT ' . $column . ' FROM ' . $table . ' ORDER BY ' . $column);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $out[] = (string) $v;
    }
    return $out;
}

T::group('sleeve-alloc', ['Sleeve', 'Db', 'Util'], static function (): void {
    $db  = freshDb('sleeve-alloc');
    $cfg = pfCfg(['sleeves' => [
        'signal' => ['enabled' => true,  'budget_usdt' => 100.0, 'symbols' => ['SOLUSDT', 'ETHUSDT']],
        'grid'   => ['enabled' => true,  'budget_usdt' => 50.0,  'symbols' => ['DOGEUSDT']],
        'pmm'    => ['enabled' => false, 'budget_usdt' => 10.0,  'symbols' => ['XRPUSDT']],
    ]]);
    $prices = ['SOLUSDT' => 130.0, 'ETHUSDT' => 3000.0, 'DOGEUSDT' => 0.21, 'XRPUSDT' => 2.0];
    // BNB belongs to no sleeve: it is UNATTRIBUTED and must never show up in a sleeve's numbers
    $wallet = static function (float $doge = 0.0, float $sol = 0.0, float $xrp = 0.0): array {
        return [
            'USDT' => ['free' => 100.0, 'locked' => 0.0],
            'BNB'  => ['free' => 2.0,   'locked' => 0.0],
            'DOGE' => ['free' => $doge, 'locked' => 0.0],
            'SOL'  => ['free' => $sol,  'locked' => 0.0],
            'XRP'  => ['free' => $xrp,  'locked' => 0.0],
        ];
    };

    // ---- an untouched sleeve has its whole budget available
    $s = Sleeve::state($cfg, $db, 'grid', $wallet(), $prices);
    T::eq('grid', (string) $s['engine'], 'state reports the engine');
    T::ok((bool) $s['enabled'], 'the grid sleeve is enabled');
    T::eq(['DOGEUSDT'], $s['symbols'], 'the sleeve carries its own symbols');
    T::near(50.0, (float) $s['budget'], 1e-9, 'budget');
    T::near(0.0, (float) $s['inventory_cost'], 1e-9, 'nothing held yet');
    T::near(0.0, (float) $s['reserved'], 1e-9, 'nothing reserved yet');
    T::near(50.0, (float) $s['available'], 1e-9, 'an untouched sleeve may commit its whole budget');
    T::near(50.0, (float) $s['equity'], 1e-9, 'equity = budget with no PnL');
    T::near(0.0, (float) $s['used_pct'], 1e-9, 'used 0 %');
    T::ok(!isset($s['inventory_qty']['BNBUSDT']), 'an unowned base is not in the sleeve inventory');

    // ---- inventory: an open lot is cost, the base balance is value
    $db->insertLot(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'qty' => 100.0,
        'remaining' => 100.0, 'price' => 0.20, 'fee_usdt' => 0.02, 'level' => 1,
        'client_id' => 'lot-1', 'created_at' => Util::nowIso(time() - 600)]);
    $s = Sleeve::state($cfg, $db, 'grid', $wallet(100.0), $prices);
    T::near(100.0, (float) $s['inventory_qty']['DOGEUSDT'], 1e-9, 'the owned base is attributed to the sleeve');
    T::near(21.0, (float) $s['inventory_value'], 1e-9, 'inventory valued at the price map');
    T::near(20.0, (float) $s['inventory_cost'], 1e-9, 'inventory cost is the open lots at their cost basis');
    T::near(1.0, (float) $s['unrealised'], 1e-9, 'unrealised = value - cost');
    T::near(30.0, (float) $s['available'], 1e-9, 'available shrinks by the inventory cost');
    T::near(51.0, (float) $s['equity'], 1e-9, 'equity = budget + realised + unrealised');

    // ---- a resting BUY reserves the quote it committed
    $db->insertEngineOrder(['client_id' => 'buy-1', 'order_id' => '1', 'mode' => 'paper', 'engine' => 'grid',
        'symbol' => 'DOGEUSDT', 'side' => 'BUY', 'status' => 'NEW', 'price' => 0.19880, 'qty' => 50.0,
        'quote' => 10.0, 'filled_qty' => 0.0, 'filled_quote' => 0.0, 'fee_usdt' => 0.0,
        'level' => 2, 'purpose' => 'grid_buy', 'created_at' => Util::nowIso(time() - 300)]);
    $s = Sleeve::state($cfg, $db, 'grid', $wallet(100.0), $prices);
    T::near(10.0, (float) $s['reserved'], 1e-9, 'a resting BUY reserves its quote');
    T::near(20.0, (float) $s['available'], 1e-9, 'available shrinks by inventory cost AND reserved quote');
    T::near(60.0, (float) $s['used_pct'], 1e-9, 'used % = (cost + reserved) / budget');

    // only the part still resting is reserved: what already filled has become inventory
    $db->updateEngineOrder('buy-1', ['status' => 'PARTIALLY_FILLED', 'filled_qty' => 20.0, 'filled_quote' => 4.0]);
    $s = Sleeve::state($cfg, $db, 'grid', $wallet(100.0), $prices);
    T::near(6.0, (float) $s['reserved'], 1e-9, 'a partially filled BUY only reserves what is still resting');
    T::near(24.0, (float) $s['available'], 1e-9, 'the filled part is not charged twice');

    // ---- realised PnL from a closed round trip feeds both equity and availability
    $db->insertCycle(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'level' => 1,
        'qty' => 50.0, 'buy_price' => 0.20, 'sell_price' => 0.2506, 'gross_usdt' => 12.53,
        'fee_usdt' => 0.02, 'pnl_usdt' => 2.5, 'opened_at' => Util::todayUtc() . 'T00:00:01Z',
        'closed_at' => Util::todayUtc() . 'T00:00:02Z']);
    $s = Sleeve::state($cfg, $db, 'grid', $wallet(100.0), $prices);
    T::eq(1, (int) $s['cycles'], 'the cycle is attributed by engine');
    T::near(2.5, (float) $s['realised'], 1e-9, 'realised = cycles pnl');
    T::near(2.5, (float) $s['pnl_today'], 1e-9, 'a cycle closed today counts in pnl_today');
    T::near(53.5, (float) $s['equity'], 1e-9, 'equity = budget + realised + unrealised');
    T::near(26.5, (float) $s['available'], 1e-9, 'realised profit is available to trade again');
    T::eq(1, (int) $s['wins'], 'a profitable cycle is a win');
    T::near(100.0, (float) $s['win_rate'], 1e-9, 'win rate');

    // ---- a SELL restores availability: the lot is consumed and the base leaves the wallet
    $db->consumeLots('DOGEUSDT', 100.0, 'paper', 'grid');
    $db->insertCycle(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'level' => 1,
        'qty' => 100.0, 'buy_price' => 0.20, 'sell_price' => 0.215, 'gross_usdt' => 21.5,
        'fee_usdt' => 0.02, 'pnl_usdt' => 1.5, 'opened_at' => Util::todayUtc() . 'T00:00:01Z',
        'closed_at' => Util::todayUtc() . 'T00:00:03Z']);
    $after = Sleeve::state($cfg, $db, 'grid', $wallet(0.0), $prices);
    T::near(0.0, (float) $after['inventory_cost'], 1e-9, 'the consumed lot is no longer inventory');
    T::near(0.0, (float) $after['inventory_value'], 1e-9, 'the sold base left the wallet');
    T::near(4.0, (float) $after['realised'], 1e-9, 'both cycles are realised');
    T::near(48.0, (float) $after['available'], 1e-9, 'selling gives the capital back to the sleeve');
    T::ok((float) $after['available'] > (float) $s['available'], 'a sell strictly increases available');
    T::near(54.0, (float) $after['equity'], 1e-9, 'equity after the round trips');

    // ---- available is clamped at zero, never negative
    $db->insertLot(['mode' => 'paper', 'engine' => 'pmm', 'symbol' => 'XRPUSDT', 'qty' => 25.0,
        'remaining' => 25.0, 'price' => 2.0, 'fee_usdt' => 0.0, 'level' => null,
        'client_id' => 'lot-x', 'created_at' => Util::nowIso(time() - 60)]);
    $halved = array_merge($prices, ['XRPUSDT' => 1.0]);   // the pair halved under the sleeve
    $p = Sleeve::state($cfg, $db, 'pmm', $wallet(0.0, 0.0, 25.0), $halved);
    T::ok(!(bool) $p['enabled'], 'a disabled sleeve still reports its state');
    T::near(50.0, (float) $p['inventory_cost'], 1e-9, 'the pmm lot is 50 USDT of cost against a 10 USDT budget');
    T::near(25.0, (float) $p['inventory_value'], 1e-9, 'and 25 USDT of value after the pair halved');
    T::near(0.0, (float) $p['available'], 1e-9, 'available is clamped at zero, never negative');
    T::near(500.0, (float) $p['used_pct'], 1e-9, 'used % may exceed 100 when a sleeve is over its budget');
    T::near(-15.0, (float) $p['equity'], 1e-9, 'equity may still be negative: it is budget + realised + unrealised');

    // ---- the signal sleeve: closed positions are realised, an open one is inventory cost
    closedPosition($db, 1.0, ['symbol' => 'SOLUSDT', 'closed_at' => Util::todayUtc() . 'T00:00:01Z']);
    closedPosition($db, -0.4, ['symbol' => 'ETHUSDT', 'closed_at' => Util::todayUtc() . 'T00:00:02Z']);
    $db->insertPosition([
        'mode' => 'paper', 'symbol' => 'SOLUSDT', 'status' => 'OPEN', 'qty' => 0.05, 'dust_qty' => 0.0,
        'entry_price' => 130.0, 'entry_eff' => 130.0, 'entry_quote' => 6.5, 'entry_fee_usdt' => 0.0065,
        'stop_price' => 129.09, 'take_profit_price' => 131.3, 'trail_high' => 130.0, 'trailing_armed' => 0,
        'score' => 80, 'entry_reason' => 'test', 'opened_at' => Util::nowIso(time() - 120),
    ]);
    $db->insertTrade(['position_id' => null, 'mode' => 'paper', 'symbol' => 'SOLUSDT', 'side' => 'BUY',
        'order_id' => '1', 'client_id' => 'b-1', 'qty' => 0.05, 'price' => 130.0, 'quote' => 6.5,
        'fee_usdt' => 0.0065, 'fee_asset' => 'SOL', 'raw' => '{}', 'created_at' => Util::nowIso(time() - 120)]);
    $sig = Sleeve::state($cfg, $db, 'signal', $wallet(0.0, 0.05), $prices);
    T::eq(2, (int) $sig['trades'], 'both closed positions are attributed by symbol');
    T::near(0.6, (float) $sig['realised'], 1e-9, 'realised = sum of the closed positions');
    T::eq(1, (int) $sig['wins'], 'one win');
    T::eq(1, (int) $sig['losses'], 'one loss');
    T::near(50.0, (float) $sig['win_rate'], 1e-9, 'win rate 50 %');
    T::near(6.5, (float) $sig['inventory_cost'], 1e-9, 'an OPEN position is inventory cost at its entry quote');
    T::near(6.5, (float) $sig['inventory_value'], 1e-9, 'the position base is valued from the price map');
    T::near(0.0, (float) $sig['unrealised'], 1e-9, 'flat position, no unrealised');
    T::near(0.0065, (float) $sig['fees'], 1e-9, 'fees come from the sleeve symbols trades');
    T::near(94.1, (float) $sig['available'], 1e-9, 'available = budget + realised - cost - reserved');
    T::near(100.6, (float) $sig['equity'], 1e-9, 'equity = budget + realised + unrealised');
    T::near(0.6, (float) $sig['pnl_today'], 1e-9, 'both positions closed today');
    T::ok(!isset($sig['inventory_qty']['DOGEUSDT']), 'the signal sleeve never sees the grid sleeve inventory');
    T::ok(!isset($sig['inventory_qty']['BNBUSDT']), 'unattributed BNB is excluded from the signal sleeve');

    // the identities the panel prints must hold for every sleeve
    foreach (['signal', 'grid', 'pmm'] as $eng) {
        $st = Sleeve::state($cfg, $db, $eng, $wallet(0.0, 0.05, 25.0), $prices);
        T::near((float) $st['budget'] + (float) $st['realised'] + (float) $st['unrealised'],
            (float) $st['equity'], 1e-9, $eng . ': equity = budget + realised + unrealised');
        T::near(max(0.0, (float) $st['budget'] + (float) $st['realised'] - (float) $st['inventory_cost'] - (float) $st['reserved']),
            (float) $st['available'], 1e-9, $eng . ': available = max(0, budget + realised - cost - reserved)');
        T::ok((float) $st['available'] >= 0.0, $eng . ': available is never negative');
    }
});

T::group('sleeve-exclusive', ['Sleeve', 'Risk', 'Db', 'Util'], static function (): void {
    $db  = freshDb('sleeve-exclusive');
    $cfg = pfCfg();

    // ---- ownerOf resolves by symbol, and only by symbol
    T::eq('signal', Sleeve::ownerOf($cfg, 'SOLUSDT'), 'ownerOf finds the signal sleeve');
    T::eq('grid', Sleeve::ownerOf($cfg, 'DOGEUSDT'), 'ownerOf finds the grid sleeve');
    T::eq('pmm', Sleeve::ownerOf($cfg, 'XRPUSDT'), 'ownerOf finds the pmm sleeve');
    T::eq('signal', Sleeve::ownerOf($cfg, 'solusdt'), 'ownerOf is case-insensitive');
    T::eq(null, Sleeve::ownerOf($cfg, 'BNBUSDT'), 'a symbol no sleeve owns is unattributed');
    T::eq(null, Sleeve::ownerOf($cfg, ''), 'the empty symbol owns nothing');
    T::ok(Sleeve::owns($cfg, 'grid', 'DOGEUSDT'), 'owns() agrees with ownerOf');
    T::ok(!Sleeve::owns($cfg, 'signal', 'DOGEUSDT'), 'a sleeve does not own another sleeve symbol');

    // a DISABLED sleeve still owns its symbols: its inventory must not silently change hands
    $off = pfCfg(['sleeves' => pfSleeves(['pmm' => ['enabled' => false, 'budget_usdt' => 1000.0, 'symbols' => ['XRPUSDT']]])]);
    T::eq('pmm', Sleeve::ownerOf($off, 'XRPUSDT'), 'a disabled sleeve still owns its symbols');
    T::eq(['SOLUSDT', 'DOGEUSDT'], Sleeve::allSymbols($off, true), 'allSymbols(enabledOnly) skips the disabled sleeve');
    T::eq(['SOLUSDT', 'DOGEUSDT', 'XRPUSDT'], Sleeve::allSymbols($off, false), 'allSymbols() covers every sleeve');

    // ---- overlap is a validation ERROR naming the symbol and BOTH sleeves
    $overlap = pfSleeves(['grid' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['SOLUSDT']]]);
    $res = Risk::validateConfig(['sleeves' => $overlap], $cfg);
    $errors = isset($res[1]) && is_array($res[1]) ? $res[1] : [];
    $joined = implode(' | ', $errors);
    T::ok($errors !== [], 'overlapping sleeve symbols are rejected');
    T::strContains($joined, 'SOLUSDT', 'the error names the shared symbol');
    T::strContains($joined, 'signal sleeve', 'the error names the first sleeve');
    T::strContains($joined, 'grid sleeve', 'the error names the second sleeve');
    T::strContains($joined, 'may belong to only one sleeve', 'the error explains the rule');
    $kept = isset($res[0]['sleeves']['grid']['symbols']) ? $res[0]['sleeves']['grid']['symbols'] : [];
    T::eq(['DOGEUSDT'], $kept, 'a rejected sleeve map is not applied: the current one is kept');

    // a legal reassignment IS applied
    $moved = pfSleeves(['grid' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['ADAUSDT']]]);
    $ok = Risk::validateConfig(['sleeves' => $moved], $cfg);
    T::eq(['ADAUSDT'], $ok[0]['sleeves']['grid']['symbols'], 'a non-overlapping reassignment is applied');
    T::eq('grid', Sleeve::ownerOf($ok[0], 'ADAUSDT'), 'ownerOf follows the new configuration');
    T::eq(null, Sleeve::ownerOf($ok[0], 'DOGEUSDT'), 'the old symbol is owned by nobody afterwards');

    // grid and pmm are single-symbol engines
    $two = Risk::validateConfig(['sleeves' => pfSleeves([
        'grid' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['DOGEUSDT', 'ADAUSDT']],
    ])], $cfg);
    T::strContains(implode(' | ', $two[1]), 'sleeve grid trades exactly one symbol', 'grid takes exactly one symbol');

    // symbols must be upper-case and end with the quote asset
    $bad = Risk::validateConfig(['sleeves' => pfSleeves([
        'signal' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['solusdt', 'SOLBTC']],
    ])], $cfg);
    T::strContains(implode(' | ', $bad[1]), 'must be uppercase and end with USDT', 'a non-quote symbol is rejected');

    // ---- a sleeve holding inventory must refuse a reassignment: this is the state the panel
    //      action reads (open lots, open/stuck positions, resting orders on the sleeve symbols)
    $held = static function (array $c, string $engine) use ($db): array {
        $out = [];
        foreach (Sleeve::symbols($c, $engine) as $sym) {
            foreach ($db->openLots($sym, 'paper') as $lot) {
                $out[] = $sym . ':lot';
            }
            foreach ($db->openEngineOrders($sym, 'paper') as $o) {
                $out[] = $sym . ':order';
            }
            if (countRows($db, "SELECT COUNT(*) FROM positions WHERE symbol = ? AND status IN ('OPEN','STUCK') AND mode = 'paper'", [$sym]) > 0) {
                $out[] = $sym . ':position';
            }
        }
        return $out;
    };
    T::eq([], $held($cfg, 'grid'), 'an empty sleeve holds nothing, so a reassignment is allowed');
    $db->insertLot(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'qty' => 100.0,
        'remaining' => 100.0, 'price' => 0.20, 'fee_usdt' => 0.02, 'level' => 1,
        'client_id' => 'lot-1', 'created_at' => Util::nowIso(time() - 600)]);
    T::eq(['DOGEUSDT:lot'], $held($cfg, 'grid'), 'a sleeve holding an open lot reports it, so the reassignment is refused');
    $s = Sleeve::state($cfg, $db, 'grid', ['DOGE' => ['free' => 100.0, 'locked' => 0.0]], ['DOGEUSDT' => 0.2]);
    T::near(20.0, (float) $s['inventory_cost'], 1e-9, 'the inventory a reassignment would strand is real, priced cost');
    $db->consumeLots('DOGEUSDT', 100.0, 'paper', 'grid');
    T::eq([], $held($cfg, 'grid'), 'once the inventory is gone the sleeve may be reassigned again');
});

T::group('sleeve-budget-cap', ['Bot', 'Sleeve', 'Risk', 'Db', 'FakePaperExchange', 'FakeMarketData'], static function (): void {
    $info = FakeMarketData::infoRow('SOLUSDT');

    // ---- the signal engine's entry size is clamped to what the sleeve may still commit
    $required = Risk::requiredSize($info, 130.0, 0.1);
    T::ok($required > 5.0 && $required < 6.5, 'SOLUSDT requires between 5 and 6.5 USDT', (string) $required);
    T::near(0.0, Risk::entrySize(pfCfg(), $info, 130.0, 5.0, 0.1), 1e-12, 'a 5 USDT budget cannot fund an entry');
    T::near(6.5, Risk::entrySize(pfCfg(), $info, 130.0, 200.0, 0.1), 1e-12, 'a full budget funds the configured size');

    // a signal sleeve whose available budget is under the required size never enters
    $db  = freshDb('sleeve-cap-signal');
    $md  = pfMd();
    $ex  = new FakePaperExchange($md, 0.1, 200.0);
    $cfg = pfCfg(['sleeves' => [
        'signal' => ['enabled' => true, 'budget_usdt' => 6.0, 'symbols' => ['SOLUSDT']],
    ]]);
    $t0 = FakeMarketData::SERVER_TIME_MS;
    $r  = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'capped signal sleeve tick ok', $r['summary']);
    T::eq(null, $db->openPosition(), 'a sleeve that cannot fund the required size does not enter');
    T::eq(0, $ex->marketBuyCalls, 'and it never sends a market buy');
    $sig = $db->latestSignals();
    T::ok(isset($sig['SOLUSDT']), 'the symbol was still evaluated');
    if (isset($sig['SOLUSDT'])) {
        T::contains($sig['SOLUSDT']['reasons_list'], 'size_unaffordable', 'the sleeve budget shows up as size_unaffordable');
    }
    T::near(200.0, $ex->free('USDT'), 1e-9, 'the wallet is untouched: the cap, not the wallet, blocked it');

    // the same wallet with a real budget does enter, so the block above was the sleeve budget
    $db2 = freshDb('sleeve-cap-signal-ok');
    $ex2 = new FakePaperExchange(pfMd(), 0.1, 200.0);
    $r2  = (new Bot(pfCfg(['sleeves' => [
        'signal' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['SOLUSDT']],
    ]]), $db2, $ex2, $t0))->tick();
    T::eq('ok', $r2['status'], 'funded signal sleeve tick ok', $r2['summary']);
    T::ok($db2->openPosition() !== null, 'the same wallet enters once the sleeve budget allows it');

    // ---- an engine cannot place an order that exceeds its sleeve's available budget
    $db3 = freshDb('sleeve-cap-grid');
    $md3 = pfMd();
    $ex3 = new FakePaperExchange($md3, 0.1, 200.0);
    $cfg3 = pfCfg(['sleeves' => [
        'grid' => ['enabled' => true, 'budget_usdt' => 3.0, 'symbols' => ['DOGEUSDT']],
    ]]);
    $r3 = (new Bot($cfg3, $db3, $ex3, $t0))->tick();
    T::eq('ok', $r3['status'], 'capped grid sleeve tick ok', $r3['summary']);
    T::eq(0, countRows($db3, 'SELECT COUNT(*) FROM engine_orders'), 'a 3 USDT sleeve cannot fund a 6.5 USDT rung');
    T::eq(0, $ex3->limitCalls, 'nothing was sent to the exchange at all');
    T::strContains($r3['summary'], 'quote free short', 'the tick says why the rung was skipped');

    $db4 = freshDb('sleeve-cap-grid-ok');
    $ex4 = new FakePaperExchange(pfMd(), 0.1, 200.0);
    $r4  = (new Bot(pfCfg(['sleeves' => [
        'grid' => ['enabled' => true, 'budget_usdt' => 1000.0, 'symbols' => ['DOGEUSDT']],
    ]]), $db4, $ex4, $t0))->tick();
    T::eq('ok', $r4['status'], 'funded grid sleeve tick ok', $r4['summary']);
    T::eq(1, countRows($db4, "SELECT COUNT(*) FROM engine_orders WHERE side = 'BUY'"), 'the same rung is placed once the budget allows it');

    // ---- sells are NEVER budget-blocked: reducing inventory returns capital to the sleeve
    $db5 = freshDb('sleeve-cap-sell');
    $md5 = pfMd();
    $md5->setPrice('DOGEUSDT', 0.19000, 0.19010);   // the lot is now above the book, so its sell can rest
    $ex5 = new FakePaperExchange($md5, 0.1, 200.0);
    $ex5->setBalance('DOGE', 32.0);
    $db5->insertLot(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'qty' => 32.0,
        'remaining' => 32.0, 'price' => 0.19880, 'fee_usdt' => 0.006, 'level' => 1,
        'client_id' => 'lot-1', 'created_at' => Util::nowIso(time() - 600)]);
    $cfg5 = pfCfg(['sleeves' => [
        'grid' => ['enabled' => true, 'budget_usdt' => 0.0, 'symbols' => ['DOGEUSDT']],
    ]]);
    $st = Sleeve::state($cfg5, $db5, 'grid', ['DOGE' => ['free' => 32.0, 'locked' => 0.0]], ['DOGEUSDT' => 0.19]);
    T::near(0.0, (float) $st['available'], 1e-12, 'the sleeve has nothing left to commit');
    $r5 = (new Bot($cfg5, $db5, $ex5, $t0))->tick();
    T::eq('ok', $r5['status'], 'exhausted grid sleeve tick ok', $r5['summary']);
    T::eq(0, countRows($db5, "SELECT COUNT(*) FROM engine_orders WHERE side = 'BUY'"), 'an exhausted sleeve buys nothing');
    T::eq(1, countRows($db5, "SELECT COUNT(*) FROM engine_orders WHERE side = 'SELL'"), 'but its inventory is still offered for sale');
    T::eq(1, $ex5->openOrderCount('DOGEUSDT'), 'the sell rests on the exchange');

    // ---- EngineOrders::place() itself consults the budget (DESIGN-PORTFOLIO.md §3), so a
    //      mis-sized engine can never spend another sleeve's capital
    $db6  = freshDb('sleeve-cap-place');
    $md6  = engineMd(FakeMarketData::SERVER_TIME_MS);
    $ex6  = new FakePaperExchange($md6, 0.1, 200.0);
    $info6 = FakeMarketData::infoRow('SOLUSDT');
    $o6   = new EngineOrders(engineCfg(), $db6, $ex6, $info6, $t0);
    T::eq(null, $o6->availableQuote(), 'single-engine mode leaves place() uncapped');
    $o6->setAvailableQuote(4.0);
    T::near(4.0, (float) $o6->availableQuote(), 1e-12, 'the sleeve cap is remembered');
    T::eq(null, $o6->place('BUY', 129.22, 6.5, 'grid_buy', 1), 'a 6.5 USDT buy is refused by a 4 USDT budget');
    T::eq(0, countRows($db6, 'SELECT COUNT(*) FROM engine_orders'), 'and nothing is written to the order book');
    T::eq(0, $ex6->limitCalls, 'and nothing is sent to the exchange');
    $o6->setAvailableQuote(50.0);
    T::ok($o6->place('BUY', 129.22, 6.5, 'grid_buy', 1) !== null, 'the same buy is placed once the budget allows it');
});

T::group('sleeve-drawdown', ['Bot', 'Sleeve', 'Risk', 'Db', 'FakePaperExchange', 'FakeMarketData'], static function (): void {
    $db = freshDb('sleeve-drawdown');
    $md = pfMd();
    $ex = new FakePaperExchange($md, 0.1, 200.0);
    $t0 = FakeMarketData::SERVER_TIME_MS;

    // the grid sleeve has lost 30 % of a 100 USDT budget; the loss is old enough that the
    // account-wide daily and weekly caps cannot be what stops it
    $old = Util::nowIso(time() - 30 * 86400);
    $db->insertCycle(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'level' => 1,
        'qty' => 100.0, 'buy_price' => 0.25, 'sell_price' => 0.15, 'gross_usdt' => 15.0,
        'fee_usdt' => 0.04, 'pnl_usdt' => -30.0, 'opened_at' => $old, 'closed_at' => $old]);

    $cfg = pfCfg(['sleeves' => pfSleeves([
        'grid' => ['enabled' => true, 'budget_usdt' => 100.0, 'symbols' => ['DOGEUSDT']],
    ])]);
    $state = Sleeve::state($cfg, $db, 'grid', [], ['DOGEUSDT' => 0.2]);
    T::near(70.0, (float) $state['equity'], 1e-9, 'the grid sleeve is at 70 of its 100 USDT budget');
    T::ok((float) $state['equity'] <= 100.0 * (1.0 - Risk::sleeveMaxDrawdownPct($cfg) / 100.0),
        'that is at or past the 25 % sleeve drawdown limit');

    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'drawdown tick ok', $r['summary']);
    T::ok((string) $db->getState('sleeve_paused_grid', '') !== '', 'the drawn-down sleeve is recorded as paused');
    T::strContains($r['summary'], 'sleeve grid drawdown-paused', 'the tick says which sleeve paused');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid'"), 'the paused sleeve opens no new exposure');

    // the other sleeves are untouched by one sleeve's drawdown
    T::ok($db->openPosition() !== null, 'the signal sleeve keeps trading');
    $pos = $db->openPosition();
    if ($pos !== null) {
        T::eq('SOLUSDT', (string) $pos['symbol'], 'and it traded its own symbol');
    }
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'pmm'"), 'the pmm sleeve keeps quoting');
    T::eq('', (string) $db->getState('sleeve_paused_pmm', ''), 'the healthy sleeve is not paused');
    T::eq('0', (string) $db->getState('halted', '0'), 'one sleeve drawing down does not halt the account');

    // the pause lifts by itself once the sleeve recovers
    $db->insertCycle(['mode' => 'paper', 'engine' => 'grid', 'symbol' => 'DOGEUSDT', 'level' => 1,
        'qty' => 100.0, 'buy_price' => 0.15, 'sell_price' => 0.25, 'gross_usdt' => 25.0,
        'fee_usdt' => 0.04, 'pnl_usdt' => 25.0, 'opened_at' => $old, 'closed_at' => $old]);
    $r = (new Bot($cfg, $db, $ex, $t0 + 60000))->tick();
    T::eq('ok', $r['status'], 'recovery tick ok', $r['summary']);
    T::eq('', (string) $db->getState('sleeve_paused_grid', ''), 'the pause lifts when the sleeve recovers');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid'"), 'and the sleeve trades again');

    // ---- the GLOBAL kill switch still halts everything, cancelling every sleeve order first
    $live = countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE status IN ('NEW','PARTIALLY_FILLED','SENDING','UNKNOWN')");
    T::ok($live >= 2, 'both engine sleeves have resting orders before the kill switch', (string) $live);
    $kill = pfCfg(['sleeves' => pfSleeves(['grid' => ['enabled' => true, 'budget_usdt' => 100.0, 'symbols' => ['DOGEUSDT']]]),
        'equity_floor_usdt' => 100000.0]);
    $r = (new Bot($kill, $db, $ex, $t0 + 120000))->tick();
    T::eq('halted', $r['status'], 'the equity floor halts the whole portfolio', $r['summary']);
    T::eq('1', (string) $db->getState('halted', '0'), 'halted flag set');
    T::eq('equity_floor', (string) $db->getState('halt_reason', ''), 'halt reason');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE status IN ('NEW','PARTIALLY_FILLED','SENDING','UNKNOWN')"),
        'every sleeve order was cancelled by the kill switch');
    T::eq(0, $ex->openOrderCount(), 'nothing is left resting on the exchange');
    T::eq(null, $db->openPosition(), 'the open position was closed by the kill switch');
    T::strContains((string) $db->getState('no_trade_reason', ''), 'halted:', 'the tick records the halt');
});

T::group('portfolio-tick', ['Bot', 'Sleeve', 'FakePaperExchange', 'FakeMarketData', 'Db'], static function (): void {
    $db  = freshDb('portfolio-tick');
    $md  = pfMd();
    $ex  = new FakePaperExchange($md, 0.1, 200.0);
    $cfg = pfCfg();
    $t0  = FakeMarketData::SERVER_TIME_MS;

    T::eq('0', (string) $db->getState('sleeve_cursor', '0'), 'the rotating cursor starts at 0');

    $r = (new Bot($cfg, $db, $ex, $t0))->tick();
    T::eq('ok', $r['status'], 'portfolio tick1 ok', $r['summary']);
    T::strContains((string) $db->getState('no_trade_reason', ''), 'portfolio:', 'the tick reports itself as a portfolio tick');

    // ---- every sleeve ran, and each one stayed on its own symbol
    T::strContains((string) $db->getState('no_trade_reason', ''), 'signal=', 'the signal sleeve ran');
    T::strContains((string) $db->getState('no_trade_reason', ''), 'grid=', 'the grid sleeve ran');
    T::strContains((string) $db->getState('no_trade_reason', ''), 'pmm=', 'the pmm sleeve ran');

    T::eq(['SOLUSDT'], pfDistinct($db, 'positions', 'symbol'), 'the signal sleeve only ever traded SOLUSDT');
    T::eq(['SOLUSDT'], pfDistinct($db, 'orders', 'symbol'), 'and only sent market orders for SOLUSDT');
    T::eq(['DOGEUSDT'], pfEngineSymbols($db, 'grid'), 'the grid sleeve only ever touched DOGEUSDT');
    T::eq(['XRPUSDT'], pfEngineSymbols($db, 'pmm'), 'the pmm sleeve only ever touched XRPUSDT');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE symbol = 'SOLUSDT'"),
        'no engine ever quoted the signal sleeve symbol');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM positions WHERE symbol IN ('DOGEUSDT','XRPUSDT')"),
        'the signal engine never opened a position on an engine sleeve symbol');
    T::eq(['SOLUSDT'], pfDistinct($db, 'signals', 'symbol'), 'only the signal sleeve symbol was evaluated');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid'"), 'one grid rung');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'pmm'"), 'one pmm quote');
    $pos = $db->openPosition();
    T::ok($pos !== null && (string) $pos['symbol'] === 'SOLUSDT', 'the signal sleeve entered its own symbol');

    // every sleeve wrote its own equity sample
    T::eq(3, countRows($db, 'SELECT COUNT(*) FROM sleeve_equity'), 'one sleeve_equity sample per sleeve');
    foreach (['signal', 'grid', 'pmm'] as $eng) {
        $rows = $db->sleeveEquitySeries($eng, 10, 'paper');
        T::eq(1, count($rows), $eng . ': one equity sample');
        if ($rows !== []) {
            T::near(1000.0, (float) $rows[0]['budget'], 1e-9, $eng . ': the sample carries the budget');
        }
    }

    // ---- the cursor advances so no sleeve is starved by a long tick
    T::eq('1', (string) $db->getState('sleeve_cursor', ''), 'the cursor advanced after a full pass');
    $r = (new Bot($cfg, $db, $ex, $t0 + 60000))->tick();
    T::eq('ok', $r['status'], 'portfolio tick2 ok', $r['summary']);
    T::eq('2', (string) $db->getState('sleeve_cursor', ''), 'the cursor keeps rotating');
    T::eq(['DOGEUSDT'], pfEngineSymbols($db, 'grid'), 'tick2: the grid sleeve is still only on DOGEUSDT');
    T::eq(['XRPUSDT'], pfEngineSymbols($db, 'pmm'), 'tick2: the pmm sleeve is still only on XRPUSDT');
    T::eq(6, countRows($db, 'SELECT COUNT(*) FROM sleeve_equity'), 'tick2 sampled every sleeve again');

    // ---- the time budget stops the loop early and records exactly which sleeves were skipped
    $db2 = freshDb('portfolio-budget');
    $ex2 = new FakePaperExchange(pfMd(), 0.1, 200.0);
    $r2  = (new Bot(pfCfg(['tick_time_budget_ms' => 1]), $db2, $ex2, $t0))->tick();
    T::eq('ok', $r2['status'], 'time-budget tick ok', $r2['summary']);
    $reason = (string) $db2->getState('no_trade_reason', '');
    T::strContains($reason, 'skipped:', 'the tick records that it stopped early');
    T::strContains($reason, 'grid', 'the skipped grid sleeve is named');
    T::strContains($reason, 'pmm', 'the skipped pmm sleeve is named');
    T::strContains($reason, '(time_budget)', 'and the reason is the time budget');
    T::strContains($r2['summary'], 'time budget', 'the summary says the budget was exceeded');
    T::strContains($reason, 'signal=', 'the first sleeve still ran: a slow tick never starves everything');
    T::eq(0, countRows($db2, 'SELECT COUNT(*) FROM engine_orders'), 'the skipped sleeves placed nothing');
    T::eq(1, countRows($db2, 'SELECT COUNT(*) FROM sleeve_equity'), 'only the sleeve that ran sampled its equity');
    T::eq('1', (string) $db2->getState('sleeve_cursor', ''), 'the next tick starts at the first sleeve this one skipped');
    T::ok($db2->openPosition() !== null, 'the sleeve that did run traded normally');
});

/**
 * The exclusivity rule of DESIGN-PORTFOLIO.md §1, driven through REAL portfolio ticks rather
 * than asserted on the validator: sleeve A (grid) is given actual inventory in symbol X
 * (DOGEUSDT), sleeve B (pmm) is configured for symbol Y (XRPUSDT), and B is pushed all the way
 * through a live sale of its own inventory while A's is sitting in the same wallet.
 *
 * A sleeve budget is an accounting boundary, not an exchange one: Binance shows both sleeves
 * one balance, so nothing but this code stops B from selling A's base. The assertions below are
 * what "must never" means in practice - B places no order on X, books no lot or cycle on X, and
 * A's lot comes out of B's whole sell path with its `remaining` untouched.
 */
T::group('sleeve-no-cross-trade', ['Bot', 'Sleeve', 'Risk', 'FakePaperExchange', 'FakeMarketData', 'Db'], static function (): void {
    $db  = freshDb('sleeve-no-cross');
    $md  = pfMd();
    $ex  = new FakePaperExchange($md, 0.1, 200.0);
    // the pmm sleeve is deliberately made willing to SELL: with the inventory target under the
    // level its own fills reach, its ask is sized up rather than skewed away. That is the whole
    // point - a sleeve that never sells could not cross into another sleeve's inventory anyway.
    $cfg = pfCfg(['pmm_target_base_pct' => 2, 'pmm_max_base_pct' => 98]);
    $t0  = FakeMarketData::SERVER_TIME_MS;

    T::eq('grid', (string) Sleeve::ownerOf($cfg, 'DOGEUSDT'), 'sleeve A (grid) owns symbol X = DOGEUSDT');
    T::eq('pmm', (string) Sleeve::ownerOf($cfg, 'XRPUSDT'), 'sleeve B (pmm) owns symbol Y = XRPUSDT');

    // tick 0: both sleeves post their first quote. 1: DOGE drops through the grid's first rung,
    // so sleeve A ends up holding real base. 2: XRP drops through the pmm bid, so sleeve B holds
    // its own. 4: XRP rallies through the pmm ask, so sleeve B actually SELLS while A's DOGE is
    // sitting in the very same wallet.
    $dogeQty = 0.0;
    for ($i = 0; $i < 7; $i++) {
        if ($i === 1) {
            $md->setPrice('DOGEUSDT', 0.19860, 0.19870);
        }
        if ($i === 2) {
            $md->setPrice('XRPUSDT', 1.99400, 1.99450);
        }
        if ($i === 4) {
            $md->setPrice('XRPUSDT', 2.05000, 2.05100);
        }
        $r = (new Bot($cfg, $db, $ex, $t0 + $i * 60000))->tick();
        T::eq('ok', $r['status'], 'cross-trade tick ' . $i . ' ok', $r['summary']);
        if ($i === 1) {
            $lots = $db->openLots('DOGEUSDT', 'paper');
            $dogeQty = $lots === [] ? 0.0 : (float) $lots[0]['remaining'];
        }
    }

    // ---- the premise: A really does hold X, and B really did sell Y
    T::ok($dogeQty > 0.0, 'sleeve A holds real inventory in X after its rung filled');
    $aLots = $db->openLots('DOGEUSDT', 'paper');
    T::eq(1, count($aLots), 'X carries exactly one open lot');
    T::eq('grid', $aLots === [] ? '' : (string) $aLots[0]['engine'], "and it is booked to sleeve A's engine");
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'pmm' AND side = 'SELL' AND status = 'FILLED'"),
        'sleeve B really did fill a sell of its own inventory');
    T::eq(1, countRows($db, "SELECT COUNT(*) FROM cycles WHERE engine = 'pmm' AND symbol = 'XRPUSDT'"),
        "and booked the round trip against its own symbol");

    // ---- B never touched X
    T::eq(['XRPUSDT'], pfEngineSymbols($db, 'pmm'), 'sleeve B only ever placed orders on its own symbol Y');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE symbol = 'DOGEUSDT' AND engine <> 'grid'"),
        'no order on X was ever placed by any engine but A');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM lots WHERE symbol = 'DOGEUSDT' AND engine <> 'grid'"),
        'no lot on X was ever booked to any engine but A');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM cycles WHERE symbol = 'DOGEUSDT'"),
        "nothing ever sold A's inventory: X has no realised cycle at all");
    T::eq(['SOLUSDT'], pfDistinct($db, 'positions', 'symbol'), 'the signal sleeve stayed on its own symbol too');

    // ---- A's inventory came through B's whole sell path untouched
    $after = $db->openLots('DOGEUSDT', 'paper');
    T::near($dogeQty, $after === [] ? 0.0 : (float) $after[0]['remaining'], 1e-12,
        "A's lot still has every unit it started with after B sold");
    T::near($dogeQty, (float) $ex->free('DOGE') + (float) $ex->locked('DOGE'), 1e-9,
        'and the wallet still holds exactly that base, free plus locked');

    // ---- the mechanism, asserted directly: FIFO consumption is engine-scoped, so even a sell
    //      booked under B can never reach A's lot (this is why the panel refuses to hand a
    //      symbol carrying foreign inventory to another sleeve rather than letting it through)
    T::eq([], $db->openLots('DOGEUSDT', 'paper', 'pmm'), "B's FIFO view of X is empty");
    T::eq([], $db->consumeLots('DOGEUSDT', $dogeQty, 'paper', 'pmm'), "and consuming X as B takes nothing");
    $stillThere = $db->openLots('DOGEUSDT', 'paper');
    T::near($dogeQty, $stillThere === [] ? 0.0 : (float) $stillThere[0]['remaining'], 1e-12,
        "the attempt left A's lot exactly as it was");

    // ---- attribution: the shared wallet is split by symbol ownership, never by balance
    $acct   = $ex->account();
    $prices = ['DOGEUSDT' => 0.1986, 'XRPUSDT' => 2.05, 'SOLUSDT' => 129.75];
    $bState = Sleeve::state($cfg, $db, 'pmm', $acct, $prices);
    $aState = Sleeve::state($cfg, $db, 'grid', $acct, $prices);
    T::ok(!isset($bState['inventory_qty']['DOGEUSDT']), "B's inventory never includes X");
    T::near($dogeQty, (float) ($aState['inventory_qty']['DOGEUSDT'] ?? 0.0), 1e-9, "A's inventory is all of X");

    // ---- and the config can never be edited into the overlap in the first place
    $bad = $cfg['sleeves'];
    $bad['pmm']['symbols'] = ['DOGEUSDT'];
    $v = Risk::validateConfig(['sleeves' => $bad], $cfg);
    $errs = isset($v[1]) && is_array($v[1]) ? $v[1] : [];
    T::ok($errs !== [], 'pointing B at X is a validation error, not a silent overlap');
});

/**
 * Two isolation rules that only show up once a sleeve goes wrong (DESIGN-PORTFOLIO.md §6.3-6.4):
 * a per-symbol flatten must not disarm the other sleeves, and a sleeve that ABORTS the tick must
 * still hand the rotating cursor on, or it would own the first slot for ever and the sleeves
 * behind it would never sync their ladders again.
 */
T::group('portfolio-isolation', ['Bot', 'Sleeve', 'FakePaperExchange', 'FakeMarketData', 'BookFailExchange', 'Db'], static function (): void {
    $db  = freshDb('portfolio-isolation');
    $md  = pfMd();
    $ex  = new FakePaperExchange($md, 0.1, 200.0);
    $cfg = pfCfg();
    $t0  = FakeMarketData::SERVER_TIME_MS;

    for ($i = 0; $i < 3; $i++) {
        if ($i === 1) {
            $md->setPrice('DOGEUSDT', 0.19860, 0.19870);
        }
        (new Bot($cfg, $db, $ex, $t0 + $i * 60000))->tick();
    }
    $gridLive = countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid' AND status = 'NEW'");
    $pmmLive  = countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'pmm' AND status = 'NEW'");
    T::ok($gridLive > 0, 'the grid sleeve has resting orders before the flatten');
    T::ok($pmmLive > 0, 'and so does the pmm sleeve');

    // ---- flatten ONE sleeve's symbol: the other sleeve's book must survive it
    $bot  = new Bot($cfg, $db, $ex, $t0 + 3 * 60000);
    $flat = $bot->flattenInventory('XRPUSDT');
    T::eq('XRPUSDT', (string) $flat['symbol'], 'the flatten went to the symbol it was given, not the grid sleeve default');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'pmm' AND status = 'NEW'"),
        "flattening one sleeve's symbol clears that sleeve's book");
    T::eq($gridLive, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid' AND status = 'NEW'"),
        "and leaves every resting order of the other sleeve live");
    T::ok(count($db->openLots('DOGEUSDT', 'paper')) > 0, "the other sleeve's inventory is untouched as well");

    // ---- a sleeve that aborts the tick still passes the cursor on
    $db2 = freshDb('portfolio-abort');
    $md2 = pfMd();
    $ex2 = new FakePaperExchange($md2, 0.1, 200.0);
    (new Bot($cfg, $db2, $ex2, $t0))->tick();
    T::ok($db2->openPosition() !== null, 'the signal sleeve holds a position to manage');
    T::eq('1', (string) $db2->getState('sleeve_cursor', ''), 'the cursor is on the signal sleeve for the next tick');
    $db2->setState('sleeve_cursor', '0');

    // step 5 reads the book for the open position with an ESSENTIAL step: a BinanceException
    // there aborts the whole tick from inside the first sleeve
    $fail = new BookFailExchange($ex2, 'SOLUSDT');
    $r    = (new Bot($cfg, $db2, $fail, $t0 + 60000))->tick();
    T::eq('error', $r['status'], 'the essential book call aborts the tick', $r['summary']);
    T::ok($fail->failures > 0, 'and it really was the book call that threw');
    T::eq('1', (string) $db2->getState('sleeve_cursor', ''), 'the cursor still advanced past the sleeve that aborted');

    // the next tick therefore starts at the sleeve behind it, and that sleeve trades
    $r2 = (new Bot($cfg, $db2, $ex2, $t0 + 120000))->tick();
    T::eq('ok', $r2['status'], 'the next tick runs normally', $r2['summary']);
    T::strContains((string) $db2->getState('no_trade_reason', ''), 'grid=', 'the sleeve behind the aborting one ran');
    T::ok(countRows($db2, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid'") > 0,
        'and it got its ladder onto the book: an aborting sleeve cannot starve the others');
});

/**
 * A grid range exit in PORTFOLIO mode is sleeve-local (DESIGN-PORTFOLIO.md §6.4): it must pause
 * that grid alone, through `grid_paused_reason`, and must NOT write the global `paused_until` /
 * `pause_reason` keys `Risk::entryBlockReason()` reads for every engine - which in single-engine
 * mode it does, and still must.
 */
T::group('portfolio-range-exit', ['Bot', 'EngineGrid', 'Risk', 'FakePaperExchange', 'FakeMarketData', 'Db'], static function (): void {
    $db  = freshDb('portfolio-range-exit');
    $md  = pfMd();
    $ex  = new FakePaperExchange($md, 0.1, 200.0);
    $cfg = pfCfg();
    $t0  = FakeMarketData::SERVER_TIME_MS;

    (new Bot($cfg, $db, $ex, $t0))->tick();
    T::near(0.2, (float) $db->getState('grid_anchor', '0'), 1e-9, 'the grid sleeve anchored at the mid');
    T::eq('', (string) $db->getState('grid_paused_reason', ''), 'and starts unpaused');

    // mid above anchor x (1 + grid_range_up_pct/100) = 0.208
    $md->setPrice('DOGEUSDT', 0.21000, 0.21010);
    $r = (new Bot($cfg, $db, $ex, $t0 + 60000))->tick();
    T::eq('ok', $r['status'], 'the range-exit tick still completes', $r['summary']);

    T::eq('grid_range_exit', (string) $db->getState('grid_paused_reason', ''), 'the grid sleeve records its own pause');
    T::eq('', (string) $db->getState('paused_until', ''), 'and never writes the GLOBAL pause in portfolio mode');
    T::eq('', (string) $db->getState('pause_reason', ''), 'nor the global pause reason');
    T::eq(0, countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'grid' AND status = 'NEW'"),
        'the range exit took the grid ladder off the book');
    T::ok(countRows($db, "SELECT COUNT(*) FROM engine_orders WHERE engine = 'pmm' AND status = 'NEW'") > 0,
        "the pmm sleeve's quote is still live: one sleeve's range exit is not the account's");
    T::eq('', (string) Risk::entryBlockReason($cfg, $db, 150.0, 200.0, 'signal'),
        'and the signal sleeve is not blocked by it either');

    // the single-engine path is unchanged: there the range exit IS the account-wide pause
    $db2 = freshDb('single-range-exit');
    $md2 = new FakeMarketData([]);
    $md2->setPrice('DOGEUSDT', 0.19990, 0.20010);
    $ex2  = new FakePaperExchange($md2, 0.1, 200.0);
    $cfg2 = pfCfg(['portfolio_enabled' => false, 'engine' => 'grid', 'engine_symbol' => 'DOGEUSDT', 'symbols' => ['DOGEUSDT']]);
    (new Bot($cfg2, $db2, $ex2, $t0))->tick();
    $md2->setPrice('DOGEUSDT', 0.21000, 0.21010);
    (new Bot($cfg2, $db2, $ex2, $t0 + 60000))->tick();
    T::eq('grid_range_exit', (string) $db2->getState('pause_reason', ''), 'single-engine mode still writes the global pause');
    T::ok((string) $db2->getState('paused_until', '') !== '', 'and still parks paused_until in the future');
});

T::group('scanner-rank', ['Scanner', 'FakeMarketData', 'Db', 'Risk'], static function (): void {
    $cfg     = pfCfg(['scanner_enabled' => true]);
    $tickers = FakeMarketData::ticker24hFixture();
    $info    = FakeMarketData::ticker24hInfo();
    $rows    = Scanner::rank($tickers, $info, $cfg);

    $bySymbol = [];
    $order    = [];
    foreach ($rows as $r) {
        $bySymbol[(string) $r['symbol']] = $r;
        $order[] = (string) $r['symbol'];
    }

    // ---- deterministic ranking: volatile AND liquid AND tight first
    T::eq(['SOLUSDT', 'DOGEUSDT', 'ETHUSDT', 'JUPUSDT', 'NOISEUSDT', 'FLATUSDT', 'WIDEUSDT', 'DUSTUSDT', 'ILLIQUSDT'],
        $order, 'the fixture ranks in exactly one order');
    T::near(1.569231, (float) $bySymbol['SOLUSDT']['score'], 1e-6, 'SOLUSDT score = atr x liquidity x spread factor');
    T::near(0.833333, (float) $bySymbol['DOGEUSDT']['score'], 1e-6, 'DOGEUSDT score');
    T::near(0.692222, (float) $bySymbol['ETHUSDT']['score'], 1e-6, 'ETHUSDT score');
    T::near(0.533445, (float) $bySymbol['JUPUSDT']['score'], 1e-6, 'JUPUSDT score');
    T::eq($order, array_map(static function (array $r): string { return (string) $r['symbol']; },
        Scanner::rank($tickers, $info, $cfg)), 'ranking the same fixture twice gives the same order');
    $shuffled = array_reverse($tickers);
    T::eq($order, array_map(static function (array $r): string { return (string) $r['symbol']; },
        Scanner::rank($shuffled, $info, $cfg)), 'the ranking does not depend on the input order');

    // ---- volatility alone never wins
    T::ok((float) $bySymbol['ILLIQUSDT']['atr_pct'] > (float) $bySymbol['SOLUSDT']['atr_pct'],
        'the illiquid pair is the more volatile one');
    T::ok((float) $bySymbol['WIDEUSDT']['atr_pct'] > (float) $bySymbol['SOLUSDT']['atr_pct'],
        'so is the wide-spread pair');
    T::near(0.0, (float) $bySymbol['ILLIQUSDT']['score'], 1e-12, 'the illiquid pair scores zero');
    T::near(0.0, (float) $bySymbol['WIDEUSDT']['score'], 1e-12, 'the wide-spread pair scores zero');
    T::ok(array_search('ILLIQUSDT', $order, true) > array_search('SOLUSDT', $order, true),
        'the illiquid pair ranks below the volatile-but-liquid one');
    T::ok(array_search('WIDEUSDT', $order, true) > array_search('SOLUSDT', $order, true),
        'the wide-spread pair ranks below the volatile-but-liquid one');
    T::near(0.5, Scanner::liquidityFactor(5000000.0, 5000000.0), 1e-12, 'a pair exactly at the volume floor keeps half its ATR');
    T::near(0.0, Scanner::liquidityFactor(500000.0, 5000000.0), 1e-12, 'ten times below the floor scores zero');
    T::near(1.0, Scanner::liquidityFactor(500000000.0, 5000000.0), 1e-12, 'the liquidity factor is capped at 1');
    T::near(0.0, Scanner::spreadFactor(0.06, 0.06), 1e-12, 'a book as wide as the limit scores zero');
    T::near(1.0, Scanner::spreadFactor(0.0, 0.06), 1e-12, 'a zero spread keeps the whole score');

    // ---- gates are RECORDED, the row is never dropped
    T::eq(['illiquid'], $bySymbol['ILLIQUSDT']['gates'], 'the illiquid row is kept and says why');
    T::eq(['spread_wide'], $bySymbol['WIDEUSDT']['gates'], 'the wide-spread row is kept and says why');
    T::eq(['dust_step'], $bySymbol['DUSTUSDT']['gates'], 'a step that costs more than the dust limit is gated');
    foreach (['ILLIQUSDT', 'WIDEUSDT', 'DUSTUSDT', 'NOISEUSDT', 'FLATUSDT'] as $sym) {
        T::eq(0, (int) $bySymbol[$sym]['eligible'], $sym . ' is not eligible');
        T::ok((float) $bySymbol[$sym]['quote_vol'] > 0.0, $sym . ' keeps its numbers for the panel');
    }
    foreach (['SOLUSDT', 'DOGEUSDT', 'ETHUSDT', 'JUPUSDT'] as $sym) {
        T::eq(1, (int) $bySymbol[$sym]['eligible'], $sym . ' is eligible');
        T::eq([], $bySymbol[$sym]['gates'], $sym . ' has no gate');
        T::ok((float) $bySymbol[$sym]['required_size'] > 0.0, $sym . ' carries its required size');
    }

    // ---- ATR outside the band is rejected, in both directions
    T::eq(['atr_high'], $bySymbol['NOISEUSDT']['gates'], 'an ATR above scanner_max_atr_pct is rejected as noise');
    T::eq(['atr_low'], $bySymbol['FLATUSDT']['gates'], 'an ATR below scanner_min_atr_pct is rejected');
    T::ok((float) $bySymbol['NOISEUSDT']['atr_pct'] > 4.0, 'the noisy pair really is outside the band');
    T::ok((float) $bySymbol['FLATUSDT']['atr_pct'] < 0.5, 'the flat pair really is outside the band');
    $noAtr    = Scanner::rank(FakeMarketData::ticker24hFixture(false), $info, $cfg);
    $unknowns = 0;
    foreach ($noAtr as $r) {
        if (in_array('atr_unknown', $r['gates'], true) && $r['atr_pct'] === null) {
            $unknowns++;
        }
    }
    T::eq(count($noAtr), $unknowns, 'without an ATR every row gates atr_unknown and reports a null ATR');
    T::eq(0, count(array_filter($noAtr, static function (array $r): bool { return (int) $r['eligible'] === 1; })),
        'nothing is eligible before the ATR pass has run');

    // ---- stablecoins, leveraged tokens and untradeable symbols never rank at all
    foreach (array_merge(FakeMarketData::ticker24hCase('stablecoin'),
                         FakeMarketData::ticker24hCase('leveraged'),
                         FakeMarketData::ticker24hCase('not_trading'),
                         FakeMarketData::ticker24hCase('other_quote')) as $sym) {
        T::ok(!isset($bySymbol[$sym]), $sym . ' is dropped before it can rank');
    }
    T::ok(isset($bySymbol['JUPUSDT']), 'a coin whose name merely ends in UP is NOT a leveraged token');
    T::ok(Scanner::isLeveraged('BTCUP') && Scanner::isLeveraged('ADADOWN')
        && Scanner::isLeveraged('XRPBULL') && Scanner::isLeveraged('ETHBEAR'), 'the four leveraged suffixes are caught');
    T::ok(!Scanner::isLeveraged('JUP') && !Scanner::isLeveraged('SUP') && !Scanner::isLeveraged('BEAR')
        && !Scanner::isLeveraged('UP'), 'coins that merely contain the suffix are kept');
    $noExcl = Scanner::rank($tickers, $info, pfCfg(['scanner_exclude' => []]));
    $names  = array_map(static function (array $r): string { return (string) $r['symbol']; }, $noExcl);
    T::contains($names, 'USDCUSDT', 'the stablecoins are excluded by scanner_exclude, nothing else');

    // ---- refresh() stores exactly that ranking
    $db = freshDb('scanner-rank');
    $md = new FakeMarketData([]);          // no klines: the fixture ATRs are what the ranking uses
    $md->setTicker24h($tickers);
    $scanner = new Scanner($cfg, $db, $md);
    T::ok($scanner->enabled(), 'the scanner is enabled');
    T::ok($scanner->due(), 'a scanner that never ran is due');
    $stored = $scanner->refresh($info, true);
    T::eq(count($rows), count($stored), 'refresh stores one row per ranked pair');
    T::eq(1, $md->callCount('ticker24h'), 'refresh spends exactly one weight-80 ticker call');
    T::eq(count($rows), countRows($db, 'SELECT COUNT(*) FROM scanner'), 'the whole set is written to the scanner table');
    $top = $db->scannerRows(3);
    T::eq('SOLUSDT', (string) $top[0]['symbol'], 'the panel reads the leader first');
    T::eq(4, count($db->scannerRows(50, true)), 'four eligible pairs');
    $stored_by = [];
    foreach ($db->scannerRows(50) as $sr) {
        $stored_by[(string) $sr['symbol']] = $sr;
    }
    T::eq(['illiquid'], $stored_by['ILLIQUSDT']['gates_list'], 'the stored row keeps its gates');
    T::eq(0, (int) $stored_by['ILLIQUSDT']['eligible'], 'and stays in the table, marked ineligible');
    T::near(1.569231, (float) $stored_by['SOLUSDT']['score'], 1e-6, 'the stored score is the ranked one');
    T::ok($db->scannerAge() !== null && $db->scannerAge() >= 0, 'the scanner age is known');
    T::ok((string) $db->getState('scanner_at', '') !== '', 'scanner_at is stamped');
    T::ok(!$scanner->due(), 'the scanner is not due again straight away');
    T::eq([], $scanner->refresh($info), 'and an un-forced refresh does nothing');
    T::eq(1, $md->callCount('ticker24h'), 'so no second weight-80 call is made');
});

T::group('portfolio-off', ['Bot', 'PaperExchange', 'FakeMarketData', 'Db', 'Sleeve'], static function (): void {
    /**
     * Regression guard: with portfolio_enabled = false the tick must be byte-for-byte the
     * single-engine tick of DESIGN-ENGINES.md. The baseline config has no portfolio keys at
     * all (a config.json written before portfolio mode existed); the comparison config carries
     * the full sleeve and scanner block with the switch off. Wall-clock columns are the only
     * thing normalised away - both runs use the same injected exchange clock.
     */
    $volatile = ['ts' => true, 'created_at' => true, 'updated_at' => true, 'closed_at' => true,
                 'opened_at' => true, 'last_at' => true];
    $skipState = ['last_tick_at' => true, 'last_tick_ms' => true, 'symbol_info_at' => true,
                  'symbol_metrics' => true, 'day_start_date' => true, 'cooldown_until' => true,
                  'last_loss_at' => true];

    /** PaperExchange mints order ids from the wall clock: paper-<ms>-<n> -> paper-<ts>-<n>. */
    $ids = static function ($v) {
        return is_string($v) ? (string) preg_replace('/paper-\\d{10,}-/', 'paper-<ts>-', $v) : $v;
    };

    /** Blank the wall-clock stamps Binance/PaperExchange put inside a stored `raw` payload. */
    $scrub = null;
    $scrub = static function ($v) use (&$scrub, $ids) {
        if (!is_array($v)) {
            return $ids($v);
        }
        $stamps = ['transactTime' => true, 'time' => true, 'updateTime' => true, 'workingTime' => true];
        $out = [];
        foreach ($v as $k => $inner) {
            $out[$k] = isset($stamps[(string) $k]) ? '<ts>' : $scrub($inner);
        }
        return $out;
    };

    $snapshot = static function (Db $db) use ($volatile, $skipState, $scrub, $ids): array {
        $out    = [];
        $tables = [];
        foreach ($db->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $tables[] = (string) $t;
        }
        foreach ($tables as $table) {
            if ($table === 'logs' || $table === 'sqlite_sequence') {
                continue;   // log text carries timings and row ids; it is not tick state
            }
            $rows  = $db->pdo()->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
            $clean = [];
            foreach ($rows as $row) {
                $r = [];
                foreach ($row as $k => $v) {
                    if (isset($volatile[$k])) {
                        $r[$k] = ($v === null || $v === '') ? $v : '<ts>';
                    } elseif ($k === 'raw' && is_string($v) && $v !== '') {
                        $decoded = json_decode($v, true);
                        $r[$k]   = is_array($decoded) ? json_encode($scrub($decoded)) : $v;
                    } elseif ($table === 'state' && $k === 'value' && isset($skipState[(string) $row['key']])) {
                        $r[$k] = '<volatile>';
                    } else {
                        $r[$k] = $ids($v);
                    }
                }
                $clean[] = $r;
            }
            usort($clean, static function (array $a, array $b): int {
                return strcmp((string) json_encode($a), (string) json_encode($b));
            });
            $out[$table] = $clean;
        }
        return $out;
    };

    $run = static function (array $cfg, string $tag): array {
        $db = freshDb($tag);
        $md = new FakeMarketData([
            'SOLUSDT'  => ['15m' => 'klines_15m_oversold',   '1h' => 'klines_1h_uptrend'],
            'DOGEUSDT' => ['15m' => 'klines_15m_overbought', '1h' => 'klines_1h_uptrend'],
        ]);
        $md->setPrice('SOLUSDT', 129.75, 129.80);
        $ex = new PaperExchange($md, $db, 0.1, 10.0);
        $t0 = FakeMarketData::SERVER_TIME_MS;
        $r1 = (new Bot($cfg, $db, $ex, $t0))->tick();
        $md->setPrice('SOLUSDT', 131.50, 131.55);
        $r2 = (new Bot($cfg, $db, $ex, $t0 + 120000))->tick();
        return ['db' => $db, 'r1' => $r1, 'r2' => $r2];
    };

    // the ONLY difference between the two configs is the portfolio block of DESIGN-PORTFOLIO.md §2
    $base = botCfg();
    $with = botCfg(pfBlock());
    T::eq([], array_diff_key($base, $with), 'the comparison config is a strict superset of the baseline');
    T::eq(array_keys(pfBlock()), array_keys(array_diff_key($with, $base)), 'and adds exactly the portfolio keys');
    foreach ($base as $k => $v) {
        if ($v !== $with[$k]) {
            T::ok(false, 'the two configs differ only in the portfolio block', 'key ' . $k);
        }
    }
    T::ok(!isset($base['portfolio_enabled']), 'the baseline config predates portfolio mode');
    T::ok(!Sleeve::portfolioEnabled($with), 'portfolio mode is off for the comparison config');
    T::ok(Sleeve::all($with) !== [], 'the sleeves are configured, they are just never used');
    T::ok(!empty($with['scanner_enabled']), 'and the scanner is enabled, so it too must stay inert');

    $a = $run($base, 'portfolio-off-base');
    $b = $run($with, 'portfolio-off-cfg');

    T::eq($a['r1']['status'], $b['r1']['status'], 'tick1 status is identical');
    T::eq($a['r1']['summary'], $b['r1']['summary'], 'tick1 summary is identical');
    T::eq($a['r2']['status'], $b['r2']['status'], 'tick2 status is identical');
    T::eq($a['r2']['summary'], $b['r2']['summary'], 'tick2 summary is identical');
    T::strContains($a['r1']['summary'], 'entered:SOLUSDT', 'the baseline really did trade');
    T::strContains($a['r2']['summary'], 'exited:take_profit', 'and really did close the round trip');

    $sa = $snapshot($a['db']);
    $sb = $snapshot($b['db']);
    T::eq(array_keys($sa), array_keys($sb), 'both runs created the same tables');
    foreach ($sa as $table => $rowsA) {
        $rowsB = isset($sb[$table]) ? $sb[$table] : null;
        T::eq(json_encode($rowsA), json_encode($rowsB), 'table ' . $table . ' is identical with portfolio mode off');
    }

    // and no sleeve code left a trace
    T::eq(0, countRows($b['db'], 'SELECT COUNT(*) FROM sleeve_equity'), 'no sleeve equity was sampled');
    T::eq(0, countRows($b['db'], 'SELECT COUNT(*) FROM scanner'), 'the scanner never ran');
    T::eq(null, $b['db']->getState('sleeve_cursor', null), 'no rotating cursor was written');
    T::eq(null, $b['db']->getState('scanner_at', null), 'no scanner timestamp was written');
    T::eq(null, $b['db']->getState('sleeve_paused_signal', null), 'no sleeve pause state was written');
    $reason = (string) $b['db']->getState('no_trade_reason', '');
    T::ok(strpos($reason, 'portfolio') === false && strpos($reason, 'sleeve') === false,
        'the no-trade reason is the single-engine one', $reason);
});

/* ============================================================ summary */

echo "\n" . T::$pass . ' passed, ' . T::$fail . ' failed'
    . (T::$skipped > 0 ? ', ' . T::$skipped . ' of ' . T::$groups . ' groups skipped (missing lib files)' : '')
    . "\n";
if (T::$pass + T::$fail === 0) {
    echo "nothing ran\n";
    exit(251);
}
exit(min(250, T::$fail));
