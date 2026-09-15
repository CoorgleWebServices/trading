<?php
declare(strict_types=1);

/**
 * Static helpers shared by every other DJ file (DESIGN-DJ.md §3):
 * esc, uid, today, isoAddDays, dayDiff, clamp, randomHex.
 *
 * Everything here is pure except uid/today/nowIso/randomHex. Nothing touches
 * the database, the session or the network, so the whole class is usable from
 * dj/tests/run.php offline.
 *
 * PHP 7.4 syntax only: no match, no union types, no str_contains, no ?->.
 */
final class Util
{
    /** Base-36 alphabet used by uid(), matching the artifact's Number#toString(36). */
    const BASE36 = '0123456789abcdefghijklmnopqrstuvwxyz';

    /* ------------------------------------------------------------- escaping */

    /**
     * htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5 on any scalar.
     * DESIGN-DJ.md §8: every dynamic value that reaches HTML goes through here.
     *
     * null and false render as the empty string, true as "1", floats as a plain
     * decimal (never 5.0E-5), arrays and objects as compact JSON.
     *
     * @param mixed $v
     */
    public static function esc($v): string
    {
        if ($v === null || $v === false) {
            return '';
        }
        if ($v === true) {
            return '1';
        }
        if (is_float($v)) {
            $v = self::decimal($v);
        } elseif (is_array($v) || is_object($v)) {
            $j = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $v = $j === false ? '' : $j;
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** A float as a plain decimal string: no exponent, no trailing zeros ("0.001", "128", "-3.5"). */
    public static function decimal(float $v, int $maxDecimals = 10): string
    {
        if (!is_finite($v)) {
            return '0';
        }
        $s = sprintf('%.' . max(0, $maxDecimals) . 'F', $v);
        if (strpos($s, '.') !== false) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        if ($s === '' || $s === '-' || $s === '-0') {
            return '0';
        }
        return $s;
    }

    /* ------------------------------------------------------------------ ids */

    /**
     * A short, sortable, collision-resistant id, exactly the artifact's shape:
     * base-36 milliseconds followed by five random base-36 characters.
     */
    public static function uid(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $out = self::base36($ms);
        for ($i = 0; $i < 5; $i++) {
            $out .= self::BASE36[random_int(0, 35)];
        }
        return $out;
    }

    /** Non-negative integer to base 36 (lower case), "0" for anything <= 0. */
    public static function base36(int $n): string
    {
        if ($n <= 0) {
            return '0';
        }
        $out = '';
        while ($n > 0) {
            $out = self::BASE36[$n % 36] . $out;
            $n = intdiv($n, 36);
        }
        return $out;
    }

    /** $bytes of cryptographic randomness as lower-case hex (2 * $bytes characters). */
    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    /* ----------------------------------------------------------------- time */

    /** Today in UTC as YYYY-MM-DD. Every date column in §4 holds this shape. */
    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    /** ISO-8601 UTC, second precision, e.g. 2026-09-15T08:42:00Z */
    public static function nowIso(?int $ts = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts === null ? time() : $ts);
    }

    /**
     * Parse a YYYY-MM-DD date or an ISO-8601 timestamp as UTC.
     * Returns null when the string is empty or unparsable.
     */
    public static function isoToTs(string $iso): ?int
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        $prev = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $ts = strtotime($iso);
        date_default_timezone_set($prev);
        return $ts === false ? null : $ts;
    }

    /**
     * $date shifted by $days, as YYYY-MM-DD. Accepts a date or a full ISO
     * timestamp; unparsable input is treated as "today". UTC throughout, so
     * there is no daylight-saving edge to get wrong.
     */
    public static function isoAddDays(string $date, int $days): string
    {
        $ts = self::isoToTs($date);
        if ($ts === null) {
            $ts = time();
        }
        return gmdate('Y-m-d', $ts + $days * 86400);
    }

    /**
     * Whole days from $a to $b ($b - $a): positive when $b is the later date.
     * null when either side is empty or unparsable, exactly like the artifact's
     * dayDiff(), so callers must handle the unknown case rather than see a 0.
     */
    public static function dayDiff(string $a, string $b): ?int
    {
        $ta = self::isoToTs($a);
        $tb = self::isoToTs($b);
        if ($ta === null || $tb === null) {
            return null;
        }
        return (int) round(($tb - $ta) / 86400);
    }

    /** ISO-8601 UTC $iso + $m minutes (used by the login lockout). */
    public static function isoAddMinutes(string $iso, int $m): string
    {
        $ts = self::isoToTs($iso);
        if ($ts === null) {
            $ts = time();
        }
        return self::nowIso($ts + $m * 60);
    }

    /** True when $date looks like a calendar date YYYY-MM-DD that really exists. */
    public static function isDate(string $date): bool
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* ------------------------------------------------------------- numerics */

    /** $v confined to [$lo, $hi]; the bounds are swapped when handed over backwards. */
    public static function clamp(float $v, float $lo, float $hi): float
    {
        if ($lo > $hi) {
            $t = $lo;
            $lo = $hi;
            $hi = $t;
        }
        if (!is_finite($v)) {
            return $lo;
        }
        return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
    }

    /** clamp() for integers (energy 1-10, ladder rungs, set positions). */
    public static function clampInt(int $v, int $lo, int $hi): int
    {
        if ($lo > $hi) {
            $t = $lo;
            $lo = $hi;
            $hi = $t;
        }
        return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
    }
}
