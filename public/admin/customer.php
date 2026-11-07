<?php
/** Customer detail: orders, totals, contact links, internal notes, block flag. */
require __DIR__ . '/inc/admin.php';
require_perm('customers.view');

$id = (string)($_GET['id'] ?? '');
$c = $id !== '' ? store_get('customers', $id) : null;
if (!$c) { flash('Customer not found.', 'error'); redirect(admin_url('customers.php')); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can('customers.edit')) { flash('You don’t have permission to edit customers.', 'error'); redirect(admin_url('customer.php?id=' . urlencode($id))); }
    $notes = trim((string)($_POST['notes'] ?? ''));
    $blocked = !empty($_POST['blocked']);
    store_patch('customers', $c['id'], ['notes' => mb_substr($notes, 0, 2000), 'blocked' => $blocked]);
    $what = [];
    if (($c['notes'] ?? '') !== $notes) $what[] = 'notes';
    if (!empty($c['blocked']) !== $blocked) $what[] = $blocked ? 'blocked' : 'unblocked';
    log_activity($blocked && empty($c['blocked']) ? 'customer.block' : 'customer.update', 'Customer ' . ($c['name'] ?? '') . ' (' . $c['phone'] . ')' . ($what ? ': ' . implode(', ', $what) : ''), ['customer_id' => $c['id']]);
    flash('Customer saved.');
    redirect(admin_url('customer.php?id=' . urlencode($id)));
}

$orders = array_values(array_filter(store_all('orders'), fn($o) => trim((string)($o['phone'] ?? '')) === (string)$c['phone']));
usort($orders, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
$delivered = array_filter($orders, fn($o) => order_status_key($o['status'] ?? '') === 'delivered');
$cancelled = array_filter($orders, fn($o) => in_array(order_status_key($o['status'] ?? ''), ['cancelled', 'returned'], true));
$sum = array_sum(array_map(fn($o) => (float)($o['total'] ?? 0), $orders));

$digits = preg_replace('/\D/', '', (string)$c['phone']);
$wa = str_starts_with($digits, '01') ? '88' . $digits : $digits;
$canEdit = can('customers.edit');
$canOrders = can('orders.view');

admin_header($c['name'] ?? $c['phone'], 'customers', ['actions' => '<a class="btn btn-ghost" href="' . admin_url('customers.php') . '"><i class="fa-solid fa-arrow-left"></i> All customers</a>']);
?>
<?php if (!empty($c['blocked'])): ?><div class="alert alert-warning"><i class="fa-solid fa-ban"></i> This customer is marked as <strong>blocked</strong>.</div><?php endif; ?>
<div class="grid-4">
  <div class="stat"><span>Orders</span><strong><?= count($orders) ?></strong></div>
  <div class="stat"><span>Total value</span><strong><?= e(money($sum)) ?></strong></div>
  <div class="stat"><span>Delivered</span><strong><?= count($delivered) ?></strong></div>
  <div class="stat"><span>Cancelled / returned</span><strong><?= count($cancelled) ?></strong></div>
</div>

<div class="split">
  <div class="card">
    <div class="card-header"><h2>Orders</h2></div>
    <?php if (!$orders): ?>
      <div class="empty-state"><i class="fa-solid fa-bag-shopping"></i><p>No orders found for this number.</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Status</th><th class="text-right">Total</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): $oid = $o['id'] ?? ($o['order_id'] ?? ''); ?>
        <tr>
          <td><?php if ($canOrders): ?><a href="<?= admin_url('order.php?id=' . urlencode($oid)) ?>"><strong><?= e($oid) ?></strong></a><?php else: ?><strong><?= e($oid) ?></strong><?php endif; ?></td>
          <td><?= !empty($o['created_at']) ? e(date('d M Y, h:i A', strtotime($o['created_at']))) : '—' ?></td>
          <td><?= array_sum(array_map(fn($i) => (int)($i['quantity'] ?? 1), (array)($o['items'] ?? []))) ?></td>
          <td><?= status_badge($o['status'] ?? '') ?></td>
          <td class="text-right"><?= e(money($o['total'] ?? 0)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <aside class="stack">
    <div class="card">
      <div class="card-header"><h2>Contact</h2></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Name</dt><dd><?= e($c['name'] ?? '—') ?></dd>
          <dt>Phone</dt><dd><?= e($c['phone']) ?> <button type="button" class="icon-btn" data-copy="<?= e($c['phone']) ?>" title="Copy"><i class="fa-regular fa-copy"></i></button></dd>
          <dt>Address</dt><dd><?= nl2br(e($c['address'] ?? '—')) ?></dd>
          <dt>Last order</dt><dd><?= !empty($c['last_order_at']) ? e(date('d M Y', strtotime($c['last_order_at']))) : '—' ?></dd>
        </dl>
        <div class="page-actions">
          <a class="btn btn-secondary" href="tel:<?= e($c['phone']) ?>"><i class="fa-solid fa-phone"></i> Call</a>
          <?php if ($wa !== ''): ?><a class="btn btn-success" href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a><?php endif; ?>
        </div>
      </div>
    </div>
    <form class="card" method="post">
      <?= csrf_field() ?>
      <div class="card-header"><h2>Internal notes</h2></div>
      <div class="card-body">
        <div class="field"><textarea name="notes" rows="5" placeholder="Visible to staff only" <?= $canEdit ? '' : 'readonly' ?>><?= e($c['notes'] ?? '') ?></textarea></div>
        <label class="check"><input type="checkbox" name="blocked" value="1" <?= !empty($c['blocked']) ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> Block this customer <span class="muted">(e.g. repeated fake orders)</span></label>
      </div>
      <?php if ($canEdit): ?><div class="card-footer"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button></div><?php endif; ?>
    </form>
  </aside>
</div>
<?php admin_footer();
