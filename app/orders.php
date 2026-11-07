<?php
/**
 * Order domain helpers shared by the storefront and the admin panel.
 */
require_once __DIR__ . '/db.php';

/** Status pipeline (key => [label, colour token used by badges]). */
function order_statuses(): array {
    return [
        'pending'    => ['label' => 'Pending',    'tone' => 'amber'],
        'confirmed'  => ['label' => 'Confirmed',  'tone' => 'blue'],
        'processing' => ['label' => 'Processing', 'tone' => 'indigo'],
        'shipped'    => ['label' => 'Shipped',    'tone' => 'teal'],
        'delivered'  => ['label' => 'Delivered',  'tone' => 'green'],
        'cancelled'  => ['label' => 'Cancelled',  'tone' => 'red'],
        'returned'   => ['label' => 'Returned',   'tone' => 'gray'],
    ];
}

/** Older orders stored free-text statuses like "Processing"; map them to keys. */
function order_status_key($status): string {
    $s = strtolower(trim((string)$status));
    return isset(order_statuses()[$s]) ? $s : 'pending';
}

function order_status_label($status): string {
    return order_statuses()[order_status_key($status)]['label'];
}

function new_order_id(): string {
    do {
        $id = 'NH-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    } while (store_get('orders', $id));
    return $id;
}

/**
 * Persist an order. $data must already be validated & priced server-side.
 * Adds id/order_id, status 'pending', a status history entry, updates the
 * customer record and decrements tracked stock. Returns the stored order.
 */
function create_order_record(array $data, string $source = 'website'): array {
    $id = new_order_id();
    $now = date('c');
    $order = array_replace($data, [
        'id' => $id,
        'order_id' => $id,
        'status' => 'pending',
        'source' => $source,
        'payment_status' => $data['payment_status'] ?? 'unpaid',
        'history' => [['status' => 'pending', 'at' => $now, 'by' => $data['created_by'] ?? 'Customer', 'note' => '']],
    ]);
    $order = store_put('orders', $order);

    // Stock: only products that track a numeric stock quantity are decremented.
    foreach ($order['items'] ?? [] as $it) {
        $p = store_get('products', $it['id']);
        if ($p && isset($p['stock']) && $p['stock'] !== '' && $p['stock'] !== null) {
            $left = max(0, (int)$p['stock'] - max(0, (int)$it['quantity']));
            store_patch('products', $p['id'], ['stock' => $left, 'in_stock' => $left > 0]);
        }
    }

    upsert_customer_from_order($order);
    return $order;
}

/** Customers are keyed by mobile number. */
function upsert_customer_from_order(array $order): void {
    $phone = (string)($order['phone'] ?? '');
    if ($phone === '') return;
    $c = store_get('customers', $phone) ?? ['id' => $phone, 'phone' => $phone, 'orders_count' => 0, 'total_spent' => 0];
    $c['name'] = $order['name'] ?? ($c['name'] ?? '');
    $c['address'] = $order['address'] ?? ($c['address'] ?? '');
    $c['orders_count'] = (int)$c['orders_count'] + 1;
    $c['total_spent'] = (float)$c['total_spent'] + (float)($order['total'] ?? 0);
    $c['last_order_at'] = date('c');
    $c['last_order_id'] = $order['id'];
    store_put('customers', $c);
}

/** Find by order id or mobile number (latest first). */
function find_order_public(string $query): ?array {
    $q = strtoupper(trim($query));
    if ($q === '') return null;
    $o = store_get('orders', $q);
    if ($o) return $o;
    $digits = preg_replace('/\D/', '', $query);
    if (preg_match('/^(?:88)?(01[3-9]\d{8})$/', $digits, $m)) {
        $hits = array_filter(store_all('orders'), fn($o) => ($o['phone'] ?? '') === $m[1]);
        if ($hits) {
            usort($hits, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
            return $hits[0];
        }
    }
    return null;
}
