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

set_time_limit(55);

// setup wizard not completed: nothing to run yet
if ((string) ($trader_cfg['panel_password_hash'] ?? '') === '') {
    trader_cron_exit('setup incomplete - open the panel in a browser first', 503, 0);
}

// double-trigger guard: refuse when the previous tick started less than 50 s ago
try {
    $trader_last = (string) Db::get()->getState('last_tick_at', '');
    if ($trader_last !== '') {
        $trader_ts = Util::isoToTs($trader_last);
        if ($trader_ts !== null && (time() - $trader_ts) < 50) {
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
