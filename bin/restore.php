<?php
/**
 * Import a file made by bin/backup.php.
 *
 *   php bin/restore.php backup.json              # upsert every document (keeps anything not in the file)
 *   php bin/restore.php backup.json --replace    # also delete documents that are not in the file
 *   php bin/restore.php backup.json --only=products,categories
 *   add --yes to skip the confirmation prompt
 *
 * Writes to the store selected by the environment (STORE_DRIVER / DATABASE_URL, see README).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/db.php';

$file = null; $opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    else $file = $a;
}
if (!$file || !is_file($file)) { fwrite(STDERR, "Usage: php bin/restore.php BACKUP.json [--replace] [--only=a,b] [--yes]\n"); exit(1); }
$data = json_decode((string)file_get_contents($file), true);
if (!is_array($data['collections'] ?? null)) { fwrite(STDERR, "Not a Nahid Honey backup file.\n"); exit(1); }

$only = isset($opt['only']) ? array_filter(explode(',', (string)$opt['only'])) : null;
$colls = array_filter($data['collections'], fn($k) => !$only || in_array($k, $only, true), ARRAY_FILTER_USE_KEY);

echo 'Restore into store "' . store_driver() . '": ' . implode(', ', array_map(fn($c, $d) => "$c (" . count($d) . ')', array_keys($colls), $colls))
   . (!empty($opt['replace']) ? ' — REPLACING existing data' : '') . "\n";
if (empty($opt['yes'])) {
    echo 'Type "yes" to continue: ';
    if (trim((string)fgets(STDIN)) !== 'yes') { echo "Cancelled.\n"; exit(1); }
}

$put = $del = 0;
foreach ($colls as $coll => $docs) {
    $keep = [];
    foreach ($docs as $doc) {
        if (!is_array($doc) || !isset($doc['id']) || $doc['id'] === '') continue;
        store_put($coll, $doc);
        $keep[(string)$doc['id']] = true;
        $put++;
    }
    if (!empty($opt['replace'])) {
        foreach (store_all($coll) as $doc) {
            if (!isset($keep[(string)$doc['id']])) { store_delete($coll, $doc['id']); $del++; }
        }
    }
}
store_flush();
echo "Done: $put document(s) written, $del deleted.\n";
