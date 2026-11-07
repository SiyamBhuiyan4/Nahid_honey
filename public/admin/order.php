<?php
require __DIR__ . '/inc/admin.php';
require_perm('orders.view');

$id = strtoupper(trim((string)($_GET['id'] ?? $_POST['id'] ?? '')));
$order = $id !== '' ? store_get('orders', $id) : null;
if (!$order) {
    http_response_code(404);
    admin_header('Order not found', 'orders');
    echo '<div class="empty-state"><i class="fa-solid fa-magnifying-glass"></i><h2>Order not found</h2><p>No order with ID “' . e($id) . '” exists. It may have been deleted.</p><a class="btn" href="' . admin_url('orders.php') . '">Back to orders</a></div>';
    admin_footer();
    exit;
}
$self = admin_url('order.php?id=' . urlencode($order['id']));
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        if (!can('orders.delete')) { flash('You do not have permission to delete orders.', 'error'); redirect($self); }
        // Return items to stock unless that already happened (cancel/return) or the goods were delivered.
        $restock = !empty($_POST['restock']) && empty($order['restocked']) && order_status_key($order['status'] ?? '') !== 'delivered';
        if ($restock) order_adjust_stock($order, +1);
        store_delete('orders', $order['id']);
        log_activity('order.delete', "Deleted order {$order['id']} ({$order['name']}, " . money($order['total'] ?? 0) . ')' . ($restock ? ' and restocked items' : ''), ['order_id' => $order['id']]);
        flash("Order {$order['id']} was deleted.");
        redirect(admin_url('orders.php'));
    }

    if (!can('orders.edit')) { flash('You do not have permission to edit orders.', 'error'); redirect($self); }

    switch ($action) {
        case 'status':
            $new = (string)($_POST['status'] ?? '');
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 1000));
            $old = order_status_key($order['status'] ?? '');
            if (!isset(order_statuses()[$new])) { flash('Choose a valid status.', 'error'); break; }
            if ($new === $old && $note === '') { flash('Status unchanged.', 'info'); break; }
            $wasRestocked = !empty($order['restocked']);
            $order = order_set_status($order, $new, $note);
            $msg = $new === $old ? 'Note added to the timeline.' : 'Status changed to ' . order_status_label($new) . '.';
            if (!$wasRestocked && !empty($order['restocked'])) $msg .= ' Items were returned to stock.';
            if ($wasRestocked && empty($order['restocked'])) $msg .= ' Items were taken out of stock again.';
            log_activity('order.status', "Order {$order['id']}: " . order_status_label($old) . ' → ' . order_status_label($new) . ($note !== '' ? " ($note)" : ''), ['order_id' => $order['id'], 'from' => $old, 'to' => $new]);
            flash($msg);
            break;

        case 'payment':
            $ps = (string)($_POST['payment_status'] ?? '');
            if (!isset(payment_statuses()[$ps])) { flash('Choose a valid payment status.', 'error'); break; }
            $ref = trim(mb_substr((string)($_POST['payment_ref'] ?? ''), 0, 120));
            store_patch('orders', $order['id'], ['payment_status' => $ps, 'payment_ref' => $ref]);
            log_activity('order.payment', "Order {$order['id']}: payment marked " . payment_statuses()[$ps][0] . ($ref !== '' ? " (ref $ref)" : ''), ['order_id' => $order['id']]);
            flash('Payment updated.');
            break;

        case 'shipping':
            $courier = trim(mb_substr((string)($_POST['courier'] ?? ''), 0, 80));
            $tracking = trim(mb_substr((string)($_POST['tracking_number'] ?? ''), 0, 120));
            store_patch('orders', $order['id'], ['courier' => $courier, 'tracking_number' => $tracking]);
            log_activity('order.shipping', "Order {$order['id']}: courier “{$courier}”, tracking “{$tracking}”", ['order_id' => $order['id']]);
            flash('Delivery details saved.');
            break;

        case 'assign':
            $uid = (string)($_POST['assigned_to'] ?? '');
            $au = $uid !== '' ? store_get('users', $uid) : null;
            if ($uid !== '' && !$au) { flash('That employee no longer exists.', 'error'); break; }
            store_patch('orders', $order['id'], ['assigned_to' => $uid === '' ? null : $uid]);
            log_activity('order.assign', "Order {$order['id']} assigned to " . ($au['name'] ?? 'nobody'), ['order_id' => $order['id'], 'user_id' => $uid]);
            flash($au ? 'Assigned to ' . ($au['name'] ?? '') . '.' : 'Order unassigned.');
            break;

        case 'notes':
            $notes = trim(mb_substr((string)($_POST['admin_notes'] ?? ''), 0, 4000));
            store_patch('orders', $order['id'], ['admin_notes' => $notes]);
            log_activity('order.notes', "Order {$order['id']}: internal notes updated", ['order_id' => $order['id']]);
            flash('Internal notes saved.');
            break;

        case 'customer':
            $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 120));
            $phone = preg_replace('/[^\d+]/', '', (string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim(mb_substr((string)($_POST['address'] ?? ''), 0, 500));
            if ($name === '' || $phone === '' || $address === '') { flash('Name, phone and address are required.', 'error'); break; }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('That email address is not valid.', 'error'); break; }
            $changes = ['name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address];
            $zoneKey = (string)($_POST['zone'] ?? '');
            $zones = (array)($SITE['delivery_zones'] ?? []);
            if ($zoneKey !== '' && isset($zones[$zoneKey]) && ($zones[$zoneKey]['label'] ?? '') !== ($order['zone'] ?? '')) {
                $changes['zone'] = $zones[$zoneKey]['label'];
                if (!empty($_POST['update_fee'])) {
                    $fee = (float)($zones[$zoneKey]['fee'] ?? 0);
                    $changes['delivery_fee'] = $fee;
                    $changes['total'] = max(0, (float)($order['subtotal'] ?? 0) - (float)($order['discount'] ?? 0) + $fee);
                }
            }
            $diff = array_keys(array_filter($changes, fn($v, $k) => (string)($order[$k] ?? '') !== (string)$v, ARRAY_FILTER_USE_BOTH));
            store_patch('orders', $order['id'], $changes);
            log_activity('order.customer', "Order {$order['id']}: updated " . ($diff ? implode(', ', $diff) : 'customer details'), ['order_id' => $order['id'], 'fields' => $diff]);
            flash('Customer details saved.');
            break;

        default:
            flash('Unknown action.', 'error');
    }
    redirect($self);
}

// ---- View ----
$statuses = order_statuses();
$curKey = order_status_key($order['status'] ?? '');
$items = (array)($order['items'] ?? []);
$history = array_reverse((array)($order['history'] ?? []));
if (!$history) $history = [['status' => $curKey, 'at' => $order['created_at'] ?? '', 'by' => 'Customer', 'note' => '']];
$canEdit = can('orders.edit');
$assignable = admin_assignable_users();
$assigned = !empty($order['assigned_to']) ? store_get('users', $order['assigned_to']) : null;

$phoneDigits = preg_replace('/\D/', '', (string)($order['phone'] ?? ''));
$waNumber = str_starts_with($phoneDigits, '0') ? '88' . $phoneDigits : $phoneDigits;
$zoneKeyNow = '';
foreach ((array)($SITE['delivery_zones'] ?? []) as $k => $z) if (($z['label'] ?? '') === ($order['zone'] ?? '')) $zoneKeyNow = $k;
$productsById = [];
foreach (store_all('products') as $p) $productsById[(string)$p['id']] = $p;

$actions = '<a class="btn" href="' . e(admin_url('order-print.php?id=' . urlencode($order['id']))) . '" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i><span class="label">Invoice</span></a>'
         . '<a class="btn btn-icon" href="' . e(admin_url('order-print.php?type=slip&id=' . urlencode($order['id']))) . '" target="_blank" rel="noopener" title="Packing slip" aria-label="Packing slip"><i class="fa-solid fa-box"></i></a>';
admin_header('Order ' . $order['id'], 'orders', ['actions' => $actions]);
?>
<div class="split">
  <div class="row">
    <a class="btn btn-ghost btn-sm" href="<?= admin_url('orders.php') ?>"><i class="fa-solid fa-arrow-left"></i> Orders</a>
    <?= status_badge($curKey) ?> <?= payment_badge($order['payment_status'] ?? 'unpaid') ?>
    <span class="chip"><i class="fa-regular fa-clock"></i> <?= e(admin_date($order['created_at'] ?? '')) ?></span>
    <span class="chip"><i class="fa-solid fa-globe"></i> <?= e(order_sources()[$order['source'] ?? 'website'] ?? ($order['source'] ?? '')) ?><?= !empty($order['created_by']) && ($order['source'] ?? '') !== 'website' ? ' · ' . e($order['created_by']) : '' ?></span>
    <?php if (!empty($order['restocked'])): ?><span class="chip"><i class="fa-solid fa-rotate-left"></i> Items returned to stock</span><?php endif; ?>
  </div>
  <button class="btn btn-sm btn-ghost" type="button" data-copy="<?= e($order['id']) ?>" data-copy-msg="Order ID copied"><i class="fa-regular fa-copy"></i> Copy ID</button>
</div>

<div class="layout-main-side">
  <div class="stack">
    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-basket-shopping"></i> Items</h2><span class="muted small"><?= count($items) ?> line<?= count($items) === 1 ? '' : 's' ?></span></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Product</th><th class="text-right">Price</th><th class="text-right">Qty</th><th class="text-right">Total</th></tr></thead>
          <tbody>
            <?php foreach ($items as $it):
              $p = $productsById[(string)($it['id'] ?? '')] ?? null;
              $img = ($it['image'] ?? '') ?: ($p ? normalize_product($p)['image'] : ''); ?>
              <tr>
                <td><div class="cell-main">
                  <?php if ($img): ?><img class="thumb" src="<?= e($img) ?>" alt="" loading="lazy"><?php endif; ?>
                  <div><strong><?= e($it['title'] ?? '') ?></strong><small><?= e($it['unit'] ?? '') ?><?php if ($p && product_tracks_stock($p)): ?> · <?= (int)$p['stock'] ?> in stock<?php endif; ?></small></div>
                </div></td>
                <td class="text-right nowrap"><?= money($it['price'] ?? 0) ?></td>
                <td class="text-right">× <?= (int)($it['quantity'] ?? 0) ?></td>
                <td class="text-right strong nowrap"><?= money($it['line_total'] ?? ((float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body" style="border-top:1px solid var(--border)">
        <div class="kv-rows" style="max-width:340px;margin-left:auto">
          <div><span class="muted">Subtotal</span><span><?= money($order['subtotal'] ?? 0) ?></span></div>
          <?php if (!empty($order['discount'])): ?>
            <div><span class="muted">Discount<?= !empty($order['coupon_code']) ? ' (' . e($order['coupon_code']) . ')' : '' ?></span><span class="text-green">−<?= money($order['discount']) ?></span></div>
          <?php endif; ?>
          <div><span class="muted">Delivery<?= !empty($order['zone']) ? ' · ' . e($order['zone']) : '' ?></span><span><?= money($order['delivery_fee'] ?? 0) ?></span></div>
          <div class="total"><span>Total</span><span><?= money($order['total'] ?? 0) ?></span></div>
        </div>
      </div>
    </div>

    <?php if (!empty($order['notes'])): ?>
    <div class="alert alert-info"><div><strong>Customer note:</strong> <?= nl2br(e($order['notes'])) ?></div></div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-arrows-rotate"></i> Update status</h2></div>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <div class="status-picker">
          <?php foreach ($statuses as $k => $s): ?>
            <label class="status-opt tone-<?= e($s['tone']) ?>">
              <input type="radio" name="status" value="<?= $k ?>" <?= $k === $curKey ? 'checked' : '' ?>>
              <span><?= e($s['label']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="field">
          <label for="note">Note <span class="muted" style="font-weight:400">(optional — shown in the timeline)</span></label>
          <textarea id="note" name="note" rows="2" placeholder="e.g. Customer confirmed by phone, handed to courier…" style="min-height:64px"></textarea>
        </div>
        <div class="split">
          <span class="hint muted small">Cancelling or marking returned puts tracked-stock items back in stock.</span>
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Save status</button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-timeline"></i> Timeline</h2></div>
      <div class="card-body">
        <ul class="timeline">
          <?php foreach ($history as $h): $hk = order_status_key($h['status'] ?? ''); ?>
            <li>
              <span class="dot tone-<?= e($statuses[$hk]['tone']) ?>"></span>
              <strong><?= e($statuses[$hk]['label']) ?></strong>
              <small><?= e(admin_date($h['at'] ?? '')) ?><?= !empty($h['by']) ? ' · by ' . e($h['by']) : '' ?></small>
              <?php if (!empty($h['note'])): ?><div class="note"><?= e($h['note']) ?></div><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-user"></i> Customer</h2>
        <?php if ($canEdit): ?><button class="btn btn-sm btn-ghost" type="button" data-modal-open="customerModal"><i class="fa-solid fa-pen"></i> Edit</button><?php endif; ?></div>
      <div class="card-body stack-sm">
        <strong style="font-size:15px"><?= e($order['name'] ?? '') ?></strong>
        <?php if (!empty($order['phone'])): ?>
          <div class="row">
            <a class="mono" href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a>
            <a class="btn btn-sm" href="tel:<?= e($order['phone']) ?>"><i class="fa-solid fa-phone"></i> Call</a>
            <?php if ($waNumber !== ''): ?><a class="btn btn-sm" href="https://wa.me/<?= e($waNumber) ?>?text=<?= rawurlencode('Hello ' . ($order['name'] ?? '') . ', about your ' . $SITE['brand_name'] . ' order ' . $order['id']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" style="color:#25D366"></i> WhatsApp</a><?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($order['email'])): ?><a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a><?php endif; ?>
        <div class="muted" style="white-space:pre-line"><?= e($order['address'] ?? '') ?></div>
        <?php if (!empty($order['zone'])): ?><span class="chip" style="align-self:flex-start"><i class="fa-solid fa-location-dot"></i> <?= e($order['zone']) ?></span><?php endif; ?>
        <?php $cnt = !empty($order['phone']) ? count(array_filter(store_all('orders'), fn($x) => ($x['phone'] ?? '') === $order['phone'])) : 0;
          if ($cnt > 1): ?>
          <a class="small" href="<?= e(admin_url('orders.php?q=' . urlencode($order['phone']))) ?>"><?= $cnt ?> order<?= $cnt === 1 ? '' : 's' ?> from this number <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-wallet"></i> Payment</h2><?= payment_badge($order['payment_status'] ?? 'unpaid') ?></div>
      <?php if ($canEdit): ?>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="payment">
        <div class="kv"><dt>Method</dt><dd><?= e($order['payment_method'] ?? '—') ?></dd><dt>Amount</dt><dd class="strong"><?= money($order['total'] ?? 0) ?></dd></div>
        <div class="field"><label for="payment_status">Payment status</label>
          <select id="payment_status" name="payment_status">
            <?php foreach (payment_statuses() as $k => [$label]): ?><option value="<?= $k ?>" <?= ($order['payment_status'] ?? 'unpaid') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="payment_ref">Transaction ID / reference</label>
          <input id="payment_ref" name="payment_ref" value="<?= e($order['payment_ref'] ?? '') ?>" placeholder="e.g. bKash TrxID"></div>
        <button class="btn btn-block" type="submit">Save payment</button>
      </form>
      <?php else: ?>
      <div class="card-body"><div class="kv"><dt>Method</dt><dd><?= e($order['payment_method'] ?? '—') ?></dd><?php if (!empty($order['payment_ref'])): ?><dt>Reference</dt><dd><?= e($order['payment_ref']) ?></dd><?php endif; ?></div></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-truck-fast"></i> Delivery</h2></div>
      <?php if ($canEdit): ?>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="shipping">
        <div class="field"><label for="courier">Courier</label>
          <input id="courier" name="courier" value="<?= e($order['courier'] ?? '') ?>" list="courierList" placeholder="Courier name">
          <datalist id="courierList">
            <?php $seen = []; foreach (store_all('orders') as $x) { $c = trim((string)($x['courier'] ?? '')); if ($c !== '' && !isset($seen[$c])) { $seen[$c] = 1; echo '<option value="' . e($c) . '">'; } } ?>
          </datalist></div>
        <div class="field"><label for="tracking_number">Tracking number</label>
          <div class="input-group">
            <input id="tracking_number" name="tracking_number" value="<?= e($order['tracking_number'] ?? '') ?>" placeholder="Consignment / tracking ID">
            <?php if (!empty($order['tracking_number'])): ?><button class="btn" type="button" data-copy="<?= e($order['tracking_number']) ?>" title="Copy"><i class="fa-regular fa-copy"></i></button><?php endif; ?>
          </div></div>
        <button class="btn btn-block" type="submit">Save delivery details</button>
      </form>
      <?php else: ?>
      <div class="card-body"><div class="kv"><dt>Courier</dt><dd><?= e(($order['courier'] ?? '') ?: '—') ?></dd><dt>Tracking</dt><dd><?= e(($order['tracking_number'] ?? '') ?: '—') ?></dd></div></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-user-check"></i> Assigned to</h2></div>
      <?php if ($canEdit): ?>
      <form method="post" class="card-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="assign">
        <select name="assigned_to" data-autosubmit aria-label="Assign to">
          <option value="">Unassigned</option>
          <?php foreach ($assignable as $au): ?><option value="<?= e($au['id']) ?>" <?= ($order['assigned_to'] ?? '') === $au['id'] ? 'selected' : '' ?>><?= e($au['name'] ?? '') ?><?= $au['id'] === ($me['id'] ?? '') ? ' (me)' : '' ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="btn btn-sm" type="submit" style="margin-top:8px">Save</button></noscript>
      </form>
      <?php else: ?>
      <div class="card-body"><?= $assigned ? e($assigned['name'] ?? '') : '<span class="muted">Nobody yet</span>' ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h2><i class="fa-solid fa-note-sticky"></i> Internal notes</h2></div>
      <?php if ($canEdit): ?>
      <form method="post" class="card-body stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="notes">
        <textarea name="admin_notes" rows="4" placeholder="Only your team can see this."><?= e($order['admin_notes'] ?? '') ?></textarea>
        <button class="btn btn-block" type="submit">Save notes</button>
      </form>
      <?php else: ?>
      <div class="card-body" style="white-space:pre-line"><?= ($order['admin_notes'] ?? '') !== '' ? e($order['admin_notes']) : '<span class="muted">No notes.</span>' ?></div>
      <?php endif; ?>
    </div>

    <?php if (can('orders.delete')): ?>
    <form method="post" class="card" data-confirm="Delete order <?= e($order['id']) ?> permanently? This cannot be undone." data-confirm-title="Delete order" data-confirm-ok="Delete order">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <div class="card-body stack-sm">
        <strong class="text-red">Danger zone</strong>
        <?php if (empty($order['restocked']) && $curKey !== 'delivered'): ?>
          <label class="check"><input type="checkbox" name="restock" value="1" checked> Return items to stock</label>
        <?php endif; ?>
        <button class="btn btn-danger btn-block" type="submit"><i class="fa-solid fa-trash"></i> Delete order</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="modal" id="customerModal" hidden>
  <form class="modal-card" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="customer">
    <div class="modal-header"><h3>Edit customer &amp; address</h3><button class="icon-btn" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label for="c_name">Name <span class="req">*</span></label><input id="c_name" name="name" value="<?= e($order['name'] ?? '') ?>" required></div>
        <div class="field"><label for="c_phone">Phone <span class="req">*</span></label><input id="c_phone" name="phone" type="tel" value="<?= e($order['phone'] ?? '') ?>" required></div>
        <div class="field span-2"><label for="c_email">Email</label><input id="c_email" name="email" type="email" value="<?= e($order['email'] ?? '') ?>"></div>
        <div class="field span-2"><label for="c_address">Address <span class="req">*</span></label><textarea id="c_address" name="address" rows="3" required><?= e($order['address'] ?? '') ?></textarea></div>
        <?php if (!empty($SITE['delivery_zones'])): ?>
        <div class="field"><label for="c_zone">Delivery zone</label>
          <select id="c_zone" name="zone">
            <?php if ($zoneKeyNow === ''): ?><option value=""><?= e(($order['zone'] ?? '') ?: '—') ?> (current)</option><?php endif; ?>
            <?php foreach ($SITE['delivery_zones'] as $k => $z): ?><option value="<?= e($k) ?>" <?= $k === $zoneKeyNow ? 'selected' : '' ?>><?= e($z['label']) ?> — <?= money($z['fee']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="justify-content:flex-end"><label class="check"><input type="checkbox" name="update_fee" value="1" checked> Update delivery fee &amp; total if the zone changes</label></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-footer"><button class="btn" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save changes</button></div>
  </form>
</div>
<?php endif; ?>
<?php admin_footer();
