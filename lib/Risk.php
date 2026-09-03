<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';

/**
 * Survival layer, sizing, adaptive rule and config validation (DESIGN.md §9).
 *
 * State keys touched: equity_hwm, day_start_equity, day_start_date, halted,
 * halt_reason, paused_until, pause_reason, consecutive_losses, last_loss_at,
 * cooldown_until, effective_threshold, effective_max_trades, last_adapt_date,
 * plus one bookkeeping key of our own: adapt_max_since_closed (number of
 * closed trades at the moment the adaptive threshold reached 100).
 *
 * Percent config values (fee_pct, *_pct) are percentages (0.1 = 0.1 %).
 */
final class Risk
{
    /** Fraction of the free quote balance an entry may use. */
    const SIZE_FRACTION = 0.65;
    /** Quote that must stay free after an entry (fee/dust buffer). */
    const QUOTE_RESERVE = 0.5;
    /** Smallest entry we ever consider without symbol info (entryBlockReason). */
    const MIN_GENERIC_SIZE = 1.0;
    /** Safety margin over minNotional so the SELL still passes after an adverse move. */
    const NOTIONAL_MARGIN = 1.15;
    /** Adaptive rule parameters. */
    const ADAPT_MIN_TRADES = 20;
    const ADAPT_WINDOW = 20;
    const ADAPT_STEP = 20;
    const ADAPT_MAX_TRADES_CAP = 4;
    const ADAPT_WIN_LOW = 40.0;
    const ADAPT_WIN_HIGH = 60.0;
    const ADAPT_EXPECTANCY_LOW = -0.005;

    /* ------------------------------------------------------------ survival */

    /**
     * Runs first every tick. Maintains equity_hwm, day_start_equity and day_start_date,
     * then applies the kill switches and the loss caps.
     * @return array{action:string, reason:string} action ∈ none|halt|no_entry
     */
    public static function survivalCheck(array $cfg, Db $db, float $equity, bool $hasOpenPosition, bool $exchangeHasBase): array
    {
        $today = Util::todayUtc();
        if (!is_finite($equity) || $equity < 0) {
            $equity = 0.0;
        }

        // day-start bookkeeping (daily loss cap reference)
        $dayDate = (string) $db->getState('day_start_date', '');
        $dayEq   = $db->getState('day_start_equity', null);
        if ($dayDate !== $today || $dayEq === null || !is_numeric($dayEq)) {
            $db->setState('day_start_date', $today);
            $db->setState('day_start_equity', $equity);
        }

        // high-water mark only ever rises
        $hwmRaw = $db->getState('equity_hwm', null);
        $hwm    = ($hwmRaw !== null && is_numeric($hwmRaw)) ? (float) $hwmRaw : 0.0;
        if ($equity > $hwm) {
            $db->setState('equity_hwm', $equity);
        }

        // already halted: keep asking the bot to flatten a tracked position, otherwise just block entries
        if (self::isHalted($db)) {
            $reason = (string) $db->getState('halt_reason', '');
            if ($reason === '') {
                $reason = 'halted';
            }
            if ($hasOpenPosition) {
                return ['action' => 'halt', 'reason' => $reason];
            }
            return ['action' => 'no_entry', 'reason' => 'halted'];
        }

        // kill switches
        if (self::floorBreached($cfg, $db, $equity)) {
            $reason = $equity <= self::f($cfg, 'equity_floor_usdt', 7.0) ? 'equity_floor' : 'drawdown';
            $db->setState('halted', '1');
            $db->setState('halt_reason', $reason);
            return ['action' => 'halt', 'reason' => $reason];
        }

        // weekly loss cap ⇒ pause new entries for 7 days
        if (self::weeklyCapHit($cfg, $db, $equity)) {
            $until = (string) $db->getState('paused_until', '');
            if (!self::isFuture($until) || (string) $db->getState('pause_reason', '') !== 'weekly_cap') {
                $db->setState('paused_until', Util::nowIso(time() + 7 * 86400));
                $db->setState('pause_reason', 'weekly_cap');
            }
            return ['action' => 'no_entry', 'reason' => 'weekly_cap'];
        }

        if (self::dailyCapHit($cfg, $db, $equity)) {
            return ['action' => 'no_entry', 'reason' => 'daily_cap'];
        }

        if (self::isFuture((string) $db->getState('paused_until', ''))) {
            return ['action' => 'no_entry', 'reason' => 'paused:' . self::pauseReason($db)];
        }

        return ['action' => 'none', 'reason' => ''];
    }

    /** equity ≤ floor OR equity < hwm × (1 − drawdown%). */
    public static function floorBreached(array $cfg, Db $db, float $equity): bool
    {
        if (!is_finite($equity)) {
            return true;
        }
        if ($equity <= self::f($cfg, 'equity_floor_usdt', 7.0)) {
            return true;
        }
        $hwmRaw = $db->getState('equity_hwm', null);
        if ($hwmRaw !== null && is_numeric($hwmRaw)) {
            $hwm = (float) $hwmRaw;
            $dd  = self::f($cfg, 'hwm_drawdown_pct', 20.0);
            if ($hwm > 0 && $dd > 0 && $equity < $hwm * (1.0 - $dd / 100.0)) {
                return true;
            }
        }
        return false;
    }

    /**
     * First blocking reason for a NEW entry, checked in the documented order.
     * @return string ''|halted|disabled|paused:<reason>|api_paused|cooldown|daily_cap|weekly_cap|max_trades|max_orders_hour|consecutive_losses|insufficient_quote
     */
    public static function entryBlockReason(array $cfg, Db $db, float $quoteFree, float $equity): string
    {
        if (self::isHalted($db)) {
            return 'halted';
        }
        if (!self::b($cfg, 'enabled', false)) {
            return 'disabled';
        }
        if (self::isFuture((string) $db->getState('paused_until', ''))) {
            return 'paused:' . self::pauseReason($db);
        }
        if (self::isFuture((string) $db->getState('api_paused_until', ''))) {
            return 'api_paused';
        }
        if (self::isFuture((string) $db->getState('cooldown_until', ''))) {
            return 'cooldown';
        }
        if (self::dailyCapHit($cfg, $db, $equity)) {
            return 'daily_cap';
        }
        if (self::weeklyCapHit($cfg, $db, $equity)) {
            return 'weekly_cap';
        }
        $todayStart = Util::todayUtc() . 'T00:00:00Z';
        if ($db->entriesSince($todayStart, self::mode($cfg)) >= self::effectiveMaxTrades($cfg, $db)) {
            return 'max_trades';
        }
        $hourAgo = Util::nowIso(time() - 3600);
        if ($db->ordersSince($hourAgo, self::mode($cfg)) >= self::i($cfg, 'max_orders_per_hour', 2)) {
            return 'max_orders_hour';
        }
        $losses = (int) $db->getState('consecutive_losses', '0');
        if ($losses >= self::i($cfg, 'max_consecutive_losses', 3)) {
            $lastLoss = (string) $db->getState('last_loss_at', '');
            if ($lastLoss === '' || substr($lastLoss, 0, 10) === Util::todayUtc()) {
                return 'consecutive_losses';
            }
        }
        if (!is_finite($quoteFree) || $quoteFree <= 0) {
            return 'insufficient_quote';
        }
        $size = min(self::f($cfg, 'trade_usdt', 6.5), self::SIZE_FRACTION * $quoteFree);
        if ($size < self::MIN_GENERIC_SIZE || $quoteFree - $size < self::QUOTE_RESERVE) {
            return 'insufficient_quote';
        }
        return '';
    }

    /* -------------------------------------------------------------- sizing */

    /** (minNotional × 1.15 + stepSize × price) / (1 − fee) — quote needed so the SELL survives an adverse move. */
    public static function requiredSize(array $info, float $price, float $feePct): float
    {
        $minNotional = isset($info['minNotional']) && is_numeric($info['minNotional']) ? (float) $info['minNotional'] : 5.0;
        $step        = isset($info['stepSize']) && is_numeric($info['stepSize']) ? (float) $info['stepSize'] : 0.0;
        if ($minNotional < 0) {
            $minNotional = 0.0;
        }
        if ($step < 0 || !is_finite($step)) {
            $step = 0.0;
        }
        if (!is_finite($price) || $price < 0) {
            $price = 0.0;
        }
        $fee = Util::clamp(is_finite($feePct) ? $feePct / 100.0 : 0.0, 0.0, 0.5);
        return ($minNotional * self::NOTIONAL_MARGIN + $step * $price) / (1.0 - $fee);
    }

    /** Quote to spend or 0.0: size = min(trade_usdt, 0.65 × quoteFree); 0 if size < requiredSize or quoteFree − size < 0.5. */
    public static function entrySize(array $cfg, array $info, float $price, float $quoteFree, float $feePct): float
    {
        if (!is_finite($quoteFree) || $quoteFree <= 0) {
            return 0.0;
        }
        $size = min(self::f($cfg, 'trade_usdt', 6.5), self::SIZE_FRACTION * $quoteFree);
        if ($size <= 0) {
            return 0.0;
        }
        $size = (float) Util::floorToStep($size, '0.01');   // what fmtQuote will actually send
        if ($size < self::requiredSize($info, $price, $feePct)) {
            return 0.0;
        }
        if ($quoteFree - $size < self::QUOTE_RESERVE) {
            return 0.0;
        }
        return $size;
    }

    /* ------------------------------------------------------------ adaptive */

    /** Entry score needed: adaptive state value clamped to [entry_threshold, 100], or entry_threshold when adaptive is off. */
    public static function effectiveThreshold(array $cfg, Db $db): int
    {
        $base = self::i($cfg, 'entry_threshold', 60);
        $base = (int) Util::clamp((float) $base, 0.0, 100.0);
        if (!self::b($cfg, 'adaptive', true)) {
            return $base;
        }
        $raw = $db->getState('effective_threshold', null);
        if ($raw === null || !is_numeric($raw)) {
            return $base;
        }
        return (int) Util::clamp((float) (int) $raw, (float) $base, 100.0);
    }

    /** Entries allowed per UTC day: adaptive state value clamped to [1, max(4, max_trades_per_day)], or the config value. */
    public static function effectiveMaxTrades(array $cfg, Db $db): int
    {
        $base = max(1, self::i($cfg, 'max_trades_per_day', 3));
        if (!self::b($cfg, 'adaptive', true)) {
            return $base;
        }
        $raw = $db->getState('effective_max_trades', null);
        if ($raw === null || !is_numeric($raw)) {
            return $base;
        }
        return (int) Util::clamp((float) (int) $raw, 1.0, (float) self::maxTradesCap($cfg));
    }

    private static function maxTradesCap(array $cfg): int
    {
        return max(self::ADAPT_MAX_TRADES_CAP, self::i($cfg, 'max_trades_per_day', 3));
    }

    /**
     * Called after every closed position: consecutive-loss counter, cooldown ladder,
     * and the bounded self-tuning step (at most once per UTC day).
     */
    public static function recordOutcome(array $cfg, Db $db, float $pnlUsdt, string $nowIso): void
    {
        if (Util::isoToTs($nowIso) === null) {
            $nowIso = Util::nowIso();
        }
        $today = substr($nowIso, 0, 10);

        if ($pnlUsdt < 0) {
            $losses = (int) $db->getState('consecutive_losses', '0') + 1;
            $db->setState('consecutive_losses', (string) $losses);
            $db->setState('last_loss_at', $nowIso);
            $maxLosses = max(1, self::i($cfg, 'max_consecutive_losses', 3));
            if ($losses >= $maxLosses) {
                $until = self::nextUtcDay($nowIso);
            } elseif ($losses >= 2) {
                $until = Util::isoAddMinutes($nowIso, max(0, self::i($cfg, 'cooldown_after_2_losses_min', 180)));
            } else {
                $until = Util::isoAddMinutes($nowIso, max(0, self::i($cfg, 'cooldown_after_loss_min', 45)));
            }
            $current = (string) $db->getState('cooldown_until', '');
            if ($current === '' || Util::isoToTs($current) === null || Util::isoToTs($current) < Util::isoToTs($until)) {
                $db->setState('cooldown_until', $until);
            }
        } elseif ($pnlUsdt > 0) {
            $db->setState('consecutive_losses', '0');
        }

        if (!self::b($cfg, 'adaptive', true)) {
            return;
        }
        $stats  = $db->stats(self::mode($cfg));
        $closed = (int) ($stats['closed'] ?? 0);
        if ($closed < self::ADAPT_MIN_TRADES) {
            return;
        }
        $window = self::rollingStats($db->lastClosed(self::ADAPT_WINDOW, self::mode($cfg)));
        $thr    = self::effectiveThreshold($cfg, $db);
        $maxT   = self::effectiveMaxTrades($cfg, $db);

        // adaptive stop: threshold pinned at 100 and still negative after 20 more trades
        if ($thr >= 100) {
            $sinceRaw = $db->getState('adapt_max_since_closed', null);
            if ($sinceRaw === null || !is_numeric($sinceRaw)) {
                $db->setState('adapt_max_since_closed', (string) $closed);
            } elseif ($closed - (int) $sinceRaw >= self::ADAPT_MIN_TRADES && $window['expectancy'] < 0) {
                self::adaptiveStop($cfg, $db);
                $db->setState('last_adapt_date', $today);
                $db->setState('adapt_max_since_closed', (string) $closed);
                return;
            }
        }

        if ((string) $db->getState('last_adapt_date', '') === $today) {
            return;
        }
        $base = (int) Util::clamp((float) self::i($cfg, 'entry_threshold', 60), 0.0, 100.0);
        if ($window['win_rate'] < self::ADAPT_WIN_LOW || $window['expectancy'] < self::ADAPT_EXPECTANCY_LOW) {
            $newThr = min(100, $thr + self::ADAPT_STEP);
            $newMax = max(1, $maxT - 1);
        } elseif ($window['win_rate'] > self::ADAPT_WIN_HIGH && $window['expectancy'] > 0) {
            $newThr = max($base, $thr - self::ADAPT_STEP);
            $newMax = min(self::maxTradesCap($cfg), $maxT + 1);
        } else {
            return;
        }
        $db->setState('effective_threshold', (string) $newThr);
        $db->setState('effective_max_trades', (string) $newMax);
        $db->setState('last_adapt_date', $today);
        if ($newThr >= 100) {
            if ($thr < 100 || $db->getState('adapt_max_since_closed', null) === null) {
                $db->setState('adapt_max_since_closed', (string) $closed);
            }
        } else {
            $db->setState('adapt_max_since_closed', null);
        }
        if (class_exists('Log', false)) {
            Log::info('Adaptive step', [
                'threshold' => $newThr, 'max_trades' => $newMax,
                'win_rate' => round($window['win_rate'], 1), 'expectancy' => round($window['expectancy'], 5),
            ]);
        }
    }

    /** win_rate (percent of decided trades) and expectancy (avg pnl) over the given closed rows. */
    private static function rollingStats(array $rows): array
    {
        $wins = 0;
        $losses = 0;
        $sum = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            if (!isset($r['pnl_usdt']) || !is_numeric($r['pnl_usdt'])) {
                continue;
            }
            $p = (float) $r['pnl_usdt'];
            $sum += $p;
            $n++;
            if ($p > 0) {
                $wins++;
            } elseif ($p < 0) {
                $losses++;
            }
        }
        $decided = $wins + $losses;
        return [
            'win_rate'   => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
            'expectancy' => $n > 0 ? $sum / $n : 0.0,
            'count'      => $n,
        ];
    }

    /** enabled=false (persisted when trader_save_config exists) + pause_reason=adaptive_stop. */
    private static function adaptiveStop(array $cfg, Db $db): void
    {
        $db->setState('pause_reason', 'adaptive_stop');
        if (function_exists('trader_save_config')) {
            $full = $cfg;
            if (function_exists('trader_config')) {
                $fresh = trader_config(true);
                if (is_array($fresh)) {
                    $full = $fresh;
                }
            }
            $full['enabled'] = false;
            try {
                trader_save_config($full);
            } catch (Throwable $e) {
                $db->setState('api_error', 'adaptive_stop: cannot save config');
            }
        }
        if (class_exists('Log', false)) {
            Log::warn('Adaptive stop: threshold at 100 and expectancy still negative; entries disabled');
        }
    }

    /* --------------------------------------------------------------- exits */

    /**
     * Stop / take-profit / trailing / max-hold decision on the bid vs entry_eff.
     * @return array{reason:string, trail_high:float, trailing_armed:int, stop_price:float}
     */
    public static function exitDecision(array $position, float $bid, array $cfg, string $nowIso): array
    {
        $entry    = self::pf($position, 'entry_eff', 0.0);
        $sl       = self::f($cfg, 'stop_loss_pct', 0.7);
        $stop     = self::pf($position, 'stop_price', 0.0);
        if ($stop <= 0 && $entry > 0) {
            $stop = $entry * (1.0 - $sl / 100.0);
        }
        $tp       = self::pf($position, 'take_profit_price', 0.0);
        if ($tp <= 0 && $entry > 0) {
            $tp = $entry * (1.0 + self::f($cfg, 'take_profit_pct', 1.0) / 100.0);
        }
        $trailHigh = self::pf($position, 'trail_high', $entry);
        $armed     = (int) self::pf($position, 'trailing_armed', 0.0) === 1 ? 1 : 0;

        $out = ['reason' => '', 'trail_high' => $trailHigh, 'trailing_armed' => $armed, 'stop_price' => $stop];
        if ($entry <= 0 || !is_finite($bid) || $bid <= 0) {
            return $out;
        }

        if ($bid > $trailHigh) {
            $trailHigh = $bid;
        }
        $activate = self::f($cfg, 'trailing_activate_pct', 0.6);
        $distance = self::f($cfg, 'trailing_distance_pct', 0.4);
        $floorPct = self::f($cfg, 'trailing_floor_pct', 0.25);
        if ($armed === 0 && ($trailHigh - $entry) / $entry * 100.0 >= $activate) {
            $armed = 1;
        }
        if ($armed === 1) {
            $trailStop = $trailHigh * (1.0 - $distance / 100.0);
            $floor     = $entry * (1.0 + $floorPct / 100.0);
            $newStop   = max($trailStop, $floor);
            if ($newStop > $stop) {
                $stop = $newStop;
            }
        }
        $out['trail_high']     = $trailHigh;
        $out['trailing_armed'] = $armed;
        $out['stop_price']     = $stop;

        if ($bid <= $stop) {
            $out['reason'] = $armed === 1 ? 'trailing_stop' : 'stop_loss';
            return $out;
        }
        if ($tp > 0 && $bid >= $tp) {
            $out['reason'] = 'take_profit';
            return $out;
        }
        $opened = isset($position['opened_at']) ? (string) $position['opened_at'] : '';
        if ($opened !== '' && Util::isoToTs($opened) !== null) {
            $maxHold = self::i($cfg, 'max_hold_minutes', 240);
            if ($maxHold > 0 && Util::isoDiffMinutes($opened, $nowIso) >= $maxHold) {
                $out['reason'] = 'max_hold';
                return $out;
            }
        }
        return $out;
    }

    /* ---------------------------------------------------------- validation */

    /**
     * Sanitise every §3 key found in $in on top of $current. Invalid values are
     * reported in errors[] and the current value is kept.
     * @return array [cfg, errors[]]
     */
    public static function validateConfig(array $in, array $current): array
    {
        $cfg    = $current;
        $errors = [];

        $numeric = [
            'trade_usdt'                  => ['f', 1.0, 100000.0],
            'equity_floor_usdt'           => ['f', 1.0, 1000000000.0],
            'hwm_drawdown_pct'            => ['f', 1.0, 90.0],
            'daily_loss_cap_pct'          => ['f', 0.1, 50.0],
            'weekly_loss_cap_pct'         => ['f', 0.1, 100.0],
            'max_trades_per_day'          => ['i', 1, 50],
            'max_orders_per_hour'         => ['i', 1, 100],
            'max_consecutive_losses'      => ['i', 1, 20],
            'cooldown_after_loss_min'     => ['i', 0, 10080],
            'cooldown_after_2_losses_min' => ['i', 0, 10080],
            'take_profit_pct'             => ['f', 0.1, 50.0],
            'take_profit_max_pct'         => ['f', 0.1, 50.0],
            'stop_loss_pct'               => ['f', 0.2, 5.0, true],
            'trailing_activate_pct'       => ['f', 0.1, 20.0],
            'trailing_distance_pct'       => ['f', 0.05, 10.0],
            'trailing_floor_pct'          => ['f', 0.0, 10.0],
            'max_hold_minutes'            => ['i', 5, 10080],
            'entry_threshold'             => ['i', 0, 100],
            'atr_min_pct'                 => ['f', 0.0, 50.0],
            'atr_max_pct'                 => ['f', 0.01, 50.0],
            'atr1h_min_pct'               => ['f', 0.0, 50.0],
            'atr1h_max_pct'               => ['f', 0.01, 50.0],
            'max_spread_pct'              => ['f', 0.0, 5.0],
            'fee_pct'                     => ['f', 0.0, 1.0],
            'paper_start_usdt'            => ['f', 1.0, 1000000.0],
            'recv_window'                 => ['i', 1000, 60000],
        ];
        foreach ($numeric as $key => $spec) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $raw = $in[$key];
            if (is_string($raw)) {
                $raw = trim($raw);
            }
            if (!is_numeric($raw) || is_bool($raw)) {
                $errors[] = $key . ' must be a number';
                continue;
            }
            $exclusive = isset($spec[3]) && $spec[3] === true;
            $v = (float) $raw;
            if (!is_finite($v)) {
                $errors[] = $key . ' must be a number';
                continue;
            }
            $lo = (float) $spec[1];
            $hi = (float) $spec[2];
            $bad = $exclusive ? ($v <= $lo || $v >= $hi) : ($v < $lo || $v > $hi);
            if ($bad) {
                $errors[] = $key . ' must be ' . ($exclusive ? 'strictly ' : '') . 'between ' . self::fmtBound($lo) . ' and ' . self::fmtBound($hi);
                continue;
            }
            if ($spec[0] === 'i') {
                if (abs($v - round($v)) > 1e-9) {
                    $errors[] = $key . ' must be a whole number';
                    continue;
                }
                $cfg[$key] = (int) round($v);
            } else {
                $cfg[$key] = $v;
            }
        }

        foreach (['enabled', 'adaptive', 'force_https'] as $key) {
            if (array_key_exists($key, $in)) {
                $cfg[$key] = self::toBool($in[$key]);
            }
        }

        if (array_key_exists('mode', $in)) {
            $mode = strtolower(trim((string) $in['mode']));
            if (in_array($mode, ['paper', 'testnet', 'live'], true)) {
                $cfg['mode'] = $mode;
            } else {
                $errors[] = 'mode must be paper, testnet or live';
            }
        }

        if (array_key_exists('quote_asset', $in)) {
            $q = strtoupper(trim((string) $in['quote_asset']));
            if (preg_match('/^[A-Z0-9]{2,10}$/', $q) === 1) {
                $cfg['quote_asset'] = $q;
            } else {
                $errors[] = 'quote_asset must be 2-10 letters/digits';
            }
        }
        $quote = strtoupper(trim((string) ($cfg['quote_asset'] ?? 'USDT')));
        if ($quote === '') {
            $quote = 'USDT';
            $cfg['quote_asset'] = $quote;
        }

        if (array_key_exists('symbols', $in)) {
            $list = $in['symbols'];
            if (is_string($list)) {
                $parts = preg_split('/[\s,;]+/', $list);
                $list  = $parts === false ? [] : $parts;
            }
            if (!is_array($list)) {
                $errors[] = 'symbols must be a list';
            } else {
                $clean = [];
                $bad   = [];
                foreach ($list as $s) {
                    if (!is_scalar($s)) {
                        continue;
                    }
                    $s = strtoupper(trim((string) $s));
                    if ($s === '') {
                        continue;
                    }
                    $okChars = preg_match('/^[A-Z0-9]{3,20}$/', $s) === 1;
                    $okQuote = strlen($s) > strlen($quote) && substr($s, -strlen($quote)) === $quote;
                    if ($okChars && $okQuote) {
                        $clean[$s] = true;
                    } else {
                        $bad[] = $s;
                    }
                }
                if ($bad !== []) {
                    $errors[] = 'invalid symbols (must be uppercase and end with ' . $quote . '): ' . implode(', ', array_slice($bad, 0, 10));
                } elseif ($clean === []) {
                    $errors[] = 'symbols must contain at least one symbol';
                } elseif (count($clean) > 30) {
                    $errors[] = 'symbols: at most 30 symbols';
                } else {
                    $cfg['symbols'] = array_keys($clean);
                }
            }
        }

        $intervals = ['1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d'];
        foreach (['candle_interval', 'trend_interval'] as $key) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $iv = strtolower(trim((string) $in[$key]));
            if (in_array($iv, $intervals, true)) {
                $cfg[$key] = $iv;
            } else {
                $errors[] = $key . ' must be one of ' . implode(', ', $intervals);
            }
        }

        if (array_key_exists('timezone', $in)) {
            $tz = trim((string) $in['timezone']);
            if ($tz === '') {
                $tz = 'UTC';
            }
            if (in_array($tz, timezone_identifiers_list(), true)) {
                $cfg['timezone'] = $tz;
            } else {
                $errors[] = 'timezone is not a valid identifier';
            }
        }

        // API credentials: blank keeps the current value (the panel never renders the secret back)
        foreach (['api_key', 'api_secret'] as $key) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $v = trim((string) $in[$key]);
            if ($v === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9]{16,128}$/', $v) === 1) {
                $cfg[$key] = $v;
            } else {
                $errors[] = $key . ' must be 16-128 letters/digits';
            }
        }
        if (array_key_exists('cron_key', $in)) {
            $v = trim((string) $in['cron_key']);
            if ($v !== '' && preg_match('/^[A-Fa-f0-9]{16,128}$/', $v) === 1) {
                $cfg['cron_key'] = strtolower($v);
            } elseif ($v !== '') {
                $errors[] = 'cron_key must be 16-128 hex characters';
            }
        }
        if (array_key_exists('panel_password_hash', $in)) {
            $v = (string) $in['panel_password_hash'];
            if ($v !== '' && strlen($v) >= 20 && $v[0] === '$') {
                $cfg['panel_password_hash'] = $v;
            } elseif ($v !== '') {
                $errors[] = 'panel_password_hash must be a password_hash() value';
            }
        }

        // cross-field rules
        $fee   = (float) ($cfg['fee_pct'] ?? 0.1);
        $tp    = (float) ($cfg['take_profit_pct'] ?? 1.0);
        $tpMax = (float) ($cfg['take_profit_max_pct'] ?? 2.0);
        $minTp = 3.0 * 2.0 * $fee;
        if ($tp < $minTp - 1e-9) {
            $errors[] = 'take_profit_pct must be at least 3x the round-trip fee (' . self::fmtBound($minTp) . '%)';
            $cfg['take_profit_pct'] = (float) ($current['take_profit_pct'] ?? max($minTp, 1.0));
            if ((float) $cfg['take_profit_pct'] < $minTp) {
                $cfg['take_profit_pct'] = $minTp;
            }
        }
        if ($tpMax < (float) $cfg['take_profit_pct']) {
            $errors[] = 'take_profit_max_pct must be >= take_profit_pct';
            $cfg['take_profit_max_pct'] = (float) $cfg['take_profit_pct'];
        }
        if ((float) ($cfg['atr_min_pct'] ?? 0.3) >= (float) ($cfg['atr_max_pct'] ?? 1.5)) {
            $errors[] = 'atr_min_pct must be lower than atr_max_pct';
            $cfg['atr_min_pct'] = (float) ($current['atr_min_pct'] ?? 0.3);
            $cfg['atr_max_pct'] = (float) ($current['atr_max_pct'] ?? 1.5);
        }
        if ((float) ($cfg['atr1h_min_pct'] ?? 0.5) >= (float) ($cfg['atr1h_max_pct'] ?? 3.0)) {
            $errors[] = 'atr1h_min_pct must be lower than atr1h_max_pct';
            $cfg['atr1h_min_pct'] = (float) ($current['atr1h_min_pct'] ?? 0.5);
            $cfg['atr1h_max_pct'] = (float) ($current['atr1h_max_pct'] ?? 3.0);
        }
        if ((float) ($cfg['trailing_floor_pct'] ?? 0.25) >= (float) ($cfg['trailing_activate_pct'] ?? 0.6)) {
            $errors[] = 'trailing_floor_pct must be lower than trailing_activate_pct';
            $cfg['trailing_floor_pct']    = (float) ($current['trailing_floor_pct'] ?? 0.25);
            $cfg['trailing_activate_pct'] = (float) ($current['trailing_activate_pct'] ?? 0.6);
        }
        if ((int) ($cfg['cooldown_after_2_losses_min'] ?? 180) < (int) ($cfg['cooldown_after_loss_min'] ?? 45)) {
            $errors[] = 'cooldown_after_2_losses_min must be >= cooldown_after_loss_min';
            $cfg['cooldown_after_2_losses_min'] = max((int) ($current['cooldown_after_2_losses_min'] ?? 180), (int) $cfg['cooldown_after_loss_min']);
        }

        return [$cfg, $errors];
    }

    /* ------------------------------------------------------------- helpers */

    private static function isHalted(Db $db): bool
    {
        return (string) $db->getState('halted', '0') === '1';
    }

    private static function pauseReason(Db $db): string
    {
        $r = trim((string) $db->getState('pause_reason', ''));
        return $r === '' ? 'manual' : $r;
    }

    /** true when $iso parses and lies in the future. */
    private static function isFuture(string $iso): bool
    {
        if (trim($iso) === '') {
            return false;
        }
        $ts = Util::isoToTs($iso);
        return $ts !== null && $ts > time();
    }

    /** ISO of 00:00:00Z on the UTC day after the day of $iso. */
    private static function nextUtcDay(string $iso): string
    {
        $dayTs = Util::isoToTs(substr($iso, 0, 10) . 'T00:00:00Z');
        if ($dayTs === null) {
            $dayTs = Util::isoToTs(Util::todayUtc() . 'T00:00:00Z');
        }
        return Util::nowIso((int) $dayTs + 86400);
    }

    /** Reference equity for the loss caps: today's start-of-day equity, falling back to the current one. */
    private static function dayStartEquity(Db $db, float $equity): float
    {
        $raw = $db->getState('day_start_equity', null);
        if ($raw !== null && is_numeric($raw) && (float) $raw > 0) {
            return (float) $raw;
        }
        return $equity > 0 ? $equity : 0.0;
    }

    private static function dailyCapHit(array $cfg, Db $db, float $equity): bool
    {
        $cap = self::f($cfg, 'daily_loss_cap_pct', 2.0);
        $ref = self::dayStartEquity($db, $equity);
        if ($cap <= 0 || $ref <= 0) {
            return false;
        }
        $pnl = $db->realisedPnl(Util::todayUtc() . 'T00:00:00Z', self::mode($cfg));
        return $pnl <= -($ref * $cap / 100.0);
    }

    private static function weeklyCapHit(array $cfg, Db $db, float $equity): bool
    {
        $cap = self::f($cfg, 'weekly_loss_cap_pct', 5.0);
        $ref = self::dayStartEquity($db, $equity);
        if ($cap <= 0 || $ref <= 0) {
            return false;
        }
        $pnl = $db->realisedPnl(Util::nowIso(time() - 7 * 86400), self::mode($cfg));
        return $pnl <= -($ref * $cap / 100.0);
    }

    /** Configured trading mode; the risk history of one mode never drives another. */
    private static function mode(array $cfg): string
    {
        $m = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
        return in_array($m, ['paper', 'testnet', 'live'], true) ? $m : 'paper';
    }

    private static function f(array $cfg, string $key, float $default): float
    {
        return isset($cfg[$key]) && is_numeric($cfg[$key]) ? (float) $cfg[$key] : $default;
    }

    private static function i(array $cfg, string $key, int $default): int
    {
        return isset($cfg[$key]) && is_numeric($cfg[$key]) ? (int) $cfg[$key] : $default;
    }

    private static function b(array $cfg, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $cfg)) {
            return $default;
        }
        return self::toBool($cfg[$key]);
    }

    private static function pf(array $row, string $key, float $default): float
    {
        return isset($row[$key]) && is_numeric($row[$key]) ? (float) $row[$key] : $default;
    }

    private static function toBool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return $v != 0;
        }
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'on', 'yes'], true);
    }

    private static function fmtBound(float $v): string
    {
        return Util::trimZeros(sprintf('%.4F', $v));
    }
}
