<?php
/**
 * Media helper — image uploads that work on a read-only (serverless) filesystem.
 *
 * Uploads are sent to the store's Telegram channel through the bot. Only the
 * Telegram `file_id` is kept; images are served by the public proxy
 * /media.php?f=<file_id>, which resolves the file server-side so the bot token
 * never reaches the browser. When Telegram is unavailable and the local
 * filesystem is writable (local development), files are saved under
 * public/uploads/ instead.
 *
 *   media_upload($_FILES['file'])  -> ['success'=>bool, 'url'=>..., 'error'=>...]
 *   media_url($fileId)             -> '/media.php?f=...'
 *   media_valid_file_id($id)       -> bool
 *   media_fetch($fileId)           -> ['body'=>bytes, 'mime'=>...] or null
 */

// Vercel rejects request bodies over ~4.5 MB, so stay below that there.
const MEDIA_MAX_BYTES = (4 * 1024 * 1024); // 4 MB
const MEDIA_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

function media_config(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/../config/telegram.php';
    return $cfg;
}

function media_url(string $fileId): string {
    return '/media.php?f=' . rawurlencode($fileId);
}

/** Telegram file ids are URL-safe base64 tokens. */
function media_valid_file_id(string $id): bool {
    return (bool)preg_match('/^[A-Za-z0-9_-]{20,255}$/', $id);
}

/** Bot API call. Returns the `result` field or null. The token stays server-side. */
function media_tg_call(string $method, array $params, bool $multipart = false, int $timeout = 30): ?array {
    $cfg = media_config();
    if (empty($cfg['bot_token'])) return null;
    $ch = curl_init(rtrim($cfg['api_base'], '/') . '/bot' . $cfg['bot_token'] . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $multipart ? $params : http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $raw = curl_exec($ch);
    $res = is_string($raw) ? json_decode($raw, true) : null;
    return (is_array($res) && !empty($res['ok'])) ? $res['result'] : null;
}

/** Validates an entry of $_FILES. Returns [mime, ext] or an error string. */
function media_validate(array $file) {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (is_array($err)) return 'Upload one file at a time.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'The image is too large (max 4 MB).';
    if ($err === UPLOAD_ERR_NO_FILE) return 'No file was received.';
    if ($err !== UPLOAD_ERR_OK) return 'The upload failed. Please try again.';
    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) return 'The upload failed. Please try again.';
    $size = filesize($tmp);
    if (!$size) return 'The file is empty.';
    if ($size > MEDIA_MAX_BYTES) return 'The image is too large (max 4 MB).';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!isset(MEDIA_TYPES[$mime])) return 'Only JPG, PNG, WebP or GIF images are allowed.';
    if (!@getimagesize($tmp)) return 'This file is not a valid image.';
    return [$mime, MEDIA_TYPES[$mime]];
}

/** Sends the file to the Telegram channel; returns its file_id or null. */
function media_upload_telegram(string $path, string $mime, string $name, string $caption = ''): ?string {
    $cfg = media_config();
    if (empty($cfg['channel_id'])) return null;
    $file = new CURLFile($path, $mime, $name);
    if ($mime === 'image/gif') {
        // GIFs sent as documents become MP4 animations; send as photo to keep an image.
        $res = media_tg_call('sendPhoto', ['chat_id' => $cfg['channel_id'], 'photo' => $file, 'caption' => $caption], true, 60);
        $photos = $res['photo'] ?? [];
        return $photos ? (end($photos)['file_id'] ?? null) : null;
    }
    // As a document Telegram keeps the original file (no recompression, transparency kept).
    $res = media_tg_call('sendDocument', [
        'chat_id' => $cfg['channel_id'], 'document' => $file, 'caption' => $caption,
        'disable_content_type_detection' => 'true',
    ], true, 60);
    return $res['document']['file_id'] ?? null;
}

/** Local-development fallback: save under public/uploads when it is writable. */
function media_upload_local(string $tmp, string $ext): ?string {
    $base = realpath(__DIR__ . '/../public');
    if (!$base) return null;
    $dir = $base . '/uploads/' . date('Y/m');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return null;
    if (!is_writable($dir)) return null;
    $name = date('His') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@move_uploaded_file($tmp, "$dir/$name")) return null;
    return '/uploads/' . date('Y/m') . '/' . $name;
}

/**
 * Full upload pipeline for one $_FILES entry.
 * Returns ['success'=>true,'url'=>...,'file_id'=>...,'storage'=>'telegram|local'] or ['success'=>false,'error'=>...].
 */
function media_upload(array $file, string $caption = ''): array {
    $v = media_validate($file);
    if (is_string($v)) return ['success' => false, 'error' => $v];
    [$mime, $ext] = $v;
    $original = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string)($file['name'] ?? 'image'))) ?: 'image';
    $name = pathinfo($original, PATHINFO_FILENAME) . '.' . $ext;
    $size = (int)filesize($file['tmp_name']);

    $fileId = media_upload_telegram($file['tmp_name'], $mime, $name, $caption);
    if ($fileId && media_valid_file_id($fileId)) {
        $result = ['success' => true, 'url' => media_url($fileId), 'file_id' => $fileId, 'storage' => 'telegram'];
    } else {
        $local = media_upload_local($file['tmp_name'], $ext);
        if (!$local) return ['success' => false, 'error' => 'Could not store the image right now. Please try again in a moment.'];
        $result = ['success' => true, 'url' => $local, 'file_id' => null, 'storage' => 'local'];
    }

    if (function_exists('store_put')) {
        try {
            $u = function_exists('current_user') ? current_user() : null;
            store_put('media', [
                'id' => $result['file_id'] ? substr(sha1($result['file_id']), 0, 20) : substr(sha1($result['url']), 0, 20),
                'url' => $result['url'], 'file_id' => $result['file_id'], 'storage' => $result['storage'],
                'name' => $name, 'mime' => $mime, 'size' => $size,
                'uploaded_by' => $u['name'] ?? null,
            ]);
        } catch (\Throwable $e) { /* the upload itself succeeded */ }
    }
    return $result;
}

/** Downloads a Telegram file by id (server-side). Returns ['body','mime'] or null. */
function media_fetch(string $fileId): ?array {
    if (!media_valid_file_id($fileId)) return null;
    $info = media_tg_call('getFile', ['file_id' => $fileId], false, 10);
    $path = $info['file_path'] ?? '';
    if ($path === '' || !preg_match('#^[A-Za-z0-9_./-]+$#', $path) || str_contains($path, '..')) return null;
    if (isset($info['file_size']) && $info['file_size'] > 20 * 1024 * 1024) return null;

    $cfg = media_config();
    $ch = curl_init(rtrim($cfg['api_base'], '/') . '/file/bot' . $cfg['bot_token'] . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || $body === '' || $code !== 200) return null;

    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: '';
    if (!isset(MEDIA_TYPES[$mime])) return null; // only ever serve images
    return ['body' => $body, 'mime' => $mime];
}
