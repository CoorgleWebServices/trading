<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Risk.php';

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
            $html .= '<tr>';
            foreach ($row as $cell) {
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
}
