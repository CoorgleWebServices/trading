<?php
declare(strict_types=1);

/**
 * Static helpers shared by every other file (DESIGN.md §5).
 *
 * All decimal handling is string based: quantities sent to Binance must never
 * be produced by casting a float to string (PHP prints 5.0E-5). Every public
 * function here is pure (except nowMs/nowIso/todayUtc/randomHex) and usable
 * from tests without a database or network.
 */
final class Util
{
    /** Maximum number of fractional digits kept when a float is converted to a decimal string. */
    const FLOAT_DECIMALS = 12;

    /* ------------------------------------------------------------------ time */

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** ISO-8601 UTC, second precision, e.g. 2026-09-03T08:42:00Z */
    public static function nowIso(?int $ts = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts === null ? time() : $ts);
    }

    /** YYYY-MM-DD in UTC */
    public static function todayUtc(): string
    {
        return gmdate('Y-m-d');
    }

    /** Parse an ISO-8601 timestamp (UTC assumed when no zone given). Returns null when unparsable. */
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

    /** $iso + $m minutes, as ISO string. Unparsable input is treated as "now". */
    public static function isoAddMinutes(string $iso, int $m): string
    {
        $ts = self::isoToTs($iso);
        if ($ts === null) {
            $ts = time();
        }
        return self::nowIso($ts + $m * 60);
    }

    /** Minutes from $a to $b ($b − $a); positive when $b is later than $a. Unparsable input counts as "now". */
    public static function isoDiffMinutes(string $a, string $b): float
    {
        $ta = self::isoToTs($a);
        $tb = self::isoToTs($b);
        if ($ta === null) {
            $ta = time();
        }
        if ($tb === null) {
            $tb = time();
        }
        return ($tb - $ta) / 60.0;
    }

    /* --------------------------------------------------------------- decimals */

    /**
     * Convert an int, float or numeric string into a plain decimal string:
     * no exponent, no leading '+', no superfluous zeros ("0.001", "12", "-3.5").
     * Floats are rendered with $maxDecimals fractional digits first (sprintf %F,
     * so 0.3 becomes "0.3" and not "0.29999999999999998890").
     *
     * @param int|float|string $v
     * @throws InvalidArgumentException on non-numeric strings
     */
    public static function toDecimalString($v, int $maxDecimals = self::FLOAT_DECIMALS): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                return '0';
            }
            return self::trimZeros(sprintf('%.' . max(0, $maxDecimals) . 'F', $v));
        }
        if (is_bool($v) || $v === null) {
            return $v ? '1' : '0';
        }
        $s = trim((string) $v);
        if ($s === '') {
            return '0';
        }
        if (!preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $s, $m) || ($m[2] === '' && (!isset($m[3]) || $m[3] === ''))) {
            throw new InvalidArgumentException('Not a numeric string: ' . $s);
        }
        $sign = $m[1] === '-' ? '-' : '';
        $int  = $m[2] === '' ? '0' : $m[2];
        $frac = isset($m[3]) ? $m[3] : '';
        $exp  = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        if ($exp !== 0) {
            // shift the decimal point by $exp places
            $digits = $int . $frac;
            $point  = strlen($int) + $exp; // new position of the decimal point inside $digits
            if ($point <= 0) {
                $digits = str_repeat('0', -$point + 1) . $digits;
                $point  = 1;
            } elseif ($point > strlen($digits)) {
                $digits .= str_repeat('0', $point - strlen($digits));
            }
            $int  = substr($digits, 0, $point);
            $frac = substr($digits, $point);
        }
        return self::trimZeros($sign . $int . ($frac !== '' ? '.' . $frac : ''));
    }

    /** Remove superfluous zeros from a plain decimal string ("007.500" → "7.5", "-0.0" → "0"). */
    public static function trimZeros(string $dec): string
    {
        $dec  = trim($dec);
        $sign = '';
        if ($dec !== '' && ($dec[0] === '-' || $dec[0] === '+')) {
            $sign = $dec[0] === '-' ? '-' : '';
            $dec  = substr($dec, 1);
        }
        $int  = $dec;
        $frac = '';
        $pos  = strpos($dec, '.');
        if ($pos !== false) {
            $int  = substr($dec, 0, $pos);
            $frac = rtrim(substr($dec, $pos + 1), '0');
        }
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        $out = $int . ($frac !== '' ? '.' . $frac : '');
        if ($out === '0') {
            return '0';
        }
        return $sign . $out;
    }

    /** Right-pad (never round) a plain decimal string to exactly $decimals fractional digits. */
    public static function padDecimals(string $dec, int $decimals): string
    {
        $pos = strpos($dec, '.');
        if ($decimals <= 0) {
            return $pos === false ? $dec : substr($dec, 0, $pos);
        }
        if ($pos === false) {
            return $dec . '.' . str_repeat('0', $decimals);
        }
        $frac = substr($dec, $pos + 1);
        if (strlen($frac) >= $decimals) {
            return substr($dec, 0, $pos + 1 + $decimals);
        }
        return $dec . str_repeat('0', $decimals - strlen($frac));
    }

    /** '0.00100000' → 3 ; '1.00000000' → 0 ; '0.5' → 1 ; '1E-5' → 5 */
    public static function decimalsOf(string $step): int
    {
        $s   = self::toDecimalString($step);
        $pos = strpos($s, '.');
        return $pos === false ? 0 : strlen($s) - $pos - 1;
    }

    /**
     * Exact floor of $qty to a multiple of $step (Binance LOT_SIZE stepSize).
     * Uses bcmath when loaded, otherwise integer math on 10^decimals.
     * Returns a plain decimal string without exponent or trailing zeros
     * ("1.234", "5"). Non-positive quantities and a zero step yield "0"
     * (a zero step returns the trimmed quantity unchanged).
     *
     * @param string|float|int $qty
     */
    public static function floorToStep($qty, string $step): string
    {
        $q = self::toDecimalString($qty);
        $s = self::toDecimalString($step);
        if ($q === '0' || $q[0] === '-') {
            return '0';
        }
        if ($s === '0' || $s[0] === '-') {
            return $q;
        }
        if (extension_loaded('bcmath')) {
            return self::floorToStepBcmath($q, $s);
        }
        return self::floorToStepInteger($q, $s);
    }

    /** bcmath implementation of floorToStep; inputs must be plain positive decimal strings. */
    public static function floorToStepBcmath(string $q, string $s): string
    {
        $d = self::decimalsOf($s);
        $n = bcdiv($q, $s, 0);          // truncates toward zero == floor for positive values
        return self::trimZeros(bcmul($n, $s, $d));
    }

    /** Pure-integer implementation of floorToStep (no extension needed); inputs are plain positive decimal strings. */
    public static function floorToStepInteger(string $q, string $s): string
    {
        $d  = self::decimalsOf($s);
        $qi = self::scaleToInt($q, $d);   // floor(q × 10^d) as digit string
        $si = self::scaleToInt($s, $d);   // s × 10^d as digit string (exact)
        if ($si === '0') {
            return self::trimZeros($q);
        }
        if (strlen($qi) <= 18 && strlen($si) <= 18) {
            $qn = (int) $qi;
            $sn = (int) $si;
            $r  = intdiv($qn, $sn) * $sn;
            return self::unscaleInt((string) $r, $d);
        }
        if (strlen($si) > 17) {
            // step larger than 10^17 units — not a real exchange filter; be conservative
            throw new InvalidArgumentException('Step too large for integer arithmetic: ' . $s);
        }
        $rem = self::strMod($qi, (int) $si);
        return self::unscaleInt(self::strSubSmall($qi, $rem), $d);
    }

    /** floor($dec × 10^$d) as a digit string (extra fractional digits are truncated). */
    public static function scaleToInt(string $dec, int $d): string
    {
        $pos  = strpos($dec, '.');
        $int  = $pos === false ? $dec : substr($dec, 0, $pos);
        $frac = $pos === false ? '' : substr($dec, $pos + 1);
        if (strlen($frac) < $d) {
            $frac .= str_repeat('0', $d - strlen($frac));
        } else {
            $frac = substr($frac, 0, $d);
        }
        $all = ltrim($int . $frac, '0');
        return $all === '' ? '0' : $all;
    }

    /** Digit string ÷ 10^$d as a trimmed plain decimal. */
    public static function unscaleInt(string $digits, int $d): string
    {
        if ($d <= 0) {
            return self::trimZeros($digits);
        }
        if (strlen($digits) <= $d) {
            $digits = str_repeat('0', $d - strlen($digits) + 1) . $digits;
        }
        $cut = strlen($digits) - $d;
        return self::trimZeros(substr($digits, 0, $cut) . '.' . substr($digits, $cut));
    }

    /** Remainder of a non-negative digit string modulo a positive int (digit-by-digit, no overflow). */
    public static function strMod(string $digits, int $m): int
    {
        $r = 0;
        $n = strlen($digits);
        for ($i = 0; $i < $n; $i++) {
            $r = ($r * 10 + (ord($digits[$i]) - 48)) % $m;
        }
        return $r;
    }

    /** Non-negative digit string minus a small non-negative int (result assumed ≥ 0). */
    public static function strSubSmall(string $digits, int $sub): string
    {
        $out    = $digits;
        $borrow = $sub;
        for ($i = strlen($out) - 1; $i >= 0 && $borrow > 0; $i--) {
            $cur    = (ord($out[$i]) - 48) - ($borrow % 10);
            $borrow = intdiv($borrow, 10);
            if ($cur < 0) {
                $cur += 10;
                $borrow++;
            }
            $out[$i] = chr(48 + $cur);
        }
        $out = ltrim($out, '0');
        return $out === '' ? '0' : $out;
    }

    /* ------------------------------------------------------------- formatting */

    /** sprintf('%.{d}F') with trailing zeros trimmed; never exponent notation. */
    public static function fmtQty(float $q, int $decimals): string
    {
        if (!is_finite($q)) {
            return '0';
        }
        $decimals = max(0, $decimals);
        $s = sprintf('%.' . $decimals . 'F', $q);
        if ($decimals > 0) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        if ($s === '-0' || $s === '' || $s === '-') {
            $s = '0';
        }
        return $s;
    }

    /** USDT quoteOrderQty: floored (never rounded up) to 2 decimals, always "x.yz". */
    public static function fmtQuote(float $q): string
    {
        if (!is_finite($q) || $q <= 0) {
            return '0.00';
        }
        return self::padDecimals(self::floorToStep($q, '0.01'), 2);
    }

    /** Panel display only. */
    public static function money(float $v, int $d = 4): string
    {
        if (!is_finite($v)) {
            return '0';
        }
        return number_format($v, max(0, $d), '.', '');
    }

    /* ----------------------------------------------------------------- misc */

    public static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    public static function clamp(float $v, float $lo, float $hi): float
    {
        if ($lo > $hi) {
            $t  = $lo;
            $lo = $hi;
            $hi = $t;
        }
        return $v < $lo ? $lo : ($v > $hi ? $hi : $v);
    }

    /** 'b-SOLUSDT-1788425760' (BUY → 'b', SELL → 's'); at most 36 chars, Binance-safe charset. */
    public static function clientOrderId(string $side, string $symbol, int $tickMinute): string
    {
        $s  = strtolower(substr(trim($side), 0, 1));
        $s  = $s === 's' ? 's' : 'b';
        $id = $s . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $symbol)) . '-' . $tickMinute;
        return substr($id, 0, 36);
    }
}
