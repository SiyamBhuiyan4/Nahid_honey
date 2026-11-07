<?php
require __DIR__ . '/inc/admin.php';
require_perm('orders.view');

// ---- Bulk actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $back = (string)($_POST['back'] ?? '');
    $back = str_starts_with($back, admin_url('orders.php')) ? $back : admin_url('orders.php');
    if (!can('orders.edit')) { flash('You do not have permission to update orders.', 'error'); redirect($back); }
    $ids = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['ids'] ?? [])))));
    $action = (string)($_POST['bulk_action'] ?? '');
    if (!$ids || $action === '') { flash('Select at least one order and an action.', 'warning'); redirect($back); }

    $done = 0;
    $note = trim((string)($_POST['bulk_note'] ?? ''));
    foreach ($ids as $id) {
        $o = store_get('orders', $id);
        if (!$o) continue;
        if (str_starts_with($action, 'status:')) {
            $key = substr($action, 7);
            if (!isset(order_statuses()[$key]) || order_status_key($o['status'] ?? '') === $key) continue;
            order_set_status($o, $key, $note);
            $done++;
        } elseif (str_starts_with($action, 'payment:')) {
            $ps = substr($action, 8);
            if (!isset(payment_statuses()[$ps]) || ($o['payment_status'] ?? 'unpaid') === $ps) continue;
            store_patch('orders', $id, ['payment_status' => $ps]);
            $done++;
        } elseif (str_starts_with($action, 'assign:')) {
            $uid = substr($action, 7);
            if ($uid !== '' && !store_get('users', $uid)) continue;
            store_patch('orders', $id, ['assigned_to' => $uid === '' ? null : $uid]);
            $done++;
        }
    }
    log_activity('order.bulk_update', "Bulk action “{$action}” on {$done} order(s)", ['ids' => $ids, 'action' => $action]);
    flash($done ? "Updated {$done} order" . ($done === 1 ? '' : 's') . '.' : 'Nothing to change — the selected orders already had that value.', $done ? 'success' : 'info');
    redirect($back);
}

$f = admin_order_filters();
$all = admin_orders_sorted();
$base = array_values(array_filter($all, fn($o) => order_matches($o, $f, true)));
$counts = array_fill_keys(array_keys(order_statuses()), 0);
foreach ($base as $o) $counts[order_status_key($o['status'] ?? '')]++;
$rows = $f['status'] === '' ? $base : array_values(array_filter($base, fn($o) => order_status_key($o['status'] ?? '') === $f['status']));
$filteredTotal = array_sum(array_map(fn($o) => order_counts_as_sale($o) ? (float)($o['total'] ?? 0) : 0, $rows));
$per = in_array((int)($_GET['per'] ?? 0), [25, 50, 100], true) ? (int)$_GET['per'] : 25;
[$pageRows, $page, $pages, $total] = admin_paginate($rows, $per);

$users = [];
foreach (store_all('users') as $u) $users[$u['id']] = $u;
$assignable = admin_assignable_users();
$hasFilters = $f['q'] !== '' || $f['payment'] !== '' || $f['source'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['assigned'] !== '';
$canEdit = can('orders.edit');

$actions = '';
if (can('orders.export')) $actions .= '<a class="btn" href="' . e(admin_qs([], admin_url('orders-export.php'))) . '"><i class="fa-solid fa-file-csv"></i><span class="label">Export</span></a>';
if (can('orders.create')) $actions .= '<a class="btn btn-primary" href="' . admin_url('order-new.php') . '"><i class="fa-solid fa-plus"></i><span class="label">New order</span></a>';
admin_header('Orders', 'orders', ['actions' => $actions]);
?>
<div class="card">
  <nav class="tabs" aria-label="Order status">
    <a class="tab <?= $f['status'] === '' ? 'is-active' : '' ?>" href="<?= e(admin_qs(['status' => null, 'page' => null])) ?>">All <span class="count"><?= count($base) ?></span></a>
    <?php foreach (order_statuses() as $k => $s): ?>
      <a class="tab <?= $f['status'] === $k ? 'is-active' : '' ?>" href="<?= e(admin_qs(['status' => $k, 'page' => null])) ?>"><?= e($s['label']) ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <form class="toolbar" method="get" action="">
    <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
    <div class="search"><i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search order ID, name, phone or tracking no.">
    </div>
    <select name="payment" data-autosubmit aria-label="Payment status">
      <option value="">Any payment</option>
      <?php foreach (payment_statuses() as $k => [$label]): ?><option value="<?= $k ?>" <?= $f['payment'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <select name="source" data-autosubmit aria-label="Source">
      <option value="">Any source</option>
      <?php foreach (order_sources() as $k => $label): ?><option value="<?= $k ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
    <?php if (count($assignable) > 1): ?>
    <select name="assigned" data-autosubmit aria-label="Assigned to">
      <option value="">Anyone</option>
      <option value="none" <?= $f['assigned'] === 'none' ? 'selected' : '' ?>>Unassigned</option>
      <?php foreach ($assignable as $au): ?><option value="<?= e($au['id']) ?>" <?= $f['assigned'] === $au['id'] ? 'selected' : '' ?>><?= e($au['name'] ?? '') ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date" title="From">
    <input type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date" title="To">
    <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(admin_url('orders.php' . ($f['status'] ? '?status=' . $f['status'] : ''))) ?>">Clear</a><?php endif; ?>
  </form>

  <?php if ($pageRows): ?>
  <form method="post" action="" id="bulkForm">
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '') ?>">
    <div class="bulk-bar" data-bulk-show hidden>
      <span><strong data-bulk-count>0</strong> selected</span>
      <?php if ($canEdit): ?>
        <select name="bulk_action" aria-label="Bulk action">
          <option value="">Choose action…</option>
          <optgroup label="Change status">
            <?php foreach (order_statuses() as $k => $s): ?><option value="status:<?= $k ?>">Mark as <?= e($s['label']) ?></option><?php endforeach; ?>
          </optgroup>
          <optgroup label="Payment">
            <?php foreach (payment_statuses() as $k => [$label]): ?><option value="payment:<?= $k ?>">Payment: <?= e($label) ?></option><?php endforeach; ?>
          </optgroup>
          <?php if (count($assignable) > 1): ?>
          <optgroup label="Assign to">
            <option value="assign:">Unassign</option>
            <?php foreach ($assignable as $au): ?><option value="assign:<?= e($au['id']) ?>"><?= e($au['name'] ?? '') ?></option><?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
        <button class="btn btn-sm btn-primary" type="submit" data-confirm="Apply this action to the selected orders?" data-confirm-ok="Apply" data-confirm-tone="primary" data-confirm-title="Update orders">Apply</button>
      <?php endif; ?>
      <button class="btn btn-sm" type="button" data-print="invoice"><i class="fa-solid fa-print"></i> Print invoices</button>
      <button class="btn btn-sm" type="button" data-print="slip"><i class="fa-solid fa-box"></i> Packing slips</button>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr>
          <th class="check"><input type="checkbox" data-check-all aria-label="Select all"></th>
          <th>Order</th><th>Date</th><th>Customer</th><th class="text-right">Items</th><th class="text-right">Total</th><th>Payment</th><th>Status</th><th>Assigned</th>
        </tr></thead>
        <tbody>
          <?php foreach ($pageRows as $o):
            $url = admin_url('order.php?id=' . urlencode($o['id']));
            $qty = array_sum(array_map(fn($i) => (int)($i['quantity'] ?? 0), $o['items'] ?? []));
            $asg = $users[$o['assigned_to'] ?? ''] ?? null; ?>
            <tr data-href="<?= e($url) ?>" style="cursor:pointer">
              <td class="check"><input type="checkbox" name="ids[]" value="<?= e($o['id']) ?>" aria-label="Select <?= e($o['id']) ?>"></td>
              <td class="nowrap"><a class="row-link mono" href="<?= e($url) ?>"><?= e($o['id']) ?></a>
                <small><?= e(order_sources()[$o['source'] ?? 'website'] ?? ($o['source'] ?? '')) ?></small></td>
              <td class="nowrap"><?= e(admin_date($o['created_at'] ?? '', 'j M Y')) ?><small><?= e(admin_date($o['created_at'] ?? '', 'g:i a')) ?></small></td>
              <td><strong><?= e($o['name'] ?? '') ?></strong><small><?= e($o['phone'] ?? '') ?><?= !empty($o['zone']) ? ' · ' . e($o['zone']) : '' ?></small></td>
              <td class="text-right"><?= $qty ?></td>
              <td class="text-right strong nowrap"><?= money($o['total'] ?? 0) ?></td>
              <td><?= payment_badge($o['payment_status'] ?? 'unpaid') ?><small><?= e($o['payment_method'] ?? '') ?></small></td>
              <td><?= status_badge($o['status'] ?? '') ?></td>
              <td class="nowrap"><?= $asg ? e($asg['name'] ?? '') : '<span class="muted">—</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>
  <div class="split" style="padding:0 20px 0 0">
    <?= admin_pagination($page, $pages, $total, $per) ?>
    <div class="row small muted" style="padding:14px 0 14px 20px">
      <span>Value (excl. cancelled): <strong style="color:var(--text)"><?= money($filteredTotal) ?></strong></span>
      <form method="get" action="" class="row">
        <?php foreach ($_GET as $k => $v): if ($k === 'per' || $k === 'page' || is_array($v)) continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
        <select name="per" data-autosubmit style="height:30px;width:auto" aria-label="Per page">
          <?php foreach ([25, 50, 100] as $n): ?><option value="<?= $n ?>" <?= $per === $n ? 'selected' : '' ?>><?= $n ?> / page</option><?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>
  <?php else: ?>
    <div class="empty-state">
      <i class="fa-solid fa-bag-shopping"></i>
      <?php if ($hasFilters || $f['status'] !== ''): ?>
        <h2>No orders match</h2><p>Try a different search or clear the filters.</p>
        <a class="btn" href="<?= admin_url('orders.php') ?>">Clear filters</a>
      <?php else: ?>
        <h2>No orders yet</h2><p>Orders placed on the website or entered by your team will appear here.</p>
        <?php if (can('orders.create')): ?><a class="btn btn-primary" href="<?= admin_url('order-new.php') ?>"><i class="fa-solid fa-plus"></i> Create an order</a><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-print]'); if (!b) return;
  var ids = Array.prototype.map.call(document.querySelectorAll('#bulkForm tbody input[name="ids[]"]:checked'), function (c) { return c.value; });
  if (!ids.length) return;
  window.open(<?= json_encode(admin_url('order-print.php')) ?> + '?type=' + b.getAttribute('data-print') + '&ids=' + encodeURIComponent(ids.join(',')), '_blank');
});
</script>
<?php admin_footer();
