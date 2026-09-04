<?php
declare(strict_types=1);

/**
 * Panel entry point (DESIGN.md §12): setup wizard, login, dashboard, settings,
 * POST actions and ?api=status JSON. Every dynamic value goes through Panel::e();
 * no inline scripts, handlers or style attributes (CSP default-src 'self').
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Panel.php';

try {
    $cfg = trader_config();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "Configuration error: " . $e->getMessage() . "\n";
    exit(1);
}
Panel::boot($cfg);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$page   = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'dashboard';
$action = $method === 'POST' && isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$isApi  = isset($_GET['api']) && $_GET['api'] === 'status';

/* ------------------------------------------------------------------ runtime */

try {
    trader_ensure_data_dir();
    $db = Db::get();
} catch (Throwable $e) {
    Log::error('panel: storage unavailable - ' . $e->getMessage());
    if ($isApi) {
        Panel::json(['ok' => false, 'error' => 'storage unavailable'], 500);
    }
    http_response_code(500);
    $detail = Panel::isLoggedIn()
        ? '<p class="danger">' . Panel::e($e->getMessage()) . '</p>'
        : '<p class="danger">The data directory or database could not be opened. The details were written to the server log '
          . '(<code>data/bot.log</code> when it is writable).</p>';
    echo panel_layout('Storage error', '<section class="card"><h2>Storage error</h2>' . $detail
        . '<p>Make sure the <code>data/</code> directory next to index.php is writable by PHP and that the '
        . '<code>pdo_sqlite</code> extension is enabled.</p></section>', ['nav' => false]);
    exit;
}

$setupDone = (string) ($cfg['panel_password_hash'] ?? '') !== '';

/* --------------------------------------------------------------------- csrf */

if ($method === 'POST' && !Panel::csrfCheck()) {
    if ($isApi) {
        Panel::json(['ok' => false, 'error' => 'csrf'], 403);
    }
    http_response_code(403);
    echo panel_layout('Request rejected', '<section class="card"><h2>Request rejected</h2><p>The form token was missing or expired '
        . '(the session times out after 30 minutes). Please go back and try again.</p><p><a class="btn" href="index.php">Back to the panel</a></p></section>',
        ['nav' => false]);
    exit;
}

/* ---------------------------------------------------------------------- api */

if ($isApi) {
    if (!$setupDone || !Panel::isLoggedIn()) {
        Panel::json(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    Panel::json(Panel::status($cfg, $db));
}

/* -------------------------------------------------------------------- setup */

if (!$setupDone) {
    $values = ['mode' => 'paper', 'api_key' => '', 'symbols' => implode(', ', $cfg['symbols']), 'force_https' => !empty($cfg['force_https'])];
    $errors = [];
    if ($action === 'setup') {
        $pw  = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $pw2 = isset($_POST['password2']) && is_string($_POST['password2']) ? $_POST['password2'] : '';
        $values['mode']        = isset($_POST['mode']) && is_string($_POST['mode']) ? strtolower(trim($_POST['mode'])) : 'paper';
        $values['api_key']     = isset($_POST['api_key']) && is_string($_POST['api_key']) ? trim($_POST['api_key']) : '';
        $values['symbols']     = isset($_POST['symbols']) && is_string($_POST['symbols']) ? trim($_POST['symbols']) : '';
        $values['force_https'] = isset($_POST['force_https']);
        $secret = isset($_POST['api_secret']) && is_string($_POST['api_secret']) ? trim($_POST['api_secret']) : '';

        if (strlen($pw) < 12) {
            $errors[] = 'The password must be at least 12 characters long.';
        } elseif ($pw !== $pw2) {
            $errors[] = 'The two passwords do not match.';
        }
        $in = [
            'mode'        => $values['mode'],
            'symbols'     => $values['symbols'],
            'force_https' => $values['force_https'] ? '1' : '0',
            'api_key'     => $values['api_key'],
            'api_secret'  => $secret,
        ];
        list($newCfg, $vErrors) = Risk::validateConfig($in, $cfg);
        foreach ($vErrors as $ve) {
            $errors[] = (string) $ve;
        }
        if ($values['mode'] !== 'paper' && ((string) $newCfg['api_key'] === '' || (string) $newCfg['api_secret'] === '')) {
            $errors[] = 'Demo, testnet and live mode need both an API key and a secret - or start in paper mode.';
        }
        if ($values['mode'] === 'live' && !isset($_POST['live_confirm'])) {
            $errors[] = 'Live mode requires the confirmation checkbox.';
        }
        if ($errors === []) {
            $newCfg['panel_password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            $newCfg['enabled'] = false;
            if ((string) ($newCfg['cron_key'] ?? '') === '') {
                $newCfg['cron_key'] = Util::randomHex(32);
            }
            try {
                trader_save_config($newCfg);
                $db->migrate();
                Log::info('panel: setup completed', ['mode' => $newCfg['mode'], 'ip' => Panel::clientIp()]);
                Panel::login();
                Panel::flash('ok', 'Setup complete. Add the cron job below so the bot ticks once per minute.');
                Panel::redirect('?page=cron');
            } catch (Throwable $e) {
                $errors[] = 'Could not save the configuration: ' . $e->getMessage();
            }
        }
    }
    echo panel_layout('Setup', page_setup($values, $errors), ['nav' => false]);
    exit;
}

/* -------------------------------------------------------------------- login */

if (!Panel::isLoggedIn()) {
    $error = '';
    if ($action === 'login') {
        $pw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (Panel::attemptLogin($cfg, $db, $pw)) {
            Log::info('panel: login', ['ip' => Panel::clientIp()]);
            Panel::redirect('?page=dashboard');
        }
        $error = 'Login failed. Check the password - after 5 failed attempts the address is locked for 15 minutes.';
    }
    echo panel_layout('Login', page_login($error), ['nav' => false]);
    exit;
}

/* ------------------------------------------------------------------ actions */

if ($action !== '') {
    handle_action($action, $cfg, $db);
    // handle_action redirects, except for settings pages that render inline
}

/* -------------------------------------------------------------------- pages */

if ($page === 'settings') {
    echo panel_layout('Settings', page_settings($cfg, $db, $cfg, [], ''), ['page' => 'settings']);
} elseif ($page === 'cron') {
    echo panel_layout('Cron', page_cron($cfg), ['page' => 'cron']);
} else {
    $status = Panel::status($cfg, $db);
    echo panel_layout('Dashboard', page_dashboard($cfg, $status), ['page' => 'dashboard', 'status' => $status, 'autorefresh' => true]);
}
exit;

/* ====================================================================== */
/*                                 actions                                */
/* ====================================================================== */

function panel_keys_missing(array $cfg): bool
{
    return (string) ($cfg['mode'] ?? 'paper') !== 'paper'
        && (trim((string) ($cfg['api_key'] ?? '')) === '' || trim((string) ($cfg['api_secret'] ?? '')) === '');
}

function handle_action(string $action, array $cfg, Db $db): void
{
    switch ($action) {
        case 'logout':
            Log::info('panel: logout', ['ip' => Panel::clientIp()]);
            Panel::logout();
            Panel::flash('info', 'You have been logged out.');
            Panel::redirect('index.php');
            return;

        case 'start':
            if ($db->getState('halted', '0') === '1') {
                Panel::flash('danger', 'Cannot start: the bot is HALTED (' . (string) $db->getState('halt_reason', '') . '). Reset the halt first.');
                Panel::redirect('?page=dashboard');
            }
            if (panel_keys_missing($cfg)) {
                Panel::flash('danger', 'Cannot start: mode "' . (string) $cfg['mode'] . '" needs an API key and secret (Settings).');
                Panel::redirect('?page=dashboard');
            }
            $cfg['enabled'] = true;
            try {
                trader_save_config($cfg);
                $db->setState('pause_reason', null);
                Log::info('panel: start (entries enabled)', ['mode' => $cfg['mode']]);
                Panel::flash('ok', 'Entries enabled. The cron tick will look for setups from the next closed candle.');
            } catch (Throwable $e) {
                Panel::flash('danger', 'Could not save: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'pause':
            $cfg['enabled'] = false;
            try {
                trader_save_config($cfg);
                $db->setState('pause_reason', 'manual');
                Log::info('panel: pause (entries disabled)');
                Panel::flash('ok', 'Entries paused. An open position is still managed (stop / take-profit / trailing) by the tick.');
            } catch (Throwable $e) {
                Panel::flash('danger', 'Could not save: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'reset_halt':
            try {
                $reason = (string) $db->getState('halt_reason', '');
                $db->setState('halted', '0');
                $db->setState('halt_reason', null);
                // Re-base the high-water mark down to the last recorded equity, otherwise the
                // drawdown kill switch re-fires on the very next tick and the reset is a no-op.
                // The absolute equity_floor is left alone on purpose: it must keep re-halting.
                $eqRow  = $db->lastEquity();
                $eqNow  = $eqRow !== null && isset($eqRow['equity_usdt']) && is_numeric($eqRow['equity_usdt'])
                    ? (float) $eqRow['equity_usdt'] : 0.0;
                $hwmRaw = $db->getState('equity_hwm', null);
                $hwmOld = is_numeric($hwmRaw) ? (float) $hwmRaw : 0.0;
                $note   = '';
                if (is_finite($eqNow) && $eqNow > 0.0 && $eqNow < $hwmOld) {
                    $db->setState('equity_hwm', $eqNow);
                    $note = ' High-water mark re-based from ' . Util::money($hwmOld, 4) . ' to ' . Util::money($eqNow, 4) . '.';
                }
                Log::warn('panel: halt reset by user', [
                    'previous_reason' => $reason,
                    'hwm_from'        => $hwmOld,
                    'hwm_to'          => $note !== '' ? $eqNow : $hwmOld,
                ]);
                Panel::flash('ok', 'Halt cleared' . ($reason !== '' ? ' (was: ' . $reason . ')' : '') . '. The bot stays paused until you press Start.' . $note);
            } catch (Throwable $e) {
                Panel::flash('danger', 'Could not reset: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'panic_sell':
            if (!isset($_POST['confirm']) || $_POST['confirm'] !== '1') {
                Panel::flash('warn', 'Panic sell not executed: tick the confirmation box first.');
                Panel::redirect('?page=dashboard');
            }
            if (!class_exists('Bot')) {
                Panel::flash('danger', 'lib/Bot.php is missing - cannot sell.');
                Panel::redirect('?page=dashboard');
            }
            set_time_limit(55);
            try {
                $fresh = trader_config(true);
                $res = Bot::runLocked(static function () use ($fresh, $db): array {
                    $ex  = Exchange::factory($fresh, $db);
                    $bot = new Bot($fresh, $db, $ex);
                    $r   = $bot->panicSell();
                    return ['status' => !empty($r['ok']) ? 'ok' : 'error', 'summary' => (string) ($r['message'] ?? ''), 'ms' => 0];
                });
                if (($res['status'] ?? '') === 'skipped') {
                    Panel::flash('warn', 'Panic sell skipped: a tick is running right now. Try again in a few seconds.');
                } else {
                    Panel::flash(($res['status'] ?? '') === 'ok' ? 'ok' : 'warn', 'Panic sell: ' . (string) ($res['summary'] ?? ''));
                }
            } catch (Throwable $e) {
                Log::error('panel: panic sell failed - ' . $e->getMessage());
                Panel::flash('danger', 'Panic sell failed: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'run_tick':
            if (!class_exists('Bot')) {
                Panel::flash('danger', 'lib/Bot.php is missing - cannot run a tick.');
                Panel::redirect('?page=dashboard');
            }
            set_time_limit(55);
            try {
                $fresh = trader_config(true);
                $t0 = microtime(true);
                $res = Bot::runLocked(static function () use ($fresh, $db): array {
                    $ex  = Exchange::factory($fresh, $db);
                    $bot = new Bot($fresh, $db, $ex);
                    return $bot->tick();
                });
                $status  = (string) ($res['status'] ?? 'ok');
                $summary = (string) ($res['summary'] ?? '');
                $ms      = isset($res['ms']) && (int) $res['ms'] > 0 ? (int) $res['ms'] : (int) round((microtime(true) - $t0) * 1000);
                $level   = $status === 'ok' ? 'ok' : ($status === 'skipped' ? 'warn' : 'danger');
                Panel::flash($level, 'Tick ' . $status . ' in ' . $ms . ' ms' . ($summary !== '' ? ': ' . $summary : ''));
            } catch (Throwable $e) {
                Log::error('panel: manual tick failed - ' . $e->getMessage());
                Panel::flash('danger', 'Tick failed: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'reset_paper':
            if ((string) ($cfg['mode'] ?? 'paper') !== 'paper') {
                Panel::flash('danger', 'Paper reset is only available in paper mode.');
                Panel::redirect('?page=dashboard');
            }
            try {
                $open = $db->openPosition();
                if ($open !== null && (string) ($open['mode'] ?? 'paper') === 'paper') {
                    $db->updatePosition((int) $open['id'], [
                        'status' => 'CLOSED', 'exit_reason' => 'paper_reset', 'pnl_usdt' => 0.0, 'pnl_pct' => 0.0,
                        'exit_price' => (float) $open['entry_eff'], 'exit_quote' => (float) $open['entry_quote'], 'closed_at' => Util::nowIso(),
                    ]);
                }
                $ex = Exchange::factory($cfg, $db);
                if ($ex instanceof PaperExchange) {
                    $ex->reset();
                }
                // symbol_metrics is market telemetry, not account state, so it is deliberately kept
                foreach (['halted', 'halt_reason', 'paused_until', 'pause_reason', 'consecutive_losses', 'last_loss_at', 'cooldown_until',
                          'equity_hwm', 'day_start_equity', 'day_start_date', 'effective_threshold', 'effective_max_trades',
                          'last_adapt_date', 'adapt_max_since_closed', 'stuck_retry_at', 'no_trade_reason'] as $k) {
                    $db->setState($k, null);
                }
                Log::warn('panel: paper account reset', ['start_usdt' => (float) ($cfg['paper_start_usdt'] ?? 10.0)]);
                Panel::flash('ok', 'Paper balances reset to ' . Util::money((float) ($cfg['paper_start_usdt'] ?? 10.0), 2) . ' ' . (string) $cfg['quote_asset']
                    . '. Closed trades stay in the history; the kill-switch state was cleared.');
            } catch (Throwable $e) {
                Panel::flash('danger', 'Reset failed: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'cancel_order':
        case 'cancel_all':
        case 'reanchor_grid':
        case 'flatten_inventory':
            handle_engine_action($action, $cfg, $db);
            return;

        case 'save_settings':
        case 'test_api':
            handle_settings($action, $cfg, $db);
            return;

        default:
            Panel::flash('warn', 'Unknown action.');
            Panel::redirect('?page=dashboard');
            return;
    }
}

/* ------------------------------------------------------------ engine actions */

/**
 * The four engine actions of DESIGN-ENGINES.md §10, all POST + CSRF (checked globally before
 * routing) and all inside Bot::runLocked so they can never race a cron tick:
 *   cancel_order (one client_id) | cancel_all | reanchor_grid | flatten_inventory (confirm box)
 * Each one redirects back to the dashboard with a flash.
 */
function handle_engine_action(string $action, array $cfg, Db $db): void
{
    if (!class_exists('Bot')) {
        Panel::flash('danger', 'lib/Bot.php is missing - the engine actions are unavailable.');
        Panel::redirect('?page=dashboard');
    }
    if ($action === 'flatten_inventory' && (!isset($_POST['confirm']) || $_POST['confirm'] !== '1')) {
        Panel::flash('warn', 'Flatten inventory not executed: tick the confirmation box first.');
        Panel::redirect('?page=dashboard');
    }
    $clientId = '';
    if ($action === 'cancel_order') {
        $clientId = isset($_POST['client_id']) && is_string($_POST['client_id']) ? trim($_POST['client_id']) : '';
        if ($clientId === '' || strlen($clientId) > 64) {
            Panel::flash('warn', 'Cancel order: no order was named.');
            Panel::redirect('?page=dashboard');
        }
    }
    set_time_limit(55);
    try {
        $fresh = trader_config(true);
        $res = Bot::runLocked(static function () use ($fresh, $db, $action, $clientId): array {
            $ex  = Exchange::factory($fresh, $db);
            $bot = new Bot($fresh, $db, $ex);
            if ($action === 'cancel_all') {
                $n = $bot->cancelAllEngineOrders('panel_cancel_all');
                return ['status' => 'ok', 'summary' => $n . ' resting order(s) cancelled.', 'ms' => 0];
            }
            if ($action === 'reanchor_grid') {
                $bot->reanchorGrid();
                $anchor = (string) $db->getState('grid_anchor', '');
                return [
                    'status'  => $anchor !== '' ? 'ok' : 'error',
                    'summary' => $anchor !== ''
                        ? 'Grid re-anchored at ' . Util::money((float) $anchor, 6) . '; the pause was cleared.'
                        : 'Could not re-anchor: no book price for the engine symbol.',
                    'ms'      => 0,
                ];
            }
            if ($action === 'flatten_inventory') {
                $r = $bot->flattenInventory();
                return [
                    'status'  => !empty($r['ok']) ? 'ok' : 'error',
                    'summary' => (string) ($r['message'] ?? '')
                        . (isset($r['cancelled']) && (int) $r['cancelled'] > 0 ? ' ' . (int) $r['cancelled'] . ' resting order(s) cancelled.' : ''),
                    'ms'      => 0,
                ];
            }
            // cancel_order: one resting order, resolved through EngineOrders so a fill that
            // beat the cancel is still booked (lots / cycles) instead of being lost
            $row = $db->engineOrder($clientId);
            if ($row === null) {
                return ['status' => 'error', 'summary' => 'Unknown order ' . $clientId . '.', 'ms' => 0];
            }
            $symbol = strtoupper((string) $row['symbol']);
            $ok     = panel_engine_orders($fresh, $db, $ex, $symbol)->cancel($clientId);
            $after  = $db->engineOrder($clientId);
            $status = $after !== null ? strtoupper((string) $after['status']) : 'UNKNOWN';
            return [
                'status'  => $ok ? 'ok' : 'error',
                'summary' => $ok
                    ? 'Order ' . $clientId . ' left the book (' . $status . ').'
                    : 'Order ' . $clientId . ' could not be cancelled (' . $status . ').',
                'ms'      => 0,
            ];
        });
        $status = (string) ($res['status'] ?? 'ok');
        if ($status === 'skipped') {
            Panel::flash('warn', 'Skipped: a tick is running right now. Try again in a few seconds.');
        } else {
            Panel::flash($status === 'ok' ? 'ok' : 'warn', (string) ($res['summary'] ?? ''));
        }
    } catch (Throwable $e) {
        Log::error('panel: engine action ' . $action . ' failed - ' . $e->getMessage());
        Panel::flash('danger', 'Engine action failed: ' . $e->getMessage());
    }
    Panel::redirect('?page=dashboard');
}

/** EngineOrders bound to $symbol, using the cached symbol info so the panel spends no extra weight. */
function panel_engine_orders(array $cfg, Db $db, ExchangeInterface $ex, string $symbol): EngineOrders
{
    $cached = $db->getStateJson('symbol_info', []);
    $info   = (is_array($cached) && isset($cached[$symbol]) && is_array($cached[$symbol])) ? $cached[$symbol] : [];
    if ($info === []) {
        $fetched = $ex->symbolInfo([$symbol]);
        $info    = isset($fetched[$symbol]) && is_array($fetched[$symbol]) ? $fetched[$symbol] : [];
    }
    $cfg['engine_symbol'] = $symbol;
    return new EngineOrders($cfg, $db, $ex, $info);
}

/** Keys shown on the settings form (every §3 key except the hashes, cron_key and force_https). */
function settings_keys(): array
{
    return ['mode', 'enabled', 'api_key', 'api_secret', 'symbols', 'quote_asset', 'trade_usdt', 'equity_floor_usdt', 'hwm_drawdown_pct',
        'daily_loss_cap_pct', 'weekly_loss_cap_pct', 'max_trades_per_day', 'max_orders_per_hour', 'max_consecutive_losses',
        'cooldown_after_loss_min', 'cooldown_after_2_losses_min', 'take_profit_pct', 'take_profit_max_pct', 'stop_loss_pct',
        'trailing_activate_pct', 'trailing_distance_pct', 'trailing_floor_pct', 'max_hold_minutes', 'entry_threshold', 'adaptive',
        'candle_interval', 'trend_interval', 'atr_min_pct', 'atr_max_pct', 'atr1h_min_pct', 'atr1h_max_pct', 'max_spread_pct',
        'fee_pct', 'paper_start_usdt', 'recv_window', 'timezone',
        // engines (DESIGN-ENGINES.md §2)
        'engine', 'allow_live_engines', 'engine_symbol', 'engine_max_orders', 'post_only',
        'grid_levels', 'grid_spacing_pct', 'grid_order_usdt', 'grid_range_up_pct', 'grid_range_down_pct', 'grid_exit_liquidates',
        'pmm_spread_pct', 'pmm_order_usdt', 'pmm_refresh_sec', 'pmm_target_base_pct', 'pmm_max_base_pct'];
}

/** Settings keys rendered as checkboxes: absent from the POST means "off", never "unchanged". */
function settings_checkbox_keys(): array
{
    return ['enabled', 'adaptive', 'allow_live_engines', 'grid_exit_liquidates', 'post_only'];
}

function handle_settings(string $action, array $cfg, Db $db): void
{
    $in    = [];
    $boxes = settings_checkbox_keys();
    foreach (settings_keys() as $key) {
        if (in_array($key, $boxes, true)) {
            $in[$key] = isset($_POST[$key]) ? '1' : '0';
            continue;
        }
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $in[$key] = trim($_POST[$key]);
        }
    }
    // the secret is write-only: blank keeps the stored one (validateConfig skips blanks)
    $validated = Risk::validateConfig($in, $cfg);
    $newCfg    = $validated[0];
    $errors    = isset($validated[1]) && is_array($validated[1]) ? $validated[1] : [];
    $warnings  = isset($validated[2]) && is_array($validated[2]) ? $validated[2] : [];
    $newMode   = (string) ($newCfg['mode'] ?? 'paper');

    if ($action === 'test_api') {
        $result = panel_test_api($newCfg);
        // show the form again with the posted values (never the secret)
        $shown = $newCfg;
        $shown['api_secret'] = (string) ($cfg['api_secret'] ?? '');
        echo panel_layout('Settings', page_settings($cfg, $db, $shown, $errors, $result, $warnings), ['page' => 'settings']);
        exit;
    }

    if ($newMode === 'live' && (string) ($cfg['mode'] ?? '') !== 'live' && !isset($_POST['live_confirm'])) {
        $errors[] = 'Switching to LIVE requires the checkbox "I understand this trades real money".';
    }
    if ($newMode !== 'paper' && ((string) $newCfg['api_key'] === '' || (string) $newCfg['api_secret'] === '')) {
        $errors[] = 'Mode "' . $newMode . '" needs both an API key and a secret.';
    }
    if ($errors !== []) {
        echo panel_layout('Settings', page_settings($cfg, $db, $newCfg, $errors, '', $warnings), ['page' => 'settings']);
        exit;
    }
    // never let the form touch these
    $newCfg['panel_password_hash'] = $cfg['panel_password_hash'];
    $newCfg['cron_key']            = $cfg['cron_key'];
    $newCfg['force_https']         = !empty($cfg['force_https']);
    try {
        trader_save_config($newCfg);
        $changed = [];
        foreach (settings_keys() as $key) {
            if ($key === 'api_secret' || $key === 'api_key') {
                if ((string) ($cfg[$key] ?? '') !== (string) ($newCfg[$key] ?? '')) {
                    $changed[] = $key;
                }
                continue;
            }
            if (($cfg[$key] ?? null) != ($newCfg[$key] ?? null)) {
                $changed[] = $key;
            }
        }
        Log::info('panel: settings saved', ['changed' => $changed, 'mode' => $newMode]);
        if ((string) ($cfg['mode'] ?? '') !== $newMode) {
            Log::warn('panel: mode changed', ['from' => (string) ($cfg['mode'] ?? ''), 'to' => $newMode]);
            // The survival state belongs to the account we just left. Risk::survivalCheck()
            // re-seeds equity_hwm / day_start_equity from the new account on the next tick.
            // 'halted'/'halt_reason' are NOT cleared: a halt still needs a manual reset.
            foreach ([
                'equity_hwm', 'day_start_equity', 'day_start_date', 'consecutive_losses',
                'last_loss_at', 'cooldown_until', 'paused_until', 'pause_reason',
                'effective_threshold', 'effective_max_trades', 'last_adapt_date',
                'adapt_max_since_closed', 'no_trade_reason',
            ] as $k) {
                $db->setState($k, null);
            }
        }

        // The engine (or its symbol) just changed: no tick will ever sync the ladder the
        // previous engine left behind (Bot::tick() only runs an engine tick while engine
        // !== 'signal'), and the dashboard block that could cancel it is hidden as soon as
        // the engine card goes away. So take it off the book here. Inventory is kept: it is
        // real base, and the operator flattens or sells it deliberately.
        $engOld = strtolower(trim((string) ($cfg['engine'] ?? 'signal')));
        $engNew = strtolower(trim((string) ($newCfg['engine'] ?? 'signal')));
        $symOld = strtoupper(trim((string) ($cfg['engine_symbol'] ?? '')));
        $symNew = strtoupper(trim((string) ($newCfg['engine_symbol'] ?? '')));
        if (($engOld !== $engNew || $symOld !== $symNew) && class_exists('Bot')) {
            try {
                $res = Bot::runLocked(static function () use ($newCfg, $db): array {
                    $ex  = Exchange::factory($newCfg, $db);
                    $bot = new Bot($newCfg, $db, $ex);
                    return ['status' => 'ok', 'summary' => (string) $bot->cancelAllEngineOrders('engine_changed'), 'ms' => 0];
                });
                if ((string) ($res['status'] ?? '') === 'skipped') {
                    Panel::flash('warn', 'A tick was running, so the previous engine\'s resting orders were left on the book: press "Cancel all orders" on the dashboard.');
                } else {
                    $n = (int) ($res['summary'] ?? 0);
                    if ($n > 0) {
                        Panel::flash('warn', $n . ' resting order(s) of the previous engine were cancelled. Inventory it still holds stays in the wallet - switch back and use "Flatten inventory" to sell it.');
                    }
                }
            } catch (Throwable $e) {
                Log::error('panel: cancelling the previous engine orders failed - ' . $e->getMessage());
                Panel::flash('warn', 'The previous engine\'s resting orders could not be cancelled: ' . $e->getMessage());
            }
        }

        Panel::flash('ok', 'Settings saved' . ($changed !== [] ? ' (' . implode(', ', $changed) . ')' : ' (no changes)') . '.');
        foreach ($warnings as $w) {
            Panel::flash('warn', (string) $w);
        }
    } catch (Throwable $e) {
        Panel::flash('danger', 'Could not save: ' . $e->getMessage());
    }
    Panel::redirect('?page=settings');
}

/** account() + testOrder on the first symbol (keys given) or a public connectivity check (no keys). Returns a message. */
function panel_test_api(array $cfg): string
{
    $mode   = (string) ($cfg['mode'] ?? 'paper');
    $key    = trim((string) ($cfg['api_key'] ?? ''));
    $secret = trim((string) ($cfg['api_secret'] ?? ''));
    $recv   = (int) ($cfg['recv_window'] ?? 10000);
    $symbols = isset($cfg['symbols']) && is_array($cfg['symbols']) ? $cfg['symbols'] : [];
    $first  = $symbols !== [] ? (string) $symbols[0] : 'BTCUSDT';
    try {
        $api = new Binance($key, $secret, Binance::normalizeNetwork($mode), $recv);
        $offset = $api->syncTime();
        $book = $api->bookTicker($first);
        $msg = 'Connected to ' . $api->tradeUrl() . ' (time offset ' . $offset . ' ms, ' . $first . ' bid ' . Util::money((float) $book['bid'], 6)
             . ' / ask ' . Util::money((float) $book['ask'], 6) . ').';
        if ($key === '' || $secret === '') {
            return 'OK: ' . $msg . ' No API key given, so only public market data was tested' . ($mode === 'paper' ? ' (enough for paper mode).' : '.');
        }
        $acc = $api->account();
        $quote = (string) ($cfg['quote_asset'] ?? 'USDT');
        $qFree = isset($acc['balances'][$quote]) ? (float) $acc['balances'][$quote]['free'] : 0.0;
        $msg .= ' Account OK: canTrade=' . (!empty($acc['can_trade']) ? 'yes' : 'NO') . ', taker fee ' . Util::money((float) $acc['taker_fee_pct'], 3)
              . ' %, ' . count($acc['balances']) . ' non-zero balances, ' . $quote . ' free ' . Util::money($qFree, 4) . '.';
        $quoteQty = max((float) ($cfg['trade_usdt'] ?? 6.5), 6.0);
        try {
            $api->testOrder(['symbol' => $first, 'side' => 'BUY', 'type' => 'MARKET', 'quoteOrderQty' => Util::fmtQuote($quoteQty)]);
            $msg .= ' Test order (MARKET BUY ' . Util::fmtQuote($quoteQty) . ' ' . $quote . ' of ' . $first . ') accepted - the key has trading permission.';
        } catch (BinanceException $e) {
            $msg .= ' Test order REJECTED: ' . $e->getMessage() . ' (code ' . $e->binanceCode . ').';
            return 'PARTIAL: ' . $msg;
        }
        return 'OK: ' . $msg;
    } catch (BinanceException $e) {
        $hint = '';
        if ($e->binanceCode === -2015 || $e->binanceCode === -2014 || $e->httpStatus === 401) {
            $hint = ' Check the key/secret, that "Enable Spot & Margin Trading" is on and that this server IP is whitelisted.';
        } elseif ($e->httpStatus === 451) {
            $hint = ' HTTP 451: Binance is not available from this server location.';
        }
        return 'FAILED: ' . $e->getMessage() . ' (code ' . $e->binanceCode . ', HTTP ' . $e->httpStatus . ').' . $hint;
    } catch (Throwable $e) {
        return 'FAILED: ' . $e->getMessage();
    }
}

/* ====================================================================== */
/*                                  layout                                */
/* ====================================================================== */

function panel_layout(string $title, string $body, array $opts = []): string
{
    $nav      = !isset($opts['nav']) || $opts['nav'] !== false;
    $current  = (string) ($opts['page'] ?? '');
    $status   = isset($opts['status']) && is_array($opts['status']) ? $opts['status'] : null;
    $auto     = !empty($opts['autorefresh']);
    $flashes  = Panel::takeFlashes();
    $e = 'Panel::e';

    $h  = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    $h .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $h .= '<meta name="referrer" content="no-referrer">';
    $h .= '<title>' . $e($title) . ' - Micro-Trader</title>';
    $h .= '<link rel="stylesheet" href="assets/panel.css?v=1">';
    $h .= '</head><body' . ($auto ? ' data-autorefresh="20"' : '') . '>';
    $h .= '<header class="topbar"><div class="topbar-inner">';
    $h .= '<div class="brand"><span class="logo" aria-hidden="true">◈</span> Micro-Trader';
    if ($status !== null) {
        $h .= ' ' . Panel::pill((string) $status['text']['mode'], (string) $status['levels']['mode'], 'mode');
        $h .= ' ' . Panel::pill((string) $status['text']['bot_state'], (string) $status['levels']['bot_state'], 'bot_state');
        $h .= ' ' . Panel::pill((string) $status['text']['tick_age'], (string) $status['levels']['tick_age'], 'tick_age');
    }
    $h .= '</div>';
    if ($nav) {
        $h .= '<nav class="nav">';
        $h .= '<a href="?page=dashboard"' . ($current === 'dashboard' ? ' class="active"' : '') . '>Dashboard</a>';
        $h .= '<a href="?page=settings"' . ($current === 'settings' ? ' class="active"' : '') . '>Settings</a>';
        $h .= '<a href="?page=cron"' . ($current === 'cron' ? ' class="active"' : '') . '>Cron</a>';
        $h .= '<form method="post" action="index.php" class="inline">' . Panel::csrfField()
            . '<input type="hidden" name="action" value="logout"><button type="submit" class="linkbtn">Logout</button></form>';
        $h .= '</nav>';
    }
    $h .= '</div></header><main class="page">';
    foreach ($flashes as $f) {
        $h .= '<div class="flash flash-' . $e($f['type']) . '" role="status">' . $e($f['msg']) . '</div>';
    }
    $h .= $body;
    $h .= '</main><footer class="foot"><span>Binance Micro-Trader · paper / demo / testnet / live · nothing here is financial advice</span>';
    if ($auto) {
        $h .= '<span class="refresh-info" data-refresh-info>auto-refresh every 20 s</span>';
    }
    $h .= '</footer><script src="assets/panel.js?v=1" defer></script></body></html>';
    return $h;
}

/* ====================================================================== */
/*                                  pages                                 */
/* ====================================================================== */

function page_setup(array $v, array $errors): string
{
    $e = 'Panel::e';
    $h  = '<section class="card narrow"><h1>First-time setup</h1>';
    $h .= '<p class="muted">Choose the panel password and the trading mode. Paper mode needs no API keys and simulates fills at the live bid/ask. '
        . 'You can change everything later in Settings.</p>';
    $h .= page_errors($errors);
    $h .= '<form method="post" action="index.php" autocomplete="off" data-confirm="Start in LIVE mode? The bot will trade real money."'
        . ' data-confirm-field="mode" data-confirm-value="live">' . Panel::csrfField() . '<input type="hidden" name="action" value="setup">';
    $h .= '<div class="grid2">';
    $h .= '<label>Panel password (min. 12 characters)<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>';
    $h .= '<label>Repeat password<input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>';
    $h .= '<label>Mode<select name="mode">';
    $modeLabels = [
        'paper'   => 'paper - simulated fills, no keys needed',
        'demo'    => 'demo - Binance Demo Trading (demo.binance.com keys)',
        'testnet' => 'testnet - testnet.binance.vision keys',
        'live'    => 'live - REAL money',
    ];
    foreach ($modeLabels as $k => $label) {
        $h .= '<option value="' . $e($k) . '"' . ($v['mode'] === $k ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $h .= '</select></label>';
    $h .= '<label>Watchlist (USDT pairs, comma separated)<input type="text" name="symbols" value="' . $e($v['symbols']) . '"></label>';
    $h .= '<label>Binance API key (optional in paper mode)<input type="text" name="api_key" value="' . $e($v['api_key']) . '" autocomplete="off" spellcheck="false"></label>';
    $h .= '<label>Binance API secret<input type="password" name="api_secret" value="" autocomplete="new-password"></label>';
    $h .= '</div>';
    $h .= '<label class="check"><input type="checkbox" name="force_https" value="1"' . (!empty($v['force_https']) ? ' checked' : '') . '> Force HTTPS (redirect http → https, secure cookie). Untick only if your host has no TLS.</label>';
    $h .= '<label class="check"><input type="checkbox" name="live_confirm" value="1"> I understand that <strong>live</strong> mode trades real money and that losses are possible.</label>';
    $h .= '<p class="actions"><button type="submit" class="btn btn-primary">Create panel</button></p>';
    $h .= '</form></section>';
    return $h;
}

function page_login(string $error): string
{
    $h  = '<section class="card narrow"><h1>Login</h1>';
    if ($error !== '') {
        $h .= '<div class="flash flash-danger">' . Panel::e($error) . '</div>';
    }
    $h .= '<form method="post" action="index.php">' . Panel::csrfField() . '<input type="hidden" name="action" value="login">';
    $h .= '<label>Password<input type="password" name="password" required autocomplete="current-password" autofocus></label>';
    $h .= '<p class="actions"><button type="submit" class="btn btn-primary">Log in</button></p></form></section>';
    return $h;
}

function page_errors(array $errors): string
{
    if ($errors === []) {
        return '';
    }
    $h = '<div class="flash flash-danger"><strong>Please fix:</strong><ul>';
    foreach ($errors as $err) {
        $h .= '<li>' . Panel::e((string) $err) . '</li>';
    }
    return $h . '</ul></div>';
}

/** Non-blocking notices from Risk::validateConfig (the config was still saved). */
function page_warnings(array $warnings): string
{
    if ($warnings === []) {
        return '';
    }
    $h = '<div class="flash flash-warn"><strong>Please note:</strong><ul>';
    foreach ($warnings as $w) {
        $h .= '<li>' . Panel::e((string) $w) . '</li>';
    }
    return $h . '</ul></div>';
}

function page_cron(array $cfg): string
{
    $e = 'Panel::e';
    $url = Panel::cronUrl($cfg);
    $h  = '<section class="card"><h1>Cron job</h1>';
    $h .= '<p>The bot only acts when <code>cron.php</code> runs. Schedule it <strong>every minute</strong> - either from the cPanel "Cron Jobs" page (recommended) or with any external HTTP cron service.</p>';
    $h .= '<h3>Option A - cPanel cron command (no key needed)</h3><pre class="code">' . $e(Panel::cronCommand()) . '</pre>';
    $h .= '<p class="muted">If your host uses a different PHP binary, replace <code>/usr/local/bin/php</code> (typical alternatives: <code>/usr/bin/php</code>, <code>/opt/cpanel/ea-php74/root/usr/bin/php</code>).</p>';
    $h .= '<h3>Option B - HTTP trigger with the cron key</h3>';
    $h .= '<pre class="code">' . $e($url) . '</pre>';
    $h .= '<p>or with the header instead of the query string:</p>';
    $h .= '<pre class="code">curl -s -H "X-Cron-Key: ' . $e((string) $cfg['cron_key']) . '" ' . $e(Panel::baseUrl() . 'cron.php') . '</pre>';
    $h .= '<p class="muted">The cron key is a secret: anyone holding it can trigger ticks (nothing else). cron.php refuses to run more often than every 50 s and never runs before setup is complete.</p>';
    $h .= '<p class="actions"><a class="btn btn-primary" href="?page=dashboard">Go to the dashboard</a> <a class="btn" href="?page=settings">Settings</a></p>';
    $h .= '</section>';
    return $h;
}

/* ------------------------------------------------------------ dashboard */

function page_dashboard(array $cfg, array $s): string
{
    $e = 'Panel::e';
    $quote = (string) ($cfg['quote_asset'] ?? 'USDT');
    $mode  = (string) ($cfg['mode'] ?? 'paper');
    $halted = !empty($s['show']['halted']);
    $enabled = !empty($s['show']['enabled']);

    $h = '';
    // ---- actions
    $h .= '<section class="card actions-card"><div class="actions-row">';
    $h .= panel_action_form('start', 'Start', 'btn btn-ok', $halted || $enabled);
    $h .= panel_action_form('pause', 'Pause', 'btn btn-warn', !$enabled);
    $h .= panel_action_form('reset_halt', 'Reset halt', 'btn', !$halted);
    $h .= panel_action_form('run_tick', 'Run tick now', 'btn btn-primary', false);
    if ($mode === 'paper') {
        $h .= '<form method="post" action="index.php" class="inline" data-confirm="Reset the paper account to the starting balance? The kill-switch state is cleared too.">'
            . Panel::csrfField() . '<input type="hidden" name="action" value="reset_paper"><button type="submit" class="btn">Reset paper</button></form>';
    }
    $h .= '<form method="post" action="index.php" class="inline panic" data-confirm="PANIC SELL: close the open position at market and disable trading. Continue?">'
        . Panel::csrfField() . '<input type="hidden" name="action" value="panic_sell">'
        . '<label class="check small"><input type="checkbox" name="confirm" value="1" required> confirm</label>'
        . '<button type="submit" class="btn btn-danger">Panic sell</button></form>';
    $h .= '</div><p class="muted small">Start only enables <em>new entries</em>; exits are always managed by the tick. Halt (equity floor / drawdown) needs a manual reset. Run tick executes one full tick inline (up to ~50 s).</p></section>';

    // ---- KPI tiles
    $h .= '<section class="kpis">';
    $h .= Panel::kpi($s, 'equity', 'Equity', true);
    $h .= Panel::kpi($s, 'quote_free', $quote . ' free');
    $h .= Panel::kpi($s, 'dust_value', 'Dust value');
    $h .= Panel::kpi($s, 'pnl_today', 'PnL today', true);
    $h .= Panel::kpi($s, 'pnl_week', 'PnL 7 d', true);
    $h .= Panel::kpi($s, 'pnl_total', 'PnL total', true);
    $h .= Panel::kpi($s, 'win_rate', 'Win rate');
    $h .= Panel::kpi($s, 'expectancy', 'Expectancy / trade', true);
    $h .= Panel::kpi($s, 'trades_today', 'Trades today / max', true);
    $h .= Panel::kpi($s, 'fees_total', 'Fees total');
    $h .= Panel::kpi($s, 'effective_threshold', 'Entry threshold');
    $h .= Panel::kpi($s, 'equity_hwm', 'High-water mark');
    $h .= '</section>';

    // ---- engine block (DESIGN-ENGINES.md §10): rendered only when grid or pmm is selected.
    // The signal-engine cards below stay exactly as they are when engine === 'signal'.
    $engineOn = !empty($s['show']['engine']);
    $h .= panel_engine_dashboard($s, $quote, $engineOn);

    // ---- three cards: API health, position, sparkline
    $h .= '<section class="grid3">';
    $h .= '<div class="card"><h2>API health ' . Panel::pill((string) $s['text']['api_health'], (string) $s['levels']['api_health'], 'api_health') . '</h2><dl class="kv">';
    $h .= panel_kv($s, 'Time offset', 'time_offset_ms');
    $h .= panel_kv($s, 'Used weight (1 m)', 'used_weight');
    $h .= panel_kv($s, 'Last API error', 'api_error');
    $h .= panel_kv($s, 'API paused until', 'api_paused_until');
    $h .= panel_kv($s, 'Network errors', 'net_errors');
    $h .= panel_kv($s, 'Symbol info', 'symbol_info_at');
    $h .= panel_kv($s, 'Taker fee', 'fee_pct');
    $h .= panel_kv($s, 'Last tick', 'tick_at');
    $h .= panel_kv($s, 'Tick status', 'tick_status');
    $h .= panel_kv($s, 'Tick duration', 'tick_ms');
    $h .= panel_kv($s, 'Last no-trade reason', 'no_trade_reason');
    $h .= panel_kv($s, 'API key', 'api_key_fp');
    $h .= '</dl></div>';

    $h .= '<div class="card"><h2>Open position</h2>';
    $h .= '<div data-show="position"' . (empty($s['show']['position']) ? ' hidden' : '') . '><dl class="kv">';
    $h .= panel_kv($s, 'Symbol', 'pos_symbol');
    $h .= panel_kv($s, 'Status', 'pos_status');
    $h .= panel_kv($s, 'Quantity', 'pos_qty');
    $h .= panel_kv($s, 'Entry eff. (fill)', 'pos_entry_eff');
    $h .= panel_kv($s, 'Entry quote', 'pos_entry_quote');
    $h .= panel_kv($s, 'Bid (last tick)', 'pos_bid');
    $h .= panel_kv($s, 'Unrealised', 'pos_unreal_pct', true);
    $h .= panel_kv($s, 'Stop', 'pos_stop');
    $h .= panel_kv($s, 'Take profit', 'pos_tp');
    $h .= panel_kv($s, 'Trailing', 'pos_trailing', true);
    $h .= panel_kv($s, 'Age', 'pos_age');
    $h .= panel_kv($s, 'Opened', 'pos_opened');
    $h .= panel_kv($s, 'Score (reasons)', 'pos_score');
    $h .= panel_kv($s, 'Exit signal', 'pos_exit', true);
    $h .= '</dl></div>';
    $h .= '<p class="muted empty-note" data-show="no_position"' . (!empty($s['show']['position']) ? ' hidden' : '') . '>No open position. Equity floor: '
        . Panel::field($s, 'equity_floor') . '.</p>';
    $h .= '</div>';

    $h .= '<div class="card"><h2>Equity (last 288 ticks)</h2>';
    $h .= '<div class="spark-wrap">' . Panel::svgSparkline($s['sparkline']) . '</div>';
    $h .= '<div class="spark-meta"><span>min ' . Panel::field($s, 'spark_min') . '</span><span>max ' . Panel::field($s, 'spark_max') . '</span>'
        . '<span>last ' . Panel::field($s, 'spark_last') . '</span><span>' . Panel::pill((string) $s['text']['spark_change'], (string) $s['levels']['spark_change'], 'spark_change') . '</span>'
        . '<span class="muted">' . Panel::field($s, 'spark_count') . ' · ' . Panel::field($s, 'equity_at') . '</span></div>';
    $h .= '</div></section>';

    // ---- symbols table
    $h .= '<section class="card"><h2>Watchlist</h2><div class="table-wrap"><table class="tbl"><thead><tr>'
        . '<th>Symbol</th><th>Status</th><th>minNotional</th><th>stepSize</th><th>Step value</th><th>Required size</th><th>ATR15 %</th><th>Spread</th><th>Eligible / gates</th><th>Score</th><th>Last eval</th>'
        . '</tr></thead><tbody data-table="symbols" data-cols="11">' . Panel::tableRows($s['tables']['symbols']) . '</tbody></table></div>'
        . '<p class="muted small">Required size = (minNotional × 1.15 + stepSize × price) / (1 − fee): the quote amount a buy needs so the later sell still passes the NOTIONAL filter. '
        . 'Symbols whose required size exceeds the trade size are skipped. ATR / spread columns fill in when the tick publishes per-symbol metrics.</p></section>';

    // ---- closed positions
    $h .= '<section class="card"><h2>Closed positions (last 30)</h2><div class="table-wrap"><table class="tbl"><thead><tr>'
        . '<th>Symbol</th><th>Opened</th><th>Closed</th><th>Qty</th><th>Entry eff.</th><th>Exit</th><th>PnL ' . $e($quote) . '</th><th>PnL %</th><th>Reason</th><th>Status</th><th>Score</th>'
        . '</tr></thead><tbody data-table="closed" data-cols="11">' . Panel::tableRows($s['tables']['closed']) . '</tbody></table></div></section>';

    // ---- no-trade reasons + log tail
    $h .= '<section class="grid2 wide">';
    $h .= '<div class="card"><h2>No-trade reasons (24 h)</h2><div class="table-wrap"><table class="tbl compact"><thead><tr><th>Gate / reason</th><th>Count</th><th></th></tr></thead>'
        . '<tbody data-table="no_trade" data-cols="3">' . Panel::tableRows($s['tables']['no_trade']) . '</tbody></table></div></div>';
    $h .= '<div class="card"><h2>Log (last 40)</h2><div class="table-wrap"><table class="tbl compact logs"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead>'
        . '<tbody data-table="logs" data-cols="3">' . Panel::tableRows($s['tables']['logs']) . '</tbody></table></div></div>';
    $h .= '</section>';

    $h .= '<p class="muted small">Server time ' . Panel::field($s, 'now') . '. Values refresh every 20 s without reloading; actions reload the page.</p>';
    return $h;
}

/**
 * Engine card, actions, open orders, cycles and cycle KPIs (DESIGN-ENGINES.md §10).
 * Every section carries data-show="engine" so assets/panel.js reveals or hides the whole
 * block when the engine changes, without a page reload and without an inline style.
 */
function panel_engine_dashboard(array $s, string $quote, bool $on): string
{
    $e   = 'Panel::e';
    $hid = $on ? '' : ' hidden';

    $h  = '<section class="card engine-card" data-show="engine"' . $hid . '>';
    $h .= '<h2>Engine ' . Panel::pill((string) ($s['text']['eng_name'] ?? '–'), (string) ($s['levels']['eng_name'] ?? 'muted'), 'eng_name')
        . ' ' . Panel::pill((string) ($s['text']['eng_state'] ?? '–'), (string) ($s['levels']['eng_state'] ?? 'muted'), 'eng_state') . '</h2>';
    $h .= '<p class="flash flash-danger" data-show="engine_live_blocked" hidden>This engine is in <strong>live</strong> mode with '
        . '<code>allow_live_engines</code> off: it places no order at all and every tick reports <code>engine_live_blocked</code>. '
        . 'Enable the setting in Settings → Engine, or switch the mode back to paper, demo or testnet.</p>';

    // actions
    $h .= '<div class="actions-row">';
    $h .= panel_action_form('cancel_all', 'Cancel all orders', 'btn btn-warn', false);
    $h .= '<span class="inline-group" data-show="grid_engine"' . (!empty($s['show']['grid_engine']) ? '' : ' hidden') . '>'
        . '<form method="post" action="index.php" class="inline" data-confirm="Re-anchor the grid on the current mid price? This also clears a range-exit pause.">'
        . Panel::csrfField() . '<input type="hidden" name="action" value="reanchor_grid">'
        . '<button type="submit" class="btn">Re-anchor grid</button></form></span>';
    $h .= '<form method="post" action="index.php" class="inline panic" data-confirm="FLATTEN INVENTORY: cancel every resting order and market-sell the whole engine inventory. Continue?">'
        . Panel::csrfField() . '<input type="hidden" name="action" value="flatten_inventory">'
        . '<label class="check small"><input type="checkbox" name="confirm" value="1" required> confirm</label>'
        . '<button type="submit" class="btn btn-danger">Flatten inventory</button></form>';
    $h .= '</div>';

    // card body
    $h .= '<div class="grid3 engine-kv">';
    $h .= '<dl class="kv">';
    $h .= panel_kv($s, 'Engine', 'eng_name');
    $h .= panel_kv($s, 'Symbol', 'eng_symbol');
    $h .= panel_kv($s, 'Last price', 'eng_price');
    $h .= panel_kv($s, 'Live orders', 'eng_orders');
    $h .= '</dl>';
    $h .= '<dl class="kv">';
    $h .= panel_kv($s, 'Inventory', 'eng_inventory');
    $h .= panel_kv($s, 'Inventory cost', 'eng_inv_cost');
    $h .= panel_kv($s, 'Inventory at bid', 'eng_inv_value');
    $h .= panel_kv($s, 'Unrealised', 'eng_unreal', true);
    $h .= '</dl>';
    $h .= '<div data-show="grid_engine"' . (!empty($s['show']['grid_engine']) ? '' : ' hidden') . '><dl class="kv">';
    $h .= panel_kv($s, 'Anchor', 'eng_anchor');
    $h .= panel_kv($s, 'Anchored at', 'eng_anchor_at');
    $h .= panel_kv($s, 'Range up', 'eng_range_up', true);
    $h .= panel_kv($s, 'Range down', 'eng_range_down', true);
    $h .= panel_kv($s, 'Rung spacing', 'eng_spacing');
    $h .= '</dl></div>';
    $h .= '<div data-show="pmm_engine"' . (!empty($s['show']['pmm_engine']) ? '' : ' hidden') . '><dl class="kv">';
    $h .= panel_kv($s, 'Half-spread', 'eng_spread');
    $h .= panel_kv($s, 'Re-quote age', 'eng_refresh');
    $h .= '</dl>';
    $h .= '<p class="muted small">pmm is expected to lose money at VIP0 fees: a round trip costs about 0.2 % while typical '
        . 'spreads on the majors are 0.01–0.05 %. Watch the realised cycle PnL below, not the fill count.</p></div>';
    $h .= '</div></section>';

    // cycle KPIs
    $h .= '<section class="kpis" data-show="engine"' . $hid . '>';
    $h .= Panel::kpi($s, 'eng_cycles_today', 'Cycles today');
    $h .= Panel::kpi($s, 'eng_cycles', 'Cycles total');
    $h .= Panel::kpi($s, 'eng_pnl_today', 'Cycle PnL today', true);
    $h .= Panel::kpi($s, 'eng_pnl', 'Cycle PnL realised', true);
    $h .= Panel::kpi($s, 'eng_fees', 'Cycle fees');
    $h .= Panel::kpi($s, 'eng_win_rate', 'Cycle win rate');
    $h .= '</section>';

    // open orders
    $h .= '<section class="card" data-show="engine"' . $hid . '><h2>Open orders</h2><div class="table-wrap">'
        . '<table class="tbl"><thead><tr><th>Side</th><th>Level</th><th>Price</th><th>Qty</th><th>Quote ' . $e($quote) . '</th>'
        . '<th>Age</th><th>Status</th><th></th></tr></thead>'
        . '<tbody data-table="engine_orders" data-cols="8">' . Panel::tableRows($s['tables']['engine_orders']) . '</tbody>'
        . '</table></div><p class="muted small">The engine owns the order book of its symbol: anything resting there that it does not '
        . 'track is cancelled on the next tick. Cancelling here resolves the order against the exchange first, so a fill that beat the '
        . 'cancel is still booked into the lots and cycles.</p></section>';

    // cycles
    $h .= '<section class="card" data-show="engine"' . $hid . '><h2>Cycles (last 30)</h2><div class="table-wrap">'
        . '<table class="tbl"><thead><tr><th>Level</th><th>Buy</th><th>Sell</th><th>Qty</th><th>PnL ' . $e($quote) . '</th><th>Closed</th></tr></thead>'
        . '<tbody data-table="cycles" data-cols="6">' . Panel::tableRows($s['tables']['cycles']) . '</tbody>'
        . '</table></div><p class="muted small">One row per realised buy → sell round trip, FIFO. PnL is net: the sell fee is deducted '
        . 'and the buy fee is already inside the buy price.</p></section>';

    return $h;
}

function panel_action_form(string $action, string $label, string $class, bool $disabled): string
{
    return '<form method="post" action="index.php" class="inline">' . Panel::csrfField()
        . '<input type="hidden" name="action" value="' . Panel::e($action) . '">'
        . '<button type="submit" class="' . Panel::e($class) . '"' . ($disabled ? ' disabled' : '') . '>' . Panel::e($label) . '</button></form>';
}

function panel_kv(array $s, string $label, string $key, bool $pill = false): string
{
    $h = '<dt>' . Panel::e($label) . '</dt><dd>';
    if ($pill) {
        $h .= Panel::pill((string) ($s['text'][$key] ?? '–'), (string) ($s['levels'][$key] ?? 'muted'), $key);
    } else {
        $h .= Panel::field($s, $key);
    }
    return $h . '</dd>';
}

/* -------------------------------------------------------------- settings */

function page_settings(array $cfg, Db $db, array $v, array $errors, string $testResult, array $warnings = []): string
{
    $e = 'Panel::e';
    foreach (panel_engine_defaults() as $k => $d) {
        if (!isset($v[$k])) {
            $v[$k] = $d;   // the form still renders on an install whose config predates the engines
        }
    }
    $h  = '<section class="card"><h1>Settings</h1>';
    $h .= page_errors($errors);
    $h .= page_warnings($warnings);
    if ($testResult !== '') {
        $lvl = strpos($testResult, 'OK:') === 0 ? 'ok' : (strpos($testResult, 'PARTIAL') === 0 ? 'warn' : 'danger');
        $h .= '<div class="flash flash-' . $lvl . '">' . $e($testResult) . '</div>';
    }
    $h .= '<form method="post" action="index.php" autocomplete="off" data-confirm="Switch to LIVE mode? The bot will trade real money on your Binance account."'
        . ' data-confirm-field="mode" data-confirm-value="live">' . Panel::csrfField();

    // -- mode & API
    $h .= '<fieldset><legend>Mode &amp; API</legend><div class="grid3">';
    $h .= '<label>Mode<select name="mode">';
    foreach (['paper', 'demo', 'testnet', 'live'] as $m) {
        $h .= '<option value="' . $e($m) . '"' . ((string) $v['mode'] === $m ? ' selected' : '') . '>' . $e($m) . '</option>';
    }
    $h .= '</select></label>';
    $h .= '<label>API key <span class="muted">(stored: ' . $e(Panel::keyFingerprint((string) ($cfg['api_key'] ?? ''))) . ')</span>'
        . '<input type="text" name="api_key" value="' . $e((string) $v['api_key']) . '" autocomplete="off" spellcheck="false" placeholder="unchanged"></label>';
    $h .= '<label>API secret <span class="muted">(' . ((string) ($cfg['api_secret'] ?? '') !== '' ? 'stored, write-only' : 'not set') . ')</span>'
        . '<input type="password" name="api_secret" value="" autocomplete="new-password" placeholder="leave blank to keep"></label>';
    $h .= '</div>';
    $h .= '<label class="check"><input type="checkbox" name="enabled" value="1"' . (!empty($v['enabled']) ? ' checked' : '') . '> Entries enabled (same as Start / Pause)</label>';
    $h .= '<label class="check"><input type="checkbox" name="live_confirm" value="1"> I understand this trades real money (required when switching to live)</label>';
    $h .= '<p class="actions"><button type="submit" name="action" value="test_api" class="btn" data-skip-confirm>Test API connection</button>'
        . ' <span class="muted small">uses the key/secret typed above (or the stored ones), then account() and a test order on the first symbol</span></p>';
    $h .= '</fieldset>';

    // -- watchlist
    $h .= '<fieldset><legend>Watchlist</legend><div class="grid2">';
    $symbols = is_array($v['symbols']) ? implode(', ', $v['symbols']) : (string) $v['symbols'];
    $h .= '<label>Symbols (comma separated, must end with the quote asset)<textarea name="symbols" rows="2">' . $e($symbols) . '</textarea></label>';
    $h .= panel_input('quote_asset', $v, 'Quote asset', 'text');
    $h .= '</div></fieldset>';

    // -- engine (DESIGN-ENGINES.md §2, §10)
    $engine = strtolower(trim((string) ($v['engine'] ?? 'signal')));
    if (!in_array($engine, ['signal', 'grid', 'pmm'], true)) {
        $engine = 'signal';
    }
    $h .= '<fieldset><legend>Engine</legend>';
    $h .= '<div class="grid3">';
    $h .= '<label>Engine<span class="muted help">signal trades one position at a time on the watchlist; grid and pmm quote one symbol continuously</span>'
        . '<select name="engine" data-engine-select>';
    foreach ([
        'signal' => 'signal - mean-reversion, market orders, one position',
        'grid'   => 'grid - ladder of resting limit orders',
        'pmm'    => 'pmm - pure market making (expected to lose at VIP0 fees)',
    ] as $k => $label) {
        $h .= '<option value="' . $e($k) . '"' . ($engine === $k ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $h .= '</select></label>';
    $h .= panel_input('engine_symbol', $v, 'Engine symbol', 'text', 'the single pair grid / pmm trade - they do not use the watchlist');
    $h .= panel_input('engine_max_orders', $v, 'Max simultaneous orders', 'number', '1-20; also held under the symbol MAX_NUM_ORDERS - 2', '1');
    $h .= '</div>';
    $h .= panel_check('post_only', $v, 'Post-only quotes (LIMIT_MAKER).',
        '<span class="muted">A quote that would match immediately is rejected and simply skipped for that tick - that rejection is normal. Unticked uses LIMIT + GTC, which can take.</span>');
    $h .= panel_check('allow_live_engines', $v, 'Allow grid / pmm to run in LIVE mode.',
        '<span class="danger">Warning: grid and pmm refuse to place a single order in live mode while this is off '
        . '(the tick reports <code>engine_live_blocked</code>). Only turn it on once you have run the engine in paper, demo or testnet '
        . 'and understand that it trades real money continuously.</span>');

    // signal
    $h .= panel_engine_group('signal', $engine,
        '<p class="muted">The signal engine is configured by the sections below (watchlist, sizing, exits, strategy). '
        . 'The grid and pmm fields do not apply to it.</p>');

    // grid
    $grid  = '<div class="grid3">';
    $grid .= panel_input('grid_levels', $v, 'Buy rungs below the anchor', 'number', '1-20, and under engine_max_orders', '1');
    $grid .= panel_input('grid_spacing_pct', $v, 'Rung spacing %', 'number', 'must be at least 2 x fee % + 0.1, otherwise a round trip cannot pay for itself');
    $grid .= panel_input('grid_order_usdt', $v, 'Quote per rung', 'number', 'must clear the symbol required size (minNotional + step)');
    $grid .= panel_input('grid_range_up_pct', $v, 'Range up %', 'number', 'mid above anchor x (1 + x) ends the grid');
    $grid .= panel_input('grid_range_down_pct', $v, 'Range down %', 'number', 'mid below anchor x (1 - x) ends the grid');
    $grid .= '</div>';
    $grid .= panel_check('grid_exit_liquidates', $v, 'On a range exit, also market-sell the inventory.',
        '<span class="muted">Off keeps the base in the wallet; a range exit always cancels every resting order and pauses the grid until you press "Re-anchor grid".</span>');
    $h .= panel_engine_group('grid', $engine, $grid);

    // pmm
    $pmm  = '<div class="flash flash-warn"><strong>pmm is expected to LOSE money at VIP0 fees.</strong> Binance charges 0.1 % maker, '
          . 'identical to taker, so a round trip costs about 0.2 % while observed spreads on the majors are 0.01-0.05 %. '
          . 'It is here because it was asked for, and it becomes viable at a better fee tier, on wide-spread pairs, or with maker rebates. '
          . 'Run it in paper or demo mode and watch the cycle KPIs before you consider anything else.</div>';
    $pmm .= '<div class="grid3">';
    $pmm .= panel_input('pmm_spread_pct', $v, 'Half-spread % each side', 'number', 'bid = mid x (1 - x), ask = mid x (1 + x)');
    $pmm .= panel_input('pmm_order_usdt', $v, 'Quote per quote-order', 'number', 'the inventory skew scales it, never above 1.5 x');
    $pmm .= panel_input('pmm_refresh_sec', $v, 'Re-quote age (s)', 'number', 'quotes older than this are cancelled and replaced', '1');
    $pmm .= panel_input('pmm_target_base_pct', $v, 'Target base %', 'number', 'share of engine equity held as base');
    $pmm .= panel_input('pmm_max_base_pct', $v, 'Max base %', 'number', 'above this stop bidding; below 100 - this stop asking');
    $pmm .= '</div>';
    $h .= panel_engine_group('pmm', $engine, $pmm);
    $h .= '</fieldset>';

    // -- sizing & kill switches
    $h .= '<fieldset><legend>Sizing &amp; kill switches</legend><div class="grid3">';
    $h .= panel_input('trade_usdt', $v, 'Trade size (quote)', 'number', 'target quote per entry; actual = min(trade, 65 % of free)');
    $h .= panel_input('equity_floor_usdt', $v, 'Equity floor (KILL)', 'number', 'equity ≤ floor ⇒ close, halt, manual reset');
    $h .= panel_input('hwm_drawdown_pct', $v, 'HWM drawdown % (KILL)', 'number', 'equity < HWM × (1 − pct) ⇒ same as floor');
    $h .= panel_input('daily_loss_cap_pct', $v, 'Daily loss cap %', 'number', 'of day-start equity');
    $h .= panel_input('weekly_loss_cap_pct', $v, 'Weekly loss cap %', 'number', 'rolling 7 days ⇒ 7-day pause');
    $h .= panel_input('max_trades_per_day', $v, 'Max entries / day', 'number', 'adaptive may lower to 1 or raise to 4', '1');
    $h .= panel_input('max_orders_per_hour', $v, 'Max orders / hour', 'number', 'bug guard, counts all orders', '1');
    $h .= panel_input('max_consecutive_losses', $v, 'Max consecutive losses', 'number', '⇒ no entries until next UTC day', '1');
    $h .= panel_input('cooldown_after_loss_min', $v, 'Cooldown after 1 loss (min)', 'number', '', '1');
    $h .= panel_input('cooldown_after_2_losses_min', $v, 'Cooldown after 2 losses (min)', 'number', '', '1');
    $h .= '</div></fieldset>';

    // -- exits
    $h .= '<fieldset><legend>Exits</legend><div class="grid3">';
    $h .= panel_input('take_profit_pct', $v, 'Take profit % (min)', 'number', 'effective TP = clamp(1.5 × ATR15 %, min, max); must be ≥ 3 × round-trip fee');
    $h .= panel_input('take_profit_max_pct', $v, 'Take profit % (max)', 'number');
    $h .= panel_input('stop_loss_pct', $v, 'Stop loss %', 'number', 'on effective entry, evaluated on bid');
    $h .= panel_input('trailing_activate_pct', $v, 'Trailing activates at %', 'number');
    $h .= panel_input('trailing_distance_pct', $v, 'Trailing distance %', 'number');
    $h .= panel_input('trailing_floor_pct', $v, 'Trailing floor %', 'number', 'stop never below entry × (1 + floor)');
    $h .= panel_input('max_hold_minutes', $v, 'Max hold (minutes)', 'number', 'time-based exit', '1');
    $h .= '</div></fieldset>';

    // -- strategy
    $h .= '<fieldset><legend>Strategy &amp; gates</legend><div class="grid3">';
    $h .= panel_input('entry_threshold', $v, 'Entry threshold (0-100)', 'number', 'score needed; adaptive raises it on poor results', '1');
    $h .= '<label class="check tall"><input type="checkbox" name="adaptive" value="1"' . (!empty($v['adaptive']) ? ' checked' : '') . '> Adaptive self-tuning (threshold / max trades from rolling win rate)</label>';
    $h .= panel_select('candle_interval', $v, 'Entry candle interval', ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d']);
    $h .= panel_select('trend_interval', $v, 'Trend (regime) interval', ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d']);
    $h .= panel_input('atr_min_pct', $v, 'ATR14 (entry TF) min %', 'number');
    $h .= panel_input('atr_max_pct', $v, 'ATR14 (entry TF) max %', 'number');
    $h .= panel_input('atr1h_min_pct', $v, 'ATR14 (trend TF) min %', 'number');
    $h .= panel_input('atr1h_max_pct', $v, 'ATR14 (trend TF) max %', 'number');
    $h .= panel_input('max_spread_pct', $v, 'Max spread %', 'number', '(ask − bid) / mid from bookTicker');
    $h .= '</div></fieldset>';

    // -- misc
    $h .= '<fieldset><legend>Fees, paper &amp; misc</legend><div class="grid3">';
    $h .= panel_input('fee_pct', $v, 'Taker fee % per side', 'number', 'paper mode uses it; live reads the real rate');
    $h .= panel_input('paper_start_usdt', $v, 'Paper start balance', 'number');
    $h .= panel_input('recv_window', $v, 'recvWindow (ms)', 'number', '', '1');
    $h .= panel_input('timezone', $v, 'Display timezone', 'text', 'e.g. UTC, Europe/Berlin, America/New_York');
    $h .= '</div></fieldset>';

    $h .= '<p class="actions"><button type="submit" name="action" value="save_settings" class="btn btn-primary">Save settings</button> <a class="btn" href="?page=dashboard">Cancel</a></p>';
    $h .= '</form></section>';

    // -- cron & keys
    $h .= '<section class="card"><h2>Cron &amp; keys</h2><dl class="kv">';
    $h .= '<dt>API key fingerprint</dt><dd class="mono">' . $e(Panel::keyFingerprint((string) ($cfg['api_key'] ?? ''))) . '</dd>';
    $h .= '<dt>API secret</dt><dd>' . ((string) ($cfg['api_secret'] ?? '') !== '' ? 'stored (never shown)' : 'not set') . '</dd>';
    $h .= '<dt>Force HTTPS</dt><dd>' . (!empty($cfg['force_https']) ? 'on' : 'off') . ' <span class="muted">(set during setup; edit data/config.json to change)</span></dd>';
    $h .= '<dt>Cron command</dt><dd><code class="wrap">' . $e(Panel::cronCommand()) . '</code></dd>';
    $h .= '<dt>HTTP trigger</dt><dd><code class="wrap">' . $e(Panel::cronUrl($cfg)) . '</code> <a href="?page=cron">details</a></dd>';
    $h .= '<dt>Config file</dt><dd class="mono">' . $e(trader_config_path()) . '</dd>';
    $h .= '<dt>Database</dt><dd class="mono">' . $e($db->path()) . '</dd>';
    $h .= '</dl></section>';
    return $h;
}

function panel_input(string $key, array $v, string $label, string $type = 'number', string $help = '', string $step = 'any'): string
{
    $val = $v[$key] ?? '';
    if (is_array($val)) {
        $val = implode(', ', $val);
    }
    $attrs = ' name="' . Panel::e($key) . '" value="' . Panel::e($val) . '"';
    if ($type === 'number') {
        $attrs .= ' type="number" step="' . Panel::e($step) . '" inputmode="decimal"';
    } else {
        $attrs .= ' type="text" spellcheck="false"';
    }
    return '<label>' . Panel::e($label) . ($help !== '' ? ' <span class="muted help">' . Panel::e($help) . '</span>' : '') . '<input' . $attrs . '></label>';
}

/**
 * Checkbox row. $extraHtml is trusted static markup written in this file (never user data);
 * the label itself and every value still go through Panel::e().
 */
function panel_check(string $key, array $v, string $label, string $extraHtml = ''): string
{
    return '<label class="check"><input type="checkbox" name="' . Panel::e($key) . '" value="1"'
        . (!empty($v[$key]) ? ' checked' : '') . '> ' . Panel::e($label)
        . ($extraHtml !== '' ? ' ' . $extraHtml : '') . '</label>';
}

/**
 * One engine's field group. Only the selected engine's group is visible; assets/panel.js
 * toggles the `is-hidden` CLASS on the select's change event (the CSP forbids inline JS and
 * inline style attributes, so visibility is a class in assets/panel.css and nothing else).
 */
function panel_engine_group(string $name, string $current, string $body): string
{
    return '<div class="engine-group' . ($name === $current ? '' : ' is-hidden') . '"'
        . ' data-engine-group="' . Panel::e($name) . '">' . $body . '</div>';
}

/** DESIGN-ENGINES.md §2 defaults, used only to render the form when config.php has no value yet. */
function panel_engine_defaults(): array
{
    return [
        'engine' => 'signal', 'allow_live_engines' => false, 'engine_symbol' => 'DOGEUSDT',
        'engine_max_orders' => 12, 'post_only' => true,
        'grid_levels' => 6, 'grid_spacing_pct' => 0.60, 'grid_order_usdt' => 1.30,
        'grid_range_up_pct' => 4.0, 'grid_range_down_pct' => 6.0, 'grid_exit_liquidates' => false,
        'pmm_spread_pct' => 0.25, 'pmm_order_usdt' => 1.30, 'pmm_refresh_sec' => 60,
        'pmm_target_base_pct' => 50, 'pmm_max_base_pct' => 80,
    ];
}

function panel_select(string $key, array $v, string $label, array $options): string
{
    $cur = (string) ($v[$key] ?? '');
    $h = '<label>' . Panel::e($label) . '<select name="' . Panel::e($key) . '">';
    foreach ($options as $o) {
        $h .= '<option value="' . Panel::e($o) . '"' . ($cur === (string) $o ? ' selected' : '') . '>' . Panel::e($o) . '</option>';
    }
    return $h . '</select></label>';
}
