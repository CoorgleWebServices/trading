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
require_once __DIR__ . '/EngineOrders.php';
// the two engine implementations are optional at load time: a signal-engine install
// (or a partial deployment) must still be able to run a tick.
if (is_file(__DIR__ . '/EngineGrid.php')) {
    require_once __DIR__ . '/EngineGrid.php';
}
if (is_file(__DIR__ . '/EnginePmm.php')) {
    require_once __DIR__ . '/EnginePmm.php';
}
// portfolio mode (docs/DESIGN-PORTFOLIO.md) is equally optional: a single-engine install
// never loads these and takes exactly the code path it took before they existed.
if (is_file(__DIR__ . '/Sleeve.php')) {
    require_once __DIR__ . '/Sleeve.php';
}
if (is_file(__DIR__ . '/Scanner.php')) {
    require_once __DIR__ . '/Scanner.php';
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
    /** Block reasons that must take an engine's whole ladder off the book (DESIGN-ENGINES.md §9). */
    const ENGINE_CAPITAL_BLOCKS = ['halted', 'disabled', 'api_paused', 'daily_cap', 'weekly_cap'];
    /** Portfolio mode: a tick stops starting new sleeves after this many ms (DESIGN-PORTFOLIO.md §6). */
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
    /** @var string signal | grid | pmm */
    private $engine;
    /** @var string the single symbol grid/pmm trade; '' for the signal engine */
    private $engineSymbol = '';
    /** @var EngineOrders[] one per symbol|engine, built on demand */
    private $engineOrdersCache = [];
    /** @var bool portfolio mode (DESIGN-PORTFOLIO.md); false ⇒ the single-engine tick, unchanged */
    private $portfolio = false;

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
    /** @var float bid of the engine symbol this tick (0.0 when unknown) */
    private $engineBid = 0.0;
    /** @var float ask of the engine symbol this tick (0.0 when unknown) */
    private $engineAsk = 0.0;
    /** @var array symbol => ['bid'=>float,'ask'=>float] read this tick (portfolio mode) */
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
        $this->portfolio  = class_exists('Sleeve') && Sleeve::portfolioEnabled($this->cfg);
        // in portfolio mode the sleeves own the symbols, so the single `engine_symbol`
        // is not part of the picture at all
        if (!$this->portfolio && Risk::isContinuousEngine($this->engine)) {
            $this->engineSymbol = strtoupper(trim((string) $this->cfg['engine_symbol']));
        }
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
        $this->engineBid        = 0.0;
        $this->engineAsk        = 0.0;
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
                $this->obsResolveCycles();
            }
        } catch (Throwable $e) {
            // observation capture is never allowed to affect the trading result
        }

        if ($status === 'ok' && $this->apiErrorThisTick) {
            $status = 'error';
        }
        if ($status !== 'skipped') {
            try {
                $this->db->prune();
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
            $pos = $this->positionWhere("status = 'STUCK'", []);
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
        // the engine's equity counts its base at the bid, so the book is read before the snapshot
        $this->loadEngineBook();
        $this->loadPortfolioBooks();
        $bal = $this->analyseBalances($acct, $prices);
        $this->reconcileBalances($acct, $bal);
        $bal = $this->analyseBalances($acct, $prices);   // positions may have changed

        // ---- 3. equity
        $this->equity    = $bal['equity'];
        $this->quoteFree = $bal['quote_free'];
        $this->db->insertEquity($bal['equity'], $bal['quote_free'], $bal['position_value'], $bal['dust_value']);
        $this->readUsedWeight();

        $open  = $this->db->openPosition();
        $stuck = $open === null ? $this->positionWhere("status = 'STUCK'", []) : null;
        if ($open !== null && $bal['equity'] <= 0.0) {
            // an empty balance snapshot with a tracked position is not a kill-switch case, it is bad data
            Log::error('tick: equity is zero while a position is open; skipping survival check', ['symbol' => $open['symbol']]);
            $this->noTradeReason = 'equity_unavailable';
            throw new BotAbort('error', 'equity unavailable while position ' . $open['symbol'] . ' is open');
        }

        // ---- 4. survival layer
        // read the halted flag BEFORE the check: survivalCheck() writes halted=1 itself on a fresh breach
        $already = ((string) $this->db->getState('halted', '0')) === '1';
        $hasInventory = $this->engineActive() && $this->engineInventoryQty() > 0.0;
        if ($this->portfolio) {
            $hasInventory = $this->portfolioInventorySymbols() !== [];
        }
        $sv = Risk::survivalCheck($this->cfg, $this->db, $bal['equity'], $open !== null || $stuck !== null || $hasInventory, $bal['exchange_has_base']);
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
            if ($hasInventory) {
                if ($this->portfolio) {
                    foreach ($this->portfolioInventorySymbols() as $invSym) {
                        $flat = $this->flattenInventory($invSym);
                        $this->notes[] = !empty($flat['ok'])
                            ? 'inventory flattened ' . $invSym
                            : 'inventory NOT flattened ' . $invSym . ' (see log)';
                    }
                } else {
                    $flat = $this->flattenInventory();
                    $this->notes[] = !empty($flat['ok']) ? 'inventory flattened' : 'inventory NOT flattened (see log)';
                }
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

        // ---- 5-7. portfolio mode runs every sleeve instead (DESIGN-PORTFOLIO.md §6)
        if ($this->portfolio) {
            $this->runPortfolioTick($bal, $acct, $prices);
            return ((string) $this->db->getState('halted', '0')) === '1' ? 'halted' : 'ok';
        }

        // ---- 5-7. grid / pmm run their own path (DESIGN-ENGINES.md §9)
        if ($this->engine !== 'signal') {
            $this->runEngineTick($bal);
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
        $stuck = $open === null ? $this->positionWhere("status = 'STUCK'", []) : null;
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
        // grid/pmm trade one symbol that need not be on the watchlist; its base — free AND locked
        // in resting orders — is inventory, not a loss, so it is valued at the bid and counted.
        // In portfolio mode the same is true of every symbol a grid/pmm SLEEVE owns, and every
        // sleeve symbol has to be valued so the global kill switch sees the whole account.
        $engineSyms = $this->inventorySymbols();
        foreach ($this->portfolioSymbols() as $pSym) {
            if (!in_array($pSym, $symbols, true)) {
                $symbols[] = $pSym;
            }
        }
        foreach (array_keys($engineSyms) as $eSym) {
            if (!in_array($eSym, $symbols, true)) {
                $symbols[] = $eSym;
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
            $row = ['symbol' => $sym, 'base' => $base, 'free' => $free, 'total' => $total, 'price' => $price, 'value' => $value, 'tracked' => isset($tracked[$sym]), 'untracked_big' => false];
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
                    if ($minNotional > 0.0 && $value >= $minNotional) {
                        $row['untracked_big'] = true;
                    }
                }
                if (!$isEngine && $minNotional > 0.0 && $value >= $minNotional) {
                    $hasBase = true;
                }
            }
            $bases[$sym] = $row;
        }
        return [
            'quote_free'        => $qFree,
            'quote_total'       => $qFree + $qLocked,
            'equity'            => $qFree + $qLocked + $posValue + $dustValue,
            'position_value'    => $posValue,
            'dust_value'        => $dustValue,
            'exchange_has_base' => $hasBase,
            'bases'             => $bases,
        ];
    }

    /** Untracked base balance ⇒ HALT; OPEN position whose base is gone ⇒ CLOSED reconciled_missing. */
    private function reconcileBalances(array $acct, array $bal): void
    {
        foreach ($bal['bases'] as $sym => $row) {
            if (!empty($row['untracked_big'])) {
                Log::error('reconcile: untracked ' . $row['base'] . ' balance worth ' . Util::money((float) $row['value']) . ' ' . $this->cfg['quote_asset'] . ' with no tracked position - halting', [
                    'symbol' => $sym, 'balance' => $row['total'], 'price' => $row['price'],
                ]);
                $this->halt('untracked_position');
                $this->notes[] = 'HALT untracked_position ' . $sym;
                break;
            }
        }
        $open = $this->db->openPosition();
        if ($open === null) {
            $open = $this->positionWhere("status = 'STUCK'", []);
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
     * Step 6. $symbols and $available are the portfolio-mode restrictions (DESIGN-PORTFOLIO.md
     * §6): the sleeve's own symbols, and the quote it may still commit. Both default to the
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

    /* ================================================================ engines (DESIGN-ENGINES.md §9) */

    /**
     * Cancels every resting engine order, whatever engine placed it. Safe to call when no
     * engine is active: without a live row in `engine_orders` it touches neither the
     * exchange nor the config, so the signal engine pays one indexed SELECT and nothing else.
     *
     * @return int number of orders that left the book
     */
    public function cancelAllEngineOrders(string $reason): int
    {
        $symbols = $this->liveEngineSymbols();
        if ($symbols === []) {
            return 0;
        }
        $n = 0;
        foreach ($symbols as $sym) {
            try {
                $orders = $this->engineOrdersFor($sym, $this->engineOf($sym));
                if ($orders === null) {
                    continue;
                }
                $n += (int) $orders->cancelAll($sym, $reason);
            } catch (BinanceException $e) {
                $this->handleApiError($e, 'engine_cancel_all:' . $sym);
            } catch (Throwable $e) {
                Log::error('engine cancelAll ' . $sym . ' failed: ' . $e->getMessage(), ['reason' => $reason]);
            }
        }
        return $n;
    }

    /**
     * Takes the resting orders off the book so $symbol can be flattened. In portfolio mode
     * only that symbol's own sleeve is touched: flattening one sleeve must never strip
     * another sleeve's quotes off the book (DESIGN-PORTFOLIO.md §3 - sleeves are isolated).
     * Single-engine mode keeps the account-wide cancel it has always done.
     */
    private function cancelForFlatten(string $symbol, string $engine, string $reason): int
    {
        if (!$this->portfolio) {
            return $this->cancelAllEngineOrders($reason);
        }
        $orders = $this->engineOrdersFor($symbol, $engine);
        if ($orders === null) {
            return 0;
        }
        try {
            return (int) $orders->cancelAll($symbol, $reason);
        } catch (BinanceException $e) {
            $this->handleApiError($e, 'engine_cancel_all:' . $symbol);
        } catch (Throwable $e) {
            Log::error('flatten cancelAll ' . $symbol . ' failed: ' . $e->getMessage(), ['reason' => $reason]);
        }
        return 0;
    }

    /**
     * Panel action "Re-anchor grid": re-centres the ladder on the current mid and clears the
     * pause a range exit left behind. Safe to call with any engine selected.
     */
    public function reanchorGrid(): void
    {
        $sym = $this->actionSymbol();
        if ($sym === '') {
            Log::warn('reanchor: no engine symbol configured');
            return;
        }
        try {
            $mid = $this->engineMid($sym);
            if ($mid <= 0.0) {
                Log::warn('reanchor: no book price for ' . $sym);
                return;
            }
            $grid = $this->makeGrid($sym, null, $this->portfolio ? 'grid' : '');
            if ($grid !== null) {
                $grid->reanchor($mid);
            } else {
                $this->db->setState('grid_anchor', $mid);
                $this->db->setState('grid_anchor_at', $this->nowIso());
                $this->db->setState('grid_symbol', $sym);
            }
            $wasGridPause = (string) $this->db->getState('grid_paused_reason', '') === 'grid_range_exit';
            $this->db->setState('grid_paused_reason', '');
            if ($wasGridPause || (string) $this->db->getState('pause_reason', '') === 'grid_range_exit') {
                $this->db->setState('paused_until', '');
                $this->db->setState('pause_reason', '');
            }
            Log::info('reanchor: grid re-anchored on ' . $sym . ' at ' . Util::money($mid, 8));
        } catch (BinanceException $e) {
            $this->handleApiError($e, 'reanchor:' . $sym);
        } catch (Throwable $e) {
            Log::error('reanchor failed: ' . $e->getMessage(), ['symbol' => $sym]);
        }
    }

    /**
     * Panel action "Flatten inventory": cancels the resting orders and market-sells the whole
     * engine inventory of the engine symbol. Safe to call when no engine is active (it then
     * reports that there is nothing to sell). The sell is booked through EngineOrders, so the
     * lots are consumed FIFO and the cycles are written exactly like an engine sell.
     *
     * $symbol names the sleeve symbol to flatten (portfolio mode); empty keeps the historical
     * behaviour of flattening whatever the single engine holds.
     *
     * @return array{ok:bool, message:string, symbol:string, qty:float, quote:float, cancelled:int}
     */
    public function flattenInventory(string $symbol = ''): array
    {
        $sym = $symbol !== '' ? strtoupper(trim($symbol)) : $this->actionSymbol();
        $out = ['ok' => false, 'message' => '', 'symbol' => $sym, 'qty' => 0.0, 'quote' => 0.0, 'cancelled' => 0];
        if ($sym === '') {
            $out['message'] = 'No engine symbol configured.';
            return $out;
        }
        // The demo-only rule (DESIGN-ENGINES.md §1) is about ORDERS, not about ticks: while
        // grid/pmm are blocked in live mode the bot must not place a single one, and this
        // action ends in a real market SELL. Cancelling is still allowed below - it only ever
        // takes risk off. Selling leftover inventory stays reachable: switch the engine back
        // to `signal` (or turn allow_live_engines on) and flatten from there.
        $flatEngine = $this->engineOf($sym);
        if ($flatEngine !== 'signal' && $this->engineLiveBlocked($flatEngine, $sym)) {
            $out['cancelled'] = $this->cancelForFlatten($sym, $flatEngine, 'engine_live_blocked');
            $out['message']   = 'The ' . $flatEngine . ' engine is blocked in live mode (allow_live_engines is off),'
                              . ' so nothing was sold. Switch the engine back to "signal" to flatten the inventory.';
            return $out;
        }
        $out['cancelled'] = $this->cancelForFlatten($sym, $flatEngine, 'flatten_inventory');
        try {
            $info = $this->infoFor($sym);
            $bid  = $this->bidOf($sym) > 0.0 ? $this->bidOf($sym) : (float) ($this->ex->bookTicker($sym)['bid'] ?? 0.0);
            if ($bid <= 0.0) {
                $out['message'] = 'No bid price for ' . $sym . '.';
                return $out;
            }
            $acct     = $this->ex->account();
            $balances = isset($acct['balances']) && is_array($acct['balances']) ? $acct['balances'] : [];
            $base     = (string) ($info['base'] ?? '');
            $baseFree = ($base !== '' && isset($balances[$base])) ? (float) ($balances[$base]['free'] ?? 0.0) : 0.0;
            $step     = (string) ($info['stepSize'] ?? '0.00000001');
            $inv      = $this->engineInventoryQty($sym);
            $qtyStr   = Util::floorToStep(min($inv, $baseFree), $step);
            $qty      = (float) $qtyStr;
            if ($qty <= 0.0) {
                $out['message'] = 'No engine inventory to sell.';
                return $out;
            }
            $minNotional = (float) ($info['minNotional'] ?? 0.0);
            if ($minNotional > 0.0 && $qty * $bid < $minNotional) {
                $out['qty']     = $qty;
                $out['message'] = 'Inventory is below the minimum notional (dust); nothing sold.';
                Log::warn('flatten: inventory below minNotional, left in the wallet', [
                    'symbol' => $sym, 'qty' => $qty, 'bid' => $bid, 'min_notional' => $minNotional,
                ]);
                return $out;
            }
            $cid = $this->newEngineClientId('SELL', $sym);
            if ($cid === null) {
                $out['message'] = 'Could not allocate a client id for the sell.';
                return $out;
            }
            // the inventory may have been bought by another engine (the user switched engines);
            // book the sell against the engine that owns the lots, or nothing is consumed
            $lotEngine = $this->inventoryEngine($sym);
            $this->db->insertEngineOrder([
                'client_id'  => $cid,
                'mode'       => $this->mode,
                'engine'     => $lotEngine,
                'symbol'     => $sym,
                'side'       => 'SELL',
                'status'     => 'SENDING',
                'price'      => $bid,
                'qty'        => $qty,
                'quote'      => $qty * $bid,
                'purpose'    => 'flatten',
                'created_at' => $this->nowIso(),
            ]);
            $r = $this->ex->marketSell($sym, $qtyStr, $info, $cid);
            $filled = (float) ($r['qty'] ?? 0.0);
            $orders = $this->engineOrdersBookingAs($sym, $lotEngine);
            if ($orders !== null) {
                $orders->bookFill(['client_id' => $cid], $r, $filled > 0.0 ? 'FILLED' : 'EXPIRED');
            } else {
                $this->db->updateEngineOrder($cid, ['status' => $filled > 0.0 ? 'FILLED' : 'EXPIRED', 'updated_at' => $this->nowIso()]);
            }
            if ($filled <= 0.0) {
                $out['message'] = 'The sell executed nothing; the inventory is untouched.';
                Log::warn('flatten: sell executed nothing', ['symbol' => $sym, 'requested_qty' => $qtyStr]);
                return $out;
            }
            $out['ok']      = true;
            $out['qty']     = $filled;
            $out['quote']   = (float) ($r['quote'] ?? 0.0);
            $out['message'] = 'Sold ' . Util::money($filled, 8) . ' ' . ($base !== '' ? $base : $sym)
                            . ' for ' . Util::money($out['quote']) . ' ' . $this->cfg['quote_asset'] . '.';
            Log::warn('flatten: engine inventory sold', [
                'symbol' => $sym, 'qty' => $filled, 'quote' => $out['quote'], 'client_id' => $cid,
            ]);
            return $out;
        } catch (BinanceException $e) {
            $this->handleApiError($e, 'flatten:' . $sym);
            $out['message'] = 'Sell failed: ' . $e->getMessage();
            return $out;
        } catch (Throwable $e) {
            Log::error('flatten failed: ' . $e->getMessage(), ['symbol' => $sym]);
            $out['message'] = 'Sell failed: ' . $e->getMessage();
            return $out;
        }
    }

    /**
     * The grid / pmm branch of the tick, run instead of steps 5-7 (DESIGN-ENGINES.md §9):
     * live-mode guard, order synchronisation, capital pause, engine tick.
     */
    private function runEngineTick(array $bal): void
    {
        // ---- 1. demo-only rule: grid/pmm never place a single order in live mode
        if ($this->engineLiveBlocked()) {
            $this->noTradeReason = 'engine_live_blocked';
            $this->notes[]       = 'engine ' . $this->engine . ' blocked in live mode';
            $this->obsEngine($this->engine, $this->engineSymbol, 'skipped', 'engine_live_blocked', 0.0, 0.0);
            // cancelling is not placing: a ladder left resting by an earlier allow_live_engines
            // run has to come off the book here, because nothing else takes it off and no sync
            // runs to book its fills. Without a live row this is one indexed SELECT and no call.
            $n = $this->cancelAllEngineOrders('engine_live_blocked');
            if ($n > 0) {
                $this->notes[] = $n . ' engine order(s) cancelled (live blocked)';
            }
            return;
        }
        $sym = $this->engineSymbol;
        if ($sym === '') {
            $this->noTradeReason = 'engine_no_symbol';
            return;
        }
        $orders = $this->engineOrdersFor($sym);
        if ($orders === null) {
            $this->noTradeReason = 'engine_no_symbol_info';
            return;
        }

        // ---- 2. reconcile the resting orders and book every new fill
        $this->obsLotBaseline($sym, $this->engine);
        $sum = $this->step('engine_sync:' . $sym, function () use ($orders, $sym): array {
            return $orders->sync($sym);
        }, false);
        if (is_array($sum)) {
            $nFilled = isset($sum['filled']) && is_array($sum['filled']) ? count($sum['filled']) : 0;
            if ($nFilled > 0) {
                $this->notes[] = $nFilled . ' engine fill(s) booked';
            }
        }

        // ---- 3. a capital pause stops the quoting and takes the ladder off the book
        $block = (string) Risk::entryBlockReason($this->cfg, $this->db, $bal['quote_free'], $bal['equity'], $this->engine);
        if ($block !== '' && self::isCapitalBlock($block)) {
            $this->noTradeReason = $block;
            $this->obsEngine($this->engine, $sym, 'skipped', $block, $this->engineBid, $this->engineAsk);
            $n = $this->cancelAllEngineOrders($block);
            if ($n > 0) {
                $this->notes[] = 'engine paused (' . $block . '): ' . $n . ' order(s) cancelled';
            }
            return;
        }
        if ($block !== '') {
            // not a capital reason (a short quote balance): the engine keeps running, because it
            // still has to manage the inventory it already holds - sells and re-quotes go on.
            $this->notes[] = 'engine: ' . $block;
        }

        // ---- 4. the engine itself
        $bid = $this->engineBid;
        $ask = $this->engineAsk;
        if ($bid <= 0.0 || $ask <= 0.0) {
            $this->noTradeReason = 'engine_no_book';
            $this->obsEngine($this->engine, $sym, 'skipped', 'engine_no_book', 0.0, 0.0);
            Log::warn('engine: no book price, tick skipped', ['symbol' => $sym]);
            return;
        }
        $baseFree = isset($bal['bases'][$sym]['free']) ? (float) $bal['bases'][$sym]['free'] : 0.0;
        $engine   = $this->engine;
        $lotsBefore = $this->obsLotsSampled($sym, $engine);
        $r = $this->step('engine_tick:' . $sym, function () use ($engine, $sym, $orders, $bid, $ask, $baseFree, $bal) {
            if ($engine === 'grid') {
                $g = $this->makeGrid($sym, $orders);
                return $g === null ? null : $g->tick($bid, $ask, (float) $bal['quote_free']);
            }
            $p = $this->makePmm($sym, $orders);
            return $p === null ? null : $p->tick($bid, $ask, $baseFree, (float) $bal['quote_free']);
        }, false);
        if (!is_array($r)) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'engine_unavailable';
            }
            return;
        }
        $action = (string) ($r['action'] ?? '');
        $detail = (string) ($r['detail'] ?? '');
        $this->obsEngineAction($engine, $sym, $action, $lotsBefore, $bid, $ask);
        $this->noTradeReason = $engine . ':' . ($action !== '' ? $action : 'idle');
        if ($detail !== '') {
            $this->notes[] = $engine . ' ' . ($action !== '' ? $action : 'idle') . ' ' . $detail;
        }
        $this->posSummary = $engine . ' ' . $sym
            . ' orders=' . count($this->db->openEngineOrders($sym, $this->mode))
            . ' inv=' . Util::money($this->engineInventoryQty($sym), 8)
            . ' bid=' . Util::money($bid, 8);
    }

    /* ================================================================ portfolio (DESIGN-PORTFOLIO.md §6) */

    /**
     * The portfolio branch of the tick, run instead of steps 5-7 when `portfolio_enabled`.
     * Steps 1-4 (time, symbol info, reconcile, balances, total equity, the GLOBAL survival
     * check and its kill switch) have already run above, unchanged and on the whole account.
     *
     * Here: the scanner at most once per tick, then every enabled sleeve in a rotating order
     * persisted in `sleeve_cursor`, each restricted to its own symbols and its own available
     * budget, each with its own drawdown pause, and the loop stopping early when the tick has
     * used up TICK_TIME_BUDGET_MS.
     */
    private function runPortfolioTick(array $bal, array $acct, array $prices): void
    {
        // ---- 2. volatility scanner: one refresh per tick at most, and never in the second
        //         half of the time budget - the sleeves are what actually trades
        $this->maybeRunScanner();

        // ---- 3. the sleeves, rotating so a long tick cannot starve the same sleeve twice
        $order = [];
        foreach (Sleeve::all($this->cfg) as $engine => $sleeve) {
            if (!empty($sleeve['enabled'])) {
                $order[] = (string) $engine;
            }
        }
        // An open signal position outlives its sleeve's `enabled` flag and its symbol list.
        // DESIGN.md §3 makes `enabled` a master switch for NEW ENTRIES only - "exits are always
        // managed" - so a disabled, removed or emptied signal sleeve must never strand a position
        // with no stop, no take-profit, no trailing and no max-hold. `runSignalSleeve()` is the
        // only other caller of `managePosition()`, and `runSleeve()` returns early on an empty
        // symbol list, so that case has to be covered here too. Step 5 runs here; entries stay off.
        $signalRuns = in_array('signal', $order, true) && Sleeve::symbols($this->cfg, 'signal') !== [];
        if (!$signalRuns) {
            $openPos = $this->db->openPosition();
            $stuckPos = $openPos === null ? $this->positionWhere("status = 'STUCK'", []) : null;
            if ($openPos !== null) {
                $this->managePosition($openPos, $acct);
            } elseif ($stuckPos !== null) {
                $this->retryStuck($stuckPos, $acct);
            }
        }

        $n = count($order);
        if ($n === 0) {
            $this->noTradeReason = 'portfolio_no_sleeve';
            return;
        }
        $cursor = (int) $this->db->getState('sleeve_cursor', '0');
        if ($cursor < 0) {
            $cursor = 0;
        }
        $cursor  = $cursor % $n;
        $budget  = $this->tickBudgetMs();
        $parts   = [];
        $skipped = [];
        $done    = 0;
        for ($i = 0; $i < $n; $i++) {
            $engine = $order[($cursor + $i) % $n];
            // at least one sleeve always runs, otherwise a slow tick would starve every sleeve
            if ($i > 0 && $this->elapsedMs() >= $budget) {
                $skipped[] = $engine;
                continue;
            }
            $before              = $this->noTradeReason;
            $this->noTradeReason = '';
            try {
                $this->runSleeve($engine, $bal, $acct, $prices);
            } catch (BotAbort $e) {
                // The aborting sleeve has had its turn. Leaving the cursor where it is would give
                // a sleeve that aborts every tick (a delisted symbol, a repeated order rejection)
                // the first slot for ever, and the sleeves behind it would never run again -
                // their ladders never synced, their fills never booked (DESIGN-PORTFOLIO.md §6.3).
                try {
                    $this->db->setState('sleeve_cursor', $this->nextSleeveCursor($cursor, $done + 1, $n));
                } catch (Throwable $inner) {
                    // best effort: never replace the abort with a storage error
                }
                throw $e;
            } catch (Throwable $e) {
                Log::error('sleeve ' . $engine . ' failed: ' . get_class($e) . ': ' . $e->getMessage(), [
                    'file' => basename($e->getFile()) . ':' . $e->getLine(),
                ]);
                $this->noTradeReason = 'sleeve_error';
            }
            $parts[] = $engine . '=' . ($this->noTradeReason !== '' ? $this->noTradeReason : 'idle');
            if ($this->noTradeReason === '' && $before !== '') {
                $this->noTradeReason = $before;
            }
            $done++;
        }
        $this->db->setState('sleeve_cursor', $this->nextSleeveCursor($cursor, $done, $n));

        $reason = 'portfolio:' . implode(' ', $parts);
        if ($skipped !== []) {
            $reason .= ' skipped:' . implode('+', $skipped) . '(time_budget)';
            $this->notes[] = 'time budget ' . $budget . ' ms exceeded, sleeve(s) skipped: ' . implode(', ', $skipped);
            Log::warn('portfolio: tick time budget exceeded, sleeves skipped', [
                'skipped' => $skipped, 'elapsed_ms' => (int) round($this->elapsedMs()), 'budget_ms' => $budget,
            ]);
        }
        $this->noTradeReason = $reason;
    }

    /**
     * The sleeve the next tick starts at: the first one this tick did not reach, and when every
     * sleeve ran, one past the current start so the order keeps rotating. $done counts the sleeves
     * this tick used up - a sleeve that aborted the tick counts as used up too.
     */
    private function nextSleeveCursor(int $cursor, int $done, int $n): int
    {
        if ($n <= 0) {
            return 0;
        }
        return $done >= $n ? ($cursor + 1) % $n : ($cursor + $done) % $n;
    }

    /**
     * One sleeve: the demo-only guard, its live picture, its drawdown pause, its engine
     * restricted to its own symbols and available budget, and its equity sample.
     */
    private function runSleeve(string $engine, array $bal, array $acct, array $prices): void
    {
        $symbols = Sleeve::symbols($this->cfg, $engine);
        if ($symbols === []) {
            $this->noTradeReason = 'sleeve_no_symbols';
            return;
        }

        // grid/pmm never place an order in live mode without allow_live_engines; a ladder left
        // resting by an earlier run still has to come off the book (cancelling is not placing)
        if (Risk::isContinuousEngine($engine) && $this->engineLiveBlocked($engine, $symbols[0])) {
            $this->noTradeReason = 'engine_live_blocked';
            $this->notes[]       = 'sleeve ' . $engine . ' blocked in live mode';
            $n = 0;
            foreach ($symbols as $sym) {
                $orders = $this->engineOrdersFor($sym, $engine);
                if ($orders !== null) {
                    try {
                        $n += (int) $orders->cancelAll($sym, 'engine_live_blocked');
                    } catch (BinanceException $e) {
                        $this->handleApiError($e, 'engine_cancel_all:' . $sym);
                    }
                }
            }
            if ($n > 0) {
                $this->notes[] = $n . ' order(s) cancelled (live blocked)';
            }
            return;
        }

        // fills since the last tick are booked BEFORE the sleeve reads its own picture:
        // `Sleeve::state()` values inventory from the wallet snapshot but costs it from `lots`,
        // so an unbooked sell would understate `available`, the drawdown pause and the equity
        // sample by the whole sale proceeds (DESIGN-PORTFOLIO.md §3).
        if (Risk::isContinuousEngine($engine)) {
            $this->syncSleeveOrders($engine, $symbols[0]);
        }

        $state = Sleeve::state($this->cfg, $this->db, $engine, $acct, $this->sleevePrices($prices, $symbols));
        // the operator's own pause (panel `pause_sleeve`, state value 'manual') and the automatic
        // drawdown pause are separate conditions: either one stops NEW exposure, and the drawdown
        // check must still run (and log) so it never overwrites or clears the manual flag.
        $manual = (string) $this->db->getState('sleeve_paused_' . $engine, '') === 'manual';
        $drawdn = $this->sleeveDrawdownPaused($engine, $state);
        if ($manual) {
            $this->notes[] = 'sleeve ' . $engine . ' paused (manual)';
        }
        $paused    = $manual || $drawdn;
        $available = $paused ? 0.0 : (float) $state['available'];

        if ($engine === 'signal') {
            $this->runSignalSleeve($bal, $acct, $symbols, $available, $paused);
        } else {
            if (count($symbols) > 1) {
                // the validator refuses this; a hand-edited config.json can still produce it
                Log::warn('sleeve ' . $engine . ' is a single-symbol engine: only ' . $symbols[0] . ' is traded', [
                    'symbols' => $symbols,
                ]);
            }
            $this->runEngineSleeve($engine, $symbols[0], $available, $bal);
        }

        try {
            $this->db->insertSleeveEquity([
                'mode'            => $this->mode,
                'engine'          => $engine,
                'equity'          => (float) $state['equity'],
                'budget'          => (float) $state['budget'],
                'realised'        => (float) $state['realised'],
                'unrealised'      => (float) $state['unrealised'],
                'inventory_value' => (float) $state['inventory_value'],
                'reserved'        => (float) $state['reserved'],
                'ts'              => $this->nowIso(),
            ]);
        } catch (Throwable $e) {
            // the per-sleeve equity series is panel telemetry: never fail a tick for it
        }
    }

    /**
     * Per-sleeve drawdown pause (DESIGN-PORTFOLIO.md §6.4): a sleeve whose equity has fallen to
     * `budget × (1 − sleeve_max_drawdown_pct/100)` stops opening new exposure while the other
     * sleeves keep trading. The pause is a live condition recorded in `sleeve_paused_<engine>`
     * for the panel; it lifts by itself when the sleeve's equity recovers.
     */
    private function sleeveDrawdownPaused(string $engine, array $state): bool
    {
        $key    = 'sleeve_paused_' . $engine;
        $was    = (string) $this->db->getState($key, '');
        // a manual pause is the operator's, not ours: never overwrite it and never clear it here
        $manual = $was === 'manual';
        $budget = (float) $state['budget'];
        $dd     = Risk::sleeveMaxDrawdownPct($this->cfg);
        if ($budget <= 0.0 || $dd <= 0.0) {
            if ($was !== '' && !$manual) {
                $this->db->setState($key, '');
            }
            return false;
        }
        $limit = $budget * (1.0 - $dd / 100.0);
        if ((float) $state['equity'] <= $limit) {
            if ($was === '' && !$manual) {
                $this->db->setState($key, $this->nowIso());
                Log::warn('sleeve ' . $engine . ' paused: equity ' . Util::money((float) $state['equity'])
                    . ' is at or below ' . Util::money($limit) . ' (' . Util::money($dd, 2) . '% of its budget)', [
                    'engine' => $engine, 'budget' => $budget, 'equity' => $state['equity'],
                ]);
            }
            $this->notes[] = 'sleeve ' . $engine . ' drawdown-paused';
            return true;
        }
        if ($was !== '' && !$manual) {
            $this->db->setState($key, '');
            Log::info('sleeve ' . $engine . ' drawdown pause cleared', ['equity' => $state['equity'], 'limit' => $limit]);
        }
        return false;
    }

    /** The signal sleeve: steps 5 and 6 restricted to the sleeve's symbols and budget. */
    private function runSignalSleeve(array $bal, array $acct, array $symbols, float $available, bool $paused): void
    {
        // ---- 5. position management. Exits are ALWAYS managed, drawdown pause or not.
        $open  = $this->db->openPosition();
        $stuck = $open === null ? $this->positionWhere("status = 'STUCK'", []) : null;
        if ($open !== null) {
            $this->managePosition($open, $acct);
        } elseif ($stuck !== null) {
            $this->retryStuck($stuck, $acct);
        }

        // ---- 6. entries
        $open  = $this->db->openPosition();
        $stuck = $open === null ? $this->positionWhere("status = 'STUCK'", []) : null;
        if ($open !== null || $stuck !== null) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = $open !== null ? 'position_open' : 'position_stuck';
            }
            return;
        }
        if ($this->ordersThisTick > 0) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'order_sent_this_tick';
            }
            return;
        }
        if ($paused) {
            $this->noTradeReason = 'sleeve_drawdown';
            return;
        }
        $this->evaluateEntries($bal['quote_free'], $bal['equity'], $symbols, $available);
    }

    /**
     * Books every fill of a continuous sleeve's symbol into `lots`/`cycles`. Run by
     * `runSleeve()` before `Sleeve::state()`, so the sleeve's `available`, its drawdown
     * pause and its equity sample all see this tick's fills. Never throws: a failed sync
     * is a non-essential step.
     */
    private function syncSleeveOrders(string $engine, string $symbol): void
    {
        $orders = $this->engineOrdersFor($symbol, $engine);
        if ($orders === null) {
            return;     // runEngineSleeve() reports engine_no_symbol_info
        }
        $this->obsLotBaseline($symbol, $engine);
        $sum = $this->step('engine_sync:' . $symbol, function () use ($orders, $symbol): array {
            return $orders->sync($symbol);
        }, false);
        if (is_array($sum)) {
            $nFilled = isset($sum['filled']) && is_array($sum['filled']) ? count($sum['filled']) : 0;
            if ($nFilled > 0) {
                $this->notes[] = $engine . ': ' . $nFilled . ' fill(s) booked';
            }
        }
    }

    /**
     * A grid / pmm sleeve: the same sequence as the single-engine branch (capital pause,
     * engine tick; the sync already ran in `runSleeve()`), but bound to the sleeve's symbol
     * and to a quote budget that is the smaller of the wallet's free quote and the sleeve's
     * `available`.
     */
    private function runEngineSleeve(string $engine, string $symbol, float $available, array $bal): void
    {
        $orders = $this->engineOrdersFor($symbol, $engine);
        if ($orders === null) {
            $this->noTradeReason = 'engine_no_symbol_info';
            return;
        }

        $block = (string) Risk::entryBlockReason($this->cfg, $this->db, $bal['quote_free'], $bal['equity'], $engine);
        if ($block !== '' && self::isCapitalBlock($block)) {
            $this->noTradeReason = $block;
            $this->obsEngine($engine, $symbol, 'skipped', $block, 0.0, 0.0);
            try {
                $n = (int) $orders->cancelAll($symbol, $block);
            } catch (BinanceException $e) {
                $this->handleApiError($e, 'engine_cancel_all:' . $symbol);
                $n = 0;
            }
            if ($n > 0) {
                $this->notes[] = 'sleeve ' . $engine . ' paused (' . $block . '): ' . $n . ' order(s) cancelled';
            }
            return;
        }
        if ($block !== '') {
            $this->notes[] = 'sleeve ' . $engine . ': ' . $block;
        }

        $book = isset($this->books[$symbol]) ? $this->books[$symbol] : ['bid' => 0.0, 'ask' => 0.0];
        $bid  = (float) $book['bid'];
        $ask  = (float) $book['ask'];
        if ($bid <= 0.0 || $ask <= 0.0) {
            $this->noTradeReason = 'engine_no_book';
            $this->obsEngine($engine, $symbol, 'skipped', 'engine_no_book', 0.0, 0.0);
            Log::warn('sleeve ' . $engine . ': no book price, skipped', ['symbol' => $symbol]);
            return;
        }

        // the budget rule of DESIGN-PORTFOLIO.md §3: an engine may never commit more quote than
        // its sleeve still has available, and never more than the wallet actually holds.
        // The engine sizes against $quote and EngineOrders::place() refuses anything above it,
        // so a mis-sized quote can never spend another sleeve's capital.
        $quote    = min((float) $bal['quote_free'], max(0.0, $available));
        $orders->setAvailableQuote($quote);
        $baseFree   = isset($bal['bases'][$symbol]['free']) ? (float) $bal['bases'][$symbol]['free'] : 0.0;
        $lotsBefore = $this->obsLotsSampled($symbol, $engine);
        $r = $this->step('engine_tick:' . $symbol, function () use ($engine, $symbol, $orders, $bid, $ask, $baseFree, $quote) {
            if ($engine === 'grid') {
                $g = $this->makeGrid($symbol, $orders, $engine);
                return $g === null ? null : $g->tick($bid, $ask, $quote);
            }
            $p = $this->makePmm($symbol, $orders, $engine);
            return $p === null ? null : $p->tick($bid, $ask, $baseFree, $quote);
        }, false);
        if (!is_array($r)) {
            if ($this->noTradeReason === '') {
                $this->noTradeReason = 'engine_unavailable';
            }
            return;
        }
        $action = (string) ($r['action'] ?? '');
        $detail = (string) ($r['detail'] ?? '');
        $this->obsEngineAction($engine, $symbol, $action, $lotsBefore, $bid, $ask);
        $this->noTradeReason = $action !== '' ? $action : 'idle';
        if ($detail !== '') {
            $this->notes[] = $engine . ' ' . ($action !== '' ? $action : 'idle') . ' ' . $detail;
        }
    }

    /**
     * Runs the volatility scanner at most once per tick, before the sleeves and never when the
     * tick has already used half its time budget (DESIGN-PORTFOLIO.md §6.2). `Scanner::due()`
     * keeps it to `scanner_refresh_min`; a weight-80 call must not run every minute.
     *
     * The half-budget test only guards ENTRY to the scan: once inside, the ATR pass is up to
     * 25 sequential klines calls of up to 15 s apiece and could still outlast the whole tick.
     * So the scan is also handed an absolute deadline at three quarters of the budget; past
     * it the partial ranking is stored and the sleeves get the rest of the tick.
     */
    private function maybeRunScanner(): void
    {
        if (!class_exists('Scanner')) {
            return;
        }
        try {
            $scanner = new Scanner($this->cfg, $this->db, $this->ex);
            if (!$scanner->due()) {
                return;
            }
            if ($this->elapsedMs() >= $this->tickBudgetMs() / 2) {
                $this->notes[] = 'scanner skipped (past half the time budget)';
                return;
            }
            $info = $this->info;
            // the ATR pass is up to 25 sequential klines calls: hand it a hard deadline at
            // three quarters of the tick budget so the sleeves still get their turn
            $deadline = ($this->tickStart * 1000.0) + ($this->tickBudgetMs() * 0.75);
            $rows = $this->step('scanner', function () use ($scanner, $info, $deadline) {
                return $scanner->refresh($info, false, $deadline);
            }, false);
            if (is_array($rows) && $rows !== []) {
                $this->notes[] = 'scanner ranked ' . count($rows) . ' pair(s)';
            }
        } catch (BotAbort $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warn('scanner: refresh failed - ' . $e->getMessage());
        }
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

    /** Every symbol owned by a sleeve (enabled or not); [] outside portfolio mode. */
    private function portfolioSymbols(): array
    {
        if (!$this->portfolio) {
            return [];
        }
        return Sleeve::allSymbols($this->cfg, false);
    }

    /**
     * Symbols whose base balance is engine INVENTORY rather than dust or an untracked
     * position: the single engine's symbol, or every symbol a grid/pmm sleeve owns.
     * @return array symbol => true
     */
    private function inventorySymbols(bool $enabledOnly = false): array
    {
        $out = [];
        if ($this->portfolio) {
            foreach (Sleeve::all($this->cfg) as $engine => $sleeve) {
                if (!Risk::isContinuousEngine((string) $engine)) {
                    continue;
                }
                if ($enabledOnly && empty($sleeve['enabled'])) {
                    continue;
                }
                foreach ($sleeve['symbols'] as $sym) {
                    $out[$sym] = true;
                }
            }
            return $out;
        }
        if ($this->engineSymbol !== '') {
            $out[$this->engineSymbol] = true;
        }
        return $out;
    }

    /** Sleeve symbols that still hold open lots (used by the kill switch to flatten). */
    private function portfolioInventorySymbols(): array
    {
        $out = [];
        foreach (array_keys($this->inventorySymbols()) as $sym) {
            if ($this->engineInventoryQty($sym) > 0.0) {
                $out[] = $sym;
            }
        }
        return $out;
    }

    /** Engine that owns $symbol: its sleeve in portfolio mode, else the configured engine. */
    private function engineOf(string $symbol): string
    {
        if (!$this->portfolio) {
            return $this->engine;
        }
        $owner = Sleeve::ownerOf($this->cfg, $symbol);
        if ($owner !== null && $owner !== '') {
            return $owner;
        }
        return $this->inventoryEngine($symbol);
    }

    /** Watchlist, or every sleeve symbol in portfolio mode: what the panel telemetry covers. */
    private function metricSymbols(): array
    {
        if (!$this->portfolio) {
            return $this->symbols();
        }
        $out = $this->portfolioSymbols();
        foreach ($this->symbols() as $sym) {
            if (!in_array($sym, $out, true)) {
                $out[] = $sym;
            }
        }
        return $out;
    }

    /** Bid read this tick for $symbol (0.0 when the book was not read). */
    private function bidOf(string $symbol): float
    {
        if ($symbol !== '' && $symbol === $this->engineSymbol && $this->engineBid > 0.0) {
            return $this->engineBid;
        }
        return isset($this->books[$symbol]) ? (float) $this->books[$symbol]['bid'] : 0.0;
    }

    /**
     * Reads the book of every symbol a grid/pmm sleeve owns, once per tick: their inventory is
     * valued at the bid and their engines quote around it. Best effort - a missing book leaves
     * the sleeve reporting `engine_no_book` instead of failing the tick.
     */
    private function loadPortfolioBooks(): void
    {
        if (!$this->portfolio) {
            return;
        }
        // only the sleeves that actually run: a disabled sleeve's inventory is still valued,
        // from the prices call the tick already made, and must not cost a bookTicker call
        foreach (array_keys($this->inventorySymbols(true)) as $sym) {
            if (isset($this->books[$sym])) {
                continue;
            }
            $book = $this->step('book:' . $sym, function () use ($sym): array {
                return $this->ex->bookTicker($sym);
            }, false);
            if (!is_array($book)) {
                continue;
            }
            $bid = (float) ($book['bid'] ?? 0.0);
            $ask = (float) ($book['ask'] ?? 0.0);
            $this->books[$sym] = ['bid' => $bid > 0.0 ? $bid : 0.0, 'ask' => $ask > 0.0 ? $ask : 0.0];
        }
    }

    /** [symbol => price] for Sleeve::state(): the bid when this tick read one, else the last price. */
    private function sleevePrices(array $prices, array $symbols): array
    {
        $out = [];
        foreach ($symbols as $sym) {
            $bid = $this->bidOf($sym);
            if ($bid <= 0.0 && isset($prices[$sym]) && is_numeric($prices[$sym])) {
                $bid = (float) $prices[$sym];
            }
            $out[$sym] = $bid > 0.0 ? $bid : 0.0;
        }
        return $out;
    }

    /**
     * true when the engine may not trade because it is live and allow_live_engines is false.
     * $engine / $symbol name the sleeve being checked in portfolio mode; empty means the
     * configured single engine, so the historical call keeps its behaviour.
     */
    private function engineLiveBlocked(string $engine = '', string $symbol = ''): bool
    {
        $engine = $engine !== '' ? $engine : $this->engine;
        $symbol = $symbol !== '' ? $symbol : $this->engineSymbol;
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

    /** A block reason that must take the whole ladder off the book (DESIGN-ENGINES.md §9.3). */
    private static function isCapitalBlock(string $reason): bool
    {
        if (in_array($reason, self::ENGINE_CAPITAL_BLOCKS, true)) {
            return true;
        }
        return strncmp($reason, 'paused:', 7) === 0;
    }

    /** grid | pmm with a configured symbol. */
    private function engineActive(): bool
    {
        return $this->engine !== 'signal' && $this->engineSymbol !== '';
    }

    /** Symbol the panel actions work on: the engine symbol, else whatever inventory is left. */
    private function actionSymbol(): string
    {
        if ($this->engineSymbol !== '') {
            return $this->engineSymbol;
        }
        if ($this->portfolio) {
            // the sleeves own the symbols; the grid sleeve is the one with an anchor to reset
            foreach (['grid', 'pmm'] as $eng) {
                $syms = Sleeve::symbols($this->cfg, $eng);
                if ($syms !== []) {
                    return $syms[0];
                }
            }
        }
        $sym = strtoupper(trim((string) ($this->cfg['engine_symbol'] ?? '')));
        if ($sym !== '') {
            return $sym;
        }
        try {
            $st = $this->db->pdo()->prepare('SELECT symbol FROM lots WHERE mode = ? AND remaining > 0 ORDER BY id DESC LIMIT 1');
            $st->execute([$this->mode]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? '' : strtoupper((string) $v);
        } catch (Throwable $e) {
            return '';
        }
    }

    /** Distinct symbols with at least one resting engine order; [] (and no query cost) is the norm. */
    private function liveEngineSymbols(): array
    {
        try {
            $rows = $this->db->engineOrders(Db::ENGINE_LIVE_STATUSES, $this->mode);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            // never touch another mode's book: a leftover paper/testnet row must not make a
            // live cancelAll fire against the real account (and vice versa)
            if (strtolower(trim((string) ($row['mode'] ?? ''))) !== strtolower(trim($this->mode))) {
                continue;
            }
            $s = strtoupper((string) ($row['symbol'] ?? ''));
            if ($s !== '') {
                $out[$s] = true;
            }
        }
        return array_keys($out);
    }

    /** Base quantity the engine still owns (open lots), 0.0 when the table or the symbol is empty. */
    private function engineInventoryQty(string $symbol = ''): float
    {
        $sym = $symbol !== '' ? $symbol : $this->engineSymbol;
        if ($sym === '') {
            return 0.0;
        }
        try {
            $lots = $this->db->openLots($sym, $this->mode, null);
        } catch (Throwable $e) {
            return 0.0;
        }
        $q = 0.0;
        foreach ($lots as $lot) {
            $q += max(0.0, (float) ($lot['remaining'] ?? 0.0));
        }
        return $q;
    }

    /**
     * Reads the engine symbol's book once per tick. The engine equity values the base at the
     * bid, and the equity snapshot runs before the engine branch, so this happens early.
     * Best effort: a missing book leaves the price at 0.0 and the engine tick reports it.
     */
    private function loadEngineBook(): void
    {
        if (!$this->engineActive()) {
            return;
        }
        $sym  = $this->engineSymbol;
        $book = $this->step('book:' . $sym, function () use ($sym): array {
            return $this->ex->bookTicker($sym);
        }, false);
        if (!is_array($book)) {
            return;
        }
        $bid = (float) ($book['bid'] ?? 0.0);
        $ask = (float) ($book['ask'] ?? 0.0);
        $this->engineBid = $bid > 0.0 ? $bid : 0.0;
        $this->engineAsk = $ask > 0.0 ? $ask : 0.0;
    }

    /** Mid price of $symbol from the book (cached when the tick already fetched it). */
    private function engineMid(string $symbol): float
    {
        if ($symbol === $this->engineSymbol && $this->engineBid > 0.0 && $this->engineAsk > 0.0) {
            return ($this->engineBid + $this->engineAsk) / 2.0;
        }
        if (isset($this->books[$symbol])
            && (float) $this->books[$symbol]['bid'] > 0.0 && (float) $this->books[$symbol]['ask'] > 0.0) {
            return ((float) $this->books[$symbol]['bid'] + (float) $this->books[$symbol]['ask']) / 2.0;
        }
        $book = $this->ex->bookTicker($symbol);
        $bid  = (float) ($book['bid'] ?? 0.0);
        $ask  = (float) ($book['ask'] ?? 0.0);
        if ($bid > 0.0 && $ask > 0.0) {
            return ($bid + $ask) / 2.0;
        }
        return max($bid, $ask);
    }

    /**
     * Engine that owns the open lots of $symbol (oldest first), the configured engine when
     * there are none. engineInventoryQty() counts every engine's lots, so a flatten after an
     * engine switch must consume them under THEIR engine or bookSell() matches nothing.
     */
    private function inventoryEngine(string $symbol): string
    {
        try {
            $lots = $this->db->openLots($symbol, $this->mode, null);
        } catch (Throwable $e) {
            return $this->engine;
        }
        foreach ($lots as $lot) {
            $e = strtolower(trim((string) ($lot['engine'] ?? '')));
            if ($e !== '') {
                return $e;
            }
        }
        return $this->engine;
    }

    /** EngineOrders for $symbol whose lot/cycle bookkeeping runs under $engine (never cached). */
    private function engineOrdersBookingAs(string $symbol, string $engine): ?EngineOrders
    {
        if ($engine === '' || $engine === $this->engine) {
            return $this->engineOrdersFor($symbol);
        }
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }
        try {
            $info = $this->infoFor($symbol);
        } catch (Throwable $e) {
            Log::warn('engine: no symbol info for ' . $symbol . ' - ' . $e->getMessage());
            return null;
        }
        $cfg           = $this->engineCfg($symbol);
        $cfg['engine'] = $engine;
        return new EngineOrders($cfg, $this->db, $this->ex, $info, $this->fixedNowMs);
    }

    /**
     * EngineOrders bound to $symbol, or null when the symbol info cannot be loaded.
     * $engine names the sleeve the fills must be booked under (portfolio mode); empty
     * keeps the configured engine, which is what the single-engine tick has always used.
     */
    private function engineOrdersFor(string $symbol, string $engine = ''): ?EngineOrders
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }
        $engine = $engine !== '' ? strtolower(trim($engine)) : $this->engine;
        $key    = $symbol . '|' . $engine;
        if (isset($this->engineOrdersCache[$key])) {
            return $this->engineOrdersCache[$key];
        }
        try {
            $info = $this->infoFor($symbol);
        } catch (Throwable $e) {
            Log::warn('engine: no symbol info for ' . $symbol . ' - ' . $e->getMessage());
            return null;
        }
        $cfg = $this->engineCfg($symbol, $engine);
        $this->engineOrdersCache[$key] = new EngineOrders($cfg, $this->db, $this->ex, $info, $this->fixedNowMs);
        return $this->engineOrdersCache[$key];
    }

    private function makeGrid(string $symbol, ?EngineOrders $orders = null, string $engine = ''): ?EngineGrid
    {
        if (!class_exists('EngineGrid')) {
            Log::error('engine: EngineGrid is not installed');
            return null;
        }
        if ($orders === null) {
            $orders = $this->engineOrdersFor($symbol, $engine);
        }
        if ($orders === null) {
            return null;
        }
        return new EngineGrid($this->engineCfg($symbol, $engine), $this->db, $this->ex, $orders, $this->infoFor($symbol));
    }

    private function makePmm(string $symbol, ?EngineOrders $orders = null, string $engine = ''): ?EnginePmm
    {
        if (!class_exists('EnginePmm')) {
            Log::error('engine: EnginePmm is not installed');
            return null;
        }
        if ($orders === null) {
            $orders = $this->engineOrdersFor($symbol, $engine);
        }
        if ($orders === null) {
            return null;
        }
        return new EnginePmm($this->engineCfg($symbol, $engine), $this->db, $this->ex, $orders, $this->infoFor($symbol));
    }

    /** Config for the engine classes: the exchange's mode, engine and acting symbol win. */
    private function engineCfg(string $symbol, string $engine = ''): array
    {
        $cfg = $this->cfg;
        $cfg['mode']          = $this->mode;
        $cfg['engine']        = $engine !== '' ? $engine : $this->engine;
        $cfg['engine_symbol'] = $symbol;
        $cfg['fee_pct']       = $this->feePct;
        return $cfg;
    }

    /** Free client id for an engine order sent by the bot itself (flatten). */
    private function newEngineClientId(string $side, string $symbol): ?string
    {
        $minute = intdiv($this->nowMs(), 60000) * 60;
        $base   = Util::clientOrderId($side, $symbol, $minute);
        $cid    = $base;
        for ($n = 2; $n < 100; $n++) {
            if ($this->db->engineOrder($cid) === null && $this->orderStatus($cid) === null) {
                return $cid;
            }
            $cid = substr($base . '-' . $n, 0, 36);
        }
        return null;
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
     * One observation for a continuous engine's entry decision (DESIGN-LEARNING.md §2).
     * Written when the engine placed a buy, and when something refused to let it - never on
     * an idle tick, which is not a decision. `entered` rows are resolved by the cycle their
     * inventory eventually closes; `skipped` rows resolve to `not_taken` immediately.
     */
    private function obsEngine(string $engine, string $symbol, string $decision, string $skipReason, float $bid, float $ask): void
    {
        if (!$this->learningOn() || $symbol === '') {
            return;
        }
        $reason = $skipReason !== '' ? $skipReason : 'skipped';
        if ($decision !== 'entered' && $this->obsSkipRepeats($engine, $symbol, $reason)) {
            // an engine that is blocked, paused or out of range reports the same refusal every
            // minute; recording it once an hour keeps the control group honest without burying
            // every real decision under a thousand copies of one
            return;
        }
        $now = $this->nowIso();
        $row = [
            'ts'          => $now,
            'mode'        => $this->mode,
            'engine'      => $engine,
            'symbol'      => $symbol,
            'decision'    => $decision === 'entered' ? 'entered' : 'skipped',
            'skip_reason' => $decision === 'entered' ? null : $reason,
            'features'    => $this->obsEngineFeatures($engine, $symbol, $bid, $ask),
        ];
        if ($row['decision'] === 'skipped') {
            $row['outcome']     = 'not_taken';
            $row['resolved_at'] = $now;
        }
        $this->obsInsert($row);
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

    /**
     * Turns an engine tick's reported action into an observation: `entered` when a BUY the
     * engine had working actually FILLED this tick, `skipped` when it was refused, nothing at
     * all when it merely had nothing to do.
     *
     * A fill, not a placement: `EngineOrders::place()` writes its row before the exchange has
     * answered, a post-only reject leaves it there, and pmm cancels and re-posts its bid every
     * refresh - counting placements would file one `entered` row a minute for quotes that never
     * traded, none of which any cycle could ever resolve. `$lotsBefore` is sampled before the
     * tick's `sync()` (see obsLotBaseline()) so a fill booked by the sync itself still counts.
     */
    private function obsEngineAction(string $engine, string $symbol, string $action, int $lotsBefore, float $bid, float $ask): void
    {
        if (!$this->learningOn()) {
            return;
        }
        if ($this->engineLotCount($symbol, $engine) > $lotsBefore) {
            $this->obsEngine($engine, $symbol, 'entered', '', $bid, $ask);
            return;
        }
        if (in_array($action, ['paused', 'range_exit', 'skipped', 'grid_dust'], true)) {
            // an action that already names its engine ('grid_dust') is not prefixed twice
            $reason = strpos($action, $engine) === 0 ? $action : $engine . '_' . $action;
            $this->obsEngine($engine, $symbol, 'skipped', $reason, $bid, $ask);
        }
    }

    /** Features an engine tick can supply without a single extra API call. */
    private function obsEngineFeatures(string $engine, string $symbol, float $bid, float $ask): array
    {
        $sec = $this->nowSec();
        $f = [
            'hour_utc' => (int) gmdate('G', $sec),
            'dow'      => (int) gmdate('w', $sec),
        ];
        $spread = $this->obsSpreadPct(['bid' => $bid, 'ask' => $ask]);
        if ($spread !== null) {
            $f['spread_pct'] = $spread;
        }
        $mid = ($bid > 0.0 && $ask > 0.0) ? ($bid + $ask) / 2.0 : 0.0;
        if ($engine === 'grid' && $mid > 0.0) {
            $anchor = 0.0;
            try {
                if ((string) $this->db->getState('grid_symbol', '') === $symbol) {
                    $raw    = $this->db->getState('grid_anchor', null);
                    $anchor = is_numeric($raw) ? (float) $raw : 0.0;
                }
            } catch (Throwable $e) {
                $anchor = 0.0;
            }
            if ($anchor > 0.0) {
                $f['dist_from_anchor_pct'] = ($mid - $anchor) / $anchor * 100.0;
            }
        }
        if ($mid > 0.0) {
            $info  = isset($this->info[$symbol]) ? $this->info[$symbol] : [];
            $order = $engine === 'grid' ? 'grid_order_usdt' : 'pmm_order_usdt';
            $usdt  = isset($this->cfg[$order]) && is_numeric($this->cfg[$order]) ? (float) $this->cfg[$order] : 0.0;
            $step  = $info !== [] ? $this->obsStepValuePct($info, $mid, $usdt) : null;
            if ($step !== null) {
                $f['step_value_pct'] = $step;
            }
        }
        return $f;
    }

    /**
     * How many FIFO lots this mode has ever booked for a symbol. A READ, used only to tell
     * "the engine's buy actually FILLED" from "the engine merely put a quote on the book".
     * One lot row is written per booked engine BUY fill, which is exactly the unit a cycle
     * later consumes - a quote that is rejected, cancelled or left resting writes none.
     */
    private function engineLotCount(string $symbol, string $engine): int
    {
        if ($symbol === '') {
            return 0;
        }
        try {
            $st = $this->db->pdo()->prepare(
                'SELECT COUNT(*) FROM lots WHERE mode = ? AND symbol = ? AND engine = ?'
            );
            $st->execute([$this->mode, $symbol, $engine]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Samples the lot baseline for this tick, before `sync()` books anything. Capture only. */
    private function obsLotBaseline(string $symbol, string $engine): void
    {
        if (!$this->learningOn() || $symbol === '') {
            return;
        }
        $this->obsLotsBefore[$engine . '|' . $symbol] = $this->engineLotCount($symbol, $engine);
    }

    /** The baseline sampled by obsLotBaseline() this tick, or 0 when learning is off. */
    private function obsLotsSampled(string $symbol, string $engine): int
    {
        $key = $engine . '|' . $symbol;
        return isset($this->obsLotsBefore[$key]) ? (int) $this->obsLotsBefore[$key] : 0;
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

    /**
     * Resolves engine observations against the cycles booked since the last tick, oldest
     * first - the order the FIFO lots are consumed in, so the cycle that closes a buy
     * resolves the observation that recorded it. The `obs_cycle_cursor` bookmark makes the
     * sweep idempotent: a cycle is considered exactly once, and a row that already carries an
     * outcome is left alone by the unforced resolve.
     */
    private function obsResolveCycles(): void
    {
        $raw = $this->db->getState('obs_cycle_cursor', null);
        if ($raw === null || $raw === '') {
            // First sweep: every cycle already on the books closes a lot bought before capture
            // began, so it can resolve nothing. Bookmark them instead of walking that history
            // and pairing an ancient PnL with an observation written this minute.
            try {
                $newest = $this->db->cycles(1, $this->mode);
            } catch (Throwable $e) {
                return;
            }
            $this->db->setState('obs_cycle_cursor', $newest === [] ? 0 : (int) $newest[0]['id']);
            return;
        }
        $cursor = (int) $raw;
        try {
            // forward from the bookmark, oldest first - the FIFO order of the lots. A page
            // read backwards from the newest would jump the cursor past a burst larger than
            // the page and lose those outcomes for good.
            $fresh = $this->db->cyclesSince($cursor, $this->mode, 200);
        } catch (Throwable $e) {
            return;
        }
        if ($fresh === []) {
            return;
        }
        $now  = $this->nowIso();
        $seen = $cursor;
        foreach ($fresh as $c) {
            $id   = (int) $c['id'];
            $seen = max($seen, $id);
            $obs  = $this->obsEntryForCycle($c);
            if ($obs === null) {
                continue;   // no identifiable observation for this buy: resolve nothing
            }
            $pnl    = isset($c['pnl_usdt']) && is_numeric($c['pnl_usdt']) ? (float) $c['pnl_usdt'] : 0.0;
            $cost   = (isset($c['qty']) ? (float) $c['qty'] : 0.0) * (isset($c['buy_price']) ? (float) $c['buy_price'] : 0.0);
            $closed = isset($c['closed_at']) && (string) $c['closed_at'] !== '' ? (string) $c['closed_at'] : $now;
            // the hold starts when the lot was bought (cycles.opened_at, the lot's created_at),
            // not when the observation was written - an engine buy can rest on the book for hours
            $opened = isset($c['opened_at']) && (string) $c['opened_at'] !== ''
                ? (string) $c['opened_at'] : (string) $obs['ts'];
            $this->obsResolveRow((int) $obs['id'], [
                'outcome'      => self::obsOutcome($pnl),
                'pnl_usdt'     => $pnl,
                'pnl_pct'      => $cost > 0.0 ? $pnl / $cost * 100.0 : 0.0,
                'exit_reason'  => 'cycle_closed',
                'held_minutes' => max(0.0, Util::isoDiffMinutes($opened, $closed)),
                'resolved_at'  => $now,
                'cycle_id'     => (int) $id,
            ]);
        }
        if ($seen > $cursor) {
            $this->db->setState('obs_cycle_cursor', $seen);
        }
    }

    /**
     * The open `entered` observation that recorded the buy this cycle closed, or null.
     *
     * The link is the lot: `cycles.opened_at` is the consumed lot's `created_at`, and the
     * observation is written by the very tick that booked that fill, so the two stamps agree to
     * within a tick. Blind FIFO cannot be used - one lot sold in two slices writes two cycles,
     * and a buy filling in two deltas writes two lots, so the queues drift apart permanently and
     * every later cycle would staple its PnL to a different buy's conditions. A cycle with no
     * match (its buy predates capture, or its observation is already resolved) resolves nothing.
     *
     * The candidate query is filtered in SQL, so rows that no cycle can ever resolve (inventory
     * still held) cannot crowd the newest decisions out of a fixed window.
     */
    private function obsEntryForCycle(array $c): ?array
    {
        $engine = isset($c['engine']) ? (string) $c['engine'] : '';
        $symbol = isset($c['symbol']) ? (string) $c['symbol'] : '';
        $opened = isset($c['opened_at']) ? (string) $c['opened_at'] : '';
        if ($engine === '' || $symbol === '' || $opened === '') {
            return null;
        }
        try {
            $open = $this->db->observations([
                'mode'     => $this->mode,
                'engine'   => $engine,
                'symbol'   => $symbol,
                'decision' => 'entered',
                'resolved' => false,
                'order'    => 'asc',
                'limit'    => 200,
            ]);
        } catch (Throwable $e) {
            return null;
        }
        $best    = null;
        $gapBest = null;
        foreach ($open as $o) {
            if (($o['position_id'] ?? null) !== null || ($o['cycle_id'] ?? null) !== null) {
                continue;   // already attached to a position or another cycle
            }
            $gap = abs(Util::isoDiffMinutes((string) $o['ts'], $opened));
            if ($gap > self::OBS_CYCLE_MATCH_MIN) {
                continue;   // a different buy: never borrow another decision's conditions
            }
            if ($gapBest === null || $gap < $gapBest) {
                $best    = $o;
                $gapBest = $gap;
            }
        }
        return $best;
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
        if ($this->engineSymbol !== '') {
            $out[$this->engineSymbol] = true;   // grid/pmm do not use the watchlist
        }
        // portfolio mode: symbol info and prices cover the union of every sleeve's symbols
        foreach ($this->portfolioSymbols() as $sym) {
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
