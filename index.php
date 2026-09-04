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
} elseif ($page === 'insights') {
    // DESIGN-LEARNING.md §6: honest statistics over the bot's own history. Read-only:
    // opening this page computes and writes nothing, it only reads observations.
    echo panel_layout('Insights', page_insights($cfg, Panel::insights($cfg, $db)), ['page' => 'insights']);
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

        case 'assign_symbol':
        case 'pause_sleeve':
        case 'resume_sleeve':
            handle_sleeve_action($action, $cfg, $db);
            return;

        case 'recompute_learning':
        case 'learn_recompute':
            handle_learning_action($cfg, $db);
            return;

        case 'toggle_bnb_burn':
        case 'check_bnb_burn':
            handle_bnb_action($action, $cfg, $db);
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
            // A fill that beat the cancel is booked right here, so it must land under the
            // engine that OWNS the order (the sleeve key, DESIGN-PORTFOLIO.md §1) and not
            // under the global cfg['engine'], which is meaningless in portfolio mode.
            $cfgOrder  = $fresh;
            $rowEngine = strtolower(trim((string) ($row['engine'] ?? '')));
            if ($rowEngine !== '') {
                $cfgOrder['engine'] = $rowEngine;
            }
            $ok     = panel_engine_orders($cfgOrder, $db, $ex, $symbol)->cancel($clientId);
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

/* --------------------------------------------------------- portfolio actions */

/** The sleeve engines, in canonical order (DESIGN-PORTFOLIO.md §1: the key IS the engine). */
function panel_sleeve_engines(): array
{
    return class_exists('Sleeve') ? Sleeve::ENGINES : ['signal', 'grid', 'pmm'];
}

/** Engines whose sleeve takes exactly one symbol (DESIGN-PORTFOLIO.md §2). */
function panel_single_symbol_engines(): array
{
    return ['grid', 'pmm'];
}

/**
 * What a sleeve is still holding, as human sentences ([] when it holds nothing).
 * Database only, so it is right in every mode: open FIFO lots, open/stuck signal positions
 * and resting engine orders on the symbols the sleeve owns - or, when $symbols is given,
 * on exactly that list of symbols (used to probe a symbol before it is handed to a sleeve).
 */
function panel_sleeve_inventory(array $cfg, Db $db, string $engine, ?array $symbols = null): array
{
    $mode  = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
    $held  = [];
    $syms  = $symbols !== null
        ? $symbols
        : (class_exists('Sleeve') ? Sleeve::symbols($cfg, $engine) : []);
    foreach ($syms as $sym) {
        $sym = strtoupper((string) $sym);
        try {
            $qty = 0.0;
            foreach ($db->openLots($sym, $mode) as $lot) {
                $qty += isset($lot['remaining']) && is_numeric($lot['remaining']) ? (float) $lot['remaining'] : 0.0;
            }
            if ($qty > 0.0) {
                $held[] = $sym . ': ' . Util::toDecimalString($qty, 8) . ' in open engine lots';
            }
        } catch (Throwable $e) {
            // no lots table on an install that predates the engines
        }
        try {
            $q = $db->pdo()->prepare("SELECT COUNT(*) FROM positions WHERE symbol = ? AND status IN ('OPEN','STUCK') AND mode = ?");
            $q->execute([$sym, $mode]);
            $n = (int) $q->fetchColumn();
            if ($n > 0) {
                $held[] = $sym . ': ' . $n . ' open position' . ($n === 1 ? '' : 's');
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $open = $db->openEngineOrders($sym, $mode);
            if ($open !== []) {
                $held[] = $sym . ': ' . count($open) . ' resting order' . (count($open) === 1 ? '' : 's');
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    return $held;
}

/**
 * The mirror of panel_sleeve_inventory(): what $symbol still holds that is booked to an engine
 * OTHER than $engine, as human sentences ([] when there is nothing).
 *
 * Why it matters: a sleeve's budget is an accounting boundary, not an exchange one
 * (DESIGN-PORTFOLIO.md §1). `EngineOrders::bookSell()` consumes FIFO lots filtered by the
 * SELLING engine, so if the "pmm" sleeve is handed a symbol whose open lots are booked to
 * "grid", a pmm sale matches nothing: it writes zero `cycles` rows (the proceeds simply leave
 * the books), while the grid lot's `remaining` never decrements. The sleeve's unrealised then
 * falls permanently and parks it in `Bot::sleeveDrawdownPaused()`. So the symbol is refused at
 * the panel boundary instead - the engines themselves are left exactly as they are.
 *
 * Database only, so it is right in every mode.
 */
function panel_symbol_foreign_inventory(array $cfg, Db $db, string $symbol, string $engine): array
{
    $symbol = strtoupper(trim($symbol));
    $engine = strtolower(trim($engine));
    $mode   = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
    if ($symbol === '') {
        return [];
    }
    $out = [];
    try {
        $qty = 0.0;
        foreach ($db->openLots($symbol, $mode) as $lot) {
            $lotEngine = strtolower(trim((string) ($lot['engine'] ?? '')));
            if ($lotEngine === $engine) {
                continue;
            }
            $qty += isset($lot['remaining']) && is_numeric($lot['remaining']) ? (float) $lot['remaining'] : 0.0;
        }
        if ($qty > 0.0) {
            $out[] = $symbol . ': ' . Util::toDecimalString($qty, 8) . ' in open lots booked to a different engine';
        }
    } catch (Throwable $e) {
        // no lots table on an install that predates the engines
    }
    try {
        $n = 0;
        foreach ($db->openEngineOrders($symbol, $mode) as $row) {
            $rowEngine = strtolower(trim((string) ($row['engine'] ?? '')));
            if ($rowEngine !== $engine) {
                $n++;
            }
        }
        if ($n > 0) {
            $out[] = $symbol . ': ' . $n . ' resting order' . ($n === 1 ? '' : 's') . ' of a different engine';
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * Portfolio actions (DESIGN-PORTFOLIO.md §7), all POST + CSRF (checked globally before routing)
 * and all inside Bot::runLocked so they can never race a cron tick:
 *
 *   assign_symbol  set a scanner row's symbol as a sleeve's symbol. Refused when another sleeve
 *                  already owns it (naming that sleeve) and refused when the target sleeve still
 *                  holds inventory (naming what it holds) - a silent symbol change under a live
 *                  grid would strand that inventory.
 *   pause_sleeve / resume_sleeve   stop or resume ONE sleeve (state `sleeve_paused_<engine>`)
 *                  while the others keep trading.
 */
function handle_sleeve_action(string $action, array $cfg, Db $db): void
{
    if (!class_exists('Sleeve')) {
        Panel::flash('danger', 'lib/Sleeve.php is missing - the portfolio actions are unavailable.');
        Panel::redirect('?page=dashboard');
    }
    if (!class_exists('Bot')) {
        Panel::flash('danger', 'lib/Bot.php is missing - the portfolio actions are unavailable.');
        Panel::redirect('?page=dashboard');
    }
    $engine = isset($_POST['engine']) && is_string($_POST['engine']) ? strtolower(trim($_POST['engine'])) : '';
    if ($engine === '' || strlen($engine) > 32 || Sleeve::of($cfg, $engine) === null) {
        Panel::flash('warn', 'No such sleeve: "' . $engine . '". Configure it in Settings → Portfolio first.');
        Panel::redirect('?page=dashboard');
    }

    if ($action === 'pause_sleeve' || $action === 'resume_sleeve') {
        $pause = $action === 'pause_sleeve';
        try {
            $res = Bot::runLocked(static function () use ($db, $engine, $pause): array {
                $db->setState('sleeve_paused_' . $engine, $pause ? 'manual' : null);
                return ['status' => 'ok', 'summary' => '', 'ms' => 0];
            });
            if ((string) ($res['status'] ?? '') === 'skipped') {
                Panel::flash('warn', 'Skipped: a tick is running right now. Try again in a few seconds.');
            } else {
                Log::warn('panel: sleeve ' . ($pause ? 'paused' : 'resumed'), ['engine' => $engine]);
                Panel::flash('ok', $pause
                    ? 'Sleeve "' . $engine . '" paused: it opens no new exposure. The other sleeves keep trading and its own exits are still managed.'
                    : 'Sleeve "' . $engine . '" resumed. It opens new exposure again as soon as its budget allows.');
            }
        } catch (Throwable $e) {
            Log::error('panel: sleeve action ' . $action . ' failed - ' . $e->getMessage());
            Panel::flash('danger', 'Sleeve action failed: ' . $e->getMessage());
        }
        Panel::redirect('?page=dashboard');
    }

    // ---- assign_symbol
    $symbol = isset($_POST['symbol']) && is_string($_POST['symbol']) ? strtoupper(trim($_POST['symbol'])) : '';
    if ($symbol === '' || preg_match('/^[A-Z0-9]{2,24}$/', $symbol) !== 1) {
        Panel::flash('warn', 'Assign: "' . $symbol . '" is not a valid symbol.');
        Panel::redirect('?page=dashboard');
    }
    $quote = strtoupper(trim((string) ($cfg['quote_asset'] ?? 'USDT')));
    if ($quote !== '' && (strlen($symbol) <= strlen($quote) || substr($symbol, -strlen($quote)) !== $quote)) {
        Panel::flash('warn', 'Assign: ' . $symbol . ' does not end with the quote asset ' . $quote . '.');
        Panel::redirect('?page=dashboard');
    }
    $owner = Sleeve::ownerOf($cfg, $symbol);
    if ($owner !== null && $owner !== $engine) {
        Panel::flash('danger', 'Refused: ' . $symbol . ' is already owned by the "' . $owner . '" sleeve. Two sleeves must never share a symbol '
            . '- one would sell what the other bought. Remove it from "' . $owner . '" first (Settings → Portfolio).');
        Panel::redirect('?page=dashboard');
    }
    if ($owner === $engine) {
        Panel::flash('info', $symbol . ' already belongs to the "' . $engine . '" sleeve. Nothing changed.');
        Panel::redirect('?page=dashboard');
    }
    // The incoming symbol must be empty too. A symbol no sleeve owns can still carry a
    // signal position, open FIFO lots or resting orders (a symbol dropped from a sleeve,
    // or one held from before portfolio mode). Handing it to a sleeve would let that
    // sleeve sell base it never bought - the one thing DESIGN-PORTFOLIO.md §1 forbids.
    $carries = panel_sleeve_inventory($cfg, $db, $engine, array($symbol));
    if ($carries !== []) {
        Panel::flash('danger', 'Refused: ' . $symbol . ' still holds inventory that belongs to no sleeve ('
            . implode('; ', $carries) . '). Giving it to the "' . $engine . '" sleeve would let that sleeve sell base '
            . 'it never bought, and book the proceeds against the wrong method. Close or flatten it first, then assign '
            . $symbol . '.');
        Panel::redirect('?page=dashboard');
    }
    $held = panel_sleeve_inventory($cfg, $db, $engine);
    if ($held !== []) {
        Panel::flash('danger', 'Refused: the "' . $engine . '" sleeve still holds inventory (' . implode('; ', $held)
            . '). Changing its symbols now would strand that inventory - flatten it or let it close first, then assign ' . $symbol . '.');
        Panel::redirect('?page=dashboard');
    }
    // Belt and braces on the same rule from the other side: whatever the check above lets
    // through must also carry no inventory booked to ANOTHER engine. EngineOrders::bookSell()
    // consumes lots filtered by the selling engine, so a sale of foreign inventory writes no
    // cycles row at all - the proceeds leave the books and the sleeve drifts to a permanent
    // unrealised loss that parks it in the drawdown pause.
    $foreign = panel_symbol_foreign_inventory($cfg, $db, $symbol, $engine);
    if ($foreign !== []) {
        Panel::flash('danger', 'Refused: ' . implode('; ', $foreign) . '. The "' . $engine . '" sleeve would be charged '
            . 'for that inventory but could never sell it out of the books - flatten it under the engine that bought it first.');
        Panel::redirect('?page=dashboard');
    }

    $single  = in_array($engine, panel_single_symbol_engines(), true);
    $current = Sleeve::symbols($cfg, $engine);
    $wanted  = $single ? [$symbol] : array_values(array_unique(array_merge($current, [$symbol])));

    set_time_limit(55);
    try {
        $res = Bot::runLocked(static function () use ($db, $engine, $symbol, $wanted, $single, $current): array {
            $fresh = trader_config(true);
            // re-check under the lock: the config may have changed since the page was rendered
            $owner2 = Sleeve::ownerOf($fresh, $symbol);
            if ($owner2 !== null && $owner2 !== $engine) {
                return ['status' => 'error', 'summary' => 'Refused: ' . $symbol . ' is now owned by the "' . $owner2 . '" sleeve.', 'ms' => 0];
            }
            // and re-check the inventory here: the pre-lock read at request time is only a fast
            // rejection - a tick that filled between it and this lock would otherwise be stranded.
            $carries2 = panel_sleeve_inventory($fresh, $db, $engine, array($symbol));
            if ($carries2 !== []) {
                return ['status' => 'error', 'summary' => 'Refused: ' . $symbol . ' now holds inventory that belongs to no sleeve ('
                    . implode('; ', $carries2) . '). Close or flatten it first.', 'ms' => 0];
            }
            $held2 = panel_sleeve_inventory($fresh, $db, $engine);
            if ($held2 !== []) {
                return ['status' => 'error', 'summary' => 'Refused: the "' . $engine . '" sleeve still holds inventory ('
                    . implode('; ', $held2) . '). Changing its symbols now would strand that inventory - flatten it or let it close first.', 'ms' => 0];
            }
            $sleeves = isset($fresh['sleeves']) && is_array($fresh['sleeves']) ? $fresh['sleeves'] : [];
            $key = null;
            foreach ($sleeves as $k => $unused) {
                if (strtolower(trim((string) $k)) === $engine) {
                    $key = $k;
                    break;
                }
            }
            if ($key === null) {
                $key = $engine;
                $sleeves[$key] = ['enabled' => false, 'budget_usdt' => 0.0, 'symbols' => []];
            }
            if (!is_array($sleeves[$key])) {
                $sleeves[$key] = ['enabled' => false, 'budget_usdt' => 0.0, 'symbols' => []];
            }
            $sleeves[$key]['symbols'] = $wanted;

            $validated = Risk::validateConfig(['sleeves' => $sleeves], $fresh);
            $newCfg    = isset($validated[0]) && is_array($validated[0]) ? $validated[0] : $fresh;
            $errors    = isset($validated[1]) && is_array($validated[1]) ? $validated[1] : [];
            if ($errors !== []) {
                return ['status' => 'error', 'summary' => 'Refused by the config validator: ' . implode(' ', array_map('strval', $errors)), 'ms' => 0];
            }
            // If validateConfig does not parse sleeves it hands them back unchanged; apply then.
            $before = isset($fresh['sleeves']) && is_array($fresh['sleeves']) ? $fresh['sleeves'] : [];
            $after  = isset($newCfg['sleeves']) && is_array($newCfg['sleeves']) ? $newCfg['sleeves'] : [];
            if ($after == $before) {
                $newCfg['sleeves'] = $sleeves;
            }
            trader_save_config($newCfg);
            Log::warn('panel: sleeve symbol assigned', ['engine' => $engine, 'symbol' => $symbol,
                'from' => $current, 'to' => $wanted]);
            return [
                'status'  => 'ok',
                'summary' => $single
                    ? $symbol . ' is now the only symbol of the "' . $engine . '" sleeve (it trades exactly one).'
                    : $symbol . ' was added to the "' . $engine . '" sleeve (' . implode(', ', $wanted) . ').',
                'ms'      => 0,
            ];
        });
        $status = (string) ($res['status'] ?? 'ok');
        if ($status === 'skipped') {
            Panel::flash('warn', 'Skipped: a tick is running right now. Try again in a few seconds.');
        } else {
            Panel::flash($status === 'ok' ? 'ok' : 'danger', (string) ($res['summary'] ?? ''));
        }
    } catch (Throwable $e) {
        Log::error('panel: assign_symbol failed - ' . $e->getMessage());
        Panel::flash('danger', 'Assign failed: ' . $e->getMessage());
    }
    Panel::redirect('?page=dashboard');
}

/* ---------------------------------------------------------- learning actions */

/**
 * "Recompute now" (DESIGN-LEARNING.md §6): runs Learn::recompute() and reports its
 * decision verbatim - including "it did nothing, and here is why", which is the
 * usual and honest answer.
 *
 * Deliberately NOT wrapped in Bot::runLocked(): Learn's entire write surface is the
 * two state keys learn_weights / learn_at (plus the learn_log it prints here). It
 * places no order, touches no position and cannot reach size, take-profit,
 * stop-loss, a sleeve budget or any kill switch, so it can never race a tick into
 * anything dangerous - and holding the tick lock for a statistics pass would only
 * cost the operator a minute of trading.
 */
function handle_learning_action(array $cfg, Db $db): void
{
    if (!class_exists('Learn')) {
        Panel::flash('danger', 'lib/Learn.php is missing - there is nothing to recompute.');
        Panel::redirect('?page=insights');
    }
    try {
        $res = Learn::recompute($db, $cfg);
    } catch (Throwable $e) {
        Log::error('panel: learning recompute failed - ' . $e->getMessage());
        Panel::flash('danger', 'Recompute failed: ' . $e->getMessage());
        Panel::redirect('?page=insights');
        return;
    }
    $reason  = (string) ($res['reason'] ?? '');
    $note    = trim((string) ($res['note'] ?? ''));
    $applied = !empty($res['applied']);
    $ok      = !empty($res['ok']);
    if (!$ok) {
        $level = 'danger';
    } elseif ($applied) {
        $level = 'ok';
    } elseif ($reason === 'too_soon' || $reason === 'learning_disabled') {
        $level = 'warn';
    } else {
        $level = 'info';
    }
    $head = 'Recompute';
    if ($applied && ($res['changed'] ?? null) !== null) {
        $head .= ' applied: "' . (string) $res['changed'] . '" ' . (int) $res['from'] . ' → ' . (int) $res['to'] . ' points';
    } elseif ($applied) {
        $head .= ' ran and changed nothing';
    } elseif ($reason === 'too_soon') {
        $head .= ' skipped (too soon)';
    } elseif ($reason === 'learning_disabled') {
        $head .= ' skipped (learning is off)';
    } elseif ($reason === 'learning_apply_off') {
        $head .= ' was a dry run (learning_apply is off)';
    } else {
        $head .= ' finished';
    }
    Log::info('panel: learning recompute', [
        'reason' => $reason, 'applied' => $applied ? 1 : 0,
        'changed' => (string) ($res['changed'] ?? ''), 'samples' => (int) ($res['samples'] ?? 0),
    ]);
    Panel::flash($level, $head . '. ' . $note);
    Panel::redirect('?page=insights');
}

/* --------------------------------------------------------------- BNB actions */

/**
 * `toggle_bnb_burn` and its read-only sibling `check_bnb_burn` (DESIGN-LEARNING.md §7).
 *
 * Both are POST + CSRF and both end in Panel::bnbWrite(), which is the ONLY thing
 * that fills the display cache the API health card reads - no page render ever
 * makes a signed call.
 *
 * When the endpoint answers `null` the host simply does not serve `/sapi` (demo and
 * testnet do not). That is reported as INFORMATION with a pointer to the Binance UI,
 * never as an error.
 */
function handle_bnb_action(string $action, array $cfg, Db $db): void
{
    $mode = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
    if ($mode === 'paper') {
        Panel::flash('info', 'Paper mode simulates fills with the configured fee_pct, so there is no BNB discount to change. '
            . 'It becomes real in demo, testnet and live mode.');
        Panel::redirect('?page=dashboard');
    }
    $key    = trim((string) ($cfg['api_key'] ?? ''));
    $secret = trim((string) ($cfg['api_secret'] ?? ''));
    if ($key === '' || $secret === '') {
        Panel::flash('warn', 'Mode "' . $mode . '" has no API key stored, so the BNB fee discount cannot be read or changed.');
        Panel::redirect('?page=dashboard');
    }
    $want = null;   // null = read only
    if ($action === 'toggle_bnb_burn' && isset($_POST['burn']) && is_string($_POST['burn'])) {
        $want = $_POST['burn'] === '1';
    }

    $row = ['available' => false, 'spot' => false, 'interest' => false, 'free' => 0.0,
            'price' => 0.0, 'value' => 0.0, 'at' => Util::nowIso(), 'mode' => $mode, 'note' => ''];
    try {
        $api    = new Binance($key, $secret, Binance::normalizeNetwork($mode), (int) ($cfg['recv_window'] ?? 10000));
        $status = null;
        if ($want !== null) {
            $status = $api->setBnbBurn($want);
        }
        if ($status === null) {
            // either a read-only check, or the toggle was not served: ask for the status
            $status = $api->bnbBurnStatus();
        }
        if (is_array($status)) {
            $row['available'] = true;
            $row['spot']      = !empty($status['spot']);
            $row['interest']  = !empty($status['interest']);
        } else {
            $row['note'] = 'GET/POST /sapi/v1/bnbBurn returned nothing on ' . $api->tradeUrl() . '.';
        }
        // the free BNB balance and, best effort, what it is worth in USDT
        try {
            $acc = $api->account();
            $bal = isset($acc['balances']) && is_array($acc['balances']) ? $acc['balances'] : [];
            $row['free'] = isset($bal['BNB']['free']) ? (float) $bal['BNB']['free'] : 0.0;
        } catch (Throwable $e) {
            Log::warn('panel: BNB balance unavailable - ' . $e->getMessage());
        }
        try {
            $px = $api->prices(['BNBUSDT']);
            if (isset($px['BNBUSDT']) && is_numeric($px['BNBUSDT'])) {
                $row['price'] = (float) $px['BNBUSDT'];
                $row['value'] = $row['free'] * $row['price'];
            }
        } catch (Throwable $e) {
            Log::warn('panel: BNBUSDT price unavailable - ' . $e->getMessage());
        }
        Panel::bnbWrite($db, $row);

        $min = Panel::bnbMinBalance($cfg);
        if (!$row['available']) {
            Panel::flash('info', 'This host does not serve /sapi, so the bot cannot read or change the BNB fee discount. '
                . 'The setting lives in the Binance UI instead (Profile → Fee settings → "Using BNB to pay for fees"). '
                . 'Demo and testnet never serve it - this is information, not an error.');
        } else {
            $on   = !empty($row['spot']);
            $text = 'BNB fee discount is ' . ($on ? 'ON' : 'OFF') . ' - an effective round trip costs '
                  . Util::money(Panel::bnbRoundTrip($on), 3) . ' %. Free BNB ' . Util::toDecimalString($row['free'], 8)
                  . ($row['price'] > 0.0 ? ' (≈ ' . Util::money($row['value'], 4) . ' USDT)' : '') . '.';
            if ($on && $row['price'] > 0.0 && $row['value'] < $min) {
                Panel::flash('warn', $text . ' That is below bnb_min_balance = ' . Util::money($min, 4)
                    . ': Binance silently reverts to charging the fee in the received asset, which changes both the fee and '
                    . 'the dust behaviour mid-run.');
            } elseif ($on && $row['price'] <= 0.0) {
                // bnb_min_balance is a USDT threshold: with no BNBUSDT price the raw BNB
                // quantity is NOT comparable to it, so say the check did not happen.
                Panel::flash('info', $text . ' The BNBUSDT price could not be read, so that balance could not be valued in '
                    . 'USDT and was NOT checked against bnb_min_balance = ' . Util::money($min, 4)
                    . '. Check it by hand, or press "Check BNB status" again.');
            } else {
                Panel::flash('ok', $text);
            }
        }
        Log::info('panel: bnb burn ' . ($want === null ? 'checked' : ($want ? 'enabled' : 'disabled')), [
            'available' => $row['available'] ? 1 : 0, 'spot' => $row['spot'] ? 1 : 0, 'free' => $row['free'],
        ]);
    } catch (BinanceException $e) {
        Log::error('panel: bnb burn call failed - ' . $e->getMessage());
        Panel::flash('danger', 'Binance refused the BNB call: ' . $e->getMessage() . ' (code ' . $e->binanceCode
            . ', HTTP ' . $e->httpStatus . ').');
    } catch (Throwable $e) {
        Log::error('panel: bnb burn call failed - ' . $e->getMessage());
        Panel::flash('danger', 'The BNB call failed: ' . $e->getMessage());
    }
    Panel::redirect('?page=dashboard');
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
        'pmm_spread_pct', 'pmm_order_usdt', 'pmm_refresh_sec', 'pmm_target_base_pct', 'pmm_max_base_pct',
        // portfolio + scanner (DESIGN-PORTFOLIO.md §2); the per-sleeve fields are nested and are
        // collected separately by settings_sleeve_input()
        'portfolio_enabled', 'sleeve_reserve_pct', 'sleeve_max_drawdown_pct',
        'scanner_enabled', 'scanner_refresh_min', 'scanner_min_quote_vol', 'scanner_max_spread_pct',
        'scanner_min_atr_pct', 'scanner_max_atr_pct', 'scanner_top_n', 'scanner_exclude',
        // learning + the BNB fee discount (DESIGN-LEARNING.md §5, §7)
        'learning_enabled', 'learning_apply', 'learn_min_samples', 'learn_recompute_hours', 'learn_window_days',
        'bnb_min_balance'];
}

/** Settings keys rendered as checkboxes: absent from the POST means "off", never "unchanged". */
function settings_checkbox_keys(): array
{
    return ['enabled', 'adaptive', 'allow_live_engines', 'grid_exit_liquidates', 'post_only',
        'portfolio_enabled', 'scanner_enabled', 'learning_enabled', 'learning_apply'];
}

/**
 * DESIGN-PORTFOLIO.md §2 defaults. Used only to render the form (and to sanitise a value the
 * config validator does not know yet) on an install whose config.php predates portfolio mode -
 * exactly like panel_engine_defaults() does for the engines.
 */
function panel_portfolio_defaults(): array
{
    return [
        'portfolio_enabled'       => false,
        'sleeve_reserve_pct'      => 5.0,
        'sleeve_max_drawdown_pct' => 25.0,
        'scanner_enabled'         => true,
        'scanner_refresh_min'     => 60,
        'scanner_min_quote_vol'   => 5000000.0,
        'scanner_max_spread_pct'  => 0.06,
        'scanner_min_atr_pct'     => 0.5,
        'scanner_max_atr_pct'     => 4.0,
        'scanner_top_n'           => 10,
        'scanner_exclude'         => ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT'],
        'sleeves'                 => [
            'signal' => ['enabled' => true,  'budget_usdt' => 1000.0, 'symbols' => ['SOLUSDT', 'ETHUSDT']],
            'grid'   => ['enabled' => true,  'budget_usdt' => 1000.0, 'symbols' => ['DOGEUSDT']],
            'pmm'    => ['enabled' => false, 'budget_usdt' => 1000.0, 'symbols' => ['XRPUSDT']],
        ],
    ];
}

/**
 * DESIGN-LEARNING.md §5 defaults plus the §7 `bnb_min_balance`. Used only to render the form
 * (and to sanitise a value the config validator does not know yet) on an install whose
 * config.php predates learning - exactly like panel_engine_defaults() and
 * panel_portfolio_defaults() do for their sections. Panel::learnDefaults() is the single
 * source, so the form and the panel's own reading of the keys can never drift apart.
 */
function panel_learning_defaults(): array
{
    return Panel::learnDefaults();
}

/**
 * Last-resort sanitiser for the §5 / §7 keys: applied ONLY to a key that
 * Risk::validateConfig() did not return at all (an install whose lib/Risk.php predates
 * learning), so a validator that does know the key always wins, clamps included.
 *
 * Note what is NOT here: nothing in this function can reach position size, take-profit,
 * stop-loss, a sleeve budget or a kill switch. The learning settings are five numbers and
 * two flags, and the bounds below are the ones DESIGN-LEARNING.md §5 documents.
 */
function settings_learning_fallback(array $in, array $cfg, array $newCfg): array
{
    foreach (panel_learning_defaults() as $key => $def) {
        if (array_key_exists($key, $newCfg)) {
            continue;
        }
        if (!array_key_exists($key, $in)) {
            $newCfg[$key] = array_key_exists($key, $cfg) ? $cfg[$key] : $def;
            continue;
        }
        $raw = $in[$key];
        switch ($key) {
            case 'learning_enabled':
            case 'learning_apply':
                $newCfg[$key] = (string) $raw === '1';
                break;
            case 'learn_min_samples':
                $newCfg[$key] = (int) Util::clamp(panel_num($raw, 60.0), 1.0, 100000.0);
                break;
            case 'learn_recompute_hours':
                $newCfg[$key] = (int) Util::clamp(panel_num($raw, 168.0), 1.0, 8760.0);
                break;
            case 'learn_window_days':
                $newCfg[$key] = (int) Util::clamp(panel_num($raw, 90.0), 1.0, 3650.0);
                break;
            case 'bnb_min_balance':
                $newCfg[$key] = Util::clamp(panel_num($raw, Panel::BNB_MIN_BALANCE_DEFAULT), 0.0, 100000.0);
                break;
            default:
                break;
        }
    }
    return $newCfg;
}

/**
 * The per-sleeve form fields as the nested `sleeves` map of §2, or null when the posted form
 * carried no sleeve field at all (an old cached form must never wipe the sleeves).
 */
function settings_sleeve_input()
{
    $seen = false;
    $out  = [];
    foreach (panel_sleeve_engines() as $engine) {
        $bKey = 'sleeve_' . $engine . '_budget_usdt';
        $sKey = 'sleeve_' . $engine . '_symbols';
        $eKey = 'sleeve_' . $engine . '_enabled';
        if (isset($_POST[$bKey]) || isset($_POST[$sKey]) || isset($_POST[$eKey]) || isset($_POST['portfolio_form'])) {
            $seen = true;
        }
        $budget = isset($_POST[$bKey]) && is_string($_POST[$bKey]) ? trim($_POST[$bKey]) : '';
        $syms   = isset($_POST[$sKey]) && is_string($_POST[$sKey]) ? trim($_POST[$sKey]) : '';
        $list   = [];
        foreach (preg_split('/[\s,;]+/', $syms) as $sym) {
            $sym = strtoupper(trim((string) $sym));
            if ($sym !== '' && !in_array($sym, $list, true)) {
                $list[] = $sym;
            }
        }
        $out[$engine] = [
            'enabled'     => isset($_POST[$eKey]),
            'budget_usdt' => is_numeric($budget) ? (float) $budget : 0.0,
            'symbols'     => $list,
        ];
    }
    return $seen ? $out : null;
}

/** A finite float from a posted string, or $d. */
function panel_num($v, float $d): float
{
    return (is_numeric($v) && is_finite((float) $v)) ? (float) $v : $d;
}

/**
 * Last-resort sanitiser for the §2 keys: applied ONLY to a key that Risk::validateConfig()
 * did not return at all (an install whose lib/Risk.php predates portfolio mode), so a
 * validator that does know the key always wins, clamps included.
 */
function settings_portfolio_fallback(array $in, array $cfg, array $newCfg): array
{
    $d = panel_portfolio_defaults();
    foreach ($d as $key => $def) {
        if ($key === 'sleeves' || array_key_exists($key, $newCfg)) {
            continue;
        }
        if (!array_key_exists($key, $in)) {
            $newCfg[$key] = array_key_exists($key, $cfg) ? $cfg[$key] : $def;
            continue;
        }
        $raw = $in[$key];
        switch ($key) {
            case 'portfolio_enabled':
            case 'scanner_enabled':
                $newCfg[$key] = (string) $raw === '1';
                break;
            case 'sleeve_reserve_pct':
                $newCfg[$key] = Util::clamp(panel_num($raw, 5.0), 0.0, 90.0);
                break;
            case 'sleeve_max_drawdown_pct':
                $newCfg[$key] = Util::clamp(panel_num($raw, 25.0), 1.0, 100.0);
                break;
            case 'scanner_refresh_min':
                $newCfg[$key] = (int) Util::clamp(panel_num($raw, 60.0), 1.0, 1440.0);
                break;
            case 'scanner_min_quote_vol':
                $newCfg[$key] = max(0.0, panel_num($raw, 5000000.0));
                break;
            case 'scanner_max_spread_pct':
                $newCfg[$key] = Util::clamp(panel_num($raw, 0.06), 0.001, 10.0);
                break;
            case 'scanner_min_atr_pct':
                $newCfg[$key] = Util::clamp(panel_num($raw, 0.5), 0.0, 50.0);
                break;
            case 'scanner_max_atr_pct':
                $newCfg[$key] = Util::clamp(panel_num($raw, 4.0), 0.01, 100.0);
                break;
            case 'scanner_top_n':
                $newCfg[$key] = (int) Util::clamp(panel_num($raw, 10.0), 1.0, 100.0);
                break;
            case 'scanner_exclude':
                $list = [];
                foreach (preg_split('/[\s,;]+/', (string) $raw) as $sym) {
                    $sym = strtoupper(trim((string) $sym));
                    if ($sym !== '' && !in_array($sym, $list, true)) {
                        $list[] = $sym;
                    }
                }
                $newCfg[$key] = $list;
                break;
            default:
                break;
        }
    }
    return $newCfg;
}

/**
 * Validation errors of §2 grouped by the sleeve they name, so the settings form can print them
 * against the offending sleeve instead of only in the list at the top. An error that names two
 * sleeves (the overlap rule reports both) is shown against both; one that talks about the
 * portfolio without naming a sleeve lands in the '' bucket.
 */
function settings_sleeve_errors(array $errors): array
{
    $out = ['' => []];
    foreach (panel_sleeve_engines() as $engine) {
        $out[$engine] = [];
    }
    foreach ($errors as $err) {
        $msg = (string) $err;
        $low = strtolower($msg);
        // every §2 sleeve rule names the sleeve ("sleeve grid: …", "X is in the grid sleeve and
        // in the pmm sleeve"), so this never claims an unrelated engine error
        if (strpos($low, 'sleeve') === false && strpos($low, 'portfolio') === false) {
            continue;
        }
        $hit = false;
        foreach (panel_sleeve_engines() as $engine) {
            if (preg_match('/\b' . preg_quote($engine, '/') . '\b/', $low) === 1) {
                $out[$engine][] = $msg;
                $hit = true;
            }
        }
        if (!$hit) {
            $out[''][] = $msg;
        }
    }
    return $out;
}

/** Inline error list for one sleeve (or the portfolio section); '' when there is nothing to say. */
function panel_inline_errors(array $msgs): string
{
    if ($msgs === []) {
        return '';
    }
    $h = '<div class="flash flash-danger inline-errors"><ul>';
    foreach ($msgs as $m) {
        $h .= '<li>' . Panel::e((string) $m) . '</li>';
    }
    return $h . '</ul></div>';
}

/**
 * Settings → Portfolio (DESIGN-PORTFOLIO.md §7): portfolio_enabled, per sleeve enabled / budget /
 * symbols with the §2 validation errors inline, and the scanner thresholds. Visibility of the
 * sleeve fields is the `is-hidden` CLASS toggled by assets/panel.js - the CSP forbids inline
 * styles and inline scripts alike.
 */
function page_settings_portfolio(array $v, array $errors): string
{
    $byS = settings_sleeve_errors($errors);
    $on  = !empty($v['portfolio_enabled']);
    $sleeves = isset($v['sleeves']) && is_array($v['sleeves']) ? $v['sleeves'] : [];

    $h  = '<fieldset><legend>Portfolio</legend>';
    $h .= '<input type="hidden" name="portfolio_form" value="1">';
    $h .= panel_inline_errors($byS['']);
    $h .= '<p class="muted small">Portfolio mode runs the three methods <strong>at the same time</strong>, each with its own slice of '
        . 'capital and its own symbols. A sleeve budget is an <strong>accounting</strong> boundary, not an exchange one: Binance holds a '
        . 'single balance and the bot enforces the split itself. Two sleeves must therefore never share a symbol - one would sell the '
        . 'inventory the other bought - and the validator rejects any overlap outright. With portfolio mode off, everything behaves '
        . 'exactly as the single-engine panel above.</p>';
    $h .= panel_check('portfolio_enabled', $v, 'Run the sleeves in parallel (portfolio mode).',
        '<span class="muted">Off = the single Engine selected above, unchanged.</span>');
    $h .= '<div class="grid3">';
    $h .= panel_input('sleeve_reserve_pct', $v, 'Reserve %', 'number', 'percent of the total quote balance never allocated to a sleeve');
    $h .= panel_input('sleeve_max_drawdown_pct', $v, 'Sleeve max drawdown %', 'number', 'a sleeve under budget x (1 - this) stops opening; the others keep trading');
    $h .= '</div>';

    $h .= '<div class="portfolio-sleeves' . ($on ? '' : ' is-hidden') . '" data-portfolio-fields>';
    foreach (panel_sleeve_engines() as $engine) {
        $cur    = isset($sleeves[$engine]) && is_array($sleeves[$engine]) ? $sleeves[$engine] : [];
        $sv     = [
            'sleeve_' . $engine . '_enabled'     => !empty($cur['enabled']),
            'sleeve_' . $engine . '_budget_usdt' => isset($cur['budget_usdt']) ? $cur['budget_usdt'] : 0.0,
            'sleeve_' . $engine . '_symbols'     => isset($cur['symbols']) && is_array($cur['symbols']) ? implode(', ', $cur['symbols']) : (string) ($cur['symbols'] ?? ''),
        ];
        $single = in_array($engine, panel_single_symbol_engines(), true);
        $h .= '<div class="sleeve-group" data-sleeve="' . Panel::e($engine) . '">';
        $h .= '<h3>' . Panel::e($engine) . ' sleeve</h3>';
        $h .= panel_inline_errors($byS[$engine]);
        $h .= panel_check('sleeve_' . $engine . '_enabled', $sv, 'Enabled');
        $h .= '<div class="grid2">';
        $h .= panel_input('sleeve_' . $engine . '_budget_usdt', $sv, 'Budget (quote)', 'number',
            'must cover about 20 minimum-size orders of its symbols');
        $h .= panel_input('sleeve_' . $engine . '_symbols', $sv, 'Symbols', 'text',
            $single ? 'exactly one symbol - this engine is single-symbol' : 'comma separated, exclusive to this sleeve');
        $h .= '</div></div>';
    }
    $h .= '</div>';

    $h .= '<h3>Scanner</h3>';
    $h .= '<p class="muted small">The scanner ranks quote-asset pairs by <em>tradeable</em> volatility: ATR discounted by liquidity and '
        . 'spread, so an illiquid or wide-spread coin scores near zero. It costs weight 80 per refresh, which is why it runs hourly and '
        . 'never per tick. It <strong>never</strong> reassigns a sleeve\'s symbols on its own - it ranks and explains, and you apply a '
        . 'suggestion with the "Assign to…" control on the dashboard.</p>';
    $h .= panel_check('scanner_enabled', $v, 'Run the volatility scanner.');
    $h .= '<div class="grid3">';
    $h .= panel_input('scanner_refresh_min', $v, 'Refresh (minutes)', 'number', 'weight 80 per refresh', '1');
    $h .= panel_input('scanner_min_quote_vol', $v, 'Min 24 h quote volume', 'number', 'the liquidity floor, in the quote asset');
    $h .= panel_input('scanner_max_spread_pct', $v, 'Max spread %', 'number', '(ask - bid) / mid');
    $h .= panel_input('scanner_min_atr_pct', $v, 'Min ATR14 % (15m)', 'number', 'below this the pair does not move enough to trade');
    $h .= panel_input('scanner_max_atr_pct', $v, 'Max ATR14 % (15m)', 'number', 'above this is untradeable noise, not opportunity');
    $h .= panel_input('scanner_top_n', $v, 'Rows shown', 'number', 'how many ranked rows the dashboard lists', '1');
    $h .= '</div>';
    $exclude = isset($v['scanner_exclude']) && is_array($v['scanner_exclude']) ? implode(', ', $v['scanner_exclude']) : (string) ($v['scanner_exclude'] ?? '');
    $h .= '<label>Never rank these pairs<span class="muted help">stablecoin pairs and anything else that should never be suggested</span>'
        . '<textarea name="scanner_exclude" rows="2" spellcheck="false">' . Panel::e($exclude) . '</textarea></label>';
    $h .= '</fieldset>';
    return $h;
}

/**
 * Symbols whose resting engine orders no tick will ever reconcile again after this save.
 * Only an ENABLED sleeve is run by `Bot::runPortfolioTick()`, and `EngineOrders::sync()` is
 * reached only from there, so a sleeve that was just disabled, emptied or pointed at another
 * symbol would leave its ladder resting on the book: it keeps filling into base that no `lots`
 * row records, that no sleeve values and that the kill switch cannot flatten.
 *
 * @return array list of symbols, upper case
 */
function settings_stranded_symbols(array $newCfg, Db $db): array
{
    $keep = [];
    if (!empty($newCfg['portfolio_enabled']) && class_exists('Sleeve')) {
        foreach (Sleeve::all($newCfg) as $sleeve) {
            if (empty($sleeve['enabled'])) {
                continue;
            }
            foreach ($sleeve['symbols'] as $sym) {
                $keep[strtoupper(trim((string) $sym))] = true;
            }
        }
    } else {
        // single-engine mode manages exactly one symbol, and only for grid/pmm
        $sym = strtoupper(trim((string) ($newCfg['engine_symbol'] ?? '')));
        if ($sym !== '' && strtolower(trim((string) ($newCfg['engine'] ?? 'signal'))) !== 'signal') {
            $keep[$sym] = true;
        }
    }
    $mode = strtolower(trim((string) ($newCfg['mode'] ?? 'paper')));
    $out  = [];
    try {
        $rows = $db->engineOrders(Db::ENGINE_LIVE_STATUSES, $mode);
    } catch (Throwable $e) {
        return [];   // no engine_orders table on an install that predates the engines
    }
    foreach ($rows as $row) {
        $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
        if ($sym === '' || isset($keep[$sym]) || in_array($sym, $out, true)) {
            continue;
        }
        $out[] = $sym;
    }
    return $out;
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
    // the per-sleeve fields (DESIGN-PORTFOLIO.md §2) are nested under `sleeves`; they are also
    // passed flat so a validator that reads either shape sees them
    $sleeveIn = settings_sleeve_input();
    if ($sleeveIn !== null) {
        $in['sleeves'] = $sleeveIn;
        foreach ($sleeveIn as $sleeveEngine => $sleeveVals) {
            $in['sleeve_' . $sleeveEngine . '_enabled']     = $sleeveVals['enabled'] ? '1' : '0';
            $in['sleeve_' . $sleeveEngine . '_budget_usdt'] = $sleeveVals['budget_usdt'];
            $in['sleeve_' . $sleeveEngine . '_symbols']     = implode(', ', $sleeveVals['symbols']);
        }
    }
    // the secret is write-only: blank keeps the stored one (validateConfig skips blanks)
    $validated = Risk::validateConfig($in, $cfg);
    $newCfg    = $validated[0];
    $errors    = isset($validated[1]) && is_array($validated[1]) ? $validated[1] : [];
    $warnings  = isset($validated[2]) && is_array($validated[2]) ? $validated[2] : [];
    // only for keys the validator does not know at all (older lib/Risk.php)
    $newCfg    = settings_portfolio_fallback($in, $cfg, $newCfg);
    $newCfg    = settings_learning_fallback($in, $cfg, $newCfg);
    if ($sleeveIn !== null) {
        $sleeveBefore = isset($cfg['sleeves']) && is_array($cfg['sleeves']) ? $cfg['sleeves'] : [];
        $sleeveAfter  = isset($newCfg['sleeves']) && is_array($newCfg['sleeves']) ? $newCfg['sleeves'] : [];
        if ($sleeveAfter == $sleeveBefore && $sleeveIn != $sleeveBefore) {
            $newCfg['sleeves'] = $sleeveIn;
        }
    }
    // A symbol may only move INTO a sleeve while it is empty of foreign inventory. The
    // dashboard "Assign to..." action refuses that already; this is the same rule on the
    // Settings form, which would otherwise be the way around it. Only symbols being ADDED are
    // checked, so an unchanged save, a budget edit, disabling a sleeve or removing a symbol are
    // never blocked. The "sleeve <engine>" prefix is what settings_sleeve_errors() keys on to
    // render the message inline against the offending sleeve.
    if ($sleeveIn !== null) {
        $sleevesNow = isset($cfg['sleeves']) && is_array($cfg['sleeves']) ? $cfg['sleeves'] : [];
        foreach ($sleeveIn as $sleeveEngine => $sleeveVals) {
            $had = [];
            foreach ($sleevesNow as $k => $v) {
                if (strtolower(trim((string) $k)) !== strtolower(trim((string) $sleeveEngine))) {
                    continue;
                }
                $syms = (is_array($v) && isset($v['symbols']) && is_array($v['symbols'])) ? $v['symbols'] : [];
                foreach ($syms as $s) {
                    $had[] = strtoupper(trim((string) $s));
                }
            }
            $posted = (is_array($sleeveVals) && isset($sleeveVals['symbols']) && is_array($sleeveVals['symbols']))
                ? $sleeveVals['symbols'] : [];
            foreach ($posted as $sym) {
                $sym = strtoupper(trim((string) $sym));
                if ($sym === '' || in_array($sym, $had, true)) {
                    continue;   // unchanged symbol: never blocked
                }
                foreach (panel_symbol_foreign_inventory($cfg, $db, $sym, (string) $sleeveEngine) as $line) {
                    $errors[] = 'sleeve ' . $sleeveEngine . ': ' . $line
                        . ' - flatten it under the engine that bought it before moving it here.';
                }
            }
        }
    }

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
        if (($cfg['sleeves'] ?? null) != ($newCfg['sleeves'] ?? null)) {
            $changed[] = 'sleeves';
        }
        Log::info('panel: settings saved', ['changed' => $changed, 'mode' => $newMode]);
        if ((string) ($cfg['mode'] ?? '') !== $newMode) {
            Log::warn('panel: mode changed', ['from' => (string) ($cfg['mode'] ?? ''), 'to' => $newMode]);
            // The survival state belongs to the account we just left. Risk::survivalCheck()
            // re-seeds equity_hwm / day_start_equity from the new account on the next tick.
            // 'halted'/'halt_reason' are NOT cleared: a halt still needs a manual reset.
            // learn_weights/learn_at go too: Learn::evidenceRows() draws evidence from one
            // mode only, so a map learned on paper must not score a live account (and its
            // stamp must not hold the first live recompute off for learn_recompute_hours).
            // 'learn_log' is kept: it is the audit trail of what was decided and when.
            foreach ([
                'equity_hwm', 'day_start_equity', 'day_start_date', 'consecutive_losses',
                'last_loss_at', 'cooldown_until', 'paused_until', 'pause_reason',
                'effective_threshold', 'effective_max_trades', 'last_adapt_date',
                'adapt_max_since_closed', 'no_trade_reason',
                'learn_weights', 'learn_at',
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

        // The same hazard for the sleeves (DESIGN-PORTFOLIO.md §6): a grid/pmm sleeve that was
        // just disabled, emptied or pointed at another symbol is no longer run by the tick, so
        // nothing calls EngineOrders::sync() for it and its ladder rests on the book forever.
        // Take those symbols off the book here, exactly as for the single engine above.
        $sleevesChanged = (($cfg['sleeves'] ?? null) != ($newCfg['sleeves'] ?? null))
            || (!empty($cfg['portfolio_enabled']) !== !empty($newCfg['portfolio_enabled']));
        $stranded = ($sleevesChanged && class_exists('Bot')) ? settings_stranded_symbols($newCfg, $db) : [];
        if ($stranded !== []) {
            try {
                $res = Bot::runLocked(static function () use ($newCfg, $db, $stranded): array {
                    $ex = Exchange::factory($newCfg, $db);
                    $n  = 0;
                    foreach ($stranded as $sym) {
                        $n += (int) panel_engine_orders($newCfg, $db, $ex, $sym)->cancelAll($sym, 'sleeve_changed');
                    }
                    return ['status' => 'ok', 'summary' => (string) $n, 'ms' => 0];
                });
                if ((string) ($res['status'] ?? '') === 'skipped') {
                    Panel::flash('warn', 'A tick was running, so the resting orders on ' . implode(', ', $stranded)
                        . ' were left on the book and no sleeve syncs them now: press "Cancel all orders" on the dashboard.');
                } else {
                    $n = (int) ($res['summary'] ?? 0);
                    if ($n > 0) {
                        Panel::flash('warn', $n . ' resting order(s) on ' . implode(', ', $stranded) . ' were cancelled: no enabled '
                            . 'sleeve owns those symbols any more. Inventory they still hold stays in the wallet - re-enable the sleeve '
                            . 'and use "Flatten inventory" to sell it.');
                    }
                }
            } catch (Throwable $e) {
                Log::error('panel: cancelling the stranded sleeve orders failed - ' . $e->getMessage());
                Panel::flash('warn', 'The resting orders of a disabled sleeve could not be cancelled: ' . $e->getMessage());
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
    $h .= '<link rel="stylesheet" href="assets/panel.css?v=' . panel_asset_version('assets/panel.css') . '">';
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
        $h .= '<a href="?page=insights"' . ($current === 'insights' ? ' class="active"' : '') . '>Insights</a>';
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
    $h .= '</footer><script src="assets/panel.js?v=' . panel_asset_version('assets/panel.js') . '" defer></script></body></html>';
    return $h;
}

/**
 * Cache-busting token for a static asset: the file's modification time, so a deploy
 * (git pull, FTP upload) always invalidates the browser's copy. Falls back to the
 * release marker when the file cannot be stat'ed.
 */
function panel_asset_version(string $relPath): string
{
    static $cache = [];
    if (isset($cache[$relPath])) {
        return $cache[$relPath];
    }
    $full = TRADER_ROOT . '/' . ltrim($relPath, '/');
    $mtime = @filemtime($full);
    $cache[$relPath] = ($mtime !== false && $mtime > 0) ? (string) $mtime : '2';
    return $cache[$relPath];
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

    // ---- the one learning line (DESIGN-LEARNING.md §6): the strongest CONFIDENT insight with
    //      its sample size, or "not enough data yet" - which is the honest answer for weeks.
    $h .= '<section class="card learn-card"><h2>What the evidence says</h2>';
    $h .= '<p class="learn-line">' . Panel::pill((string) ($s['text']['learn_line'] ?? 'not enough data yet'),
        (string) ($s['levels']['learn_line'] ?? 'muted'), 'learn_line', 'pill-wide') . '</p>';
    $h .= '<p class="muted small">Every entry evaluation is recorded with the conditions present at the time, whether or not it '
        . 'traded, and resolved with the outcome that followed. A claim only counts when both sides clear the sample threshold and '
        . 'their confidence intervals do not overlap. <a href="?page=insights">Open the Insights page</a> for the per-condition '
        . 'tables, the current weights and what a recompute would do.</p>';
    $h .= '</section>';

    // ---- engine block (DESIGN-ENGINES.md §10): rendered only when grid or pmm is selected.
    // The signal-engine cards below stay exactly as they are when engine === 'signal'.
    $engineOn = !empty($s['show']['engine']);
    $h .= panel_engine_dashboard($s, $quote, $engineOn);

    // ---- portfolio block (DESIGN-PORTFOLIO.md §7): hidden unless portfolio_enabled, so the
    // single-engine and signal-engine cards keep working exactly as before when it is off.
    $h .= panel_portfolio_dashboard($s, $quote, !empty($s['show']['portfolio']));

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
    // BNB fee discount (DESIGN-LEARNING.md §7): it changes the fee arithmetic the learning
    // statistics measure, so it belongs next to the rest of the API health.
    $h .= panel_kv($s, 'BNB fee discount', 'bnb_burn', true);
    $h .= panel_kv($s, 'BNB balance', 'bnb_balance');
    $h .= panel_kv($s, 'Round-trip cost', 'bnb_round_trip');
    $h .= panel_kv($s, 'BNB checked', 'bnb_checked');
    $h .= '</dl>';
    $h .= '<p class="flash flash-warn bnb-warning" data-show="bnb_warning" data-field="bnb_warning"'
        . (empty($s['show']['bnb_warning']) ? ' hidden' : '') . '>' . $e((string) ($s['text']['bnb_warning'] ?? '')) . '</p>';
    $h .= '<p class="muted small" data-show="bnb_note" data-field="bnb_note"'
        . (empty($s['show']['bnb_note']) ? ' hidden' : '') . '>' . $e((string) ($s['text']['bnb_note'] ?? '')) . '</p>';
    $h .= '<div class="actions-row bnb-actions" data-show="bnb_toggle"' . (empty($s['show']['bnb_toggle']) ? ' hidden' : '') . '>';
    $h .= '<form method="post" action="index.php" class="inline">' . Panel::csrfField()
        . '<input type="hidden" name="action" value="check_bnb_burn">'
        . '<button type="submit" class="btn btn-mini">Check BNB status</button></form>';
    $h .= '<form method="post" action="index.php" class="inline" data-confirm="Pay Binance fees with BNB from now on? A round trip then costs 0.15 % instead of 0.2 %.">'
        . Panel::csrfField() . '<input type="hidden" name="action" value="toggle_bnb_burn">'
        . '<input type="hidden" name="burn" value="1"><button type="submit" class="btn btn-mini">Discount ON</button></form>';
    $h .= '<form method="post" action="index.php" class="inline" data-confirm="Stop paying Binance fees with BNB? A round trip then costs 0.2 % again.">'
        . Panel::csrfField() . '<input type="hidden" name="action" value="toggle_bnb_burn">'
        . '<input type="hidden" name="burn" value="0"><button type="submit" class="btn btn-mini">Discount OFF</button></form>';
    $h .= '</div>';
    $h .= '<p class="muted small">The configured <code>fee_pct</code> is informational only: in demo, testnet and live the taker '
        . 'rate comes from the account. With the BNB discount on an effective round trip costs 0.15 %, without it 0.2 % - which is '
        . 'the arithmetic every number on the Insights page is measured against.</p>';
    $h .= '</div>';

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

    // ---- scanner (DESIGN-PORTFOLIO.md §7)
    $h .= panel_scanner_dashboard($s, !empty($s['show']['scanner']));

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

/**
 * Portfolio card, overlaid per-sleeve equity sparkline and the per-sleeve pause / resume
 * actions (DESIGN-PORTFOLIO.md §7). Every section carries data-show="portfolio", so
 * assets/panel.js reveals or hides the whole block without a reload and without an inline
 * style attribute. With portfolio_enabled = false the block is simply hidden and every
 * single-engine and signal-engine card above it is untouched.
 */
function panel_portfolio_dashboard(array $s, string $quote, bool $on): string
{
    $e   = 'Panel::e';
    $hid = $on ? '' : ' hidden';
    $pf  = isset($s['portfolio']) && is_array($s['portfolio']) ? $s['portfolio'] : [];
    $sleeves = isset($pf['sleeves']) && is_array($pf['sleeves']) ? $pf['sleeves'] : [];

    $h  = '<section class="card portfolio-card" data-show="portfolio"' . $hid . '>';
    $h .= '<h2>Portfolio ' . Panel::pill((string) ($s['text']['pf_state'] ?? '-'), (string) ($s['levels']['pf_state'] ?? 'muted'), 'pf_state')
        . ' <span class="muted small" data-field="pf_sleeves">' . $e((string) ($s['text']['pf_sleeves'] ?? '')) . '</span></h2>';
    $h .= '<p class="muted small">Each sleeve is one method with its own budget and its own exclusive symbols. The budget is an '
        . '<strong>accounting</strong> boundary, not an exchange one: Binance holds a single balance and the bot enforces the split '
        . 'itself, which only works because two sleeves never share a symbol. The global kill switch still uses total equity and still '
        . 'halts everything.</p>';

    // per-sleeve pause / resume
    if ($sleeves !== []) {
        $h .= '<div class="actions-row sleeve-actions">';
        foreach ($sleeves as $c) {
            $eng = isset($c['engine']) ? (string) $c['engine'] : '';
            if ($eng === '') {
                continue;
            }
            $paused = !empty($c['paused']);
            $h .= '<span class="inline-group"><span class="mono sleeve-name">' . $e($eng) . '</span>';
            $h .= panel_sleeve_form('pause_sleeve', $eng, 'Pause', 'btn btn-mini btn-warn', $paused);
            $h .= panel_sleeve_form('resume_sleeve', $eng, 'Resume', 'btn btn-mini', !$paused);
            if ($eng === 'grid') {
                // a range exit pauses the grid sleeve alone (DESIGN-ENGINES.md §7.2); the engine
                // card that normally carries this button is hidden in portfolio mode, so without
                // it the sleeve could never be resumed from the panel
                $h .= '<form method="post" action="index.php" class="inline" data-confirm="Re-anchor the grid on the current mid price? This also clears a range-exit pause.">'
                    . Panel::csrfField() . '<input type="hidden" name="action" value="reanchor_grid">'
                    . '<button type="submit" class="btn btn-mini">Re-anchor</button></form>';
            }
            $h .= '</span>';
        }
        $h .= '</div><p class="muted small">Pausing one sleeve stops it opening new exposure; its exits stay managed and the other '
            . 'sleeves keep trading. That is what makes the comparison safe - a failing method stops itself without taking the account down.</p>';
    }

    $h .= '<div class="table-wrap"><table class="tbl"><thead><tr>'
        . '<th>Sleeve</th><th>Symbols</th><th>Budget</th><th>Equity</th><th>Realised ' . $e($quote) . '</th><th>Unrealised ' . $e($quote) . '</th>'
        . '<th>PnL %</th><th>Trades / cycles</th><th>Win rate</th><th>Fees</th><th>Used %</th><th>Status</th>'
        . '</tr></thead><tbody data-table="portfolio" data-cols="12">'
        . Panel::tableRows(isset($s['tables']['portfolio']) && is_array($s['tables']['portfolio']) ? $s['tables']['portfolio'] : [])
        . '</tbody></table></div>';

    // the honest headline: who leads, on how many trades, and why that is not a result yet
    $h .= '<p class="pf-best">' . Panel::pill((string) ($s['text']['pf_best'] ?? '-'), (string) ($s['levels']['pf_best'] ?? 'muted'), 'pf_best', 'pill-wide') . '</p>';
    $h .= '<p class="muted small" data-field="pf_best_caveat">' . $e((string) ($s['text']['pf_best_caveat'] ?? '')) . '</p>';

    $h .= '<dl class="kv pf-kv">';
    $h .= panel_kv($s, 'Total budget', 'pf_budget');
    $h .= panel_kv($s, 'Total sleeve equity', 'pf_equity', true);
    $h .= panel_kv($s, 'Realised', 'pf_realised', true);
    $h .= panel_kv($s, 'Unrealised', 'pf_unrealised', true);
    $h .= panel_kv($s, 'Sample', 'pf_sample');
    $h .= panel_kv($s, 'Reserve', 'pf_reserve');
    $h .= panel_kv($s, 'Sleeve drawdown pause', 'pf_drawdown');
    $h .= panel_kv($s, 'Unattributed base', 'pf_unattributed', true);
    $h .= panel_kv($s, 'Valuation', 'pf_valuation');
    $h .= '</dl>';
    $h .= '<p class="muted small">Unattributed base is a balance whose symbol belongs to no sleeve: it is excluded from every sleeve\'s '
        . 'numbers and still counts in total equity for the global kill switch.</p>';
    $h .= '</section>';

    // ---- overlaid per-sleeve equity sparkline
    $spark = isset($s['sleeve_sparkline']) && is_array($s['sleeve_sparkline']) ? $s['sleeve_sparkline'] : [];
    $h .= '<section class="card" data-show="portfolio"' . $hid . '><h2>Sleeve equity (last 288 samples)</h2>';
    $h .= '<div class="spark-wrap">' . Panel::svgMultiSparkline($spark) . '</div>';
    $h .= '<div class="spark-meta">';
    foreach ($sleeves as $c) {
        $eng = isset($c['engine']) ? (string) $c['engine'] : '';
        if ($eng === '') {
            continue;
        }
        $key = 'pf_leg_' . Panel::slug($eng);
        $h .= '<span class="legend-item"><span class="legend-swatch swatch-' . $e(Panel::slug($eng)) . '" aria-hidden="true"></span>'
            . '<span class="legend-name mono">' . $e($eng) . '</span> '
            . Panel::field($s, $key, 'span', 'legend-value') . '</span>';
    }
    $h .= '<span class="muted">min ' . Panel::field($s, 'pf_spark_min') . ' · max ' . Panel::field($s, 'pf_spark_max')
        . ' · ' . Panel::field($s, 'pf_spark_count') . '</span></div>';
    $h .= '<p class="muted small">All sleeves share one scale, so the lines are directly comparable. One sample is written per sleeve '
        . 'per tick; a flat line means the sleeve has not traded, not that it is broken.</p>';
    $h .= '</section>';

    return $h;
}

/** One sleeve action button (POST + CSRF); disabled when it would be a no-op. */
function panel_sleeve_form(string $action, string $engine, string $label, string $class, bool $disabled): string
{
    return '<form method="post" action="index.php" class="inline">' . Panel::csrfField()
        . '<input type="hidden" name="action" value="' . Panel::e($action) . '">'
        . '<input type="hidden" name="engine" value="' . Panel::e($engine) . '">'
        . '<button type="submit" class="' . Panel::e($class) . '"' . ($disabled ? ' disabled' : '') . '>'
        . Panel::e($label) . '</button></form>';
}

/**
 * Volatility scanner card (DESIGN-PORTFOLIO.md §5, §7): every documented column plus the
 * per-row "Assign to…" control. The scanner never reassigns anything by itself - this control
 * is the only way a suggestion is applied, and it refuses an assignment that would strand
 * inventory or take a symbol another sleeve owns.
 */
function panel_scanner_dashboard(array $s, bool $on): string
{
    $e   = 'Panel::e';
    $hid = $on ? '' : ' hidden';
    $h  = '<section class="card scanner-card" data-show="scanner"' . $hid . '>';
    $h .= '<h2>Scanner ' . Panel::pill((string) ($s['text']['pf_scanner_state'] ?? '-'), (string) ($s['levels']['pf_scanner_state'] ?? 'muted'), 'pf_scanner_state')
        . ' ' . Panel::pill((string) ($s['text']['pf_scanner_age'] ?? '-'), (string) ($s['levels']['pf_scanner_age'] ?? 'muted'), 'pf_scanner_age') . '</h2>';
    $h .= '<div class="table-wrap"><table class="tbl"><thead><tr>'
        . '<th>#</th><th>Symbol</th><th>Price</th><th>24 h change</th><th>ATR %</th><th>Spread</th><th>24 h quote vol</th>'
        . '<th>Step value</th><th>Required size</th><th>Score</th><th>Eligible / gates</th><th>Assign</th>'
        . '</tr></thead><tbody data-table="scanner" data-cols="12">'
        . Panel::tableRows(isset($s['tables']['scanner']) && is_array($s['tables']['scanner']) ? $s['tables']['scanner'] : [])
        . '</tbody></table></div>';
    $h .= '<p class="muted small">score = ATR % × liquidity factor × spread factor, so volatility alone never wins: an illiquid or '
        . 'wide-spread coin is penalised toward zero. A gated row is kept, not dropped, so the gate can explain the rejection. '
        . 'Refresh interval: <span data-field="pf_scanner_refresh">' . $e((string) ($s['text']['pf_scanner_refresh'] ?? '')) . '</span>; '
        . 'rows: <span data-field="pf_scanner_rows">' . $e((string) ($s['text']['pf_scanner_rows'] ?? '')) . '</span>.</p>';
    $h .= '<p class="muted small">"Assign to…" sets the symbol as that sleeve\'s symbol. It is refused when another sleeve already owns '
        . 'the symbol, and when the target sleeve still holds inventory - a silent symbol change under a live grid would strand it.</p>';
    $h .= '</section>';
    return $h;
}

/* -------------------------------------------------------------- settings */

/* ------------------------------------------------------------- insights page */

/**
 * The Insights page (DESIGN-LEARNING.md §6): what the bot's own history actually
 * shows, with the sample size next to every claim and a plain sentence whenever the
 * evidence is too thin to conclude anything - which, at a few trades a day, it will
 * be for weeks.
 *
 * Every dynamic value goes through Panel::e(); there is no inline script, no inline
 * handler and no style attribute anywhere in here (the CSP forbids all three, and
 * the open/closed state of a feature card is plain <details>, not JavaScript).
 */
function page_insights(array $cfg, array $ins): string
{
    $e = 'Panel::e';
    $head = isset($ins['header']) && is_array($ins['header']) ? $ins['header'] : [];
    $w    = isset($ins['weights']) && is_array($ins['weights']) ? $ins['weights'] : [];
    $sk   = isset($ins['skipped']) && is_array($ins['skipped']) ? $ins['skipped'] : [];
    $line = isset($ins['line']) && is_array($ins['line']) ? $ins['line'] : [];
    $num  = static function ($v): string {
        return (string) (int) $v;
    };

    // ---- header: the counts, then the honest sentence
    $h  = '<section class="card"><h1>Insights</h1>';
    $h .= '<p class="muted small">The bot records the conditions present at every entry evaluation - the ones it took and the ones '
        . 'it refused - and the outcome that followed. This page is honest, inspectable statistics over that history: it is not a '
        . 'model, it will not discover alpha, and it says so plainly whenever the evidence is too thin. Skipped evaluations are the '
        . 'control group; they carry no PnL and are never counted as wins or losses.</p>';
    $h .= '<p>' . Panel::pill((string) ($head['sentence'] ?? ''), (string) ($head['level'] ?? 'muted'), 'ins_sentence', 'pill-wide') . '</p>';
    $h .= '<dl class="kv">';
    $h .= '<dt>Window</dt><dd>' . $e('last ' . $num($head['window_days'] ?? 0) . ' days · mode '
        . strtoupper((string) ($head['mode'] ?? '')) . ' (plus rows captured without a mode)') . '</dd>';
    $h .= '<dt>Observations in the window</dt><dd>' . $e($num($head['total'] ?? 0) . ' ('
        . $num($head['entered'] ?? 0) . ' entered, ' . $num($head['skipped'] ?? 0) . ' skipped)') . '</dd>';
    $h .= '<dt>Captured all time (this mode)</dt><dd>' . $e($num($head['all_time'] ?? 0)) . '</dd>';
    $h .= '<dt>Resolved outcomes</dt><dd>' . $e($num($head['resolved'] ?? 0) . ' resolved · '
        . $num($head['wins'] ?? 0) . ' win, ' . $num($head['losses'] ?? 0) . ' loss, '
        . $num($head['flat'] ?? 0) . ' flat, ' . $num($head['not_taken'] ?? 0) . ' not taken (skipped) · '
        . $num($head['open'] ?? 0) . ' still open') . '</dd>';
    $h .= '<dt>Win/loss outcomes to reason from</dt><dd>' . $e($num($head['decided'] ?? 0)) . '</dd>';
    $h .= '<dt>Samples a claim needs</dt><dd>' . $e($num($head['min_samples'] ?? 0) . ' per bucket, in each of two buckets ('
        . 'learn_min_samples)') . '</dd>';
    $h .= '<dt>Still needed</dt><dd>' . $e(((int) ($head['needed'] ?? 0)) > 0
        ? 'roughly ' . $num($head['needed'] ?? 0) . ' more resolved trades'
        : 'nothing - the sample is large enough for a claim to be possible') . '</dd>';
    $h .= '<dt>Evidence by engine</dt><dd>' . $e((string) ($head['engines_text'] ?? 'none yet')) . '</dd>';
    $h .= '<dt>Learning</dt><dd>' . $e(!empty($head['learning_enabled']) ? 'capture on (learning_enabled)' : 'off (learning_enabled = false)')
        . ' · ' . $e(!empty($head['learning_apply']) ? 'adjustments APPLIED (learning_apply)' : 'dry run only (learning_apply = false)') . '</dd>';
    $h .= '</dl>';
    $h .= '<p class="learn-line">' . Panel::pill((string) ($line['text'] ?? 'not enough data yet'),
        (string) ($line['level'] ?? 'muted'), 'learn_line', 'pill-wide') . '</p>';
    $h .= '</section>';

    // ---- current weights, last recompute, what changed and on what evidence
    $h .= '<section class="card"><h2>Current weights ' . Panel::pill(!empty($head['learning_apply']) ? 'APPLY ON' : 'APPLY OFF',
        (string) ($w['apply_level'] ?? 'muted')) . '</h2>';
    $h .= '<p class="muted small">Learning may adjust <strong>only</strong> the score-component weights and the effective entry '
        . 'threshold, inside the caps below. It has no access to position size, take-profit, stop-loss, sleeve budgets or any kill '
        . 'switch: its whole write surface is two state keys, and the test suite asserts that the rest of the configuration comes '
        . 'back unchanged from a recompute.</p>';
    $h .= '<dl class="kv">';
    $h .= '<dt>learning_apply</dt><dd>' . $e((string) ($w['apply_text'] ?? '')) . '</dd>';
    $h .= '<dt>Last recompute</dt><dd>' . $e((string) ($w['last_text'] ?? 'never')) . '</dd>';
    $h .= '<dt>What it changed</dt><dd>' . $e((string) ($w['changed_text'] ?? '')) . '</dd>';
    $h .= '<dt>Next recompute</dt><dd>' . $e((string) ($w['next_text'] ?? '') . ' · at most one per '
        . $num($w['hours'] ?? 0) . ' h (learn_recompute_hours)') . '</dd>';
    $h .= '<dt>Effective threshold</dt><dd>' . $e((string) ($w['threshold_text'] ?? '')) . '</dd>';
    $h .= '</dl>';
    $h .= '<div class="table-wrap"><table class="tbl compact"><thead><tr><th>Score component</th><th>Base points</th>'
        . '<th>Learned delta</th><th>Effective</th><th>Cap</th></tr></thead><tbody data-table="learn_weights" data-cols="5">'
        . Panel::tableRows(isset($w['table']) && is_array($w['table']) ? $w['table'] : []) . '</tbody></table></div>';
    $h .= '<p class="muted small">A delta is clamped to ±10 points and to the component\'s own base value, so a component can be '
        . 'neutralised but never inverted into a bonus for the opposite condition. At most one component moves per recompute.</p>';

    $h .= '<h3>What a recompute would do next</h3>';
    $h .= '<div class="table-wrap"><table class="tbl compact"><thead><tr><th>Component</th><th>Fires when</th><th>Delta</th>'
        . '<th>Win rate when it fires</th><th>Win rate otherwise</th><th>Avg PnL difference</th></tr></thead>'
        . '<tbody data-table="learn_candidates" data-cols="6">'
        . Panel::tableRows(isset($w['candidates']) && is_array($w['candidates']) ? $w['candidates'] : []) . '</tbody></table></div>';
    $h .= '<p class="muted small">A candidate appears only when both sides hold at least the sample threshold, their win-rate '
        . 'confidence intervals do not overlap, and the average-PnL difference agrees in sign with the win-rate difference. '
        . 'The strongest one is the single change a recompute would apply.</p>';

    $h .= '<h3>Recompute history</h3>';
    $h .= '<div class="table-wrap"><table class="tbl compact"><thead><tr><th>When</th><th>Changed</th><th>From → to</th>'
        . '<th>Evidence (resolved trades)</th><th>Note</th></tr></thead><tbody data-table="learn_log" data-cols="5">'
        . Panel::tableRows(isset($w['log']) && is_array($w['log']) ? $w['log'] : []) . '</tbody></table></div>';

    $h .= '<div class="actions"><form method="post" action="index.php" class="inline" '
        . 'data-confirm="Run a recompute now? It reads only trades already closed, and with learning_apply off it writes nothing.">'
        . Panel::csrfField() . '<input type="hidden" name="action" value="recompute_learning">'
        . '<button type="submit" class="btn btn-primary">Recompute now</button></form> '
        . '<span class="muted small">Runs Learn::recompute() and reports its decision - including "it changed nothing, and here is '
        . 'why", which is the usual answer. A recompute uses only trades closed before it, and refuses to re-run inside '
        . $e($num($w['hours'] ?? 0)) . ' h.</span></div>';
    $h .= '</section>';

    // ---- skipped vs entered
    $h .= '<section class="card"><h2>Skipped vs entered</h2>';
    $h .= '<p>' . $e((string) ($sk['summary'] ?? '')) . '</p>';
    $h .= '<div class="table-wrap"><table class="tbl"><thead><tr><th>Refused because</th><th>n</th><th>Share of skips</th>'
        . '<th>Avg RSI</th><th>Avg ATR %</th><th>Avg spread %</th><th>Avg score</th></tr></thead>'
        . '<tbody data-table="learn_skipped" data-cols="7">'
        . Panel::tableRows(isset($sk['table']) && is_array($sk['table']) ? $sk['table'] : []) . '</tbody></table></div>';
    $h .= '<p class="muted small">These are the conditions the bot most often refused, so you can see whether the gates are too '
        . 'tight. The averages describe what was refused; they are not evidence that entering those setups would have paid - '
        . 'a skipped evaluation has no outcome at all.</p>';
    $h .= '</section>';

    // ---- one card per feature
    $features = isset($ins['features']) && is_array($ins['features']) ? $ins['features'] : [];
    if ($features === []) {
        $h .= '<section class="card"><h2>Conditions</h2><p class="muted">No feature statistics are available yet.</p></section>';
        return $h;
    }
    $h .= '<section class="card"><h2>Conditions</h2><p class="muted small">One card per captured condition. Buckets are fixed in '
        . 'advance (DESIGN-LEARNING.md §3) so they cannot be drawn around whatever happens to look good, and an empty bucket is '
        . 'shown rather than dropped - "no evidence here" is information too. A win rate is never printed without the sample it '
        . 'rests on. Confident cards are open; the rest start collapsed.</p></section>';
    foreach ($features as $f) {
        $confident = !empty($f['confident']);
        $h .= '<details class="card insight-card"' . ($confident ? ' open' : '') . '>';
        $h .= '<summary><span class="insight-title">' . $e((string) ($f['label'] ?? '')) . '</span> '
            . Panel::pill((string) ($f['state'] ?? ''), (string) ($f['level'] ?? 'muted')) . ' '
            . '<span class="muted small">' . $e('n=' . (int) ($f['samples'] ?? 0) . ' resolved trades · '
            . (string) ($f['feature'] ?? '')) . '</span></summary>';
        $h .= '<p class="muted small">' . $e((string) ($f['separation_text'] ?? '')) . '</p>';
        $h .= '<div class="table-wrap"><table class="tbl"><thead><tr><th>Bucket</th><th>n</th><th>Still open</th><th>Win rate</th>'
            . '<th>95 % confidence interval</th><th>Avg PnL</th><th>Total PnL</th><th>Enough samples?</th></tr></thead>'
            . '<tbody data-table="learn_' . $e((string) ($f['slug'] ?? 'x')) . '" data-cols="8">'
            . Panel::tableRows(isset($f['table']) && is_array($f['table']) ? $f['table'] : []) . '</tbody></table></div>';
        $h .= '<p class="insight-note">' . $e((string) ($f['note'] ?? '')) . '</p>';
        $h .= '</details>';
    }
    return $h;
}

function page_settings(array $cfg, Db $db, array $v, array $errors, string $testResult, array $warnings = []): string
{
    $e = 'Panel::e';
    foreach (panel_engine_defaults() as $k => $d) {
        if (!isset($v[$k])) {
            $v[$k] = $d;   // the form still renders on an install whose config predates the engines
        }
    }
    foreach (panel_portfolio_defaults() as $k => $d) {
        if (!isset($v[$k])) {
            $v[$k] = $d;   // ditto for the portfolio and scanner keys (DESIGN-PORTFOLIO.md §2)
        }
    }
    foreach (panel_learning_defaults() as $k => $d) {
        if (!array_key_exists($k, $v)) {
            $v[$k] = $d;   // ditto for the learning keys (DESIGN-LEARNING.md §5, §7)
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

    // -- portfolio & scanner (DESIGN-PORTFOLIO.md §2, §7)
    $h .= page_settings_portfolio($v, $errors);

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

    // -- learning (DESIGN-LEARNING.md §5)
    $h .= '<fieldset><legend>Learning</legend>';
    $h .= '<p class="muted small">The bot records the <strong>conditions</strong> present at every entry evaluation - the ones it took '
        . 'and the ones it refused - and the outcome that followed, then reports which conditions actually preceded profit. That is '
        . 'statistics over its own history, not a model that discovers alpha. What it may change is deliberately tiny: the '
        . '<strong>score-component weights and the effective entry threshold, nothing else</strong>. It cannot reach position size, '
        . 'take-profit, stop-loss, a sleeve budget or any kill switch - those are hard invariants asserted by the test suite. '
        . 'See <a href="?page=insights">Insights</a> for what it currently believes and on how many trades.</p>';
    $h .= panel_check('learning_enabled', $v, 'Capture observations and compute insights.',
        '<span class="muted">Off writes no observation row and makes no claim; the Insights page then only shows what is already stored.</span>');
    $h .= panel_check('learning_apply', $v, 'Feed the adjustments back into scoring (learning_apply).',
        '<span class="muted">Off (the default) is a dry run: the panel shows exactly what a recompute <em>would</em> do and nothing is '
        . 'written or scored, which is how the operator builds trust before enabling it. On, a recompute may move <strong>one</strong> '
        . 'score component by at most ±10 points, never below zero for that component, and the effective threshold stays inside '
        . '[entry_threshold − 10, 100].</span>');
    $h .= '<div class="grid3">';
    $h .= panel_input('learn_min_samples', $v, 'Min samples per bucket', 'number',
        'outcomes a bucket needs before any claim is called confident (default 60); a lucky streak can never move anything', '1');
    $h .= panel_input('learn_recompute_hours', $v, 'Min hours between recomputes', 'number',
        'default 168 (one week); a recompute only uses trades closed before it, so the bot never trains on what it then trades', '1');
    $h .= panel_input('learn_window_days', $v, 'Evidence window (days)', 'number',
        'how far back the evidence is drawn (default 90)', '1');
    $h .= '</div></fieldset>';

    // -- misc
    $h .= '<fieldset><legend>Fees, paper &amp; misc</legend><div class="grid3">';
    $h .= panel_input('fee_pct', $v, 'Taker fee % per side', 'number', 'paper mode uses it; live reads the real rate');
    $h .= panel_input('paper_start_usdt', $v, 'Paper start balance', 'number');
    $h .= panel_input('recv_window', $v, 'recvWindow (ms)', 'number', '', '1');
    $h .= panel_input('timezone', $v, 'Display timezone', 'text', 'e.g. UTC, Europe/Berlin, America/New_York');
    $h .= panel_input('bnb_min_balance', $v, 'BNB warning threshold (USDT)', 'number',
        'warn when the BNB fee discount is on but the free BNB is worth less than this (default 1.0): Binance then silently '
        . 'reverts to charging the received asset, which changes the fee and the dust behaviour mid-run');
    $h .= '</div>';
    $h .= '<p class="muted small">The taker fee above is informational only - the live rate comes from the account. With the BNB '
        . 'discount on a round trip costs 0.15 %, without it 0.2 %; the discount itself is read and toggled from the API health '
        . 'card on the dashboard.</p>';
    $h .= '</fieldset>';

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
