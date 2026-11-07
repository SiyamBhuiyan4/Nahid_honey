<?php
/**
 * Image upload endpoint (AJAX). POST multipart `file` with the X-CSRF-Token header.
 * Responds with JSON: {success:true, url:"/media.php?f=..."} or {success:false, error:"..."}.
 * GET returns the most recent uploads: {success:true, items:[{url,name,created_at}]}.
 */
require __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/../../app/media.php';

header('Content-Type: application/json; charset=utf-8');

function media_json(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!current_user()) media_json(['success' => false, 'error' => 'Please sign in again.'], 401);
if (!can('media.upload')) media_json(['success' => false, 'error' => 'You don’t have permission to upload images.'], 403);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $items = store_all('media');
    usort($items, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    $items = array_map(fn($m) => ['url' => $m['url'], 'name' => $m['name'] ?? '', 'created_at' => $m['created_at'] ?? ''], array_slice($items, 0, 60));
    media_json(['success' => true, 'items' => $items]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') media_json(['success' => false, 'error' => 'Method not allowed'], 405);

csrf_check();
if (empty($_FILES['file'])) {
    // post_max_size exceeded empties $_FILES and $_POST.
    media_json(['success' => false, 'error' => 'No image received (max 4 MB).'], 400);
}

$context = trim(substr((string)($_POST['context'] ?? ''), 0, 120));
$res = media_upload($_FILES['file'], $context);
if (!$res['success']) media_json(['success' => false, 'error' => $res['error']], 422);

log_activity('media.upload', 'Uploaded image' . ($context !== '' ? " for $context" : ''), ['url' => $res['url'], 'storage' => $res['storage']]);
media_json(['success' => true, 'url' => $res['url'], 'storage' => $res['storage']]);
