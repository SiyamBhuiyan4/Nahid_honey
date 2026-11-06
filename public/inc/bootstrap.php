<?php
/**
 * Shared bootstrap: settings, data access and view helpers.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../database/TelegramDB.php';

$SITE = require __DIR__ . '/../../config/site.php';
$db = new TelegramDB();

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money($amount): string {
    global $SITE;
    return ($SITE['currency_symbol'] ?? '৳') . number_format((float)$amount);
}

function slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
    return trim($s, '-') ?: 'other';
}

/** Placeholder shown only when a product has no image. */
function placeholder_image(): string {
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 400"><rect width="400" height="400" fill="#F4EEE3"/>'
        . '<path d="M200 110c-38 52-62 88-62 122a62 62 0 0 0 124 0c0-34-24-70-62-122z" fill="#F2C266"/></svg>'
    );
}

/**
 * Normalise a stored product into what templates need.
 * Only uses what the product itself contains — no invented text.
 */
function normalize_product(array $p): array {
    $sale    = (float)($p['sale_price'] ?? $p['price'] ?? 0);
    $regular = (float)($p['regular_price'] ?? $p['original_price'] ?? $sale);
    $images  = array_values(array_filter(array_merge(
        [$p['image'] ?? ''],
        is_array($p['images'] ?? null) ? $p['images'] : []
    )));
    $category = trim((string)($p['category'] ?? ''));

    return [
        'id'             => $p['id'],
        'title'          => (string)($p['title'] ?? $p['title_en'] ?? ''),
        'category'       => $category,
        'category_slug'  => $category !== '' ? slugify($category) : '',
        'price'          => $sale,
        'original_price' => $regular > $sale ? $regular : null,
        'save_amount'    => $regular > $sale ? $regular - $sale : 0,
        'discount_text'  => (string)($p['discount_text'] ?? ''),
        'unit'           => (string)($p['weight_volume'] ?? $p['weight'] ?? $p['unit'] ?? ''),
        'rating'         => isset($p['rating']) ? (float)$p['rating'] : null,
        'reviews_count'  => isset($p['reviews_count']) ? (int)$p['reviews_count'] : null,
        'badge'          => (string)($p['badge'] ?? ''),
        'image'          => $images[0] ?? placeholder_image(),
        'images'         => $images ?: [placeholder_image()],
        'description'    => (string)($p['description'] ?? ''),
        'features'       => is_array($p['features'] ?? null) ? $p['features'] : [],
        'in_stock'       => ($p['in_stock'] ?? true) !== false,
        'featured'       => !empty($p['featured']) || !empty($p['top_selling']) || ($p['badge'] ?? '') !== '',
    ];
}

function all_products(): array {
    global $db;
    return array_map('normalize_product', $db->getCollection('products'));
}

/** Categories in the order they first appear in the product list. */
function categories_from(array $products): array {
    global $SITE;
    $icons = $SITE['category_images'] ?? [];
    $cats = [];
    foreach ($products as $p) {
        if ($p['category'] === '') continue;
        $slug = $p['category_slug'];
        if (!isset($cats[$slug])) {
            $cats[$slug] = [
                'slug' => $slug, 'name' => $p['category'], 'count' => 0,
                'image' => $icons[$slug] ?? $p['image'], 'is_icon' => isset($icons[$slug]),
            ];
        }
        $cats[$slug]['count']++;
    }
    return array_values($cats);
}

function find_product(array $products, $id): ?array {
    foreach ($products as $p) {
        if ((string)$p['id'] === (string)$id) return $p;
    }
    return null;
}

/** Minimal product payload for client-side cart & search. */
function client_products(array $products): array {
    return array_map(fn($p) => [
        'id' => $p['id'], 'title' => $p['title'], 'price' => $p['price'],
        'original_price' => $p['original_price'], 'unit' => $p['unit'],
        'image' => $p['image'], 'category' => $p['category'], 'in_stock' => $p['in_stock'],
    ], $products);
}
