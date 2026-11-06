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
            // DESIGN-SCALP.md §8: the fee split per closed position. `pnl_usdt` stays the
            // authoritative NET result; these two say how much of it the exchange took, which
            // is the whole §2 verdict (gross edge vs fees vs net). Added by ALTER TABLE on an
            // existing database, so a row written before they existed reads 0.
            'gross_usdt' => 'f', 'fees_usdt' => 'f',
            // Which engine opened this position. Defaults to 'signal' on every pre-existing row
            // and on every writer that omits it, so the signal engine is unchanged; the scalp
            // engine writes 'scalp' and openPosition() skips those rows (it returns THE one
            // position the signal engine manages, and scalp runs many at once).
            'engine' => 's',
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
        'engine_orders' => [
            'client_id' => 's', 'order_id' => 's', 'mode' => 's', 'engine' => 's',
            'symbol' => 's', 'side' => 's', 'status' => 's',
            'price' => 'f', 'qty' => 'f', 'quote' => 'f',
            'filled_qty' => 'f', 'filled_quote' => 'f',
            'fee_usdt' => 'f', 'fee_asset' => 's',
            'level' => 'i', 'purpose' => 's',
            'created_at' => 's', 'updated_at' => 's', 'raw' => 's',
        ],
        'lots' => [
            'id' => 'i', 'mode' => 's', 'engine' => 's', 'symbol' => 's',
            'qty' => 'f', 'remaining' => 'f', 'price' => 'f', 'fee_usdt' => 'f',
            'level' => 'i', 'client_id' => 's', 'created_at' => 's',
        ],
        'cycles' => [
            'id' => 'i', 'mode' => 's', 'engine' => 's', 'symbol' => 's',
            'level' => 'i', 'qty' => 'f', 'buy_price' => 'f', 'sell_price' => 'f',
            'gross_usdt' => 'f', 'fee_usdt' => 'f', 'pnl_usdt' => 'f',
            'opened_at' => 's', 'closed_at' => 's',
        ],
        'sleeve_equity' => [
            'id' => 'i', 'ts' => 's', 'mode' => 's', 'engine' => 's',
            'equity' => 'f', 'budget' => 'f', 'realised' => 'f', 'unrealised' => 'f',
            'inventory_value' => 'f', 'reserved' => 'f',
        ],
        'scanner' => [
            'symbol' => 's', 'ts' => 's', 'price' => 'f', 'change_pct' => 'f', 'quote_vol' => 'f',
            'spread_pct' => 'f', 'atr_pct' => 'f', 'step_value' => 'f', 'min_notional' => 'f',
            'required_size' => 'f', 'score' => 'f', 'eligible' => 'i', 'gates' => 's',
        ],
        'movers' => [
            'symbol' => 's', 'ts' => 's', 'last' => 'f', 'change_pct' => 'f', 'range_pct' => 'f',
            'trade_count' => 'i', 'quote_vol' => 'f', 'spread_pct' => 'f', 'step_value' => 'f',
            'required_size' => 'f', 'score' => 'f', 'rank' => 'i', 'in_universe' => 'i',
            'eligible' => 'i', 'gates' => 's',
        ],
        'scalp_runs' => [
            'id' => 'i', 'started_at' => 's', 'ended_at' => 's', 'mode' => 's',
            'polls' => 'i', 'entries' => 'i', 'exits' => 'i',
            'gross_usdt' => 'f', 'fees_usdt' => 'f', 'net_usdt' => 'f', 'note' => 's',
        ],
        'observations' => [
            'id' => 'i', 'ts' => 's', 'mode' => 's', 'engine' => 's', 'symbol' => 's',
            'decision' => 's', 'skip_reason' => 's',
            'score' => 'i', 'threshold' => 'i', 'features' => 's',
            'position_id' => 'i', 'cycle_id' => 'i',
            'outcome' => 's', 'pnl_usdt' => 'f', 'pnl_pct' => 'f',
            'exit_reason' => 's', 'held_minutes' => 'f', 'resolved_at' => 's',
        ],
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
CREATE TABLE IF NOT EXISTS engine_orders (
  client_id TEXT PRIMARY KEY, order_id TEXT, mode TEXT NOT NULL, engine TEXT NOT NULL,
  symbol TEXT NOT NULL, side TEXT NOT NULL,
  status TEXT NOT NULL,
  price REAL NOT NULL, qty REAL NOT NULL, quote REAL NOT NULL,
  filled_qty REAL NOT NULL DEFAULT 0, filled_quote REAL NOT NULL DEFAULT 0,
  fee_usdt REAL NOT NULL DEFAULT 0, fee_asset TEXT,
  level INTEGER,
  purpose TEXT,
  created_at TEXT NOT NULL, updated_at TEXT, raw TEXT
);
CREATE INDEX IF NOT EXISTS idx_engine_orders_live ON engine_orders(status, symbol);
CREATE INDEX IF NOT EXISTS idx_engine_orders_created ON engine_orders(created_at);
CREATE INDEX IF NOT EXISTS idx_engine_orders_scan ON engine_orders(mode, engine, symbol, side);
CREATE TABLE IF NOT EXISTS lots (
  id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, engine TEXT NOT NULL, symbol TEXT NOT NULL,
  qty REAL NOT NULL, remaining REAL NOT NULL, price REAL NOT NULL, fee_usdt REAL NOT NULL DEFAULT 0,
  level INTEGER, client_id TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_lots_open ON lots(symbol, remaining);
CREATE INDEX IF NOT EXISTS idx_lots_scan ON lots(mode, engine, symbol);
CREATE TABLE IF NOT EXISTS cycles (
  id INTEGER PRIMARY KEY AUTOINCREMENT, mode TEXT NOT NULL, engine TEXT NOT NULL, symbol TEXT NOT NULL,
  level INTEGER, qty REAL NOT NULL, buy_price REAL NOT NULL, sell_price REAL NOT NULL,
  gross_usdt REAL NOT NULL, fee_usdt REAL NOT NULL, pnl_usdt REAL NOT NULL,
  opened_at TEXT, closed_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cycles_closed ON cycles(closed_at);
CREATE TABLE IF NOT EXISTS sleeve_equity (
  id INTEGER PRIMARY KEY AUTOINCREMENT, ts TEXT NOT NULL, mode TEXT NOT NULL, engine TEXT NOT NULL,
  equity REAL NOT NULL, budget REAL NOT NULL, realised REAL NOT NULL, unrealised REAL NOT NULL,
  inventory_value REAL NOT NULL, reserved REAL NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sleeve_equity ON sleeve_equity(mode, engine, ts);
CREATE TABLE IF NOT EXISTS scanner (
  symbol TEXT PRIMARY KEY, ts TEXT NOT NULL, price REAL, change_pct REAL, quote_vol REAL,
  spread_pct REAL, atr_pct REAL, step_value REAL, min_notional REAL, required_size REAL,
  score REAL, eligible INTEGER NOT NULL DEFAULT 0, gates TEXT
);
CREATE INDEX IF NOT EXISTS idx_scanner_score ON scanner(score);
CREATE TABLE IF NOT EXISTS observations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts TEXT NOT NULL, mode TEXT NOT NULL, engine TEXT NOT NULL, symbol TEXT NOT NULL,
  decision TEXT NOT NULL,
  skip_reason TEXT,
  score INTEGER, threshold INTEGER,
  features TEXT NOT NULL,
  position_id INTEGER, cycle_id INTEGER,
  outcome TEXT,
  pnl_usdt REAL, pnl_pct REAL, exit_reason TEXT, held_minutes REAL,
  resolved_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_obs_open ON observations(outcome, mode);
CREATE INDEX IF NOT EXISTS idx_obs_ts ON observations(mode, ts);
CREATE INDEX IF NOT EXISTS idx_obs_position ON observations(position_id);
CREATE INDEX IF NOT EXISTS idx_obs_cycle ON observations(cycle_id);
CREATE TABLE IF NOT EXISTS movers (
  symbol TEXT PRIMARY KEY, ts TEXT NOT NULL, last REAL, change_pct REAL, range_pct REAL,
  trade_count INTEGER, quote_vol REAL, spread_pct REAL, step_value REAL, required_size REAL,
  score REAL, rank INTEGER, in_universe INTEGER NOT NULL DEFAULT 0,
  eligible INTEGER NOT NULL DEFAULT 0, gates TEXT
);
CREATE INDEX IF NOT EXISTS idx_movers_score ON movers(score);
CREATE INDEX IF NOT EXISTS idx_movers_universe ON movers(in_universe, rank);
CREATE TABLE IF NOT EXISTS scalp_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT, started_at TEXT NOT NULL, ended_at TEXT,
  mode TEXT NOT NULL, polls INTEGER DEFAULT 0, entries INTEGER DEFAULT 0, exits INTEGER DEFAULT 0,
  gross_usdt REAL DEFAULT 0, fees_usdt REAL DEFAULT 0, net_usdt REAL DEFAULT 0, note TEXT
);
CREATE INDEX IF NOT EXISTS idx_scalp_runs_started ON scalp_runs(mode, started_at);
SQL;
        $this->pdo->exec($sql);

        // DESIGN-SCALP.md §8: the per-closed-position fee split. ADDITIVE ONLY - one guarded
        // ALTER TABLE per missing column, never a rebuild: `positions` is the table the whole
        // survival layer, the equity curve and the trade history read, and dropping and
        // recreating it to add a column would risk every row in it. Existing rows read 0.
        $this->addColumnIfMissing('positions', 'gross_usdt', 'REAL NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('positions', 'fees_usdt', 'REAL NOT NULL DEFAULT 0');
        // Which engine owns a position. The DEFAULT is what makes this safe to add under a live
        // signal position: every row that existed before, and every INSERT that does not mention
        // the column, reads 'signal', so openPosition() keeps returning exactly what it did.
        $this->addColumnIfMissing('positions', 'engine', "TEXT NOT NULL DEFAULT 'signal'");
    }

    /**
     * Add one column to an existing table when it is not there already, and do nothing at all
     * when it is. Idempotent, so migrate() can run on every open.
     *
     * $table, $column and $decl are compile-time literals from this file only - never anything
     * a caller or the panel supplied - because SQLite cannot bind an identifier.
     *
     * @param string $decl the column's SQL type and constraints, e.g. 'REAL NOT NULL DEFAULT 0'
     * @return bool true when the column was added by this call
     */
    private function addColumnIfMissing(string $table, string $column, string $decl): bool
    {
        if ($this->hasColumn($table, $column)) {
            return false;
        }
        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $decl);
        return true;
    }

    /**
     * True when $table has a column called $column.
     *
     * Deliberately `PRAGMA table_info(...)` and not the `pragma_table_info()` table-valued
     * function: the latter needs SQLite 3.16+, and this has to work on whatever sqlite the
     * shared host happens to ship. The identifier is quoted and comes from this file only.
     * An unreadable pragma answers "the column is there", which makes the caller skip the
     * ALTER rather than attempt one that would throw.
     */
    public function hasColumn(string $table, string $column): bool
    {
        try {
            $rows = $this->pdo->query('PRAGMA table_info("' . str_replace('"', '', $table) . '")');
            if ($rows === false) {
                return true;
            }
            foreach ($rows->fetchAll() as $r) {
                if (is_array($r) && isset($r['name']) && (string) $r['name'] === $column) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return true;
        }
        return false;
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

    /**
     * THE open position the signal engine manages, or null.
     *
     * Scalp rows are excluded on purpose. The scalp engine holds up to `scalp_max_open`
     * positions at once in this same table (DESIGN-SCALP.md §8), and this method's contract is
     * "the one position", which the whole signal path - exit checks, closePosition(), the
     * survival layer's `hasOpenPosition` - is built on. Without the filter, turning the scalp
     * engine on would hand a scalp position to the signal engine's exit logic, which would
     * manage and close it under its own stop and target. `COALESCE` so a row written before the
     * `engine` column existed still reads as the signal engine's.
     */
    public function openPosition(): ?array
    {
        return $this->fetchOne(
            'positions',
            "SELECT * FROM positions WHERE status = 'OPEN' AND COALESCE(engine, 'signal') <> 'scalp'
             ORDER BY id DESC LIMIT 1"
        );
    }

    /* --------------------------------------------------------- scalp engine */

    /**
     * Every OPEN scalp position in $mode, oldest first (so the loop manages the longest-held
     * one first and `scalp_max_hold_sec` cannot be starved by newer entries).
     *
     * @return array positions rows
     */
    public function openScalpPositions(?string $mode = null): array
    {
        $params = [];
        $sql    = "SELECT * FROM positions WHERE status = 'OPEN' AND engine = 'scalp'"
                . self::modeClause($mode, $params) . ' ORDER BY id ASC';
        return $this->fetchAll('positions', $sql, $params);
    }

    /**
     * Closed scalp positions for one symbol in $mode — the raw material for
     * Scalper::symbolLedger(). Returns the rows, not a total: the ledger is DERIVED on every
     * read (DESIGN-SCALP.md §7) so it can never drift out of step with the trades it describes.
     *
     * @return array positions rows
     */
    public function scalpPositions(?string $symbol = null, ?string $mode = null, ?string $status = null): array
    {
        $params = [];
        $sql    = "SELECT * FROM positions WHERE engine = 'scalp'";
        if ($symbol !== null && trim($symbol) !== '') {
            $sql .= ' AND symbol = ?';
            $params[] = strtoupper(trim($symbol));
        }
        if ($status !== null && trim($status) !== '') {
            $sql .= ' AND status = ?';
            $params[] = strtoupper(trim($status));
        }
        $sql .= self::modeClause($mode, $params) . ' ORDER BY id ASC';
        return $this->fetchAll('positions', $sql, $params);
    }

    /**
     * Distinct symbols that have ever carried a scalp position in $mode. Used by the scoreboard
     * so a symbol that has dropped out of the universe still shows the money it made or lost.
     *
     * @return array list of uppercase symbols
     */
    public function scalpSymbols(?string $mode = null): array
    {
        $params = [];
        $sql    = "SELECT DISTINCT symbol FROM positions WHERE engine = 'scalp'"
                . self::modeClause($mode, $params) . ' ORDER BY symbol ASC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (is_array($r) && isset($r['symbol'])) {
                $out[] = strtoupper(trim((string) $r['symbol']));
            }
        }
        return $out;
    }

    /** Scalp entries opened at or after $sinceIso, in $mode — the hourly runaway cap (§6). */
    public function scalpEntriesSince(string $sinceIso, ?string $mode = null): int
    {
        $params = [$sinceIso];
        $sql    = "SELECT COUNT(*) FROM positions WHERE engine = 'scalp' AND opened_at >= ?"
                . self::modeClause($mode, $params);
        return (int) $this->scalar($sql, $params);
    }

    /**
     * Replace the movers board wholesale (DESIGN-SCALP.md §3/§8).
     *
     * Same contract as replaceScanner(): one transaction, DELETE then INSERT, so a reader never
     * sees a half-refreshed board, and a failure leaves the previous board intact rather than an
     * empty table. `gates` is JSON-encoded here when the caller passed an array.
     *
     * @param array $rows rows shaped like Movers::rank() output
     * @return int rows stored
     */
    public function replaceMovers(array $rows): int
    {
        $now   = Util::nowIso();
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $row['symbol']      = $symbol;
            $row['ts']          = (isset($row['ts']) && $row['ts'] !== '') ? (string) $row['ts'] : $now;
            $row['eligible']    = empty($row['eligible']) ? 0 : 1;
            $row['in_universe'] = empty($row['in_universe']) ? 0 : 1;
            if (isset($row['gates'])) {
                if (is_array($row['gates'])) {
                    $json = json_encode(array_values($row['gates']), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                    $row['gates'] = $json === false ? '[]' : $json;
                } else {
                    $row['gates'] = (string) $row['gates'];
                }
            }
            $clean[$symbol] = $row;
        }

        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->exec('DELETE FROM movers');
            foreach ($clean as $row) {
                $this->insertRow('movers', $row);
            }
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return count($clean);
    }

    /**
     * Movers rows, best first. Ordered by the stored `rank` (which Movers::rank() assigned in
     * its own total order) rather than by score, so the panel shows exactly the ranking the
     * engine used - a gated row scores 0 and must not tie its way above an eligible one.
     * `gates` stays the stored JSON; `gates_list` carries it decoded.
     *
     * @param bool $universeOnly only the rows actually being traded
     */
    public function moversRows(int $limit = 10, bool $universeOnly = false): array
    {
        $sql = 'SELECT * FROM movers WHERE 1 = 1';
        if ($universeOnly) {
            $sql .= ' AND in_universe = 1';
        }
        $sql .= ' ORDER BY rank ASC, symbol ASC LIMIT ?';
        $lim  = $limit > 0 ? $limit : 10;
        $rows = $this->fetchAll('movers', $sql, [$lim]);
        foreach ($rows as $i => $r) {
            $rows[$i]['gates_list'] = self::decodeGates($r['gates'] ?? null);
        }
        return $rows;
    }

    /**
     * **MINUTES** since the movers board was last written, or null when it never was.
     *
     * Note the unit, because scannerAge() a few methods down returns SECONDS. That is
     * deliberate rather than an oversight: the board's own refresh interval is configured in
     * minutes (`scalp_universe_refresh_min`, default 10), so minutes is the unit every caller
     * wants to compare it against, while the scanner's hourly cadence is reported in seconds.
     * A caller that needs seconds must not assume; it should read this line.
     */
    public function moversAge(): ?int
    {
        $ts = $this->scalar('SELECT MAX(ts) FROM movers');
        if ($ts === null || trim((string) $ts) === '') {
            return null;
        }
        $t = Util::isoToTs((string) $ts);
        return $t === null ? null : (int) max(0, (int) round((time() - $t) / 60));
    }

    /** @return array list of uppercase symbols currently in the traded universe, best first */
    public function moversUniverse(): array
    {
        $st = $this->pdo->prepare('SELECT symbol FROM movers WHERE in_universe = 1 ORDER BY rank ASC, symbol ASC');
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if (is_array($r) && isset($r['symbol'])) {
                $out[] = strtoupper(trim((string) $r['symbol']));
            }
        }
        return $out;
    }

    /** @param mixed $raw */
    private static function decodeGates($raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return [];
        }
        $d = json_decode($s, true);
        return is_array($d) ? array_values($d) : [];
    }

    /**
     * Open a scalp run row and return its id (DESIGN-SCALP.md §8). The raw insert: it defaults
     * `started_at` and `mode` only. `scalpRunStart()` wraps it and additionally defaults and
     * sanitises every counter and money column; prefer that one unless you are passing a row
     * you have already normalised.
     *
     * `mode` is defaulted because the column is NOT NULL with no SQL default, so an omitted
     * mode would fail the INSERT rather than record an unattributed run.
     */
    public function insertScalpRun(array $row): int
    {
        if (!isset($row['started_at']) || trim((string) $row['started_at']) === '') {
            $row['started_at'] = Util::nowIso();
        }
        if (!isset($row['mode'])) {
            $row['mode'] = '';
        }
        return $this->insertRow('scalp_runs', $row);
    }

    /**
     * Write fields onto a scalp run row. The raw update: only `scalp_runs` columns are kept
     * (insertRow/updateRow whitelist them) but nothing is defaulted or range-checked.
     * `scalpRunEnd()` wraps it, stamps `ended_at`, keeps a non-finite number out of the row and
     * refuses to rewrite `id` or `started_at`.
     */
    public function updateScalpRun(int $id, array $fields): void
    {
        $this->updateRow('scalp_runs', 'id', $id, $fields);
    }

    /** @return array the most recent scalp runs, newest first */
    public function scalpRuns(int $limit = 20, ?string $mode = null): array
    {
        $params = [];
        $sql    = 'SELECT * FROM scalp_runs WHERE 1 = 1' . self::modeClause($mode, $params)
                . ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit > 0 ? $limit : 20;
        return $this->fetchAll('scalp_runs', $sql, $params);
    }

    public function lastScalpRun(?string $mode = null): ?array
    {
        $rows = $this->scalpRuns(1, $mode);
        return $rows === [] ? null : $rows[0];
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

    /** Same idea as modeClause() for the engine column (engine_orders / lots / cycles). */
    private static function engineClause(?string $engine, array &$params): string
    {
        if ($engine === null || $engine === '') {
            return '';
        }
        $params[] = $engine;
        return ' AND engine = ?';
    }

    /**
     * Realised PnL at or after $sinceIso: sum of positions.pnl_usdt of positions closed
     * then (DESIGN.md §4); null $mode = every mode. The engines' round trips live in
     * cycles.pnl_usdt — Risk adds cyclePnl() on top for the daily / weekly caps
     * (DESIGN-ENGINES §5) so the caps govern all three engines.
     */
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

    /**
     * Keep signals/equity/logs for $days days, and observations for
     * $observationDays (much longer: the learning window is 90 days by default
     * and the evidence is worthless once it has been deleted).
     *
     * `engine_orders` is pruned on the same $days cutoff, but only for rows that
     * are SPENT: terminal status, nothing ever filled on them, and no lot points
     * back at them. A pmm install writes a couple of those a minute and they are
     * pure noise once resolved - while a row that filled is the provenance of a
     * lot and a cycle, so it is never deleted here. Nothing live is touched:
     * ENGINE_LIVE_STATUSES rows may still be resting on the exchange.
     */
    public function prune(int $days = 30, int $observationDays = 365): void
    {
        $cutoff = Util::nowIso(time() - max(1, $days) * 86400);
        $obsCutoff = Util::nowIso(time() - max(max(1, $days), $observationDays) * 86400);
        $this->pdo->prepare('DELETE FROM signals WHERE created_at < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM equity WHERE ts < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM sleeve_equity WHERE ts < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM logs WHERE ts < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE last_at IS NOT NULL AND last_at < ?')->execute([$cutoff]);
        $this->pdo->prepare('DELETE FROM observations WHERE ts < ?')->execute([$obsCutoff]);
        // One scalp_runs row per cron tick is ~1440 a day (DESIGN-SCALP.md §4), so the run log
        // is tail data on the same horizon as the other tails. The per-symbol scoreboard is
        // derived from `positions`, never from these rows, so pruning them loses no P&L.
        $this->pdo->prepare('DELETE FROM scalp_runs WHERE started_at < ?')->execute([$cutoff]);

        $in     = implode(', ', array_fill(0, count(self::ENGINE_LIVE_STATUSES), '?'));
        $params = array_merge([$cutoff], self::ENGINE_LIVE_STATUSES);
        $this->pdo->prepare(
            'DELETE FROM engine_orders WHERE created_at < ? AND filled_qty <= 0 AND status NOT IN (' . $in . ')'
            . ' AND client_id NOT IN (SELECT client_id FROM lots WHERE client_id IS NOT NULL)'
        )->execute($params);
    }

    /* --------------------------------------------------------------- engines */
    /*
     * Grid / pure-market-making bookkeeping (docs/DESIGN-ENGINES.md §5).
     *
     *   engine_orders  every limit order the engines put on the book
     *   lots           FIFO inventory produced by engine BUY fills
     *   cycles         realised buy -> sell round trips
     *
     * `SENDING` is the transient status an engine order carries between the row
     * being written and the exchange answering (same idempotency discipline as
     * the `orders` table); `UNKNOWN` is a send whose outcome was never seen.
     * Both count as live: they may be resting on the exchange.
     */

    /** Engine-order statuses that may still be resting on the exchange. */
    const ENGINE_LIVE_STATUSES = ['SENDING', 'NEW', 'PARTIALLY_FILLED', 'UNKNOWN'];

    /** Quantities below this are treated as zero when consuming inventory. */
    const LOT_EPSILON = 1.0e-12;

    /** Engine orders with any of $statuses (empty = every status), newest first; null/'' $mode = every mode. */
    public function engineOrders(array $statuses = [], ?string $mode = null): array
    {
        $sql    = 'SELECT * FROM engine_orders WHERE 1 = 1';
        $params = [];
        $clean  = [];
        foreach ($statuses as $s) {
            $s = strtoupper(trim((string) $s));
            if ($s !== '') {
                $clean[] = $s;
            }
        }
        if ($clean !== []) {
            $sql .= ' AND status IN (' . implode(', ', array_fill(0, count($clean), '?')) . ')';
            $params = $clean;
        }
        $sql .= self::modeClause($mode, $params) . ' ORDER BY created_at DESC, client_id DESC';
        return $this->fetchAll('engine_orders', $sql, $params);
    }

    public function engineOrder(string $clientId): ?array
    {
        return $this->fetchOne('engine_orders', 'SELECT * FROM engine_orders WHERE client_id = ?', [$clientId]);
    }

    /** Insert one engine order (`client_id` is the primary key; defaults: status SENDING, created_at now). */
    public function insertEngineOrder(array $row): void
    {
        if (!isset($row['created_at'])) {
            $row['created_at'] = Util::nowIso();
        }
        if (!isset($row['status'])) {
            $row['status'] = 'SENDING';
        }
        $this->insertRow('engine_orders', $row);
    }

    public function updateEngineOrder(string $clientId, array $fields): void
    {
        if (!isset($fields['updated_at'])) {
            $fields['updated_at'] = Util::nowIso();
        }
        $this->updateRow('engine_orders', 'client_id', $clientId, $fields);
    }

    /**
     * Live engine orders for one symbol (statuses in ENGINE_LIVE_STATUSES), oldest first.
     * null/'' $mode = every mode (panel view); the engines always pass their configured
     * mode so a paper tick can never reconcile - and abandon - a live order (DESIGN.md §4).
     */
    public function openEngineOrders(string $symbol, ?string $mode = null): array
    {
        $in     = implode(', ', array_fill(0, count(self::ENGINE_LIVE_STATUSES), '?'));
        $params = array_merge([$symbol], self::ENGINE_LIVE_STATUSES);
        $sql    = 'SELECT * FROM engine_orders WHERE symbol = ? AND status IN (' . $in . ')'
                . self::modeClause($mode, $params)
                . ' ORDER BY created_at ASC, client_id ASC';
        return $this->fetchAll('engine_orders', $sql, $params);
    }

    /* ------------------------------------------------------------------ lots */

    /** Insert a FIFO inventory lot; `remaining` defaults to `qty`, `created_at` to now. */
    public function insertLot(array $row): int
    {
        if (!isset($row['created_at'])) {
            $row['created_at'] = Util::nowIso();
        }
        if (!isset($row['remaining']) && isset($row['qty'])) {
            $row['remaining'] = $row['qty'];
        }
        return $this->insertRow('lots', $row);
    }

    /** Lots with inventory left for $symbol, FIFO order (oldest first). */
    public function openLots(string $symbol, ?string $mode = null, ?string $engine = null): array
    {
        $params = [$symbol, self::LOT_EPSILON];
        $sql    = 'SELECT * FROM lots WHERE symbol = ? AND remaining > ?'
                . self::modeClause($mode, $params)
                . self::engineClause($engine, $params)
                . ' ORDER BY created_at ASC, id ASC';
        return $this->fetchAll('lots', $sql, $params);
    }

    /**
     * Consume $qty of base inventory for $symbol, strict FIFO (oldest lot first),
     * decrementing `remaining` as it goes.
     *
     * Never consumes more than is available: when the inventory is short the
     * method returns the slices it could fill and the caller is expected to
     * notice (sum the slice quantities) rather than being handed a fabricated lot.
     *
     * @return array [['lot' => row (with `remaining` already decremented),
     *                 'qty' => float consumed from that lot,
     *                 'cost' => float qty x lot.price], ...]
     */
    public function consumeLots(string $symbol, float $qty, ?string $mode = null, ?string $engine = null): array
    {
        return $this->consumeFrom($this->openLots($symbol, $mode, $engine), $qty);
    }

    /**
     * Same as consumeLots(), restricted to the lots of one grid rung (oldest first).
     *
     * A grid sell is placed against one specific lot (DESIGN-ENGINES §7.4: "for each
     * lot with no live sell"), so its fill must consume THAT lot: plain FIFO would
     * burn an older, dearer lot whose own sell is still resting, leaving the engine
     * quoting more base than it holds and pricing the cycle against the wrong basis.
     *
     * @return array same slice shape as consumeLots()
     */
    public function consumeLotsAtLevel(string $symbol, float $qty, ?string $mode, ?string $engine, int $level): array
    {
        $params = [$symbol, self::LOT_EPSILON];
        $sql    = 'SELECT * FROM lots WHERE symbol = ? AND remaining > ?'
                . self::modeClause($mode, $params)
                . self::engineClause($engine, $params)
                . ' AND level = ?'
                . ' ORDER BY created_at ASC, id ASC';
        $params[] = $level;
        return $this->consumeFrom($this->fetchAll('lots', $sql, $params), $qty);
    }

    /** Take $qty out of $lots in the order given, decrementing `remaining`. */
    private function consumeFrom(array $lots, float $qty): array
    {
        $out = [];
        if (!is_finite($qty) || $qty <= self::LOT_EPSILON) {
            return $out;
        }
        $left = $qty;
        $st   = $this->pdo->prepare('UPDATE lots SET remaining = ? WHERE id = ?');
        foreach ($lots as $lot) {
            if ($left <= self::LOT_EPSILON) {
                break;
            }
            $rem = (float) $lot['remaining'];
            if ($rem <= self::LOT_EPSILON) {
                continue;
            }
            $take = $rem <= $left ? $rem : $left;
            $newRemaining = $rem - $take;
            if ($newRemaining < self::LOT_EPSILON) {
                $newRemaining = 0.0;
            }
            $st->execute([$newRemaining, (int) $lot['id']]);
            $lot['remaining'] = $newRemaining;
            $out[] = [
                'lot'  => $lot,
                'qty'  => $take,
                'cost' => $take * (float) $lot['price'],
            ];
            $left -= $take;
        }
        return $out;
    }

    /* ---------------------------------------------------------------- cycles */

    /** Insert a realised round trip; `closed_at` defaults to now. */
    public function insertCycle(array $row): int
    {
        if (!isset($row['closed_at'])) {
            $row['closed_at'] = Util::nowIso();
        }
        return $this->insertRow('cycles', $row);
    }

    /** Newest first. */
    public function cycles(int $limit = 30, ?string $mode = null, ?string $engine = null): array
    {
        $params = [];
        $sql    = 'SELECT * FROM cycles WHERE 1 = 1'
                . self::modeClause($mode, $params)
                . self::engineClause($engine, $params)
                . ' ORDER BY closed_at DESC, id DESC LIMIT ?';
        $params[] = max(1, $limit);
        return $this->fetchAll('cycles', $sql, $params);
    }

    /**
     * Cycles booked after $afterId, oldest first: the forward page the observation sweep
     * walks, so a burst larger than one page is carried over instead of jumped past.
     */
    public function cyclesSince(int $afterId, ?string $mode = null, int $limit = 200): array
    {
        $params = [max(0, $afterId)];
        $sql    = 'SELECT * FROM cycles WHERE id > ?'
                . self::modeClause($mode, $params)
                . ' ORDER BY id ASC LIMIT ?';
        $params[] = max(1, $limit);
        return $this->fetchAll('cycles', $sql, $params);
    }

    /**
     * Sum of cycles.pnl_usdt closed at or after $since (null/'' = all history);
     * null/'' $mode = every mode, exactly like the other analytics methods.
     */
    public function cyclePnl(?string $since = null, ?string $mode = null, ?string $engine = null): float
    {
        $params = [];
        $sql    = 'SELECT COALESCE(SUM(pnl_usdt), 0) FROM cycles WHERE 1 = 1';
        if ($since !== null && $since !== '') {
            $sql     .= ' AND closed_at >= ?';
            $params[] = $since;
        }
        $sql .= self::modeClause($mode, $params) . self::engineClause($engine, $params);
        return (float) $this->scalar($sql, $params);
    }

    /**
     * Engine KPIs: cycles, pnl, fees, wins, losses, win_rate (percent of decided
     * cycles), inventory_qty and inventory_cost (open lots at their cost basis).
     * null/'' scopes mean "every mode" / "every engine".
     */
    public function engineStats(?string $mode = null, ?string $engine = null): array
    {
        $params = [];
        $sql    = 'SELECT COUNT(*) AS cycles, COALESCE(SUM(pnl_usdt), 0) AS pnl,
                    COALESCE(SUM(fee_usdt), 0) AS fees,
                    COALESCE(SUM(CASE WHEN pnl_usdt > 0 THEN 1 ELSE 0 END), 0) AS wins,
                    COALESCE(SUM(CASE WHEN pnl_usdt < 0 THEN 1 ELSE 0 END), 0) AS losses
             FROM cycles WHERE 1 = 1'
                . self::modeClause($mode, $params)
                . self::engineClause($engine, $params);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();

        $invParams = [self::LOT_EPSILON];
        $invSql    = 'SELECT COALESCE(SUM(remaining), 0) AS qty, COALESCE(SUM(remaining * price), 0) AS cost
             FROM lots WHERE remaining > ?'
                . self::modeClause($mode, $invParams)
                . self::engineClause($engine, $invParams);
        $ist = $this->pdo->prepare($invSql);
        $ist->execute($invParams);
        $inv = $ist->fetch();

        $wins    = (int) ($r['wins'] ?? 0);
        $losses  = (int) ($r['losses'] ?? 0);
        $decided = $wins + $losses;

        return [
            'cycles'         => (int) ($r['cycles'] ?? 0),
            'pnl'            => (float) ($r['pnl'] ?? 0),
            'fees'           => (float) ($r['fees'] ?? 0),
            'wins'           => $wins,
            'losses'         => $losses,
            'win_rate'       => $decided > 0 ? $wins / $decided * 100.0 : 0.0,
            'inventory_qty'  => (float) ($inv['qty'] ?? 0),
            'inventory_cost' => (float) ($inv['cost'] ?? 0),
        ];
    }

    /* ------------------------------------------------- sleeves and scanner */
    /*
     * Portfolio mode (docs/DESIGN-PORTFOLIO.md §4).
     *
     *   sleeve_equity  one equity sample per sleeve per tick, for the overlaid
     *                  per-sleeve sparkline and the "best method so far" line
     *   scanner        the latest volatility ranking, replaced wholesale by
     *                  Scanner::refresh(); rejected rows are KEPT with their
     *                  failed `gates` so the panel can explain the rejection
     *
     * Both tables are additive: with `portfolio_enabled = false` nothing ever
     * writes to them and every other query behaves exactly as before.
     */

    /**
     * Append one equity sample for one sleeve. `$row` may be a `Sleeve::state()`
     * array with `mode` added: only the sleeve_equity columns are kept, `ts`
     * defaults to now and any missing / non-finite number is stored as 0.
     *
     * @param array $row mode, engine, equity, budget, realised, unrealised,
     *                   inventory_value, reserved (plus an optional ts)
     */
    public function insertSleeveEquity(array $row): int
    {
        $row['ts']     = (isset($row['ts']) && $row['ts'] !== '') ? (string) $row['ts'] : Util::nowIso();
        $row['mode']   = isset($row['mode']) ? (string) $row['mode'] : '';
        $row['engine'] = isset($row['engine']) ? (string) $row['engine'] : '';
        foreach (['equity', 'budget', 'realised', 'unrealised', 'inventory_value', 'reserved'] as $col) {
            $v = (isset($row[$col]) && is_numeric($row[$col])) ? (float) $row[$col] : 0.0;
            $row[$col] = is_finite($v) ? $v : 0.0;
        }
        return $this->insertRow('sleeve_equity', $row);
    }

    /**
     * Equity series for one sleeve, oldest → newest, at most $limit rows (the
     * most recent ones). '' / null $engine means every sleeve, and the optional
     * trailing $mode scopes the query the same way the analytics methods do.
     */
    public function sleeveEquitySeries(string $engine, int $limit = 288, ?string $mode = null): array
    {
        $params = [];
        $sql    = 'SELECT * FROM sleeve_equity WHERE 1 = 1'
                . self::engineClause($engine, $params)
                . self::modeClause($mode, $params)
                . ' ORDER BY id DESC LIMIT ?';
        $params[] = max(1, $limit);
        return array_reverse($this->fetchAll('sleeve_equity', $sql, $params));
    }

    /**
     * Replace the whole scanner table with $rows (one row per symbol; the last
     * row wins on a duplicate symbol). `gates` may be given as an array and is
     * stored as JSON; `eligible` is coerced to 0/1; `ts` defaults to now.
     * Rows without a symbol are ignored.
     *
     * @return int number of rows stored
     */
    public function replaceScanner(array $rows): int
    {
        $now   = Util::nowIso();
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $row['symbol']   = $symbol;
            $row['ts']       = (isset($row['ts']) && $row['ts'] !== '') ? (string) $row['ts'] : $now;
            $row['eligible'] = empty($row['eligible']) ? 0 : 1;
            if (isset($row['gates'])) {
                if (is_array($row['gates'])) {
                    $json = json_encode(array_values($row['gates']), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                    $row['gates'] = $json === false ? '[]' : $json;
                } else {
                    $row['gates'] = (string) $row['gates'];
                }
            }
            $clean[$symbol] = $row;
        }

        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->exec('DELETE FROM scanner');
            foreach ($clean as $row) {
                $this->insertRow('scanner', $row);
            }
            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return count($clean);
    }

    /**
     * Scanner rows, at most $limit, in the same total order as
     * Scanner::compareRows: eligible first, then score (NULL scores last),
     * then 24 h quote volume, then symbol. Ordering by score alone would let a
     * gated row (score forced to 0) outrank an eligible one that also scores 0.
     * `gates` stays the stored JSON string; `gates_list` carries it decoded.
     */
    public function scannerRows(int $limit = 10, bool $eligibleOnly = false): array
    {
        $sql = 'SELECT * FROM scanner WHERE 1 = 1';
        if ($eligibleOnly) {
            $sql .= ' AND eligible = 1';
        }
        $sql .= ' ORDER BY eligible DESC, score DESC, quote_vol DESC, symbol ASC LIMIT ?';
        $rows = $this->fetchAll('scanner', $sql, [max(1, $limit)]);
        foreach ($rows as $i => $r) {
            $rows[$i]['gates_list'] = self::decodeReasons((string) ($r['gates'] ?? ''));
        }
        return $rows;
    }

    /** Age in seconds of the newest scanner row, or null when the table is empty. */
    public function scannerAge(): ?int
    {
        $ts = $this->scalar('SELECT MAX(ts) FROM scanner');
        if ($ts === null || (string) $ts === '') {
            return null;
        }
        $t = Util::isoToTs((string) $ts);
        if ($t === null) {
            return null;
        }
        $age = time() - $t;
        return $age < 0 ? 0 : $age;
    }

    /* ------------------------------------------------ scalp scoreboard (§7) */
    /*
     * The rest of the movers / scalp_runs storage lives in the "scalp engine" section above.
     * What is here is the part DESIGN-SCALP.md §7 is strict about: the per-symbol scoreboard,
     * which is DERIVED from `positions` and never stored as a running total.
     */

    /**
     * Movers rows ordered the way Movers::compareRows orders them: eligible first, then score,
     * then trade count, then 24 h quote volume, then symbol.
     *
     * This is the sibling of moversRows(), and the difference matters:
     *   moversRows()  orders by the STORED `rank` and can filter to the traded universe - what
     *                 the engine is actually doing.
     *   moversTop()   re-derives the ranking order from the stored metrics and can filter to the
     *                 rows that passed every gate - what the board WOULD trade. It is also the
     *                 safe one when `rank` may be NULL (the §8 schema allows it), because
     *                 `ORDER BY rank ASC` puts NULLs FIRST in SQLite, which would put a rankless
     *                 row at the head of the board.
     *
     * A gated row scores 0, so `eligible DESC` leads: without it a rejected coin ties its way
     * above an eligible one that also happens to score 0.
     *
     * `gates` stays the stored JSON string; `gates_list` carries it decoded.
     *
     * @param bool $eligibleOnly true returns only rows that passed every gate
     */
    public function moversTop(int $limit = 10, bool $eligibleOnly = false): array
    {
        $sql = 'SELECT * FROM movers WHERE 1 = 1';
        if ($eligibleOnly) {
            $sql .= ' AND eligible = 1';
        }
        $sql .= ' ORDER BY eligible DESC, score DESC, trade_count DESC, quote_vol DESC, symbol ASC LIMIT ?';
        $rows = $this->fetchAll('movers', $sql, [$limit > 0 ? $limit : 10]);
        foreach ($rows as $i => $r) {
            $rows[$i]['gates_list'] = self::decodeGates(isset($r['gates']) ? $r['gates'] : null);
        }
        return $rows;
    }

    /**
     * Open a scalp run and return its id, with every field defaulted and sanitised:
     * `started_at` now, `mode` '', the counters 0 and the money columns finite. A run that dies
     * mid-loop therefore still leaves a row with a start time and no `ended_at`, which is how
     * the panel tells "running" from "finished".
     *
     * Thin wrapper over insertScalpRun() so there is exactly ONE insert path into `scalp_runs`.
     *
     * @param array $row mode, plus optional started_at, polls, entries, exits,
     *                   gross_usdt, fees_usdt, net_usdt, note
     */
    public function scalpRunStart(array $row): int
    {
        $row['mode'] = isset($row['mode']) ? (string) $row['mode'] : '';
        foreach (['polls', 'entries', 'exits'] as $col) {
            $row[$col] = (isset($row[$col]) && is_numeric($row[$col])) ? (int) $row[$col] : 0;
        }
        foreach (['gross_usdt', 'fees_usdt', 'net_usdt'] as $col) {
            $row[$col] = self::finite(isset($row[$col]) ? $row[$col] : 0.0);
        }
        unset($row['id']);
        return $this->insertScalpRun($row);
    }

    /**
     * Close a scalp run: write the counters and the §2 gross / fees / net split, stamping
     * `ended_at` with now unless $fields names one.
     *
     * `id` and `started_at` are stripped: a summary array can be handed over whole without
     * rewriting which run it is or when it began.
     *
     * @param array $fields polls, entries, exits, gross_usdt, fees_usdt, net_usdt, note, ended_at
     */
    public function scalpRunEnd(int $id, array $fields): void
    {
        if ($id <= 0) {
            return;
        }
        if (!isset($fields['ended_at']) || trim((string) $fields['ended_at']) === '') {
            $fields['ended_at'] = Util::nowIso();
        } else {
            $fields['ended_at'] = (string) $fields['ended_at'];
        }
        foreach (['polls', 'entries', 'exits'] as $col) {
            if (array_key_exists($col, $fields)) {
                $fields[$col] = is_numeric($fields[$col]) ? (int) $fields[$col] : 0;
            }
        }
        foreach (['gross_usdt', 'fees_usdt', 'net_usdt'] as $col) {
            if (array_key_exists($col, $fields)) {
                $fields[$col] = self::finite($fields[$col]);
            }
        }
        unset($fields['id'], $fields['started_at']);
        $this->updateScalpRun($id, $fields);
    }

    /**
     * Per-symbol scoreboard, DERIVED from `positions` (DESIGN-SCALP.md §7). This is the answer
     * to the user's actual question - *which coin is making money* - and it is computed on every
     * read, never stored: a running total would be a second source of truth that drifts the
     * first time a close is booked twice or a row is edited by hand. A derivation cannot drift,
     * because there is nothing to drift from.
     *
     * Returned as [SYMBOL => [...]], ordered by `net` descending then symbol, which is the
     * scoreboard order the panel renders:
     *
     *   trades    completed round trips: CLOSED positions carrying a pnl. The SAMPLE SIZE that
     *             has to sit beside every rate, per §2 - a 100 % win rate on two trades is noise.
     *   wins      trades with pnl > 0            losses  trades with pnl < 0
     *             (a dead-flat trade counts in `trades` and in neither, as Db::stats() does)
     *   gross     SUM(gross_usdt) - P&L BEFORE fees: "is there any directional edge at all?"
     *   fees      SUM(fees_usdt)  - what it cost to find out
     *   net       SUM(pnl_usdt)   - the answer, and the same number the survival layer caps on
     *   open      positions still on the book (OPEN and STUCK)
     *   deployed  SUM(entry_quote) of those, i.e. the capital this symbol currently has at work
     *
     * `net` comes from `pnl_usdt` rather than `gross - fees` on purpose: `pnl_usdt` is what the
     * rest of the system already treats as realised P&L, so the scoreboard cannot quietly
     * disagree with the equity curve or the daily cap. For a position that recorded the split
     * the three reconcile; a position written before `gross_usdt`/`fees_usdt` existed
     * contributes 0 to `gross` and `fees` while still contributing its `net` - which SHOWS the
     * gap instead of inventing a gross that was never measured. Never present `gross` as an
     * edge without `trades` beside it.
     *
     * STUCK counts as deployed, not as closed: its capital really is still committed (DESIGN.md
     * §10 blocks entries on a STUCK position exactly like an OPEN one), so letting a symbol's
     * budget spend it again would double-count the money.
     *
     * @param string|null $mode   configured mode, or null/'' for every mode
     * @param string|null $engine which engine's positions to count; defaults to the scalp
     *                            engine, '' / null widens it to every engine in that mode
     * @return array [SYMBOL => ['trades'=>int,'wins'=>int,'losses'=>int,'gross'=>float,
     *                           'fees'=>float,'net'=>float,'open'=>int,'deployed'=>float]]
     */
    public function scalpSymbolStats(?string $mode = null, ?string $engine = 'scalp'): array
    {
        // COALESCE so a row written before the `engine` column existed still reads as the
        // signal engine's rather than matching every filter.
        $engineSql = '';
        $engineBind = null;
        if ($engine !== null && trim($engine) !== '') {
            $engineSql  = " AND COALESCE(engine, 'signal') = ?";
            $engineBind = trim($engine);
        }

        $out = [];

        $params = [];
        $sql    = "SELECT symbol, COUNT(*) AS trades,
                    COALESCE(SUM(CASE WHEN pnl_usdt > 0 THEN 1 ELSE 0 END), 0) AS wins,
                    COALESCE(SUM(CASE WHEN pnl_usdt < 0 THEN 1 ELSE 0 END), 0) AS losses,
                    COALESCE(SUM(gross_usdt), 0) AS gross,
                    COALESCE(SUM(fees_usdt), 0)  AS fees,
                    COALESCE(SUM(pnl_usdt), 0)   AS net
             FROM positions WHERE status = 'CLOSED' AND pnl_usdt IS NOT NULL"
                . self::modeClause($mode, $params);
        if ($engineBind !== null) {
            $sql .= $engineSql;
            $params[] = $engineBind;
        }
        $sql .= ' GROUP BY symbol';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $symbol = strtoupper(trim((string) ($r['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $out[$symbol] = [
                'trades'   => (int) ($r['trades'] ?? 0),
                'wins'     => (int) ($r['wins'] ?? 0),
                'losses'   => (int) ($r['losses'] ?? 0),
                'gross'    => self::finite(isset($r['gross']) ? $r['gross'] : 0.0),
                'fees'     => self::finite(isset($r['fees']) ? $r['fees'] : 0.0),
                'net'      => self::finite(isset($r['net']) ? $r['net'] : 0.0),
                'open'     => 0,
                'deployed' => 0.0,
            ];
        }

        $oParams = [];
        $openSql = "SELECT symbol, COUNT(*) AS open_n, COALESCE(SUM(entry_quote), 0) AS deployed
             FROM positions WHERE status IN ('OPEN', 'STUCK')"
                . self::modeClause($mode, $oParams);
        if ($engineBind !== null) {
            $openSql .= $engineSql;
            $oParams[] = $engineBind;
        }
        $openSql .= ' GROUP BY symbol';
        $ost = $this->pdo->prepare($openSql);
        $ost->execute($oParams);
        foreach ($ost->fetchAll() as $r) {
            $symbol = strtoupper(trim((string) ($r['symbol'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            if (!isset($out[$symbol])) {
                $out[$symbol] = [
                    'trades' => 0, 'wins' => 0, 'losses' => 0,
                    'gross'  => 0.0, 'fees' => 0.0, 'net' => 0.0,
                    'open'   => 0, 'deployed' => 0.0,
                ];
            }
            $out[$symbol]['open']     = (int) ($r['open_n'] ?? 0);
            $out[$symbol]['deployed'] = self::finite(isset($r['deployed']) ? $r['deployed'] : 0.0);
        }

        // Scoreboard order: best net first, ties broken by symbol so the board is reproducible.
        $nets = [];
        foreach ($out as $symbol => $row) {
            $nets[$symbol] = (float) $row['net'];
        }
        uksort($out, static function (string $a, string $b) use ($nets): int {
            if ($nets[$a] !== $nets[$b]) {
                return $nets[$b] <=> $nets[$a];
            }
            return strcmp($a, $b);
        });
        return $out;
    }

    /** A finite float, or 0.0. Keeps a NULL / NaN / INF out of a stored or reported number. */
    private static function finite($v): float
    {
        if (!is_numeric($v)) {
            return 0.0;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : 0.0;
    }

    /* --------------------------------------------------------- observations */
    /*
     * Learning capture (docs/DESIGN-LEARNING.md §2).
     *
     * One row per entry evaluation, WHETHER OR NOT IT TRADED: the skipped rows
     * are the control group, without which the bot would only ever learn from
     * the trades it happened to take. `features` is a flat JSON object of
     * numeric values only, so bucketing is uniform (lib/Learn.php).
     *
     * Resolution is one-way and happens once: `resolveObservation()` refuses to
     * touch a row that already carries an outcome unless it is forced, so a
     * repeated close booking (or a re-run reconcile) cannot count a trade twice.
     * `skipped` rows resolve to `not_taken` the moment they are written; they
     * carry no PnL and are never counted as wins or losses.
     *
     * This table is read-only evidence: nothing in Learn writes back to it, and
     * no other table's behaviour depends on it.
     */

    /** The only outcomes an observation may carry (NULL while it is still open). */
    const OBSERVATION_OUTCOMES = ['win', 'loss', 'flat', 'not_taken'];

    /** The only fields resolveObservation() may write; the captured conditions are immutable. */
    const OBSERVATION_RESOLVE_FIELDS = [
        'outcome', 'pnl_usdt', 'pnl_pct', 'exit_reason', 'held_minutes', 'resolved_at',
        'position_id', 'cycle_id',
    ];

    /**
     * Insert one observation and return its id.
     *
     * Defaults: `ts` now, `engine` 'signal', `mode` '' (the caller normally passes
     * the configured mode), `features` '{}', and `decision` derived from whether a
     * position / cycle id was given. A `skipped` row without an explicit outcome is
     * stored already resolved as `not_taken`.
     *
     * @param array $row ts, mode, engine, symbol, decision, skip_reason, score,
     *                   threshold, features (array or JSON), position_id, cycle_id
     */
    public function insertObservation(array $row): int
    {
        $row['ts']     = (isset($row['ts']) && $row['ts'] !== '') ? (string) $row['ts'] : Util::nowIso();
        $row['mode']   = isset($row['mode']) ? (string) $row['mode'] : '';
        $row['engine'] = (isset($row['engine']) && (string) $row['engine'] !== '') ? (string) $row['engine'] : 'signal';
        $row['symbol'] = strtoupper(trim((string) ($row['symbol'] ?? '')));

        $decision = strtolower(trim((string) ($row['decision'] ?? '')));
        if ($decision !== 'entered' && $decision !== 'skipped') {
            $hasRef = (isset($row['position_id']) && $row['position_id'] !== '')
                   || (isset($row['cycle_id']) && $row['cycle_id'] !== '');
            $decision = $hasRef ? 'entered' : 'skipped';
        }
        $row['decision'] = $decision;
        $row['features'] = self::encodeFeatures($row['features'] ?? []);

        foreach (['score', 'threshold'] as $intCol) {
            if (isset($row[$intCol]) && !is_numeric($row[$intCol])) {
                unset($row[$intCol]);
            }
        }
        if ($decision === 'skipped' && !isset($row['outcome'])) {
            $row['outcome'] = 'not_taken';
            if (!isset($row['resolved_at'])) {
                $row['resolved_at'] = $row['ts'];
            }
        }
        if (isset($row['outcome'])) {
            $row['outcome'] = self::normaliseOutcome($row['outcome'], $decision);
        }
        return $this->insertRow('observations', $row);
    }

    /**
     * Resolve one observation: outcome, PnL, exit reason, held minutes, resolved_at.
     *
     * Idempotent by design (DESIGN-LEARNING.md §8 `learn-capture`): a row that
     * already carries an outcome is left untouched and the method returns false,
     * unless $force is true. When no outcome is given it is derived from
     * `pnl_usdt` (>0 win, <0 loss, 0 flat), and `resolved_at` defaults to now.
     *
     * @return bool true when the row was written
     */
    public function resolveObservation(int $id, array $fields, bool $force = false): bool
    {
        $row = $this->observation($id);
        if ($row === null) {
            return false;
        }
        if (!$force && $row['outcome'] !== null && (string) $row['outcome'] !== '') {
            return false;
        }
        $set = [];
        foreach (self::OBSERVATION_RESOLVE_FIELDS as $col) {
            if (array_key_exists($col, $fields)) {
                $set[$col] = $fields[$col];
            }
        }
        if ($set === []) {
            return false;
        }
        $decision = (string) ($row['decision'] ?? '');
        $outcome  = '';
        if (isset($set['outcome']) && trim((string) $set['outcome']) !== '') {
            $outcome = self::normaliseOutcome($set['outcome'], $decision);
        } elseif (isset($set['pnl_usdt']) && is_numeric($set['pnl_usdt'])) {
            $pnl     = (float) $set['pnl_usdt'];
            $outcome = $pnl > 0 ? 'win' : ($pnl < 0 ? 'loss' : 'flat');
        } elseif ($decision === 'skipped') {
            $outcome = 'not_taken';
        }
        if ($outcome !== '') {
            $set['outcome'] = $outcome;
            if (!isset($set['resolved_at']) || (string) $set['resolved_at'] === '') {
                $set['resolved_at'] = Util::nowIso();
            }
        } else {
            unset($set['outcome']);
        }
        foreach (['pnl_usdt', 'pnl_pct', 'held_minutes'] as $numCol) {
            if (isset($set[$numCol]) && !is_numeric($set[$numCol])) {
                unset($set[$numCol]);
            }
        }
        $this->updateRow('observations', 'id', $id, $set);
        return true;
    }

    public function observation(int $id): ?array
    {
        $row = $this->fetchOne('observations', 'SELECT * FROM observations WHERE id = ?', [$id]);
        return $row === null ? null : self::withFeatures($row);
    }

    /**
     * The observation attached to one position or cycle, or null.
     * $kind is 'position' or 'cycle'. An unresolved row wins over a resolved one
     * (that is the row a close wants to resolve), newest first otherwise.
     */
    public function observationFor(string $kind, int $refId): ?array
    {
        $kind = strtolower(trim($kind));
        if ($kind === 'positions') {
            $kind = 'position';
        } elseif ($kind === 'cycles') {
            $kind = 'cycle';
        }
        if ($kind !== 'position' && $kind !== 'cycle') {
            return null;
        }
        $col = $kind === 'position' ? 'position_id' : 'cycle_id';
        $row = $this->fetchOne(
            'observations',
            'SELECT * FROM observations WHERE ' . $col . ' = ? ORDER BY (outcome IS NULL) DESC, id DESC LIMIT 1',
            [$refId]
        );
        return $row === null ? null : self::withFeatures($row);
    }

    /**
     * Observations still waiting for an outcome (oldest first); null/'' $mode = every mode.
     *
     * The trailing $positionsOnly is additive and defaults to today's behaviour. When
     * true the query returns only rows a POSITION can resolve (`position_id IS NOT
     * NULL`), which is what Bot::obsResolvePositions() actually wants: without it the
     * scan drags every open cycle row of a busy grid through PHP to discard it.
     * Covered by idx_obs_open.
     */
    public function openObservations(?string $mode = null, int $limit = 1000, bool $positionsOnly = false): array
    {
        $params = [];
        $sql    = 'SELECT * FROM observations WHERE outcome IS NULL' . self::modeClause($mode, $params)
                . ($positionsOnly ? ' AND position_id IS NOT NULL' : '')
                . ' ORDER BY id ASC LIMIT ?';
        $params[] = max(1, $limit);
        return self::withFeaturesAll($this->fetchAll('observations', $sql, $params));
    }

    /**
     * Filtered observations, newest first by default.
     *
     * Supported $filter keys (all optional; every value may also be an array,
     * which becomes an IN (...) list):
     *   mode, engine, symbol, decision, outcome
     *   since / until          on `ts`        (ISO, inclusive / inclusive)
     *   resolved_since / resolved_before  on `resolved_at` (>= / <, exclusive upper
     *                          bound: that is what makes a walk-forward recompute
     *                          use only what was closed BEFORE its own instant)
     *   resolved               bool: true = outcome IS NOT NULL, false = IS NULL
     *   limit                  int, default 500
     *   order                  'desc' (default) or 'asc' on ts, id
     */
    public function observations(array $filter = []): array
    {
        $params = [];
        $sql    = 'SELECT * FROM observations WHERE 1 = 1';
        foreach (['mode', 'engine', 'symbol', 'decision', 'outcome'] as $col) {
            if (array_key_exists($col, $filter) && $filter[$col] !== null && $filter[$col] !== '') {
                $sql .= self::inClause($col, $filter[$col], $params);
            }
        }
        if (!empty($filter['since'])) {
            $sql .= ' AND ts >= ?';
            $params[] = (string) $filter['since'];
        }
        if (!empty($filter['until'])) {
            $sql .= ' AND ts <= ?';
            $params[] = (string) $filter['until'];
        }
        if (!empty($filter['resolved_since'])) {
            $sql .= ' AND resolved_at IS NOT NULL AND resolved_at >= ?';
            $params[] = (string) $filter['resolved_since'];
        }
        if (!empty($filter['resolved_before'])) {
            $sql .= ' AND resolved_at IS NOT NULL AND resolved_at < ?';
            $params[] = (string) $filter['resolved_before'];
        }
        if (array_key_exists('resolved', $filter) && $filter['resolved'] !== null) {
            $sql .= empty($filter['resolved']) ? ' AND outcome IS NULL' : ' AND outcome IS NOT NULL';
        }
        $order = isset($filter['order']) && strtolower((string) $filter['order']) === 'asc' ? 'ASC' : 'DESC';
        $limit = isset($filter['limit']) && is_numeric($filter['limit']) ? (int) $filter['limit'] : 500;
        $sql   .= ' ORDER BY ts ' . $order . ', id ' . $order . ' LIMIT ?';
        $params[] = max(1, $limit);
        return self::withFeaturesAll($this->fetchAll('observations', $sql, $params));
    }

    /**
     * Header counts for the Insights page: total rows, resolved rows, entered,
     * skipped, wins and losses. null/'' $mode = every mode.
     *
     * @return array{total:int,resolved:int,entered:int,skipped:int,wins:int,losses:int}
     */
    public function observationCounts(?string $mode = null): array
    {
        $params = [];
        $sql    = "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN outcome IS NOT NULL THEN 1 ELSE 0 END), 0) AS resolved,
                    COALESCE(SUM(CASE WHEN decision = 'entered' THEN 1 ELSE 0 END), 0) AS entered,
                    COALESCE(SUM(CASE WHEN decision = 'skipped' THEN 1 ELSE 0 END), 0) AS skipped,
                    COALESCE(SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END), 0) AS wins,
                    COALESCE(SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END), 0) AS losses
             FROM observations WHERE 1 = 1" . self::modeClause($mode, $params);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return [
            'total'    => (int) ($r['total'] ?? 0),
            'resolved' => (int) ($r['resolved'] ?? 0),
            'entered'  => (int) ($r['entered'] ?? 0),
            'skipped'  => (int) ($r['skipped'] ?? 0),
            'wins'     => (int) ($r['wins'] ?? 0),
            'losses'   => (int) ($r['losses'] ?? 0),
        ];
    }

    /** ' AND col = ?' or ' AND col IN (?, ?, ...)', binding into $params. */
    private static function inClause(string $col, $value, array &$params): string
    {
        if (!is_array($value)) {
            $params[] = (string) $value;
            return ' AND ' . $col . ' = ?';
        }
        $clean = [];
        foreach ($value as $v) {
            if (is_scalar($v)) {
                $clean[] = (string) $v;
            }
        }
        if ($clean === []) {
            return '';
        }
        foreach ($clean as $v) {
            $params[] = $v;
        }
        return ' AND ' . $col . ' IN (' . implode(', ', array_fill(0, count($clean), '?')) . ')';
    }

    /** Numeric-only flat JSON object (DESIGN-LEARNING §2); non-numeric values are dropped. */
    private static function encodeFeatures($features): string
    {
        if (is_string($features)) {
            $trimmed = trim($features);
            if ($trimmed === '') {
                return '{}';
            }
            $decoded = json_decode($trimmed, true);
            return is_array($decoded) ? self::encodeFeatures($decoded) : '{}';
        }
        if (!is_array($features)) {
            return '{}';
        }
        $out = [];
        foreach ($features as $k => $v) {
            $key = trim((string) $k);
            if ($key === '') {
                continue;
            }
            if (is_bool($v)) {
                $out[$key] = $v ? 1 : 0;
                continue;
            }
            if (!is_numeric($v)) {
                continue;
            }
            $f = (float) $v;
            if (!is_finite($f)) {
                continue;
            }
            $out[$key] = $f;
        }
        $json = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_FORCE_OBJECT);
        return $json === false ? '{}' : $json;
    }

    /** Decode a stored features blob to [name => float]; always an array. */
    public static function decodeFeatures($raw): array
    {
        $v = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $item) {
            if (is_bool($item)) {
                $out[(string) $k] = $item ? 1.0 : 0.0;
            } elseif (is_numeric($item)) {
                $out[(string) $k] = (float) $item;
            }
        }
        return $out;
    }

    /** One of OBSERVATION_OUTCOMES; anything unknown becomes 'flat' ('not_taken' for a skip). */
    private static function normaliseOutcome($value, string $decision): string
    {
        $v = strtolower(trim((string) $value));
        if (in_array($v, self::OBSERVATION_OUTCOMES, true)) {
            return $v;
        }
        return $decision === 'skipped' ? 'not_taken' : 'flat';
    }

    /** Attach `features_map` (decoded) to a row; `features` stays the stored JSON string. */
    private static function withFeatures(array $row): array
    {
        $row['features_map'] = self::decodeFeatures($row['features'] ?? '{}');
        return $row;
    }

    private static function withFeaturesAll(array $rows): array
    {
        foreach ($rows as $i => $r) {
            $rows[$i] = self::withFeatures($r);
        }
        return $rows;
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
