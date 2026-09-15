<?php
declare(strict_types=1);

/**
 * 9Bar DJ Desk - offline test suite (DESIGN-DJ.md §10).
 *
 *   cd /home/user/trading && php dj/tests/run.php
 *   php dj/tests/run.php -q      only failures and the summary
 *
 * Exit code = the number of failed assertions (0 = success, capped at 250);
 * 251 when nothing could run because dj/lib is missing.
 *
 * Three promises this file keeps:
 *
 *   1. NOTHING TOUCHES THE NETWORK. There is no curl, no fopen on a URL, no
 *      stream wrapper but the local filesystem. The desk itself has no outbound
 *      calls at all, so there is nothing to stub.
 *   2. NOTHING TOUCHES dj/data. TRADER_DJ_ROOT is pointed at a fresh directory
 *      under the system temp dir before bootstrap.php is loaded, so every
 *      SQLite file, config.json and log the code under test creates lands
 *      there and is deleted on the way out. The last group asserts that the
 *      real dj/data is still untouched.
 *   3. NOTHING TOUCHES THE TRADING PANEL. The desk is a separate application
 *      (DESIGN-DJ.md §0) and this suite loads dj/bootstrap.php only - never
 *      ../lib, ../config.php or ../index.php.
 *
 * Groups, in the order of DESIGN-DJ.md §10:
 *   dj-util    esc, uid uniqueness, the date helpers
 *   dj-music   Camelot neighbours at the 12->1 wrap, matchesFor inside and
 *              outside the ±6% band, energyColor endpoints, prep derivation at
 *              all three thresholds, joinWarning
 *   dj-db      the schema is idempotent, insert/update/delete round trips, JSON
 *              columns survive, set_items keep their order after a move
 *   dj-auth    a wrong password fails, the right one succeeds, lockout after 5,
 *              CSRF rejects a bad token
 *   dj-pages   every route renders for a logged-in session and every dynamic
 *              value is escaped, and a POST without a valid CSRF token is rejected
 *   dj-import  the pipe form and the "artist - title" form
 *   dj-export  CSV and JSON round trip
 *
 * PHP 7.4 syntax only, like every other file in dj/.
 */

/* =========================================================================
 * Output: hand every byte straight to STDOUT through an output-buffer
 * handler that returns the empty string. Nothing ever reaches the SAPI
 * output layer, so headers_sent() stays false for the whole run - which is
 * what lets session_start(), header() and http_response_code() work while
 * dj/index.php is being rendered in-process further down.
 * ========================================================================= */

/* This file is the offline suite and belongs on the command line. dj/.htaccess
 * denies /tests/ twice over, but a host with AllowOverride None (dj/README.md
 * step 3) serves it, and everything below writes files and hashes passwords.
 * The guard makes the web server's configuration the second layer, not the only one. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This file is the offline test suite. Run it from a shell: php dj/tests/run.php\n");
}

ob_start(static function (string $chunk): string {
    if ($chunk !== '') {
        fwrite(STDOUT, $chunk);
    }
    return '';
}, 1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('html_errors', '0');
date_default_timezone_set('UTC');
set_time_limit(0);

$TEST_QUIET = in_array('-q', isset($argv) ? $argv : [], true);
$DJ_DIR     = dirname(__DIR__);

/* ------------------------------------------------------------- temp root */

$tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/dj-tests-' . getmypid() . '-' . bin2hex(random_bytes(3));
if (!@mkdir($tmpRoot . '/data', 0750, true) && !is_dir($tmpRoot . '/data')) {
    fwrite(STDERR, "Cannot create temp dir $tmpRoot\n");
    exit(251);
}

// Everything the desk writes is resolved from TRADER_DJ_ROOT: Db::defaultPath(),
// dj_data_dir(), the error log. Defining it here, before bootstrap.php runs,
// redirects the whole application into the temp directory.
define('TRADER_DJ_ROOT', $tmpRoot);

register_shutdown_function(static function () use ($tmpRoot): void {
    dj_rmtree($tmpRoot);
});

/**
 * A fingerprint of dj/data: name, size and mtime of everything in it except the
 * PHP error log, which belongs to the installation rather than to this suite.
 * Taken before the first test and compared after the last one, so "never touch
 * dj/data" is proved rather than asserted.
 */
function dj_data_fingerprint(string $dir): string
{
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return 'absent';
    }
    $parts = [];
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..' || $e === 'dj.log') {
            continue;
        }
        $path    = $dir . '/' . $e;
        $parts[] = $e . ':' . (string) @filesize($path) . ':' . (string) @filemtime($path);
    }
    sort($parts);
    return implode('|', $parts);
}

/** Best-effort recursive delete; never fatal, never leaves the temp root behind. */
function dj_rmtree(string $dir): void
{
    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return;
    }
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $path = $dir . '/' . $e;
        if (is_dir($path) && !is_link($path)) {
            dj_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/* --------------------------------------------------------- session in CLI */

// Auth::startSession() deliberately returns early on the CLI, so the suite
// starts the session itself: with one active, Auth reads and writes it exactly
// as it does behind a web server (auth flag, CSRF token, flashes).
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($tmpRoot);
session_name('dj_sid');
@session_start();

/* ------------------------------------------------------------ lib loading */

$DJ_DATA_BEFORE = dj_data_fingerprint($DJ_DIR . '/data');

$djLibs  = ['Util', 'Db', 'Auth', 'Data', 'Music', 'Render'];
$missing = [];
foreach ($djLibs as $lib) {
    if (!is_file($DJ_DIR . '/lib/' . $lib . '.php')) {
        $missing[] = 'lib/' . $lib . '.php';
    }
}
if (!is_file($DJ_DIR . '/bootstrap.php')) {
    $missing[] = 'bootstrap.php';
}
if ($missing !== []) {
    fwrite(STDERR, 'dj/ is incomplete: missing ' . implode(', ', $missing) . "\n");
    exit(251);
}
require_once $DJ_DIR . '/bootstrap.php';
if (is_file($DJ_DIR . '/config.php')) {
    require_once $DJ_DIR . '/config.php';
}

/* ======================================================== assert harness */

final class T
{
    public static $pass    = 0;
    public static $fail    = 0;
    public static $skipped = 0;
    public static $groups  = 0;
    public static $quiet   = false;
    public static $group   = '';

    public static function ok(bool $cond, string $name, string $detail = ''): bool
    {
        if ($cond) {
            self::$pass++;
            if (!self::$quiet) {
                echo 'ok   - ' . self::$group . ': ' . $name . "\n";
            }
        } else {
            self::$fail++;
            echo 'FAIL - ' . self::$group . ': ' . $name . ($detail !== '' ? ' (' . $detail . ')' : '') . "\n";
        }
        return $cond;
    }

    /** @param mixed $expected @param mixed $actual */
    public static function eq($expected, $actual, string $name): bool
    {
        if (is_float($expected) || is_float($actual)) {
            return self::near((float) $expected, (float) $actual, 1e-9 * max(1.0, abs((float) $expected)), $name);
        }
        return self::ok($expected === $actual, $name, 'expected ' . self::dump($expected) . ' got ' . self::dump($actual));
    }

    public static function near(float $expected, float $actual, float $tol, string $name): bool
    {
        return self::ok(is_finite($actual) && abs($expected - $actual) <= $tol, $name,
            'expected ' . $expected . ' +/-' . $tol . ' got ' . $actual);
    }

    public static function has(string $haystack, string $needle, string $name): bool
    {
        return self::ok(strpos($haystack, $needle) !== false, $name, self::dump($needle) . ' not found');
    }

    public static function hasNot(string $haystack, string $needle, string $name): bool
    {
        $at = strpos($haystack, $needle);
        return self::ok($at === false, $name, self::dump($needle) . ' found at offset ' . (string) $at);
    }

    /** @param string[] $needs classes/functions that must exist, else the group is skipped */
    public static function group(string $name, array $needs, callable $fn): void
    {
        self::$groups++;
        self::$group = $name;
        foreach ($needs as $n) {
            if (!class_exists($n, false) && !interface_exists($n, false) && !function_exists($n)) {
                self::$skipped++;
                echo 'skip - ' . $name . ': needs ' . $n . " (file missing or not loadable)\n";
                return;
            }
        }
        try {
            $fn();
        } catch (Throwable $e) {
            self::$fail++;
            echo 'FAIL - ' . $name . ': uncaught ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }

    public static function skip(string $name, string $why): void
    {
        self::$skipped++;
        echo 'skip - ' . $name . ': ' . $why . "\n";
    }

    /** @param mixed $v */
    public static function dump($v): string
    {
        if (is_array($v)) {
            $j = json_encode($v);
            return $j === false ? 'array' : (strlen($j) > 160 ? substr($j, 0, 157) . '...' : $j);
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        if (is_string($v)) {
            return '"' . (strlen($v) > 160 ? substr($v, 0, 157) . '...' : $v) . '"';
        }
        return (string) $v;
    }
}
T::$quiet = $TEST_QUIET;

/** A fresh SQLite database for one group, inside the temp root. */
function djDb(string $tag): Db
{
    Db::reset();
    $path = TRADER_DJ_ROOT . '/data/test-' . preg_replace('/[^a-z0-9]+/i', '-', $tag)
          . '-' . bin2hex(random_bytes(2)) . '.sqlite';
    return Db::get($path);
}

/* =========================================================================
 * The in-process router harness
 *
 * dj-pages, dj-import and dj-export drive the real dj/index.php, because a
 * route is only proved to render by rendering it. There is no shell here (no
 * exec, DESIGN-DJ.md §2) and no web server, so index.php is included directly
 * - which takes two pieces of care:
 *
 *   1. A file can only be included once: its top-level `function` and `class`
 *      declarations would clash on the second render. So the source is split
 *      with PHP's own tokenizer into a DECLARATIONS file (included once, at
 *      global scope, so the functions are hoisted exactly as they are in the
 *      original) and a BODY file (the router itself, included once per render).
 *   2. A router answers a POST with `header('Location: …'); exit;`. `exit`
 *      would end the suite, so every T_EXIT is rewritten to a call that throws
 *      DjExit instead, which the renderer catches and treats as end-of-response.
 *
 * __DIR__ and __FILE__ are rewritten to string literals pointing at the real
 * dj/ directory, so `require_once __DIR__ . '/bootstrap.php'` still resolves.
 * Nothing else about the source is changed.
 * ========================================================================= */

/** Thrown in place of exit/die while a route is being rendered. */
final class DjExit extends RuntimeException
{
}

/**
 * Stands in for exit/die inside the rendered router.
 *
 * @param mixed $status
 * @throws DjExit
 */
function dj_test_exit($status = 0): void
{
    if (is_string($status)) {
        echo $status;           // die('message') prints before it stops
    }
    throw new DjExit('exit');
}

final class DjSource
{
    /** Tokens that are only noise between two meaningful ones. */
    const NOISE = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * Split PHP source into [declarations, body], with __DIR__/__FILE__/exit
     * rewritten in both halves.
     *
     * @return array{0:string, 1:string} each a complete PHP file
     */
    public static function split(string $src, string $dir, string $file): array
    {
        $tokens = token_get_all($src);
        $n      = count($tokens);

        // pass 1: rewrite, dropping the leading "<?php" and the declare() header
        $flat  = [];
        $first = true;
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                $flat[] = [null, $t];
                continue;
            }
            $id   = $t[0];
            $text = $t[1];
            if ($first && $id === T_OPEN_TAG) {
                $first = false;
                continue;                       // our own prologue replaces it
            }
            if ($id === T_DECLARE) {
                $i = self::endOfStatement($tokens, $i);
                continue;                       // strict_types is in the prologue
            }
            if ($id === T_DIR) {
                $flat[] = [T_CONSTANT_ENCAPSED_STRING, var_export($dir, true)];
                continue;
            }
            if ($id === T_FILE) {
                $flat[] = [T_CONSTANT_ENCAPSED_STRING, var_export($file, true)];
                continue;
            }
            if ($id === T_EXIT) {
                $j = self::nextMeaningful($tokens, $i + 1);
                $call = ($j !== null && $tokens[$j] === '(') ? 'dj_test_exit' : 'dj_test_exit(0)';
                $flat[] = [T_STRING, $call];
                continue;
            }
            $flat[] = [$id, $text];
        }

        // pass 2: lift every top-level declaration out of the body
        $decls = '';
        $body  = '';
        $depth = 0;
        $count = count($flat);
        for ($i = 0; $i < $count; $i++) {
            $id   = $flat[$i][0];
            $text = $flat[$i][1];

            if ($depth === 0 && self::startsDeclaration($flat, $i)) {
                $end = self::endOfDeclaration($flat, $i);
                for ($k = $i; $k <= $end; $k++) {
                    $decls .= $flat[$k][1];
                }
                $decls .= "\n";
                $i = $end;
                continue;
            }

            if ($id === null) {
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                }
            } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }
            $body .= $text;
        }

        $prologue = "<?php\ndeclare(strict_types=1);\n";
        return [$prologue . $decls, $prologue . $body];
    }

    /** True when $flat[$i] opens a top-level declaration that must be lifted out. */
    private static function startsDeclaration(array $flat, int $i): bool
    {
        $id   = $flat[$i][0];
        $text = $flat[$i][1];
        if ($id === T_ABSTRACT || $id === T_FINAL || $id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) {
            return true;
        }
        if ($id === T_CONST) {
            return true;
        }
        if ($id === T_FUNCTION) {
            // a closure ("function () use (…)") is an expression, not a declaration;
            // a named function may still return by reference ("function &rows()")
            $j = self::nextMeaningful($flat, $i + 1);
            if ($j !== null && $flat[$j][0] === null && $flat[$j][1] === '&') {
                $j = self::nextMeaningful($flat, $j + 1);
            }
            return $j !== null && $flat[$j][0] === T_STRING;
        }
        if ($id === T_STRING && strtolower($text) === 'define') {
            // define() at top level would warn "already defined" on the second render
            $j = self::nextMeaningful($flat, $i + 1);
            return $j !== null && $flat[$j][0] === null && $flat[$j][1] === '(';
        }
        return false;
    }

    /** Index of the last token of the declaration starting at $i. */
    private static function endOfDeclaration(array $flat, int $i): int
    {
        $id = $flat[$i][0];
        if ($id === T_CONST || ($id === T_STRING && strtolower($flat[$i][1]) === 'define')) {
            return self::endOfStatement($flat, $i);
        }
        $count = count($flat);
        $depth = 0;
        $seen  = false;
        for ($k = $i; $k < $count; $k++) {
            $tid  = $flat[$k][0];
            $text = $flat[$k][1];
            if ($tid === null && $text === '{') {
                $depth++;
                $seen = true;
            } elseif ($tid === T_CURLY_OPEN || $tid === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($tid === null && $text === '}') {
                $depth--;
                if ($seen && $depth === 0) {
                    return $k;
                }
            } elseif (!$seen && $tid === null && $text === ';') {
                return $k;          // an abstract or interface signature
            }
        }
        return $count - 1;
    }

    /**
     * Index of the ";" that ends the statement starting at $i. Accepts both
     * shapes used here: raw token_get_all() output and the normalised
     * [id, text] pairs of pass 2.
     */
    private static function endOfStatement(array $tokens, int $i): int
    {
        $count = count($tokens);
        for ($k = $i; $k < $count; $k++) {
            $t    = $tokens[$k];
            $id   = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? (isset($t[1]) ? $t[1] : '') : $t;
            if ($id === null && $text === ';') {
                return $k;
            }
        }
        return $count - 1;
    }

    /** Index of the next token that is neither whitespace nor a comment, or null. */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($k = $from; $k < $count; $k++) {
            $t  = $tokens[$k];
            $id = is_array($t) ? $t[0] : null;
            if ($id !== null && in_array($id, self::NOISE, true)) {
                continue;
            }
            return $k;
        }
        return null;
    }
}

/**
 * Renders routes of dj/index.php in this process, the way a browser would ask
 * for them: a GET with a query string, or a POST with form fields.
 */
final class DjRouter
{
    /** @var string */
    private static $djDir = '';
    /** @var string */
    private static $bodyFile = '';
    /** @var bool */
    private static $ready = false;
    /** @var string */
    private static $why = '';
    /** @var int */
    private static $status = 200;

    /**
     * Prepare the split source files under $tmpDir. Returns false (with why())
     * when there is no index.php to drive yet.
     */
    public static function prepare(string $djDir, string $tmpDir): bool
    {
        if (self::$ready) {
            return true;
        }
        self::$djDir = $djDir;
        $index = $djDir . '/index.php';
        if (!is_file($index)) {
            self::$why = 'dj/index.php does not exist yet';
            return false;
        }
        if (!function_exists('token_get_all')) {
            self::$why = 'the tokenizer extension is not available';
            return false;
        }
        $src = @file_get_contents($index);
        if ($src === false || $src === '') {
            self::$why = 'dj/index.php could not be read';
            return false;
        }
        $split     = DjSource::split($src, $djDir, $index);
        $declsFile = $tmpDir . '/dj-index-decls.php';
        $bodyFile  = $tmpDir . '/dj-index-body.php';
        if (@file_put_contents($declsFile, $split[0]) === false
            || @file_put_contents($bodyFile, $split[1]) === false) {
            self::$why = 'the rewritten router could not be written to ' . $tmpDir;
            return false;
        }
        // global scope on purpose: the declarations must hoist exactly as they
        // do in the original file, so top-level code may call them by name
        require_once $declsFile;
        self::$bodyFile = $bodyFile;
        self::$ready    = true;
        return true;
    }

    public static function ready(): bool
    {
        return self::$ready;
    }

    public static function why(): string
    {
        return self::$why === '' ? 'the router harness is not prepared' : self::$why;
    }

    /** HTTP status of the last render. */
    public static function status(): int
    {
        return self::$status;
    }

    /** GET index.php?page=…&… */
    public static function get(string $page, array $params = []): string
    {
        $query = array_merge(['page' => $page], $params);
        return self::render('GET', $query, []);
    }

    /** GET a relative URL the desk itself rendered, e.g. "index.php?page=export&format=csv". */
    public static function follow(string $href): string
    {
        $q = [];
        $at = strpos($href, '?');
        if ($at !== false) {
            parse_str(html_entity_decode(substr($href, $at + 1), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $q);
        }
        return self::render('GET', is_array($q) ? $q : [], []);
    }

    /** POST index.php with $fields as the body. */
    public static function post(array $fields, array $query = []): string
    {
        return self::render('POST', $query, $fields);
    }

    /**
     * One request/response cycle.
     *
     * @throws RuntimeException when the router throws anything but exit
     */
    private static function render(string $method, array $query, array $post): string
    {
        if (!self::$ready) {
            throw new RuntimeException('DjRouter::prepare() has not succeeded');
        }
        $qs = http_build_query($query, '', '&');

        $_GET     = $query;
        $_POST    = $post;
        $_REQUEST = array_merge($query, $post);
        $_FILES   = [];
        $_SERVER  = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => '/dj/index.php' . ($qs === '' ? '' : '?' . $qs),
            'QUERY_STRING'    => $qs,
            'SCRIPT_NAME'     => '/dj/index.php',
            'PHP_SELF'        => '/dj/index.php',
            'SCRIPT_FILENAME' => self::$djDir . '/index.php',
            'DOCUMENT_ROOT'   => dirname(self::$djDir),
            'HTTP_HOST'       => 'desk.example',
            'SERVER_NAME'     => 'desk.example',
            'SERVER_PORT'     => '443',
            'HTTPS'           => 'on',
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_USER_AGENT' => 'dj-tests/1.0',
            'REQUEST_TIME'    => time(),
        ];

        if (class_exists('Auth', false)) {
            Auth::resetHeaders();
        }
        @http_response_code(200);

        ob_start();
        try {
            include self::$bodyFile;
        } catch (DjExit $e) {
            // a redirect or an early answer; whatever was printed is the response
        } catch (Throwable $e) {
            $out = ob_get_clean();
            throw new RuntimeException('index.php threw ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()
                . ' after ' . strlen((string) $out) . ' bytes', 0, $e);
        }
        $code = @http_response_code();
        self::$status = is_int($code) ? $code : 200;
        $html = ob_get_clean();
        return $html === false ? '' : $html;
    }
}

/* ------------------------------------------------------------- HTML tools */

/** Attribute name => value of one tag, e.g. '<input type="hidden" name="a" value="b">'. */
function dj_attrs(string $tag): array
{
    $out = [];
    if (preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*=\s*"([^"]*)"/', $tag, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            $out[strtolower($a[1])] = html_entity_decode($a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    if (preg_match_all('/\s(readonly|disabled|checked|selected|required)(?=[\s>])/i', $tag, $m2)) {
        foreach ($m2[1] as $flag) {
            $out[strtolower($flag)] = '1';
        }
    }
    return $out;
}

/**
 * Every <form> on a page, as the browser would submit it.
 *
 * @return array[] each ['method','action','fields'=>[name=>value],'textareas'=>[name=>value]]
 */
function dj_forms(string $html): array
{
    $forms = [];
    if (!preg_match_all('#<form\b([^>]*)>(.*?)</form>#is', $html, $m, PREG_SET_ORDER)) {
        return $forms;
    }
    foreach ($m as $f) {
        $attrs  = dj_attrs('<form ' . $f[1] . '>');
        $inner  = $f[2];
        $fields = [];
        $areas  = [];

        if (preg_match_all('#<input\b[^>]*>#i', $inner, $inputs)) {
            foreach ($inputs[0] as $tag) {
                $a    = dj_attrs($tag);
                $name = isset($a['name']) ? $a['name'] : '';
                $type = isset($a['type']) ? strtolower($a['type']) : 'text';
                if ($name === '' || $type === 'submit' || $type === 'button' || $type === 'file') {
                    continue;
                }
                if (($type === 'checkbox' || $type === 'radio') && !isset($a['checked'])) {
                    continue;
                }
                $fields[$name] = isset($a['value']) ? $a['value'] : '';
            }
        }
        if (preg_match_all('#<textarea\b([^>]*)>(.*?)</textarea>#is', $inner, $tas, PREG_SET_ORDER)) {
            foreach ($tas as $ta) {
                $a    = dj_attrs('<textarea ' . $ta[1] . '>');
                $name = isset($a['name']) ? $a['name'] : '';
                if ($name === '' || isset($a['readonly']) || isset($a['disabled'])) {
                    continue;
                }
                $areas[$name] = html_entity_decode($ta[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        if (preg_match_all('#<select\b([^>]*)>(.*?)</select>#is', $inner, $sels, PREG_SET_ORDER)) {
            foreach ($sels as $sel) {
                $a    = dj_attrs('<select ' . $sel[1] . '>');
                $name = isset($a['name']) ? $a['name'] : '';
                if ($name === '' || isset($a['disabled'])) {
                    continue;
                }
                $chosen = null;
                $firstV = null;
                if (preg_match_all('#<option\b([^>]*)>#i', $sel[2], $opts)) {
                    foreach ($opts[0] as $tag) {
                        $oa = dj_attrs($tag);
                        $ov = isset($oa['value']) ? $oa['value'] : '';
                        if ($firstV === null) {
                            $firstV = $ov;
                        }
                        if (isset($oa['selected'])) {
                            $chosen = $ov;
                            break;
                        }
                    }
                }
                $fields[$name] = $chosen !== null ? $chosen : (string) $firstV;
            }
        }

        $forms[] = [
            'method'    => isset($attrs['method']) ? strtoupper($attrs['method']) : 'GET',
            'action'    => isset($attrs['action']) ? $attrs['action'] : '',
            'fields'    => $fields,
            'textareas' => $areas,
        ];
    }
    return $forms;
}

/** Every href="…" on a page, in document order. @return string[] */
function dj_links(string $html): array
{
    $out = [];
    if (preg_match_all('#<a\b[^>]*\shref="([^"]*)"#i', $html, $m)) {
        foreach ($m[1] as $href) {
            $out[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $out;
}

/* =========================================================================
 *                                dj-util
 * ========================================================================= */

T::group('dj-util', ['Util'], static function (): void {
    // --- esc: every dynamic value in the desk goes through here (§8)
    T::eq('&lt;img src=x onerror=alert(1)&gt;', Util::esc('<img src=x onerror=alert(1)>'), 'esc neutralises a script-y tag');
    T::eq('&amp;', Util::esc('&'), 'esc escapes a bare ampersand');
    T::eq('&quot;quoted&quot;', Util::esc('"quoted"'), 'esc escapes double quotes');
    T::eq('&apos;quoted&apos;', Util::esc("'quoted'"), 'esc escapes single quotes (ENT_QUOTES | ENT_HTML5)');
    T::eq('', Util::esc(null), 'esc of null is the empty string');
    T::eq('', Util::esc(false), 'esc of false is the empty string');
    T::eq('1', Util::esc(true), 'esc of true is 1');
    T::eq('128', Util::esc(128), 'esc of an int is the plain number');
    T::eq('0.00005', Util::esc(0.00005), 'esc of a small float never becomes 5.0E-5');
    T::has(Util::esc(['a' => '<b>']), '&lt;b&gt;', 'esc of an array escapes the tags inside its JSON');
    T::hasNot(Util::esc(['a' => '<b>']), '<b>', 'esc of an array never leaks a raw tag');

    // --- decimal
    T::eq('0.001', Util::decimal(0.001), 'decimal prints a small value plainly');
    T::eq('128', Util::decimal(128.0), 'decimal drops trailing zeros');
    T::eq('-3.5', Util::decimal(-3.5), 'decimal keeps the sign');
    T::eq('0', Util::decimal(-0.0), 'decimal never prints -0');
    T::eq('0', Util::decimal(NAN), 'decimal of a non-finite value is 0');

    // --- uid uniqueness
    $seen = [];
    for ($i = 0; $i < 2000; $i++) {
        $seen[Util::uid()] = true;
    }
    T::eq(2000, count($seen), 'uid() produced 2000 distinct ids');
    T::ok(preg_match('/^[0-9a-z]+$/', Util::uid()) === 1, 'uid() is lower-case base 36');
    T::ok(strlen(Util::uid()) >= 10, 'uid() is at least 10 characters long');
    T::eq('0', Util::base36(0), 'base36 of 0');
    T::eq('a', Util::base36(10), 'base36 of 10');
    T::eq('10', Util::base36(36), 'base36 of 36');
    T::eq(16, strlen(Util::randomHex(8)), 'randomHex(8) is 16 hex characters');
    T::ok(preg_match('/^[0-9a-f]+$/', Util::randomHex(8)) === 1, 'randomHex is lower-case hex');
    T::ok(Util::randomHex(8) !== Util::randomHex(8), 'randomHex does not repeat itself');

    // --- dates
    T::ok(preg_match('/^\d{4}-\d{2}-\d{2}$/', Util::today()) === 1, 'today() is YYYY-MM-DD');
    T::eq(gmdate('Y-m-d'), Util::today(), 'today() is UTC');
    T::eq('2026-01-01', Util::isoAddDays('2025-12-31', 1), 'isoAddDays crosses the year boundary');
    T::eq('2025-12-31', Util::isoAddDays('2026-01-01', -1), 'isoAddDays walks backwards');
    T::eq('2024-03-01', Util::isoAddDays('2024-02-29', 1), 'isoAddDays handles a leap day');
    T::eq('2026-03-01', Util::isoAddDays('2026-02-28', 1), 'isoAddDays handles a non-leap February');
    T::eq(7, Util::dayDiff('2026-01-01', '2026-01-08'), 'dayDiff counts forwards');
    T::eq(-7, Util::dayDiff('2026-01-08', '2026-01-01'), 'dayDiff counts backwards');
    T::eq(0, Util::dayDiff('2026-01-08', '2026-01-08'), 'dayDiff of the same day is 0');
    T::eq(null, Util::dayDiff('', '2026-01-08'), 'dayDiff of an empty date is null, never 0');
    T::eq(null, Util::dayDiff('not a date', '2026-01-08'), 'dayDiff of junk is null');
    T::ok(Util::isDate('2026-02-28'), 'isDate accepts a real date');
    T::ok(!Util::isDate('2026-02-30'), 'isDate rejects 30 February');
    T::ok(!Util::isDate('2026-2-8'), 'isDate rejects an unpadded date');
    T::eq('2026-09-15T08:42:00Z', Util::nowIso(Util::isoToTs('2026-09-15T08:42:00Z')), 'nowIso/isoToTs round trip');
    T::eq(null, Util::isoToTs('  '), 'isoToTs of blank is null');
    T::eq('2026-01-01T00:15:00Z', Util::isoAddMinutes('2026-01-01T00:00:00Z', 15), 'isoAddMinutes adds the lockout window');

    // --- numerics
    T::eq(5.0, Util::clamp(9.0, 1.0, 5.0), 'clamp holds the ceiling');
    T::eq(1.0, Util::clamp(-9.0, 1.0, 5.0), 'clamp holds the floor');
    T::eq(3.0, Util::clamp(3.0, 5.0, 1.0), 'clamp swaps reversed bounds');
    T::eq(10, Util::clampInt(11, 1, 10), 'clampInt holds the energy ceiling');
    T::eq(1, Util::clampInt(0, 1, 10), 'clampInt holds the energy floor');
});

/* =========================================================================
 *                                dj-music
 * ========================================================================= */

T::group('dj-music', ['Music', 'Data'], static function (): void {
    // --- the wheel
    $wheel = Music::camelotWheel();
    T::eq(24, count($wheel), 'the Camelot wheel has 24 codes');
    T::eq('1A', $wheel[0], 'the wheel starts at 1A');
    T::eq('12B', $wheel[23], 'the wheel ends at 12B');

    T::eq('8A', Music::normalizeKey(' 8a '), 'normalizeKey tidies whitespace and case');
    T::eq('8A', Music::normalizeKey('08A'), 'normalizeKey drops a leading zero');
    T::eq('', Music::normalizeKey('13A'), 'normalizeKey rejects 13A');
    T::eq('', Music::normalizeKey('Fm'), 'normalizeKey rejects a musical key name');
    T::eq('', Music::normalizeKey(''), 'normalizeKey of blank is blank');
    T::eq('', Music::normalizeKey(null), 'normalizeKey of null is blank');

    // --- neighbours, including both ends of the 12 -> 1 wrap
    $n12 = Music::keyNeighbours('12A');
    T::eq(['12A', '1A', '11A', '12B'], $n12, '12A wraps up to 1A and down to 11A');
    $n1 = Music::keyNeighbours('1A');
    T::eq(['1A', '2A', '12A', '1B'], $n1, '1A wraps down to 12A');
    T::eq(['8B', '9B', '7B', '8A'], Music::keyNeighbours('8B'), '8B neighbours 9B, 7B and 8A');
    T::eq([], Music::keyNeighbours('nope'), 'an unparseable key has no neighbours');
    T::ok(Music::keysCompatible('12A', '1A'), '12A mixes into 1A across the wrap');
    T::ok(Music::keysCompatible('1A', '12A'), '1A mixes into 12A across the wrap');
    T::ok(Music::keysCompatible('8A', '8B'), 'the same number with the other letter is compatible');
    T::ok(!Music::keysCompatible('8A', '5A'), '8A does not mix into 5A');
    T::ok(!Music::keysCompatible('8A', ''), 'an unknown key is never called compatible');

    // --- matchesFor, inside and outside the ±6% band
    $track = ['id' => 't1', 'title' => 'Anchor', 'bpm' => 100.0, 'key' => '8A', 'energy' => 5];
    $crate = [
        ['id' => 't1', 'title' => 'Anchor itself', 'bpm' => 100.0, 'key' => '8A', 'energy' => 5],
        ['id' => 'in-low',  'title' => 'Just inside below', 'bpm' => 94.0,  'key' => '9A', 'energy' => 7],
        ['id' => 'in-high', 'title' => 'Exactly on the edge', 'bpm' => 106.0, 'key' => '7A', 'energy' => 3],
        ['id' => 'out',     'title' => 'Just outside', 'bpm' => 107.0, 'key' => '8B', 'energy' => 6],
        ['id' => 'wrongkey', 'title' => 'Wrong key', 'bpm' => 100.0, 'key' => '5A', 'energy' => 6],
        ['id' => 'acapella', 'title' => 'No tempo at all', 'bpm' => 0, 'key' => '8B', 'energy' => 4],
    ];
    $ids = [];
    foreach (Music::matchesFor($track, $crate) as $m) {
        $ids[] = (string) $m['track']['id'];
    }
    T::ok(!in_array('t1', $ids, true), 'matchesFor never matches the track against itself');
    T::ok(in_array('in-low', $ids, true), 'matchesFor keeps a track at the bottom of the ±6% band');
    T::ok(in_array('in-high', $ids, true), 'matchesFor keeps a track exactly on the +6% edge');
    T::ok(!in_array('out', $ids, true), 'matchesFor drops a track one BPM outside the band');
    T::ok(!in_array('wrongkey', $ids, true), 'matchesFor drops a track that is not a Camelot neighbour');
    T::ok(in_array('acapella', $ids, true), 'matchesFor keeps an acapella with no tempo of its own');

    $why = '';
    foreach (Music::matchesFor($track, $crate) as $m) {
        if ((string) $m['track']['id'] === 'in-low') {
            $why = (string) $m['why'];
        }
    }
    T::has($why, '9A neighbour', 'the reason names the neighbouring key');
    T::has($why, 'BPM', 'the reason names the tempo move');
    T::has($why, 'lifts', 'the reason says the energy lifts');
    T::ok(count(Music::matchesFor($track, array_merge($crate, $crate, $crate))) <= Music::MAX_MATCHES,
        'matchesFor never returns more than the six rows the panel shows');
    T::eq([], Music::matchesFor(['id' => 'x', 'bpm' => 120, 'key' => ''], $crate), 'a track with no key has no matches');

    // --- energyColor endpoints and the marigold midpoint
    T::eq('rgb(43,168,176)', Music::energyColor(1), 'energy 1 is the cool cyan end');
    T::eq('rgb(220,62,124)', Music::energyColor(10), 'energy 10 is the hot rani end');
    T::eq('rgb(224,154,43)', Music::energyColor(5.5), 'the middle of the ramp is marigold');
    T::eq('rgb(43,168,176)', Music::energyColor(0), 'a missing energy clamps to the cool end');
    T::eq('rgb(43,168,176)', Music::energyColor('nonsense'), 'an unparseable energy clamps to the cool end');
    T::eq('rgb(220,62,124)', Music::energyColor(99), 'an out-of-range energy clamps to the hot end');

    // --- prep derivation at all three thresholds
    T::eq(14, Music::prepTotal(), 'there are 14 prep steps');
    T::eq('raw', Music::prepDerive(['prep_done' => []]), 'nothing ticked is raw');
    T::eq('raw', Music::prepDerive(['prep_done' => ['src' => true, 'bpm' => true, 'grid' => true]]),
        'three of the four source+grid steps is still raw');
    $gridded = [];
    foreach (Data::GRIDDED_STEPS as $id) {
        $gridded[$id] = true;
    }
    T::eq('gridded', Music::prepDerive(['prep_done' => $gridded]), 'the four source+grid steps make a track gridded');
    $all = [];
    foreach (Music::prepFlat() as $step) {
        $all[$step['id']] = true;
    }
    T::eq(14, count($all), 'the flattened prep list has 14 distinct ids');
    T::eq('ready', Music::prepDerive(['prep_done' => $all]), 'all 14 steps make a track ready');
    T::eq('ready', Music::prepDerive(['prep_done' => json_encode($all)]), 'prep derivation reads the stored JSON too');
    T::eq(14, Music::prepDoneCount(['prep_done' => $all]), 'prepDoneCount counts every tick');
    T::eq(0, Music::prepDoneCount(['prep_done' => ['not-a-step' => true]]), 'an unknown tick counts for nothing');
    T::eq(13, Music::promoTotal(), 'there are 13 promo steps');

    // --- joinWarning
    T::eq('', Music::joinWarning(['key' => '8A', 'bpm' => 128], ['key' => '9A', 'bpm' => 130]),
        'a Camelot neighbour is never a rough join');
    T::eq('', Music::joinWarning(['key' => '8A', 'bpm' => 128], ['key' => '5A', 'bpm' => 130]),
        'a tempo inside 6% is never a rough join, whatever the key');
    T::eq('', Music::joinWarning(['key' => '', 'bpm' => 0], ['key' => '', 'bpm' => 0]),
        'two unknown tracks cannot be judged, so nothing is claimed');
    $warn = Music::joinWarning(['key' => '8A', 'bpm' => 104], ['key' => '5A', 'bpm' => 140]);
    T::ok($warn !== '', 'a wrong key and a 35% tempo jump is a rough join');
    T::has($warn, '8A', 'the warning names the outgoing key');
    T::has($warn, '5A', 'the warning names the incoming key');
    T::has($warn, 'Camelot neighbour', 'the warning says why the keys do not work');
    T::has($warn, '104', 'the warning names the outgoing tempo');
    T::has($warn, '140', 'the warning names the incoming tempo');
    $keyOnly = Music::joinWarning(['key' => '8A', 'bpm' => 0], ['key' => '5A', 'bpm' => 0]);
    T::has($keyOnly, 'Camelot', 'with no tempos known the warning talks only about keys');
    T::hasNot($keyOnly, 'BPM', 'with no tempos known the warning claims nothing about tempo');
});

/* =========================================================================
 *                                 dj-db
 * ========================================================================= */

T::group('dj-db', ['Db', 'Util'], static function (): void {
    $db = djDb('db');
    T::ok(strpos($db->path(), rtrim(sys_get_temp_dir(), '/\\')) === 0,
        'the test database lives under the system temp directory, not in dj/data');

    // --- the schema is idempotent
    $db->migrate();
    $db->migrate();
    $tables = [];
    foreach ($db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll() as $r) {
        $tables[] = (string) $r['name'];
    }
    foreach (['crate', 'gigs', 'login_attempts', 'meta', 'practice', 'set_items', 'sets'] as $want) {
        T::ok(in_array($want, $tables, true), 'the schema has a ' . $want . ' table after three migrations');
    }
    $idx = $db->pdo()->query("SELECT name FROM sqlite_master WHERE type='index' AND name='idx_set_items'")->fetchAll();
    T::eq(1, count($idx), 'idx_set_items exists exactly once');

    // --- crate round trip
    $id = $db->crateInsert([
        'title' => 'Bass Rani', 'artist' => 'Nucleya', 'bpm' => 140.0, 'key' => '5A',
        'energy' => 9, 'bucket' => 'desi', 'tags' => 'peak, dhol',
    ]);
    T::ok($id !== '', 'crateInsert returns the new id');
    $row = $db->crateGet($id);
    T::ok($row !== null, 'the inserted track can be read back');
    T::eq('Bass Rani', $row['title'], 'the title survived the round trip');
    T::eq(140.0, $row['bpm'], 'the BPM came back as a float');
    T::eq(9, $row['energy'], 'the energy came back as an int');
    T::eq('raw', $row['prep'], 'a new track starts raw');
    T::eq(Util::today(), $row['added_at'], 'added_at defaults to today');
    T::eq([], $row['prep_done'], 'prep_done starts as an empty object');

    $db->crateUpdate($id, ['energy' => 7, 'bucket' => 'house']);
    $row = $db->crateGet($id);
    T::eq(7, $row['energy'], 'crateUpdate wrote the new energy');
    T::eq('house', $row['bucket'], 'crateUpdate wrote the new bucket');
    T::eq('Bass Rani', $row['title'], 'crateUpdate left the untouched columns alone');

    $threw = false;
    try {
        $db->crateInsert(['title' => '   ']);
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    T::ok($threw, 'a track with no title is refused');

    // --- JSON columns survive
    $ticks = ['src' => true, 'bpm' => true, 'grid' => true, 'drift' => true];
    $db->crateUpdate($id, ['prep_done' => $ticks, 'prep' => 'gridded']);
    $row = $db->crateGet($id);
    T::eq($ticks, $row['prep_done'], 'prep_done survived as a decoded array');
    T::eq('gridded', $row['prep'], 'the derived prep state was stored');
    $raw = $db->pdo()->query('SELECT prep_done FROM crate')->fetchColumn();
    T::ok(is_string($raw) && json_decode((string) $raw, true) === $ticks, 'prep_done is stored as JSON text');

    $gigId = $db->gigInsert(['name' => 'Ninth Bar', 'venue' => 'Basement', 'date' => '2026-10-02', 'fee' => 250.0,
        'status' => 'confirmed', 'promo' => ['date' => true, 'flyer' => true]]);
    $gig = $db->gigGet($gigId);
    T::eq(['date' => true, 'flyer' => true], $gig['promo'], 'gigs.promo survived as a decoded array');
    T::eq(250.0, $gig['fee'], 'the fee came back as a float');

    $db->metaSet('press', ['bio' => 'Uncle K plays Bollywood at 124.', 'rate' => 250]);
    T::eq('Uncle K plays Bollywood at 124.', $db->metaArray('press')['bio'], 'meta JSON survived');
    $db->metaSet('skills', ['rung1' => true]);
    T::eq(['rung1' => true], $db->metaArray('skills'), 'meta holds the ladder state');
    T::eq([], $db->metaArray('nothing-here'), 'an absent meta key reads as an empty array');
    $db->metaDelete('skills');
    T::eq([], $db->metaArray('skills'), 'metaDelete removes the key');

    // --- set_items keep their order after a move
    $setId = $db->setInsert(['name' => 'Friday warm-up', 'brief' => '90 minutes, opening']);
    $items = [];
    foreach (['One', 'Two', 'Three', 'Four'] as $t) {
        $items[] = $db->setItemInsert($setId, ['title' => $t, 'bpm' => 120.0, 'key' => '8A']);
    }
    $titles = static function (array $rows): array {
        $out = [];
        foreach ($rows as $r) {
            $out[] = (string) $r['title'];
        }
        return $out;
    };
    T::eq(['One', 'Two', 'Three', 'Four'], $titles($db->setItems($setId)), 'items start in the order they were added');

    T::ok($db->setItemMove($setId, $items[2], -1), 'moving Three up reports a change');
    T::eq(['One', 'Three', 'Two', 'Four'], $titles($db->setItems($setId)), 'Three moved up one place');
    T::ok($db->setItemMove($setId, $items[0], 1), 'moving One down reports a change');
    T::eq(['Three', 'One', 'Two', 'Four'], $titles($db->setItems($setId)), 'One moved down one place');
    T::ok(!$db->setItemMove($setId, $items[2], -1), 'moving the first item up again changes nothing');
    T::eq(['Three', 'One', 'Two', 'Four'], $titles($db->setItems($setId)), 'a refused move leaves the order alone');
    T::ok(!$db->setItemMove($setId, $items[3], 1), 'moving the last item down changes nothing');

    $pos = [];
    foreach ($db->setItems($setId) as $r) {
        $pos[] = (int) $r['pos'];
    }
    T::eq([1, 2, 3, 4], $pos, 'pos stays a dense 1..n after the moves');

    $db->setItemUpdate($items[1], ['note' => 'ride the dhol for 16']);
    $note = '';
    foreach ($db->setItems($setId) as $r) {
        if ((int) $r['id'] === $items[1]) {
            $note = (string) $r['note'];
        }
    }
    T::eq('ride the dhol for 16', $note, 'a transition note survived');

    $db->setItemDelete($items[1]);
    T::eq(['Three', 'One', 'Four'], $titles($db->setItems($setId)), 'deleting an item closes the gap');
    $pos = [];
    foreach ($db->setItems($setId) as $r) {
        $pos[] = (int) $r['pos'];
    }
    T::eq([1, 2, 3], $pos, 'pos is renumbered after a delete');

    $db->setItemsReplace($setId, [['title' => 'A'], ['title' => 'B']]);
    T::eq(['A', 'B'], $titles($db->setItems($setId)), 'setItemsReplace rewrites the whole set');

    $full = $db->setWithItems($setId);
    T::ok($full !== null && isset($full['items']) && count($full['items']) === 2, 'setWithItems carries the items');

    // --- practice, and the delete round trip
    $pid = $db->practiceInsert(['date' => Util::today(), 'minutes' => 45, 'focus' => 'EQ swaps', 'rating' => 'Clean']);
    T::eq(1, count($db->practiceList()), 'the practice session was stored');
    $db->practiceDelete($pid);
    T::eq(0, count($db->practiceList()), 'the practice session was deleted');

    $db->crateDelete($id);
    T::eq(null, $db->crateGet($id), 'crateDelete removed the track');
    $db->setDelete($setId);
    T::eq(0, count($db->setItems($setId)), 'deleting a set takes its items with it');
    $db->gigDelete($gigId);
    T::eq(null, $db->gigGet($gigId), 'gigDelete removed the gig');

    // --- filters and export shape
    $db->crateInsert(['title' => 'Opener', 'bpm' => 100.0, 'key' => '8A', 'energy' => 3, 'bucket' => 'house']);
    $db->crateInsert(['title' => 'Peak time', 'bpm' => 140.0, 'key' => '5A', 'energy' => 9, 'bucket' => 'desi']);
    T::eq(1, $db->crateCount(['energy_max' => 4]), 'the openers count uses energy <= 4');
    T::eq(1, $db->crateCount(['energy_min' => 8]), 'the peaks count uses energy >= 8');
    T::eq(1, count($db->crateList(['bucket' => 'desi'])), 'the bucket filter narrows the crate');
    T::eq(1, count($db->crateList(['q' => 'peak'])), 'the search box matches a title');
    T::eq(0, count($db->crateList(['q' => 'no such track'])), 'the search box can match nothing');
    T::eq(['desi' => 1, 'house' => 1], $db->crateBuckets(), 'crateBuckets counts each bucket');

    $export = $db->exportAll();
    foreach (['crate', 'sets', 'set_items', 'practice', 'gigs', 'meta', 'login_attempts'] as $k) {
        T::ok(array_key_exists($k, $export), 'exportAll carries the ' . $k . ' table');
    }
    T::eq(2, count($export['crate']), 'exportAll carries both tracks');
    T::ok(is_array($export['meta']['press']), 'exportAll decodes the meta JSON');
    Db::reset();
});

/* =========================================================================
 *                                dj-auth
 * ========================================================================= */

T::group('dj-auth', ['Auth', 'Db'], static function (): void {
    $db  = djDb('auth');
    $cfg = ['password_hash' => Auth::hashPassword('correct horse battery'), 'force_https' => false];

    T::ok(Auth::needsSetup(['password_hash' => '']), 'an empty password hash means the setup screen');
    T::ok(!Auth::needsSetup($cfg), 'once a password is set, setup is over');

    // --- a wrong password fails, the right one succeeds
    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    Auth::logout(false);
    T::ok(!Auth::isLoggedIn(), 'nobody is logged in to start with');
    T::ok(!Auth::attemptLogin($cfg, $db, 'wrong'), 'a wrong password is refused');
    T::ok(!Auth::isLoggedIn(), 'a refused login leaves the session unauthenticated');
    T::ok(!Auth::attemptLogin($cfg, $db, ''), 'an empty password is refused');
    T::ok(Auth::attemptLogin($cfg, $db, 'correct horse battery'), 'the right password is accepted');
    T::ok(Auth::isLoggedIn(), 'the accepted login marked the session authenticated');
    T::eq(0, (int) $db->loginAttempt('203.0.113.7')['attempts'], 'a successful login clears the failure counter');

    $noPassword = ['password_hash' => '', 'force_https' => false];
    Auth::logout(false);
    T::ok(!Auth::attemptLogin($noPassword, $db, 'anything'), 'no password set means no password works');

    // --- lockout after 5 failures for 15 minutes
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    for ($i = 1; $i <= 4; $i++) {
        Auth::attemptLogin($cfg, $db, 'wrong ' . $i);
        T::ok(!Auth::loginLocked($db, '203.0.113.9'), 'still not locked out after ' . $i . ' failures');
    }
    Auth::attemptLogin($cfg, $db, 'wrong 5');
    T::ok(Auth::loginLocked($db, '203.0.113.9'), 'the fifth failure locks the IP out');
    T::ok(!Auth::attemptLogin($cfg, $db, 'correct horse battery'),
        'even the right password is refused while the IP is locked out');
    $left = Auth::lockoutSeconds($db, '203.0.113.9');
    T::ok($left > 0 && $left <= 15 * 60, 'the lockout window is at most 15 minutes (' . $left . 's left)');
    T::ok(!Auth::loginLocked($db, '198.51.100.4'), 'the lockout is per IP, not global');

    $db->loginOk('203.0.113.9');
    T::ok(!Auth::loginLocked($db, '203.0.113.9'), 'clearing the record lifts the lockout');

    // --- CSRF rejects a bad token
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $token = Auth::csrfToken();
    T::ok(strlen($token) >= 32, 'the CSRF token is at least 32 characters');
    T::ok(Auth::checkCsrf($token), 'the session token is accepted');
    T::ok(!Auth::checkCsrf('nope'), 'a wrong token is rejected');
    T::ok(!Auth::checkCsrf(''), 'an empty token is rejected');
    T::ok(!Auth::checkCsrf(null), 'a missing token is rejected');
    T::ok(!Auth::checkCsrf(['csrf' => $token]), 'an array token is rejected without raising a TypeError');
    T::ok(!Auth::checkCsrf(substr($token, 0, -1) . 'x'), 'a token that is wrong in one character is rejected');

    $_POST = ['csrf' => 'forged'];
    T::ok(!Auth::checkCsrf(), 'a POST carrying a forged token is rejected');
    $_POST = ['csrf' => $token];
    T::ok(Auth::checkCsrf(), 'a POST carrying the session token is accepted');
    $_POST = [];
    T::ok(!Auth::checkCsrf(), 'a POST carrying no token at all is rejected');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    T::ok(Auth::checkCsrf(), 'a GET is not a state change, so it needs no token');

    // --- the token changes when the session does
    $before = Auth::csrfToken();
    Auth::login();
    T::ok(Auth::csrfToken() !== $before, 'logging in mints a fresh CSRF token');
    Auth::logout(false);
    T::ok(!Auth::isLoggedIn(), 'logging out clears the auth flag');

    // --- the header set and the password rule
    $headers = Auth::securityHeaderList();
    T::eq('DENY', $headers['X-Frame-Options'], 'X-Frame-Options is DENY');
    T::eq('nosniff', $headers['X-Content-Type-Options'], 'X-Content-Type-Options is nosniff');
    T::eq('no-referrer', $headers['Referrer-Policy'], 'Referrer-Policy is no-referrer');
    T::eq('no-store', $headers['Cache-Control'], 'Cache-Control is no-store');
    T::has($headers['Content-Security-Policy'], "default-src 'self'", 'the CSP defaults to self');
    T::has($headers['Content-Security-Policy'], "object-src 'none'", 'the CSP forbids objects');
    T::has($headers['Content-Security-Policy'], "frame-ancestors 'none'", 'the CSP forbids framing');
    T::has($headers['Content-Security-Policy'], "form-action 'self'", 'the CSP pins form-action to self');
    T::hasNot($headers['Content-Security-Policy'], 'unsafe-inline', 'the CSP never allows inline code');

    T::eq('', Auth::passwordProblem('long enough', 'long enough'), 'a good password is accepted');
    T::has(Auth::passwordProblem('short', 'short'), '8', 'a short password names the minimum length');
    T::has(Auth::passwordProblem('long enough', 'different'), 'match', 'a mismatch is reported');
    T::has(Auth::passwordProblem('', ''), 'Choose', 'an empty password is reported');
    T::ok(password_verify('long enough', Auth::hashPassword('long enough')), 'hashPassword produces a verifiable hash');
    T::ok(Auth::hashPassword('x') !== Auth::hashPassword('x'), 'each hash gets its own salt');
    Db::reset();
});

/* =========================================================================
 *              shared fixture for dj-pages / dj-import / dj-export
 *
 * One desk, one logged-in session, one deliberately hostile track title.
 * ========================================================================= */

/** The payload DESIGN-DJ.md §10 names: it must never reach a page unescaped. */
const DJ_XSS      = '<img src=x onerror=alert(1)>';
const DJ_XSS_SAFE = '&lt;img src=x onerror=alert(1)&gt;';

/** Every route the rail offers, plus the fallback for an unknown page. */
function dj_routes(): array
{
    return ['desk', 'crate', 'prep', 'set', 'practice', 'gigs', '9bar', 'path', 'export', 'no-such-page'];
}

/**
 * Fill the temp installation: config.json with a password set, a crate, a set,
 * practice, a gig and the meta rows, all carrying the hostile title.
 *
 * @return array{db:Db, track:string, set:string, gig:string}
 */
function dj_seed(): array
{
    $dir = TRADER_DJ_ROOT . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    file_put_contents($dir . '/config.json', json_encode([
        'password_hash' => Auth::hashPassword('correct horse battery'),
        'force_https'   => false,
        'timezone'      => 'UTC',
        'theme'         => 'auto',
    ], JSON_PRETTY_PRINT));
    if (function_exists('dj_config')) {
        dj_config(true);
    }

    Db::reset();
    $db = Db::get();                      // TRADER_DJ_ROOT/data/dj.sqlite, in the temp root

    $track = $db->crateInsert([
        'title' => DJ_XSS, 'artist' => DJ_XSS, 'bpm' => 124.0, 'key' => '8A',
        'energy' => 7, 'bucket' => 'bollywood', 'tags' => DJ_XSS,
        'prep_done' => ['src' => true, 'bpm' => true, 'grid' => true, 'drift' => true],
        'prep' => 'gridded',
    ]);
    $db->crateInsert(['title' => 'Kesariya (Deep Edit)', 'artist' => 'Pritam / bootleg', 'bpm' => 122.0,
        'key' => '8A', 'energy' => 4, 'bucket' => 'edit', 'tags' => 'opener, vocal']);
    $db->crateInsert(['title' => 'Mai Ni Meriye', 'artist' => 'Lost Stories', 'bpm' => 124.0,
        'key' => '9A', 'energy' => 9, 'bucket' => 'house', 'tags' => 'lift']);

    $setId = $db->setInsert(['name' => DJ_XSS, 'brief' => DJ_XSS]);
    $db->setItemInsert($setId, ['track_id' => $track, 'title' => DJ_XSS, 'artist' => DJ_XSS,
        'bpm' => 124.0, 'key' => '8A', 'note' => DJ_XSS]);
    $db->setItemInsert($setId, ['title' => 'Mai Ni Meriye', 'artist' => 'Lost Stories',
        'bpm' => 124.0, 'key' => '9A', 'note' => 'lift on the drop']);

    $db->practiceInsert(['date' => Util::today(), 'minutes' => 45, 'focus' => DJ_XSS, 'rating' => 'Clean']);
    $db->practiceInsert(['date' => Util::isoAddDays(Util::today(), -1), 'minutes' => 30,
        'focus' => 'EQ swaps', 'rating' => 'OK']);

    $gigId = $db->gigInsert(['name' => DJ_XSS, 'venue' => DJ_XSS, 'date' => Util::isoAddDays(Util::today(), 14),
        'fee' => 250.0, 'status' => 'confirmed', 'promo' => ['date' => true, 'flyer' => true]]);

    $db->metaSet('press', ['bio' => DJ_XSS, 'rate' => '250', 'links' => DJ_XSS, 'rider' => DJ_XSS]);
    $db->metaSet('skills', ['beatmatch' => true]);
    $db->metaSet('rig', ['controller' => true]);

    // a logged-in session, the way DESIGN-DJ.md §10 asks for
    $_SESSION['auth']      = true;
    $_SESSION['login_at']  = time();
    $_SESSION['last_seen'] = time();
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = Util::randomHex(32);
    }

    return ['db' => $db, 'track' => $track, 'set' => $setId, 'gig' => $gigId];
}

/**
 * The POST form on $html that carries a free-text area - the desk's pipe
 * importer. Returns null when the page has none.
 */
function dj_bulk_form(string $html): ?array
{
    foreach (dj_forms($html) as $form) {
        if ($form['method'] !== 'POST' || $form['textareas'] === []) {
            continue;
        }
        if (!isset($form['fields']['csrf'])) {
            continue;
        }
        return $form;
    }
    return null;
}

$DJ_FIXTURE = null;
if (class_exists('Db', false) && class_exists('Auth', false) && function_exists('dj_config')) {
    $DJ_FIXTURE = dj_seed();
}
$DJ_ROUTER_OK = DjRouter::prepare($DJ_DIR, $tmpRoot);

/* =========================================================================
 *                               dj-pages
 * ========================================================================= */

T::group('dj-pages', ['Db', 'Auth', 'Render'], static function () use ($DJ_ROUTER_OK, $DJ_FIXTURE): void {
    if (!$DJ_ROUTER_OK || $DJ_FIXTURE === null) {
        T::skip('dj-pages', DjRouter::why());
        return;
    }
    /** @var Db $db */
    $db = $DJ_FIXTURE['db'];

    $showedEscaped = 0;
    foreach (dj_routes() as $route) {
        $html = DjRouter::get($route);
        $tag  = 'page=' . $route;

        T::ok($html !== '', $tag . ' renders something');
        T::has($html, '<html', $tag . ' renders a document');
        T::has($html, '</html>', $tag . ' renders a complete document');
        T::has($html, '9Bar', $tag . ' carries the 9Bar header');
        T::ok(DjRouter::status() < 400 || $route === 'no-such-page',
            $tag . ' answers with a non-error status', 'status ' . DjRouter::status());

        // --- DESIGN-DJ.md §10: never raw, always escaped
        T::hasNot($html, DJ_XSS, $tag . ' never renders the hostile track title raw');
        T::hasNot($html, '<img src=x', $tag . ' never emits the attacker\'s <img> tag');
        if (strpos($html, 'img src=x onerror=alert(1)') !== false) {
            $showedEscaped++;
            T::has($html, DJ_XSS_SAFE, $tag . ' renders the hostile track title escaped');
        }

        // --- DESIGN-DJ.md §8: no inline script, no event handlers, no inline style
        T::ok(preg_match('#<script\b(?![^>]*\ssrc=)#i', $html) !== 1, $tag . ' has no inline <script>');
        T::ok(preg_match('#\son[a-z]+\s*=\s*"#i', $html) !== 1, $tag . ' has no on… event attribute');
        T::ok(preg_match('#\sstyle\s*=\s*"#i', $html) !== 1, $tag . ' has no inline style attribute');
        T::has($html, 'assets/dj.css', $tag . ' loads the desk stylesheet');

        // --- every POST form on the page carries a CSRF token (§8)
        foreach (dj_forms($html) as $i => $form) {
            if ($form['method'] === 'POST') {
                T::ok(isset($form['fields']['csrf']) && $form['fields']['csrf'] !== '',
                    $tag . ' form #' . $i . ' carries a CSRF token');
            }
        }
    }

    $crate = DjRouter::get('crate');
    T::has($crate, DJ_XSS_SAFE, 'page=crate shows the hostile track title, escaped');
    T::ok($showedEscaped >= 2, 'the hostile title reaches at least two pages, escaped every time',
        'it appeared on ' . $showedEscaped . ' page(s)');

    // the expanded crate row ("what mixes out of it") is a route of its own
    $open = DjRouter::get('crate', ['open' => $DJ_FIXTURE['track']]);
    T::has($open, '<html', 'an expanded crate row renders');
    T::hasNot($open, DJ_XSS, 'an expanded crate row never renders the hostile title raw');

    $oneSet = DjRouter::get('set', ['id' => $DJ_FIXTURE['set']]);
    T::has($oneSet, '<html', 'a saved set reopens in the builder');
    T::hasNot($oneSet, DJ_XSS, 'a reopened set never renders the hostile title raw');

    $oneGig = DjRouter::get('9bar', ['gig' => $DJ_FIXTURE['gig']]);
    T::has($oneGig, '<html', 'a gig promo checklist renders');
    T::hasNot($oneGig, DJ_XSS, 'a gig promo checklist never renders the hostile name raw');

    // --- the logged-out desk is the login screen, not the crate
    $_SESSION['auth'] = false;
    $out = DjRouter::get('crate');
    T::hasNot($out, DJ_XSS, 'a logged-out visitor is shown no crate data at all');
    T::ok(stripos($out, 'password') !== false || stripos($out, 'log in') !== false,
        'a logged-out visitor is asked to log in');
    $_SESSION['auth'] = true;

    /* ------------------------------------------------------------------
     * The first-run screen (§8). Auth::forceHttps() deliberately lets setup
     * through over plain http, so the one request that carries a brand-new
     * password can arrive in clear text. Two things follow from that, and
     * both are asserted here rather than left to a browser:
     *   - the screen says so, in a warning the operator cannot miss;
     *   - the https-redirect box starts UNTICKED on such a request, because
     *     ticking it closes setup behind them and 301s every later request
     *     to an https:// their host may not answer.
     * ------------------------------------------------------------------ */
    $secureSetup   = dj_page_setup('', true, false);
    $insecureSetup = dj_page_setup('', true, true);
    $box           = '<input type="checkbox" name="force_https" value="1"';

    T::hasNot($secureSetup, 'flash-warn', 'setup over https shows no clear-text warning');
    T::has($secureSetup, $box . ' checked>', 'setup over https keeps the redirect box ticked');

    T::has($insecureSetup, 'flash-warn', 'setup over plain http warns that the password is in clear text');
    T::has($insecureSetup, 'would cross the network in clear text',
        'and says exactly what is at risk');
    T::has($insecureSetup, $box . '>', 'setup over plain http leaves the redirect box unticked');
    T::hasNot($insecureSetup, $box . ' checked>',
        'setup over plain http never pre-ticks a redirect the host cannot serve');
    T::has($insecureSetup, 'Left unticked because this request came in over plain http',
        'and explains why the box is unticked');
    T::has($insecureSetup, 'dj/data/config.json would be the only way back',
        'and names the way out if the operator ticks it anyway');

    T::hasNot(dj_page_setup('', false, false), $box . ' checked>',
        'a config with force_https off leaves the box unticked over https too');
    T::has(dj_page_setup('Passwords do not match', false, false), 'flash-danger',
        'a setup error is shown on the screen');

    foreach (['secure' => $secureSetup, 'insecure' => $insecureSetup] as $how => $html) {
        T::ok(preg_match('#<script\b(?![^>]*\ssrc=)#i', $html) !== 1,
            'the ' . $how . ' setup screen has no inline <script>');
        T::ok(preg_match('#\sstyle\s*=\s*"#i', $html) !== 1,
            'the ' . $how . ' setup screen has no inline style attribute');
        foreach (dj_forms($html) as $i => $form) {
            if ($form['method'] === 'POST') {
                T::ok(isset($form['fields']['csrf']) && $form['fields']['csrf'] !== '',
                    'the ' . $how . ' setup screen form #' . $i . ' carries a CSRF token');
            }
        }
    }

    /* ------------------------------------------------------------------
     * §10: a POST without a valid CSRF token is rejected.
     *
     * The same request is sent twice - once with a forged token and once
     * with the session's own - so the difference isolates the token as the
     * reason the first one changed nothing.
     * ------------------------------------------------------------------ */
    $form = dj_bulk_form(DjRouter::get('crate'));
    if ($form === null) {
        T::skip('dj-pages', 'the crate page has no POST form with a text area to forge against');
        return;
    }
    $area = array_keys($form['textareas']);
    $area = (string) $area[0];

    $before = $db->crateCount();

    $forged = $form['fields'];
    $forged['csrf'] = str_repeat('0', 64);
    $forged[$area]  = 'Forged Import | 128 | 8A | 5 | house';
    DjRouter::post($forged);
    T::eq($before, $db->crateCount(), 'a POST with a forged CSRF token changes nothing');
    T::eq(0, count($db->crateList(['q' => 'Forged Import'])), 'the forged POST added no track');

    $missing = $form['fields'];
    unset($missing['csrf']);
    $missing[$area] = 'Tokenless Import | 128 | 8A | 5 | house';
    DjRouter::post($missing);
    T::eq($before, $db->crateCount(), 'a POST with no CSRF token at all changes nothing');
    T::eq(0, count($db->crateList(['q' => 'Tokenless Import'])), 'the tokenless POST added no track');

    $good = $form['fields'];
    $good['csrf'] = Auth::csrfToken();
    $good[$area]  = 'Honest Import | 128 | 8A | 5 | house';
    DjRouter::post($good);
    T::eq(1, count($db->crateList(['q' => 'Honest Import'])),
        'the very same POST with the session token is accepted - so the token was the only difference');
    foreach ($db->crateList(['q' => 'Honest Import']) as $row) {
        $db->crateDelete((string) $row['id']);
    }
});

/* =========================================================================
 *                               dj-import
 *
 * Driven through the real form on the crate page, the way a browser posts it:
 * the pipe fields of §7 and the tolerant "artist - title" split of the
 * artifact's bulkPlain().
 * ========================================================================= */

T::group('dj-import', ['Db', 'Auth'], static function () use ($DJ_ROUTER_OK, $DJ_FIXTURE): void {
    if (!$DJ_ROUTER_OK || $DJ_FIXTURE === null) {
        T::skip('dj-import', DjRouter::why());
        return;
    }
    /** @var Db $db */
    $db   = $DJ_FIXTURE['db'];
    $form = dj_bulk_form(DjRouter::get('crate'));
    if ($form === null) {
        T::skip('dj-import', 'the crate page has no paste importer form');
        return;
    }
    $names = array_keys($form['textareas']);
    $area  = (string) $names[0];

    $paste = implode("\n", [
        'Nucleya - Bass Rani | 140 | 5A | 9 | desi',
        'Ritviz – Udd Gaye | 104 | 11B | 6 | bollywood',
        'Lost Stories · Mai Ni Meriye II | 124 | 11B | 7 | house',
        'Percussion Tool 126 | 126 | 1A | 3 | tool',
        'Bare Title With No Fields',
        '   ',
        'Sloppy Row | not-a-number | zz9 | 99 | not-a-bucket',
    ]);

    $fields         = $form['fields'];
    $fields['csrf'] = Auth::csrfToken();
    $fields[$area]  = $paste;
    $before = $db->crateCount();
    DjRouter::post($fields);
    T::eq($before + 6, $db->crateCount(), 'six of the seven pasted lines became tracks (the blank one did not)');

    $find = static function (Db $db, string $title): ?array {
        foreach ($db->crateList(['q' => $title]) as $row) {
            if ((string) $row['title'] === $title) {
                return $row;
            }
        }
        return null;
    };

    // --- the pipe form
    $bass = $find($db, 'Bass Rani');
    if (T::ok($bass !== null, 'the pipe line produced a track')) {
        T::eq('Nucleya', $bass['artist'], 'the hyphen split put Nucleya in the artist column');
        T::eq('Bass Rani', $bass['title'], 'the hyphen split put Bass Rani in the title column');
        T::eq(140.0, $bass['bpm'], 'the second field became the BPM');
        T::eq('5A', $bass['key'], 'the third field became the Camelot key');
        T::eq(9, $bass['energy'], 'the fourth field became the energy');
        T::eq('desi', $bass['bucket'], 'the fifth field became the bucket');
        T::eq('raw', $bass['prep'], 'an imported track starts raw');
        T::eq(Util::today(), $bass['added_at'], 'an imported track is dated today');
    }

    // --- the tolerant "artist - title" split on all three separators
    $udd = $find($db, 'Udd Gaye');
    if (T::ok($udd !== null, 'an en dash separates artist from title')) {
        T::eq('Ritviz', $udd['artist'], 'the en dash split put Ritviz in the artist column');
        T::eq(104.0, $udd['bpm'], 'the en dash row kept its BPM');
    }
    $mai = $find($db, 'Mai Ni Meriye II');
    if (T::ok($mai !== null, 'a middle dot separates artist from title')) {
        T::eq('Lost Stories', $mai['artist'], 'the middle dot split put Lost Stories in the artist column');
    }

    // --- a line with no separator is all title
    $tool = $find($db, 'Percussion Tool 126');
    if (T::ok($tool !== null, 'a line with no separator is taken as a title')) {
        T::eq('', (string) $tool['artist'], 'a line with no separator has no artist');
        T::eq(126.0, $tool['bpm'], 'a line with no separator still reads its pipe fields');
        T::eq('tool', $tool['bucket'], 'a line with no separator still reads its bucket');
    }

    // --- defaults, exactly as the artifact's bulkPlain()
    $bare = $find($db, 'Bare Title With No Fields');
    if (T::ok($bare !== null, 'a bare title is imported')) {
        T::eq(0.0, $bare['bpm'], 'a missing BPM is 0, never a guess');
        T::eq('', (string) $bare['key'], 'a missing key is blank');
        T::eq(5, $bare['energy'], 'a missing energy defaults to 5');
        T::eq('house', $bare['bucket'], 'a missing bucket defaults to house');
    }
    $sloppy = $find($db, 'Sloppy Row');
    if (T::ok($sloppy !== null, 'a row of junk fields still imports its title')) {
        T::eq(0.0, $sloppy['bpm'], 'a non-numeric BPM becomes 0');
        T::eq('', (string) $sloppy['key'], 'an impossible Camelot key is dropped');
        T::eq(10, $sloppy['energy'], 'an energy of 99 is clamped to 10');
        T::ok(in_array((string) $sloppy['bucket'], ['house', 'bollywood', 'desi', 'techno', 'edit', 'tool'], true),
            'an unknown bucket falls back to a real one');
    }

    // --- an empty paste imports nothing
    $count = $db->crateCount();
    $empty = $form['fields'];
    $empty['csrf'] = Auth::csrfToken();
    $empty[$area]  = "\n   \n\n";
    DjRouter::post($empty);
    T::eq($count, $db->crateCount(), 'pasting nothing but blank lines imports nothing');
});

/* =========================================================================
 *                               dj-export
 * ========================================================================= */

T::group('dj-export', ['Db'], static function () use ($DJ_ROUTER_OK, $DJ_FIXTURE): void {
    if (!$DJ_ROUTER_OK || $DJ_FIXTURE === null) {
        T::skip('dj-export', DjRouter::why());
        return;
    }
    /** @var Db $db */
    $db   = $DJ_FIXTURE['db'];
    $page = DjRouter::get('export');
    T::has($page, '<html', 'the export page renders');

    // follow the page's own links rather than guessing the query string
    $csvHref  = '';
    $jsonHref = '';
    foreach (dj_links($page) as $href) {
        if ($csvHref === '' && stripos($href, 'csv') !== false) {
            $csvHref = $href;
        }
        if ($jsonHref === '' && stripos($href, 'json') !== false) {
            $jsonHref = $href;
        }
    }
    T::ok($csvHref !== '', 'the export page offers a CSV download');
    T::ok($jsonHref !== '', 'the export page offers a JSON backup');

    /* ------------------------------------------------------------- CSV */

    $csv = $csvHref !== '' ? DjRouter::follow($csvHref) : DjRouter::get('export', ['format' => 'csv']);
    T::hasNot($csv, '<html', 'the CSV download is a file, not a page');
    T::ok(strlen($csv) > 0, 'the CSV download is not empty');

    $lines = preg_split('/\r\n|\n/', trim($csv));
    $lines = is_array($lines) ? array_values(array_filter($lines, static function ($l) {
        return trim((string) $l) !== '';
    })) : [];
    T::ok(count($lines) >= 2, 'the CSV has a header row and at least one track');

    $header = str_getcsv((string) $lines[0], ',', '"', '\\');
    $header = array_map('strtolower', $header);
    foreach (['title', 'artist', 'bpm', 'key', 'energy', 'bucket'] as $col) {
        T::ok(in_array($col, $header, true), 'the CSV header has a ' . $col . ' column');
    }

    // the round trip: parse the CSV back and compare it with the database
    $rows = [];
    for ($i = 1; $i < count($lines); $i++) {
        $cells = str_getcsv((string) $lines[$i], ',', '"', '\\');
        $row   = [];
        foreach ($header as $j => $name) {
            $row[$name] = isset($cells[$j]) ? $cells[$j] : '';
        }
        $rows[$row['title']] = $row;
    }
    T::eq($db->crateCount(), count($rows), 'the CSV carries every track in the crate');
    T::ok(isset($rows['Bass Rani']), 'an imported track is in the CSV');
    if (isset($rows['Bass Rani'])) {
        T::eq('Nucleya', $rows['Bass Rani']['artist'], 'the CSV round trip kept the artist');
        T::eq('140', $rows['Bass Rani']['bpm'], 'the CSV round trip kept the BPM');
        T::eq('5A', $rows['Bass Rani']['key'], 'the CSV round trip kept the key');
        T::eq('9', $rows['Bass Rani']['energy'], 'the CSV round trip kept the energy');
    }
    T::ok(isset($rows[DJ_XSS]), 'a title full of angle brackets survives the CSV round trip verbatim');
    T::ok(isset($rows['Kesariya (Deep Edit)']), 'a title with brackets and spaces survives the CSV round trip');

    // a comma in a value must be quoted, not allowed to shift the columns
    $db->crateInsert(['title' => 'Comma, Quote " and newline', 'artist' => "two\nlines",
        'bpm' => 100.0, 'key' => '1A', 'energy' => 5, 'bucket' => 'house']);
    $csv2   = $csvHref !== '' ? DjRouter::follow($csvHref) : DjRouter::get('export', ['format' => 'csv']);
    T::has($csv2, '"Comma, Quote "" and newline"', 'a comma and a quote are escaped RFC 4180 style');

    /* ------------------------------------------------------------ JSON */

    $json = $jsonHref !== '' ? DjRouter::follow($jsonHref) : DjRouter::get('export', ['format' => 'json']);
    T::hasNot($json, '<html', 'the JSON backup is a file, not a page');
    $data = json_decode($json, true);
    T::ok(is_array($data), 'the JSON backup decodes');
    if (!is_array($data)) {
        return;
    }
    $tables = isset($data['tables']) && is_array($data['tables']) ? $data['tables'] : $data;
    foreach (['crate', 'sets', 'set_items', 'practice', 'gigs', 'meta'] as $t) {
        T::ok(isset($tables[$t]), 'the JSON backup carries the ' . $t . ' table');
    }
    T::eq($db->crateCount(), count($tables['crate']), 'the JSON backup carries every track');
    T::eq(count($db->setsList()), count($tables['sets']), 'the JSON backup carries every saved set');
    T::eq(count($db->practiceList()), count($tables['practice']), 'the JSON backup carries every practice session');
    T::eq(count($db->gigsList()), count($tables['gigs']), 'the JSON backup carries every gig');

    $titles = [];
    foreach ($tables['crate'] as $row) {
        $titles[] = isset($row['title']) ? (string) $row['title'] : '';
    }
    T::ok(in_array(DJ_XSS, $titles, true), 'the JSON backup carries the raw title, unescaped - it is data, not HTML');
    T::ok(in_array('Bass Rani', $titles, true), 'the JSON backup carries an imported track');

    $ticked = false;
    foreach ($tables['crate'] as $row) {
        if (isset($row['prep_done']) && is_array($row['prep_done']) && $row['prep_done'] !== []) {
            $ticked = true;
        }
    }
    T::ok($ticked, 'the JSON backup carries the prep ticks as a decoded object');
    T::ok(isset($tables['meta']['press']) && is_array($tables['meta']['press']),
        'the JSON backup carries the press kit as a decoded object');
    T::ok(!isset($tables['meta']['password_hash']), 'the JSON backup carries no password hash');
    T::hasNot($json, '$2y$', 'the JSON backup never contains a bcrypt hash');
});

/* =========================================================================
 *                             the house rules
 * ========================================================================= */

T::group('dj-selfcontained', [], static function () use ($DJ_DIR): void {
    // Comments quote the very patterns these rules forbid ("no onclick=", "no
    // ?->"), so the scan runs on code with every comment removed.
    $strip = static function (string $src): string {
        if (!function_exists('token_get_all')) {
            return $src;
        }
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
                $out .= "\n";
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    };

    // §0: dj/ may never reach into the trading panel next door
    $files = [];
    foreach (['bootstrap.php', 'config.php', 'index.php', 'tests/run.php'] as $rel) {
        if (is_file($DJ_DIR . '/' . $rel)) {
            $files[$rel] = $strip((string) file_get_contents($DJ_DIR . '/' . $rel));
        }
    }
    $libFiles = glob($DJ_DIR . '/lib/*.php');
    foreach (is_array($libFiles) ? $libFiles : [] as $path) {
        $files['lib/' . basename($path)] = $strip((string) file_get_contents($path));
    }
    T::ok(count($files) >= 8, 'every file of the desk was read for the house-rule scan');

    foreach ($files as $rel => $src) {
        T::ok(preg_match('#(require|include)(_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]/\.\.#', $src) !== 1,
            $rel . ' never requires a file outside dj/');
        // PDO::exec() is not process execution, so a method call is not a hit
        T::ok(preg_match('#(?<![\w>:$])(exec|shell_exec|passthru|proc_open|popen|system)\s*\(#', $src) !== 1,
            $rel . ' uses no process execution');
        T::ok(preg_match('#\bstr_contains\s*\(|\bstr_starts_with\s*\(|\bstr_ends_with\s*\(#', $src) !== 1,
            $rel . ' uses no PHP 8 string helper');
        // spelled in two pieces so this suite does not fail its own scan
        T::ok(strpos($src, '?' . '->') === false, $rel . ' uses no nullsafe operator');
        T::ok(preg_match('#[=(,\s]match\s*\(#', $src) !== 1, $rel . ' uses no match expression');
        T::ok(preg_match('#^\s*(enum|readonly)\s#m', $src) !== 1, $rel . ' declares no enum and no readonly property');
        T::has($src, 'declare(strict_types=1)', $rel . ' declares strict types');
    }

    // §8: the desk emits no inline script, no event handler and no inline style
    foreach ($files as $rel => $src) {
        if ($rel === 'tests/run.php') {
            continue;      // this suite greps for those patterns, so it contains them
        }
        T::ok(strpos($src, 'onclick=') === false, $rel . ' emits no onclick attribute');
        T::ok(preg_match('#<script(?![^>]*\ssrc=)#i', $src) !== 1, $rel . ' emits no inline <script>');
        T::ok(preg_match('#\sstyle="[^"]#', $src) !== 1, $rel . ' emits no inline style attribute');
    }

    // the assets carry the identity of DESIGN-DJ.md §9
    $css = is_file($DJ_DIR . '/assets/dj.css') ? (string) file_get_contents($DJ_DIR . '/assets/dj.css') : '';
    T::ok($css !== '', 'assets/dj.css exists');
    foreach (['Khand', 'Hanken Grotesk', 'IBM Plex Mono'] as $family) {
        T::has($css, $family, 'dj.css asks for ' . $family);
    }
    T::has($css, 'fonts.googleapis.com', 'dj.css loads its faces from Google Fonts');
    T::has($css, 'tabular-nums', 'dj.css sets tabular numerals on its numbers');
    T::has($css, '--accent:#C22A64', 'dj.css uses the rani pink accent');
    T::has($css, '@media (prefers-color-scheme: dark)', 'dj.css has the system dark state');
    T::has($css, ':root:not([data-theme="light"])', 'dj.css guards the system dark state');
    T::has($css, ':root[data-theme="dark"]', 'dj.css has the explicit dark state');
    T::has($css, 'background: var(--ground)', 'dj.css paints an explicit page background');
    T::has($css, '@media (max-width: 760px)', 'dj.css has a phone breakpoint');
    T::has($css, 'overflow-x: auto', 'dj.css lets the rail scroll sideways on a phone');

    // every colour is declared as a token: no hex literal survives once the
    // custom-property declarations are removed
    $withoutTokens = preg_replace('#--[A-Za-z0-9-]+\s*:[^;]*;#', '', $css);
    T::ok(preg_match('##[0-9A-Fa-f]{3,8}\b#', (string) $withoutTokens) !== 1,
        'every colour in dj.css comes from a token');

    // §8 over a real SAPI: PHP's default session cache limiter emits a
    // Cache-Control of its own plus Pragma and Expires, which would overwrite
    // the one header set of securityHeaderList(). Auth::startSession() turns it
    // off, and startSession() is a no-op on the CLI, so this is asserted on the
    // source rather than on a response.
    $authSrc = isset($files['lib/Auth.php']) ? $files['lib/Auth.php'] : '';
    T::ok($authSrc !== '', 'lib/Auth.php was read for the header scan');
    T::ok(preg_match("#session_cache_limiter\\(\\s*''\\s*\\)#", $authSrc) === 1,
        'Auth::startSession() disables the session cache limiter, so only the five §8 headers are sent');
    T::ok(strpos($authSrc, 'Pragma') === false, 'Auth sends no Pragma header of its own');
    T::ok(strpos($authSrc, 'Strict-Transport-Security') === false, 'Auth sends no HSTS header');

    // the runtime directory must stay out of git. The repository root ignore
    // file anchors its "data/*" rule to the root, so it does NOT cover
    // dj/data/config.json - which carries the bcrypt password hash. dj/ owns an
    // ignore file of its own for exactly that.
    $ignorePath = $DJ_DIR . '/.gitignore';
    T::ok(is_file($ignorePath), 'dj/.gitignore exists');
    $ignore = is_file($ignorePath) ? (string) file_get_contents($ignorePath) : '';
    T::ok(preg_match('#^data/\*\s*$#m', $ignore) === 1, 'dj/.gitignore keeps dj/data/ out of git');
    T::ok(preg_match('#^!data/\.htaccess\s*$#m', $ignore) === 1,
        'dj/.gitignore still tracks dj/data/.htaccess, which has to ship');
    T::ok(preg_match('#^\*\.sqlite\s*$#m', $ignore) === 1, 'dj/.gitignore keeps the database out of git');
    T::ok(preg_match('#^\*\.log\s*$#m', $ignore) === 1, 'dj/.gitignore keeps the log out of git');

    $js = is_file($DJ_DIR . '/assets/dj.js') ? (string) file_get_contents($DJ_DIR . '/assets/dj.js') : '';
    T::ok($js !== '', 'assets/dj.js exists');
    T::has($js, 'data-confirm', 'dj.js implements the confirm dialogs');
    T::ok(strpos($js, 'XMLHttpRequest') === false && strpos($js, 'fetch(') === false,
        'dj.js makes no network requests of any kind');

    // promise 2: this suite never writes into the real dj/data
    $temp = rtrim(sys_get_temp_dir(), '/\\');
    T::ok(strpos((string) TRADER_DJ_ROOT, $temp) === 0, 'TRADER_DJ_ROOT points into the temp directory');
    T::ok(strpos((string) Db::defaultPath(), $temp) === 0, 'the desk resolves its database inside the temp directory');
    T::ok(strpos((string) dj_data_dir(), $temp) === 0, 'the desk resolves data/ inside the temp directory');
    T::ok(strpos((string) dj_config_path(), $temp) === 0, 'the desk resolves config.json inside the temp directory');
    T::eq($GLOBALS['DJ_DATA_BEFORE'], dj_data_fingerprint($DJ_DIR . '/data'),
        'dj/data is byte-for-byte what it was before the suite started');
});

/* ============================================================== summary */

T::$group = '';
echo "\n";
echo 'groups ' . T::$groups . ($TEST_QUIET ? '' : '') . "\n";
echo 'pass   ' . T::$pass . "\n";
echo 'fail   ' . T::$fail . "\n";
echo 'skip   ' . T::$skipped . "\n";
echo (T::$fail === 0 ? "ALL OK\n" : "FAILURES\n");

while (ob_get_level() > 0) {
    ob_end_flush();
}
exit(T::$fail === 0 ? 0 : min(250, T::$fail));
