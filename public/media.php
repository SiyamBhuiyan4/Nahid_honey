<?php
/**
 * Public image proxy: /media.php?f=<telegram file_id>
 * Resolves the file through the bot server-side and streams the bytes, so the
 * bot token never appears in any URL. Responses are immutable (a file_id
 * always points at the same bytes) and cached by browsers and the CDN.
 */
require_once __DIR__ . '/../app/media.php';

header('X-Content-Type-Options: nosniff');
$id = (string)($_GET['f'] ?? '');

function media_fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    echo $msg;
    exit;
}

if (!media_valid_file_id($id)) media_fail(400, 'Invalid image id');

$etag = '"' . substr(sha1($id), 0, 24) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    exit;
}

$file = media_fetch($id);
if (!$file) media_fail(404, 'Image not found');

header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . strlen($file['body']));
header('Cache-Control: public, max-age=31536000, immutable');
header('CDN-Cache-Control: public, max-age=31536000, immutable');
header('ETag: ' . $etag);
header("Content-Security-Policy: default-src 'none'; sandbox");
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $file['body'];
