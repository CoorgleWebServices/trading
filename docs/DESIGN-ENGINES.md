# Engines: grid trading and pure market making

Extends `docs/DESIGN.md`, which stays authoritative for everything it already covers
(PHP 7.4 rules, Db/Util/Log/Binance/Risk conventions, panel security, tick locking).
This document is authoritative for everything below.

## 1. What changes and why

The existing bot is a **signal engine**: one position at a time, market orders, entries only on a
closed 15m candle. It is deliberately rare. Two new engines trade continuously instead:

* **grid** — a ladder of resting limit orders. Buy a rung down, sell a rung up, repeat. Earns from
  oscillation. Loses when price trends out of the range, which the range exit bounds.
* **pmm** — pure market making, the Hummingbot core strategy. Quote a bid and an ask around mid,
  re-quote every refresh, earn the spread.

**Honest economics, to be repeated in the panel and README.** Binance VIP0 charges 0.1 % maker,
identical to taker, so a round trip costs ~0.2 %. Observed spreads on the majors are 0.01–0.05 %.
Therefore **pmm is expected to lose money at VIP0 fees**; it is built because it was asked for and
because it becomes viable at better fee tiers, on wide-spread pairs, or with maker rebates. Grid is
viable when rung spacing exceeds the round-trip cost, which the config validator enforces.

**Demo-only rule.** `grid` and `pmm` refuse to run when `mode` is `live` unless
`allow_live_engines` is explicitly true. `Exchange`/`Bot` must not place a single order in that
state: log once, set `no_trade_reason='engine_live_blocked'`, return. The panel shows a banner and
the setting carries the warning text. Default `allow_live_engines = false`.

## 2. Configuration (added to §3 of DESIGN.md)

| key | default | notes |
|---|---|---|
| `engine` | `"signal"` | `signal` \| `grid` \| `pmm` |
| `allow_live_engines` | `false` | must be true before grid/pmm may run in live mode |
| `engine_symbol` | `"DOGEUSDT"` | the single symbol grid/pmm trade (they do not use the watchlist) |
| `grid_levels` | `6` | buy rungs below the anchor; 1–20, and must stay under the symbol's MAX_NUM_ORDERS |
| `grid_spacing_pct` | `0.60` | distance between rungs; validator requires ≥ `2 × fee_pct + 0.1` |
| `grid_order_usdt` | `1.30` | quote per rung; must satisfy `Risk::requiredSize()` for the symbol |
| `grid_range_up_pct` | `4.0` | mid above `anchor × (1+x)` ⇒ range exit |
| `grid_range_down_pct` | `6.0` | mid below `anchor × (1−x)` ⇒ range exit |
| `grid_exit_liquidates` | `false` | on range exit, also market-sell the inventory |
| `pmm_spread_pct` | `0.25` | half-spread each side of mid |
| `pmm_order_usdt` | `1.30` | quote per quote-order |
| `pmm_refresh_sec` | `60` | re-quote age; quotes older than this are cancelled and replaced |
| `pmm_target_base_pct` | `50` | inventory target, percent of engine equity held as base |
| `pmm_max_base_pct` | `80` | above this, stop bidding; below `100−this`, stop asking |
| `engine_max_orders` | `12` | hard cap on simultaneously open orders (belt and braces vs MAX_NUM_ORDERS) |
| `post_only` | `true` | use `LIMIT_MAKER`; false uses `LIMIT` + `GTC` |

`Risk::validateConfig()` enforces: spacing ≥ round-trip cost + 0.1; `grid_levels × grid_order_usdt`
≤ 90 % of quote free at save time (warning, not error); `grid_order_usdt`/`pmm_order_usdt` ≥
`requiredSize`; `engine_max_orders` ≤ 20; `pmm_spread_pct` > 0. Selecting `grid`/`pmm` while
`mode=live` and `allow_live_engines=false` is saved but flagged with a visible warning.

## 3. Binance client additions (lib/Binance.php)

```php
/** LIMIT (timeInForce GTC) or LIMIT_MAKER when $postOnly. $priceStr/$qtyStr are pre-rounded strings. */
public function limitOrder(string $symbol, string $side, string $qtyStr, string $priceStr, string $clientId, bool $postOnly): array;
public function cancelOrder(string $symbol, string $clientId): array;       // DELETE /api/v3/order        weight 1
public function cancelAllOrders(string $symbol): array;                     // DELETE /api/v3/openOrders   weight 1
public function openOrders(string $symbol): array;                          // GET   /api/v3/openOrders    weight 6 (symbol REQUIRED — never call it without one, weight 80)
public function myTrades(string $symbol, string $orderId): array;           // GET   /api/v3/myTrades      weight 5
```
Rules:
* `LIMIT` needs `timeInForce=GTC`, `quantity`, `price`. `LIMIT_MAKER` needs `quantity`, `price` only
  and is rejected if it would match immediately — that rejection is **normal**, not an error:
  catch `-2010` whose message contains `immediately match`, log at debug, and skip that quote for
  this tick. Add `BinanceException::isPostOnlyReject(): bool` for this test.
* Cancelling an order that already filled or vanished returns `-2011 CANCEL_REJECTED`; treat it as
  success and resolve the order's real state with `getOrder()`.
* `openOrders()` normalises to `[clientOrderId => ['order_id','symbol','side','price','orig_qty','executed_qty','status','time']]`.
* Prices must satisfy `PRICE_FILTER.tickSize` and `PERCENT_PRICE_BY_SIDE`. Parse both in
  `exchangeInfo()`: add `bidMultiplierDown|Up`, `askMultiplierDown|Up`, `avgPriceMins` and
  `maxNumOrders` (from `MAX_NUM_ORDERS`) to the parsed symbol-info shape.

## 4. Util additions

```php
Util::roundToTick(float $price, string $tickSize, string $dir): string;  // $dir 'down'|'up'|'nearest'; exact, no exponent
```
Buy prices round **down**, sell prices round **up** — always away from the mid, so a post-only quote
stays passive.

## 5. New tables (migrate in Db::migrate(), additive only)

```sql
CREATE TABLE IF NOT EXISTS engine_orders (
  client_id TEXT PRIMARY KEY, order_id TEXT, mode TEXT NOT NULL, engine TEXT NOT NULL,
  symbol TEXT NOT NULL, side TEXT NOT NULL,
  status TEXT NOT NULL,              -- NEW | PARTIALLY_FILLED | FILLED | CANCELED | REJECTED | EXPIRED | UNKNOWN
  price REAL NOT NULL, qty REAL NOT NULL, quote REAL NOT NULL,
  filled_qty REAL NOT NULL DEFAULT 0, filled_quote REAL NOT NULL DEFAULT 0,
  fee_usdt REAL NOT NULL DEFAULT 0, fee_asset TEXT,
  level INTEGER,                     -- grid rung index; NULL for pmm
  purpose TEXT,                      -- 'grid_buy' | 'grid_sell' | 'pmm_bid' | 'pmm_ask'
  created_at TEXT NOT NULL, updated_at TEXT, raw TEXT
);
CREATE INDEX IF NOT EXISTS idx_engine_orders_live ON engine_orders(status, symbol);

CREATE TABLE IF NOT EXISTS lots (          -- FIFO inventory produced by engine buys
  id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, engine TEXT NOT NULL, symbol TEXT NOT NULL,
  qty REAL NOT NULL, remaining REAL NOT NULL, price REAL NOT NULL, fee_usdt REAL NOT NULL DEFAULT 0,
  level INTEGER, client_id TEXT, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS cycles (        -- realised buy→sell round trips
  id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, engine TEXT NOT NULL, symbol TEXT NOT NULL,
  level INTEGER, qty REAL NOT NULL, buy_price REAL NOT NULL, sell_price REAL NOT NULL,
  gross_usdt REAL NOT NULL, fee_usdt REAL NOT NULL, pnl_usdt REAL NOT NULL,
  opened_at TEXT, closed_at TEXT NOT NULL
);
```
`Db` gains: `engineOrders(array $statuses)`, `engineOrder(string $clientId)`, `insertEngineOrder()`,
`updateEngineOrder()`, `openEngineOrders(string $symbol)`, `insertLot()`, `openLots(string $symbol)`,
`consumeLots(string $symbol, float $qty)` (FIFO, returns consumed slices and updates `remaining`),
`insertCycle()`, `cycles(int $limit)`, `cyclePnl(?string $since, ?string $mode)`,
`engineStats(?string $mode, ?string $engine)` → `['cycles','pnl','fees','wins','losses','win_rate','inventory_qty','inventory_cost']`.

`Risk::realisedPnl()` and the daily/weekly caps must count **both** `positions.pnl_usdt` and
`cycles.pnl_usdt` for the active mode, so the survival layer governs all three engines.

## 6. Order synchronisation (lib/EngineOrders.php)

One class, used by both engines, run at the top of every engine tick:

```php
final class EngineOrders {
  public function __construct(array $cfg, Db $db, ExchangeInterface $ex, array $info);
  /** Reconciles local NEW/PARTIALLY_FILLED rows against the exchange, books fills, returns a summary. */
  public function sync(string $symbol): array;   // ['filled'=>[rows], 'open'=>[rows], 'cancelled'=>int]
  public function place(string $side, float $price, float $quote, string $purpose, ?int $level): ?array;
  public function cancel(string $clientId): bool;
  public function cancelAll(string $symbol, string $reason): int;
  public function bookFill(array $order, array $fill): void;  // lots + cycles + trades rows
}
```
`sync()`:
1. `openOrders(symbol)` from the exchange (weight 6).
2. Every local row with status NEW/PARTIALLY_FILLED that is **absent** from that set is resolved with
   `getOrder(symbol, clientId)`; its terminal status and `executedQty`/`cummulativeQuoteQty` are stored.
3. Any exchange order **absent locally** (placed by a previous run, or by the user in the Binance UI)
   is cancelled and logged at warn — the engine owns the book for its symbol.
4. Fills are booked exactly once, guarded by `filled_qty` already recorded, so a repeated sync is
   idempotent. A BUY fill inserts a lot; a SELL fill consumes lots FIFO and writes a `cycles` row per
   consumed slice with `pnl = sell_proceeds_net − lot_cost − allocated_fees`.

Fees follow the DESIGN.md rules: base-asset commission reduces the lot quantity; quote commission
reduces proceeds; BNB commission is valued in USDT and subtracted from `pnl`.

## 7. Grid engine (lib/EngineGrid.php)

State keys: `grid_anchor`, `grid_anchor_at`, `grid_symbol`, `grid_paused_reason`.

```php
final class EngineGrid { public function __construct(array $cfg, Db $db, ExchangeInterface $ex, EngineOrders $orders, array $info);
  public function tick(float $bid, float $ask): array;   // ['action'=>string,'detail'=>string]
  public function anchor(): float;  public function reanchor(float $mid): void; }
```
Per tick, after `EngineOrders::sync()`:
1. If no anchor, set `anchor = mid` and log.
2. **Range exit:** `mid > anchor×(1+grid_range_up_pct/100)` or `mid < anchor×(1−grid_range_down_pct/100)`
   ⇒ `cancelAll`, optionally market-sell all inventory when `grid_exit_liquidates`, set
   `paused_until` far future with `pause_reason='grid_range_exit'`, log at warn, return. Requires a
   manual "Re-anchor grid" from the panel to resume.
3. For each rung `i` in `1..grid_levels`: target buy price `Pb(i) = roundToTick(anchor×(1−i×s), tick, 'down')`.
   Post a `grid_buy` at `Pb(i)` when: no live buy at that level, no lot held for that level, price is
   below `bid` (post-only safety), the rung passes `PERCENT_PRICE_BY_SIDE`, and the open-order cap allows it.
4. For each lot with no live sell: post a `grid_sell` at
   `roundToTick(max(lot.price×(1+s), anchor×(1−(level−1)×s)), tick, 'up')`, quantity
   `floorToStep(lot.remaining)`, only when above `ask`. A lot whose sellable quantity or notional is
   below the filters is left alone and reported as `grid_dust`.
5. Cap total live orders at `engine_max_orders` and at `info.maxNumOrders − 2`.

Placing at most **one new order per side per tick** keeps the order rate and the weight predictable;
the ladder fills in over a few minutes and is then stable.

## 8. Pure market making (lib/EnginePmm.php)

```php
final class EnginePmm { public function __construct(array $cfg, Db $db, ExchangeInterface $ex, EngineOrders $orders, array $info);
  public function tick(float $bid, float $ask, float $baseFree, float $quoteFree): array; }
```
Per tick, after sync:
1. `mid = (bid+ask)/2`. Cancel any live quote older than `pmm_refresh_sec`, or whose price has drifted
   more than `pmm_spread_pct/2` from its intended level.
2. `basePct = base_value / (base_value + quote_free) × 100`.
3. Post a bid at `roundToTick(mid×(1−spread/100), tick, 'down')` sized `pmm_order_usdt`, unless
   `basePct ≥ pmm_max_base_pct`, quote free is short, or a live bid already exists.
4. Post an ask at `roundToTick(mid×(1+spread/100), tick, 'up')` sized from inventory, unless
   `basePct ≤ 100−pmm_max_base_pct`, inventory is below the filters, or a live ask already exists.
5. Inventory skew: when `basePct > pmm_target_base_pct`, shrink the bid and grow the ask
   proportionally (linear between target and max), and the reverse below target. Never exceed
   `pmm_order_usdt × 1.5` on either side.
6. The panel must state the expected-loss warning wherever pmm is selected.

## 9. Bot integration (lib/Bot.php)

`Bot::tick()` keeps steps 1–4 (time, symbol info, reconcile, balances, equity, survival check)
unchanged for every engine, then branches:

* `engine === 'signal'` → the existing steps 5–7, untouched.
* `engine === 'grid'|'pmm'` → engine tick:
  1. If mode is live and `allow_live_engines` is false ⇒ `no_trade_reason='engine_live_blocked'`, return.
  2. `EngineOrders::sync()`; book fills.
  3. If halted or `entryBlockReason()` is non-empty for a **capital** reason (halted, daily cap, weekly
     cap, api paused) ⇒ `cancelAll()` and return; the engine must not keep quoting while paused.
     A cooldown or `max_trades` block does **not** stop an engine (those are signal-engine concepts);
     `Risk::entryBlockReason()` gains an `$engine` argument so it can distinguish them.
  4. Otherwise run `EngineGrid::tick()` / `EnginePmm::tick()`.
* The kill switch (`equity_floor`, `drawdown`) must `cancelAll()` **before** any liquidation, for
  every engine.

Equity for grid/pmm = quote free + quote locked + base free + base locked valued at bid, so resting
orders are never counted as lost.

## 10. Panel

* Settings: an **Engine** selector (`signal` / `grid` / `pmm`) that reveals only that engine's fields,
  the `allow_live_engines` checkbox with its warning, and the pmm expected-loss notice.
* Dashboard, when an engine is active: an **Engine** card (engine, symbol, anchor, distance to each
  range edge, live order count, inventory qty and cost, unrealised at bid); an **Open orders** table
  (side, level, price, qty, quote, age, status) with a per-order Cancel button and a "Cancel all"
  action; a **Cycles** table (last 30: level, buy, sell, qty, pnl, closed_at) and cycle KPIs
  (cycles today, realised PnL, fees, win rate).
* Actions (POST + CSRF, inside `Bot::runLocked`): `cancel_order` (client_id), `cancel_all`,
  `reanchor_grid`, `flatten_inventory` (market-sell all engine inventory, with a confirm checkbox).
* The signal-engine cards stay visible when `engine === 'signal'`.

## 11. Tests (tests/run.php, offline)

`FakeMarketData`/`PaperExchange` gain limit-order support: a resting order fills when the scripted
bid/ask crosses its price, so a test can script a price path and assert cycles. Add groups:

* `util-tick` — `roundToTick` down/up/nearest, exact strings, no exponent, tickSize `0.00001` and `1`.
* `engine-orders` — place/sync/cancel; a fill books a lot; a sell consumes FIFO and writes a cycle
  with the correct fee-inclusive pnl; sync is idempotent (double sync books one fill); an unknown
  exchange order is cancelled.
* `engine-grid` — ladder is placed below mid; a buy fill produces a sell one rung up; the completed
  cycle pnl equals `spacing − fees` within tolerance; the order cap holds; range exit cancels
  everything and pauses; re-anchor resumes.
* `engine-pmm` — bid and ask are posted around mid at the configured spread; quotes older than
  `pmm_refresh_sec` are replaced; inventory skew stops bidding above `pmm_max_base_pct` and stops
  asking when inventory is below the filters.
* `engine-guard` — grid/pmm in live mode with `allow_live_engines=false` place **zero** orders and
  report `engine_live_blocked`; the equity floor cancels all orders and halts; a daily-cap pause
  cancels all orders.
* `engine-fees` — a full cycle at spacing below the fee floor is rejected by `validateConfig`.
