<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Log.php';
require_once __DIR__ . '/Binance.php';
require_once __DIR__ . '/Indicators.php';
require_once __DIR__ . '/Risk.php';
require_once __DIR__ . '/Exchange.php';

/**
 * Volatility scanner (docs/DESIGN-PORTFOLIO.md §5).
 *
 * Ranks the exchange's quote-asset pairs by *tradeable* volatility: ATR discounted by how
 * liquid the pair is and how wide its book is. Volatility alone must never win — an illiquid
 * or wide-spread coin is penalised toward zero, because a 6 % daily range you cannot enter
 * and exit at a sane price is not an opportunity.
 *
 * The scanner **never reassigns a sleeve's symbols on its own**. It ranks and explains; the
 * operator applies a suggestion from the panel. Silent symbol changes under a live grid would
 * strand inventory.
 *
 * Cost: one `GET /api/v3/ticker/24hr` with no symbol filter (**weight 80**), one batched
 * `exchangeInfo` for the candidates it does not already know (weight 20), and one 15m klines
 * call (weight 2) for each of the ATR_CANDIDATES survivors — roughly 150 weight per refresh.
 * That is why `refresh()` is guarded by `due()` (`scanner_refresh_min`, default 60 minutes)
 * and must never be called per tick.
 *
 * State keys: `scanner_at` (ISO of the last refresh ATTEMPT - a failed fetch stamps it too, so
 * a flaky data host cannot turn the hourly weight-80 call into a per-tick one).
 *
 * `rank()` is a pure static function of ($tickers, $info, $cfg): every filter, every gate and
 * the whole scoring formula live there, so the ranking can be tested on a fixture with no I/O,
 * no database and no clock.
 */
final class Scanner
{
    /** How many survivors get a real ATR (each costs a klines call, weight 2). */
    const ATR_CANDIDATES = 25;
    /** ATR is measured on this interval, per DESIGN-PORTFOLIO.md §2 (`scanner_min_atr_pct`). */
    const ATR_INTERVAL = '15m';
    /** Klines requested per candidate: plenty of warm-up for a Wilder ATR14, still weight 2. */
    const ATR_LIMIT = 100;
    const ATR_PERIOD = 14;

    /** Dust rule: the quantity step may cost at most this percent of the sleeve reference size. */
    const DUST_PCT = 0.3;

    /** Symbols per exchangeInfo request when topping up missing symbol info. */
    const INFO_BATCH = 100;

    /** Leveraged-token suffixes, matched ONLY at the end of the base asset. */
    const LEVERAGED_SUFFIXES = ['UP', 'DOWN', 'BULL', 'BEAR'];

    /**
     * Shortest underlying a leveraged token can have. Every Binance leveraged token was
     * `<UNDERLYING><SUFFIX>` with a real ticker in front (BTCUP, ADADOWN, XRPBULL, 1INCHUP),
     * so requiring at least three characters of prefix keeps legitimate coins whose own name
     * merely ends in those letters — JUP, SUP, CUP, BEAR itself — out of the rejection list.
     */
    const LEVERAGED_MIN_BASE = 3;

    /**
     * Real coins whose ticker merely ENDS in a leveraged-token suffix but has a long enough
     * prefix to slip past LEVERAGED_MIN_BASE. SYRUP (Maple Finance) is `SYR` + `UP` = exactly
     * three characters of prefix, so the length rule alone cannot save it. Binance delisted its
     * UP/DOWN tokens in 2021 and never listed BULL/BEAR, so this list only ever grows.
     */
    const LEVERAGED_ALLOW = ['SYRUP'];

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

    /** `scanner_enabled` (default true when the key is absent from an older config.json). */
    public function enabled(): bool
    {
        return self::cfgBool($this->cfg, 'scanner_enabled', true);
    }

    /**
     * True when `scanner_refresh_min` has elapsed since `scanner_at` (or it never ran).
     * Always false while the scanner is disabled, so `if ($s->due()) $s->refresh($info);`
     * is all the integration Bot::tick() needs.
     */
    public function due(): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $at = trim((string) $this->db->getState('scanner_at', ''));
        if ($at === '' || Util::isoToTs($at) === null) {
            return true;
        }
        $mins = (int) round(self::cfgFloat($this->cfg, 'scanner_refresh_min', 60.0));
        if ($mins < 1) {
            $mins = 1;
        }
        return Util::isoDiffMinutes($at, Util::nowIso()) >= (float) $mins;
    }

    /**
     * Rank every quote-asset pair and store the result. Returns the stored rows (best first).
     *
     * Guarded by `due()` — a call that is not due does nothing and returns [] — and by
     * `scanner_enabled`. `$force` (optional, additive) is for the panel's manual rescan and
     * for tests; it skips the `due()` guard only, never the enabled flag.
     *
     * `$info` is the symbol info the caller already has (typically the union of the sleeve
     * symbols). Anything missing for a candidate is fetched here in one batched request; the
     * caller's cache is not modified.
     *
     * `$deadlineMs` (optional, additive) is a `microtime(true) * 1000` instant; the ATR pass
     * stops there and the partial ranking is stored, so a slow exchange cannot eat the sleeves'
     * tick. Null (the default) keeps the unbounded behaviour.
     *
     * @param array $info parsed symbol info keyed by symbol (Binance::exchangeInfo shape)
     * @return array rows shaped like the `scanner` table (see rank()), `ts` filled in
     */
    public function refresh(array $info, bool $force = false, ?float $deadlineMs = null): array
    {
        if (!$this->enabled()) {
            return [];
        }
        if (!$force && !$this->due()) {
            return [];
        }

        $tickers = $this->fetchTickers();
        if ($tickers === []) {
            // A failed (or empty) fetch still consumed the attempt: stamp `scanner_at` anyway,
            // or `due()` stays true and every single tick repeats the weight-80 call while the
            // data host is flaky - exactly the per-tick storm the hourly guard exists to avoid.
            $this->db->setState('scanner_at', Util::nowIso());
            return [];
        }

        // Pass 1: filters, cheap gates and the liquidity/spread factors. No ATR yet, so every
        // survivor carries the `atr_unknown` gate and scores 0.
        $rows = self::rank($tickers, $info, $this->cfg);

        // The ATR pass is the expensive one, so it is spent on the survivors only, ordered by
        // the cheap proxy |change_pct| (DESIGN-PORTFOLIO.md §5.5).
        $candidates = self::atrCandidates($rows, self::ATR_CANDIDATES);

        $info = $this->topUpInfo($info, $candidates);

        $bySymbol = [];
        foreach ($tickers as $key => $t) {
            if (!is_array($t)) {
                continue;
            }
            $sym = isset($t['symbol']) ? strtoupper(trim((string) $t['symbol'])) : '';
            if ($sym === '' && is_string($key)) {
                $sym = strtoupper(trim($key));
            }
            if ($sym !== '') {
                $t['symbol']     = $sym;
                $bySymbol[$sym]  = $t;
            }
        }
        $atrOk = 0;
        foreach ($candidates as $symbol) {
            // One klines call each, sequential, up to 15 s apiece: on a slow exchange the pass
            // alone can outlast the whole tick and starve every sleeve. Past the deadline we
            // keep what we have - the symbols not reached simply stay gated `atr_unknown`.
            if ($deadlineMs !== null && microtime(true) * 1000.0 >= $deadlineMs) {
                Log::warn('scanner: ATR pass stopped at the time budget', [
                    'done' => $atrOk,
                    'of'   => count($candidates),
                ]);
                break;
            }
            if (!isset($bySymbol[$symbol])) {
                continue;
            }
            $atr = $this->atrPct($symbol);
            if ($atr !== null) {
                $bySymbol[$symbol]['atr_pct'] = $atr;
                $atrOk++;
            }
        }

        // Pass 2: same pure function, now with real ATRs and the topped-up symbol info.
        $rows = self::rank(array_values($bySymbol), $info, $this->cfg);

        $ts       = Util::nowIso();
        $eligible = 0;
        foreach ($rows as $i => $r) {
            $rows[$i]['ts'] = $ts;
            if (!empty($r['eligible'])) {
                $eligible++;
            }
        }

        $this->db->replaceScanner($rows);
        $this->db->setState('scanner_at', $ts);

        Log::info('scanner: ranked ' . count($rows) . ' pairs', [
            'eligible'   => $eligible,
            'atr_probed' => $atrOk,
            'top'        => isset($rows[0]['symbol']) ? (string) $rows[0]['symbol'] : '',
        ]);

        return $rows;
    }

    // ---------------------------------------------------------------- pure ranking

    /**
     * Rank tickers. **Pure**: no clock, no database, no network — ($tickers, $info, $cfg) in,
     * rows out, so a fixture can pin the whole ranking down.
     *
     * Dropped outright (they are not tradeable candidates at all, DESIGN-PORTFOLIO.md §5.2):
     * symbols that do not end in `quote_asset`, symbols listed in `scanner_exclude`, leveraged
     * tokens, and symbols whose known `exchangeInfo` is not `TRADING` + `isSpotTradingAllowed`.
     *
     * Everything else is KEPT, whether it passes or not. A row that fails a gate keeps its
     * numbers and records the reason in `gates` (`illiquid`, `spread_wide`, `spread_unknown`,
     * `no_price`, `no_info`, `dust_step`, `atr_unknown`, `atr_low`, `atr_high`) so the panel
     * can explain the rejection instead of silently hiding the symbol.
     *
     * Score (§5.6): `atr_pct × liquidity_factor × spread_factor`, with
     *   `liquidity_factor = clamp(log10(quote_vol / scanner_min_quote_vol) / 2 + 0.5, 0, 1)`
     *   `spread_factor    = max(0, 1 − spread_pct / scanner_max_spread_pct)`
     * A pair at exactly the volume floor keeps half its ATR, a pair ten times below it scores
     * zero, and a book as wide as the limit scores zero: volatility alone never wins. A row
     * that failed any gate scores 0 outright — it is not tradeable, so it must not outrank a
     * pair that is, whatever consumer sorts the table.
     *
     * @param array $tickers rows from Binance::ticker24h() — a list, or a symbol-keyed map.
     *                       An extra numeric `atr_pct` on a row is used as that pair's ATR;
     *                       without it the row is gated `atr_unknown` and scores 0.
     * @param array $info    parsed symbol info keyed by symbol
     * @return array rows ['symbol','price','change_pct','quote_vol','spread_pct','atr_pct',
     *                     'step_value','min_notional','required_size','score','eligible','gates']
     *               ordered eligible-first, then by score, quote volume and symbol
     */
    public static function rank(array $tickers, array $info, array $cfg): array
    {
        $quote     = strtoupper(trim((string) self::cfgStr($cfg, 'quote_asset', 'USDT')));
        $minVol    = self::cfgFloat($cfg, 'scanner_min_quote_vol', 5000000.0);
        $maxSpread = self::cfgFloat($cfg, 'scanner_max_spread_pct', 0.06);
        $minAtr    = self::cfgFloat($cfg, 'scanner_min_atr_pct', 0.5);
        $maxAtr    = self::cfgFloat($cfg, 'scanner_max_atr_pct', 4.0);
        $feePct    = self::cfgFloat($cfg, 'fee_pct', 0.1);
        $dustCap   = self::dustLimit($cfg);
        $exclude   = self::excludeMap($cfg);

        if ($quote === '') {
            $quote = 'USDT';
        }
        $qLen = strlen($quote);

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

            // ---- §5.2 filters: not candidates at all
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
            if (self::isLeveraged($base)) {
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

            // ---- §5.3 metrics
            $gates = [];
            $last  = self::num($t, 'last');
            $bid   = self::num($t, 'bid');
            $ask   = self::num($t, 'ask');
            $mid   = ($bid > 0.0 && $ask > 0.0) ? ($bid + $ask) / 2.0 : 0.0;
            $price = $last > 0.0 ? $last : $mid;
            if ($price <= 0.0) {
                $gates[] = 'no_price';
            }

            $spreadKnown = ($bid > 0.0 && $ask > 0.0 && $ask >= $bid && $mid > 0.0);
            $spread      = $spreadKnown ? ($ask - $bid) / $mid * 100.0 : 0.0;

            $quoteVol  = self::num($t, 'quote_vol');
            $changePct = self::num($t, 'change_pct');

            $stepValue = 0.0;
            $minNot    = 0.0;
            $required  = 0.0;
            if ($si === null) {
                $gates[] = 'no_info';
            } else {
                $step      = isset($si['stepSize']) && is_numeric($si['stepSize']) ? (float) $si['stepSize'] : 0.0;
                $stepValue = $step > 0.0 && $price > 0.0 ? $step * $price : 0.0;
                $minNot    = isset($si['minNotional']) && is_numeric($si['minNotional']) ? (float) $si['minNotional'] : 0.0;
                $required  = Risk::requiredSize($si, $price, $feePct);
            }

            $atr = null;
            if (isset($t['atr_pct']) && is_numeric($t['atr_pct'])) {
                $a = (float) $t['atr_pct'];
                if (is_finite($a) && $a >= 0.0) {
                    $atr = $a;
                }
            }

            // ---- §5.4 / §5.5 gates: recorded, never a reason to drop the row
            if ($quoteVol < $minVol) {
                $gates[] = 'illiquid';
            }
            if (!$spreadKnown) {
                $gates[] = 'spread_unknown';
            } elseif ($maxSpread > 0.0 && $spread > $maxSpread) {
                $gates[] = 'spread_wide';
            }
            if ($si !== null && $dustCap > 0.0 && $stepValue > $dustCap) {
                $gates[] = 'dust_step';
            }
            if ($atr === null) {
                $gates[] = 'atr_unknown';
            } elseif ($atr < $minAtr) {
                $gates[] = 'atr_low';
            } elseif ($maxAtr > 0.0 && $atr > $maxAtr) {
                $gates[] = 'atr_high';
            }

            // ---- §5.6 score
            $liq   = self::liquidityFactor($quoteVol, $minVol);
            $spf   = $spreadKnown ? self::spreadFactor($spread, $maxSpread) : 0.0;
            $score = $atr === null ? 0.0 : $atr * $liq * $spf;
            if (!is_finite($score) || $score < 0.0) {
                $score = 0.0;
            }
            if ($gates !== []) {
                // A gated row is not tradeable, so it scores 0 and `gates` says why. Without
                // this a 9 %-ATR coin rejected as noise, or one nobody can fill, would still
                // sort above a liquid mover for every consumer that orders by score alone.
                $score = 0.0;
            }

            $rows[$symbol] = [
                'symbol'        => $symbol,
                'price'         => round($price, 10),
                'change_pct'    => round($changePct, 4),
                'quote_vol'     => round($quoteVol, 2),
                'spread_pct'    => round($spread, 6),
                'atr_pct'       => $atr === null ? null : round($atr, 6),
                'step_value'    => round($stepValue, 10),
                'min_notional'  => round($minNot, 8),
                'required_size' => round($required, 8),
                'score'         => round($score, 6),
                'eligible'      => $gates === [] ? 1 : 0,
                'gates'         => $gates,
            ];
        }

        $out = array_values($rows);
        usort($out, ['Scanner', 'compareRows']);
        return $out;
    }

    /**
     * Total order, so the ranking is byte-for-byte reproducible on a fixture:
     * eligible first, then score, then 24 h quote volume, then the symbol.
     */
    public static function compareRows(array $a, array $b): int
    {
        $ea = (int) ($a['eligible'] ?? 0);
        $eb = (int) ($b['eligible'] ?? 0);
        if ($ea !== $eb) {
            return $eb <=> $ea;
        }
        $sa = (float) ($a['score'] ?? 0);
        $sb = (float) ($b['score'] ?? 0);
        if ($sa !== $sb) {
            return $sb <=> $sa;
        }
        $va = (float) ($a['quote_vol'] ?? 0);
        $vb = (float) ($b['quote_vol'] ?? 0);
        if ($va !== $vb) {
            return $vb <=> $va;
        }
        return strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
    }

    /**
     * `min(1, log10(quote_vol / scanner_min_quote_vol) / 2 + 0.5)`, floored at 0 so an illiquid
     * pair is pushed to zero instead of going negative and inverting the ranking.
     */
    public static function liquidityFactor(float $quoteVol, float $minVol): float
    {
        if (!is_finite($quoteVol) || $quoteVol <= 0.0) {
            return 0.0;
        }
        if (!is_finite($minVol) || $minVol <= 0.0) {
            return 1.0;     // no floor configured: liquidity cannot discount the score
        }
        return Util::clamp(log10($quoteVol / $minVol) / 2.0 + 0.5, 0.0, 1.0);
    }

    /** `max(0, 1 − spread_pct / scanner_max_spread_pct)`: a book at the limit scores zero. */
    public static function spreadFactor(float $spreadPct, float $maxSpread): float
    {
        if (!is_finite($spreadPct) || $spreadPct < 0.0) {
            return 0.0;
        }
        if (!is_finite($maxSpread) || $maxSpread <= 0.0) {
            return $spreadPct <= 0.0 ? 1.0 : 0.0;
        }
        return Util::clamp(1.0 - $spreadPct / $maxSpread, 0.0, 1.0);
    }

    /**
     * Leveraged token test. The suffix must be exactly that — a SUFFIX of the BASE asset with a
     * plausible underlying in front of it. `BTCUP`, `ADADOWN`, `XRPBULL`, `ETHBEAR` are tokens;
     * `JUP`, `SUP`, `BEAR`, `UP` and any coin that merely CONTAINS those letters are not.
     */
    public static function isLeveraged(string $base): bool
    {
        $b  = strtoupper(trim($base));
        $lb = strlen($b);
        if ($lb === 0) {
            return false;
        }
        if (in_array($b, self::LEVERAGED_ALLOW, true)) {
            return false;                                   // known-real coin, never a token
        }
        foreach (self::LEVERAGED_SUFFIXES as $suffix) {
            $ls = strlen($suffix);
            if ($lb <= $ls) {
                continue;                                   // the coin IS the word (UP, BEAR)
            }
            if (substr($b, $lb - $ls) !== $suffix) {
                continue;                                   // suffix only, never "contains"
            }
            if ($lb - $ls < self::LEVERAGED_MIN_BASE) {
                continue;                                   // JUP, SUP: underlying too short to be real
            }
            return true;
        }
        return false;
    }

    /**
     * Dust rule (DESIGN-PORTFOLIO.md §5.4, DESIGN.md §1): one quantity step must cost at most
     * 0.3 % of the money the smallest sleeve puts to work, or every order leaves a crumb that
     * cannot be sold. Reference = the smallest ENABLED sleeve budget; with no sleeve configured
     * it falls back to `trade_usdt × 20`, the §2 relation between a budget and the orders it
     * has to support. Returns 0.0 when neither is known, which disables the gate rather than
     * rejecting every symbol on a guess.
     */
    public static function dustLimit(array $cfg): float
    {
        $ref     = 0.0;
        $sleeves = isset($cfg['sleeves']) && is_array($cfg['sleeves']) ? $cfg['sleeves'] : [];
        foreach ($sleeves as $sleeve) {
            if (!is_array($sleeve)) {
                continue;
            }
            if (array_key_exists('enabled', $sleeve) && !self::truthy($sleeve['enabled'])) {
                continue;
            }
            $b = isset($sleeve['budget_usdt']) && is_numeric($sleeve['budget_usdt'])
                ? (float) $sleeve['budget_usdt'] : 0.0;
            if ($b <= 0.0 || !is_finite($b)) {
                continue;
            }
            if ($ref <= 0.0 || $b < $ref) {
                $ref = $b;
            }
        }
        if ($ref <= 0.0) {
            $trade = self::cfgFloat($cfg, 'trade_usdt', 0.0);
            $ref   = $trade > 0.0 ? $trade * 20.0 : 0.0;
        }
        if ($ref <= 0.0 || !is_finite($ref)) {
            return 0.0;
        }
        return $ref * (self::DUST_PCT / 100.0);
    }

    /**
     * Survivors worth an ATR call: everything whose only complaints are the ones this second
     * pass can answer (`atr_unknown`, `no_info`). Ordered by the cheap proxy |change_pct|,
     * with deterministic tie-breaks.
     *
     * @param array $rows output of rank()
     * @return array list of symbols, at most $limit
     */
    public static function atrCandidates(array $rows, int $limit): array
    {
        $fixable = ['atr_unknown' => true, 'no_info' => true];
        $pool    = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['symbol'])) {
                continue;
            }
            $gates = isset($r['gates']) && is_array($r['gates']) ? $r['gates'] : [];
            $ok    = true;
            foreach ($gates as $g) {
                if (!isset($fixable[(string) $g])) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) {
                continue;
            }
            $pool[] = [
                'symbol' => (string) $r['symbol'],
                'move'   => abs((float) ($r['change_pct'] ?? 0)),
                'vol'    => (float) ($r['quote_vol'] ?? 0),
            ];
        }
        usort($pool, static function (array $a, array $b): int {
            if ($a['move'] !== $b['move']) {
                return $b['move'] <=> $a['move'];
            }
            if ($a['vol'] !== $b['vol']) {
                return $b['vol'] <=> $a['vol'];
            }
            return strcmp($a['symbol'], $b['symbol']);
        });
        $out = [];
        foreach ($pool as $p) {
            if (count($out) >= $limit) {
                break;
            }
            $out[] = $p['symbol'];
        }
        return $out;
    }

    // ---------------------------------------------------------------- I/O helpers

    /**
     * One weight-80 call. Rate-limit failures (429/418) are re-thrown so the tick's error
     * policy can set `api_paused_until`; anything else is logged and the refresh is skipped —
     * a scanner outage must never take the trading tick down with it.
     */
    private function fetchTickers(): array
    {
        if (!method_exists($this->md, 'ticker24h')) {
            Log::warn('scanner: market data has no ticker24h(); refresh skipped');
            return [];
        }
        try {
            $rows = $this->md->ticker24h([]);
        } catch (BinanceException $e) {
            if ($e->retryAfter !== null) {
                throw $e;
            }
            Log::warn('scanner: ticker24h failed', ['code' => $e->binanceCode, 'msg' => $e->getMessage()]);
            return [];
        } catch (Throwable $e) {
            Log::warn('scanner: ticker24h failed', ['msg' => $e->getMessage()]);
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    /**
     * Fetch symbol info for candidates the caller did not already have, batched. The caller's
     * own cache is never written back — this map is local to the refresh.
     *
     * @param array $symbols candidate symbols
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
        $chunks = array_chunk(array_keys($missing), self::INFO_BATCH);
        foreach ($chunks as $chunk) {
            try {
                $fetched = $this->md->symbolInfo($chunk);
            } catch (BinanceException $e) {
                if ($e->retryAfter !== null) {
                    throw $e;
                }
                Log::warn('scanner: symbolInfo failed', ['code' => $e->binanceCode, 'msg' => $e->getMessage()]);
                continue;
            } catch (Throwable $e) {
                Log::warn('scanner: symbolInfo failed', ['msg' => $e->getMessage()]);
                continue;
            }
            if (!is_array($fetched)) {
                continue;
            }
            foreach ($fetched as $sym => $row) {
                if (is_array($row)) {
                    $info[strtoupper(trim((string) $sym))] = $row;
                }
            }
        }
        return $info;
    }

    /** True ATR14 on closed 15m candles, as a percent of the last close. Null when unavailable. */
    private function atrPct(string $symbol): ?float
    {
        try {
            $rows = $this->md->klines($symbol, self::ATR_INTERVAL, self::ATR_LIMIT);
        } catch (BinanceException $e) {
            if ($e->retryAfter !== null) {
                throw $e;
            }
            Log::debug('scanner: klines failed for ' . $symbol, ['code' => $e->binanceCode]);
            return null;
        } catch (Throwable $e) {
            Log::debug('scanner: klines failed for ' . $symbol, ['msg' => $e->getMessage()]);
            return null;
        }
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $closed = Indicators::closed($rows, $this->md->serverTimeMs());
        if (count($closed) < self::ATR_PERIOD + 1) {
            $closed = $rows;
        }
        return self::atrPctOf($closed, self::ATR_PERIOD);
    }

    /**
     * ATR($n)/close × 100 from klines rows [openTime, o, h, l, c, v, closeTime]. Pure.
     * Null when there are not enough candles or the close is unusable.
     */
    public static function atrPctOf(array $klines, int $period = self::ATR_PERIOD): ?float
    {
        $h = [];
        $l = [];
        $c = [];
        foreach ($klines as $k) {
            if (!is_array($k) || count($k) < 5) {
                continue;
            }
            $h[] = (float) $k[2];
            $l[] = (float) $k[3];
            $c[] = (float) $k[4];
        }
        if (count($c) < $period + 1) {
            return null;
        }
        $atr   = Indicators::last(Indicators::atr($h, $l, $c, $period));
        $close = Indicators::last($c);
        if ($atr === null || !is_numeric($atr) || $close === null || (float) $close <= 0.0) {
            return null;
        }
        $pct = (float) $atr / (float) $close * 100.0;
        if (!is_finite($pct) || $pct < 0.0) {
            return null;
        }
        return $pct;
    }

    // ---------------------------------------------------------------- config helpers

    /** `scanner_exclude` as an uppercase lookup map. */
    public static function excludeMap(array $cfg): array
    {
        $out  = [];
        $list = isset($cfg['scanner_exclude']) && is_array($cfg['scanner_exclude'])
            ? $cfg['scanner_exclude']
            : ['USDCUSDT', 'FDUSDUSDT', 'TUSDUSDT', 'BUSDUSDT', 'EURUSDT'];
        foreach ($list as $s) {
            if (is_string($s) || is_numeric($s)) {
                $s = strtoupper(trim((string) $s));
                if ($s !== '') {
                    $out[$s] = true;
                }
            }
        }
        return $out;
    }

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

    private static function cfgBool(array $cfg, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $cfg)) {
            return $default;
        }
        return self::truthy($cfg[$key]);
    }

    /** '0', 'false', '' and 0 are false; everything else follows PHP truthiness. */
    private static function truthy($v): bool
    {
        if (is_string($v)) {
            $s = strtolower(trim($v));
            return !($s === '' || $s === '0' || $s === 'false' || $s === 'no' || $s === 'off');
        }
        return (bool) $v;
    }
}
