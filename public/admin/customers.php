<?php
/** Customers — built from orders, keyed by mobile number. */
require __DIR__ . '/inc/admin.php';
require_perm('customers.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'rebuild') {
        if (!is_admin_user()) { flash('Only administrators can rebuild the customer list.', 'error'); redirect(admin_url('customers.php')); }
        // Recompute every customer from all orders; keep staff-entered notes and block flags.
        $existing = [];
        foreach (store_all('customers') as $c) $existing[(string)$c['id']] = $c;
        $orders = store_all('orders');
        usort($orders, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
        $built = [];
        foreach ($orders as $o) {
            $phone = trim((string)($o['phone'] ?? ''));
            if ($phone === '') continue;
            $c = $built[$phone] ?? ['id' => $phone, 'phone' => $phone, 'orders_count' => 0, 'total_spent' => 0];
            if (!empty($o['name'])) $c['name'] = $o['name'];
            if (!empty($o['address'])) $c['address'] = $o['address'];
            $c['orders_count']++;
            $c['total_spent'] += (float)($o['total'] ?? 0);
            $c['last_order_at'] = $o['created_at'] ?? null;
            $c['last_order_id'] = $o['id'] ?? ($o['order_id'] ?? null);
            $built[$phone] = $c;
        }
        $removed = 0;
        foreach ($built as $phone => $c) {
            $old = $existing[$phone] ?? [];
            store_put('customers', array_replace($old, $c));
        }
        foreach ($existing as $id => $old) {
            if (isset($built[$id])) continue;
            if (!empty($old['notes']) || !empty($old['blocked'])) {
                store_put('customers', array_replace($old, ['orders_count' => 0, 'total_spent' => 0, 'last_order_at' => null, 'last_order_id' => null]));
            } else {
                store_delete('customers', $id); $removed++;
            }
        }
        log_activity('customers.rebuild', 'Rebuilt customer list from ' . count($orders) . ' orders (' . count($built) . ' customers)');
        flash('Customer list rebuilt from ' . count($orders) . ' orders — ' . count($built) . ' customers.' . ($removed ? " $removed without orders removed." : ''));
    }
    redirect(admin_url('customers.php'));
}

$all = store_all('customers');
$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'recent');
$filter = (string)($_GET['filter'] ?? '');
$list = array_values(array_filter($all, function ($c) use ($q, $filter) {
    if ($filter === 'blocked' && empty($c['blocked'])) return false;
    if ($filter === 'repeat' && (int)($c['orders_count'] ?? 0) < 2) return false;
    if ($q === '') return true;
    $digits = preg_replace('/\D/', '', $q);
    $hay = ($c['name'] ?? '') . ' ' . ($c['address'] ?? '') . ' ' . ($c['phone'] ?? '');
    return mb_stripos($hay, $q) !== false || ($digits !== '' && str_contains(preg_replace('/\D/', '', (string)($c['phone'] ?? '')), $digits));
}));
$sorts = [
    'recent' => ['Last order (newest)', fn($a, $b) => strcmp((string)($b['last_order_at'] ?? ''), (string)($a['last_order_at'] ?? ''))],
    'spent'  => ['Total spent', fn($a, $b) => (float)($b['total_spent'] ?? 0) <=> (float)($a['total_spent'] ?? 0)],
    'orders' => ['Most orders', fn($a, $b) => (int)($b['orders_count'] ?? 0) <=> (int)($a['orders_count'] ?? 0)],
    'name'   => ['Name (A–Z)', fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''))],
];
if (!isset($sorts[$sort])) $sort = 'recent';
usort($list, $sorts[$sort][1]);

$perPage = 25;
$pages = max(1, (int)ceil(count($list) / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$rows = array_slice($list, ($page - 1) * $perPage, $perPage);

$totalSpent = array_sum(array_map(fn($c) => (float)($c['total_spent'] ?? 0), $all));
$repeat = count(array_filter($all, fn($c) => (int)($c['orders_count'] ?? 0) >= 2));
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_replace(['q' => $q, 'sort' => $sort, 'filter' => $filter, 'page' => $page], $over), fn($v) => $v !== '' && $v !== null));

$actions = is_admin_user()
    ? '<form method="post" style="display:inline">' . csrf_field() . '<input type="hidden" name="action" value="rebuild"><button class="btn btn-secondary" type="submit" data-confirm="Recalculate every customer from all orders? Notes and block flags are kept."><i class="fa-solid fa-arrows-rotate"></i> Rebuild from orders</button></form>'
    : '';
admin_header('Customers', 'customers', ['actions' => $actions]);
?>
<div class="grid-4">
  <div class="stat"><span>Customers</span><strong><?= count($all) ?></strong></div>
  <div class="stat"><span>Repeat customers</span><strong><?= $repeat ?></strong></div>
  <div class="stat"><span>Lifetime revenue</span><strong><?= e(money($totalSpent)) ?></strong></div>
  <div class="stat"><span>Avg. per customer</span><strong><?= e(money(count($all) ? $totalSpent / count($all) : 0)) ?></strong></div>
</div>

<div class="card">
  <form class="toolbar" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, phone or address">
    <select name="filter" data-autosubmit>
      <option value="">All customers</option>
      <option value="repeat" <?= $filter === 'repeat' ? 'selected' : '' ?>>Repeat customers</option>
      <option value="blocked" <?= $filter === 'blocked' ? 'selected' : '' ?>>Blocked</option>
    </select>
    <select name="sort" data-autosubmit>
      <?php foreach ($sorts as $k => [$l]): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>>Sort: <?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="fa-solid fa-users"></i><h3>No customers found</h3><p>Customers appear here automatically when orders are placed.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Customer</th><th>Phone</th><th class="text-right">Orders</th><th class="text-right">Total spent</th><th>Last order</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><a href="<?= admin_url('customer.php?id=' . urlencode($c['id'])) ?>"><strong><?= e($c['name'] ?? '—') ?></strong></a>
          <?php if (!empty($c['blocked'])): ?> <span class="badge badge-red">Blocked</span><?php endif; ?>
          <?php if (!empty($c['address'])): ?><div class="muted"><?= e(mb_strimwidth($c['address'], 0, 70, '…')) ?></div><?php endif; ?></td>
        <td><a href="tel:<?= e($c['phone']) ?>"><?= e($c['phone']) ?></a></td>
        <td class="text-right"><?= (int)($c['orders_count'] ?? 0) ?></td>
        <td class="text-right"><?= e(money($c['total_spent'] ?? 0)) ?></td>
        <td><?= !empty($c['last_order_at']) ? e(date('d M Y', strtotime($c['last_order_at']))) : '—' ?>
          <?php if (!empty($c['last_order_id'])): ?><div class="muted"><?= e($c['last_order_id']) ?></div><?php endif; ?></td>
        <td class="text-right"><a class="btn btn-sm btn-ghost" href="<?= admin_url('customer.php?id=' . urlencode($c['id'])) ?>">View <i class="fa-solid fa-chevron-right"></i></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
    <nav class="pagination">
      <?php if ($page > 1): ?><a href="<?= e($qs(['page' => $page - 1])) ?>">&larr; Prev</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= e($qs(['page' => $page + 1])) ?>">Next &rarr;</a><?php endif; ?>
    </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php admin_footer();
