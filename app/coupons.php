<?php
/**
 * Coupons. Stored in collection 'coupons':
 *   id, code (UPPERCASE, unique), type: 'percent'|'fixed'|'free_shipping',
 *   value (number), min_order (number|0), max_discount (number|0 = no cap),
 *   starts_at / ends_at ('YYYY-MM-DD' or ''), usage_limit (int|0 = unlimited),
 *   used_count (int), active (bool), description
 */
require_once __DIR__ . '/db.php';

function find_coupon(string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    foreach (store_all('coupons') as $c) {
        if (strtoupper($c['code'] ?? '') === $code) return $c;
    }
    return null;
}

/**
 * Validate a coupon against a cart. Returns
 *   ['ok' => true, 'coupon' => [...], 'discount' => float, 'free_shipping' => bool, 'label' => string]
 *   ['ok' => false, 'error' => 'message for the customer']
 */
function evaluate_coupon(string $code, float $subtotal, float $deliveryFee): array {
    $c = find_coupon($code);
    if (!$c || empty($c['active'])) return ['ok' => false, 'error' => 'This coupon code is not valid.'];
    $today = date('Y-m-d');
    if (!empty($c['starts_at']) && $today < $c['starts_at']) return ['ok' => false, 'error' => 'This coupon is not active yet.'];
    if (!empty($c['ends_at']) && $today > $c['ends_at']) return ['ok' => false, 'error' => 'This coupon has expired.'];
    if (!empty($c['usage_limit']) && (int)($c['used_count'] ?? 0) >= (int)$c['usage_limit']) {
        return ['ok' => false, 'error' => 'This coupon has reached its usage limit.'];
    }
    $min = (float)($c['min_order'] ?? 0);
    if ($min > 0 && $subtotal < $min) {
        return ['ok' => false, 'error' => 'Add items worth ' . number_format($min) . ' or more to use this coupon.'];
    }
    $type = $c['type'] ?? 'fixed';
    $value = max(0, (float)($c['value'] ?? 0));
    $discount = 0.0;
    $free = false;
    if ($type === 'percent') {
        $discount = round($subtotal * min(100, max(0, $value)) / 100);
        if (!empty($c['max_discount'])) $discount = min($discount, (float)$c['max_discount']);
    } elseif ($type === 'free_shipping') {
        $free = true;
    } else {
        $discount = min($value, $subtotal);
    }
    $label = $type === 'percent' ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% off'
        : ($type === 'free_shipping' ? 'Free delivery' : number_format($value) . ' off');
    return ['ok' => true, 'coupon' => $c, 'discount' => $discount, 'free_shipping' => $free,
            'delivery_discount' => $free ? $deliveryFee : 0, 'label' => $label];
}

function mark_coupon_used(string $id): void {
    $c = store_get('coupons', $id);
    if ($c) store_patch('coupons', $id, ['used_count' => (int)($c['used_count'] ?? 0) + 1]);
}
