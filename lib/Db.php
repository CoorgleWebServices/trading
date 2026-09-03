<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * SQLite wrapper (DESIGN.md §4). Prepared statements everywhere, WAL journal,
 * busy_timeout 5000 ms. Singleton via Db::get(); tests pass a temporary path
 * (or ':memory:') after Db::reset().
 *
 * Rows returned by the read methods are type-normalised (INTEGER → int,
 * REAL → float, TEXT → string, NULL stays null) so callers get the same PHP
 * types on 7.4 and 8.x regardless of pdo_sqlite's fetch behaviour.
 */
final class Db
{
    /** @var Db|null */
    private static $instance = null;

    /** @var PDO */
    private $pdo;

    /** @var string */
    private $path;

    /** Column types per table used for insert whitelisting and fetch normalisation. */
    const COLUMNS = [
        'state' => ['key' => 's', 'value' => 's'],
        'positions' => [
            'id' => 'i', 'mode' => 's', 'symbol' => 's', 'status' => 's',
            'qty' => 'f', 'dust_qty' => 'f', 'entry_price' => 'f', 'entry_eff' => 'f',
            'entry_quote' => 'f', 'entry_fee_usdt' => 'f',
            'exit_price' => 'f', 'exit_quote' => 'f', 'exit_fee_usdt' => 'f',
            'pnl_usdt' => 'f', 'pnl_pct' => 'f',
            'stop_price' => 'f', 'take_profit_price' => 'f', 'trail_high' => 'f',
            'trailing_armed' => 'i', 'score' => 'i', 'entry_reason' => 's', 'exit_reason' => 's',
            'opened_at' => 's', 'closed_at' => 's',
        ],
        'orders' => [
            'client_id' => 's', 'position_id' => 'i', 'mode' => 's', 'symbol' => 's',
            'side' => 's', 'status' => 's', 'created_at' => 's', 'updated_at' => 's', 'raw' => 's',
        ],
        'trades' => [
            'id' => 'i', 'position_id' => 'i', 'mode' => 's', 'symbol' => 's', 'side' => 's',
            'order_id' => 's', 'client_id' => 's', 'qty' => 'f', 'price' => 'f', 'quote' => 'f',
            'fee_usdt' => 'f', 'fee_asset' => 's', 'raw' => 's', 'created_at' => 's',
        ],
        'signals' => [
            'id' => 'i', 'symbol' => 's', 'score' => 'i', 'eligible' => 'i', 'price' => 'f',
            'reasons' => 's', 'created_at' => 's',
        ],
        'equity' => [
            'id' => 'i', 'ts' => 's', 'equity_usdt' => 'f', 'quote_free' => 'f',
            'position_value' => 'f', 'dust_value' => 'f',
        ],
        'logs' => ['id' => 'i', 'ts' => 's', 'level' => 's', 'message' => 's', 'context' => 's'],
        'login_attempts' => ['ip' => 's', 'attempts' => 'i', 'last_at' => 's', 'locked_until' => 's'],
    ];

    /* ------------------------------------------------------------ lifecycle */

    /** Singleton; default TRADER_ROOT/data/trader.sqlite. The schema is migrated on first open. */
    public static function get(?string $path = null): Db
    {
        if (self::$instance === null) {
            self::$instance = new self($path === null ? self::defaultPath() : $path);
        }
        return self::$instance;
    }

    /** Drop the singleton (tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function defaultPath(): string
    {
        $root = defined('TRADER_ROOT') ? TRADER_ROOT : dirname(__DIR__);
        return $root . '/data/trader.sqlite';
    }

    private function __construct(string $path)
    {
        $this->path = $path;
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
        if ($path !== ':memory:' && is_file($path)) {
            @chmod($path, 0640);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function migrate(): void
    {
        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS positions (
  id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, symbol TEXT NOT NULL,
  status TEXT NOT NULL,
  qty REAL NOT NULL,
  dust_qty REAL NOT NULL DEFAULT 0,
  entry_price REAL NOT NULL,
  entry_eff REAL NOT NULL,
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
  side TEXT NOT NULL, status TEXT NOT NULL,
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
CREATE INDEX IF NOT EXISTS idx_positions_status ON positions(status);
CREATE INDEX IF NOT EXISTS idx_positions_closed ON positions(closed_at);
CREATE INDEX IF NOT EXISTS idx_positions_opened ON positions(opened_at);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_signals_symbol ON signals(symbol, id);
CREATE INDEX IF NOT EXISTS idx_signals_created ON signals(created_at);
CREATE INDEX IF NOT EXISTS idx_equity_ts ON equity(ts);
CREATE INDEX IF NOT EXISTS idx_logs_ts ON logs(ts);
SQL;
        $this->pdo->exec($sql);
    }

    /* ------------------------------------------------------------ internals */

    /** @param mixed $v */
    private static function bindable($v)
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v)) {
            return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (is_float($v)) {
            if (!is_finite($v)) {
                return 0.0;
            }
            return $v;
        }
        return $v;
    }

    /** Keep only known columns of $table, converting values to bindable scalars. */
    private static function whitelist(string $table, array $row): array
    {
        $out = [];
        foreach (self::COLUMNS[$table] as $col => $type) {
            if (array_key_exists($col, $row)) {
                $out[$col] = self::bindable($row[$col]);
            }
        }
        return $out;
    }

    private static function castRow(string $table, array $row): array
    {
        $types = self::COLUMNS[$table];
        foreach ($row as $col => $v) {
            if ($v === null || !isset($types[$col])) {
                continue;
            }
            if ($types[$col] === 'i') {
                $row[$col] = (int) $v;
            } elseif ($types[$col] === 'f') {
                $row[$col] = (float) $v;
            } else {
                $row[$col] = (string) $v;
            }
        }
        return $row;
    }

    private function castRows(string $table, array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::castRow($table, $r);
        }
        return $out;
    }

    private function insertRow(string $table, array $row): int
    {
        $data = self::whitelist($table, $row);
        if ($data === []) {
            throw new InvalidArgumentException('Nothing to insert into ' . $table);
        }
        $cols = array_keys($data);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
              . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $st = $this->pdo->prepare($sql);
        $st->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    private function updateRow(string $table, string $keyCol, $keyVal, array $fields): void
    {
        $data = self::whitelist($table, $fields);
        unset($data[$keyCol]);
        if ($data === []) {
            return;
        }
        $sets = [];
        foreach (array_keys($data) as $c) {
            $sets[] = $c . ' = ?';
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $keyCol . ' = ?';
        $st  = $this->pdo->prepare($sql);
        $params   = array_values($data);
        $params[] = $keyVal;
        $st->execute($params);
    }

    private function fetchAll(string $table, string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $this->castRows($table, $st->fetchAll());
    }

    private function fetchOne(string $table, string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return is_array($row) ? self::castRow($table, $row) : null;
    }

    private function scalar(string $sql, array $params = [])
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /* ---------------------------------------------------------------- state */

    /** @return string|mixed the stored string, or $default when the key is absent */
    public function getState(string $key, $default = null)
    {
        $v = $this->scalar('SELECT value FROM state WHERE key = ?', [$key]);
        return $v === null ? $default : (string) $v;
    }

    /** arrays → JSON; bool → '1'/'0'; null → delete; floats → plain decimal (no exponent). */
    public function setState(string $key, $value): void
    {
        if ($value === null) {
            $st = $this->pdo->prepare('DELETE FROM state WHERE key = ?');
            $st->execute([$key]);
            return;
        }
        if (is_bool($value)) {
            $str = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $str = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            if ($str === false) {
                $str = '{}';
            }
        } elseif (is_float($value)) {
            $str = Util::toDecimalString($value, 10);
        } else {
            $str = (string) $value;
        }
        $st = $this->pdo->prepare('INSERT OR REPLACE INTO state (key, value) VALUES (?, ?)');
        $st->execute([$key, $str]);
    }

    public function getStateJson(string $key, $default = null)
    {
        $raw = $this->getState($key, null);
        if ($raw === null || $raw === '') {
            return $default;
        }
        $v = json_decode((string) $raw, true);
        return ($v === null && strtolower(trim((string) $raw)) !== 'null') ? $default : $v;
    }

    /** All state rows as [key => value] (panel/status convenience). */
    public function allState(): array
    {
        $st = $this->pdo->query('SELECT key, value FROM state ORDER BY key');
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(string) $r['key']] = (string) $r['value'];
        }
        return $out;
    }

    /* ------------------------------------------------------------ positions */

    public function openPosition(): ?array
    {
        return $this->fetchOne('positions', "SELECT * FROM positions WHERE status = 'OPEN' ORDER BY id DESC LIMIT 1");
    }

    public function position(int $id): ?array
    {
        return $this->fetchOne('positions', 'SELECT * FROM positions WHERE id = ?', [$id]);
    }

    public function insertPosition(array $row): int
    {
        if (!isset($row['opened_at'])) {
            $row['opened_at'] = Util::nowIso();
        }
        if (!isset($row['status'])) {
            $row['status'] = 'OPEN';
        }
        if (!isset($row['trail_high']) && isset($row['entry_eff'])) {
            $row['trail_high'] = $row['entry_eff'];
        }
        return $this->insertRow('positions', $row);
    }

    public function updatePosition(int $id, array $fields): void
    {
        $this->updateRow('positions', 'id', $id, $fields);
    }

    /** Newest first, status IN (CLOSED, STUCK). */
    public function closedPositions(int $limit = 50): array
    {
        return $this->fetchAll(
            'positions',
            "SELECT * FROM positions WHERE status IN ('CLOSED','STUCK') ORDER BY COALESCE(closed_at, opened_at) DESC, id DESC LIMIT ?",
            [max(1, $limit)]
        );
    }

    /* --------------------------------------------------------------- orders */

    public function insertOrder(array $row): void
    {
        if (!isset($row['created_at'])) {
            $row['created_at'] = Util::nowIso();
        }
        if (!isset($row['status'])) {
            $row['status'] = 'SENDING';
        }
        $this->insertRow('orders', $row);
    }

    public function updateOrder(string $clientId, array $fields): void
    {
        if (!isset($fields['updated_at'])) {
            $fields['updated_at'] = Util::nowIso();
        }
        $this->updateRow('orders', 'client_id', $clientId, $fields);
    }

    public function order(string $clientId): ?array
    {
        return $this->fetchOne('orders', 'SELECT * FROM orders WHERE client_id = ?', [$clientId]);
    }

    /** status IN (SENDING, UNKNOWN), oldest first. */
    public function pendingOrders(): array
    {
        return $this->fetchAll('orders', "SELECT * FROM orders WHERE status IN ('SENDING','UNKNOWN') ORDER BY created_at ASC, client_id ASC");
    }

    /** Number of orders (any status) created at or after $sinceIso; null $mode = every mode. */
    public function ordersSince(string $sinceIso, ?string $mode = null): int
    {
        $params = [$sinceIso];
        $sql    = 'SELECT COUNT(*) FROM orders WHERE created_at >= ?' . self::modeClause($mode, $params);
        return (int) $this->scalar($sql, $params);
    }

    /* --------------------------------------------------------------- trades */

    public function insertTrade(array $row): int
    {
        if (!isset($row['created_at'])) {
            $row['created_at'] = Util::nowIso();
        }
        return $this->insertRow('trades', $row);
    }

    public function trades(int $limit = 100): array
    {
        return $this->fetchAll('trades', 'SELECT * FROM trades ORDER BY id DESC LIMIT ?', [max(1, $limit)]);
    }

    /* -------------------------------------------------------------- signals */

    public function insertSignal(string $symbol, int $score, bool $eligible, float $price, array $reasons): void
    {
        $this->insertRow('signals', [
            'symbol'     => strtoupper($symbol),
            'score'      => $score,
            'eligible'   => $eligible ? 1 : 0,
            'price'      => $price,
            'reasons'    => json_encode(array_values($reasons), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'created_at' => Util::nowIso(),
        ]);
    }

    /**
     * Latest row per symbol, keyed by symbol. `reasons` stays the stored JSON
     * string; `reasons_list` carries it decoded as a string[].
     */
    public function latestSignals(): array
    {
        $rows = $this->fetchAll(
            'signals',
            'SELECT s.* FROM signals s JOIN (SELECT symbol, MAX(id) AS mid FROM signals GROUP BY symbol) m ON s.id = m.mid ORDER BY s.symbol'
        );
        $out = [];
        foreach ($rows as $r) {
            $r['reasons_list'] = self::decodeReasons((string) $r['reasons']);
            $out[(string) $r['symbol']] = $r;
        }
        return $out;
    }

    /** [reason => count] over signals.reasons of ineligible rows in the last $hours hours, most frequent first. */
    public function noTradeReasons(int $hours = 24): array
    {
        $since = Util::nowIso(time() - max(1, $hours) * 3600);
        $st = $this->pdo->prepare('SELECT reasons FROM signals WHERE eligible = 0 AND created_at >= ?');
        $st->execute([$since]);
        $counts = [];
        foreach ($st->fetchAll() as $r) {
            foreach (self::decodeReasons((string) $r['reasons']) as $tag) {
                if ($tag === '') {
                    continue;
                }
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }

    private static function decodeReasons(string $raw): array
    {
        $v = json_decode($raw, true);
        if (!is_array($v)) {
            return $raw === '' ? [] : [$raw];
        }
        $out = [];
        foreach ($v as $k => $t) {
            if (is_string($t) || is_numeric($t)) {
                $out[] = (string) $t;
            } elseif (is_string($k)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /* --------------------------------------------------------------- equity */

    public function insertEquity(float $equity, float $quoteFree, float $posValue, float $dustValue): void
    {
        $this->insertRow('equity', [
            'ts'             => Util::nowIso(),
            'equity_usdt'    => $equity,
            'quote_free'     => $quoteFree,
            'position_value' => $posValue,
            'dust_value'     => $dustValue,
        ]);
    }

    /** Oldest → newest, at most $limit rows (the most recent ones). */
    public function equitySeries(int $limit = 288): array
    {
        $rows = $this->fetchAll('equity', 'SELECT * FROM equity ORDER BY id DESC LIMIT ?', [max(1, $limit)]);
        return array_reverse($rows);
    }

    public function lastEquity(): ?array
    {
        return $this->fetchOne('equity', 'SELECT * FROM equity ORDER BY id DESC LIMIT 1');
    }

    /* ----------------------------------------------------------------- logs */

    public function log(string $level, string $msg, array $ctx = []): void
    {
        $context = null;
        if ($ctx !== []) {
            $context = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($context === false) {
                $context = null;
            }
        }
        $this->insertRow('logs', [
            'ts'      => Util::nowIso(),
            'level'   => strtoupper($level),
            'message' => $msg,
            'context' => $context,
        ]);
    }

    /** Newest first. */
    public function logs(int $limit = 60): array
    {
        return $this->fetchAll('logs', 'SELECT * FROM logs ORDER BY id DESC LIMIT ?', [max(1, $limit)]);
    }

    /* ------------------------------------------------------------ analytics */

    /**
     * ' AND mode = ?' (binding $mode into $params) when a mode is given, '' otherwise.
     * null/'' means "every mode" so the panel keeps seeing the whole history; the risk
     * layer always passes the configured mode so paper history cannot gate live (DESIGN §9).
     */
    private static function modeClause(?string $mode, array &$params): string
    {
        if ($mode === null || $mode === '') {
            return '';
        }
        $params[] = $mode;
        return ' AND mode = ?';
    }

    /** Sum of pnl_usdt of positions closed at or after $sinceIso; null $mode = every mode. */
    public function realisedPnl(string $sinceIso, ?string $mode = null): float
    {
        $params = [$sinceIso];
        $sql    = "SELECT COALESCE(SUM(pnl_usdt), 0) FROM positions"
                . " WHERE status = 'CLOSED' AND pnl_usdt IS NOT NULL AND closed_at >= ?"
                . self::modeClause($mode, $params);
        return (float) $this->scalar($sql, $params);
    }

    /** Number of positions opened (entries) at or after $sinceIso; null $mode = every mode. */
    public function entriesSince(string $sinceIso, ?string $mode = null): int
    {
        $params = [$sinceIso];
        $sql    = 'SELECT COUNT(*) FROM positions WHERE opened_at >= ?' . self::modeClause($mode, $params);
        return (int) $this->scalar($sql, $params);
    }

    /** Last $n CLOSED positions (with a pnl), newest first; null $mode = every mode. */
    public function lastClosed(int $n, ?string $mode = null): array
    {
        $params = [];
        $sql    = "SELECT * FROM positions WHERE status = 'CLOSED' AND pnl_usdt IS NOT NULL"
                . self::modeClause($mode, $params)
                . ' ORDER BY COALESCE(closed_at, opened_at) DESC, id DESC LIMIT ?';
        $params[] = max(1, $n);
        return $this->fetchAll('positions', $sql, $params);
    }

    /**
     * total_pnl, wins, losses, win_rate (percent 0–100 of decided trades), fees_total
     * (sum of trades.fee_usdt), trades_today (entries today UTC), pnl_today, pnl_week
     * (rolling 7 days), expectancy (average pnl per closed position), plus `closed` count.
     */
    public function stats(?string $mode = null): array
    {
        $params = [];
        $sql    = "SELECT COUNT(*) AS closed, COALESCE(SUM(pnl_usdt), 0) AS total_pnl,
                    COALESCE(SUM(CASE WHEN pnl_usdt > 0 THEN 1 ELSE 0 END), 0) AS wins,
                    COALESCE(SUM(CASE WHEN pnl_usdt < 0 THEN 1 ELSE 0 END), 0) AS losses
             FROM positions WHERE status = 'CLOSED' AND pnl_usdt IS NOT NULL"
                . self::modeClause($mode, $params);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        $closed   = (int) ($r['closed'] ?? 0);
        $totalPnl = (float) ($r['total_pnl'] ?? 0);
        $wins     = (int) ($r['wins'] ?? 0);
        $losses   = (int) ($r['losses'] ?? 0);
        $decided  = $wins + $losses;

        $feeParams = [];
        $feeSql    = 'SELECT COALESCE(SUM(fee_usdt), 0) FROM trades WHERE 1 = 1' . self::modeClause($mode, $feeParams);
        $fees      = (float) $this->scalar($feeSql, $feeParams);

        $todayStart = Util::todayUtc() . 'T00:00:00Z';
        $weekStart  = Util::nowIso(time() - 7 * 86400);

        return [
            'total_pnl'    => $totalPnl,
            'closed'       => $closed,
            'wins'         => $wins,
            'losses'       => $losses,
            'win_rate'     => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
            'fees_total'   => $fees,
            'trades_today' => $this->entriesSince($todayStart, $mode),
            'pnl_today'    => $this->realisedPnl($todayStart, $mode),
            'pnl_week'     => $this->realisedPnl($weekStart, $mode),
            'expectancy'   => $closed > 0 ? $totalPnl / $closed : 0.0,
        ];
    }

    /** Keep signals/equity/logs for $days days. */
    public function prune(int $days = 30): void
    {
        $cutoff = Util::nowIso(time() - max(1, $days) * 86400);
        $this->pdo->prepare('DELETE FROM signals WHERE created_at < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM equity WHERE ts < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM logs WHERE ts < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE last_at IS NOT NULL AND last_at < ?')->execute([$cutoff]);
    }

    /* ---------------------------------------------------------------- login */

    /** Row for $ip (attempts 0 when unknown) plus `locked` => bool (locked_until in the future). */
    public function loginAttempt(string $ip): array
    {
        $row = $this->fetchOne('login_attempts', 'SELECT * FROM login_attempts WHERE ip = ?', [$ip]);
        if ($row === null) {
            $row = ['ip' => $ip, 'attempts' => 0, 'last_at' => null, 'locked_until' => null];
        }
        $row['locked'] = false;
        if (!empty($row['locked_until'])) {
            $ts = Util::isoToTs((string) $row['locked_until']);
            $row['locked'] = $ts !== null && $ts > time();
        }
        return $row;
    }

    /** Count a failed login; after $max failures the IP is locked for $lockMinutes. */
    public function loginFailed(string $ip, int $max = 5, int $lockMinutes = 15): void
    {
        $cur = $this->loginAttempt($ip);
        $attempts = (int) $cur['attempts'];
        if (!empty($cur['locked_until']) && !$cur['locked']) {
            $attempts = 0; // previous lock expired: start a fresh window
        }
        $attempts++;
        $now = Util::nowIso();
        $lockedUntil = $cur['locked'] ? (string) $cur['locked_until'] : null;
        if ($attempts >= max(1, $max)) {
            $lockedUntil = Util::isoAddMinutes($now, max(1, $lockMinutes));
        }
        $st = $this->pdo->prepare(
            'INSERT OR REPLACE INTO login_attempts (ip, attempts, last_at, locked_until) VALUES (?, ?, ?, ?)'
        );
        $st->execute([$ip, $attempts, $now, $lockedUntil]);
    }

    public function loginOk(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }
}
