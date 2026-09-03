<?php
declare(strict_types=1);

/**
 * Pure static indicator math (DESIGN.md §7).
 *
 * Every series function takes a plain float array and returns an array of the
 * same length, aligned to the input: entries are null where the indicator is
 * not yet defined (warm-up period). Nothing here touches the database, the
 * network or the clock.
 */
final class Indicators
{
    /** Normalise any list of numbers to a 0-indexed float array (non-numeric → 0.0). */
    private static function floats(array $v): array
    {
        $out = [];
        foreach (array_values($v) as $x) {
            $out[] = is_numeric($x) ? (float) $x : 0.0;
        }
        return $out;
    }

    /** Simple moving average over $n values; window sums are computed directly (no drift). */
    public static function sma(array $v, int $n): array
    {
        $v   = self::floats($v);
        $c   = count($v);
        $out = array_fill(0, $c, null);
        if ($n <= 0 || $c < $n) {
            return $out;
        }
        for ($i = $n - 1; $i < $c; $i++) {
            $sum = 0.0;
            for ($j = $i - $n + 1; $j <= $i; $j++) {
                $sum += $v[$j];
            }
            $out[$i] = $sum / $n;
        }
        return $out;
    }

    /** Exponential moving average, seeded with SMA(n) at index n−1, k = 2/(n+1). */
    public static function ema(array $v, int $n): array
    {
        $v   = self::floats($v);
        $c   = count($v);
        $out = array_fill(0, $c, null);
        if ($n <= 0 || $c < $n) {
            return $out;
        }
        $k   = 2.0 / ($n + 1);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $v[$i];
        }
        $prev        = $sum / $n;
        $out[$n - 1] = $prev;
        for ($i = $n; $i < $c; $i++) {
            $prev    = $prev + $k * ($v[$i] - $prev);
            $out[$i] = $prev;
        }
        return $out;
    }

    /**
     * Relative Strength Index with Wilder smoothing. First value at index $n
     * (average of the first $n changes), then avg = (prev×(n−1) + current)/n.
     * A perfectly flat window (no gains, no losses) yields the neutral 50.
     */
    public static function rsi(array $close, int $n = 14): array
    {
        $v   = self::floats($close);
        $c   = count($v);
        $out = array_fill(0, $c, null);
        if ($n <= 0 || $c < $n + 1) {
            return $out;
        }
        $gain = 0.0;
        $loss = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $d = $v[$i] - $v[$i - 1];
            if ($d > 0) {
                $gain += $d;
            } else {
                $loss -= $d;
            }
        }
        $avgG    = $gain / $n;
        $avgL    = $loss / $n;
        $out[$n] = self::rsiValue($avgG, $avgL);
        for ($i = $n + 1; $i < $c; $i++) {
            $d    = $v[$i] - $v[$i - 1];
            $g    = $d > 0 ? $d : 0.0;
            $l    = $d < 0 ? -$d : 0.0;
            $avgG = ($avgG * ($n - 1) + $g) / $n;
            $avgL = ($avgL * ($n - 1) + $l) / $n;
            $out[$i] = self::rsiValue($avgG, $avgL);
        }
        return $out;
    }

    private static function rsiValue(float $avgG, float $avgL): float
    {
        $sum = $avgG + $avgL;
        if ($sum <= 0.0) {
            return 50.0;
        }
        return 100.0 * $avgG / $sum;
    }

    /**
     * Average True Range with Wilder smoothing. True range needs the previous
     * close, so TR starts at index 1; the first ATR (index $n) is the mean of
     * TR[1..n], afterwards atr = (prev×(n−1) + TR)/n.
     */
    public static function atr(array $high, array $low, array $close, int $n = 14): array
    {
        $h = self::floats($high);
        $l = self::floats($low);
        $c = self::floats($close);
        $len = min(count($h), count($l), count($c));
        $out = array_fill(0, count($c), null);
        if ($n <= 0 || $len < $n + 1) {
            return $out;
        }
        $tr = array_fill(0, $len, 0.0);
        for ($i = 1; $i < $len; $i++) {
            $pc    = $c[$i - 1];
            $tr[$i] = max($h[$i] - $l[$i], abs($h[$i] - $pc), abs($l[$i] - $pc));
        }
        $sum = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $sum += $tr[$i];
        }
        $prev    = $sum / $n;
        $out[$n] = $prev;
        for ($i = $n + 1; $i < $len; $i++) {
            $prev    = ($prev * ($n - 1) + $tr[$i]) / $n;
            $out[$i] = $prev;
        }
        return $out;
    }

    /** Population standard deviation over a rolling window of $n values. */
    public static function stddev(array $v, int $n): array
    {
        $v   = self::floats($v);
        $c   = count($v);
        $out = array_fill(0, $c, null);
        if ($n <= 0 || $c < $n) {
            return $out;
        }
        for ($i = $n - 1; $i < $c; $i++) {
            $sum = 0.0;
            for ($j = $i - $n + 1; $j <= $i; $j++) {
                $sum += $v[$j];
            }
            $mean = $sum / $n;
            $var  = 0.0;
            for ($j = $i - $n + 1; $j <= $i; $j++) {
                $d    = $v[$j] - $mean;
                $var += $d * $d;
            }
            $out[$i] = sqrt($var / $n);
        }
        return $out;
    }

    /** Bollinger bands: ['mid' => SMA(n), 'upper' => mid + k×σ, 'lower' => mid − k×σ] (population σ). */
    public static function bollinger(array $close, int $n = 20, float $k = 2.0): array
    {
        $mid   = self::sma($close, $n);
        $sd    = self::stddev($close, $n);
        $c     = count($mid);
        $upper = array_fill(0, $c, null);
        $lower = array_fill(0, $c, null);
        for ($i = 0; $i < $c; $i++) {
            if ($mid[$i] === null || $sd[$i] === null) {
                continue;
            }
            $upper[$i] = $mid[$i] + $k * $sd[$i];
            $lower[$i] = $mid[$i] - $k * $sd[$i];
        }
        return ['mid' => $mid, 'upper' => $upper, 'lower' => $lower];
    }

    /** Element $back positions from the end (0 = last); null when out of range. */
    public static function last(array $a, int $back = 0)
    {
        $a   = array_values($a);
        $idx = count($a) - 1 - max(0, $back);
        if ($idx < 0) {
            return null;
        }
        return $a[$idx];
    }

    /**
     * Closed candles only: drops the last kline row when its closeTime (index 6)
     * is still in the future relative to $serverTimeMs. Rows are re-indexed.
     */
    public static function closed(array $klines, int $serverTimeMs): array
    {
        $rows = array_values($klines);
        if ($rows === []) {
            return [];
        }
        $lastRow = $rows[count($rows) - 1];
        if (is_array($lastRow) && isset($lastRow[6]) && is_numeric($lastRow[6])) {
            if ((int) $lastRow[6] > $serverTimeMs) {
                array_pop($rows);
            }
        }
        return $rows;
    }
}
