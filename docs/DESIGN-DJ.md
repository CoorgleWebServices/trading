# 9Bar DJ Desk — a self-contained PHP port

A second, independent application living at `dj/`, deployed to `public_html/dj/`.
It shares **nothing** with the trading panel: its own login, its own SQLite database, its own
config, its own assets. Neither app may require a file from the other. That separation is the
whole point of the choice — two unrelated tools that happen to live on one domain.

Ported from the published artifact `9Bar Desk`
(<https://claude.ai/artifact/6LUvi95Vu2VhrAS6dNpHrR>), which stays the reference for behaviour
and wording. Read it if a detail here is ambiguous.

## 1. Scope

**In:** Desk, Crate, Prep bench, Set builder, Practice, Gigs, 9Bar, The path.

**Out, deliberately:**
* The artifact's **Trades** tab. The panel next door already tracks real Binance positions with
  live fills; a second manual journal beside it would invite confusion about which is true. Say
  so in the README so the omission is visible rather than looking like an oversight.
* Every **Claude-powered** feature — the AI set builder, "Parse with Claude", "Find my crate
  gaps". cPanel has no Claude access. The set builder becomes a manual one (§6) and the paste
  importer keeps only the deterministic pipe parser. Wherever the artifact offered an AI action,
  the port shows a one-line note saying that feature lives in the artifact version.

## 2. Runtime and house rules

Identical to `docs/DESIGN.md` §0: **PHP 7.4-compatible syntax only** (no match, enums, readonly,
named arguments, `str_contains`, `str_starts_with`, `?->`, union types, constructor promotion),
`declare(strict_types=1)` at the top of every file, `require_once` with `__DIR__`, no Composer, no
external PHP libraries, no `exec`. Extensions: `curl` is NOT needed here; `pdo_sqlite` and `json`
are. `php -l` runs on PHP 8.x and will not catch 7.4 violations — check by eye.

## 3. Layout

```
dj/
  index.php          router, layout, every page
  bootstrap.php      TRADER_DJ_ROOT, error handling, UTC, requires lib/*
  config.php         dj_config() / dj_save_config(); data/config.json (0600)
  .htaccess          deny data/ and lib/; Options -Indexes
  lib/
    Util.php         esc, uid, today, isoAddDays, dayDiff, clamp, randomHex
    Db.php           SQLite wrapper + schema (§4)
    Auth.php         password, session, CSRF, login lockout  (§8)
    Music.php        Camelot wheel, neighbours, matchesFor, energyColor, prep derivation
    Data.php         PREPSTEPS, PROMOSTEPS, LADDER, RIG, BUCKETS, PREP, RATINGS, DEMO
    Render.php       shared HTML helpers: card, table, pill, meter, checklist, empty state
  assets/
    dj.css           own stylesheet, the artifact's palette (§9)
    dj.js            tab-free progressive niceties only; NO inline JS anywhere
  data/              created 0750; config.json, dj.sqlite; .htaccess denies all
  tests/run.php      offline suite, `php dj/tests/run.php`
```

## 4. Database (`dj/data/dj.sqlite`, WAL, busy_timeout 5000)

```sql
CREATE TABLE IF NOT EXISTS crate (
  id TEXT PRIMARY KEY, title TEXT NOT NULL, artist TEXT, bpm REAL, key TEXT,
  energy INTEGER, bucket TEXT, prep TEXT, prep_done TEXT, tags TEXT, added_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS sets (
  id TEXT PRIMARY KEY, name TEXT NOT NULL, brief TEXT, created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS set_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, set_id TEXT NOT NULL, pos INTEGER NOT NULL,
  track_id TEXT, title TEXT, artist TEXT, bpm REAL, key TEXT, note TEXT
);
CREATE TABLE IF NOT EXISTS practice (
  id TEXT PRIMARY KEY, date TEXT NOT NULL, minutes INTEGER NOT NULL, focus TEXT, rating TEXT
);
CREATE TABLE IF NOT EXISTS gigs (
  id TEXT PRIMARY KEY, name TEXT NOT NULL, venue TEXT, date TEXT, fee REAL DEFAULT 0,
  status TEXT, promo TEXT
);
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS login_attempts (
  ip TEXT PRIMARY KEY, attempts INTEGER NOT NULL DEFAULT 0, last_at TEXT, locked_until TEXT
);
CREATE INDEX IF NOT EXISTS idx_set_items ON set_items(set_id, pos);
```
`prep_done` and `promo` hold a JSON object `{stepId: true}`. `meta` holds `skills`, `rig` and
`press` as JSON. All dates are `YYYY-MM-DD`; all timestamps ISO-8601 UTC.

## 5. Sections

1. **Desk** — crate health (openers ≤4, peaks ≥8, bridge tools, still unprepped), practice streak
   with a 7-day strip, next gig, latest set. Same counts and wording as the artifact.
2. **Crate** — table of tracks: title, artist, BPM, Camelot key, energy bar, bucket tag, prep dot.
   Filter chips by bucket, a search box, a sort select. Expanding a row shows **what mixes out of
   it**: `Music::matchesFor()` — Camelot neighbours (±1 on the wheel, or the same number with the
   other letter) intersected with ±6 % BPM, each with a plain reason ("8A neighbour · +2 BPM ·
   lifts"). Add one track via a form, or many via the pipe importer
   `Title | bpm | key | energy | bucket`, one per line.
3. **Prep bench** — the 14-step Bollywood checklist in 5 phases (`PREPSTEPS`, copied verbatim
   including the explanatory text). Per-track state in `crate.prep_done`. Ticking derives `prep`:
   all 14 ⇒ `ready`; the four source+grid steps ⇒ `gridded`; otherwise `raw`. Queue defaults to the
   bollywood/desi/edit buckets with an all-buckets toggle.
4. **Set builder (manual)** — pick tracks from the crate into an ordered list. Each row has up
   and down buttons, a remove button and a free-text transition note. A live readout shows track
   count, total BPM span and flags any adjacent pair that is neither Camelot-compatible nor within
   6 % BPM, so the operator sees the rough joins without an AI. Save to `sets` + `set_items`;
   saved sets can be reopened and edited, which the artifact cannot do.
5. **Practice** — log date, minutes, focus, rating. Drives the streak.
6. **Gigs** — event, venue, date, fee, status.
7. **9Bar** — the brand desk: nights sorted upcoming-first with a promo progress meter, the 13-step
   `PROMOSTEPS` checklist per gig stored in `gigs.promo`, a copy-ready numbered tracklist built
   from any saved set, and the press kit (bio, rate, links, rider) in `meta.press` with a copy
   button.
8. **The path** — the 12-rung `LADDER` and the `RIG` checklist, state in `meta.skills` / `meta.rig`.

## 6. Set builder details

Building the set is a POST round trip per action (add, move, remove, note, save), so it works
with JavaScript disabled. `dj.js` may add nothing more than confirm dialogs. The rough-join check
is `Music::joinWarning($a, $b)`, returning `''` or a short reason — pure, and unit tested.

## 7. Import and export

* **Import:** the pipe parser, plus a tolerant "artist - title" split, exactly as the artifact's
  `bulkPlain()`.
* **Export:** a `?page=export` action emitting the whole crate as CSV and a JSON backup of every
  table. The artifact has no export at all, so this is the port's advantage and the answer to
  "how do I get my data out" — mention it in the README.

## 8. Security

Same standard as the trading panel, independently implemented in `Auth.php`:
`password_hash`/`password_verify`; session cookie `httponly`, `samesite=Strict`, `secure` on
HTTPS; `session_regenerate_id(true)` on login; a CSRF token checked on **every** POST with
`hash_equals`; per-IP lockout after 5 failures for 15 minutes; `Util::esc()` on every dynamic
value; headers `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
`Referrer-Policy: no-referrer`, `Cache-Control: no-store`, and
`Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com;
font-src https://fonts.gstatic.com; img-src 'self' data:; object-src 'none'; base-uri 'none';
frame-ancestors 'none'; form-action 'self'`. No inline `<script>`, no `onclick`, no inline
`style` attributes. `data/` denied by `.htaccess`. Errors logged, never displayed.

First visit with no password set shows a setup screen; once set, the setup route is unreachable.

## 9. Look

Its own stylesheet carrying the artifact's identity: Khand for headings, Hanken Grotesk for body,
IBM Plex Mono for every number, loaded from Google Fonts with real fallback stacks. Rani pink
`--accent`, the cyan→marigold→rani ramp for track energy, full light/dark token system in the
three documented states (`:root`, `prefers-color-scheme: dark` guarded by
`:root:not([data-theme="light"])`, and `:root[data-theme="dark"]`). Every colour comes from a
token. Numerics use `font-variant-numeric: tabular-nums`. Header reads **9Bar** with **Uncle K**
beneath, matching the artifact.

## 10. Tests (`dj/tests/run.php`, offline, no network)

`dj-util` (esc, uid uniqueness, date helpers), `dj-music` (Camelot neighbours at the 12→1 wrap,
matchesFor inside and outside the ±6 % band, energyColor endpoints, prep derivation at all three
thresholds, joinWarning), `dj-db` (schema is idempotent; insert/update/delete round trips; JSON
columns survive; set_items keep their order after a move), `dj-auth` (a wrong password fails, the
right one succeeds, lockout after 5, CSRF rejects a bad token), `dj-pages` (every route renders
for a logged-in session and every dynamic value is escaped — assert a track titled
`<img src=x onerror=alert(1)>` appears escaped and never raw), `dj-import` (pipe and
"artist - title" forms), `dj-export` (CSV and JSON round trip).
