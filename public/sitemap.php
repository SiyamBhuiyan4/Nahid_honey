<?php
/** XML sitemap (served at /sitemap.xml and /sitemap.php): home, categories, products. */
require_once __DIR__ . '/inc/bootstrap.php';

$scheme = is_https() ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost'));
$base = $scheme . '://' . $host;

$products = all_products();
$urls = [[$base . '/', null, '1.0']];
foreach (categories_from($products) as $c) {
    $urls[] = [$base . '/?category=' . rawurlencode($c['slug']), null, '0.8'];
}
$updated = [];
foreach (store_all('products') as $raw) $updated[(string)$raw['id']] = $raw['updated_at'] ?? null;
foreach ($products as $p) {
    $ts = !empty($updated[(string)$p['id']]) ? strtotime($updated[(string)$p['id']]) : false;
    $urls[] = [$base . '/product.php?id=' . rawurlencode((string)$p['id']), $ts ? gmdate('Y-m-d', $ts) : null, '0.7'];
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as [$loc, $mod, $prio]) {
    echo '  <url><loc>', htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8'), '</loc>';
    if ($mod) echo '<lastmod>', $mod, '</lastmod>';
    echo '<priority>', $prio, '</priority></url>', "\n";
}
echo '</urlset>', "\n";
