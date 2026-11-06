<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Binance.php';
require_once __DIR__ . '/Exchange.php';
require_once __DIR__ . '/Risk.php';
// The movers board (§3) is this engine's universe. Guarded so a partial checkout, or a tool
// that only wants the pure helpers below, still loads this file.
if (file_exists(__DIR__ . '/Movers.php')) {
    require_once __DIR__ . '/Movers.php';
}

/**
 * Scalp engine - the fast loop (docs/DESIGN-SCALP.md §4, §5, §7).
 *
 * Many symbols, tiny size, market orders, seconds not hours. One cron tick runs a
 * BOUNDED loop (`scalp_run_seconds`, default 50) polling every `scalp_poll_sec`
 * (default 3), which is how sub-minute trading is reached on a host whose cron only
 * fires once a minute. Each poll makes exactly ONE market-data call - bid and ask for
 * every symbol at once - and keeps a per-symbol ring buffer of `scalp_window` mid
 * prices IN MEMORY ONLY. Nothing is persisted per tick: only entries, exits and the
 * run summary are.
 *
 * ## What this is actually for (§2)
 *
 * At 0.1 % per side the arithmetic is unforgiving, and it is worth writing down,
 * because measuring it is the whole point of this engine:
 *
 *   * Take profit is measured on the **bid** against **entry_eff**, and `entry_eff`
 *     already carries the BUY fee (the commission came out of the base asset, so the
 *     same money bought fewer coins). A 0.25 % target therefore nets roughly +0.15 %
 *     after both fees.
 *   * A 0.20 % stop, measured the same way, loses roughly -0.30 % net.
 *   * So the break-even win rate is `0.30 / (0.15 + 0.30)` = about 67 %.
 *
 * Two wins in three, net of slippage, is a high bar. The engine does not assume it is
 * cleared: every closed position records `gross_usdt` and `fees_usdt` SEPARATELY, so
 * `net = gross - fees` reconciles per trade and in the run summary and gross can never
 * silently equal net. Gross positive with net negative is a FEE problem; gross negative
 * means there is no edge at all and no fee tier would save it. The honest answer is
 * always to MEASURE and SHOW it, never to quietly soften the strategy.
 *
 * ## Safety
 *
 * **Demo-only**, exactly like grid and pmm: with `mode` = `live` and
 * `allow_live_engines` not explicitly true, run() places ZERO orders and says so.
 *
 * Three brakes, in addition to - never instead of - the global survival layer (equity
 * floor, drawdown kill switch, daily and weekly caps), which still applies and wins:
 *
 *   1. **Consecutive-loss breaker** (§5): `scalp_max_consecutive_losses` net-negative
 *      closes in a row and NEW ENTRIES stop until a human presses Resume. Any win
 *      resets the streak. The pause never clears itself.
 *   2. **Per-symbol budget** (§7): every symbol spends only its own pot, derived from
 *      its own `positions` rows and never stored as a running total, so one bad coin
 *      cannot drain the run and the scoreboard answers "WHICH coin makes money".
 *   3. **Hourly entry cap** (`scalp_max_trades_per_hour`): a runaway guard.
 *
 * An open position is ALWAYS managed to its exit, on every poll, EVEN WHILE ENTRIES
 * ARE PAUSED by any of the above and even when `enabled` is off (DESIGN.md §3: the
 * master switch stops new entries; exits are always managed). Abandoning a position
 * would be worse than any losing streak.
 *
 * ## Testability (§10 `scalp-loop`)
 *
 * The clock and the sleeper are INJECTABLE, so a test drives a scripted price path
 * instantly with no wall-clock sleeping and no network. `entrySignal()`/`entryDecision()`,
 * `exitDecision()`/`exitReason()` and the ledger math are pure statics, unit-testable
 * on their own.
 *
 * ## State keys
 *
 *   `scalp_losses`       consecutive losing closes, reset by any win
 *   `scalp_paused`       '1' once the breaker tripped (cleared only by resume())
 *   `scalp_pause_reason` human-readable reason, shown in the panel banner
 *   `scalp_paused_at`    ISO time the breaker tripped
 *   `scalp_resumed_at`   ISO time a human last resumed
 *   `scalp_stop_requested` '1' asks the running loop to give the tick lock up
 *   `scalp_last_run_at`, `scalp_last_summary`, `scalp_last_run_id`, `scalp_last_error`
 */
final class Scalper
{
    /** Engine marker written to `positions.engine`. */
    const ENGINE = 'scalp';

    /** Hard ceilings, independent of config, so a bad config cannot melt the host or the API budget. */
    const MAX_RUN_SECONDS = 55;
    const MIN_POLL_SEC    = 1;
    const MAX_POLL_SEC    = 30;
    const MAX_OPEN_CAP    = 30;

    /** Ring-buffer length bounds (`scalp_window`). */
    const MIN_WINDOW = 3;
    const MAX_WINDOW = 200;

    /** Hard ceiling on loop iterations, in case an injected clock never advances. */
    const MAX_POLLS = 5000;

    /** Position statuses that still hold inventory (and so still occupy budget). */
    const OPEN_STATUSES = ['OPEN', 'STUCK'];

    /** Quantities/notionals below this are treated as zero. */
    const EPS = 1.0e-12;

    /**
     * Tolerance for the percent comparisons in exitDecision() and entrySignal(). A threshold
     * such as "the bid is 0.25 % above entry_eff" is computed from a ratio of binary floats and
     * lands a few parts in 1e16 short of the round number, so a strict comparison silently
     * misses the exact boundary. 1e-9 of a percent is 1e-11 relative - below any tick size on
     * any pair - so it fixes the boundary without widening the rule.
     */
    const PCT_TOL = 1.0e-9;

    /** Free quote that must survive an entry (DESIGN.md §9 reserve). */
    const QUOTE_RESERVE = 0.5;

    /** DESIGN-SCALP.md §6 defaults, so the engine behaves the same against an older config.json. */
    const DEFAULTS = [
        'scalp_universe_size'          => 20,
        'scalp_order_usdt'             => 6.0,
        'scalp_budget_per_symbol'      => 100.0,
        'scalp_max_open'               => 6,
        'scalp_tp_pct'                 => 0.25,
        'scalp_sl_pct'                 => 0.20,
        'scalp_max_hold_sec'           => 120,
        'scalp_dip_pct'                => 0.15,
        'scalp_window'                 => 20,
        'scalp_poll_sec'               => 3,
        'scalp_run_seconds'            => 50,
        'scalp_max_consecutive_losses' => 5,
        'scalp_max_trades_per_hour'    => 60,
        'scalp_max_spread_pct'         => 0.05,
    ];

    /* state keys */
    const ST_LOSSES    = 'scalp_losses';
    const ST_PAUSED    = 'scalp_paused';
    const ST_REASON    = 'scalp_pause_reason';
    const ST_PAUSED_AT = 'scalp_paused_at';
    const ST_RESUMED   = 'scalp_resumed_at';
    const ST_STOP      = 'scalp_stop_requested';
    const ST_RUN       = 'scalp_last_run_id';
    const ST_ERROR     = 'scalp_last_error';

    /** Refusal reasons. */
    const R_PAUSED   = 'loss_breaker';
    const R_MAX_OPEN = 'max_open';
    const R_OPEN     = 'symbol_open';
    const R_HOURLY   = 'hourly_cap';
    const R_BUDGET   = 'symbol_budget';
    const R_SIZE     = 'size_unaffordable';
    const R_QUOTE    = 'insufficient_quote';
    const R_WINDOW   = 'window_warming';
    const R_DIP      = 'no_dip';
    const R_TURN     = 'no_turn';
    const R_SPREAD   = 'spread_wide';
    const R_BOOK     = 'no_book';
    const R_ENGINE   = 'engine_not_scalp';

    /** Exit reasons written to `positions.exit_reason`. */
    const X_TP   = 'scalp_tp';
    const X_SL   = 'scalp_sl';
    const X_HOLD = 'scalp_timeout';

    /**
     * Blocks `Risk::entryBlockReason()` reports for the SIGNAL engine only. A continuous
     * engine must not be stopped by them (DESIGN-ENGINES.md §9): scalp has its own
     * hourly cap (§6) and its own loss breaker (§5) instead. Everything else Risk
     * reports - halted, disabled, paused, api_paused, the daily and weekly caps - is the
     * survival layer and still wins.
     */
    const SIGNAL_ONLY_BLOCKS = ['cooldown', 'max_trades', 'max_orders_hour', 'consecutive_losses'];

    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var ExchangeInterface */
    private $ex;
    /** @var string */
    private $mode;
    /** @var array [SYMBOL => info] */
    private $info = [];
    /** @var array [SYMBOL => list<float>] in-memory mid-price ring buffers; never persisted */
    private $windows = [];
    /** @var int orders placed by this instance */
    private $orders = 0;
    /** @var callable () => float UNIX seconds */
    private $clock;
    /** @var callable (float $seconds) => void */
    private $sleeper;
    /** @var float|null mirrored free quote balance: ONE account() call per run */
    private $quoteFreeCache = null;
    /** @var array [ASSET => float] mirrored free base balances */
    private $baseFreeCache = [];
    /** @var bool */
    private $stopFlag = false;
    /** @var int client-id uniqueness tail */
    private $seq = 0;

    /**
     * @param array         $cfg     config (DESIGN.md §3 + DESIGN-SCALP.md §6)
     * @param array         $info    [SYMBOL => parsed symbol info]; the rest is fetched once per run
     * @param callable|null $clock   () => float UNIX seconds. Injectable: a test needs no wall clock.
     * @param callable|null $sleeper (float $seconds) => void. Injectable: a test never really sleeps.
     */
    public function __construct(
        array $cfg,
        Db $db,
        ExchangeInterface $ex,
        array $info = [],
        ?callable $clock = null,
        ?callable $sleeper = null
    ) {
        $this->cfg  = $cfg;
        $this->db   = $db;
        $this->ex   = $ex;
        $this->mode = self::modeOf($cfg, $ex);
        foreach ($info as $sym => $row) {
            if (is_array($row)) {
                $this->info[strtoupper(trim((string) $sym))] = $row;
            }
        }
        $this->clock = $clock === null
            ? function () { return microtime(true); }
            : $clock;
        $this->sleeper = $sleeper === null
            ? function ($seconds) {
                $us = (int) round(((float) $seconds) * 1000000.0);
                if ($us > 0) {
                    usleep($us);
                }
            }
            : $sleeper;
    }

    /* ------------------------------------------------------------- switches */

    /** True when the configured engine is `scalp` and the bot is enabled at all. */
    public function enabled(): bool
    {
        if (!self::cfgBool($this->cfg, 'enabled', false)) {
            return false;
        }
        return self::engineSelected($this->cfg);
    }

    /** True when `engine` is unset or set to `scalp`. */
    public static function engineSelected(array $cfg): bool
    {
        if (!isset($cfg['engine'])) {
            return true;
        }
        $e = strtolower(trim((string) $cfg['engine']));
        return $e === '' || $e === self::ENGINE;
    }

    /**
     * True when the engine refuses to place orders because it is live and
     * `allow_live_engines` is off. Mirrors Bot's guard for grid/pmm: this engine exists
     * to measure on demo, and nothing in the fee arithmetic says it is ready for real
     * money.
     */
    public function liveBlocked(): bool
    {
        return $this->mode === 'live' && !self::cfgBool($this->cfg, 'allow_live_engines', false);
    }

    /** True while the consecutive-loss breaker holds entries (§5). */
    public function paused(): bool
    {
        return self::isPaused($this->db);
    }

    /** True while the breaker holds. It NEVER clears itself - only resume() clears it. */
    public static function isPaused(Db $db): bool
    {
        $v = strtolower(trim((string) $db->getState(self::ST_PAUSED, '')));
        return !($v === '' || $v === '0' || $v === 'false' || $v === 'no' || $v === 'off');
    }

    public function pauseReason(): string
    {
        return trim((string) $this->db->getState(self::ST_REASON, ''));
    }

    /** Consecutive losing closes so far. */
    public function lossStreak(): int
    {
        return max(0, (int) $this->db->getState(self::ST_LOSSES, '0'));
    }

    /** `scalp_max_consecutive_losses`, clamped to 2..20 (§6). */
    public function lossLimit(): int
    {
        return self::lossLimitOf($this->cfg);
    }

    public static function lossLimitOf(array $cfg): int
    {
        $n = (int) round(self::cfgFloat($cfg, 'scalp_max_consecutive_losses', 5.0));
        return (int) Util::clamp((float) $n, 2.0, 20.0);
    }

    /**
     * The breaker's whole state, for the panel's banner.
     * @return array ['paused','reason','paused_at','resumed_at','streak','limit']
     */
    public static function breaker(Db $db, array $cfg = []): array
    {
        return [
            'paused'     => self::isPaused($db),
            'reason'     => trim((string) $db->getState(self::ST_REASON, '')),
            'paused_at'  => trim((string) $db->getState(self::ST_PAUSED_AT, '')),
            'resumed_at' => trim((string) $db->getState(self::ST_RESUMED, '')),
            'streak'     => max(0, (int) $db->getState(self::ST_LOSSES, '0')),
            'limit'      => self::lossLimitOf($cfg),
        ];
    }

    /**
     * Clear the breaker: the panel's "Resume scalping" button (POST + CSRF, §5). Resets
     * the streak to 0 and lifts the pause. This is the ONLY thing that lifts it - the
     * engine never un-pauses itself, because the user asked to be asked.
     */
    public function resume(): void
    {
        self::clearPause($this->db);
    }

    /** Static form of resume(), for callers that hold only a Db. */
    public static function clearPause(Db $db): void
    {
        $streak = max(0, (int) $db->getState(self::ST_LOSSES, '0'));
        $db->setState(self::ST_LOSSES, 0);
        $db->setState(self::ST_PAUSED, null);
        $db->setState(self::ST_REASON, null);
        $db->setState(self::ST_PAUSED_AT, null);
        $db->setState(self::ST_RESUMED, Util::nowIso());
        Log::info('scalp: resumed by the operator', ['cleared_streak' => $streak]);
    }

    /* ------------------------------------------------------------- the loop */

    /**
     * Run one bounded scalping loop and return a summary (also written to `scalp_runs`).
     *
     * @param array|float|null $arg an explicit universe (array of symbols - tests, and the
     *                              panel's "run now"), an absolute `microtime(true) * 1000`
     *                              deadline the loop must not pass (float), or null for
     *                              `scalp_run_seconds` from now.
     * @return array ['status','summary','run_id','polls','entries','exits',
     *                'gross','fees','net','gross_usdt','fees_usdt','net_usdt',
     *                'blocked','note','universe','paused','streak','refusals']
     */
    public function run($arg = null): array
    {
        $symbols    = is_array($arg) ? $arg : null;
        $deadlineTs = null;
        if ($arg !== null && !is_array($arg) && is_numeric($arg)) {
            $deadlineTs = ((float) $arg) / 1000.0;      // absolute ms -> seconds
        }

        $startTs = $this->now();

        $totals   = ['polls' => 0, 'entries' => 0, 'exits' => 0, 'gross' => 0.0, 'fees' => 0.0, 'net' => 0.0];
        $refusals = [];
        $runId    = 0;

        if ($this->liveBlocked()) {
            // Not an error and not silent: the demo-only guard doing its job. Zero orders.
            $runId = $this->openRun($startTs);
            $this->closeRun($runId, $totals, 'live_blocked');
            return $this->summary(
                'halted',
                'scalp refuses to run in live mode (allow_live_engines is off); no order placed',
                $runId,
                $totals,
                'live_blocked',
                [],
                $refusals,
                true
            );
        }

        $universe = $this->universe($symbols);
        $runId    = $this->openRun($startTs);
        $this->db->setState(self::ST_RUN, (string) $runId);

        if ($universe === []) {
            $this->closeRun($runId, $totals, 'no_universe');
            return $this->summary(
                'skipped',
                'no tradeable symbols on the movers board yet',
                $runId,
                $totals,
                'no_universe',
                $universe,
                $refusals
            );
        }
        $this->loadInfo($universe);

        $runSec = (int) Util::clamp(
            (float) (int) round(self::cfgFloat($this->cfg, 'scalp_run_seconds', 50.0)),
            1.0,
            (float) self::MAX_RUN_SECONDS
        );
        $pollSec = Util::clamp(
            self::cfgFloat($this->cfg, 'scalp_poll_sec', 3.0),
            (float) self::MIN_POLL_SEC,
            (float) self::MAX_POLL_SEC
        );
        $endTs  = $deadlineTs === null ? $startTs + (float) $runSec : $deadlineTs;
        $status = 'ok';
        $note   = '';

        while (true) {
            $books = $this->books($universe);
            $totals['polls']++;
            if ($books === []) {
                $note = self::R_BOOK;
            } else {
                $r = $this->poll($books);
                $totals['entries'] += (int) $r['entries'];
                $totals['exits']   += (int) $r['exits'];
                $totals['gross']   += (float) $r['gross_usdt'];
                $totals['fees']    += (float) $r['fees_usdt'];
                $totals['net']     += (float) $r['net_usdt'];
                foreach ($r['refusals'] as $sym => $reason) {
                    $refusals[$sym] = $reason;
                }
                if (isset($r['refusals']['*'])) {
                    $note = (string) $r['refusals']['*'];
                }
            }
            if ($this->stopRequested()) {
                // consume the request so it stops THIS loop and not every future one
                $note = 'stop_requested';
                $this->clearStopRequest();
                break;
            }
            if ($totals['polls'] >= self::MAX_POLLS) {
                $note = 'max_polls';
                break;
            }
            if ($this->now() + $pollSec >= $endTs) {
                break;
            }
            $this->rest($pollSec);
            if ($this->now() >= $endTs) {
                break;
            }
        }

        $this->closeRun($runId, $totals, $note);
        $summary = 'scalp ' . $totals['polls'] . ' polls, ' . $totals['entries'] . ' in, '
                 . $totals['exits'] . ' out, net ' . sprintf('%+.4f', $totals['net']) . ' USDT'
                 . ' (gross ' . sprintf('%+.4f', $totals['gross']) . ', fees '
                 . sprintf('%.4f', $totals['fees']) . ')'
                 . ($note !== '' ? ' [held: ' . $note . ']' : '');
        try {
            $this->db->setState('scalp_last_run_at', $this->nowIso());
            $this->db->setState('scalp_last_summary', $summary);
        } catch (Throwable $e) {
            // bookkeeping must never fail the run
        }
        Log::info('scalp: ' . $summary, [
            'gross' => $totals['gross'], 'fees' => $totals['fees'], 'net' => $totals['net'],
            'universe' => count($universe), 'orders' => $this->orders,
            'paused' => $this->paused() ? 1 : 0, 'streak' => $this->lossStreak(),
        ]);
        return $this->summary($status, $summary, $runId, $totals, $note, $universe, $refusals);
    }

    /**
     * One decision point.
     *
     * EXITS ARE MANAGED FIRST AND ALWAYS: never skipped, never budget-blocked, never
     * stopped by the pause or by `enabled` being off. An open position is money at
     * risk; a missed entry is only a missed opportunity. Entries run afterwards, and
     * only when nothing blocks them.
     *
     * @param array $books [SYMBOL => ['bid'=>float,'ask'=>float]]
     * @return array ['entries','exits','gross_usdt','fees_usdt','net_usdt','refusals','opened','closed']
     */
    public function poll(array $books): array
    {
        $nowTs  = $this->now();
        $nowIso = self::isoOf($nowTs);
        $out    = [
            'entries' => 0, 'exits' => 0,
            'gross_usdt' => 0.0, 'fees_usdt' => 0.0, 'net_usdt' => 0.0,
            'refusals' => [], 'opened' => [], 'closed' => [],
        ];
        $norm = [];
        foreach ($books as $sym => $book) {
            if (!is_array($book)) {
                continue;
            }
            $u   = strtoupper(trim((string) $sym));
            $bid = self::num(isset($book['bid']) ? $book['bid'] : 0.0);
            $ask = self::num(isset($book['ask']) ? $book['ask'] : 0.0);
            if ($u === '' || $bid <= 0.0 || $ask <= 0.0) {
                continue;
            }
            $norm[$u] = ['bid' => $bid, 'ask' => $ask];
        }
        // ---- ring buffers: one mid per symbol per poll, in memory only
        foreach ($norm as $u => $book) {
            $this->push($u, ($book['bid'] + $book['ask']) / 2.0);
        }
        if ($this->liveBlocked()) {
            $out['refusals']['*'] = 'live_blocked';
            return $out;
        }

        // ---- 1. exits, always
        foreach ($this->openRows() as $pos) {
            $sym = strtoupper((string) $pos['symbol']);
            $bid = isset($norm[$sym]) ? $norm[$sym]['bid'] : $this->bidFor($sym);
            if ($bid <= 0.0) {
                continue;
            }
            $reason = self::exitReason($pos, $bid, $this->cfg, $nowIso);
            if ($reason === '') {
                continue;
            }
            $closed = $this->sell($pos, $bid, $reason, $nowIso);
            if ($closed === null) {
                continue;
            }
            $out['exits']      += 1;
            $out['gross_usdt'] += (float) $closed['gross_usdt'];
            $out['fees_usdt']  += (float) $closed['fees_usdt'];
            $out['net_usdt']   += (float) $closed['net_usdt'];
            $out['closed'][]    = $closed;
        }

        // ---- 2. entries, only when nothing blocks them
        $block = $this->entryBlock($nowTs);
        if ($block !== '') {
            $out['refusals']['*'] = $block;
            return $out;
        }

        $maxOpen   = self::maxOpenOf($this->cfg);
        $openRows  = $this->openRows();
        $openCount = count($openRows);
        $held      = [];
        foreach ($openRows as $p) {
            $held[strtoupper((string) $p['symbol'])] = true;
        }
        $perHour   = (int) round(self::cfgFloat($this->cfg, 'scalp_max_trades_per_hour', 60.0));
        $entries1h = $this->entriesLastHour($nowTs);
        $order     = self::orderUsdt($this->cfg);

        // Deterministic order: the board's own ranking (the universe order), then any extra
        // symbol the books carry. Without it, two symbols competing for the last slot under
        // `scalp_max_open` would be decided by hash order.
        foreach ($this->entryOrder($norm) as $sym) {
            if ($openCount >= $maxOpen) {
                $out['refusals'][$sym] = self::R_MAX_OPEN;
                continue;
            }
            if (isset($held[$sym])) {
                $out['refusals'][$sym] = self::R_OPEN;       // one position per symbol, ever
                continue;
            }
            if ($perHour > 0 && $entries1h >= $perHour) {
                $out['refusals'][$sym] = self::R_HOURLY;
                continue;
            }
            $bid = $norm[$sym]['bid'];
            $ask = $norm[$sym]['ask'];
            $why = self::entryDecision($this->window($sym), $bid, $ask, $this->cfg);
            if ($why !== '') {
                $out['refusals'][$sym] = $why;
                continue;
            }
            // ---- §7 per-symbol budget, checked HERE, per entry, against a freshly derived
            //      ledger: a symbol that has spent its own pot stops on its own while the
            //      others carry on. Exits are never budget-blocked.
            $ledger = self::symbolLedger($this->db, $this->cfg, $sym, $this->mode);
            if ($order > $ledger['available'] + self::EPS) {
                $out['refusals'][$sym] = self::R_BUDGET;
                continue;
            }
            $afford = $this->affordable($sym, $ask, $order);
            if ($afford !== '') {
                $out['refusals'][$sym] = $afford;
                continue;
            }
            $opened = $this->buy($sym, $order, $ask, $nowIso);
            if ($opened === null) {
                $out['refusals'][$sym] = 'order_failed';
                continue;
            }
            $out['entries'] += 1;
            $out['opened'][] = $opened;
            $held[$sym]      = true;
            $openCount++;
            $entries1h++;
        }
        return $out;
    }

    /**
     * Why entries are held right now, or '' when they may proceed. Exits are NEVER
     * gated on this.
     *
     * Order matters: the global survival layer is checked before the engine's own
     * brakes, so a kill switch, an equity floor or a daily cap always wins and is
     * always the reason reported (§5).
     */
    public function entryBlock(?float $nowTs = null): string
    {
        if ($this->liveBlocked()) {
            return 'live_blocked';
        }
        if (!self::engineSelected($this->cfg)) {
            return self::R_ENGINE;
        }
        $quoteFree = $this->quoteFree();
        $block     = '';
        try {
            $block = (string) Risk::entryBlockReason(
                $this->cfg,
                $this->db,
                $quoteFree,
                $quoteFree,
                self::ENGINE
            );
        } catch (Throwable $e) {
            $block = '';
        }
        if ($block !== '') {
            $bare  = $block;
            $colon = strpos($block, ':');
            if ($colon !== false) {
                $bare = substr($block, 0, $colon);
            }
            if (!in_array($bare, self::SIGNAL_ONLY_BLOCKS, true)) {
                return $block;
            }
        }
        // §5: the user's explicit request, and the thing that makes this safe to walk away from
        if ($this->paused()) {
            return self::R_PAUSED;
        }
        $cap = (int) round(self::cfgFloat($this->cfg, 'scalp_max_trades_per_hour', 60.0));
        if ($cap > 0 && $this->entriesLastHour($nowTs === null ? $this->now() : $nowTs) >= $cap) {
            return self::R_HOURLY;
        }
        if (count($this->openRows()) >= self::maxOpenOf($this->cfg)) {
            return self::R_MAX_OPEN;
        }
        if ($quoteFree - self::orderUsdt($this->cfg) < self::QUOTE_RESERVE) {
            return self::R_QUOTE;
        }
        return '';
    }

    public static function maxOpenOf(array $cfg): int
    {
        $n = (int) round(self::cfgFloat($cfg, 'scalp_max_open', 6.0));
        return (int) Util::clamp((float) $n, 1.0, (float) self::MAX_OPEN_CAP);
    }

    /** Quote to spend per position (§6 `scalp_order_usdt`), floored to what fmtQuote sends. */
    public static function orderUsdt(array $cfg): float
    {
        $v = self::cfgFloat($cfg, 'scalp_order_usdt', 6.0);
        if ($v <= 0.0) {
            $v = 6.0;
        }
        return (float) Util::floorToStep($v, '0.01');
    }

    /** Ask the loop to stop after the poll in flight (the tick lock is wanted). */
    public function requestStop(): void
    {
        $this->stopFlag = true;
    }

    /** The in-memory ring buffers, for the panel and for tests. @return array [SYMBOL => float[]] */
    public function windows(): array
    {
        return $this->windows;
    }

    /** One symbol's ring buffer (chronological, newest last). @return float[] */
    public function window(string $symbol): array
    {
        $u = strtoupper(trim($symbol));
        return isset($this->windows[$u]) ? $this->windows[$u] : [];
    }

    /**
     * Seed a ring buffer directly. Tests use it to reach a full window without replaying
     * `scalp_window` polls; the loop itself only ever fills buffers from the books.
     * @param float[] $mids
     */
    public function seedWindow(string $symbol, array $mids): void
    {
        $u = strtoupper(trim($symbol));
        $this->windows[$u] = [];
        foreach ($mids as $m) {
            $this->push($u, self::num($m));
        }
    }

    /**
     * The trading universe: the movers board's `in_universe` rows (§3), refreshed at most
     * every `scalp_universe_refresh_min` (weight 80) and NEVER inside the poll loop, with
     * the watchlist as a fallback when the board has never run.
     *
     * A symbol holding an open position is ALWAYS pinned, even when it has dropped out of
     * the ranking, so a position can never be stranded outside the universe (§3, §4).
     *
     * @param array|null $explicit skip the board entirely and use these symbols
     * @return string[]
     */
    public function universe(?array $explicit = null): array
    {
        $pinned = [];
        foreach ($this->openRows() as $p) {
            $s = strtoupper(trim((string) $p['symbol']));
            if ($s !== '') {
                $pinned[$s] = true;
            }
        }
        $out = [];
        if (is_array($explicit)) {
            foreach ($explicit as $s) {
                $this->addSymbol($out, (string) $s);
            }
        } else {
            if (class_exists('Movers')) {
                try {
                    $movers = new Movers($this->cfg, $this->db, $this->ex);
                    $movers->refresh($this->info, false, array_keys($pinned));
                } catch (Throwable $e) {
                    Log::warn('scalp: movers refresh failed - ' . $e->getMessage());
                }
            }
            $board = [];
            try {
                $board = $this->db->moversUniverse();
            } catch (Throwable $e) {
                $board = [];
            }
            foreach ($board as $s) {
                $this->addSymbol($out, (string) $s);
            }
            if ($out === []) {
                // the board has never run: fall back to the watchlist so a fresh install can
                // still be exercised, bounded by the same universe size
                $size  = self::universeSize($this->cfg);
                $watch = isset($this->cfg['symbols']) && is_array($this->cfg['symbols'])
                       ? $this->cfg['symbols'] : [];
                foreach ($watch as $s) {
                    if (count($out) >= $size) {
                        break;
                    }
                    $this->addSymbol($out, (string) $s);
                }
            }
        }
        foreach (array_keys($pinned) as $s) {
            // belt and braces: never drop a symbol we are holding
            if (!isset($out[$s])) {
                $out[$s] = true;
            }
        }
        return array_values(array_keys($out));
    }

    /** `scalp_universe_size`, clamped to 1..30 (§6). */
    public static function universeSize(array $cfg): int
    {
        if (class_exists('Movers') && method_exists('Movers', 'universeSizeOf')) {
            return (int) Movers::universeSizeOf($cfg);
        }
        $n = (int) round(self::cfgFloat($cfg, 'scalp_universe_size', 20.0));
        return (int) Util::clamp((float) $n, 1.0, 30.0);
    }

    /* ------------------------------------------------------- pure decisions */

    /**
     * Entry decision for one symbol. **Pure** - a price window and the config in, a
     * reason out. Returns '' to enter, or the reason not to. LONG ONLY; this engine
     * never shorts, because a spot account cannot.
     *
     * All of these must hold (§4):
     *   1. the window is FULL (`scalp_window` mids) - a partial window has no high to dip from;
     *   2. `dip = (windowHigh - mid) / windowHigh >= scalp_dip_pct` - it has pulled back;
     *   3. the LAST TWO TICKS ARE RISING - it has turned. Buying a falling knife on the dip
     *      rule alone is how a 0.20 % stop gets hit on the very next poll;
     *   4. `spread_pct <= scalp_max_spread_pct` - the round trip starts by paying the spread,
     *      so a wide book is a guaranteed loss before the price has moved at all.
     */
    public static function entryDecision(array $window, float $bid, float $ask, array $cfg): string
    {
        return self::entrySignal($window, $bid, $ask, $cfg)['reason'];
    }

    /**
     * entryDecision() with its workings, for the panel and for tests.
     *
     * @param float[] $window ring buffer of mids, chronological, newest last
     * @return array ['ok'=>bool,'reason'=>string,'mid'=>float,'high'=>float,
     *                'dip_pct'=>float,'spread_pct'=>float]
     */
    public static function entrySignal(array $window, float $bid, float $ask, array $cfg): array
    {
        $need      = self::windowOf($cfg);
        $dipNeed   = max(0.0, self::cfgFloat($cfg, 'scalp_dip_pct', 0.15));
        $maxSpread = max(0.0, self::cfgFloat($cfg, 'scalp_max_spread_pct', 0.05));

        $mids = [];
        foreach ($window as $m) {
            $v = self::num($m);
            if ($v > 0.0) {
                $mids[] = $v;
            }
        }
        $n   = count($mids);
        $out = [
            'ok' => false, 'reason' => self::R_WINDOW, 'mid' => $n > 0 ? $mids[$n - 1] : 0.0,
            'high' => 0.0, 'dip_pct' => 0.0, 'spread_pct' => 0.0,
        ];
        if (!is_finite($bid) || !is_finite($ask) || $bid <= 0.0 || $ask <= 0.0 || $ask < $bid) {
            $out['reason'] = self::R_BOOK;
            return $out;
        }
        $book = ($bid + $ask) / 2.0;
        $out['spread_pct'] = $book > 0.0 ? ($ask - $bid) / $book * 100.0 : 0.0;
        if ($n < $need) {
            $out['reason'] = self::R_WINDOW;
            return $out;
        }
        if ($maxSpread > 0.0 && $out['spread_pct'] > $maxSpread + 1.0e-12) {
            $out['reason'] = self::R_SPREAD;
            return $out;
        }
        $high = max($mids);
        $out['high'] = $high;
        $mid = $mids[$n - 1];
        if ($high <= 0.0 || $mid <= 0.0) {
            $out['reason'] = self::R_WINDOW;
            return $out;
        }
        $out['dip_pct'] = ($high - $mid) / $high * 100.0;
        if ($out['dip_pct'] + 1.0e-12 < $dipNeed) {
            $out['reason'] = self::R_DIP;
            return $out;
        }
        // The turn: the last TWO changes are both positive, so one up-tick in a slide is
        // not mistaken for a bounce.
        if (!($mids[$n - 1] > $mids[$n - 2] && $mids[$n - 2] > $mids[$n - 3])) {
            $out['reason'] = self::R_TURN;
            return $out;
        }
        $out['ok']     = true;
        $out['reason'] = '';
        return $out;
    }

    /**
     * Exit reason for an open scalp position, or '' to hold. **Pure**.
     *
     * Take profit, stop and max hold, every one measured on the **BID** against
     * **entry_eff**, never against the raw fill price: `entry_eff` is the dust-excluded
     * cost basis that already carries the BUY fee, and the bid is what a sale actually
     * receives. Measuring a target on the ask, or against the raw fill, books a "profit"
     * the round trip never produced.
     */
    public static function exitReason(array $pos, float $bid, array $cfg, string $nowIso): string
    {
        return self::exitDecision($pos, $bid, $cfg, $nowIso)['reason'];
    }

    /**
     * exitReason() with its workings.
     * @return array ['reason'=>''|'scalp_tp'|'scalp_sl'|'scalp_timeout',
     *                'tp_price'=>float,'stop_price'=>float,'age_sec'=>float,'pnl_pct'=>float]
     */
    public static function exitDecision(array $pos, float $bid, array $cfg, string $nowIso): array
    {
        $eff   = self::num(isset($pos['entry_eff']) ? $pos['entry_eff'] : 0.0);
        $tpPct = max(0.0, self::cfgFloat($cfg, 'scalp_tp_pct', 0.25));
        $slPct = max(0.0, self::cfgFloat($cfg, 'scalp_sl_pct', 0.20));
        $hold  = (int) round(self::cfgFloat($cfg, 'scalp_max_hold_sec', 120.0));

        $out = [
            'reason'     => '',
            'tp_price'   => $eff > 0.0 ? $eff * (1.0 + $tpPct / 100.0) : 0.0,
            'stop_price' => $eff > 0.0 ? $eff * (1.0 - $slPct / 100.0) : 0.0,
            'age_sec'    => 0.0,
            'pnl_pct'    => 0.0,
        ];
        $opened = isset($pos['opened_at']) ? Util::isoToTs((string) $pos['opened_at']) : null;
        $now    = Util::isoToTs($nowIso);
        if ($opened !== null && $now !== null) {
            $out['age_sec'] = (float) max(0, $now - $opened);
        }
        if ($eff <= 0.0 || !is_finite($bid) || $bid <= 0.0) {
            return $out;
        }
        $movePct = ($bid / $eff - 1.0) * 100.0;
        $out['pnl_pct'] = $movePct;

        // The comparisons carry a tolerance because `$movePct` is a ratio of two binary floats:
        // a bid of exactly entry_eff x 1.0025 computes as 0.24999999999999467, which a strict
        // `>= 0.25` misses. The position then rides past its own target until the next poll
        // moves the price - which, at a 0.25 % target, is how a winner turns into a loser.
        // TOL is far below any price step that matters (1e-9 of a percent) and far above the
        // representation error, so it cannot turn a real miss into a hit.
        if ($tpPct > 0.0 && $movePct >= $tpPct - self::PCT_TOL) {
            $out['reason'] = self::X_TP;
            return $out;
        }
        if ($slPct > 0.0 && $movePct <= -$slPct + self::PCT_TOL) {
            $out['reason'] = self::X_SL;
            return $out;
        }
        if ($hold > 0 && $out['age_sec'] >= (float) $hold) {
            // A scalp that has not resolved either way is capital tied up in a trade whose
            // thesis - a bounce in the next couple of minutes - has already failed.
            $out['reason'] = self::X_HOLD;
            return $out;
        }
        return $out;
    }

    /** `scalp_window`, clamped to MIN_WINDOW..MAX_WINDOW. */
    public static function windowOf(array $cfg): int
    {
        $n = (int) round(self::cfgFloat($cfg, 'scalp_window', 20.0));
        return (int) Util::clamp((float) $n, (float) self::MIN_WINDOW, (float) self::MAX_WINDOW);
    }

    /* --------------------------------------------- per-symbol budget (§7) */

    /** This symbol's cap: `scalp_budgets[SYMBOL]` when set, else `scalp_budget_per_symbol`. */
    public static function budgetFor(array $cfg, string $symbol): float
    {
        $sym = strtoupper(trim($symbol));
        $map = self::budgets($cfg);
        if ($sym !== '' && array_key_exists($sym, $map)) {
            return $map[$sym];
        }
        $d = self::cfgFloat($cfg, 'scalp_budget_per_symbol', 100.0);
        return ($d >= 0.0 && is_finite($d)) ? $d : 100.0;
    }

    /** Alias of budgetFor() (DESIGN-SCALP.md §7 wording). */
    public static function symbolBudget(array $cfg, string $symbol): float
    {
        return self::budgetFor($cfg, $symbol);
    }

    /** @return array [SYMBOL => float] from `scalp_budgets` (array or JSON string) */
    public static function budgets(array $cfg): array
    {
        $raw = isset($cfg['scalp_budgets']) ? $cfg['scalp_budgets'] : [];
        if (is_string($raw)) {
            $raw = trim($raw);
            $raw = $raw === '' ? [] : json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $sym => $v) {
            $s = strtoupper(trim((string) $sym));
            if ($s === '' || !is_numeric($v)) {
                continue;
            }
            $f = (float) $v;
            if (is_finite($f) && $f >= 0.0) {
                $out[$s] = $f;
            }
        }
        return $out;
    }

    /**
     * §7 ledger math, pure: `available = max(0, budget + realised_net - deployed)`.
     *
     * Never negative, never NaN, never infinite - for an empty symbol, a zero budget or
     * a symbol deep underwater.
     */
    public static function available(float $budget, float $realisedNet, float $deployed): float
    {
        $b = self::num($budget);
        $r = self::num($realisedNet);
        $d = self::num($deployed);
        if ($b < 0.0) {
            $b = 0.0;
        }
        if ($d < 0.0) {
            $d = 0.0;
        }
        $a = $b + $r - $d;
        if (!is_finite($a) || $a < 0.0) {
            return 0.0;
        }
        return $a;
    }

    /**
     * One symbol's ledger, DERIVED on every read from the `positions` rows it owns (§7).
     *
     * Deliberately NOT a stored running total: a counter in `state` would drift out of
     * step with the trades it claims to describe the first time a tick died between the
     * sell and the increment, and would then silently misreport which coin is making
     * money - the one question this whole engine exists to answer.
     *
     * A symbol that has lost its pot stops trading on its own while the others carry on.
     *
     * @param string|null $mode null means the configured mode
     * @return array ['symbol','budget','deployed','available','gross','fees','net',
     *                'trades','wins','losses','win_rate','open','net_pct']
     */
    public static function symbolLedger(Db $db, array $cfg, string $symbol, ?string $mode = null): array
    {
        $sym    = strtoupper(trim($symbol));
        $budget = self::budgetFor($cfg, $sym);
        $m      = $mode === null ? self::cfgMode($cfg) : $mode;
        $rows   = [];
        if ($sym !== '') {
            try {
                $rows = $db->scalpPositions($sym, $m);
            } catch (Throwable $e) {
                $rows = [];
            }
        }
        $deployed = 0.0;
        $gross    = 0.0;
        $fees     = 0.0;
        $realised = 0.0;
        $trades   = 0;
        $wins     = 0;
        $losses   = 0;
        $open     = 0;
        foreach ($rows as $r) {
            $status = strtoupper(trim((string) (isset($r['status']) ? $r['status'] : '')));
            if (in_array($status, self::OPEN_STATUSES, true)) {
                $open++;
                $deployed += self::rowF($r, 'entry_quote');
                continue;
            }
            if ($status !== 'CLOSED') {
                continue;
            }
            $trades++;
            $gross += self::rowF($r, 'gross_usdt');
            $fees  += self::rowF($r, 'fees_usdt');
            $n      = self::rowF($r, 'pnl_usdt');
            $realised += $n;
            if ($n > 0.0) {
                $wins++;
            } elseif ($n < 0.0) {
                $losses++;
            }
        }
        $available = self::available($budget, $realised, $deployed);
        return [
            'symbol'    => $sym,
            'budget'    => self::fin($budget),
            'deployed'  => self::fin($deployed),
            'available' => self::fin($available),
            'gross'     => self::fin($gross),
            'fees'      => self::fin($fees),
            'net'       => self::fin($realised),
            'trades'    => $trades,
            'wins'      => $wins,
            'losses'    => $losses,
            // null, not 0, when nothing has closed: "0 % win rate" and "no trades yet" are
            // very different statements and the panel must not conflate them.
            'win_rate'  => $trades > 0 ? round($wins / $trades * 100.0, 2) : null,
            'open'      => $open,
            'net_pct'   => $budget > 0.0 ? round($realised / $budget * 100.0, 4) : null,
        ];
    }

    /**
     * The scoreboard the user will actually read (§7): one row per symbol that has traded
     * or is in the universe, sorted by net descending. The per-symbol nets sum to the
     * engine's net, because both are derived from the same rows.
     *
     * @return array list of symbolLedger() rows
     */
    public function scoreboard(): array
    {
        return self::scoreboardOf($this->db, $this->cfg, $this->mode, $this->universeSymbols());
    }

    /**
     * Static scoreboard, for the panel.
     * @param string[] $universe symbols to show even when they have never traded
     */
    public static function scoreboardOf(Db $db, array $cfg, ?string $mode = null, array $universe = []): array
    {
        $m    = $mode === null ? self::cfgMode($cfg) : $mode;
        $syms = [];
        foreach ($universe as $s) {
            $u = strtoupper(trim((string) $s));
            if ($u !== '') {
                $syms[$u] = true;
            }
        }
        try {
            foreach ($db->scalpSymbols($m) as $s) {
                $u = strtoupper(trim((string) $s));
                if ($u !== '') {
                    $syms[$u] = true;
                }
            }
        } catch (Throwable $e) {
            // a board-less, trade-less database is a legitimate empty scoreboard
        }
        $out = [];
        foreach (array_keys($syms) as $s) {
            $out[] = self::symbolLedger($db, $cfg, $s, $m);
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['net'] !== $b['net']) {
                return $b['net'] <=> $a['net'];
            }
            if ($a['trades'] !== $b['trades']) {
                return $b['trades'] <=> $a['trades'];
            }
            return strcmp((string) $a['symbol'], (string) $b['symbol']);
        });
        return $out;
    }

    /**
     * The §2 verdict over every closed scalp position: gross, fees, net AND the trade count.
     *
     * The trade count is part of the verdict, not decoration. A +0.40 USDT net over 6
     * trades is noise; the same number over 600 is a signal. Reporting net without n
     * invites exactly the conclusion this engine was built to avoid.
     */
    public function verdict(): array
    {
        return self::verdictOf($this->db, $this->cfg, $this->mode);
    }

    public static function verdictOf(Db $db, array $cfg, ?string $mode = null): array
    {
        $m      = $mode === null ? self::cfgMode($cfg) : $mode;
        $gross  = 0.0;
        $fees   = 0.0;
        $net    = 0.0;
        $trades = 0;
        $wins   = 0;
        $losses = 0;
        $rows   = [];
        try {
            $rows = $db->scalpPositions(null, $m, 'CLOSED');
        } catch (Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $trades++;
            $gross += self::rowF($r, 'gross_usdt');
            $fees  += self::rowF($r, 'fees_usdt');
            $n      = self::rowF($r, 'pnl_usdt');
            $net   += $n;
            if ($n > 0.0) {
                $wins++;
            } elseif ($n < 0.0) {
                $losses++;
            }
        }
        $open     = 0;
        $deployed = 0.0;
        try {
            foreach ($db->scalpPositions(null, $m) as $r) {
                $status = strtoupper(trim((string) (isset($r['status']) ? $r['status'] : '')));
                if (in_array($status, self::OPEN_STATUSES, true)) {
                    $open++;
                    $deployed += self::rowF($r, 'entry_quote');
                }
            }
        } catch (Throwable $e) {
            // leave the open figures at zero
        }
        return [
            'gross'    => self::fin($gross),
            'fees'     => self::fin($fees),
            'net'      => self::fin($net),
            'trades'   => $trades,
            'wins'     => $wins,
            'losses'   => $losses,
            'open'     => $open,
            'deployed' => self::fin($deployed),
            'win_rate' => $trades > 0 ? round($wins / $trades * 100.0, 2) : null,
            // What the win rate would have to be for THIS configuration to break even,
            // shown beside the real one so the user can see the gap rather than being told
            // a story about it.
            'breakeven_win_rate' => self::breakEvenWinRate($cfg),
        ];
    }

    /**
     * Win rate needed to break even at the configured TP, SL and fee, as a percent, or
     * null when the numbers make it meaningless.
     *
     * `tpNet = tp - fee` and `slNet = sl + fee` where `fee` is ONE side's fee:
     * `entry_eff` already carries the BUY fee, so only the SELL fee is still outstanding
     * when a target measured against `entry_eff` is reached. Break-even is
     * `slNet / (tpNet + slNet)`.
     */
    public static function breakEvenWinRate(array $cfg): ?float
    {
        $tp  = self::cfgFloat($cfg, 'scalp_tp_pct', 0.25);
        $sl  = self::cfgFloat($cfg, 'scalp_sl_pct', 0.20);
        $fee = self::cfgFloat($cfg, 'fee_pct', 0.1);
        $tpNet = $tp - $fee;
        $slNet = $sl + $fee;
        if ($tpNet <= 0.0 || $tpNet + $slNet <= 0.0) {
            return null;        // the target cannot clear the fee at all: no win rate saves it
        }
        return round($slNet / ($tpNet + $slNet) * 100.0, 2);
    }

    /* ------------------------------------------------------ position queries */

    /**
     * Scalp positions that still hold inventory (OPEN and STUCK), oldest first, so the
     * loop manages the longest-held one first and `scalp_max_hold_sec` cannot be starved
     * by newer entries.
     *
     * Scalp runs MANY open positions at once, so it never uses Db::openPosition(), which
     * returns THE one position the signal engine manages.
     */
    public function openRows(): array
    {
        return self::openPositions($this->db, $this->cfg, $this->mode);
    }

    /** @param string|null $mode null means the configured mode */
    public static function openPositions(Db $db, array $cfg, ?string $mode = null): array
    {
        $m   = $mode === null ? self::cfgMode($cfg) : $mode;
        $out = [];
        try {
            foreach ($db->scalpPositions(null, $m) as $r) {
                $status = strtoupper(trim((string) (isset($r['status']) ? $r['status'] : '')));
                if (in_array($status, self::OPEN_STATUSES, true)) {
                    $out[] = $r;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** Scalp ENTRIES in the last hour - the runaway guard counts these (§6). */
    private function entriesLastHour(float $nowTs): int
    {
        $since = self::isoOf($nowTs - 3600.0);
        try {
            return (int) $this->db->scalpEntriesSince($since, $this->mode);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Symbols currently on the board plus the ones we hold (no refresh, no network). */
    private function universeSymbols(): array
    {
        $out = [];
        try {
            foreach ($this->db->moversUniverse() as $s) {
                $out[] = $s;
            }
        } catch (Throwable $e) {
            // no board yet
        }
        foreach ($this->openRows() as $p) {
            $out[] = strtoupper((string) $p['symbol']);
        }
        return array_values(array_unique($out));
    }

    /* ----------------------------------------------------------------- I/O */

    /**
     * ONE market-data call per poll: bid and ask for EVERY symbol at once
     * (`GET /api/v3/ticker/bookTicker` with no symbol - weight 2 at the time of writing,
     * budgeted conservatively at 4). At 20 polls per minute that is <= 80 weight/min
     * against a 6000/min limit, so the universe can grow without re-architecting.
     *
     * The paper and fake exchanges have no all-symbols endpoint, so that path polls the
     * universe one symbol at a time - local calls, no network.
     *
     * @param string[] $symbols
     * @return array [SYMBOL => ['bid'=>float,'ask'=>float]]
     */
    private function books(array $symbols): array
    {
        $want = [];
        foreach ($symbols as $s) {
            $u = strtoupper(trim((string) $s));
            if ($u !== '') {
                $want[$u] = true;
            }
        }
        if ($want === []) {
            return [];
        }
        $all = null;
        if (method_exists($this->ex, 'bookTickerAll')) {
            try {
                $all = $this->ex->bookTickerAll();
            } catch (Throwable $e) {
                Log::warn('scalp: bookTickerAll failed - ' . $e->getMessage());
                return [];
            }
        } elseif (method_exists($this->ex, 'api')) {
            try {
                $api = $this->ex->api();
                if ($api instanceof Binance && method_exists($api, 'bookTickerAll')) {
                    $all = $api->bookTickerAll();
                }
            } catch (Throwable $e) {
                Log::warn('scalp: bookTickerAll failed - ' . $e->getMessage());
                return [];
            }
        }
        $out = [];
        if (is_array($all)) {
            foreach ($all as $sym => $book) {
                $u = strtoupper(trim((string) $sym));
                if (!isset($want[$u]) || !is_array($book)) {
                    continue;
                }
                $bid = self::num(isset($book['bid']) ? $book['bid'] : 0.0);
                $ask = self::num(isset($book['ask']) ? $book['ask'] : 0.0);
                if ($bid > 0.0 && $ask > 0.0) {
                    $out[$u] = ['bid' => $bid, 'ask' => $ask];
                }
            }
            return $out;
        }
        foreach (array_keys($want) as $u) {
            try {
                $b = $this->ex->bookTicker($u);
            } catch (Throwable $e) {
                continue;
            }
            $bid = self::num(isset($b['bid']) ? $b['bid'] : 0.0);
            $ask = self::num(isset($b['ask']) ? $b['ask'] : 0.0);
            if ($bid > 0.0 && $ask > 0.0) {
                $out[$u] = ['bid' => $bid, 'ask' => $ask];
            }
        }
        return $out;
    }

    /**
     * The bid for one symbol when this poll's books do not carry it. An open position is
     * never abandoned just because its symbol dropped off the board.
     */
    private function bidFor(string $symbol): float
    {
        try {
            $b = $this->ex->bookTicker($symbol);
        } catch (Throwable $e) {
            Log::warn('scalp: no book for an open position ' . $symbol . ' - ' . $e->getMessage());
            return 0.0;
        }
        return self::num(isset($b['bid']) ? $b['bid'] : 0.0);
    }

    /** Parsed symbol info for the universe, fetched once per run - never inside the poll loop. */
    private function loadInfo(array $symbols): void
    {
        $want = [];
        foreach ($symbols as $s) {
            $u = strtoupper(trim((string) $s));
            if ($u !== '' && !isset($this->info[$u])) {
                $want[] = $u;
            }
        }
        if ($want === []) {
            return;
        }
        try {
            $fresh = $this->ex->symbolInfo($want);
        } catch (Throwable $e) {
            Log::warn('scalp: symbolInfo failed - ' . $e->getMessage());
            return;
        }
        if (!is_array($fresh)) {
            return;
        }
        foreach ($fresh as $sym => $row) {
            if (is_array($row)) {
                $this->info[strtoupper(trim((string) $sym))] = $row;
            }
        }
    }

    private function infoFor(string $symbol): array
    {
        $u = strtoupper(trim($symbol));
        return isset($this->info[$u]) ? $this->info[$u] : [];
    }

    /** Push one mid onto a symbol's ring buffer, trimming to `scalp_window`. */
    private function push(string $symbol, float $mid): void
    {
        if ($mid <= 0.0 || !is_finite($mid)) {
            return;
        }
        $need = self::windowOf($this->cfg);
        if (!isset($this->windows[$symbol])) {
            $this->windows[$symbol] = [];
        }
        $this->windows[$symbol][] = $mid;
        $over = count($this->windows[$symbol]) - $need;
        if ($over > 0) {
            $this->windows[$symbol] = array_slice($this->windows[$symbol], $over);
        }
    }

    /** @param array $out [SYMBOL => true], by reference */
    private function addSymbol(array &$out, string $symbol): void
    {
        $s = strtoupper(trim($symbol));
        if ($s === '' || isset($out[$s])) {
            return;
        }
        $q = self::quoteAsset($this->cfg);
        if (strlen($s) <= strlen($q) || substr($s, -strlen($q)) !== $q) {
            return;
        }
        $out[$s] = true;
    }

    /**
     * Candidate symbols for entries this poll, in a deterministic order: the universe's
     * own ranking first, then anything else the books carry.
     * @return string[]
     */
    private function entryOrder(array $books): array
    {
        $out  = [];
        $seen = [];
        foreach ($this->universeSymbols() as $s) {
            if (isset($books[$s]) && !isset($seen[$s])) {
                $out[]     = $s;
                $seen[$s]  = true;
            }
        }
        foreach (array_keys($books) as $s) {
            if (!isset($seen[$s])) {
                $out[]    = $s;
                $seen[$s] = true;
            }
        }
        return $out;
    }

    /** Symbol-level affordability: the exchange's filters, and the free-quote reserve. */
    private function affordable(string $symbol, float $ask, float $order): string
    {
        $info = $this->infoFor($symbol);
        if ($info === []) {
            return self::R_SIZE;
        }
        $feePct = self::feePct($this->cfg, $this->db);
        if ($order < Risk::requiredSize($info, $ask, $feePct)) {
            return self::R_SIZE;
        }
        if ($this->quoteFree() - $order < self::QUOTE_RESERVE) {
            return self::R_QUOTE;
        }
        return '';
    }

    /* --------------------------------------------------------------- orders */

    /**
     * Market BUY and the OPEN position row.
     * @return array|null the stored row, or null when nothing was bought
     */
    private function buy(string $symbol, float $size, float $ask, string $nowIso): ?array
    {
        $info = $this->infoFor($symbol);
        $cid  = $this->clientId('B', $symbol);
        try {
            $r = $this->ex->marketBuy($symbol, $size, $info, $cid);
        } catch (Throwable $e) {
            $r = $this->recoverOrder($symbol, $cid, 'BUY', $e);
            if ($r === null) {
                return null;
            }
        }
        $this->orders++;
        $qty   = self::num(isset($r['qty']) ? $r['qty'] : 0.0);
        $quote = self::num(isset($r['quote']) ? $r['quote'] : 0.0);
        if ($qty <= self::EPS || $quote <= 0.0) {
            Log::warn('scalp: BUY executed nothing', ['symbol' => $symbol, 'client_id' => $cid]);
            return null;
        }
        $dust = max(0.0, self::num(isset($r['dust_qty']) ? $r['dust_qty'] : 0.0));
        $fill = self::num(isset($r['price']) ? $r['price'] : $ask, $ask);
        $fee  = self::num(isset($r['fee_usdt']) ? $r['fee_usdt'] : 0.0);
        // Same basis as the signal engine (DESIGN.md §10 step 6): the dust is not lost, it
        // stays in the wallet and is recovered by a later SELL, so charging it against the
        // sellable quantity would put entry_eff a whole stepSize above the fill and stop a
        // fresh position out on the very next poll.
        $basis = $quote - $dust * $fill;
        $eff   = ($fill > 0.0 && $basis > 0.0) ? $basis / $qty : $quote / $qty;
        $tp    = self::cfgFloat($this->cfg, 'scalp_tp_pct', 0.25);
        $sl    = self::cfgFloat($this->cfg, 'scalp_sl_pct', 0.20);

        $pid = $this->db->insertPosition([
            'mode'              => $this->mode,
            'engine'            => self::ENGINE,
            'symbol'            => $symbol,
            'status'            => 'OPEN',
            'qty'               => $qty,
            'dust_qty'          => $dust,
            'entry_price'       => $fill,
            'entry_eff'         => $eff,
            'entry_quote'       => $quote,
            'entry_fee_usdt'    => $fee,
            'gross_usdt'        => 0.0,
            'fees_usdt'         => $fee,
            'stop_price'        => $eff * (1.0 - $sl / 100.0),
            'take_profit_price' => $eff * (1.0 + $tp / 100.0),
            'trail_high'        => $eff,
            'trailing_armed'    => 0,
            'entry_reason'      => 'scalp_dip',
            'opened_at'         => $nowIso,
        ]);
        $this->db->insertTrade([
            'position_id' => $pid, 'mode' => $this->mode, 'symbol' => $symbol, 'side' => 'BUY',
            'order_id'    => (string) (isset($r['order_id']) ? $r['order_id'] : ''),
            'client_id'   => $cid,
            'qty'         => $qty, 'price' => $fill, 'quote' => $quote,
            'fee_usdt'    => $fee,
            'fee_asset'   => (string) (isset($r['fee_asset']) ? $r['fee_asset'] : ''),
            'created_at'  => $nowIso,
        ]);
        $this->creditBuy($symbol, $quote, $qty + $dust);
        Log::info('scalp: bought ' . $symbol . ' ' . Util::money($quote, 2) . ' '
            . self::quoteAsset($this->cfg), [
                'position_id' => $pid, 'qty' => $qty, 'price' => $fill, 'entry_eff' => $eff,
            ]);
        $row = $this->db->position($pid);
        return is_array($row) ? $row : null;
    }

    /**
     * Market SELL and the CLOSED position row, with gross and fees recorded SEPARATELY
     * (§2/§8). The arithmetic, which has to reconcile exactly or the verdict is worthless:
     *
     *   net_usdt   = exit_quote - entry_quote      what the account actually gained (= pnl_usdt)
     *   fees_usdt  = entry_fee_usdt + exit_fee     what the exchange took, both sides
     *   gross_usdt = net_usdt + fees_usdt          the directional move, fees excluded
     *
     * so `net === gross - fees` ALWAYS, per trade and summed over the run, and gross can
     * never silently equal net while a fee was paid. `net_usdt` is `pnl_usdt` so the
     * survival layer, the equity curve and the closed-trade history all see one number
     * and the same one.
     *
     * @return array|null the closed summary, or null when nothing was sold
     */
    private function sell(array $pos, float $bid, string $reason, string $nowIso): ?array
    {
        $pid  = (int) $pos['id'];
        $sym  = strtoupper((string) $pos['symbol']);
        $info = $this->infoFor($sym);
        $step = (string) (isset($info['stepSize']) ? $info['stepSize'] : '0.00000001');
        $base = self::baseAsset($sym, self::quoteAsset($this->cfg), $info);

        $wanted = self::num(isset($pos['qty']) ? $pos['qty'] : 0.0);
        $onHand = $this->baseFree($base);
        $qtyStr = Util::floorToStep($onHand > 0.0 ? min($wanted, $onHand) : $wanted, $step);
        if ((float) $qtyStr <= self::EPS) {
            $this->markStuck($pos, 'scalp_unsellable');
            return null;
        }
        $minNotional = self::num(isset($info['minNotional']) ? $info['minNotional'] : 0.0);
        $applyMin    = !array_key_exists('applyMinToMarket', $info) || (bool) $info['applyMinToMarket'];
        if ($applyMin && $minNotional > 0.0 && (float) $qtyStr * $bid < $minNotional) {
            // Below the exchange's minimum: the sell can only be rejected. Mark it and move
            // on rather than hammering the API with an order that cannot succeed. The check
            // is local, so the position is re-examined every poll and sold the moment the
            // price recovers - it is parked, not abandoned.
            $this->markStuck($pos, 'scalp_notional');
            return null;
        }
        $cid = $this->clientId('S', $sym);
        try {
            $r = $this->ex->marketSell($sym, $qtyStr, $info, $cid);
        } catch (Throwable $e) {
            $r = $this->recoverOrder($sym, $cid, 'SELL', $e);
            if ($r === null) {
                return null;
            }
        }
        $this->orders++;
        $filled = self::num(isset($r['qty']) ? $r['qty'] : 0.0);
        if ($filled <= self::EPS) {
            Log::warn('scalp: SELL executed nothing, position left ' . $pos['status'], [
                'symbol' => $sym, 'position_id' => $pid, 'requested_qty' => $qtyStr,
            ]);
            return null;                        // nothing left the wallet: leave the row as it is
        }
        $exitPrice  = self::num(isset($r['price']) ? $r['price'] : $bid, $bid);
        $exitQuote  = self::num(isset($r['quote']) ? $r['quote'] : 0.0);
        $exitFee    = self::num(isset($r['fee_usdt']) ? $r['fee_usdt'] : 0.0);
        $entryQuote = self::rowF($pos, 'entry_quote');
        $entryFee   = self::rowF($pos, 'entry_fee_usdt');

        $net   = $exitQuote - $entryQuote;
        $fees  = $entryFee + $exitFee;
        $gross = $net + $fees;

        $heldSec = 0;
        $opened  = Util::isoToTs((string) $pos['opened_at']);
        $nowSec  = Util::isoToTs($nowIso);
        if ($opened !== null && $nowSec !== null) {
            $heldSec = max(0, $nowSec - $opened);
        }
        $this->db->updatePosition($pid, [
            'status'        => 'CLOSED',
            'exit_price'    => $exitPrice,
            'exit_quote'    => $exitQuote,
            'exit_fee_usdt' => $exitFee,
            'gross_usdt'    => $gross,
            'fees_usdt'     => $fees,
            'pnl_usdt'      => $net,
            'pnl_pct'       => $entryQuote > 0.0 ? $net / $entryQuote * 100.0 : 0.0,
            'exit_reason'   => $reason,
            'closed_at'     => $nowIso,
        ]);
        $this->db->insertTrade([
            'position_id' => $pid, 'mode' => $this->mode, 'symbol' => $sym, 'side' => 'SELL',
            'order_id'    => (string) (isset($r['order_id']) ? $r['order_id'] : ''),
            'client_id'   => $cid,
            'qty'         => $filled, 'price' => $exitPrice, 'quote' => $exitQuote,
            'fee_usdt'    => $exitFee,
            'fee_asset'   => (string) (isset($r['fee_asset']) ? $r['fee_asset'] : ''),
            'created_at'  => $nowIso,
        ]);
        $this->creditSell($base, $exitQuote, $filled);

        // §5 the breaker, and the global survival ladder, both see every close
        $this->recordOutcome($sym, $net, $reason, $nowIso);
        try {
            Risk::recordOutcome($this->cfg, $this->db, $net, $nowIso);
        } catch (Throwable $e) {
            Log::warn('scalp: risk outcome not recorded - ' . $e->getMessage());
        }
        Log::info('scalp: sold ' . $sym . ' (' . $reason . ') net ' . sprintf('%+.4f', $net)
            . ' = gross ' . sprintf('%+.4f', $gross) . ' - fees ' . sprintf('%.4f', $fees), [
                'position_id' => $pid, 'qty' => $filled, 'price' => $exitPrice,
                'entry_quote' => $entryQuote, 'exit_quote' => $exitQuote, 'held_sec' => $heldSec,
            ]);
        return [
            'position_id' => $pid, 'symbol' => $sym, 'reason' => $reason,
            'gross_usdt'  => $gross, 'fees_usdt' => $fees, 'net_usdt' => $net,
            'pnl_usdt'    => $net, 'held_sec' => $heldSec,
        ];
    }

    /**
     * A market order that threw: resolve it once against the exchange instead of assuming
     * it never happened, so a position is never lost to a timeout.
     * @return array|null the normalised fill, or null when the order did not happen
     */
    private function recoverOrder(string $symbol, string $clientId, string $side, Throwable $e): ?array
    {
        $code = $e instanceof BinanceException ? $e->binanceCode : 0;
        try {
            $this->db->setState(self::ST_ERROR, $side . ' ' . $symbol . ': ' . $e->getMessage());
        } catch (Throwable $ignored) {
            // state bookkeeping must never mask the order error
        }
        Log::warn('scalp ' . strtolower($side) . ' ' . $symbol . ': ' . $e->getMessage(), [
            'client_id' => $clientId, 'code' => $code,
        ]);
        try {
            $r = $this->ex->getOrder($symbol, $clientId);
        } catch (Throwable $ignored) {
            return null;
        }
        if (!is_array($r) || self::num(isset($r['qty']) ? $r['qty'] : 0.0) <= self::EPS) {
            return null;
        }
        Log::warn('scalp: ' . $side . ' ' . $symbol . ' recovered from the exchange', [
            'client_id' => $clientId, 'qty' => $r['qty'],
        ]);
        return $r;
    }

    private function markStuck(array $pos, string $reason): void
    {
        if (strtoupper((string) $pos['status']) === 'STUCK') {
            return;                             // already marked; do not log once per poll
        }
        try {
            $this->db->updatePosition((int) $pos['id'], ['status' => 'STUCK', 'exit_reason' => $reason]);
            Log::warn('scalp: position ' . $pos['id'] . ' marked STUCK (' . $reason . ')', [
                'symbol' => $pos['symbol'], 'qty' => $pos['qty'],
            ]);
        } catch (Throwable $e) {
            // nothing useful left to do
        }
    }

    /* -------------------------------------------------------------- breaker */

    /**
     * The consecutive-loss breaker (§5), applied to one closed trade.
     *
     * A net-negative close increments the streak; ANY win resets it to 0. On reaching
     * `scalp_max_consecutive_losses` the engine sets `scalp_paused` with a reason naming
     * the count and the money, and stops opening NEW positions until a human calls
     * resume(). It never clears the pause itself, and open positions keep being managed
     * to their exit while it holds.
     *
     * A break-even close (net exactly 0) is neither a win nor a loss: it does not
     * increment and does not reset.
     */
    private function recordOutcome(string $symbol, float $net, string $reason, string $nowIso): void
    {
        $streak = $this->lossStreak();
        if ($net > 0.0) {
            if ($streak !== 0) {
                $this->db->setState(self::ST_LOSSES, 0);
            }
            return;
        }
        if ($net === 0.0) {
            return;
        }
        $streak++;
        $this->db->setState(self::ST_LOSSES, $streak);
        $limit = $this->lossLimit();
        if ($streak < $limit) {
            return;
        }
        if ($this->paused()) {
            return;                             // already paused: do not restamp the reason
        }
        // What those losses actually cost, so the banner states a number and not just a count
        $lost = 0.0;
        try {
            foreach (array_slice($this->db->lastClosed($streak, $this->mode), 0, $streak) as $r) {
                $lost += self::rowF($r, 'pnl_usdt');
            }
        } catch (Throwable $e) {
            $lost = 0.0;
        }
        $msg = $streak . ' losing trades in a row (' . sprintf('%+.4f', $lost)
             . ' USDT over them), last ' . $symbol . ' ' . $reason
             . ' at ' . $nowIso . '. Entries are paused until you resume.';
        $this->db->setState(self::ST_PAUSED, '1');
        $this->db->setState(self::ST_REASON, $msg);
        $this->db->setState(self::ST_PAUSED_AT, $nowIso);
        Log::warn('scalp: PAUSED - ' . $msg, ['streak' => $streak, 'limit' => $limit]);
    }

    /* -------------------------------------------------------- loop plumbing */

    /** Current time as (fractional) UNIX seconds, from the INJECTED clock. */
    private function now(): float
    {
        try {
            $v = self::num(call_user_func($this->clock));
        } catch (Throwable $e) {
            $v = 0.0;
        }
        return $v > 0.0 ? $v : (float) time();
    }

    /** Now as an ISO-8601 UTC string, from the injected clock. */
    private function nowIso(): string
    {
        return self::isoOf($this->now());
    }

    private static function isoOf(float $ts): string
    {
        return Util::nowIso((int) floor($ts));
    }

    /** Wait, through the INJECTED sleeper (a test's sleeper advances its clock instead). */
    private function rest(float $seconds): void
    {
        try {
            call_user_func($this->sleeper, $seconds);
        } catch (Throwable $e) {
            Log::warn('scalp: sleeper failed - ' . $e->getMessage());
        }
    }

    /**
     * Ask a running loop to stop after the poll in flight, from another process: the tick
     * lock is wanted. Mirrors requestStop() for callers that hold only a Db.
     */
    public static function requestStopIn(Db $db): void
    {
        $db->setState(self::ST_STOP, '1');
    }

    /** Consume a stop request, so it ends this loop and not every future one. */
    private function clearStopRequest(): void
    {
        $this->stopFlag = false;
        try {
            $this->db->setState(self::ST_STOP, null);
        } catch (Throwable $e) {
            // the flag is a courtesy, not a dependency
        }
    }

    /** True when the loop should give the tick lock up after the poll in flight. */
    private function stopRequested(): bool
    {
        if ($this->stopFlag) {
            return true;
        }
        try {
            $v = strtolower(trim((string) $this->db->getState(self::ST_STOP, '')));
        } catch (Throwable $e) {
            return false;
        }
        return !($v === '' || $v === '0' || $v === 'false' || $v === 'no' || $v === 'off');
    }

    /** ≤ 36 chars and unique within a run: this engine places many orders a minute. */
    private function clientId(string $side, string $symbol): string
    {
        $this->seq++;
        $base = Util::clientOrderId($side, $symbol, (int) floor($this->now()));
        return substr($base . 'x' . $this->seq . Util::randomHex(2), 0, 36);
    }

    /* ------------------------------------------------------------- balances */

    /** Free quote, mirrored: ONE account() call per run, then adjusted by our own fills. */
    private function quoteFree(): float
    {
        if ($this->quoteFreeCache === null) {
            $this->loadBalances();
        }
        return $this->quoteFreeCache === null ? 0.0 : $this->quoteFreeCache;
    }

    private function baseFree(string $asset): float
    {
        if ($this->quoteFreeCache === null) {
            $this->loadBalances();
        }
        return isset($this->baseFreeCache[$asset]) ? $this->baseFreeCache[$asset] : 0.0;
    }

    private function loadBalances(): void
    {
        $this->quoteFreeCache = 0.0;
        $this->baseFreeCache  = [];
        try {
            $acct = $this->ex->account();
        } catch (Throwable $e) {
            Log::warn('scalp: account() failed - ' . $e->getMessage());
            return;
        }
        $bal   = isset($acct['balances']) && is_array($acct['balances']) ? $acct['balances'] : [];
        $quote = self::quoteAsset($this->cfg);
        foreach ($bal as $asset => $row) {
            $a    = strtoupper(trim((string) $asset));
            $free = self::num(is_array($row) ? (isset($row['free']) ? $row['free'] : 0.0) : $row);
            if ($a === $quote) {
                $this->quoteFreeCache = $free;
            } else {
                $this->baseFreeCache[$a] = $free;
            }
        }
    }

    private function creditBuy(string $symbol, float $quoteSpent, float $baseGained): void
    {
        $this->quoteFree();
        $this->quoteFreeCache = max(0.0, (float) $this->quoteFreeCache - $quoteSpent);
        $base = self::baseAsset($symbol, self::quoteAsset($this->cfg), $this->infoFor($symbol));
        if ($base !== '') {
            $this->baseFreeCache[$base] = $this->baseFree($base) + $baseGained;
        }
    }

    private function creditSell(string $base, float $quoteGained, float $baseSold): void
    {
        $this->quoteFree();
        $this->quoteFreeCache = (float) $this->quoteFreeCache + $quoteGained;
        if ($base !== '') {
            $this->baseFreeCache[$base] = max(0.0, $this->baseFree($base) - $baseSold);
        }
    }

    /* ------------------------------------------------------------ §8 storage */

    private function openRun(float $startTs): int
    {
        try {
            return (int) $this->db->insertScalpRun([
                'started_at' => self::isoOf($startTs),
                'mode'       => $this->mode,
                'polls'      => 0, 'entries' => 0, 'exits' => 0,
                'gross_usdt' => 0.0, 'fees_usdt' => 0.0, 'net_usdt' => 0.0,
            ]);
        } catch (Throwable $e) {
            Log::warn('scalp: could not open a run row - ' . $e->getMessage());
            return 0;
        }
    }

    private function closeRun(int $id, array $totals, string $note): void
    {
        if ($id <= 0) {
            return;
        }
        try {
            $this->db->updateScalpRun($id, [
                'ended_at'   => $this->nowIso(),
                'polls'      => (int) $totals['polls'],
                'entries'    => (int) $totals['entries'],
                'exits'      => (int) $totals['exits'],
                'gross_usdt' => self::fin((float) $totals['gross']),
                'fees_usdt'  => self::fin((float) $totals['fees']),
                'net_usdt'   => self::fin((float) $totals['net']),
                'note'       => $note,
            ]);
        } catch (Throwable $e) {
            // the run row is a record, not a dependency
            Log::warn('scalp: could not close run row ' . $id . ' - ' . $e->getMessage());
        }
    }

    /** @return array the union of both run-summary shapes, so either caller finds its keys */
    private function summary(
        string $status,
        string $summaryText,
        int $runId,
        array $totals,
        string $note,
        array $universe,
        array $refusals,
        bool $logged = false
    ): array {
        if (!$logged && $status !== 'ok') {
            Log::warn('scalp: ' . $summaryText);
        }
        return [
            'status'     => $status,
            'summary'    => $summaryText,
            'run_id'     => $runId,
            'polls'      => (int) $totals['polls'],
            'entries'    => (int) $totals['entries'],
            'exits'      => (int) $totals['exits'],
            'gross'      => self::fin((float) $totals['gross']),
            'fees'       => self::fin((float) $totals['fees']),
            'net'        => self::fin((float) $totals['net']),
            'gross_usdt' => self::fin((float) $totals['gross']),
            'fees_usdt'  => self::fin((float) $totals['fees']),
            'net_usdt'   => self::fin((float) $totals['net']),
            'blocked'    => $note,
            'note'       => $note,
            'universe'   => $universe,
            'paused'     => $this->paused(),
            'streak'     => $this->lossStreak(),
            'refusals'   => $refusals,
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /** Per-side fee percent: the live rate when the bot has read one, else the config. */
    public static function feePct(array $cfg, Db $db): float
    {
        try {
            $live = $db->getState('fee_pct_live', null);
        } catch (Throwable $e) {
            $live = null;
        }
        if ($live !== null && is_numeric($live)) {
            return self::num($live, 0.1);
        }
        return self::cfgFloat($cfg, 'fee_pct', 0.1);
    }

    private static function modeOf(array $cfg, ExchangeInterface $ex): string
    {
        try {
            $m = strtolower(trim((string) $ex->mode()));
        } catch (Throwable $e) {
            $m = '';
        }
        if ($m !== '') {
            return $m;
        }
        $c = self::cfgMode($cfg);
        return $c === null ? 'paper' : $c;
    }

    /** Configured mode, or null (= every mode) when unset. */
    private static function cfgMode(array $cfg): ?string
    {
        $m = strtolower(trim((string) (isset($cfg['mode']) ? $cfg['mode'] : '')));
        return $m === '' ? null : $m;
    }

    private static function quoteAsset(array $cfg): string
    {
        $q = strtoupper(trim((string) (isset($cfg['quote_asset']) ? $cfg['quote_asset'] : 'USDT')));
        return $q === '' ? 'USDT' : $q;
    }

    private static function baseAsset(string $symbol, string $quote, array $info): string
    {
        if (isset($info['base']) && trim((string) $info['base']) !== '') {
            return strtoupper(trim((string) $info['base']));
        }
        $s = strtoupper($symbol);
        $q = strtoupper($quote);
        if ($q !== '' && strlen($s) > strlen($q) && substr($s, -strlen($q)) === $q) {
            return substr($s, 0, strlen($s) - strlen($q));
        }
        return '';
    }

    /** @param mixed $v */
    private static function num($v, float $fallback = 0.0): float
    {
        if (is_bool($v) || $v === null || !is_numeric($v)) {
            return $fallback;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : $fallback;
    }

    private static function rowF(array $row, string $key): float
    {
        return self::num(isset($row[$key]) ? $row[$key] : 0.0);
    }

    /** No scalp number is ever NaN or infinite, and none carries float noise into the panel. */
    private static function fin(float $v): float
    {
        return is_finite($v) ? round($v, 8) : 0.0;
    }

    /** Config float, falling back to the §6 default and then to $default. */
    private static function cfgFloat(array $cfg, string $key, float $default): float
    {
        if (isset($cfg[$key]) && is_numeric($cfg[$key])) {
            $v = (float) $cfg[$key];
            if (is_finite($v)) {
                return $v;
            }
        }
        if (isset(self::DEFAULTS[$key]) && is_numeric(self::DEFAULTS[$key])) {
            return (float) self::DEFAULTS[$key];
        }
        return $default;
    }

    private static function cfgBool(array $cfg, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $cfg)) {
            return $default;
        }
        $v = $cfg[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((float) $v) != 0.0;
        }
        $s = strtolower(trim((string) $v));
        return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'off');
    }
}
