# Scalp engine — fast micro-trading across many symbols

A second engine (`engine = 'scalp'`) alongside signal. Extends `docs/DESIGN.md`, which stays
authoritative for what it covers. (The grid and pmm engines it was originally written beside, and
portfolio mode, have since been removed.)

## 1. What the user asked for, in their words

Frequent automatic buy/sell, as fast as the host allows, across **20–30 coins at once**, in tiny
size, on the **demo** account, with a board showing the **top coins by fluctuation and by number
of traders**, and a **pause after 5–10 consecutive losses** that waits for a human to resume.

The existing engines do not do this. `signal` holds one position and trades a few times a day;
This engine is many symbols, many trades,
market orders, seconds not hours.

## 2. The honest purpose: measurement, not profit

At 0.1 % per side a round trip costs ~0.2 %, so a 0.25 % scalp keeps ~0.05 % before slippage.
The arithmetic says this loses. The user's question is the right one — *measure it on demo* —
so the engine's real deliverable is a verdict, and the panel must make it unmissable:

```
Gross P&L (before fees)   +1.42 USDT     ← is there ANY directional edge?
Fees paid                 −3.10 USDT     ← what it cost to find out
Net P&L                   −1.68 USDT     ← the answer
```

Separating gross from net is the whole point. Gross positive with net negative means the
signal works and fees eat it, which is a fee problem. Gross negative means there is no edge at
all and no fee tier would save it. Never show net alone, and never imply a few green trades
prove anything — show the trade count beside every verdict.

**Demo-only**: `scalp` places no order when `mode` is `live` unless
`allow_live_engines` is explicitly true.

## 3. Movers board (`lib/Movers.php`)

Answers "top currencies with fluctuations and too many traders".

* Source: `GET /api/v3/ticker/24hr` with no symbol (**weight 80** — so at most once per
  `scalp_universe_refresh_min`, default 10, never inside the fast loop).
* `Binance::normalizeTicker24h()` must additionally keep **`count`** (the 24-hour trade count —
  this is the "many traders" signal and is currently dropped) and `weightedAvgPrice`.
* Filters: quote asset matches, status `TRADING`, `isSpotTradingAllowed`, not a leveraged token
  (`UP`/`DOWN`/`BULL`/`BEAR` as a *suffix* of the base asset only), not in `scalp_exclude`
  (stablecoin pairs), `quote_vol >= scalp_min_quote_vol`, spread `<= scalp_max_spread_pct`,
  and the dust rule `stepSize × price <= 0.3 % of scalp_order_usdt`.
* Score, each term normalised 0–1 across the surviving set so no term can dominate by unit:
  `score = 0.45·moveRank + 0.35·tradeCountRank + 0.20·liquidityRank − spreadPenalty`
  where move uses `(high − low) / last` (intraday range beats `change_pct`, which is blind to a
  round trip) and `spreadPenalty = min(0.3, spread_pct / scalp_max_spread_pct × 0.3)`.
* Output: every candidate with its metrics and its gates, stored in table `movers`. The top
  `scalp_universe_size` eligible rows are the trading universe. Rows that failed a gate are kept
  with the reason so the panel can explain a rejection rather than silently dropping it.
* The board **never** changes the universe mid-position: a symbol holding an open position stays
  in the universe until it closes, even if it drops out of the ranking.

## 4. Fast loop (`lib/Scalper.php`)

One cron tick runs a bounded loop instead of a single pass, which is how sub-minute trading is
reached on a host whose cron fires once a minute.

* `scalp_run_seconds` (default 50) total, polling every `scalp_poll_sec` (default 3) → ~16
  decision points per minute. The loop exits early at `scalp_run_seconds` or on the tick lock
  being wanted, and always leaves state consistent.
* Each poll makes **one** call: `GET /api/v3/ticker/bookTicker` with no symbol, returning bid and
  ask for every symbol at once. Verify its current weight from the docs before shipping and record
  it in a comment; budget conservatively at 4. At 20 polls per minute that is ≤ 80 weight/min
  against a 6000/min limit, so the universe can grow without re-architecting.
* Per symbol the loop keeps a short in-memory ring buffer (`scalp_window` ticks, default 8) of
  mid prices. Nothing is persisted per tick — only entries, exits and the run summary are.

### Entry
Long only. For a symbol with no open position, all must hold:
1. the window is full,
2. `dip = (windowHigh − mid) / windowHigh ≥ scalp_dip_pct` (default 0.15 %) — it has pulled back,
3. the last two ticks are rising — it has turned,
4. `spread_pct ≤ scalp_max_spread_pct`,
5. open positions `< scalp_max_open` (default 6) and none already on this symbol,
6. `Risk::entrySize()` clears the symbol's filters, and free quote stays above the reserve.

### Exit
Checked every poll, and an open position is **always** managed even when entries are paused:
`take profit` at `scalp_tp_pct` (0.25 %), `stop` at `scalp_sl_pct` (0.20 %), or `max hold` at
`scalp_max_hold_sec` (120). All three measured against `entry_eff` (fees and dust included, per
DESIGN.md), evaluated on the **bid**, because that is what a sale actually receives.

## 5. Consecutive-loss breaker

The user's explicit request, and the thing that makes running this safe to walk away from.

* `scalp_max_consecutive_losses` (default 5, range 2–20). A closed position with net P&L < 0
  increments the counter; any win resets it to 0.
* On reaching the limit: set state `scalp_paused = 1` with `scalp_pause_reason` naming the count
  and the time, log at warn, and **stop opening new positions**. Open positions keep being
  managed to their exit — abandoning a position would be worse than the losing streak.
* It does **not** clear itself. The panel shows a prominent banner with the streak, the net P&L
  over those trades, and a **Resume scalping** button (POST + CSRF) that clears the counter and
  the pause. That is the "pause and ask me" behaviour.
* Independent of, and additional to, the existing global survival layer: the equity floor,
  drawdown kill switch and daily/weekly caps all still apply and still win.

## 6. Configuration

| key | default | notes |
|---|---|---|
| `scalp_universe_size` | `20` | symbols traded at once; 1–30 |
| `scalp_order_usdt` | `50.0` | per position; must clear `Risk::requiredSize()` AND be large enough that the §3 dust rule (step value <= 0.3 % of one order) does not gate the whole market. One stepSize costs ~0.13 USDT on SOL, ~0.24 on ETH, ~0.68 on BTC, so a 6 USDT order leaves only the cheapest coins eligible. |
| `scalp_budget_per_symbol` | `100.0` | default cap per symbol (§7) |
| `scalp_budgets` | `{}` | per-symbol overrides, e.g. `{"BTCUSDT":200,"DOGEUSDT":50}` |
| `scalp_max_open` | `6` | concurrent positions, one per symbol |
| `scalp_tp_pct` | `0.25` | take profit |
| `scalp_sl_pct` | `0.20` | stop |
| `scalp_max_hold_sec` | `120` | time exit |
| `scalp_dip_pct` | `0.15` | pullback needed before a bounce entry |
| `scalp_window` | `8` | ticks held per symbol. MUST fit inside one loop: the buffer is in memory and cron rebuilds the engine every minute, so a window needing more polls than `scalp_run_seconds / scalp_poll_sec` affords never fills and the engine places no order at all. `Risk::validateConfig()` warns. |
| `scalp_poll_sec` | `3` | seconds between polls |
| `scalp_run_seconds` | `50` | loop length inside one cron tick |
| `scalp_max_consecutive_losses` | `5` | then pause and wait for a human |
| `scalp_max_trades_per_hour` | `60` | runaway guard, counts entries |
| `scalp_min_quote_vol` | `20000000` | 24 h quote volume floor |
| `scalp_max_spread_pct` | `0.05` | |
| `scalp_universe_refresh_min` | `10` | how often the weight-80 board refreshes |
| `scalp_exclude` | stablecoin pairs | never ranked |

`Risk::validateConfig()` bounds every one, and rejects `scalp_tp_pct` below `2 × fee_pct` with a
message naming the real break-even, so the user cannot silently configure a guaranteed loss
without being told. It is a **warning, not an error**: the user may deliberately want to measure
a sub-break-even target, and the panel states what it costs.

## 7. Per-symbol budget and scoreboard

Every symbol trades its **own** pot, so the run answers "which coin actually makes money"
rather than giving one blended number. This is the point of the whole exercise.

* Each symbol's cap is `scalp_budgets[SYMBOL]` when set, otherwise `scalp_budget_per_symbol`
  (default 100 USDT). With a 20-symbol universe that allocates 2000 of the demo account's
  balance, and the sum is checked against free quote at save time (a warning, not an error —
  not every symbol is deployed at once).
* The ledger is **derived, never stored as a running total**, so it cannot drift out of step with
  the positions it describes. `Scalper::symbolLedger(Db, cfg, symbol)` returns, from the
  `positions` rows for that symbol in the current mode:

  | field | meaning |
  |---|---|
  | `budget` | its cap |
  | `deployed` | `entry_quote` of its open positions |
  | `available` | `max(0, budget + realised_net − deployed)` |
  | `gross`, `fees`, `net` | realised, summed over its closed positions |
  | `trades`, `wins`, `losses`, `win_rate` | sample size beside every rate |
  | `open` | count of open positions |

* **Enforcement:** an entry is refused when `scalp_order_usdt > available` for that symbol, with
  reason `symbol_budget`. A symbol that has lost its pot stops trading **on its own** while the
  others carry on — the per-symbol equivalent of the consecutive-loss breaker, and what keeps one
  bad coin from draining the run. Exits are never budget-blocked; selling returns capital.
* `available` must never go negative, NaN or infinite — assert it for an empty symbol, a zero
  budget and a symbol deep underwater.

**Scoreboard** (the card the user will actually read): one row per symbol that has traded or is
in the universe, sorted by net P&L descending — symbol, budget, deployed, trades, win rate with
its sample size, gross, fees, net, and net as a percent of its budget. A footer repeats the
§2 warning that a handful of trades proves nothing, naming the current total trade count.

## 8. Storage

```sql
CREATE TABLE IF NOT EXISTS movers (
  symbol TEXT PRIMARY KEY, ts TEXT NOT NULL, last REAL, change_pct REAL, range_pct REAL,
  trade_count INTEGER, quote_vol REAL, spread_pct REAL, step_value REAL, required_size REAL,
  score REAL, rank INTEGER, in_universe INTEGER NOT NULL DEFAULT 0,
  eligible INTEGER NOT NULL DEFAULT 0, gates TEXT
);
CREATE TABLE IF NOT EXISTS scalp_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT NOT NULL, ended_at TEXT,
  mode TEXT NOT NULL, polls INTEGER DEFAULT 0, entries INTEGER DEFAULT 0, exits INTEGER DEFAULT 0,
  gross_usdt REAL DEFAULT 0, fees_usdt REAL DEFAULT 0, net_usdt REAL DEFAULT 0, note TEXT
);
```
Per-symbol attribution needs no table of its own: it is derived from `positions` by symbol,
which is why `gross_usdt` and `fees_usdt` must be recorded per closed position.

Scalp positions reuse the existing `positions` table with `mode` and an `engine`-style marker, so
the survival layer, the equity curve and the closed-trade history keep working unchanged. Each
closed scalp position records `gross_usdt` and `fees_usdt` separately — add those two columns if
absent (additive only) — because §2's verdict depends on them.

## 9. Panel

* **Movers card** (top 10): rank, symbol, last, 24 h change %, intraday range %, **trade count**,
  quote volume, spread, required size, score, in-universe, gates. This is the "top 10 with
  fluctuations and many traders" board.
* **Scalp card**: running/paused state, the consecutive-loss streak out of its limit, open
  positions with age in seconds and unrealised %, entries this hour against the cap, polls in the
  last run, and the **Gross / Fees / Net** verdict block from §2 with the trade count.
* **Pause banner** when `scalp_paused`, naming the streak and carrying the Resume button.
* Settings gets every §6 key, with the break-even warning rendered inline.
* `?api=status` gains a `scalp` block and a `movers` block.

## 10. Tests

* `scalp-movers` — ranking is deterministic on a fixture; a high-volatility but thin or
  wide-spread coin ranks below a liquid mover; leveraged tokens and stablecoin pairs are excluded;
  gates are recorded rather than the row dropped; a symbol with an open position stays in the
  universe after it drops out of the ranking.
* `scalp-entry` — no entry without a full window, without a dip, without the two-tick turn, with
  a wide spread, at `scalp_max_open`, or twice on one symbol.
* `scalp-exit` — TP, SL and max-hold each fire against `entry_eff` on the bid, not the raw fill.
* `scalp-breaker` — **the one the user asked for**: N consecutive losses pause entries, a win
  resets the counter, open positions are still managed while paused, the pause does not clear
  itself, and Resume clears it. Also assert the global kill switch still overrides.
* `scalp-pnl` — gross, fees and net reconcile per trade and in the run summary; gross never
  silently equals net.
* `scalp-budget` — each symbol spends only its own pot: an entry over `available` is refused with
  reason `symbol_budget`; a symbol that exhausts its budget stops while others keep trading; a
  per-symbol override beats the default; `available` is never negative or NaN for an empty, a
  zero-budget or a deeply underwater symbol; a close returns capital to that symbol and no other;
  the scoreboard's per-symbol nets sum to the run's net.
* `scalp-guard` — live mode without `allow_live_engines` places zero orders; the hourly cap holds;
  the loop honours `scalp_run_seconds`.
* `scalp-loop` — a scripted price path through `FakeMarketData` produces the expected entries and
  exits with no network call.
