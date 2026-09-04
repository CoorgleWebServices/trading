<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Indicators.php';

/**
 * Deterministic mean-reversion scoring model (DESIGN.md §8).
 *
 * Kline rows are the Binance array shape used everywhere in this project:
 *   [openTime(int), open, high, low, close, volume (floats), closeTime(int)]
 * Both entry points are pure: no database, no clock, no network, and they
 * never throw on short or malformed input.
 */
final class Strategy
{
    /** Minimum closed 15m candles for a meaningful RSI14 / Bollinger(20) / SMA20(volume) read. */
    const MIN_15M = 50;
    /** Minimum closed 1h candles: EMA200 plus the 6-bar slope of EMA50. */
    const MIN_1H = 206;

    /** Score/gate constants from the design table. */
    const RSI_OVERSOLD = 30.0;
    const RSI_DEEP_OVERSOLD = 25.0;
    const RSI_OVERBOUGHT = 70.0;
    const VOL_RATIO = 1.2;
    const CRASH_SUM_PCT = -3.0;
    const EMA_SLOPE_BARS = 6;
    const EXIT_MIN_GAIN_PCT = 0.3;

    /**
     * The ONLY things a learned weight may touch (DESIGN-LEARNING.md §4): the score
     * components, with the base points each one contributes when it fires. The map is
     * the whole write surface of learning inside this class - there is no key here for
     * size, take-profit, stop-loss, a budget or a kill switch, so no `learn_weights`
     * entry can reach one. `trend` is the 1h regime GATE and carries no points, so its
     * base is 0 and a delta on it provably does nothing.
     */
    const LEARN_COMPONENTS = ['rsi' => 20, 'bb' => 20, 'reversal' => 20, 'rsi_up' => 20, 'vol' => 20, 'trend' => 0];
    /** Hard cap on a single learned delta, in score points (DESIGN-LEARNING.md §4). */
    const LEARN_DELTA_CAP = 10;

    /**
     * @param array      $c15  closed 15m kline rows (≥ 250 expected; ≥ MIN_15M required)
     * @param array      $c1h  closed 1h kline rows (≥ 210 expected; ≥ MIN_1H required)
     * @param array      $cfg  config (§3 keys; missing keys fall back to the documented defaults)
     * @param array|null $book ['bid' => float, 'ask' => float] from bookTicker, optional
     * @param array|null $weights learned score-component deltas (DESIGN-LEARNING.md §4),
     *                   e.g. ['rsi' => 0, 'bb' => -10, 'vol' => +10]. Optional and ignored
     *                   unless BOTH `learning_enabled` and `learning_apply` are true in $cfg,
     *                   so the four-argument call scores exactly as it always has.
     * @return array{score:int, eligible:bool, reasons:string[], gates:string[], price:float, atr_pct:float, atr1h_pct:float, rsi:float, tp_pct:float}
     */
    public static function evaluate(array $c15, array $c1h, array $cfg, ?array $book = null, ?array $weights = null): array
    {
        $tpMin = self::f($cfg, 'take_profit_pct', 1.0);
        $tpMax = self::f($cfg, 'take_profit_max_pct', 2.0);
        $res = [
            'score'     => 0,
            'eligible'  => false,
            'reasons'   => [],
            'gates'     => [],
            'price'     => 0.0,
            'atr_pct'   => 0.0,
            'atr1h_pct' => 0.0,
            'rsi'       => 0.0,
            'tp_pct'    => Util::clamp($tpMin, $tpMin, $tpMax),
        ];

        try {
            $k15 = self::columns($c15);
            $k1h = self::columns($c1h);
            if ($k15 !== null && count($k15['close']) > 0) {
                $res['price'] = (float) Indicators::last($k15['close']);
            }
            if ($k15 === null || $k1h === null || count($k15['close']) < self::MIN_15M || count($k1h['close']) < self::MIN_1H) {
                $res['gates'][] = 'data_short';
                return $res;
            }
            return self::score($k15, $k1h, $cfg, $book, $res, self::activeWeights($cfg, $weights));
        } catch (Throwable $e) {
            $res['eligible'] = false;
            $res['gates']    = ['data_short'];
            $res['reasons']  = [];
            return $res;
        }
    }

    /**
     * Full gate + score computation once the data is known to be long enough.
     * $weights is null unless learning is both enabled and applied, and then it can only
     * shift the score components below; every gate, the tp_pct and the clamp are outside
     * its reach.
     */
    private static function score(array $k15, array $k1h, array $cfg, ?array $book, array $res, ?array $weights = null): array
    {
        $gates   = [];
        $reasons = [];

        /* ---------------------------------------------------------- 1h regime */
        $close1h = $k1h['close'];
        $n1h     = count($close1h);
        $t1      = $n1h - 1;
        $ema50   = Indicators::ema($close1h, 50);
        $ema200  = Indicators::ema($close1h, 200);
        $e50     = $ema50[$t1];
        $e200    = $ema200[$t1];
        $e50Back = $ema50[$t1 - self::EMA_SLOPE_BARS] ?? null;
        $lastC1h = $close1h[$t1];
        if ($e50 !== null && $e200 !== null) {
            $below   = $e50 < $e200;
            $falling = $e50Back !== null && $e50 < $e50Back;
            if (($below && $falling) || ($lastC1h < $e200 && $below)) {
                $gates[] = 'trend_down';
            }
        }
        // crash guard: sum of the last 4 closed 1h returns (percent)
        $sumRet = 0.0;
        for ($i = $t1 - 3; $i <= $t1; $i++) {
            $prev = $close1h[$i - 1];
            if ($prev > 0) {
                $sumRet += ($close1h[$i] - $prev) / $prev * 100.0;
            }
        }
        if ($sumRet < self::CRASH_SUM_PCT) {
            $gates[] = 'crash_guard';
        }

        /* ------------------------------------------------------ 15m indicators */
        $close = $k15['close'];
        $n     = count($close);
        $t     = $n - 1;
        $price = $close[$t];
        $res['price'] = $price;

        $rsiArr = Indicators::rsi($close, 14);
        $atrArr = Indicators::atr($k15['high'], $k15['low'], $close, 14);
        $bb     = Indicators::bollinger($close, 20, 2.0);
        $volSma = Indicators::sma($k15['volume'], 20);

        $rsi     = $rsiArr[$t] ?? null;
        $rsiPrev = $rsiArr[$t - 1] ?? null;
        $atr     = $atrArr[$t] ?? null;
        $upper   = $bb['upper'][$t] ?? null;
        $lower   = $bb['lower'][$t] ?? null;
        $vsma    = $volSma[$t] ?? null;

        if ($rsi === null || $atr === null || $upper === null || $lower === null || $vsma === null || $price <= 0) {
            $res['gates'] = ['data_short'];
            return $res;
        }
        $res['rsi'] = (float) $rsi;

        $atrPct = $atr / $price * 100.0;
        $res['atr_pct'] = $atrPct;
        if ($atrPct < self::f($cfg, 'atr_min_pct', 0.30)) {
            $gates[] = 'atr_low';
        } elseif ($atrPct > self::f($cfg, 'atr_max_pct', 1.5)) {
            $gates[] = 'atr_high';
        }

        $atr1hArr = Indicators::atr($k1h['high'], $k1h['low'], $close1h, 14);
        $atr1h    = $atr1hArr[$t1] ?? null;
        if ($atr1h !== null && $lastC1h > 0) {
            $atr1hPct = $atr1h / $lastC1h * 100.0;
            $res['atr1h_pct'] = $atr1hPct;
            if ($atr1hPct < self::f($cfg, 'atr1h_min_pct', 0.5)) {
                $gates[] = 'atr1h_low';
            } elseif ($atr1hPct > self::f($cfg, 'atr1h_max_pct', 3.0)) {
                $gates[] = 'atr1h_high';
            }
        }

        if ($book !== null) {
            $bid = isset($book['bid']) && is_numeric($book['bid']) ? (float) $book['bid'] : 0.0;
            $ask = isset($book['ask']) && is_numeric($book['ask']) ? (float) $book['ask'] : 0.0;
            if ($bid > 0 && $ask > 0) {
                $mid = ($ask + $bid) / 2.0;
                if ($mid > 0 && ($ask - $bid) / $mid * 100.0 > self::f($cfg, 'max_spread_pct', 0.05)) {
                    $gates[] = 'spread_wide';
                }
            } else {
                $gates[] = 'spread_wide';
            }
        }

        /* ---------------------------------------------------------- scoring */
        $score = 0;
        $open  = $k15['open'][$t];
        $low   = $k15['low'][$t];
        $vol   = $k15['volume'][$t];

        // Each component contributes its documented base points plus, ONLY when learning is
        // enabled and applied, its clamped delta. With $weights null every call below returns
        // the base value unchanged, so the score is bit-identical to the pre-learning one.
        if ($rsi <= self::RSI_OVERSOLD) {
            $score += self::points($weights, 'rsi');
            $reasons[] = 'rsi<=30';
            if ($rsi <= self::RSI_DEEP_OVERSOLD) {
                $score += 10;
                $reasons[] = 'rsi<=25';
            }
        }
        if ($price <= $lower) {
            $score += self::points($weights, 'bb');
            $reasons[] = 'bb_lower';
            $bandwidth = $upper - $lower;
            if ($bandwidth > 0 && $price <= $lower - 0.25 * $bandwidth) {
                $score += 10;
                $reasons[] = 'bb_deep';
            }
        }
        if ($low < $lower && $price > $open) {
            $score += self::points($weights, 'reversal');
            $reasons[] = 'reversal_candle';
        }
        if ($rsiPrev !== null && $rsi > $rsiPrev) {
            $score += self::points($weights, 'rsi_up');
            $reasons[] = 'rsi_up';
        }
        if ($vsma > 0 && $vol >= self::VOL_RATIO * $vsma) {
            $score += self::points($weights, 'vol');
            $reasons[] = 'vol_high';
        }
        if ($rsi >= self::RSI_OVERBOUGHT || $price >= $upper) {
            $score -= 40;
            $reasons[] = 'overbought';
        }
        $score = (int) Util::clamp((float) $score, 0.0, 100.0);

        $tpMin = self::f($cfg, 'take_profit_pct', 1.0);
        $tpMax = self::f($cfg, 'take_profit_max_pct', 2.0);
        $res['tp_pct']   = Util::clamp(1.5 * $atrPct, $tpMin, $tpMax);
        $res['score']    = $score;
        $res['reasons']  = $reasons;
        $res['gates']    = $gates;
        $res['eligible'] = $gates === [];
        return $res;
    }

    /**
     * Signal-based exit for an open position. Never exits a losing position.
     * @return string ''|'rsi_overbought'|'bb_upper'
     */
    public static function exitSignal(array $c15, array $position, float $bid, array $cfg): string
    {
        try {
            $entry = isset($position['entry_eff']) && is_numeric($position['entry_eff']) ? (float) $position['entry_eff'] : 0.0;
            if ($entry <= 0 || $bid <= 0) {
                return '';
            }
            $gainPct = ($bid - $entry) / $entry * 100.0;
            if ($gainPct < self::EXIT_MIN_GAIN_PCT) {
                return '';
            }
            $k = self::columns($c15);
            if ($k === null || count($k['close']) < 22) {
                return '';
            }
            $t   = count($k['close']) - 1;
            $rsi = Indicators::last(Indicators::rsi($k['close'], 14));
            if ($rsi !== null && $rsi > self::RSI_OVERBOUGHT) {
                return 'rsi_overbought';
            }
            $bb    = Indicators::bollinger($k['close'], 20, 2.0);
            $upper = $bb['upper'][$t] ?? null;
            if ($upper !== null && $bid >= $upper) {
                return 'bb_upper';
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }

    /* --------------------------------------------------- learned weights (§4) */

    /**
     * The weight map to apply, or null. Null unless the caller passed one AND both
     * `learning_enabled` and `learning_apply` are true: with `learning_apply` false
     * (the default) this returns null and scoring is exactly what it was before
     * learning existed.
     */
    private static function activeWeights(array $cfg, ?array $weights): ?array
    {
        if ($weights === null || $weights === [] || !is_array($weights)) {
            return null;
        }
        // learning_enabled defaults to true (DESIGN-LEARNING.md §5); learning_apply to false,
        // and it is the one that actually lets a delta through.
        if (!self::flag($cfg, 'learning_enabled', true) || !self::flag($cfg, 'learning_apply', false)) {
            return null;
        }
        return $weights;
    }

    /**
     * Points one score component contributes: its base value plus the clamped learned delta.
     * A positive component can never be driven negative, so a weight may neutralise a
     * component but never invert it into a bonus for the opposite condition.
     */
    private static function points(?array $weights, string $component): int
    {
        $base = array_key_exists($component, self::LEARN_COMPONENTS) ? (int) self::LEARN_COMPONENTS[$component] : 0;
        if ($weights === null) {
            return $base;
        }
        // the delta is already clamped to -base, so this max() is belt and braces: a component
        // can be neutralised to zero, never turned into a negative (an inverted) contribution
        return (int) max(0, $base + self::componentDelta($weights, $component));
    }

    /**
     * The delta a weight map may apply to one score component, after the §4 caps:
     * clamped to ±LEARN_DELTA_CAP and to the component's own base value (never an
     * inversion), 0 for an unknown key, a non-numeric value or a component that carries
     * no points. Public so the safety test can assert the clamp directly.
     */
    public static function componentDelta(?array $weights, string $component): int
    {
        if ($weights === null || !array_key_exists($component, self::LEARN_COMPONENTS)) {
            return 0;
        }
        $base = (int) self::LEARN_COMPONENTS[$component];
        if ($base <= 0 || !isset($weights[$component]) || !is_numeric($weights[$component])) {
            return 0;
        }
        $cap = (int) self::LEARN_DELTA_CAP;
        $lo  = -min($cap, $base);
        return (int) Util::clamp((float) round((float) $weights[$component]), (float) $lo, (float) $cap);
    }

    /* ------------------------------------------------------------- helpers */

    /** Split kline rows into float columns; null when a row is malformed. */
    private static function columns(array $rows): ?array
    {
        $out = ['open' => [], 'high' => [], 'low' => [], 'close' => [], 'volume' => []];
        foreach (array_values($rows) as $r) {
            if (!is_array($r) || count($r) < 6) {
                return null;
            }
            $r = array_values($r);
            for ($i = 1; $i <= 5; $i++) {
                if (!is_numeric($r[$i])) {
                    return null;
                }
            }
            $out['open'][]   = (float) $r[1];
            $out['high'][]   = (float) $r[2];
            $out['low'][]    = (float) $r[3];
            $out['close'][]  = (float) $r[4];
            $out['volume'][] = (float) $r[5];
        }
        return $out;
    }

    private static function f(array $cfg, string $key, float $default): float
    {
        return isset($cfg[$key]) && is_numeric($cfg[$key]) ? (float) $cfg[$key] : $default;
    }

    /** Config flag reader that accepts bool / 0-1 / '1' / 'true' / 'yes' / 'on'. */
    private static function flag(array $cfg, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $cfg)) {
            return $default;
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
}
