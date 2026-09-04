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

## Engines: signal, grid, pmm

The bot runs exactly one **engine** at a time, chosen with the `engine` setting. The signal engine
is the default and is what the rest of this file describes; the other two trade continuously and
are **demo-only by default**.

| engine | what it does | orders | earns from | loses when |
|---|---|---|---|---|
| `signal` (default) | one position at a time, entries only on a closed 15m candle | market | mean reversion inside an uptrend | the setup keeps failing (the survival layer bounds it) |
| `grid` | a ladder of resting buy rungs below an anchor, each with a sell one rung up | limit (post-only) | oscillation inside a range | price **trends out of the range** |
| `pmm` | pure market making: one bid and one ask around mid, re-quoted every refresh | limit (post-only) | the spread | always, at VIP0 fees - see below |

**pmm is expected to LOSE money at VIP0 fees.** Binance charges 0.1 % maker, identical to taker, so
a round trip costs about 0.2 %, while observed spreads on the majors are 0.01 - 0.05 %. It is
included because it was asked for and because it becomes viable at better fee tiers, on wide-spread
pairs, or with maker rebates. Do not run it live expecting profit. The panel repeats this warning
wherever pmm is selected.

**Grid earns while the market ranges and bleeds while it trends.** Every completed rung round trip
banks `grid_spacing_pct` minus the ~0.2 % round trip, so a choppy market pays and a one-way market
does not: the ladder just keeps buying into the fall and ends up holding inventory bought above the
market. It is viable only when the rung spacing exceeds the round-trip cost, which the settings
validator enforces (`grid_spacing_pct` must be at least `2 x fee_pct + 0.1`). Its real risk is not
the fee but the trend: a grid holds inventory bought on the way down, so a market that leaves the
range leaves you long. `grid_range_up_pct` / `grid_range_down_pct` bound that - on a range exit the
bot cancels every order, optionally market-sells the inventory (`grid_exit_liquidates`, off by
default), and pauses until you press **Re-anchor grid** in the panel.

### The demo-only rule

`grid` and `pmm` **place no order at all in `live` mode** unless `allow_live_engines` is explicitly
turned on. The default is off. In that state every tick returns immediately with
`no_trade_reason = engine_live_blocked`, the event is logged once, and the dashboard shows a red
banner. This is deliberate, not a bug: prove an engine in paper, demo or testnet first.

The rule covers the **panel actions** as well as the cron tick, because it is about orders, not
about ticks:

| action | while live-blocked |
|---|---|
| **Run tick** / cron | returns `engine_live_blocked`, places nothing |
| **Cancel all orders** | still works - cancelling only ever takes risk off the book |
| **Cancel** (one order) | still works, same reason |
| **Re-anchor grid** | moves the anchor and clears the pause; quotes nothing against it |
| **Flatten inventory** | **refused.** It ends in a real market SELL, so it cancels the resting orders, sells nothing, and tells you to switch the engine back to `signal` first |

So the one way out of a live-blocked engine that is holding inventory is to set `engine` back to
`signal` (or turn `allow_live_engines` on, deliberately) and flatten from there. `tests/run.php`
proves the whole guarantee in the `engine-demo-only` group: it builds a real ladder and a real lot
with the flag on, turns the flag off, drives every action listed above, and asserts that the limit,
market-buy and market-sell counters of the exchange double are all still zero.

### Engine settings

All sixteen keys below are ordinary config keys with defaults in `config.php`, so an installation
that predates the engines picks them up on the next load - you never have to hand-edit
`data/config.json`. The Settings page shows only the fields of the engine you have selected, and
`Risk::validateConfig()` checks their bounds on save (see the notes column).

| key | default | notes |
|---|---|---|
| `engine` | `signal` | `signal` / `grid` / `pmm` |
| `allow_live_engines` | `false` | must be on before grid/pmm may trade in live mode |
| `engine_symbol` | `DOGEUSDT` | the single symbol grid/pmm trade; they ignore the watchlist |
| `engine_max_orders` | `12` | hard cap on simultaneously open orders (1 - 20) |
| `post_only` | `true` | `LIMIT_MAKER`; a post-only rejection is normal and simply skips that quote |
| `grid_levels` | `6` | buy rungs below the anchor, 1 - 20 |
| `grid_spacing_pct` | `0.60` | distance between rungs; must clear the round-trip fee |
| `grid_order_usdt` | `1.30` | quote per rung; must clear the symbol's required size |
| `grid_range_up_pct` | `4.0` | mid above `anchor x (1 + x)` ends the grid |
| `grid_range_down_pct` | `6.0` | mid below `anchor x (1 - x)` ends the grid |
| `grid_exit_liquidates` | `false` | on a range exit, also market-sell the inventory |
| `pmm_spread_pct` | `0.25` | half-spread each side of mid |
| `pmm_order_usdt` | `1.30` | quote per quote-order |
| `pmm_refresh_sec` | `60` | quotes older than this are cancelled and replaced |
| `pmm_target_base_pct` | `50` | inventory target, percent of engine equity held as base |
| `pmm_max_base_pct` | `80` | above this stop bidding; below `100 - this` stop asking |

**Funding the ladder.** A grid needs `grid_levels x grid_order_usdt` of free quote before the lower
rungs can fill, and each rung must also clear the symbol's required size - about **1.35 USDT for
DOGEUSDT** (see the minimum-notional section above), so the 1.30 default should be raised slightly
for that pair. The defaults therefore want roughly `6 x 1.30 = 7.80 USDT` free, or `8.10` at a rung
of 1.35. That is nothing on a demo account, but on a 10 USDT live account it is very nearly the
whole balance: the settings validator warns when the full ladder costs more than 90 % of the free
quote. On a small real account drop `grid_levels` to 1 - 2.

### Switching back to the signal engine

The resting orders and the inventory belong to the engine, not to the signal engine, and once the
selector says `signal` no tick will ever sync that ladder again. So do this in order:

1. **Flatten inventory** while the engine is still selected (it market-sells what the engine holds
   and needs its confirmation tick box). Skip this only if you want to keep the base.
2. Wait one tick, so the fills book into `cycles` and the KPIs are right.
3. Set `engine` back to `signal` in **Settings** and save.

Saving a change to `engine` **or** to `engine_symbol` cancels the previous engine's resting orders
automatically - it is the last moment anything can, since the dashboard's engine card disappears
with the same save - and tells you how many went. That safety net is not a substitute for step 1:
it cancels orders, it never sells. **Inventory is deliberately kept**, because it is real base and
selling it is your decision, not the panel's. If you find leftover base later, select the engine
again and use **Flatten inventory**, or sell it by hand on Binance.

The signal-engine cards reappear as soon as `signal` is selected. Realised engine PnL keeps
counting against the daily and weekly loss caps either way, so a bad grid still pauses the account.

---

## Recommended rollout

0. **Pick the engine first.** The steps below describe the signal engine. `grid` and `pmm` are
   demo-only until you deliberately set `allow_live_engines`, so step 3 is off the table for them
   by default - and it should stay off the table for `pmm`, which is expected to lose money at
   VIP0 fees. See "Engines: signal, grid, pmm" above.

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
  closed positions, a histogram of why no trade was taken in the last 24 hours, and the log tail. When an engine is active the dashboard also shows an **Engine** card (engine, symbol, anchor, distance to each range edge, live order count, inventory and unrealised at bid), an **Open orders** table with a per-order Cancel and **Cancel all orders**, a **Cycles** table with its KPIs, and the **Re-anchor grid** and **Flatten inventory** actions.
* **Actions**: Start, Pause, Reset halt, Panic sell (sells at market and disables trading; needs a
  confirmation tick box), Run tick now, Reset paper account, Logout.
* **Settings**: every parameter with validation (the take-profit must be at least three times the
  round-trip fee, the stop between 0.2 % and 5 %, symbols must end with the quote asset, ...), the
  cron command, the cron key, and "Test API connection".
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

The engines are covered by the same run: `util-tick`, `engine-orders` (place / sync / cancel, a
fill becomes a lot, a sell consumes lots FIFO into cycles, a repeated sync books nothing twice),
`engine-grid` (the ladder, a rung round trip earning spacing minus fees, the order cap, range exit
and re-anchor), `engine-pmm` (both quotes around mid, refresh, inventory skew), `engine-fees`
(spacing under the fee floor is rejected on save), `engine-guard` (the equity floor and the daily
cap take the ladder off the book) and `engine-demo-only` (the demo-only rule, proved over a real
ladder and a real lot, across the tick and every panel action).

---

## Files and data

```
trader/
  index.php      panel        cron.php   tick        config.php  configuration helpers
  lib/           the code     assets/    css + js    tests/      offline tests
  docs/DESIGN.md the design contract every file follows;  docs/DESIGN-ENGINES.md the same for grid + pmm
  data/          runtime (auto-created): config.json (secrets, 0600), trader.sqlite (positions,
                 orders, trades, signals, equity, logs - 30 days retention), bot.log (rotates at
                 2 MB), tick.lock
```

Back up `data/` if you care about the history. To start over, stop the cron, delete `data/` and open
the panel again (the wizard runs anew). *Reset paper account* in the panel only resets the simulated
balances.

---

*This software is provided as is, without any warranty. Trading cryptocurrencies can lose you all
the money you put in. Nothing in this repository is investment advice.*
