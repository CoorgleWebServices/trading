# Learning: observation capture, evidence, and bounded feedback

Extends `docs/DESIGN.md`, `DESIGN-ENGINES.md` and `DESIGN-PORTFOLIO.md`, which remain
authoritative for what they cover. Authoritative for everything below.

## 1. What "learning" means here, and what it does not

The bot records the **conditions** present at every trading decision and the **outcome** that
followed, then reports which conditions actually preceded profit. That is honest, inspectable
statistics over its own history. It is not a neural network and it will not discover alpha.

Three rules keep it from being dangerous:

1. **Learning may never increase risk.** It can adjust score weights and the entry threshold.
   It may never change position size, stop-loss, take-profit, budgets, or any kill switch.
   These are hard invariants, asserted by tests.
2. **Evidence before action.** No adjustment is applied from fewer than `learn_min_samples`
   (default 60) outcomes in a bucket, and only when the effect clears a Wilson confidence
   interval, so a lucky streak cannot move anything.
3. **Never train on what it then trades.** Adjustments are recomputed at most once per
   `learn_recompute_hours` (default 168, one week) from trades **closed before** that moment, and
   the recompute is stamped so the panel can show which trades were evidence and which were the
   out-of-sample test.

The panel must state the sample size next to every claim, and must say plainly when the evidence
is too thin to conclude anything — which, at a few trades a day, it will be for weeks.

## 2. Observation capture

Every entry evaluation writes one row, **whether or not it traded**. Skipped evaluations are the
control group; without them the bot only ever learns from trades it happened to take.

```sql
CREATE TABLE IF NOT EXISTS observations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT NOT NULL, mode TEXT NOT NULL, engine TEXT NOT NULL, symbol TEXT NOT NULL,
  decision TEXT NOT NULL,            -- 'entered' | 'skipped'
  skip_reason TEXT,                  -- gate or block reason when skipped
  score INTEGER, threshold INTEGER,
  features TEXT NOT NULL,            -- JSON, see below
  position_id INTEGER, cycle_id INTEGER,
  outcome TEXT,                      -- NULL while open; 'win' | 'loss' | 'flat' | 'not_taken'
  pnl_usdt REAL, pnl_pct REAL, exit_reason TEXT, held_minutes REAL,
  resolved_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_obs_open ON observations(outcome, mode);
CREATE INDEX IF NOT EXISTS idx_obs_ts ON observations(mode, ts);
```

`features` JSON, all numeric so bucketing is uniform:
`rsi`, `atr_pct`, `atr1h_pct`, `bb_pos` (0 at the lower band, 1 at the upper), `vol_ratio`
(volume / SMA20 volume), `trend_up` (1/0 from the 1h regime gate), `spread_pct`, `hour_utc`,
`dow` (0–6), `dist_from_anchor_pct` (grid only), `step_value_pct` (dust coarseness).

Resolution: when a position closes or a cycle completes, the matching observation is updated with
`outcome`, `pnl_usdt`, `exit_reason`, `held_minutes`, `resolved_at`. `skipped` rows resolve to
`not_taken` immediately — they carry no PnL and are used only for condition frequency, never
counted as wins or losses.

## 3. Evidence (lib/Learn.php)

```php
final class Learn {
  /** Bucket one feature and report the outcome statistics per bucket. Pure over its inputs. */
  public static function buckets(array $rows, string $feature, array $edges): array;
  /** @return array Wilson score interval ['lo'=>float,'hi'=>float] for k successes of n at 95 %. */
  public static function wilson(int $k, int $n): array;
  /** Every feature, ranked by how strongly it separates winners from losers. */
  public static function insights(Db $db, array $cfg, int $days = 90): array;
  /** Bounded weight nudges implied by the evidence; [] when the evidence is insufficient. */
  public static function adjustments(Db $db, array $cfg): array;
  /** Applies §4 caps and writes learn_weights + learn_at. Returns what it changed and why. */
  public static function recompute(Db $db, array $cfg): array;
}
```
`insights()` returns per feature:
```php
['feature','buckets'=>[['label','n','wins','losses','win_rate','avg_pnl','wilson_lo','wilson_hi']],
 'separation'=>float,      // best bucket expectancy − worst bucket expectancy
 'confident'=>bool,        // both buckets meet learn_min_samples AND their Wilson intervals are disjoint
 'note'=>string]           // plain-language reading, or why it is inconclusive
```
Default bucket edges: RSI `[0,20,25,30,40,50,70,100]`; `atr_pct` `[0,0.3,0.5,0.8,1.2,2,100]`;
`bb_pos` `[0,0.1,0.25,0.5,0.75,1]`; `vol_ratio` `[0,0.8,1,1.2,1.5,3,999]`; `hour_utc` in 4-hour
blocks; `trend_up` and `dow` are categorical.

## 4. Bounded feedback

`learn_weights` (state, JSON) maps a Strategy score component to a delta:
`{'rsi':+0,'bb':-10,'reversal':+0,'rsi_up':+10,'vol':-10,'trend':+0}`.
`Strategy::evaluate()` adds the matching delta to each component's contribution when
`learning_enabled` is true. Hard caps, enforced in `Learn::recompute()` and asserted by tests:

* each delta is clamped to **±10** points and to the component's own base value (a component can
  be neutralised, never inverted into a bonus for the opposite condition);
* at most **one component** changes per recompute, the one with the strongest confident separation;
* the effective entry threshold stays inside `[entry_threshold − 10, 100]`;
* **nothing else is writable.** `Learn` has no access to size, TP, SL, budgets or kill switches, and
  a test asserts the config keys it can touch are exactly the weight map and threshold.

`learning_apply` (default **false**) gates whether adjustments take effect. With it false the panel
still shows everything it *would* do, which is how the operator builds trust before enabling it.

## 5. Configuration

| key | default | notes |
|---|---|---|
| `learning_enabled` | `true` | capture observations and compute insights |
| `learning_apply` | `false` | actually feed adjustments back into scoring |
| `learn_min_samples` | `60` | per bucket, before any claim is called confident |
| `learn_recompute_hours` | `168` | minimum gap between recomputes |
| `learn_window_days` | `90` | how far back evidence is drawn |

## 6. Panel

An **Insights** page (`?page=insights`, authenticated):
* a header stating total observations, resolved outcomes, and — when below
  `learn_min_samples` — a plain sentence that no conclusion is available yet and roughly how many
  more trades are needed;
* one card per feature: the bucket table (range, n, win rate with its confidence interval, average
  PnL), the separation figure, and the plain-language note;
* a **current weights** card showing `learn_weights`, when it was last recomputed, what changed and
  on what evidence, plus whether `learning_apply` is on;
* a **skipped vs entered** card: the conditions the bot most often refused, so the operator can see
  whether the gates are too tight;
* a "Recompute now" action (POST + CSRF) that runs `Learn::recompute()` and reports its decision.

The dashboard gains one line: the strongest confident insight, with its sample size, or "not enough
data yet".

## 7. BNB fee discount (small, related: it changes the fee arithmetic learning measures)

`Binance::bnbBurnStatus()` and `setBnbBurn()` already exist. Wire them up:
* the API health card gains a **BNB fee discount** row: on/off/unavailable, the account's BNB free
  balance, and the effective round-trip cost (0.15 % when on, 0.2 % when off);
* a `toggle_bnb_burn` action (POST + CSRF). When the endpoint returns null the panel must say the
  host does not serve `/sapi` and that the toggle lives in the Binance UI instead — never present
  that as an error;
* a **warning** when burn is on but the BNB balance is below `bnb_min_balance` (default 1.0 USDT
  equivalent): Binance silently reverts to charging the received asset, which changes both the fee
  and the dust behaviour mid-run;
* `fee_pct` is informational only — the live taker rate already comes from the account.

## 8. Tests

* `learn-wilson` — the interval matches known values; k=0 and k=n do not divide by zero.
* `learn-buckets` — bucketing is correct at edges; empty buckets are reported, not dropped.
* `learn-capture` — an entry and a skip each write one observation; a close resolves it with the
  right outcome and PnL; a skip resolves to `not_taken`; no observation is written twice.
* `learn-caps` — **the safety test**: deltas clamp to ±10, only one component moves per recompute,
  the threshold stays in range, and `recompute()` provably cannot alter size, TP, SL, budgets or any
  kill-switch key (assert the whole config is unchanged except the permitted keys).
* `learn-evidence` — below `learn_min_samples` nothing is confident and no adjustment is produced;
  a strong, well-sampled separation does produce exactly one bounded adjustment; a lucky 5-trade
  streak produces none.
* `learn-walkforward` — a recompute uses only trades closed before it, and does not re-fire inside
  `learn_recompute_hours`.
* `learn-apply-off` — with `learning_apply` false, `Strategy::evaluate()` scores identically to a
  run with learning disabled, so the existing strategy tests remain valid.
