<?php
declare(strict_types=1);

/**
 * Common bootstrap for index.php, cron.php and tests: error handling, UTC,
 * TRADER_ROOT, and every lib/*.php in dependency order.
 */

if (!defined('TRADER_ROOT')) {
    define('TRADER_ROOT', __DIR__);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
date_default_timezone_set('UTC');

// PHP's own error log goes next to the bot log (create data/ early so ini_set has a target)
if (!is_dir(TRADER_ROOT . '/data')) {
    @mkdir(TRADER_ROOT . '/data', 0750, true);
}
if (is_dir(TRADER_ROOT . '/data') && is_writable(TRADER_ROOT . '/data')) {
    ini_set('error_log', TRADER_ROOT . '/data/bot.log');
}

set_exception_handler(static function (Throwable $e): void {
    $line = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    try {
        if (class_exists('Log', false)) {
            Log::error('Uncaught ' . $line, ['trace' => substr($e->getTraceAsString(), 0, 2000)]);
        } else {
            error_log('Uncaught ' . $line);
        }
    } catch (Throwable $inner) {
        error_log('Uncaught ' . $line);
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Fatal error - see data/bot.log\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo "Internal error - see data/bot.log\n";
    exit(1);
});

// dependency order; guarded so partial checkouts (tests, single-file tools) do not fatally error
foreach (['Util', 'Db', 'Log', 'Binance', 'Indicators', 'Strategy', 'Risk', 'Exchange', 'Sleeve', 'Scanner', 'Bot', 'Panel'] as $trader_lib) {
    $trader_file = __DIR__ . '/lib/' . $trader_lib . '.php';
    if (file_exists($trader_file)) {
        require_once $trader_file;
    }
}
unset($trader_lib, $trader_file);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}
