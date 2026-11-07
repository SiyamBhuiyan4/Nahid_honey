<?php
/**
 * CSV export of orders. Uses the same filters as the orders list
 * (q, status, payment, source, from, to, assigned) so "Export" downloads
 * exactly what is on screen. ?lines=1 exports one row per order item.
 */
require __DIR__ . '/inc/admin.php';
require_perm('orders.export');

$f = admin_order_filters();
$rows = array_values(array_filter(admin_orders_sorted(), fn($o) => order_matches($o, $f)));
$lines = !empty($_GET['lines']);
$users = [];
foreach (store_all('users') as $u) $users[$u['id']] = $u['name'] ?? '';

$label = $f['status'] !== '' ? '-' . $f['status'] : '';
$label .= $f['from'] !== '' || $f['to'] !== '' ? '-' . ($f['from'] ?: 'start') . '-to-' . ($f['to'] ?: date('Y-m-d')) : '';
$filename = slugify($SITE['brand_name'] ?? 'store') . '-orders' . $label . '-' . date('Ymd-His') . '.csv';

log_activity('order.export', 'Exported ' . count($rows) . ' order(s) to CSV', ['filters' => array_filter($f), 'lines' => $lines]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Bangla text correctly
$put = fn(array $r) => fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r), ',', '"', '');

$head = ['Order ID', 'Date', 'Status', 'Payment status', 'Payment method', 'Source', 'Customer', 'Phone', 'Email', 'Address', 'Zone',
         'Items', 'Subtotal', 'Discount', 'Coupon', 'Delivery fee', 'Total', 'Courier', 'Tracking number', 'Assigned to', 'Customer note', 'Internal note'];
if ($lines) array_splice($head, 11, 1, ['Product', 'Unit', 'Qty', 'Unit price', 'Line total']);
$put($head);

foreach ($rows as $o) {
    $base = [
        $o['id'] ?? '', admin_date($o['created_at'] ?? '', 'Y-m-d H:i'), order_status_label($o['status'] ?? ''),
        payment_statuses()[$o['payment_status'] ?? 'unpaid'][0] ?? '', $o['payment_method'] ?? '',
        order_sources()[$o['source'] ?? 'website'] ?? ($o['source'] ?? ''),
        $o['name'] ?? '', $o['phone'] ?? '', $o['email'] ?? '', $o['address'] ?? '', $o['zone'] ?? '',
    ];
    $tail = [
        (float)($o['subtotal'] ?? 0), (float)($o['discount'] ?? 0), $o['coupon_code'] ?? '', (float)($o['delivery_fee'] ?? 0), (float)($o['total'] ?? 0),
        $o['courier'] ?? '', $o['tracking_number'] ?? '', $users[$o['assigned_to'] ?? ''] ?? '', $o['notes'] ?? '', $o['admin_notes'] ?? '',
    ];
    if ($lines) {
        foreach ($o['items'] ?? [] as $it) {
            $put(array_merge($base, [$it['title'] ?? '', $it['unit'] ?? '', (int)($it['quantity'] ?? 0), (float)($it['price'] ?? 0),
                (float)($it['line_total'] ?? ((float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0)))], $tail));
        }
    } else {
        $items = implode('; ', array_map(fn($it) => ($it['title'] ?? '') . (!empty($it['unit']) ? ' (' . $it['unit'] . ')' : '') . ' x' . (int)($it['quantity'] ?? 0), $o['items'] ?? []));
        $put(array_merge($base, [$items], $tail));
    }
}
fclose($out);
