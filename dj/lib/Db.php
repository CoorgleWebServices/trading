<?php
declare(strict_types=1);

require_once __DIR__ . '/Util.php';

/**
 * SQLite wrapper and schema for the DJ desk (DESIGN-DJ.md §4).
 *
 * Its own database file, dj/data/dj.sqlite: the desk never opens, reads or
 * migrates the trading panel's trader.sqlite. WAL journal, busy_timeout
 * 5000 ms, ERRMODE_EXCEPTION, prepared statements everywhere — no value ever
 * reaches SQL by string concatenation.
 *
 * Rows come back type-normalised (INTEGER => int, REAL => float, TEXT =>
 * string, NULL stays null) and the JSON columns of §4 — crate.prep_done,
 * gigs.promo and meta.value — are decoded on read and encoded on write. A read
 * never hands the caller a raw JSON string where an array is expected: an
 * absent, empty or corrupt value decodes to an empty array, so
 * `$track['prep_done']['src']` is always a safe thing to write.
 *
 * Singleton via Db::get(); tests pass a temporary path (or ':memory:') after
 * Db::reset().
 */
final class Db
{
    /** @var Db|null */
    private static $instance = null;

    /** @var PDO */
    private $pdo;

    /** @var string */
    private $path;

    /** Column types per table: 'i' int, 'f' float, 's' text, 'j' JSON object. */
    const COLUMNS = [
        'crate' => [
            'id' => 's', 'title' => 's', 'artist' => 's', 'bpm' => 'f', 'key' => 's',
            'energy' => 'i', 'bucket' => 's', 'prep' => 's', 'prep_done' => 'j',
            'tags' => 's', 'added_at' => 's',
        ],
        'sets' => ['id' => 's', 'name' => 's', 'brief' => 's', 'created_at' => 's'],
        'set_items' => [
            'id' => 'i', 'set_id' => 's', 'pos' => 'i', 'track_id' => 's',
            'title' => 's', 'artist' => 's', 'bpm' => 'f', 'key' => 's', 'note' => 's',
        ],
        'practice' => ['id' => 's', 'date' => 's', 'minutes' => 'i', 'focus' => 's', 'rating' => 's'],
        'gigs' => [
            'id' => 's', 'name' => 's', 'venue' => 's', 'date' => 's', 'fee' => 'f',
            'status' => 's', 'promo' => 'j',
        ],
        'meta' => ['key' => 's', 'value' => 's'],
        'login_attempts' => ['ip' => 's', 'attempts' => 'i', 'last_at' => 's', 'locked_until' => 's'],
    ];

    /** Sort keys of the crate table, matching the artifact's sort select. */
    const CRATE_SORTS = [
        'added'  => 'added_at DESC, rowid DESC',
        'bpm'    => 'COALESCE(bpm, 0) ASC, title COLLATE NOCASE ASC',
        'energy' => 'COALESCE(energy, 0) DESC, title COLLATE NOCASE ASC',
        'key'    => 'CAST(COALESCE("key", \'\') AS INTEGER) ASC, COALESCE("key", \'\') ASC',
        'title'  => 'title COLLATE NOCASE ASC, rowid ASC',
    ];

    /* ------------------------------------------------------------ lifecycle */

    /** Singleton; defaults to TRADER_DJ_ROOT/data/dj.sqlite. The schema is migrated on open. */
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
        $root = defined('TRADER_DJ_ROOT') ? TRADER_DJ_ROOT : dirname(__DIR__);
        return $root . '/data/dj.sqlite';
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

    /** Exactly the schema of DESIGN-DJ.md §4. Idempotent: safe to run on every open. */
    public function migrate(): void
    {
        $sql = <<<'SQL'
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
SQL;
        $this->pdo->exec($sql);
    }

    /* ------------------------------------------------------------ internals */

    /** Convert a PHP value to something PDO can bind; arrays become JSON. */
    private static function bindable($v)
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_array($v) || is_object($v)) {
            $j = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            return $j === false ? '{}' : $j;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : 0.0;
        }
        return $v;
    }

    /** Keep only known columns of $table, converting values to bindable scalars. */
    private static function whitelist(string $table, array $row): array
    {
        $out = [];
        foreach (self::COLUMNS[$table] as $col => $type) {
            if (array_key_exists($col, $row)) {
                $v = $row[$col];
                if ($type === 'j' && !is_array($v) && !is_object($v)) {
                    // a caller handing over a JSON string keeps it; anything else
                    // that is not an object becomes an empty object, never a stray scalar
                    $v = is_string($v) && $v !== '' ? $v : '{}';
                }
                $out[$col] = self::bindable($v);
            }
        }
        return $out;
    }

    /**
     * Decode a stored JSON column into an associative array. Missing, empty,
     * non-JSON and non-object values all become [] so callers can index into
     * the result without checking its type first.
     *
     * @param mixed $raw
     */
    public static function decodeJson($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $v = json_decode($raw, true);
        return is_array($v) ? $v : [];
    }

    private static function castRow(string $table, array $row): array
    {
        $types = self::COLUMNS[$table];
        foreach ($row as $col => $v) {
            if (!isset($types[$col])) {
                continue;
            }
            if ($types[$col] === 'j') {
                $row[$col] = self::decodeJson($v);
                continue;
            }
            if ($v === null) {
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

    private function insertRow(string $table, array $row): void
    {
        $data = self::whitelist($table, $row);
        if ($data === []) {
            throw new InvalidArgumentException('Nothing to insert into ' . $table);
        }
        $cols = array_keys($data);
        $quoted = [];
        foreach ($cols as $c) {
            $quoted[] = '"' . $c . '"';
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $quoted) . ') VALUES ('
             . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($data));
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
            $sets[] = '"' . $c . '" = ?';
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE "' . $keyCol . '" = ?';
        $params   = array_values($data);
        $params[] = $keyVal;
        $this->pdo->prepare($sql)->execute($params);
    }

    /** LIMIT/OFFSET clause built from integers only - never from caller text. */
    private static function limitClause(array $filter): string
    {
        $limit  = isset($filter['limit']) ? (int) $filter['limit'] : 0;
        $offset = isset($filter['offset']) ? (int) $filter['offset'] : 0;
        if ($limit <= 0) {
            return $offset > 0 ? ' LIMIT -1 OFFSET ' . $offset : '';
        }
        return ' LIMIT ' . $limit . ($offset > 0 ? ' OFFSET ' . $offset : '');
    }

    /* ---------------------------------------------------------------- crate */

    /**
     * Tracks, filtered and sorted the way the artifact's crate view does.
     *
     * $filter: bucket (one bucket, '' or 'all' for every bucket), buckets (a
     * list, used by the prep queue), energy_min / energy_max (the desk's
     * "openers <= 4" and "peaks >= 8" counts), prep (raw|gridded|ready), q
     * (free text matched against title, artist and tags), sort
     * (added|bpm|key|energy|title), limit, offset.
     */
    public function crateList(array $filter = []): array
    {
        $where  = [];
        $params = [];

        $bucket = isset($filter['bucket']) ? strtolower(trim((string) $filter['bucket'])) : '';
        if ($bucket !== '' && $bucket !== 'all') {
            $where[]  = 'LOWER(COALESCE(bucket, \'\')) = ?';
            $params[] = $bucket;
        }
        if (isset($filter['buckets']) && is_array($filter['buckets']) && $filter['buckets'] !== []) {
            $list = [];
            foreach ($filter['buckets'] as $b) {
                if (is_scalar($b)) {
                    $b = strtolower(trim((string) $b));
                    if ($b !== '') {
                        $list[] = $b;
                    }
                }
            }
            if ($list !== []) {
                $where[] = 'LOWER(COALESCE(bucket, \'\')) IN (' . implode(', ', array_fill(0, count($list), '?')) . ')';
                foreach ($list as $b) {
                    $params[] = $b;
                }
            }
        }
        // CAST on the placeholder, not just on the column: COALESCE() drops the
        // column's INTEGER affinity, and PDO binds every execute() parameter as
        // text, so an uncast comparison would put every integer below every
        // string and quietly match the whole crate
        if (isset($filter['energy_min']) && $filter['energy_min'] !== '') {
            $where[]  = 'CAST(COALESCE(energy, 0) AS INTEGER) >= CAST(? AS INTEGER)';
            $params[] = (int) $filter['energy_min'];
        }
        if (isset($filter['energy_max']) && $filter['energy_max'] !== '') {
            $where[]  = 'CAST(COALESCE(energy, 0) AS INTEGER) <= CAST(? AS INTEGER)';
            $params[] = (int) $filter['energy_max'];
        }
        $prep = isset($filter['prep']) ? strtolower(trim((string) $filter['prep'])) : '';
        if ($prep !== '' && $prep !== 'all') {
            $where[]  = 'LOWER(COALESCE(prep, \'raw\')) = ?';
            $params[] = $prep;
        }
        $q = isset($filter['q']) ? trim((string) $filter['q']) : '';
        if ($q !== '') {
            // instr() on a lower-cased haystack, matching the artifact's indexOf():
            // no LIKE wildcards to escape and no user text inside the SQL
            $where[]  = "instr(lower(COALESCE(title, '') || ' ' || COALESCE(artist, '')"
                      . " || ' ' || COALESCE(tags, '')), ?) > 0";
            $params[] = strtolower($q);
        }

        $sort  = isset($filter['sort']) ? strtolower(trim((string) $filter['sort'])) : 'added';
        $order = isset(self::CRATE_SORTS[$sort]) ? self::CRATE_SORTS[$sort] : self::CRATE_SORTS['added'];

        $sql = 'SELECT * FROM crate'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
             . ' ORDER BY ' . $order
             . self::limitClause($filter);
        return $this->fetchAll('crate', $sql, $params);
    }

    public function crateGet(string $id): ?array
    {
        return $this->fetchOne('crate', 'SELECT * FROM crate WHERE id = ?', [$id]);
    }

    public function crateCount(array $filter = []): int
    {
        unset($filter['limit'], $filter['offset']);
        return count($this->crateList($filter));
    }

    /** Insert a track; an absent id gets a fresh uid, an absent added_at gets today. Returns the id. */
    public function crateInsert(array $row): string
    {
        $id = isset($row['id']) ? trim((string) $row['id']) : '';
        if ($id === '') {
            $id = Util::uid();
        }
        $row['id'] = $id;
        if (!isset($row['title']) || trim((string) $row['title']) === '') {
            throw new InvalidArgumentException('A crate row needs a title');
        }
        if (!isset($row['added_at']) || trim((string) $row['added_at']) === '') {
            $row['added_at'] = Util::today();
        }
        if (!array_key_exists('prep_done', $row)) {
            $row['prep_done'] = [];
        }
        // a new track is "raw" until the prep bench says otherwise, so the
        // column is never NULL and the prep filters need no special case
        if (!isset($row['prep']) || trim((string) $row['prep']) === '') {
            $row['prep'] = 'raw';
        }
        $this->insertRow('crate', $row);
        return $id;
    }

    /**
     * Insert many crate rows in one transaction (the paste importer). All rows
     * land or none do, so a failure part-way cannot leave a half-imported crate
     * behind the "that change did not save" flash. Returns the number inserted.
     */
    public function crateInsertMany(array $rows): int
    {
        $n   = 0;
        $own = $this->begin();
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $this->crateInsert($row);
                $n++;
            }
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
        return $n;
    }

    public function crateUpdate(string $id, array $fields): void
    {
        $this->updateRow('crate', 'id', $id, $fields);
    }

    public function crateDelete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM crate WHERE id = ?')->execute([$id]);
    }

    /** Distinct buckets present in the crate, with their track counts. @return array<string,int> */
    public function crateBuckets(): array
    {
        $st = $this->pdo->query("SELECT LOWER(COALESCE(bucket, '')) AS b, COUNT(*) AS n"
            . ' FROM crate GROUP BY b ORDER BY b');
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $b = (string) $r['b'];
            if ($b !== '') {
                $out[$b] = (int) $r['n'];
            }
        }
        return $out;
    }

    /* ----------------------------------------------------------------- sets */

    /** Saved sets, newest first. $filter: q (name/brief search), limit, offset. */
    public function setsList(array $filter = []): array
    {
        $where  = [];
        $params = [];
        $q = isset($filter['q']) ? trim((string) $filter['q']) : '';
        if ($q !== '') {
            $where[]  = "instr(lower(COALESCE(name, '') || ' ' || COALESCE(brief, '')), ?) > 0";
            $params[] = strtolower($q);
        }
        $sql = 'SELECT * FROM sets'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
             . ' ORDER BY created_at DESC, rowid DESC'
             . self::limitClause($filter);
        return $this->fetchAll('sets', $sql, $params);
    }

    public function setGet(string $id): ?array
    {
        return $this->fetchOne('sets', 'SELECT * FROM sets WHERE id = ?', [$id]);
    }

    /** The set plus its ordered items under `items`, or null when the set is gone. */
    public function setWithItems(string $id): ?array
    {
        $set = $this->setGet($id);
        if ($set === null) {
            return null;
        }
        $set['items'] = $this->setItems($id);
        return $set;
    }

    public function setInsert(array $row): string
    {
        $id = isset($row['id']) ? trim((string) $row['id']) : '';
        if ($id === '') {
            $id = Util::uid();
        }
        $row['id'] = $id;
        if (!isset($row['name']) || trim((string) $row['name']) === '') {
            throw new InvalidArgumentException('A set needs a name');
        }
        if (!isset($row['created_at']) || trim((string) $row['created_at']) === '') {
            $row['created_at'] = Util::today();
        }
        $this->insertRow('sets', $row);
        return $id;
    }

    public function setUpdate(string $id, array $fields): void
    {
        $this->updateRow('sets', 'id', $id, $fields);
    }

    /** Delete a set and every item belonging to it. */
    public function setDelete(string $id): void
    {
        $own = $this->begin();
        try {
            $this->pdo->prepare('DELETE FROM set_items WHERE set_id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM sets WHERE id = ?')->execute([$id]);
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
    }

    /**
     * Create or update a set and replace its whole item list in one transaction
     * (the set builder's Save). Returns the set id.
     */
    public function setSave(array $set, array $items): string
    {
        $id = isset($set['id']) ? trim((string) $set['id']) : '';
        $own = $this->begin();
        try {
            if ($id !== '' && $this->setGet($id) !== null) {
                $fields = [];
                foreach (['name', 'brief'] as $k) {
                    if (array_key_exists($k, $set)) {
                        $fields[$k] = $set[$k];
                    }
                }
                if ($fields !== []) {
                    $this->setUpdate($id, $fields);
                }
            } else {
                $set['id'] = $id;
                $id = $this->setInsert($set);
            }
            $this->setItemsReplace($id, $items);
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
        return $id;
    }

    /* ------------------------------------------------------------ set_items */

    /** The items of a set in playing order. */
    public function setItems(string $setId): array
    {
        return $this->fetchAll('set_items', 'SELECT * FROM set_items WHERE set_id = ? ORDER BY pos, id', [$setId]);
    }

    public function setItemGet(int $id): ?array
    {
        return $this->fetchOne('set_items', 'SELECT * FROM set_items WHERE id = ?', [$id]);
    }

    /** Append an item (or insert it at $item['pos']) and renumber. Returns the new item id. */
    public function setItemInsert(string $setId, array $item): int
    {
        $item['set_id'] = $setId;
        if (!isset($item['pos']) || (int) $item['pos'] <= 0) {
            $max = $this->scalar('SELECT MAX(pos) FROM set_items WHERE set_id = ?', [$setId]);
            $item['pos'] = ($max === null ? 0 : (int) $max) + 1;
        }
        $own = $this->begin();
        try {
            // Open the gap first: renumberSetItems() breaks a pos tie by id, and
            // the new row always holds the highest one, so without this the
            // requested slot would be handed back to the incumbent.
            $this->pdo->prepare('UPDATE set_items SET pos = pos + 1 WHERE set_id = ? AND pos >= ?')
                ->execute([$setId, (int) $item['pos']]);
            $this->insertRow('set_items', $item);
            $id = (int) $this->pdo->lastInsertId();
            $this->renumberSetItems($setId);
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
        return $id;
    }

    /** Update one item (typically its transition note). */
    public function setItemUpdate(int $id, array $fields): void
    {
        unset($fields['set_id']);
        $this->updateRow('set_items', 'id', $id, $fields);
    }

    /** Remove one item and close the gap it leaves in pos. */
    public function setItemDelete(int $id): void
    {
        $row = $this->setItemGet($id);
        if ($row === null) {
            return;
        }
        $setId = (string) $row['set_id'];
        $own = $this->begin();
        try {
            $this->pdo->prepare('DELETE FROM set_items WHERE id = ?')->execute([$id]);
            $this->renumberSetItems($setId);
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
    }

    /**
     * Move an item $delta places (-1 up, +1 down) inside its set and renumber
     * every row to a dense 1..n. The new order is written from a rebuilt list
     * rather than by swapping two pos values, so an interrupted or duplicated
     * pos from an older write cannot survive the move. Returns true when the
     * order actually changed.
     */
    public function setItemMove(string $setId, int $itemId, int $delta): bool
    {
        if ($delta === 0) {
            return false;
        }
        $own = $this->begin();
        try {
            $ids = $this->setItemIds($setId);
            $idx = array_search($itemId, $ids, true);
            if ($idx === false) {
                $this->commit($own);
                return false;
            }
            $target = Util::clampInt((int) $idx + $delta, 0, count($ids) - 1);
            if ($target === (int) $idx) {
                $this->commit($own);
                return false;
            }
            array_splice($ids, (int) $idx, 1);
            array_splice($ids, $target, 0, [$itemId]);
            $this->writeSetItemOrder($ids);
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
        return true;
    }

    /**
     * Replace every item of a set in one transaction, numbering pos 1..n in the
     * order given. $items are plain arrays of the set_items columns; set_id,
     * id and pos are ignored and re-derived.
     */
    public function setItemsReplace(string $setId, array $items): void
    {
        $own = $this->begin();
        try {
            $this->pdo->prepare('DELETE FROM set_items WHERE set_id = ?')->execute([$setId]);
            $pos = 1;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                unset($item['id']);
                $item['set_id'] = $setId;
                $item['pos']    = $pos;
                $this->insertRow('set_items', $item);
                $pos++;
            }
            $this->commit($own);
        } catch (Throwable $e) {
            $this->rollBack($own);
            throw $e;
        }
    }

    /** @return int[] item ids of a set in playing order */
    private function setItemIds(string $setId): array
    {
        $st = $this->pdo->prepare('SELECT id FROM set_items WHERE set_id = ? ORDER BY pos, id');
        $st->execute([$setId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            $out[] = (int) $id;
        }
        return $out;
    }

    /** Write pos = 1..n over the given item ids, in that order. */
    private function writeSetItemOrder(array $ids): void
    {
        $up  = $this->pdo->prepare('UPDATE set_items SET pos = ? WHERE id = ?');
        $pos = 1;
        foreach ($ids as $id) {
            $up->execute([$pos, (int) $id]);
            $pos++;
        }
    }

    private function renumberSetItems(string $setId): void
    {
        $this->writeSetItemOrder($this->setItemIds($setId));
    }

    /* ------------------------------------------------------------- practice */

    /** Practice sessions, newest first. $filter: since (YYYY-MM-DD), limit, offset. */
    public function practiceList(array $filter = []): array
    {
        $where  = [];
        $params = [];
        $since = isset($filter['since']) ? trim((string) $filter['since']) : '';
        if ($since !== '') {
            $where[]  = 'date >= ?';
            $params[] = $since;
        }
        $sql = 'SELECT * FROM practice'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
             . ' ORDER BY date DESC, rowid DESC'
             . self::limitClause($filter);
        return $this->fetchAll('practice', $sql, $params);
    }

    public function practiceGet(string $id): ?array
    {
        return $this->fetchOne('practice', 'SELECT * FROM practice WHERE id = ?', [$id]);
    }

    public function practiceInsert(array $row): string
    {
        $id = isset($row['id']) ? trim((string) $row['id']) : '';
        if ($id === '') {
            $id = Util::uid();
        }
        $row['id'] = $id;
        if (!isset($row['date']) || trim((string) $row['date']) === '') {
            $row['date'] = Util::today();
        }
        $row['minutes'] = isset($row['minutes']) ? max(0, (int) $row['minutes']) : 0;
        $this->insertRow('practice', $row);
        return $id;
    }

    public function practiceUpdate(string $id, array $fields): void
    {
        $this->updateRow('practice', 'id', $id, $fields);
    }

    public function practiceDelete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM practice WHERE id = ?')->execute([$id]);
    }

    /** Distinct practice days, newest first — the streak and the 7-day strip read this. @return string[] */
    public function practiceDays(int $limit = 400): array
    {
        $st = $this->pdo->prepare('SELECT DISTINCT date FROM practice ORDER BY date DESC LIMIT '
            . max(1, $limit));
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $d) {
            $out[] = (string) $d;
        }
        return $out;
    }

    /* ----------------------------------------------------------------- gigs */

    /**
     * Gigs. $filter: status, q, sort — 'date' (newest first, the default) or
     * 'upcoming' (today and later in ascending date order, then past nights
     * newest first, which is how the 9Bar desk lists nights).
     */
    public function gigsList(array $filter = []): array
    {
        $where  = [];
        $params = [];
        $status = isset($filter['status']) ? trim((string) $filter['status']) : '';
        if ($status !== '' && strtolower($status) !== 'all') {
            $where[]  = 'LOWER(COALESCE(status, \'\')) = ?';
            $params[] = strtolower($status);
        }
        $q = isset($filter['q']) ? trim((string) $filter['q']) : '';
        if ($q !== '') {
            $where[]  = "instr(lower(COALESCE(name, '') || ' ' || COALESCE(venue, '')), ?) > 0";
            $params[] = strtolower($q);
        }
        $sort = isset($filter['sort']) ? strtolower(trim((string) $filter['sort'])) : 'date';
        if ($sort === 'upcoming') {
            $today = Util::today();
            $order = 'CASE WHEN COALESCE(date, \'\') >= ? THEN 0 ELSE 1 END ASC,'
                   . ' CASE WHEN COALESCE(date, \'\') >= ? THEN COALESCE(date, \'\') END ASC,'
                   . ' COALESCE(date, \'\') DESC, rowid DESC';
            $params[] = $today;
            $params[] = $today;
        } else {
            $order = 'COALESCE(date, \'\') DESC, rowid DESC';
        }
        $sql = 'SELECT * FROM gigs'
             . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
             . ' ORDER BY ' . $order
             . self::limitClause($filter);
        return $this->fetchAll('gigs', $sql, $params);
    }

    public function gigGet(string $id): ?array
    {
        return $this->fetchOne('gigs', 'SELECT * FROM gigs WHERE id = ?', [$id]);
    }

    public function gigInsert(array $row): string
    {
        $id = isset($row['id']) ? trim((string) $row['id']) : '';
        if ($id === '') {
            $id = Util::uid();
        }
        $row['id'] = $id;
        if (!isset($row['name']) || trim((string) $row['name']) === '') {
            throw new InvalidArgumentException('A gig needs a name');
        }
        if (!array_key_exists('promo', $row)) {
            $row['promo'] = [];
        }
        if (!array_key_exists('fee', $row) || $row['fee'] === '' || $row['fee'] === null) {
            $row['fee'] = 0.0;
        }
        $this->insertRow('gigs', $row);
        return $id;
    }

    public function gigUpdate(string $id, array $fields): void
    {
        $this->updateRow('gigs', 'id', $id, $fields);
    }

    public function gigDelete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM gigs WHERE id = ?')->execute([$id]);
    }

    /** The soonest gig on or after today, or null. */
    public function nextGig(?string $from = null): ?array
    {
        $from = $from === null || trim($from) === '' ? Util::today() : trim($from);
        return $this->fetchOne(
            'gigs',
            "SELECT * FROM gigs WHERE COALESCE(date, '') >= ? ORDER BY date ASC, rowid ASC LIMIT 1",
            [$from]
        );
    }

    /* ----------------------------------------------------------------- meta */

    /**
     * A meta value, JSON-decoded. `skills`, `rig` and `press` are stored as JSON
     * objects (§4), so this returns an array; $default comes back for an absent
     * or unreadable key.
     *
     * @param mixed $default
     * @return mixed
     */
    public function metaGet(string $key, $default = null)
    {
        $raw = $this->scalar('SELECT value FROM meta WHERE key = ?', [$key]);
        if ($raw === null) {
            return $default;
        }
        $raw = (string) $raw;
        if (trim($raw) === '') {
            return $default;
        }
        $v = json_decode($raw, true);
        if ($v === null && strtolower(trim($raw)) !== 'null') {
            return $default;
        }
        return $v;
    }

    /** A meta value guaranteed to be an array (skills, rig, press). */
    public function metaArray(string $key): array
    {
        $v = $this->metaGet($key, []);
        return is_array($v) ? $v : [];
    }

    /** Store a meta value as JSON. null deletes the key. */
    public function metaSet(string $key, $value): void
    {
        if ($value === null) {
            $this->pdo->prepare('DELETE FROM meta WHERE key = ?')->execute([$key]);
            return;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new InvalidArgumentException('Cannot encode meta value for ' . $key . ': ' . json_last_error_msg());
        }
        $this->pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')->execute([$key, $json]);
    }

    /** Every meta key with its decoded value. @return array<string,mixed> */
    public function metaAll(): array
    {
        $st = $this->pdo->query('SELECT key, value FROM meta ORDER BY key');
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $key = (string) $r['key'];
            $v   = json_decode((string) $r['value'], true);
            $out[$key] = ($v === null && strtolower(trim((string) $r['value'])) !== 'null') ? [] : $v;
        }
        return $out;
    }

    public function metaDelete(string $key): void
    {
        $this->pdo->prepare('DELETE FROM meta WHERE key = ?')->execute([$key]);
    }

    /* --------------------------------------------------------------- export */

    /**
     * Every table as plain PHP arrays, JSON columns already decoded — the
     * backing data for the ?page=export JSON backup of DESIGN-DJ.md §7.
     */
    public function exportAll(): array
    {
        return [
            'crate'          => $this->fetchAll('crate', 'SELECT * FROM crate ORDER BY added_at, rowid'),
            'sets'           => $this->fetchAll('sets', 'SELECT * FROM sets ORDER BY created_at, rowid'),
            'set_items'      => $this->fetchAll('set_items', 'SELECT * FROM set_items ORDER BY set_id, pos, id'),
            'practice'       => $this->fetchAll('practice', 'SELECT * FROM practice ORDER BY date, rowid'),
            'gigs'           => $this->fetchAll('gigs', "SELECT * FROM gigs ORDER BY COALESCE(date, ''), rowid"),
            'meta'           => $this->metaAll(),
            'login_attempts' => $this->fetchAll('login_attempts', 'SELECT * FROM login_attempts ORDER BY ip'),
        ];
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

    /** Count a failed login; after $max failures the IP is locked for $lockMinutes (§8: 5 / 15 min). */
    public function loginFailed(string $ip, int $max = 5, int $lockMinutes = 15): void
    {
        $cur      = $this->loginAttempt($ip);
        $attempts = (int) $cur['attempts'];
        if (!empty($cur['locked_until']) && !$cur['locked']) {
            $attempts = 0; // a previous lock has expired: start a fresh window
        }
        $attempts++;
        $now         = Util::nowIso();
        $lockedUntil = $cur['locked'] ? (string) $cur['locked_until'] : null;
        if ($attempts >= max(1, $max)) {
            $lockedUntil = Util::isoAddMinutes($now, max(1, $lockMinutes));
        }
        $this->pdo->prepare(
            'INSERT OR REPLACE INTO login_attempts (ip, attempts, last_at, locked_until) VALUES (?, ?, ?, ?)'
        )->execute([$ip, $attempts, $now, $lockedUntil]);
    }

    /** A successful login clears the IP's failure record. */
    public function loginOk(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    /** Drop lockout rows older than $days (housekeeping; never required for correctness). */
    public function loginPrune(int $days = 30): void
    {
        $cutoff = Util::nowIso(time() - max(1, $days) * 86400);
        $this->pdo->prepare('DELETE FROM login_attempts WHERE last_at IS NOT NULL AND last_at < ?')
            ->execute([$cutoff]);
    }

    /* --------------------------------------------------------- transactions */

    /** Begin a transaction unless one is already open. Returns true when this call owns it. */
    private function begin(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }
        $this->pdo->beginTransaction();
        return true;
    }

    private function commit(bool $own): void
    {
        if ($own && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function rollBack(bool $own): void
    {
        if ($own && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
