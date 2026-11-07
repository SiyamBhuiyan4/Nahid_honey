<?php
/**
 * Front controller — used by Vercel (every non-/assets request lands here) and
 * locally in router mode:  php -S 127.0.0.1:8080 api/index.php
 *
 *   /                    -> public/index.php       (?category=, ?q= etc. untouched)
 *   /checkout(.php)      -> public/checkout.php
 *   /admin  -> 301 /admin/,  /admin/ -> public/admin/index.php
 *   /sitemap.xml         -> public/sitemap.php
 *   /assets/... etc.     -> static file from public/ (local router mode / fallback)
 *   /inc/, /admin/inc/, dotfiles, unknown paths -> 404 page
 */
$public = realpath(__DIR__ . '/../public');
$uri = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = rawurldecode($uri);

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header_remove('X-Powered-By');

// Pages are required at global scope (bootstrap.php defines globals such as $SITE).
// $notFound() prepares the 404 response and returns the file to require.
$notFound = function () use ($public): string {
    http_response_code(404);
    chdir($public);
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/404.php';
    $_SERVER['SCRIPT_FILENAME'] = $public . '/404.php';
    $_SERVER['DOCUMENT_ROOT'] = $public;
    if (is_file($public . '/404.php')) return $public . '/404.php';
    header('Content-Type: text/plain; charset=utf-8');
    echo "404 Not Found\n";
    return __FILE__ . '.none';
};

$segments = array_values(array_filter(explode('/', $path), 'strlen'));
foreach ($segments as $seg) {
    // Hidden files (.env, .git, .htaccess), traversal and private include folders are never served.
    if ($seg[0] === '.' || $seg === 'inc' || str_contains($seg, "\0") || str_contains($seg, '\\')) {
        $__page = $notFound();
        if (is_file($__page)) require $__page;
        return;
    }
}
$rel = implode('/', $segments);
$query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';

// Directory without trailing slash (/admin) -> /admin/ so relative links resolve correctly.
if ($rel !== '' && !str_ends_with($path, '/') && is_dir($public . '/' . $rel) && is_file($public . '/' . $rel . '/index.php')) {
    header('Location: /' . $rel . '/' . $query, true, 301);
    return;
}

// robots.txt gets an absolute Sitemap line for whatever host serves the site.
if ($rel === 'robots.txt' && is_file($public . '/robots.txt')) {
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo rtrim((string)file_get_contents($public . '/robots.txt')), "\n\nSitemap: ", ($https ? 'https' : 'http'), '://', $host, "/sitemap.xml\n";
    return;
}

$aliases = ['sitemap.xml' => 'sitemap.php'];
$candidates = [];
if ($rel === '') {
    $candidates[] = 'index.php';
} elseif (isset($aliases[$rel])) {
    $candidates[] = $aliases[$rel];
} elseif (str_ends_with($path, '/')) {
    $candidates[] = $rel . '/index.php';
} else {
    $candidates[] = $rel;                                         // /checkout.php, /assets/css/site.css
    if (!pathinfo($rel, PATHINFO_EXTENSION)) $candidates[] = $rel . '.php'; // /checkout
}

$static = [
    'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'avif' => 'image/avif', 'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml; charset=utf-8', 'webmanifest' => 'application/manifest+json',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
];

foreach ($candidates as $c) {
    $file = realpath($public . '/' . $c);
    if (!$file || !is_file($file) || !str_starts_with($file, $public . DIRECTORY_SEPARATOR)) continue;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if ($ext === 'php') {
        $script = '/' . ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($public))), '/');
        $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $script;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['DOCUMENT_ROOT'] = $public;
        chdir(dirname($file));
        require $file;
        return;
    }

    if (isset($static[$ext])) {
        // Local router mode (php -S api/index.php) and any static path Vercel did not route itself.
        $mtime = filemtime($file);
        $etag = '"' . dechex($mtime) . '-' . dechex(filesize($file)) . '"';
        header('Content-Type: ' . $static[$ext]);
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . filesize($file));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
        return;
    }
}

$__page = $notFound();
if (is_file($__page)) require $__page;
