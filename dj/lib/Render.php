<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Data.php';
require_once __DIR__ . '/Music.php';

/**
 * dj/lib/Render.php — the shared HTML helpers of the desk (DESIGN-DJ.md §3):
 * layout, card, table wrapper, pill, meter, checklist, empty state, energy bar.
 *
 * index.php composes pages out of these so it stays readable; nothing here
 * touches the database, and nothing here reads $_GET or $_POST.
 *
 * Three rules hold for every function in this file (DESIGN-DJ.md §8):
 *
 *   1. every dynamic value goes through Util::esc();
 *   2. no inline <script>, no onclick= and no inline style="" is ever emitted —
 *      colour that genuinely varies per row (the energy ramp) is drawn as an
 *      inline <svg> with a `fill` PRESENTATION ATTRIBUTE, which is neither a
 *      style attribute nor blocked by `style-src 'self'`;
 *   3. everything works with JavaScript switched off: state changes are POST
 *      forms carrying the CSRF field, navigation is plain links.
 *
 * PHP 7.4 syntax only: no match, no enums, no named arguments, no union types,
 * no str_contains/str_starts_with, no ?->, no constructor promotion.
 */
final class Render
{
    /** The eight sections of DESIGN-DJ.md §5, in nav order: [slug, label]. */
    const SECTIONS = [
        ['desk', 'Desk'],
        ['crate', 'Crate'],
        ['prep', 'Prep bench'],
        ['set', 'Set builder'],
        ['practice', 'Practice'],
        ['gigs', 'Gigs'],
        ['9bar', '9Bar'],
        ['path', 'The path'],
    ];

    /** The rail note the artifact carries under its navigation. */
    const RAIL_NOTE = 'Camelot: move ±1 on the wheel, or swap letter. 8A → 7A, 9A, 8B.';

    /* ==================================================================== */
    /*                               document                               */
    /* ==================================================================== */

    /**
     * The whole HTML document.
     *
     * $opts: page (current slug, '' for setup/login), nav (bool, default true),
     * readout (array of [label, value] pairs for the header strip),
     * theme (auto|light|dark).
     */
    public static function layout(string $title, string $body, array $opts = []): string
    {
        $nav     = !isset($opts['nav']) || $opts['nav'] !== false;
        $current = isset($opts['page']) ? (string) $opts['page'] : '';
        $theme   = isset($opts['theme']) ? (string) $opts['theme'] : 'auto';
        $readout = isset($opts['readout']) && is_array($opts['readout']) ? $opts['readout'] : [];

        $h  = '<!DOCTYPE html><html lang="en"';
        if ($theme === 'light' || $theme === 'dark') {
            $h .= ' data-theme="' . Util::esc($theme) . '"';
        }
        $h .= '><head><meta charset="utf-8">';
        $h .= '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        $h .= '<meta name="referrer" content="no-referrer">';
        $h .= '<title>' . Util::esc($title) . ' - 9Bar</title>';
        $h .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
        $h .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        $h .= '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Khand:wght@500;600;700'
            . '&amp;family=Hanken+Grotesk:wght@400;500;600;700&amp;family=IBM+Plex+Mono:wght@400;500;600&amp;display=swap">';
        $h .= '<link rel="stylesheet" href="assets/dj.css?v=' . Util::esc(self::assetVersion('assets/dj.css')) . '">';
        $h .= '</head><body>';

        $h .= '<header class="topbar"><div class="topbar-in">';
        $h .= '<div class="brand"><b>9Bar</b><span>Uncle K</span></div>';
        if ($readout !== []) {
            $h .= self::readout($readout);
        }
        $h .= '</div></header>';

        $h .= '<div class="wrap">';
        if ($nav) {
            $h .= self::rail($current);
        }
        $h .= '<main class="main" id="main">';
        $h .= self::flashes(Auth::takeFlashes());
        $h .= $body;
        $h .= '</main></div>';

        $h .= '<footer class="foot"><p>9Bar · Uncle K · the crate, the bench and the path. '
            . 'Your data lives in this folder and nowhere else.</p></footer>';
        $h .= '<script src="assets/dj.js?v=' . Util::esc(self::assetVersion('assets/dj.js')) . '" defer></script>';
        $h .= '</body></html>';
        return $h;
    }

    /** The eight-section rail plus the Camelot reminder and the logout form. */
    public static function rail(string $current): string
    {
        $h = '<nav class="rail" aria-label="Sections">';
        $i = 1;
        foreach (self::SECTIONS as $s) {
            $isHere = ($s[0] === $current);
            $h .= '<a class="rail-link" href="' . Util::esc(self::href($s[0])) . '"'
                . ($isHere ? ' aria-current="page"' : '') . '>'
                . '<span class="n mono">' . Util::esc(str_pad((string) $i, 2, '0', STR_PAD_LEFT)) . '</span>'
                . Util::esc($s[1]) . '</a>';
            $i++;
        }
        $h .= '<a class="rail-link rail-link-minor" href="' . Util::esc(self::href('export')) . '"'
            . ($current === 'export' ? ' aria-current="page"' : '') . '>'
            . '<span class="n mono">··</span>Export</a>';
        $h .= '<div class="rail-note">' . Util::esc(self::RAIL_NOTE) . '</div>';
        $h .= self::actionForm('logout', 'Log out', [], ['class' => 'rail-logout', 'button' => 'btn ghost sm']);
        $h .= '</nav>';
        return $h;
    }

    /** The header number strip: [[label, value], …]. */
    public static function readout(array $items): string
    {
        $h = '<dl class="readout">';
        foreach ($items as $it) {
            if (!is_array($it) || !isset($it[0], $it[1])) {
                continue;
            }
            $h .= '<div><dt>' . Util::esc($it[0]) . '</dt><dd class="mono">' . Util::esc($it[1]) . '</dd></div>';
        }
        return $h . '</dl>';
    }

    /** The per-section page heading and its one-line explanation. */
    public static function head(string $title, string $sub = ''): string
    {
        $h = '<div class="head"><h2>' . Util::esc($title) . '</h2>';
        if ($sub !== '') {
            $h .= '<p>' . Util::esc($sub) . '</p>';
        }
        return $h . '</div>';
    }

    /**
     * Cache-busting token for a static asset: its modification time, so an upload
     * always invalidates the browser's copy.
     */
    public static function assetVersion(string $relPath): string
    {
        static $cache = [];
        if (isset($cache[$relPath])) {
            return $cache[$relPath];
        }
        $root = defined('TRADER_DJ_ROOT') ? TRADER_DJ_ROOT : dirname(__DIR__);
        $mtime = @filemtime($root . '/' . ltrim($relPath, '/'));
        $cache[$relPath] = ($mtime !== false && $mtime > 0) ? (string) $mtime : '1';
        return $cache[$relPath];
    }

    /* ==================================================================== */
    /*                                 links                                */
    /* ==================================================================== */

    /**
     * A relative URL into the desk: index.php?page=…&… with every value encoded.
     * Empty and null parameters are dropped so links stay short and canonical.
     */
    public static function href(string $page, array $params = []): string
    {
        $q = ['page' => $page];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '' || $v === false) {
                continue;
            }
            $q[(string) $k] = is_bool($v) ? '1' : (string) $v;
        }
        return 'index.php?' . http_build_query($q, '', '&');
    }

    /* ==================================================================== */
    /*                                 forms                                */
    /* ==================================================================== */

    /**
     * A one-button POST form for $action — the only way the desk changes state.
     * Always carries the CSRF field (DESIGN-DJ.md §8).
     *
     * $hidden: name => value pairs carried alongside.
     * $opts:   class (form class), button (button class), confirm (text for
     *          dj.js to use as a confirm dialog), title, disabled.
     */
    public static function actionForm(string $action, string $label, array $hidden = [], array $opts = []): string
    {
        $formCls = isset($opts['class']) ? (string) $opts['class'] : 'inline';
        $btnCls  = isset($opts['button']) ? (string) $opts['button'] : 'btn sm';
        $h  = '<form class="' . Util::esc($formCls) . '" method="post" action="index.php">';
        $h .= Auth::csrfField();
        $h .= self::hiddenFields(array_merge(['action' => $action], $hidden));
        $h .= '<button type="submit" class="' . Util::esc($btnCls) . '"';
        if (!empty($opts['confirm'])) {
            $h .= ' data-confirm="' . Util::esc($opts['confirm']) . '"';
        }
        if (!empty($opts['title'])) {
            $h .= ' title="' . Util::esc($opts['title']) . '"';
        }
        if (!empty($opts['disabled'])) {
            $h .= ' disabled';
        }
        $h .= '>' . Util::esc($label) . '</button></form>';
        return $h;
    }

    /** name => value pairs as hidden inputs. */
    public static function hiddenFields(array $fields): string
    {
        $h = '';
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $h .= '<input type="hidden" name="' . Util::esc($k) . '" value="' . Util::esc($v) . '">';
        }
        return $h;
    }

    /**
     * A labelled control. $control is HTML the caller has already built.
     * $cls widens or narrows the field inside a .row (a class, never a style).
     */
    public static function field(string $label, string $control, string $cls = ''): string
    {
        return '<label class="f' . ($cls === '' ? '' : ' ' . Util::esc($cls)) . '">'
            . '<span class="f-l">' . Util::esc($label) . '</span>' . $control . '</label>';
    }

    /** A text-ish input. $attrs are plain name => value attribute pairs. */
    public static function input(string $name, $value = '', string $type = 'text', array $attrs = []): string
    {
        $h = '<input type="' . Util::esc($type) . '" name="' . Util::esc($name) . '" value="' . Util::esc($value) . '"';
        foreach ($attrs as $k => $v) {
            if ($v === true) {
                $h .= ' ' . Util::esc($k);
            } elseif ($v !== false && $v !== null) {
                $h .= ' ' . Util::esc($k) . '="' . Util::esc($v) . '"';
            }
        }
        return $h . '>';
    }

    /**
     * A select. $options is a list of [value, label] pairs (or plain scalars,
     * used as both), $selected the value to mark.
     *
     * @param mixed $selected
     */
    public static function select(string $name, array $options, $selected = null, array $attrs = []): string
    {
        $h = '<select name="' . Util::esc($name) . '"';
        foreach ($attrs as $k => $v) {
            if ($v === true) {
                $h .= ' ' . Util::esc($k);
            } elseif ($v !== false && $v !== null) {
                $h .= ' ' . Util::esc($k) . '="' . Util::esc($v) . '"';
            }
        }
        $h .= '>';
        foreach ($options as $o) {
            if (is_array($o)) {
                $v = isset($o[0]) ? $o[0] : '';
                $l = isset($o[1]) ? $o[1] : $v;
            } else {
                $v = $o;
                $l = $o;
            }
            $on = ($selected !== null && (string) $v === (string) $selected);
            $h .= '<option value="' . Util::esc($v) . '"' . ($on ? ' selected' : '') . '>' . Util::esc($l) . '</option>';
        }
        return $h . '</select>';
    }

    /* ==================================================================== */
    /*                                 blocks                               */
    /* ==================================================================== */

    /**
     * A titled card. $opts: head (extra HTML in the card header, typically a
     * button or a count), class (extra card classes), bodyClass.
     */
    public static function card(string $title, string $body, array $opts = []): string
    {
        $cls  = 'card' . (isset($opts['class']) && $opts['class'] !== '' ? ' ' . (string) $opts['class'] : '');
        $bCls = isset($opts['bodyClass']) ? (string) $opts['bodyClass'] : 'card-b';
        $h = '<section class="' . Util::esc($cls) . '">';
        if ($title !== '' || !empty($opts['head'])) {
            $h .= '<div class="card-h"><h3>' . Util::esc($title) . '</h3>';
            if (!empty($opts['head'])) {
                $h .= '<span class="spacer"></span>' . (string) $opts['head'];
            }
            $h .= '</div>';
        }
        $h .= '<div class="' . Util::esc($bCls) . '">' . $body . '</div></section>';
        return $h;
    }

    /** Horizontal-scroll wrapper — the only element allowed to be wider than the page. */
    public static function scrollX(string $inner): string
    {
        return '<div class="scroll-x">' . $inner . '</div>';
    }

    /**
     * A table inside its scroll wrapper.
     *
     * $cols: [['label' => 'Date', 'cls' => 'n'], …] — cls 'n' right-aligns a numeric column.
     * $rows: list of rows; each row is a list of ALREADY-ESCAPED HTML cells, or
     *        ['cells' => [...], 'cls' => 'rowclass'].
     */
    public static function table(array $cols, array $rows, string $emptyMsg = ''): string
    {
        if ($rows === []) {
            return self::emptyState($emptyMsg === '' ? 'Nothing here yet.' : $emptyMsg);
        }
        $h = '<table class="ttable"><thead><tr>';
        foreach ($cols as $c) {
            $label = is_array($c) ? (isset($c['label']) ? (string) $c['label'] : '') : (string) $c;
            $cls   = is_array($c) && isset($c['cls']) ? (string) $c['cls'] : '';
            $h .= '<th' . ($cls === '' ? '' : ' class="' . Util::esc($cls) . '"') . '>' . Util::esc($label) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $cells = isset($row['cells']) && is_array($row['cells']) ? $row['cells'] : $row;
            $rcls  = isset($row['cls']) ? (string) $row['cls'] : '';
            $h .= '<tr' . ($rcls === '' ? '' : ' class="' . Util::esc($rcls) . '"') . '>';
            foreach ($cells as $i => $cell) {
                $cls = isset($cols[$i]) && is_array($cols[$i]) && isset($cols[$i]['cls'])
                    ? (string) $cols[$i]['cls'] : '';
                $h .= '<td' . ($cls === '' ? '' : ' class="' . Util::esc($cls) . '"') . '>' . (string) $cell . '</td>';
            }
            $h .= '</tr>';
        }
        return self::scrollX($h . '</tbody></table>');
    }

    /**
     * A label/value line, the artifact's .kv. $valueHtml must already be escaped;
     * $valueCls tints the VALUE (good / bad), never the label beside it.
     */
    public static function kv(string $label, string $valueHtml, string $valueCls = ''): string
    {
        return '<div class="kv"><span>' . Util::esc($label) . '</span>'
            . '<b class="mono' . ($valueCls === '' ? '' : ' ' . Util::esc($valueCls)) . '">'
            . $valueHtml . '</b></div>';
    }

    /** A small coloured label. $kind: ok | warn | danger | info | accent | ''. */
    public static function pill(string $text, string $kind = ''): string
    {
        return '<span class="pill' . ($kind === '' ? '' : ' pill-' . Util::esc($kind)) . '">'
            . Util::esc($text) . '</span>';
    }

    /** A bucket / prep tag, tinted by the bucket's own class. */
    public static function tag(string $text, string $cls = ''): string
    {
        return '<span class="tag' . ($cls === '' ? '' : ' ' . Util::esc($cls)) . '">' . Util::esc($text) . '</span>';
    }

    /** The muted line that stands where the artifact had a Claude button (§1). */
    public static function aiNote(string $text): string
    {
        return '<p class="ai-note">' . Util::esc($text) . '</p>';
    }

    /** Nothing-to-show state. */
    public static function emptyState(string $msg): string
    {
        return '<div class="empty">' . Util::esc($msg) . '</div>';
    }

    /** A dashed advisory box (the artifact's .ref / .demo-note). */
    public static function note(string $msg, string $cls = 'ref'): string
    {
        return '<div class="' . Util::esc($cls) . '">' . Util::esc($msg) . '</div>';
    }

    /** Flash messages taken off the session. */
    public static function flashes(array $flashes): string
    {
        if ($flashes === []) {
            return '';
        }
        $h = '';
        foreach ($flashes as $f) {
            $type = isset($f['type']) ? (string) $f['type'] : 'info';
            $msg  = isset($f['msg']) ? (string) $f['msg'] : '';
            $h .= '<p class="flash flash-' . Util::esc($type) . '" role="status">' . Util::esc($msg) . '</p>';
        }
        return $h;
    }

    /**
     * A progress meter. Uses the native <progress> element so the proportion is
     * carried by attributes rather than an inline width style.
     */
    public static function meter(int $done, int $total, string $label = ''): string
    {
        $total = $total > 0 ? $total : 1;
        $done  = Util::clampInt($done, 0, $total);
        $text  = $label === '' ? ($done . ' / ' . $total) : $label;
        $h  = '<span class="meter' . ($done >= $total ? ' meter-full' : '') . '">';
        $h .= '<progress max="' . Util::esc($total) . '" value="' . Util::esc($done) . '">'
            . Util::esc($done . ' of ' . $total) . '</progress>';
        $h .= '<span class="meter-n mono">' . Util::esc($text) . '</span></span>';
        return $h;
    }

    /* ==================================================================== */
    /*                             track visuals                            */
    /* ==================================================================== */

    /**
     * The energy bar: the artifact's cyan → marigold → rani ramp, drawn as an
     * inline <svg>. The colour is a `fill` presentation attribute, NOT an inline
     * style, so it survives `style-src 'self'` without a stylesheet hook.
     *
     * @param mixed $energy
     */
    public static function energyBar($energy): string
    {
        $e     = is_numeric($energy) ? (int) $energy : 0;
        $shown = $e > 0 ? (string) $e : '—';
        $lvl   = Util::clampInt($e > 0 ? $e : 1, 1, 10);
        $color = Music::energyColor($lvl);
        $label = $e > 0 ? 'Energy ' . $e . ' of 10' : 'Energy not set';
        $h  = '<span class="energy">';
        $h .= '<svg class="bar" width="100%" height="5" viewBox="0 0 100 5" preserveAspectRatio="none"'
            . ' role="img" aria-label="' . Util::esc($label) . '">'
            . '<rect x="0" y="0" width="100" height="5" rx="2.5" fill="' . Util::esc($color) . '" fill-opacity="0.2"></rect>'
            . '<rect x="0" y="0" width="' . Util::esc($lvl * 10) . '" height="5" rx="2.5" fill="' . Util::esc($color) . '"></rect>'
            . '</svg>';
        $h .= '<span class="num mono">' . Util::esc($shown) . '</span></span>';
        return $h;
    }

    /**
     * The 4px energy stripe down the left of a crate row. Same ramp, same
     * presentation-attribute trick, decorative so it is hidden from assistive tech.
     *
     * @param mixed $energy
     */
    public static function energyStripe($energy): string
    {
        $e     = is_numeric($energy) ? (int) $energy : 0;
        $color = Music::energyColor(Util::clampInt($e > 0 ? $e : 1, 1, 10));
        return '<svg class="stripe" width="4" height="30" viewBox="0 0 4 30" aria-hidden="true" focusable="false">'
            . '<rect x="0" y="0" width="4" height="30" rx="2" fill="' . Util::esc($color) . '"></rect></svg>';
    }

    /** The prep dot: green ready, amber gridded, grey raw. */
    public static function prepDot(?string $prep): string
    {
        $id = strtolower(trim((string) $prep));
        if (!in_array($id, ['raw', 'gridded', 'ready'], true)) {
            $id = 'raw';
        }
        $label = Data::prepLabel($id);
        return '<span class="prep ' . Util::esc($id) . '" role="img" aria-label="' . Util::esc($label) . '"'
            . ' title="' . Util::esc($label) . '"></span>';
    }

    /**
     * The seven-day practice strip. $days is the list of the last seven dates
     * oldest-first, $done the set of dates that have a session.
     *
     * @param string[]           $days
     * @param array<string,bool> $done
     */
    public static function streak(array $days, array $done): string
    {
        $h = '<div class="streak" role="img" aria-label="Practice over the last seven days">';
        foreach ($days as $d) {
            $on = !empty($done[$d]);
            $h .= '<i' . ($on ? ' class="on"' : '') . ' title="'
                . Util::esc($d . ($on ? ' · practised' : ' · nothing logged')) . '"></i>';
        }
        return $h . '</div>';
    }

    /* ==================================================================== */
    /*                              checklists                              */
    /* ==================================================================== */

    /**
     * A phased checklist (PREPSTEPS, PROMOSTEPS) as ONE POST form with a real
     * checkbox per step, so it works with JavaScript switched off: the box is
     * ticked and the form submitted, and the server takes the submitted list as
     * the new truth.
     *
     * $phases: [['phase' => string, 'steps' => [['id','t','d'], …]], …]
     * $done:   ['stepId' => true, …]
     * $opts:   action (POST action, required), hidden (extra hidden fields),
     *          name (checkbox array name, default 'steps'), idPrefix,
     *          submit (button label), disabled (bool), footer (extra HTML).
     */
    public static function checklist(array $phases, array $done, array $opts = []): string
    {
        $action   = isset($opts['action']) ? (string) $opts['action'] : '';
        $hidden   = isset($opts['hidden']) && is_array($opts['hidden']) ? $opts['hidden'] : [];
        $name     = isset($opts['name']) ? (string) $opts['name'] : 'steps';
        $prefix   = isset($opts['idPrefix']) ? (string) $opts['idPrefix'] : 'st';
        $submit   = isset($opts['submit']) ? (string) $opts['submit'] : 'Save ticks';
        $disabled = !empty($opts['disabled']);

        $h  = '<form class="checklist" method="post" action="index.php">';
        $h .= Auth::csrfField();
        $h .= self::hiddenFields(array_merge(['action' => $action], $hidden));
        foreach ($phases as $phase) {
            if (!is_array($phase) || !isset($phase['steps']) || !is_array($phase['steps'])) {
                continue;
            }
            $h .= '<div class="phase">';
            if (!empty($phase['phase'])) {
                $h .= '<h4>' . Util::esc($phase['phase']) . '</h4>';
            }
            foreach ($phase['steps'] as $step) {
                $h .= self::step($step, $done, $name, $prefix, $disabled);
            }
            $h .= '</div>';
        }
        $h .= '<div class="checklist-foot">';
        $h .= '<button type="submit" class="btn primary sm"' . ($disabled ? ' disabled' : '') . '>'
            . Util::esc($submit) . '</button>';
        if (!empty($opts['footer'])) {
            $h .= (string) $opts['footer'];
        }
        $h .= '</div></form>';
        return $h;
    }

    /**
     * A flat checklist — the 12-rung ladder and the rig, which have no phases.
     *
     * $items: [['id' => string, 't' => title, 'd' => body, 'extra' => html], …]
     * $opts:  as checklist(), plus numbered (bool) to print 01, 02, … in front.
     */
    public static function checklistFlat(array $items, array $done, array $opts = []): string
    {
        $phases = [['phase' => '', 'steps' => $items]];
        if (!empty($opts['numbered'])) {
            $n = 1;
            foreach (array_keys($phases[0]['steps']) as $i) {
                $phases[0]['steps'][$i]['k'] = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
                $n++;
            }
        }
        return self::checklist($phases, $done, $opts);
    }

    /**
     * One checklist row: checkbox, title, explanatory body — the writing copied
     * verbatim from the artifact by Data.php, never paraphrased here.
     *
     * @param array              $step
     * @param array<string,bool> $done
     */
    private static function step(array $step, array $done, string $name, string $prefix, bool $disabled): string
    {
        $id  = isset($step['id']) ? (string) $step['id'] : '';
        $on  = $id !== '' && !empty($done[$id]);
        $dom = $prefix . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        $h  = '<div class="pstep' . ($on ? ' on' : '') . '">';
        if (isset($step['k'])) {
            $h .= '<span class="k mono">' . Util::esc($step['k']) . '</span>';
        }
        $h .= '<input type="checkbox" id="' . Util::esc($dom) . '" name="' . Util::esc($name) . '[]"'
            . ' value="' . Util::esc($id) . '"' . ($on ? ' checked' : '') . ($disabled ? ' disabled' : '') . '>';
        $h .= '<label for="' . Util::esc($dom) . '"><b>' . Util::esc(isset($step['t']) ? $step['t'] : '') . '</b>';
        if (isset($step['d']) && $step['d'] !== '') {
            $h .= '<span class="d">' . Util::esc($step['d']) . '</span>';
        }
        $h .= '</label>';
        if (isset($step['extra'])) {
            $h .= '<span class="x">' . (string) $step['extra'] . '</span>';
        }
        return $h . '</div>';
    }

    /* ==================================================================== */
    /*                              copy blocks                             */
    /* ==================================================================== */

    /**
     * A read-only block of text the operator wants to paste somewhere else (the
     * numbered tracklist, the press kit). The button carries a data attribute
     * for dj.js; with JavaScript off the textarea is still selectable, which is
     * why the text is in a textarea rather than behind a button.
     */
    public static function copyBlock(string $domId, string $text, string $label = 'Copy'): string
    {
        $h  = '<div class="copyblock">';
        $h .= '<textarea class="mono" id="' . Util::esc($domId) . '" rows="10" readonly>' . Util::esc($text) . '</textarea>';
        $h .= '<div class="row"><button type="button" class="btn sm" data-copy="' . Util::esc($domId) . '">'
            . Util::esc($label) . '</button>'
            . '<span class="muted">Select the box and copy if the button does nothing — it needs JavaScript.</span></div>';
        return $h . '</div>';
    }
}
