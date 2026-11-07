<?php
/**
 * Persistent store.
 *
 * Production (Vercel): set DATABASE_URL (or POSTGRES_URL) to a Postgres
 * connection string, e.g. a free Neon database. Locally, with no env var,
 * a SQLite file at storage/store.sqlite is used. Same API either way.
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

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $url = getenv('DATABASE_URL') ?: getenv('POSTGRES_URL') ?: '';
    if ($url !== '') {
        $u = parse_url($url);
        $host = $u['host'] ?? 'localhost';
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $u['port'] ?? 5432, ltrim($u['path'] ?? '/postgres', '/'));
        parse_str($u['query'] ?? '', $q);
        $dsn .= ';sslmode=' . ($q['sslmode'] ?? 'require');
        // Neon needs the endpoint id when the client library lacks SNI support.
        if (str_contains($host, 'neon.tech') && preg_match('/^(ep-[a-z0-9-]+?)(-pooler)?\./', $host, $m)) {
            $dsn .= ';options=endpoint=' . $m[1];
        }
        $pdo = new PDO($dsn, urldecode($u['user'] ?? ''), urldecode($u['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $path = getenv('SQLITE_PATH') ?: STORE_ROOT . '/storage/store.sqlite';
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0775, true);
        if (!is_writable(dirname($path))) $path = sys_get_temp_dir() . '/nahid-honey.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    }
    store_migrate($pdo);
    return $pdo;
}

function store_migrate(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS docs (
        coll TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        PRIMARY KEY (coll, id))');
    $pdo->exec('CREATE TABLE IF NOT EXISTS counters (name TEXT PRIMARY KEY, value INTEGER NOT NULL)');

    $seeded = $pdo->query("SELECT value FROM counters WHERE name = '__seeded'")->fetchColumn();
    if (!$seeded) store_seed($pdo);
}

/** First run: import the JSON files that shipped with the repo. */
function store_seed(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("INSERT INTO counters (name, value) VALUES ('__seeded', 1) ON CONFLICT (name) DO NOTHING");
        $claim->execute();
        if ($claim->rowCount() === 0) { $pdo->rollBack(); return; } // another request seeded

        $now = date('c');
        $ins = $pdo->prepare('INSERT INTO docs (coll, id, data, created_at, updated_at) VALUES (?,?,?,?,?) ON CONFLICT (coll, id) DO NOTHING');
        foreach (['products', 'reviews', 'orders'] as $coll) {
            $file = STORE_ROOT . "/database/{$coll}.json";
            $rows = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
            $max = 0;
            foreach ($rows as $i => $row) {
                if ($coll === 'orders') {
                    $row['id'] = $row['id'] ?? $row['order_id'] ?? ('NH-SEED' . $i);
                } else {
                    $row['id'] = (string)($row['id'] ?? ($i + 1));
                }
                $row['id'] = (string)$row['id'];
                if (ctype_digit($row['id'])) $max = max($max, (int)$row['id']);
                $created = $row['created_at'] ?? $now;
                $row['created_at'] = $created;
                $ins->execute([$coll, $row['id'], json_encode($row, JSON_UNESCAPED_UNICODE), $created, $now]);
            }
            $pdo->prepare('INSERT INTO counters (name, value) VALUES (?, ?) ON CONFLICT (name) DO NOTHING')->execute([$coll, $max]);
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
    $cache = &store_cache();
    if (!isset($cache[$coll])) {
        $st = db()->prepare('SELECT data FROM docs WHERE coll = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$coll]);
        $cache[$coll] = array_map(fn($r) => json_decode($r['data'], true), $st->fetchAll());
    }
    return $cache[$coll];
}

function store_get(string $coll, $id): ?array {
    $st = db()->prepare('SELECT data FROM docs WHERE coll = ? AND id = ?');
    $st->execute([$coll, (string)$id]);
    $row = $st->fetchColumn();
    return $row ? json_decode($row, true) : null;
}

/** Atomically increments and returns a counter (used for numeric ids). */
function store_next_id(string $name): int {
    $st = db()->prepare('INSERT INTO counters (name, value) VALUES (?, 1)
        ON CONFLICT (name) DO UPDATE SET value = counters.value + 1 RETURNING value');
    $st->execute([$name]);
    return (int)$st->fetchColumn();
}

function store_put(string $coll, array $doc): array {
    $now = date('c');
    if (!isset($doc['id']) || $doc['id'] === '' || $doc['id'] === null) {
        $doc['id'] = (string)store_next_id($coll);
    }
    $doc['id'] = (string)$doc['id'];
    $doc['created_at'] = $doc['created_at'] ?? $now;
    $doc['updated_at'] = $now;
    db()->prepare('INSERT INTO docs (coll, id, data, created_at, updated_at) VALUES (?,?,?,?,?)
        ON CONFLICT (coll, id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at')
        ->execute([$coll, $doc['id'], json_encode($doc, JSON_UNESCAPED_UNICODE), $doc['created_at'], $now]);
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
    $st = db()->prepare('DELETE FROM docs WHERE coll = ? AND id = ?');
    $st->execute([$coll, (string)$id]);
    $cache = &store_cache();
    unset($cache[$coll]);
    return $st->rowCount() > 0;
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
