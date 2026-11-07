<?php
/**
 * Vercel serverless entrypoint — maps the request path to a PHP file under
 * public/ (e.g. /admin/orders.php, /checkout.php, /admin/ → admin/index.php).
 * Unknown paths fall back to the storefront home page.
 */
$public = realpath(__DIR__ . '/../public');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rel = trim($uri, '/');

$candidates = [];
if ($rel === '') {
    $candidates[] = 'index.php';
} else {
    $candidates[] = $rel;                 // /checkout.php
    $candidates[] = $rel . '.php';        // /checkout
    $candidates[] = $rel . '/index.php';  // /admin
}

foreach ($candidates as $c) {
    $file = realpath($public . '/' . $c);
    if ($file && str_starts_with($file, $public . DIRECTORY_SEPARATOR) && is_file($file)
        && str_ends_with($file, '.php') && !str_contains($file, DIRECTORY_SEPARATOR . 'inc' . DIRECTORY_SEPARATOR)) {
        chdir(dirname($file));
        $_SERVER['SCRIPT_NAME'] = '/' . ltrim(substr($file, strlen($public)), '/');
        require $file;
        return;
    }
}

chdir($public);
require $public . '/index.php';
