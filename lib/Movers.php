<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Binance.php';
require_once __DIR__ . '/Risk.php';
require_once __DIR__ . '/Exchange.php';
require_once __DIR__ . '/Scanner.php';

/**
 * Movers board (docs/DESIGN-SCALP.md §3).
 *
 * Answers the user's actual question - "show me the top coins with big fluctuations and too many
 * traders" - and hands the scalp loop the universe it trades.
 *
 * The two signals the user named are taken literally:
 *   * **fluctuation** = the intraday range `(high - low) / last`, NOT `priceChangePercent`.
 *     A coin that rose 3 % and stayed there has nothing left to scalp; a coin that swung 3 %
 *     up and 3 % back has had dozens of round trips. `change_pct` is blind to exactly the
 *     thing this engine makes money from, so it is reported but never scored.
 *   * **too many traders** = `count`, the 24 h trade count from `/ticker/24hr`. This is why
 *     Binance::normalizeTicker24h() had to stop dropping that field. Trade count, not volume:
 *     one whale moving 50 M USDT is not "many traders", and a coin with 2 M small trades is.
 *
 * Cost: ONE `GET /api/v3/ticker/24hr` with no symbol filter, **weight 80**, plus a batched
 * `exchangeInfo` (weight 20) for candidates whose filters are not cached. That is why
 * refresh() is guarded by due() (`scalp_universe_refresh_min`, default 10 minutes) and must
 * NEVER be called from the fast loop, which polls bookTicker instead (§4).
 *
 * State key: `movers_at` - the ISO time of the last refresh ATTEMPT. A failed or empty fetch
 * stamps it too, so a flaky data host cannot turn a 10-minutely weight-80 call into a
 * once-per-poll one.
 *
 * `rank()` is PURE: ($tickers, $info, $cfg, $keep) in, rows out. No clock, no database, no
 * network, so the whole ranking is pinned down by a fixture in tests/run.php.
 */
final class Movers
{
    /** Symbols per exchangeInfo request when topping up missing filters. */
    const INFO_BATCH = 100;

    /** Score weights (§3). They sum to 1.0 so a score is directly comparable between refreshes. */
    const W_MOVE  = 0.45;
    const W_COUNT = 0.35;
    const W_LIQ   = 0.20;

    /** The spread penalty can cost at most this much of the score. */
    const SPREAD_PENALTY_MAX = 0.3;

    /**
     * Dust rule (§3): one quantity step must cost at most this percent of ONE SCALP ORDER
     * (`scalp_order_usdt`), or every BUY leaves behind a crumb that is large next to the money
     * the trade put at risk.
     *
     * The reference is one order, exactly as §3 writes it, and NOT the symbol's §7 budget -
     * even though Scanner::dustLimit() measures against a sleeve's whole budget and even though
     * using the budget here would let several of the majors onto the board. At the default
     * 6 USDT order the cap is 1.8 cents, which gates DOGEUSDT (a 1-DOGE step costs ~20 cents,
     * 3.3 % of the order) and SOLUSDT and ETHUSDT with it. That is not a bug to tune away: on a
     * 0.25 % take-profit a 3.3 % crumb per entry is the difference between the strategy and
     * noise, and the gate's whole job is to say so on the board - the row is KEPT, flagged
     * `dust_step`, with its numbers, so the panel can explain the rejection instead of the
     * engine quietly trading something it cannot exit cleanly. Coins with fine step sizes (the
     * large majority of the USDT pairs) pass it easily; raising `scalp_order_usdt` raises the
     * cap in proportion, which is the honest lever and belongs to the user, not to this file.
     */
    const DUST_PCT = 0.3;

    /**
     * Default `scalp_exclude` (§6): the stablecoin pairs. They have gigantic trade counts and
     * gigantic volume and go nowhere, so without this they would own the top of a board built
     * to find movement. Same list Scanner defaults to, for the same reason.
     */
    const DEFAULT_EXCLUDE = ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT'];

    /** @var array */
    private $cfg;
    /** @var Db */
    private $db;
    /** @var MarketDataInterface */
    private $md;

    public function __construct(array $cfg, Db $db, MarketDataInterface $md)
    {
        $this->cfg = $cfg;
        $this->db  = $db;
        $this->md  = $md;
    }

    /**
     * True when `scalp_universe_refresh_min` has elapsed since `movers_at`, or it never ran.
     * Unlike Scanner::due() this is NOT gated on an enabled flag: the board is useful to look at
     * even with the engine switched off, and the caller decides whether to refresh it.
     */
    public function due(): bool
    {
        $at = trim((string) $this->db->getState('movers_at', ''));
        if ($at === '' || Util::isoToTs($at) === null) {
            return true;
        }
        $mins = (int) round(self::cfgFloat($this->cfg, 'scalp_universe_refresh_min', 10.0));
        if ($mins < 1) {
            $mins = 1;
        }
        return Util::isoDiffMinutes($at, Util::nowIso()) >= (float) $mins;
    }

    /**
     * Rank the exchange and store the board. Returns the stored rows, best first.
     *
     * @param array $info  symbol info the caller already has (Binance::exchangeInfo shape)
     * @param bool  $force skip the due() guard (the panel's manual refresh, and tests)
     * @param array $keep  symbols that must stay in the universe whatever the ranking says -
     *                     normally the symbols holding an open scalp position (§3, last bullet)
     * @return array rows shaped like rank() output with `ts` filled in
     */
    public function refresh(array $info, bool $force = false, array $keep = []): array
    {
        if (!$force && !$this->due()) {
            return [];
        }

        $tickers = $this->fetchTickers();
        if ($tickers === []) {
            // The attempt was spent either way: stamp the clock, or due() stays true and every
            // single tick repeats the weight-80 call for as long as the data host is unhappy.
            $this->db->setState('movers_at', Util::nowIso());
            return [];
        }

        // Pass 1 with whatever filters we already hold, purely to pick who is worth an
        // exchangeInfo lookup. Symbols with no filters carry the `no_info` gate here.
        $first   = self::rank($tickers, $info, $this->cfg, $keep);
        $info    = $this->topUpInfo($info, self::infoCandidates($first, $this->universeSize() * 3));

        // Pass 2: the same pure function, now with the filters that decide `dust_step` and
        // `required_size`. This is the board that gets stored.
        $rows = self::rank($tickers, $info, $this->cfg, $keep);

        $ts       = Util::nowIso();
        $eligible = 0;
        foreach ($rows as $i => $r) {
            $rows[$i]['ts'] = $ts;
            if (!empty($r['eligible'])) {
                $eligible++;
            }
        }

        try {
            $this->db->replaceMovers($rows);
        } catch (Throwable $e) {
            Log::warn('movers: board not stored - ' . $e->getMessage());
            $this->db->setState('movers_at', $ts);
            return $rows;
        }
        $this->db->setState('movers_at', $ts);

        Log::info('movers: ranked ' . count($rows) . ' pairs', [
            'eligible' => $eligible,
            'universe' => self::universeOf($rows),
            'top'      => isset($rows[0]['symbol']) ? (string) $rows[0]['symbol'] : '',
        ]);

        return $rows;
    }

    /** `scalp_universe_size`, clamped to 1..30 (§6). */
    public function universeSize(): int
    {
        return self::universeSizeOf($this->cfg);
    }

    public static function universeSizeOf(array $cfg): int
    {
        $n = (int) round(self::cfgFloat($cfg, 'scalp_universe_size', 20.0));
        return (int) Util::clamp((float) $n, 1.0, 30.0);
    }

    // ------------------------------------------------------------- pure ranking

    /**
     * Rank tickers into the movers board. **Pure** - no clock, no database, no network.
     *
     * Dropped outright, because they are not candidates at all: symbols that do not end in
     * `quote_asset`, symbols in `scalp_exclude`, leveraged tokens (UP/DOWN/BULL/BEAR as a
     * SUFFIX of the base asset only - `JUP` and `SYRUP` are real coins, see
     * Scanner::isLeveraged()), and symbols whose known filters are not TRADING + spot-allowed.
     *
     * Everything else is KEPT whether it passes or not, with its numbers and its reasons in
     * `gates` (`no_price`, `no_info`, `illiquid`, `thin_trades`, `spread_unknown`,
     * `spread_wide`, `dust_step`, `too_small`), so the panel can explain a rejection instead of
     * silently hiding the coin.
     * A gated row is NOT eligible and never enters the universe on its own merit - but a symbol
     * named in $keep stays in it regardless, because it is holding a position (see below).
     *
     * Score (§3), each term ranked 0..1 ACROSS THE SURVIVING SET rather than scaled by an
     * absolute unit, so no term can dominate just because its unit is bigger:
     *
     *     score = 0.45*moveRank + 0.35*tradeCountRank + 0.20*liquidityRank - spreadPenalty
     *     spreadPenalty = min(0.3, spread_pct / scalp_max_spread_pct * 0.3)
     *
     * The ranks are computed over the ELIGIBLE rows only. Including gated rows would let a
     * thin, unfillable coin with a huge range push the real movers down the percentile scale.
     *
     * @param array $tickers rows from Binance::ticker24h() - a list or a symbol-keyed map
     * @param array $info    parsed symbol info keyed by symbol
     * @param array $keep    symbols pinned into the universe (open positions)
     * @return array rows ['symbol','last','change_pct','range_pct','trade_count','quote_vol',
     *                     'spread_pct','step_value','required_size','score','rank',
     *                     'in_universe','eligible','gates']
     */
    public static function rank(array $tickers, array $info, array $cfg, array $keep = []): array
    {
        $quote     = strtoupper(trim((string) self::cfgStr($cfg, 'quote_asset', 'USDT')));
        if ($quote === '') {
            $quote = 'USDT';
        }
        $qLen      = strlen($quote);
        $minVol    = self::cfgFloat($cfg, 'scalp_min_quote_vol', 20000000.0);
        $maxSpread = self::cfgFloat($cfg, 'scalp_max_spread_pct', 0.05);
        $feePct    = self::cfgFloat($cfg, 'fee_pct', 0.1);
        $orderUsdt = self::cfgFloat($cfg, 'scalp_order_usdt', 6.0);
        $dustCap   = $orderUsdt > 0.0 ? $orderUsdt * (self::DUST_PCT / 100.0) : 0.0;
        $exclude   = self::excludeMap($cfg);

        $pinned = [];
        foreach ($keep as $k) {
            $k = strtoupper(trim((string) $k));
            if ($k !== '') {
                $pinned[$k] = true;
            }
        }

        $rows = [];
        foreach ($tickers as $key => $t) {
            if (!is_array($t)) {
                continue;
            }
            $symbol = isset($t['symbol']) ? strtoupper(trim((string) $t['symbol'])) : '';
            if ($symbol === '' && is_string($key)) {
                $symbol = strtoupper(trim($key));
            }
            if ($symbol === '' || isset($rows[$symbol])) {
                continue;
            }

            // ---- not candidates at all
            if (strlen($symbol) <= $qLen || substr($symbol, -$qLen) !== $quote) {
                continue;
            }
            if (isset($exclude[$symbol])) {
                continue;
            }
            $si = isset($info[$symbol]) && is_array($info[$symbol]) ? $info[$symbol] : null;
            if ($si !== null && isset($si['base']) && trim((string) $si['base']) !== '') {
                $base = strtoupper(trim((string) $si['base']));
            } else {
                $base = substr($symbol, 0, strlen($symbol) - $qLen);
            }
            // Reuse Scanner's test rather than a second copy of the UP/DOWN/BULL/BEAR rules and
            // its real-coin allow list: two copies would drift and one of them would be wrong.
            if (Scanner::isLeveraged($base)) {
                continue;
            }
            if ($si !== null) {
                if (strtoupper((string) ($si['status'] ?? '')) !== 'TRADING') {
                    continue;
                }
                if (!(bool) ($si['spotAllowed'] ?? false)) {
                    continue;
                }
                $siQuote = strtoupper(trim((string) ($si['quote'] ?? '')));
                if ($siQuote !== '' && $siQuote !== $quote) {
                    continue;
                }
            }

            // ---- metrics
            $gates = [];
            $last  = self::num($t, 'last');
            $bid   = self::num($t, 'bid');
            $ask   = self::num($t, 'ask');
            $high  = self::num($t, 'high');
            $low   = self::num($t, 'low');
            $mid   = ($bid > 0.0 && $ask > 0.0) ? ($bid + $ask) / 2.0 : 0.0;
            $price = $last > 0.0 ? $last : $mid;
            if ($price <= 0.0) {
                $gates[] = 'no_price';
            }

            // Fluctuation: the intraday RANGE, not the net change. See the class docblock.
            $rangePct = 0.0;
            if ($price > 0.0 && $high > 0.0 && $low > 0.0 && $high >= $low) {
                $rangePct = ($high - $low) / $price * 100.0;
            }
            if (!is_finite($rangePct) || $rangePct < 0.0) {
                $rangePct = 0.0;
            }

            $spreadKnown = ($bid > 0.0 && $ask > 0.0 && $ask >= $bid && $mid > 0.0);
            $spread      = $spreadKnown ? ($ask - $bid) / $mid * 100.0 : 0.0;

            $quoteVol  = self::num($t, 'quote_vol');
            $changePct = self::num($t, 'change_pct');
            $count     = (int) max(0.0, self::num($t, 'count'));

            $stepValue = 0.0;
            $required  = 0.0;
            if ($si === null) {
                $gates[] = 'no_info';
            } else {
                $step      = isset($si['stepSize']) && is_numeric($si['stepSize']) ? (float) $si['stepSize'] : 0.0;
                $stepValue = ($step > 0.0 && $price > 0.0) ? $step * $price : 0.0;
                $required  = Risk::requiredSize($si, $price, $feePct);
            }

            // ---- gates: recorded, never a reason to drop the row
            if ($quoteVol < $minVol) {
                $gates[] = 'illiquid';
            }
            // The trade-count floor is NOT applied here: it needs to see the whole board first
            // (see the `thin_trades` block after this loop).
            if (!$spreadKnown) {
                $gates[] = 'spread_unknown';
            } elseif ($maxSpread > 0.0 && $spread > $maxSpread) {
                $gates[] = 'spread_wide';
            }
            if ($si !== null && $dustCap > 0.0 && $stepValue > $dustCap) {
                $gates[] = 'dust_step';
            }
            if ($si !== null && $required > 0.0 && $orderUsdt > 0.0 && $orderUsdt < $required) {
                // The configured order is below what this symbol's minNotional + one step costs,
                // so an entry here could only ever be rejected, or fill into an unsellable dust
                // position. Better to say so on the board than to retry it every poll.
                $gates[] = 'too_small';
            }

            $rows[$symbol] = [
                'symbol'        => $symbol,
                'last'          => round($price, 10),
                'change_pct'    => round($changePct, 4),
                'range_pct'     => round($rangePct, 6),
                'trade_count'   => $count,
                'quote_vol'     => round($quoteVol, 2),
                'spread_pct'    => round($spread, 6),
                'step_value'    => round($stepValue, 10),
                'required_size' => round($required, 8),
                'score'         => 0.0,
                'rank'          => 0,
                'in_universe'   => 0,
                'eligible'      => $gates === [] ? 1 : 0,
                'gates'         => $gates,
                // not stored; rank()'s own bookkeeping, dropped before it returns
                '_spread_known' => $spreadKnown,
            ];
        }

        // ---- trade-count floor (`scalp_min_trade_count`, §6 via Risk::validateConfig).
        //
        // Applied HERE and not inside the loop above, because whether it may be applied at all
        // depends on the whole board: a feed that does not carry `count` would otherwise gate
        // EVERY symbol on the exchange and leave the universe empty, turning a missing field
        // into a total trading halt. So the floor is only enforced once the board is seen to
        // carry trade counts at all - fail open on absent data, gate on present data.
        //
        // This is the user's "too many traders" read as a hard floor as well as a score term.
        // Set the key to 0 to turn it off and let the 35 % score term do the work alone.
        $countFloor  = self::cfgFloat($cfg, 'scalp_min_trade_count', 0.0);
        $countsKnown = false;
        foreach ($rows as $r) {
            if ((int) $r['trade_count'] > 0) {
                $countsKnown = true;
                break;
            }
        }
        if ($countFloor > 0.0 && $countsKnown) {
            foreach ($rows as $sym => $r) {
                if ((float) $r['trade_count'] < $countFloor) {
                    $rows[$sym]['gates'][]  = 'thin_trades';
                    $rows[$sym]['eligible'] = 0;
                }
            }
        }

        // ---- percentile ranks over the eligible rows only
        $eligible = [];
        foreach ($rows as $sym => $r) {
            if ((int) $r['eligible'] === 1) {
                $eligible[] = $sym;
            }
        }
        $moveRank  = self::percentiles($rows, $eligible, 'range_pct');
        $countRank = self::percentiles($rows, $eligible, 'trade_count');
        $liqRank   = self::percentiles($rows, $eligible, 'quote_vol');

        foreach ($rows as $sym => $r) {
            if ((int) $r['eligible'] !== 1) {
                // A gated row is not tradeable, so it scores 0 and `gates` says why. Without
                // this, a coin nobody can fill would still sort above one that can for every
                // consumer that orders by score.
                $rows[$sym]['score'] = 0.0;
                unset($rows[$sym]['_spread_known']);
                continue;
            }
            $penalty = 0.0;
            if ($maxSpread > 0.0) {
                $penalty = min(self::SPREAD_PENALTY_MAX, max(0.0, (float) $r['spread_pct']) / $maxSpread * self::SPREAD_PENALTY_MAX);
            }
            $score = self::W_MOVE * ($moveRank[$sym] ?? 0.0)
                   + self::W_COUNT * ($countRank[$sym] ?? 0.0)
                   + self::W_LIQ * ($liqRank[$sym] ?? 0.0)
                   - $penalty;
            // NOT floored at 0. The §3 formula subtracts the penalty, and flooring would throw
            // the penalty away for every coin whose three terms are small - two otherwise
            // identical coins, one with a book four times wider, would come out exactly equal.
            // The range is [-0.3, 1.0], and an eligible row still outranks every gated one
            // because compareRows() (and the ORDER BY behind moversTop) sorts eligible FIRST,
            // not by score alone. Only a non-finite score is replaced.
            if (!is_finite($score)) {
                $score = 0.0;
            }
            $rows[$sym]['score'] = round($score, 6);
            unset($rows[$sym]['_spread_known']);
        }

        $out = array_values($rows);
        usort($out, ['Movers', 'compareRows']);

        // ---- universe: the top N eligible rows, plus every pinned symbol that is on the board.
        //      A pinned symbol is in the universe even when it is gated or ranks nowhere: it
        //      holds an open position, and the loop has to keep managing that position to its
        //      exit. Dropping it from the universe would strand a live position (§3).
        $size   = self::universeSizeOf($cfg);
        $chosen = 0;
        foreach ($out as $i => $r) {
            $sym = (string) $r['symbol'];
            $in  = false;
            if ((int) $r['eligible'] === 1 && $chosen < $size) {
                // Quota FIRST: a pinned row that is eligible and inside the top N pays for its
                // slot like any other, so the universe never exceeds `scalp_universe_size`.
                $in = true;
                $chosen++;
            } elseif (isset($pinned[$sym])) {
                // Fallback only: gated, or ranked outside the quota, but holding a position -
                // never strand a live position (§3).
                $in = true;
            }
            $out[$i]['in_universe'] = $in ? 1 : 0;
            $out[$i]['rank']        = $i + 1;
        }

        return $out;
    }

    /**
     * Each symbol's position in [0,1] for one numeric field, over $keys only.
     *
     * Rank-based, not min/max-scaled: a single outlier (one coin with 40x everyone's trade
     * count) would otherwise squash every other coin's term to near zero and make that one
     * term decide the whole board. Ties share the same value, and a set of one scores 1.0.
     *
     * @param array $keys symbols to include
     * @return array [symbol => float in 0..1]
     */
    public static function percentiles(array $rows, array $keys, string $field): array
    {
        $vals = [];
        foreach ($keys as $sym) {
            if (!isset($rows[$sym])) {
                continue;
            }
            $v = isset($rows[$sym][$field]) ? (float) $rows[$sym][$field] : 0.0;
            $vals[$sym] = is_finite($v) ? $v : 0.0;
        }
        $n = count($vals);
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            $only = array_keys($vals);
            return [(string) $only[0] => 1.0];
        }
        $sorted = array_values($vals);
        sort($sorted, SORT_NUMERIC);
        if ($sorted[0] === $sorted[$n - 1]) {
            // Every survivor is equal on this term. "Fraction strictly below" would hand all of
            // them 0.0, which does not mean "all bad" - it means the term cannot separate them -
            // and a 0 would silently delete 45 % or 35 % of every score on the board, leaving
            // only the spread penalty to decide the ranking. Full credit for everyone keeps the
            // term neutral instead, and matches the single-survivor case just below.
            $out = [];
            foreach ($vals as $sym => $v) {
                $out[$sym] = 1.0;
            }
            return $out;
        }
        // How many values are strictly below this one, over n-1: deterministic, tie-safe, and
        // independent of the units. sprintf (not a plain cast) so the lookup key is exact to
        // 17 significant digits and two genuinely different values can never share a bucket.
        $below = [];
        foreach ($sorted as $i => $v) {
            $k = sprintf('%.17G', $v);
            if (!isset($below[$k])) {
                $below[$k] = $i;
            }
        }
        $out = [];
        foreach ($vals as $sym => $v) {
            $out[$sym] = Util::clamp(((float) $below[sprintf('%.17G', $v)]) / (float) ($n - 1), 0.0, 1.0);
        }
        return $out;
    }

    /**
     * Total order, so a fixture pins the board byte for byte: eligible first, then score, then
     * range, then trade count, then quote volume, then symbol.
     */
    public static function compareRows(array $a, array $b): int
    {
        $ea = (int) ($a['eligible'] ?? 0);
        $eb = (int) ($b['eligible'] ?? 0);
        if ($ea !== $eb) {
            return $eb <=> $ea;
        }
        foreach (['score', 'range_pct', 'trade_count', 'quote_vol'] as $f) {
            $va = (float) ($a[$f] ?? 0);
            $vb = (float) ($b[$f] ?? 0);
            if ($va !== $vb) {
                return $vb <=> $va;
            }
        }
        return strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
    }

    /** @return array list of uppercase symbols with in_universe = 1, in board order */
    public static function universeOf(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (is_array($r) && !empty($r['in_universe']) && isset($r['symbol'])) {
                $out[] = strtoupper(trim((string) $r['symbol']));
            }
        }
        return $out;
    }

    /**
     * Symbols worth an exchangeInfo lookup: those whose ONLY complaint is `no_info`, best
     * first. Spending the batched call on coins already rejected for being illiquid or wide
     * would buy nothing, and neither would spending it on a row that is already passing -
     * that row's filters are cached, which is exactly why it has no gate.
     *
     * @return array list of symbols, at most $limit
     */
    public static function infoCandidates(array $rows, int $limit): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['symbol'])) {
                continue;
            }
            $gates = isset($r['gates']) && is_array($r['gates']) ? $r['gates'] : [];
            // The row must actually CARRY the gate. A row with `gates === []` has its filters
            // cached already and needs nothing - counting it would spend the $limit on symbols
            // topUpInfo() then skips, and since compareRows() sorts every eligible row ahead of
            // every gated one, a board of cached symbols leaves no slot for a brand-new mover.
            if (array_values($gates) === ['no_info']) {
                $out[] = (string) $r['symbol'];
            }
            if (count($out) >= max(1, $limit)) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ I/O

    /**
     * The one weight-80 call.
     *
     * A rate-limit failure (429 / 418, i.e. `retryAfter` is set) is RE-THROWN, exactly as
     * Scanner::fetchTickers() does: that is the one failure the tick's error policy has to see,
     * because its answer is `api_paused_until` and swallowing it would leave the caller free to
     * keep hitting an exchange that has just asked us to stop. `movers_at` is stamped before
     * the throw so the board's own guard still counts the attempt.
     *
     * Every other failure is logged and answered with [] - a movers outage must never take the
     * trading tick down with it.
     *
     * @return array ticker rows, or [] when the call failed
     */
    private function fetchTickers(): array
    {
        try {
            $rows = $this->md->ticker24h([]);
        } catch (BinanceException $e) {
            if ($e->retryAfter !== null) {
                $this->db->setState('movers_at', Util::nowIso());
                throw $e;
            }
            Log::warn('movers: ticker24h failed', ['code' => $e->binanceCode, 'msg' => $e->getMessage()]);
            return [];
        } catch (Throwable $e) {
            Log::warn('movers: ticker24h failed', ['msg' => $e->getMessage()]);
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * One batched exchangeInfo for the candidates we have no filters for. The caller's cache is
     * never modified - the merged map is returned.
     */
    private function topUpInfo(array $info, array $symbols): array
    {
        $missing = [];
        foreach ($symbols as $s) {
            $s = strtoupper(trim((string) $s));
            if ($s !== '' && !isset($info[$s])) {
                $missing[$s] = true;
            }
        }
        if ($missing === []) {
            return $info;
        }
        $batch = array_slice(array_keys($missing), 0, self::INFO_BATCH);
        try {
            $fresh = $this->md->symbolInfo($batch);
            if (is_array($fresh)) {
                foreach ($fresh as $sym => $row) {
                    if (is_array($row)) {
                        $info[strtoupper(trim((string) $sym))] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warn('movers: symbolInfo top-up failed - ' . $e->getMessage());
        }
        return $info;
    }

    // -------------------------------------------------------------- config

    /**
     * `scalp_exclude` as an uppercase lookup map (a list, or a comma/space separated string).
     *
     * When the key is ABSENT the default is the stablecoin pairs, exactly as §6 says and as
     * Scanner::excludeMap() does. Returning [] for an absent key instead would put USDCUSDT and
     * FDUSDUSDT - enormous trade counts, enormous volume, a range of nothing - straight onto a
     * board whose whole purpose is finding things that move. An explicitly EMPTY list is still
     * honoured as "exclude nothing", which is what a test that wants the raw ranking needs.
     *
     * @return array [SYMBOL => true]
     */
    public static function excludeMap(array $cfg): array
    {
        $raw = $cfg['scalp_exclude'] ?? null;
        $list = [];
        if ($raw === null) {
            $list = self::DEFAULT_EXCLUDE;
        } elseif (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw)) {
            $parts = preg_split('/[\s,]+/', $raw);
            $list  = $parts === false ? [] : $parts;
        }
        $out = [];
        foreach ($list as $v) {
            $v = strtoupper(trim((string) $v));
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        return $out;
    }

    /** @param mixed $row */
    private static function num(array $row, string $key): float
    {
        if (!isset($row[$key]) || !is_numeric($row[$key])) {
            return 0.0;
        }
        $v = (float) $row[$key];
        return is_finite($v) ? $v : 0.0;
    }

    private static function cfgFloat(array $cfg, string $key, float $default): float
    {
        if (!isset($cfg[$key]) || !is_numeric($cfg[$key])) {
            return $default;
        }
        $v = (float) $cfg[$key];
        return is_finite($v) ? $v : $default;
    }

    private static function cfgStr(array $cfg, string $key, string $default): string
    {
        if (!isset($cfg[$key]) || (!is_string($cfg[$key]) && !is_numeric($cfg[$key]))) {
            return $default;
        }
        $v = trim((string) $cfg[$key]);
        return $v === '' ? $default : $v;
    }
}
