<?php
/**
 * Vercel Serverless Entrypoint for Nahid Honey
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_starts_with($uri, '/api.php')) {
    require_once __DIR__ . '/../public/api.php';
    exit;
}

if (str_starts_with($uri, '/checkout.php')) {
    require_once __DIR__ . '/../public/checkout.php';
    exit;
}

if (str_starts_with($uri, '/track.php')) {
    require_once __DIR__ . '/../public/track.php';
    exit;
}

if (str_starts_with($uri, '/track-order.php')) {
    require_once __DIR__ . '/../public/track-order.php';
    exit;
}

require_once __DIR__ . '/../public/index.php';
