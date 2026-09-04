# Binance Micro-Trader — Design Contract

This document is the contract every file in this project is written against.
Target runtime: **PHP 7.4+ (works on 8.x)** on shared cPanel hosting, no shell access required,
no Composer, no external PHP libraries. Extensions used: `curl`, `pdo_sqlite`, `json`
(`bcmath` is used when present, with an integer-math fallback).

**Compatibility rules:** no `match`, no enums, no `readonly`, no named arguments, no
`str_contains`/`str_starts_with`, no `exec`/`shell_exec`/`proc_open`, no `mb_*` dependency.
`declare(strict_types=1)`, typed properties and arrow functions (7.4) are fine.
Never stringify floats with `(string)` or string interpolation for API parameters (PHP prints
`5.0E-5`); always go through `Util::fmtQty()` / `Util::fmtQuote()`.

## 1. Goals and non-goals

* Auto-trade Binance **SPOT** USDT pairs with **market orders**, **one open position at a time**,
  long-only, evaluated once per minute by a cron "tick".
* Never let the account die. Survival layer (§9) runs before any strategy code every tick:
  equity floor kill switch, high-water-mark drawdown kill switch, daily and weekly loss caps,
  consecutive-loss cooldown ladder, trade-count caps, stop-loss on every position, dust-aware
  sizing, order idempotency, exchange-state reconciliation.
* Four modes: `paper` (simulated fills at live bid/ask, default), `demo`
  (demo-api.binance.com with Binance Demo Trading keys from demo.binance.com), `testnet`
  (testnet.binance.vision with testnet keys), `live` (api.binance.com with real keys).
  `demo` and `testnet` are different environments with different keys; a key from one is
  rejected by the other with `-2015 Invalid API-key, IP, or permissions for action`.
* Simple password-protected panel hosted at e.g. `https://yourdomain.com/trader/`.
* "AI" here means a **deterministic mean-reversion scoring model plus a bounded self-tuning
  rule** driven by rolling win rate. No LLM calls. Nothing here guarantees profit; the honest
  expectation for a 10 USDT account with 0.2% round-trip fees is roughly −3% … +2% per month.
  The risk layer bounds the loss; it cannot create an edge.

### Fee and dust facts the code must respect
* Taker fee 0.1% per side (read the real rate from `GET /api/v3/account` → `commissionRates.taker`).
* On a BUY the fee is taken in the **base asset** (unless the BNB discount is active and BNB is
  held), so the sellable quantity is `floor((executedQty − baseCommission) / stepSize)`.
  The remainder is **dust**: not lost, it stays in the wallet and is recovered on a later SELL
  (we always sell the whole floored free balance), but it must be shown separately and counted in
  equity at market value.
* Because of that, a position must be sized so the SELL still passes the NOTIONAL filter after
  an adverse move: `required_size = (minNotional × 1.15 + stepSize × price) / (1 − fee)`.
  The panel shows this per symbol; a symbol whose required size exceeds what we can afford is
  simply not eligible (BTCUSDT on a 10 USDT account is the typical case).

## 2. Directory layout (upload the whole folder as `public_html/trader/`)

```
trader/
  index.php            panel: setup wizard, login, dashboard, settings, actions, ?api=status JSON
  cron.php             tick entry point (CLI: php cron.php  |  HTTP: header X-Cron-Key or ?key=)
  bootstrap.php        error handling, timezone UTC, requires lib/*.php, defines TRADER_ROOT
  config.php           function trader_config(bool $reload=false): array; function trader_save_config(array): void
  .htaccess            deny data/, lib/, tests/, docs/, bootstrap.php, config.php; Options -Indexes; block .git
  .gitignore           data/
  lib/
    Util.php           static helpers (see §5)
    Db.php             SQLite wrapper (§4)
    Log.php            Log::info/warn/error($msg, array $ctx=[]) → Db logs + data/bot.log (rotates at 2 MB)
    Binance.php        signed/unsigned REST client for prod + testnet + data-api (§6)
    Indicators.php     pure static math (§7)
    Strategy.php       Strategy::evaluate() and Strategy::exitSignal() (§8)
    Risk.php           survival layer, sizing, config validation (§9)
    Exchange.php       MarketDataInterface, ExchangeInterface, BinanceMarketData, LiveExchange, PaperExchange, Exchange::factory (§6)
    Bot.php            Bot::tick(), reconciliation, closePosition, panicSell (§10)
    Panel.php          auth, csrf, sessions, headers, render helpers, status payload (§12)
  assets/
    panel.css
    panel.js           auto-refresh via fetch('index.php?api=status') every 20 s; NO inline JS anywhere (CSP)
  data/                runtime dir (auto-created 0750): config.json (0600), trader.sqlite, tick.lock, bot.log
    .htaccess          Require all denied (+ Apache 2.2 fallback)
  tests/
    run.php            offline tests (php tests/run.php) — exits non-zero on failure
    FakeMarketData.php implements MarketDataInterface from fixtures; scriptable prices
    fixtures/          klines_15m_oversold.json, klines_15m_overbought.json, klines_1h_uptrend.json, klines_1h_downtrend.json (Binance klines array format, ≥ 320 rows for 15m, ≥ 260 for 1h)
  docs/DESIGN.md       this file
  README.md            install on cPanel, cron, Binance key setup, safety notes, FAQ
```

## 3. Configuration (`data/config.json`, edited through the panel)

`trader_config()` returns defaults merged with the file. `trader_save_config()` writes atomically
(tmp + rename, chmod 0600). Keys and defaults:

| key | default | notes |
|---|---|---|
| `panel_password_hash` | `""` | `password_hash()` bcrypt; empty ⇒ setup wizard |
| `cron_key` | random 64 hex | required for the HTTP trigger of cron.php |
| `api_key` / `api_secret` | `""` | secret never rendered back; panel shows fingerprint of key only (`abcd…wxyz`) |
| `mode` | `"paper"` | `paper` \| `demo` \| `testnet` \| `live` |
| `enabled` | `false` | master switch for NEW entries; exits are always managed |
| `force_https` | `true` | panel redirects http→https and sets the secure cookie flag |
| `symbols` | `["SOLUSDT","ETHUSDT","XRPUSDT","DOGEUSDT","BNBUSDT","ADAUSDT"]` | watchlist; must end with `quote_asset`; the bot ranks eligible ones each tick |
| `quote_asset` | `"USDT"` | |
| `trade_usdt` | `6.5` | target quote per entry; actual = §9 sizing |
| `equity_floor_usdt` | `7.0` | KILL SWITCH: equity ≤ floor ⇒ close position, halt, manual reset |
| `hwm_drawdown_pct` | `20` | KILL SWITCH: equity < HWM×(1−pct/100) ⇒ same as floor |
| `daily_loss_cap_pct` | `2.0` | realised loss today ≥ pct of day-start equity ⇒ no entries until next UTC day |
| `weekly_loss_cap_pct` | `5.0` | rolling 7 days ⇒ no entries for 7 days (state `paused_until`) |
| `max_trades_per_day` | `3` | ENTRIES per UTC day (adaptive may lower to 1 / raise to 4) |
| `max_orders_per_hour` | `2` | bug guard, counts all orders |
| `max_consecutive_losses` | `3` | ⇒ no entries until next UTC day |
| `cooldown_after_loss_min` | `45` | after 1 loss; after 2 consecutive: `cooldown_after_2_losses_min` |
| `cooldown_after_2_losses_min` | `180` | |
| `take_profit_pct` | `1.0` | minimum TP; effective TP = clamp(1.5×ATR15%, tp, `take_profit_max_pct`) |
| `take_profit_max_pct` | `2.0` | |
| `stop_loss_pct` | `0.7` | measured on effective entry, evaluated on bid |
| `trailing_activate_pct` | `0.6` | |
| `trailing_distance_pct` | `0.4` | stop follows `trail_high × (1 − d/100)`, never below `entry_eff × (1 + trailing_floor_pct/100)` |
| `trailing_floor_pct` | `0.25` | |
| `max_hold_minutes` | `240` | time-based exit |
| `entry_threshold` | `60` | score 0–100 needed (adaptive ±) |
| `adaptive` | `true` | |
| `candle_interval` | `"15m"` | entries evaluated only when a new candle has closed |
| `trend_interval` | `"1h"` | regime gate |
| `atr_min_pct` / `atr_max_pct` | `0.30` / `1.5` | ATR14(15m)/close gate |
| `atr1h_min_pct` / `atr1h_max_pct` | `0.5` / `3.0` | |
| `max_spread_pct` | `0.05` | (ask−bid)/mid gate from bookTicker |
| `fee_pct` | `0.1` | per side; paper mode uses it; live overwrites from account commissionRates |
| `paper_start_usdt` | `10.0` | |
| `recv_window` | `10000` | ms |
| `timezone` | `"UTC"` | display only |

**Engine keys.** The sixteen `engine` / `grid_*` / `pmm_*` / `post_only` / `allow_live_engines`
keys are defined in **[DESIGN-ENGINES.md §2](DESIGN-ENGINES.md#2-configuration-added-to-3-of-designmd)**,
which is authoritative for them. They live in the same `trader_config_defaults()` table as the keys
above, so a `config.json` written before the engines existed still comes back fully populated, and
`Risk::validateConfig()` enforces their bounds alongside these ones.

## 4. Database (`data/trader.sqlite`, created by `Db::migrate()`; WAL; busy_timeout 5000)

```sql
CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
-- keys: halted('0'/'1'), halt_reason, paused_until(ISO|''), pause_reason, api_paused_until, api_error,
--   consecutive_losses, last_loss_at, cooldown_until, last_tick_at, last_tick_status, last_tick_ms,
--   equity_hwm, day_start_equity, day_start_date, paper_balances(JSON {asset: free}), symbol_info(JSON),
--   symbol_info_at, symbol_metrics(JSON), time_offset_ms, used_weight, effective_threshold,
--   effective_max_trades, last_adapt_date, adapt_max_since_closed, last_eval_candle_<SYMBOL>(openTime ms),
--   net_errors, fee_pct_live, no_trade_reason, stuck_retry_at
-- symbol_metrics: [SYMBOL => ['price','atr_pct','atr1h_pct','spread_pct','eligible',
--   'at'(ISO)]] — panel telemetry written by Bot::mergeSymbolMetrics(); `price` is refreshed for
--   every watchlist symbol on every tick (from the prices call already made, no extra weight) so
--   the symbols table keeps showing step value / required size while paused, halted or in a
--   position; the other keys are refreshed only when the symbol is actually evaluated.
--   Keys absent from a patch keep their previous value; the whole write is best-effort.
CREATE TABLE IF NOT EXISTS positions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, symbol TEXT NOT NULL,
  status TEXT NOT NULL,                       -- OPEN | CLOSED | STUCK
  qty REAL NOT NULL,                          -- sellable base qty (floored to stepSize) after commission
  dust_qty REAL NOT NULL DEFAULT 0,           -- remainder left in wallet by this buy
  entry_price REAL NOT NULL,                  -- avg fill price
  entry_eff REAL NOT NULL,                    -- (entry_quote − dust_qty × fill) / qty  (fees in, dust excluded) — TP/SL reference
  entry_quote REAL NOT NULL, entry_fee_usdt REAL NOT NULL DEFAULT 0,
  exit_price REAL, exit_quote REAL, exit_fee_usdt REAL DEFAULT 0,
  pnl_usdt REAL, pnl_pct REAL,
  stop_price REAL NOT NULL, take_profit_price REAL NOT NULL, trail_high REAL NOT NULL,
  trailing_armed INTEGER NOT NULL DEFAULT 0,
  score INTEGER, entry_reason TEXT, exit_reason TEXT,
  opened_at TEXT NOT NULL, closed_at TEXT
);
CREATE TABLE IF NOT EXISTS orders (
  client_id TEXT PRIMARY KEY, position_id INTEGER, mode TEXT NOT NULL, symbol TEXT NOT NULL,
  side TEXT NOT NULL, status TEXT NOT NULL,   -- SENDING | DONE | FAILED | UNKNOWN
  created_at TEXT NOT NULL, updated_at TEXT, raw TEXT
);
CREATE TABLE IF NOT EXISTS trades (
  id INTEGER PRIMARY KEY AUTOINCREMENT, position_id INTEGER, mode TEXT NOT NULL, symbol TEXT NOT NULL,
  side TEXT NOT NULL, order_id TEXT, client_id TEXT, qty REAL NOT NULL, price REAL NOT NULL, quote REAL NOT NULL,
  fee_usdt REAL NOT NULL DEFAULT 0, fee_asset TEXT, raw TEXT, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS signals (
  id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT NOT NULL, score INTEGER NOT NULL,
  eligible INTEGER NOT NULL DEFAULT 1, price REAL NOT NULL, reasons TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS equity (
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, equity_usdt REAL NOT NULL,
  quote_free REAL NOT NULL, position_value REAL NOT NULL, dust_value REAL NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, level TEXT NOT NULL,
  message TEXT NOT NULL, context TEXT
);
CREATE TABLE IF NOT EXISTS login_attempts (
  ip TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, last_at TEXT, locked_until TEXT
);
```
Retention: `Db::prune()` keeps signals/equity/logs for 30 days. All timestamps are ISO-8601 UTC
(`Util::nowIso()` → `2026-09-03T08:42:00Z`).

### `Db` API (lib/Db.php)

```php
final class Db {
  public static function get(?string $path=null): Db;     // singleton; default TRADER_ROOT/data/trader.sqlite; tests pass a temp path
  public static function reset(): void;                    // drop singleton (tests)
  public function pdo(): PDO;
  public function migrate(): void;
  public function getState(string $key, $default=null);    // returns string|default
  public function setState(string $key, $value): void;     // arrays → json; bool → '1'/'0'; null → delete
  public function getStateJson(string $key, $default=null);
  public function openPosition(): ?array;
  public function insertPosition(array $row): int;
  public function updatePosition(int $id, array $fields): void;
  public function closedPositions(int $limit=50): array;   // newest first, status IN (CLOSED,STUCK)
  public function insertOrder(array $row): void;  public function updateOrder(string $clientId, array $fields): void;
  public function pendingOrders(): array;                  // status IN (SENDING, UNKNOWN)
  public function ordersSince(string $sinceIso, ?string $mode=null): int;
  public function insertTrade(array $row): int;
  public function insertSignal(string $symbol, int $score, bool $eligible, float $price, array $reasons): void;
  public function latestSignals(): array;                  // latest row per symbol
  public function noTradeReasons(int $hours=24): array;    // [reason => count] from signals.reasons of ineligible rows
  public function insertEquity(float $equity, float $quoteFree, float $posValue, float $dustValue): void;
  public function equitySeries(int $limit=288): array;     // oldest→newest
  public function log(string $level, string $msg, array $ctx=[]): void;
  public function logs(int $limit=60): array;              // newest first
  public function realisedPnl(string $sinceIso, ?string $mode=null): float;
  public function entriesSince(string $sinceIso, ?string $mode=null): int;
  public function lastClosed(int $n, ?string $mode=null): array;
  public function stats(?string $mode=null): array;   // total_pnl, wins, losses, win_rate, fees_total, trades_today, pnl_today, pnl_week, expectancy
  public function prune(int $days=30): void;
  public function loginAttempt(string $ip): array;  public function loginFailed(string $ip, int $max=5, int $lockMinutes=15): void;  public function loginOk(string $ip): void;
}
```

On the five analytics methods the optional trailing `?string $mode` scopes the query to one mode
(`' AND mode = ?'`, bound, never interpolated); `null`/`''` means **every mode** — the panel view,
which keeps today's behaviour. `Risk` always passes the configured mode so paper history can
neither block nor unblock live trading, and `stats()` scopes its `trades.fee_usdt` sum the same way.

## 5. Util (lib/Util.php) — all static

```php
Util::nowMs(): int;  Util::nowIso(?int $ts=null): string;  Util::todayUtc(): string /* YYYY-MM-DD */;
Util::isoAddMinutes(string $iso, int $m): string;  Util::isoDiffMinutes(string $a, string $b): float;
Util::decimalsOf(string $step): int;                // '0.00100000' → 3 ; '1.00000000' → 0
Util::floorToStep(string|float $qty, string $step): string;   // exact: bcmath if loaded else integer math on 10^decimals; returns plain decimal string, no exponent
Util::fmtQty(float $q, int $decimals): string;      // sprintf('%.'.$d.'F') trimmed; never exponent
Util::fmtQuote(float $q): string;                   // 2 decimals for USDT quoteOrderQty
Util::money(float $v, int $d=4): string;            // panel display
Util::randomHex(int $bytes): string;
Util::clamp(float $v, float $lo, float $hi): float;
Util::clientOrderId(string $side, string $symbol, int $tickMinute): string;  // 'b-SOLUSDT-1788425760' (≤ 36 chars)
```

## 6. Binance client and exchange abstraction

```php
final class BinanceException extends RuntimeException { public int $binanceCode; public int $httpStatus; public ?int $retryAfter; }
final class Binance {
  public function __construct(string $apiKey, string $apiSecret, $network=false, int $recvWindow=10000);  // bool (legacy) | 'prod'|'demo'|'testnet'
  public function tradeUrl(): string;   // https://api.binance.com | https://demo-api.binance.com | https://testnet.binance.vision
  public function dataUrl(): string;    // https://data-api.binance.vision; demo/testnet use tradeUrl; on cURL failure fall back to tradeUrl once
  public function syncTime(): int;  public function setTimeOffset(int $ms): void;  public function timeOffset(): int;
  public function exchangeInfo(array $symbols): array;   // parsed shape below, cached by caller
  public function klines(string $symbol, string $interval, int $limit=320): array; // rows [openTime(int), open, high, low, close, volume (floats), closeTime(int)]
  public function bookTicker(string $symbol): array;     // ['bid'=>float,'ask'=>float]
  public function prices(array $symbols): array;         // [symbol=>float] via /ticker/price?symbols=[...]
  public function avgPrice(string $symbol): float;       // /api/v3/avgPrice
  public function account(): array;                      // ['balances'=>[asset=>['free'=>f,'locked'=>f]] (non-zero), 'taker_fee_pct'=>float, 'can_trade'=>bool]
  public function marketBuyQuote(string $symbol, string $quoteStr, string $clientId): array;  // raw FULL response
  public function marketSellQty(string $symbol, string $qtyStr, string $clientId): array;
  public function getOrder(string $symbol, string $clientId): array;   // GET /api/v3/order?origClientOrderId
  public function testOrder(array $params): bool;
  public function lastUsedWeight(): int;
}
```
Parsed symbol info (cached in state `symbol_info`, refreshed every 6 h or on any `-1013`):
```php
['SOLUSDT' => ['base'=>'SOL','quote'=>'USDT','status'=>'TRADING','spotAllowed'=>true,'quoteOrderQtyAllowed'=>true,
  'stepSize'=>'0.00100000','minQty'=>'0.00100000','maxQty'=>'...','marketStepSize'=>'0','marketMinQty'=>'0',
  'minNotional'=>5.0,'applyMinToMarket'=>true,'tickSize'=>'0.01000000','basePrecision'=>8,'quotePrecision'=>8]]
```
Client rules:
* Build ONE query string with `http_build_query($p, '', '&', PHP_QUERY_RFC3986)`, sign that exact
  string with `hash_hmac('sha256', $qs, $secret)`, append `&signature=`, send exactly that
  (GET: in URL; POST: as body with `application/x-www-form-urlencoded`). Booleans as `'true'`/`'false'` strings.
* `timestamp = Util::nowMs() + offset`; on `-1021` → `syncTime()` and retry once.
* HTTP 429/418 → throw with `retryAfter` (header, default 120 s / 3600 s). HTTP 5xx or cURL timeout on
  POST /order → throw `BinanceException` with code `-1007` (status UNKNOWN); callers reconcile.
* cURL: `CONNECTTIMEOUT 8`, `TIMEOUT 15`, `SSL_VERIFYPEER true`, capture `X-MBX-USED-WEIGHT-1M` and `Retry-After`.
* Never log the secret, the signature, the full URL or request headers; log method + path + HTTP status + code/msg.
* `syncTime()` asks the **data host** first and falls back to the trade host once (it used to be
  implicitly the trade host); on testnet the two are the same host, so the fallback is skipped.
* `exchangeInfo()` and `prices()` take a symbol batch. On Binance error `-1121` (invalid symbol)
  they retry **per symbol** instead of failing the whole batch: each rejected symbol is logged once
  and remembered in the private per-process map `Binance::$invalidSymbols`, which later batches in
  the same process skip. A typo in the watchlist therefore costs one symbol, not the tick.
  It is process-local state only (no DB key) and is rebuilt on the next run.
* `exchangeInfo([])` returns `[]` without issuing a request — an unfiltered exchangeInfo is a
  huge payload and no caller wants it; all callers pass concrete symbol lists.

```php
interface MarketDataInterface {
  public function klines(string $symbol, string $interval, int $limit): array;
  public function prices(array $symbols): array;
  public function bookTicker(string $symbol): array;
  public function symbolInfo(array $symbols): array;
  public function syncTime(): int;           // offset ms
  public function serverTimeMs(): int;
}
interface ExchangeInterface extends MarketDataInterface {
  public function mode(): string;
  public function account(): array;          // same shape as Binance::account()
  /** @return array{qty:float, dust_qty:float, price:float, quote:float, fee_usdt:float, fee_asset:string, order_id:string, raw:array}
   *  qty = SELLABLE base (floored to stepSize) after commission; quote = USDT spent (cummulativeQuoteQty) */
  public function marketBuy(string $symbol, float $quoteUsdt, array $info, string $clientId): array;
  /** @return same shape; quote = USDT received NET of USDT commission; qty = executed qty */
  public function marketSell(string $symbol, string $qtyStr, array $info, string $clientId): array;
  public function getOrder(string $symbol, string $clientId): ?array;   // normalised like marketBuy/Sell or null if not found
}
final class BinanceMarketData implements MarketDataInterface {}   // wraps Binance without keys
final class LiveExchange implements ExchangeInterface { public function __construct(Binance $api, string $mode); }
final class PaperExchange implements ExchangeInterface {
  public function __construct(MarketDataInterface $md, Db $db, float $feePct, float $startUsdt, string $quote='USDT');
  public function reset(): void;
}
final class Exchange { public static function factory(array $cfg, Db $db): ExchangeInterface; }
```
PaperExchange: BUY fills at **ask**, SELL at **bid** (bookTicker). BUY: `gross = quote/ask`,
`commission = gross×fee` in base, `net = gross − commission`, `qty = floorToStep(net)`,
`dust_qty = net − qty`, `fee_usdt = commission×ask`. SELL: `quote = qty×bid×(1−fee)`,
`fee_usdt = qty×bid×fee`. Balances persisted in state `paper_balances`; orders recorded DONE.
BNB commission (live): if `commissionAsset === 'BNB'` nothing is deducted from base; `fee_usdt`
= commission × BNBUSDT price (fetched lazily).

## 7. Indicators (lib/Indicators.php) — pure static functions on float arrays, output aligned to input (null where undefined)

```php
Indicators::sma(array $v, int $n): array;  Indicators::ema(array $v, int $n): array;   // EMA seeded with SMA(n)
Indicators::rsi(array $close, int $n=14): array;                                       // Wilder smoothing
Indicators::atr(array $high, array $low, array $close, int $n=14): array;              // Wilder smoothing
Indicators::bollinger(array $close, int $n=20, float $k=2.0): array;                   // ['mid'=>[], 'upper'=>[], 'lower'=>[]]
Indicators::stddev(array $v, int $n): array;                                           // population
Indicators::last(array $a, int $back=0);
Indicators::closed(array $klines, int $serverTimeMs): array;   // drops the last row when closeTime > serverTime
```

## 8. Strategy (lib/Strategy.php)

```php
final class Strategy {
  /** @param array $c15 closed klines rows (15m, ≥ 250) ; @param array $c1h closed rows (1h, ≥ 210)
   *  @return array{score:int, eligible:bool, reasons:string[], gates:string[], price:float, atr_pct:float, atr1h_pct:float, rsi:float, tp_pct:float} */
  public static function evaluate(array $c15, array $c1h, array $cfg, ?array $book=null): array;
  /** @return string ''|'rsi_overbought'|'bb_upper' */
  public static function exitSignal(array $c15, array $position, float $bid, array $cfg): string;
}
```
**Gates** (any failure ⇒ `eligible=false`, score still computed, gate tags in `gates`):
`trend_down` (1h: EMA50 < EMA200 AND EMA50 falling over 6 bars, or close < EMA200 AND EMA50 < EMA200),
`crash_guard` (sum of last 4 closed 1h returns < −3%), `atr_low`/`atr_high` (ATR14(15m)/close outside
[atr_min_pct, atr_max_pct]), `atr1h_low`/`atr1h_high`, `spread_wide` ((ask−bid)/mid > max_spread_pct, only if `$book` given),
`data_short` (not enough candles).
**Score** (0–100, on the last closed 15m candle):

| component | points |
|---|---|
| RSI14 ≤ 30 | +20 (additional +10 if ≤ 25) |
| close ≤ lower Bollinger(20,2) | +20 (+10 if close ≤ lower − 0.25×bandwidth… i.e. deep pierce) |
| bullish reversal candle: low < lower band AND close > open | +20 |
| RSI turning up: RSI[t] > RSI[t−1] | +20 |
| volume ≥ 1.2 × SMA20(volume) | +20 |
| RSI ≥ 70 or close ≥ upper band | −40 |

Clamp 0–100. `reasons` tags: `rsi<=30`, `rsi<=25`, `bb_lower`, `bb_deep`, `reversal_candle`,
`rsi_up`, `vol_high`, `overbought`. `tp_pct = clamp(1.5 × atr_pct, take_profit_pct, take_profit_max_pct)`.
`exitSignal`: `rsi_overbought` when RSI14 > 70 and position is up ≥ 0.3% vs `entry_eff` on bid;
`bb_upper` when bid ≥ upper band and position up ≥ 0.3%. Never signal-exit a losing position.

## 9. Risk (lib/Risk.php) — the survival layer

```php
final class Risk {
  /** Runs first every tick. Returns ['action'=>'none'|'halt'|'no_entry', 'reason'=>string]. Also maintains equity_hwm, day_start_equity. */
  public static function survivalCheck(array $cfg, Db $db, float $equity, bool $hasOpenPosition, bool $exchangeHasBase): array;
  public static function floorBreached(array $cfg, Db $db, float $equity): bool;   // equity ≤ floor OR equity < hwm×(1−dd)
  public static function entryBlockReason(array $cfg, Db $db, float $quoteFree, float $equity): string; // ''|halted|disabled|paused:<reason>|api_paused|cooldown|daily_cap|weekly_cap|max_trades|max_orders_hour|consecutive_losses|insufficient_quote
  public static function requiredSize(array $info, float $price, float $feePct): float;  // (minNotional×1.15 + step×price)/(1−fee)
  /** quote to spend or 0.0: size = min(trade_usdt, 0.65×quoteFree); 0 if size < requiredSize or quoteFree − size < 0.5 */
  public static function entrySize(array $cfg, array $info, float $price, float $quoteFree, float $feePct): float;
  public static function effectiveThreshold(array $cfg, Db $db): int;          // adaptive, see below
  public static function effectiveMaxTrades(array $cfg, Db $db): int;
  public static function recordOutcome(array $cfg, Db $db, float $pnlUsdt, string $nowIso): void;  // consecutive_losses, cooldown_until, adapt once per day
  /** ['reason'=>''|'stop_loss'|'take_profit'|'trailing_stop'|'max_hold', 'trail_high'=>float, 'trailing_armed'=>int, 'stop_price'=>float] evaluated on bid vs entry_eff */
  public static function exitDecision(array $position, float $bid, array $cfg, string $nowIso): array;
  public static function validateConfig(array $in, array $current): array;   // [cfg, errors[]]; tp ≥ 3×2×fee; sl in (0.2, 5); floor ≥ 1; symbols uppercase ending in quote; etc.
}
```
Adaptive rule (only if `adaptive`, ≥ 20 closed trades, at most one step per UTC day):
win rate < 40% or expectancy < −0.005 ⇒ threshold += 20 (max 100), max_trades −= 1 (min 1);
win rate > 60% and expectancy > 0 ⇒ threshold −= 20 (min entry_threshold), max_trades += 1 (max 4).
If threshold is already 100 and expectancy still < 0 after 20 more trades ⇒ set `enabled=false`,
`pause_reason='adaptive_stop'` (user must re-enable). Never touches TP/SL/size.

Cooldown ladder after a loss: 1 loss ⇒ `cooldown_after_loss_min`; 2 consecutive ⇒
`cooldown_after_2_losses_min`; ≥ `max_consecutive_losses` ⇒ until next UTC day.
Daily cap: `realisedPnl(todayStart) ≤ −day_start_equity×cap%`. Weekly: rolling 7 days.

All risk queries are scoped to the configured mode; a mode switch clears the per-account state
keys (`equity_hwm`, `day_start_equity`, `day_start_date`, cooldown/pause and adaptive keys), and
clearing a halt re-bases `equity_hwm` to the current equity (the absolute `equity_floor` still
re-halts). `floorBreached()` skips the drawdown test while `equity_hwm` is unset, so the next
`survivalCheck()` simply re-seeds it from the new account's equity.

## 10. Bot tick (lib/Bot.php)

```php
final class Bot {
  public function __construct(array $cfg, Db $db, ExchangeInterface $ex, ?int $nowMs=null);  // nowMs injectable for tests
  public function tick(): array;                 // ['status'=>'ok|skipped|halted|error','summary'=>string,'ms'=>int]
  public function closePosition(string $reason): ?array;
  public function panicSell(): array;            // closePosition('panic'); enabled=false
  public function reconcile(): void;             // pending orders + exchange balance vs local position
  public static function runLocked(callable $fn): array;   // flock(data/tick.lock LOCK_EX|LOCK_NB); ['status'=>'skipped'] if busy
}
```
Tick order:
1. If `api_paused_until` in the future ⇒ return skipped. `syncTime()`; symbol info (cache 6 h).
2. `reconcile()`: for each SENDING/UNKNOWN order call `getOrder`; FILLED ⇒ create/close position
   from it; not found ⇒ FAILED. Then `account()`; a watchlist base balance whose value ≥ its
   minNotional while no OPEN position exists ⇒ HALT `reason=untracked_position` (bug guard);
   an OPEN position whose base free < qty×0.9 ⇒ mark CLOSED `exit_reason=reconciled_missing`, pnl 0, warn.
3. Prices/book; equity = quote free+locked + Σ base×price (watchlist bases **plus any OPEN/STUCK
   position's symbol**, so a position on a symbol taken off the watchlist is still valued and
   cannot trip a false equity_floor); dust value separately.
   `insertEquity`, `last_tick_at`, `used_weight`.
4. `Risk::survivalCheck` ⇒ `halt`: closePosition('equity_floor'|'drawdown'), state halted, enabled=false, return.
5. OPEN position: bid from bookTicker; `Risk::exitDecision`; if '' ⇒ `Strategy::exitSignal` (15m klines).
   On exit: `qtyStr = floorToStep(min(position.qty + dust carried, base free))`; if `qty × avgPrice < minNotional`
   ⇒ status STUCK, `exit_reason=stuck_notional`, warn (retry every 15 min while price recovers, never every minute).
   Else insert order SENDING → `marketSell` → DONE; trade; close position
   `pnl = exit_quote − entry_quote − any commission paid in an asset outside the pair (BNB)`;
   `Risk::recordOutcome`. On `-1007`/timeout ⇒ order UNKNOWN, position stays OPEN, reconcile next tick.
6. No OPEN position and `Risk::entryBlockReason() === ''`: for each symbol whose last closed 15m candle
   openTime > `last_eval_candle_<SYMBOL>`: fetch 15m (320) + 1h (260) klines + bookTicker, `Strategy::evaluate`,
   store signal (eligible + gates), update `last_eval_candle`. Additionally require
   `Risk::requiredSize(info, price, fee) ≤ Risk::entrySize(...)` else gate `size_unaffordable`.
   Pick the best eligible score ≥ effective threshold; **max one order per tick**; insert order SENDING
   → `marketBuy` → DONE; if executedQty == 0 ⇒ FAILED, log. Position:
   `entry_eff = (quote − dust_qty × price)/qty` (the dust is recovered by a later SELL, so it is
   not charged to the sellable qty; charging it would stop a fresh position out on the next tick),
   `stop = entry_eff×(1−sl)`, `tp = entry_eff×(1+tp_pct_from_signal)`, `trail_high = entry_eff`.
7. `prune()`, `last_tick_status`, `no_trade_reason`, return summary.

Error policy: `-1021` handled in client; `429/418` ⇒ `api_paused_until = now+retryAfter`, abort;
`-2015` ⇒ `enabled=false`, `api_error='Invalid API key / IP / permission'`, abort; `-1013` ⇒ refresh symbol
info, log, no retry this tick; `-2010` ⇒ reconcile and log; 3 consecutive network errors ⇒ `api_paused_until = now+15min`.
Every tick logs one INFO line: equity, quote free, position, used weight, decision/no-trade reason.

## 11. cron.php

* CLI: `php /home/USER/public_html/trader/cron.php` (no key when `PHP_SAPI === 'cli'`).
* HTTP: header `X-Cron-Key: …` or `?key=…`; `hash_equals((string)$cfg['cron_key'], (string)$given)`; 403 otherwise;
  refuse if last tick < 50 s ago (`text/plain` "too soon").
* `set_time_limit(55)`; `Bot::runLocked(...)`; prints the summary. If mode ≠ paper and keys missing ⇒ log + exit 0.
* Never runs when `panel_password_hash` is empty (setup not done).

## 12. Panel (index.php + lib/Panel.php + assets/)

Bootstrap order: force HTTPS (if configured and not HTTPS) → security headers → session cookie params
(`httponly`, `samesite=Strict`, `secure` when HTTPS) + `use_strict_mode` → `session_start` → idle timeout 30 min →
CSRF check on POST → route. Headers: `X-Frame-Options: DENY`,
`Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`.
No inline `<script>` or `onclick`; CSS in assets/panel.css (inline `style=""` allowed only via `style-src 'self' 'unsafe-inline'`
if really needed — prefer classes). `Panel::e()` = htmlspecialchars ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5 on EVERY dynamic value.

Routes (`$_GET['page']`, `$_POST['action']`):
* **Setup wizard** when `panel_password_hash` is empty: password (≥ 12 chars, twice), mode (default paper),
  optional API key/secret, watchlist, force_https checkbox. Creates `data/`, `data/.htaccess`, DB, config.
  Then shows the cron command + HTTP cron URL/header example.
* **Login**: password only; lockout 5 fails / 15 min per IP; generic error; `session_regenerate_id(true)`.
* **Dashboard**: status pills (mode; ENABLED / PAUSED(reason) / HALTED(reason) / COOLDOWN / API PAUSED; last tick age,
  red "cron not running" if > 3 min); KPI tiles: equity, quote free, dust value, PnL today, PnL 7d, PnL total,
  win rate, expectancy/trade, trades today / effective max, fees total, effective threshold, HWM; API health card
  (time offset ms, used weight, last API error, api_paused_until); open-position card (symbol, qty, entry_eff,
  bid, unrealised %, stop, TP, trailing armed, age, exit-signal status); equity sparkline (inline SVG, last 288 points);
  symbols table (symbol, status, minNotional, stepSize, step value, required size, ATR15%, spread, eligible/gates,
  score, last eval); closed positions table (last 30); no-trade reasons histogram (24 h); log tail (40).
* **Actions** (POST + CSRF, redirect with flash): `start` (refused if halted or setup incomplete), `pause`,
  `reset_halt`, `panic_sell` (needs confirm checkbox), `run_tick` (inline tick, shows summary), `reset_paper`, `logout`.
* **Settings**: every §3 key except hashes/cron_key/force_https; secret write-only (blank keeps); switching to `live`
  requires checkbox "I understand this trades real money"; `Risk::validateConfig`; shows cron command and key
  fingerprint; button "Test API connection" (calls account() + testOrder on the first symbol; shows result).
* **`?api=status`**: JSON for panel.js (401 if not logged in).

## 13. Tests (tests/run.php) — offline, `php tests/run.php`

Tiny assert helper, prints `ok`/`FAIL` lines, exit code = number of failures. Covers:
`Util::floorToStep` (incl. `'0.00001'` step, `'1'` step, exponent-free output, bcmath vs fallback),
`decimalsOf`, `fmtQty`; EMA/RSI/ATR/Bollinger sanity; `Strategy::evaluate` (oversold+uptrend fixture ⇒ eligible and
score ≥ 60; overbought fixture ⇒ score ≤ 20; downtrend 1h ⇒ gate `trend_down`); Risk (floor, hwm drawdown, daily cap,
cooldown ladder, max trades, requiredSize/entrySize clamps, exitDecision for sl/tp/trailing/max_hold, adaptive steps);
PaperExchange buy/sell accounting (fees, net qty, dust); full Bot tick sequence in paper mode with
`FakeMarketData`: (1) enters on strong signal, (2) no second entry same candle, (3) exits on take-profit when bid is raised,
(4) exits on stop-loss when bid is dropped, (5) kill switch halts when equity is dropped below floor,
(6) idempotency: a SENDING order is reconciled instead of re-sent.
Regression groups that must never go green for the wrong reason:
`bot-dust` (a 6.5 USDT entry on a ~600 USDT pair with a 0.001 step: `entry_eff` excludes the retained
dust, so `Risk::exitDecision` returns `''` on the very next tick instead of `stop_loss`),
`bot-offlist` (a position whose symbol was removed from the watchlist is still valued in equity, so
no false `equity_floor` halt), `bot-stuck` (a STUCK position blocks new entries exactly like an OPEN
one), `bot-metrics` (`symbol_metrics` prices refresh every tick; a too-small balance reports
`size_unaffordable(quote_free=…)` and does not halt), `risk-mode-scope` (paper history caps paper
but never blocks live), `risk-hwm-rebase` (clearing a halt re-bases `equity_hwm`, and the absolute
`equity_floor` still re-halts).
