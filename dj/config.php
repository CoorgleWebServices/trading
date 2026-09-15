<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Util.php';

/**
 * Configuration (DESIGN-DJ.md §3): dj/data/config.json, edited through the desk.
 *   dj_config(bool $reload = false): array   defaults merged with the file
 *   dj_save_config(array $cfg): void         atomic write (tmp + rename), chmod 0600
 *
 * Self-contained: nothing here reads the trading panel's config.php or its
 * data directory. The two applications share a domain and nothing else.
 */

function dj_root(): string
{
    return defined('TRADER_DJ_ROOT') ? TRADER_DJ_ROOT : __DIR__;
}

function dj_data_dir(): string
{
    return dj_root() . '/data';
}

function dj_config_path(): string
{
    return dj_data_dir() . '/config.json';
}

/** The four keys of DESIGN-DJ.md §3 and their defaults. */
function dj_config_defaults(): array
{
    return [
        'password_hash' => '',      // password_hash(); empty => the setup screen (§8)
        'force_https'   => true,    // redirect http->https and set the secure cookie flag
        'timezone'      => 'UTC',   // display only; storage is UTC throughout (§4)
        'theme'         => 'auto',  // auto | light | dark (§9)
    ];
}

/**
 * Create data/ (0750) and data/.htaccess when missing. Returns the directory.
 *
 * @throws RuntimeException when the directory cannot be created
 */
function dj_ensure_data_dir(): string
{
    $dir = dj_data_dir();
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create data directory ' . $dir);
        }
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        $content = "# Deny all web access to the DJ desk's runtime directory\n"
                 . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                 . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
        @file_put_contents($ht, $content, LOCK_EX);
        @chmod($ht, 0640);
    }
    return $dir;
}

/**
 * Coerce a loaded value to the PHP type of its default (bool/int/float/string).
 *
 * @param mixed $value
 * @param mixed $default
 * @return mixed
 */
function dj_config_cast($value, $default)
{
    if (is_bool($default)) {
        if (is_string($value)) {
            $v = strtolower(trim($value));
            return in_array($v, ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $value;
    }
    if (is_int($default)) {
        return is_numeric($value) ? (int) $value : $default;
    }
    if (is_float($default)) {
        return is_numeric($value) ? (float) $value : $default;
    }
    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }
    return $default;
}

/** Merge $cfg over the defaults with type coercion; unknown keys are kept as-is. */
function dj_config_merge(array $cfg): array
{
    $out = dj_config_defaults();
    foreach ($cfg as $k => $v) {
        if (array_key_exists($k, $out)) {
            $out[$k] = dj_config_cast($v, $out[$k]);
        } else {
            $out[$k] = $v;
        }
    }
    $out['theme'] = strtolower(trim((string) $out['theme']));
    if (!in_array($out['theme'], ['auto', 'light', 'dark'], true)) {
        $out['theme'] = 'auto';
    }
    $out['timezone'] = trim((string) $out['timezone']);
    if ($out['timezone'] === '' || !in_array($out['timezone'], timezone_identifiers_list(), true)) {
        $out['timezone'] = 'UTC';
    }
    $out['password_hash'] = trim((string) $out['password_hash']);
    return $out;
}

/**
 * Defaults merged with data/config.json. Cached per request; $reload re-reads the file.
 *
 * A present but unusable file FAILS CLOSED — it throws rather than quietly
 * handing back the defaults. The defaults carry an empty `password_hash`, and
 * an empty password hash reopens the setup screen to whoever asks for it; a
 * damaged config must never turn into an open door.
 *
 * @throws RuntimeException when data/config.json exists but cannot be used
 */
function dj_config(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $file = [];
    $path = dj_config_path();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read ' . $path . ' - refusing to run with default settings, '
                . 'which would reopen the setup screen. Fix the file permissions (0600, owned by the PHP user).');
        }
        if (trim($raw) === '') {
            throw new RuntimeException($path . ' is empty - refusing to run with default settings, which would '
                . 'reopen the setup screen. Restore it from a backup, or delete it to run setup again.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException($path . ' is not valid JSON (' . json_last_error_msg() . ') - refusing to run '
                . 'with default settings, which would reopen the setup screen. Restore it from a backup, or delete '
                . 'it to run setup again.');
        }
        $file = $decoded;
    }
    $cache = dj_config_merge($file);
    return $cache;
}

/**
 * Atomically write the full config (defaults merged with $cfg) to data/config.json
 * with mode 0600, then refresh the per-request cache.
 *
 * @throws RuntimeException when the file cannot be written
 */
function dj_save_config(array $cfg): void
{
    $dir  = dj_ensure_data_dir();
    $path = dj_config_path();
    $full = dj_config_merge($cfg);
    $json = json_encode($full, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        throw new RuntimeException('Cannot encode config: ' . json_last_error_msg());
    }
    $tmp   = $dir . '/config.' . Util::randomHex(6) . '.tmp';
    $data  = $json . "\n";
    $wrote = @file_put_contents($tmp, $data, LOCK_EX);
    // a full disk or an exceeded quota returns a short count, not false:
    // never rename a truncated config into place
    if ($wrote === false || $wrote !== strlen($data)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot write ' . $tmp . ' (disk full or quota exceeded?)');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Cannot replace ' . $path);
    }
    @chmod($path, 0600);
    dj_config(true);
}
