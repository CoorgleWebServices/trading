<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Risk.php';
// Portfolio mode (docs/DESIGN-PORTFOLIO.md) is optional: a checkout without lib/Sleeve.php
// renders exactly the single-engine panel it always did.
if (file_exists(__DIR__ . '/Sleeve.php')) {
    require_once __DIR__ . '/Sleeve.php';
}
// Learning (docs/DESIGN-LEARNING.md) is optional in exactly the same way: without
// lib/Learn.php the panel renders every card it always did and the Insights page
// says so instead of computing anything.
if (file_exists(__DIR__ . '/Learn.php')) {
    require_once __DIR__ . '/Learn.php';
}

/**
 * Panel helpers (DESIGN.md §12): security headers, sessions, CSRF, escaping,
 * flash messages, login lockout, the status payload shared by the dashboard
 * and ?api=status, small render helpers and the equity sparkline.
 *
 * Nothing in here echoes unescaped user data: every dynamic value that reaches
 * HTML goes through Panel::e().
 */
final class Panel
{
    const SESSION_NAME       = 'trader_sid';
    const IDLE_SECONDS       = 1800;   // 30 min
    const LOGIN_MAX_FAILS    = 5;
    const LOGIN_LOCK_MINUTES = 15;
    const CRON_STALE_SECONDS = 180;    // "cron not running" after 3 min
    const CSP = "default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";
    /** Round trips a sleeve needs before the "best method" line is allowed to look like a result. */
    const PORTFOLIO_MIN_SAMPLE = 30;
    /** DESIGN-PORTFOLIO.md §6.4 default, used only when the config key is absent. */
    const SLEEVE_DRAWDOWN_DEFAULT = 25.0;

    /** Effective round-trip taker cost with and without the BNB discount (DESIGN-LEARNING.md §7). */
    const BNB_ROUND_TRIP_ON  = 0.15;
    const BNB_ROUND_TRIP_OFF = 0.20;
    /** DESIGN-LEARNING.md §7 default for `bnb_min_balance`, in USDT equivalent. */
    const BNB_MIN_BALANCE_DEFAULT = 1.0;
    /** State key holding the panel's read-only BNB display cache (never read by trading code). */
    const BNB_STATE = 'bnb_status';

    /** @var bool guards against sending headers twice */
    private static $headersSent = false;

    /* ============================================================ bootstrap */

    /**
     * force HTTPS → security headers → session cookie params → session_start → idle timeout.
     * The CSRF check is separate (Panel::csrfCheck()) so index.php can decide how to answer.
     */
    public static function boot(array $cfg): void
    {
        self::forceHttps($cfg);
        self::securityHeaders();
        self::startSession();
        self::idleTimeout(self::IDLE_SECONDS);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
            return true;
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $first = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
            if ($first === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * Redirects http → https (301) when force_https is on. Never redirects on the CLI, and not
     * before setup is complete: the wizard is where the user decides force_https, and the
     * default (true) would otherwise lock a host without TLS out of its own setup page.
     */
    public static function forceHttps(array $cfg): void
    {
        if (PHP_SAPI === 'cli' || empty($cfg['force_https']) || self::isHttps()) {
            return;
        }
        if ((string) ($cfg['panel_password_hash'] ?? '') === '') {
            return;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) !== 1) {
            return; // cannot build a safe redirect target
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/' || strpos($uri, "\n") !== false || strpos($uri, "\r") !== false) {
            $uri = '/';
        }
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }

    public static function securityHeaders(): void
    {
        if (self::$headersSent || headers_sent()) {
            return;
        }
        self::$headersSent = true;
        header('X-Frame-Options: DENY');
        header('Content-Security-Policy: ' . self::CSP);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=15552000');
        }
    }

    /** Cookie path of the panel directory, e.g. "/trader" (or "/"). */
    public static function cookiePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '' || $dir === '.' || $dir === '/') {
            return '/';
        }
        return rtrim($dir, '/') . '/';
    }

    public static function startSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) (self::IDLE_SECONDS * 2));
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'domain'   => '',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || strlen($_SESSION['csrf']) < 32) {
            $_SESSION['csrf'] = Util::randomHex(32);
        }
    }

    /**
     * Ends an authenticated session that has been idle longer than $seconds.
     * Returns true when the session was expired by this call.
     */
    public static function idleTimeout(int $seconds = self::IDLE_SECONDS): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $now = time();
        $expired = false;
        if (!empty($_SESSION['auth'])) {
            $last = isset($_SESSION['last_seen']) ? (int) $_SESSION['last_seen'] : $now;
            if ($now - $last > $seconds) {
                self::logout(false);
                self::flash('warn', 'Session expired after 30 minutes of inactivity - please log in again.');
                $expired = true;
            }
        }
        $_SESSION['last_seen'] = $now;
        return $expired;
    }

    /* ================================================================= csrf */

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = Util::randomHex(32);
        }
        return (string) $_SESSION['csrf'];
    }

    /** True when the request is not a POST, or carries a valid token (field "csrf" or header X-CSRF-Token). */
    public static function csrfCheck(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true;
        }
        $given = '';
        if (isset($_POST['csrf']) && is_string($_POST['csrf'])) {
            $given = $_POST['csrf'];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $given = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        $token = self::csrfToken();
        return $token !== '' && $given !== '' && hash_equals($token, $given);
    }

    /** Hidden CSRF input for forms. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . self::e(self::csrfToken()) . '">';
    }

    /* ============================================================= escaping */

    /** htmlspecialchars ENT_QUOTES|ENT_SUBSTITUTE|ENT_HTML5 on any scalar; floats never print exponents. */
    public static function e($v): string
    {
        if ($v === null || $v === false) {
            return '';
        }
        if ($v === true) {
            return '1';
        }
        if (is_float($v)) {
            $v = Util::toDecimalString($v, 10);
        } elseif (is_array($v) || is_object($v)) {
            $j = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $v = $j === false ? '' : $j;
        }
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /* ================================================================ flash */

    /** $type: ok | warn | danger | info */
    public static function flash(string $type, string $msg): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        if (!in_array($type, ['ok', 'warn', 'danger', 'info'], true)) {
            $type = 'info';
        }
        $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
    }

    /** @return array<int, array{type:string, msg:string}> and clears them */
    public static function takeFlashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
            return [];
        }
        $out = [];
        foreach ($_SESSION['flash'] as $f) {
            if (is_array($f) && isset($f['msg'])) {
                $out[] = ['type' => (string) ($f['type'] ?? 'info'), 'msg' => (string) $f['msg']];
            }
        }
        unset($_SESSION['flash']);
        return $out;
    }

    /* ================================================================ auth */

    /** REMOTE_ADDR only - proxy headers are client-controlled and must not bypass the lockout. */
    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            $ip = 'unknown';
        }
        return substr($ip, 0, 64);
    }

    public static function isLoggedIn(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['auth']);
    }

    public static function loginLocked(Db $db, string $ip): bool
    {
        try {
            $row = $db->loginAttempt($ip);
            return !empty($row['locked']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Verifies the password; counts failures per IP (5 fails ⇒ 15 min lock).
     * Returns true and marks the session authenticated on success.
     */
    public static function attemptLogin(array $cfg, Db $db, string $password): bool
    {
        $ip   = self::clientIp();
        $hash = (string) ($cfg['panel_password_hash'] ?? '');
        if (self::loginLocked($db, $ip)) {
            return false;
        }
        $ok = $hash !== '' && $password !== '' && password_verify($password, $hash);
        if (!$ok) {
            // always burn a comparable amount of time so timing does not reveal the state
            if ($hash === '') {
                password_verify($password, '$2y$10$usesomesillystringforsalt$');
            }
            try {
                $db->loginFailed($ip, self::LOGIN_MAX_FAILS, self::LOGIN_LOCK_MINUTES);
            } catch (Throwable $e) {
                // the lockout table is best effort
            }
            return false;
        }
        try {
            $db->loginOk($ip);
        } catch (Throwable $e) {
        }
        self::login();
        return true;
    }

    /** Marks the current session as authenticated (fresh id, fresh CSRF token). */
    public static function login(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_regenerate_id(true);
        $_SESSION['auth']      = true;
        $_SESSION['login_at']  = time();
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf']      = Util::randomHex(32);
    }

    /** Clears the session; keeps a fresh id so flashes can still be shown when $keepSession is true. */
    public static function logout(bool $regenerate = true): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if ($regenerate) {
            session_regenerate_id(true);
        }
        $_SESSION['csrf'] = Util::randomHex(32);
        $_SESSION['last_seen'] = time();
    }

    /* ============================================================ responses */

    /** Local redirect (query string or relative path only), then exit. */
    public static function redirect(string $to = ''): void
    {
        $to = str_replace(["\r", "\n"], '', $to);
        if ($to === '' || $to[0] === '?' ) {
            $to = 'index.php' . $to;
        } elseif (preg_match('#^(?:[a-z]+:)?//#i', $to) === 1) {
            $to = 'index.php'; // never redirect off-site
        }
        header('Location: ' . $to, true, 303);
        exit;
    }

    public static function json(array $data, int $code = 200): void
    {
        self::securityHeaders();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $out = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo $out === false ? '{"ok":false,"error":"encode"}' : $out;
        exit;
    }

    /** https://host/trader/ (directory of index.php with trailing slash). */
    public static function baseUrl(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        if (preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) !== 1) {
            $host = 'localhost';
        }
        $scheme = self::isHttps() ? 'https' : 'http';
        return $scheme . '://' . $host . self::cookiePath();
    }

    public static function cronCommand(): string
    {
        $root = defined('TRADER_ROOT') ? TRADER_ROOT : dirname(__DIR__);
        return '* * * * * /usr/local/bin/php ' . $root . '/cron.php >/dev/null 2>&1';
    }

    public static function cronUrl(array $cfg): string
    {
        return self::baseUrl() . 'cron.php?key=' . rawurlencode((string) ($cfg['cron_key'] ?? ''));
    }

    /** "abcd…wxyz" for an API key; the secret is never fingerprinted or shown. */
    public static function keyFingerprint(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '(not set)';
        }
        if (strlen($key) < 12) {
            return '(set, ' . strlen($key) . ' chars)';
        }
        return substr($key, 0, 4) . '…' . substr($key, -4);
    }

    /* ============================================================ formatting */

    /** ISO UTC → "Y-m-d H:i" in the display timezone; "–" for empty/invalid. */
    public static function fmtTime(?string $iso, string $tz = 'UTC', string $format = 'Y-m-d H:i:s'): string
    {
        if ($iso === null || trim($iso) === '') {
            return '–';
        }
        $ts = Util::isoToTs($iso);
        if ($ts === null) {
            return '–';
        }
        try {
            $zone = new DateTimeZone($tz === '' ? 'UTC' : $tz);
        } catch (Throwable $e) {
            $zone = new DateTimeZone('UTC');
        }
        $d = new DateTime('@' . $ts);
        $d->setTimezone($zone);
        return $d->format($format);
    }

    /** Human age: "12 s ago", "3 min ago", "2 h ago", "4 d ago", "never". */
    public static function ago(?string $iso, ?int $now = null): string
    {
        if ($iso === null || trim($iso) === '') {
            return 'never';
        }
        $ts = Util::isoToTs($iso);
        if ($ts === null) {
            return 'never';
        }
        $now = $now === null ? time() : $now;
        return self::duration($now - $ts) . ' ago';
    }

    /** "45 s", "3 min", "2 h 05 min", "4 d 3 h" */
    public static function duration(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . ' min';
        }
        if ($seconds < 86400) {
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);
            return $h . ' h ' . sprintf('%02d', $m) . ' min';
        }
        $d = (int) floor($seconds / 86400);
        $h = (int) floor(($seconds % 86400) / 3600);
        return $d . ' d ' . $h . ' h';
    }

    /** "+0.1234" / "-0.5000" with a sign. */
    public static function signed(float $v, int $d = 4): string
    {
        $s = Util::money($v, $d);
        if ($v > 0 && $s !== '' && $s[0] !== '-') {
            $s = '+' . $s;
        }
        return $s;
    }

    public static function pct(float $v, int $d = 2, bool $sign = false): string
    {
        return ($sign ? self::signed($v, $d) : Util::money($v, $d)) . ' %';
    }

    public static function pnlLevel(float $v): string
    {
        if ($v > 0) {
            return 'ok';
        }
        if ($v < 0) {
            return 'danger';
        }
        return 'muted';
    }

    /* ======================================================= render helpers */

    /** <span class="pill pill-LEVEL" data-level="FIELD" data-field="FIELD">TEXT</span> */
    public static function pill(string $text, string $level = 'muted', string $field = '', string $extraClass = ''): string
    {
        $level = in_array($level, ['ok', 'warn', 'danger', 'muted', 'info'], true) ? $level : 'muted';
        $attrs = ' class="pill pill-' . $level . ($extraClass !== '' ? ' ' . self::e($extraClass) : '') . '"';
        if ($field !== '') {
            $attrs .= ' data-field="' . self::e($field) . '" data-level="' . self::e($field) . '"';
        }
        return '<span' . $attrs . '>' . self::e($text) . '</span>';
    }

    /** <TAG class="CLASS" data-field="KEY">value</TAG> from $status['text'][KEY]. */
    public static function field(array $status, string $key, string $tag = 'span', string $class = ''): string
    {
        $text = isset($status['text'][$key]) ? (string) $status['text'][$key] : '–';
        $tag  = preg_match('/^[a-z][a-z0-9]*$/', $tag) === 1 ? $tag : 'span';
        return '<' . $tag . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . ' data-field="' . self::e($key) . '">'
            . self::e($text) . '</' . $tag . '>';
    }

    /** KPI tile. */
    public static function kpi(array $status, string $key, string $label, bool $levelled = false): string
    {
        $level = $levelled && isset($status['levels'][$key]) ? (string) $status['levels'][$key] : '';
        $attrs = ' class="kpi-value' . ($level !== '' ? ' lvl-' . self::e($level) : '') . '" data-field="' . self::e($key) . '"';
        if ($levelled) {
            $attrs .= ' data-level="' . self::e($key) . '" data-level-prefix="lvl-"';
        }
        $text = isset($status['text'][$key]) ? (string) $status['text'][$key] : '–';
        return '<div class="kpi"><div class="kpi-label">' . self::e($label) . '</div><div' . $attrs . '>' . self::e($text) . '</div></div>';
    }

    /**
     * Renders <tr> rows for a table payload: ['rows' => [[cell, ...], ...], 'cols' => int, 'empty' => string].
     * A cell is a string or ['t' => text, 'c' => class, 'bar' => 0..100].
     */
    public static function tableRows(array $table): string
    {
        $rows = isset($table['rows']) && is_array($table['rows']) ? $table['rows'] : [];
        $cols = isset($table['cols']) ? (int) $table['cols'] : 1;
        if ($rows === []) {
            $empty = isset($table['empty']) ? (string) $table['empty'] : 'No data yet';
            return '<tr class="empty"><td colspan="' . max(1, $cols) . '">' . self::e($empty) . '</td></tr>';
        }
        $html = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            // a row is a plain list of cells, or ['cells' => [...], 'class' => 'row-ok'] when it
            // carries a row colour (the portfolio card colours by PnL sign)
            $class = '';
            $cells = $row;
            if (isset($row['cells']) && is_array($row['cells'])) {
                $cells = $row['cells'];
                $class = isset($row['class']) ? (string) $row['class'] : '';
            }
            $html .= '<tr' . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . '>';
            foreach ($cells as $cell) {
                $html .= self::tableCell($cell);
            }
            $html .= '</tr>';
        }
        return $html;
    }

    private static function tableCell($cell): string
    {
        if (!is_array($cell)) {
            return '<td>' . self::e($cell) . '</td>';
        }
        $text  = isset($cell['t']) ? (string) $cell['t'] : '';
        $class = isset($cell['c']) ? (string) $cell['c'] : '';
        $td = '<td' . ($class !== '' ? ' class="' . self::e($class) . '"' : '') . '>';
        if (isset($cell['bar'])) {
            $w = (int) Util::clamp((float) $cell['bar'], 0.0, 100.0);
            $td .= '<span class="bar-wrap"><svg class="bar" viewBox="0 0 100 10" preserveAspectRatio="none" aria-hidden="true">'
                 . '<rect x="0" y="0" width="' . $w . '" height="10"></rect></svg></span>';
            $td .= '<span class="bar-text">' . self::e($text) . '</span>';
        } elseif (isset($cell['pill'])) {
            $td .= self::pill($text, (string) $cell['pill']);
        } elseif (isset($cell['btn']) && is_array($cell['btn'])) {
            $td .= self::actionButton($cell['btn'], $text);
        } elseif (isset($cell['assign']) && is_array($cell['assign'])) {
            $td .= self::assignControl($cell['assign'], $text);
        } else {
            $td .= self::e($text);
        }
        return $td . '</td>';
    }

    /**
     * Inline POST form for a per-row action button: `['t' => 'Cancel', 'btn' => ['action' => …,
     * 'fields' => ['client_id' => …], 'class' => …]]`. The CSRF token is included, the classes
     * live in assets/panel.css and nothing is inlined — assets/panel.js rebuilds the identical
     * markup when it refreshes the table.
     */
    public static function actionButton(array $btn, string $label): string
    {
        $action = isset($btn['action']) ? (string) $btn['action'] : '';
        if ($action === '') {
            return '';
        }
        $class  = isset($btn['class']) && (string) $btn['class'] !== '' ? (string) $btn['class'] : 'btn btn-mini';
        $fields = isset($btn['fields']) && is_array($btn['fields']) ? $btn['fields'] : [];
        $h = '<form method="post" action="index.php" class="inline">' . self::csrfField()
           . '<input type="hidden" name="action" value="' . self::e($action) . '">';
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . self::e((string) $k) . '" value="' . self::e($v) . '">';
        }
        return $h . '<button type="submit" class="' . self::e($class) . '">'
            . self::e($label !== '' ? $label : 'Go') . '</button></form>';
    }

    /**
     * Sparkline geometry for the last N equity points.
     * @return array{points:string, area:string, min:float, max:float, last:float, first:float, count:int, w:int, h:int}
     */
    public static function sparkline(array $values, int $w = 600, int $h = 140, int $pad = 6): array
    {
        $vals = [];
        foreach ($values as $v) {
            if (is_numeric($v) && is_finite((float) $v)) {
                $vals[] = (float) $v;
            }
        }
        $n = count($vals);
        $out = ['points' => '', 'area' => '', 'min' => 0.0, 'max' => 0.0, 'last' => 0.0, 'first' => 0.0, 'count' => $n, 'w' => $w, 'h' => $h];
        if ($n === 0) {
            return $out;
        }
        $min = min($vals);
        $max = max($vals);
        $out['min']   = $min;
        $out['max']   = $max;
        $out['first'] = $vals[0];
        $out['last']  = $vals[$n - 1];
        $span = $max - $min;
        if ($span <= 0) {
            $span = max(abs($max) * 0.01, 0.01);
            $min  = $min - $span / 2;
        }
        $iw = max(1, $w - 2 * $pad);
        $ih = max(1, $h - 2 * $pad);
        $pts = [];
        for ($i = 0; $i < $n; $i++) {
            $x = $n === 1 ? $pad + $iw / 2 : $pad + $iw * $i / ($n - 1);
            $y = $pad + $ih - ($vals[$i] - $min) / $span * $ih;
            $pts[] = sprintf('%.1F,%.1F', $x, $y);
        }
        $out['points'] = implode(' ', $pts);
        $firstX = sprintf('%.1F', $n === 1 ? $pad + $iw / 2 : $pad);
        $lastX  = sprintf('%.1F', $n === 1 ? $pad + $iw / 2 : $pad + $iw);
        $out['area'] = $firstX . ',' . ($h - $pad) . ' ' . $out['points'] . ' ' . $lastX . ',' . ($h - $pad);
        return $out;
    }

    /** Inline SVG for a sparkline payload (CSS classes only, no style attributes). */
    public static function svgSparkline(array $spark): string
    {
        $w = (int) ($spark['w'] ?? 600);
        $h = (int) ($spark['h'] ?? 140);
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="Equity history">'
            . '<polygon class="spark-area" points="' . self::e((string) ($spark['area'] ?? '')) . '" data-sparkline="area"></polygon>'
            . '<polyline class="spark-line" points="' . self::e((string) ($spark['points'] ?? '')) . '" data-sparkline="points"></polyline>'
            . '</svg>';
    }

    /* ========================================================= status payload */

    private static function isFuture(string $iso, int $now): bool
    {
        if (trim($iso) === '') {
            return false;
        }
        $ts = Util::isoToTs($iso);
        return $ts !== null && $ts > $now;
    }

    private static function f($v, float $d = 0.0): float
    {
        return is_numeric($v) && is_finite((float) $v) ? (float) $v : $d;
    }

    /**
     * Everything the dashboard shows, as one array:
     *  text[field]   display strings keyed by data-field
     *  levels[field] ok|warn|danger|muted for pills / coloured values
     *  tables[name]  ['rows'=>[[cell,...]], 'cols'=>int, 'empty'=>string]
     *  sparkline     Panel::sparkline() output
     *  show[key]     booleans for conditional blocks (position, paper)
     *  raw           numbers for programmatic use
     */
    public static function status(array $cfg, Db $db): array
    {
        $now    = time();
        $nowIso = Util::nowIso($now);
        $tz     = (string) ($cfg['timezone'] ?? 'UTC');
        $quote  = (string) ($cfg['quote_asset'] ?? 'USDT');
        $mode   = (string) ($cfg['mode'] ?? 'paper');

        $state = [];
        try {
            $state = $db->allState();
        } catch (Throwable $e) {
            $state = [];
        }
        $st = static function (string $k, string $d = '') use ($state): string {
            return array_key_exists($k, $state) ? (string) $state[$k] : $d;
        };

        $text   = [];
        $levels = [];
        $raw    = [];

        // ---- mode & bot state
        $text['mode']   = strtoupper($mode);
        $levels['mode'] = $mode === 'live' ? 'danger' : (($mode === 'testnet' || $mode === 'demo') ? 'warn' : 'info');

        $enabled       = !empty($cfg['enabled']);
        $halted        = $st('halted', '0') === '1';
        $apiPausedTill = $st('api_paused_until');
        $pausedTill    = $st('paused_until');
        $cooldownTill  = $st('cooldown_until');
        $pauseReason   = $st('pause_reason');

        if ($halted) {
            $botState = 'HALTED';
            $reason   = $st('halt_reason', 'unknown');
            $botLevel = 'danger';
        } elseif (!$enabled) {
            $botState = 'PAUSED';
            $reason   = $pauseReason !== '' ? $pauseReason : 'disabled';
            $botLevel = 'warn';
        } elseif (self::isFuture($apiPausedTill, $now)) {
            $botState = 'API PAUSED';
            $reason   = 'until ' . self::fmtTime($apiPausedTill, $tz, 'H:i');
            $botLevel = 'warn';
        } elseif (self::isFuture($pausedTill, $now)) {
            $botState = 'PAUSED';
            $reason   = ($pauseReason !== '' ? $pauseReason . ' ' : '') . 'until ' . self::fmtTime($pausedTill, $tz, 'M j H:i');
            $botLevel = 'warn';
        } elseif (self::isFuture($cooldownTill, $now)) {
            $botState = 'COOLDOWN';
            $reason   = 'until ' . self::fmtTime($cooldownTill, $tz, 'H:i');
            $botLevel = 'warn';
        } else {
            $botState = 'ENABLED';
            $reason   = '';
            $botLevel = 'ok';
        }
        $text['bot_state']   = $botState . ($reason !== '' ? ' (' . $reason . ')' : '');
        $levels['bot_state'] = $botLevel;
        $text['bot_reason']  = $reason;
        $raw['halted']       = $halted;
        $raw['enabled']      = $enabled;

        // ---- last tick
        $lastTickAt = $st('last_tick_at');
        $tickTs     = $lastTickAt !== '' ? Util::isoToTs($lastTickAt) : null;
        $tickAge    = $tickTs === null ? null : max(0, $now - $tickTs);
        $cronOk     = $tickAge !== null && $tickAge <= self::CRON_STALE_SECONDS;
        if ($tickAge === null) {
            $text['tick_age'] = 'cron not running (no tick yet)';
        } elseif (!$cronOk) {
            $text['tick_age'] = 'cron not running (last tick ' . self::duration($tickAge) . ' ago)';
        } else {
            $text['tick_age'] = 'tick ' . self::duration($tickAge) . ' ago';
        }
        $levels['tick_age']  = $cronOk ? 'ok' : 'danger';
        $text['tick_at']     = self::fmtTime($lastTickAt, $tz);
        $text['tick_status'] = $st('last_tick_status', '–');
        $text['tick_ms']     = $st('last_tick_ms') !== '' ? $st('last_tick_ms') . ' ms' : '–';
        $raw['tick_age_s']   = $tickAge;

        // ---- stats & equity
        $stats = [];
        try {
            $stats = $db->stats();
        } catch (Throwable $e) {
            $stats = [];
        }
        $eq = null;
        try {
            $eq = $db->lastEquity();
        } catch (Throwable $e) {
            $eq = null;
        }
        $equity    = $eq !== null ? self::f($eq['equity_usdt'] ?? 0) : 0.0;
        $quoteFree = $eq !== null ? self::f($eq['quote_free'] ?? 0) : 0.0;
        $dustValue = $eq !== null ? self::f($eq['dust_value'] ?? 0) : 0.0;
        $posValue  = $eq !== null ? self::f($eq['position_value'] ?? 0) : 0.0;
        $eqTs      = $eq !== null ? (string) ($eq['ts'] ?? '') : '';
        $hwm       = self::f($st('equity_hwm'), 0.0);

        $pnlToday = self::f($stats['pnl_today'] ?? 0);
        $pnlWeek  = self::f($stats['pnl_week'] ?? 0);
        $pnlTotal = self::f($stats['total_pnl'] ?? 0);
        $winRate  = self::f($stats['win_rate'] ?? 0);
        $expect   = self::f($stats['expectancy'] ?? 0);
        $closedN  = (int) ($stats['closed'] ?? 0);
        $wins     = (int) ($stats['wins'] ?? 0);
        $losses   = (int) ($stats['losses'] ?? 0);
        $fees     = self::f($stats['fees_total'] ?? 0);
        $tradesToday = (int) ($stats['trades_today'] ?? 0);

        $effThreshold = 0;
        $effMax       = 0;
        try {
            $effThreshold = Risk::effectiveThreshold($cfg, $db);
            $effMax       = Risk::effectiveMaxTrades($cfg, $db);
        } catch (Throwable $e) {
            $effThreshold = (int) ($cfg['entry_threshold'] ?? 60);
            $effMax       = (int) ($cfg['max_trades_per_day'] ?? 3);
        }

        $text['equity']       = Util::money($equity, 4) . ' ' . $quote;
        $text['equity_at']    = $eqTs !== '' ? self::ago($eqTs, $now) : 'never';
        $text['quote_free']   = Util::money($quoteFree, 4) . ' ' . $quote;
        $text['dust_value']   = Util::money($dustValue, 4) . ' ' . $quote;
        $text['pnl_today']    = self::signed($pnlToday, 4);
        $text['pnl_week']     = self::signed($pnlWeek, 4);
        $text['pnl_total']    = self::signed($pnlTotal, 4);
        $levels['pnl_today']  = self::pnlLevel($pnlToday);
        $levels['pnl_week']   = self::pnlLevel($pnlWeek);
        $levels['pnl_total']  = self::pnlLevel($pnlTotal);
        $text['win_rate']     = $closedN > 0 ? Util::money($winRate, 1) . ' % (' . $wins . 'W/' . $losses . 'L)' : '– (no trades)';
        $text['expectancy']   = $closedN > 0 ? self::signed($expect, 4) . ' /trade' : '–';
        $levels['expectancy'] = $closedN > 0 ? self::pnlLevel($expect) : 'muted';
        $text['trades_today'] = $tradesToday . ' / ' . $effMax;
        $levels['trades_today'] = $tradesToday >= $effMax ? 'warn' : 'muted';
        $text['fees_total']   = Util::money($fees, 4) . ' ' . $quote;
        $text['effective_threshold'] = (string) $effThreshold . ' / 100';
        $text['equity_hwm']   = $hwm > 0 ? Util::money($hwm, 4) . ' ' . $quote : '–';
        $text['closed_count'] = (string) $closedN;

        $floor = self::f($cfg['equity_floor_usdt'] ?? 7.0, 7.0);
        $levels['equity'] = $equity > 0 && $equity <= $floor * 1.05 ? 'danger' : ($equity > 0 ? 'ok' : 'muted');
        $text['equity_floor'] = Util::money($floor, 2) . ' ' . $quote;

        $raw['equity']     = $equity;
        $raw['quote_free'] = $quoteFree;
        $raw['dust_value'] = $dustValue;
        $raw['pnl_today']  = $pnlToday;
        $raw['pnl_week']   = $pnlWeek;
        $raw['pnl_total']  = $pnlTotal;
        $raw['win_rate']   = $winRate;
        $raw['expectancy'] = $expect;
        $raw['fees_total'] = $fees;
        $raw['equity_hwm'] = $hwm;
        $raw['effective_threshold'] = $effThreshold;
        $raw['effective_max_trades'] = $effMax;
        $raw['trades_today'] = $tradesToday;

        // ---- API health
        $apiError   = $st('api_error');
        $netErrors  = (int) self::f($st('net_errors', '0'));
        $apiPaused  = self::isFuture($apiPausedTill, $now);
        $text['time_offset_ms']   = $st('time_offset_ms') !== '' ? $st('time_offset_ms') . ' ms' : '–';
        $text['used_weight']      = $st('used_weight') !== '' ? $st('used_weight') . ' / 6000' : '–';
        $text['api_error']        = $apiError !== '' ? $apiError : 'none';
        $text['api_paused_until'] = $apiPaused ? self::fmtTime($apiPausedTill, $tz) : 'not paused';
        $text['net_errors']       = (string) $netErrors;
        $text['symbol_info_at']   = self::ago($st('symbol_info_at'), $now);
        $text['no_trade_reason']  = $st('no_trade_reason') !== '' ? $st('no_trade_reason') : '–';
        $text['fee_pct']          = Util::money(self::f($st('fee_pct_live'), self::f($cfg['fee_pct'] ?? 0.1, 0.1)), 3) . ' %'
                                  . ($st('fee_pct_live') !== '' ? ' (live)' : ' (config)');
        if ($apiPaused) {
            $apiLevel = 'danger';
            $apiText  = 'PAUSED';
        } elseif ($apiError !== '' || $netErrors > 0) {
            $apiLevel = 'warn';
            $apiText  = 'DEGRADED';
        } else {
            $apiLevel = 'ok';
            $apiText  = 'OK';
        }
        $text['api_health']   = $apiText;
        $levels['api_health'] = $apiLevel;
        $levels['api_error']  = $apiError !== '' ? 'warn' : 'muted';
        $levels['api_paused_until'] = $apiPaused ? 'danger' : 'muted';

        // ---- open position
        $pos = null;
        try {
            $pos = $db->openPosition();
        } catch (Throwable $e) {
            $pos = null;
        }
        $show = ['position' => $pos !== null, 'paper' => $mode === 'paper', 'halted' => $halted, 'enabled' => $enabled];
        $posPayload = null;
        if ($pos !== null) {
            $qty      = self::f($pos['qty'] ?? 0);
            $entryEff = self::f($pos['entry_eff'] ?? 0);
            $bid      = null;
            $posTs    = $eqTs !== '' ? Util::isoToTs($eqTs) : null;
            $openedTs = Util::isoToTs((string) ($pos['opened_at'] ?? ''));
            if ($qty > 0 && $posValue > 0 && $posTs !== null && ($openedTs === null || $posTs >= $openedTs)) {
                $bid = $posValue / $qty;   // last tick valued the position at the current price
            }
            $unreal = ($bid !== null && $entryEff > 0) ? ($bid - $entryEff) / $entryEff * 100.0 : null;
            $exitText  = 'holding';
            $exitLevel = 'muted';
            if ($bid !== null) {
                try {
                    $dec = Risk::exitDecision($pos, $bid, $cfg, $nowIso);
                    $r = (string) ($dec['reason'] ?? '');
                    if ($r !== '') {
                        $exitText  = 'exit pending: ' . $r;
                        $exitLevel = $r === 'take_profit' ? 'ok' : 'warn';
                    } elseif (!empty($dec['trailing_armed'])) {
                        $exitText  = 'holding, trailing armed';
                        $exitLevel = 'ok';
                    }
                } catch (Throwable $e) {
                    $exitText = 'holding';
                }
            } else {
                $exitText = 'holding (bid unknown until next tick)';
            }
            $ageS = $openedTs !== null ? max(0, $now - $openedTs) : 0;
            $maxHold = (int) ($cfg['max_hold_minutes'] ?? 240);
            $text['pos_symbol']    = (string) ($pos['symbol'] ?? '');
            $text['pos_status']    = (string) ($pos['status'] ?? 'OPEN');
            $text['pos_qty']       = Util::toDecimalString($qty, 8) . ' (dust ' . Util::toDecimalString(self::f($pos['dust_qty'] ?? 0), 8) . ')';
            $text['pos_entry_eff'] = Util::money($entryEff, 6) . ' (fill ' . Util::money(self::f($pos['entry_price'] ?? 0), 6) . ')';
            $text['pos_entry_quote'] = Util::money(self::f($pos['entry_quote'] ?? 0), 4) . ' ' . $quote;
            $text['pos_bid']       = $bid !== null ? Util::money($bid, 6) : '–';
            $text['pos_unreal_pct'] = $unreal !== null
                ? self::pct($unreal, 2, true) . ' (' . self::signed(($bid - $entryEff) * $qty, 4) . ')'
                : '–';
            $levels['pos_unreal_pct'] = $unreal !== null ? self::pnlLevel($unreal) : 'muted';
            $text['pos_stop']      = Util::money(self::f($pos['stop_price'] ?? 0), 6)
                . ($entryEff > 0 ? ' (' . self::pct((self::f($pos['stop_price'] ?? 0) - $entryEff) / $entryEff * 100.0, 2, true) . ')' : '');
            $text['pos_tp']        = Util::money(self::f($pos['take_profit_price'] ?? 0), 6)
                . ($entryEff > 0 ? ' (' . self::pct((self::f($pos['take_profit_price'] ?? 0) - $entryEff) / $entryEff * 100.0, 2, true) . ')' : '');
            $text['pos_trailing']  = !empty($pos['trailing_armed']) ? 'armed, high ' . Util::money(self::f($pos['trail_high'] ?? 0), 6) : 'not armed';
            $levels['pos_trailing'] = !empty($pos['trailing_armed']) ? 'ok' : 'muted';
            $text['pos_age']       = self::duration($ageS) . ' / ' . $maxHold . ' min max';
            $text['pos_opened']    = self::fmtTime((string) ($pos['opened_at'] ?? ''), $tz);
            $text['pos_exit']      = $exitText;
            $levels['pos_exit']    = $exitLevel;
            $text['pos_score']     = isset($pos['score']) ? (string) (int) $pos['score'] . ' (' . (string) ($pos['entry_reason'] ?? '') . ')' : '–';
            $posPayload = [
                'id' => (int) ($pos['id'] ?? 0), 'symbol' => (string) ($pos['symbol'] ?? ''), 'status' => (string) ($pos['status'] ?? ''),
                'qty' => $qty, 'entry_eff' => $entryEff, 'bid' => $bid, 'unrealised_pct' => $unreal,
                'stop_price' => self::f($pos['stop_price'] ?? 0), 'take_profit_price' => self::f($pos['take_profit_price'] ?? 0),
                'trailing_armed' => !empty($pos['trailing_armed']), 'opened_at' => (string) ($pos['opened_at'] ?? ''),
            ];
        } else {
            foreach (['pos_symbol', 'pos_status', 'pos_qty', 'pos_entry_eff', 'pos_entry_quote', 'pos_bid', 'pos_unreal_pct', 'pos_stop', 'pos_tp', 'pos_trailing', 'pos_age', 'pos_opened', 'pos_exit', 'pos_score'] as $k) {
                $text[$k] = '–';
            }
            $levels['pos_unreal_pct'] = 'muted';
            $levels['pos_exit']       = 'muted';
            $levels['pos_trailing']   = 'muted';
        }

        // ---- equity sparkline
        $series = [];
        try {
            foreach ($db->equitySeries(288) as $row) {
                $series[] = self::f($row['equity_usdt'] ?? 0);
            }
        } catch (Throwable $e) {
            $series = [];
        }
        $spark = self::sparkline($series);
        $text['spark_min']   = $spark['count'] > 0 ? Util::money($spark['min'], 4) : '–';
        $text['spark_max']   = $spark['count'] > 0 ? Util::money($spark['max'], 4) : '–';
        $text['spark_last']  = $spark['count'] > 0 ? Util::money($spark['last'], 4) : '–';
        $text['spark_count'] = $spark['count'] . ' points';
        $text['spark_change'] = $spark['count'] > 1 && $spark['first'] > 0
            ? self::pct(($spark['last'] - $spark['first']) / $spark['first'] * 100.0, 2, true)
            : '–';
        $levels['spark_change'] = $spark['count'] > 1 ? self::pnlLevel($spark['last'] - $spark['first']) : 'muted';

        // ---- symbols table
        $tables = [];
        $tables['symbols'] = self::symbolsTable($cfg, $db, $st, $now, $tz);

        // ---- closed positions
        $rows = [];
        try {
            foreach ($db->closedPositions(30) as $p) {
                $pnl    = isset($p['pnl_usdt']) && $p['pnl_usdt'] !== null ? self::f($p['pnl_usdt']) : null;
                $pnlPct = isset($p['pnl_pct']) && $p['pnl_pct'] !== null ? self::f($p['pnl_pct']) : null;
                $status = (string) ($p['status'] ?? '');
                $rows[] = [
                    ['t' => (string) ($p['symbol'] ?? ''), 'c' => 'mono'],
                    ['t' => self::fmtTime((string) ($p['opened_at'] ?? ''), $tz, 'm-d H:i'), 'c' => 'mono'],
                    ['t' => self::fmtTime((string) ($p['closed_at'] ?? ''), $tz, 'm-d H:i'), 'c' => 'mono'],
                    ['t' => Util::toDecimalString(self::f($p['qty'] ?? 0), 8), 'c' => 'num'],
                    ['t' => Util::money(self::f($p['entry_eff'] ?? 0), 6), 'c' => 'num'],
                    ['t' => isset($p['exit_price']) && $p['exit_price'] !== null ? Util::money(self::f($p['exit_price']), 6) : '–', 'c' => 'num'],
                    ['t' => $pnl !== null ? self::signed($pnl, 4) : '–', 'c' => 'num ' . ($pnl !== null ? 'lvl-' . self::pnlLevel($pnl) : '')],
                    ['t' => $pnlPct !== null ? self::pct($pnlPct, 2, true) : '–', 'c' => 'num ' . ($pnlPct !== null ? 'lvl-' . self::pnlLevel($pnlPct) : '')],
                    ['t' => (string) ($p['exit_reason'] ?? ''), 'c' => 'mono'],
                    ['t' => $status, 'pill' => $status === 'STUCK' ? 'danger' : 'muted'],
                    ['t' => isset($p['score']) && $p['score'] !== null ? (string) (int) $p['score'] : '–', 'c' => 'num'],
                ];
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        $tables['closed'] = ['rows' => $rows, 'cols' => 11, 'empty' => 'No closed positions yet'];

        // ---- no-trade reasons (24 h)
        $rows = [];
        try {
            $hist = $db->noTradeReasons(24);
            $maxC = 0;
            foreach ($hist as $c) {
                $maxC = max($maxC, (int) $c);
            }
            foreach ($hist as $reason => $c) {
                $rows[] = [
                    ['t' => (string) $reason, 'c' => 'mono'],
                    ['t' => (string) (int) $c, 'c' => 'num'],
                    ['t' => '', 'bar' => $maxC > 0 ? (int) round((int) $c / $maxC * 100) : 0],
                ];
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        $tables['no_trade'] = ['rows' => $rows, 'cols' => 3, 'empty' => 'No ineligible evaluations in the last 24 h'];

        // ---- log tail
        $rows = [];
        try {
            foreach ($db->logs(40) as $l) {
                $level = strtoupper((string) ($l['level'] ?? 'INFO'));
                $lvl   = $level === 'ERROR' ? 'danger' : ($level === 'WARN' ? 'warn' : ($level === 'DEBUG' ? 'muted' : 'info'));
                $msg   = (string) ($l['message'] ?? '');
                $ctx   = (string) ($l['context'] ?? '');
                if ($ctx !== '' && $ctx !== 'null') {
                    $msg .= '  ' . (strlen($ctx) > 300 ? substr($ctx, 0, 300) . '…' : $ctx);
                }
                $rows[] = [
                    ['t' => self::fmtTime((string) ($l['ts'] ?? ''), $tz, 'm-d H:i:s'), 'c' => 'mono nowrap'],
                    ['t' => $level, 'pill' => $lvl],
                    ['t' => $msg, 'c' => 'logmsg'],
                ];
            }
        } catch (Throwable $e) {
            $rows = [];
        }
        $tables['logs'] = ['rows' => $rows, 'cols' => 3, 'empty' => 'No log entries yet'];

        // ---- engine card, open orders, cycles (DESIGN-ENGINES.md §10)
        $eng    = self::engineBlock($cfg, $db, $st, $now, $tz);
        $text   = array_merge($text, $eng['text']);
        $levels = array_merge($levels, $eng['levels']);
        $raw    = array_merge($raw, $eng['raw']);
        $tables = array_merge($tables, $eng['tables']);
        $show   = array_merge($show, $eng['show']);

        // ---- portfolio card, sleeve sparkline, scanner (DESIGN-PORTFOLIO.md §7).
        // Additive: with portfolio_enabled = false every block above is untouched and
        // show.portfolio / show.scanner keep the new cards hidden.
        $pf     = self::portfolioBlock($cfg, $db, $st, $now, $tz);
        $text   = array_merge($text, $pf['text']);
        $levels = array_merge($levels, $pf['levels']);
        $raw    = array_merge($raw, $pf['raw']);
        $tables = array_merge($tables, $pf['tables']);
        $show   = array_merge($show, $pf['show']);

        // ---- the learning line and the BNB fee-discount rows (DESIGN-LEARNING.md §6, §7).
        // Additive and database-only: no network call is ever made while rendering.
        $learn  = self::learnBlock($cfg, $db, $now);
        $bnb    = self::bnbBlock($cfg, $db, $now, $tz);
        $text   = array_merge($text, $learn['text'], $bnb['text']);
        $levels = array_merge($levels, $learn['levels'], $bnb['levels']);
        $show   = array_merge($show, $learn['show'], $bnb['show']);
        $raw    = array_merge($raw, $learn['raw'], $bnb['raw']);

        $text['now']         = self::fmtTime($nowIso, $tz) . ' ' . $tz;
        $text['api_key_fp']  = self::keyFingerprint((string) ($cfg['api_key'] ?? ''));

        return [
            'ok'        => true,
            'now'       => $nowIso,
            'mode'      => $mode,
            'text'      => $text,
            'levels'    => $levels,
            'show'      => $show,
            'tables'    => $tables,
            'sparkline' => $spark,
            'position'  => $posPayload,
            'engine'    => $eng['payload'],
            'portfolio' => $pf['portfolio'],
            'scanner'   => $pf['scanner'],
            'sleeve_sparkline' => $pf['sparkline'],
            'learn'     => $learn['payload'],
            'bnb'       => $bnb['payload'],
            'raw'       => $raw,
            'refresh_s' => 20,
        ];
    }


    /**
     * Engine card, open-order table, cycle table and cycle KPIs (DESIGN-ENGINES.md §10).
     *
     * Always returns the whole shape, even for the signal engine, so `?api=status` keeps one
     * stable contract and panel.js never has to test for missing keys; `show.engine` is what
     * decides whether the dashboard renders the block at all.
     *
     * @return array{text: array, levels: array, raw: array, tables: array, show: array, payload: array}
     */
    private static function engineBlock(array $cfg, Db $db, callable $st, int $now, string $tz): array
    {
        $engine = strtolower(trim((string) ($cfg['engine'] ?? 'signal')));
        if (!in_array($engine, ['signal', 'grid', 'pmm'], true)) {
            $engine = 'signal';
        }
        $active = $engine !== 'signal';
        $symbol = strtoupper(trim((string) ($cfg['engine_symbol'] ?? '')));
        $quote  = (string) ($cfg['quote_asset'] ?? 'USDT');
        $mode   = (string) ($cfg['mode'] ?? 'paper');
        $base   = '';

        $text   = [];
        $levels = [];
        $raw    = [];

        // ---- symbol info + last seen price (the engine symbol need not be on the watchlist,
        //      so symbol_metrics may not carry it; the last engine trade is then the best price)
        $info = null;
        try {
            $all = $db->getStateJson('symbol_info', []);
            if (is_array($all) && $symbol !== '' && isset($all[$symbol]) && is_array($all[$symbol])) {
                $info = $all[$symbol];
                $base = (string) ($info['base'] ?? '');
            }
        } catch (Throwable $e) {
            $info = null;
        }
        if ($base === '' && $symbol !== '' && strlen($symbol) > strlen($quote) && substr($symbol, -strlen($quote)) === $quote) {
            $base = substr($symbol, 0, -strlen($quote));
        }
        $price = 0.0;
        try {
            $metrics = $db->getStateJson('symbol_metrics', []);
            if (is_array($metrics) && $symbol !== '' && isset($metrics[$symbol]['price'])) {
                $price = self::f($metrics[$symbol]['price']);
            }
        } catch (Throwable $e) {
            $price = 0.0;
        }
        if ($price <= 0.0 && $symbol !== '') {
            try {
                $q = $db->pdo()->prepare('SELECT price FROM trades WHERE symbol = ? ORDER BY id DESC LIMIT 1');
                $q->execute([$symbol]);
                $v = $q->fetchColumn();
                $price = ($v === false || $v === null) ? 0.0 : self::f($v);
            } catch (Throwable $e) {
                $price = 0.0;
            }
        }

        // ---- KPIs, inventory and cycles
        $stats  = ['cycles' => 0, 'pnl' => 0.0, 'fees' => 0.0, 'wins' => 0, 'losses' => 0,
                   'win_rate' => 0.0, 'inventory_qty' => 0.0, 'inventory_cost' => 0.0];
        $cycles = [];
        $pnlToday = 0.0;
        try {
            $stats = array_merge($stats, $db->engineStats($mode, $active ? $engine : null));
        } catch (Throwable $e) {
            // table missing (older install): the card just shows zeros
        }
        try {
            $cycles = $db->cycles(30, $mode, $active ? $engine : null);
        } catch (Throwable $e) {
            $cycles = [];
        }
        $todayStart = Util::todayUtc() . 'T00:00:00Z';
        try {
            $pnlToday = $db->cyclePnl($todayStart, $mode, $active ? $engine : null);
        } catch (Throwable $e) {
            $pnlToday = 0.0;
        }
        $cyclesToday = 0;
        foreach ($cycles as $c) {
            if (strcmp((string) ($c['closed_at'] ?? ''), $todayStart) >= 0) {
                $cyclesToday++;
            }
        }

        $invQty  = self::f($stats['inventory_qty']);
        $invCost = self::f($stats['inventory_cost']);
        $invVal  = $price > 0.0 ? $invQty * $price : 0.0;
        $unreal  = ($price > 0.0 && $invQty > 0.0) ? $invVal - $invCost : 0.0;

        // ---- open orders
        $open = [];
        try {
            // the table carries per-order Cancel buttons that act through the CURRENT mode's
            // exchange, so it must only ever show the current mode's rows (strtolower/trim
            // matches how EngineOrders normalises the mode it writes)
            $open = $symbol !== ''
                ? $db->openEngineOrders($symbol, strtolower(trim($mode)))
                : $db->engineOrders(Db::ENGINE_LIVE_STATUSES, strtolower(trim($mode)));
        } catch (Throwable $e) {
            $open = [];
        }
        $cap = (int) self::f($cfg['engine_max_orders'] ?? 12, 12.0);
        if ($cap < 1) {
            $cap = 1;
        }

        // ---- anchor and range edges (grid only)
        $anchor   = 0.0;
        $anchorAt = '';
        if ((string) $st('grid_symbol', '') === $symbol && $symbol !== '') {
            $anchor   = self::f($st('grid_anchor', '0'));
            $anchorAt = $st('grid_anchor_at', '');
        }
        $upPct   = self::f($cfg['grid_range_up_pct'] ?? 4.0, 4.0);
        $downPct = self::f($cfg['grid_range_down_pct'] ?? 6.0, 6.0);
        $upEdge   = $anchor > 0.0 ? $anchor * (1.0 + $upPct / 100.0) : 0.0;
        $downEdge = $anchor > 0.0 ? $anchor * (1.0 - $downPct / 100.0) : 0.0;

        // ---- state pill
        $liveBlocked = $active && $mode === 'live' && empty($cfg['allow_live_engines']);
        $gridPause   = (string) $st('grid_paused_reason', '');
        if (!$active) {
            $stateText  = 'signal engine';
            $stateLevel = 'muted';
        } elseif ($liveBlocked) {
            $stateText  = 'BLOCKED in live mode (allow_live_engines is off)';
            $stateLevel = 'danger';
        } elseif ($gridPause !== '') {
            $stateText  = 'PAUSED (' . $gridPause . ') - re-anchor to resume';
            $stateLevel = 'warn';
        } elseif (empty($cfg['enabled'])) {
            $stateText  = 'PAUSED (entries disabled)';
            $stateLevel = 'warn';
        } elseif ($open !== []) {
            $stateText  = 'quoting';
            $stateLevel = 'ok';
        } else {
            $stateText  = 'idle (no resting order)';
            $stateLevel = 'muted';
        }

        $text['eng_name']      = strtoupper($engine);
        $levels['eng_name']    = $active ? ($engine === 'pmm' ? 'warn' : 'info') : 'muted';
        $text['eng_state']     = $stateText;
        $levels['eng_state']   = $stateLevel;
        $text['eng_symbol']    = $symbol !== '' ? $symbol : '-';
        $text['eng_price']     = $price > 0.0 ? Util::money($price, 6) : '-';
        $text['eng_anchor']    = $anchor > 0.0
            ? Util::money($anchor, 6) . ($anchorAt !== '' ? ' (' . self::ago($anchorAt, $now) . ')' : '')
            : ($engine === 'grid' ? 'not anchored yet' : '-');
        $text['eng_anchor_at'] = $anchorAt !== '' ? self::fmtTime($anchorAt, $tz) : '-';
        $text['eng_range_up']   = $upEdge > 0.0
            ? Util::money($upEdge, 6) . ' (+' . Util::money($upPct, 2) . ' %'
              . ($price > 0.0 ? ', ' . self::pct(($upEdge - $price) / $price * 100.0, 2, true) . ' away' : '') . ')'
            : '-';
        $text['eng_range_down'] = $downEdge > 0.0
            ? Util::money($downEdge, 6) . ' (-' . Util::money($downPct, 2) . ' %'
              . ($price > 0.0 ? ', ' . self::pct(($downEdge - $price) / $price * 100.0, 2, true) . ' away' : '') . ')'
            : '-';
        $levels['eng_range_up']   = ($upEdge > 0.0 && $price > 0.0 && $price >= $upEdge * 0.99) ? 'warn' : 'muted';
        $levels['eng_range_down'] = ($downEdge > 0.0 && $price > 0.0 && $price <= $downEdge * 1.01) ? 'warn' : 'muted';
        $text['eng_orders']    = count($open) . ' / ' . $cap;
        $levels['eng_orders']  = count($open) >= $cap ? 'warn' : 'muted';
        $text['eng_inventory'] = Util::toDecimalString($invQty, 8) . ($base !== '' ? ' ' . $base : '');
        $text['eng_inv_cost']  = Util::money($invCost, 4) . ' ' . $quote
            . ($invQty > 0.0 ? ' (avg ' . Util::money($invCost / $invQty, 6) . ')' : '');
        $text['eng_inv_value'] = $price > 0.0 ? Util::money($invVal, 4) . ' ' . $quote : '-';
        $text['eng_unreal']    = ($price > 0.0 && $invQty > 0.0)
            ? self::signed($unreal, 4) . ' (' . self::pct($invCost > 0.0 ? $unreal / $invCost * 100.0 : 0.0, 2, true) . ')'
            : '-';
        $levels['eng_unreal']  = ($price > 0.0 && $invQty > 0.0) ? self::pnlLevel($unreal) : 'muted';

        $text['eng_cycles_today'] = (string) $cyclesToday;
        $text['eng_cycles']       = (string) (int) $stats['cycles'];
        $text['eng_pnl']          = self::signed(self::f($stats['pnl']), 4);
        $levels['eng_pnl']        = self::pnlLevel(self::f($stats['pnl']));
        $text['eng_pnl_today']    = self::signed($pnlToday, 4);
        $levels['eng_pnl_today']  = self::pnlLevel($pnlToday);
        $text['eng_fees']         = Util::money(self::f($stats['fees']), 4) . ' ' . $quote;
        $decided = (int) $stats['wins'] + (int) $stats['losses'];
        $text['eng_win_rate']     = $decided > 0
            ? Util::money(self::f($stats['win_rate']), 1) . ' % (' . (int) $stats['wins'] . 'W/' . (int) $stats['losses'] . 'L)'
            : '- (no cycles)';
        $text['eng_spread']       = Util::money(self::f($cfg['pmm_spread_pct'] ?? 0.25, 0.25), 3) . ' % each side';
        $text['eng_refresh']      = (string) (int) self::f($cfg['pmm_refresh_sec'] ?? 60, 60.0) . ' s';
        $text['eng_spacing']      = Util::money(self::f($cfg['grid_spacing_pct'] ?? 0.60, 0.60), 3) . ' %';

        // ---- open-orders table (side, level, price, qty, quote, age, status, cancel)
        $rows = [];
        foreach ($open as $o) {
            $side   = strtoupper((string) ($o['side'] ?? ''));
            $status = strtoupper((string) ($o['status'] ?? ''));
            $ts     = Util::isoToTs((string) ($o['created_at'] ?? ''));
            $rows[] = [
                ['t' => $side, 'pill' => $side === 'SELL' ? 'warn' : 'info'],
                ['t' => ($o['level'] === null || $o['level'] === '') ? '-' : (string) (int) $o['level'], 'c' => 'num'],
                ['t' => Util::money(self::f($o['price'] ?? 0), 6), 'c' => 'num'],
                ['t' => Util::toDecimalString(self::f($o['qty'] ?? 0), 8), 'c' => 'num'],
                ['t' => Util::money(self::f($o['quote'] ?? 0), 4), 'c' => 'num'],
                ['t' => $ts === null ? '-' : self::duration(max(0, $now - $ts)), 'c' => 'nowrap'],
                ['t' => $status, 'pill' => $status === 'PARTIALLY_FILLED' ? 'warn' : 'muted'],
                ['t' => 'Cancel', 'btn' => [
                    'action' => 'cancel_order',
                    'fields' => ['client_id' => (string) ($o['client_id'] ?? '')],
                    'class'  => 'btn btn-mini btn-warn',
                ]],
            ];
        }
        $tables = [];
        $tables['engine_orders'] = ['rows' => $rows, 'cols' => 8, 'empty' => 'No resting engine orders'];

        // ---- cycles table (last 30)
        $rows = [];
        foreach ($cycles as $c) {
            $pnl = self::f($c['pnl_usdt'] ?? 0);
            $rows[] = [
                ['t' => ($c['level'] === null || $c['level'] === '') ? '-' : (string) (int) $c['level'], 'c' => 'num'],
                ['t' => Util::money(self::f($c['buy_price'] ?? 0), 6), 'c' => 'num'],
                ['t' => Util::money(self::f($c['sell_price'] ?? 0), 6), 'c' => 'num'],
                ['t' => Util::toDecimalString(self::f($c['qty'] ?? 0), 8), 'c' => 'num'],
                ['t' => self::signed($pnl, 6), 'c' => 'num lvl-' . self::pnlLevel($pnl)],
                ['t' => self::fmtTime((string) ($c['closed_at'] ?? ''), $tz, 'm-d H:i:s'), 'c' => 'mono nowrap'],
            ];
        }
        $tables['cycles'] = ['rows' => $rows, 'cols' => 6, 'empty' => 'No completed cycles yet'];

        $raw['engine']          = $engine;
        $raw['engine_symbol']   = $symbol;
        $raw['engine_anchor']   = $anchor;
        $raw['engine_orders']   = count($open);
        $raw['engine_cap']      = $cap;
        $raw['inventory_qty']   = $invQty;
        $raw['inventory_cost']  = $invCost;
        $raw['inventory_value'] = $invVal;
        $raw['inventory_unreal'] = $unreal;
        $raw['cycles']          = (int) $stats['cycles'];
        $raw['cycles_today']    = $cyclesToday;
        $raw['cycle_pnl']       = self::f($stats['pnl']);
        $raw['cycle_pnl_today'] = $pnlToday;
        $raw['cycle_fees']      = self::f($stats['fees']);
        $raw['cycle_win_rate']  = self::f($stats['win_rate']);
        $raw['engine_live_blocked'] = $liveBlocked;

        return [
            'text'    => $text,
            'levels'  => $levels,
            'raw'     => $raw,
            'tables'  => $tables,
            'show'    => [
                'engine'              => $active,
                'signal_engine'       => !$active,
                'grid_engine'         => $engine === 'grid',
                'pmm_engine'          => $engine === 'pmm',
                'engine_live_blocked' => $liveBlocked,
            ],
            'payload' => [
                'engine'         => $engine,
                'active'         => $active,
                'symbol'         => $symbol,
                'base'           => $base,
                'price'          => $price,
                'anchor'         => $anchor,
                'anchor_at'      => $anchorAt,
                'range_up'       => $upEdge,
                'range_down'     => $downEdge,
                'open_orders'    => count($open),
                'max_orders'     => $cap,
                'inventory_qty'  => $invQty,
                'inventory_cost' => $invCost,
                'inventory_value' => $invVal,
                'unrealised'     => $unreal,
                'cycles'         => (int) $stats['cycles'],
                'cycles_today'   => $cyclesToday,
                'pnl'            => self::f($stats['pnl']),
                'pnl_today'      => $pnlToday,
                'fees'           => self::f($stats['fees']),
                'wins'           => (int) $stats['wins'],
                'losses'         => (int) $stats['losses'],
                'win_rate'       => self::f($stats['win_rate']),
                'live_blocked'   => $liveBlocked,
                'paused_reason'  => $gridPause,
            ],
        ];
    }

    /** Symbols table rows: symbol info (state) + latest signals (+ optional state `symbol_metrics`). */
    private static function symbolsTable(array $cfg, Db $db, callable $st, int $now, string $tz): array
    {
        $symbols = isset($cfg['symbols']) && is_array($cfg['symbols']) ? $cfg['symbols'] : [];
        $info    = [];
        $signals = [];
        $metrics = [];
        try {
            $info = $db->getStateJson('symbol_info', []);
            if (!is_array($info)) {
                $info = [];
            }
        } catch (Throwable $e) {
            $info = [];
        }
        try {
            $signals = $db->latestSignals();
        } catch (Throwable $e) {
            $signals = [];
        }
        try {
            $metrics = $db->getStateJson('symbol_metrics', []);
            if (!is_array($metrics)) {
                $metrics = [];
            }
        } catch (Throwable $e) {
            $metrics = [];
        }
        $feePct    = self::f($st('fee_pct_live'), self::f($cfg['fee_pct'] ?? 0.1, 0.1));
        $tradeUsdt = self::f($cfg['trade_usdt'] ?? 6.5, 6.5);
        $quoteFree = 0.0;
        try {
            $eq = $db->lastEquity();
            $quoteFree = $eq !== null ? self::f($eq['quote_free'] ?? 0) : 0.0;
        } catch (Throwable $e) {
            $quoteFree = 0.0;
        }

        $rows = [];
        foreach ($symbols as $sym) {
            $sym = strtoupper((string) $sym);
            $i   = isset($info[$sym]) && is_array($info[$sym]) ? $info[$sym] : null;
            $sig = isset($signals[$sym]) && is_array($signals[$sym]) ? $signals[$sym] : null;
            $m   = isset($metrics[$sym]) && is_array($metrics[$sym]) ? $metrics[$sym] : [];

            $price = 0.0;
            if (isset($m['price']) && is_numeric($m['price'])) {
                $price = (float) $m['price'];
            } elseif ($sig !== null && isset($sig['price'])) {
                $price = self::f($sig['price']);
            }

            if ($i === null) {
                $statusCell = ['t' => 'no info', 'pill' => 'muted'];
                $minNotional = '–';
                $stepSize = '–';
                $stepValue = '–';
                $required = '–';
                $reqLevel = '';
            } else {
                $tradable = (string) ($i['status'] ?? '') === 'TRADING' && !empty($i['spotAllowed']);
                $statusCell = ['t' => (string) ($i['status'] ?? '?') . (empty($i['spotAllowed']) ? ' (no spot)' : ''), 'pill' => $tradable ? 'ok' : 'danger'];
                $minNotional = Util::money(self::f($i['minNotional'] ?? 0), 2);
                $stepSize = Util::trimZeros((string) ($i['stepSize'] ?? '0'));
                $stepValue = $price > 0 ? Util::money(self::f($i['stepSize'] ?? 0) * $price, 4) : '–';
                if ($price > 0) {
                    try {
                        $req = Risk::requiredSize($i, $price, $feePct);
                        $size = Risk::entrySize($cfg, $i, $price, max($quoteFree, $tradeUsdt / Risk::SIZE_FRACTION), $feePct);
                        $required = Util::money($req, 2);
                        // green only when the configured trade size covers it AND the live
                        // free balance can actually fund the entry (DESIGN §9 sizing)
                        $reqLevel = $req <= $tradeUsdt && $size > 0 ? 'lvl-ok' : 'lvl-danger';
                        if ($reqLevel === 'lvl-danger') {
                            $required .= ' (unaffordable)';
                        } elseif (Risk::entrySize($cfg, $i, $price, $quoteFree, $feePct) <= 0.0) {
                            $required .= ' (needs ' . Util::money($req / Risk::SIZE_FRACTION, 2) . ' free)';
                            $reqLevel = 'lvl-warn';
                        }
                    } catch (Throwable $e) {
                        $required = '–';
                        $reqLevel = '';
                    }
                } else {
                    $required = '–';
                    $reqLevel = '';
                }
            }

            $atr = isset($m['atr_pct']) && is_numeric($m['atr_pct']) ? self::pct((float) $m['atr_pct'], 2) : '–';
            $spread = isset($m['spread_pct']) && is_numeric($m['spread_pct']) ? self::pct((float) $m['spread_pct'], 3) : '–';

            if ($sig === null) {
                $eligCell = ['t' => 'not evaluated', 'pill' => 'muted'];
                $score = '–';
                $lastEval = 'never';
            } else {
                $eligible = !empty($sig['eligible']);
                $tags = isset($sig['reasons_list']) && is_array($sig['reasons_list']) ? $sig['reasons_list'] : [];
                $tagText = implode(', ', array_map('strval', $tags));
                $eligCell = ['t' => ($eligible ? 'eligible' : 'gated') . ($tagText !== '' ? ': ' . $tagText : ''), 'c' => 'tags ' . ($eligible ? 'lvl-ok' : 'lvl-warn')];
                $score = (string) (int) ($sig['score'] ?? 0);
                $lastEval = self::ago((string) ($sig['created_at'] ?? ''), $now);
            }

            $rows[] = [
                ['t' => $sym, 'c' => 'mono'],
                $statusCell,
                ['t' => $minNotional, 'c' => 'num'],
                ['t' => $stepSize, 'c' => 'num'],
                ['t' => $stepValue, 'c' => 'num'],
                ['t' => $required, 'c' => 'num ' . $reqLevel],
                ['t' => $atr, 'c' => 'num'],
                ['t' => $spread, 'c' => 'num'],
                $eligCell,
                ['t' => $score, 'c' => 'num'],
                ['t' => $lastEval, 'c' => 'nowrap'],
            ];
        }
        return ['rows' => $rows, 'cols' => 11, 'empty' => 'No symbols configured'];
    }

    /**
     * Inline POST form for a per-row "Assign to…" control (DESIGN-PORTFOLIO.md §7):
     * `['t' => 'Assign', 'assign' => ['action' => 'assign_symbol', 'name' => 'engine',
     *   'fields' => ['symbol' => 'SOLUSDT'], 'options' => [['v' => 'grid', 't' => 'grid sleeve']]]]`.
     * A select plus a submit button, because the CSP forbids an inline onchange handler; the
     * CSRF token is included and assets/panel.js rebuilds the identical markup on a refresh.
     */
    public static function assignControl(array $spec, string $label = 'Assign'): string
    {
        $action  = isset($spec['action']) ? (string) $spec['action'] : '';
        $name    = isset($spec['name']) && (string) $spec['name'] !== '' ? (string) $spec['name'] : 'engine';
        $options = isset($spec['options']) && is_array($spec['options']) ? $spec['options'] : [];
        if ($action === '' || $options === []) {
            return '<span class="muted">' . self::e($spec['empty'] ?? '-') . '</span>';
        }
        $fields = isset($spec['fields']) && is_array($spec['fields']) ? $spec['fields'] : [];
        $place  = isset($spec['placeholder']) ? (string) $spec['placeholder'] : 'Assign to…';
        $h = '<form method="post" action="index.php" class="inline assign">' . self::csrfField()
           . '<input type="hidden" name="action" value="' . self::e($action) . '">';
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . self::e((string) $k) . '" value="' . self::e($v) . '">';
        }
        $h .= '<select name="' . self::e($name) . '" class="assign-select" required>';
        $h .= '<option value="" selected disabled>' . self::e($place) . '</option>';
        foreach ($options as $o) {
            if (is_array($o)) {
                $ov = isset($o['v']) ? (string) $o['v'] : '';
                $ot = isset($o['t']) ? (string) $o['t'] : $ov;
            } else {
                $ov = (string) $o;
                $ot = $ov;
            }
            if ($ov === '') {
                continue;
            }
            $h .= '<option value="' . self::e($ov) . '">' . self::e($ot) . '</option>';
        }
        $h .= '</select><button type="submit" class="btn btn-mini">' . self::e($label !== '' ? $label : 'Assign') . '</button></form>';
        return $h;
    }

    /**
     * Geometry for several series drawn on ONE chart with a SHARED scale, so the sleeve
     * equities can be compared by eye (DESIGN-PORTFOLIO.md §7). Pure geometry: the colours
     * are CSS classes in assets/panel.css and nothing here emits a style attribute.
     *
     * @param array $seriesMap [key => float[]] oldest → newest
     * @return array{w:int, h:int, count:int, min:float, max:float, series:array}
     */
    public static function multiSparkline(array $seriesMap, int $w = 600, int $h = 140, int $pad = 6): array
    {
        $clean = [];
        $all   = [];
        foreach ($seriesMap as $key => $values) {
            $vals = [];
            if (is_array($values)) {
                foreach ($values as $v) {
                    if (is_numeric($v) && is_finite((float) $v)) {
                        $vals[] = (float) $v;
                        $all[]  = (float) $v;
                    }
                }
            }
            $clean[(string) $key] = $vals;
        }
        $out = ['w' => $w, 'h' => $h, 'count' => count($all), 'min' => 0.0, 'max' => 0.0, 'series' => []];
        if ($all === []) {
            foreach ($clean as $key => $vals) {
                $out['series'][$key] = ['key' => $key, 'points' => '', 'count' => 0,
                    'first' => 0.0, 'last' => 0.0, 'min' => 0.0, 'max' => 0.0];
            }
            return $out;
        }
        $min = min($all);
        $max = max($all);
        $out['min'] = $min;
        $out['max'] = $max;
        $span = $max - $min;
        if ($span <= 0) {
            $span = max(abs($max) * 0.01, 0.01);
            $min  = $min - $span / 2;
        }
        $iw = max(1, $w - 2 * $pad);
        $ih = max(1, $h - 2 * $pad);
        foreach ($clean as $key => $vals) {
            $n   = count($vals);
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $x = $n === 1 ? $pad + $iw / 2 : $pad + $iw * $i / ($n - 1);
                $y = $pad + $ih - ($vals[$i] - $min) / $span * $ih;
                $pts[] = sprintf('%.1F,%.1F', $x, $y);
            }
            $out['series'][$key] = [
                'key'   => $key,
                'points' => implode(' ', $pts),
                'count' => $n,
                'first' => $n > 0 ? $vals[0] : 0.0,
                'last'  => $n > 0 ? $vals[$n - 1] : 0.0,
                'min'   => $n > 0 ? min($vals) : 0.0,
                'max'   => $n > 0 ? max($vals) : 0.0,
            ];
        }
        return $out;
    }

    /** Inline SVG for a multi-series sparkline (one polyline per series, CSS classes only). */
    public static function svgMultiSparkline(array $spark, string $label = 'Sleeve equity history'): string
    {
        $w = (int) ($spark['w'] ?? 600);
        $h = (int) ($spark['h'] ?? 140);
        $series = isset($spark['series']) && is_array($spark['series']) ? $spark['series'] : [];
        $svg = '<svg class="spark spark-multi" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="'
             . self::e($label) . '">';
        foreach ($series as $key => $s) {
            $k = self::slug((string) $key);
            $svg .= '<polyline class="spark-line spark-series spark-' . self::e($k) . '" points="'
                  . self::e((string) ($s['points'] ?? '')) . '" data-sparkline-series="' . self::e((string) $key) . '"></polyline>';
        }
        return $svg . '</svg>';
    }

    /** Lower-case [a-z0-9_-] slug for a CSS class or a data-field suffix built from config data. */
    public static function slug(string $v): string
    {
        $s = strtolower(trim($v));
        $s = preg_replace('/[^a-z0-9_-]+/', '-', $s);
        $s = is_string($s) ? trim($s, '-') : '';
        return $s === '' ? 'x' : substr($s, 0, 32);
    }

    /**
     * Portfolio card, per-sleeve equity sparkline and scanner table (DESIGN-PORTFOLIO.md §7).
     *
     * Always returns the whole shape, even with `portfolio_enabled = false`, so `?api=status`
     * keeps one stable contract and assets/panel.js never has to test for missing keys;
     * `show.portfolio` / `show.scanner` decide whether the dashboard renders the blocks.
     *
     * Reads the database only - no network, no clock beyond "now" - and every query is
     * wrapped, so an install whose tables predate portfolio mode simply shows zeros.
     *
     * @return array{text: array, levels: array, raw: array, tables: array, show: array,
     *   sparkline: array, portfolio: array, scanner: array}
     */
    private static function portfolioBlock(array $cfg, Db $db, callable $st, int $now, string $tz): array
    {
        $quote = strtoupper(trim((string) ($cfg['quote_asset'] ?? 'USDT')));
        if ($quote === '') {
            $quote = 'USDT';
        }
        $mode       = strtolower(trim((string) ($cfg['mode'] ?? 'paper')));
        $haveSleeve = class_exists('Sleeve');
        $on         = $haveSleeve && Sleeve::portfolioEnabled($cfg);

        // With portfolio_enabled = false NO sleeve code runs at all: not one query, not one
        // Sleeve:: call. The block is still returned in full (zeros) so ?api=status keeps one
        // stable shape and panel.js never has to test for missing keys.
        $sleeves = [];
        if ($haveSleeve && $on) {
            try {
                $sleeves = Sleeve::all($cfg);
            } catch (Throwable $e) {
                $sleeves = [];
            }
        }

        $halted     = $st('halted', '0') === '1';
        $entriesOn  = !empty($cfg['enabled']);
        $allowLive  = !empty($cfg['allow_live_engines']);
        $ddPct      = self::f($cfg['sleeve_max_drawdown_pct'] ?? self::SLEEVE_DRAWDOWN_DEFAULT, self::SLEEVE_DRAWDOWN_DEFAULT);
        if ($ddPct <= 0.0 || $ddPct > 100.0) {
            $ddPct = self::SLEEVE_DRAWDOWN_DEFAULT;
        }
        $reservePct = self::f($cfg['sleeve_reserve_pct'] ?? 5.0, 5.0);

        /* Market input. Base balances are only known to the panel in paper mode (the tick owns
           the exchange account), so when they are not, the inventory valuation comes from the
           last sleeve_equity sample the tick wrote - which is exactly what it was written for. */
        $balances = [];
        $prices   = [];
        try {
            $pb = $db->getStateJson('paper_balances', []);
            if ($mode === 'paper' && is_array($pb)) {
                $balances = $pb;
            }
        } catch (Throwable $e) {
            $balances = [];
        }
        try {
            $m = $db->getStateJson('symbol_metrics', []);
            if (is_array($m)) {
                $prices = $m;
            }
        } catch (Throwable $e) {
            $prices = [];
        }
        $balancesKnown = $balances !== [];

        $text   = [];
        $levels = [];
        $raw    = [];
        $rows   = [];
        $cards  = [];
        $seriesMap = [];
        $owned     = [];

        $totBudget = 0.0;
        $totEquity = 0.0;
        $totReal   = 0.0;
        $totUnreal = 0.0;
        $totSample = 0;

        foreach ($sleeves as $engineKey => $sleeve) {
            $engine  = (string) $engineKey;
            $enabled = !empty($sleeve['enabled']);
            $budget  = self::f($sleeve['budget_usdt'] ?? 0.0);
            $symbols = isset($sleeve['symbols']) && is_array($sleeve['symbols']) ? $sleeve['symbols'] : [];
            foreach ($symbols as $sym) {
                $owned[strtoupper((string) $sym)] = $engine;
            }

            $state = null;
            try {
                $state = Sleeve::state($cfg, $db, $engine, $balances, $prices);
            } catch (Throwable $e) {
                $state = null;
            }
            if (!is_array($state)) {
                $state = [];
            }

            $realised  = self::f($state['realised'] ?? 0.0);
            $invCost   = self::f($state['inventory_cost'] ?? 0.0);
            $invValue  = self::f($state['inventory_value'] ?? 0.0);
            $reserved  = self::f($state['reserved'] ?? 0.0);
            $unreal    = self::f($state['unrealised'] ?? 0.0);
            $trades    = (int) ($state['trades'] ?? 0);
            $cycles    = (int) ($state['cycles'] ?? 0);
            $wins      = (int) ($state['wins'] ?? 0);
            $losses    = (int) ($state['losses'] ?? 0);
            $fees      = self::f($state['fees'] ?? 0.0);
            $pnlToday  = self::f($state['pnl_today'] ?? 0.0);
            $sampleAt  = '';

            $sample = null;
            try {
                $hist = $db->sleeveEquitySeries($engine, 1, $mode);
                if ($hist !== []) {
                    $sample = $hist[count($hist) - 1];
                }
            } catch (Throwable $e) {
                $sample = null;
            }
            if ($sample !== null) {
                $sampleAt = (string) ($sample['ts'] ?? '');
                if (!$balancesKnown) {
                    $invValue = self::f($sample['inventory_value'] ?? 0.0);
                    $reserved = self::f($sample['reserved'] ?? 0.0);
                    $unreal   = self::f($sample['unrealised'] ?? 0.0);
                }
            }

            $equity  = $budget + $realised + $unreal;
            $pnl     = $realised + $unreal;
            $pnlPct  = $budget > 0.0 ? $pnl / $budget * 100.0 : 0.0;
            $usedPct = $budget > 0.0 ? ($invCost + $reserved) / $budget * 100.0 : 0.0;
            if (!is_finite($usedPct) || $usedPct < 0.0) {
                $usedPct = 0.0;
            }
            $decided = $wins + $losses;
            $round   = $trades + $cycles;

            /* status: disabled → blocked → drawdown-paused → paused → running */
            $ddFloor     = $budget > 0.0 ? $budget * (1.0 - $ddPct / 100.0) : 0.0;
            $liveBlocked = $engine !== 'signal' && $mode === 'live' && !$allowLive;
            $pausedState = trim((string) $st('sleeve_paused_' . $engine, ''));
            $pausedFlag  = $pausedState !== '' && $pausedState !== '0';
            if (!$enabled) {
                $stText  = 'disabled';
                $stLevel = 'muted';
            } elseif ($halted) {
                $stText  = 'blocked (halted: ' . $st('halt_reason', 'unknown') . ')';
                $stLevel = 'danger';
            } elseif ($liveBlocked) {
                $stText  = 'blocked (live, allow_live_engines off)';
                $stLevel = 'danger';
            } elseif ($budget > 0.0 && $equity <= $ddFloor) {
                $stText  = 'drawdown-paused (equity ≤ ' . Util::money($ddFloor, 4) . ')';
                $stLevel = 'danger';
            } elseif ($engine === 'grid' && trim((string) $st('grid_paused_reason', '')) !== '') {
                // sleeve-local range-exit pause: only a re-anchor resumes it
                $stText  = 'paused (' . trim((string) $st('grid_paused_reason', '')) . ') - re-anchor to resume';
                $stLevel = 'warn';
            } elseif ($pausedFlag) {
                $stText  = 'paused' . ($pausedState !== '1' ? ' (' . $pausedState . ')' : '');
                $stLevel = 'warn';
            } elseif (!$entriesOn) {
                $stText  = 'paused (entries disabled)';
                $stLevel = 'warn';
            } else {
                $stText  = 'running';
                $stLevel = 'ok';
            }

            $card = [
                'engine'          => $engine,
                'enabled'         => $enabled,
                'symbols'         => $symbols,
                'budget'          => $budget,
                'equity'          => $equity,
                'realised'        => $realised,
                'unrealised'      => $unreal,
                'pnl'             => $pnl,
                'pnl_pct'         => $pnlPct,
                'pnl_today'       => $pnlToday,
                'inventory_cost'  => $invCost,
                'inventory_value' => $invValue,
                'reserved'        => $reserved,
                'available'       => self::f($state['available'] ?? 0.0),
                'used_pct'        => $usedPct,
                'trades'          => $trades,
                'cycles'          => $cycles,
                'sample'          => $round,
                'wins'            => $wins,
                'losses'          => $losses,
                'win_rate'        => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
                'fees'            => $fees,
                'expectancy'      => $round > 0 ? $realised / $round : 0.0,
                'status'          => $stText,
                'status_level'    => $stLevel,
                'paused'          => $pausedFlag,
                'blocked'         => $liveBlocked || $halted,
                'sample_at'       => $sampleAt,
            ];
            $cards[$engine] = $card;

            $totBudget += $budget;
            $totEquity += $equity;
            $totReal   += $realised;
            $totUnreal += $unreal;
            $totSample += $round;

            $rowLevel = self::pnlLevel($pnl);
            $rows[] = [
                'class' => $pnl > 0 ? 'row-ok' : ($pnl < 0 ? 'row-danger' : ''),
                'cells' => [
                    ['t' => strtoupper($engine), 'c' => 'mono' . ($enabled ? '' : ' muted')],
                    ['t' => $symbols === [] ? '-' : implode(', ', $symbols), 'c' => 'mono'],
                    ['t' => Util::money($budget, 2), 'c' => 'num'],
                    ['t' => Util::money($equity, 4), 'c' => 'num lvl-' . $rowLevel],
                    ['t' => self::signed($realised, 4), 'c' => 'num lvl-' . self::pnlLevel($realised)],
                    ['t' => self::signed($unreal, 4), 'c' => 'num lvl-' . self::pnlLevel($unreal)],
                    ['t' => self::pct($pnlPct, 2, true), 'c' => 'num lvl-' . $rowLevel],
                    ['t' => $trades . ' / ' . $cycles, 'c' => 'num'],
                    ['t' => $decided > 0 ? Util::money($card['win_rate'], 1) . ' % (' . $wins . 'W/' . $losses . 'L)' : '-', 'c' => 'num'],
                    ['t' => Util::money($fees, 4), 'c' => 'num'],
                    ['t' => Util::money($usedPct, 1) . ' %', 'c' => 'num', 'bar' => $usedPct],
                    ['t' => $stText, 'pill' => $stLevel],
                ],
            ];

            $vals = [];
            try {
                foreach ($db->sleeveEquitySeries($engine, 288, $mode) as $r) {
                    $vals[] = self::f($r['equity'] ?? 0.0);
                }
            } catch (Throwable $e) {
                $vals = [];
            }
            $seriesMap[$engine] = $vals;
        }

        /* ---- "best method so far": the leader by REALISED pnl, with its sample size and the
           plain caveat. A handful of trades proves nothing, so a small sample is never dressed
           up as a winner: the line stays muted until there are enough round trips to mean
           anything at all. */
        $best = null;
        foreach ($cards as $c) {
            if ($c['sample'] <= 0) {
                continue;
            }
            if ($best === null || $c['realised'] > $best['realised']) {
                $best = $c;
            }
        }
        $others = [];
        foreach ($cards as $c) {
            if ($best !== null && $c['engine'] === $best['engine']) {
                continue;
            }
            if ($c['sample'] <= 0) {
                continue;
            }
            $others[] = $c['engine'] . ' ' . self::signed($c['realised'], 4) . ' over ' . (int) $c['sample']
                . ' round trip' . ((int) $c['sample'] === 1 ? '' : 's');
        }
        if ($best === null) {
            $text['pf_best']   = 'No sleeve has closed a round trip yet - there is nothing to compare.';
            $levels['pf_best'] = 'muted';
            $text['pf_best_caveat'] = 'A comparison needs closed trades. Until every sleeve has a few hundred of them, '
                . 'any ranking here is noise: the leader would be luck, not evidence of an edge.';
        } else {
            $n = (int) $best['sample'];
            $text['pf_best'] = 'Best method so far: ' . strtoupper($best['engine']) . ' with ' . self::signed($best['realised'], 4)
                . ' ' . $quote . ' realised over ' . $n . ' round trip' . ($n === 1 ? '' : 's')
                . ' (' . (int) $best['trades'] . ' signal trade' . ((int) $best['trades'] === 1 ? '' : 's')
                . ', ' . (int) $best['cycles'] . ' engine cycle' . ((int) $best['cycles'] === 1 ? '' : 's') . ')'
                . ($others !== [] ? ' - then ' . implode(', ', $others) . '.' : '.');
            $levels['pf_best'] = $n >= self::PORTFOLIO_MIN_SAMPLE ? self::pnlLevel($best['realised']) : 'muted';
            $text['pf_best_caveat'] = $n < self::PORTFOLIO_MIN_SAMPLE
                ? $n . ' round trip' . ($n === 1 ? '' : 's') . ' proves nothing. A handful of trades is noise: this ranking '
                  . 'can flip on the very next one, and a leader on such a sample is as likely to be luck as skill. '
                  . 'Do not read a winner into it - compare again after a few hundred round trips per sleeve.'
                : $n . ' round trips is still a small sample. A few hundred per sleeve is what it takes before the '
                  . 'difference between two methods means anything; treat this as a running tally, not a verdict.';
        }
        $raw['pf_best_engine'] = $best === null ? '' : $best['engine'];
        $raw['pf_best_sample'] = $best === null ? 0 : (int) $best['sample'];

        /* ---- unattributed inventory (§3): base the sleeves do not own is excluded from every
           sleeve's numbers, so it has to be named somewhere or it silently disappears. */
        $unattributed = [];
        $balanceList  = $on ? $balances : [];
        foreach ($balanceList as $asset => $bal) {
            $asset = strtoupper((string) $asset);
            if ($asset === '' || $asset === $quote) {
                continue;
            }
            $qty = is_array($bal) ? self::f($bal['free'] ?? 0.0) + self::f($bal['locked'] ?? 0.0) : self::f($bal);
            if ($qty <= 0.0) {
                continue;
            }
            $sym = $asset . $quote;
            if (isset($owned[$sym])) {
                continue;
            }
            $px  = isset($prices[$sym]['price']) ? self::f($prices[$sym]['price']) : 0.0;
            $unattributed[$sym] = Util::toDecimalString($qty, 8) . ' ' . $asset
                . ($px > 0.0 ? ' (' . Util::money($qty * $px, 4) . ' ' . $quote . ')' : '');
        }
        if ($on) {
            $held = [
                'lots'      => ['SELECT DISTINCT symbol FROM lots WHERE remaining > 0', ' (engine lots)'],
                'positions' => ["SELECT DISTINCT symbol FROM positions WHERE status IN ('OPEN','STUCK')", ' (open position)'],
            ];
            foreach ($held as $spec) {
                try {
                    $params = [];
                    $sql    = $spec[0];
                    if ($mode !== '') {
                        $sql .= ' AND mode = ?';
                        $params[] = $mode;
                    }
                    $q = $db->pdo()->prepare($sql);
                    $q->execute($params);
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sym) {
                        $sym = strtoupper((string) $sym);
                        if ($sym !== '' && !isset($owned[$sym]) && !isset($unattributed[$sym])) {
                            $unattributed[$sym] = $sym . $spec[1];
                        }
                    }
                } catch (Throwable $e) {
                    // the table predates the engines: nothing to attribute, never a fatal
                }
            }
        }
        $text['pf_unattributed'] = $unattributed === []
            ? ($on ? ($balancesKnown ? 'none' : 'none tracked locally') : '-')
            : implode(' · ', $unattributed);
        $levels['pf_unattributed'] = $unattributed === [] ? 'muted' : 'warn';

        /* ---- totals */
        $totPnl = $totReal + $totUnreal;
        $text['pf_state']       = $on ? 'PORTFOLIO' : 'single engine';
        $levels['pf_state']     = $on ? 'info' : 'muted';
        $text['pf_sleeves']     = (string) count($cards) . ' sleeve' . (count($cards) === 1 ? '' : 's');
        $text['pf_budget']      = Util::money($totBudget, 2) . ' ' . $quote;
        $text['pf_equity']      = Util::money($totEquity, 4) . ' ' . $quote;
        $levels['pf_equity']    = self::pnlLevel($totPnl);
        $text['pf_realised']    = self::signed($totReal, 4);
        $levels['pf_realised']  = self::pnlLevel($totReal);
        $text['pf_unrealised']  = self::signed($totUnreal, 4);
        $levels['pf_unrealised'] = self::pnlLevel($totUnreal);
        $text['pf_reserve']     = Util::money($reservePct, 2) . ' % held back';
        $text['pf_drawdown']    = Util::money($ddPct, 2) . ' % of budget';
        $text['pf_sample']      = (string) $totSample . ' round trips across all sleeves';
        $text['pf_valuation']   = $balancesKnown
            ? 'inventory valued from the local paper balances'
            : 'inventory valued from the last per-sleeve equity sample written by the tick';

        $raw['pf_enabled']    = $on;
        $raw['pf_budget']     = $totBudget;
        $raw['pf_equity']     = $totEquity;
        $raw['pf_realised']   = $totReal;
        $raw['pf_unrealised'] = $totUnreal;
        $raw['pf_sample']     = $totSample;

        $tables = [];
        $tables['portfolio'] = ['rows' => $rows, 'cols' => 12,
            'empty' => $on ? 'No sleeves configured - add them in Settings → Portfolio' : 'Portfolio mode is off'];

        /* ---- overlaid per-sleeve equity sparkline */
        $spark = self::multiSparkline($seriesMap);
        foreach ($cards as $engine => $c) {
            $s = isset($spark['series'][$engine]) ? $spark['series'][$engine] : ['count' => 0, 'last' => 0.0, 'first' => 0.0];
            $key = 'pf_leg_' . self::slug($engine);
            $text[$key] = ((int) $s['count'] > 0 ? Util::money(self::f($s['last']), 4) : '-')
                . ' · ' . (int) $s['count'] . ' pt';
            $levels[$key] = (int) $s['count'] > 1 ? self::pnlLevel(self::f($s['last']) - self::f($s['first'])) : 'muted';
        }
        $text['pf_spark_min']   = $spark['count'] > 0 ? Util::money(self::f($spark['min']), 4) : '-';
        $text['pf_spark_max']   = $spark['count'] > 0 ? Util::money(self::f($spark['max']), 4) : '-';
        $text['pf_spark_count'] = (int) $spark['count'] . ' points';

        /* ---- scanner (§5, §7) */
        $scannerOn = !isset($cfg['scanner_enabled']) || !empty($cfg['scanner_enabled']);
        $topN      = (int) self::f($cfg['scanner_top_n'] ?? 10, 10.0);
        if ($topN < 1) {
            $topN = 1;
        } elseif ($topN > 100) {
            $topN = 100;
        }
        $scanRows = [];
        try {
            $scanRows = $db->scannerRows($topN, false);
        } catch (Throwable $e) {
            $scanRows = [];
        }
        $age = null;
        try {
            $age = $db->scannerAge();
        } catch (Throwable $e) {
            $age = null;
        }
        $options = [];
        if ($on) {
            foreach ($sleeves as $engineKey => $sleeve) {
                $options[] = ['v' => (string) $engineKey, 't' => (string) $engineKey . ' sleeve'];
            }
        }

        $srows   = [];
        $rank    = 0;
        $payload = [];
        foreach ($scanRows as $r) {
            $rank++;
            $sym      = strtoupper((string) ($r['symbol'] ?? ''));
            $eligible = !empty($r['eligible']);
            $gates    = isset($r['gates_list']) && is_array($r['gates_list']) ? $r['gates_list'] : [];
            $gateText = implode(', ', array_map('strval', $gates));
            $atr      = ($r['atr_pct'] === null || $r['atr_pct'] === '') ? null : self::f($r['atr_pct']);
            $ownerNow = isset($owned[$sym]) ? $owned[$sym] : '';
            $assign   = ['t' => 'Assign', 'assign' => [
                'action'  => 'assign_symbol',
                'name'    => 'engine',
                'fields'  => ['symbol' => $sym],
                'options' => $options,
                'empty'   => $on ? '-' : 'portfolio off',
            ]];
            $srows[] = [
                'class' => $eligible ? '' : 'row-muted',
                'cells' => [
                    ['t' => (string) $rank, 'c' => 'num'],
                    ['t' => $sym . ($ownerNow !== '' ? ' (' . $ownerNow . ')' : ''), 'c' => 'mono'],
                    ['t' => Util::money(self::f($r['price'] ?? 0.0), 6), 'c' => 'num'],
                    ['t' => self::pct(self::f($r['change_pct'] ?? 0.0), 2, true), 'c' => 'num lvl-' . self::pnlLevel(self::f($r['change_pct'] ?? 0.0))],
                    ['t' => $atr === null ? '-' : self::pct($atr, 2), 'c' => 'num'],
                    ['t' => self::pct(self::f($r['spread_pct'] ?? 0.0), 3), 'c' => 'num'],
                    ['t' => self::volume(self::f($r['quote_vol'] ?? 0.0)), 'c' => 'num'],
                    ['t' => Util::money(self::f($r['step_value'] ?? 0.0), 6), 'c' => 'num'],
                    ['t' => Util::money(self::f($r['required_size'] ?? 0.0), 2), 'c' => 'num'],
                    ['t' => Util::money(self::f($r['score'] ?? 0.0), 4), 'c' => 'num'],
                    ['t' => ($eligible ? 'eligible' : 'gated') . ($gateText !== '' ? ': ' . $gateText : ''),
                     'c' => 'tags ' . ($eligible ? 'lvl-ok' : 'lvl-warn')],
                    $assign,
                ],
            ];
            $payload[] = [
                'rank' => $rank, 'symbol' => $sym, 'price' => self::f($r['price'] ?? 0.0),
                'change_pct' => self::f($r['change_pct'] ?? 0.0), 'atr_pct' => $atr,
                'spread_pct' => self::f($r['spread_pct'] ?? 0.0), 'quote_vol' => self::f($r['quote_vol'] ?? 0.0),
                'step_value' => self::f($r['step_value'] ?? 0.0), 'required_size' => self::f($r['required_size'] ?? 0.0),
                'score' => self::f($r['score'] ?? 0.0), 'eligible' => $eligible, 'gates' => array_values($gates),
                'owner' => $ownerNow,
            ];
        }
        $tables['scanner'] = ['rows' => $srows, 'cols' => 12,
            'empty' => $scannerOn ? 'No scan yet - the tick refreshes the ranking every scanner_refresh_min minutes' : 'The scanner is off'];

        $refreshMin = (int) self::f($cfg['scanner_refresh_min'] ?? 60, 60.0);
        $text['pf_scanner_state']   = $scannerOn ? 'ON' : 'OFF';
        $levels['pf_scanner_state'] = $scannerOn ? 'ok' : 'muted';
        $text['pf_scanner_age']     = $age === null ? 'never scanned' : self::duration($age) . ' ago';
        $levels['pf_scanner_age']   = ($age !== null && $refreshMin > 0 && $age <= $refreshMin * 120) ? 'ok' : 'muted';
        $text['pf_scanner_refresh'] = $refreshMin . ' min (weight 80 per refresh)';
        $text['pf_scanner_rows']    = count($srows) . ' of top ' . $topN;
        $raw['scanner_rows']        = count($srows);
        $raw['scanner_age_s']       = $age;

        return [
            'text'      => $text,
            'levels'    => $levels,
            'raw'       => $raw,
            'tables'    => $tables,
            'sparkline' => $spark,
            'show'      => [
                'portfolio'      => $on,
                'portfolio_off'  => !$on,
                'scanner'        => $scannerOn && ($on || $srows !== []),
                'sleeve_actions' => $on && $cards !== [],
            ],
            'portfolio' => [
                'enabled'      => $on,
                'mode'         => $mode,
                'reserve_pct'  => $reservePct,
                'max_drawdown_pct' => $ddPct,
                'budget'       => $totBudget,
                'equity'       => $totEquity,
                'realised'     => $totReal,
                'unrealised'   => $totUnreal,
                'sample'       => $totSample,
                'sleeves'      => array_values($cards),
                'best'         => $best === null ? null : [
                    'engine' => $best['engine'], 'realised' => $best['realised'], 'sample' => (int) $best['sample'],
                    'trades' => (int) $best['trades'], 'cycles' => (int) $best['cycles'],
                    'significant' => (int) $best['sample'] >= self::PORTFOLIO_MIN_SAMPLE,
                ],
                'caveat'        => $text['pf_best_caveat'],
                'unattributed'  => array_values($unattributed),
                'sparkline'     => $spark,
            ],
            'scanner'   => [
                'enabled'     => $scannerOn,
                'age_s'       => $age,
                'refresh_min' => $refreshMin,
                'top_n'       => $topN,
                'rows'        => $payload,
            ],
        ];
    }

    /** Compact 24 h volume: 12.34 M / 987.65 k / 12.34 - the raw number is useless in a column. */
    public static function volume(float $v): string
    {
        $a = abs($v);
        if ($a >= 1000000000.0) {
            return Util::money($v / 1000000000.0, 2) . ' B';
        }
        if ($a >= 1000000.0) {
            return Util::money($v / 1000000.0, 2) . ' M';
        }
        if ($a >= 1000.0) {
            return Util::money($v / 1000.0, 2) . ' k';
        }
        return Util::money($v, 2);
    }


    /* ==================================================================== */
    /*  Learning: Insights page, dashboard line (DESIGN-LEARNING.md §6)      */
    /* ==================================================================== */

    /**
     * The five §5 keys plus the §7 BNB threshold, with their documented defaults.
     * Used to render the settings form (and to sanitise a posted value) on an
     * install whose config.php predates learning - exactly like the engine and
     * portfolio defaults in index.php do for their sections.
     */
    public static function learnDefaults(): array
    {
        return [
            'learning_enabled'      => true,
            'learning_apply'        => false,
            'learn_min_samples'     => 60,
            'learn_recompute_hours' => 168,
            'learn_window_days'     => 90,
            'bnb_min_balance'       => self::BNB_MIN_BALANCE_DEFAULT,
        ];
    }

    /** A config flag that may arrive as a bool, a number or a string. */
    public static function learnFlag(array $cfg, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $cfg) || $cfg[$key] === null) {
            return $default;
        }
        $v = $cfg[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((float) $v) != 0.0;
        }
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return $default;
        }
        return $s === '1' || $s === 'true' || $s === 'yes' || $s === 'on';
    }

    /** A clamped integer config value. */
    public static function learnInt(array $cfg, string $key, int $default, int $lo, int $hi): int
    {
        $v = (isset($cfg[$key]) && is_numeric($cfg[$key])) ? (int) $cfg[$key] : $default;
        return (int) Util::clamp((float) $v, (float) $lo, (float) $hi);
    }

    /**
     * A win rate is NEVER rendered without the sample it rests on: this is the only
     * helper the panel formats one with, so the rule cannot be forgotten in one place.
     */
    public static function winRate(array $b): string
    {
        $n       = (int) (isset($b['n']) ? $b['n'] : 0);
        $decided = (int) (isset($b['decided']) ? $b['decided'] : 0);
        if ($decided <= 0) {
            return '– (no win/loss outcome yet, n=' . $n . ')';
        }
        $wins   = (int) (isset($b['wins']) ? $b['wins'] : 0);
        $losses = (int) (isset($b['losses']) ? $b['losses'] : 0);
        return Util::money(self::f($b['win_rate'] ?? 0), 1) . ' % of ' . $decided
             . ' (' . $wins . 'W/' . $losses . 'L)';
    }

    /** The 95 % Wilson interval of a bucket's win rate, with its sample size. */
    public static function ci(array $b): string
    {
        $decided = (int) (isset($b['decided']) ? $b['decided'] : 0);
        if ($decided <= 0) {
            return '–';
        }
        return Util::money(self::f($b['wilson_lo_pct'] ?? 0), 1) . ' – '
             . Util::money(self::f($b['wilson_hi_pct'] ?? 0), 1) . ' % (n=' . $decided . ')';
    }

    /** Rows Learn draws its evidence from: the configured mode plus rows captured without one. */
    private static function learnModeFilter(array $cfg)
    {
        $mode = trim((string) (isset($cfg['mode']) ? $cfg['mode'] : ''));
        return $mode === '' ? '' : [$mode, ''];
    }

    /**
     * Is there even arithmetically enough evidence for a confident claim?
     *
     * A claim needs `learn_min_samples` in EACH of two buckets, so fewer than
     * 2 x min decided outcomes can never be confident. The probe is bounded to
     * that many rows, which is what keeps the 20 s dashboard refresh cheap: the
     * expensive Learn::insights() pass only runs once the arithmetic allows a
     * conclusion at all.
     *
     * @return array{decided:int,needed:int,enough:bool,min:int}
     */
    private static function learnProbe(Db $db, array $cfg, int $now): array
    {
        $min  = self::learnInt($cfg, 'learn_min_samples', 60, 1, 100000);
        $days = self::learnInt($cfg, 'learn_window_days', 90, 1, 3650);
        $want = $min * 2;
        $rows = [];
        try {
            $rows = $db->observations([
                'decision' => 'entered',
                'outcome'  => ['win', 'loss'],
                'mode'     => self::learnModeFilter($cfg),
                'since'    => Util::nowIso($now - $days * 86400),
                'limit'    => $want,
            ]);
        } catch (Throwable $e) {
            $rows = [];
        }
        $decided = count($rows);
        return [
            'decided' => $decided,
            'needed'  => max(0, $want - $decided),
            'enough'  => $decided >= $want,
            'min'     => $min,
        ];
    }

    /**
     * The dashboard's one line (DESIGN-LEARNING.md §6): the strongest confident
     * insight WITH its sample size, or "not enough data yet" - and, when it is the
     * latter, roughly how many more resolved trades that would take.
     *
     * @return array{text:string,level:string,confident:bool,feature:string,samples:int,needed:int}
     */
    public static function learnLine(array $cfg, Db $db, ?int $now = null): array
    {
        $now = $now === null ? time() : $now;
        $out = ['text' => 'not enough data yet', 'level' => 'muted', 'confident' => false,
                'feature' => '', 'samples' => 0, 'needed' => 0];
        if (!class_exists('Learn')) {
            $out['text'] = 'Learning is not installed on this deployment (lib/Learn.php is missing).';
            return $out;
        }
        if (!self::learnFlag($cfg, 'learning_enabled', true)) {
            $out['text'] = 'Learning is off (learning_enabled = false): no observations are captured and nothing is concluded.';
            return $out;
        }
        $probe = self::learnProbe($db, $cfg, $now);
        $out['samples'] = $probe['decided'];
        $out['needed']  = $probe['needed'];
        if (!$probe['enough']) {
            $out['text'] = 'Not enough data yet: ' . $probe['decided'] . ' resolved trade'
                . ($probe['decided'] === 1 ? '' : 's') . ' with a win/loss outcome, and a claim needs '
                . $probe['min'] . ' in each of two buckets - roughly ' . $probe['needed'] . ' more.';
            return $out;
        }
        $insights = [];
        try {
            $insights = Learn::insights($db, $cfg, self::learnInt($cfg, 'learn_window_days', 90, 1, 3650));
        } catch (Throwable $e) {
            $insights = [];
        }
        foreach ($insights as $ins) {
            if (empty($ins['confident'])) {
                continue;
            }
            $best  = isset($ins['confident_best']) && is_array($ins['confident_best']) ? $ins['confident_best'] : null;
            $worst = isset($ins['confident_worst']) && is_array($ins['confident_worst']) ? $ins['confident_worst'] : null;
            if ($best === null || $worst === null) {
                continue;
            }
            $out['confident'] = true;
            $out['feature']   = (string) $ins['feature'];
            $out['level']     = 'ok';
            $out['text']      = (string) $ins['label'] . ' ' . (string) $best['label'] . ': win rate '
                . self::winRate($best) . ', 95 % CI ' . self::ci($best) . ', avg PnL ' . self::signed(self::f($best['avg_pnl'] ?? 0))
                . ' - against ' . (string) $worst['label'] . ': win rate ' . self::winRate($worst)
                . '. The two intervals do not overlap'
                . (isset($ins['family']) && (int) $ins['family'] > 1
                    ? ', even widened for the ' . (int) $ins['family'] . ' bucket comparisons this scan made'
                    : '') . '.';
            return $out;
        }
        $out['text'] = 'No condition separates winners from losers yet on ' . $probe['decided']
            . ' resolved trades: not enough data yet to conclude anything.';
        return $out;
    }

    /**
     * Everything `?page=insights` renders (DESIGN-LEARNING.md §6), as one array.
     *
     * Reads the database only - no network, no clock beyond "now" - and never
     * throws: every query is wrapped, so an install whose database predates the
     * observations table simply reports that nothing has been captured yet.
     *
     * @return array{header:array,features:array,weights:array,skipped:array,line:array,text:array,levels:array}
     */
    public static function insights(array $cfg, Db $db, ?int $now = null): array
    {
        $now   = $now === null ? time() : $now;
        $tz    = (string) (isset($cfg['timezone']) ? $cfg['timezone'] : 'UTC');
        $mode  = strtolower(trim((string) (isset($cfg['mode']) ? $cfg['mode'] : 'paper')));
        $min   = self::learnInt($cfg, 'learn_min_samples', 60, 1, 100000);
        $days  = self::learnInt($cfg, 'learn_window_days', 90, 1, 3650);
        $hours = self::learnInt($cfg, 'learn_recompute_hours', 168, 1, 100000);
        $on    = self::learnFlag($cfg, 'learning_enabled', true);
        $apply = self::learnFlag($cfg, 'learning_apply', false);
        $since = Util::nowIso($now - $days * 86400);
        $have  = class_exists('Learn');

        // ---- the window's raw rows, fetched once and reused by every card below
        $rows = [];
        try {
            $rows = $db->observations([
                'since' => $since,
                'mode'  => self::learnModeFilter($cfg),
                'limit' => 20000,
            ]);
        } catch (Throwable $e) {
            $rows = [];
        }
        $total = count($rows);
        $entered = 0;
        $skipped = 0;
        $resolved = 0;
        $wins = 0;
        $losses = 0;
        $flat = 0;
        $notTaken = 0;
        $open = 0;
        $engineMix = [];
        foreach ($rows as $row) {
            $eng = strtolower(trim((string) (isset($row['engine']) ? $row['engine'] : '')));
            if ($eng === '') {
                $eng = 'signal';   // rows captured before the engine column carried a value
            }
            if (!isset($engineMix[$eng])) {
                $engineMix[$eng] = 0;
            }
            $engineMix[$eng]++;
            $decision = strtolower(trim((string) (isset($row['decision']) ? $row['decision'] : '')));
            $outcome  = (isset($row['outcome']) && $row['outcome'] !== null)
                ? strtolower(trim((string) $row['outcome'])) : '';
            if ($decision === 'entered') {
                $entered++;
            } else {
                $skipped++;
            }
            if ($outcome !== '') {
                $resolved++;
            }
            if ($outcome === 'win') {
                $wins++;
            } elseif ($outcome === 'loss') {
                $losses++;
            } elseif ($outcome === 'flat') {
                $flat++;
            } elseif ($outcome === 'not_taken') {
                $notTaken++;
            } elseif ($outcome === '') {
                $open++;
            }
        }
        $decided = $wins + $losses;
        $needed  = max(0, $min * 2 - $decided);
        $allTime = 0;
        try {
            $counts  = $db->observationCounts($mode);
            $allTime = (int) (isset($counts['total']) ? $counts['total'] : 0);
        } catch (Throwable $e) {
            $allTime = 0;
        }

        // ---- how the evidence is spread across engines (DESIGN-LEARNING.md §3):
        // the condition cards pool EVERY engine into one win rate, while the weight
        // suggestions read signal-engine rows only. Say so rather than let a grid-heavy
        // window read as evidence about the signal scorer.
        ksort($engineMix);
        $learnEngines = ($have && defined('Learn::LEARN_ENGINES')) ? constant('Learn::LEARN_ENGINES') : ['signal', ''];
        $engineParts  = [];
        $engineFeed   = 0;
        foreach ($engineMix as $eng => $cnt) {
            $engineParts[] = $eng . ' ' . $cnt;
            if (in_array($eng, $learnEngines, true)) {   // '' was normalised to 'signal' above
                $engineFeed += $cnt;
            }
        }
        $engineList = $engineParts === [] ? 'none yet' : implode(', ', $engineParts);
        $enginesText = $engineList . '. The condition cards below pool every engine into one win rate; '
            . 'the weight suggestions use signal-engine rows only (' . $engineFeed . ' of ' . $total . ' here). '
            . ($engineMix === []
                ? 'Nothing has been captured yet, so neither view rests on anything.'
                : (count($engineMix) > 1
                    ? 'With more than one engine captured, a card can be carried by an engine the weights never touch.'
                    : 'Only one engine has been captured, so the two views currently rest on the same rows.'));

        // ---- the honest header sentence
        if (!$have) {
            $sentence = 'lib/Learn.php is not installed on this deployment, so nothing is computed here.';
            $level    = 'warn';
        } elseif (!$on) {
            $sentence = 'Learning is switched off (learning_enabled = false): no observation is captured and no claim is made. '
                      . 'Everything below is what the database already holds.';
            $level    = 'muted';
        } elseif ($total === 0) {
            $sentence = 'Nothing has been captured yet. Every entry evaluation writes one row - the ones it took and the ones it '
                      . 'refused - so this page fills in as the bot ticks.';
            $level    = 'muted';
        } elseif ($decided < $min * 2) {
            $sentence = 'Not enough data yet. ' . $decided . ' of the ' . ($min * 2) . ' resolved trades needed are in, so roughly '
                      . $needed . ' more are required before anything here can be called a result: a claim needs at least '
                      . $min . ' outcomes in EACH of two buckets (learn_min_samples), and their confidence intervals must not '
                      . 'overlap. At a few trades a day that is weeks away, and saying so is the honest answer.';
            $level    = 'warn';
        } else {
            $sentence = $decided . ' resolved trades are in - enough for a claim to be possible. A bucket is only called confident '
                      . 'when it holds at least ' . $min . ' outcomes and its confidence interval does not overlap the one it is '
                      . 'compared with; anything else below is explicitly marked inconclusive.';
            $level    = 'ok';
        }

        $header = [
            'total'            => $total,
            'all_time'         => $allTime,
            'entered'          => $entered,
            'skipped'          => $skipped,
            'resolved'         => $resolved,
            'open'             => $open,
            'wins'             => $wins,
            'losses'           => $losses,
            'flat'             => $flat,
            'not_taken'        => $notTaken,
            'decided'          => $decided,
            'min_samples'      => $min,
            'needed'           => $needed,
            'enough'           => $decided >= $min * 2,
            'window_days'      => $days,
            'mode'             => $mode,
            'learning_enabled' => $on,
            'learning_apply'   => $apply,
            'engines'          => $engineMix,
            'engines_text'     => $enginesText,
            'engines_feed'     => $engineFeed,
            'sentence'         => $sentence,
            'level'            => $level,
        ];

        // ---- one card per feature (DESIGN-LEARNING.md §6)
        $features = [];
        if ($have) {
            $list = [];
            try {
                $list = Learn::insights($db, $cfg, $days);
            } catch (Throwable $e) {
                $list = [];
            }
            foreach ($list as $ins) {
                $features[] = self::insightCard($ins, $min);
            }
        }

        // ---- current weights, last recompute, what changed and on what evidence
        $weights = self::weightsCard($cfg, $db, $now, $tz, $hours, $apply, $have);

        // ---- skipped vs entered
        $skippedCard = self::skippedCard($rows, $entered, $skipped, $days);

        $line = self::learnLine($cfg, $db, $now);

        $text = [
            'ins_total'     => (string) $total,
            'ins_all_time'  => (string) $allTime,
            'ins_entered'   => (string) $entered,
            'ins_skipped'   => (string) $skipped,
            'ins_resolved'  => $resolved . ' resolved (' . $wins . 'W/' . $losses . 'L/' . $flat . ' flat/' . $notTaken
                               . ' not taken, ' . $open . ' still open)',
            'ins_decided'   => $decided . ' win/loss outcomes',
            'ins_window'    => 'last ' . $days . ' days, mode ' . strtoupper($mode === '' ? 'all' : $mode),
            'ins_min'       => (string) $min,
            'ins_needed'    => $needed > 0 ? 'roughly ' . $needed . ' more resolved trades' : 'the sample is large enough',
            'ins_sentence'  => $sentence,
            'ins_engines'   => $enginesText,
            'learn_line'    => $line['text'],
        ];
        $levels = ['ins_sentence' => $level, 'learn_line' => $line['level']];

        return [
            'header'   => $header,
            'features' => $features,
            'weights'  => $weights,
            'skipped'  => $skippedCard,
            'line'     => $line,
            'text'     => $text,
            'levels'   => $levels,
        ];
    }

    /**
     * One feature card: the bucket table (range, n, win rate WITH its confidence
     * interval, average PnL), the separation figure and the plain-language note.
     */
    private static function insightCard(array $ins, int $min): array
    {
        $rows = [];
        foreach ((isset($ins['buckets']) && is_array($ins['buckets']) ? $ins['buckets'] : []) as $b) {
            if (!is_array($b)) {
                continue;
            }
            $n       = (int) (isset($b['n']) ? $b['n'] : 0);
            $avg     = self::f(isset($b['avg_pnl']) ? $b['avg_pnl'] : 0);
            $enough  = $n >= $min;
            $rows[] = [
                ['t' => (string) (isset($b['label']) ? $b['label'] : ''), 'c' => 'mono'],
                ['t' => (string) $n, 'c' => 'num' . ($enough ? '' : ' lvl-muted')],
                ['t' => (string) (int) (isset($b['open_now']) ? $b['open_now'] : 0), 'c' => 'num lvl-muted'],
                ['t' => self::winRate($b), 'c' => $n > 0 ? '' : 'lvl-muted'],
                ['t' => self::ci($b), 'c' => 'mono'],
                ['t' => $n > 0 ? self::signed($avg, 4) : '–', 'c' => 'num ' . ($n > 0 ? 'lvl-' . self::pnlLevel($avg) : 'lvl-muted')],
                ['t' => $n > 0 ? self::signed(self::f(isset($b['total_pnl']) ? $b['total_pnl'] : 0), 4) : '–', 'c' => 'num'],
                ['t' => $enough ? 'yes' : 'no (needs ' . $min . ')', 'c' => $enough ? '' : 'lvl-muted'],
            ];
        }
        $confident = !empty($ins['confident']);
        $sep       = self::f(isset($ins['separation']) ? $ins['separation'] : 0);
        $csep      = self::f(isset($ins['confident_separation']) ? $ins['confident_separation'] : 0);
        return [
            'feature'    => (string) (isset($ins['feature']) ? $ins['feature'] : ''),
            'label'      => (string) (isset($ins['label']) ? $ins['label'] : ''),
            'slug'       => self::slug((string) (isset($ins['feature']) ? $ins['feature'] : 'x')),
            'confident'  => $confident,
            'samples'    => (int) (isset($ins['samples']) ? $ins['samples'] : 0),
            'state'      => $confident ? 'CONFIDENT' : ((int) (isset($ins['samples']) ? $ins['samples'] : 0) === 0 ? 'NO DATA' : 'INCONCLUSIVE'),
            'level'      => $confident ? 'ok' : 'muted',
            'separation' => $sep,
            'separation_text' => 'separation ' . self::signed($sep, 4) . ' USDT (best bucket average PnL − worst)'
                . ($confident ? ', ' . self::signed($csep, 4) . ' between the two well-sampled buckets' : ''),
            'note'       => (string) (isset($ins['note']) ? $ins['note'] : ''),
            'table'      => ['rows' => $rows, 'cols' => 8, 'empty' => 'No observation carries this condition yet'],
        ];
    }

    /**
     * The current-weights card: `learn_weights`, when it was last recomputed, what
     * changed and on what evidence, whether `learning_apply` is on, and - because
     * that is the whole point of the dry run - what a recompute WOULD do next.
     */
    private static function weightsCard(array $cfg, Db $db, int $now, string $tz, int $hours, bool $apply, bool $have): array
    {
        $entry   = self::learnInt($cfg, 'entry_threshold', 60, 0, 100);
        $map     = [];
        $log     = [];
        $cands   = [];
        if ($have) {
            try {
                $map = Learn::weights($db, $cfg);
            } catch (Throwable $e) {
                $map = [];
            }
            try {
                $log = Learn::recomputeLog($db);
            } catch (Throwable $e) {
                $log = [];
            }
            try {
                $cands = Learn::adjustments($db, $cfg);
            } catch (Throwable $e) {
                $cands = [];
            }
        }
        $components = ($have && defined('Learn::COMPONENTS')) ? constant('Learn::COMPONENTS')
            : ['rsi', 'bb', 'reversal', 'rsi_up', 'vol', 'trend'];
        $base = ($have && defined('Learn::BASE_POINTS')) ? constant('Learn::BASE_POINTS')
            : ['rsi' => 20, 'bb' => 20, 'reversal' => 20, 'rsi_up' => 20, 'vol' => 20, 'trend' => 0];
        $maxDelta = ($have && defined('Learn::MAX_DELTA')) ? (int) constant('Learn::MAX_DELTA') : 10;

        $rows = [];
        foreach ($components as $component) {
            $c     = (string) $component;
            $b     = (int) (isset($base[$c]) && is_numeric($base[$c]) ? $base[$c] : 0);
            $delta = (int) (isset($map[$c]) && is_numeric($map[$c]) ? $map[$c] : 0);
            $cap   = min($maxDelta, abs($b));
            $rows[] = [
                ['t' => $c, 'c' => 'mono'],
                ['t' => (string) $b, 'c' => 'num'],
                ['t' => $delta === 0 ? '0' : self::signed((float) $delta, 0), 'c' => 'num ' . ($delta === 0 ? 'lvl-muted' : 'lvl-info')],
                ['t' => (string) ($b + $delta), 'c' => 'num'],
                ['t' => $cap > 0 ? '±' . $cap : 'not adjustable (no score points)', 'c' => $cap > 0 ? 'num' : 'lvl-muted'],
            ];
        }
        $threshold = (int) (isset($map['threshold']) && is_numeric($map['threshold']) ? $map['threshold'] : $entry);
        // The bar the bot actually applies (Bot::signalTick -> Risk::effectiveThreshold):
        // the adaptive controller may have raised it above entry_threshold, and the
        // learned `threshold` above is inert - no recompute may move it.
        try {
            $eff = (int) Risk::effectiveThreshold($cfg, $db);
        } catch (Throwable $e) {
            $eff = $entry;
        }

        $logRows = [];
        foreach ($log as $entryRow) {
            if (!is_array($entryRow)) {
                continue;
            }
            $changed = isset($entryRow['changed']) && $entryRow['changed'] !== null ? (string) $entryRow['changed'] : '';
            $logRows[] = [
                ['t' => self::fmtTime((string) (isset($entryRow['at']) ? $entryRow['at'] : ''), $tz), 'c' => 'mono nowrap'],
                ['t' => $changed !== '' ? $changed : 'nothing', 'c' => 'mono'],
                ['t' => $changed !== '' ? self::signed(self::f($entryRow['from'] ?? 0), 0) . ' → ' . self::signed(self::f($entryRow['to'] ?? 0), 0) : '–', 'c' => 'num'],
                ['t' => (string) (int) (isset($entryRow['samples']) ? $entryRow['samples'] : 0), 'c' => 'num'],
                ['t' => (string) (isset($entryRow['note']) ? $entryRow['note'] : ''), 'c' => 'logmsg'],
            ];
        }

        $candRows = [];
        foreach ($cands as $c) {
            if (!is_array($c)) {
                continue;
            }
            $fires  = isset($c['fires']) && is_array($c['fires']) ? $c['fires'] : [];
            $others = isset($c['others']) && is_array($c['others']) ? $c['others'] : [];
            $candRows[] = [
                ['t' => (string) (isset($c['component']) ? $c['component'] : ''), 'c' => 'mono'],
                ['t' => (string) (isset($c['when']) ? $c['when'] : ''), 'c' => ''],
                ['t' => self::signed(self::f($c['delta'] ?? 0), 0) . ' points', 'c' => 'num'],
                ['t' => self::winRate($fires), 'c' => ''],
                ['t' => self::winRate($others), 'c' => ''],
                ['t' => self::signed(self::f($c['separation'] ?? 0), 4), 'c' => 'num'],
            ];
        }

        $lastAt = '';
        try {
            $lastAt = (string) $db->getState('learn_at', '');
        } catch (Throwable $e) {
            $lastAt = '';
        }
        $lastTs = $lastAt !== '' ? Util::isoToTs($lastAt) : null;
        $nextTs = $lastTs === null ? null : $lastTs + $hours * 3600;
        $due    = $nextTs === null || $nextTs <= $now;

        $lastEntry = isset($log[0]) && is_array($log[0]) ? $log[0] : null;
        $changedText = 'Nothing has been applied yet.';
        if ($lastEntry !== null) {
            $ch = isset($lastEntry['changed']) && $lastEntry['changed'] !== null ? (string) $lastEntry['changed'] : '';
            $changedText = $ch !== ''
                ? 'Last recompute moved "' . $ch . '" from ' . self::signed(self::f($lastEntry['from'] ?? 0), 0)
                  . ' to ' . self::signed(self::f($lastEntry['to'] ?? 0), 0) . ' points on '
                  . (int) (isset($lastEntry['samples']) ? $lastEntry['samples'] : 0) . ' resolved trades.'
                : 'The last recompute changed nothing: the evidence did not clear the confidence test on '
                  . (int) (isset($lastEntry['samples']) ? $lastEntry['samples'] : 0) . ' resolved trades.';
        }

        return [
            'map'          => $map,
            'threshold'    => $threshold,
            'entry_threshold' => $entry,
            'effective_threshold' => $eff,
            'threshold_text'  => $eff . ' / 100 in force now (entry_threshold ' . $entry
                                 . ($eff > $entry ? ', raised by the adaptive controller' : '')
                                 . '; learned threshold ' . $threshold . ', which no recompute has ever moved'
                                 . ' - learning may never take it below ' . max(0, $entry - 10) . ')',
            'apply'        => $apply,
            'apply_text'   => $apply
                ? 'ON - a recompute writes the weight map and Strategy adds the deltas to its score.'
                : 'OFF - this is a dry run: the panel shows exactly what it would do and nothing is written or scored.',
            'apply_level'  => $apply ? 'warn' : 'muted',
            'last_at'      => $lastAt,
            'last_text'    => $lastAt === '' ? 'never' : self::fmtTime($lastAt, $tz) . ' (' . self::ago($lastAt, $now) . ')',
            'next_text'    => $nextTs === null
                ? 'now - no recompute has ever run'
                : self::fmtTime(Util::nowIso($nextTs), $tz) . ($due ? ' (due)' : ' (not before then)'),
            'due'          => $due,
            'hours'        => $hours,
            'changed_text' => $changedText,
            'table'        => ['rows' => $rows, 'cols' => 5, 'empty' => 'No score components'],
            'log'          => ['rows' => $logRows, 'cols' => 5, 'empty' => 'No recompute has run yet'],
            'candidates'   => ['rows' => $candRows, 'cols' => 6, 'empty' => 'No component clears the evidence test right now'],
            'candidate_count' => count($candRows),
        ];
    }

    /**
     * Skipped vs entered (DESIGN-LEARNING.md §6): the conditions the bot most often
     * refused, so the operator can see whether the gates are too tight. Skipped rows
     * carry no PnL and are never counted as wins or losses - this card reports
     * frequency and the conditions present, nothing else.
     */
    private static function skippedCard(array $rows, int $entered, int $skipped, int $days): array
    {
        $byReason = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decision = strtolower(trim((string) (isset($row['decision']) ? $row['decision'] : '')));
            if ($decision !== 'skipped') {
                continue;
            }
            $reason = trim((string) (isset($row['skip_reason']) && $row['skip_reason'] !== null ? $row['skip_reason'] : ''));
            if ($reason === '') {
                $reason = '(no reason recorded)';
            }
            if (!isset($byReason[$reason])) {
                $byReason[$reason] = ['n' => 0, 'rsi' => [], 'atr' => [], 'spread' => [], 'score' => [], 'last' => ''];
            }
            $byReason[$reason]['n']++;
            $features = isset($row['features_map']) && is_array($row['features_map'])
                ? $row['features_map']
                : (isset($row['features']) && is_array($row['features']) ? $row['features'] : []);
            foreach (['rsi' => 'rsi', 'atr' => 'atr_pct', 'spread' => 'spread_pct'] as $slot => $key) {
                if (isset($features[$key]) && is_numeric($features[$key]) && is_finite((float) $features[$key])) {
                    $byReason[$reason][$slot][] = (float) $features[$key];
                }
            }
            if (isset($row['score']) && is_numeric($row['score'])) {
                $byReason[$reason]['score'][] = (float) $row['score'];
            }
            $ts = (string) (isset($row['ts']) ? $row['ts'] : '');
            if ($ts > $byReason[$reason]['last']) {
                $byReason[$reason]['last'] = $ts;
            }
        }
        uasort($byReason, static function (array $a, array $b): int {
            if ($a['n'] === $b['n']) {
                return 0;
            }
            return $a['n'] < $b['n'] ? 1 : -1;
        });
        $max = 0;
        foreach ($byReason as $r) {
            $max = max($max, (int) $r['n']);
        }
        $avg = static function (array $v): string {
            if ($v === []) {
                return '–';
            }
            return Util::money(array_sum($v) / count($v), 2);
        };
        $out = [];
        foreach ($byReason as $reason => $r) {
            $n = (int) $r['n'];
            $out[] = [
                ['t' => (string) $reason, 'c' => 'mono'],
                ['t' => (string) $n, 'c' => 'num'],
                ['t' => $skipped > 0 ? Util::money($n / $skipped * 100.0, 1) . ' %' : '–',
                 'bar' => $max > 0 ? (int) round($n / $max * 100) : 0],
                ['t' => $avg($r['rsi']), 'c' => 'num'],
                ['t' => $avg($r['atr']), 'c' => 'num'],
                ['t' => $avg($r['spread']), 'c' => 'num'],
                ['t' => $avg($r['score']), 'c' => 'num'],
            ];
        }
        $evaluations = $entered + $skipped;
        $summary = $evaluations === 0
            ? 'No entry evaluation has been captured in the last ' . $days . ' days.'
            : 'The bot entered ' . $entered . ' of ' . $evaluations . ' evaluations in the last ' . $days . ' days ('
              . Util::money($evaluations > 0 ? $entered / $evaluations * 100.0 : 0.0, 1) . ' %) and refused ' . $skipped
              . '. Skipped rows are the control group: they carry no PnL and are never counted as wins or losses. '
              . 'A reason that dominates this table is the gate to question - not proof that loosening it would pay.';
        return [
            'entered'     => $entered,
            'skipped'     => $skipped,
            'evaluations' => $evaluations,
            'summary'     => $summary,
            'table'       => ['rows' => $out, 'cols' => 7, 'empty' => 'No refused evaluation in the window'],
        ];
    }

    /** State key of the memoised dashboard learning line (display cache only). */
    const LEARN_LINE_STATE = 'learn_line';

    /**
     * learnLine() behind a signature cache. The signature is the observation table's
     * own counts plus the settings the line depends on, so it can never serve a line
     * that the current data would not produce.
     */
    private static function learnLineCached(array $cfg, Db $db, int $now): array
    {
        $sig = '';
        try {
            $c   = $db->observationCounts(null);
            $sig = implode('/', [
                (int) $c['total'], (int) $c['resolved'], (int) $c['wins'], (int) $c['losses'],
                self::learnInt($cfg, 'learn_min_samples', 60, 1, 100000),
                self::learnInt($cfg, 'learn_window_days', 90, 1, 3650),
                trim((string) (isset($cfg['mode']) ? $cfg['mode'] : '')),
                self::learnFlag($cfg, 'learning_enabled', true) ? '1' : '0',
            ]);
        } catch (Throwable $e) {
            $sig = '';
        }
        if ($sig !== '') {
            try {
                $hit = $db->getStateJson(self::LEARN_LINE_STATE, null);
            } catch (Throwable $e) {
                $hit = null;
            }
            if (is_array($hit) && isset($hit['sig']) && (string) $hit['sig'] === $sig && isset($hit['text'])) {
                return [
                    'text'      => (string) $hit['text'],
                    'level'     => (string) (isset($hit['level']) ? $hit['level'] : 'muted'),
                    'confident' => !empty($hit['confident']),
                    'feature'   => (string) (isset($hit['feature']) ? $hit['feature'] : ''),
                    'samples'   => (int) (isset($hit['samples']) ? $hit['samples'] : 0),
                    'needed'    => (int) (isset($hit['needed']) ? $hit['needed'] : 0),
                ];
            }
        }
        $line = self::learnLine($cfg, $db, $now);
        if ($sig !== '') {
            try {
                $db->setState(self::LEARN_LINE_STATE, array_merge($line, ['sig' => $sig, 'at' => Util::nowIso($now)]));
            } catch (Throwable $e) {
                // the cache is best effort: a read-only database still renders the line
            }
        }
        return $line;
    }

    /**
     * The learning line for the dashboard status payload.
     *
     * The dashboard refreshes every 20 s, so the line is memoised in the state key
     * `learn_line` against a signature of the observation table (total / resolved /
     * wins / losses) and the settings the line depends on. Any new or newly resolved
     * observation changes the signature and the line is recomputed immediately; an
     * unchanged table costs one COUNT instead of a full bucketing pass over up to
     * 50 000 rows. The cache is display state only - nothing reads it back into a
     * trading decision.
     *
     * @return array{text:array,levels:array,show:array,raw:array,payload:array}
     */
    private static function learnBlock(array $cfg, Db $db, int $now): array
    {
        $line = self::learnLineCached($cfg, $db, $now);
        $on   = self::learnFlag($cfg, 'learning_enabled', true);
        return [
            'text'   => ['learn_line' => $line['text']],
            'levels' => ['learn_line' => $line['level']],
            'show'   => ['learning' => $on && class_exists('Learn')],
            'raw'    => ['learn_samples' => (int) $line['samples'], 'learn_confident' => (bool) $line['confident']],
            'payload' => [
                'enabled'   => $on,
                'apply'     => self::learnFlag($cfg, 'learning_apply', false),
                'confident' => (bool) $line['confident'],
                'feature'   => (string) $line['feature'],
                'samples'   => (int) $line['samples'],
                'needed'    => (int) $line['needed'],
                'line'      => (string) $line['text'],
            ],
        ];
    }

    /* ==================================================================== */
    /*  BNB fee discount (DESIGN-LEARNING.md §7)                            */
    /* ==================================================================== */

    /** `bnb_min_balance` in USDT equivalent, with the §7 default. */
    public static function bnbMinBalance(array $cfg): float
    {
        $v = (isset($cfg['bnb_min_balance']) && is_numeric($cfg['bnb_min_balance']))
            ? (float) $cfg['bnb_min_balance'] : self::BNB_MIN_BALANCE_DEFAULT;
        return (is_finite($v) && $v >= 0.0) ? $v : self::BNB_MIN_BALANCE_DEFAULT;
    }

    /**
     * The panel's read-only display cache for the BNB fee discount.
     *
     * `/sapi/v1/bnbBurn` is a signed account call, so it is NEVER made while
     * rendering a page (the dashboard refreshes every 20 s). The panel action
     * writes this cache; every render only reads it.
     *
     * @return array{checked:bool,available:bool,spot:bool,interest:bool,free:float,
     *   price:float,value:float,at:string,mode:string,note:string}
     */
    public static function bnbCache(Db $db): array
    {
        $raw = null;
        try {
            $raw = $db->getStateJson(self::BNB_STATE, null);
        } catch (Throwable $e) {
            $raw = null;
        }
        $in = is_array($raw) ? $raw : [];
        return [
            'checked'   => $in !== [],
            'available' => !empty($in['available']),
            'spot'      => !empty($in['spot']),
            'interest'  => !empty($in['interest']),
            'free'      => self::f(isset($in['free']) ? $in['free'] : 0),
            'price'     => self::f(isset($in['price']) ? $in['price'] : 0),
            'value'     => self::f(isset($in['value']) ? $in['value'] : 0),
            'at'        => (string) (isset($in['at']) ? $in['at'] : ''),
            'mode'      => (string) (isset($in['mode']) ? $in['mode'] : ''),
            'note'      => (string) (isset($in['note']) ? $in['note'] : ''),
        ];
    }

    /** Store one BNB reading. The only writer is the panel's toggle / check action. */
    public static function bnbWrite(Db $db, array $row): void
    {
        try {
            $db->setState(self::BNB_STATE, [
                'available' => !empty($row['available']),
                'spot'      => !empty($row['spot']),
                'interest'  => !empty($row['interest']),
                'free'      => self::f(isset($row['free']) ? $row['free'] : 0),
                'price'     => self::f(isset($row['price']) ? $row['price'] : 0),
                'value'     => self::f(isset($row['value']) ? $row['value'] : 0),
                'at'        => (string) (isset($row['at']) && (string) $row['at'] !== '' ? $row['at'] : Util::nowIso()),
                'mode'      => (string) (isset($row['mode']) ? $row['mode'] : ''),
                'note'      => (string) (isset($row['note']) ? $row['note'] : ''),
            ]);
        } catch (Throwable $e) {
            if (class_exists('Log')) {
                Log::warn('panel: could not store the BNB status - ' . $e->getMessage());
            }
        }
    }

    /** Round-trip taker cost in percent, with and without the discount (§7). */
    public static function bnbRoundTrip(bool $on): float
    {
        return $on ? self::BNB_ROUND_TRIP_ON : self::BNB_ROUND_TRIP_OFF;
    }

    /**
     * The API health card's BNB rows (DESIGN-LEARNING.md §7): on / off / unavailable,
     * the account's BNB free balance and the effective round-trip cost, plus the
     * low-balance warning.
     *
     * A null answer from the endpoint is INFORMATION, never an error: hosts that do
     * not serve `/sapi` (demo, testnet) simply have the toggle in the Binance UI.
     *
     * @return array{text:array,levels:array,show:array,raw:array,payload:array}
     */
    private static function bnbBlock(array $cfg, Db $db, int $now, string $tz): array
    {
        $mode  = strtolower(trim((string) (isset($cfg['mode']) ? $cfg['mode'] : 'paper')));
        $keyed = trim((string) (isset($cfg['api_key']) ? $cfg['api_key'] : '')) !== ''
              && trim((string) (isset($cfg['api_secret']) ? $cfg['api_secret'] : '')) !== '';
        $c     = self::bnbCache($db);
        $min   = self::bnbMinBalance($cfg);
        $paper = $mode === 'paper';

        $on        = $c['checked'] && $c['available'] && $c['spot'];
        // `bnb_min_balance` is a USDT threshold (§7), so the test only means anything
        // when the balance could actually be valued in USDT. With no BNBUSDT price the
        // raw BNB quantity is NOT compared against it - that is a unit mismatch.
        $priced    = $c['price'] > 0.0;
        $low       = $on && $priced && $c['value'] < $min;
        $roundTrip = self::bnbRoundTrip($on);

        if ($paper) {
            $state = 'not applicable (paper)';
            $level = 'muted';
        } elseif (!$c['checked']) {
            $state = 'not checked yet';
            $level = 'muted';
        } elseif (!$c['available']) {
            $state = 'unavailable on this host';
            $level = 'info';
        } elseif ($c['spot']) {
            $state = $low ? 'ON (BNB balance low)' : 'ON';
            $level = $low ? 'warn' : 'ok';
        } else {
            $state = 'OFF';
            $level = 'muted';
        }

        $balance = '–';
        if ($c['checked'] && $c['available']) {
            $balance = Util::toDecimalString($c['free'], 8) . ' BNB';
            if ($c['price'] > 0.0) {
                $balance .= ' (≈ ' . Util::money($c['value'], 4) . ' USDT at ' . Util::money($c['price'], 2) . ')';
            } else {
                $balance .= ' (USDT value unknown: the BNBUSDT price could not be read)';
            }
        } elseif ($paper) {
            $balance = 'paper mode fills use fee_pct, not the account fee';
        }

        $note = '';
        if ($paper) {
            $note = 'Paper mode simulates fills with the configured fee_pct, so the BNB discount changes nothing here. '
                  . 'It is real in demo, testnet and live.';
        } elseif (!$keyed) {
            $note = 'No API key is stored, so the discount cannot be read from the account.';
        } elseif (!$c['checked']) {
            $note = 'Press "Check BNB status" to read it from the account (one signed call, weight 1).';
        } elseif (!$c['available']) {
            $note = 'This host does not serve /sapi, so the bot cannot read or change the discount: the toggle lives in the '
                  . 'Binance UI (Profile → Fee settings → "Using BNB to pay for fees"). That is information, not an error.'
                  . ($c['note'] !== '' ? ' ' . $c['note'] : '');
        }

        $warning = '';
        if ($on && !$priced) {
            $warning = 'BNB burn is ON but the BNBUSDT price could not be read, so the free balance of '
                     . Util::toDecimalString($c['free'], 8) . ' BNB could not be valued in USDT and was NOT checked '
                     . 'against bnb_min_balance = ' . Util::money($min, 4) . '. Check the balance by hand, or press '
                     . '"Check BNB status" again.';
        }
        if ($low) {
            $warning = 'BNB burn is ON but the free BNB balance is worth ' . Util::money($c['value'], 4)
                     . ' USDT, below bnb_min_balance = ' . Util::money($min, 4) . '. Binance silently reverts to charging the '
                     . 'fee in the received asset, which changes both the fee and the dust behaviour mid-run. Top the BNB up '
                     . 'or turn the discount off so the arithmetic stays what the bot measures.';
        }

        return [
            'text' => [
                'bnb_burn'       => $state,
                'bnb_balance'    => $balance,
                'bnb_round_trip' => Util::money($roundTrip, 3) . ' % round trip'
                    . ($on ? ' (0.075 % per side with the discount)' : ' (0.1 % per side)')
                    . ($paper || !$c['checked'] || !$c['available'] ? ' - assumed, not read from this account' : ''),
                'bnb_checked'    => $c['at'] !== '' ? self::fmtTime($c['at'], $tz) . ' (' . self::ago($c['at'], $now) . ')' : 'never',
                'bnb_note'       => $note,
                'bnb_warning'    => $warning,
            ],
            'levels' => [
                'bnb_burn'    => $level,
                'bnb_warning' => 'warn',
            ],
            'show' => [
                // the API health rows themselves are always rendered - a mode where the
                // discount does not apply is information, not a reason to hide it; `bnb`
                // says whether it applies at all in this mode
                'bnb'         => !$paper,
                'bnb_toggle'  => !$paper && $keyed,
                'bnb_warning' => $warning !== '',
                'bnb_note'    => $note !== '',
            ],
            'raw' => [
                'bnb_on'    => $on,
                'bnb_free'  => $c['free'],
                'bnb_value' => $c['value'],
                'bnb_round_trip_pct' => $roundTrip,
            ],
            'payload' => [
                'checked'    => $c['checked'],
                'available'  => $c['available'],
                'spot'       => $c['spot'],
                'free'       => $c['free'],
                'value'      => $c['value'],
                'price'      => $c['price'],
                'at'         => $c['at'],
                'min_balance' => $min,
                'low'        => $low,
                'round_trip_pct' => $roundTrip,
                'next'       => $on ? 0 : 1,
                'note'       => $note,
                'warning'    => $warning,
            ],
        ];
    }

}
