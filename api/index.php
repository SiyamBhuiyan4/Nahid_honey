<?php
/**
 * Vercel serverless entrypoint — routes every request to the right page.
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pages = ['api.php', 'checkout.php', 'product.php', 'track.php', 'track-order.php'];
$page = ltrim($uri, '/');
require __DIR__ . '/../public/' . (in_array($page, $pages, true) ? $page : 'index.php');
