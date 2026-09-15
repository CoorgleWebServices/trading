<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';
require_once __DIR__ . '/Db.php';

/**
 * Password, session, CSRF, login lockout and response headers for the DJ desk
 * (DESIGN-DJ.md §8). Implemented independently of the trading panel — same
 * standard, no shared code, no shared cookie, no shared database.
 *
 * Order of operations for a request: forceHttps -> securityHeaders ->
 * startSession (cookie params set BEFORE session_start) -> idleTimeout.
 * Auth::boot() does all four.
 */
final class Auth
{
    /** Own cookie name: the trading panel next door uses its own, so the two never collide. */
    const SESSION_NAME       = 'dj_sid';
    const IDLE_SECONDS       = 1800;   // 30 min
    const LOGIN_MAX_FAILS    = 5;
    const LOGIN_LOCK_MINUTES = 15;
    const MIN_PASSWORD       = 8;

    /** Exactly the policy of DESIGN-DJ.md §8: no inline script, no inline style, fonts from Google only. */
    const CSP = "default-src 'self'; style-src 'self' https://fonts.googleapis.com; "
              . "font-src https://fonts.gstatic.com; img-src 'self' data:; object-src 'none'; "
              . "base-uri 'none'; frame-ancestors 'none'; form-action 'self'";

    /** @var bool guards against sending the header set twice */
    private static $headersSent = false;

    /* ============================================================ bootstrap */

    /**
     * force HTTPS -> security headers -> session cookie params -> session_start -> idle timeout.
     * The CSRF check stays separate (Auth::checkCsrf()) so index.php decides how to answer a failure.
     */
    public static function boot(array $cfg): void
    {
        self::forceHttps($cfg);
        self::securityHeaders();
        self::startSession();
        self::idleTimeout(self::IDLE_SECONDS);
    }

    /** True until a password has been set: the first visit shows the setup screen (§8). */
    public static function needsSetup(array $cfg): bool
    {
        return trim((string) (isset($cfg['password_hash']) ? $cfg['password_hash'] : '')) === '';
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
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
            if (strtolower(trim($parts[0])) === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * Redirects http -> https (301) when force_https is on. Never on the CLI, and
     * never before setup is complete: the default is true, and a host without TLS
     * would otherwise be redirected away from its own setup screen.
     */
    public static function forceHttps(array $cfg): void
    {
        if (PHP_SAPI === 'cli' || empty($cfg['force_https']) || self::isHttps()) {
            return;
        }
        if (self::needsSetup($cfg)) {
            return;
        }
        $host = (string) (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
        if ($host === '' || preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $host) !== 1) {
            return; // cannot build a safe redirect target
        }
        $uri = (string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
        if ($uri === '' || $uri[0] !== '/' || strpos($uri, "\n") !== false || strpos($uri, "\r") !== false) {
            $uri = '/';
        }
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }

    /**
     * The exact header set of DESIGN-DJ.md §8, as name => value. Exposed so the
     * offline suite can assert the set without a web server.
     *
     * @return array<string,string>
     */
    public static function securityHeaderList(): array
    {
        return [
            'X-Frame-Options'         => 'DENY',
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'no-referrer',
            'Cache-Control'           => 'no-store',
            'Content-Security-Policy' => self::CSP,
        ];
    }

    /** Emit exactly the §8 header set. Idempotent; a no-op once output has started. */
    public static function securityHeaders(): void
    {
        if (self::$headersSent || headers_sent()) {
            return;
        }
        self::$headersSent = true;
        foreach (self::securityHeaderList() as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /** Test hook: allow the header set to be emitted again. */
    public static function resetHeaders(): void
    {
        self::$headersSent = false;
    }

    /* ============================================================== session */

    /** Cookie path of the desk's own directory, e.g. "/dj/" — never the panel's. */
    public static function cookiePath(): string
    {
        $script = (string) (isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php');
        $dir = str_replace('\\', '/', dirname($script));
        if ($dir === '' || $dir === '.' || $dir === '/') {
            return '/';
        }
        return rtrim($dir, '/') . '/';
    }

    /**
     * Start the session with hardened settings. Every ini_set and the cookie
     * parameters are applied BEFORE session_start(), which is the only point at
     * which they take effect: httponly, samesite=Strict, secure on HTTPS,
     * use_strict_mode so a session id we never issued is not adopted.
     */
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
        // PHP's default "nocache" cache limiter rewrites Cache-Control and adds
        // Pragma/Expires of its own, which would overwrite the one header set of
        // securityHeaderList(). Turning it off leaves exactly the five §8 headers.
        session_cache_limiter('');
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
     * End an authenticated session idle for longer than $seconds (§8: 30 minutes).
     * Returns true when this call expired the session.
     */
    public static function idleTimeout(int $seconds = self::IDLE_SECONDS): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $now     = time();
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

    /** The session's CSRF token, minted on first use. '' when there is no session. */
    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = Util::randomHex(32);
        }
        return (string) $_SESSION['csrf'];
    }

    /**
     * Check the CSRF token on every POST (§8). Called without an argument it
     * reads the "csrf" field of the current request and passes any non-POST
     * through; called with one it checks that value.
     *
     * The is_string() guard matters: an attacker who sends csrf[]=x makes
     * $_POST['csrf'] an array, and hash_equals() would raise a TypeError on it
     * rather than returning false.
     *
     * @param mixed $given
     */
    public static function checkCsrf($given = null): bool
    {
        if (func_num_args() === 0) {
            $method = isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
            if (strtoupper($method) !== 'POST') {
                return true;
            }
            $given = isset($_POST['csrf']) ? $_POST['csrf'] : null;
            if (!is_string($given) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                $given = $_SERVER['HTTP_X_CSRF_TOKEN'];
            }
        }
        if (!is_string($given) || $given === '') {
            return false;
        }
        $token = self::csrfToken();
        return $token !== '' && hash_equals($token, $given);
    }

    /** The hidden input every form in the desk carries. */
    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . Util::esc(self::csrfToken()) . '">';
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
                $out[] = [
                    'type' => (string) (isset($f['type']) ? $f['type'] : 'info'),
                    'msg'  => (string) $f['msg'],
                ];
            }
        }
        unset($_SESSION['flash']);
        return $out;
    }

    /* ================================================================= auth */

    /** REMOTE_ADDR only — proxy headers are client-controlled and must not bypass the lockout. */
    public static function clientIp(): string
    {
        $ip = (string) (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        if ($ip === '') {
            $ip = 'unknown';
        }
        return substr($ip, 0, 64);
    }

    public static function isLoggedIn(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['auth']);
    }

    /** True while this IP is inside its 15-minute lockout window. */
    public static function loginLocked(Db $db, string $ip): bool
    {
        try {
            $row = $db->loginAttempt($ip);
            return !empty($row['locked']);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Seconds left on this IP's lockout, 0 when it is not locked. */
    public static function lockoutSeconds(Db $db, string $ip): int
    {
        try {
            $row = $db->loginAttempt($ip);
            if (empty($row['locked']) || empty($row['locked_until'])) {
                return 0;
            }
            $ts = Util::isoToTs((string) $row['locked_until']);
            return $ts === null ? 0 : max(0, $ts - time());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Verify the password and, on success, mark the session authenticated.
     * Counts failures per IP: 5 failures lock the IP out for 15 minutes (§8).
     */
    public static function attemptLogin(array $cfg, Db $db, string $password): bool
    {
        $ip   = self::clientIp();
        $hash = (string) (isset($cfg['password_hash']) ? $cfg['password_hash'] : '');
        if (self::loginLocked($db, $ip)) {
            return false;
        }
        $ok = $hash !== '' && $password !== '' && password_verify($password, $hash);
        if (!$ok) {
            // burn a comparable amount of time when no password is set, so the
            // timing of the answer does not reveal which case this was
            if ($hash === '') {
                password_verify($password, '$2y$10$usesomesillystringforsalt$');
            }
            try {
                $db->loginFailed($ip, self::LOGIN_MAX_FAILS, self::LOGIN_LOCK_MINUTES);
            } catch (Throwable $e) {
                // the lockout table is best effort; a failed write must not let the login through
            }
            return false;
        }
        try {
            $db->loginOk($ip);
        } catch (Throwable $e) {
            // clearing the counter is best effort too
        }
        self::login();
        return true;
    }

    /** Mark the current session authenticated: fresh session id, fresh CSRF token. */
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

    /** Clear the session; a fresh id and token are minted so a flash can still be shown. */
    public static function logout(bool $regenerate = true): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if ($regenerate) {
            session_regenerate_id(true);
        }
        $_SESSION['csrf']      = Util::randomHex(32);
        $_SESSION['last_seen'] = time();
    }

    /* ============================================================== setup */

    /** password_hash() with the platform default algorithm. */
    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Cannot hash the password on this PHP build');
        }
        return $hash;
    }

    /**
     * Why a chosen password cannot be used, or '' when it is fine.
     * Pure, so the setup and change-password screens share one rule and the
     * offline suite can check it.
     */
    public static function passwordProblem(string $password, string $confirm): string
    {
        if (trim($password) === '') {
            return 'Choose a password.';
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            return 'Use at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if ($password !== $confirm) {
            return 'The two passwords do not match.';
        }
        return '';
    }
}
