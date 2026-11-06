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

/** Defaults table from DESIGN.md §3 plus(the removed grid/pmm contract)(cron_key is filled with a random value on first use). */
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

        // --- engine selection. `signal` trades the watchlist one position at a time; `scalp`
        //     (docs/DESIGN-SCALP.md) trades many symbols many times a minute.
        //
        //     The grid and pmm engines, and portfolio mode with its sleeves and volatility
        //     scanner, were REMOVED. pmm could not clear a 0.2 % round trip at VIP0 fees, and
        //     portfolio mode quietly took the tick over from whichever engine was selected - a
        //     scalp install with `portfolio_enabled` left on ran the sleeves and never the scalp
        //     loop, which looks exactly like a broken engine and is precisely how it was found.
        //     Its per-engine budgets are superseded by the scalp engine's own per-symbol budgets
        //     (DESIGN-SCALP.md §7).
        //
        //     `allow_live_engines` stays: it is the demo-only guard the scalp engine runs behind.
        'engine'                      => 'signal',
        'allow_live_engines'          => false,

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

        // --- scalp engine (docs/DESIGN-SCALP.md §6): fast, many-symbol micro-trading. Same
        //     reason as the engine and portfolio keys - a config.json written before the scalp
        //     engine existed still comes back from trader_config() complete and typed.
        //
        //     The one key worth reading twice is `scalp_tp_pct`. With `fee_pct` at 0.1 the
        //     target is measured on the bid against entry_eff, which already carries the BUY
        //     fee, so 0.25 nets about +0.15 % on a win while 0.20 loses about -0.30 % - a
        //     break-even win rate near 67 %. Risk::validateConfig() says so explicitly rather
        //     than letting the arithmetic stay buried here.
        'scalp_universe_size'         => 20,
        // 50, not the signal engine's 6. The dust rule (Movers, 0.3 % of one order per quantity
        // step) is what decides how much of the market the board can even offer: one stepSize of
        // SOL costs ~0.13 USDT, of ETH ~0.24, of BTC ~0.68, so a 6 USDT order gates every one of
        // them `dust_step` and leaves a universe of only the very cheapest coins - and on any
        // symbol that did slip through, a sixth of the position would be left as unsellable dust
        // and a winning take-profit would still close at a loss. 50 admits most liquid pairs,
        // is half of the 100 USDT default per-symbol budget, and at `scalp_max_open` 6 deploys
        // at most 300 USDT.
        'scalp_order_usdt'            => 50.0,
        'scalp_budget_per_symbol'     => 100.0,
        // Per-symbol overrides, e.g. {"BTCUSDT":200,"DOGEUSDT":50}. A MAP, not a list, so
        // trader_config_merge() routes it through trader_config_budgets() instead of
        // trader_config_cast(), which would flatten the keys away.
        'scalp_budgets'               => [],
        'scalp_max_open'              => 6,
        'scalp_tp_pct'                => 0.25,
        'scalp_sl_pct'                => 0.20,
        'scalp_max_hold_sec'          => 120,
        'scalp_dip_pct'               => 0.15,
        // 8, not 20. The ring buffer is IN MEMORY and cron builds a fresh engine every minute,
        // so the window has to fill inside one `scalp_run_seconds` loop or it never fills at all
        // and every poll is refused `window_warming` - an engine that looks healthy and places
        // no order, ever. At the default 3 s poll a 50 s loop affords 16 polls, so 8 ticks of
        // warm-up leaves 8 polls to actually trade in. Risk::validateConfig() warns when a
        // configured window does not fit.
        'scalp_window'                => 8,
        'scalp_poll_sec'              => 3,
        'scalp_run_seconds'           => 50,
        'scalp_max_consecutive_losses' => 5,
        'scalp_max_trades_per_hour'   => 60,
        'scalp_min_quote_vol'         => 20000000.0,
        'scalp_min_trade_count'       => 50000,
        'scalp_max_spread_pct'        => 0.05,
        'scalp_universe_refresh_min'  => 10,
        'scalp_exclude'               => ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT', 'USDPUSDT'],
    ];
}

/**
 * Normalise `scalp_budgets` (DESIGN-SCALP.md §7) to [SYMBOL => float].
 *
 * It is a MAP of symbol to cap, which trader_config_cast() cannot handle - its array branch
 * builds a LIST and would throw the symbols away, leaving every override silently ignored and
 * every symbol back on the default budget. Accepts a decoded map, or the JSON string the panel
 * form posts. Negative and non-numeric caps are dropped rather than coerced to 0: a cap of 0
 * stops a symbol trading entirely, which is not what a typo should do.
 */
function trader_config_budgets($value, array $default): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            return $default;
        }
        $value = $decoded;
    }
    if (!is_array($value)) {
        return $default;
    }
    $out = [];
    foreach ($value as $sym => $cap) {
        $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $sym));
        if ($sym === '' || !is_numeric($cap)) {
            continue;
        }
        $f = (float) $cap;
        if (!is_finite($f) || $f < 0.0) {
            continue;
        }
        $out[$sym] = $f;
    }
    return $out;
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
        if ($k === 'scalp_budgets') {
            $out['scalp_budgets'] = trader_config_budgets($v, $out['scalp_budgets']);
        } elseif (array_key_exists($k, $out)) {
            $out[$k] = trader_config_cast($v, $out[$k]);
        } else {
            $out[$k] = $v;
        }
    }
    $out['quote_asset'] = strtoupper(trim((string) $out['quote_asset']));
    $out['symbols'] = array_values(array_unique(array_map('strtoupper', $out['symbols'])));
    // MIGRATION. The grid and pmm engines were removed, and so was portfolio mode. A config.json
    // saved while one of them was selected must not leave the bot pointing at an engine that no
    // longer exists - Risk::engineName() would fall back to `signal` silently and the user would
    // never be told why their engine changed. Rewrite it here, once, and leave a breadcrumb in
    // `engine_migrated_from` so the panel can say so.
    $engine = strtolower(trim((string) ($out['engine'] ?? 'signal')));
    if ($engine === 'grid' || $engine === 'pmm') {
        $out['engine_migrated_from'] = $engine;
        $out['engine'] = 'signal';
    }
    // Portfolio mode used to take the tick over from whichever engine was selected, so leaving a
    // stale `portfolio_enabled` in the file would do nothing now but confuse anyone reading it.
    unset($out['portfolio_enabled'], $out['sleeves'], $out['sleeve_reserve_pct'],
          $out['sleeve_max_drawdown_pct']);

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
