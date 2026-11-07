<?php
/**
 * Persistent store.
 *
 * Drivers (see store_backend()):
 *   telegram — Vercel default: JSON files + a pinned index message in a Telegram
 *              channel (app/store_telegram.php). Writes are buffered and flushed
 *              at the end of the request (or explicitly with store_flush()).
 *   pgsql    — when DATABASE_URL / POSTGRES_URL is set (Neon, Supabase, ...).
 *   sqlite   — local default, storage/store.sqlite (SQLITE_PATH to override).
 * Same API for all three.
 *
 * Data model: one `docs` table holding JSON documents grouped by collection
 * (products, categories, orders, reviews, users, coupons, customers,
 * activity, settings, ...). The shop is small, so filtering happens in PHP.
 *
 *   store_all('products')              -> list of docs (each has 'id')
 *   store_get('orders', 'NH-123ABC')   -> doc or null
 *   store_put('coupons', [...])        -> inserts/replaces, returns doc (id auto if missing)
 *   store_patch('orders', $id, [...])  -> merges fields, returns doc or null
 *   store_delete('reviews', $id)       -> bool
 *   setting('key', $default) / setting_set('key', $value)
 */

const STORE_ROOT = __DIR__ . '/..';
const STORE_SCHEMA = 2; // bump when store_migrate() gains new DDL

/**
 * Build a PDO pgsql DSN from a postgres:// URL (Neon, Supabase, Vercel
 * Postgres, local). Only keywords libpq understands are passed through.
 */
function store_pg_dsn(string $url): array {
    $u = parse_url($url);
    if (!$u || empty($u['host'])) throw new RuntimeException('DATABASE_URL is not a valid postgres:// URL');
    $host = $u['host'];
    $db = urldecode(ltrim($u['path'] ?? '', '/')) ?: 'postgres';
    parse_str($u['query'] ?? '', $q);
    $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $parts = [
        'host' => $host,
        'port' => (int)($u['port'] ?? 5432),
        'dbname' => $db,
        'sslmode' => $q['sslmode'] ?? ($local ? 'prefer' : 'require'),
        'connect_timeout' => (int)($q['connect_timeout'] ?? 10),
        'application_name' => 'nahid-honey',
    ];
    // Neon routes by SNI; older libpq builds without SNI need the endpoint id.
    if (str_contains($host, '.neon.tech') && preg_match('/^(ep-[a-z0-9-]+?)(-pooler)?\./', $host, $m)) {
        $parts['options'] = "'endpoint={$m[1]}'";
    }
    // channel_binding, pgbouncer, etc. are intentionally dropped (unsupported by some libpq/PDO builds).
    $dsn = 'pgsql:';
    foreach ($parts as $k => $v) $dsn .= "$k=$v;";
    return [rtrim($dsn, ';'), urldecode($u['user'] ?? ''), urldecode($u['pass'] ?? '')];
}

/**
 * Which storage backend this request uses: 'telegram' | 'pgsql' | 'sqlite'.
 * STORE_DRIVER env wins; otherwise Postgres when DATABASE_URL/POSTGRES_URL is
 * set, Telegram on Vercel, SQLite locally.
 */
function store_backend(): string {
    static $b = null;
    if ($b) return $b;
    $env = strtolower(trim((string)getenv('STORE_DRIVER')));
    if (in_array($env, ['telegram', 'pgsql', 'sqlite'], true)) return $b = $env;
    if (getenv('DATABASE_URL') || getenv('POSTGRES_URL')) return $b = 'pgsql';
    if (getenv('VERCEL')) return $b = 'telegram';
    return $b = 'sqlite';
}

/** Telegram driver instance (only in telegram mode). */
function tg_store(): TgStore {
    require_once __DIR__ . '/store_telegram.php';
    return TgStore::get();
}

/** 'telegram', 'pgsql' or 'sqlite'. */
function store_driver(): string {
    return store_backend() === 'telegram' ? 'telegram' : db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Telegram mode: write buffered changes now (otherwise done automatically at the end of the request). */
function store_flush(): void {
    if (store_backend() === 'telegram') tg_store()->flush();
}

/** True when data will not survive (SQLite in /tmp on a serverless host). */
function store_is_ephemeral(): bool {
    return store_driver() === 'sqlite' && (getenv('VERCEL') || str_starts_with((string)($GLOBALS['__store_sqlite_path'] ?? ''), sys_get_temp_dir()));
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (store_backend() === 'telegram') throw new LogicException('db(): no PDO connection in STORE_DRIVER=telegram mode; use the store_* functions');
    $url = store_backend() === 'pgsql' ? (getenv('DATABASE_URL') ?: getenv('POSTGRES_URL') ?: '') : '';
    if (store_backend() === 'pgsql' && $url === '') throw new RuntimeException('STORE_DRIVER=pgsql needs DATABASE_URL');
    if ($url !== '') {
        [$dsn, $user, $pass] = store_pg_dsn($url);
        // Emulated prepares: one round-trip per query (instead of parse+execute)
        // and safe behind PgBouncer / Neon's transaction-mode pooler.
        $pdo = new PDO($dsn, $user, $pass, $opts + [PDO::ATTR_EMULATE_PREPARES => true]);
    } else {
        $path = getenv('SQLITE_PATH') ?: STORE_ROOT . '/storage/store.sqlite';
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        if (!is_writable(dirname($path)) || (is_file($path) && !is_writable($path))) {
            $path = sys_get_temp_dir() . '/nahid-honey.sqlite';
            error_log('[nahid-honey] WARNING: no DATABASE_URL and storage/ is read-only; using ephemeral ' . $path);
        }
        $GLOBALS['__store_sqlite_path'] = $path;
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=WAL');
    }
    store_migrate($pdo);
    return $pdo;
}

/**
 * Creates tables on first use and seeds once. Costs a single query per
 * request once the schema is current.
 */
function store_migrate(PDO $pdo): void {
    $state = [];
    try {
        foreach ($pdo->query("SELECT name, value FROM counters WHERE name IN ('__schema', '__seeded')") as $r) {
            $state[$r['name']] = (int)$r['value'];
        }
    } catch (PDOException $e) { /* fresh database: tables do not exist yet */ }

    if (($state['__schema'] ?? 0) < STORE_SCHEMA) {
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $pdo->beginTransaction();
        try {
            // Serialise concurrent cold starts (CREATE TABLE IF NOT EXISTS can race on Postgres).
            if ($pg) $pdo->query('SELECT pg_advisory_xact_lock(726354001)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS docs (
                coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL,
                created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                PRIMARY KEY (coll, id))');
            $pdo->exec('CREATE TABLE IF NOT EXISTS counters (name TEXT PRIMARY KEY, value INTEGER NOT NULL)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS docs_coll_created ON docs (coll, created_at)');
            $pdo->prepare('INSERT INTO counters (name, value) VALUES (?, ?)
                ON CONFLICT (name) DO UPDATE SET value = excluded.value')->execute(['__schema', STORE_SCHEMA]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $state['__seeded'] = (int)$pdo->query("SELECT value FROM counters WHERE name = '__seeded'")->fetchColumn();
    }
    if (empty($state['__seeded'])) store_seed($pdo);
}

/** Sortable UTC timestamp for the created_at column (the doc keeps its own value). */
function store_sort_ts($value): string {
    $t = is_string($value) && $value !== '' ? strtotime($value) : false;
    return gmdate('Y-m-d\TH:i:s\Z', $t ?: time());
}

function store_json(array $doc): string {
    return json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

/**
 * Seed data shipped with the repo: [coll => [id => doc], counters].
 * Used by the SQL drivers and the Telegram driver alike.
 */
function store_seed_rows(): array {
    $now = date('c');
    $colls = $counters = [];
    foreach (['products', 'reviews', 'orders'] as $coll) {
        $file = STORE_ROOT . "/database/{$coll}.json";
        $rows = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $max = 0;
        $colls[$coll] = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            if ($coll === 'orders') {
                $row['id'] = $row['id'] ?? $row['order_id'] ?? ('NH-SEED' . $i);
            } else {
                $row['id'] = $row['id'] ?? ($i + 1);
            }
            $row['id'] = (string)$row['id'];
            if (ctype_digit($row['id'])) $max = max($max, (int)$row['id']);
            $row['created_at'] = $row['created_at'] ?? $now;
            $row['updated_at'] = $row['updated_at'] ?? $now;
            $colls[$coll][$row['id']] = $row;
        }
        $counters[$coll] = $max;
    }
    return [$colls, $counters];
}

/** First run: import the JSON files that shipped with the repo. */
function store_seed(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        // The claim row serialises concurrent first requests: the loser blocks
        // here until the winner commits, then sees the row and backs off.
        $claim = $pdo->prepare("INSERT INTO counters (name, value) VALUES ('__seeded', 1) ON CONFLICT (name) DO NOTHING");
        $claim->execute();
        if ($claim->rowCount() === 0) { $pdo->rollBack(); return; } // another request seeded

        [$colls, $counters] = store_seed_rows();
        $ins = $pdo->prepare('INSERT INTO docs (coll, id, data, created_at, updated_at) VALUES (?,?,?,?,?) ON CONFLICT (coll, id) DO NOTHING');
        $ctr = $pdo->prepare('INSERT INTO counters (name, value) VALUES (?, ?) ON CONFLICT (name) DO NOTHING');
        foreach ($colls as $coll => $rows) {
            foreach ($rows as $row) {
                $ins->execute([$coll, $row['id'], store_json($row), store_sort_ts($row['created_at']), store_sort_ts($row['updated_at'])]);
            }
            $ctr->execute([$coll, $counters[$coll]]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Per-request cache so repeated reads are free. */
function &store_cache(): array { static $c = []; return $c; }

function store_all(string $coll): array {
    if (store_backend() === 'telegram') return tg_store()->all($coll);
    $cache = &store_cache();
    if (!isset($cache[$coll])) {
        $st = db()->prepare('SELECT data FROM docs WHERE coll = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$coll]);
        $cache[$coll] = array_values(array_filter(array_map(fn($r) => json_decode($r['data'], true), $st->fetchAll()), 'is_array'));
    }
    return $cache[$coll];
}

function store_get(string $coll, $id): ?array {
    if (store_backend() === 'telegram') return tg_store()->find($coll, (string)$id);
    $st = db()->prepare('SELECT data FROM docs WHERE coll = ? AND id = ?');
    $st->execute([$coll, (string)$id]);
    $row = $st->fetchColumn();
    return $row ? json_decode($row, true) : null;
}

/** Atomically increments and returns a counter (used for numeric ids). */
function store_next_id(string $name): int {
    if (store_backend() === 'telegram') return tg_store()->nextId($name);
    $st = db()->prepare('INSERT INTO counters (name, value) VALUES (?, 1)
        ON CONFLICT (name) DO UPDATE SET value = counters.value + 1 RETURNING value');
    $taken = db()->prepare('SELECT 1 FROM docs WHERE coll = ? AND id = ?');
    // Skip values already used by a doc saved with an explicit id (imports, restores).
    for ($i = 0; $i < 1000; $i++) {
        $st->execute([$name]);
        $v = (int)$st->fetchColumn();
        $st->closeCursor();
        $taken->execute([$name, (string)$v]);
        $used = $taken->fetchColumn();
        $taken->closeCursor();
        if (!$used) return $v;
    }
    throw new RuntimeException("store_next_id($name): no free id");
}

function store_put(string $coll, array $doc): array {
    $now = date('c');
    $auto = !isset($doc['id']) || $doc['id'] === '' || $doc['id'] === null;
    if ($auto) $doc['id'] = (string)store_next_id($coll);
    $doc['id'] = (string)$doc['id'];
    $doc['created_at'] = $doc['created_at'] ?? $now;
    $doc['updated_at'] = $now;
    if (store_backend() === 'telegram') { tg_store()->put($coll, $doc, $auto); return $doc; }
    db()->prepare('INSERT INTO docs (coll, id, data, created_at, updated_at) VALUES (?,?,?,?,?)
        ON CONFLICT (coll, id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at')
        ->execute([$coll, $doc['id'], store_json($doc), store_sort_ts($doc['created_at']), store_sort_ts($now)]);
    $cache = &store_cache();
    unset($cache[$coll]);
    return $doc;
}

function store_patch(string $coll, $id, array $changes): ?array {
    $doc = store_get($coll, $id);
    if (!$doc) return null;
    return store_put($coll, array_replace($doc, $changes, ['id' => (string)$id]));
}

function store_delete(string $coll, $id): bool {
    if (store_backend() === 'telegram') return tg_store()->delete($coll, (string)$id);
    $st = db()->prepare('DELETE FROM docs WHERE coll = ? AND id = ?');
    $st->execute([$coll, (string)$id]);
    $cache = &store_cache();
    unset($cache[$coll]);
    return $st->rowCount() > 0;
}

/** Names of all collections that hold at least one document (used by bin/backup.php). */
function store_collections(): array {
    if (store_backend() === 'telegram') return tg_store()->collections();
    return db()->query('SELECT DISTINCT coll FROM docs ORDER BY coll')->fetchAll(PDO::FETCH_COLUMN);
}

function setting(string $key, $default = null) {
    $doc = store_get('settings', $key);
    return $doc ? ($doc['value'] ?? $default) : $default;
}

function setting_set(string $key, $value): void {
    store_put('settings', ['id' => $key, 'value' => $value]);
}

/** Audit trail shown in Admin → Activity log. */
function log_activity(string $action, string $details = '', array $meta = []): void {
    $u = function_exists('current_user') ? current_user() : null;
    try {
        store_put('activity', [
            'id' => date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'user_id' => $u['id'] ?? null,
            'user_name' => $u['name'] ?? 'System',
            'action' => $action,
            'details' => $details,
            'meta' => $meta,
            'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (\Throwable $e) { /* never block the real action */ }
}
