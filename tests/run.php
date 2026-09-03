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

$libs = ['Util', 'Db', 'Log', 'Binance', 'Indicators', 'Strategy', 'Risk', 'Exchange', 'Bot'];
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

/* ============================================================ summary */

echo "\n" . T::$pass . ' passed, ' . T::$fail . ' failed'
    . (T::$skipped > 0 ? ', ' . T::$skipped . ' of ' . T::$groups . ' groups skipped (missing lib files)' : '')
    . "\n";
if (T::$pass + T::$fail === 0) {
    echo "nothing ran\n";
    exit(251);
}
exit(min(250, T::$fail));
