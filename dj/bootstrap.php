<?php
declare(strict_types=1);

/**
 * Common bootstrap for dj/index.php and dj/tests/run.php (DESIGN-DJ.md §3):
 * TRADER_DJ_ROOT, error handling, UTC, and every dj/lib/*.php in dependency order.
 *
 * This file — and everything it loads — is self-contained: the DJ desk never
 * requires, includes or reads a file belonging to the trading panel next door.
 */

if (!defined('TRADER_DJ_ROOT')) {
    define('TRADER_DJ_ROOT', __DIR__);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
date_default_timezone_set('UTC');

// PHP's own error log goes into data/ (created early so ini_set has a target).
if (!is_dir(TRADER_DJ_ROOT . '/data')) {
    @mkdir(TRADER_DJ_ROOT . '/data', 0750, true);
}
if (is_dir(TRADER_DJ_ROOT . '/data') && is_writable(TRADER_DJ_ROOT . '/data')) {
    ini_set('error_log', TRADER_DJ_ROOT . '/data/dj.log');
}

/**
 * Uncaught exceptions are logged with their trace and answered with a generic
 * message: DESIGN-DJ.md §8 — "Errors logged, never displayed."
 */
set_exception_handler(static function (Throwable $e): void {
    $line = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('[dj] Uncaught ' . $line . ' | ' . substr($e->getTraceAsString(), 0, 2000));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Fatal error - see dj/data/dj.log\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo "Internal error - see dj/data/dj.log\n";
    exit(1);
});

// dependency order; guarded so a partial checkout (a single tool, one test file)
// does not fatally error before it can say what is missing
foreach (['Util', 'Db', 'Auth', 'Data', 'Music', 'Render'] as $dj_lib) {
    $dj_file = __DIR__ . '/lib/' . $dj_lib . '.php';
    if (file_exists($dj_file)) {
        require_once $dj_file;
    }
}
unset($dj_lib, $dj_file);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
