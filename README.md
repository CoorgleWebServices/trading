# Binance Micro-Trader

A small, self-contained PHP bot that trades Binance **spot** USDT pairs with market orders,
one position at a time, from a shared cPanel host. It ships with a password-protected web panel,
a once-per-minute cron "tick", a paper-trading mode that is on by default, and a survival layer
whose only job is to keep a tiny account from dying.

No Composer, no external libraries, no shell access required. PHP 7.4+ (works on 8.x),
`curl`, `pdo_sqlite`, `json`.

---

## What it is - and what it is NOT

**It is**

* a deterministic mean-reversion strategy on 15-minute candles (RSI, Bollinger bands, a reversal
  candle, volume) with a 1-hour trend filter, plus a bounded self-tuning rule driven by the rolling
  win rate. That is the whole "AI". There are no LLM calls, no cloud services, no prediction API.
* a risk layer that runs before any strategy code on every tick: equity floor kill switch,
  high-water-mark drawdown kill switch, daily and weekly loss caps, a cooldown ladder after
  losses, trade-count caps, a stop-loss on every position, dust-aware sizing, order idempotency
  and reconciliation with the exchange.
* four modes: `paper` (simulated fills at the live bid/ask - the default), `demo`
  (demo-api.binance.com, keys from demo.binance.com), `testnet` (testnet.binance.vision) and
  `live` (api.binance.com).

**It is NOT**

* a money printer. With a 10 USDT account and 0.2 % round-trip fees, the honest expectation is
  roughly **-3 % ... +2 % per month**. Every trade must earn more than 0.2 % just to break even, and
  a 1 % take-profit target on 6.5 USDT is about 6 cents. The risk layer bounds the loss; it cannot
  create an edge. Treat this as a toy that is engineered not to blow up, not as an income source.
* financial advice, and not a substitute for understanding what a market order, a stop-loss and a
  minimum notional are. Read this whole file before switching to `live`.
* a high-frequency system. One cron tick per minute, one order per tick at most, entries only when
  a new 15-minute candle has closed.

---

## Requirements

* cPanel hosting (or any Apache/PHP host) with **PHP 7.4 or newer** (8.0 - 8.4 tested) and the
  extensions `curl`, `pdo_sqlite`, `json`. `bcmath` is used when present; otherwise an
  integer-math fallback is used - both are exact.
* Cron jobs enabled in cPanel (or any external service that can call a URL every minute).
* Outbound HTTPS to `api.binance.com` and `data-api.binance.vision`, plus `demo-api.binance.com`
  for demo mode or `testnet.binance.vision` for testnet mode. Some hosts block outbound connections - the "Test API connection"
  button in Settings tells you.
* HTTPS on your domain (Let's Encrypt/AutoSSL in cPanel is fine). The panel redirects HTTP to
  HTTPS by default.
* Binance does not serve accounts from every country; it is your job to check that trading is
  allowed where you live.

---

## Install on cPanel, step by step

1. **Upload the folder.** In cPanel open *File Manager*, go to `public_html`, upload the zip of this
   project and extract it so that you end up with `public_html/trader/` containing `index.php`,
   `cron.php`, `bootstrap.php`, `config.php`, `.htaccess`, `lib/`, `assets/`, `tests/`, `docs/`.
   You can rename `trader` to anything you like; the URL follows the folder name.
2. **Select the PHP version.** cPanel → *MultiPHP Manager* (or *Select PHP Version* on
   CloudLinux hosts): choose PHP 8.2/8.3 (7.4 also works) for the domain. In *PHP Extensions* /
   *Select PHP Version → Extensions* make sure `curl`, `pdo_sqlite`, `sqlite3` and `json` are
   ticked. `bcmath` is optional.
3. **Permissions.** Folders `755`, files `644` (the File Manager defaults). The runtime directory
   `trader/data/` is created automatically with mode `0750`; `data/config.json` gets `0600`. If the
   web server cannot create `data/`, create it yourself in File Manager and give it `750` (or `755`
   on hosts that run PHP as your own user - the usual cPanel setup). Never make `data/` world-writable.
4. **Check that the runtime directory is protected.** Open
   `https://yourdomain.com/trader/data/` in a browser - you must get **403 Forbidden**. The same for
   `https://yourdomain.com/trader/lib/Bot.php`, `/tests/`, `/docs/`, `/config.php` and
   `/bootstrap.php`. If any of those show content, `.htaccess` is not being honoured (ask your host
   to enable `AllowOverride`, or move the folder below `public_html` and symlink only `index.php`,
   `cron.php` and `assets/`).
5. **Open the panel.** Go to `https://yourdomain.com/trader/`. The **setup wizard** appears
   because no password exists yet. Choose a panel password (12 characters minimum, typed twice),
   leave the mode on **paper**, optionally paste API keys (not needed for paper mode), keep or edit
   the watchlist, and leave "force HTTPS" on. The wizard creates `data/`, `data/.htaccess`, the
   SQLite database and `data/config.json`, then shows you the exact cron command and the HTTP cron
   URL with its key.
6. **Add the cron job** (next section), then wait two minutes and check the dashboard: the "last
   tick" pill must be green. If it shows "cron not running", see Troubleshooting.

---

## Cron setup (every minute)

cPanel → *Cron Jobs* → *Add New Cron Job*. Set the schedule to **once per minute**
(`* * * * *`, the "Once Per Minute" preset) and use ONE of the two commands below.

**Option A - CLI (preferred, no key needed):**

```
* * * * * /usr/local/bin/php /home/USER/public_html/trader/cron.php >/dev/null 2>&1
```

Replace `USER` with your cPanel user name. The setup wizard and the Settings page print this line
with the real path already filled in. On some hosts the PHP binary is version-specific, e.g.
`/usr/local/bin/ea-php83` or `/opt/cpanel/ea-php83/root/usr/bin/php`; the *Cron Jobs* page or your
host's documentation tells you which one to use. `php -v` in cPanel's *Terminal* also shows it.

**Option B - HTTP trigger (when CLI cron is not available, or from an external cron service):**

```
* * * * * curl -s -H "X-Cron-Key: YOUR_CRON_KEY" https://yourdomain.com/trader/cron.php >/dev/null 2>&1
```

`YOUR_CRON_KEY` is the 64-character key shown in Settings (it is also accepted as
`?key=YOUR_CRON_KEY`, but the header keeps the key out of web-server logs). Without a valid key the
endpoint answers `403 forbidden`.

Notes:

* `cron.php` prints one line per run, e.g. `ok equity 9.9935 USDT | free 3.5000 | position none |
  decision no_new_candle (412 ms)`. `>/dev/null 2>&1` keeps cPanel from mailing you every minute;
  remove it temporarily if you need to see the output.
* A run that starts less than 50 seconds after the previous one is refused with `too soon`, and a
  file lock (`data/tick.lock`) makes sure two ticks never overlap. Running the cron more often than
  once a minute is therefore harmless but pointless.
* The tick never runs before the setup wizard has been completed (`setup incomplete`).
* Exits (stop-loss, take-profit, trailing, time stop, kill switch) are managed on every tick even
  while the bot is paused; only **new entries** need the master switch to be on.

---

## Binance API key setup

You do not need keys for paper mode. For demo, testnet and live:

1. **Demo Trading (recommended practice environment).** From your Binance account open
   **Demo Trading**, then *API Key Management* at
   <https://demo.binance.com/en/my/settings/api-management>, and create an HMAC key. Tick
   **Enable Reading** and **Enable Spot & Margin Trading**. Copy both the key and the secret
   before leaving the page, because the secret is shown only once. Paste them into Settings and
   set mode to **demo**. Demo balances are fake, you reset them yourself from the Binance UI, and
   prices mirror the live book.
2. **Testnet (the older alternative).** Go to <https://testnet.binance.vision>, log in with GitHub,
   *Generate HMAC_SHA256 Key*, and set mode to **testnet**. Testnet balances reset roughly monthly.

   Demo and testnet are **separate environments with separate keys and separate hosts**. A demo key
   sent to testnet, or a testnet key sent to live, is rejected with
   `-2015 Invalid API-key, IP, or permissions for action`. Match the mode to where the key came from:

   | Mode | Host the bot calls | Where the key comes from |
   |---|---|---|
   | `demo` | `https://demo-api.binance.com` | demo.binance.com |
   | `testnet` | `https://testnet.binance.vision` | testnet.binance.vision |
   | `live` | `https://api.binance.com` | binance.com API Management |

3. **Live key.** Binance → profile → *API Management* → *Create API* (system generated).
   Then, on the key's permissions:
   * tick **Enable Spot & Margin Trading** - this is the only permission the bot needs;
   * leave **Enable Withdrawals** OFF (the bot never withdraws, and a leaked key must not be able to);
   * leave Futures, Margin loans, Universal Transfer and everything else OFF;
   * choose **Restrict access to trusted IPs only** and add your server's **outbound** IP.
     That IP is often not the one shown in cPanel's sidebar (shared hosts use a separate outgoing
     address). Find it with the *Terminal* in cPanel: `curl -s https://api.ipify.org`, or with a
     one-off cron job `curl -s https://api.ipify.org > /home/USER/myip.txt`. Binance also prints
     the offending IP inside the `-2015` error message, which the panel shows after
     "Test API connection".
3. Paste the key and secret into Settings (the secret is write-only: it is stored in
   `data/config.json` with mode `0600` and never rendered back; the panel only shows a fingerprint
   of the key). Press **Test API connection** - it calls the account endpoint and a test order on
   the first watchlist symbol without placing anything.
4. Switching the mode to `live` requires ticking "I understand this trades real money".
5. Keep the account small: transfer only what you are prepared to lose. The bot only ever sees the
   spot wallet of that key.

---

## Engines: signal and scalp

The bot runs exactly one **engine** at a time, chosen with the `engine` setting.

| engine | what it does | orders | earns from | loses when |
|---|---|---|---|---|
| `signal` (default) | one position at a time, entries only on a closed 15m candle | market | mean reversion inside an uptrend | the setup keeps failing (the survival layer bounds it) |
| `scalp` | 20 coins at once, ~16 decision points a minute, tiny market orders, a budget per coin | market | tiny mean-reversion bounces | fees, mostly - which is why it measures and shows gross vs net (see below) |

### What was removed, and why

Earlier versions also had a `grid` engine (a ladder of resting limit orders), a `pmm` engine
(pure market making) and **portfolio mode**, which split the account into per-engine "sleeves"
and ranked pairs with a volatility scanner. All of it is gone.

* **pmm could not work at this fee tier.** Binance charges 0.1 % maker, identical to taker, so a
  round trip costs ~0.2 % while spreads on the majors are 0.01 - 0.05 %. It was structurally
  unprofitable, and no amount of tuning changed that.
* **grid** earned while a market ranged and bled while it trended, which made it a bet on market
  regime rather than on anything the bot could measure.
* **portfolio mode quietly took the tick over from whichever engine was selected.** A bot set to
  `scalp` with `portfolio_enabled` left on would run the sleeves and never the scalp loop - the
  panel showed a healthy scalp card that never opened a trade. Its per-engine budgets are
  superseded by the scalp engine's own per-symbol budgets, which do the same job better.

**If your `data/config.json` still says `grid` or `pmm`**, the bot rewrites it to `signal` on the
next load and the dashboard shows an **Engine changed** notice saying so - it does not switch
silently. Orders those engines left resting on Binance are NOT abandoned: the same notice carries
a **Cancel leftover orders** button, because an order resting on the exchange does not care that
the code that placed it is gone. Coins they already bought stay in your wallet; sell those by
hand, or the survival layer will halt on `untracked_position`.

### The demo-only rule

The scalp engine places **no order at all** in live mode unless `allow_live_engines` is explicitly
true. The tick reports `engine_live_blocked` and the panel says so. This is deliberate: the fee
arithmetic below says the engine needs roughly a 76 % win rate just to break even, and that claim
should be tested on demo with real numbers before any real money is involved.

### Engine settings

| key | default | notes |
|---|---|---|
| `engine` | `signal` | `signal` or `scalp` |
| `allow_live_engines` | `false` | must be on before the scalp engine may trade in live mode |

Everything else the scalp engine needs is in its own section below.


## Scalp: many coins, many trades, one pot per coin

`engine = scalp` is the fourth engine. It is the only one that trades **many symbols at once, many
times a minute, in tiny size**, and it is the one to pick if what you want is the question *"which
coin actually makes money?"* answered with numbers instead of opinions.

It is **demo-only**: in `live` mode it places **zero orders** unless
`allow_live_engines` is explicitly turned on. Leave it off.

### What it actually does

One cron tick runs a **bounded loop** instead of a single pass, which is how you get sub-minute
trading out of a host whose cron only fires once a minute:

* `scalp_run_seconds` (50) of looping, polling every `scalp_poll_sec` (3) → **~16 decision points
  per minute**, every minute, across the whole universe.
* Each poll makes **one** market-data call — `GET /api/v3/ticker/bookTicker` with no symbol, which
  returns bid and ask for every symbol at once. 20 polls a minute is ≤ 80 weight against a
  6000/min limit, so the universe can grow without re-architecting.
* No candles. The loop reads the book and nothing else.
* Per symbol it keeps an in-memory ring buffer of `scalp_window` mid prices. Nothing is written per
  tick — only entries, exits and the run summary.

**Entry** (long only). For a symbol with no open position, all of these: the window is full; the
price has **pulled back** `scalp_dip_pct` (0.15 %) from the window high; the **last two ticks are
rising** (so one up-tick inside a slide is not mistaken for a bounce); the spread is inside
`scalp_max_spread_pct`; fewer than `scalp_max_open` positions are open; and the symbol's own budget
can fund the order.

**Exit**, checked every poll: take profit at `scalp_tp_pct` (0.25 %), stop at `scalp_sl_pct`
(0.20 %), or max hold at `scalp_max_hold_sec` (120 s). All three are measured **on the bid**, against
`entry_eff` — the dust-excluded cost basis that already carries the buy fee — because the bid is
what a sale actually receives. Measuring a target on the ask, or against the raw fill, books profit
the round trip never produced.

**An open position is always managed.** Exits run first on every poll and are never skipped: not
when entries are paused, not when the loss breaker has tripped, not when `enabled` is off, not under
the equity floor, and not when another engine has taken over the tick. Abandoning a position would
be worse than any losing streak.

### The movers board

`scalp_universe_size` (20) symbols, chosen by a board that answers *"the coins with the most movement
and the most traders"*:

```
score = 0.45·move + 0.35·trade count + 0.20·liquidity − spread penalty
```

Each term is normalised 0–1 across the surviving candidates, so no term can dominate just because of
its units. **Move** is the intraday range `(high − low) / last`, not the 24 h change percent — a coin
that rose 5 % and then sat still has nothing in it for a round trip, and `change_pct` cannot tell the
difference.

The board is a **weight-80** call, so it refreshes at most once per `scalp_universe_refresh_min`
(10 min) and **never from inside the fast loop**. Candidates are filtered on quote asset, `TRADING`
status, spot-trading allowed, not a leveraged token, not in `scalp_exclude` (stablecoin pairs),
24 h quote volume ≥ `scalp_min_quote_vol`, spread ≤ `scalp_max_spread_pct`, and the dust rule
`stepSize × price ≤ 0.3 % of scalp_order_usdt`.

Rows that fail a gate are **kept with the reason**, not silently dropped, so the panel can tell you
*why* a coin you expected to see is not being traded. A symbol holding an open position is **pinned**
into the universe until it closes, even if it falls out of the ranking — a position can never be
stranded outside the universe it is managed from.

### A budget per coin, so you can see which one makes money

This is the point of the whole exercise. **Every symbol trades its own pot.**

* The cap is `scalp_budgets[SYMBOL]` when you set one, otherwise `scalp_budget_per_symbol` —
  **100 USDT by default**. Set per-coin overrides as JSON: `{"BTCUSDT":200,"DOGEUSDT":50}`.
* The ledger is **derived from the `positions` rows every time**, never kept as a running total, so
  it cannot drift out of step with the trades it describes:

  | field | meaning |
  |---|---|
  | `budget` | the cap |
  | `deployed` | `entry_quote` of the symbol's open positions |
  | `available` | `max(0, budget + realised net − deployed)` |
  | `gross`, `fees`, `net` | realised, summed over its closed positions |
  | `trades`, `wins`, `losses`, `win_rate` | the sample size, always beside the rate |

* **Enforcement:** an entry is refused when `scalp_order_usdt > available` for that symbol, with the
  reason `symbol_budget`. **A coin that loses its pot stops trading on its own while the others carry
  on.** That is the per-symbol equivalent of the loss breaker, and it is what stops one bad coin
  draining the whole run. Exits are *never* budget-blocked — selling returns capital.
* With 20 symbols at 100 each, the budgets allocate 2000 USDT. The sum is checked against your free
  balance when you save, as a **warning, not an error**: each cap is a ceiling, and not every symbol
  is deployed at once.

The **scoreboard** card is the one you will actually read: one row per symbol, sorted by net, showing
budget, deployed, trades, win rate *with its sample size*, gross, fees, net, and net as a percent of
that coin's budget.

### The pause after N consecutive losses

* `scalp_max_consecutive_losses` (5, range 2–20). A closed position with net P&L **< 0** increments
  the counter; **any win resets it to 0**.
* On reaching the limit the engine sets `scalp_paused`, writes a reason naming the streak, the money
  lost over it and the time, logs at warn, and **stops opening new positions**. Open positions keep
  being managed to their exit.
* The streak counts **only this engine's own closes**. Another engine's losing trade does not pad it.
* **It does not clear itself.** The dashboard shows a banner with the streak and a **Resume scalping**
  button (POST + CSRF). That button is the only thing that lifts the pause — it resets the counter to
  0 and allows entries from the next tick. That is the "pause and ask me" behaviour.
* If a tick happens to be running when you press it, the panel tells you so and the engine **stays
  paused** — it never reports a resume that did not happen.
* This is **additional to**, not instead of, the global survival layer: the equity floor, the drawdown
  kill switch and the daily and weekly loss caps all still apply and all still win.

### Gross, fees, net — and why 0.25 % against 0.2 % fees is thin

The panel never shows net alone. It shows all three, with the trade count beside them:

```
Gross P&L (before fees)   +1.42 USDT     ← is there ANY directional edge?
Fees paid                 −3.10 USDT     ← what it cost to find out
Net P&L                   −1.68 USDT     ← the answer
```

Separating them is the whole point. **Gross positive with net negative** means the signal works and
the fees eat it — a fee problem. **Gross negative** means there is no edge at all, and no fee tier
would save it.

Now the arithmetic, which you should read before you run this for a week:

* Binance charges **0.1 % per side** at VIP0. A round trip is **0.2 % of the price**.
* The target is measured on the **bid against `entry_eff`**, which already carries the *buy* fee. So
  against that reference the sell still owes **0.1 %**. Break-even is 0.1 %, not 0.2 %.
* A win at `scalp_tp_pct` 0.25 % therefore nets about **+0.15 %**. A loss at `scalp_sl_pct` 0.20 %
  costs about **−0.30 %** once the sell fee is added.
* Lose 0.30 to win 0.15 and you need a **~67 % win rate just to break even**, before slippage. The
  panel states this number for your actual settings.
* On a 6 USDT order, +0.15 % is **0.9 of a cent**. The fee on that round trip is about 1.2 cents.

`Risk::validateConfig()` says this out loud when you save: it bounds every key, and it warns — never
silently — when `scalp_tp_pct` is below the round trip or at/below the fee still owed on the sell.
It is a **warning, not an error**, because measuring a sub-break-even target on demo is a legitimate
experiment and the reason this engine exists. It saves, and it tells you what it costs.

**A measured run.** Driving the real loop over a deterministic scripted path across 20 symbols for
the equivalent of ~25 minutes of market (60 closed round trips, paper mode, 0.1 % fees):

```
closed round trips      60
Gross P&L (before fees) -0.0239 USDT      ← no directional edge, either way
Fees paid               -0.7196 USDT      ← 30x the gross, per trade
Net P&L                 -0.7436 USDT
win rate                28.3% (17 of 60)
```

Repeated on three different price paths the gross ranged from −0.02 to −0.45 USDT while the fees were
a flat −0.72 every time. **Fees were the dominant term in every run.** Scripted prices are not a
market and 60 trades prove nothing about a real one — but they do show the shape of the thing: the
fee line is predictable and the edge is not.

### A default you must change before it will trade at all

The ring buffer lives in memory for the life of one `Scalper`, and cron builds a **fresh one every
minute**. So a window of `W` ticks at `scalp_poll_sec` `P` needs `W × P` seconds of book, out of the
`floor(scalp_run_seconds / P)` polls one tick affords.

The shipped defaults are `scalp_window` **20**, `scalp_poll_sec` **3**, `scalp_run_seconds` **50** —
that is a 60-second window against **16 polls**. The window never fills, every poll is refused
`window_warming`, and **the engine places no order at all** while looking perfectly healthy.

Set `scalp_window` to **8** (or anything up to 12) and it trades. The settings page warns about this
with the arithmetic when you save, so you do not have to rediscover it.

### Configuration

| key | default | notes |
|---|---|---|
| `scalp_universe_size` | `20` | symbols traded at once; 1–30 |
| `scalp_order_usdt` | `6.0` | per position; must clear the exchange's minimum notional |
| `scalp_budget_per_symbol` | `100.0` | the default cap per coin |
| `scalp_budgets` | `{}` | per-coin overrides, e.g. `{"BTCUSDT":200,"DOGEUSDT":50}` |
| `scalp_max_open` | `6` | concurrent positions, at most one per symbol |
| `scalp_tp_pct` | `0.25` | take profit, on the bid against `entry_eff` |
| `scalp_sl_pct` | `0.20` | stop, same basis |
| `scalp_max_hold_sec` | `120` | time exit |
| `scalp_dip_pct` | `0.15` | pullback needed before a bounce entry |
| `scalp_window` | `20` | ticks held per symbol — **see the section above** |
| `scalp_poll_sec` | `3` | seconds between polls |
| `scalp_run_seconds` | `50` | loop length inside one cron tick (hard ceiling 55) |
| `scalp_max_consecutive_losses` | `5` | then pause and wait for you |
| `scalp_max_trades_per_hour` | `60` | runaway guard; counts entries |
| `scalp_min_quote_vol` | `20000000` | 24 h quote volume floor |
| `scalp_max_spread_pct` | `0.05` | |
| `scalp_universe_refresh_min` | `10` | how often the weight-80 board refreshes |
| `scalp_exclude` | stablecoin pairs | never ranked |

### What to expect on the dashboard

* a **movers card** — the top 10 with rank, last price, 24 h change, intraday range, **trade count**,
  quote volume, spread, required size, score, whether it is in the universe, and any gate it failed;
* a **scalp card** — running or paused, the loss streak against its limit, open positions with age
  and unrealised percent, entries this hour against the cap, polls in the last run, the last order
  error with its age, and the Gross / Fees / Net block with the trade count;
* the **per-symbol scoreboard**, sorted by net;
* a **pause banner** with the Resume button whenever the breaker has tripped;
* `?api=status` carries `scalp` and `movers` blocks with the same numbers.

The design contract for all of it is `docs/DESIGN-SCALP.md`.

---

## Learning: what the bot records, and what it may change

Learning here is **honest statistics over the bot's own history**, not a model and not alpha. Every
entry evaluation writes one row to a new `observations` table - the ones it took *and* the ones it
refused, because the refusals are the control group - recording the conditions at that moment (RSI,
ATR on 15m and 1h, position in the Bollinger band, volume ratio, the 1h regime, spread, hour, day of
week, step-value coarseness) and, when the position or cycle closes, the outcome that followed. A
skipped evaluation resolves immediately to `not_taken`: it carries no PnL and is never counted as a
win or a loss.

Capture costs **no extra Binance requests**: it reads the candles, book and symbol filters the tick
already fetched, and writes one small row per evaluated symbol. That is why `learning_enabled` is on
by default, and a test asserts the same run with capture on and off makes the same calls, the same
trades and the same equity curve.

**Three rules keep it from being dangerous.**

1. **Learning may never increase risk.** It can adjust the score-component weights (`rsi`, `bb`,
   `reversal`, `rsi_up`, `vol`) and the effective entry threshold. It cannot touch position size,
   stop-loss, take-profit, the scalp budgets or any kill switch - not "we don't call that", but
   structurally: `lib/Learn.php` writes three `state` keys and nothing else, and never loads the
   config writer at all. The `learn-caps` test snapshots the whole configuration and database,
   runs a recompute on evidence engineered to demand an enormous change, and asserts that the only
   thing that moved was one weight. `learn-caps-repeat` then proves repetition cannot launder a
   bounded step into an unbounded one: thirty recomputes in a row against 98 %-vs-2 % evidence
   worth thousands of USDT, re-checking the whole config and the whole `state` table after **every
   single run**, including ten with every component already pinned at its cap and nowhere left to
   put the next point.
2. **Evidence before action.** A claim needs `learn_min_samples` (60) **decided** outcomes - wins
   and losses, never rows - in each of two buckets, *and* non-overlapping 95 % Wilson confidence
   intervals. A lucky five-trade streak moves nothing; nor do 110 break-even trades, which swell
   the row count without deciding anything. Each delta is capped at ±10 points and at the
   component's own base value (a component can be neutralised, never inverted), at most **one**
   component changes per recompute, and the effective threshold stays inside
   `[entry_threshold − 10, 100]`.
3. **Never train on what it then trades.** A recompute reads only trades **closed before** it runs,
   refuses to re-run inside `learn_recompute_hours`, and stamps what it saw, so the panel can say
   which trades were evidence and which were the out-of-sample test.

`learning_apply` is **off** by default: the Insights page shows exactly what a recompute *would* do
and writes nothing. That is how you build trust before letting it act - and at a few trades a day
the honest answer will be "not enough data yet" for weeks. Recomputes are operator-triggered
(**Recompute now** on the Insights page); nothing changes a weight behind your back.

**What it cannot conclude.** It cannot tell you that a condition *causes* profit, only that trades
carrying it have so far done better than trades that did not. It cannot see anything it never
traded: a skipped evaluation has no outcome, so the control group tells you what the gates refused,
never what refusing cost you. It cannot separate a real edge from a regime that happened to last
three weeks - the window is 90 days by default and markets change faster than that. And it cannot
manufacture significance: the buckets are fixed in advance (so they cannot be drawn around whatever
looks good), the reported pair is the strongest of a whole scan (so the threshold is widened for
the number of comparisons that scan could have made), and a claim that only clears a plain 95 %
because it was the best of thirty-eight is reported as **inconclusive**, not as a finding.

Arithmetic, honestly: at 3 trades a day, `learn_min_samples = 60` decided outcomes **in each of two
buckets** is 120 trades, of which a bucket typically holds a fraction - so six to ten weeks before
the first claim is even possible, and longer before it is stable. There is no way to shorten that
except by trading more, which is the opposite of what the risk layer is for.

| key | default | notes |
|---|---|---|
| `learning_enabled` | `true` | capture observations and compute insights (no extra API calls) |
| `learning_apply` | `false` | actually feed the adjustments back into scoring |
| `learn_min_samples` | `60` | per bucket, before any claim is called confident |
| `learn_recompute_hours` | `168` | minimum gap between recomputes |
| `learn_window_days` | `90` | how far back evidence is drawn |
| `bnb_min_balance` | `1.0` | warn when BNB fee burn is on but the balance is below this |

Observations are retained for **365 days** (`Db::prune()`), much longer than the 30-day retention of
signals, equity and logs: a 90-day evidence window is worthless once the rows behind it have been
deleted. Beyond that the table is pruned like everything else, so it cannot grow without bound.

### Reading the Insights page

`?page=insights`, behind the panel login. Top to bottom:

* **The header sentence.** The one line that matters. It is either "Nothing has been captured yet",
  "Not enough data yet" with the count still needed, or "N resolved trades are in - enough for a
  claim to be possible". It never says more than the sample supports.
* **The counts.** Observations in the window split into entered and skipped; resolved outcomes split
  into win / loss / flat / not-taken / **still open**; the win-loss total to reason from; and how
  many more are needed. *Still open* matters: an open trade has no outcome, so it is excluded from
  every figure - and that exclusion is one-sided, because a winner takes profit and closes while a
  loser rides. Every number here is a count, never an extrapolation.
* **Evidence by engine.** Which engines the window's rows came from. The condition cards pool
  **every** engine into one win rate, while the weight suggestions read **signal-engine rows only** -
  so a window dominated by one coin's fills can produce a confident-looking card about conditions the
  weights will never act on. The row says so in as many words.
* **Current weights.** Each score component's base points, its learned delta, the resulting points,
  and the cap that applies (±10, and never past the component's own base). Plus when the last
  recompute ran, what it changed and on what evidence, whether `learning_apply` is on, and what a
  recompute *would* do right now.
* **Skipped vs entered.** The conditions the bot most often refused, so you can see whether the
  gates are too tight. These averages describe what was refused; they are **not** evidence that
  entering those setups would have paid.
* **One card per condition.** Confident cards are open, the rest start collapsed. Each holds a
  bucket table - bucket, `n`, **Still open**, win rate, its 95 % confidence interval, average PnL,
  total PnL, and whether the bucket clears `learn_min_samples` - plus the separation figure and a
  plain-language note. Buckets are fixed in advance and an empty bucket is shown rather than
  dropped: "no evidence here" is information too.
* **The state pill** on each card is `CONFIDENT`, `INCONCLUSIVE` or `NO DATA`. `CONFIDENT` means
  both buckets cleared the sample floor **and** their intervals stayed disjoint after the threshold
  was widened for every bucket comparison the scan could have reported. If a note says the intervals
  separate at 95 % on their own but not once widened, that is the page telling you the finding is
  the best of many draws and could still be luck.
* **Recompute now** (POST + CSRF) runs `Learn::recompute()` and reports its decision - including
  "nothing changed, and here is why", which is the usual answer.

The dashboard carries one line of this: the strongest confident insight with its sample size, or
"not enough data yet".

### BNB fee discount

The API health card shows whether **BNB fee burn** is on, the account's free BNB and the effective
round-trip cost (0.15 % with the discount, 0.2 % without), with a POST + CSRF toggle. If your host
does not serve `/sapi`, the panel says so and points you at the Binance UI - that is a limitation,
not an error. When burn is on and the BNB balance falls below `bnb_min_balance`, Binance silently
goes back to charging the received asset, which changes both the fee and the dust behaviour
mid-run; the panel warns about exactly that. `fee_pct` remains informational only - the live taker
rate comes from the account.

---

## Recommended rollout

0. **Pick the engine first.** The steps below describe the signal engine. `scalp` has its own
   section above and is **demo-only by default** - `allow_live_engines` must be switched on
   before it will place a single live order, and you should not switch it on until the
   Gross / Fees / Net card has told you whether there is an edge at all.
1. **Paper for 2 - 4 weeks.** Fills are simulated at the live bid/ask with the configured fee, so
   the numbers are realistic. Watch: win rate, expectancy per trade, total fees versus total PnL,
   the no-trade-reasons histogram (if it is all `atr_low` or `size_unaffordable` your watchlist or
   sizing is wrong), and whether any position ends up `STUCK`.
2. **Demo or testnet for 1 - 2 weeks.** Same strategy, real HTTP calls, real signing, real filters
   (`LOT_SIZE`, `NOTIONAL`), real error codes. This shakes out key/IP/permission problems and time
   drift (`-1021`) before any money is involved.
3. **Live with a tiny size.** 10 - 20 USDT, `trade_usdt` 6.5, the default caps. Check the dashboard
   once a day for the first weeks. If the adaptive rule disables trading (`adaptive_stop`), the
   strategy is not working in the current market - do not just re-enable it.

---

## How the strategy works (plain words)

Every minute the bot checks whether a new **15-minute candle** has closed for each watchlist
symbol. Only then does it evaluate that symbol (so at most four evaluations per symbol per hour, and
the same candle is never traded twice).

**Gates** - any one of these makes the symbol ineligible for this candle, no matter the score:

* `trend_down` - on the 1-hour chart the 50-EMA is below the 200-EMA and falling, or price is under
  the 200-EMA with the 50 below the 200. We only buy dips inside an uptrend or a flat market.
* `crash_guard` - the last four 1-hour candles lost more than 3 % together. Not catching knives.
* `atr_low` / `atr_high` - 15-minute volatility (ATR14 / price) outside 0.30 % ... 1.5 %. Too quiet
  cannot pay the fees, too wild blows through the stop.
* `atr1h_low` / `atr1h_high` - same on the 1-hour chart (0.5 % ... 3 %).
* `spread_wide` - bid/ask spread above 0.05 %.
* `size_unaffordable` - the position we could afford would not survive the minimum-notional rule on
  the way out (see the dust section).
* `data_short` - not enough candles yet.

**Score (0 - 100)** on the last closed 15-minute candle:

| condition | points |
|---|---|
| RSI14 at or below 30 | +20 (another +10 at or below 25) |
| close at or below the lower Bollinger band (20, 2) | +20 (another +10 for a deep pierce) |
| reversal candle: low below the band and close above open | +20 |
| RSI turning up versus the previous candle | +20 |
| volume at least 1.2 x its 20-candle average | +20 |
| RSI at or above 70, or close at or above the upper band | -40 |

The best eligible symbol with a score at or above the **entry threshold** (60 by default) is
bought with a market order for `trade_usdt` (or 65 % of the free USDT, whichever is smaller).
One order per tick, one position at a time.

**Exits**, evaluated on the bid against the *effective* entry price (what you actually paid per
sellable unit, fees and dust included):

* stop-loss 0.7 % below entry (`stop_loss_pct`);
* take-profit at 1.5 x the 15-minute ATR, clamped to 1 % ... 2 %;
* trailing stop: once the position is up 0.6 %, the stop follows the high at a 0.4 % distance and
  never drops below entry + 0.25 %;
* time stop after 240 minutes;
* signal exit when RSI goes above 70 or the bid touches the upper band - but only if the position
  is in profit (at least +0.3 %). A losing position is never closed on a signal, only by the stop.

**Adaptive rule** (`adaptive` = on): after at least 20 closed trades, at most once per day, looking
at the last 20 trades: win rate under 40 % or negative expectancy → threshold +20, allowed trades per
day -1; win rate over 60 % with positive expectancy → threshold -20 (never below your setting),
trades per day +1 (never above 4). If the threshold is already 100 and the next 20 trades are still
negative, the bot switches itself off (`adaptive_stop`). It never touches stop, target or size.

---

## How the survival layer works (plain words)

It runs **before** the strategy on every tick, and most of it does not care what the strategy says.

* **Equity floor kill switch** (`equity_floor_usdt`, default 7): if total equity (USDT + coins at
  market value) is at or below the floor, any open position is sold, the bot is **halted** and new
  entries stay off until you press *Reset halt* in the panel. Set the floor to the amount you are
  not willing to go below.
* **Drawdown kill switch** (`hwm_drawdown_pct`, default 20): equity more than 20 % below its
  highest value ever → same as the floor.
* **Daily loss cap** (2 % of the equity at the start of the UTC day) and **weekly loss cap**
  (5 %, rolling 7 days) stop new entries for the rest of the day / for 7 days.
* **Cooldown ladder**: after one losing trade no entries for 45 minutes; after two consecutive
  losses 3 hours; after three, nothing until the next UTC day.
* **Trade caps**: at most 3 entries per UTC day (the adaptive rule can move this between 1 and 4)
  and at most 2 orders per hour (a bug guard - it counts every order, buys and sells).
* **A stop on every position** - there is no way to open a position without a stop price.
* **Dust-aware sizing** - explained below.
* **Order idempotency**: every order gets a deterministic client id (`b-SOLUSDT-<minute>`) and is
  written to the database as `SENDING` before it is sent. If the tick dies or the request times out,
  the next tick asks Binance what happened to that id instead of sending it again.
* **Reconciliation**: on every tick the bot compares its records with the exchange. A coin balance
  worth more than the minimum notional that the bot does not know about → halt (`untracked_position`,
  someone traded manually or something went wrong). A tracked position whose coins are gone → closed
  as `reconciled_missing`.
* **API error policy**: rate limits pause the bot for the time Binance asks for; an invalid key/IP
  (`-2015`) disables trading; three network errors in a row pause it for 15 minutes; clock drift is
  fixed automatically.

---

## Minimum notional, step size and dust - why BTCUSDT is out on a 10 USDT account

Binance enforces two filters on every spot order:

* **LOT_SIZE `stepSize`** - the quantity must be a multiple of the step (SOLUSDT: 0.001 SOL,
  DOGEUSDT: 1 DOGE, BTCUSDT: 0.00001 BTC).
* **NOTIONAL `minNotional`** - quantity x price must be at least 5 USDT on most USDT pairs
  (1 USDT on DOGEUSDT).

When you **buy** with a market order, the 0.1 % taker fee is taken **in the coin you bought**
(unless you hold BNB and have the BNB discount on). So a 6.5 USDT buy of SOL at 129.80 gives
0.050077 SOL gross, minus 0.000050 SOL fee = 0.050027 SOL. Only multiples of 0.001 can be sold:
**0.050 SOL is sellable, 0.000027 SOL is dust**. Dust is not lost - it stays in the wallet, is
counted in your equity at market value, is shown separately in the panel and is swept up by a later
sell of the same coin (the bot always sells the whole floored free balance) - but it means your
effective entry price is slightly higher than the fill price. Every stop and target is measured on
that effective price, which is why `entry_eff` is what the panel shows.

The sell has to pass the notional filter too, **after** an adverse move. So the bot only enters if

```
required_size = (minNotional x 1.15 + stepSize x price) / (1 - fee)
```

fits in what it can spend (`trade_usdt`, capped at 65 % of the free USDT, and it keeps 0.5 USDT
free). Examples with a 10 USDT account (`trade_usdt` 6.5):

| symbol | stepSize | step value | required size | verdict |
|---|---|---|---|---|
| SOLUSDT at 130 | 0.001 | 0.13 USDT | (5.75 + 0.13) / 0.999 = **5.89 USDT** | ok |
| DOGEUSDT at 0.20 | 1 | 0.20 USDT | (1.15 + 0.20) / 0.999 = **1.35 USDT** | ok |
| BTCUSDT at 110 000 | 0.00001 | 1.10 USDT | (5.75 + 1.10) / 0.999 = **6.86 USDT** | **not affordable** |

### Minimum viable balance

An entry may only ever spend **65 % of the free USDT**, so a symbol whose required size is `R`
needs `R / 0.65` sitting free before it can be traded at all. That is about **9.1 USDT free**
for a 5 USDT-minNotional pair like SOLUSDT (5.89 / 0.65) and about **2.1 USDT** for DOGEUSDT
(1.35 / 0.65).

Below that band the bot keeps evaluating every tick but gates every symbol with
`size_unaffordable`; the panel's last no-trade reason reads
`size_unaffordable(quote_free=...)` and the Required column marks the symbol
`(needs ... free)`. It does **not** halt, because nothing is broken - the account is simply too
small to open a position that could also be closed safely.

Starting live at 10 USDT therefore leaves only about **9 % of headroom** (10 vs 9.1) before you
land in that band: two small losses, or one loss plus fees, and SOLUSDT-class pairs stop being
tradable until you top the account up. DOGEUSDT keeps working much further down.

With BTC above roughly 75 000 USDT one step is more than a dollar of dust, and 6.5 USDT is not
enough to guarantee that the sell still clears 5 USDT after a 0.7 % stop plus fees plus dust. The
panel shows the required size per symbol, and such a symbol is simply not eligible
(`size_unaffordable`). It is not a bug; put BTCUSDT back on the watchlist when the account is
bigger. The same reasoning is why the defaults keep 3.5 USDT uninvested: the fee on the sell is in
USDT, the next buy needs headroom, and a position that cannot be closed is the one thing the bot
must never create.

If a position does get stuck below the notional (a fast crash), it is marked `STUCK`, the panel
shows it in red and the bot retries the sell every 15 minutes while the price recovers. You can
always sell it by hand on Binance; the next reconciliation will notice.

---

## The panel

* **Dashboard**: status pills (mode; ENABLED / PAUSED / HALTED / COOLDOWN / API PAUSED; last tick
  age - red "cron not running" after 3 minutes), equity and PnL tiles, API health (clock offset,
  used request weight, last error), the open position with its live stop/target/trailing state, an
  equity sparkline, the watchlist table (filters, required size, ATR, spread, last score and gates),
  closed positions, a histogram of why no trade was taken in the last 24 hours, and the log tail.
* **With the scalp engine selected** the dashboard also shows a **Scalp engine** card (the
  Gross / Fees / Net verdict with its trade count and break-even win rate, the per-symbol
  scoreboard, and open positions with their age in seconds), a **Movers** card (the top 10 by
  intraday range and 24 h trade count, with the gate that rejected any row), and - once the
  consecutive-loss breaker trips - a **Scalping is paused** banner with its **Resume scalping**
  button.
* **Insights** (`?page=insights`): what the observations say. A header with the totals and, while
  the evidence is thin, a plain sentence saying no conclusion is available yet and roughly how many
  more resolved trades are needed; one card per condition with its bucket table (range, n, win rate
  *with* its confidence interval, average PnL) and a plain-language note; a **current weights** card
  (the learned deltas, their caps, when a recompute last ran, what it changed and on what evidence,
  and whether `learning_apply` is on); a **skipped vs entered** card showing what the gates refused
  most often; and the **Recompute now** action. The dashboard carries one line of it: the strongest
  confident insight with its sample size, or "not enough data yet".
* **Actions**: Start, Pause, Reset halt, Panic sell (sells at market and disables trading; needs a
  confirmation tick box), Run tick now, Reset paper account, Logout, and - on the API health card -
  the BNB fee-burn toggle and its read-only refresh.
* **Settings**: every parameter with validation (the take-profit must be at least three times the
  round-trip fee, the stop between 0.2 % and 5 %, symbols must end with the quote asset, ...), the
  cron command, the cron key, and "Test API connection". The **Scalp engine** section holds its
  own keys and states the fee arithmetic inline - the win rate the configured target and stop
  would need just to break even - so a guaranteed loss cannot be configured silently.
* The dashboard refreshes itself every 20 seconds through `index.php?api=status`.

Sessions expire after 30 minutes of inactivity; five wrong passwords lock the IP for 15 minutes.

---

## Troubleshooting

**`-2015 Invalid API-key, IP, or permissions for action`**
Nearly always the key and the mode do not match. Demo keys (from demo.binance.com) only work in
**demo** mode, testnet keys only in **testnet** mode, and real keys only in **live** mode, because
each mode talks to a different host - see the table in the key-setup section. Otherwise: the key is
wrong, the server's outbound IP is not in the key's IP whitelist, or "Enable Spot & Margin Trading"
is not ticked. The bot sets
`enabled=false` when it sees this. Fix the key in Binance, re-enter it in Settings, press "Test
API connection", then Start. The error text from Binance contains the IP it saw - use exactly
that one in the whitelist.

**`-1021 Timestamp for this request is outside of the recvWindow`**
The server clock is off. The bot measures the offset against Binance on every tick and retries once
with the corrected time, so a single `-1021` in the log is normal after a reboot. If it repeats on
every tick, the host's clock drifts badly: raise `recv_window` (max 60000) and ask your host to run
NTP.

**"cron not running" on the dashboard**
No tick was recorded for more than 3 minutes. Check in this order: the cron job exists and is set to
every minute; the PHP path in the command is right (`which php` or the path from *Cron Jobs*); remove
`>/dev/null 2>&1` for a few minutes so cPanel mails you the output; for the HTTP variant call the URL
in a browser with `?key=...` - `forbidden` means the key is wrong, `too soon` means it works.
`setup incomplete` means the wizard was never finished. Also look at `data/bot.log`.

**`database is locked` / `SQLSTATE[HY000]: General error: 5`**
SQLite could not get the write lock within 5 seconds. Usually a second tick overlapping with a slow
one (the lock file normally prevents this), a backup tool copying `data/` at that moment, or a
host that puts `public_html` on NFS. It is retried on the next tick. If it persists: make sure only
one cron job calls `cron.php`, and that the panel is not being hammered by several browser tabs.
`data/trader.sqlite-wal` and `-shm` are normal WAL side files - never delete them while the bot is
running.

**`-1013 Filter failure: LOT_SIZE / NOTIONAL`**
The exchange filters changed. The bot refreshes symbol info immediately and tries again on the next
candle. If a symbol keeps failing, its step or notional makes it unaffordable - drop it.

**`-2010 Account has insufficient balance`**
The exchange and the bot disagree about balances (a manual trade, a fee in BNB, dust). The bot
reconciles and logs; check the position card and the wallet on Binance.

**`HTTP 429` / `418` (rate limit / IP ban)**
The bot pauses itself for the time Binance requests (`api_paused_until` on the dashboard). One tick
per minute uses a few percent of the weight budget; something else on the same IP is probably
hitting Binance too.

**Panel shows "Internal error - see data/bot.log" or a blank page**
Open `data/bot.log` through the File Manager (it is not reachable over HTTP - that is on purpose).
Typical causes: an extension missing after a PHP version change (`pdo_sqlite`), or `data/` not
writable.

**Position `STUCK` (`stuck_notional`)**
See the dust section. It resolves itself when the price recovers; you can also sell the coin
manually.

**Halted (`untracked_position`)**
The wallet holds a watchlist coin worth more than the minimum notional that the bot did not buy.
Either sell it manually or remove that symbol from the watchlist, then *Reset halt*.

---

## Security checklist

* [ ] The panel is reachable only over **HTTPS** (`force_https` on; AutoSSL/Let's Encrypt working).
* [ ] `https://yourdomain.com/trader/data/` returns **403**; so do `/lib/`, `/tests/`, `/docs/`,
      `config.php` and `bootstrap.php`.
* [ ] Panel password of 12+ characters that you use nowhere else. Change it in Settings if in doubt.
* [ ] API key: **spot trading only, withdrawals OFF, IP-restricted** to the server's outbound IP.
* [ ] Demo, testnet and live keys never mixed up; `mode` checked before pressing Start.
* [ ] `data/config.json` is `0600` and `data/` is not world-writable (`750`).
* [ ] The cron key is only in the cron command, never in a bookmark or a chat message; rotate it in
      Settings if it leaks (the HTTP trigger uses a constant-time comparison and answers 403 otherwise).
* [ ] cPanel account itself has a strong password and two-factor authentication.
* [ ] No `phpinfo()` or backup copies (`trader_old/`, `config.php.bak`) lying around in
      `public_html`.
* [ ] `.git/` is not uploaded to the server (the `.htaccess` blocks it anyway).
* [ ] Only the amount you can afford to lose is on the exchange account the key belongs to.
* [ ] You looked at `data/bot.log` at least once and know where the *Panic sell* button is.

---

## Running the tests

```
php tests/run.php        # one line per assertion, exit code = number of failures
php tests/run.php -q     # failures and summary only
```

The suite is fully offline: it uses `tests/FakeMarketData.php` and the synthetic candle fixtures in
`tests/fixtures/` (their fixed "server time" is 2026-09-03T12:05:00Z), writes everything to a
temporary directory and never touches `data/` or the network. It covers decimal/step maths,
indicators, the strategy on oversold / overbought / downtrend series, the survival layer, paper
fills, and a complete tick sequence (entry, no double entry on one candle, take-profit, stop-loss,
kill switch, order reconciliation after a crash or a timeout).

The scalp engine is covered by the same run: `scalp-movers` (deterministic ranking on a fixture;
stablecoins and leveraged tokens dropped; a thin-trade or wide-spread coin gated rather than
dropped; a 5 % one-way drift scoring below an 8 % intraday range, which is the whole point of
scoring range and not 24 h change; a symbol holding a position pinned into the universe even when
gated; and a feed carrying no trade counts failing OPEN rather than gating the entire exchange),
`scalp-entry` and `scalp-exit` (every entry condition and all three exits, measured on the bid
against `entry_eff` - including the case that is +0.25 % on the raw fill but only +0.15 % on
`entry_eff` and must NOT book a profit the round trip never made), `scalp-pnl` (gross, fees and
net reconcile per trade and over the run, and gross is never silently equal to net),
`scalp-guard` (demo-only, the hourly cap, the loop's time budget) and `scalp-config` (the
break-even warning is a warning and not an error, the bounds hold, an unparseable budget map is
an error rather than a silent reset).

Two groups cover the things that were asked for by name. `scalp-breaker` drives real fills on a
real paper exchange until three closes lose in a row, then asserts the engine paused itself, that
a paused poll opens **zero** positions, that the pause survives a new engine instance, that the
global kill switch still overrides, and that any win resets the counter. `scalp-budget` asserts
that `available` is never negative or NaN, that one symbol losing its whole pot leaves every other
symbol untouched, and - the part that matters - gives two symbols the same price path, the same
window and the same poll, differing only in budget, so that the one with money trades and the one
without does not.

Every gate is tested with a **control** proving the same code path DOES trade when the gate is
lifted. Without that, "0 orders placed" would be indistinguishable from a test that never reached
an entry in the first place.

Learning adds ten more: `learn-wilson` (the interval matches known values; k=0 and k=n do not
divide by zero), `learn-buckets` (edges are inclusive-low / exclusive-high, empty buckets are
reported rather than dropped), `learn-capture` (an entry and a skip each write exactly one
observation, a close resolves it with the right outcome and PnL, a skip resolves to `not_taken`,
and no observation is ever written or resolved twice), `learn-evidence` (below `learn_min_samples`
nothing is confident and no adjustment is produced; a lucky five-trade streak produces none; a
strong, well-sampled separation produces exactly one bounded adjustment), `learn-walkforward` (a
recompute sees only trades closed before it and refuses to re-fire inside `learn_recompute_hours`),
`learn-apply-off` (with `learning_apply` false the scorer returns byte-identical results, so every
existing strategy test stays valid), `learn-neutral` (the same four-tick run with capture on and
off makes the same market-data calls and produces the same trades, signals, equity and state - the
proof that `learning_enabled = true` is a safe default) and, above all, `learn-caps`: the safety
test that snapshots the entire configuration and every table, runs a recompute on evidence
engineered to demand an enormous change, and asserts that position size, take-profit, stop-loss,
every scalp budget and every kill-switch key came back byte-identical, that the only state keys
written were the learning ones, and that the deltas obey ±10, one component per run and the
threshold range.

Two more groups close the gaps that mattered most. `learn-caps-repeat` is the safety proof run
thirty times: it snapshots the whole config and the whole `state` table, then recomputes ten times
against 98 %-vs-2 % evidence worth thousands of USDT, ten more with every component already pinned
at its cap, and ten more back-to-back at the same instant - re-checking after **every single run**
that the only state keys that moved were `learn_weights`, `learn_at` and the learn log, that no
weight ever escaped ±10 or its own base value, that no table other than `state` was written, and
that every kill-switch key came back byte-identical. Repetition cannot walk a weight past its cap.
`learn-evidence` also pins the three ways a sample can look bigger than it is: 55 flat outcomes
plus 6 decided ones per side is 61 rows a bucket - past the floor if rows were what counted - and
produces no adjustment; a marginal separation that clears a plain 95 % on its own is reported as
inconclusive once the threshold is widened for the 38 bucket comparisons the scan could have made,
while the planted 80/20 relationship survives the same widening; and a bucket with unresolved
entries reports them in its **Still open** column and says in its note which way that censoring
cuts.

Three panel and storage groups sit alongside them. `panel-insights` pins what the Insights page
says: the engine-mix row states the split and what it means, and the dashboard headline carries the
multiple-comparison correction itself rather than quietly restating a scan result as a bare 95 %
finding. `panel-bnb` pins the unit rule behind the fee-discount row - 0.5 BNB is worth about 300
USDT, so comparing that bare quantity against a 1.0 **USDT** threshold would invent a low-balance
warning that does not exist; the panel warns only on a balance it could actually value, says
plainly when it could not, and the `toggle_bnb_burn` action is held to the same rule by inspecting
its source with the comments stripped. `db-prune` pins retention: signals, equity and logs at 30
days, observations at 365 (and never shorter than the tail horizon), spent engine orders deleted
while anything still resting, anything that filled and anything a lot still points at survives -
plus the presence of every index the hot per-tick predicates rely on.

---

## The DJ desk (`dj/`) - a separate application

`dj/` is **not part of the trading panel**. It is a second, independent app that happens to live in
the same repository and can be uploaded to the same hosting: the 9Bar DJ desk - a crate, a prep
bench, a set builder, a practice log, gigs and a skill ladder.

The two share a domain and nothing else:

* **Its own README.** Everything about it - what it does, how to install it, how to get your data
  back out - is in [`dj/README.md`](dj/README.md). Its design contract is
  [`docs/DESIGN-DJ.md`](docs/DESIGN-DJ.md).
* **Its own login.** A separate password, hashed into `dj/data/config.json`. Logging in to the
  trading panel does not log you in to the desk, and neither session can reach the other.
* **Its own database.** `dj/data/dj.sqlite`. It never reads `data/trader.sqlite`, and the panel
  never reads the desk's.
* **Its own code.** No file under `dj/` may require, include or read `lib/`, `config.php` or
  `index.php` from the panel - the desk's test suite asserts it. Deleting either directory leaves
  the other one working.
* **Its own tests.** `php dj/tests/run.php`, offline, run separately from the panel's own suite.

It needs no `curl`, no cron job and no API key; `pdo_sqlite` and `json` are enough.

---

## Files and data

```
trader/
  index.php      panel        cron.php   tick        config.php  configuration helpers
  lib/           the code     assets/    css + js    tests/      offline tests
  docs/DESIGN.md            the design contract every file follows
  docs/DESIGN-LEARNING.md   the contract for observation capture, evidence and bounded feedback
  docs/DESIGN-SCALP.md      the contract for the scalp engine, the movers board and the per-coin budgets
  data/          runtime (auto-created): config.json (secrets, 0600), trader.sqlite (positions,
                 orders, trades, signals, equity, logs - 30 days retention; observations are kept
                 for a year, since a 90-day evidence window is worthless once deleted), bot.log
                 (rotates at 2 MB), tick.lock
  dj/            a SEPARATE application (the 9Bar DJ desk) with its own README, login, database
                 and tests - see the section above; nothing in it belongs to the bot
```

Back up `data/` if you care about the history. To start over, stop the cron, delete `data/` and open
the panel again (the wizard runs anew). *Reset paper account* in the panel only resets the simulated
balances.

---

*This software is provided as is, without any warranty. Trading cryptocurrencies can lose you all
the money you put in. Nothing in this repository is investment advice.*
