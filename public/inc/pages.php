<?php
/**
 * Helpers for the product / checkout / tracking / wishlist pages.
 * Wraps render_header()/render_footer() so a page can add SEO tags and make
 * sure pages.css / pages.js are present, whatever the layout version prints.
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/order-public.php';

const NH_PAGES_VERSION = '1';

function site_base_url(): string {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return strtolower(explode(',', $proto)[0]) . '://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', explode(',', $host)[0]);
}

function absolute_url(string $path): string {
    if ($path === '' || preg_match('~^(https?:)?//~i', $path) || str_starts_with($path, 'data:')) return $path;
    return site_base_url() . '/' . ltrim($path, '/');
}

/**
 * $meta: description, canonical, og (array of property => content), jsonld (array|null), head (raw html), body_class
 */
function nh_page_header(string $title, array $products, string $active = '', array $meta = []): void {
    ob_start();
    render_header($title, $products, $active);
    $html = ob_get_clean();

    $head = '';
    if (!empty($meta['description'])) {
        $desc = e(mb_substr(trim(preg_replace('/\s+/u', ' ', $meta['description'])), 0, 300, 'UTF-8'));
        $tag = '<meta name="description" content="' . $desc . '">';
        $count = 0;
        $html = preg_replace('~<meta\s+name="description"[^>]*>~i', $tag, $html, 1, $count);
        if (!$count) $head .= $tag . "\n";
    }
    // Drop any layout-provided tags that this page sets itself (avoid duplicates).
    foreach (array_keys((array)($meta['og'] ?? [])) as $prop) {
        $html = preg_replace('~\s*<meta\s+(?:property|name)="' . preg_quote($prop, '~') . '"[^>]*>~i', '', $html);
    }
    if (!empty($meta['canonical'])) $html = preg_replace('~\s*<link\s+rel="canonical"[^>]*>~i', '', $html);
    if (!empty($meta['canonical'])) $head .= '  <link rel="canonical" href="' . e($meta['canonical']) . '">' . "\n";
    foreach ((array)($meta['og'] ?? []) as $prop => $content) {
        if ($content === '' || $content === null) continue;
        $attr = str_starts_with($prop, 'twitter:') ? 'name' : 'property';
        $head .= '  <meta ' . $attr . '="' . e($prop) . '" content="' . e($content) . '">' . "\n";
    }
    if (!empty($meta['jsonld'])) {
        $head .= '  <script type="application/ld+json">' . json_encode($meta['jsonld'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>' . "\n";
    }
    if (!str_contains($html, 'pages.css')) {
        $head .= '  <link rel="stylesheet" href="/assets/css/pages.css?v=' . NH_PAGES_VERSION . '">' . "\n";
    }
    $head .= $meta['head'] ?? '';
    if ($head !== '') $html = preg_replace('~</head>~i', $head . '</head>', $html, 1);
    if (!empty($meta['body_class'])) {
        $html = preg_replace_callback('~<body([^>]*)>~i', function ($m) use ($meta) {
            if (preg_match('~class="([^"]*)"~', $m[1])) {
                return '<body' . preg_replace('~class="([^"]*)"~', 'class="$1 ' . e($meta['body_class']) . '"', $m[1], 1) . '>';
            }
            return '<body' . $m[1] . ' class="' . e($meta['body_class']) . '">';
        }, $html, 1);
    }
    echo $html;
}

function nh_page_footer(array $products, array $data = []): void {
    ob_start();
    render_footer($products);
    $html = ob_get_clean();
    $inject = '';
    if ($data) {
        $inject .= '<script>window.NH_PAGE = ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n";
    }
    if (!str_contains($html, 'pages.js')) {
        $inject .= '<script src="/assets/js/pages.js?v=' . NH_PAGES_VERSION . '" defer></script>' . "\n";
    }
    if ($inject !== '') {
        // NH_PAGE must exist before pages.js runs; put it before the first script tag of the footer scripts.
        $pos = stripos($html, '</body>');
        if ($data && preg_match('~<script[^>]+src="[^"]*pages\.js~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $html = substr_replace($html, $inject, $m[0][1], 0);
        } elseif ($pos !== false) {
            $html = substr_replace($html, $inject, $pos, 0);
        } else {
            $html .= $inject;
        }
    }
    echo $html;
}

/** Approved reviews for a product, newest first. Missing status = approved. */
function product_reviews(string $productId): array {
    $list = array_values(array_filter(store_all('reviews'), fn($r) =>
        (string)($r['product_id'] ?? '') === $productId && in_array($r['status'] ?? 'approved', ['approved', ''], true)));
    usort($list, fn($a, $b) => strcmp($b['review_date'] ?? $b['created_at'] ?? '', $a['review_date'] ?? $a['created_at'] ?? ''));
    return $list;
}

function stars_html(float $rating, string $class = 'nh-stars'): string {
    $out = '<span class="' . e($class) . '" aria-label="' . e(number_format($rating, 1)) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $icon = $rating >= $i - 0.25 ? 'fa-solid fa-star' : ($rating >= $i - 0.75 ? 'fa-solid fa-star-half-stroke' : 'fa-regular fa-star');
        $out .= '<i class="' . $icon . '"></i>';
    }
    return $out . '</span>';
}

function initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) $s .= mb_substr($p, 0, 1, 'UTF-8');
    return mb_strtoupper($s ?: '?', 'UTF-8');
}

function nice_date(string $value, string $format = 'd M Y'): string {
    $t = strtotime($value);
    return $t ? date($format, $t) : '';
}

/** Payment instruction for a method key, normalised to ['text' => ..., 'number' => ...] or null. */
function payment_instruction(array $SITE, string $key): ?array {
    $v = $SITE['payment_instructions'][$key] ?? null;
    if ($v === null || $v === '' || $v === []) return null;
    if (is_string($v)) return ['text' => $v, 'number' => '', 'type' => ''];
    if (is_array($v)) {
        $text = (string)($v['text'] ?? $v['instructions'] ?? $v['note'] ?? '');
        $num = (string)($v['number'] ?? $v['account'] ?? '');
        if ($text === '' && $num === '') return null;
        return ['text' => $text, 'number' => $num, 'type' => (string)($v['type'] ?? $v['account_type'] ?? '')];
    }
    return null;
}
