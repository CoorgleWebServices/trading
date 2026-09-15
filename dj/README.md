# 9Bar DJ Desk

A small, self-contained PHP application for a working DJ: the crate, the prep bench, the set
builder, the practice log, the gig book, the 9Bar brand desk and the path. It is a port of the
published **9Bar Desk** artifact to something that runs on ordinary shared hosting, keeps your
data in a file you own, and works with JavaScript switched off.

No Composer, no external libraries, no shell access, no outbound network calls of any kind.
PHP 7.4+ (works on 8.x), `pdo_sqlite`, `json`. That is the whole dependency list.

---

## It is a separate application from the trading panel

If you also run the Binance micro-trader from this repository, understand this clearly:
**the two share nothing.**

|  | trading panel | DJ desk |
|---|---|---|
| lives at | `public_html/trader/` | `public_html/dj/` |
| password | its own | **its own, a different one** |
| database | `trader/data/trader.sqlite` | `dj/data/dj.sqlite` |
| config | `trader/data/config.json` | `dj/data/config.json` |
| session cookie | `trader_sid`, path `/trader/` | `dj_sid`, path `/dj/` |
| assets | `trader/assets/panel.css` | `dj/assets/dj.css` |

Neither application requires, includes or reads a single file from the other. `dj/` never
touches `../lib`, `../config.php` or `../index.php`, and the trading panel never looks inside
`dj/`. Logging into one does not log you into the other. Deleting one leaves the other working.
They are two unrelated tools that happen to live on one domain, and that separation is the whole
point of the choice: a bug or a break-in on one side cannot walk across into the other.

---

## The eight sections

1. **Desk** — crate health (openers at energy ≤ 4, peaks ≥ 8, bridge tools, still unprepped),
   the practice streak with a seven-day strip, the next gig and the latest set.
2. **Crate** — every track with title, artist, BPM, Camelot key, an energy bar, a bucket tag and
   a prep dot. Filter by bucket, search, sort. Open a row and the desk shows **what mixes out of
   it**: Camelot neighbours (±1 on the wheel, or the same number with the other letter) that also
   sit inside ±6 % on tempo, each with a plain reason — *"8A neighbour · +2 BPM · lifts"*.
3. **Prep bench** — the fourteen-step Bollywood checklist in five phases, ticked per track.
   Ticking derives the track's state: all fourteen ⇒ **ready**; the four source-and-grid steps
   ⇒ **gridded**; anything less ⇒ **raw**.
4. **Set builder** — pick tracks out of the crate into an ordered list, move them up and down,
   write a transition note on each, and watch a live readout of the track count, the BPM span and
   every adjacent pair that is neither Camelot-compatible nor within 6 % on tempo. Sets are saved,
   and — unlike the artifact — they can be reopened and edited.
5. **Practice** — date, minutes, focus, rating. This is what drives the streak.
6. **Gigs** — event, venue, date, fee, status.
7. **9Bar** — the brand desk: nights upcoming-first with a promo progress meter, the thirteen-step
   promo checklist per gig, a copy-ready numbered tracklist built from any saved set, and the press
   kit (bio, rate, links, rider) with a copy button.
8. **The path** — the twelve-rung ladder and the rig checklist.

---

## Requirements

* Any Apache/PHP host — cPanel, Plesk, a VPS — with **PHP 7.4 or newer** (8.0–8.4 tested) and the
  extensions `pdo_sqlite` and `json`. Both are compiled in on practically every host.
* Write permission on `dj/data/`. That is the only directory the desk writes to.
* HTTPS on your domain. The desk redirects HTTP to HTTPS and sets the secure cookie flag when it
  is served over TLS. The one exception is the first-run setup screen, which is left reachable
  over plain HTTP so a host without TLS is not redirected away from it — open
  `https://yourdomain.com/dj/` yourself for that first visit, and the screen warns you if you did
  not. On a setup screen that did arrive over plain HTTP the redirect checkbox starts unticked,
  because turning it on there would close setup behind you and send every later request to an
  `https://` your host may not answer; tick it once TLS is in place.
* No cron job, no outbound network access, no API key, no `curl`. The desk never calls anything.

---

## Install: upload it to `public_html/dj/`

1. **Upload the whole `dj/` folder.** In cPanel's File Manager, go to `public_html`, click
   **Upload**, and put the folder there — or drag `dj/` into it over FTP/SFTP. When you are done
   the server should look like this:

   ```
   public_html/
     dj/
       index.php
       bootstrap.php
       config.php
       .htaccess
       assets/dj.css
       assets/dj.js
       lib/*.php
       tests/run.php
       data/              (created for you on the first visit)
   ```

   If you are uploading a ZIP, extract it *inside* `public_html` and make sure the result is
   `public_html/dj/index.php` and not `public_html/dj/dj/index.php`.

2. **Check the permissions.** Directories `0755`, files `0644`. The desk creates `data/` itself
   as `0750`, writes `data/config.json` as `0600` and `data/dj.sqlite` as `0640`. If the first
   visit complains that it cannot save, set `dj/` to `0755` and make sure it is owned by the
   account PHP runs as.

3. **Confirm the `.htaccess` survived the upload.** It is a dotfile, and some FTP clients hide
   them. `dj/.htaccess` and `dj/data/.htaccess` between them turn off directory indexes and deny
   the web server any access to `data/`, `lib/`, `tests/`, `bootstrap.php`, `config.php`, and
   every `.sqlite`, `.log`, `.json` and `.md` file. Test it: browsing to
   `https://yourdomain.com/dj/data/dj.sqlite` must give you **403 Forbidden**, never a download.
   If it downloads, your host has `AllowOverride None` and you must move `dj/data/` outside the
   document root or ask support to enable `.htaccess` overrides before you put real data in.

4. **Open `https://yourdomain.com/dj/`.**

### First run

The very first visit shows a setup screen, because no password has been set yet.

* Choose a password of at least eight characters and type it twice. It is stored as a
  `password_hash()` bcrypt hash in `dj/data/config.json` — never in plain text, and never
  anywhere else.
* Leave **"Redirect http to https and set the secure cookie flag"** ticked unless your domain
  genuinely has no TLS.
* Press **Set the password**. You are logged straight in, the SQLite database is created, and
  the setup route stops existing: from that moment on, asking for it just shows the login screen.

There is **no account, no email and no password recovery**. Write the password down. If you lose
it, the only way back in is to delete `dj/data/config.json` over FTP, which reopens the setup
screen; your crate, sets, practice and gigs are in `dj/data/dj.sqlite` and survive that.

Five wrong passwords from one IP address lock that address out for fifteen minutes.

After that, start by putting ten tracks in the crate — by hand, or by pasting a list into the
importer (see *Getting data in*, below).

---

## What the artifact has and this port does not

Two things were deliberately left out. Neither is an oversight, and it is worth knowing why.

### The Claude-powered features

The artifact's **AI set builder** ("Build the set", "Find my crate gaps") and its **AI paste
parser** ("Parse with Claude") are not here, and cannot be.

Those features work in the artifact because it runs inside Claude and can ask the model a
question. This port runs on cPanel. A shared PHP host has no Claude access: there is no model to
ask, there is no API key to give it, and adding one would mean sending your crate to a third
party on every click — a network call from an application that otherwise makes none, and a
recurring bill for something you are running precisely because it is yours. So the port does not
pretend. Wherever the artifact offered a Claude button, the desk shows a one-line note saying
that feature lives in the artifact version, and offers the deterministic thing instead:

* **The set builder is manual.** You choose the order; the desk checks your work. It flags every
  adjacent pair that is neither Camelot-compatible nor within 6 % on tempo, so you see the rough
  joins before the room does. Honestly, this is the part that teaches you something — a model
  handing you a finished tracklist does not.
* **The paste importer keeps the pipe parser.** `Title | bpm | key | energy | bucket`, one track
  per line, with the artifact's tolerant `Artist - Title` split on the first field. It is exact,
  it is offline, and it never mis-reads a title.

If you want the AI versions, open the artifact. They are complementary: plan there, keep the
crate here.

### The Trades tab

The artifact has a **Trades** tab — a hand-typed trading journal. This port does not, on purpose.

The panel next door already tracks real Binance positions with live fills, real fees, real dust
and a real equity curve. A second, hand-typed journal sitting beside it would immediately raise
the question *which of these two numbers is true?* — and the answer would always be "the one you
did not just type". So the desk stays a DJ tool and the trading panel stays the trading record.
If you want your P&L, it is at `/trader/`, and it is the machine's own account of what happened.

---

## Getting data in

**One track at a time** — the add form on the Crate page: title, artist, BPM, Camelot key,
energy, bucket, tags.

**A whole list at once** — the paste importer on the same page. One track per line:

```
Nucleya - Bass Rani | 140 | 5A | 9 | desi
Ritviz - Udd Gaye | 104 | 11B | 6 | bollywood
Percussion Tool 124 | 124 | 1A | 3 | tool
Kesariya (Deep Edit)
```

Everything after the title is optional. A first field written as `Artist - Title` is split for
you (a hyphen, an en dash or a middle dot surrounded by spaces all work). A missing BPM stays 0
rather than being guessed, a key that is not a real Camelot code is dropped, a missing energy
defaults to 5 and a missing bucket defaults to `house`. Nothing is invented — check the BPMs
against your software afterwards.

---

## Getting your data out

The artifact has no export at all. This port does, and it is the answer to *"how do I get my
data out if I stop using this?"* — click **Export** in the rail, or go to
`https://yourdomain.com/dj/?page=export`.

* **Crate as CSV** — every track with `id, title, artist, bpm, key, energy, bucket, prep,
  prep_done, tags, added_at`, quoted the way RFC 4180 asks for, so commas, quotes and newlines
  inside a title survive intact. It opens in Excel, Numbers, LibreOffice or Google Sheets, and
  it is the format to keep if all you want is your track list.
* **Everything as JSON** — a full backup of every table: crate, sets, set items, practice, gigs
  and the meta rows (ladder, rig, press kit). This is the one to keep if you might come back. It
  contains no password hash.

Two other ways out, both of which cost nothing:

* **Copy the file.** `dj/data/dj.sqlite` is a plain SQLite database. Download it over FTP and it
  opens in any SQLite browser, `sqlite3` on the command line, Python, anything. The schema is
  seven small tables and it is documented in `docs/DESIGN-DJ.md` §4.
* **Take the whole folder.** Copy `dj/` somewhere else and it runs there — it has no state
  outside its own directory and no dependency on the host it came from.

**Back it up.** Nothing here is in the cloud. Download the JSON export, or `dj/data/dj.sqlite`,
somewhere safe every so often; a shared host is not a backup, and neither is a single disk.

---

## Security

* One password, stored as a `password_hash()` bcrypt hash. Five failed attempts from an address
  lock that address for fifteen minutes.
* The session cookie is `httponly`, `samesite=Strict` and `secure` over HTTPS; the session id is
  regenerated on login; an authenticated session idle for thirty minutes is ended.
* Every POST carries a CSRF token, checked with `hash_equals`. A request without a valid one
  changes nothing.
* Every dynamic value that reaches a page goes through the escaper. A track called
  `<img src=x onerror=alert(1)>` renders as text, on every page that shows it.
* The desk sends `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: no-referrer`, `Cache-Control: no-store` and a Content-Security-Policy that
  allows scripts and styles only from the desk's own folder (plus Google Fonts for the two
  stylesheets and the font files). There is no inline `<script>`, no `onclick=` and no inline
  `style=""` anywhere, so that policy holds without a single exception.
* Errors are written to `dj/data/dj.log` and never shown to a visitor.

---

## Tests

```
cd /path/to/the/repo
php dj/tests/run.php          # one line per assertion
php dj/tests/run.php -q       # failures and the summary only
```

The suite is completely offline: it never opens a socket, it builds its database under the system
temp directory, and it leaves `dj/data/` byte-for-byte as it found it. It exits non-zero when
anything fails. It covers the helpers, the Camelot and tempo maths, the database round trips,
authentication and the lockout, every route rendering for a logged-in session with a hostile
track title, the CSRF rejection, the importer and both exports.

---

## Files

```
dj/
  index.php          router, layout and every page
  bootstrap.php      TRADER_DJ_ROOT, error handling, UTC, loads lib/
  config.php         dj_config() / dj_save_config() over data/config.json
  .htaccess          denies data/, lib/, tests/ and the dotfiles
  .gitignore         keeps data/ out of git (the root ignore file does not reach in here)
  lib/Util.php       escaping, ids, dates, clamps
  lib/Db.php         SQLite wrapper and the schema
  lib/Auth.php       password, session, CSRF, lockout, headers
  lib/Music.php      the Camelot wheel, matches, the energy ramp, prep derivation
  lib/Data.php       the checklists, buckets, ladder, rig and example rows
  lib/Render.php     the shared HTML helpers
  assets/dj.css      the desk's own stylesheet
  assets/dj.js       confirm dialogs and a copy button; nothing else
  data/              config.json (0600), dj.sqlite (0640), dj.log — denied to the web
  tests/run.php      the offline suite
```

The design contract this is written against is `docs/DESIGN-DJ.md`. The published artifact stays
the reference for behaviour and wording.
