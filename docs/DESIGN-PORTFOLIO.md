# Portfolio mode: parallel sleeves, per-method attribution, and a volatility scanner

Extends `docs/DESIGN.md` and `docs/DESIGN-ENGINES.md`, which stay authoritative for everything
they already cover. Authoritative for everything below.

## 1. Goal

Run the three methods **at the same time**, each with its own slice of capital and its own symbols,
and show on the dashboard which one is actually working. Plus a scanner that ranks USDT pairs by
volatility so the sleeves can be pointed at coins that move.

A **sleeve** is one method with a budget and an exclusive symbol set. The sleeve key IS the engine
name, so there is exactly one sleeve per method: `signal`, `grid`, `pmm`. Every existing row already
carries an `engine`/`mode` column, so attribution needs no new keys on the trading tables.

**Honest framing to repeat in the panel and README:** a sleeve's budget is an *accounting* boundary,
not an exchange one. Binance holds a single balance; the bot enforces the split itself. Two sleeves
must therefore never share a symbol, or one would sell inventory the other bought — the validator
rejects overlap outright.

## 2. Configuration

| key | default | notes |
|---|---|---|
| `portfolio_enabled` | `false` | false ⇒ the single-engine behaviour of DESIGN-ENGINES.md, unchanged |
| `sleeves` | see below | map keyed by engine name |
| `sleeve_reserve_pct` | `5` | percent of total quote held back, never allocated |
| `scanner_enabled` | `true` | |
| `scanner_refresh_min` | `60` | how often /ticker/24hr is refreshed (weight 80 per refresh) |
| `scanner_min_quote_vol` | `5000000` | 24 h quote volume floor, in USDT — the liquidity filter |
| `scanner_max_spread_pct` | `0.06` | |
| `scanner_min_atr_pct` | `0.5` | ATR14 on 15m, percent of price |
| `scanner_max_atr_pct` | `4.0` | above this is untradeable noise, not opportunity |
| `scanner_top_n` | `10` | how many rows the panel shows |
| `scanner_exclude` | `["USDCUSDT","FDUSDUSDT","TUSDUSDT","BUSDUSDT","EURUSDT"]` | stablecoin pairs never rank |

```php
'sleeves' => [
  'signal' => ['enabled' => true,  'budget_usdt' => 1000.0, 'symbols' => ['SOLUSDT','ETHUSDT']],
  'grid'   => ['enabled' => true,  'budget_usdt' => 1000.0, 'symbols' => ['DOGEUSDT']],
  'pmm'    => ['enabled' => false, 'budget_usdt' => 1000.0, 'symbols' => ['XRPUSDT']],
],
```
`Risk::validateConfig()` additionally enforces, as **errors**:
* a symbol may appear in at most one sleeve (report the symbol and both sleeves);
* every symbol ends with `quote_asset` and is upper-case;
* `grid` and `pmm` sleeves take exactly one symbol (their engines are single-symbol);
* `Σ enabled budgets ≤ total_quote × (1 − sleeve_reserve_pct/100)` — a **warning** when it cannot be
  checked (no balance yet), an error when it can;
* each budget ≥ `20 × Risk::requiredSize()` for its symbols when known, else a warning naming the
  minimum that would work;
* portfolio mode with `grid`/`pmm` enabled obeys the existing demo-only rule
  (`allow_live_engines`), unchanged.

## 3. Sleeve accounting (lib/Sleeve.php)

```php
final class Sleeve {
  public static function all(array $cfg): array;                    // [engine => sleeve cfg], enabled first
  public static function of(array $cfg, string $engine): ?array;
  public static function symbols(array $cfg, string $engine): array;
  public static function ownerOf(array $cfg, string $symbol): ?string;   // which sleeve owns a symbol
  /** Live picture of one sleeve. Pure: everything comes from $db, $balances and $prices. */
  public static function state(array $cfg, Db $db, string $engine, array $balances, array $prices): array;
}
```
`Sleeve::state()` returns:
```php
['engine','enabled','budget','symbols',
 'inventory_qty'  => [symbol => qty],       // base held, attributed to this sleeve
 'inventory_value'=> float,                 // at bid
 'inventory_cost' => float,                 // FIFO cost of open lots + open signal positions
 'reserved'       => float,                 // quote committed to this sleeve's resting BUY orders
 'realised'       => float,                 // positions.pnl_usdt + cycles.pnl_usdt for engine+mode
 'unrealised'     => float,                 // inventory_value − inventory_cost
 'equity'         => float,                 // budget + realised + unrealised
 'available'      => float,                 // max(0, budget + realised − inventory_cost − reserved)
 'used_pct'       => float,
 'trades'         => int, 'cycles' => int, 'wins' => int, 'losses' => int,
 'win_rate'       => float, 'fees' => float, 'pnl_today' => float, 'expectancy' => float]
```
**Budget rule:** an engine may not open a position or place a BUY whose quote exceeds
`available`. `EngineOrders::place()` and the signal engine's `Risk::entrySize()` both consult it.
Sells are never budget-blocked — reducing inventory always returns capital to the sleeve.

Attribution of base balances to sleeves is by **symbol ownership**, which is unambiguous because
symbols are exclusive. A base balance whose symbol belongs to no sleeve is reported as
`unattributed` in the portfolio card and is excluded from every sleeve's numbers (it still counts in
total equity for the global kill switch).

## 4. New tables

```sql
CREATE TABLE IF NOT EXISTS sleeve_equity (
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, mode TEXT NOT NULL, engine TEXT NOT NULL,
  equity REAL NOT NULL, budget REAL NOT NULL, realised REAL NOT NULL, unrealised REAL NOT NULL,
  inventory_value REAL NOT NULL, reserved REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sleeve_equity ON sleeve_equity(mode, engine, ts);

CREATE TABLE IF NOT EXISTS scanner (
  symbol TEXT PRIMARY KEY, ts TEXT NOT NULL, price REAL, change_pct REAL, quote_vol REAL,
  spread_pct REAL, atr_pct REAL, step_value REAL, min_notional REAL, required_size REAL,
  score REAL, eligible INTEGER NOT NULL DEFAULT 0, gates TEXT
);
```
`Db` gains `insertSleeveEquity()`, `sleeveEquitySeries(string $engine, int $limit)`,
`replaceScanner(array $rows)`, `scannerRows(int $limit, bool $eligibleOnly)`, `scannerAge()`.

## 5. Volatility scanner (lib/Scanner.php)

```php
final class Scanner {
  public function __construct(array $cfg, Db $db, MarketDataInterface $md);
  public function due(): bool;                       // scanner_refresh_min since scanner_at
  public function refresh(array $info): array;       // ranks and stores; returns the rows
  public static function rank(array $tickers, array $info, array $cfg): array;   // pure, testable
}
```
`refresh()`:
1. `GET /api/v3/ticker/24hr` with no symbol (**weight 80** — this is why it runs hourly, never per
   tick; guard on `due()` and store `scanner_at`).
2. Keep symbols that end in `quote_asset`, are not in `scanner_exclude`, are not leveraged tokens
   (`UP`/`DOWN`/`BULL`/`BEAR` suffixes), and whose `exchangeInfo` status is `TRADING` with
   `isSpotTradingAllowed`.
3. Compute per candidate: `spread_pct = (ask−bid)/mid×100`, `quote_vol`, `change_pct`,
   `step_value = stepSize × price`, `min_notional`, `required_size` (`Risk::requiredSize`).
4. Gate: `quote_vol ≥ scanner_min_quote_vol`, `spread_pct ≤ scanner_max_spread_pct`,
   `step_value ≤ 0.3 % of the smallest sleeve budget's order size` (the dust rule from DESIGN.md §1).
   Failing gates are recorded in `gates`, not dropped, so the panel can explain the rejection.
5. For the top 25 survivors by a cheap proxy (`|change_pct|`), fetch 15m klines (weight 2 each) and
   compute true `atr_pct = ATR14/close × 100`. Gate on `scanner_min_atr_pct … scanner_max_atr_pct`.
6. `score = atr_pct × liquidity_factor × spread_factor`, where
   `liquidity_factor = min(1, log10(quote_vol / min_quote_vol) / 2 + 0.5)` and
   `spread_factor = max(0, 1 − spread_pct / scanner_max_spread_pct)`. Volatility alone must never
   win: an illiquid or wide-spread coin is penalised toward zero.
7. `replaceScanner()` the whole set.

The scanner **never reassigns a sleeve's symbols on its own.** It ranks and explains; the operator
applies a suggestion with a one-click panel action (§7). Silent symbol changes under a live grid
would strand inventory.

## 6. Bot integration

`Bot::tick()` when `portfolio_enabled`:
1. Steps 1–4 unchanged (time, symbol info for the union of all sleeve symbols, reconcile, balances,
   total equity, `insertEquity`, `Risk::survivalCheck`). The **global** kill switch still uses total
   equity and still halts everything, cancelling every sleeve's resting orders first.
2. `Scanner::due()` ⇒ `refresh()` (at most one per tick, before the sleeves, and never when the tick
   is already past half its time budget).
3. For each enabled sleeve, in a **rotating order** persisted in state `sleeve_cursor` so no sleeve
   is starved when the tick runs long:
   * skip and log if the sleeve's engine is grid/pmm and the demo-only guard blocks it;
   * compute `Sleeve::state()`;
   * run that engine restricted to the sleeve's symbols and its `available` budget;
   * `insertSleeveEquity()`;
   * stop the loop early once `TICK_TIME_BUDGET_MS` (40000) is exceeded, recording which sleeves were
     skipped in `no_trade_reason` so the panel can say so.
4. Per-sleeve pause: a sleeve whose `equity ≤ budget × (1 − sleeve_max_drawdown_pct/100)` stops
   opening new exposure (`sleeve_paused_<engine>` state) while the others continue. Default
   `sleeve_max_drawdown_pct = 25`. This is what makes the comparison safe: a failing method stops
   itself without taking the account down.

Single-engine mode (`portfolio_enabled = false`) must behave **exactly** as it does today.

## 7. Panel

* **Portfolio card** (dashboard, when `portfolio_enabled`): one row per sleeve — engine, symbols,
  budget, equity, realised, unrealised, PnL %, trades/cycles, win rate, fees, used %, status
  (running / paused / blocked / drawdown-paused). Row colour by PnL sign. A "best method so far"
  line naming the leader by realised PnL, with the sample size, and the plain caveat that a handful
  of trades proves nothing.
* **Per-sleeve equity sparkline** from `sleeve_equity`, all three overlaid on one small chart with a
  legend, so the comparison is visual.
* **Scanner card**: rank, symbol, price, 24 h change, ATR %, spread, 24 h quote volume, step value,
  required size, score, eligible/gates, and an "Assign to…" control per row that sets that symbol as
  a sleeve's symbol (POST + CSRF, refuses if another sleeve already owns it, refuses for a sleeve
  holding inventory, and says why).
* **Settings**: a Portfolio section — `portfolio_enabled`, and per sleeve enabled / budget / symbols;
  plus the scanner thresholds. Validation errors from §2 render inline against the offending sleeve.
* `?api=status` gains a `portfolio` block (per-sleeve numbers) and `scanner` (top rows).

## 8. Tests

* `sleeve-alloc` — budget arithmetic: available shrinks with inventory cost and reserved buy orders,
  never negative; realised/unrealised/equity add up; a sell restores availability.
* `sleeve-exclusive` — overlapping symbols across sleeves is a validation error; a sleeve holding
  inventory refuses a symbol reassignment; ownerOf resolves correctly.
* `sleeve-budget-cap` — an engine cannot place an order exceeding `available`; the signal engine's
  entry size is clamped to it; sells are never blocked.
* `sleeve-drawdown` — a sleeve past `sleeve_max_drawdown_pct` stops opening while the other sleeves
  keep trading; the global kill switch still halts everything.
* `portfolio-tick` — three sleeves in one tick each trade only their own symbols; the rotating cursor
  advances; exceeding the time budget skips the remaining sleeves and records it.
* `scanner-rank` — ranking is deterministic on a fixture; illiquid and wide-spread coins are
  penalised below volatile-but-liquid ones; stablecoin and leveraged-token pairs are excluded; gates
  are recorded rather than the row being dropped; ATR outside the band is rejected.
* `portfolio-off` — with `portfolio_enabled = false`, every existing single-engine test still passes
  and no sleeve code runs.
