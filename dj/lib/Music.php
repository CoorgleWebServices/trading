<?php
declare(strict_types=1);

require_once __DIR__ . '/Data.php';

/**
 * dj/lib/Music.php — the domain logic of the desk: the Camelot wheel, what mixes
 * out of what, the energy ramp, and the prep / promo checklist arithmetic.
 *
 * Everything here is pure and static: no database, no session, no globals, no
 * output. Each function takes plain arrays (crate rows and gig rows as they come
 * back from Db) and returns plain values, so every one of them is directly unit
 * testable. Ported from the artifact's keyNeighbours / matchesFor / energyColor /
 * prep* helpers, keeping its reason wording ("8A neighbour · +2 BPM · lifts").
 *
 * Self-contained: this file requires only dj/lib/Data.php.
 */
final class Music
{
    /** Tempo band either side of a track, as a fraction. ±6%. */
    const BPM_TOLERANCE = 0.06;

    /** The crate detail panel shows at most this many "mixes out of this" rows. */
    const MAX_MATCHES = 6;

    /** Guard against float comparison noise right on the ±6% edge. */
    const EPSILON = 0.000001;

    /** The three points of the energy ramp: cyan → marigold → rani. */
    const ENERGY_STOPS = [
        [43, 168, 176],
        [224, 154, 43],
        [220, 62, 124],
    ];

    /**
     * All 24 Camelot codes in wheel order: 1A, 1B, 2A, 2B … 12A, 12B.
     *
     * @return string[]
     */
    public static function camelotWheel(): array
    {
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $out[] = $i . 'A';
            $out[] = $i . 'B';
        }
        return $out;
    }

    /**
     * Tidy a user-typed key into a canonical Camelot code, or '' if it is not one.
     * Accepts ' 8a ' and returns '8A'; returns '' for '', null, 'Fm' or '13A'.
     *
     * @param mixed $k
     */
    public static function normalizeKey($k): string
    {
        if (!is_string($k) && !is_int($k)) {
            return '';
        }
        $s = strtoupper(trim((string)$k));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^(0?[1-9]|1[0-2])([AB])$/', $s, $m) !== 1) {
            return '';
        }
        return ((int)$m[1]) . $m[2];
    }

    /**
     * The keys that mix with $k: the key itself, ±1 around the wheel (12 wraps to
     * 1 and 1 wraps to 12), and the same number with the other letter.
     * An unparseable key has no neighbours, which is the artifact's behaviour too.
     *
     * @param mixed $k
     * @return string[]
     */
    public static function keyNeighbours($k): array
    {
        $key = self::normalizeKey($k);
        if ($key === '') {
            return [];
        }
        $n = (int)substr($key, 0, -1);
        $letter = substr($key, -1);
        $up = ($n % 12) + 1;
        $down = (($n + 10) % 12) + 1;
        $other = ($letter === 'A') ? 'B' : 'A';
        return [$key, $up . $letter, $down . $letter, $n . $other];
    }

    /**
     * True when $b sits in $a's neighbour set. False whenever either key is
     * missing or unparseable — callers decide what an unknown key means.
     *
     * @param mixed $a
     * @param mixed $b
     */
    public static function keysCompatible($a, $b): bool
    {
        $bk = self::normalizeKey($b);
        if ($bk === '') {
            return false;
        }
        return in_array($bk, self::keyNeighbours($a), true);
    }

    /**
     * BPM of a row as a float, 0.0 when missing, blank, negative or non-numeric.
     * Everything tempo-related goes through this so nothing ever divides by zero.
     *
     * @param array|null $row
     */
    public static function bpm($row): float
    {
        if (!is_array($row) || !isset($row['bpm']) || !is_numeric($row['bpm'])) {
            return 0.0;
        }
        $v = (float)$row['bpm'];
        return $v > 0 ? $v : 0.0;
    }

    /**
     * Up to six tracks in $crate that mix out of $track: a Camelot neighbour whose
     * tempo sits inside ±6% (a track with no BPM on either side is not excluded by
     * tempo — an acapella rides anything). Each match carries the artifact's plain
     * reason string, e.g. "8A neighbour · +2 BPM · lifts".
     *
     * @param array   $track the track being opened
     * @param array[] $crate every other row to consider
     * @return array[] [['track' => row, 'why' => string, 't' => row, 'reason' => string], …]
     *                 't' and 'reason' are aliases kept for the artifact's own key names.
     */
    public static function matchesFor(array $track, array $crate): array
    {
        $neighbours = self::keyNeighbours(isset($track['key']) ? $track['key'] : '');
        if (!$neighbours) {
            return [];
        }
        $tBpm = self::bpm($track);
        $lo = $tBpm * (1 - self::BPM_TOLERANCE);
        $hi = $tBpm * (1 + self::BPM_TOLERANCE);
        $tKey = self::normalizeKey(isset($track['key']) ? $track['key'] : '');
        $tEnergy = self::energy($track);
        $tId = isset($track['id']) ? (string)$track['id'] : null;

        $out = [];
        foreach ($crate as $other) {
            if (!is_array($other)) {
                continue;
            }
            $oId = isset($other['id']) ? (string)$other['id'] : null;
            if ($tId !== null && $oId !== null && $oId === $tId) {
                continue;
            }
            $oKey = self::normalizeKey(isset($other['key']) ? $other['key'] : '');
            if ($oKey === '' || !in_array($oKey, $neighbours, true)) {
                continue;
            }
            $oBpm = self::bpm($other);
            if ($tBpm > 0 && $oBpm > 0 && ($oBpm < $lo - self::EPSILON || $oBpm > $hi + self::EPSILON)) {
                continue;
            }

            $why = [];
            $why[] = ($oKey === $tKey) ? 'same key' : $oKey . ' neighbour';
            if ($tBpm > 0 && $oBpm > 0) {
                $why[] = ($oBpm > $tBpm ? '+' : '') . self::round($oBpm - $tBpm) . ' BPM';
            }
            $oEnergy = self::energy($other);
            if ($oEnergy > $tEnergy) {
                $why[] = 'lifts';
            } elseif ($oEnergy < $tEnergy) {
                $why[] = 'drops';
            }
            $reason = implode(' · ', $why);

            $out[] = ['track' => $other, 'why' => $reason, 't' => $other, 'reason' => $reason];
            if (count($out) >= self::MAX_MATCHES) {
                break;
            }
        }
        return $out;
    }

    /**
     * The energy ramp, 1 (cool intro cyan) through 10 (hot peak rani), as a CSS
     * colour. Out-of-range and missing values clamp to the ends rather than throw.
     * Returned in the artifact's exact form, e.g. "rgb(43,168,176)".
     *
     * @param mixed $e
     */
    public static function energyColor($e): string
    {
        $v = is_numeric($e) ? (float)$e : 1.0;
        if ($v <= 0) {
            $v = 1.0;
        }
        $t = max(0.0, min(1.0, ($v - 1) / 9)) * 2;
        $i = (int)min(1, (int)floor($t));
        $f = $t - $i;
        $a = self::ENERGY_STOPS[$i];
        $b = self::ENERGY_STOPS[$i + 1];
        return 'rgb('
            . self::round($a[0] + ($b[0] - $a[0]) * $f) . ','
            . self::round($a[1] + ($b[1] - $a[1]) * $f) . ','
            . self::round($a[2] + ($b[2] - $a[2]) * $f) . ')';
    }

    /**
     * Every prep step, flattened out of its phase, in order.
     *
     * @return array[] each ['id' => string, 't' => title, 'd' => body]
     */
    public static function prepFlat(): array
    {
        return self::flatten(Data::PREPSTEPS);
    }

    /** How many prep steps there are in total (14). */
    public static function prepTotal(): int
    {
        return count(self::prepFlat());
    }

    /**
     * How many of the 14 prep steps are ticked on this track.
     *
     * @param array|null $track
     */
    public static function prepDoneCount($track): int
    {
        return self::countDone(self::prepDone($track), self::prepFlat());
    }

    /**
     * The ticked-step map of a track, whichever way the row spells it and whether
     * it arrives decoded or still as the JSON text stored in crate.prep_done.
     *
     * @param array|null $track
     * @return array<string,bool>
     */
    public static function prepDone($track): array
    {
        return self::stateMap($track, ['prep_done', 'prepDone']);
    }

    /**
     * The prep state a track's ticks add up to: all 14 steps ⇒ 'ready'; the four
     * source + grid steps (src, bpm, grid, drift) ⇒ 'gridded'; otherwise 'raw'.
     *
     * @param array|null $track
     */
    public static function prepDerive($track): string
    {
        $done = self::prepDone($track);
        if (self::countDone($done, self::prepFlat()) >= self::prepTotal()) {
            return 'ready';
        }
        foreach (Data::GRIDDED_STEPS as $id) {
            if (empty($done[$id])) {
                return 'raw';
            }
        }
        return 'gridded';
    }

    /**
     * Every promo step, flattened out of its phase, in order.
     *
     * @return array[] each ['id' => string, 't' => title, 'd' => body]
     */
    public static function promoFlat(): array
    {
        return self::flatten(Data::PROMOSTEPS);
    }

    /** How many promo steps there are in total (13). */
    public static function promoTotal(): int
    {
        return count(self::promoFlat());
    }

    /**
     * How many of the 13 promo steps are ticked on this gig.
     *
     * @param array|null $gig
     */
    public static function promoDoneCount($gig): int
    {
        return self::countDone(self::promoDone($gig), self::promoFlat());
    }

    /**
     * The ticked-step map of a gig, decoded from gigs.promo if it is still JSON.
     *
     * @param array|null $gig
     * @return array<string,bool>
     */
    public static function promoDone($gig): array
    {
        return self::stateMap($gig, ['promo']);
    }

    /**
     * Whether the join from $a into $b is rough, and why in one short line.
     *
     * Returns '' — no warning — when the two are Camelot-compatible, or when their
     * tempos are within 6% of each other, or when neither can be judged because
     * both keys and both BPMs are missing. Otherwise a concrete reason naming the
     * dimensions that were actually known, e.g.
     * "8A → 5A is not a Camelot neighbour · 104 → 140 BPM is a 34.6% jump".
     *
     * @param array|null $a the outgoing track
     * @param array|null $b the incoming track
     */
    public static function joinWarning($a, $b): string
    {
        $aKey = is_array($a) && isset($a['key']) ? self::normalizeKey($a['key']) : '';
        $bKey = is_array($b) && isset($b['key']) ? self::normalizeKey($b['key']) : '';
        $aBpm = self::bpm($a);
        $bBpm = self::bpm($b);

        $keyKnown = ($aKey !== '' && $bKey !== '');
        $bpmKnown = ($aBpm > 0 && $bBpm > 0);

        if (!$keyKnown && !$bpmKnown) {
            return '';
        }
        if ($keyKnown && self::keysCompatible($aKey, $bKey)) {
            return '';
        }
        $drift = 0.0;
        if ($bpmKnown) {
            $drift = abs($bBpm - $aBpm) / $aBpm;
            if ($drift <= self::BPM_TOLERANCE + self::EPSILON) {
                return '';
            }
        }

        $why = [];
        if ($keyKnown) {
            $why[] = $aKey . ' → ' . $bKey . ' is not a Camelot neighbour';
        }
        if ($bpmKnown) {
            $why[] = self::round($aBpm) . ' → ' . self::round($bBpm)
                . ' BPM is a ' . self::pct($drift * 100) . '% jump';
        }
        return implode(' · ', $why);
    }

    /* ---------------------------------------------------------------- private */

    /**
     * Energy of a row as an int, 0 when missing or not a number.
     *
     * @param array $row
     */
    private static function energy(array $row): int
    {
        if (!isset($row['energy']) || !is_numeric($row['energy'])) {
            return 0;
        }
        return (int)$row['energy'];
    }

    /**
     * Pull the steps out of their phases, in order.
     *
     * @param array[] $phases
     * @return array[]
     */
    private static function flatten(array $phases): array
    {
        $out = [];
        foreach ($phases as $phase) {
            foreach ($phase['steps'] as $step) {
                $out[] = $step;
            }
        }
        return $out;
    }

    /**
     * Read a {stepId: true} map off a row, tolerating a missing column, a raw JSON
     * string (a caller that skipped Db's decoding) and anything malformed.
     *
     * @param array|null $row
     * @param string[]   $names column names to try, in order
     * @return array<string,bool>
     */
    private static function stateMap($row, array $names): array
    {
        if (!is_array($row)) {
            return [];
        }
        foreach ($names as $name) {
            if (!isset($row[$name])) {
                continue;
            }
            $v = $row[$name];
            if (is_array($v)) {
                return $v;
            }
            if (is_string($v) && $v !== '') {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return [];
    }

    /**
     * How many of $steps are ticked in $done.
     *
     * @param array<string,bool> $done
     * @param array[]            $steps
     */
    private static function countDone(array $done, array $steps): int
    {
        $n = 0;
        foreach ($steps as $step) {
            if (!empty($done[$step['id']])) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Round half up towards +infinity, the way JavaScript's Math.round does, so a
     * negative BPM delta reads the same here as it does in the artifact.
     */
    private static function round(float $v): int
    {
        return (int)floor($v + 0.5);
    }

    /** One decimal place, with a bare integer when the decimal would be a zero. */
    private static function pct(float $v): string
    {
        $s = number_format($v, 1, '.', '');
        if (substr($s, -2) === '.0') {
            $s = substr($s, 0, -2);
        }
        return $s;
    }
}
