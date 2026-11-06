<?php
declare(strict_types=1);

/**
 * Tick entry point (DESIGN.md §11).
 *
 *  CLI : php /home/USER/public_html/trader/cron.php          (no key needed)
 *  HTTP: GET cron.php with header "X-Cron-Key: <cron_key>" or ?key=<cron_key>
 *
 * Output is always text/plain: "<status> <summary> (<ms> ms)".
 */

require_once __DIR__ . '/bootstrap.php';

$trader_cli = PHP_SAPI === 'cli';

/** Print one line and stop. HTTP status is only applied for web requests. */
function trader_cron_exit(string $text, int $httpStatus, int $exitCode): void
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code($httpStatus);
    }
    echo $text, "\n";
    exit($exitCode);
}

if (!$trader_cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
}

try {
    $trader_cfg = trader_config();
} catch (Throwable $e) {
    // a corrupt/unreadable data/config.json must be reported loudly: falling back to
    // defaults would silently orphan an unmanaged live position
    trader_cron_exit('error config unusable: ' . $e->getMessage(), 500, 1);
}

if (!$trader_cli) {
    $trader_given = '';
    if (isset($_SERVER['HTTP_X_CRON_KEY']) && is_string($_SERVER['HTTP_X_CRON_KEY'])) {
        $trader_given = $_SERVER['HTTP_X_CRON_KEY'];
    } elseif (isset($_GET['key']) && is_string($_GET['key'])) {
        $trader_given = $_GET['key'];
    }
    $trader_expected = (string) ($trader_cfg['cron_key'] ?? '');
    if ($trader_expected === '' || $trader_given === '' || !hash_equals($trader_expected, (string) $trader_given)) {
        trader_cron_exit('forbidden', 403, 1);
    }
    unset($trader_given, $trader_expected);
}

/*
 * Wall-clock guard.
 *
 * The signal, grid and pmm engines make a single pass and are comfortable inside a minute.
 * The scalp engine (docs/DESIGN-SCALP.md §4) instead runs a BOUNDED LOOP of
 * `scalp_run_seconds` (default 50, hard ceiling 55) inside this one tick, because that is the
 * only way to trade more than once a minute on a host whose cron fires once a minute. Bot
 * measures that budget from the start of the tick, so the shared steps come out of the same
 * 50 seconds - but 55 seconds of limit for a 50-second loop plus its bookkeeping leaves no
 * room at all, and the process would be killed mid-sell.
 *
 * So the limit is the loop budget plus a margin for everything around it. It is a guard
 * against a HANG, not a schedule: the loop stops itself at its own deadline, and Bot::runLocked
 * below holds an exclusive flock on data/tick.lock for the whole run, so even if one tick
 * overran its minute the next cron finds the lock busy and exits rather than starting a second
 * scalp loop. Two concurrent loops would double-trade the same universe.
 *
 * set_time_limit() is in `disable_functions` on some shared hosts, hence function_exists();
 * calling a disabled function would be a fatal error before a single tick ever ran. It is also
 * a no-op under the CLI SAPI (max_execution_time is already 0 there), which is harmless.
 */
$trader_limit   = 55;
$trader_min_gap = 50;
$trader_scalp   = strtolower(trim((string) ($trader_cfg['engine'] ?? 'signal'))) === 'scalp';
if ($trader_scalp) {
    $trader_run = isset($trader_cfg['scalp_run_seconds']) && is_numeric($trader_cfg['scalp_run_seconds'])
        ? (int) round((float) $trader_cfg['scalp_run_seconds'])
        : 50;
    if ($trader_run < 1) {
        $trader_run = 1;
    }
    if ($trader_run > 55) {
        $trader_run = 55;
    }
    // loop budget + the shared steps 1-4 + the run bookkeeping, with room for one slow
    // market order at the very end of the loop
    $trader_limit = $trader_run + 40;
    // The double-trigger window below defaults to 50 s, which is the whole scalp budget: a
    // cron that fires a few seconds late would then be turned away as "too soon" and a full
    // minute of scalping would be lost, which is the opposite of what this engine is for.
    // Shortened to the loop budget less a jitter margin. Nothing is risked by it - overlap is
    // prevented by the flock, not by this - and an extra run inside the minute is simply more
    // polling, which is what the engine wants.
    $trader_min_gap = $trader_run - 10;
    if ($trader_min_gap < 10) {
        $trader_min_gap = 10;
    }
    unset($trader_run);
}
if (function_exists('set_time_limit')) {
    @set_time_limit($trader_limit);
}

// setup wizard not completed: nothing to run yet
if ((string) ($trader_cfg['panel_password_hash'] ?? '') === '') {
    trader_cron_exit('setup incomplete - open the panel in a browser first', 503, 0);
}

// double-trigger guard: refuse when the previous tick started less than $trader_min_gap
// seconds ago (50 s for every engine but scalp - see above)
try {
    $trader_last = (string) Db::get()->getState('last_tick_at', '');
    if ($trader_last !== '') {
        $trader_ts = Util::isoToTs($trader_last);
        if ($trader_ts !== null && (time() - $trader_ts) < $trader_min_gap) {
            trader_cron_exit('too soon', 429, 0);
        }
    }
    unset($trader_last, $trader_ts);
} catch (Throwable $e) {
    Log::error('cron: cannot read state - ' . $e->getMessage());
    trader_cron_exit('error database unavailable: ' . $e->getMessage(), 500, 1);
}

$trader_result = Bot::runLocked(static function () use ($trader_cfg): array {
    $db = Db::get();
    try {
        $ex = Exchange::factory($trader_cfg, $db);
    } catch (RuntimeException $e) {
        // mode != paper without keys (or an unknown mode): log and leave quietly
        Log::warn('cron: ' . $e->getMessage());
        return ['status' => 'skipped', 'summary' => $e->getMessage(), 'ms' => 0];
    }
    $bot = new Bot($trader_cfg, $db, $ex);
    // The scalp loop is NOT run from here. It is step 5-7 of the tick, inside the engine
    // branch of Bot::tick() (DESIGN-SCALP.md §4), so it sits behind the same survival layer,
    // the same reconciliation and the same symbol info as every other engine, and inside the
    // same flock - never beside them.
    return $bot->tick();
});

$trader_status  = (string) ($trader_result['status'] ?? 'error');
$trader_summary = (string) ($trader_result['summary'] ?? '');
$trader_ms      = (int) ($trader_result['ms'] ?? 0);
$trader_line    = $trader_status . ' ' . $trader_summary . ' (' . $trader_ms . ' ms)';

if ($trader_status === 'error') {
    trader_cron_exit($trader_line, 500, 1);
}
trader_cron_exit($trader_line, 200, 0);
