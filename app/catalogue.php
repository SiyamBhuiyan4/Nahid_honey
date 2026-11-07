<?php
/**
 * Catalogue helpers shared by the admin product, category, inventory and review pages.
 * Requires public/inc/bootstrap.php (store_*, slugify, setting, $SITE).
 */

const PRODUCT_STATUSES = ['published' => 'Published', 'draft' => 'Draft'];
const REVIEW_STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'hidden' => 'Hidden'];

/** Default low-stock threshold (Inventory page setting). */
function cat_low_stock_default(): int {
    return max(0, (int)setting('low_stock_threshold', 5));
}

function cat_status($p): string {
    $s = (string)($p['status'] ?? 'published');
    return isset(PRODUCT_STATUSES[$s]) ? $s : 'published';
}

function cat_stock($p): ?int {
    return isset($p['stock']) && $p['stock'] !== '' && $p['stock'] !== null ? (int)$p['stock'] : null;
}

function cat_threshold($p): int {
    return isset($p['low_stock_threshold']) && $p['low_stock_threshold'] !== '' && $p['low_stock_threshold'] !== null
        ? max(0, (int)$p['low_stock_threshold']) : cat_low_stock_default();
}

/** 'in' | 'low' | 'out' (untracked products follow their in_stock flag). */
function cat_stock_state($p): string {
    $stock = cat_stock($p);
    if ($stock === null) return ($p['in_stock'] ?? true) === false ? 'out' : 'in';
    if ($stock <= 0) return 'out';
    return $stock <= cat_threshold($p) ? 'low' : 'in';
}

function cat_stock_badge($p): string {
    $stock = cat_stock($p);
    $state = cat_stock_state($p);
    $tone = ['in' => 'green', 'low' => 'amber', 'out' => 'red'][$state];
    $label = $stock === null ? ($state === 'out' ? 'Out of stock' : 'In stock') : ($state === 'out' ? 'Out · 0' : ($state === 'low' ? "Low · $stock" : "$stock in stock"));
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

function cat_status_badge($p): string {
    $s = cat_status($p);
    return '<span class="badge badge-' . ($s === 'published' ? 'green' : 'gray') . '">' . e(PRODUCT_STATUSES[$s]) . '</span>';
}

/** Main image + gallery as one ordered list. */
function cat_product_images(array $p): array {
    $list = array_merge([(string)($p['image'] ?? '')], is_array($p['images'] ?? null) ? $p['images'] : []);
    return array_values(array_unique(array_filter(array_map('trim', array_map('strval', $list)))));
}

function cat_product_title(array $p): string {
    return (string)($p['title_en'] ?? '') !== '' ? (string)$p['title_en'] : (string)($p['title'] ?? '');
}

/** Image src usable from /admin/ pages (category icons in config are relative to the site root). */
function cat_img_src(string $src): string {
    if ($src === '') return placeholder_image();
    if (preg_match('#^(https?:|data:|/)#i', $src)) return $src;
    return '/' . $src;
}

/** Only allow http(s) URLs and site-relative paths for images. */
function cat_clean_image_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) return $url;
    if (preg_match('#^/?[A-Za-z0-9_./?=&%-]+$#', $url) && !str_contains($url, '..')) return $url;
    return '';
}

function cat_unique_slug(string $slug, $exceptId = null): string {
    $slug = slugify($slug);
    $taken = [];
    foreach (store_all('products') as $p) {
        if ($exceptId !== null && (string)$p['id'] === (string)$exceptId) continue;
        if (!empty($p['slug'])) $taken[$p['slug']] = true;
    }
    $try = $slug; $n = 2;
    while (isset($taken[$try])) $try = $slug . '-' . $n++;
    return $try;
}

/** Product count per category slug. */
function cat_product_counts(): array {
    $counts = [];
    foreach (store_all('products') as $p) {
        $c = trim((string)($p['category'] ?? ''));
        if ($c === '') continue;
        $counts[slugify($c)] = ($counts[slugify($c)] ?? 0) + 1;
    }
    return $counts;
}

/**
 * Category docs sorted for display. Any category that exists on products but
 * not in the collection is imported (first open imports them all).
 */
function cat_categories(): array {
    global $SITE;
    $docs = [];
    foreach (store_all('categories') as $c) $docs[$c['id']] = $c;
    $icons = $SITE['category_images'] ?? [];
    $next = $docs ? max(array_map(fn($c) => (int)($c['sort_order'] ?? 0), $docs)) + 1 : 1;
    $imported = [];
    foreach (store_all('products') as $p) {
        $name = trim((string)($p['category'] ?? ''));
        if ($name === '') continue;
        $slug = slugify($name);
        if (isset($docs[$slug])) continue;
        $docs[$slug] = store_put('categories', [
            'id' => $slug, 'name' => $name, 'image' => '', 'description' => '',
            'sort_order' => $next++, 'visible' => true,
        ]);
        $imported[] = $name;
    }
    if ($imported) log_activity('category.import', 'Imported categories from products: ' . implode(', ', $imported));
    $list = array_values($docs);
    usort($list, fn($a, $b) => ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0)) ?: strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
    return $list;
}

/** Category names for product forms: collection first, then any product-only names. */
function cat_category_names(): array {
    $sorted = store_all('categories');
    usort($sorted, fn($a, $b) => (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));
    $names = [];
    foreach ($sorted as $c) $names[$c['id']] = $c['name'] ?? $c['id'];
    foreach (store_all('products') as $p) {
        $n = trim((string)($p['category'] ?? ''));
        if ($n !== '' && !isset($names[slugify($n)])) $names[slugify($n)] = $n;
    }
    return $names;
}

/** Makes sure a category used on a product exists in the collection. */
function cat_ensure_category(string $name): void {
    $name = trim($name);
    if ($name === '' || store_get('categories', slugify($name))) return;
    $max = 0;
    foreach (store_all('categories') as $c) $max = max($max, (int)($c['sort_order'] ?? 0));
    global $SITE;
    store_put('categories', [
        'id' => slugify($name), 'name' => $name, 'image' => '',
        'description' => '', 'sort_order' => $max + 1, 'visible' => true,
    ]);
}

function cat_review_status(array $r): string {
    $s = (string)($r['status'] ?? 'approved');
    return isset(REVIEW_STATUSES[$s]) ? $s : 'approved';
}

/**
 * Recomputes a product's rating & reviews_count from its approved linked reviews.
 * Products with no approved linked reviews keep their existing values.
 */
function cat_recompute_rating($productId): void {
    if ($productId === null || $productId === '') return;
    $product = store_get('products', $productId);
    if (!$product) return;
    $sum = 0; $n = 0;
    foreach (store_all('reviews') as $r) {
        if ((string)($r['product_id'] ?? '') !== (string)$productId || cat_review_status($r) !== 'approved') continue;
        $sum += (float)($r['rating'] ?? 0); $n++;
    }
    if ($n === 0) return;
    $rating = round($sum / $n, 1);
    if ((float)($product['rating'] ?? -1) === $rating && (int)($product['reviews_count'] ?? -1) === $n) return;
    store_patch('products', $productId, ['rating' => $rating, 'reviews_count' => $n]);
}

/** Simple pager: returns [slice, page, pages]. */
function cat_paginate(array $rows, int $perPage, int $page): array {
    $pages = max(1, (int)ceil(count($rows) / $perPage));
    $page = min(max(1, $page), $pages);
    return [array_slice($rows, ($page - 1) * $perPage, $perPage), $page, $pages];
}

function cat_pagination_html(int $page, int $pages, array $query, int $total = 0, int $perPage = 0): string {
    if ($pages <= 1) return '';
    $link = function ($p) use ($query) { return '?' . http_build_query(array_merge($query, ['page' => $p])); };
    $h = '<nav class="pagination">';
    $h .= $total && $perPage ? '<span>Showing ' . (($page - 1) * $perPage + 1) . '–' . min($total, $page * $perPage) . ' of ' . $total . '</span>' : '<span></span>';
    $h .= '<div class="pages">';
    $h .= $page > 1 ? '<a href="' . e($link($page - 1)) . '" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></a>' : '<span class="is-disabled"><i class="fa-solid fa-chevron-left"></i></span>';
    for ($i = 1; $i <= $pages; $i++) {
        if ($i !== 1 && $i !== $pages && abs($i - $page) > 2) {
            if ($i === 2 || $i === $pages - 1) $h .= '<span class="is-disabled">…</span>';
            continue;
        }
        $h .= $i === $page ? '<span class="is-active">' . $i . '</span>' : '<a href="' . e($link($i)) . '">' . $i . '</a>';
    }
    $h .= $page < $pages ? '<a href="' . e($link($page + 1)) . '" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></a>' : '<span class="is-disabled"><i class="fa-solid fa-chevron-right"></i></span>';
    return $h . '</div></nav>';
}
