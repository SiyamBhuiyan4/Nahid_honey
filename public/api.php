<?php
/**
 * Nahid Honey API Controller
 * Endpoints:
 *   - action=get_products
 *   - action=get_product
 *   - action=create_order (POST JSON)
 *   - action=track_order
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../database/TelegramDB.php';

$telegramDB = new TelegramDB();

// Helper functions
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $message, int $statusCode = 400, array $extra = []): void {
    jsonResponse(array_merge([
        'success' => false,
        'error' => $message,
    ], $extra), $statusCode);
}

// Parse request payload
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawInput = file_get_contents('php://input');
if (empty($rawInput) && PHP_SAPI === 'cli') {
    $rawInput = file_get_contents('php://stdin');
}
$jsonInput = json_decode($rawInput, true);

$action = $_GET['action'] ?? $_POST['action'] ?? ($jsonInput['action'] ?? '');

switch ($action) {
    case 'get_products':
        handleGetProducts($telegramDB);
        break;

    case 'get_product':
        handleGetSingleProduct($telegramDB);
        break;

    case 'create_order':
        handleCreateOrder($telegramDB, $jsonInput);
        break;

    case 'track_order':
    case 'find_order':
        handleTrackOrder($telegramDB, $jsonInput);
        break;

    default:
        jsonResponse([
            'success' => true,
            'name' => 'Nahid Honey API',
            'version' => '1.0.0',
            'endpoints' => [
                'GET api.php?action=get_products' => 'List products with filters (category, search, featured, sort)',
                'GET api.php?action=get_product&id=1' => 'Single product detail by id or slug',
                'POST api.php?action=create_order' => 'Submit order with customer & items data',
                'GET/POST api.php?action=track_order&id=NH-XXXXXX' => 'Track order status by order ID or phone',
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        break;
}

/**
 * Handle Products Listing
 */
function handleGetProducts(TelegramDB $db): void {
    $products = $db->getCollection('products');

    // Query parameters
    $category = trim($_GET['category'] ?? '');
    $search = trim($_GET['search'] ?? ($_GET['q'] ?? ''));
    $featured = isset($_GET['featured']) ? filter_var($_GET['featured'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
    $sort = trim($_GET['sort'] ?? '');
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    $filtered = $products;

    // Filter by Category
    if ($category !== '' && strtolower($category) !== 'all') {
        $categoryLower = strtolower($category);
        $filtered = array_filter($filtered, function ($p) use ($categoryLower) {
            $catSlug = strtolower($p['category'] ?? '');
            $catName = strtolower($p['category_bn'] ?? ($p['category_name'] ?? ''));
            return $catSlug === $categoryLower || str_contains($catSlug, $categoryLower) || str_contains($catName, $categoryLower);
        });
    }

    // Filter by Search Query
    if ($search !== '') {
        $searchLower = strtolower($search);
        $filtered = array_filter($filtered, function ($p) use ($searchLower) {
            $title = strtolower(($p['title_bn'] ?? '') . ' ' . ($p['title'] ?? '') . ' ' . ($p['title_en'] ?? ''));
            $desc = strtolower($p['description'] ?? '');
            $catName = strtolower($p['category_bn'] ?? ($p['category_name'] ?? ''));
            return str_contains($title, $searchLower) || str_contains($desc, $searchLower) || str_contains($catName, $searchLower);
        });
    }

    // Filter by Featured
    if ($featured !== null) {
        $filtered = array_filter($filtered, function ($p) use ($featured) {
            return !empty($p['featured']) === $featured;
        });
    }

    // Sorting
    $filtered = array_values($filtered);
    $getPrice = fn($item) => (float)($item['sale_price'] ?? ($item['price'] ?? ($item['regular_price'] ?? 0)));

    if ($sort === 'price_asc') {
        usort($filtered, fn($a, $b) => $getPrice($a) <=> $getPrice($b));
    } elseif ($sort === 'price_desc') {
        usort($filtered, fn($a, $b) => $getPrice($b) <=> $getPrice($a));
    } elseif ($sort === 'rating') {
        usort($filtered, fn($a, $b) => ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0));
    } elseif ($sort === 'popular') {
        usort($filtered, fn($a, $b) => ($b['reviews_count'] ?? 0) <=> ($a['reviews_count'] ?? 0));
    }

    $total = count($filtered);

    // Pagination
    if ($limit > 0) {
        $filtered = array_slice($filtered, $offset, $limit);
    }

    jsonResponse([
        'success' => true,
        'count' => count($filtered),
        'total' => $total,
        'data' => $filtered,
    ]);
}

/**
 * Handle Single Product Lookup
 */
function handleGetSingleProduct(TelegramDB $db): void {
    $products = $db->getCollection('products');
    $id = $_GET['id'] ?? '';
    $slug = $_GET['slug'] ?? '';

    foreach ($products as $p) {
        if ($id !== '' && (string)$p['id'] === (string)$id) {
            jsonResponse(['success' => true, 'data' => $p]);
        }
        if ($slug !== '' && ($p['slug'] ?? '') === $slug) {
            jsonResponse(['success' => true, 'data' => $p]);
        }
    }

    jsonError('পণ্যটি খুঁজে পাওয়া যায়নি (Product not found)', 404);
}

/**
 * Handle Order Creation
 */
function handleCreateOrder(TelegramDB $db, ?array $jsonInput): void {
    $payload = !empty($jsonInput) ? $jsonInput : array_merge($_GET, $_POST);

    // Customer Name Validation
    $name = trim($payload['name'] ?? $payload['customer_name'] ?? '');
    if (mb_strlen($name, 'UTF-8') < 2) {
        jsonError('অনুগ্রহ করে আপনার সঠিক নাম লিখুন।', 422, ['field' => 'name']);
    }

    // Phone Validation (Bangladesh formats: 01XXXXXXXXX or +8801XXXXXXXXX)
    $phone = trim($payload['phone'] ?? $payload['customer_phone'] ?? '');
    $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
    if (!preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $cleanPhone)) {
        jsonError('সঠিক ১১ ডিজিটের মোবাইল নম্বর দিন (যেমন: 017XXXXXXXX)।', 422, ['field' => 'phone']);
    }

    // Normalize phone to standard 11-digit local format: 01XXXXXXXXX
    if (str_starts_with($cleanPhone, '+88')) {
        $cleanPhone = substr($cleanPhone, 3);
    } elseif (str_starts_with($cleanPhone, '88')) {
        $cleanPhone = substr($cleanPhone, 2);
    }

    // Delivery Address Validation
    $address = trim($payload['address'] ?? $payload['customer_address'] ?? '');
    if (mb_strlen($address, 'UTF-8') < 5) {
        jsonError('অনুগ্রহ করে আপনার সম্পূর্ণ ডেলিভারি ঠিকানা দিন (বাসা নং, রোড, এলাকা/থানা)।', 422, ['field' => 'address']);
    }

    // Delivery Zone & Fee
    $rawZone = trim($payload['zone'] ?? 'Dhaka');
    $isOutside = (
        stripos($rawZone, 'outside') !== false ||
        mb_stripos($rawZone, 'বাইরে', 0, 'UTF-8') !== false
    );

    if ($isOutside) {
        $zoneLabel = 'Outside Dhaka (ঢাকার বাইরে)';
        $deliveryFee = 130;
    } else {
        $zoneLabel = 'Inside Dhaka (ঢাকার ভিতরে)';
        $deliveryFee = 70;
    }

    // Payment Method
    $rawPayment = trim($payload['payment_method'] ?? 'Cash on Delivery');
    if (stripos($rawPayment, 'bkash') !== false || mb_stripos($rawPayment, 'বিকাশ', 0, 'UTF-8') !== false) {
        $paymentMethod = 'bKash (বিকাশ)';
    } else {
        $paymentMethod = 'Cash on Delivery (ক্যাশ অন ডেলিভারি)';
    }

    // Notes
    $notes = trim($payload['notes'] ?? '');

    // Ordered Items Validation
    $items = $payload['items'] ?? [];
    if (is_string($items)) {
        $items = json_decode($items, true) ?: [];
    }

    if (!is_array($items) || empty($items)) {
        jsonError('অর্ডার করার জন্য অন্তত একটি পণ্য কার্টে যোগ করুন।', 422, ['field' => 'items']);
    }

    $cleanItems = [];
    $subtotal = 0;

    foreach ($items as $index => $item) {
        $title = trim($item['title'] ?? ($item['name'] ?? ''));
        $qty = max(1, (int)($item['quantity'] ?? ($item['qty'] ?? 1)));
        $price = (float)($item['price'] ?? 0);
        $image = trim($item['image'] ?? '');
        $unit = trim($item['unit'] ?? '');
        $id = $item['id'] ?? ($index + 1);

        if ($title === '' || $price < 0) {
            jsonError('অর্ডারের পণ্যের বিবরণে ত্রুটি রয়েছে।', 422, ['field' => "items.{$index}"]);
        }

        $lineTotal = $price * $qty;
        $subtotal += $lineTotal;

        $cleanItems[] = [
            'id' => $id,
            'title' => $title,
            'unit' => $unit,
            'quantity' => $qty,
            'price' => $price,
            'line_total' => $lineTotal,
            'image' => $image,
        ];
    }

    $total = $subtotal + $deliveryFee;

    // Prepare order data for TelegramDB
    $orderData = [
        'name' => $name,
        'phone' => $cleanPhone,
        'address' => $address,
        'zone' => $zoneLabel,
        'delivery_fee' => $deliveryFee,
        'payment_method' => $paymentMethod,
        'notes' => $notes,
        'items' => $cleanItems,
        'subtotal' => $subtotal,
        'total' => $total,
        'currency' => 'BDT',
    ];

    try {
        $savedOrder = $db->createOrder($orderData);

        jsonResponse([
            'success' => true,
            'message' => 'ধন্যবাদ! আপনার অর্ডারটি সফলভাবে গৃহীত হয়েছে।',
            'order_id' => $savedOrder['order_id'],
            'tracking_url' => 'track.php?id=' . urlencode($savedOrder['order_id']),
            'order' => $savedOrder,
        ], 201);
    } catch (\Throwable $e) {
        jsonError('অর্ডার সংরক্ষণ করতে সমস্যা হয়েছে: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle Order Tracking Lookup
 */
function handleTrackOrder(TelegramDB $db, ?array $jsonInput): void {
    $payload = !empty($jsonInput) ? $jsonInput : array_merge($_GET, $_POST);

    $query = trim(
        $payload['id'] ??
        ($payload['query'] ??
        ($payload['phone'] ??
        ($payload['order_id'] ?? '')))
    );

    if ($query === '') {
        jsonError('অনুগ্রহ করে অর্ডার আইডি অথবা মোবাইল নম্বর দিন।', 422, ['field' => 'query']);
    }

    // Clean phone if query is numeric
    $cleanQuery = preg_replace('/[^\d+A-Za-z\-]/', '', $query);

    // If query looks like phone with country code, strip +88 or 88
    if (preg_match('/^(?:\+?88)?01[3-9]\d{8}$/', $cleanQuery)) {
        if (str_starts_with($cleanQuery, '+88')) {
            $cleanQuery = substr($cleanQuery, 3);
        } elseif (str_starts_with($cleanQuery, '88')) {
            $cleanQuery = substr($cleanQuery, 2);
        }
    }

    $order = $db->findOrder($cleanQuery);
    if (!$order && $cleanQuery !== $query) {
        $order = $db->findOrder($query);
    }

    if (!$order) {
        jsonError('দুঃখিত! এই অর্ডার আইডি বা মোবাইল নম্বরে কোনো অর্ডার খুঁজে পাওয়া যায়নি। সঠিক তথ্য দিয়ে আবার চেষ্টা করুন।', 404);
    }

    // Map status timeline
    $status = $order['status'] ?? 'Processing';
    $timeline = [
        [
            'key' => 'pending',
            'title' => 'অর্ডার গৃহীত হয়েছে (Order Placed)',
            'description' => 'আপনার অর্ডারটি সিস্টেমে রেকর্ড করা হয়েছে।',
            'completed' => true,
            'date' => $order['created_at'] ?? date('Y-m-d H:i:s'),
        ],
        [
            'key' => 'processing',
            'title' => 'প্রক্রিয়াকরণ ও প্যাকেজিং (Processing)',
            'description' => 'পণ্য বাছাই ও কোয়ালিটি চেকিং চলছে।',
            'completed' => in_array($status, ['Processing', 'Out for Delivery', 'Delivered']),
            'date' => ($status !== 'Pending') ? ($order['created_at'] ?? '') : null,
        ],
        [
            'key' => 'out_for_delivery',
            'title' => 'ডেলিভারির জন্য বের হয়েছে (Out for Delivery)',
            'description' => 'ডেলিভারি রাইডার আপনার ঠিকানায় আসছে।',
            'completed' => in_array($status, ['Out for Delivery', 'Delivered']),
            'date' => null,
        ],
        [
            'key' => 'delivered',
            'title' => 'সফলভাবে ডেলিভারি সম্পন্ন (Delivered)',
            'description' => 'পণ্য গ্রাহকের নিকট সফলভাবে পৌঁছে দেয়া হয়েছে।',
            'completed' => ($status === 'Delivered'),
            'date' => null,
        ],
    ];

    $order['timeline'] = $timeline;

    jsonResponse([
        'success' => true,
        'data' => $order,
    ]);
}
