<?php
/**
 * Storefront API (JSON)
 *   GET  api.php?action=get_products[&category=&search=&sort=&limit=&offset=]
 *   GET  api.php?action=get_product&id=1
 *   GET  api.php?action=get_wishlist_products&ids=1,2,3
 *   POST api.php?action=apply_coupon     {code, items:[{id,quantity}], zone}
 *   POST api.php?action=create_order     {name, phone, address, zone, payment_method, items, ...}
 *   GET  api.php?action=track_order&id=NH-XXXXXX   (or a mobile number)
 *   POST api.php?action=submit_review    {product_id, name, rating, comment}
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/order-public.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_fail(string $msg, int $status = 400, array $extra = []): void {
    json_out(['success' => false, 'error' => $msg] + $extra, $status);
}
function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_fail('Use POST for this action.', 405);
}

$input  = json_decode(file_get_contents('php://input') ?: '', true);
$input  = is_array($input) ? $input : [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '');
$body   = $input ?: $_POST;

switch ($action) {
    case 'get_products': {
        $list = all_products();
        $cat = trim($_GET['category'] ?? '');
        $q   = mb_strtolower(trim($_GET['search'] ?? $_GET['q'] ?? ''), 'UTF-8');
        if ($cat !== '' && strtolower($cat) !== 'all') {
            $list = array_filter($list, fn($p) => $p['category_slug'] === slugify($cat));
        }
        if ($q !== '') {
            $list = array_filter($list, fn($p) =>
                str_contains(mb_strtolower($p['title'] . ' ' . $p['category'] . ' ' . $p['description'], 'UTF-8'), $q));
        }
        $list = array_values($list);
        $sort = $_GET['sort'] ?? '';
        if ($sort === 'price_asc')  usort($list, fn($a, $b) => $a['price'] <=> $b['price']);
        if ($sort === 'price_desc') usort($list, fn($a, $b) => $b['price'] <=> $a['price']);
        $total = count($list);
        $limit = (int)($_GET['limit'] ?? 0);
        if ($limit > 0) $list = array_slice($list, (int)($_GET['offset'] ?? 0), $limit);
        json_out(['success' => true, 'count' => count($list), 'total' => $total, 'data' => $list]);
    }

    case 'get_product': {
        $p = find_product(all_products(), $_GET['id'] ?? '');
        $p ? json_out(['success' => true, 'data' => $p]) : json_fail('Product not found.', 404);
    }

    case 'get_wishlist_products': {
        $raw = $body['ids'] ?? $_GET['ids'] ?? [];
        $ids = array_slice(array_map('strval', is_array($raw) ? $raw : explode(',', (string)$raw)), 0, 100);
        $catalog = all_products();
        $out = [];
        foreach ($ids as $id) {
            $p = find_product($catalog, trim($id));
            if ($p) $out[] = $p;
        }
        json_out(['success' => true, 'count' => count($out), 'data' => $out]);
    }

    case 'apply_coupon': {
        $code = strtoupper(trim((string)($body['code'] ?? $_GET['code'] ?? '')));
        if ($code === '') json_fail('Please enter a coupon code.', 422, ['field' => 'coupon']);
        [$items, $subtotal] = price_cart((array)($body['items'] ?? []));
        [$zoneKey, $zone] = pick_zone($SITE, (string)($body['zone'] ?? ''));
        $fee = zone_fee($SITE, $zone, $subtotal);
        $c = evaluate_coupon($code, $subtotal, $fee);
        if (!$c['ok']) json_fail($c['error'], 422, ['field' => 'coupon']);
        $delivery = max(0, $fee - (float)$c['delivery_discount']);
        json_out([
            'success' => true,
            'code' => strtoupper($c['coupon']['code']),
            'label' => $c['label'],
            'description' => (string)($c['coupon']['description'] ?? ''),
            'discount' => (float)$c['discount'],
            'free_shipping' => (bool)$c['free_shipping'],
            'delivery_discount' => (float)$c['delivery_discount'],
            'subtotal' => $subtotal,
            'delivery_fee' => $delivery,
            'total' => max(0, $subtotal - (float)$c['discount'] + $delivery),
            'message' => 'Coupon applied: ' . $c['label'],
        ]);
    }

    case 'create_order':
        require_post();
        create_order($db, $SITE, $body);

    case 'track_order':
    case 'find_order': {
        $payload = $body ?: $_GET;
        $query = trim((string)($payload['id'] ?? $payload['query'] ?? $payload['phone'] ?? $_GET['id'] ?? ''));
        if ($query === '') json_fail('Please enter an order ID or mobile number.', 422);
        if (mb_strlen($query) > 40) json_fail('No order found for that ID or mobile number.', 404);
        [$order, $byId] = lookup_order_public($query);
        $order ? json_out(['success' => true, 'data' => public_order_view($order, $byId)])
               : json_fail('No order found for that ID or mobile number.', 404);
    }

    case 'submit_review':
        require_post();
        submit_review($body);

    default:
        json_out(['success' => true, 'name' => $SITE['brand_name'] . ' API', 'version' => '3.0.0']);
}

/* ------------------------------------------------------------------ */

/** Server-side pricing. Returns [items, subtotal]; fails on bad carts. */
function price_cart(array $rows): array {
    $catalog = all_products();
    $merged = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $id = (string)($row['id'] ?? '');
        if ($id === '') continue;
        $merged[$id] = min(99, ($merged[$id] ?? 0) + max(1, (int)($row['quantity'] ?? $row['qty'] ?? 1)));
    }
    if (!$merged) json_fail('Your cart is empty.', 422, ['field' => 'items']);
    if (count($merged) > 50) json_fail('Too many different items in one order.', 422, ['field' => 'items']);

    $items = [];
    $subtotal = 0.0;
    foreach ($merged as $id => $qty) {
        $p = find_product($catalog, $id);
        if (!$p) json_fail('One of the products in your cart is no longer available. Please refresh and try again.', 422, ['field' => 'items', 'product_id' => $id]);
        if (!$p['in_stock'] || ($p['stock'] !== null && $p['stock'] <= 0)) {
            json_fail('"' . $p['title'] . '" is out of stock. Please remove it to continue.', 422, ['field' => 'items', 'product_id' => $id]);
        }
        if ($p['stock'] !== null && $qty > $p['stock']) {
            json_fail('Only ' . $p['stock'] . ' left of "' . $p['title'] . '". Please reduce the quantity.', 422, ['field' => 'items', 'product_id' => $id, 'available' => $p['stock']]);
        }
        $line = $p['price'] * $qty;
        $subtotal += $line;
        $items[] = [
            'id' => (string)$p['id'], 'title' => $p['title'], 'unit' => $p['unit'], 'sku' => $p['sku'],
            'quantity' => $qty, 'price' => $p['price'], 'line_total' => $line,
            'image' => str_starts_with($p['image'], 'data:') ? '' : $p['image'],
        ];
    }
    return [$items, $subtotal];
}

function pick_zone(array $SITE, string $key): array {
    $zones = (array)($SITE['delivery_zones'] ?? []);
    if (!$zones) return ['', ['label' => '', 'fee' => 0]];
    if (!isset($zones[$key])) $key = (string)array_key_first($zones);
    return [$key, $zones[$key] + ['label' => $key, 'fee' => 0]];
}

/** Zone fee, or 0 when the subtotal reaches the store's free-delivery threshold (if one is set). */
function zone_fee(array $SITE, array $zone, float $subtotal): float {
    $free = (float)($SITE['free_delivery_threshold'] ?? 0);
    if ($free > 0 && $subtotal >= $free) return 0.0;
    return max(0, (float)($zone['fee'] ?? 0));
}

function clean_text($v, int $max): string {
    $s = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_substr($s, 0, $max, 'UTF-8');
}

function bd_mobile(string $raw): ?string {
    $d = preg_replace('/\D/', '', $raw);
    return preg_match('/^(?:88)?(01[3-9]\d{8})$/', $d, $m) ? $m[1] : null;
}

function create_order(TelegramDB $db, array $SITE, array $in): void {
    // Honeypot: real customers never see or fill this field.
    if (trim((string)($in['website'] ?? $in['company'] ?? '')) !== '') {
        json_fail('We could not place your order. Please try again.', 422);
    }

    $name    = clean_text($in['name'] ?? '', 80);
    $address = clean_text($in['address'] ?? '', 500);
    $notes   = clean_text($in['notes'] ?? '', 500);
    $email   = clean_text($in['email'] ?? '', 120);
    $district = clean_text($in['district'] ?? '', 60);
    $area    = clean_text($in['area'] ?? '', 80);
    $payRef  = clean_text($in['payment_reference'] ?? '', 60);

    if (mb_strlen($name, 'UTF-8') < 2) json_fail('Please enter your full name.', 422, ['field' => 'name']);
    $phone = bd_mobile((string)($in['phone'] ?? ''));
    if (!$phone) json_fail('Please enter a valid 11-digit mobile number (01XXXXXXXXX).', 422, ['field' => 'phone']);
    $alt = '';
    if (trim((string)($in['alt_phone'] ?? '')) !== '') {
        $alt = bd_mobile((string)$in['alt_phone']);
        if (!$alt) json_fail('The alternative mobile number is not valid.', 422, ['field' => 'alt_phone']);
        if ($alt === $phone) $alt = '';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_fail('Please enter a valid email address or leave it empty.', 422, ['field' => 'email']);
    if (mb_strlen($address, 'UTF-8') < 8) json_fail('Please enter your full delivery address (house, road, area).', 422, ['field' => 'address']);

    [$zoneKey, $zone] = pick_zone($SITE, (string)($in['zone'] ?? ''));
    $payments = (array)($SITE['payment_methods'] ?? []);
    $payKey = (string)($in['payment_method'] ?? '');
    if (!isset($payments[$payKey])) $payKey = (string)array_key_first($payments);
    $payLabel = payment_method_label($payments[$payKey] ?? '', $payKey);

    $cust = store_get('customers', $phone);
    if ($cust && !empty($cust['blocked'])) {
        json_fail('We couldn\'t place this order online. Please contact us.', 422);
    }

    [$items, $subtotal] = price_cart((array)($in['items'] ?? []));

    // Same phone + same cart within 2 minutes = a double tap; return the first order.
    $fingerprint = cart_fingerprint($items);
    foreach (array_reverse(store_all('orders')) as $o) {
        if (($o['phone'] ?? '') !== $phone) continue;
        $age = time() - (int)strtotime((string)($o['created_at'] ?? ''));
        if ($age < 0 || $age > 120) continue;
        if (cart_fingerprint((array)($o['items'] ?? [])) === $fingerprint) {
            json_out(order_response($o, 'Your order was already placed.') + ['duplicate' => true], 200);
        }
    }

    $fee = zone_fee($SITE, $zone, $subtotal);
    $discount = 0.0;
    $deliveryDiscount = 0.0;
    $coupon = null;
    $code = strtoupper(clean_text($in['coupon_code'] ?? $in['coupon'] ?? '', 40));
    if ($code !== '') {
        $c = evaluate_coupon($code, $subtotal, $fee);
        if (!$c['ok']) json_fail($c['error'] . ' Remove the coupon to continue.', 422, ['field' => 'coupon']);
        $coupon = $c['coupon'];
        $discount = min($subtotal, (float)$c['discount']);
        $deliveryDiscount = min($fee, (float)$c['delivery_discount']);
    }
    $delivery = max(0, $fee - $deliveryDiscount);
    $total = max(0, $subtotal - $discount + $delivery);

    $data = [
        'name' => $name, 'phone' => $phone, 'alt_phone' => $alt, 'email' => $email,
        'address' => $address, 'district' => $district, 'area' => $area,
        'zone' => (string)$zone['label'], 'zone_key' => $zoneKey,
        'payment_method' => $payLabel, 'payment_method_key' => $payKey, 'payment_reference' => $payRef,
        'notes' => $notes, 'items' => $items, 'subtotal' => $subtotal,
        'coupon_code' => $coupon ? strtoupper((string)$coupon['code']) : '',
        'coupon_id' => $coupon ? (string)$coupon['id'] : '',
        'discount' => $discount, 'delivery_discount' => $deliveryDiscount,
        'delivery_fee_original' => $fee, 'delivery_fee' => $delivery,
        'total' => $total, 'currency' => 'BDT',
        'ip' => substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 100),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
    ];

    try {
        $order = create_order_record($data, 'website');
        if ($coupon) {
            try { mark_coupon_used((string)$coupon['id']); } catch (\Throwable $e) { /* not critical */ }
        }
        if (function_exists('store_flush')) store_flush(); // make sure it is saved before we confirm
    } catch (\Throwable $e) {
        json_fail('We could not place your order right now. Please try again.', 500);
    }

    if (($SITE['order_notify_telegram'] ?? true) !== false) try {
        $db->notifyChannel(order_telegram_html($order, (string)($SITE['currency_symbol'] ?? '৳')));
    } catch (\Throwable $e) { /* Telegram is best-effort only */ }

    json_out(order_response($order, 'Your order has been placed.'), 201);
}

function cart_fingerprint(array $items): string {
    $parts = [];
    foreach ($items as $it) $parts[] = (string)($it['id'] ?? '') . ':' . (int)($it['quantity'] ?? 0);
    sort($parts);
    return implode('|', $parts);
}

function order_response(array $o, string $message): array {
    $id = (string)($o['order_id'] ?? $o['id']);
    return [
        'success' => true,
        'message' => $message,
        'order_id' => $id,
        'tracking_url' => '/track.php?id=' . urlencode($id),
        'success_url' => '/order-success.php?id=' . urlencode($id),
        'order' => public_order_view($o, true),
    ];
}

function submit_review(array $in): void {
    if (trim((string)($in['website'] ?? '')) !== '') json_fail('Could not submit your review.', 422);

    $p = find_product(all_products(), (string)($in['product_id'] ?? ''));
    if (!$p) json_fail('Product not found.', 404, ['field' => 'product_id']);
    $name = clean_text($in['name'] ?? '', 60);
    $location = clean_text($in['location'] ?? '', 60);
    $comment = clean_text($in['comment'] ?? '', 1000);
    $rating = (int)($in['rating'] ?? 0);

    if (mb_strlen($name, 'UTF-8') < 2) json_fail('Please enter your name.', 422, ['field' => 'name']);
    if ($rating < 1 || $rating > 5) json_fail('Please choose a rating from 1 to 5 stars.', 422, ['field' => 'rating']);
    if (mb_strlen($comment, 'UTF-8') < 10) json_fail('Please write at least a few words (10+ characters).', 422, ['field' => 'comment']);
    if (preg_match_all('~https?://~i', $comment) > 1) json_fail('Please remove links from your review.', 422, ['field' => 'comment']);

    // Ignore exact re-submissions of a review that is still waiting for approval.
    foreach (store_all('reviews') as $r) {
        if (($r['status'] ?? '') === 'pending' && (string)($r['product_id'] ?? '') === (string)$p['id']
            && ($r['comment'] ?? '') === $comment && ($r['customer_name'] ?? '') === $name) {
            json_out(['success' => true, 'status' => 'pending', 'message' => 'Thanks! Your review has been submitted for approval.']);
        }
    }

    try {
        store_put('reviews', [
            'customer_name' => $name, 'location' => $location, 'avatar' => '',
            'product_id' => (string)$p['id'], 'product_title' => $p['title'],
            'rating' => $rating, 'review_date' => date('Y-m-d'), 'comment' => $comment,
            'verified_purchase' => false, 'likes_count' => 0,
            'status' => 'pending', 'source' => 'website',
            'ip' => substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 100),
        ]);
    } catch (\Throwable $e) {
        json_fail('Could not submit your review right now. Please try again.', 500);
    }
    json_out(['success' => true, 'status' => 'pending', 'message' => 'Thanks! Your review has been submitted for approval.'], 201);
}
