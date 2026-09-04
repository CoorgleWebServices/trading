<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Util.php';

/**
 * Configuration (DESIGN.md §3): data/config.json edited through the panel.
 *  trader_config(bool $reload = false): array   defaults merged with the file
 *  trader_save_config(array $cfg): void         atomic write (tmp + rename), chmod 0600
 */

function trader_root(): string
{
    return defined('TRADER_ROOT') ? TRADER_ROOT : __DIR__;
}

function trader_data_dir(): string
{
    return trader_root() . '/data';
}

function trader_config_path(): string
{
    return trader_data_dir() . '/config.json';
}

/** Defaults table from DESIGN.md §3 plus DESIGN-ENGINES.md §2 (cron_key is filled with a random value on first use). */
function trader_config_defaults(): array
{
    return [
        'panel_password_hash'         => '',
        'cron_key'                    => '',
        'api_key'                     => '',
        'api_secret'                  => '',
        'mode'                        => 'paper',
        'enabled'                     => false,
        'force_https'                 => true,
        'symbols'                     => ['SOLUSDT', 'ETHUSDT', 'XRPUSDT', 'DOGEUSDT', 'BNBUSDT', 'ADAUSDT'],
        'quote_asset'                 => 'USDT',
        'trade_usdt'                  => 6.5,
        'equity_floor_usdt'           => 7.0,
        'hwm_drawdown_pct'            => 20.0,
        'daily_loss_cap_pct'          => 2.0,
        'weekly_loss_cap_pct'         => 5.0,
        'max_trades_per_day'          => 3,
        'max_orders_per_hour'         => 2,
        'max_consecutive_losses'      => 3,
        'cooldown_after_loss_min'     => 45,
        'cooldown_after_2_losses_min' => 180,
        'take_profit_pct'             => 1.0,
        'take_profit_max_pct'         => 2.0,
        'stop_loss_pct'               => 0.7,
        'trailing_activate_pct'       => 0.6,
        'trailing_distance_pct'       => 0.4,
        'trailing_floor_pct'          => 0.25,
        'max_hold_minutes'            => 240,
        'entry_threshold'             => 60,
        'adaptive'                    => true,
        'candle_interval'             => '15m',
        'trend_interval'              => '1h',
        'atr_min_pct'                 => 0.30,
        'atr_max_pct'                 => 1.5,
        'atr1h_min_pct'               => 0.5,
        'atr1h_max_pct'               => 3.0,
        'max_spread_pct'              => 0.05,
        'fee_pct'                     => 0.1,
        'paper_start_usdt'            => 10.0,
        'recv_window'                 => 10000,
        'timezone'                    => 'UTC',

        // --- engines (docs/DESIGN-ENGINES.md §2). They live here, not only in the panel's
        //     form fallback, so a config.json written before the engines existed still comes
        //     back from trader_config() with every key present and correctly typed.
        'engine'                      => 'signal',
        'allow_live_engines'          => false,
        'engine_symbol'               => 'DOGEUSDT',
        'engine_max_orders'           => 12,
        'post_only'                   => true,
        'grid_levels'                 => 6,
        'grid_spacing_pct'            => 0.60,
        'grid_order_usdt'             => 1.30,
        'grid_range_up_pct'           => 4.0,
        'grid_range_down_pct'         => 6.0,
        'grid_exit_liquidates'        => false,
        'pmm_spread_pct'              => 0.25,
        'pmm_order_usdt'              => 1.30,
        'pmm_refresh_sec'             => 60,
        'pmm_target_base_pct'         => 50.0,
        'pmm_max_base_pct'            => 80.0,

        // --- portfolio mode (docs/DESIGN-PORTFOLIO.md §2). Same reason as the engine keys:
        //     a config.json written before portfolio mode existed still comes back complete.
        //     `sleeves` is a nested map, so trader_config_merge() normalises it separately
        //     (trader_config_cast() only handles flat lists).
        'portfolio_enabled'           => false,
        'sleeve_reserve_pct'          => 5.0,
        'sleeve_max_drawdown_pct'     => 25.0,
        'scanner_enabled'             => true,
        'scanner_refresh_min'         => 60,
        'scanner_min_quote_vol'       => 5000000.0,
        'scanner_max_spread_pct'      => 0.06,
        'scanner_min_atr_pct'         => 0.5,
        'scanner_max_atr_pct'         => 4.0,
        'scanner_top_n'               => 10,
        'scanner_exclude'             => ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT'],
        'sleeves'                     => [
            'signal' => ['enabled' => true,  'budget_usdt' => 1000.0, 'symbols' => ['SOLUSDT', 'ETHUSDT']],
            'grid'   => ['enabled' => true,  'budget_usdt' => 1000.0, 'symbols' => ['DOGEUSDT']],
            'pmm'    => ['enabled' => false, 'budget_usdt' => 1000.0, 'symbols' => ['XRPUSDT']],
        ],

        // --- learning (docs/DESIGN-LEARNING.md §5) plus the §7 BNB warning threshold. Same
        //     reason as the engine and portfolio keys: a config.json written before learning
        //     existed still comes back from trader_config() complete and correctly typed.
        //     `learning_enabled` only captures observations and computes insights; nothing is
        //     fed back into scoring until `learning_apply` is switched on, and even then
        //     lib/Learn.php can write only the score weights and the effective threshold.
        'learning_enabled'            => true,
        'learning_apply'              => false,
        'learn_min_samples'           => 60,
        'learn_recompute_hours'       => 168,
        'learn_window_days'           => 90,
        'bnb_min_balance'             => 1.0,
    ];
}

/**
 * Normalise the nested `sleeves` map of DESIGN-PORTFOLIO.md §2 to
 * [engine => ['enabled'=>bool,'budget_usdt'=>float,'symbols'=>string[]]].
 * Unknown engine keys are kept (Risk::validateConfig() reports them); scalars are coerced.
 */
function trader_config_sleeves($value, array $default): array
{
    if (!is_array($value)) {
        return $default;
    }
    $out = [];
    foreach ($value as $engine => $sleeve) {
        $engine = strtolower(trim((string) $engine));
        if ($engine === '' || !is_array($sleeve)) {
            continue;
        }
        $symbols = [];
        $raw = isset($sleeve['symbols']) ? $sleeve['symbols'] : [];
        if (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', $raw);
            $raw = $parts === false ? [] : $parts;
        }
        if (is_array($raw)) {
            foreach ($raw as $sym) {
                if (!is_scalar($sym)) {
                    continue;
                }
                $sym = strtoupper(trim((string) $sym));
                if ($sym !== '' && !in_array($sym, $symbols, true)) {
                    $symbols[] = $sym;
                }
            }
        }
        $budget = isset($sleeve['budget_usdt']) && is_numeric($sleeve['budget_usdt'])
            ? (float) $sleeve['budget_usdt'] : 0.0;
        $out[$engine] = [
            'enabled'     => trader_config_cast(isset($sleeve['enabled']) ? $sleeve['enabled'] : false, false),
            'budget_usdt' => $budget < 0.0 ? 0.0 : $budget,
            'symbols'     => $symbols,
        ];
    }
    return $out === [] ? $default : $out;
}

/**
 * Create data/ (0750) and data/.htaccess when missing. Returns the directory.
 * @throws RuntimeException when the directory cannot be created
 */
function trader_ensure_data_dir(): string
{
    $dir = trader_data_dir();
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create data directory ' . $dir);
        }
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        $content = "# Deny all web access to the runtime directory\n"
                 . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                 . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n";
        @file_put_contents($ht, $content, LOCK_EX);
        @chmod($ht, 0640);
    }
    return $dir;
}

/** Coerce a loaded value to the PHP type of its default (bool/int/float/string/array). */
function trader_config_cast($value, $default)
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
    if (is_array($default)) {
        if (is_string($value)) {
            $parts = preg_split('/[\s,;]+/', $value);
            $value = $parts === false ? [] : $parts;
        }
        if (!is_array($value)) {
            return $default;
        }
        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $s = trim((string) $item);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return $out;
    }
    if (is_scalar($value) || $value === null) {
        return (string) $value;
    }
    return $default;
}

/** Merge $cfg over the defaults with type coercion; unknown keys are kept as-is. */
function trader_config_merge(array $cfg): array
{
    $out = trader_config_defaults();
    foreach ($cfg as $k => $v) {
        if ($k === 'sleeves') {
            $out['sleeves'] = trader_config_sleeves($v, $out['sleeves']);
        } elseif (array_key_exists($k, $out)) {
            $out[$k] = trader_config_cast($v, $out[$k]);
        } else {
            $out[$k] = $v;
        }
    }
    $out['quote_asset'] = strtoupper(trim((string) $out['quote_asset']));
    $out['symbols'] = array_values(array_unique(array_map('strtoupper', $out['symbols'])));
    $out['mode'] = strtolower(trim((string) $out['mode']));
    if (!in_array($out['mode'], ['paper', 'demo', 'testnet', 'live'], true)) {
        $out['mode'] = 'paper';
    }
    return $out;
}

/** Defaults merged with data/config.json. Cached per request; $reload re-reads the file. */
function trader_config(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) {
        return $cache;
    }
    $file = [];
    $path = trader_config_path();
    if (is_file($path)) {
        // a present but unusable file must never fall back to the defaults: an empty
        // panel_password_hash reopens the public setup wizard, and the cron_key
        // auto-save below would then overwrite the damaged file with pure defaults
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read ' . $path . ' - refusing to run with default settings. '
                . 'Fix the file permissions (0600, owned by the PHP user).');
        }
        if (trim($raw) === '') {
            throw new RuntimeException($path . ' is empty - refusing to run with default settings. '
                . 'Restore it from a backup, or delete it to run the setup wizard again.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException($path . ' is not valid JSON (' . json_last_error_msg() . ') - refusing to run '
                . 'with default settings. Restore it from a backup, or delete it to run the setup wizard again.');
        }
        $file = $decoded;
    }
    $cfg = trader_config_merge($file);
    if ((string) $cfg['cron_key'] === '') {
        $cfg['cron_key'] = Util::randomHex(32);
        // persist so the HTTP cron key is stable across requests; never fatal if the dir is read-only
        try {
            trader_save_config($cfg);
        } catch (Throwable $e) {
            // keep the in-memory key for this request
        }
    }
    $cache = $cfg;
    return $cache;
}

/**
 * Atomically write the full config (defaults merged with $cfg) to data/config.json with mode 0600.
 * @throws RuntimeException when the file cannot be written
 */
function trader_save_config(array $cfg): void
{
    $dir  = trader_ensure_data_dir();
    $path = trader_config_path();
    $full = trader_config_merge($cfg);
    $json = json_encode($full, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) {
        throw new RuntimeException('Cannot encode config: ' . json_last_error_msg());
    }
    $tmp   = $dir . '/config.' . Util::randomHex(6) . '.tmp';
    $data  = $json . "\n";
    $wrote = @file_put_contents($tmp, $data, LOCK_EX);
    // a full disk / exceeded quota returns a short count, not false: never rename a truncated config
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
    // refresh the per-request cache
    trader_config(true);
}
