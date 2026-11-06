<?php
/**
 * Store API
 *   GET  api.php?action=get_products[&category=&search=&sort=&limit=&offset=]
 *   GET  api.php?action=get_product&id=1
 *   POST api.php?action=create_order   (JSON body)
 *   GET  api.php?action=track_order&id=NH-XXXXXX
 */
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_fail(string $msg, int $status = 400, array $extra = []): void {
    json_out(['success' => false, 'error' => $msg] + $extra, $status);
}

$input  = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '');

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

    case 'create_order':
        create_order($db, $SITE, $input ?: $_POST);

    case 'track_order':
    case 'find_order': {
        $payload = $input ?: $_GET;
        $query = trim($payload['id'] ?? $payload['query'] ?? $payload['phone'] ?? '');
        if ($query === '') json_fail('Please enter an order ID or mobile number.', 422);
        $clean = preg_replace('/[^\d+A-Za-z\-]/', '', $query);
        if (preg_match('/^(?:\+?88)?(01[3-9]\d{8})$/', $clean, $m)) $clean = $m[1];
        $order = $db->findOrder($clean) ?? $db->findOrder($query);
        $order ? json_out(['success' => true, 'data' => $order]) : json_fail('No order found for that ID or mobile number.', 404);
    }

    default:
        json_out(['success' => true, 'name' => $SITE['brand_name'] . ' API', 'version' => '2.0.0']);
}

function create_order(TelegramDB $db, array $SITE, array $payload): void {
    $name    = trim($payload['name'] ?? '');
    $phone   = preg_replace('/[^\d]/', '', $payload['phone'] ?? '');
    $address = trim($payload['address'] ?? '');
    $notes   = mb_substr(trim($payload['notes'] ?? ''), 0, 500, 'UTF-8');

    if (mb_strlen($name, 'UTF-8') < 2)  json_fail('Please enter your full name.', 422, ['field' => 'name']);
    if (!preg_match('/^(?:88)?(01[3-9]\d{8})$/', $phone, $m)) json_fail('Please enter a valid 11-digit mobile number (01XXXXXXXXX).', 422, ['field' => 'phone']);
    $phone = $m[1];
    if (mb_strlen($address, 'UTF-8') < 5) json_fail('Please enter your full delivery address.', 422, ['field' => 'address']);

    $zones = $SITE['delivery_zones'];
    $zoneKey = $payload['zone'] ?? '';
    if (!isset($zones[$zoneKey])) $zoneKey = array_key_first($zones);
    $payKey = $payload['payment_method'] ?? '';
    if (!isset($SITE['payment_methods'][$payKey])) $payKey = array_key_first($SITE['payment_methods']);

    // Prices always come from the product list, never from the browser.
    $catalog = all_products();
    $items = [];
    $subtotal = 0;
    foreach ((array)($payload['items'] ?? []) as $row) {
        $p = find_product($catalog, $row['id'] ?? '');
        if (!$p) json_fail('One of the products in your cart is no longer available. Please refresh and try again.', 422);
        if (!$p['in_stock']) json_fail('"' . $p['title'] . '" is out of stock.', 422);
        $qty = max(1, min(99, (int)($row['quantity'] ?? 1)));
        $line = $p['price'] * $qty;
        $subtotal += $line;
        $items[] = [
            'id' => $p['id'], 'title' => $p['title'], 'unit' => $p['unit'], 'quantity' => $qty,
            'price' => $p['price'], 'line_total' => $line,
            'image' => str_starts_with($p['image'], 'data:') ? '' : $p['image'],
        ];
    }
    if (!$items) json_fail('Your cart is empty.', 422, ['field' => 'items']);

    $fee = (float)$zones[$zoneKey]['fee'];
    try {
        $order = $db->createOrder([
            'name' => $name, 'phone' => $phone, 'address' => $address,
            'zone' => $zones[$zoneKey]['label'], 'delivery_fee' => $fee,
            'payment_method' => $SITE['payment_methods'][$payKey], 'notes' => $notes,
            'items' => $items, 'subtotal' => $subtotal, 'total' => $subtotal + $fee, 'currency' => 'BDT',
        ]);
    } catch (\Throwable $e) {
        json_fail('We could not place your order right now. Please try again.', 500);
    }

    json_out([
        'success' => true,
        'message' => 'Your order has been placed.',
        'order_id' => $order['order_id'],
        'tracking_url' => 'track.php?id=' . urlencode($order['order_id']),
        'order' => $order,
    ], 201);
}
