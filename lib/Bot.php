<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Binance.php';
require_once __DIR__ . '/Exchange.php';
require_once __DIR__ . '/Indicators.php';
require_once __DIR__ . '/Strategy.php';
require_once __DIR__ . '/Risk.php';
// the scalp engine (docs/DESIGN-SCALP.md) and its movers board are optional in exactly the
// same way: a checkout without them runs every engine it ran before, and `engine = scalp`
// then reports the missing file instead of fatally erroring on a class that is not there.
if (is_file(__DIR__ . '/Movers.php')) {
    require_once __DIR__ . '/Movers.php';
}
if (is_file(__DIR__ . '/Scalper.php')) {
    require_once __DIR__ . '/Scalper.php';
}

/**
 * Internal control-flow exception used by Bot::tick(): ends the current tick
 * with the given status ('skipped' | 'error') and summary. Never escapes tick().
 */
final class BotAbort extends RuntimeException
{
    /** @var string */
    public $status;

    public function __construct(string $status, string $summary)
    {
        parent::__construct($summary);
        $this->status = $status;
    }
}

/**
 * The once-per-minute tick (DESIGN.md §10): reconciliation, equity, survival
 * layer, position management, entries. One order per tick at most; every order
 * goes through the `orders` table (SENDING → DONE | FAILED | UNKNOWN) with a
 * deterministic client id so a crashed tick can be reconciled instead of re-sent.
 */
final class Bot
{
    const SYMBOL_INFO_TTL_SEC = 21600;   // 6 h
    const STUCK_RETRY_MINUTES = 15;
    const NET_ERRORS_TO_PAUSE = 3;
    const NET_PAUSE_MINUTES   = 15;
    const KLINES_15M          = 320;
    const KLINES_1H           = 260;
    const RECONCILE_MISSING_RATIO = 0.9;
    /** Block reasons that must take an engine's whole ladder off the book (the removed grid/pmm contract). */
    /** A tick stops starting slow optional work after this many ms. */
    const TICK_TIME_BUDGET_MS = 40000;

    /**
     * THE WHOLE WRITE SURFACE OF LEARNING INSIDE THE BOT (DESIGN-LEARNING.md §2).
     * Capture may write these columns of `observations` and nothing else: every insert and
     * every resolve below is filtered through these two lists before it reaches
     * `Db::insertObservation()` / `Db::resolveObservation()` (which filter again), so no code
     * path here can reach position size, take-profit, stop-loss, a sleeve budget or a
     * kill-switch state key. Capture reads the tick's own data and writes one table plus the
     * `obs_cycle_cursor` bookmark; it never writes config.
     */
    const OBS_COLUMNS = [
        'ts', 'mode', 'engine', 'symbol', 'decision', 'skip_reason', 'score', 'threshold',
        'features', 'position_id', 'cycle_id', 'outcome', 'pnl_usdt', 'pnl_pct',
        'exit_reason', 'held_minutes', 'resolved_at',
    ];
    /** The `symbol` of an account-wide refusal, which belongs to no single symbol. */
    const OBS_ALL_SYMBOLS = '*';
    /** How long an unchanged engine refusal is recorded only once, in minutes. */
    const OBS_ENGINE_SKIP_REPEAT_MIN = 60;
    /** Columns resolution may set. A resolve NEVER rewrites the captured conditions. */
    const OBS_RESOLVE_COLUMNS = ['outcome', 'pnl_usdt', 'pnl_pct', 'exit_reason', 'held_minutes', 'resolved_at', 'cycle_id'];
    /** Minutes of slack when matching a cycle to the observation of the buy it closed. */
    const OBS_CYCLE_MATCH_MIN = 2.0;

    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var ExchangeInterface */
    private $ex;
    /** @var int|null */
    private $fixedNowMs;
    /** @var string paper | demo | testnet | live */
    private $mode;
    /** @var array symbol => parsed symbol info */
    private $info = [];
    /** @var float per-side fee in percent used for sizing */
    private $feePct;
    /** @var string signal | grid | pmm | scalp */
    private $engine;
    /** @var EngineOrders[] one per symbol|engine, built on demand */
    private $engineOrdersCache = [];

    // ---- per-tick scratch
    /** @var int */
    private $ordersThisTick = 0;
    /** @var bool */
    private $apiErrorThisTick = false;
    /** @var bool */
    private $netErrorThisTick = false;
    /** @var string */
    private $noTradeReason = '';
    /** @var float|null */
    private $equity = null;
    /** @var float|null */
    private $quoteFree = null;
    /** @var string */
    private $posSummary = '';
    /** @var string[] */
    private $notes = [];
    /** @var array symbol => ['bid'=>float,'ask'=>float] read this tick */
    private $books = [];
    /** @var float microtime(true) at the start of the current tick */
    private $tickStart = 0.0;
    /** @var array symbol => captured observation of the entry evaluation running now */
    private $obsPending = [];
    /** @var array "engine|symbol" => lot count sampled before this tick's sync (capture only) */
    private $obsLotsBefore = [];
    /** @var array|null learned score-component deltas for this tick (null = none applied) */
    private $learnWeights = null;
    /** @var bool whether learnWeights() has already run this tick */
    private $learnWeightsLoaded = false;

    public function __construct(array $cfg, Db $db, ExchangeInterface $ex, ?int $nowMs = null)
    {
        $this->cfg        = $cfg + self::defaults();
        $this->db         = $db;
        $this->ex         = $ex;
        $this->fixedNowMs = $nowMs;
        $this->mode       = $ex->mode();
        $this->feePct     = max(0.0, (float) $this->cfg['fee_pct']);
        $this->engine     = Risk::engineName($this->cfg);
        // the single `engine_symbol`
        // is not part of the picture at all
    }

    /** Minimal defaults so a partial config (tests) still works; the real defaults live in config.php. */
    private static function defaults(): array
    {
        return [
            'mode'                => 'paper',
            'enabled'             => false,
            'symbols'             => ['SOLUSDT', 'ETHUSDT', 'XRPUSDT', 'DOGEUSDT', 'BNBUSDT', 'ADAUSDT'],
            'quote_asset'         => 'USDT',
            'trade_usdt'          => 6.5,
            'take_profit_pct'     => 1.0,
            'take_profit_max_pct' => 2.0,
            'stop_loss_pct'       => 0.7,
            'candle_interval'     => '15m',
            'trend_interval'      => '1h',
            'fee_pct'             => 0.1,
            'engine'              => 'signal',
            'allow_live_engines'  => false,
            'engine_symbol'       => 'DOGEUSDT',
            'engine_max_orders'   => 12,
            // DESIGN-LEARNING.md §5: capture on by default, feedback off by default
            'learning_enabled'    => true,
            'learning_apply'      => false,
        ];
    }

    /* ================================================================ public API */

    /**
     * Runs one tick. Never throws.
     * @return array{status:string, summary:string, ms:int}  status: ok | skipped | halted | error
     */
    public function tick(): array
    {
        $start = microtime(true);
        $this->tickStart        = $start;
        $this->ordersThisTick   = 0;
        $this->apiErrorThisTick = false;
        $this->netErrorThisTick = false;
        $this->noTradeReason    = '';
        $this->equity           = null;
        $this->quoteFree        = null;
        $this->posSummary       = '';
        $this->notes            = [];
        $this->books            = [];
        $this->obsPending       = [];
        $this->obsLotsBefore    = [];
        $this->learnWeights     = null;
        $this->learnWeightsLoaded = false;

        try {
            $this->db->setState('last_tick_at', $this->nowIso());
        } catch (Throwable $e) {
            // continue; the tick will report through the return value
        }

        $status  = 'ok';
        $summary = '';
        try {
            $status  = $this->runTick();
            $summary = $this->buildSummary();
        } catch (BotAbort $e) {
            $status  = $e->status;
            $summary = $e->getMessage();
            if ($this->noTradeReason === '') {
                $this->noTradeReason = $status === 'skipped' ? 'api_paused' : 'tick_error';
            }
        } catch (Throwable $e) {
            $status  = 'error';
            $summary = 'exception: ' . get_class($e) . ': ' . $e->getMessage();
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'tick_error';
            }
            try {
                Log::error('tick: ' . get_class($e) . ': ' . $e->getMessage(), [
                    'file'  => basename($e->getFile()) . ':' . $e->getLine(),
                    'trace' => substr($e->getTraceAsString(), 0, 1500),
                ]);
            } catch (Throwable $inner) {
                // logging must never break the tick
            }
        }

        // ---- learning (DESIGN-LEARNING.md §2). Entirely gated on `learning_enabled`, never
        //      throws, and writes only the `observations` table: with learning off this block
        //      is three cheap boolean tests and the tick is exactly what it was before.
        try {
            if ($this->learningOn()) {
                // an aborted tick can leave a captured evaluation unwritten; it is still evidence -
                // unless an order already went to the exchange this tick, in which case the entry
                // may well have filled (reconcile books it next tick) and flushing would file a
                // live entry in the control group as `not_taken`, permanently: skipped rows are
                // resolved at insert and Db::resolveObservation() is never forced here. One tick
                // of control rows is the cheaper loss.
                if ($status !== 'ok' && $this->ordersThisTick > 0) {
                    $this->obsPending = [];
                }
                $this->obsFlush('', null, $status === 'ok' ? '' : 'tick_' . $status);
                $this->obsResolvePositions();
            }
        } catch (Throwable $e) {
            // observation capture is never allowed to affect the trading result
        }

        if ($status === 'ok' && $this->apiErrorThisTick) {
            $status = 'error';
        }
        if ($status !== 'skipped') {
            try {
                // retention is measured from THIS tick's clock, not the wall clock: the rows
                // the tick just wrote (equity, sleeve_equity, signals) carry $this->nowIso(),
                // so a wall-clock cutoff would delete them whenever the two clocks differ.
                $this->db->prune(30, 365, $this->nowSec());
            } catch (Throwable $e) {
                // retention is best effort
            }
        }

        $ms = (int) round((microtime(true) - $start) * 1000);
        try {
            $this->readUsedWeight();
            if (!$this->netErrorThisTick && $status !== 'skipped') {
                $this->db->setState('net_errors', 0);
            }
            if ($status === 'ok' || $status === 'halted') {
                $this->db->setState('api_error', '');
            }
            $this->db->setState('last_tick_status', $status);
            $this->db->setState('last_tick_ms', $ms);
            if ($status !== 'skipped') {
                $this->db->setState('no_trade_reason', $this->noTradeReason);
            }
            Log::info('tick ' . $status . ': ' . $summary, [
                'status'      => $status,
                'equity'      => $this->equity,
                'quote_free'  => $this->quoteFree,
                'position'    => $this->posSummary,
                'used_weight' => $this->db->getState('used_weight', null),
                'decision'    => $this->noTradeReason,
                'orders'      => $this->ordersThisTick,
                'ms'          => $ms,
            ]);
        } catch (Throwable $e) {
            // never fail the caller because of bookkeeping
        }

        return ['status' => $status, 'summary' => $summary, 'ms' => $ms];
    }

    /**
     * Sells the OPEN position (or, when none is open, the most recent STUCK one) at market.
     * Errors are logged and applied to the error policy; returns the closed position row
     * (with an extra `order` key holding the normalised fill) or null when nothing was sold.
     */
    public function closePosition(string $reason): ?array
    {
        $pos = $this->db->openPosition();
        if ($pos === null) {
            $pos = $this->positionWhere(self::STUCK_WHERE, []);
        }
        if ($pos === null) {
            return null;
        }
        try {
            $this->ensureSymbolInfo();
            $acct = $this->ex->account();
            $book = $this->ex->bookTicker((string) $pos['symbol']);
            $bid  = (float) ($book['bid'] ?? 0.0);
            if ($bid <= 0.0) {
                Log::warn('closePosition: no bid price', ['symbol' => $pos['symbol'], 'reason' => $reason]);
                return null;
            }
            return $this->sellPosition($pos, $reason, $acct, $bid);
        } catch (BinanceException $e) {
            $this->handleApiError($e, 'closePosition');
            return null;
        }
    }

    /** Emergency exit: sells the position and disables new entries. */
    public function panicSell(): array
    {
        $closed = null;
        $error  = '';
        try {
            $closed = $this->closePosition('panic');
        } catch (Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
            Log::error('panicSell: ' . $error);
        }
        $this->disableEntries('panic_sell');
        if ($closed !== null) {
            $msg = 'Sold ' . $closed['symbol'] . ' (' . Util::money((float) $closed['pnl_usdt']) . ' USDT). Trading disabled.';
        } elseif ($error !== '') {
            $msg = 'Sell failed: ' . $error . '. Trading disabled.';
        } else {
            $msg = 'No position to sell (or the sell was not possible - see log). Trading disabled.';
        }
        Log::warn('panicSell: ' . $msg);
        return ['ok' => $closed !== null, 'message' => $msg, 'position' => $closed];
    }

    /**
     * Pending orders (SENDING/UNKNOWN) against the exchange, then exchange balances
     * against the local position. May throw BinanceException.
     */
    public function reconcile(): void
    {
        $this->ensureSymbolInfo();
        $this->reconcileOrders();
        $acct   = $this->ex->account();
        $prices = $this->ex->prices($this->valuedSymbols());
        $this->reconcileBalances($acct, $this->analyseBalances($acct, $prices));
    }

    /**
     * Runs $fn under an exclusive non-blocking lock on data/tick.lock.
     * @return array ['status'=>'skipped', ...] when another tick holds the lock.
     */
    public static function runLocked(callable $fn): array
    {
        $root = defined('TRADER_ROOT') ? TRADER_ROOT : dirname(__DIR__);
        $dir  = $root . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $path = $dir . '/tick.lock';
        $fp   = @fopen($path, 'c');
        if ($fp === false) {
            return ['status' => 'error', 'summary' => 'cannot open lock file ' . $path, 'ms' => 0];
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return ['status' => 'skipped', 'summary' => 'tick already running (lock busy)', 'ms' => 0];
        }
        try {
            $r = $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        if (is_array($r)) {
            if (!isset($r['status'])) {
                $r['status'] = 'ok';
            }
            if (!isset($r['summary'])) {
                $r['summary'] = '';
            }
            if (!isset($r['ms'])) {
                $r['ms'] = 0;
            }
            return $r;
        }
        return ['status' => 'ok', 'summary' => is_scalar($r) ? (string) $r : '', 'ms' => 0];
    }

    /* ================================================================ the tick */

    /** @return string final status (ok | halted); aborts throw BotAbort */
    private function runTick(): string
    {
        // ---- 1. API pause, time sync, symbol info
        $pausedUntil = (string) $this->db->getState('api_paused_until', '');
        if ($pausedUntil !== '') {
            $ts = Util::isoToTs($pausedUntil);
            if ($ts !== null && $ts > $this->nowSec()) {
                $this->noTradeReason = 'api_paused';
                throw new BotAbort('skipped', 'api paused until ' . $pausedUntil . ' (' . (string) $this->db->getState('api_error', '') . ')');
            }
            $this->db->setState('api_paused_until', '');
        }
        $this->step('sync_time', function (): void {
            $offset = $this->ex->syncTime();
            $this->db->setState('time_offset_ms', $offset);
        });
        $this->step('symbol_info', function (): void {
            $this->loadSymbolInfo(false);
        });

        // ---- 2. reconcile
        $this->step('reconcile_orders', function (): void {
            $this->reconcileOrders();
        });
        $acct = $this->step('account', function (): array {
            return $this->ex->account();
        });
        $prices = $this->step('prices', function (): array {
            return $this->ex->prices($this->valuedSymbols());
        });
        // keep the panel's step value / required size fresh even while paused, halted or
        // holding a position — the prices were fetched above, so this costs no extra weight
        $pxPatch = [];
        foreach ($this->metricSymbols() as $pxSym) {
            if (isset($prices[$pxSym]) && (float) $prices[$pxSym] > 0.0) {
                $pxPatch[$pxSym] = ['price' => (float) $prices[$pxSym]];
            }
        }
        $this->mergeSymbolMetrics($pxPatch);
        $this->applyFeeFromAccount($acct);
        $bal = $this->analyseBalances($acct, $prices);
        $this->reconcileBalances($acct, $bal);
        $bal = $this->analyseBalances($acct, $prices);   // positions may have changed

        // ---- 3. equity
        $this->equity    = $bal['equity'];
        $this->quoteFree = $bal['quote_free'];
        $this->db->insertEquity($bal['equity'], $bal['quote_free'], $bal['position_value'], $bal['dust_value']);
        $this->readUsedWeight();

        $open  = $this->db->openPosition();
        // Scalp rows are excluded here for the same reason Db::openPosition() excludes them: the
        // halt branch below routes $stuck through retryStuck() -> sellPosition() -> closeFromFill(),
        // which writes pnl_usdt but neither gross_usdt nor fees_usdt and never reaches
        // Scalper::recordOutcome() - so a scalp loss would miss both §2's gross/fees/net verdict
        // and §5's consecutive-loss breaker. Open scalp rows go to runScalpLoop() instead, which
        // is what the comment on the halt branch below already says should happen.
        $stuck = $open === null ? $this->positionWhere(self::STUCK_WHERE, []) : null;
        if ($open !== null && $bal['equity'] <= 0.0) {
            // an empty balance snapshot with a tracked position is not a kill-switch case, it is bad data
            Log::error('tick: equity is zero while a position is open; skipping survival check', ['symbol' => $open['symbol']]);
            $this->noTradeReason = 'equity_unavailable';
            throw new BotAbort('error', 'equity unavailable while position ' . $open['symbol'] . ' is open');
        }

        // ---- 4. survival layer
        // read the halted flag BEFORE the check: survivalCheck() writes halted=1 itself on a fresh breach
        $already = ((string) $this->db->getState('halted', '0')) === '1';
        // Open scalp positions are money at risk too. They live in the same `positions` table
        // (DESIGN-SCALP.md §8) but Db::openPosition() skips them on purpose, because the signal
        // engine's whole exit path is built on "THE one position". Counting them here is what
        // keeps survivalCheck() returning `halt` while any are open, which is what gets them
        // managed to an exit in the halt branch below instead of stranded. Read only when scalp
        // is the selected engine, because it is the only engine that can actually close them:
        // under any other engine this is [] and the call below is the one it has always been.
        $scalpOpen = $this->engine === 'scalp' ? $this->scalpOpenRows() : [];
        $sv = Risk::survivalCheck($this->cfg, $this->db, $bal['equity'], $open !== null || $stuck !== null || $scalpOpen !== [], $bal['exchange_has_base']);
        $action = (string) ($sv['action'] ?? 'none');
        $svReason = (string) ($sv['reason'] ?? '');
        if ($action === 'halt') {
            $reason  = $svReason !== '' ? $svReason : 'equity_floor';
            // the kill switch takes every resting engine order off the book BEFORE it liquidates
            $cancelled = $this->cancelAllEngineOrders($reason);
            if ($cancelled > 0) {
                $this->notes[] = $cancelled . ' engine order(s) cancelled';
            }
            if ($open !== null) {
                $closed = $this->closePosition($reason);
                $this->notes[] = $closed !== null ? 'position closed' : 'position NOT closed (see log)';
            } elseif ($stuck !== null) {
                $this->retryStuck($stuck, $acct);
            }
            if ($scalpOpen !== []) {
                // The kill switch wins - no scalp ENTRY can happen, because Scalper::entryBlock()
                // reads Risk::entryBlockReason() itself and reports `halted` - but an open
                // position is still managed to its exit (DESIGN-SCALP.md §5: abandoning one would
                // be worse than any losing streak, and DESIGN.md §9 wants a stop on every
                // position). Running the loop rather than market-selling from here is deliberate:
                // the loop keeps the per-position gross_usdt / fees_usdt bookkeeping §2's
                // verdict depends on, which a bare sell through closeFromFill() would not.
                $this->notes[] = count($scalpOpen) . ' open scalp position(s) still managed under the halt';
                // exits only, explicitly: Scalper::entryBlock() reads the same kill switch and
                // already reports `halted`, so this is belt and braces - but a branch whose only
                // job is to carry positions out should not depend on a second class agreeing.
                $this->runScalpLoop(true);
            }
            if (!$already) {
                $this->notes[] = 'KILL SWITCH ' . $reason;
                $this->halt($reason);
            }
            $this->noTradeReason = 'halted:' . $reason;
            return 'halted';
        }
        if ($action === 'no_entry' && $svReason !== '') {
            $this->notes[] = 'survival: ' . $svReason;
        }

        // DESIGN-SCALP.md §4: "an open position is ALWAYS managed even when entries are paused".
        // The signal branch below returns before the scalp branch, so without this a position
        // left open by switching `engine` away from scalp would sit with no take-profit, no stop
        // and no max-hold until the user noticed. Exits only: the selected engine owns the free
        // quote here, so no new scalp entry may be opened against it. The halt branch above
        // already ran the loop and returned, so this cannot run twice in one tick.
        if ($this->engine !== 'scalp') {
            $scalpStranded = $this->scalpOpenRows();
            if ($scalpStranded !== [] && ($this->engine !== 'scalp' || $this->scalpExitsOnlyAvailable())) {
                $this->notes[] = count($scalpStranded) . ' open scalp position(s) managed (exits only)';
                // the loop reports the SCALP engine's no-trade reason, which is not the engine
                // that owns this tick: keep whatever the owning path has set or will set
                $keepReason = $this->noTradeReason;
                $this->runScalpLoop(true);
                $this->noTradeReason = $keepReason;
            }
        }

        // ---- 5-7. the scalp engine runs its own bounded loop (DESIGN-SCALP.md §4)
        if ($this->engine === 'scalp') {
            $this->runScalpTick();
            return ((string) $this->db->getState('halted', '0')) === '1' ? 'halted' : 'ok';
        }

        // ---- 5. position management
        if ($open !== null) {
            $this->managePosition($open, $acct);
        } elseif ($stuck !== null) {
            $this->retryStuck($stuck, $acct);
        }

        // ---- 6. entries
        $open  = $this->db->openPosition();
        $stuck = $open === null ? $this->positionWhere(self::STUCK_WHERE, []) : null;
        if ($open !== null || $stuck !== null) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = $open !== null ? 'position_open' : 'position_stuck';
            }
        } elseif ($this->ordersThisTick > 0) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'order_sent_this_tick';
            }
        } else {
            $this->evaluateEntries($bal['quote_free'], $bal['equity']);
        }

        // ---- 7. wrap up (prune, state, log in tick())
        return ((string) $this->db->getState('halted', '0')) === '1' ? 'halted' : 'ok';
    }

    /* ================================================================ step 2: reconcile */

    private function reconcileOrders(): void
    {
        foreach ($this->db->pendingOrders() as $o) {
            $cid  = (string) $o['client_id'];
            $sym  = strtoupper((string) $o['symbol']);
            $side = strtoupper((string) $o['side']);
            if ((string) $o['mode'] !== $this->mode) {
                continue;   // belongs to another mode's exchange; left alone
            }
            $r = $this->ex->getOrder($sym, $cid);
            if ($r === null) {
                $this->db->updateOrder($cid, ['status' => 'FAILED', 'updated_at' => $this->nowIso(), 'raw' => json_encode(['error' => 'not found on exchange during reconcile'])]);
                Log::warn('reconcile: order not found on exchange, marked FAILED', ['client_id' => $cid, 'symbol' => $sym, 'side' => $side]);
                continue;
            }
            $st  = strtoupper((string) ($r['status'] ?? 'FILLED'));
            $qty = (float) ($r['qty'] ?? 0.0);
            if ($st === 'NEW' || $st === 'PARTIALLY_FILLED' || $st === 'PENDING_NEW') {
                $this->db->updateOrder($cid, ['status' => 'UNKNOWN', 'updated_at' => $this->nowIso()]);
                Log::warn('reconcile: order still ' . $st . ', will check again', ['client_id' => $cid, 'symbol' => $sym]);
                continue;
            }
            if ($qty <= 0.0) {
                $this->db->updateOrder($cid, ['status' => 'FAILED', 'updated_at' => $this->nowIso(), 'raw' => $this->encodeResult($r)]);
                Log::warn('reconcile: order ' . ($st !== '' ? $st : 'unfilled') . ' with no executed quantity, marked FAILED', ['client_id' => $cid, 'symbol' => $sym, 'side' => $side]);
                continue;
            }
            if ($st !== 'FILLED' && $st !== '') {
                // EXPIRED / EXPIRED_IN_MATCH / CANCELED with executedQty > 0: base really moved, book the fill
                Log::warn('reconcile: order ' . $st . ' but partially executed, treated as a fill', ['client_id' => $cid, 'symbol' => $sym, 'side' => $side, 'qty' => $qty]);
            }
            $r['client_id'] = $cid;
            if ($side === 'BUY') {
                $pid = $this->tradePositionId($cid);
                if ($pid === null) {
                    $tp  = $this->effectiveTpPct(null);
                    $pid = $this->openPositionFromFill($sym, $r, null, 'reconciled', $tp);
                    Log::warn('reconcile: BUY was filled, position created from exchange data', ['client_id' => $cid, 'symbol' => $sym, 'position_id' => $pid]);
                }
                $this->markDone($cid, $r, $pid);
            } else {
                $pid = isset($o['position_id']) && $o['position_id'] !== null ? (int) $o['position_id'] : null;
                $pos = $pid !== null ? $this->positionWhere('id = ?', [$pid]) : null;
                if ($pos !== null && ($pos['status'] === 'OPEN' || $pos['status'] === 'STUCK')) {
                    $this->closeFromFill($pos, $r, 'reconciled');
                    Log::warn('reconcile: SELL was filled, position closed from exchange data', ['client_id' => $cid, 'symbol' => $sym, 'position_id' => $pid]);
                } elseif ($this->tradePositionId($cid) === null) {
                    $this->db->insertTrade($this->tradeRow($pid, $sym, 'SELL', $r));
                }
                $this->markDone($cid, $r, null);
            }
        }
    }

    /** orders row → DONE; the raw column is filled only when the exchange left none (paper keeps its own). */
    private function markDone(string $cid, array $r, ?int $positionId): void
    {
        $fields = ['status' => 'DONE', 'updated_at' => $this->nowIso()];
        if ($positionId !== null) {
            $fields['position_id'] = $positionId;
        }
        if ($this->orderRaw($cid) === '') {
            $fields['raw'] = $this->encodeResult($r);
        }
        $this->db->updateOrder($cid, $fields);
    }

    /**
     * Balance snapshot: quote, per-symbol base value, equity split.
     * @return array{quote_free:float, quote_total:float, equity:float, position_value:float, dust_value:float, exchange_has_base:bool, bases:array}
     */
    private function analyseBalances(array $acct, array $prices): array
    {
        $quote    = (string) $this->cfg['quote_asset'];
        $balances = isset($acct['balances']) && is_array($acct['balances']) ? $acct['balances'] : [];
        $qFree    = isset($balances[$quote]) ? (float) ($balances[$quote]['free'] ?? 0.0) : 0.0;
        $qLocked  = isset($balances[$quote]) ? (float) ($balances[$quote]['locked'] ?? 0.0) : 0.0;

        $open  = $this->db->openPosition();
        $stuck = $this->positionWhere("status = 'STUCK'", []);
        $tracked = [];
        if ($open !== null) {
            $tracked[(string) $open['symbol']] = $open;
        }
        if ($stuck !== null && !isset($tracked[(string) $stuck['symbol']])) {
            $tracked[(string) $stuck['symbol']] = $stuck;
        }
        // Scalp positions are tracked positions: they are rows in this same `positions` table
        // (DESIGN-SCALP.md §8), merely ones Db::openPosition() skips because the signal engine
        // manages exactly one. Leaving them out of $tracked would make the base balance of the
        // very first filled scalp buy look like an UNTRACKED position, and reconcileBalances()
        // would HALT the bot with `untracked_position` the moment the engine worked - the same
        // trap the grid/pmm inventory branch below already avoids. §4 allows only one open
        // position per symbol, but the quantities are summed anyway (by position id, so the
        // STUCK row the lookup above may already have found is never counted twice) and a
        // hand-edited database cannot under-report the holding and over-report the dust.
        $trackedIds = [];
        foreach ([$open, $stuck] as $known) {
            if ($known !== null && isset($known['id'])) {
                $trackedIds[(int) $known['id']] = true;
            }
        }
        foreach ($this->scalpOpenRows() as $sRow) {
            $sId  = isset($sRow['id']) ? (int) $sRow['id'] : 0;
            $sSym = isset($sRow['symbol']) ? strtoupper(trim((string) $sRow['symbol'])) : '';
            if ($sSym === '' || $sSym === $quote || ($sId !== 0 && isset($trackedIds[$sId]))) {
                continue;
            }
            $trackedIds[$sId] = true;
            if (isset($tracked[$sSym])) {
                $tracked[$sSym]['qty'] = (float) $tracked[$sSym]['qty'] + max(0.0, (float) $sRow['qty']);
                continue;
            }
            $tracked[$sSym] = $sRow;
        }

        $posValue = 0.0;
        $dustValue = 0.0;
        $hasBase = false;
        $bases = [];
        $seen = [];
        $symbols = $this->symbols();
        foreach (array_keys($tracked) as $sym) {
            if (!in_array($sym, $symbols, true)) {
                $symbols[] = $sym;   // a position off the watchlist must still count towards equity
            }
        }
        foreach ($symbols as $sym) {
            if (!isset($this->info[$sym])) {
                continue;
            }
            $info = $this->info[$sym];
            $base = (string) ($info['base'] ?? '');
            if ($base === '' || $base === $quote || isset($seen[$base])) {
                continue;
            }
            $seen[$base] = true;
            $isEngine = isset($engineSyms[$sym]);
            $free   = isset($balances[$base]) ? (float) ($balances[$base]['free'] ?? 0.0) : 0.0;
            $locked = isset($balances[$base]) ? (float) ($balances[$base]['locked'] ?? 0.0) : 0.0;
            $total  = $free + $locked;
            $price  = isset($prices[$sym]) ? (float) $prices[$sym] : 0.0;
            $bookBid = $this->bidOf($sym);
            if ($isEngine && $bookBid > 0.0) {
                $price = $bookBid;
            }
            $value  = $price > 0.0 ? $total * $price : 0.0;
            $minNotional = (float) ($info['minNotional'] ?? 0.0);
            $row = ['symbol' => $sym, 'base' => $base, 'free' => $free, 'total' => $total, 'price' => $price, 'value' => $value, 'tracked' => isset($tracked[$sym]), 'untracked' => false, 'untracked_big' => false];
            if ($total > 0.0 && $price > 0.0) {
                if (isset($tracked[$sym])) {
                    $pq = (float) $tracked[$sym]['qty'];
                    $posValue  += min($pq, $total) * $price;
                    $dustValue += max(0.0, $total - $pq) * $price;
                } elseif ($isEngine) {
                    // engine inventory: held on purpose, so it is neither dust nor an untracked
                    // position — counting it as one would halt the bot the moment a rung fills
                    $posValue += $value;
                } else {
                    $dustValue += $value;
                    // Whether this is big enough to HALT on cannot be decided here: the floor
                    // scales with equity, and equity is only complete once this loop has summed
                    // every position and every crumb. Mark it a candidate and settle it below.
                    $row['untracked'] = true;
                }
                if (!$isEngine && $minNotional > 0.0 && $value >= $minNotional) {
                    $hasBase = true;
                }
            }
            $bases[$sym] = $row;
        }

        // Second pass: now that equity is known, decide which untracked balances are actually
        // worth halting over. See untrackedHaltFloor() - minNotional is a property of the SYMBOL,
        // so on its own it let 1.15 USDT of leftover DOGE shut down a 5000 USDT account.
        $equity = $qFree + $qLocked + $posValue + $dustValue;
        foreach ($bases as $sym => $row) {
            if (empty($row['untracked'])) {
                continue;
            }
            $mn = isset($this->info[$sym]['minNotional']) ? (float) $this->info[$sym]['minNotional'] : 0.0;
            if ($mn > 0.0 && (float) $row['value'] >= $this->untrackedHaltFloor($mn, $equity)) {
                $bases[$sym]['untracked_big'] = true;
            }
        }

        return [
            'quote_free'        => $qFree,
            'quote_total'       => $qFree + $qLocked,
            'equity'            => $equity,
            'position_value'    => $posValue,
            'dust_value'        => $dustValue,
            'exchange_has_base' => $hasBase,
            'bases'             => $bases,
        ];
    }

    /**
     * The value an UNTRACKED base balance has to reach before it halts the bot.
     *
     * `max(minNotional, untracked_halt_pct % of equity)`.
     *
     * minNotional alone is the wrong test once the account is more than tiny. It is a property of
     * the SYMBOL, not of the account: DOGEUSDT's is 1.00 USDT, so 1.15 USDT of leftover DOGE -
     * 0.02 % of a 5000 USDT account - would halt all trading, repeatedly, and "Reset halt" could
     * not clear it because the next tick re-checked the same wallet. That is not a safety
     * mechanism doing its job, it is a crumb switching the bot off.
     *
     * Scaling by equity keeps the protection EXACTLY as strict as it was wherever it mattered and
     * only relaxes it where it never did:
     *
     *   - at 10 USDT equity the floor is max(5.00, 0.05) = 5.00 - minNotional still governs,
     *     byte for byte the old behaviour on the account this bot was designed for;
     *   - at 5000 USDT it is max(5.00, 25.00) = 25.00, so crumbs are ignored and a genuinely
     *     unexplained position still halts the moment it is worth noticing.
     *
     * The thing this check exists to catch - something trading the account behind the bot's back,
     * or a position it lost track of - is a position of real size, and real size scales with the
     * account. A fixed 1 USDT trigger catches only dust.
     *
     * `untracked_halt_pct` (default 0.5) tunes it; 0 restores the old minNotional-only rule
     * exactly. The floor is never BELOW minNotional, so this can only ever relax, never tighten,
     * and it cannot be set to something that halts on an amount too small to sell.
     */
    private function untrackedHaltFloor(float $minNotional, float $equity): float
    {
        $pct = isset($this->cfg['untracked_halt_pct']) && is_numeric($this->cfg['untracked_halt_pct'])
            ? (float) $this->cfg['untracked_halt_pct']
            : 0.5;
        if (!is_finite($pct) || $pct <= 0.0) {
            return $minNotional;                        // explicitly opted out of the scaling
        }
        // THIS tick's equity, passed in by the caller - never db->lastEquity(). reconcileBalances()
        // runs at step 2 and the equity row is not written until step 3, so on a fresh database
        // the stored value does not exist yet and every first tick would silently fall back to
        // the bare minNotional. That is exactly the bug this method exists to fix, so reading it
        // from the database would have made the whole thing a no-op on the one run that matters.
        if (!is_finite($equity) || $equity <= 0.0) {
            return $minNotional;
        }
        $scaled = $equity * $pct / 100.0;
        return $scaled > $minNotional ? $scaled : $minNotional;
    }

    /** Untracked base balance ⇒ HALT; OPEN position whose base is gone ⇒ CLOSED reconciled_missing. */
    private function reconcileBalances(array $acct, array $bal): void
    {
        foreach ($bal['bases'] as $sym => $row) {
            if (!empty($row['untracked_big'])) {
                $floor = $this->untrackedHaltFloor((float) ($this->info[$sym]['minNotional'] ?? 0.0),
                    isset($bal['equity']) ? (float) $bal['equity'] : 0.0);
                Log::error('reconcile: untracked ' . $row['base'] . ' balance worth '
                    . Util::money((float) $row['value']) . ' ' . $this->cfg['quote_asset']
                    . ' with no tracked position (halt floor ' . Util::money($floor) . ') - halting'
                    . ' | SELL that balance at MARKET on the exchange, then press "Reset halt"', [
                    'symbol' => $sym, 'balance' => $row['total'], 'price' => $row['price'],
                    'value' => $row['value'], 'halt_floor' => $floor,
                ]);
                $this->halt('untracked_position');
                $this->notes[] = 'HALT untracked_position ' . $sym;
                break;
            }
        }
        // Same `<> 'scalp'` filter as Db::openPosition(), and it matters most here: this runs on
        // EVERY tick, and the `reconciled_missing` close below books pnl_usdt = 0 with no
        // gross_usdt / fees_usdt. On a scalp row that is exactly backwards -
        // Scalper::markStuck('scalp_unsellable') parks a row BECAUSE the coins are gone - so real
        // money spent would be recorded as a break-even trade and §2's verdict would be a lie.
        $open = $this->db->openPosition();
        if ($open === null) {
            $open = $this->positionWhere(self::STUCK_WHERE, []);
        }
        if ($open === null) {
            return;
        }
        $sym = (string) $open['symbol'];
        if (!isset($bal['bases'][$sym])) {
            return;   // no symbol info for the position's symbol: cannot judge
        }
        $free = (float) $bal['bases'][$sym]['free'];
        $qty  = (float) $open['qty'];
        if ($qty > 0.0 && $free < $qty * self::RECONCILE_MISSING_RATIO) {
            $fields = [
                'status'        => 'CLOSED',
                'exit_price'    => (float) $open['entry_price'],
                'exit_quote'    => (float) $open['entry_quote'],
                'exit_fee_usdt' => 0.0,
                'pnl_usdt'      => 0.0,
                'pnl_pct'       => 0.0,
                'exit_reason'   => 'reconciled_missing',
                'closed_at'     => $this->nowIso(),
            ];
            $this->db->updatePosition((int) $open['id'], $fields);
            Log::warn('reconcile: base balance below position qty, position closed as reconciled_missing (pnl 0)', [
                'symbol' => $sym, 'position_id' => $open['id'], 'qty' => $qty, 'base_free' => $free,
            ]);
            $this->notes[] = 'reconciled_missing ' . $sym;
        }
    }

    /* ================================================================ step 5: position */

    private function managePosition(array $pos, array $acct): void
    {
        $sym  = (string) $pos['symbol'];
        $book = $this->step('book:' . $sym, function () use ($sym): array {
            return $this->ex->bookTicker($sym);
        });
        $bid = (float) ($book['bid'] ?? 0.0);
        if ($bid <= 0.0) {
            Log::warn('position: no bid price, exit check skipped', ['symbol' => $sym]);
            $this->noTradeReason = 'position_open';
            return;
        }
        $entryEff = (float) $pos['entry_eff'];
        $upnl = $entryEff > 0.0 ? ($bid / $entryEff - 1.0) * 100.0 : 0.0;
        $this->posSummary = $sym . ' qty=' . Util::money((float) $pos['qty'], 6) . ' bid=' . Util::money($bid, 6) . ' upnl=' . sprintf('%+.2f%%', $upnl);

        $d = Risk::exitDecision($pos, $bid, $this->cfg, $this->nowIso());
        $upd = [];
        foreach (['trail_high' => 'f', 'trailing_armed' => 'i', 'stop_price' => 'f'] as $k => $t) {
            if (!array_key_exists($k, $d) || $d[$k] === null) {
                continue;
            }
            $new = $t === 'i' ? (int) $d[$k] : (float) $d[$k];
            $old = $t === 'i' ? (int) $pos[$k] : (float) $pos[$k];
            if ($new !== $old) {
                $upd[$k] = $new;
            }
        }
        if ($upd !== []) {
            $this->db->updatePosition((int) $pos['id'], $upd);
            $pos = array_merge($pos, $upd);
        }
        $reason = (string) ($d['reason'] ?? '');
        if ($reason === '') {
            $k15 = $this->step('klines15:' . $sym, function () use ($sym): array {
                return $this->ex->klines($sym, (string) $this->cfg['candle_interval'], self::KLINES_15M);
            }, false);
            if (is_array($k15) && $k15 !== []) {
                $c15    = Indicators::closed($k15, $this->serverTimeMs());
                $reason = (string) Strategy::exitSignal($c15, $pos, $bid, $this->cfg);
            }
        }
        if ($reason === '') {
            $this->noTradeReason = 'position_open';
            return;
        }
        $this->notes[] = 'exit ' . $reason;
        $this->attemptSell($pos, $reason, $acct, $bid);
    }

    /** STUCK position: retry the sell at most every 15 minutes. */
    private function retryStuck(array $pos, array $acct): void
    {
        $last = (string) $this->db->getState('stuck_retry_at', '');
        if ($last !== '' && Util::isoDiffMinutes($last, $this->nowIso()) < self::STUCK_RETRY_MINUTES) {
            return;
        }
        $this->db->setState('stuck_retry_at', $this->nowIso());
        $sym  = (string) $pos['symbol'];
        $book = $this->step('book:' . $sym, function () use ($sym): array {
            return $this->ex->bookTicker($sym);
        }, false);
        $bid = is_array($book) ? (float) ($book['bid'] ?? 0.0) : 0.0;
        if ($bid <= 0.0) {
            return;
        }
        $this->posSummary = $sym . ' STUCK qty=' . Util::money((float) $pos['qty'], 6) . ' bid=' . Util::money($bid, 6);
        $this->attemptSell($pos, 'stuck_recovered', $acct, $bid);
    }

    /** sellPosition() with the tick error policy applied. */
    private function attemptSell(array $pos, string $reason, array $acct, float $bid): void
    {
        try {
            $closed = $this->sellPosition($pos, $reason, $acct, $bid);
        } catch (BinanceException $e) {
            $action = $this->handleApiError($e, 'sell:' . $pos['symbol']);
            $this->noTradeReason = 'exit_failed:' . $reason;
            if ($action === 'abort') {
                throw new BotAbort('error', 'sell ' . $pos['symbol'] . ' failed: ' . $e->getMessage());
            }
            return;
        }
        if ($closed === null) {
            $now = $this->positionWhere('id = ?', [(int) $pos['id']]);
            $st  = $now !== null ? (string) $now['status'] : (string) $pos['status'];
            $this->noTradeReason = $st === 'STUCK' ? 'position_stuck' : 'position_open';
            return;
        }
        $this->noTradeReason = 'exited:' . $reason;
        $this->posSummary = $pos['symbol'] . ' closed ' . $reason . ' pnl=' . sprintf('%+.4f', (float) $closed['pnl_usdt']);
    }

    /**
     * Market-sells the whole floored base balance belonging to the position.
     * Returns the closed row (+ `order`), or null when nothing is sellable / the position is STUCK.
     * @throws BinanceException (the order row is already updated to UNKNOWN/FAILED)
     */
    private function sellPosition(array $pos, string $reason, array $acct, float $bid): ?array
    {
        $sym  = (string) $pos['symbol'];
        $info = $this->infoFor($sym);
        $base = (string) ($info['base'] ?? '');
        $step = (string) ($info['stepSize'] ?? '0.00000001');
        $balances = isset($acct['balances']) && is_array($acct['balances']) ? $acct['balances'] : [];
        $baseFree = ($base !== '' && isset($balances[$base])) ? (float) ($balances[$base]['free'] ?? 0.0) : 0.0;

        $carry  = (float) $pos['qty'] + $this->dustCarried($sym);
        $qtyStr = Util::floorToStep(min($carry, $baseFree), $step);
        $qty    = (float) $qtyStr;
        if ($qty <= 0.0) {
            Log::warn('sell: nothing sellable', ['symbol' => $sym, 'position_qty' => $pos['qty'], 'base_free' => $baseFree, 'reason' => $reason]);
            return null;
        }
        $minNotional = (float) ($info['minNotional'] ?? 0.0);
        $applyMin    = !array_key_exists('applyMinToMarket', $info) || (bool) $info['applyMinToMarket'];
        if ($applyMin && $minNotional > 0.0 && $qty * $bid < $minNotional) {
            // Binance checks NOTIONAL on MARKET orders against the avgPrice VWAP, not the bid;
            // only pay for that call when the cheap bid check says we are below the minimum.
            $avg = $this->marketAvgPrice($sym);
            $ref = $avg > 0.0 ? $avg : $bid;
            if ($qty * $ref < $minNotional) {
                if ((string) $pos['status'] !== 'STUCK') {
                    $this->db->updatePosition((int) $pos['id'], ['status' => 'STUCK', 'exit_reason' => 'stuck_notional']);
                    Log::warn('sell: notional below minimum, position marked STUCK (retry every ' . self::STUCK_RETRY_MINUTES . ' min)', [
                        'symbol' => $sym, 'qty' => $qty, 'bid' => $bid, 'avg_price' => $avg, 'notional' => $qty * $ref, 'min_notional' => $minNotional, 'wanted_reason' => $reason,
                    ]);
                }
                $this->db->setState('stuck_retry_at', $this->nowIso());
                return null;
            }
        }
        $r = $this->placeOrder('SELL', $sym, $info, $qtyStr, (int) $pos['id']);

        $filled = (float) ($r['qty'] ?? 0.0);
        if ($filled <= 0.0) {
            // EXPIRED / no liquidity: nothing left the wallet, so the position must stay as it is
            if (isset($r['client_id'])) {
                $this->db->updateOrder((string) $r['client_id'], ['status' => 'FAILED', 'updated_at' => $this->nowIso()]);
            }
            Log::warn('sell: order executed nothing, position left ' . $pos['status'], [
                'symbol' => $sym, 'requested_qty' => $qtyStr,
                'order_status' => isset($r['status']) ? $r['status'] : '', 'wanted_reason' => $reason,
            ]);
            return null;
        }
        $left = $qty - $filled;
        if ($left > 0.0 && $minNotional > 0.0 && $left * $bid >= $minNotional) {
            // partial fill: bank the proceeds against the cost basis and keep the rest for the next tick
            $proceeds = (float) ($r['quote'] ?? 0.0);
            $this->db->updatePosition((int) $pos['id'], [
                'qty'         => max(0.0, (float) $pos['qty'] - $filled),
                'entry_quote' => (float) $pos['entry_quote'] - $proceeds,
            ]);
            $this->db->insertTrade($this->tradeRow((int) $pos['id'], $sym, 'SELL', $r));
            $this->markDone((string) $r['client_id'], $r, (int) $pos['id']);
            Log::warn('sell: partial fill, position stays open for the remainder', [
                'symbol' => $sym, 'requested_qty' => $qtyStr, 'filled' => $filled,
                'left' => $left, 'proceeds' => $proceeds, 'wanted_reason' => $reason,
            ]);
            return null;
        }
        $closed = $this->closeFromFill($pos, $r, $reason);
        $this->markDone((string) $r['client_id'], $r, (int) $pos['id']);
        $closed['order'] = $r;
        return $closed;
    }

    /* ================================================================ step 6: entries */

    /**
     * Step 6. $symbols and $available optionally restrict the candidates and the quote that may
     * be committed; both default to the
     * single-engine behaviour — the whole watchlist and the whole free quote balance — so the
     * call made when `portfolio_enabled` is false is byte-for-byte the old one.
     */
    private function evaluateEntries(float $quoteFree, float $equity, ?array $symbols = null, ?float $available = null): void
    {
        $this->obsPending = [];
        $block = (string) Risk::entryBlockReason($this->cfg, $this->db, $quoteFree, $equity);
        if ($block !== '') {
            // nothing is evaluated behind a block, so there are no candle features to record;
            // the refusal itself is still evidence, kept once an hour per reason (§2)
            $this->obsBlocked($block);
            $this->noTradeReason = $block;
            return;
        }
        // the sleeve may not commit more than its available budget; sells are never blocked
        $budget = $available === null ? $quoteFree : min($quoteFree, max(0.0, $available));
        if ($symbols === null) {
            $symbols = $this->symbols();
        }
        if ($symbols === []) {
            $this->noTradeReason = 'no_symbols';
            return;
        }
        $serverMs  = $this->serverTimeMs();
        $threshold = (int) Risk::effectiveThreshold($this->cfg, $this->db);
        $evaluated = 0;
        $eligibleCount = 0;
        $unaffordable = 0;
        $metrics   = [];
        $bestSeen = null;
        $best = null;

        foreach ($symbols as $sym) {
            if (!isset($this->info[$sym])) {
                continue;
            }
            $info = $this->info[$sym];
            $k15 = $this->step('klines15:' . $sym, function () use ($sym): array {
                return $this->ex->klines($sym, (string) $this->cfg['candle_interval'], self::KLINES_15M);
            }, false);
            if (!is_array($k15) || $k15 === []) {
                continue;
            }
            $c15 = Indicators::closed($k15, $serverMs);
            if ($c15 === []) {
                continue;
            }
            $lastOpen = (int) $c15[count($c15) - 1][0];
            $prevOpen = (int) $this->db->getState('last_eval_candle_' . $sym, '0');
            if ($lastOpen <= $prevOpen) {
                continue;
            }
            $k1h = $this->step('klines1h:' . $sym, function () use ($sym): array {
                return $this->ex->klines($sym, (string) $this->cfg['trend_interval'], self::KLINES_1H);
            }, false);
            if (!is_array($k1h)) {
                continue;
            }
            $c1h  = Indicators::closed($k1h, $serverMs);
            $book = $this->step('book:' . $sym, function () use ($sym): array {
                return $this->ex->bookTicker($sym);
            }, false);
            if (!is_array($book) || (float) ($book['bid'] ?? 0.0) <= 0.0 || (float) ($book['ask'] ?? 0.0) <= 0.0) {
                $book = null;
            }

            // the fifth argument is null unless learning_enabled AND learning_apply are both
            // true, and Strategy re-checks both, so this call is the old four-argument one
            $sig = Strategy::evaluate($c15, $c1h, $this->cfg, $book, $this->learnWeights());
            $evaluated++;
            $score    = (int) ($sig['score'] ?? 0);
            $eligible = (bool) ($sig['eligible'] ?? false);
            $reasons  = isset($sig['reasons']) && is_array($sig['reasons']) ? array_values($sig['reasons']) : [];
            $gates    = isset($sig['gates']) && is_array($sig['gates']) ? array_values($sig['gates']) : [];
            $sigPrice = (float) ($sig['price'] ?? 0.0);
            $price    = $book !== null ? (float) $book['ask'] : $sigPrice;
            if ($price <= 0.0) {
                $price = $sigPrice;
            }

            if (!$this->symbolTradable($info)) {
                $gates[]  = 'symbol_not_trading';
                $eligible = false;
            }
            $size = 0.0;
            if ($price > 0.0) {
                $size     = (float) Risk::entrySize($this->cfg, $info, $price, $budget, $this->feePct);
                $required = (float) Risk::requiredSize($info, $price, $this->feePct);
                if ($size <= 0.0 || $required > $size) {
                    $gates[]  = 'size_unaffordable';
                    $eligible = false;
                    $unaffordable++;
                }
            } else {
                $gates[]  = 'no_price';
                $eligible = false;
            }

            $this->db->insertSignal($sym, $score, $eligible, $sigPrice > 0.0 ? $sigPrice : $price, $eligible ? $reasons : $gates);
            $this->db->setState('last_eval_candle_' . $sym, $lastOpen);

            $metrics[$sym] = [
                'price'     => $price > 0.0 ? $price : $sigPrice,
                'atr_pct'   => (float) (isset($sig['atr_pct']) ? $sig['atr_pct'] : 0.0),
                'atr1h_pct' => (float) (isset($sig['atr1h_pct']) ? $sig['atr1h_pct'] : 0.0),
                'eligible'  => $eligible ? 1 : 0,
            ];
            if ($book !== null) {
                $mid = ((float) $book['bid'] + (float) $book['ask']) / 2.0;
                if ($mid > 0.0) {
                    $metrics[$sym]['spread_pct'] = ((float) $book['ask'] - (float) $book['bid']) / $mid * 100.0;
                }
            }

            // one observation per evaluated symbol, entered or skipped (DESIGN-LEARNING.md §2);
            // the features come from the candles and the book this tick already fetched
            if ($this->learningOn()) {
                $this->obsPending[$sym] = [
                    'score'     => $score,
                    'threshold' => $threshold,
                    'eligible'  => $eligible,
                    'gates'     => $gates,
                    'features'  => $this->obsSignalFeatures($c15, $sig, $gates, $book, $info, $price, $size),
                ];
            }

            if ($bestSeen === null || $score > $bestSeen) {
                $bestSeen = $score;
            }
            if (!$eligible) {
                continue;
            }
            $eligibleCount++;
            if ($score >= $threshold && ($best === null || $score > $best['score'])) {
                $best = [
                    'symbol'  => $sym,
                    'info'    => $info,
                    'score'   => $score,
                    'reasons' => $reasons,
                    'size'    => $size,
                    'tp_pct'  => isset($sig['tp_pct']) ? (float) $sig['tp_pct'] : null,
                    'price'   => $price,
                ];
            }
        }

        $this->mergeSymbolMetrics($metrics);

        if ($evaluated === 0) {
            $this->obsFlush('', null, '');
            $this->noTradeReason = 'no_new_candle';
            return;
        }
        if ($best === null) {
            $this->obsFlush('', null, '');
            if ($eligibleCount > 0) {
                $this->noTradeReason = 'score_below_threshold(best=' . (int) $bestSeen . '<' . $threshold . ')';
            } elseif ($unaffordable === $evaluated) {
                // the balance, not the market, gated every symbol
                $this->noTradeReason = 'size_unaffordable(quote_free=' . Util::money($budget, 2) . ')';
            } else {
                $this->noTradeReason = 'no_eligible_signal';
            }
            return;
        }

        $sym = $best['symbol'];
        $this->notes[] = 'entry ' . $sym . ' score=' . $best['score'] . ' size=' . Util::fmtQuote($best['size']);
        try {
            $r = $this->placeOrder('BUY', $sym, $best['info'], $best['size'], null);
        } catch (BinanceException $e) {
            $action = $this->handleApiError($e, 'buy:' . $sym);
            if ($e->binanceCode === -1007) {
                // the send may have filled (the order row is UNKNOWN and reconcile decides next
                // tick): recording this evaluation as `skipped` / `not_taken` would book a real
                // entry into the control group and could never be corrected. Drop the capture.
                $this->obsPending = [];
            }
            $this->obsFlush('', null, 'entry_failed');
            $this->noTradeReason = 'entry_failed:' . $e->binanceCode;
            if ($action === 'abort') {
                throw new BotAbort('error', 'buy ' . $sym . ' failed: ' . $e->getMessage());
            }
            return;
        }
        $pid = $this->openPositionFromFill($sym, $r, $best['score'], implode(',', $best['reasons']), $this->effectiveTpPct($best['tp_pct']));
        if ($pid === null) {
            $this->db->updateOrder((string) $r['client_id'], ['status' => 'FAILED', 'updated_at' => $this->nowIso()]);
            Log::warn('buy: order returned no executed quantity, marked FAILED', ['symbol' => $sym, 'client_id' => $r['client_id'], 'result' => $this->resultForLog($r)]);
            $this->obsFlush('', null, 'entry_unfilled');
            $this->noTradeReason = 'entry_unfilled';
            return;
        }
        $this->markDone((string) $r['client_id'], $r, $pid);
        $this->obsFlush($sym, $pid, '');
        $this->noTradeReason = 'entered:' . $sym;
        // report the stored entry_eff (dust excluded), not quote/qty: they differ by up to a
        // whole stepSize and the operator uses this line to sanity-check the stop
        $newPos   = $this->db->openPosition();
        $entryEff = $newPos !== null && isset($newPos['entry_eff'])
            ? (float) $newPos['entry_eff']
            : (float) $r['quote'] / max(1e-12, (float) $r['qty']);
        $this->posSummary = $sym . ' opened qty=' . Util::money((float) $r['qty'], 6) . ' eff=' . Util::money($entryEff, 6);
        Log::info('entry: bought ' . $sym, [
            'position_id' => $pid, 'score' => $best['score'], 'reasons' => $best['reasons'],
            'qty' => $r['qty'], 'dust_qty' => $r['dust_qty'], 'price' => $r['price'], 'quote' => $r['quote'],
            'entry_eff' => $entryEff, 'fee_usdt' => $r['fee_usdt'],
        ]);
    }

    /* ================================================================ engines (the removed grid/pmm contract) */

    /**
     * Cancels every resting order left in `engine_orders`, whatever placed it.
     *
     * KEPT ON PURPOSE after the grid and pmm engines were removed. Those engines rested limit
     * orders on the exchange, and an order resting on Binance does not care that the code that
     * placed it is gone: without this there would be no way left to take one off the book, and
     * a ladder from a last grid run would sit there indefinitely holding real balance. So this
     * no longer goes through EngineOrders (deleted with them) - it reads the open rows directly,
     * cancels each one at the exchange and marks it CANCELED.
     *
     * Safe and nearly free when there is nothing to do: with no open row it touches neither the
     * exchange nor the config and costs one indexed SELECT, which is the normal case now.
     *
     * @return int number of orders that left the book
     */
    public function cancelAllEngineOrders(string $reason): int
    {
        try {
            $open = $this->db->engineOrders(['NEW', 'PARTIALLY_FILLED'], $this->mode);
        } catch (Throwable $e) {
            return 0;
        }
        if ($open === []) {
            return 0;
        }
        $n = 0;
        foreach ($open as $row) {
            $sym = strtoupper(trim((string) ($row['symbol'] ?? '')));
            $cid = (string) ($row['client_id'] ?? '');
            if ($sym === '' || $cid === '') {
                continue;
            }
            try {
                $this->ex->cancelOrder($sym, $cid);
                $n++;
            } catch (BinanceException $e) {
                // -2011 UNKNOWN_ORDER means it is already off the book (filled, expired or
                // cancelled by hand): that is success for our purposes, so record it as such
                // rather than leaving a row that looks live forever.
                if ($e->binanceCode !== -2011) {
                    $this->handleApiError($e, 'engine_cancel_all:' . $sym);
                    continue;
                }
            } catch (Throwable $e) {
                Log::error('engine cancel ' . $sym . ' failed: ' . $e->getMessage(), ['reason' => $reason, 'client_id' => $cid]);
                continue;
            }
            try {
                $this->db->updateEngineOrder($cid, [
                    'status' => 'CANCELED', 'updated_at' => $this->nowIso(),
                ]);
            } catch (Throwable $e) {
                // the order is off the book either way; the row is bookkeeping
            }
        }
        if ($n > 0) {
            Log::warn('cancelled ' . $n . ' leftover engine order(s)', ['reason' => $reason]);
        }
        return $n;
    }

    /**
     * True when the same refusal was already recorded for this engine and symbol less than
     * OBS_ENGINE_SKIP_REPEAT_MIN ago. A block is re-reported every single tick for as long as
     * it lasts; without this the control group would be almost entirely duplicates.
     */
    private function obsSkipRepeats(string $engine, string $symbol, string $reason): bool
    {
        try {
            $filter = ['mode' => $this->mode, 'engine' => $engine, 'limit' => 1];
            if ($symbol !== '') {
                $filter['symbol'] = $symbol;
            }
            $last = $this->db->observations($filter);
        } catch (Throwable $e) {
            return false;
        }
        if ($last === []) {
            return false;
        }
        $row = $last[0];
        if ((string) $row['decision'] !== 'skipped' || (string) $row['skip_reason'] !== $reason) {
            return false;
        }
        return Util::isoDiffMinutes((string) $row['ts'], $this->nowIso()) < self::OBS_ENGINE_SKIP_REPEAT_MIN;
    }

    /* ================================================================ steps 5-7: scalp */

    /**
     * Steps 5-7 for the scalp engine (DESIGN-SCALP.md §3-§5): many symbols, tiny size, market
     * orders, seconds rather than hours.
     *
     * Steps 1-4 above ran exactly as they do for every other engine and they still win over
     * anything decided here:
     *
     *   * the equity-floor and drawdown KILL SWITCHES returned 'halted' before this method was
     *     reached, so no entry is even considered;
     *   * the daily and weekly caps, the global pause and the api pause are re-read inside
     *     Scalper::entryBlock() through Risk::entryBlockReason(), which holds ENTRIES while
     *     still managing every open position to its exit;
     *   * the demo-only guard below places ZERO orders in live mode.
     *
     * The order is the order §3 and §4 ask for: the guard, then the movers board (at most once
     * per `scalp_universe_refresh_min` - it is a weight-80 call and must never run inside the
     * fast loop), then the bounded loop itself.
     */
    private function runScalpTick(): void
    {
        if (!class_exists('Scalper')) {
            $this->noTradeReason = 'scalp_unavailable';
            $this->notes[]       = 'lib/Scalper.php is missing';
            Log::error('scalp: lib/Scalper.php is missing, the engine cannot run');
            return;
        }

        // ---- 1. demo-only (DESIGN-SCALP.md §2), the same rule and the same wording as grid
        //         and pmm: mode live without `allow_live_engines` means not one order.
        if ($this->engineLiveBlocked('scalp')) {
            $this->noTradeReason = 'engine_live_blocked';
            $this->notes[]       = 'engine scalp blocked in live mode';
            $n = $this->cancelAllEngineOrders('engine_live_blocked');
            if ($n > 0) {
                $this->notes[] = $n . ' engine order(s) cancelled (live blocked)';
            }
            return;
        }

        // ---- 2. this engine trades with MARKET orders only and never rests anything on the
        //         book, so a ladder left behind by grid or pmm when the user switched engines
        //         would sit there unmanaged for as long as scalp runs. Costs one indexed SELECT
        //         when there is nothing to cancel, which is the normal case.
        $stale = $this->cancelAllEngineOrders('engine_switched_to_scalp');
        if ($stale > 0) {
            $this->notes[] = $stale . ' resting order(s) from a previous engine cancelled';
        }

        // ---- 3. the movers board (§3)
        $this->maybeRefreshMovers();

        // ---- 4. the bounded fast loop (§4)
        $this->runScalpLoop();
    }

    /**
     * Runs one bounded scalp loop (DESIGN-SCALP.md §4) and folds its result into this tick's
     * report.
     *
     * The deadline is measured from the START OF THE TICK, not from here, so
     * `scalp_run_seconds` bounds the WHOLE tick: the shared steps 1-4 come out of the same
     * budget, the tick always ends inside its minute, and the `tick.lock` is free again before
     * the next cron fires. Scalper::run() always completes at least one poll even when the
     * budget is already spent, so a slow tick still manages its open positions rather than
     * leaving them unattended for a minute.
     */
    private function runScalpLoop(bool $exitsOnly = false): void
    {
        if (!class_exists('Scalper')) {
            return;
        }
        $runSec = isset($this->cfg['scalp_run_seconds']) && is_numeric($this->cfg['scalp_run_seconds'])
            ? (int) round((float) $this->cfg['scalp_run_seconds'])
            : 50;
        if ($runSec < 1) {
            $runSec = 1;
        }
        if ($runSec > Scalper::MAX_RUN_SECONDS) {
            $runSec = Scalper::MAX_RUN_SECONDS;
        }
        $start      = $this->tickStart > 0.0 ? $this->tickStart : microtime(true);
        $deadlineMs = ($start + (float) $runSec) * 1000.0;

        $r = null;
        try {
            $scalper = new Scalper($this->cfg, $this->db, $this->ex, $this->info);
            // non-essential: a market-data failure inside the loop goes through the api-error
            // ladder (and so can pause the bot) but must not turn the whole tick into an error
            $r = $this->step('scalp_loop', function () use ($scalper, $deadlineMs, $exitsOnly) {
                return $scalper->run($deadlineMs, $exitsOnly);
            }, false);
        } catch (BotAbort $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('scalp: loop failed - ' . get_class($e) . ': ' . $e->getMessage(), [
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            $this->notes[] = 'scalp loop failed: ' . $e->getMessage();
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'scalp_error';
            }
            return;
        }
        if (!is_array($r)) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'scalp_error';
            }
            return;
        }

        $entries = isset($r['entries']) ? (int) $r['entries'] : 0;
        $exits   = isset($r['exits']) ? (int) $r['exits'] : 0;
        // every entry and every exit was a real market order, so the tick's order count has to
        // know about them: it is what the panel and the hourly order guard read
        $this->ordersThisTick += max(0, $entries) + max(0, $exits);

        $refusals = isset($r['refusals']) && is_array($r['refusals']) ? $r['refusals'] : [];
        $why = isset($refusals['*']) ? (string) $refusals['*'] : '';
        if ($why === '') {
            $why = isset($r['blocked']) ? (string) $r['blocked'] : '';
        }
        if ($why === '') {
            $why = $entries > 0 ? 'entered' : 'idle';
        }
        $this->noTradeReason = 'scalp:' . $why;

        $summary = isset($r['summary']) ? trim((string) $r['summary']) : '';
        if ($summary !== '') {
            // posSummary only, NOT also a note: buildSummary() prints the position summary and
            // the notes in the same line, so adding both printed the whole scalp line twice in
            // every cron response and every log entry.
            $this->posSummary = $summary;
        }
        if (!empty($r['paused'])) {
            // §5: the pause does NOT clear itself. Say so every tick until a human resumes.
            $this->notes[] = 'scalp PAUSED after ' . (isset($r['streak']) ? (int) $r['streak'] : 0)
                           . ' consecutive loss(es): press "Resume scalping" in the panel';
        }
    }

    /**
     * Refreshes the movers board when `scalp_universe_refresh_min` has elapsed (DESIGN-SCALP.md
     * §3). `GET /api/v3/ticker/24hr` with no symbol is WEIGHT 80, so Movers::due() rate-limits
     * it and it never runs inside the fast loop.
     *
     * Symbols holding an open scalp position are pinned, so a re-ranking can never take the
     * universe out from under a position (§3, last bullet).
     *
     * Best effort by design: a failed refresh leaves the previous board in place and the loop
     * trades on that. The board is a ranking, not a dependency - Scalper::universe() even falls
     * back to the watchlist when it has never run at all.
     */
    private function maybeRefreshMovers(): void
    {
        if (!class_exists('Movers')) {
            return;
        }
        try {
            $movers = new Movers($this->cfg, $this->db, $this->ex);
            if (!$movers->due()) {
                return;
            }
            $info = $this->info;
            $keep = $this->scalpKeepSymbols();
            $rows = $this->step('movers', function () use ($movers, $info, $keep) {
                return $movers->refresh($info, false, $keep);
            }, false);
            if (is_array($rows) && $rows !== []) {
                $inUniverse = 0;
                foreach ($rows as $row) {
                    if (is_array($row) && !empty($row['in_universe'])) {
                        $inUniverse++;
                    }
                }
                $this->notes[] = 'movers ranked ' . count($rows) . ' pair(s), ' . $inUniverse . ' in universe';
            }
        } catch (BotAbort $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warn('movers: refresh failed - ' . $e->getMessage());
        }
    }

    /**
     * Whether lib/Scalper.php's run() takes the exits-only flag (DESIGN-SCALP.md §4). Without it
     * the loop is free to OPEN a position, which must never happen on a tick the portfolio
     * sleeves own: they budget the free quote. Under any engine other than scalp
     * Scalper::entryBlock() already refuses on its own with `engine_not_scalp`, so the flag is
     * not needed there and this is not consulted.
     */
    private function scalpExitsOnlyAvailable(): bool
    {
        if (!class_exists('Scalper')) {
            return false;
        }
        try {
            return (new ReflectionMethod('Scalper', 'run'))->getNumberOfParameters() >= 2;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Scalp positions that still hold inventory in this mode, or [] when there are none.
     *
     * Delegated to Scalper::openPositions() so Bot and the engine agree to the row on what
     * "still holding" means - OPEN *and* STUCK (Scalper::OPEN_STATUSES). A STUCK scalp row is
     * precisely the one whose base balance would otherwise look untracked, so the two must not
     * be allowed to drift apart. Falls back to the Db query when Scalper is not loaded.
     *
     * Never throws: this read feeds the survival layer, which must still run without it.
     * @return array positions rows
     */
    private function scalpOpenRows(): array
    {
        try {
            if (class_exists('Scalper')) {
                $rows = Scalper::openPositions($this->db, $this->cfg, $this->mode);
            } elseif (method_exists($this->db, 'openScalpPositions')) {
                $rows = $this->db->openScalpPositions($this->mode);
            } else {
                return [];
            }
        } catch (Throwable $e) {
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    /** Symbols pinned into the movers universe because they hold a position (§3). */
    private function scalpKeepSymbols(): array
    {
        $out = [];
        foreach ($this->scalpOpenRows() as $row) {
            $sym = isset($row['symbol']) ? strtoupper(trim((string) $row['symbol'])) : '';
            if ($sym !== '' && !in_array($sym, $out, true)) {
                $out[] = $sym;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------- portfolio helpers */

    /** Milliseconds the current tick has been running. */
    private function elapsedMs(): float
    {
        if ($this->tickStart <= 0.0) {
            return 0.0;
        }
        return (microtime(true) - $this->tickStart) * 1000.0;
    }

    /** TICK_TIME_BUDGET_MS, overridable through `tick_time_budget_ms` (tests, slow hosts). */
    private function tickBudgetMs(): int
    {
        $v = isset($this->cfg['tick_time_budget_ms']) && is_numeric($this->cfg['tick_time_budget_ms'])
            ? (int) $this->cfg['tick_time_budget_ms']
            : self::TICK_TIME_BUDGET_MS;
        return $v > 0 ? $v : self::TICK_TIME_BUDGET_MS;
    }

    /** The watchlist: what the panel telemetry covers. */
    private function metricSymbols(): array
    {
        return $this->symbols();
    }

    /** Bid read this tick for $symbol (0.0 when the book was not read). */
    private function bidOf(string $symbol): float
    {
        return isset($this->books[$symbol]) ? (float) $this->books[$symbol]['bid'] : 0.0;
    }

    /**
     * true when the engine may not trade because it is live and `allow_live_engines` is false.
     *
     * The scalp engine is the only caller left (grid and pmm were removed), and it is the engine
     * this guard matters most for: DESIGN-SCALP.md is explicit that it is for measuring on demo,
     * and nothing about its fee arithmetic says it is ready for real money.
     */
    private function engineLiveBlocked(string $engine = '', string $symbol = ''): bool
    {
        $engine = $engine !== '' ? $engine : $this->engine;
        // either source of truth saying "live" blocks: the exchange the bot is really talking to
        // and the configured mode, so a mis-paired config can never slip an order through
        $live    = $this->mode === 'live' || strtolower(trim((string) $this->cfg['mode'])) === 'live';
        $blocked = $live && !self::truthy($this->cfg, 'allow_live_engines');
        $logged  = (string) $this->db->getState('engine_live_blocked_at', '');
        if (!$blocked) {
            if ($logged !== '') {
                $this->db->setState('engine_live_blocked_at', '');
            }
            return false;
        }
        if ($logged === '') {
            $this->db->setState('engine_live_blocked_at', $this->nowIso());
            Log::warn('engine: ' . $engine . ' refuses to run in live mode (allow_live_engines is false); no order is placed', [
                'engine' => $engine, 'symbol' => $symbol,
            ]);
        }
        return true;
    }

    /** Config flag that may arrive as bool, int or string. */
    private static function truthy(array $cfg, string $key): bool
    {
        if (!array_key_exists($key, $cfg)) {
            return false;
        }
        $v = $cfg[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (float) $v != 0.0;
        }
        if (is_string($v)) {
            $v = strtolower(trim($v));
            return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
        }
        return false;
    }

    /* ============================================ learning capture (DESIGN-LEARNING.md §2)

       Everything in this section is observation capture: it reads what the tick already has
       and writes rows in `observations`. It cannot change a single trading parameter --
       every insert goes through OBS_COLUMNS and every update through OBS_RESOLVE_COLUMNS,
       both of which name observation columns only. The one thing learning feeds back into
       trading is the score-component weight map, and this file only ever READS it
       (learnWeights()) and hands it to Strategy, which clamps it again.
    */

    /** Capture and feedback are both off when `learning_enabled` is false. */
    private function learningOn(): bool
    {
        return self::truthy($this->cfg, 'learning_enabled');
    }

    /**
     * The learned score-component deltas to hand to Strategy::evaluate(), or null.
     *
     * Null unless BOTH `learning_enabled` and `learning_apply` are true. The map is read
     * from the `learn_weights` state key and filtered down to the components Strategy
     * actually knows, so a key that named anything else - a size, a stop, a budget - is
     * dropped here and could not reach the scorer even if something wrote it.
     */
    private function learnWeights(): ?array
    {
        if ($this->learnWeightsLoaded) {
            return $this->learnWeights;
        }
        $this->learnWeightsLoaded = true;
        $this->learnWeights       = null;
        if (!$this->learningOn() || !self::truthy($this->cfg, 'learning_apply')) {
            return null;
        }
        try {
            $raw = $this->db->getStateJson('learn_weights', []);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (Strategy::LEARN_COMPONENTS as $component => $base) {
            if (isset($raw[$component]) && is_numeric($raw[$component])) {
                $out[(string) $component] = (float) $raw[$component];
            }
        }
        $this->learnWeights = $out === [] ? null : $out;
        return $this->learnWeights;
    }

    /**
     * Inserts one observation through `Db::insertObservation()`. Only OBS_COLUMNS are ever
     * handed over, and the list is a constant, not the caller's keys.
     * @return int|null the new row id
     */
    private function obsInsert(array $row): ?int
    {
        if (!$this->learningOn()) {
            return null;
        }
        $clean = [];
        foreach (self::OBS_COLUMNS as $col) {
            if (array_key_exists($col, $row)) {
                $clean[$col] = $row[$col];
            }
        }
        if ($clean === []) {
            return null;
        }
        try {
            $id = (int) $this->db->insertObservation($clean);
            return $id > 0 ? $id : null;
        } catch (Throwable $e) {
            Log::warn('observation insert failed: ' . $e->getMessage(), ['symbol' => isset($row['symbol']) ? $row['symbol'] : '']);
            return null;
        }
    }

    /**
     * Resolves one observation. `Db::resolveObservation()` is never forced here, so a row
     * that already carries an outcome is left exactly as it is: a repeated close (a second
     * sweep, a re-run reconcile, a re-booked cycle) writes nothing and can neither
     * double-count a trade nor flip an outcome. Only OBS_RESOLVE_COLUMNS are settable, so a
     * resolve can never rewrite the conditions captured at decision time.
     */
    private function obsResolveRow(int $id, array $fields): void
    {
        if (!$this->learningOn() || $id <= 0) {
            return;
        }
        $clean = [];
        foreach (self::OBS_RESOLVE_COLUMNS as $col) {
            if (array_key_exists($col, $fields)) {
                $clean[$col] = $fields[$col];
            }
        }
        if ($clean === []) {
            return;
        }
        try {
            $this->db->resolveObservation($id, $clean);
        } catch (Throwable $e) {
            Log::warn('observation resolve failed: ' . $e->getMessage(), ['observation_id' => $id]);
        }
    }

    /** win / loss / flat from a realised PnL. */
    private static function obsOutcome(float $pnl): string
    {
        if ($pnl > 0.0) {
            return 'win';
        }
        return $pnl < 0.0 ? 'loss' : 'flat';
    }

    /**
     * The feature vector of one signal-engine evaluation. Everything comes from the candles,
     * the book and the symbol info this tick already fetched: capture costs no API call.
     */
    private function obsSignalFeatures(array $c15, array $sig, array $gates, ?array $book, array $info, float $price, float $size): array
    {
        $sec     = $this->nowSec();
        $reasons = isset($sig['reasons']) && is_array($sig['reasons']) ? $sig['reasons'] : [];
        $f = [
            'rsi'       => isset($sig['rsi']) ? (float) $sig['rsi'] : 0.0,
            'atr_pct'   => isset($sig['atr_pct']) ? (float) $sig['atr_pct'] : 0.0,
            'atr1h_pct' => isset($sig['atr1h_pct']) ? (float) $sig['atr1h_pct'] : 0.0,
            'trend_up'  => in_array('trend_down', $gates, true) ? 0 : 1,
            'hour_utc'  => (int) gmdate('G', $sec),
            'dow'       => (int) gmdate('w', $sec),
            // the two score components that no continuous feature stands for, as 1/0 so they
            // bucket like everything else
            'rsi_up'    => in_array('rsi_up', $reasons, true) ? 1 : 0,
            'reversal'  => in_array('reversal_candle', $reasons, true) ? 1 : 0,
        ];
        $closes = [];
        $vols   = [];
        foreach ($c15 as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $r = array_values($row);
            if (!is_numeric($r[4]) || !is_numeric($r[5])) {
                continue;
            }
            $closes[] = (float) $r[4];
            $vols[]   = (float) $r[5];
        }
        $n = count($closes);
        if ($n >= 20) {
            $bb    = Indicators::bollinger($closes, 20, 2.0);
            $upper = isset($bb['upper'][$n - 1]) ? $bb['upper'][$n - 1] : null;
            $lower = isset($bb['lower'][$n - 1]) ? $bb['lower'][$n - 1] : null;
            if ($upper !== null && $lower !== null && (float) $upper > (float) $lower) {
                // 0 at the lower band, 1 at the upper; a deeper pierce than the band clamps to
                // the edge so every row still lands in a bucket
                $f['bb_pos'] = Util::clamp(($closes[$n - 1] - (float) $lower) / ((float) $upper - (float) $lower), 0.0, 1.0);
            }
            $vsma = Indicators::sma($vols, 20);
            $v20  = isset($vsma[$n - 1]) ? $vsma[$n - 1] : null;
            if ($v20 !== null && (float) $v20 > 0.0) {
                $f['vol_ratio'] = $vols[$n - 1] / (float) $v20;
            }
        }
        $spread = $this->obsSpreadPct($book);
        if ($spread !== null) {
            $f['spread_pct'] = $spread;
        }
        $step = $this->obsStepValuePct($info, $price, $size > 0.0 ? $size : (float) $this->cfg['trade_usdt']);
        if ($step !== null) {
            $f['step_value_pct'] = $step;
        }
        return $f;
    }

    /** (ask − bid) / mid × 100, or null when the book is unusable. */
    private function obsSpreadPct(?array $book): ?float
    {
        if (!is_array($book)) {
            return null;
        }
        $bid = isset($book['bid']) && is_numeric($book['bid']) ? (float) $book['bid'] : 0.0;
        $ask = isset($book['ask']) && is_numeric($book['ask']) ? (float) $book['ask'] : 0.0;
        if ($bid <= 0.0 || $ask <= 0.0) {
            return null;
        }
        $mid = ($bid + $ask) / 2.0;
        return $mid > 0.0 ? ($ask - $bid) / $mid * 100.0 : null;
    }

    /** Dust coarseness: one step of base, valued at price, as a percent of the order size. */
    private function obsStepValuePct(array $info, float $price, float $orderUsdt): ?float
    {
        $step = isset($info['stepSize']) && is_numeric($info['stepSize']) ? (float) $info['stepSize'] : 0.0;
        if ($step <= 0.0 || $price <= 0.0 || $orderUsdt <= 0.0) {
            return null;
        }
        return $step * $price / $orderUsdt * 100.0;
    }

    /**
     * Writes the observations captured by the entry evaluation that just ran: one row per
     * evaluated symbol, `entered` for the one that actually traded and `skipped` for every
     * other one. The skipped rows are the control group of DESIGN-LEARNING.md §2 and resolve
     * to `not_taken` here and now - they never carry PnL.
     */
    private function obsFlush(string $entered, ?int $positionId, string $failReason): void
    {
        $pending          = $this->obsPending;
        $this->obsPending = [];
        if ($pending === [] || !$this->learningOn()) {
            return;
        }
        $now = $this->nowIso();
        foreach ($pending as $sym => $o) {
            $isEntry = ($entered !== '' && (string) $sym === $entered && $positionId !== null);
            $row = [
                'ts'          => $now,
                'mode'        => $this->mode,
                'engine'      => 'signal',
                'symbol'      => (string) $sym,
                'decision'    => $isEntry ? 'entered' : 'skipped',
                'skip_reason' => $isEntry ? null : $this->obsSkipReason($o, $failReason),
                'score'       => (int) $o['score'],
                'threshold'   => (int) $o['threshold'],
                'features'    => is_array($o['features']) ? $o['features'] : [],
                'position_id' => $isEntry ? $positionId : null,
            ];
            if (!$isEntry) {
                $row['outcome']     = 'not_taken';
                $row['resolved_at'] = $now;
            }
            $this->obsInsert($row);
        }
    }

    /** Why this evaluation did not trade: the gate that blocked it, or how it lost the pick. */
    private function obsSkipReason(array $o, string $failReason): string
    {
        $gates = isset($o['gates']) && is_array($o['gates']) ? array_values($o['gates']) : [];
        if (empty($o['eligible'])) {
            return $gates !== [] ? (string) $gates[0] : 'not_eligible';
        }
        if ((int) $o['score'] < (int) $o['threshold']) {
            return 'below_threshold';
        }
        // eligible and over the threshold: either the order never happened, or another
        // symbol scored higher and only one entry per tick is allowed
        return $failReason !== '' ? $failReason : 'not_best';
    }

    /**
     * The whole entry step was refused before any symbol could be evaluated (a cooldown, a
     * loss cap, `enabled` off, ...). There are no candle features to record - fetching them
     * would cost API calls the tick deliberately does not make - so the row carries the block
     * reason and the clock, under the symbol `*` because the block is account-wide, not
     * per symbol. Recorded once an hour per reason, not once a minute.
     */
    private function obsBlocked(string $reason): void
    {
        if (!$this->learningOn() || $reason === '') {
            return;
        }
        if ($this->obsSkipRepeats('signal', self::OBS_ALL_SYMBOLS, $reason)) {
            return;
        }
        $now = $this->nowIso();
        $sec = $this->nowSec();
        $this->obsInsert([
            'ts'          => $now,
            'mode'        => $this->mode,
            'engine'      => 'signal',
            'symbol'      => self::OBS_ALL_SYMBOLS,
            'decision'    => 'skipped',
            'skip_reason' => $reason,
            'features'    => ['hour_utc' => (int) gmdate('G', $sec), 'dow' => (int) gmdate('w', $sec)],
            'outcome'     => 'not_taken',
            'resolved_at' => $now,
        ]);
    }

    /**
     * Resolves every observation whose position has since closed - whatever closed it: an
     * exit signal, the kill switch, a panic sell or reconciliation. Idempotent: it only ever
     * looks at rows that still have no outcome, and the resolve is unforced.
     */
    private function obsResolvePositions(): void
    {
        try {
            $open = $this->db->openObservations($this->mode, 500, true);
        } catch (Throwable $e) {
            return;
        }
        $now = $this->nowIso();
        foreach ($open as $o) {
            $pid = isset($o['position_id']) && $o['position_id'] !== null ? (int) $o['position_id'] : 0;
            if ($pid <= 0) {
                continue;
            }
            try {
                $pos = $this->db->position($pid);
            } catch (Throwable $e) {
                continue;
            }
            if ($pos === null || (string) $pos['status'] !== 'CLOSED') {
                continue;   // still open, or STUCK and not sold yet
            }
            $pnl    = isset($pos['pnl_usdt']) && is_numeric($pos['pnl_usdt']) ? (float) $pos['pnl_usdt'] : 0.0;
            $opened = isset($pos['opened_at']) && (string) $pos['opened_at'] !== '' ? (string) $pos['opened_at'] : (string) $o['ts'];
            $closed = isset($pos['closed_at']) && (string) $pos['closed_at'] !== '' ? (string) $pos['closed_at'] : $now;
            $this->obsResolveRow((int) $o['id'], [
                'outcome'      => self::obsOutcome($pnl),
                'pnl_usdt'     => $pnl,
                'pnl_pct'      => isset($pos['pnl_pct']) && is_numeric($pos['pnl_pct']) ? (float) $pos['pnl_pct'] : 0.0,
                'exit_reason'  => isset($pos['exit_reason']) ? (string) $pos['exit_reason'] : '',
                'held_minutes' => Util::isoDiffMinutes($opened, $closed),
                'resolved_at'  => $now,
            ]);
        }
    }

    /* ================================================================ orders */

    /**
     * SENDING → exchange call → row left SENDING for the caller to mark DONE. On failure the row
     * becomes UNKNOWN (-1007) or FAILED and the
     * exception is rethrown. $amount: quote USDT (float) for BUY, base qty string for SELL.
     * @param float|string $amount
     * @throws BinanceException
     */
    private function placeOrder(string $side, string $symbol, array $info, $amount, ?int $positionId): array
    {
        $cid = $this->newClientId($side, $symbol);
        if ($cid === null) {
            throw new BinanceException('order ' . $side . ' ' . $symbol . ': a pending order with the same client id exists; reconcile first', -1000, 0);
        }
        $this->db->insertOrder([
            'client_id'   => $cid,
            'position_id' => $positionId,
            'mode'        => $this->mode,
            'symbol'      => $symbol,
            'side'        => $side,
            'status'      => 'SENDING',
            'created_at'  => $this->nowIso(),
        ]);
        $this->ordersThisTick++;
        try {
            if ($side === 'BUY') {
                $r = $this->ex->marketBuy($symbol, (float) $amount, $info, $cid);
            } else {
                $r = $this->ex->marketSell($symbol, (string) $amount, $info, $cid);
            }
        } catch (BinanceException $e) {
            $unknown = $e->binanceCode === -1007;
            // keep anything the exchange already stored in raw (PaperExchange writes its fill there)
            // so reconcile() can still recover the order; merge the error into it instead of replacing it
            $errInfo = ['error' => $e->getMessage(), 'code' => $e->binanceCode, 'http' => $e->httpStatus];
            $rawOut  = $errInfo;
            try {
                $row = method_exists($this->db, 'order') ? $this->db->order($cid) : null;
                if (is_array($row) && isset($row['raw']) && is_string($row['raw']) && $row['raw'] !== '') {
                    $prev = json_decode($row['raw'], true);
                    if (is_array($prev)) {
                        $prev['order_error'] = $errInfo;
                        $rawOut = $prev;
                    }
                }
            } catch (Throwable $ignored) {
                $rawOut = $errInfo;
            }
            $this->db->updateOrder($cid, [
                'status'     => $unknown ? 'UNKNOWN' : 'FAILED',
                'updated_at' => $this->nowIso(),
                'raw'        => json_encode($rawOut, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
            Log::error('order ' . $side . ' ' . $symbol . ' ' . ($unknown ? 'UNKNOWN' : 'FAILED') . ': ' . $e->getMessage(), [
                'client_id' => $cid, 'code' => $e->binanceCode, 'http' => $e->httpStatus,
            ]);
            throw $e;
        }
        $r['client_id'] = $cid;
        // stays SENDING here: the caller marks it DONE once the position/trade rows exist, so a crash
        // in between leaves a pending order that reconcile() can still turn into a position.
        return $r;
    }

    /** Deterministic id for this tick minute; null when the same id is still pending. */
    private function newClientId(string $side, string $symbol): ?string
    {
        $minute = intdiv($this->nowMs(), 60000) * 60;
        $base   = Util::clientOrderId($side, $symbol, $minute);
        $cid    = $base;
        for ($n = 2; $n < 100; $n++) {
            $status = $this->orderStatus($cid);
            if ($status === null) {
                return $cid;
            }
            if ($status === 'SENDING' || $status === 'UNKNOWN') {
                return null;
            }
            $cid = substr($base . '-' . $n, 0, 36);
        }
        return null;
    }

    private function orderStatus(string $cid): ?string
    {
        $st = $this->db->pdo()->prepare('SELECT status FROM orders WHERE client_id = ? LIMIT 1');
        $st->execute([$cid]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    private function orderRaw(string $cid): string
    {
        $st = $this->db->pdo()->prepare('SELECT raw FROM orders WHERE client_id = ? LIMIT 1');
        $st->execute([$cid]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? '' : (string) $v;
    }

    /** position_id of the trade recorded for this client id, or null when none. */
    private function tradePositionId(string $cid): ?int
    {
        $st = $this->db->pdo()->prepare('SELECT position_id FROM trades WHERE client_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$cid]);
        $v = $st->fetchColumn();
        if ($v === false) {
            return null;
        }
        return $v === null ? 0 : (int) $v;
    }

    /* ================================================================ positions */

    /** Creates the OPEN position + BUY trade from a normalised fill; null when nothing was executed. */
    private function openPositionFromFill(string $symbol, array $r, ?int $score, string $entryReason, float $tpPct): ?int
    {
        $qty   = (float) ($r['qty'] ?? 0.0);
        $quote = (float) ($r['quote'] ?? 0.0);
        if ($qty <= 0.0 || $quote <= 0.0) {
            return null;
        }
        $dust = max(0.0, (float) ($r['dust_qty'] ?? 0.0));
        $fill = (float) ($r['price'] ?? 0.0);
        // The dust is not lost: it stays in the wallet and a later SELL of the whole floored free
        // balance recovers it, so it must not be charged against the sellable quantity. Value it at
        // the fill price and take it out of the basis; otherwise entry_eff (and the stop / TP /
        // trailing floor anchored on it) sits up to a whole stepSize above the fill and the
        // position is stopped out on the next tick.
        $basis = $quote - $dust * $fill;
        $eff   = ($fill > 0.0 && $basis > 0.0) ? $basis / $qty : $quote / $qty;
        $sl    = max(0.0, (float) $this->cfg['stop_loss_pct']);
        $row = [
            'mode'              => $this->mode,
            'symbol'            => $symbol,
            'status'            => 'OPEN',
            'qty'               => $qty,
            'dust_qty'          => $dust,
            'entry_price'       => (float) ($r['price'] ?? $eff),
            'entry_eff'         => $eff,
            'entry_quote'       => $quote,
            'entry_fee_usdt'    => (float) ($r['fee_usdt'] ?? 0.0),
            'stop_price'        => $eff * (1.0 - $sl / 100.0),
            'take_profit_price' => $eff * (1.0 + $tpPct / 100.0),
            'trail_high'        => $eff,
            'trailing_armed'    => 0,
            'score'             => $score,
            'entry_reason'      => $entryReason,
            'opened_at'         => $this->nowIso(),
        ];
        $pid = $this->db->insertPosition($row);
        $this->db->insertTrade($this->tradeRow($pid, $symbol, 'BUY', $r));
        return $pid;
    }

    /** Marks the position CLOSED from a SELL fill, records the trade and the outcome. Returns the closed row. */
    private function closeFromFill(array $pos, array $r, string $reason): array
    {
        $entryQuote = (float) $pos['entry_quote'];
        $exitQuote  = (float) ($r['quote'] ?? 0.0);
        $sym        = (string) $pos['symbol'];
        $feeExt     = $this->entryFeeUnaccounted((int) $pos['id'], $sym)
                    + $this->feeUnaccounted($sym, 'SELL', (float) ($r['fee_usdt'] ?? 0.0), isset($r['fee_asset']) ? (string) $r['fee_asset'] : '');
        $pnl        = $exitQuote - $entryQuote - $feeExt;
        $fields = [
            'status'        => 'CLOSED',
            'exit_price'    => (float) ($r['price'] ?? 0.0),
            'exit_quote'    => $exitQuote,
            'exit_fee_usdt' => (float) ($r['fee_usdt'] ?? 0.0),
            'pnl_usdt'      => $pnl,
            'pnl_pct'       => $entryQuote > 0.0 ? $pnl / $entryQuote * 100.0 : 0.0,
            'exit_reason'   => $reason,
            'closed_at'     => $this->nowIso(),
        ];
        $this->db->updatePosition((int) $pos['id'], $fields);
        $this->db->insertTrade($this->tradeRow((int) $pos['id'], (string) $pos['symbol'], 'SELL', $r));
        Risk::recordOutcome($this->cfg, $this->db, $pnl, $this->nowIso());
        Log::info('exit: sold ' . $pos['symbol'] . ' (' . $reason . ') pnl ' . sprintf('%+.4f', $pnl) . ' ' . $this->cfg['quote_asset'], [
            'position_id' => $pos['id'], 'qty' => $r['qty'] ?? null, 'price' => $r['price'] ?? null,
            'exit_quote' => $exitQuote, 'entry_quote' => $entryQuote, 'fee_usdt' => $r['fee_usdt'] ?? null, 'pnl_pct' => $fields['pnl_pct'],
        ]);
        return array_merge($pos, $fields);
    }

    /**
     * Fee (in USDT) that was deducted from neither the position qty nor the quote proceeds and so is
     * still missing from `exit_quote - entry_quote` (the BNB-discount case). A BUY commission taken
     * in the base asset already shrank `qty`; a SELL commission taken in the quote asset was already
     * subtracted from `quote`; anything else (BNB) is a real cost nothing has counted yet.
     */
    private function feeUnaccounted(string $symbol, string $side, float $feeUsdt, string $feeAsset): float
    {
        if ($feeUsdt <= 0.0 || $feeAsset === '') {
            return 0.0;
        }
        $quote = (string) $this->cfg['quote_asset'];
        $base  = substr($symbol, 0, max(0, strlen($symbol) - strlen($quote)));
        if ($side === 'BUY' ? $feeAsset === $base : $feeAsset === $quote) {
            return 0.0;
        }
        return $feeUsdt;
    }

    /** Same, for the BUY trade that opened the position. */
    private function entryFeeUnaccounted(int $positionId, string $symbol): float
    {
        $st = $this->db->pdo()->prepare("SELECT fee_usdt, fee_asset FROM trades WHERE position_id = ? AND side = 'BUY' ORDER BY id ASC LIMIT 1");
        $st->execute([$positionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return 0.0;
        }
        return $this->feeUnaccounted($symbol, 'BUY', (float) $row['fee_usdt'], isset($row['fee_asset']) ? (string) $row['fee_asset'] : '');
    }

    private function tradeRow(?int $positionId, string $symbol, string $side, array $r): array
    {
        return [
            'position_id' => $positionId,
            'mode'        => $this->mode,
            'symbol'      => $symbol,
            'side'        => $side,
            'order_id'    => isset($r['order_id']) ? (string) $r['order_id'] : null,
            'client_id'   => isset($r['client_id']) ? (string) $r['client_id'] : null,
            'qty'         => (float) ($r['qty'] ?? 0.0),
            'price'       => (float) ($r['price'] ?? 0.0),
            'quote'       => (float) ($r['quote'] ?? 0.0),
            'fee_usdt'    => (float) ($r['fee_usdt'] ?? 0.0),
            'fee_asset'   => isset($r['fee_asset']) ? (string) $r['fee_asset'] : null,
            'raw'         => $this->encodeResult($r),
            'created_at'  => $this->nowIso(),
        ];
    }

    /** Sum of dust_qty left in the wallet by this symbol's positions in this mode. */
    private function dustCarried(string $symbol): float
    {
        $st = $this->db->pdo()->prepare('SELECT COALESCE(SUM(dust_qty), 0) FROM positions WHERE symbol = ? AND mode = ?');
        $st->execute([$symbol, $this->mode]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? 0.0 : max(0.0, (float) $v);
    }

    /**
     * The one definition of "a STUCK position THIS bot owns".
     *
     * Scalp runs many positions at once and marks its own rows STUCK (Scalper::markStuck()),
     * which it then manages itself on every poll - so retryStuck() and the survival layer must
     * never pick one up, or the two would both try to sell the same inventory. The clause lived
     * inline at seven call sites; it is here so a future engine marker cannot be added to some
     * of them and forgotten at the rest. COALESCE because `engine` is an added column and rows
     * written before it existed read NULL on some databases.
     *
     * Deliberately carries NO `mode` clause, exactly like the seven inline copies it replaces:
     * positionWhere() has never filtered on mode and changing that here would be a behaviour
     * change dressed up as a refactor. (Db::openPosition() is the same; Db::positionWhere()'s
     * SQL is pre-existing and out of scope.)
     */
    const STUCK_WHERE = "status = 'STUCK' AND COALESCE(engine, 'signal') <> 'scalp'";

    /** Latest position row matching $where (typed like Db::openPosition()). */
    private function positionWhere(string $where, array $params): ?array
    {
        $st = $this->db->pdo()->prepare('SELECT * FROM positions WHERE ' . $where . ' ORDER BY id DESC LIMIT 1');
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['qty', 'dust_qty', 'entry_price', 'entry_eff', 'entry_quote', 'entry_fee_usdt', 'exit_price', 'exit_quote',
                  'exit_fee_usdt', 'pnl_usdt', 'pnl_pct', 'stop_price', 'take_profit_price', 'trail_high'] as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) {
                $row[$c] = (float) $row[$c];
            }
        }
        foreach (['id', 'trailing_armed', 'score'] as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) {
                $row[$c] = (int) $row[$c];
            }
        }
        foreach (['mode', 'symbol', 'status', 'entry_reason', 'exit_reason', 'opened_at', 'closed_at'] as $c) {
            if (array_key_exists($c, $row) && $row[$c] !== null) {
                $row[$c] = (string) $row[$c];
            }
        }
        return $row;
    }

    /* ================================================================ error policy */

    /**
     * Applies DESIGN.md §10 error policy. Returns 'abort' (stop the tick) or 'continue'.
     */
    private function handleApiError(BinanceException $e, string $step): string
    {
        $this->apiErrorThisTick = true;
        $code = $e->binanceCode;
        $http = $e->httpStatus;
        $ctx  = ['step' => $step, 'code' => $code, 'http' => $http];

        if ($http === 429 || $http === 418) {
            $ra = $e->retryAfter !== null && $e->retryAfter > 0 ? (int) $e->retryAfter : ($http === 429 ? 120 : 3600);
            $until = Util::nowIso($this->nowSec() + $ra);
            $this->db->setState('api_paused_until', $until);
            $this->db->setState('api_error', 'rate limited (HTTP ' . $http . '), paused until ' . $until);
            Log::error('api: rate limited, paused until ' . $until, $ctx);
            return 'abort';
        }
        if ($code === -2015) {
            $this->db->setState('api_error', 'Invalid API key / IP / permission');
            $this->disableEntries('api_error');
            Log::error('api: invalid API key / IP / permission - trading disabled', $ctx);
            return 'abort';
        }
        if ($e->isNetworkError()) {
            $this->netErrorThisTick = true;
            $n = (int) $this->db->getState('net_errors', '0') + 1;
            $this->db->setState('net_errors', $n);
            $this->db->setState('api_error', 'network: ' . $e->getMessage());
            if ($n >= self::NET_ERRORS_TO_PAUSE) {
                $until = Util::isoAddMinutes($this->nowIso(), self::NET_PAUSE_MINUTES);
                $this->db->setState('api_paused_until', $until);
                $this->db->setState('net_errors', 0);
                Log::error('api: ' . $n . ' consecutive network errors, paused until ' . $until . ' - ' . $e->getMessage(), $ctx);
            } else {
                Log::warn('api: network error ' . $n . '/' . self::NET_ERRORS_TO_PAUSE . ' - ' . $e->getMessage(), $ctx);
            }
            return 'abort';
        }
        $this->db->setState('api_error', $e->getMessage());
        if ($code === -1013) {
            Log::warn('api: filter failure (-1013), refreshing symbol info - ' . $e->getMessage(), $ctx);
            try {
                $this->loadSymbolInfo(true);
            } catch (Throwable $inner) {
                Log::warn('api: symbol info refresh failed - ' . $inner->getMessage());
            }
            return 'continue';
        }
        if ($code === -2010) {
            Log::warn('api: order rejected (-2010), reconciling - ' . $e->getMessage(), $ctx);
            try {
                $this->reconcileOrders();
                $acct   = $this->ex->account();
                $prices = $this->ex->prices($this->valuedSymbols());
                $this->reconcileBalances($acct, $this->analyseBalances($acct, $prices));
            } catch (Throwable $inner) {
                Log::warn('api: reconcile after -2010 failed - ' . $inner->getMessage());
            }
            return 'continue';
        }
        Log::error('api: ' . $e->getMessage(), $ctx);
        return 'continue';
    }

    /** /api/v3/avgPrice for the NOTIONAL pre-check; 0.0 when unavailable (paper mode / API error). */
    private function marketAvgPrice(string $symbol): float
    {
        $ex = $this->ex;
        if (!method_exists($ex, 'api')) {
            return 0.0;
        }
        $v = $this->step('avgPrice:' . $symbol, function () use ($ex, $symbol): float {
            $api = $ex->api();
            return $api instanceof Binance ? (float) $api->avgPrice($symbol) : 0.0;
        }, false);
        return is_numeric($v) && (float) $v > 0.0 ? (float) $v : 0.0;
    }

    /**
     * Runs one API step under the error policy. Essential steps abort the tick on any
     * BinanceException; non-essential ones return null so the caller can skip the item.
     * @return mixed
     */
    private function step(string $name, callable $fn, bool $essential = true)
    {
        try {
            return $fn();
        } catch (BinanceException $e) {
            $action = $this->handleApiError($e, $name);
            if ($action === 'abort' || $essential) {
                throw new BotAbort('error', $name . ' failed: ' . $e->getMessage());
            }
            return null;
        }
    }

    /* ================================================================ state helpers */

    private function halt(string $reason): void
    {
        $already = ((string) $this->db->getState('halted', '0')) === '1'
            && (string) $this->db->getState('halt_reason', '') === $reason;
        $this->db->setState('halted', '1');
        $this->db->setState('halt_reason', $reason);
        $this->disableEntries('halted:' . $reason);
        if (!$already) {
            Log::error('HALT: ' . $reason . ' - trading stopped, manual reset required');
        }
    }

    /** enabled=false in memory and, when config.php is loaded, in data/config.json. */
    private function disableEntries(string $pauseReason): void
    {
        $this->cfg['enabled'] = false;
        try {
            $this->db->setState('pause_reason', $pauseReason);
        } catch (Throwable $e) {
            // state write failure must not stop the disable
        }
        if (function_exists('trader_config') && function_exists('trader_save_config')) {
            try {
                $c = trader_config(true);
                if (!empty($c['enabled'])) {
                    $c['enabled'] = false;
                    trader_save_config($c);
                }
            } catch (Throwable $e) {
                Log::error('disable: could not persist enabled=false - ' . $e->getMessage());
            }
        }
    }

    private function applyFeeFromAccount(array $acct): void
    {
        if ($this->mode === 'paper') {
            $this->feePct = max(0.0, (float) $this->cfg['fee_pct']);
            return;
        }
        if (isset($acct['taker_fee_pct']) && is_numeric($acct['taker_fee_pct']) && (float) $acct['taker_fee_pct'] >= 0.0) {
            $this->feePct = (float) $acct['taker_fee_pct'];
            $this->db->setState('fee_pct_live', $this->feePct);
        }
    }

    private function readUsedWeight(): void
    {
        if (!method_exists($this->ex, 'api')) {
            return;
        }
        try {
            $api = $this->ex->api();
            if ($api instanceof Binance) {
                $this->db->setState('used_weight', $api->lastUsedWeight());
            }
        } catch (Throwable $e) {
            // informational only
        }
    }

    /**
     * Merges per-symbol panel telemetry into state `symbol_metrics`
     * ([SYMBOL => ['price','atr_pct','atr1h_pct','spread_pct','eligible','at']]).
     * Keys absent from $patch keep their previous value. Purely informational:
     * it must never break a tick, so everything is swallowed.
     */
    private function mergeSymbolMetrics(array $patch): void
    {
        if ($patch === []) {
            return;
        }
        try {
            $cur = $this->db->getStateJson('symbol_metrics', []);
            if (!is_array($cur)) {
                $cur = [];
            }
            $at = $this->nowIso();
            foreach ($patch as $sym => $m) {
                if (!is_array($m)) {
                    continue;
                }
                $sym       = strtoupper((string) $sym);
                $m['at']   = $at;
                $prev      = isset($cur[$sym]) && is_array($cur[$sym]) ? $cur[$sym] : [];
                $cur[$sym] = array_merge($prev, $m);
            }
            $this->db->setState('symbol_metrics', $cur);
        } catch (Throwable $e) {
            // panel telemetry only
        }
    }

    /* ================================================================ symbol info */

    /** Cached in state `symbol_info` for 6 h; $force re-fetches. Throws BinanceException. */
    private function loadSymbolInfo(bool $force): void
    {
        $symbols = $this->valuedSymbols();
        $cached  = $this->db->getStateJson('symbol_info', null);
        $cached  = is_array($cached) ? $cached : [];
        if (!$force) {
            $at = (string) $this->db->getState('symbol_info_at', '');
            $ts = $at !== '' ? Util::isoToTs($at) : null;
            $fresh = $ts !== null && ($this->nowSec() - $ts) < self::SYMBOL_INFO_TTL_SEC;
            $complete = $cached !== [];
            foreach ($symbols as $s) {
                if (!isset($cached[$s]) || !is_array($cached[$s])) {
                    $complete = false;
                    break;
                }
            }
            if ($fresh && $complete) {
                $this->info = $cached;
                return;
            }
        }
        $info = $this->ex->symbolInfo($symbols);
        if (!is_array($info) || $info === []) {
            if ($cached !== []) {
                Log::warn('symbol info: empty response, keeping cached copy');
                $this->info = $cached;
                return;
            }
            throw new BinanceException('exchangeInfo returned no symbols for the watchlist', -1000, 200);
        }
        foreach ($symbols as $s) {
            if (!isset($info[$s])) {
                Log::warn('symbol info: ' . $s . ' not returned by the exchange; it will be skipped');
            }
        }
        $merged = $info;
        foreach ($cached as $s => $row) {
            if (!isset($merged[$s]) && is_array($row)) {
                $merged[$s] = $row;   // keep old rows for symbols temporarily missing
            }
        }
        $this->info = $merged;
        $this->db->setState('symbol_info', $merged);
        $this->db->setState('symbol_info_at', $this->nowIso());
    }

    /** Loads the cache when nothing is loaded yet (used outside tick()). */
    private function ensureSymbolInfo(): void
    {
        if ($this->info === []) {
            $this->loadSymbolInfo(false);
        }
    }

    /** Symbol info for one symbol, fetched on demand when not cached. Throws BinanceException. */
    private function infoFor(string $symbol): array
    {
        $this->ensureSymbolInfo();
        if (isset($this->info[$symbol]) && is_array($this->info[$symbol])) {
            return $this->info[$symbol];
        }
        $fetched = $this->ex->symbolInfo([$symbol]);
        if (isset($fetched[$symbol]) && is_array($fetched[$symbol])) {
            $this->info[$symbol] = $fetched[$symbol];
            $this->db->setState('symbol_info', $this->info);
            return $fetched[$symbol];
        }
        $quote = (string) $this->cfg['quote_asset'];
        $base  = $symbol;
        if (strlen($symbol) > strlen($quote) && substr($symbol, -strlen($quote)) === $quote) {
            $base = substr($symbol, 0, -strlen($quote));
        }
        Log::warn('symbol info: ' . $symbol . ' unknown to the exchange, using permissive defaults');
        return ['base' => $base, 'quote' => $quote, 'stepSize' => '0.00000001', 'minNotional' => 0.0, 'applyMinToMarket' => false];
    }

    private function symbolTradable(array $info): bool
    {
        if (isset($info['status']) && (string) $info['status'] !== '' && strtoupper((string) $info['status']) !== 'TRADING') {
            return false;
        }
        if (array_key_exists('spotAllowed', $info) && !$info['spotAllowed']) {
            return false;
        }
        if (array_key_exists('quoteOrderQtyAllowed', $info) && !$info['quoteOrderQtyAllowed']) {
            return false;
        }
        if (isset($info['quote']) && (string) $info['quote'] !== '' && (string) $info['quote'] !== (string) $this->cfg['quote_asset']) {
            return false;
        }
        return true;
    }

    /** Watchlist: upper-case, unique, ending with the quote asset. */
    private function symbols(): array
    {
        $quote = (string) $this->cfg['quote_asset'];
        $raw   = $this->cfg['symbols'];
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $s) {
            if (!is_scalar($s)) {
                continue;
            }
            $s = strtoupper(trim((string) $s));
            if ($s === '' || isset($out[$s])) {
                continue;
            }
            if ($quote !== '' && (strlen($s) <= strlen($quote) || substr($s, -strlen($quote)) !== $quote)) {
                continue;
            }
            $out[$s] = true;
        }
        return array_keys($out);
    }

    /**
     * Watchlist plus the symbol of any OPEN/STUCK position, so a position stays priced and valued
     * even after its symbol is removed from the watchlist. Entries still use symbols() alone, so a
     * de-watchlisted symbol can be sold but never re-entered.
     * @return string[]
     */
    private function valuedSymbols(): array
    {
        $out = [];
        foreach ($this->symbols() as $s) {
            $out[$s] = true;
        }
        foreach ([$this->db->openPosition(), $this->positionWhere("status = 'STUCK'", [])] as $pos) {
            if ($pos !== null) {
                $sym = strtoupper((string) $pos['symbol']);
                if ($sym !== '') {
                    $out[$sym] = true;
                }
            }
        }
        // The scalp engine trades the movers board (DESIGN-SCALP.md §3), so its symbols are
        // normally NOT on the watchlist. Without them here the tick would fetch neither their
        // filters nor their price, and an open scalp position would be missing from equity -
        // which understates it, and an understated equity is what trips the floor kill switch.
        // Added whatever engine is selected: a position does not stop being real because the
        // user switched the selector.
        foreach ($this->scalpKeepSymbols() as $sym) {
            $out[$sym] = true;
        }
        return array_keys($out);
    }

    /** TP percent: the signal's tp_pct clamped to [take_profit_pct, take_profit_max_pct]; config TP when no signal. */
    private function effectiveTpPct(?float $signalTp): float
    {
        $min = (float) $this->cfg['take_profit_pct'];
        $max = (float) $this->cfg['take_profit_max_pct'];
        if ($max < $min) {
            $max = $min;
        }
        if ($signalTp === null || !is_finite($signalTp) || $signalTp <= 0.0) {
            return $min;
        }
        return Util::clamp($signalTp, $min, $max);
    }

    /* ================================================================ time */

    private function nowMs(): int
    {
        return $this->fixedNowMs !== null ? $this->fixedNowMs : Util::nowMs();
    }

    private function nowSec(): int
    {
        return intdiv($this->nowMs(), 1000);
    }

    private function nowIso(): string
    {
        return Util::nowIso($this->nowSec());
    }

    /** Exchange clock for candle-close detection (the injected clock wins in tests). */
    private function serverTimeMs(): int
    {
        if ($this->fixedNowMs !== null) {
            return $this->fixedNowMs;
        }
        try {
            return $this->ex->serverTimeMs();
        } catch (Throwable $e) {
            return Util::nowMs();
        }
    }

    /* ================================================================ misc */

    private function buildSummary(): string
    {
        $q = (string) $this->cfg['quote_asset'];
        $parts = [];
        $parts[] = 'equity ' . ($this->equity !== null ? Util::money($this->equity) : '?') . ' ' . $q;
        $parts[] = 'free ' . ($this->quoteFree !== null ? Util::money($this->quoteFree) : '?');
        $parts[] = 'position ' . ($this->posSummary !== '' ? $this->posSummary : 'none');
        $w = $this->db->getState('used_weight', null);
        if ($w !== null) {
            $parts[] = 'weight ' . $w;
        }
        $parts[] = 'decision ' . ($this->noTradeReason !== '' ? $this->noTradeReason : 'none');
        if ($this->notes !== []) {
            $parts[] = implode('; ', $this->notes);
        }
        return implode(' | ', $parts);
    }

    private function encodeResult(array $r): string
    {
        $raw = isset($r['raw']) && is_array($r['raw']) ? $r['raw'] : [];
        unset($r['raw']);
        $json = json_encode(['result' => $r, 'raw' => $raw], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? '{}' : $json;
    }

    private function resultForLog(array $r): array
    {
        unset($r['raw']);
        return $r;
    }
}
