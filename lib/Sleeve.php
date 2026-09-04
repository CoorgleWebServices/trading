<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';

/**
 * Portfolio sleeves (docs/DESIGN-PORTFOLIO.md §3).
 *
 * A sleeve is one method with a budget and an exclusive symbol set. The sleeve
 * key IS the engine name, so there is exactly one sleeve per method:
 * `signal`, `grid`, `pmm`.
 *
 * A sleeve budget is an ACCOUNTING boundary, not an exchange one: Binance holds
 * a single balance and the bot enforces the split itself. That only works while
 * two sleeves never share a symbol - `Risk::validateConfig()` rejects overlap -
 * because attribution here is strictly by symbol ownership:
 *
 *   * a base balance is attributed to the sleeve that owns its symbol;
 *   * a base balance whose symbol belongs to no sleeve is *unattributed*: it is
 *     excluded from every sleeve's numbers (it still counts in total equity for
 *     the global kill switch, which is Bot's business, not this class's).
 *
 * Everything is static and `state()` is PURE: it reads `$db`, `$balances` and
 * `$prices` and performs no network I/O whatsoever, so tests can drive it
 * directly with a fixture database and a scripted price map.
 *
 * With `portfolio_enabled = false` nothing in this file is ever called and the
 * single-engine behaviour of docs/DESIGN-ENGINES.md is untouched.
 */
final class Sleeve
{
    /** The three methods, in canonical order (a config may define a subset). */
    const ENGINES = ['signal', 'grid', 'pmm'];

    /** Empty sleeve: what `state()` reports for an engine with no configuration. */
    const EMPTY_SLEEVE = ['enabled' => false, 'budget_usdt' => 0.0, 'symbols' => []];

    /** Statuses of a signal position that still holds inventory. */
    const OPEN_POSITION_STATUSES = ['OPEN', 'STUCK'];

    /* ------------------------------------------------------------- helpers */

    /** A finite float or $fallback (never NAN/INF, so no sleeve number can poison the panel). */
    private static function num($v, float $fallback = 0.0): float
    {
        if (is_bool($v) || !is_numeric($v)) {
            return $fallback;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : $fallback;
    }

    /** Loose truthiness that also understands the strings a panel form posts. */
    private static function truthy($v): bool
    {
        if (is_string($v)) {
            $s = strtolower(trim($v));
            return !($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no');
        }
        return !empty($v);
    }

    /** Configured quote asset, upper-case (default USDT). */
    private static function quoteAsset(array $cfg): string
    {
        $q = strtoupper(trim((string) ($cfg['quote_asset'] ?? 'USDT')));
        return $q === '' ? 'USDT' : $q;
    }

    /** Configured mode, or null (= every mode) when unset. */
    private static function mode(array $cfg): ?string
    {
        $m = strtolower(trim((string) ($cfg['mode'] ?? '')));
        return $m === '' ? null : $m;
    }

    /** Base asset of a symbol given the quote asset ('SOLUSDT','USDT' → 'SOL'); '' when it does not fit. */
    public static function baseAsset(string $symbol, string $quote): string
    {
        $symbol = strtoupper(trim($symbol));
        $quote  = strtoupper(trim($quote));
        $lq     = strlen($quote);
        if ($symbol === '' || $lq === 0 || strlen($symbol) <= $lq) {
            return '';
        }
        if (substr($symbol, -$lq) !== $quote) {
            return '';
        }
        return substr($symbol, 0, strlen($symbol) - $lq);
    }

    /** Symbol list from a config value: an array, or a comma/space separated string. */
    private static function symbolList($raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/[\s,;]+/', $raw);
            $raw   = is_array($parts) ? $parts : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $s) {
            if (is_array($s) || is_object($s)) {
                continue;
            }
            $sym = strtoupper(trim((string) $s));
            if ($sym !== '' && !in_array($sym, $out, true)) {
                $out[] = $sym;
            }
        }
        return $out;
    }

    /** Normalise one raw sleeve entry to exactly ['enabled','budget_usdt','symbols']. */
    private static function normalise($raw): array
    {
        if (!is_array($raw)) {
            return self::EMPTY_SLEEVE;
        }
        $budget = self::num($raw['budget_usdt'] ?? 0.0, 0.0);
        if ($budget < 0.0) {
            $budget = 0.0;
        }
        return [
            'enabled'     => self::truthy($raw['enabled'] ?? false),
            'budget_usdt' => $budget,
            'symbols'     => self::symbolList($raw['symbols'] ?? []),
        ];
    }

    /* ----------------------------------------------------------- accessors */

    /** True when portfolio mode is on. False ⇒ the single-engine behaviour, unchanged. */
    public static function portfolioEnabled(array $cfg): bool
    {
        return self::truthy($cfg['portfolio_enabled'] ?? false);
    }

    /**
     * Every configured sleeve as [engine => ['enabled','budget_usdt','symbols']],
     * ENABLED SLEEVES FIRST, each group keeping its configured order.
     */
    public static function all(array $cfg): array
    {
        $raw = $cfg['sleeves'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $on  = [];
        $off = [];
        foreach ($raw as $key => $entry) {
            $engine = strtolower(trim((string) $key));
            if ($engine === '') {
                continue;
            }
            $sleeve = self::normalise($entry);
            if ($sleeve['enabled']) {
                $on[$engine] = $sleeve;
            } else {
                $off[$engine] = $sleeve;
            }
        }
        return array_merge($on, $off);
    }

    /** One sleeve's normalised configuration, or null when the engine has no sleeve. */
    public static function of(array $cfg, string $engine): ?array
    {
        $engine = strtolower(trim($engine));
        if ($engine === '') {
            return null;
        }
        $all = self::all($cfg);
        return isset($all[$engine]) ? $all[$engine] : null;
    }

    /** True when the sleeve exists and is enabled. */
    public static function isEnabled(array $cfg, string $engine): bool
    {
        $s = self::of($cfg, $engine);
        return $s !== null && $s['enabled'];
    }

    /** The symbols one sleeve owns (upper-case, de-duplicated); [] when it has no sleeve. */
    public static function symbols(array $cfg, string $engine): array
    {
        $s = self::of($cfg, $engine);
        return $s === null ? [] : $s['symbols'];
    }

    /**
     * Which sleeve owns $symbol, or null when no sleeve does (the balance is then
     * *unattributed*). Disabled sleeves still own their symbols: their inventory
     * must not silently become another sleeve's, and a reassignment must still be
     * refused. Enabled sleeves are checked first, so an (invalid) overlapping
     * configuration resolves deterministically.
     */
    public static function ownerOf(array $cfg, string $symbol): ?string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }
        foreach (self::all($cfg) as $engine => $sleeve) {
            if (in_array($symbol, $sleeve['symbols'], true)) {
                return (string) $engine;
            }
        }
        return null;
    }

    /** True when $engine's sleeve owns $symbol. */
    public static function owns(array $cfg, string $engine, string $symbol): bool
    {
        return self::ownerOf($cfg, $symbol) === strtolower(trim($engine));
    }

    /** Union of every sleeve's symbols (optionally only the enabled ones), in sleeve order. */
    public static function allSymbols(array $cfg, bool $enabledOnly = false): array
    {
        $out = [];
        foreach (self::all($cfg) as $sleeve) {
            if ($enabledOnly && !$sleeve['enabled']) {
                continue;
            }
            foreach ($sleeve['symbols'] as $sym) {
                if (!in_array($sym, $out, true)) {
                    $out[] = $sym;
                }
            }
        }
        return $out;
    }

    /* -------------------------------------------------------- market input */

    /**
     * Price for $symbol out of a price map. Accepts [symbol => float] (what
     * `MarketDataInterface::prices()` returns) and [symbol => ['bid'=>…,'ask'=>…]]
     * (a bookTicker map), preferring the bid — inventory is valued at what it
     * could actually be sold for. 0.0 when the symbol is absent.
     */
    private static function priceOf(array $prices, string $symbol): float
    {
        if (!array_key_exists($symbol, $prices)) {
            return 0.0;
        }
        $v = $prices[$symbol];
        if (is_array($v)) {
            foreach (['bid', 'price', 'close', 'ask'] as $k) {
                if (isset($v[$k]) && is_numeric($v[$k])) {
                    return max(0.0, self::num($v[$k]));
                }
            }
            return 0.0;
        }
        return max(0.0, self::num($v));
    }

    /**
     * Free + locked balance of one asset. Accepts the `account()` shape
     * ([asset => ['free'=>…,'locked'=>…]]), a whole account array (with a
     * 'balances' key) and the flat paper shape ([asset => float]).
     */
    private static function balanceOf(array $balances, string $asset): float
    {
        if ($asset === '') {
            return 0.0;
        }
        if (!array_key_exists($asset, $balances)
            && isset($balances['balances']) && is_array($balances['balances'])) {
            $balances = $balances['balances'];
        }
        if (!array_key_exists($asset, $balances)) {
            return 0.0;
        }
        $b = $balances[$asset];
        if (is_array($b)) {
            $qty = self::num($b['free'] ?? 0.0) + self::num($b['locked'] ?? 0.0);
        } else {
            $qty = self::num($b);
        }
        return $qty > 0.0 ? $qty : 0.0;
    }

    /* --------------------------------------------------------- SQL helpers */

    /** '(?, ?, ?)' for an IN clause, with the symbols appended to $params. */
    private static function inClause(array $symbols, array &$params): string
    {
        foreach ($symbols as $s) {
            $params[] = $s;
        }
        return '(' . implode(', ', array_fill(0, count($symbols), '?')) . ')';
    }

    /** ' AND mode = ?' (binding $mode) or ''. Mirrors Db's own analytics scoping. */
    private static function modeSql(?string $mode, array &$params): string
    {
        if ($mode === null || $mode === '') {
            return '';
        }
        $params[] = $mode;
        return ' AND mode = ?';
    }

    /** One aggregate row (or [] on any driver hiccup — a sleeve card must never fatal a tick). */
    private static function aggregate(Db $db, string $sql, array $params): array
    {
        try {
            $st = $db->pdo()->prepare($sql);
            $st->execute($params);
            $row = $st->fetch();
        } catch (Throwable $e) {
            return [];
        }
        return is_array($row) ? $row : [];
    }

    /* --------------------------------------------------------------- state */

    /**
     * Live picture of one sleeve. PURE: everything comes from $db, $balances and
     * $prices — no network call, no clock-dependent branch beyond "today".
     *
     * @param array $balances [asset => ['free'=>float,'locked'=>float]] (or [asset => float])
     * @param array $prices   [symbol => float] (bid preferred), as produced by the tick
     * @return array{engine:string, enabled:bool, budget:float, symbols:string[],
     *   inventory_qty:array, inventory_value:float, inventory_cost:float, reserved:float,
     *   realised:float, unrealised:float, equity:float, available:float, used_pct:float,
     *   trades:int, cycles:int, wins:int, losses:int, win_rate:float, fees:float,
     *   pnl_today:float, expectancy:float}
     */
    public static function state(array $cfg, Db $db, string $engine, array $balances, array $prices): array
    {
        $engine  = strtolower(trim($engine));
        $sleeve  = self::of($cfg, $engine);
        $enabled = $sleeve !== null && $sleeve['enabled'];
        $budget  = $sleeve === null ? 0.0 : $sleeve['budget_usdt'];
        $symbols = $sleeve === null ? [] : $sleeve['symbols'];

        $mode       = self::mode($cfg);
        $quote      = self::quoteAsset($cfg);
        $todayStart = Util::todayUtc() . 'T00:00:00Z';

        /* --- inventory: base balances of the symbols this sleeve owns ------ */
        $inventoryQty   = [];
        $inventoryValue = 0.0;
        foreach ($symbols as $symbol) {
            $qty = self::balanceOf($balances, self::baseAsset($symbol, $quote));
            $inventoryQty[$symbol] = $qty;
            $inventoryValue += $qty * self::priceOf($prices, $symbol);
        }

        /* --- inventory cost: open engine lots + open signal positions ------ */
        $inventoryCost = 0.0;
        $reserved      = 0.0;
        foreach ($symbols as $symbol) {
            foreach ($db->openLots($symbol, $mode) as $lot) {
                $inventoryCost += self::num($lot['remaining'] ?? 0.0) * self::num($lot['price'] ?? 0.0);
            }
            // Quote committed by this sleeve's live BUY orders: what is still
            // resting, i.e. the order minus whatever already filled (the filled
            // part has become inventory and is counted above, never twice).
            foreach ($db->openEngineOrders($symbol, $mode) as $order) {
                if (strtoupper((string) ($order['side'] ?? '')) !== 'BUY') {
                    continue;
                }
                $committed = self::num($order['quote'] ?? 0.0);
                if ($committed <= 0.0) {
                    $committed = self::num($order['price'] ?? 0.0) * self::num($order['qty'] ?? 0.0);
                }
                $committed -= self::num($order['filled_quote'] ?? 0.0);
                if ($committed > 0.0) {
                    $reserved += $committed;
                }
            }
        }

        $realised   = 0.0;
        $pnlToday   = 0.0;
        $trades     = 0;
        $wins       = 0;
        $losses     = 0;
        $fees       = 0.0;

        if ($symbols !== []) {
            /* open signal positions still hold their entry quote as inventory */
            $params = [];
            $in     = self::inClause($symbols, $params);
            $sts    = '(' . implode(', ', array_fill(0, count(self::OPEN_POSITION_STATUSES), '?')) . ')';
            foreach (self::OPEN_POSITION_STATUSES as $st) {
                $params[] = $st;
            }
            $sql = 'SELECT COALESCE(SUM(entry_quote), 0) AS cost FROM positions'
                 . ' WHERE symbol IN ' . $in . ' AND status IN ' . $sts
                 . self::modeSql($mode, $params);
            $row = self::aggregate($db, $sql, $params);
            $inventoryCost += self::num($row['cost'] ?? 0.0);

            /* realised signal PnL, decided counts */
            $params = [];
            $in     = self::inClause($symbols, $params);
            $sql = 'SELECT COUNT(*) AS n, COALESCE(SUM(pnl_usdt), 0) AS pnl,
                    COALESCE(SUM(CASE WHEN pnl_usdt > 0 THEN 1 ELSE 0 END), 0) AS wins,
                    COALESCE(SUM(CASE WHEN pnl_usdt < 0 THEN 1 ELSE 0 END), 0) AS losses
                    FROM positions
                    WHERE symbol IN ' . $in . " AND status = 'CLOSED' AND pnl_usdt IS NOT NULL"
                 . self::modeSql($mode, $params);
            $row      = self::aggregate($db, $sql, $params);
            $trades   = (int) ($row['n'] ?? 0);
            $realised = self::num($row['pnl'] ?? 0.0);
            $wins     = (int) ($row['wins'] ?? 0);
            $losses   = (int) ($row['losses'] ?? 0);

            /* realised signal PnL closed today */
            $params = [];
            $in     = self::inClause($symbols, $params);
            $sql = 'SELECT COALESCE(SUM(pnl_usdt), 0) AS pnl FROM positions'
                 . ' WHERE symbol IN ' . $in . " AND status = 'CLOSED' AND pnl_usdt IS NOT NULL"
                 . ' AND closed_at >= ?';
            $params[] = $todayStart;
            $sql     .= self::modeSql($mode, $params);
            $row      = self::aggregate($db, $sql, $params);
            $pnlToday = self::num($row['pnl'] ?? 0.0);

            /* fees: every fill of this sleeve's symbols, signal and engine alike
               (EngineOrders books its fills into `trades` too, so cycles.fee_usdt
               must NOT be added here or the fees would be counted twice) */
            $params = [];
            $in     = self::inClause($symbols, $params);
            $sql = 'SELECT COALESCE(SUM(fee_usdt), 0) AS fees FROM trades WHERE symbol IN ' . $in
                 . self::modeSql($mode, $params);
            $row  = self::aggregate($db, $sql, $params);
            $fees = self::num($row['fees'] ?? 0.0);
        }

        /* --- engine round trips: one sleeve per engine, so engine+mode IS the
               sleeve's cycle set (DESIGN-PORTFOLIO §1) ------------------------ */
        $engineStats = $db->engineStats($mode, $engine);
        $cycles      = (int) ($engineStats['cycles'] ?? 0);
        $realised   += self::num($engineStats['pnl'] ?? 0.0);
        $wins       += (int) ($engineStats['wins'] ?? 0);
        $losses     += (int) ($engineStats['losses'] ?? 0);
        $pnlToday   += $db->cyclePnl($todayStart, $mode, $engine);

        /* --- derived ------------------------------------------------------- */
        $inventoryValue = self::num($inventoryValue);
        $inventoryCost  = self::num($inventoryCost);
        $reserved       = self::num($reserved);
        $realised       = self::num($realised);
        $pnlToday       = self::num($pnlToday);

        $unrealised = $inventoryValue - $inventoryCost;
        $equity     = $budget + $realised + $unrealised;
        $available  = $budget + $realised - $inventoryCost - $reserved;
        if (!is_finite($available) || $available < 0.0) {
            $available = 0.0;
        }
        $usedPct = $budget > 0.0 ? ($inventoryCost + $reserved) / $budget * 100.0 : 0.0;
        if (!is_finite($usedPct) || $usedPct < 0.0) {
            $usedPct = 0.0;
        }
        $decided = $wins + $losses;
        $round   = $trades + $cycles;

        return [
            'engine'          => $engine,
            'enabled'         => $enabled,
            'budget'          => $budget,
            'symbols'         => $symbols,
            'inventory_qty'   => $inventoryQty,
            'inventory_value' => $inventoryValue,
            'inventory_cost'  => $inventoryCost,
            'reserved'        => $reserved,
            'realised'        => $realised,
            'unrealised'      => self::num($unrealised),
            'equity'          => self::num($equity),
            'available'       => $available,
            'used_pct'        => $usedPct,
            'trades'          => $trades,
            'cycles'          => $cycles,
            'wins'            => $wins,
            'losses'          => $losses,
            'win_rate'        => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
            'fees'            => $fees,
            'pnl_today'       => $pnlToday,
            'expectancy'      => $round > 0 ? $realised / $round : 0.0,
        ];
    }
}
