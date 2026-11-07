<?php
/**
 * Storefront-side order helpers shared by api.php and track.php:
 * the customer-safe view of an order and the Telegram new-order alert.
 */

/** 01712345678 -> 017•••••678 */
function mask_phone(string $phone): string {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) < 7) return $d;
    return substr($d, 0, 3) . str_repeat('•', strlen($d) - 6) . substr($d, -3);
}

/** Payment method label from settings (string label or ['label' => ...]). */
function payment_method_label($value, string $fallback = ''): string {
    if (is_array($value)) return (string)($value['label'] ?? $value['name'] ?? $fallback);
    return (string)($value ?? $fallback);
}

/**
 * What a customer may see about an order. Internal notes, IPs, admin names
 * and the full phone number are never returned. The full street address is
 * only shown when the order was looked up by its exact order ID.
 */
function public_order_view(array $o, bool $showAddress = false): array {
    $statusKey = order_status_key($o['status'] ?? 'pending');
    $history = [];
    foreach ((array)($o['history'] ?? []) as $h) {
        if (!is_array($h)) continue;
        $history[] = [
            'status' => order_status_key($h['status'] ?? ''),
            'label'  => order_status_label($h['status'] ?? ''),
            'at'     => (string)($h['at'] ?? ''),
        ];
    }
    $items = [];
    foreach ((array)($o['items'] ?? []) as $it) {
        $qty = (int)($it['quantity'] ?? 1);
        $items[] = [
            'id' => (string)($it['id'] ?? ''), 'title' => (string)($it['title'] ?? ''),
            'unit' => (string)($it['unit'] ?? ''), 'quantity' => $qty,
            'price' => (float)($it['price'] ?? 0),
            'line_total' => (float)($it['line_total'] ?? ((float)($it['price'] ?? 0) * $qty)),
            'image' => (string)($it['image'] ?? ''),
        ];
    }
    $view = [
        'order_id'       => (string)($o['order_id'] ?? $o['id'] ?? ''),
        'status'         => $statusKey,
        'status_label'   => order_status_label($statusKey),
        'created_at'     => (string)($o['created_at'] ?? ''),
        'updated_at'     => (string)($o['updated_at'] ?? ''),
        'name'           => (string)($o['name'] ?? ''),
        'phone_masked'   => mask_phone((string)($o['phone'] ?? '')),
        'zone'           => (string)($o['zone'] ?? ''),
        'district'       => (string)($o['district'] ?? ''),
        'area'           => (string)($o['area'] ?? ''),
        'payment_method' => (string)($o['payment_method'] ?? ''),
        'payment_status' => (string)($o['payment_status'] ?? ''),
        'items'          => $items,
        'subtotal'       => (float)($o['subtotal'] ?? 0),
        'discount'       => (float)($o['discount'] ?? 0),
        'coupon_code'    => (string)($o['coupon_code'] ?? ''),
        'delivery_fee'   => (float)($o['delivery_fee'] ?? 0),
        'total'          => (float)($o['total'] ?? 0),
        'history'        => $history,
        'courier'        => (string)($o['courier'] ?? ''),
        'tracking_number'=> (string)($o['tracking_number'] ?? ''),
        'tracking_url'   => (string)($o['tracking_url'] ?? ''),
    ];
    if ($showAddress) $view['address'] = (string)($o['address'] ?? '');
    return $view;
}

/** Look an order up by ID or mobile. Returns [order|null, matchedById]. */
function lookup_order_public(string $query): array {
    $query = trim($query);
    if ($query === '') return [null, false];
    $o = find_order_public($query);
    if (!$o) return [null, false];
    $byId = strtoupper($query) === strtoupper((string)($o['order_id'] ?? $o['id'] ?? ''));
    return [$o, $byId];
}

/** HTML message for the Telegram order channel. */
function order_telegram_html(array $o, string $currency): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $m = fn($v) => $currency . number_format((float)$v);
    $lines = [];
    $lines[] = '🛒 <b>New order ' . $h($o['order_id']) . '</b>';
    $lines[] = '';
    $lines[] = '👤 ' . $h($o['name']);
    $lines[] = '📞 ' . $h($o['phone']) . (!empty($o['alt_phone']) ? ' / ' . $h($o['alt_phone']) : '');
    if (!empty($o['email'])) $lines[] = '✉️ ' . $h($o['email']);
    $place = implode(', ', array_filter([$o['area'] ?? '', $o['district'] ?? '']));
    $lines[] = '📍 ' . $h($o['address']) . ($place !== '' ? ' (' . $h($place) . ')' : '');
    $lines[] = '🚚 ' . $h($o['zone']) . ' — ' . $m($o['delivery_fee']);
    $lines[] = '💳 ' . $h($o['payment_method']) . (!empty($o['payment_reference']) ? ' — Ref: ' . $h($o['payment_reference']) : '');
    $lines[] = '';
    foreach ($o['items'] as $it) {
        $lines[] = '• ' . $h($it['title']) . ($it['unit'] !== '' ? ' (' . $h($it['unit']) . ')' : '') . ' × ' . (int)$it['quantity'] . ' = ' . $m($it['line_total']);
    }
    $lines[] = '';
    $lines[] = 'Subtotal: ' . $m($o['subtotal']);
    if (!empty($o['coupon_code'])) {
        $lines[] = '🏷 Coupon <b>' . $h($o['coupon_code']) . '</b>: −' . $m($o['discount'])
            . (!empty($o['delivery_discount']) ? ' + free delivery (−' . $m($o['delivery_discount']) . ')' : '');
    }
    $lines[] = 'Delivery: ' . $m($o['delivery_fee']);
    $lines[] = '💰 <b>Total: ' . $m($o['total']) . '</b>';
    if (!empty($o['notes'])) { $lines[] = ''; $lines[] = '📝 ' . $h($o['notes']); }
    return implode("\n", $lines);
}
