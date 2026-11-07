<?php
/**
 * Export every collection to one JSON file.
 *
 *   php bin/backup.php                      # -> backups/nahid-honey-YYYYmmdd-HHMMSS.json
 *   php bin/backup.php /path/to/file.json
 *   php bin/backup.php - > backup.json      # stdout
 *
 * Uses the store selected by the environment (STORE_DRIVER / DATABASE_URL, see README).
 * The file contains customer data and password hashes — keep it private.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/db.php';

$out = $argv[1] ?? (__DIR__ . '/../backups/nahid-honey-' . date('Ymd-His') . '.json');
$data = ['app' => 'nahid-honey', 'version' => 1, 'exported_at' => date('c'), 'driver' => store_driver(), 'collections' => []];
foreach (store_collections() as $coll) {
    if ($coll === 'login_attempts') continue;
    $data['collections'][$coll] = store_all($coll);
}
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

if ($out === '-') { echo $json, "\n"; exit; }
if (!is_dir(dirname($out))) mkdir(dirname($out), 0700, true);
file_put_contents($out, $json . "\n");
@chmod($out, 0600);
$counts = array_map('count', $data['collections']);
fwrite(STDERR, 'Backup written to ' . realpath($out) . "\n");
foreach ($counts as $c => $n) fwrite(STDERR, sprintf("  %-16s %d\n", $c, $n));
