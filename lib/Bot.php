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

    public function __construct(array $cfg, Db $db, ExchangeInterface $ex, ?int $nowMs = null)
    {
        $this->cfg        = $cfg + self::defaults();
        $this->db         = $db;
        $this->ex         = $ex;
        $this->fixedNowMs = $nowMs;
        $this->mode       = $ex->mode();
        $this->feePct     = max(0.0, (float) $this->cfg['fee_pct']);
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
        $this->ordersThisTick   = 0;
        $this->apiErrorThisTick = false;
        $this->netErrorThisTick = false;
        $this->noTradeReason    = '';
        $this->equity           = null;
        $this->quoteFree        = null;
        $this->posSummary       = '';
        $this->notes            = [];

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
        foreach ($this->symbols() as $pxSym) {
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
        $sv = Risk::survivalCheck($this->cfg, $this->db, $bal['equity'], $open !== null || $stuck !== null, $bal['exchange_has_base']);
        $action = (string) ($sv['action'] ?? 'none');
        $svReason = (string) ($sv['reason'] ?? '');
        if ($action === 'halt') {
            $reason  = $svReason !== '' ? $svReason : 'equity_floor';
            if ($open !== null) {
                $closed = $this->closePosition($reason);
                $this->notes[] = $closed !== null ? 'position closed' : 'position NOT closed (see log)';
            } elseif ($stuck !== null) {
                $this->retryStuck($stuck, $acct);
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
            $free   = isset($balances[$base]) ? (float) ($balances[$base]['free'] ?? 0.0) : 0.0;
            $locked = isset($balances[$base]) ? (float) ($balances[$base]['locked'] ?? 0.0) : 0.0;
            $total  = $free + $locked;
            $price  = isset($prices[$sym]) ? (float) $prices[$sym] : 0.0;
            $value  = $price > 0.0 ? $total * $price : 0.0;
            $minNotional = (float) ($info['minNotional'] ?? 0.0);
            $row = ['symbol' => $sym, 'base' => $base, 'free' => $free, 'total' => $total, 'price' => $price, 'value' => $value, 'tracked' => isset($tracked[$sym]), 'untracked_big' => false];
            if ($total > 0.0 && $price > 0.0) {
                if (isset($tracked[$sym])) {
                    $pq = (float) $tracked[$sym]['qty'];
                    $posValue  += min($pq, $total) * $price;
                    $dustValue += max(0.0, $total - $pq) * $price;
                } else {
                    $dustValue += $value;
                    if ($minNotional > 0.0 && $value >= $minNotional) {
                        $row['untracked_big'] = true;
                    }
                }
                if ($minNotional > 0.0 && $value >= $minNotional) {
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

    private function evaluateEntries(float $quoteFree, float $equity): void
    {
        $block = (string) Risk::entryBlockReason($this->cfg, $this->db, $quoteFree, $equity);
        if ($block !== '') {
            $this->noTradeReason = $block;
            return;
        }
        $symbols = $this->symbols();
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

            $sig = Strategy::evaluate($c15, $c1h, $this->cfg, $book);
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
                $size     = (float) Risk::entrySize($this->cfg, $info, $price, $quoteFree, $this->feePct);
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
            $this->noTradeReason = 'no_new_candle';
            return;
        }
        if ($best === null) {
            if ($eligibleCount > 0) {
                $this->noTradeReason = 'score_below_threshold(best=' . (int) $bestSeen . '<' . $threshold . ')';
            } elseif ($unaffordable === $evaluated) {
                // the balance, not the market, gated every symbol
                $this->noTradeReason = 'size_unaffordable(quote_free=' . Util::money($quoteFree, 2) . ')';
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
            $this->noTradeReason = 'entry_unfilled';
            return;
        }
        $this->markDone((string) $r['client_id'], $r, $pid);
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
