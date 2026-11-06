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
} elseif ($page === 'diagnostics') {
    // Which files and features are actually on this server. Read-only, and it
    // reports presence only - never a key, a password, a balance or data/ contents.
    echo panel_layout('Diagnostics', page_diagnostics($cfg), ['page' => 'diagnostics']);
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
                // PaperExchange::reset() puts the simulated wallet back to `paper_start_usdt`,
                // so any scalp row still holding base inventory would claim coins the wallet no
                // longer has: the next poll would markStuck() it and its `entry_quote` would keep
                // counting against that symbol's budget forever (DESIGN-SCALP.md §7). Close them
                // at cost first. STUCK rows count too - they are in Scalper::OPEN_STATUSES and so
                // in `deployed`, which is why this filters Scalper::openPositions() rather than
                // asking for OPEN alone.
                $scalpOpen = [];
                if (class_exists('Scalper')) {
                    $scalpOpen = Scalper::openPositions($db, $cfg, 'paper');
                } elseif (method_exists($db, 'scalpPositions')) {
                    foreach ($db->scalpPositions(null, 'paper') as $r) {
                        if (in_array(strtoupper(trim((string) ($r['status'] ?? ''))), ['OPEN', 'STUCK'], true)) {
                            $scalpOpen[] = $r;
                        }
                    }
                }
                foreach ($scalpOpen as $p) {
                    // gross and fees zeroed alongside pnl so the DESIGN-SCALP.md §2
                    // net = gross - fees invariant still holds for these synthetic closes.
                    $db->updatePosition((int) $p['id'], [
                        'status' => 'CLOSED', 'exit_reason' => 'paper_reset',
                        'pnl_usdt' => 0.0, 'pnl_pct' => 0.0, 'gross_usdt' => 0.0, 'fees_usdt' => 0.0,
                        'exit_price' => (float) $p['entry_eff'], 'exit_quote' => (float) $p['entry_quote'],
                        'closed_at' => Util::nowIso(),
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
                    . '. Closed trades stay in the history; the kill-switch state was cleared.'
                    . (count($scalpOpen) > 0
                        ? ' ' . count($scalpOpen) . ' open scalp position(s) were closed at cost as paper_reset, '
                            . 'so their capital is back in each symbol\'s budget.'
                        : ''));
            } catch (Throwable $e) {
                Panel::flash('danger', 'Reset failed: ' . $e->getMessage());
            }
            Panel::redirect('?page=dashboard');
            return;

        case 'cancel_engine_orders':
            handle_cleanup_action($cfg, $db);
            return;

        case 'resume_scalp':
            handle_scalp_action($cfg, $db);
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

/* ---------------------------------------------------------- learning actions */

/**
 * "Recompute now" (DESIGN-LEARNING.md §6): runs Learn::recompute() and reports its
 * decision verbatim - including "it did nothing, and here is why", which is the
 * usual and honest answer.
 *
 * Deliberately NOT wrapped in Bot::runLocked(): Learn's entire write surface is the
 * two state keys learn_weights / learn_at (plus the learn_log it prints here). It
 * places no order, touches no position and cannot reach size, take-profit,
 * stop-loss or any kill switch, so it can never race a tick into
 * anything dangerous - and holding the tick lock for a statistics pass would only
 * cost the operator a minute of trading.
 */
/**
 * "Cancel leftover engine orders" - the one piece of the removed grid and pmm engines that had
 * to stay reachable.
 *
 * Those engines rested LIMIT orders on Binance. An order resting on the exchange does not care
 * that the code which placed it is gone, so removing the engines without this would have left
 * any last ladder sitting on the book indefinitely, holding real balance, with nothing in the
 * panel able to take it off. POST + CSRF (checked globally before routing) and inside
 * Bot::runLocked so it cannot race a tick.
 */
function handle_cleanup_action(array $cfg, Db $db): void
{
    if (!class_exists('Bot')) {
        Panel::flash('danger', 'lib/Bot.php is missing - the cleanup action is unavailable.');
        Panel::redirect('?page=dashboard');
    }
    try {
        $res = Bot::runLocked(static function () use ($cfg, $db): array {
            $ex  = Exchange::factory($cfg, $db);
            $bot = new Bot($cfg, $db, $ex);
            return ['status' => 'ok', 'summary' => (string) $bot->cancelAllEngineOrders('manual_cleanup'), 'ms' => 0];
        });
        if ((string) ($res['status'] ?? '') === 'skipped') {
            Panel::flash('warn', 'A tick is running right now - try again in a few seconds.');
        } else {
            $n = (int) ($res['summary'] ?? 0);
            Panel::flash($n > 0 ? 'ok' : 'info', $n > 0
                ? $n . ' leftover order(s) were cancelled. Any coins they already bought stay in the '
                    . 'wallet - sell those by hand, or the kill switch will halt on untracked_position.'
                : 'No leftover engine orders were found; there is nothing resting on the book.');
        }
    } catch (Throwable $e) {
        Panel::flash('danger', 'Cleanup failed: ' . $e->getMessage());
    }
    Panel::redirect('?page=dashboard');
}

/**
 * "Resume scalping" (DESIGN-SCALP.md §5) - POST + CSRF (checked globally before routing) and
 * inside Bot::runLocked so it can never race a cron tick that is mid-loop.
 *
 * This is the whole "pause and ask me to resume" contract: the engine stops opening positions
 * after `scalp_max_consecutive_losses` losses in a row and NEVER lifts that itself. Only this
 * button does. Nothing else in the codebase clears `scalp_paused`.
 */
function handle_scalp_action(array $cfg, Db $db): void
{
    if (!class_exists('Scalper') || !class_exists('Bot')) {
        Panel::flash('danger', 'lib/Scalper.php is missing - the scalp actions are unavailable.');
        Panel::redirect('?page=dashboard');
    }
    if (trim((string) $db->getState('scalp_paused', '')) !== '1') {
        Panel::flash('warn', 'The scalp engine is not paused, so there is nothing to resume.');
        Panel::redirect('?page=dashboard');
    }
    try {
        $res = Bot::runLocked(static function () use ($cfg, $db): array {
            // The exchange is only needed for the constructor; resume() touches state alone and
            // places no order, so a paper exchange is enough even in demo or live mode.
            $ex = Exchange::factory($cfg, $db);
            $sc = new Scalper($cfg, $db, $ex);
            $streak = $sc->lossStreak();
            $sc->resume();
            return ['status' => 'ok', 'summary' => (string) $streak, 'ms' => 0];
        });
        $st = (string) ($res['status'] ?? '');
        if ($st === 'skipped') {
            Panel::flash('warn', 'A tick is running right now - try Resume again in a few seconds. '
                . 'The engine is still paused.');
        } elseif ($st !== 'ok') {
            Panel::flash('danger', 'Resume did not run (' . ($st === '' ? 'unknown status' : $st)
                . '): ' . trim((string) ($res['summary'] ?? '')) . ' The engine is still paused.');
        } else {
            Panel::flash('ok', 'Scalping resumed. The losing streak was reset to 0 and entries are '
                . 'allowed again from the next tick. The global kill switches (equity floor, '
                . 'drawdown, daily and weekly caps) are unchanged and still apply.');
        }
    } catch (Throwable $e) {
        Panel::flash('danger', 'Resume failed: ' . $e->getMessage());
    }
    Panel::redirect('?page=dashboard');
}

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
        // engine selection
        'engine', 'allow_live_engines',
        // scalp engine (DESIGN-SCALP.md §6)
        'scalp_universe_size', 'scalp_order_usdt', 'scalp_budget_per_symbol', 'scalp_budgets',
        'scalp_max_open', 'scalp_tp_pct', 'scalp_sl_pct', 'scalp_max_hold_sec', 'scalp_dip_pct',
        'scalp_window', 'scalp_poll_sec', 'scalp_run_seconds', 'scalp_max_consecutive_losses',
        'scalp_max_trades_per_hour', 'scalp_min_quote_vol', 'scalp_min_trade_count',
        'scalp_max_spread_pct', 'scalp_universe_refresh_min', 'scalp_exclude',
        // learning + the BNB fee discount (DESIGN-LEARNING.md §5, §7)
        'learning_enabled', 'learning_apply', 'learn_min_samples', 'learn_recompute_hours', 'learn_window_days',
        'bnb_min_balance'];
}

/** Settings keys rendered as checkboxes: absent from the POST means "off", never "unchanged". */
function settings_checkbox_keys(): array
{
    return ['enabled', 'adaptive', 'allow_live_engines', 'learning_enabled', 'learning_apply'];
}

/**
 * DESIGN-LEARNING.md §5 defaults plus the §7 `bnb_min_balance`. Used only to render the form
 * (and to sanitise a value the config validator does not know yet) on an install whose
 * config.php predates learning - exactly like panel_engine_defaults() and
 * does for its section. Panel::learnDefaults() is the single
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
 * stop-loss or a kill switch. The learning settings are five numbers and
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

/** A finite float from a posted string, or $d. */
function panel_num($v, float $d): float
{
    return (is_numeric($v) && is_finite((float) $v)) ? (float) $v : $d;
}

/** Inline error list for one settings section; '' when there is nothing to say. */
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
    // only for keys the validator does not know at all (older lib/Risk.php)
    $newCfg    = settings_learning_fallback($in, $cfg, $newCfg);
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
        $h .= '<a href="?page=diagnostics"' . ($current === 'diagnostics' ? ' class="active"' : '') . '>Diagnostics</a>';
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

/**
 * Deploy diagnostics (DESIGN.md §12). Answers "is this feature actually on the
 * server?", which is the question a half-finished upload creates: the panel may
 * show a control whose library file never arrived, and the control then does
 * nothing. A feature counts as present only when every file it needs exists AND
 * the marker is in the file, so a stale upload cannot report a working feature.
 *
 * Deliberately reports presence and absence only. No keys, no password, no
 * balances, nothing out of data/.
 */
function page_diagnostics(array $cfg): string
{
    $e = 'Panel::e';
    $root = TRADER_ROOT;
    $marker = function (string $file, string $needle) use ($root): bool {
        $p = $root . '/' . $file;
        return is_file($p) && strpos((string) @file_get_contents($p), $needle) !== false;
    };
    $yn = function (bool $ok): string {
        return $ok ? '<span class="ok-tag">yes</span>' : '<span class="bad-tag">no</span>';
    };

    $core = ['index.php','cron.php','config.php','bootstrap.php','lib/Util.php','lib/Db.php','lib/Log.php',
             'lib/Binance.php','lib/Indicators.php','lib/Strategy.php','lib/Risk.php','lib/Exchange.php',
             'lib/Bot.php','lib/Panel.php','assets/panel.css','assets/panel.js'];
    $extra = ['lib/Scalper.php'       => 'the scalp engine',
              'lib/Movers.php'       => 'the movers board',
              'lib/Learn.php'        => 'learning / insights'];

    // Each row is [files that must exist, file to grep, marker string]. The MARKER has to be
    // something that file genuinely contains, or the row reports "present but out of date" on a
    // perfectly good deploy and sends the user round the upload loop for nothing. The scalp row
    // used to grep cron.php for `Scalper`, which was true of an earlier build and stopped being
    // true when the loop moved inside Bot::tick() - so it cried wolf on every correct install.
    // Grep the file that actually owns the feature.
    $feats = [
        'Demo mode'            => [['lib/Binance.php'], 'lib/Binance.php', 'demo-api.binance.com'],
        'Scalp engine'         => [['lib/Scalper.php','lib/Movers.php','lib/Bot.php'], 'lib/Bot.php', 'runScalpTick'],
        'Movers board'         => [['lib/Movers.php'], 'lib/Movers.php', 'class Movers'],
        'Learning / Insights'  => [['lib/Learn.php','index.php'], 'index.php', 'insights'],
        'BNB fee discount'     => [['lib/Binance.php'], 'lib/Binance.php', 'bnbBurn'],
    ];

    $missing = [];
    foreach ($core as $f) { if (!is_file($root . '/' . $f)) { $missing[] = $f; } }
    foreach (array_keys($extra) as $f) { if (!is_file($root . '/' . $f)) { $missing[] = $f; } }

    $h = '<section class="card"><h2>Deploy check</h2>';
    $h .= $missing
        ? '<p class="flash flash-danger">' . count($missing) . ' file(s) are not on this server. Upload the full folder again, overwriting, and the features below will appear. Missing: <code>' . $e(implode(', ', $missing)) . '</code></p>'
        : '<p class="flash flash-ok">Every file is present. If a control still does not appear, hard refresh the page (Ctrl+Shift+R, or Cmd+Shift+R on a Mac) so the browser reloads the stylesheet and script.</p>';

    $h .= '<h3>Features</h3><div class="table-wrap"><table><thead><tr><th>Feature</th><th>Available</th><th>Why not</th></tr></thead><tbody>';
    foreach ($feats as $label => $spec) {
        $lack = [];
        foreach ($spec[0] as $nf) { if (!is_file($root . '/' . $nf)) { $lack[] = $nf; } }
        $ok  = !$lack && $marker($spec[1], $spec[2]);
        $why = $lack ? 'missing ' . implode(', ', $lack) : ($ok ? '' : 'file is present but out of date - upload it again');
        $h .= '<tr><td>' . $e($label) . '</td><td>' . $yn($ok) . '</td><td class="muted">' . $e($why) . '</td></tr>';
    }
    $h .= '</tbody></table></div>';

    $h .= '<h3>Optional files</h3><div class="table-wrap"><table><thead><tr><th>File</th><th>Present</th><th>Needed for</th></tr></thead><tbody>';
    foreach ($extra as $f => $what) {
        $h .= '<tr><td><code>' . $e($f) . '</code></td><td>' . $yn(is_file($root . '/' . $f)) . '</td><td class="muted">' . $e($what) . '</td></tr>';
    }
    $h .= '</tbody></table></div>';

    $h .= '<h3>Runtime</h3><div class="table-wrap"><table><tbody>';
    foreach (['curl','pdo_sqlite','json','openssl'] as $x) {
        $h .= '<tr><td>PHP extension <code>' . $e($x) . '</code></td><td>' . $yn(extension_loaded($x)) . '</td></tr>';
    }
    $h .= '<tr><td>PHP version</td><td><code>' . $e(PHP_VERSION) . '</code></td></tr>';
    $h .= '<tr><td><code>data/</code> writable</td><td>' . $yn(is_writable($root . '/data')) . '</td></tr>';
    $h .= '<tr><td>Folder</td><td><code>' . $e($root) . '</code></td></tr>';
    $h .= '</tbody></table></div>';
    $h .= '<p class="muted">This page reads file names only. It never shows your API key, panel password, balances or anything stored in <code>data/</code>.</p>';
    return $h . '</section>';
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

    // ---- the grid/pmm removal notice, and the cleanup button that goes with it
    $migrated = !empty($s['show']['engine_migrated']);
    $h .= '<section class="card danger-card" data-show="engine_migrated"' . ($migrated ? '' : ' hidden') . '>';
    $h .= '<h2>Engine changed</h2><p><span data-field="engine_migrated">'
        . $e((string) ($s['text']['engine_migrated'] ?? '')) . '</span></p>';
    $h .= '<form method="post" action="index.php" class="inline">' . Panel::csrfField()
        . '<input type="hidden" name="action" value="cancel_engine_orders">'
        . '<button type="submit" class="btn btn-warn">Cancel leftover orders</button></form>';
    $h .= '</section>';

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

    // ---- scalp engine (DESIGN-SCALP.md §9): the pause banner first, then the verdict card,
    //      the per-symbol scoreboard, the open positions and the movers board.
    $h .= panel_scalp_dashboard($s, $quote);

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
 * Scalp engine blocks (DESIGN-SCALP.md §9): the pause banner, the Gross / Fees / Net verdict,
 * the per-symbol scoreboard, the open positions and the movers board.
 *
 * No inline script, no inline handler and no style attribute anywhere in here - the CSP forbids
 * all three and tests/run.php asserts it.
 *
 * The verdict block is the point of the whole engine, so it is deliberately the loudest thing
 * on the card and it always carries the trade count. §2: never show net alone.
 */
function panel_scalp_dashboard(array $s, string $quote): string
{
    $e    = 'Panel::e';
    $show = isset($s['show']) && is_array($s['show']) ? $s['show'] : [];
    $txt  = isset($s['text']) && is_array($s['text']) ? $s['text'] : [];
    $lvl  = isset($s['levels']) && is_array($s['levels']) ? $s['levels'] : [];
    $on   = !empty($show['scalp']);
    $h    = '';

    // ---- the pause banner. The user asked to be ASKED, so this is a banner with a button, not
    //      a line in a log they would have to go looking for.
    $hidB = !empty($show['scalp_paused']) ? '' : ' hidden';
    $h .= '<section class="card danger-card" data-show="scalp_paused"' . $hidB . '>';
    $h .= '<h2>Scalping is paused</h2>';
    $h .= '<p><span data-field="scalp_pause_reason">' . $e((string) ($txt['scalp_pause_reason'] ?? '')) . '</span></p>';
    $h .= '<p class="muted small">Open positions are still being managed to their exit - only new '
        . 'entries are held. Resuming resets the losing streak to 0. The global kill switches '
        . '(equity floor, drawdown, daily and weekly caps) are separate and still apply.</p>';
    $h .= '<form method="post" action="index.php" class="inline">' . Panel::csrfField()
        . '<input type="hidden" name="action" value="resume_scalp">'
        . '<button type="submit" class="btn btn-warn">Resume scalping</button></form>';
    $h .= '</section>';

    // ---- the verdict card
    $hid = $on ? '' : ' hidden';
    $h .= '<section class="card scalp-card" data-show="scalp"' . $hid . '>';
    $h .= '<h2>Scalp engine '
        . Panel::pill((string) ($txt['scalp_state'] ?? '-'), (string) ($lvl['scalp_state'] ?? 'muted'), 'scalp_state')
        . ' ' . Panel::pill((string) ($txt['scalp_streak'] ?? '-'), (string) ($lvl['scalp_streak'] ?? 'muted'), 'scalp_streak')
        . '</h2>';

    $h .= '<div class="table-wrap"><table class="tbl compact verdict"><tbody>';
    $h .= '<tr><th>Gross P&amp;L (before fees)</th><td class="num ' . $e('lvl-' . (string) ($lvl['scalp_gross'] ?? 'muted'))
        . '" data-field="scalp_gross">' . $e((string) ($txt['scalp_gross'] ?? '-')) . '</td>'
        . '<td class="muted small">is there ANY directional edge?</td></tr>';
    $h .= '<tr><th>Fees paid</th><td class="num ' . $e('lvl-' . (string) ($lvl['scalp_fees'] ?? 'muted'))
        . '" data-field="scalp_fees">' . $e((string) ($txt['scalp_fees'] ?? '-')) . '</td>'
        . '<td class="muted small">what it cost to find out</td></tr>';
    $h .= '<tr><th>Net P&amp;L</th><td class="num ' . $e('lvl-' . (string) ($lvl['scalp_net'] ?? 'muted'))
        . '" data-field="scalp_net">' . $e((string) ($txt['scalp_net'] ?? '-')) . '</td>'
        . '<td class="muted small">the answer</td></tr>';
    $h .= '<tr><th>Sample</th><td class="num" data-field="scalp_trades">' . $e((string) ($txt['scalp_trades'] ?? '-')) . '</td>'
        . '<td class="muted small">a few green trades prove nothing</td></tr>';
    $h .= '<tr><th>Win rate</th><td class="num ' . $e('lvl-' . (string) ($lvl['scalp_win_rate'] ?? 'muted'))
        . '" data-field="scalp_win_rate">' . $e((string) ($txt['scalp_win_rate'] ?? '-')) . '</td>'
        . '<td class="muted small">a target measured against entry_eff still owes the sell fee</td></tr>';
    $h .= '</tbody></table></div>';

    $h .= '<p class="' . $e('lvl-' . (string) ($lvl['scalp_verdict'] ?? 'muted')) . '" data-field="scalp_verdict">'
        . $e((string) ($txt['scalp_verdict'] ?? '')) . '</p>';
    // Throughput: the entries this hour against the runaway cap, and the polls the last loop
    // actually managed. The user asked for "multiple trades every minute or second", so both
    // numbers belong on the card: the cap is what stops it, the poll count is what it achieved.
    $h .= '<p class="muted small">Entries: <span class="' . $e('lvl-' . (string) ($lvl['scalp_entries_hour'] ?? 'muted'))
        . '" data-field="scalp_entries_hour" data-level="scalp_entries_hour" data-level-prefix="lvl-">'
        . $e((string) ($txt['scalp_entries_hour'] ?? '-')) . '</span>. '
        . 'Polls in the last run: <span data-field="scalp_polls">' . $e((string) ($txt['scalp_polls'] ?? '0')) . '</span>. '
        . 'Last run: <span data-field="scalp_last_run">' . $e((string) ($txt['scalp_last_run'] ?? '')) . '</span>. '
        . 'Cadence: <span data-field="scalp_cadence">' . $e((string) ($txt['scalp_cadence'] ?? '')) . '</span>. '
        . 'Gross minus fees equals net exactly, per closed trade and in total - if they ever disagree, '
        . 'the accounting is wrong and the verdict should not be trusted.</p>';

    // ---- per-symbol scoreboard
    $h .= '<h3>Per-symbol scoreboard</h3>';
    $h .= '<div class="table-wrap"><table class="tbl"><thead><tr>'
        . '<th>Symbol</th><th>Budget</th><th>Deployed</th><th>Available</th><th>Trades</th><th>Win rate</th>'
        . '<th>Gross ' . $e($quote) . '</th><th>Fees</th><th>Net ' . $e($quote) . '</th><th>Net % of budget</th>'
        . '</tr></thead><tbody data-table="scalp_board" data-cols="10">'
        . Panel::tableRows(isset($s['tables']['scalp_board']) && is_array($s['tables']['scalp_board']) ? $s['tables']['scalp_board'] : [])
        . '</tbody></table></div>';
    $h .= '<p class="muted small">Every symbol spends only its own pot: available = budget + realised net − deployed, '
        . 'clamped at zero. A symbol that loses its budget stops trading on its own while the others carry on. '
        . 'Set the default and the per-symbol overrides in Settings → Scalp engine. '
        . 'Sorting by net is NOT a recommendation - with a handful of trades per symbol the order is mostly luck.</p>';

    // ---- open positions
    $h .= '<h3>Open scalp positions</h3>';
    $h .= '<div class="table-wrap"><table class="tbl compact"><thead><tr>'
        . '<th>Symbol</th><th>Qty</th><th>Entry eff.</th><th>Cost ' . $e($quote) . '</th>'
        . '<th>Age (s)</th><th>Unrealised</th><th>Reason</th>'
        . '</tr></thead><tbody data-table="scalp_open" data-cols="7">'
        . Panel::tableRows(isset($s['tables']['scalp_open']) && is_array($s['tables']['scalp_open']) ? $s['tables']['scalp_open'] : [])
        . '</tbody></table></div>';
    // The engine's last order error. A rejected SELL is the one failure that really matters here:
    // the position stays OPEN and is retried every poll, so it is not abandoned, but the user has
    // to be able to see that their stop is not getting out. It was previously only in bot.log.
    $h .= '<p class="muted small">Last order error: <span class="'
        . $e('lvl-' . (string) ($lvl['scalp_last_error'] ?? 'muted'))
        . '" data-field="scalp_last_error" data-level="scalp_last_error" data-level-prefix="lvl-">'
        . $e((string) ($txt['scalp_last_error'] ?? 'none')) . '</span>. '
        . 'A rejected sell leaves the position OPEN and is retried on the next poll rather than '
        . 'abandoned; a sell that is below the exchange minimum is marked STUCK and sold the moment '
        . 'the notional recovers. Either way the capital still counts as deployed against that '
        . 'symbol\'s budget, so it cannot be spent twice.</p>';

    $h .= '<p class="muted small">Age is in SECONDS because that is the scale this engine works on - '
        . 'a position is timed out at scalp_max_hold_sec whether it is up or down. Unrealised is measured '
        . 'against entry_eff (the dust-excluded basis that already carries the buy fee) from the last price '
        . 'the tick or the movers board recorded, so it is a few seconds stale and still owes the sell fee; '
        . 'it reads &quot;-&quot; when no price is known rather than pretending the position is flat.</p>';
    $h .= '</section>';

    // ---- movers board: the "top coins with fluctuations and many traders" table
    $hidM = !empty($show['movers']) ? '' : ' hidden';
    $h .= '<section class="card movers-card" data-show="movers"' . $hidM . '>';
    $h .= '<h2>Movers - top 10 by fluctuation and traders '
        . Panel::pill((string) ($txt['movers_age'] ?? '-'), (string) ($lvl['movers_age'] ?? 'muted'), 'movers_age') . '</h2>';
    $h .= '<div class="table-wrap"><table class="tbl"><thead><tr>'
        . '<th>#</th><th>Symbol</th><th>Last</th><th>24 h change</th><th>24 h range</th><th>24 h trades</th>'
        . '<th>24 h quote vol</th><th>Spread</th><th>Required size</th><th>Score</th><th>State</th><th>Gates</th>'
        . '</tr></thead><tbody data-table="movers" data-cols="12">'
        . Panel::tableRows(isset($s['tables']['movers']) && is_array($s['tables']['movers']) ? $s['tables']['movers'] : [])
        . '</tbody></table></div>';
    $h .= '<p class="muted small">score = 0.45 × range rank + 0.35 × trade-count rank + 0.20 × liquidity rank − spread penalty, '
        . 'each rank taken across the eligible set so no one term wins on units alone. '
        . '<strong>Range, not 24 h change</strong>: a coin that rose 3 % and stayed there has nothing left to scalp, '
        . 'while one that swung 3 % up and back has had dozens of round trips. '
        . '<strong>Trade count, not volume</strong>: one whale moving 50 M is not "many traders". '
        . 'A gated row is kept, not dropped, so the gate can explain the rejection. '
        . '<span data-field="movers_meta">' . $e((string) ($txt['movers_meta'] ?? '')) . '</span>.</p>';
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
        . 'threshold, inside the caps below. It has no access to position size, take-profit, stop-loss, the scalp budgets or any kill '
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

    // -- engine (the removed grid/pmm contract)
    $engine = strtolower(trim((string) ($v['engine'] ?? 'signal')));
    if (!in_array($engine, ['signal', 'scalp'], true)) {
        $engine = 'signal';
    }
    $h .= '<fieldset><legend>Engine</legend>';
    $h .= '<div class="grid3">';
    $h .= '<label>Engine<span class="muted help">signal trades one position at a time on the watchlist; '
        . 'scalp trades many symbols at once, many times a minute</span>'
        . '<select name="engine" data-engine-select>';
    foreach ([
        'signal' => 'signal - mean-reversion, market orders, one position',
        'scalp'  => 'scalp - fast micro-trades across 20-30 symbols at once (demo, to measure it)',
    ] as $k => $label) {
        $h .= '<option value="' . $e($k) . '"' . ($engine === $k ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    $h .= '</select></label>';
    $h .= '</div>';
    $h .= panel_check('allow_live_engines', $v, 'Allow the scalp engine to run in LIVE mode.',
        '<span class="danger">Warning: the scalp engine refuses to place a single order in live mode while this is off '
        . '(the tick reports <code>engine_live_blocked</code>). Only turn it on once you have run it on demo and read the '
        . 'Gross / Fees / Net card - the fee arithmetic says it needs roughly a 76 % win rate just to break even.</span>');

    // signal
    $h .= panel_engine_group('signal', $engine,
        '<p class="muted">The signal engine is configured by the sections below (watchlist, sizing, exits, strategy). '
        . 'The scalp fields do not apply to it.</p>');

    // scalp (DESIGN-SCALP.md §6)
    $scalpTp  = (float) ($v['scalp_tp_pct'] ?? 0.25);
    $scalpSl  = (float) ($v['scalp_sl_pct'] ?? 0.20);
    $scalpFee = (float) ($v['fee_pct'] ?? 0.1);
    $tpNet    = $scalpTp - $scalpFee;
    $slNet    = $scalpSl + $scalpFee;
    $be       = ($tpNet > 0.0 && ($tpNet + $slNet) > 0.0) ? $slNet / ($tpNet + $slNet) * 100.0 : null;

    // The arithmetic, rendered inline. The user's question is "does this make money", and the
    // honest answer starts with the win rate it would take - so it is stated here, next to the
    // fields that set it, not buried in a doc.
    $scalp  = '<div class="flash flash-warn"><strong>Read this before you turn it on.</strong> The target is measured on the '
            . '<em>bid</em> against <code>entry_eff</code>, which already carries the buy fee, so at tp '
            . $e(Util::money($scalpTp, 3)) . ' % / sl ' . $e(Util::money($scalpSl, 3)) . ' % / fee '
            . $e(Util::money($scalpFee, 3)) . ' % per side a win nets about <strong>+' . $e(Util::money(max(0.0, $tpNet), 3))
            . ' %</strong> and a loss about <strong>-' . $e(Util::money($slNet, 3)) . ' %</strong>'
            . ($be === null
                ? ', and the target cannot clear the sell fee at all - every "winning" trade still closes at a loss.'
                : ', so you need roughly a <strong>' . $e(number_format($be, 1)) . ' % win rate just to break even</strong>, before slippage.')
            . ' That is a high bar. The engine does not assume it is cleared: it records gross and fees separately on every '
            . 'closed trade so the dashboard can tell you whether there is any directional edge at all, separately from what '
            . 'the fees took. Run it on <strong>demo</strong> and read the Gross / Fees / Net block before you consider real money.</div>';
    $scalp .= '<div class="grid3">';
    $scalp .= panel_input('scalp_universe_size', $v, 'Symbols traded at once', 'number', '1-30; the top N eligible rows of the movers board', '1');
    $scalp .= panel_input('scalp_order_usdt', $v, 'Quote per position', 'number', 'must clear minNotional + one step; too small and the dust rule gates most of the market');
    $scalp .= panel_input('scalp_max_open', $v, 'Max open positions', 'number', '1-30, one per symbol', '1');
    $scalp .= panel_input('scalp_budget_per_symbol', $v, 'Default budget per symbol', 'number', 'each symbol spends only its own pot');
    $scalp .= panel_input('scalp_tp_pct', $v, 'Take profit %', 'number', 'on the bid, against entry_eff');
    $scalp .= panel_input('scalp_sl_pct', $v, 'Stop %', 'number', 'on the bid, against entry_eff');
    $scalp .= panel_input('scalp_max_hold_sec', $v, 'Max hold (s)', 'number', 'a scalp that has not resolved is capital tied up in a failed thesis', '1');
    $scalp .= panel_input('scalp_dip_pct', $v, 'Pullback needed %', 'number', 'how far below the window high before a bounce entry');
    $scalp .= panel_input('scalp_max_consecutive_losses', $v, 'Pause after N losses in a row', 'number', '2-20; then it waits for you to press Resume', '1');
    $scalp .= '</div>';
    $scalp .= '<label>Per-symbol budget overrides (JSON)'
            . '<span class="muted help">e.g. {"BTCUSDT":200,"DOGEUSDT":50}. Anything not listed uses the default above. '
            . 'Leave empty for no overrides.</span>'
            . '<textarea name="scalp_budgets" rows="2">' . $e(panel_budgets_text($v)) . '</textarea></label>';
    $scalp .= '<div class="grid3">';
    $scalp .= panel_input('scalp_window', $v, 'Ticks held per symbol', 'number', '3-200; MUST fit in one loop (run_seconds / poll_sec) or the window never fills and nothing trades', '1');
    $scalp .= panel_input('scalp_poll_sec', $v, 'Seconds between polls', 'number', '1-30; one bookTicker call for every symbol per poll');
    $scalp .= panel_input('scalp_run_seconds', $v, 'Loop length per tick (s)', 'number', '1-55; must end inside the cron tick', '1');
    $scalp .= panel_input('scalp_max_trades_per_hour', $v, 'Max entries per hour', 'number', 'runaway guard', '1');
    $scalp .= panel_input('scalp_min_quote_vol', $v, 'Min 24 h quote volume', 'number', 'the liquidity floor');
    $scalp .= panel_input('scalp_min_trade_count', $v, 'Min 24 h trade count', 'number', 'the "many traders" floor - count, not volume', '0');
    $scalp .= panel_input('scalp_max_spread_pct', $v, 'Max spread %', 'number', 'a wide book is a loss before the price moves');
    $scalp .= panel_input('scalp_universe_refresh_min', $v, 'Board refresh (minutes)', 'number', 'weight 80 per refresh', '1');
    $scalp .= '</div>';
    $scalpEx = isset($v['scalp_exclude']) && is_array($v['scalp_exclude'])
        ? implode(', ', $v['scalp_exclude']) : (string) ($v['scalp_exclude'] ?? '');
    $scalp .= '<label>Never rank these symbols<span class="muted help">stablecoin pairs and anything you want kept out of the universe</span>'
            . '<textarea name="scalp_exclude" rows="2">' . $e($scalpEx) . '</textarea></label>';
    $h .= panel_engine_group('scalp', $engine, $scalp);
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

    // -- learning (DESIGN-LEARNING.md §5)
    $h .= '<fieldset><legend>Learning</legend>';
    $h .= '<p class="muted small">The bot records the <strong>conditions</strong> present at every entry evaluation - the ones it took '
        . 'and the ones it refused - and the outcome that followed, then reports which conditions actually preceded profit. That is '
        . 'statistics over its own history, not a model that discovers alpha. What it may change is deliberately tiny: the '
        . '<strong>score-component weights and the effective entry threshold, nothing else</strong>. It cannot reach position size, '
        . 'take-profit, stop-loss, a scalp budget or any kill switch - those are hard invariants asserted by the test suite. '
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

/** Engine defaults, used only to render the form when config.php has no value yet. */
function panel_engine_defaults(): array
{
    return [
        'engine' => 'signal', 'allow_live_engines' => false,
        'scalp_universe_size' => 20, 'scalp_order_usdt' => 50.0, 'scalp_budget_per_symbol' => 100.0,
        'scalp_budgets' => [], 'scalp_max_open' => 6, 'scalp_tp_pct' => 0.25, 'scalp_sl_pct' => 0.20,
        'scalp_max_hold_sec' => 120, 'scalp_dip_pct' => 0.15, 'scalp_window' => 8,
        'scalp_poll_sec' => 3, 'scalp_run_seconds' => 50, 'scalp_max_consecutive_losses' => 5,
        'scalp_max_trades_per_hour' => 60, 'scalp_min_quote_vol' => 20000000.0,
        'scalp_min_trade_count' => 50000, 'scalp_max_spread_pct' => 0.05,
        'scalp_universe_refresh_min' => 10,
        'scalp_exclude' => ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT', 'USDPUSDT'],
    ];
}

/**
 * `scalp_budgets` as the textarea should show it: pretty JSON, or '' for an empty map.
 *
 * A saved config holds a decoded map; a form that failed validation holds the raw string the
 * user typed, and THAT is what has to come back so they can see and fix their typo rather than
 * having it silently replaced with the last good value.
 */
function panel_budgets_text(array $v): string
{
    $raw = isset($v['scalp_budgets']) ? $v['scalp_budgets'] : [];
    if (is_string($raw)) {
        return trim($raw);
    }
    if (!is_array($raw) || $raw === []) {
        return '';
    }
    $json = json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    return $json === false ? '' : $json;
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
