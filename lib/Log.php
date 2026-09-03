<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';

/**
 * Log::info / warn / error / debug ($msg, array $ctx = []).
 * Every line goes to the Db `logs` table (levels INFO/WARN/ERROR/DEBUG) and is
 * appended to data/bot.log, which rotates to bot.log.1 once it exceeds 2 MB.
 * Context keys that look like secrets are redacted before anything is stored.
 */
final class Log
{
    const MAX_BYTES = 2097152; // 2 MB

    /** Context keys (case-insensitive substring match) whose values are never written. */
    const SECRET_KEYS = ['secret', 'signature', 'password', 'passwd', 'api_key', 'apikey', 'cron_key', 'authorization', 'x-mbx-apikey', 'token', 'hash'];

    /** @var string|null override for tests */
    private static $path = null;

    /** @var bool set to true while a write is failing, to avoid recursion */
    private static $busy = false;

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('WARN', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::write('DEBUG', $msg, $ctx);
    }

    /** Path of the text log (default TRADER_ROOT/data/bot.log). */
    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }
        $root = defined('TRADER_ROOT') ? TRADER_ROOT : dirname(__DIR__);
        return $root . '/data/bot.log';
    }

    /** Override the text log location (tests); null restores the default. */
    public static function setPath(?string $path): void
    {
        self::$path = $path;
    }

    /** Recursively replace secret-looking values with '[redacted]'; stray long hex/base64 strings are shortened. */
    public static function redact(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            $key = strtolower((string) $k);
            $isSecret = false;
            foreach (self::SECRET_KEYS as $needle) {
                if (strpos($key, $needle) !== false) {
                    $isSecret = true;
                    break;
                }
            }
            if ($isSecret) {
                $out[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_object($v)) {
                $out[$k] = $v instanceof Throwable ? get_class($v) . ': ' . $v->getMessage() : get_class($v);
            } elseif (is_string($v) && strlen($v) >= 64 && preg_match('/^[A-Za-z0-9+\/=_-]{64,}$/', $v)) {
                $out[$k] = substr($v, 0, 4) . '…' . substr($v, -4);
            } elseif (is_float($v) && !is_finite($v)) {
                $out[$k] = 0;
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** One text line: "2026-09-03T08:42:00Z [INFO] message {"k":"v"}" */
    public static function formatLine(string $level, string $msg, array $ctx = []): string
    {
        $line = Util::nowIso() . ' [' . $level . '] ' . str_replace(["\r", "\n"], ' ', $msg);
        if ($ctx !== []) {
            $json = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        return $line . "\n";
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        if (self::$busy) {
            return;
        }
        self::$busy = true;
        $ctx = self::redact($ctx);
        try {
            Db::get()->log($level, $msg, $ctx);
        } catch (Throwable $e) {
            // database unavailable: still keep the text log
            self::appendFile(self::formatLine('ERROR', 'Log: db write failed: ' . $e->getMessage()));
        }
        self::appendFile(self::formatLine($level, $msg, $ctx));
        self::$busy = false;
    }

    private static function appendFile(string $line): void
    {
        $path = self::path();
        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            self::rotate($path);
            @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
            if (is_file($path)) {
                @chmod($path, 0640);
            }
        } catch (Throwable $e) {
            // never let logging break the bot
        }
    }

    /** bot.log → bot.log.1 once it exceeds MAX_BYTES (the previous .1 is replaced). */
    public static function rotate(string $path): void
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return;
        }
        $size = @filesize($path);
        if ($size === false || $size <= self::MAX_BYTES) {
            return;
        }
        $old = $path . '.1';
        if (is_file($old)) {
            @unlink($old);
        }
        @rename($path, $old);
    }
}
