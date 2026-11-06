<?php
/**
 * Instant Search AJAX API
 * Nahid Honey - TelegramDB Backend
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../database/TelegramDB.php';

$query = trim($_GET['q'] ?? '');

if (empty($query)) {
    echo json_encode(['success' => true, 'results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new TelegramDB();
$products = $db->getCollection('products');

$results = [];
$qLower = mb_strtolower($query, 'UTF-8');

foreach ($products as $p) {
    $title = mb_strtolower($p['title_bn'] ?? $p['title'] ?? '', 'UTF-8');
    $category = mb_strtolower($p['category_bn'] ?? $p['category'] ?? '', 'UTF-8');
    $desc = mb_strtolower($p['description'] ?? '', 'UTF-8');

    if (str_contains($title, $qLower) || str_contains($category, $qLower) || str_contains($desc, $qLower)) {
        $salePrice = (float)($p['sale_price'] ?? $p['price'] ?? 0);
        $regularPrice = (float)($p['regular_price'] ?? $p['original_price'] ?? $salePrice);

        $results[] = [
            'id' => $p['id'],
            'title' => $p['title_bn'] ?? $p['title'],
            'price' => $salePrice,
            'original_price' => $regularPrice > $salePrice ? $regularPrice : null,
            'unit' => $p['weight_volume'] ?? $p['weight'] ?? $p['unit'] ?? '১ পিস',
            'image' => $p['image'] ?? '',
            'category_name' => $p['category_bn'] ?? $p['category_name'] ?? $p['category'] ?? ''
        ];
    }
}

echo json_encode([
    'success' => true,
    'query' => $query,
    'total' => count($results),
    'results' => array_slice($results, 0, 10)
], JSON_UNESCAPED_UNICODE);
