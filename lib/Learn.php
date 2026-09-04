<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';

/**
 * Evidence and bounded feedback (docs/DESIGN-LEARNING.md §3 and §4).
 *
 * What this class is: honest, inspectable statistics over the bot's own
 * observations (Db::insertObservation(), DESIGN-LEARNING §2) plus a deliberately
 * tiny feedback loop. It buckets the conditions present at each entry
 * evaluation, reports which buckets actually preceded profit with a Wilson
 * confidence interval, and - only when the evidence clears that interval -
 * nudges ONE Strategy score-component weight by at most 10 points.
 *
 * THE SAFETY INVARIANT (DESIGN-LEARNING §1 and §4). Learning may never increase
 * risk. It is structurally impossible for this file to change position size,
 * take-profit, stop-loss, sleeve budgets or any kill switch:
 *
 *   * it never requires config.php and never calls trader_save_config(): the
 *     configuration is read-only input here, so no config key can be written;
 *   * every state write goes through writeState(), which throws unless the key
 *     is one of ALLOWED_STATE (learn_weights, learn_at, learn_log);
 *   * sanitizeWeights() rebuilds the weight map from COMPONENTS alone, so an
 *     unknown key (hand-edited state, a future caller) is dropped rather than
 *     stored, and every delta is clamped to +-MAX_DELTA and to the component's
 *     own base points - a component can be neutralised, never inverted;
 *   * recompute() changes at most one component per run, keeps the threshold
 *     inside [entry_threshold - 10, 100], and honours learn_recompute_hours,
 *     enforcing all of that itself rather than trusting its caller.
 *
 * buckets() and wilson() are pure static functions over their arguments: no
 * database, no clock, no configuration, no state.
 */
final class Learn
{
    /** Two-sided 95 % normal quantile, for the Wilson score interval. */
    const Z95 = 1.959963984540054;

    /**
     * Family-wise error rate the `confident` gate targets. insights() scans every
     * FEATURE and tests the max-vs-min bucket pair of each, so the nominal 5 %
     * belongs to the WHOLE scan, not to any one card: the gate interval is widened
     * by Bonferroni over the comparisons that were eligible, and the note says how
     * many there were. The per-bucket intervals shown in the tables stay at a plain
     * 95 %, which is what their column header claims.
     */
    const FAMILY_ALPHA = 0.05;

    /* ---------------------------------------------------- config defaults */

    const DEFAULT_MIN_SAMPLES     = 60;     // learn_min_samples
    const DEFAULT_RECOMPUTE_HOURS = 168;    // learn_recompute_hours (one week)
    const DEFAULT_WINDOW_DAYS     = 90;     // learn_window_days
    const DEFAULT_ENTRY_THRESHOLD = 60;     // entry_threshold (DESIGN.md §3)

    /* ------------------------------------------------------------- caps §4 */

    /** Every delta is clamped to this many points (and to the component's base). */
    const MAX_DELTA = 10;

    /** The entry threshold may never fall further than this below entry_threshold. */
    const THRESHOLD_SLACK = 10;

    /** The ONLY state keys this class may write. writeState() throws on anything else. */
    const ALLOWED_STATE = ['learn_weights', 'learn_at', 'learn_log'];

    /** The Strategy score components a learned delta may touch (DESIGN-LEARNING §4). */
    const COMPONENTS = ['rsi', 'bb', 'reversal', 'rsi_up', 'vol', 'trend'];

    /**
     * Base points of each component (DESIGN.md §8 score table); mirrors
     * Strategy::LEARN_COMPONENTS. A delta is clamped to this as well as to
     * MAX_DELTA, so a component can be neutralised but never inverted into a bonus
     * for the opposite condition - and a component that carries no points at all
     * (`trend`: the 1h regime is a gate, not a score row) can never be nudged.
     * componentCap() takes the stricter of this and Strategy's own table whenever
     * Strategy is loaded, so Learn can never propose a delta Strategy would drop.
     */
    const BASE_POINTS = [
        'rsi' => 20, 'bb' => 20, 'reversal' => 20, 'rsi_up' => 20, 'vol' => 20, 'trend' => 0,
    ];

    /** How many recompute decisions the learn_log keeps. */
    const LOG_ENTRIES = 20;

    /** Hard ceiling on the evidence a single call will read. */
    const MAX_ROWS = 50000;

    /** Outcomes that represent a trade the bot actually took. */
    const TRADED_OUTCOMES = ['win', 'loss', 'flat'];

    /**
     * The weights tune Strategy's score, which only the signal engine computes,
     * so only signal-engine observations may drive an adjustment. '' is included
     * for rows captured before an engine was recorded.
     */
    const LEARN_ENGINES = ['signal', ''];

    /** Features captured per observation (DESIGN-LEARNING §2), in panel order. */
    const FEATURES = [
        'rsi', 'atr_pct', 'atr1h_pct', 'bb_pos', 'vol_ratio', 'trend_up',
        'spread_pct', 'hour_utc', 'dow', 'dist_from_anchor_pct', 'step_value_pct',
    ];

    /**
     * Default bucket edges (DESIGN-LEARNING §3). Bucket i covers [edge[i], edge[i+1]),
     * the last one includes its upper edge. An empty list means the feature is
     * categorical (one bucket per distinct value): `trend_up` and `dow`.
     */
    const EDGES = [
        'rsi'                  => [0, 20, 25, 30, 40, 50, 70, 100],
        'atr_pct'              => [0, 0.3, 0.5, 0.8, 1.2, 2, 100],
        'atr1h_pct'            => [0, 0.5, 1, 1.5, 2, 3, 100],
        'bb_pos'               => [0, 0.1, 0.25, 0.5, 0.75, 1],
        'vol_ratio'            => [0, 0.8, 1, 1.2, 1.5, 3, 999],
        'trend_up'             => [],
        'spread_pct'           => [0, 0.01, 0.02, 0.03, 0.05, 1],
        'hour_utc'             => [0, 4, 8, 12, 16, 20, 24],
        'dow'                  => [],
        'dist_from_anchor_pct' => [-100, -2, -1, -0.5, 0, 0.5, 1, 2, 100],
        'step_value_pct'       => [0, 0.05, 0.1, 0.2, 0.3, 100],
    ];

    /** Human names for the panel. */
    const FEATURE_LABELS = [
        'rsi'                  => 'RSI14 (15m)',
        'atr_pct'              => 'ATR14 % of price (15m)',
        'atr1h_pct'            => 'ATR14 % of price (1h)',
        'bb_pos'               => 'Bollinger position (0 = lower band, 1 = upper)',
        'vol_ratio'            => 'Volume / SMA20 volume',
        'trend_up'             => '1h regime gate (1 = trend up)',
        'spread_pct'           => 'Spread %',
        'hour_utc'             => 'Hour of day (UTC)',
        'dow'                  => 'Day of week (0 = Sunday)',
        'dist_from_anchor_pct' => 'Distance from the grid anchor %',
        'step_value_pct'       => 'Step value % of the order (dust coarseness)',
    ];

    /**
     * Which captured feature stands for which score component, and the condition
     * under which that component fires in Strategy::evaluate() (DESIGN.md §8).
     * A component whose feature is absent from the observations is simply never
     * adjustable - there is no evidence for it.
     */
    const COMPONENT_CONDITION = [
        'rsi'      => ['feature' => 'rsi',       'op' => 'lte', 'value' => 30.0, 'label' => 'RSI14 <= 30'],
        'bb'       => ['feature' => 'bb_pos',    'op' => 'lte', 'value' => 0.0,  'label' => 'close at or below the lower Bollinger band'],
        'vol'      => ['feature' => 'vol_ratio', 'op' => 'gte', 'value' => 1.2,  'label' => 'volume >= 1.2x SMA20 volume'],
        // `trend` carries no score points, so its cap is 0 and it is never adjusted;
        // the condition stays listed so the panel can still name what it would mean.
        'trend'    => ['feature' => 'trend_up',  'op' => 'gte', 'value' => 1.0,  'label' => '1h regime up'],
        'rsi_up'   => ['feature' => 'rsi_up',    'op' => 'gte', 'value' => 1.0,  'label' => 'RSI turning up'],
        'reversal' => ['feature' => 'reversal',  'op' => 'gte', 'value' => 1.0,  'label' => 'bullish reversal candle'],
    ];

    /* ==================================================================== */
    /*  Pure statistics - no Db, no clock, no config                        */
    /* ==================================================================== */

    /**
     * Two-sided normal quantile for a Bonferroni family of $m comparisons.
     *
     * $m <= 1 returns Z95 exactly, so a single pre-specified comparison is tested
     * at the documented 95 % and nothing about the existing behaviour moves.
     */
    public static function familyZ(int $m): float
    {
        if ($m <= 1) {
            return self::Z95;
        }
        if ($m > 100000) {
            $m = 100000;
        }
        return self::normalQuantile(1.0 - self::FAMILY_ALPHA / (2.0 * (float) $m));
    }

    /**
     * Inverse standard-normal CDF (Acklam's rational approximation, |error| below
     * 1.2e-9). Pure arithmetic - no ext/stats, no lookup table.
     */
    private static function normalQuantile(float $p): float
    {
        if (!is_finite($p)) {
            return self::Z95;
        }
        $p = Util::clamp($p, 1.0e-12, 1.0 - 1.0e-12);

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
              1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
              6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
              -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
              3.754408661907416e+00];

        $plow  = 0.02425;
        $phigh = 1.0 - $plow;

        if ($p < $plow) {
            $q = sqrt(-2.0 * log($p));
            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                 / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }
        if ($p > $phigh) {
            $q = sqrt(-2.0 * log(1.0 - $p));
            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                 / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }
        $q = $p - 0.5;
        $r = $q * $q;
        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
             / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
    }

    /**
     * Wilson score interval at 95 % for $k successes out of $n.
     *
     * Never divides by zero: n = 0 returns the no-information interval [0, 1]
     * (which therefore overlaps everything and can never be "confident"), and
     * k = 0 / k = n are ordinary cases of the formula - the whole point of Wilson
     * over the normal approximation.
     *
     * $z is the two-sided normal quantile and defaults to the documented 95 %.
     * A caller may pass a wider one (familyZ()) to test a hand-picked extreme
     * against the whole family it was picked from; a non-finite or non-positive
     * value falls back to 95 % rather than producing nonsense.
     *
     * @return array{lo:float,hi:float} both in 0..1
     */
    public static function wilson(int $k, int $n, float $z = self::Z95): array
    {
        if ($n <= 0) {
            return ['lo' => 0.0, 'hi' => 1.0];
        }
        if ($k < 0) {
            $k = 0;
        }
        if ($k > $n) {
            $k = $n;
        }
        if (!is_finite($z) || $z <= 0.0) {
            $z = self::Z95;
        }
        $z2 = $z * $z;
        $nf = (float) $n;
        $p  = $k / $nf;

        $denom  = 1.0 + $z2 / $nf;
        $centre = ($p + $z2 / (2.0 * $nf)) / $denom;
        $inner  = $p * (1.0 - $p) / $nf + $z2 / (4.0 * $nf * $nf);
        if ($inner < 0.0) {
            $inner = 0.0;
        }
        $margin = ($z / $denom) * sqrt($inner);

        return [
            'lo' => Util::clamp($centre - $margin, 0.0, 1.0),
            'hi' => Util::clamp($centre + $margin, 0.0, 1.0),
        ];
    }

    /**
     * Bucket one feature and report the outcome statistics per bucket. Pure over
     * its inputs: it reads only $rows, $feature and $edges.
     *
     * $rows are observation rows: each may carry `features` (JSON string or
     * array) or `features_map`, plus `outcome`, `decision` and `pnl_usdt`. A row
     * whose feature is missing or non-numeric is skipped; a value below the first
     * edge or above the last is counted in the nearest end bucket rather than
     * dropped. EMPTY BUCKETS ARE REPORTED, never dropped, so the panel can show
     * where the bot has no evidence at all.
     *
     * With fewer than two numeric edges the feature is treated as categorical:
     * one bucket per distinct value, ordered by value (`trend_up`, `dow`).
     *
     * Per bucket: label, lo, hi, n (rows in the bucket), wins, losses, flat,
     * skipped, open, decided (wins+losses), win_rate (percent of decided),
     * avg_pnl, total_pnl, and the Wilson interval of the win rate as
     * wilson_lo/wilson_hi (fractions 0..1, identical to wilson()) plus
     * wilson_lo_pct/wilson_hi_pct for display.
     */
    public static function buckets(array $rows, string $feature, array $edges): array
    {
        $clean = [];
        foreach ($edges as $e) {
            if (is_numeric($e)) {
                $v = (float) $e;
                if (is_finite($v)) {
                    $clean[] = $v;
                }
            }
        }
        sort($clean);
        $numeric = count($clean) >= 2;

        $groups = [];
        $meta   = [];
        if ($numeric) {
            $last = count($clean) - 1;
            for ($i = 0; $i < $last; $i++) {
                $groups[$i] = [];
                $join       = ($clean[$i] < 0 || $clean[$i + 1] < 0) ? ' to ' : '-';
                $meta[$i]   = [
                    'label' => self::num($clean[$i]) . $join . self::num($clean[$i + 1]),
                    'lo'    => $clean[$i],
                    'hi'    => $clean[$i + 1],
                ];
            }
        }
        $catValue = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $v = self::featureValue($row, $feature);
            if ($v === null) {
                continue;
            }
            if ($numeric) {
                $key = self::bucketIndex($v, $clean);
            } else {
                $key = self::num($v);
                if (!isset($groups[$key])) {
                    $groups[$key]   = [];
                    $meta[$key]     = ['label' => $key, 'lo' => $v, 'hi' => $v];
                    $catValue[$key] = $v;
                }
            }
            $groups[$key][] = $row;
        }

        if (!$numeric && $catValue !== []) {
            asort($catValue);
            $ordered = [];
            foreach (array_keys($catValue) as $key) {
                $ordered[$key] = $groups[$key];
            }
            $groups = $ordered;
        }

        $out = [];
        foreach ($groups as $key => $bucketRows) {
            $out[] = array_merge(
                ['label' => $meta[$key]['label'], 'lo' => $meta[$key]['lo'], 'hi' => $meta[$key]['hi']],
                self::stat($bucketRows)
            );
        }
        return $out;
    }

    /** Default edges for a feature ([] = categorical / unknown feature). */
    public static function edgesFor(string $feature): array
    {
        return isset(self::EDGES[$feature]) ? self::EDGES[$feature] : [];
    }

    /** The captured feature names, in panel order. */
    public static function features(): array
    {
        return self::FEATURES;
    }

    /* ==================================================================== */
    /*  Evidence                                                            */
    /* ==================================================================== */

    /**
     * Every feature, ranked by how strongly it separates winners from losers.
     *
     * Reads resolved, entered observations of the configured mode from the last
     * $days days ($days <= 0 falls back to learn_window_days). $engine scopes the
     * evidence to one engine; null (the default) reports every engine, which is
     * what the Insights page shows - the weight feedback itself is narrower, see
     * adjustments().
     *
     * Per feature: ['feature','label','buckets'=>[...],'separation'=>float,
     *  'confident'=>bool,'note'=>string, plus 'confident_separation','best',
     *  'worst','confident_best','confident_worst','samples','min_samples',
     *  'eligible','family','open_now'].
     *
     * `confident` is true ONLY when the best and the worst bucket both hold at
     * least learn_min_samples DECIDED (win or loss) rows AND their Wilson
     * intervals are disjoint - and, because the reported pair is the max-vs-min
     * of a scan over every feature, disjoint too once the interval is widened by
     * Bonferroni over `family`, the number of bucket comparisons this scan could
     * have reported. Reporting the best of many comparisons at a nominal 95 % is
     * how a pure-noise feature such as `dow` becomes a headline; `family` is
     * carried on every card and named in the note so the claim is never stated
     * as a bare 95 % result.
     *
     * Each card also reports `open_now`: entries in the same window that carry
     * the feature but have NOT resolved yet. They are excluded from every figure
     * (they have no outcome), and the exclusion is one-sided - a winner hits its
     * take-profit and closes while a loser rides - so the note says so rather
     * than letting a censored bucket read as clean evidence.
     */
    public static function insights(Db $db, array $cfg, int $days = 90, ?string $engine = null): array
    {
        $days    = $days > 0 ? $days : self::windowDays($cfg);
        $min     = self::minSamples($cfg);
        $engines = ($engine === null || $engine === '') ? null : [$engine];
        $rows    = self::evidenceRows($db, $cfg, $days, $engines, null);
        // The same window's STILL OPEN entries. They are never evidence - they have
        // no outcome - but a bucket whose losers are still riding would otherwise
        // read as clean evidence, so every card reports how much of itself is censored.
        $openRows = self::evidenceRows($db, $cfg, $days, $engines, null, true);

        // The family is every bucket pair that COULD have been reported: C(k,2) per
        // feature over its eligible buckets. Bucketing is done once and reused.
        $bucketsBy = [];
        $family    = 0;
        foreach (self::FEATURES as $feature) {
            $b = self::buckets($rows, $feature, self::edgesFor($feature));
            $bucketsBy[$feature] = $b;
            $k = 0;
            foreach ($b as $bucket) {
                if ((int) $bucket['decided'] >= $min) {
                    $k++;
                }
            }
            if ($k >= 2) {
                $family += (int) (($k * ($k - 1)) / 2);
            }
        }
        $z = self::familyZ($family);

        $out = [];
        foreach (self::FEATURES as $feature) {
            $out[] = self::featureInsight($rows, $feature, $min, $bucketsBy[$feature], $z, $family, $openRows);
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['confident'] !== $b['confident']) {
                return $a['confident'] ? -1 : 1;
            }
            $ca = abs((float) $a['confident_separation']);
            $cb = abs((float) $b['confident_separation']);
            if ($ca !== $cb) {
                return $ca < $cb ? 1 : -1;
            }
            $sa = abs((float) $a['separation']);
            $sb = abs((float) $b['separation']);
            if ($sa !== $sb) {
                return $sa < $sb ? 1 : -1;
            }
            return strcmp((string) $a['feature'], (string) $b['feature']);
        });
        return $out;
    }

    /**
     * Bounded weight nudges implied by the evidence; [] when the evidence is
     * insufficient - which, at a few trades a day, it will be for weeks.
     *
     * For each score component the observations are split into the rows where the
     * component's condition held and the rows where it did not. A nudge is
     * proposed only when BOTH sides hold at least learn_min_samples rows, their
     * Wilson win-rate intervals are disjoint, and the expectancy difference
     * agrees in sign with the win-rate difference. The delta is then +-10 points,
     * clamped again to the component's base value.
     *
     * Only signal-engine observations count: these deltas tune Strategy's score,
     * which is the signal engine's model. $nowIso restricts the evidence to what
     * was resolved strictly before that instant (walk-forward; recompute() passes
     * its own instant).
     *
     * @return array list of ['component','feature','delta','separation','when',
     *               'fires','others','n','note'], strongest separation first
     */
    public static function adjustments(Db $db, array $cfg, ?string $nowIso = null): array
    {
        $rows = self::evidenceRows($db, $cfg, self::windowDays($cfg), self::LEARN_ENGINES, $nowIso);
        return self::adjustmentsFrom($rows, $cfg);
    }

    /* ==================================================================== */
    /*  Bounded feedback                                                    */
    /* ==================================================================== */

    /**
     * Apply the §4 caps and write learn_weights + learn_at (and a learn_log
     * entry). Returns what it changed and on what evidence.
     *
     * Every cap is enforced here, not by the caller:
     *   * each delta is clamped to +-MAX_DELTA and to the component's base value;
     *   * at most ONE component changes per run, the one with the strongest
     *     confident separation;
     *   * the effective entry threshold is kept inside
     *     [entry_threshold - THRESHOLD_SLACK, 100] - the learner never moves it
     *     on its own, it only re-clamps whatever the map already carries;
     *   * a recompute inside learn_recompute_hours of the last one does nothing;
     *   * only observations RESOLVED BEFORE this instant are evidence, so the
     *     bot never trains on what it is about to trade.
     *
     * With `learning_apply` false (the default) this is a dry run: it computes
     * everything and writes NOTHING, so the panel can show what it would do.
     *
     * @param string|null $nowIso the recompute instant (tests / walk-forward);
     *                            defaults to now
     */
    public static function recompute(Db $db, array $cfg, ?string $nowIso = null): array
    {
        $now = ($nowIso !== null && $nowIso !== '') ? (string) $nowIso : Util::nowIso();
        $nowTs = Util::isoToTs($now);
        if ($nowTs === null) {
            $now   = Util::nowIso();
            $nowTs = time();
        }

        $entry     = self::entryThreshold($cfg);
        $floor     = self::thresholdFloor($cfg);
        $minSample = self::minSamples($cfg);
        $window    = self::windowDays($cfg);
        $hours     = self::recomputeHours($cfg);
        $enabled   = self::flag($cfg, 'learning_enabled', true);
        $apply     = self::flag($cfg, 'learning_apply', false);
        $current   = self::weights($db, $cfg);
        $lastAt    = (string) $db->getState('learn_at', '');

        $res = [
            'ok'               => true,
            'at'               => $now,
            'applied'          => false,
            'changed'          => null,
            'from'             => 0,
            'to'               => 0,
            'reason'           => '',
            'note'             => '',
            'weights'          => $current,
            'previous'         => $current,
            'threshold'        => (int) $current['threshold'],
            'threshold_range'  => [$floor, 100],
            'entry_threshold'  => $entry,
            'window_days'      => $window,
            'min_samples'      => $minSample,
            'recompute_hours'  => $hours,
            'samples'          => 0,
            'candidates'       => [],
            'evidence'         => null,
            'learning_enabled' => $enabled,
            'learning_apply'   => $apply,
            'last_at'          => $lastAt === '' ? null : $lastAt,
            'next_at'          => null,
        ];

        if (!$enabled) {
            $res['reason'] = 'learning_disabled';
            $res['note']   = 'Learning is switched off (learning_enabled = false); nothing was computed or written.';
            return $res;
        }

        // walk-forward guard: at most one recompute per learn_recompute_hours
        if ($lastAt !== '') {
            $lastTs = Util::isoToTs($lastAt);
            if ($lastTs !== null) {
                $nextTs = $lastTs + $hours * 3600;
                if ($nowTs < $nextTs) {
                    $res['reason']  = 'too_soon';
                    $res['next_at'] = Util::nowIso($nextTs);
                    $res['note']    = 'Last recompute was ' . $lastAt . '; the next one is due at '
                                    . $res['next_at'] . ' (learn_recompute_hours = ' . $hours . ').';
                    return $res;
                }
            }
        }

        $rows = self::evidenceRows($db, $cfg, $window, self::LEARN_ENGINES, $now);
        $res['samples'] = count($rows);
        $candidates     = self::adjustmentsFrom($rows, $cfg);
        $res['candidates'] = $candidates;
        $res['next_at']    = Util::nowIso($nowTs + $hours * 3600);

        // pick the strongest candidate that actually moves its component
        $new     = $current;
        $chosen  = null;
        foreach ($candidates as $candidate) {
            $component = (string) $candidate['component'];
            if (!isset($current[$component]) || !in_array($component, self::COMPONENTS, true)) {
                continue;
            }
            $to = self::clampDelta($component, (float) $current[$component] + (float) $candidate['delta']);
            if ($to === (int) $current[$component]) {
                continue;   // already at the cap in that direction
            }
            $new[$component] = $to;
            $chosen = ['candidate' => $candidate, 'component' => $component,
                       'from' => (int) $current[$component], 'to' => $to];
            break;
        }

        // belt and braces: whatever the loop did, the map that gets written must
        // still satisfy every §4 cap and differ in at most one component
        $new = self::sanitizeWeights($new, $cfg);
        $diff = [];
        foreach (self::COMPONENTS as $component) {
            if ((int) $new[$component] !== (int) $current[$component]) {
                $diff[] = $component;
            }
        }
        if (count($diff) > 1 || (int) $new['threshold'] !== (int) $current['threshold']) {
            // unreachable by construction; refuse rather than write a wide change
            $res['ok']     = false;
            $res['reason'] = 'cap_violation';
            $res['note']   = 'Refused to write: the computed map changed more than one component.';
            $res['weights'] = $current;
            return $res;
        }

        $res['weights'] = $new;
        // report what the SANITISED map actually says, not what the loop intended
        if ($chosen !== null && (int) $new[$chosen['component']] !== (int) $chosen['from']) {
            $chosen['to']    = (int) $new[$chosen['component']];
            $res['changed']  = $chosen['component'];
            $res['from']     = (int) $chosen['from'];
            $res['to']       = $chosen['to'];
            $res['evidence'] = $chosen['candidate'];
            $res['note']     = self::changeNote($chosen, $res['samples']);
        } else {
            $chosen      = null;
            $res['note'] = self::noChangeNote($candidates, $rows, $minSample, $current);
        }

        if (!$apply) {
            $res['reason']  = 'learning_apply_off';
            $res['applied'] = false;
            $res['note']   .= ' learning_apply is off, so nothing was written - this is what it would do.';
            $res['next_at'] = $lastAt === '' ? null : $res['next_at'];
            return $res;
        }

        // ---- the only writes this class ever performs -------------------
        self::writeState($db, 'learn_weights', $new);
        self::writeState($db, 'learn_at', $now);
        self::appendLog($db, [
            'at'      => $now,
            'changed' => $res['changed'],
            'from'    => $res['from'],
            'to'      => $res['to'],
            'samples' => $res['samples'],
            'note'    => trim((string) $res['note']),
            'weights' => $new,
        ]);
        // -----------------------------------------------------------------

        $res['applied'] = true;
        $res['reason']  = $chosen === null ? 'no_confident_evidence' : 'applied';
        return $res;
    }

    /**
     * The current learned weight map: every component present, every value
     * already clamped to the §4 caps, plus `threshold` clamped into
     * [entry_threshold - 10, 100]. Read-only.
     */
    public static function weights(Db $db, array $cfg): array
    {
        return self::sanitizeWeights($db->getStateJson('learn_weights', []), $cfg);
    }

    /**
     * Rebuild a weight map from COMPONENTS alone, clamping every delta to
     * +-MAX_DELTA and to the component's base points, and the threshold into
     * [entry_threshold - THRESHOLD_SLACK, 100]. Unknown keys are dropped: this is
     * what makes it structurally impossible for anything but a score-component
     * delta and the entry threshold to be stored under learn_weights.
     *
     * @param mixed $map
     */
    public static function sanitizeWeights($map, array $cfg): array
    {
        $in  = is_array($map) ? $map : [];
        $out = [];
        foreach (self::COMPONENTS as $component) {
            $v = (isset($in[$component]) && is_numeric($in[$component])) ? (float) $in[$component] : 0.0;
            $out[$component] = self::clampDelta($component, $v);
        }
        $entry = self::entryThreshold($cfg);
        $floor = self::thresholdFloor($cfg);
        $t = (isset($in['threshold']) && is_numeric($in['threshold'])) ? (float) $in['threshold'] : (float) $entry;
        if (!is_finite($t)) {
            $t = (float) $entry;
        }
        $out['threshold'] = (int) round(Util::clamp($t, (float) $floor, 100.0));
        return $out;
    }

    /** Clamp one delta to +-MAX_DELTA and to the component's base value (0 for an unknown component). */
    public static function clampDelta(string $component, float $delta): int
    {
        if (!is_finite($delta)) {
            return 0;
        }
        $cap = self::componentCap($component);
        return (int) round(Util::clamp($delta, -$cap, $cap));
    }

    /**
     * The widest delta a component may carry: the smallest of MAX_DELTA, its base
     * points here, and - when Strategy is loaded - its base points there. A
     * component Strategy does not score (or does not know) caps at 0, so the
     * learner cannot spend its one change per run on a delta that would be ignored.
     */
    private static function componentCap(string $component): float
    {
        $base = isset(self::BASE_POINTS[$component]) ? abs((float) self::BASE_POINTS[$component]) : 0.0;
        if (defined('Strategy::LEARN_COMPONENTS')) {
            $map = constant('Strategy::LEARN_COMPONENTS');
            $theirs = (is_array($map) && isset($map[$component]) && is_numeric($map[$component]))
                ? abs((float) $map[$component]) : 0.0;
            if ($theirs < $base) {
                $base = $theirs;
            }
        }
        $cap = (float) self::MAX_DELTA;
        return $base < $cap ? $base : $cap;
    }

    /** The state keys Learn is allowed to write - the whole write surface. */
    public static function writableStateKeys(): array
    {
        return self::ALLOWED_STATE;
    }

    /** The recompute decisions, newest first. */
    public static function recomputeLog(Db $db, int $limit = self::LOG_ENTRIES): array
    {
        $log = $db->getStateJson('learn_log', []);
        if (!is_array($log)) {
            return [];
        }
        $out = [];
        foreach ($log as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return array_slice($out, 0, max(1, $limit));
    }

    /* ==================================================================== */
    /*  Internals                                                           */
    /* ==================================================================== */

    /**
     * The ONLY way this class touches persistent state. Anything outside
     * ALLOWED_STATE throws instead of being written - position size, take-profit,
     * stop-loss, sleeve budgets and every kill switch are unreachable from here.
     *
     * @param mixed $value
     */
    private static function writeState(Db $db, string $key, $value): void
    {
        if (!in_array($key, self::ALLOWED_STATE, true)) {
            throw new LogicException('Learn may only write ' . implode(', ', self::ALLOWED_STATE)
                . '; refused to write "' . $key . '"');
        }
        $db->setState($key, $value);
    }

    private static function appendLog(Db $db, array $entry): void
    {
        $log = self::recomputeLog($db, self::LOG_ENTRIES);
        array_unshift($log, $entry);
        self::writeState($db, 'learn_log', array_slice($log, 0, self::LOG_ENTRIES));
    }

    /**
     * Resolved, entered observations for the learning window.
     *
     * $engines null = every engine. $before (ISO) keeps only what was resolved
     * strictly before that instant and anchors the window to it, which is what
     * makes a recompute walk-forward.
     *
     * $openOnly returns the mirror image instead: entered rows of the same window
     * and mode that have NOT resolved. Those are never evidence - insights() uses
     * them only to report how much of each bucket is still censored.
     */
    private static function evidenceRows(Db $db, array $cfg, int $days, ?array $engines, ?string $before, bool $openOnly = false): array
    {
        $refTs = time();
        if ($before !== null && $before !== '') {
            $ts = Util::isoToTs($before);
            if ($ts !== null) {
                $refTs = $ts;
            }
        }
        $days   = $days > 0 ? $days : self::DEFAULT_WINDOW_DAYS;
        $filter = [
            'decision' => 'entered',
            'outcome'  => self::TRADED_OUTCOMES,
            'since'    => Util::nowIso($refTs - $days * 86400),
            'limit'    => self::MAX_ROWS,
        ];
        $mode = isset($cfg['mode']) ? trim((string) $cfg['mode']) : '';
        if ($mode !== '') {
            // '' keeps rows captured without a mode visible; a paper row can never
            // reach a live account's evidence and vice versa
            $filter['mode'] = [$mode, ''];
        }
        if ($engines !== null) {
            $filter['engine'] = $engines;
        }
        if ($before !== null && $before !== '') {
            $filter['resolved_before'] = $before;
        }
        if ($openOnly) {
            unset($filter['outcome'], $filter['resolved_before']);
            $filter['resolved'] = false;
        }
        return $db->observations($filter);
    }

    /** Pure part of adjustments(): the candidates implied by an evidence set. */
    private static function adjustmentsFrom(array $rows, array $cfg): array
    {
        $min = self::minSamples($cfg);
        $out = [];
        foreach (self::COMPONENT_CONDITION as $component => $cond) {
            if (!in_array($component, self::COMPONENTS, true)) {
                continue;
            }
            $fires = [];
            $rest  = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $v = self::featureValue($row, (string) $cond['feature']);
                if ($v === null) {
                    continue;
                }
                if (self::holds($v, $cond)) {
                    $fires[] = $row;
                } else {
                    $rest[] = $row;
                }
            }
            if ($fires === [] || $rest === []) {
                continue;
            }
            $a = self::stat($fires);
            $b = self::stat($rest);
            if ((int) $a['decided'] < $min || (int) $b['decided'] < $min) {
                continue;               // not enough evidence on one of the sides
            }
            if (!self::disjoint($a, $b)) {
                continue;               // the intervals overlap: could be luck
            }
            $direction  = $a['wilson_lo'] > $b['wilson_hi'] ? 1 : -1;
            $separation = $a['avg_pnl'] - $b['avg_pnl'];
            if ($separation === 0.0 || ($separation > 0 ? 1 : -1) !== $direction) {
                continue;               // win rate and expectancy disagree: inconclusive
            }
            $delta = self::clampDelta($component, (float) ($direction * self::MAX_DELTA));
            if ($delta === 0) {
                continue;
            }
            $out[] = [
                'component'  => $component,
                'feature'    => (string) $cond['feature'],
                'when'       => (string) $cond['label'],
                'delta'      => $delta,
                'separation' => $separation,
                'direction'  => $direction > 0 ? 'up' : 'down',
                'n'          => $a['n'] + $b['n'],
                'fires'      => $a,
                'others'     => $b,
                'confident'  => true,
                'note'       => 'When ' . $cond['label'] . ': ' . self::describe($a)
                              . '; otherwise: ' . self::describe($b)
                              . '. The win-rate intervals do not overlap.',
            ];
        }
        usort($out, static function (array $x, array $y): int {
            $a = abs((float) $x['separation']);
            $b = abs((float) $y['separation']);
            if ($a === $b) {
                return strcmp((string) $x['component'], (string) $y['component']);
            }
            return $a < $b ? 1 : -1;
        });
        return $out;
    }

    /**
     * One feature's insight card.
     *
     * $buckets lets insights() reuse the bucketing it already did to size the
     * family; $z and $family carry the multiple-comparison correction, and
     * $openRows the still-unresolved entries this card is censored by. All four
     * are optional, so a caller that wants one plain uncorrected card still gets
     * exactly what it always got.
     */
    private static function featureInsight(
        array $rows,
        string $feature,
        int $min,
        ?array $buckets = null,
        float $z = self::Z95,
        int $family = 1,
        array $openRows = []
    ): array {
        if ($buckets === null) {
            $buckets = self::buckets($rows, $feature, self::edgesFor($feature));
        }

        // How many entries carrying this feature are still open, per bucket and in
        // total. Right-censoring runs one way: a trade that has not closed yet is
        // more often a loser still riding, so a win rate computed on the closed ones
        // alone flatters the bucket. The number is REPORTED, never mixed into the
        // evidence - n, decided, the win rate and the Wilson interval are untouched.
        $openTotal   = 0;
        $openByLabel = [];
        foreach (self::buckets($openRows, $feature, self::edgesFor($feature)) as $ob) {
            $openByLabel[(string) $ob['label']] = (int) $ob['n'];
            $openTotal += (int) $ob['n'];
        }
        foreach ($buckets as $i => $b) {
            $label = (string) $b['label'];
            $buckets[$i]['open_now'] = isset($openByLabel[$label]) ? $openByLabel[$label] : 0;
        }

        $samples  = 0;
        $withData = [];
        $eligible = [];
        foreach ($buckets as $b) {
            $samples += (int) $b['n'];
            if ((int) $b['n'] > 0) {
                $withData[] = $b;
            }
            if ((int) $b['decided'] >= $min) {
                $eligible[] = $b;
            }
        }

        $best = null;
        $worst = null;
        $separation = 0.0;
        if (count($withData) >= 2) {
            $pair       = self::extremes($withData);
            $best       = $pair[0];
            $worst      = $pair[1];
            $separation = (float) $best['avg_pnl'] - (float) $worst['avg_pnl'];
        } elseif (count($withData) === 1) {
            $best  = $withData[0];
            $worst = $withData[0];
        }

        $cBest = null;
        $cWorst = null;
        $confidentSeparation = 0.0;
        $confident = false;
        $raw       = false;
        if (count($eligible) >= 2) {
            $pair   = self::extremes($eligible);
            $cBest  = $pair[0];
            $cWorst = $pair[1];
            $confidentSeparation = (float) $cBest['avg_pnl'] - (float) $cWorst['avg_pnl'];
            // The pair is picked by expectancy but the interval test is on the win
            // rate, so the two can point opposite ways (few big winners vs many
            // small ones). Only call it confident when they agree, exactly as
            // adjustmentsFrom() does - otherwise the note would claim the
            // PnL-best bucket "clearly beats" the bucket that wins more often.
            $raw = self::disjoint($cBest, $cWorst)
                && $confidentSeparation > 0.0
                && (float) $cBest['wilson_lo'] > (float) $cWorst['wilson_hi'];
            // ... and the same test again against an interval widened by Bonferroni
            // over the whole family, because this pair is the MAX-vs-MIN of a scan
            // across every feature: the best of many nominal 95 % comparisons is not
            // a 95 % claim about the search that produced it.
            $gBest     = self::wilson((int) $cBest['wins'], (int) $cBest['decided'], $z);
            $gWorst    = self::wilson((int) $cWorst['wins'], (int) $cWorst['decided'], $z);
            $confident = $raw && $gBest['lo'] > $gWorst['hi'];
        }

        return [
            'feature'               => $feature,
            'label'                 => isset(self::FEATURE_LABELS[$feature]) ? self::FEATURE_LABELS[$feature] : $feature,
            'buckets'               => $buckets,
            'separation'            => $separation,
            'confident'             => $confident,
            'confident_separation'  => $confidentSeparation,
            'best'                  => $best,
            'worst'                 => $worst,
            'confident_best'        => $cBest,
            'confident_worst'       => $cWorst,
            'samples'               => $samples,
            'min_samples'           => $min,
            'eligible'              => count($eligible),
            'family'                => $family,
            'open_now'              => $openTotal,
            'note'                  => self::insightNote($feature, $samples, $withData, $eligible, $confident, $cBest, $cWorst, $best, $worst, $min, $family, $raw)
                                     . self::censorNote($openTotal),
        ];
    }

    /** [best, worst] of a bucket list by average PnL (ties broken by label). */
    private static function extremes(array $buckets): array
    {
        $best  = null;
        $worst = null;
        foreach ($buckets as $b) {
            if ($best === null || (float) $b['avg_pnl'] > (float) $best['avg_pnl']) {
                $best = $b;
            }
            if ($worst === null || (float) $b['avg_pnl'] < (float) $worst['avg_pnl']) {
                $worst = $b;
            }
        }
        return [$best, $worst];
    }

    /** Do two buckets' Wilson intervals fail to overlap? */
    private static function disjoint(array $a, array $b): bool
    {
        if ((int) $a['decided'] <= 0 || (int) $b['decided'] <= 0) {
            return false;
        }
        return (float) $a['wilson_lo'] > (float) $b['wilson_hi']
            || (float) $b['wilson_lo'] > (float) $a['wilson_hi'];
    }

    /** Outcome statistics of a set of rows (the shape every bucket carries). */
    private static function stat(array $rows): array
    {
        $wins = 0;
        $losses = 0;
        $flat = 0;
        $skipped = 0;
        $open = 0;
        $sum = 0.0;
        $pnlRows = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $outcome  = isset($row['outcome']) && $row['outcome'] !== null
                ? strtolower(trim((string) $row['outcome'])) : '';
            $decision = isset($row['decision']) ? strtolower(trim((string) $row['decision'])) : '';
            if ($outcome === 'win') {
                $wins++;
            } elseif ($outcome === 'loss') {
                $losses++;
            } elseif ($outcome === 'flat') {
                $flat++;
            } elseif ($outcome === 'not_taken' || $decision === 'skipped') {
                $skipped++;
            } else {
                $open++;
            }
            if (isset($row['pnl_usdt']) && is_numeric($row['pnl_usdt'])) {
                $pnl = (float) $row['pnl_usdt'];
                if (is_finite($pnl)) {
                    $sum += $pnl;
                    $pnlRows++;
                }
            }
        }
        $n       = count($rows);
        $decided = $wins + $losses;
        $w       = self::wilson($wins, $decided);
        return [
            'n'             => $n,
            'wins'          => $wins,
            'losses'        => $losses,
            'flat'          => $flat,
            'skipped'       => $skipped,
            'open'          => $open,
            'decided'       => $decided,
            'win_rate'      => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
            'avg_pnl'       => $pnlRows > 0 ? $sum / $pnlRows : 0.0,
            'total_pnl'     => $sum,
            'wilson_lo'     => $w['lo'],
            'wilson_hi'     => $w['hi'],
            'wilson_lo_pct' => $w['lo'] * 100.0,
            'wilson_hi_pct' => $w['hi'] * 100.0,
        ];
    }

    /** Does a feature value satisfy a component's firing condition? */
    private static function holds(float $value, array $cond): bool
    {
        $threshold = (float) $cond['value'];
        if ((string) $cond['op'] === 'lte') {
            return $value <= $threshold;
        }
        return $value >= $threshold;
    }

    /**
     * One feature value of an observation row, or null when it is absent or not
     * numeric. Accepts `features_map`, a `features` array, a `features` JSON
     * string, or the value as a plain column of the row.
     */
    private static function featureValue(array $row, string $feature): ?float
    {
        $map = null;
        if (isset($row['features_map']) && is_array($row['features_map'])) {
            $map = $row['features_map'];
        } elseif (isset($row['features'])) {
            if (is_array($row['features'])) {
                $map = $row['features'];
            } elseif (is_string($row['features'])) {
                $decoded = json_decode($row['features'], true);
                if (is_array($decoded)) {
                    $map = $decoded;
                }
            }
        }
        if (is_array($map) && array_key_exists($feature, $map)) {
            return self::toFloat($map[$feature]);
        }
        if (array_key_exists($feature, $row)) {
            return self::toFloat($row[$feature]);
        }
        return null;
    }

    /** @param mixed $v */
    private static function toFloat($v): ?float
    {
        if (is_bool($v)) {
            return $v ? 1.0 : 0.0;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : null;
    }

    /** Index of the bucket $v falls in; values outside the edges clamp to the end buckets. */
    private static function bucketIndex(float $v, array $edges): int
    {
        $last = count($edges) - 2;
        if ($v <= $edges[0]) {
            return 0;
        }
        for ($i = 0; $i <= $last; $i++) {
            if ($v < $edges[$i + 1]) {
                return $i;
            }
        }
        return $last;
    }

    /* -------------------------------------------------------- plain language */

    private static function describe(array $b): string
    {
        return 'n=' . (int) $b['n'] . ', win rate ' . self::pct((float) $b['win_rate'])
             . ' [' . self::pct((float) $b['wilson_lo_pct']) . '-' . self::pct((float) $b['wilson_hi_pct']) . ']'
             . ', avg PnL ' . self::signed((float) $b['avg_pnl']) . ' USDT';
    }

    private static function changeNote(array $chosen, int $samples): string
    {
        $c = $chosen['candidate'];
        $verb = $chosen['to'] < $chosen['from'] ? 'Reduced' : 'Raised';
        return $verb . ' the "' . $chosen['component'] . '" score component from '
             . self::signed((float) $chosen['from'], 0) . ' to ' . self::signed((float) $chosen['to'], 0)
             . ' points on ' . $samples . ' resolved trades. ' . $c['note'];
    }

    private static function noChangeNote(array $candidates, array $rows, int $min, array $current): string
    {
        if ($rows === []) {
            return 'No resolved trades in the window yet, so there is nothing to learn from.';
        }
        if ($candidates === []) {
            return 'No component separates winners from losers with ' . $min
                 . ' or more samples on both sides and non-overlapping confidence intervals, '
                 . 'on ' . count($rows) . ' resolved trades. Nothing was changed - that is the honest answer.';
        }
        return 'The strongest confident candidate ("' . (string) $candidates[0]['component']
             . '") is already at its cap of ' . self::signed((float) $current[$candidates[0]['component']], 0)
             . ' points, so nothing moved.';
    }

    private static function insightNote(
        string $feature,
        int $samples,
        array $withData,
        array $eligible,
        bool $confident,
        ?array $cBest,
        ?array $cWorst,
        ?array $best,
        ?array $worst,
        int $min,
        int $family = 1,
        bool $raw = false
    ): string {
        $name = isset(self::FEATURE_LABELS[$feature]) ? self::FEATURE_LABELS[$feature] : $feature;
        if ($samples === 0) {
            return 'No resolved observation carries ' . $name . ' yet.';
        }
        // This pair was picked as the extreme of a scan, so say how wide the scan was.
        $scan = $family > 1
            ? ' (the strongest of ' . $family . ' bucket comparisons across '
              . count(self::FEATURES) . ' conditions, and the threshold is widened for that)'
            : '';
        if ($confident && $cBest !== null && $cWorst !== null) {
            return $name . ' ' . (string) $cBest['label'] . ' (' . self::describe($cBest) . ') clearly beats '
                 . (string) $cWorst['label'] . ' (' . self::describe($cWorst)
                 . '). Both buckets clear ' . $min . ' samples and their confidence intervals do not overlap'
                 . $scan . ', so this is unlikely to be luck.';
        }
        if (count($eligible) < 2) {
            $bestLabel = $best !== null ? (string) $best['label'] : '-';
            $bestN     = $best !== null ? (int) $best['decided'] : 0;
            return 'Not enough data: ' . $samples . ' resolved trades, and fewer than two buckets reach '
                 . $min . ' decided (win or loss) trades (the best, ' . $bestLabel . ', holds ' . $bestN . '). '
                 . 'No conclusion is available yet.';
        }
        if ($cBest !== null && $cWorst !== null) {
            if ($raw) {
                // Separated on its own, but not once the scan that selected it is paid for.
                $why = ' - the intervals separate at 95 % on their own, but not once the threshold '
                     . 'is widened for the ' . $family . ' bucket comparisons made across '
                     . count(self::FEATURES) . ' conditions, so the best of that many could still be luck.';
            } else {
                $why = self::disjoint($cBest, $cWorst)
                    ? ' - the bucket with the better average PnL is not the one that wins more often, '
                    . 'so the win rate and the expectancy disagree and neither claim is safe.'
                    : ' - the confidence intervals still overlap, so the difference could be luck.';
            }
            return 'Inconclusive: ' . (string) $cBest['label'] . ' (' . self::describe($cBest) . ') vs '
                 . (string) $cWorst['label'] . ' (' . self::describe($cWorst) . ')' . $why;
        }
        return 'Inconclusive on ' . $samples . ' resolved trades.';
    }

    /**
     * The censoring disclosure appended to every note. Resolution is not random:
     * winners hit take-profit and close, losers ride - and a grid lot bought into a
     * fall is never liquidated (`grid_exit_liquidates` is false by default), so it
     * books no cycle and its observation stays open indefinitely. Saying how many
     * are excluded is the difference between a win rate and a claim.
     */
    private static function censorNote(int $openNow): string
    {
        if ($openNow <= 0) {
            return '';
        }
        return ' ' . $openNow . ' further entered observation'
             . ($openNow === 1 ? ' in these buckets is' : 's in these buckets are')
             . ' still open and excluded from every figure above: a trade that has not closed yet is '
             . 'more often a loser still riding, so these win rates read better than the buckets have earned.';
    }

    /** Percent with one decimal, e.g. "38.1%". */
    private static function pct(float $v): string
    {
        return number_format($v, 1, '.', '') . '%';
    }

    /** Signed number, e.g. "+0.0421" / "-10". */
    private static function signed(float $v, int $decimals = 4): string
    {
        $s = number_format($v, $decimals, '.', '');
        if ($s === '-0' || $s === '-0.0000') {
            $s = number_format(0.0, $decimals, '.', '');
        }
        return ($v > 0 ? '+' : '') . $s;
    }

    /** Compact number for a bucket label ("0", "0.3", "-1"). */
    private static function num(float $v): string
    {
        if (!is_finite($v)) {
            return '0';
        }
        if (abs($v) < 1.0e15 && abs($v - round($v)) < 1.0e-9) {
            return (string) (int) round($v);
        }
        $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
        return ($s === '' || $s === '-') ? '0' : $s;
    }

    /* ------------------------------------------------------------- config */

    private static function minSamples(array $cfg): int
    {
        $v = self::int($cfg, 'learn_min_samples', self::DEFAULT_MIN_SAMPLES);
        return $v < 1 ? 1 : $v;
    }

    private static function recomputeHours(array $cfg): int
    {
        $v = self::int($cfg, 'learn_recompute_hours', self::DEFAULT_RECOMPUTE_HOURS);
        return $v < 1 ? 1 : $v;
    }

    private static function windowDays(array $cfg): int
    {
        $v = self::int($cfg, 'learn_window_days', self::DEFAULT_WINDOW_DAYS);
        return $v < 1 ? 1 : $v;
    }

    private static function entryThreshold(array $cfg): int
    {
        $v = self::int($cfg, 'entry_threshold', self::DEFAULT_ENTRY_THRESHOLD);
        return (int) Util::clamp((float) $v, 0.0, 100.0);
    }

    /** The lowest effective entry threshold the learning is ever allowed to imply. */
    private static function thresholdFloor(array $cfg): int
    {
        $floor = self::entryThreshold($cfg) - self::THRESHOLD_SLACK;
        return (int) Util::clamp((float) $floor, 0.0, 100.0);
    }

    private static function int(array $cfg, string $key, int $default): int
    {
        return (isset($cfg[$key]) && is_numeric($cfg[$key])) ? (int) $cfg[$key] : $default;
    }

    private static function flag(array $cfg, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $cfg)) {
            return $default;
        }
        $v = $cfg[$key];
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
        }
        return (bool) $v;
    }
}
