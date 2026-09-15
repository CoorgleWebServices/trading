<?php
declare(strict_types=1);

/**
 * dj/index.php — the router, the layout host and every page of the 9Bar desk
 * (DESIGN-DJ.md §5, §6, §7).
 *
 * Request order: config -> Auth::boot (https, headers, session, idle timeout) ->
 * storage -> CSRF on every POST -> setup -> login -> action dispatcher -> page.
 * The CSRF check happens BEFORE any action is looked at, so an unauthenticated
 * or forged POST never reaches a handler (DESIGN-DJ.md §8).
 *
 * House rules that hold for every line below:
 *   * every dynamic value goes through Util::esc(), via Render's helpers;
 *   * no inline <script>, no onclick=, no inline style="";
 *   * every form works with JavaScript switched off — the set builder is a
 *     POST round trip per action, the checklists are real checkboxes;
 *   * nothing here requires, includes or reads a file belonging to the trading
 *     panel next door. The two applications share a domain and nothing else.
 *
 * PHP 7.4 syntax only: no match, no enums, no named arguments, no union types,
 * no str_contains/str_starts_with, no ?->, no constructor promotion.
 */

require_once __DIR__ . '/bootstrap.php';

/* ====================================================================== */
/*                                 entry                                  */
/* ====================================================================== */

/**
 * One whole request. Wrapped in a function so dj/tests/run.php can require this
 * file (with DJ_NO_MAIN defined) and call the page builders directly.
 */
function dj_main(): void
{
    try {
        $cfg = dj_config();
    } catch (Throwable $e) {
        error_log('[dj] config: ' . $e->getMessage());
        // The message names the config file's absolute path and how to reset it;
        // that belongs in the log, not in an anonymous visitor's 500 page
        // (DESIGN-DJ.md §8 - "Errors logged, never displayed").
        dj_fatal('Configuration error', 'The desk could not read its configuration - see dj/data/dj.log.');
    }

    Auth::boot($cfg);

    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    $page   = isset($_GET['page']) && is_string($_GET['page']) ? strtolower(trim($_GET['page'])) : 'desk';
    $action = ($method === 'POST' && isset($_POST['action']) && is_string($_POST['action']))
        ? trim($_POST['action']) : '';

    try {
        dj_ensure_data_dir();
        $db = Db::get();
    } catch (Throwable $e) {
        error_log('[dj] storage: ' . $e->getMessage());
        http_response_code(500);
        echo Render::layout('Storage error', Render::card('Storage error',
            '<p>The <code>data/</code> directory or the SQLite database could not be opened. '
            . 'The details went to the server log.</p>'
            . '<p>Make sure <code>dj/data/</code> is writable by PHP and that the '
            . '<code>pdo_sqlite</code> extension is enabled.</p>'), ['nav' => false]);
        return;
    }

    /* ---- CSRF first, before any POST is interpreted (§8) ---- */
    if ($method === 'POST' && !Auth::checkCsrf()) {
        http_response_code(403);
        echo Render::layout('Request rejected', Render::card('Request rejected',
            '<p>The form token was missing or expired — the session ends after 30 minutes of '
            . 'inactivity. Go back, reload the page and try again.</p>'
            . '<p><a class="btn" href="index.php">Back to the desk</a></p>'), ['nav' => false]);
        return;
    }

    /* ---- setup: reachable only while no password is set (§8) ---- */
    if (Auth::needsSetup($cfg)) {
        echo dj_setup_request($cfg, $action);
        return;
    }

    /* ---- login ---- */
    if (!Auth::isLoggedIn()) {
        echo dj_login_request($cfg, $db, $action);
        return;
    }

    /* ---- one dispatcher for every state change; it always redirects ---- */
    if ($action !== '') {
        dj_action($action, $cfg, $db);
        return;
    }

    /* ---- export emits a file of its own, so it answers before the layout ---- */
    if ($page === 'export') {
        $format = isset($_GET['format']) && is_string($_GET['format']) ? strtolower(trim($_GET['format'])) : '';
        if ($format === 'csv' || $format === 'json') {
            dj_export_emit($format, $db);
            return;
        }
    }

    echo dj_page($page, $cfg, $db);
}

/** A plain-text 500 for a failure that happens before the layout can be trusted. */
function dj_fatal(string $title, string $detail): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    }
    echo $title . ': ' . $detail . "\n";
    exit(1);
}

/** See the other side of the request: a relative redirect, then stop. */
function dj_redirect(string $to): void
{
    $to = trim($to);
    if ($to === '' || strpos($to, "\r") !== false || strpos($to, "\n") !== false) {
        $to = 'index.php';
    }
    if (!headers_sent()) {
        header('Location: ' . $to, true, 303);
    }
    exit;
}

/**
 * Where an action should send the browser back to: the `back` field a form
 * carried, when it is one of our own relative URLs, otherwise $default.
 * Anything with a scheme, a host or a control character is refused, so a
 * posted field can never become an open redirect.
 */
function dj_back(string $default): string
{
    $b = isset($_POST['back']) && is_string($_POST['back']) ? trim($_POST['back']) : '';
    if ($b !== '' && preg_match('#^index\.php(\?[A-Za-z0-9_=&%.+~*()\'!:,\-]*)?$#', $b) === 1) {
        return $b;
    }
    return $default;
}

/* ====================================================================== */
/*                            request helpers                             */
/* ====================================================================== */

/** A POSTed scalar as a trimmed string ('' when absent or an array). */
function dj_post(string $key, string $default = ''): string
{
    if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) {
        return $default;
    }
    return trim((string) $_POST[$key]);
}

/** A GET scalar as a trimmed string. */
function dj_get(string $key, string $default = ''): string
{
    if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
        return $default;
    }
    return trim((string) $_GET[$key]);
}

/** A POSTed list of scalars (checkbox arrays) as strings. @return string[] */
function dj_post_list(string $key): array
{
    if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
        return [];
    }
    $out = [];
    foreach ($_POST[$key] as $v) {
        if (is_scalar($v)) {
            $out[] = trim((string) $v);
        }
    }
    return $out;
}

/** A track's BPM for display: a whole number, or an em dash when unknown. */
function dj_bpm($v): string
{
    $n = is_numeric($v) ? (float) $v : 0.0;
    return $n > 0 ? (string) ((int) round($n)) : '—';
}

/** A fee for display. $zero is what an unpaid booking reads as. */
function dj_money($v, string $zero = '—'): string
{
    $n = is_numeric($v) ? (float) $v : 0.0;
    if ($n <= 0) {
        return $zero;
    }
    return '$' . Util::decimal(round($n, 2));
}

/** The 24 Camelot codes as [value, label] pairs, with a blank first entry. */
function dj_key_options(): array
{
    $out = [['', '—']];
    foreach (Music::camelotWheel() as $k) {
        $out[] = [$k, $k];
    }
    return $out;
}

/** Energy 1–10 as [value, label] pairs. */
function dj_energy_options(): array
{
    $out = [];
    for ($i = 1; $i <= 10; $i++) {
        $out[] = [(string) $i, (string) $i];
    }
    return $out;
}

/** The six crate buckets as [id, label] pairs. */
function dj_bucket_options(): array
{
    $out = [];
    foreach (Data::BUCKETS as $b) {
        $out[] = [$b['id'], $b['label']];
    }
    return $out;
}

/** The three prep states as [id, label] pairs. */
function dj_prep_options(): array
{
    $out = [];
    foreach (Data::PREP as $p) {
        $out[] = [$p['id'], $p['label']];
    }
    return $out;
}

/** The gig statuses the artifact offers. */
function dj_status_options(): array
{
    return [['enquiry', 'Enquiry'], ['confirmed', 'Confirmed'], ['played', 'Played']];
}

/** The crate sort select, in the artifact's wording. */
function dj_sort_options(): array
{
    return [
        ['added', 'Newest first'],
        ['bpm', 'By BPM'],
        ['key', 'By key'],
        ['energy', 'By energy'],
        ['title', 'By title'],
    ];
}

/** The current streak in whole days, counted back from today (the artifact's rule). */
function dj_streak(Db $db): int
{
    $set = [];
    foreach ($db->practiceDays() as $d) {
        $set[$d] = true;
    }
    $n = 0;
    $d = Util::today();
    for ($i = 0; $i < 400; $i++) {
        if (isset($set[$d])) {
            $n++;
        } elseif ($i > 0) {
            break;
        }
        $d = Util::isoAddDays($d, -1);
    }
    return $n;
}

/** The header number strip of DESIGN-DJ.md §9 / the artifact's readout. */
function dj_readout(Db $db): array
{
    $crate = $db->crateList();
    $ready = 0;
    foreach ($crate as $t) {
        if ((string) (isset($t['prep']) ? $t['prep'] : '') === 'ready') {
            $ready++;
        }
    }
    $pct  = $crate === [] ? 0 : (int) round($ready / count($crate) * 100);
    $next = $db->nextGig();
    return [
        ['Crate', count($crate)],
        ['Ready', $pct . '%'],
        ['Streak', dj_streak($db) . 'd'],
        ['Sets', count($db->setsList())],
        ['Next gig', ($next !== null && !empty($next['date'])) ? substr((string) $next['date'], 5) : '—'],
    ];
}

/* ====================================================================== */
/*                             setup and login                            */
/* ====================================================================== */

/**
 * The first-visit setup screen and its POST. Reachable only while
 * `password_hash` is empty; once a password is set this function is never
 * called again, so the route simply does not exist any more (§8).
 */
function dj_setup_request(array $cfg, string $action): string
{
    $error = '';
    if ($action === 'setup') {
        $pw      = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirm = isset($_POST['password2']) && is_string($_POST['password2']) ? $_POST['password2'] : '';
        $error   = Auth::passwordProblem($pw, $confirm);
        if ($error === '') {
            try {
                $cfg['password_hash'] = Auth::hashPassword($pw);
                $cfg['force_https']   = isset($_POST['force_https']);
                dj_save_config($cfg);
                Auth::login();
                Auth::flash('ok', 'The desk is yours. Start by putting ten tracks in the crate.');
                dj_redirect(Render::href('desk'));
            } catch (Throwable $e) {
                error_log('[dj] setup: ' . $e->getMessage());
                $error = 'Could not save the configuration. Check that dj/data/ is writable by PHP.';
            }
        }
    }
    // forceHttps() deliberately lets the setup screen through over plain http, so a
    // TLS-less host is not redirected away from its own first run (Auth::forceHttps).
    // That is exactly the one request carrying a brand-new password, so say so.
    $insecure = PHP_SAPI !== 'cli' && !Auth::isHttps();
    return Render::layout('Set up', dj_page_setup($error, !empty($cfg['force_https']), $insecure), ['nav' => false]);
}

function dj_page_setup(string $error, bool $forceHttps, bool $insecure = false): string
{
    $b  = Render::head('Set up the desk', 'One password, stored as a hash in dj/data/config.json. '
        . 'There is no account and no recovery — write it down.');
    $f  = '<form method="post" action="index.php" class="stack">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'setup']);
    if ($insecure) {
        $f .= '<p class="flash flash-warn" role="alert">'
            . Util::esc('This page is not encrypted. The password you type would cross the network in '
                . 'clear text — close this, open the same address as https:// instead, and only carry on '
                . 'over http if your domain genuinely has no TLS.')
            . '</p>';
    }
    if ($error !== '') {
        $f .= '<p class="flash flash-danger" role="alert">' . Util::esc($error) . '</p>';
    }
    $f .= '<div class="row">';
    $f .= Render::field('Password', Render::input('password', '', 'password',
        ['required' => true, 'autocomplete' => 'new-password', 'minlength' => (string) Auth::MIN_PASSWORD]), '');
    $f .= Render::field('Repeat it', Render::input('password2', '', 'password',
        ['required' => true, 'autocomplete' => 'new-password']), '');
    $f .= '</div>';
    // On a request that arrived in clear text the box starts UNTICKED. Ticking it
    // there is a one-way door: the moment the password is saved the setup route
    // closes, and every later request is 301'd to an https:// the host may not
    // answer — the operator would be locked out of a desk they just created, with
    // deleting dj/data/config.json the only way back in. Auth::isHttps() honours
    // X-Forwarded-Proto, so a proxy-terminated TLS host still gets it ticked.
    $httpsChecked = $forceHttps && !$insecure;
    $f .= '<label class="f"><input type="checkbox" name="force_https" value="1"'
        . ($httpsChecked ? ' checked' : '') . '> <span>Redirect http to https and set the secure cookie flag</span></label>';
    if ($insecure) {
        $f .= '<p class="muted">'
            . Util::esc('Left unticked because this request came in over plain http. Tick it once your '
                . 'domain serves https, or you will be redirected to an address that does not answer '
                . 'and locked out — deleting dj/data/config.json would be the only way back to this screen.')
            . '</p>';
    }
    $f .= '<div class="row"><button class="btn primary" type="submit">Set the password</button></div>';
    $f .= '</form>';
    return '<div class="login">' . $b . Render::card('First run', $f) . '</div>';
}

/** The login screen and its POST, with the per-IP lockout of §8. */
function dj_login_request(array $cfg, Db $db, string $action): string
{
    $error = '';
    if ($action === 'login') {
        $pw = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if (Auth::attemptLogin($cfg, $db, $pw)) {
            dj_redirect(Render::href('desk'));
        }
        $left = Auth::lockoutSeconds($db, Auth::clientIp());
        $error = $left > 0
            ? 'Too many attempts. This address is locked for another ' . (int) ceil($left / 60) . ' minute(s).'
            : 'Wrong password. After 5 failed attempts the address is locked for 15 minutes.';
    }
    return Render::layout('Log in', dj_page_login($error), ['nav' => false]);
}

function dj_page_login(string $error): string
{
    $f  = '<form method="post" action="index.php" class="stack">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'login']);
    if ($error !== '') {
        $f .= '<p class="flash flash-danger" role="alert">' . Util::esc($error) . '</p>';
    }
    $f .= Render::field('Password', Render::input('password', '', 'password',
        ['required' => true, 'autocomplete' => 'current-password', 'autofocus' => true]));
    $f .= '<div class="row"><button class="btn primary" type="submit">Open the desk</button></div>';
    $f .= '</form>';
    return '<div class="login">'
        . Render::head('9Bar', 'Uncle K. The crate, the bench and the path.')
        . Render::card('Log in', $f) . '</div>';
}

/* ====================================================================== */
/*                           the action dispatcher                        */
/* ====================================================================== */

/**
 * Every state change in the desk goes through here, and every one of them ends
 * in a redirect, so a reload never repeats a write. The CSRF token was already
 * verified in dj_main() before this function was reached (§8).
 */
function dj_action(string $action, array $cfg, Db $db): void
{
    $home = Render::href('desk');

    if ($action === 'logout') {
        Auth::logout();
        Auth::flash('ok', 'Logged out.');
        dj_redirect('index.php');
    }

    try {
        switch ($action) {
            case 'crate_add':
                dj_do_crate_add($db);
                break;
            case 'crate_import':
                dj_do_crate_import($db);
                break;
            case 'crate_seed':
                dj_do_crate_seed($db);
                break;
            case 'crate_update':
                dj_do_crate_update($db);
                break;
            case 'crate_delete':
                dj_do_crate_delete($db);
                break;
            case 'prep_save':
                dj_do_prep_save($db);
                break;
            case 'prep_reset':
                dj_do_prep_reset($db);
                break;
            case 'set_edit':
                dj_do_set_edit($db);
                break;
            case 'set_open':
                dj_do_set_open($db);
                break;
            case 'set_new':
                dj_draft_put(dj_draft_blank());
                Auth::flash('ok', 'Fresh set started.');
                break;
            case 'set_delete':
                dj_do_set_delete($db);
                break;
            case 'practice_add':
                dj_do_practice_add($db);
                break;
            case 'practice_quick':
                $db->practiceInsert(['date' => Util::today(), 'minutes' => 30,
                    'focus' => 'Quick session', 'rating' => 'OK']);
                Auth::flash('ok', 'Thirty minutes logged.');
                break;
            case 'practice_delete':
                $db->practiceDelete(dj_post('id'));
                Auth::flash('ok', 'Session removed.');
                break;
            case 'gig_add':
                dj_do_gig_add($db);
                break;
            case 'gig_update':
                dj_do_gig_update($db);
                break;
            case 'gig_delete':
                $db->gigDelete(dj_post('id'));
                Auth::flash('ok', 'Gig removed.');
                break;
            case 'promo_save':
                dj_do_promo_save($db);
                break;
            case 'press_save':
                dj_do_press_save($db);
                break;
            case 'skills_save':
                dj_do_meta_checklist($db, 'skills', count(Data::LADDER), 'Skill ladder saved.');
                break;
            case 'rig_save':
                dj_do_meta_checklist($db, 'rig', count(Data::RIG), 'Rig list saved.');
                break;
            default:
                Auth::flash('warn', 'Unknown action.');
                dj_redirect($home);
        }
    } catch (Throwable $e) {
        error_log('[dj] action ' . $action . ': ' . $e->getMessage());
        Auth::flash('danger', 'That change did not save. The details went to the server log.');
    }

    dj_redirect(dj_back($home));
}

/* --------------------------------------------------------------- crate */

/** One track from the add form. */
function dj_do_crate_add(Db $db): void
{
    $title = dj_post('title');
    if ($title === '') {
        Auth::flash('warn', 'A track needs a title.');
        return;
    }
    $db->crateInsert([
        'title'    => $title,
        'artist'   => dj_post('artist'),
        'bpm'      => is_numeric(dj_post('bpm')) ? (float) dj_post('bpm') : 0.0,
        'key'      => Music::normalizeKey(dj_post('key')),
        'energy'   => Util::clampInt((int) dj_post('energy', '5'), 1, 10),
        'bucket'   => dj_bucket_id(dj_post('bucket', 'house')),
        'prep'     => dj_prep_id(dj_post('prep', 'raw')),
        'tags'     => dj_post('tags'),
        'added_at' => Util::today(),
    ]);
    Auth::flash('ok', 'Added “' . $title . '” to the crate.');
}

/** The deterministic paste importer of §7 — the artifact's bulkPlain(), ported. */
function dj_do_crate_import(Db $db): void
{
    $raw  = isset($_POST['bulk']) && is_string($_POST['bulk']) ? $_POST['bulk'] : '';
    $rows = dj_parse_bulk($raw);
    if ($rows === []) {
        Auth::flash('warn', 'Nothing to import — paste one track per line.');
        return;
    }
    $n = $db->crateInsertMany($rows);
    Auth::flash('ok', $n . ' added. Check the BPMs against your software.');
}

/**
 * `Title | bpm | key | energy | bucket`, one per line, with the artifact's
 * tolerant "artist - title" split on the first field (hyphen, en dash or
 * middle dot surrounded by spaces).
 *
 * Pure: it only builds rows, so dj/tests/run.php can check it without a database.
 *
 * @return array[] crate rows ready for Db::crateInsert()
 */
function dj_parse_bulk(string $raw): array
{
    $out = [];
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    if (!is_array($lines)) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        $head   = ($parts[0] !== '') ? $parts[0] : $line;
        $artist = '';
        $title  = $head;
        $split  = preg_split('/\s+[-–·]\s+/u', $head);
        if (is_array($split) && count($split) > 1) {
            $artist = array_shift($split);
            $title  = implode(' - ', $split);
        }
        if ($title === '') {
            continue;
        }
        $bpm    = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 0.0;
        $energy = isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : 5;
        $out[] = [
            'title'    => $title,
            'artist'   => $artist,
            'bpm'      => $bpm > 0 ? $bpm : 0.0,
            'key'      => Music::normalizeKey(isset($parts[2]) ? $parts[2] : ''),
            'energy'   => Util::clampInt($energy, 1, 10),
            'bucket'   => dj_bucket_id(isset($parts[4]) ? $parts[4] : 'house'),
            'prep'     => 'raw',
            'tags'     => '',
            'added_at' => Util::today(),
        ];
    }
    return $out;
}

/** The six example rows of Data::DEMO, on request only. */
function dj_do_crate_seed(Db $db): void
{
    $rows = [];
    foreach (Data::DEMO as $row) {
        $row['added_at'] = Util::today();
        $rows[] = $row;
    }
    $db->crateInsertMany($rows);
    Auth::flash('ok', 'Six starter rows added — edit or delete freely.');
}

/** The prep state and tags edited from an opened crate row. */
function dj_do_crate_update(Db $db): void
{
    $id = dj_post('id');
    if ($id === '' || $db->crateGet($id) === null) {
        Auth::flash('warn', 'That track is gone.');
        return;
    }
    $fields = ['tags' => dj_post('tags'), 'prep' => dj_prep_id(dj_post('prep', 'raw'))];
    if (isset($_POST['key'])) {
        $fields['key'] = Music::normalizeKey(dj_post('key'));
    }
    if (isset($_POST['bpm'])) {
        $fields['bpm'] = is_numeric(dj_post('bpm')) ? (float) dj_post('bpm') : 0.0;
    }
    if (isset($_POST['energy'])) {
        $fields['energy'] = Util::clampInt((int) dj_post('energy', '5'), 1, 10);
    }
    if (isset($_POST['bucket'])) {
        $fields['bucket'] = dj_bucket_id(dj_post('bucket', 'house'));
    }
    $db->crateUpdate($id, $fields);
    Auth::flash('ok', 'Track updated.');
}

function dj_do_crate_delete(Db $db): void
{
    $id = dj_post('id');
    $row = $id === '' ? null : $db->crateGet($id);
    $db->crateDelete($id);
    Auth::flash('ok', $row === null ? 'Track removed.' : 'Removed “' . (string) $row['title'] . '”.');
}

/** A bucket id we recognise, falling back to house the way the artifact does. */
function dj_bucket_id(string $raw): string
{
    $raw = strtolower(trim($raw));
    foreach (Data::BUCKETS as $b) {
        if ($b['id'] === $raw) {
            return $raw;
        }
    }
    return 'house';
}

/** A prep-state id we recognise, falling back to raw. */
function dj_prep_id(string $raw): string
{
    $raw = strtolower(trim($raw));
    foreach (Data::PREP as $p) {
        if ($p['id'] === $raw) {
            return $raw;
        }
    }
    return 'raw';
}

/* ----------------------------------------------------------- prep bench */

/**
 * The 14 ticks of one track. The submitted list is the whole truth, and the
 * derived prep state is written alongside it (§5.3).
 */
function dj_do_prep_save(Db $db): void
{
    $id = dj_post('track_id');
    $track = $id === '' ? null : $db->crateGet($id);
    if ($track === null) {
        Auth::flash('warn', 'Pick a track on the left first.');
        return;
    }
    $valid = [];
    foreach (Music::prepFlat() as $step) {
        $valid[$step['id']] = true;
    }
    $done = [];
    foreach (dj_post_list('steps') as $stepId) {
        if (isset($valid[$stepId])) {
            $done[$stepId] = true;
        }
    }
    $prep = Music::prepDerive(['prep_done' => $done]);
    $db->crateUpdate($id, ['prep_done' => $done, 'prep' => $prep]);
    Auth::flash('ok', count($done) . ' / ' . Music::prepTotal() . ' ticked — '
        . Data::prepLabel($prep) . '.');
}

function dj_do_prep_reset(Db $db): void
{
    $id = dj_post('track_id');
    if ($id === '' || $db->crateGet($id) === null) {
        return;
    }
    $db->crateUpdate($id, ['prep_done' => [], 'prep' => 'raw']);
    Auth::flash('ok', 'Prep reset to raw.');
}

/* --------------------------------------------------------- set builder */

/**
 * The set under construction lives in the session, so an abandoned draft never
 * leaves an orphan row in `sets`. Saving hands the whole thing to
 * Db::setSave(), which writes the set and its items in one transaction (§6).
 */
function dj_draft_blank(): array
{
    return ['id' => '', 'name' => '', 'brief' => '', 'items' => []];
}

/** The current draft, normalised. */
function dj_draft(): array
{
    $d = null;
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['dj_draft']) && is_array($_SESSION['dj_draft'])) {
        $d = $_SESSION['dj_draft'];
    } elseif (isset($GLOBALS['dj_draft_fallback']) && is_array($GLOBALS['dj_draft_fallback'])) {
        $d = $GLOBALS['dj_draft_fallback'];
    }
    if (!is_array($d)) {
        return dj_draft_blank();
    }
    $out = dj_draft_blank();
    foreach (['id', 'name', 'brief'] as $k) {
        if (isset($d[$k]) && is_scalar($d[$k])) {
            $out[$k] = (string) $d[$k];
        }
    }
    if (isset($d['items']) && is_array($d['items'])) {
        foreach ($d['items'] as $it) {
            if (is_array($it)) {
                $out['items'][] = dj_draft_item($it);
            }
        }
    }
    return $out;
}

/** One draft row, holding exactly the set_items columns of §4. */
function dj_draft_item(array $in): array
{
    return [
        'track_id' => isset($in['track_id']) && is_scalar($in['track_id']) ? (string) $in['track_id'] : '',
        'title'    => isset($in['title']) && is_scalar($in['title']) ? (string) $in['title'] : '',
        'artist'   => isset($in['artist']) && is_scalar($in['artist']) ? (string) $in['artist'] : '',
        'bpm'      => isset($in['bpm']) && is_numeric($in['bpm']) ? (float) $in['bpm'] : 0.0,
        'key'      => isset($in['key']) && is_scalar($in['key']) ? (string) $in['key'] : '',
        'note'     => isset($in['note']) && is_scalar($in['note']) ? (string) $in['note'] : '',
    ];
}

function dj_draft_put(array $draft): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['dj_draft'] = $draft;
        return;
    }
    $GLOBALS['dj_draft_fallback'] = $draft;
}

/**
 * One POST round trip of the manual set builder (§6). The whole editor is a
 * single form with several submit buttons, so the name, the brief and every
 * transition note travel with each add, move and remove — nothing typed is
 * lost when a row is nudged up or down, and none of it needs JavaScript.
 */
function dj_do_set_edit(Db $db): void
{
    $draft = dj_draft();
    $draft['name']  = dj_post('name');
    $draft['brief'] = dj_post('brief');

    $notes = isset($_POST['note']) && is_array($_POST['note']) ? $_POST['note'] : [];
    foreach ($draft['items'] as $i => $item) {
        if (isset($notes[$i]) && is_scalar($notes[$i])) {
            $draft['items'][$i]['note'] = trim((string) $notes[$i]);
        }
    }

    $op = dj_post('op');
    $arg = '';
    $colon = strpos($op, ':');
    if ($colon !== false) {
        $arg = substr($op, $colon + 1);
        $op  = substr($op, 0, $colon);
    }
    $idx = ($arg === '' || !ctype_digit($arg)) ? -1 : (int) $arg;
    $count = count($draft['items']);

    if ($op === 'add') {
        $trackId = dj_post('track_id');
        $track   = $trackId === '' ? null : $db->crateGet($trackId);
        if ($track === null) {
            Auth::flash('warn', 'Pick a track from the crate first.');
        } else {
            $draft['items'][] = dj_draft_item([
                'track_id' => (string) $track['id'],
                'title'    => (string) $track['title'],
                'artist'   => isset($track['artist']) ? (string) $track['artist'] : '',
                'bpm'      => isset($track['bpm']) ? $track['bpm'] : 0.0,
                'key'      => isset($track['key']) ? (string) $track['key'] : '',
                'note'     => '',
            ]);
        }
    } elseif ($op === 'up' || $op === 'down') {
        $to = $idx + ($op === 'up' ? -1 : 1);
        if ($idx >= 0 && $idx < $count && $to >= 0 && $to < $count) {
            $moved = $draft['items'][$idx];
            array_splice($draft['items'], $idx, 1);
            array_splice($draft['items'], $to, 0, [$moved]);
        }
    } elseif ($op === 'rm') {
        if ($idx >= 0 && $idx < $count) {
            array_splice($draft['items'], $idx, 1);
        }
    } elseif ($op === 'clear') {
        $draft = dj_draft_blank();
        Auth::flash('ok', 'Builder cleared.');
    } elseif ($op === 'save') {
        if ($draft['items'] === []) {
            Auth::flash('warn', 'Put at least one track in the set before saving.');
        } else {
            if (trim($draft['name']) === '') {
                $draft['name'] = 'Set ' . Util::today();
            }
            $id = $db->setSave(
                ['id' => $draft['id'], 'name' => $draft['name'], 'brief' => $draft['brief'],
                 'created_at' => Util::today()],
                $draft['items']
            );
            $draft['id'] = $id;
            Auth::flash('ok', 'Saved “' . $draft['name'] . '” — ' . count($draft['items']) . ' tracks.');
        }
    }

    dj_draft_put($draft);
}

/** Reopen a saved set for editing — the thing the artifact cannot do (§5.4). */
function dj_do_set_open(Db $db): void
{
    $id  = dj_post('id');
    $set = $id === '' ? null : $db->setWithItems($id);
    if ($set === null) {
        Auth::flash('warn', 'That set is gone.');
        return;
    }
    $items = [];
    foreach ($set['items'] as $it) {
        $items[] = dj_draft_item($it);
    }
    dj_draft_put([
        'id'    => (string) $set['id'],
        'name'  => (string) $set['name'],
        'brief' => isset($set['brief']) ? (string) $set['brief'] : '',
        'items' => $items,
    ]);
    Auth::flash('ok', 'Opened “' . (string) $set['name'] . '” for editing.');
}

function dj_do_set_delete(Db $db): void
{
    $id = dj_post('id');
    if ($id === '') {
        return;
    }
    $db->setDelete($id);
    $draft = dj_draft();
    if ($draft['id'] === $id) {
        $draft['id'] = '';
        dj_draft_put($draft);
    }
    Auth::flash('ok', 'Set deleted.');
}

/* ------------------------------------------------------- practice, gigs */

function dj_do_practice_add(Db $db): void
{
    $date = dj_post('date');
    if (!Util::isDate($date)) {
        $date = Util::today();
    }
    $rating = dj_post('rating', 'OK');
    if (!in_array($rating, Data::RATINGS, true)) {
        $rating = 'OK';
    }
    $db->practiceInsert([
        'date'    => $date,
        'minutes' => Util::clampInt((int) dj_post('minutes', '30'), 0, 1440),
        'focus'   => dj_post('focus'),
        'rating'  => $rating,
    ]);
    Auth::flash('ok', 'Session logged.');
}

function dj_do_gig_add(Db $db): void
{
    $name = dj_post('name');
    if ($name === '') {
        Auth::flash('warn', 'A gig needs a name.');
        return;
    }
    $date = dj_post('date');
    $db->gigInsert([
        'name'   => $name,
        'venue'  => dj_post('venue'),
        'date'   => Util::isDate($date) ? $date : '',
        'fee'    => is_numeric(dj_post('fee')) ? (float) dj_post('fee') : 0.0,
        'status' => dj_gig_status(dj_post('status', 'confirmed')),
        'promo'  => [],
    ]);
    Auth::flash('ok', 'Gig added.');
}

function dj_do_gig_update(Db $db): void
{
    $id = dj_post('id');
    if ($id === '' || $db->gigGet($id) === null) {
        Auth::flash('warn', 'That gig is gone.');
        return;
    }
    // only the fields the form actually carried: the status select in the gigs
    // table sends nothing else, and must not blank the name or the fee
    $fields = [];
    if (isset($_POST['name']) && dj_post('name') !== '') {
        $fields['name'] = dj_post('name');
    }
    if (isset($_POST['venue'])) {
        $fields['venue'] = dj_post('venue');
    }
    if (isset($_POST['date'])) {
        $date = dj_post('date');
        $fields['date'] = Util::isDate($date) ? $date : '';
    }
    if (isset($_POST['fee'])) {
        $fields['fee'] = is_numeric(dj_post('fee')) ? (float) dj_post('fee') : 0.0;
    }
    if (isset($_POST['status'])) {
        $fields['status'] = dj_gig_status(dj_post('status', 'confirmed'));
    }
    if ($fields === []) {
        return;
    }
    $db->gigUpdate($id, $fields);
    Auth::flash('ok', 'Gig updated.');
}

function dj_gig_status(string $raw): string
{
    $raw = strtolower(trim($raw));
    foreach (dj_status_options() as $s) {
        if ($s[0] === $raw) {
            return $raw;
        }
    }
    return 'confirmed';
}

/* -------------------------------------------------------------- 9Bar */

/** The 13 promo ticks of one night, stored in gigs.promo (§5.7). */
function dj_do_promo_save(Db $db): void
{
    $id  = dj_post('gig_id');
    $gig = $id === '' ? null : $db->gigGet($id);
    if ($gig === null) {
        Auth::flash('warn', 'Pick a night first.');
        return;
    }
    $valid = [];
    foreach (Music::promoFlat() as $step) {
        $valid[$step['id']] = true;
    }
    $done = [];
    foreach (dj_post_list('steps') as $stepId) {
        if (isset($valid[$stepId])) {
            $done[$stepId] = true;
        }
    }
    $db->gigUpdate($id, ['promo' => $done]);
    Auth::flash('ok', count($done) . ' / ' . Music::promoTotal() . ' of the promo list done.');
}

/** The press kit (bio, rate, links, rider) in meta.press. */
function dj_do_press_save(Db $db): void
{
    $db->metaSet('press', [
        'bio'   => dj_post('bio'),
        'rate'  => dj_post('rate'),
        'links' => dj_post('links'),
        'rider' => dj_post('rider'),
    ]);
    Auth::flash('ok', 'Press kit saved.');
}

/* -------------------------------------------------------------- the path */

/**
 * A meta checklist keyed by position (meta.skills, meta.rig), exactly as the
 * artifact stores them.
 */
function dj_do_meta_checklist(Db $db, string $key, int $total, string $msg): void
{
    $done = [];
    foreach (dj_post_list('steps') as $raw) {
        if (ctype_digit($raw)) {
            $i = (int) $raw;
            if ($i >= 0 && $i < $total) {
                $done[(string) $i] = true;
            }
        }
    }
    $db->metaSet($key, $done);
    Auth::flash('ok', $msg);
}

/* ====================================================================== */
/*                                 pages                                  */
/* ====================================================================== */

/**
 * The full HTML document for one section. Separate from dj_main() so the
 * offline suite can render every route without a web server.
 */
function dj_page(string $page, array $cfg, Db $db): string
{
    $labels = [];
    foreach (Render::SECTIONS as $s) {
        $labels[$s[0]] = $s[1];
    }
    if (!isset($labels[$page]) && $page !== 'export') {
        $page = 'desk';
    }

    if ($page === 'crate') {
        $body = dj_page_crate($db);
    } elseif ($page === 'prep') {
        $body = dj_page_prep($db);
    } elseif ($page === 'set') {
        $body = dj_page_set($db);
    } elseif ($page === 'practice') {
        $body = dj_page_practice($db);
    } elseif ($page === 'gigs') {
        $body = dj_page_gigs($db);
    } elseif ($page === '9bar') {
        $body = dj_page_9bar($db);
    } elseif ($page === 'path') {
        $body = dj_page_path($db);
    } elseif ($page === 'export') {
        $body = dj_page_export($db);
    } else {
        $body = dj_page_desk($db);
    }

    $title = isset($labels[$page]) ? $labels[$page] : 'Export';
    return Render::layout($title, $body, [
        'page'    => $page,
        'readout' => dj_readout($db),
        'theme'   => isset($cfg['theme']) ? (string) $cfg['theme'] : 'auto',
    ]);
}

/* ---------------------------------------------------------------- desk */

/**
 * DESIGN-DJ.md §5.1 — crate health, the practice streak with its seven-day
 * strip, the next gig and the latest set, in the artifact's wording and counts.
 */
function dj_page_desk(Db $db): string
{
    $back  = Render::href('desk');
    $crate = $db->crateList();

    /* ---- crate health */
    $openers = 0;
    $peaks = 0;
    $unprepped = 0;
    $bridges = 0;
    foreach ($crate as $t) {
        $e = isset($t['energy']) && is_numeric($t['energy']) ? (int) $t['energy'] : 0;
        if ($e <= 4) {
            $openers++;
        }
        if ($e >= 8) {
            $peaks++;
        }
        if ((string) (isset($t['prep']) ? $t['prep'] : '') !== 'ready') {
            $unprepped++;
        }
        $tags = isset($t['tags']) ? (string) $t['tags'] : '';
        if ((string) (isset($t['bucket']) ? $t['bucket'] : '') === 'tool' || stripos($tags, 'bridge') !== false) {
            $bridges++;
        }
    }
    if ($crate === []) {
        $health = '<p class="muted">Crate is empty. Head to <b>Crate</b> and add your first ten tracks.</p>';
    } else {
        $health  = Render::kv('Openers (energy 1–4)', Util::esc($openers));
        $health .= Render::kv('Peak weapons (8–10)', Util::esc($peaks));
        $health .= Render::kv('Bridge tools', Util::esc($bridges));
        $health .= Render::kv('Still unprepped', Util::esc($unprepped), $unprepped > 0 ? 'bad' : 'good');
    }

    /* ---- practice */
    $streak  = dj_streak($db);
    $minutes = 0;
    foreach ($db->practiceList() as $p) {
        $minutes += isset($p['minutes']) ? (int) $p['minutes'] : 0;
    }
    $doneDays = [];
    foreach ($db->practiceDays() as $d) {
        $doneDays[$d] = true;
    }
    $week = [];
    for ($i = 6; $i >= 0; $i--) {
        $week[] = Util::isoAddDays(Util::today(), -$i);
    }
    $prac  = Render::kv('Current streak', Util::esc($streak . ' day' . ($streak === 1 ? '' : 's')));
    $prac .= Render::kv('Total logged', Util::esc(intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm'));
    $prac .= Render::streak($week, $doneDays);
    $prac .= '<p class="muted">Last seven days.</p>';

    /* ---- next gig */
    $gig = $db->nextGig();
    if ($gig === null) {
        $gigHtml = '<p class="muted">Nothing booked. The first one is usually a friend’s party — '
            . 'add it under <b>Gigs</b> so you prepare like it counts.</p>';
    } else {
        $gigHtml  = '<p><b>' . Util::esc($gig['name']) . '</b><br>'
            . '<span class="muted">' . Util::esc(isset($gig['venue']) ? $gig['venue'] : '') . '</span></p>';
        $gigHtml .= Render::kv('Date', Util::esc(isset($gig['date']) ? $gig['date'] : '—'));
        $gigHtml .= Render::kv('Fee', Util::esc(dj_money(isset($gig['fee']) ? $gig['fee'] : 0, 'unpaid')));
        $gigHtml .= Render::kv('Promo', Util::esc(Music::promoDoneCount($gig) . ' / ' . Music::promoTotal()));
    }

    /* ---- latest set */
    $sets = $db->setsList(['limit' => 1]);
    if ($sets === []) {
        $setHtml = '<p class="muted">No sets yet. Build one in <b>Set builder</b> once you have a dozen tracks in.</p>';
    } else {
        $last  = $db->setWithItems((string) $sets[0]['id']);
        $items = ($last === null) ? [] : $last['items'];
        $first = [];
        foreach (array_slice($items, 0, 3) as $it) {
            $first[] = Util::esc(isset($it['title']) ? $it['title'] : '');
        }
        $setHtml  = '<p><b>' . Util::esc($sets[0]['name']) . '</b><br>'
            . '<span class="muted">' . Util::esc(count($items) . ' track' . (count($items) === 1 ? '' : 's')) . '</span></p>';
        $setHtml .= '<p class="muted">' . implode(' → ', $first) . '</p>';
    }

    $quick = Render::actionForm('practice_quick', 'Log 30 min', ['back' => $back]);

    $body  = Render::head('The desk', 'Where the rig, the crate and the practice log meet. '
        . 'Everything here is yours and private.');
    $body .= '<div class="grid2">';
    $body .= Render::card('Crate health', $health);
    $body .= Render::card('Practice', $prac, ['head' => $quick]);
    $body .= Render::card('Next gig', $gigHtml);
    $body .= Render::card('Latest set', $setHtml);
    $body .= '</div>';
    return $body;
}

/* --------------------------------------------------------------- crate */

/**
 * DESIGN-DJ.md §5.2 — the track table, the bucket chips, the search box and
 * the sort select (all plain links and a GET form, so they work without
 * JavaScript), plus the add form and the deterministic paste importer.
 */
function dj_page_crate(Db $db): string
{
    $bucket = strtolower(dj_get('bucket', 'all'));
    if ($bucket !== 'all' && dj_bucket_id($bucket) !== $bucket) {
        $bucket = 'all';
    }
    $q    = dj_get('q');
    $sort = strtolower(dj_get('sort', 'added'));
    if (!isset(Db::CRATE_SORTS[$sort])) {
        $sort = 'added';
    }
    $open = dj_get('open');

    $view = ['bucket' => $bucket === 'all' ? '' : $bucket, 'q' => $q, 'sort' => $sort];
    $back = Render::href('crate', ['bucket' => $bucket === 'all' ? '' : $bucket, 'q' => $q,
        'sort' => $sort === 'added' ? '' : $sort, 'open' => $open]);

    $all  = $db->crateList();
    $rows = $db->crateList($view);

    $body  = Render::head('Crate', 'Every track with its BPM, Camelot key and energy. '
        . 'Open a row to see what mixes out of it.');
    $body .= dj_crate_addcard($back);

    if ($all === []) {
        return $body . Render::card('Your crate', dj_crate_demo($back));
    }

    $body .= dj_crate_filters($bucket, $q, $sort, $db);
    $body .= Render::card('', dj_crate_table($rows, $all, $open, $bucket, $q, $sort, $back),
        ['bodyClass' => 'card-b card-b-flush']);
    return $body;
}

/** The add-one form and the paste importer, with the §1 note where Claude used to be. */
function dj_crate_addcard(string $back): string
{
    $f  = '<form class="row" method="post" action="index.php">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'crate_add', 'back' => $back]);
    $f .= Render::field('Title', Render::input('title', '', 'text',
        ['required' => true, 'placeholder' => 'Kesariya (Remix)']), '');
    $f .= Render::field('Artist', Render::input('artist', '', 'text', ['placeholder' => 'Pritam']), '');
    $f .= Render::field('BPM', Render::input('bpm', '', 'number',
        ['min' => '0', 'max' => '300', 'step' => '0.5', 'placeholder' => '124']), 'f-narrow');
    $f .= Render::field('Key', Render::select('key', dj_key_options(), '8A'), 'f-narrow');
    $f .= Render::field('Energy', Render::select('energy', dj_energy_options(), '5'), 'f-narrow');
    $f .= Render::field('Bucket', Render::select('bucket', dj_bucket_options(), 'house'), 'f-narrow');
    $f .= Render::field('Prep', Render::select('prep', dj_prep_options(), 'raw'), 'f-narrow');
    $f .= '<button class="btn primary" type="submit">Add to crate</button>';
    $f .= '</form>';

    $b  = '<form class="stack" method="post" action="index.php">';
    $b .= Auth::csrfField() . Render::hiddenFields(['action' => 'crate_import', 'back' => $back]);
    $b .= Render::field('Paste a list — one track per line',
        '<textarea class="mono" name="bulk" rows="5" placeholder="Nucleya - Bass Rani | 140 | 5A | 9 | desi'
        . "\n" . 'Lost Stories - Mai Ni Meriye | 124 | 11B | 7 | house"></textarea>');
    $b .= '<p class="muted">The format is <code>Title | bpm | key | energy | bucket</code>. '
        . 'An <code>Artist - Title</code> first field is split for you; everything after the pipes is optional.</p>';
    $b .= Render::aiNote('The AI paste-parser — "Parse with Claude" — lives in the artifact version. '
        . 'This port keeps the deterministic pipe parser, which needs no network and no key.');
    $b .= '<div class="row"><button class="btn" type="submit">Parse as pipes</button></div>';
    $b .= '</form>';

    return Render::card('Add tracks', $f . '<details><summary>Paste a list</summary>' . $b . '</details>');
}

/** The example rows, shown only while the crate is empty. Verbatim from the artifact. */
function dj_crate_demo(string $back): string
{
    $h  = Render::note('Example rows — these are not in your crate yet. They show the shape of a '
        . 'Bollywood × house library: something to open on, a bridge tool to cross the tempo gap, '
        . 'an acapella to drop over a four-four.', 'demo-note');
    $h .= '<div class="tracks">';
    foreach (Data::DEMO as $t) {
        $h .= dj_track_row($t, '');
    }
    $h .= '</div>';
    $h .= '<div class="row">'
        . Render::actionForm('crate_seed', 'Add these six as starter rows', ['back' => $back],
            ['button' => 'btn primary'])
        . '<span class="muted">Or add your own above — these six are only a shape to copy.</span></div>';
    return $h;
}

/** Bucket chips (links), the search box and the sort select (one GET form). */
function dj_crate_filters(string $bucket, string $q, string $sort, Db $db): string
{
    $counts = $db->crateBuckets();
    $chips  = '<div class="chipbar">';
    $chips .= '<a class="chip" href="' . Util::esc(Render::href('crate',
            ['q' => $q, 'sort' => $sort === 'added' ? '' : $sort]))
        . '"' . ($bucket === 'all' ? ' aria-current="page"' : '') . '>All</a>';
    foreach (Data::BUCKETS as $b) {
        $n = isset($counts[$b['id']]) ? (int) $counts[$b['id']] : 0;
        $chips .= '<a class="chip" href="' . Util::esc(Render::href('crate',
                ['bucket' => $b['id'], 'q' => $q, 'sort' => $sort === 'added' ? '' : $sort])) . '"'
            . ($bucket === $b['id'] ? ' aria-current="page"' : '') . '>'
            . Util::esc($b['label']) . ' <span class="mono">' . Util::esc($n) . '</span></a>';
    }
    $chips .= '</div>';

    $f  = '<form class="row" method="get" action="index.php">';
    $f .= Render::hiddenFields(['page' => 'crate', 'bucket' => $bucket === 'all' ? '' : $bucket]);
    $f .= Render::field('Search', Render::input('q', $q, 'search',
        ['placeholder' => 'Search title, artist, tag']));
    $f .= Render::field('Sort', Render::select('sort', dj_sort_options(), $sort), 'f-narrow');
    $f .= '<button class="btn" type="submit">Apply</button>';
    if ($q !== '' || $sort !== 'added') {
        $f .= '<a class="btn ghost" href="' . Util::esc(Render::href('crate',
            ['bucket' => $bucket === 'all' ? '' : $bucket])) . '">Clear</a>';
    }
    $f .= '</form>';
    return '<div class="filters">' . $chips . '<span class="spacer"></span>' . $f . '</div>';
}

/** The track table itself, with the opened row's "mixes out of this" panel under it. */
function dj_crate_table(array $rows, array $all, string $open, string $bucket, string $q, string $sort, string $back): string
{
    if ($rows === []) {
        return Render::emptyState('Nothing matches that filter.');
    }
    $h = '<div class="tracks">';
    foreach ($rows as $t) {
        $id     = (string) $t['id'];
        $isOpen = ($open !== '' && $open === $id);
        $href   = Render::href('crate', [
            'bucket' => $bucket === 'all' ? '' : $bucket,
            'q'      => $q,
            'sort'   => $sort === 'added' ? '' : $sort,
            'open'   => $isOpen ? '' : $id,
        ]) . '#t-' . rawurlencode($id);
        $h .= dj_track_row($t, $href, $isOpen);
        if ($isOpen) {
            $h .= dj_crate_detail($t, $all, $back);
        }
    }
    return $h . '</div>';
}

/**
 * One crate row: energy stripe, title and artist, BPM, Camelot key, energy bar,
 * bucket tag, prep dot — the artifact's seven columns. With $href the whole row
 * is the link that opens and closes the detail panel; without one (the example
 * rows) it is inert.
 */
function dj_track_row(array $t, string $href, bool $isOpen = false): string
{
    $b      = Data::bucket(isset($t['bucket']) ? (string) $t['bucket'] : null);
    $bpm    = Music::bpm($t);
    $bridge = ((string) (isset($t['bucket']) ? $t['bucket'] : '') !== 'tool') && $bpm > 0 && $bpm < 112;
    $id     = isset($t['id']) ? (string) $t['id'] : '';

    $inner  = Render::energyStripe(isset($t['energy']) ? $t['energy'] : 0);
    $inner .= '<span class="ttl"><b>' . Util::esc($t['title'])
        . ($bridge ? ' ' . Render::tag('bridge', 'b-desi') : '') . '</b>'
        . '<small>' . Util::esc((isset($t['artist']) && $t['artist'] !== '') ? $t['artist'] : '—')
        . (!empty($t['tags']) ? ' · ' . Util::esc($t['tags']) : '') . '</small></span>';
    $inner .= '<span class="num mono">' . Util::esc(dj_bpm($bpm)) . ' <em>bpm</em></span>';
    $inner .= '<span class="num mono">' . Util::esc(!empty($t['key']) ? $t['key'] : '—') . '</span>';
    $inner .= '<span class="e-cell">' . Render::energyBar(isset($t['energy']) ? $t['energy'] : 0) . '</span>';
    $inner .= '<span class="t-cell">' . Render::tag($b['label'], $b['cls']) . '</span>';
    $inner .= '<span>' . Render::prepDot(isset($t['prep']) ? (string) $t['prep'] : 'raw') . '</span>';

    if ($href === '') {
        // the example rows: inert, because they are not in anybody's crate yet
        return '<div class="tr">' . $inner . '</div>';
    }
    return '<a class="tr' . ($isOpen ? ' on' : '') . '" id="t-' . Util::esc($id) . '"'
        . ' href="' . Util::esc($href) . '" aria-expanded="' . ($isOpen ? 'true' : 'false') . '">'
        . $inner . '</a>';
}

/** What mixes out of one track: Music::matchesFor() with its plain reasons (§5.2). */
function dj_crate_detail(array $t, array $all, string $back): string
{
    $id = (string) $t['id'];
    $matches = Music::matchesFor($t, $all);

    $h = '<div class="detail"><h4>Mixes out of this</h4>';
    if ($matches === []) {
        $key = Music::normalizeKey(isset($t['key']) ? $t['key'] : '');
        $h .= '<p class="muted">Nothing in the crate sits next to ' . Util::esc($key === '' ? 'this key' : $key)
            . ' inside ±6% tempo. That is a gap worth filling.</p>';
    } else {
        foreach ($matches as $m) {
            $o = $m['track'];
            $h .= '<div class="match">' . Render::prepDot(isset($o['prep']) ? (string) $o['prep'] : 'raw')
                . '<b>' . Util::esc($o['title']) . '</b>'
                . '<span class="mono muted">' . Util::esc(!empty($o['key']) ? $o['key'] : '') . '</span>'
                . '<span class="why">' . Util::esc($m['why']) . '</span></div>';
        }
    }

    $f  = '<form class="row" method="post" action="index.php">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'crate_update', 'id' => $id, 'back' => $back]);
    $f .= Render::field('BPM', Render::input('bpm', Music::bpm($t) > 0 ? Util::decimal(Music::bpm($t)) : '',
        'number', ['min' => '0', 'max' => '300', 'step' => '0.5']), 'f-narrow');
    $f .= Render::field('Key', Render::select('key', dj_key_options(),
        Music::normalizeKey(isset($t['key']) ? $t['key'] : '')), 'f-narrow');
    $f .= Render::field('Energy', Render::select('energy', dj_energy_options(),
        isset($t['energy']) ? (string) (int) $t['energy'] : '5'), 'f-narrow');
    $f .= Render::field('Bucket', Render::select('bucket', dj_bucket_options(),
        isset($t['bucket']) ? (string) $t['bucket'] : 'house'), 'f-narrow');
    $f .= Render::field('Prep state', Render::select('prep', dj_prep_options(),
        isset($t['prep']) ? (string) $t['prep'] : 'raw'), 'f-narrow');
    $f .= Render::field('Tags', Render::input('tags', isset($t['tags']) ? $t['tags'] : '', 'text',
        ['placeholder' => 'opener, dhol, stem swap']), '');
    $f .= '<button class="btn primary" type="submit">Save track</button>';
    $f .= '</form>';

    $h .= $f;
    $h .= '<div class="row row-end">'
        . '<a class="btn" href="' . Util::esc(Render::href('prep', ['track' => $id, 'all' => '1'])) . '">Prep bench →</a>'
        . Render::actionForm('crate_delete', 'Delete', ['id' => $id, 'back' => Render::href('crate')],
            ['button' => 'btn ghost danger', 'confirm' => 'Delete this track from the crate?'])
        . '</div>';
    return $h . '</div>';
}

/* ---------------------------------------------------------- prep bench */

/**
 * DESIGN-DJ.md §5.3 — the 14-step Bollywood checklist in its 5 phases, the
 * explanatory text copied verbatim by Data::PREPSTEPS. Ticking derives the
 * track's prep state; the queue defaults to the bollywood / desi / edit
 * buckets with an all-buckets toggle.
 */
function dj_page_prep(Db $db): string
{
    $showAll = dj_get('all') !== '';
    $pick    = dj_get('track');

    $filter = $showAll ? [] : ['buckets' => ['bollywood', 'desi', 'edit']];
    $queue  = dj_prep_queue($db->crateList($filter));

    $track = null;
    if ($pick !== '') {
        $track = $db->crateGet($pick);
    }
    if ($track === null && $queue !== []) {
        $track = $queue[0];
    }
    $trackId = $track === null ? '' : (string) $track['id'];
    $back    = Render::href('prep', ['track' => $trackId, 'all' => $showAll ? '1' : '']);

    $total = Music::prepTotal();

    /* ---- the queue on the left */
    $scopeHref = Render::href('prep', ['track' => $trackId, 'all' => $showAll ? '' : '1']);
    $q  = '<div class="row"><a class="chip' . ($showAll ? ' on' : '') . '" href="'
        . Util::esc($scopeHref) . '">'
        . Util::esc($showAll ? 'Bollywood family only' : 'Show all buckets') . '</a></div>';
    if ($queue === []) {
        $q .= '<p class="muted">' . Util::esc($db->crateCount() > 0
            ? 'No Bollywood, desi or edit tracks in the crate yet.'
            : 'Crate is empty.') . '</p>';
    } else {
        $q .= '<div class="queue">';
        foreach ($queue as $t) {
            $id = (string) $t['id'];
            $n  = Music::prepDoneCount($t);
            $q .= '<a class="qi" href="' . Util::esc(Render::href('prep',
                    ['track' => $id, 'all' => $showAll ? '1' : ''])) . '"'
                . ($id === $trackId ? ' aria-current="true"' : '') . '>'
                . '<b>' . Util::esc($t['title']) . '</b>'
                . Render::meter($n, $total, $n . '/' . $total . ($n >= $total ? ' · ready' : ''))
                . '</a>';
        }
        $q .= '</div>';
    }

    /* ---- the bench on the right */
    if ($track === null) {
        $bench = Render::note('Reference view — add a Bollywood, desi or edit track to the crate and '
            . 'pick it on the left to start ticking these off per track.');
        $bench .= Render::checklist(Data::PREPSTEPS, [], [
            'action'   => 'prep_save',
            'hidden'   => ['track_id' => '', 'back' => $back],
            'idPrefix' => 'ps',
            'disabled' => true,
            'submit'   => 'Save ticks',
        ]);
    } else {
        $n = Music::prepDoneCount($track);
        $head  = '<div class="benchhead"><b>' . Util::esc($track['title']) . '</b>'
            . Render::tag((string) ((isset($track['artist']) && $track['artist'] !== '') ? $track['artist'] : '—'))
            . '<span class="mono muted">'
            . Util::esc((Music::bpm($track) > 0 ? dj_bpm(Music::bpm($track)) . ' BPM · ' : '')
                . (!empty($track['key']) ? (string) $track['key'] : 'no key')) . '</span>'
            . '<span class="spacer"></span>'
            . Render::meter($n, $total)
            . Render::actionForm('prep_reset', 'Reset', ['track_id' => $trackId, 'back' => $back],
                ['button' => 'btn ghost sm', 'confirm' => 'Clear every tick on this track?'])
            . '</div>';
        $bench = $head . Render::checklist(Data::PREPSTEPS, Music::prepDone($track), [
            'action'   => 'prep_save',
            'hidden'   => ['track_id' => $trackId, 'back' => $back],
            'idPrefix' => 'ps',
            'submit'   => 'Save ticks',
            'footer'   => '<span class="muted">Finishing the grid steps marks this track <b>gridded</b> '
                . 'in the crate. Finishing all fourteen marks it <b>cued + ready</b>.</span>',
        ]);
    }

    $body  = Render::head('Prep bench', 'Bollywood originals are not built for mixing: the grids drift, '
        . 'the intros are free-time, and the endings stop dead. Fourteen steps per track, in the order '
        . 'you actually do them.');
    $body .= Render::card('', '<div class="bench"><div>' . $q . '</div>'
        . '<div>' . $bench . '</div></div>');
    return $body;
}

/** The artifact's queue order: unfinished tracks first, then most-prepped first. */
function dj_prep_queue(array $rows): array
{
    $total = Music::prepTotal();
    $keyed = [];
    foreach ($rows as $i => $t) {
        $n = Music::prepDoneCount($t);
        $keyed[] = ['i' => $i, 'row' => $t, 'n' => $n, 'full' => $n >= $total ? 1 : 0];
    }
    usort($keyed, static function (array $a, array $b): int {
        if ($a['full'] !== $b['full']) {
            return $a['full'] - $b['full'];
        }
        if ($a['n'] !== $b['n']) {
            return $b['n'] - $a['n'];
        }
        return $a['i'] - $b['i'];
    });
    $out = [];
    foreach ($keyed as $k) {
        $out[] = $k['row'];
    }
    return $out;
}

/* --------------------------------------------------------- set builder */

/**
 * DESIGN-DJ.md §5.4 and §6 — the manual set builder. The whole editor is ONE
 * form with several named submit buttons, so every action (add, up, down,
 * remove, save) is a single POST round trip that also carries the name, the
 * brief and every transition note. No JavaScript is involved anywhere.
 */
function dj_page_set(Db $db): string
{
    $back  = Render::href('set');
    $draft = dj_draft();
    $items = $draft['items'];
    $crate = $db->crateList(['sort' => 'title']);

    /* ---- the live readout of §6 */
    $count = count($items);
    $bpms  = [];
    foreach ($items as $it) {
        $b = Music::bpm($it);
        if ($b > 0) {
            $bpms[] = $b;
        }
    }
    if ($bpms === []) {
        $span = '—';
    } elseif (count($bpms) === 1) {
        $span = dj_bpm($bpms[0]) . ' BPM';
    } else {
        $span = dj_bpm(min($bpms)) . '–' . dj_bpm(max($bpms)) . ' BPM ('
            . dj_bpm(max($bpms) - min($bpms)) . ' span)';
    }
    $warnings = [];
    for ($i = 0; $i + 1 < $count; $i++) {
        $w = Music::joinWarning($items[$i], $items[$i + 1]);
        if ($w !== '') {
            $warnings[$i] = $w;
        }
    }

    $readout  = '<div class="grid3">';
    $readout .= Render::kv('Tracks', Util::esc($count));
    $readout .= Render::kv('BPM span', Util::esc($span));
    $readout .= Render::kv('Rough joins', Util::esc(count($warnings)),
        $warnings === [] ? 'good' : 'bad');
    $readout .= '</div>';
    $readout .= '<p class="muted">A join is flagged when the two tracks are neither Camelot-compatible '
        . 'nor within 6% of each other on tempo. It is a prompt to plan the move, not a rule.</p>';

    /* ---- the editor */
    $f  = '<form class="stack" method="post" action="index.php">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'set_edit', 'back' => $back]);
    $f .= '<div class="row">';
    $f .= Render::field('Set name', Render::input('name', $draft['name'], 'text',
        ['placeholder' => 'Friday, 9Bar at the rooftop']), '');
    $f .= Render::field('The room', Render::input('brief', $draft['brief'], 'text',
        ['placeholder' => 'Mixed crowd, they know the Bollywood hooks but want to dance']), '');
    $f .= '</div>';

    if ($items === []) {
        $f .= Render::emptyState('Nothing in the set yet. Pick a track below and press Add.');
    } else {
        $f .= '<div class="setlist">';
        foreach ($items as $i => $it) {
            $f .= '<div class="setitem">';
            $f .= '<span class="idx mono">' . Util::esc(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)) . '</span>';
            $f .= '<div><b>' . Util::esc($it['title']) . '</b>';
            $f .= '<small>' . Util::esc($it['artist'] !== '' ? $it['artist'] : '—')
                . (Music::bpm($it) > 0 ? ' · ' . Util::esc(dj_bpm($it['bpm']) . ' BPM') : '')
                . ($it['key'] !== '' ? ' · ' . Util::esc($it['key']) : '') . '</small>';
            $f .= '<div class="note">' . Render::field('Transition note',
                Render::input('note[' . $i . ']', $it['note'], 'text',
                    ['placeholder' => 'Bass out on the 1, ride the dhol for 16'])) . '</div>';
            $f .= '</div>';
            $f .= '<span class="ctl">';
            $f .= '<button class="btn ghost sm" type="submit" name="op" value="up:' . Util::esc($i) . '"'
                . ($i === 0 ? ' disabled' : '') . ' title="Move up" aria-label="Move up">↑</button>';
            $f .= '<button class="btn ghost sm" type="submit" name="op" value="down:' . Util::esc($i) . '"'
                . ($i === $count - 1 ? ' disabled' : '') . ' title="Move down" aria-label="Move down">↓</button>';
            $f .= '<button class="btn ghost sm danger" type="submit" name="op" value="rm:' . Util::esc($i) . '"'
                . ' title="Remove" aria-label="Remove">✕</button>';
            $f .= '</span>';
            $f .= '</div>';
            if (isset($warnings[$i])) {
                $f .= '<p class="join">' . Render::pill('rough join', 'warn') . ' '
                    . Util::esc($warnings[$i]) . '</p>';
            }
        }
        $f .= '</div>';
    }

    /* ---- add from the crate, and the buttons */
    $f .= '<div class="row">';
    if ($crate === []) {
        $f .= '<p class="muted">The crate is empty — add tracks under <b>Crate</b> first.</p>';
    } else {
        $opts = [];
        foreach ($crate as $t) {
            $label = (string) $t['title'];
            if (!empty($t['artist'])) {
                $label .= ' — ' . (string) $t['artist'];
            }
            $meta = [];
            if (Music::bpm($t) > 0) {
                $meta[] = dj_bpm($t['bpm']);
            }
            if (!empty($t['key'])) {
                $meta[] = (string) $t['key'];
            }
            if ($meta !== []) {
                $label .= ' (' . implode(' · ', $meta) . ')';
            }
            $opts[] = [(string) $t['id'], $label];
        }
        $f .= Render::field('Add from the crate', Render::select('track_id', $opts, null), '');
        $f .= '<button class="btn" type="submit" name="op" value="add">Add to set</button>';
    }
    $f .= '</div>';
    $f .= '<div class="row">';
    $f .= '<button class="btn primary" type="submit" name="op" value="save">'
        . Util::esc($draft['id'] === '' ? 'Save this set' : 'Save changes') . '</button>';
    $f .= '<button class="btn ghost" type="submit" name="op" value="clear" data-confirm="Clear the builder?">'
        . 'Clear the builder</button>';
    $f .= '</div>';
    $f .= '</form>';

    $title = $draft['id'] === '' ? 'New set' : 'Editing a saved set';

    $body  = Render::head('Set builder', 'Pick tracks out of the crate and put them in order. '
        . 'The readout flags every join that is neither Camelot-compatible nor within 6% on tempo, '
        . 'so you can see the rough moves before the room does.');
    $body .= Render::aiNote('The AI set builder — "Build the set" and "Find my crate gaps" — lives in the '
        . 'artifact version. This is the manual builder: you order the set, the desk checks the joins.');
    $body .= Render::card($title, $readout . $f, ['head' => Render::actionForm('set_new', 'Start a new set',
        ['back' => $back], ['button' => 'btn ghost sm'])]);
    $body .= Render::card('Saved sets', dj_saved_sets($db, $draft['id'], $back));
    return $body;
}

/** Saved sets, newest first, each reopenable for editing. */
function dj_saved_sets(Db $db, string $currentId, string $back): string
{
    $sets = $db->setsList();
    if ($sets === []) {
        return Render::emptyState('Nothing saved yet.');
    }
    $rows = [];
    foreach ($sets as $s) {
        $id    = (string) $s['id'];
        $items = $db->setItems($id);
        $ops   = Render::actionForm('set_open', $id === $currentId ? 'Reload' : 'Open',
                ['id' => $id, 'back' => $back], ['button' => 'btn ghost sm'])
            . Render::actionForm('set_delete', 'Delete', ['id' => $id, 'back' => $back],
                ['button' => 'btn ghost sm danger', 'confirm' => 'Delete this set?']);
        $rows[] = [
            '<b>' . Util::esc($s['name']) . '</b>' . ($id === $currentId ? ' ' . Render::pill('open', 'accent') : '')
                . '<small class="muted">' . Util::esc(isset($s['brief']) ? $s['brief'] : '') . '</small>',
            '<span class="mono">' . Util::esc(count($items)) . '</span>',
            Util::esc(isset($s['created_at']) ? $s['created_at'] : ''),
            '<span class="row">' . $ops . '</span>',
        ];
    }
    return Render::table([
        ['label' => 'Set'],
        ['label' => 'Tracks', 'cls' => 'n'],
        ['label' => 'Created'],
        ['label' => ''],
    ], $rows);
}

/* ------------------------------------------------------------ practice */

/** DESIGN-DJ.md §5.5 — date, minutes, focus, rating. This is what drives the streak. */
function dj_page_practice(Db $db): string
{
    $back = Render::href('practice');

    $f  = '<form class="row" method="post" action="index.php">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'practice_add', 'back' => $back]);
    $f .= Render::field('Date', Render::input('date', Util::today(), 'date', ['required' => true]), 'f-narrow');
    $f .= Render::field('Minutes', Render::input('minutes', '30', 'number',
        ['min' => '5', 'step' => '5']), 'f-narrow');
    $f .= Render::field('Focus', Render::input('focus', '', 'text',
        ['placeholder' => 'EQ swap on the phrase, no sync']), '');
    $f .= Render::field('Felt like', Render::select('rating', Data::RATINGS, 'OK'), 'f-narrow');
    $f .= '<button class="btn primary" type="submit">Log it</button>';
    $f .= '</form>';

    $sessions = $db->practiceList();
    $rows = [];
    foreach ($sessions as $p) {
        $rows[] = [
            '<span class="mono">' . Util::esc($p['date']) . '</span>',
            Util::esc(!empty($p['focus']) ? $p['focus'] : '—'),
            '<span class="mono">' . Util::esc((int) $p['minutes']) . 'm</span>',
            Render::tag((string) (isset($p['rating']) ? $p['rating'] : '')),
            Render::actionForm('practice_delete', '✕', ['id' => (string) $p['id'], 'back' => $back],
                ['button' => 'btn ghost sm danger', 'confirm' => 'Remove this session?']),
        ];
    }

    $streak = dj_streak($db);
    $mins = 0;
    foreach ($sessions as $p) {
        $mins += (int) $p['minutes'];
    }
    $head = '<span class="mono muted">' . Util::esc($streak . 'd streak · ' . intdiv($mins, 60) . 'h '
        . ($mins % 60) . 'm logged') . '</span>';

    $body  = Render::head('Practice log', 'Riyaaz. The only thing that separates a person with a '
        . 'controller from a DJ.');
    $body .= Render::card('Log a session', $f);
    $body .= Render::card('Sessions', Render::table([
        ['label' => 'Date'],
        ['label' => 'Focus'],
        ['label' => 'Minutes', 'cls' => 'n'],
        ['label' => 'Felt like'],
        ['label' => ''],
    ], $rows, 'No sessions logged. Thirty focused minutes beats three unfocused hours.'), ['head' => $head]);
    return $body;
}

/* ---------------------------------------------------------------- gigs */

/** DESIGN-DJ.md §5.6 — event, venue, date, fee, status. */
function dj_page_gigs(Db $db): string
{
    $back = Render::href('gigs');

    $f  = '<form class="row" method="post" action="index.php">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'gig_add', 'back' => $back]);
    $f .= Render::field('Event', Render::input('name', '', 'text',
        ['required' => true, 'placeholder' => 'Diwali house party']), '');
    $f .= Render::field('Venue', Render::input('venue', '', 'text',
        ['placeholder' => 'Rohan’s rooftop']), '');
    $f .= Render::field('Date', Render::input('date', '', 'date'), 'f-narrow');
    $f .= Render::field('Fee', Render::input('fee', '0', 'number', ['min' => '0', 'step' => '25']), 'f-narrow');
    $f .= Render::field('Status', Render::select('status', dj_status_options(), 'confirmed'), 'f-narrow');
    $f .= '<button class="btn primary" type="submit">Add gig</button>';
    $f .= '</form>';

    $gigs = $db->gigsList(['sort' => 'upcoming']);
    $rows = [];
    $total = 0.0;
    foreach ($gigs as $g) {
        $id = (string) $g['id'];
        $total += is_numeric($g['fee']) ? (float) $g['fee'] : 0.0;
        $rows[] = [
            '<b>' . Util::esc($g['name']) . '</b><small class="muted">'
                . Util::esc(isset($g['venue']) ? $g['venue'] : '') . '</small>',
            '<span class="mono">' . Util::esc(!empty($g['date']) ? $g['date'] : '—') . '</span>',
            '<span class="mono">' . Util::esc(dj_money(isset($g['fee']) ? $g['fee'] : 0)) . '</span>',
            dj_gig_status_form($g, $back),
            Render::meter(Music::promoDoneCount($g), Music::promoTotal()),
            '<span class="row">'
                . '<a class="btn ghost sm" href="' . Util::esc(Render::href('9bar', ['gig' => $id])) . '">Promo →</a>'
                . Render::actionForm('gig_delete', '✕', ['id' => $id, 'back' => $back],
                    ['button' => 'btn ghost sm danger', 'confirm' => 'Remove this gig?'])
                . '</span>',
        ];
    }
    $head = $gigs === [] ? '' : '<span class="mono muted">'
        . Util::esc(count($gigs) . ' booked · ' . dj_money($total, '$0') . ' in fees') . '</span>';

    $body  = Render::head('Gigs', 'From the first bar slot to the first booking with a fee attached.');
    $body .= Render::card('Add a gig', $f);
    $body .= Render::card('Nights', Render::table([
        ['label' => 'Event'],
        ['label' => 'Date'],
        ['label' => 'Fee', 'cls' => 'n'],
        ['label' => 'Status'],
        ['label' => 'Promo'],
        ['label' => ''],
    ], $rows, 'No gigs yet.'), ['head' => $head]);
    return $body;
}

/**
 * The status select that sits in the gigs table. It is the "Marked played" line
 * of the promo list — that step says to update it under Gigs — so it posts on
 * its own and carries nothing else.
 */
function dj_gig_status_form(array $gig, string $back): string
{
    $f  = '<form class="inline" method="post" action="index.php">';
    $f .= Auth::csrfField();
    $f .= Render::hiddenFields(['action' => 'gig_update', 'id' => (string) $gig['id'], 'back' => $back]);
    $f .= Render::select('status', dj_status_options(),
        isset($gig['status']) ? (string) $gig['status'] : 'confirmed', ['aria-label' => 'Status']);
    $f .= '<button class="btn ghost sm" type="submit">Set</button>';
    return $f . '</form>';
}

/* ---------------------------------------------------------------- 9Bar */

/**
 * DESIGN-DJ.md §5.7 — the brand desk: nights upcoming-first with a promo
 * progress meter, the 13-step PROMOSTEPS checklist per gig stored in
 * gigs.promo, a copy-ready numbered tracklist built from any saved set, and
 * the press kit in meta.press.
 */
function dj_page_9bar(Db $db): string
{
    $gigId = dj_get('gig');
    $setId = dj_get('set');
    $back  = Render::href('9bar', ['gig' => $gigId, 'set' => $setId]);

    $gigs = $db->gigsList(['sort' => 'upcoming']);
    $gig  = $gigId === '' ? null : $db->gigGet($gigId);
    if ($gig === null && $gigs !== []) {
        $gig = $gigs[0];
    }
    $gigId = $gig === null ? '' : (string) $gig['id'];

    $body = Render::head('9Bar', 'The brand desk. Every night, the promo list that fills the room, '
        . 'the tracklist people always ask for, and the press kit you send when someone asks what you do.');

    /* ---- the nights */
    $nights = '';
    if ($gigs === []) {
        $nights = Render::emptyState('No nights yet. Add one under Gigs and the promo list appears here.');
    } else {
        $nights .= '<div class="queue">';
        foreach ($gigs as $g) {
            $id = (string) $g['id'];
            $n  = Music::promoDoneCount($g);
            $nights .= '<a class="qi" href="' . Util::esc(Render::href('9bar', ['gig' => $id, 'set' => $setId]))
                . '"' . ($id === $gigId ? ' aria-current="true"' : '') . '>'
                . '<b>' . Util::esc($g['name']) . '</b>'
                . '<small class="muted">' . Util::esc((!empty($g['date']) ? (string) $g['date'] : 'no date')
                    . ' · ' . (!empty($g['venue']) ? (string) $g['venue'] : '—')) . '</small>'
                . Render::meter($n, Music::promoTotal())
                . '</a>';
        }
        $nights .= '</div>';
    }

    /* ---- the promo checklist for the chosen night */
    if ($gig === null) {
        $promo = Render::note('Reference view — add a night under Gigs and pick it on the left to start '
            . 'ticking these off per night.')
            . Render::checklist(Data::PROMOSTEPS, [], [
                'action'   => 'promo_save',
                'hidden'   => ['gig_id' => '', 'back' => $back],
                'idPrefix' => 'pm',
                'disabled' => true,
                'submit'   => 'Save the promo list',
            ]);
    } else {
        $n = Music::promoDoneCount($gig);
        $promo  = '<div class="benchhead"><b>' . Util::esc($gig['name']) . '</b>'
            . Render::tag((string) (!empty($gig['venue']) ? $gig['venue'] : '—'))
            . '<span class="mono muted">' . Util::esc(!empty($gig['date']) ? (string) $gig['date'] : 'no date')
            . '</span><span class="spacer"></span>' . Render::meter($n, Music::promoTotal()) . '</div>';
        $promo .= Render::checklist(Data::PROMOSTEPS, Music::promoDone($gig), [
            'action'   => 'promo_save',
            'hidden'   => ['gig_id' => $gigId, 'back' => $back],
            'idPrefix' => 'pm',
            'submit'   => 'Save the promo list',
        ]);
    }

    $body .= Render::card('The nights', '<div class="bench"><div>' . $nights . '</div>'
        . '<div>' . $promo . '</div></div>');

    /* ---- the copy-ready tracklist */
    $body .= Render::card('Tracklist', dj_9bar_tracklist($db, $setId));

    /* ---- the press kit */
    $body .= Render::card('Press kit', dj_9bar_press($db, $back));
    return $body;
}

/** A numbered tracklist built from any saved set — the thing people ask for after a night. */
function dj_9bar_tracklist(Db $db, string $setId): string
{
    $sets = $db->setsList();
    if ($sets === []) {
        return Render::emptyState('Save a set in the Set builder and its tracklist appears here, '
            . 'numbered and ready to paste.');
    }
    $set = $setId === '' ? null : $db->setWithItems($setId);
    if ($set === null) {
        $set = $db->setWithItems((string) $sets[0]['id']);
    }
    if ($set === null) {
        return Render::emptyState('That set is gone.');
    }

    $opts = [];
    foreach ($sets as $s) {
        $opts[] = [(string) $s['id'], (string) $s['name']];
    }
    $f  = '<form class="row" method="get" action="index.php">';
    $f .= Render::hiddenFields(['page' => '9bar', 'gig' => dj_get('gig')]);
    $f .= Render::field('Set', Render::select('set', $opts, (string) $set['id']), '');
    $f .= '<button class="btn" type="submit">Show</button>';
    $f .= '</form>';

    $lines = [];
    $i = 1;
    foreach ($set['items'] as $it) {
        $title  = isset($it['title']) ? (string) $it['title'] : '';
        $artist = isset($it['artist']) ? (string) $it['artist'] : '';
        $lines[] = str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '. '
            . ($artist !== '' ? $artist . ' — ' : '') . $title;
        $i++;
    }
    $text = '9Bar · ' . (string) $set['name'] . "\n" . implode("\n", $lines);

    return $f . Render::copyBlock('tracklist', $text, 'Copy the tracklist');
}

/** The press kit in meta.press: bio, rate, links, rider. */
function dj_9bar_press(Db $db, string $back): string
{
    $press = $db->metaArray('press');
    $get = static function (array $p, string $k): string {
        return isset($p[$k]) && is_scalar($p[$k]) ? (string) $p[$k] : '';
    };
    $bio   = $get($press, 'bio');
    $rate  = $get($press, 'rate');
    $links = $get($press, 'links');
    $rider = $get($press, 'rider');

    $f  = '<form class="stack" method="post" action="index.php">';
    $f .= Auth::csrfField() . Render::hiddenFields(['action' => 'press_save', 'back' => $back]);
    $f .= Render::field('Bio', '<textarea name="bio" rows="4" placeholder="Two sentences. Who you are, '
        . 'what a 9Bar night sounds like.">' . Util::esc($bio) . '</textarea>');
    $f .= '<div class="row">';
    $f .= Render::field('Rate', Render::input('rate', $rate, 'text',
        ['placeholder' => '2 hours, $150, own controller']), '');
    $f .= Render::field('Links', Render::input('links', $links, 'text',
        ['placeholder' => 'instagram.com/… · soundcloud.com/…']), '');
    $f .= '</div>';
    $f .= Render::field('Rider', '<textarea name="rider" rows="3" placeholder="What has to be in the booth: '
        . 'mixer, 2 channels free, a spare RCA.">' . Util::esc($rider) . '</textarea>');
    $f .= '<div class="row"><button class="btn primary" type="submit">Save the press kit</button></div>';
    $f .= '</form>';

    $text = "9Bar — Uncle K\n\n" . $bio
        . "\n\nRate: " . $rate
        . "\nLinks: " . $links
        . "\n\nRider:\n" . $rider;
    return $f . Render::copyBlock('presskit', $text, 'Copy the press kit');
}

/* ------------------------------------------------------------ the path */

/** DESIGN-DJ.md §5.8 — the 12-rung ladder and the starter rig, state in meta.skills / meta.rig. */
function dj_page_path(Db $db): string
{
    $back   = Render::href('path');
    $skills = $db->metaArray('skills');
    $rig    = $db->metaArray('rig');

    /* ---- the ladder */
    $items = [];
    $i = 0;
    foreach (Data::LADDER as $rung) {
        $items[] = ['id' => (string) $i, 't' => $rung['t'], 'd' => $rung['d']];
        $i++;
    }
    $ladderDone = 0;
    foreach ($items as $it) {
        if (!empty($skills[$it['id']])) {
            $ladderDone++;
        }
    }
    $ladder = Render::checklistFlat($items, $skills, [
        'action'   => 'skills_save',
        'hidden'   => ['back' => $back],
        'idPrefix' => 'sk',
        'numbered' => true,
        'submit'   => 'Save the ladder',
    ]);

    /* ---- the rig */
    $rigItems = [];
    $total = 0;
    $got   = 0;
    $i = 0;
    foreach (Data::RIG as $buy) {
        $price  = (int) $buy['price'];
        $total += $price;
        if (!empty($rig[(string) $i])) {
            $got += $price;
        }
        $rigItems[] = [
            'id'    => (string) $i,
            't'     => $buy['t'],
            'd'     => $buy['d'],
            'extra' => '<span class="cost mono">' . Util::esc($price > 0 ? '$' . $price : 'free') . '</span>',
        ];
        $i++;
    }
    $rigHtml = Render::checklistFlat($rigItems, $rig, [
        'action'   => 'rig_save',
        'hidden'   => ['back' => $back],
        'idPrefix' => 'rg',
        'submit'   => 'Save the rig list',
        'footer'   => '<span class="muted">The first six come to <b class="mono">$508</b>. '
            . 'The monitors are a later problem.</span>',
    ]);

    $body  = Render::head('The path', 'A real sequence — each rung assumes the one below it. '
        . 'And the rig that gets you through it.');
    $body .= Render::card('Skill ladder', $ladder,
        ['head' => '<span class="mono muted">' . Util::esc($ladderDone . ' / ' . count(Data::LADDER)) . '</span>']);
    $body .= Render::card('Starter rig', $rigHtml,
        ['head' => '<span class="mono muted">' . Util::esc('$' . $got . ' / $' . $total) . '</span>']);
    return $body;
}

/* ====================================================================== */
/*                                 export                                 */
/* ====================================================================== */

/**
 * DESIGN-DJ.md §7 — the answer to "how do I get my data out", which the
 * artifact has no answer for at all: the whole crate as CSV and a JSON backup
 * of every table, both plain GET downloads that need no JavaScript.
 */
function dj_page_export(Db $db): string
{
    $counts = [
        'Crate'     => count($db->crateList()),
        'Sets'      => count($db->setsList()),
        'Practice'  => count($db->practiceList()),
        'Gigs'      => count($db->gigsList()),
    ];
    $kv = '';
    foreach ($counts as $label => $n) {
        $kv .= Render::kv((string) $label, Util::esc($n));
    }

    $b  = '<p>Everything the desk knows lives in <code>dj/data/dj.sqlite</code> on your own hosting. '
        . 'These two downloads take a copy out of it.</p>';
    $b .= $kv;
    $b .= '<div class="row">';
    $b .= '<a class="btn primary" href="' . Util::esc(Render::href('export', ['format' => 'csv']))
        . '" download>Crate as CSV</a>';
    $b .= '<a class="btn" href="' . Util::esc(Render::href('export', ['format' => 'json']))
        . '" download>Full JSON backup</a>';
    $b .= '</div>';
    $b .= '<p class="muted">The CSV holds one row per track with its BPM, key, energy, bucket, prep state '
        . 'and prep ticks — the shape a spreadsheet or another library tool can read. The JSON backup holds '
        . 'every table, including your sets, their running order, the practice log, the gigs and their promo '
        . 'ticks, so it can be restored in full.</p>';

    return Render::head('Export', 'Your data, out of the desk and into a file you keep.')
        . Render::card('Take a copy', $b);
}

/** Emit one export as a download. Called before any layout has been written. */
function dj_export_emit(string $format, Db $db): void
{
    if ($format === 'json') {
        $body = dj_export_json($db);
        $type = 'application/json; charset=utf-8';
        $name = '9bar-backup-' . Util::today() . '.json';
    } else {
        $body = dj_export_csv($db);
        $type = 'text/csv; charset=utf-8';
        $name = '9bar-crate-' . Util::today() . '.csv';
    }
    if (!headers_sent()) {
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo $body;
}

/** The crate as RFC 4180 CSV: a header row, then one row per track. */
function dj_export_csv(Db $db): string
{
    $cols = ['id', 'title', 'artist', 'bpm', 'key', 'energy', 'bucket', 'prep', 'prep_done', 'tags', 'added_at'];
    $out  = dj_csv_line($cols);
    foreach ($db->crateList(['sort' => 'title']) as $t) {
        $row = [];
        foreach ($cols as $c) {
            $v = isset($t[$c]) ? $t[$c] : '';
            if ($c === 'prep_done') {
                $ticks = Music::prepDone($t);
                $v = $ticks === [] ? '' : (string) json_encode($ticks, JSON_UNESCAPED_SLASHES);
            } elseif ($c === 'bpm') {
                $v = is_numeric($v) && (float) $v > 0 ? Util::decimal((float) $v) : '';
            } elseif ($c === 'energy') {
                $v = is_numeric($v) ? (string) (int) $v : '';
            }
            $row[] = is_scalar($v) ? (string) $v : '';
        }
        $out .= dj_csv_line($row);
    }
    return $out;
}

/** One CSV record, quoted the way RFC 4180 asks for and terminated with CRLF. */
function dj_csv_line(array $fields): string
{
    $out = [];
    foreach ($fields as $f) {
        $s = (string) $f;
        if (strpbrk($s, ",\"\r\n") !== false) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }
        $out[] = $s;
    }
    return implode(',', $out) . "\r\n";
}

/** A JSON backup of every table of DESIGN-DJ.md §4, JSON columns already decoded. */
function dj_export_json(Db $db): string
{
    $payload = [
        'app'         => '9Bar DJ Desk',
        'schema'      => 1,
        'exported_at' => Util::nowIso(),
        'tables'      => $db->exportAll(),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION);
    return $json === false ? '{"error":"encode failed"}' : $json . "\n";
}

/* ====================================================================== */

if (!defined('DJ_NO_MAIN')) {
    dj_main();
}
