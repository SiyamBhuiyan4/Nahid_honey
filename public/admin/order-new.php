<?php
require __DIR__ . '/inc/admin.php';
require_perm('orders.create');

$zones = (array)($SITE['delivery_zones'] ?? []);
$payments = (array)($SITE['payment_methods'] ?? []);
$products = all_products(true);
$byId = [];
foreach ($products as $p) $byId[(string)$p['id']] = $p;
$raw = [];
foreach (store_all('products') as $p) $raw[(string)$p['id']] = $p;

$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 120));
    $digits = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
    $phone = preg_match('/^(?:88)?(01[3-9]\d{8})$/', $digits, $m) ? $m[1] : '';
    $email = trim((string)($_POST['email'] ?? ''));
    $address = trim(mb_substr((string)($_POST['address'] ?? ''), 0, 500));
    $zoneKey = (string)($_POST['zone'] ?? '');
    $payKey = (string)($_POST['payment_method'] ?? '');
    $source = ($_POST['source'] ?? 'phone') === 'admin' ? 'admin' : 'phone';

    if ($name === '') $errors['name'] = 'Enter the customer’s name.';
    if ($phone === '') $errors['phone'] = 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'That email address is not valid.';
    if ($address === '') $errors['address'] = 'Enter the delivery address.';
    if ($zones && !isset($zones[$zoneKey])) $errors['zone'] = 'Choose a delivery zone.';
    if ($payments && !isset($payments[$payKey])) $errors['payment_method'] = 'Choose a payment method.';

    // Lines are re-priced from the catalogue; staff may override a unit price.
    $items = [];
    $ids = (array)($_POST['item_id'] ?? []);
    $qtys = (array)($_POST['item_qty'] ?? []);
    $prices = (array)($_POST['item_price'] ?? []);
    foreach ($ids as $i => $pid) {
        $p = $byId[(string)$pid] ?? null;
        $qty = max(0, min(999, (int)($qtys[$i] ?? 0)));
        if (!$p || $qty < 1) continue;
        $price = isset($prices[$i]) && $prices[$i] !== '' && is_numeric($prices[$i]) ? max(0, (float)$prices[$i]) : (float)$p['price'];
        $key = $p['id'] . '|' . $price;
        if (isset($items[$key])) { $items[$key]['quantity'] += $qty; $items[$key]['line_total'] = $items[$key]['quantity'] * $price; continue; }
        $items[$key] = ['id' => $p['id'], 'title' => $p['title'], 'unit' => $p['unit'], 'quantity' => $qty, 'price' => $price,
                        'line_total' => $qty * $price, 'image' => str_starts_with($p['image'], 'data:') ? '' : $p['image']];
    }
    $items = array_values($items);
    if (!$items) $errors['items'] = 'Add at least one product.';
    foreach ($items as $it) {
        $rp = $raw[(string)$it['id']] ?? null;
        if (product_tracks_stock($rp) && (int)$rp['stock'] < $it['quantity'] && empty($_POST['allow_oversell'])) {
            $errors['items'] = "Only " . (int)$rp['stock'] . " of “{$it['title']}” in stock. Reduce the quantity or tick “Allow selling beyond stock”.";
        }
    }

    $subtotal = array_sum(array_column($items, 'line_total'));
    $fee = $zones && isset($zones[$zoneKey]) ? (float)$zones[$zoneKey]['fee'] : 0.0;
    if (isset($_POST['delivery_fee']) && $_POST['delivery_fee'] !== '' && is_numeric($_POST['delivery_fee'])) $fee = max(0, (float)$_POST['delivery_fee']);
    $discount = max(0, (float)($_POST['discount'] ?? 0));
    $couponCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
    $coupon = null;
    if ($couponCode !== '') {
        $ev = evaluate_coupon($couponCode, $subtotal, $fee);
        if (!$ev['ok']) $errors['coupon_code'] = $ev['error'];
        else {
            $coupon = $ev['coupon'];
            $discount += (float)$ev['discount'];
            if (!empty($ev['free_shipping'])) $fee = 0;
        }
    }
    $discount = min($discount, $subtotal);

    if (!$errors) {
        $me = current_user();
        $data = [
            'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address,
            'zone' => $zones[$zoneKey]['label'] ?? '', 'delivery_fee' => $fee,
            'payment_method' => $payments[$payKey] ?? '',
            'payment_status' => isset(payment_statuses()[$_POST['payment_status'] ?? '']) ? $_POST['payment_status'] : 'unpaid',
            'notes' => trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 1000)),
            'admin_notes' => trim(mb_substr((string)($_POST['admin_notes'] ?? ''), 0, 4000)),
            'items' => $items, 'subtotal' => $subtotal, 'discount' => $discount,
            'coupon_code' => $coupon ? strtoupper($coupon['code']) : '',
            'total' => max(0, $subtotal - $discount + $fee),
            'currency' => 'BDT',
            'created_by' => $me['name'] ?? 'Admin', 'created_by_id' => $me['id'] ?? null,
            'assigned_to' => !empty($_POST['assign_me']) ? ($me['id'] ?? null) : null,
        ];
        $order = create_order_record($data, $source);
        if ($coupon) mark_coupon_used($coupon['id']);
        $initial = (string)($_POST['initial_status'] ?? 'pending');
        if ($initial !== 'pending' && in_array($initial, ['confirmed', 'processing'], true)) {
            $order = order_set_status($order, $initial, 'Set when the order was created');
        }
        log_activity('order.create', "Created {$source} order {$order['id']} for {$name} (" . money($order['total']) . ')', ['order_id' => $order['id']]);
        flash("Order {$order['id']} created.");
        redirect(admin_url('order.php?id=' . urlencode($order['id'])));
    }
}

$catalog = array_map(fn($p) => [
    'id' => (string)$p['id'], 'title' => $p['title'], 'unit' => $p['unit'], 'price' => $p['price'],
    'image' => $p['image'], 'category' => $p['category'], 'sku' => $p['sku'],
    'stock' => product_tracks_stock($raw[(string)$p['id']] ?? null) ? (int)$raw[(string)$p['id']]['stock'] : null,
    'hidden' => $p['status'] !== 'published', 'in_stock' => $p['in_stock'],
], $products);

// Re-fill lines after a validation error
$prefill = [];
foreach ((array)($old['item_id'] ?? []) as $i => $pid) {
    if (isset($byId[(string)$pid])) $prefill[] = ['id' => (string)$pid, 'qty' => (int)($old['item_qty'][$i] ?? 1), 'price' => (string)($old['item_price'][$i] ?? '')];
}
$v = fn($k, $d = '') => e($old[$k] ?? $d);
$err = fn($k) => isset($errors[$k]) ? '<p class="error">' . e($errors[$k]) . '</p>' : '';
$firstZone = array_key_first($zones);

admin_header('New order', 'orders');
?>
<?php if ($errors): ?><div class="alert alert-error"><div>Please fix the highlighted fields.<?php if (isset($errors['items'])): ?><br><?= e($errors['items']) ?><?php endif; ?></div></div><?php endif; ?>

<form method="post" id="newOrder" autocomplete="off" novalidate>
  <?= csrf_field() ?>
  <div class="layout-main-side">
    <div class="stack">
      <div class="card">
        <div class="card-header"><h2><i class="fa-solid fa-basket-shopping"></i> Products</h2></div>
        <div class="card-body stack">
          <div class="picker">
            <div class="toolbar"><div class="search" style="flex:1 1 100%"><i class="fa-solid fa-magnifying-glass"></i>
              <input type="search" id="productSearch" placeholder="Search products by name, size, category or SKU…" aria-label="Search products"></div></div>
            <div class="picker-results" id="pickerResults" hidden></div>
          </div>
          <div class="table-wrap">
            <table class="table" id="lines">
              <thead><tr><th>Product</th><th class="text-right" style="width:130px">Unit price</th><th class="text-center" style="width:100px">Qty</th><th class="text-right">Total</th><th class="actions"></th></tr></thead>
              <tbody></tbody>
            </table>
            <div class="empty-state" id="linesEmpty" style="padding:32px"><i class="fa-solid fa-jar"></i><h2>No products added</h2><p>Search above and click a product to add it.</p></div>
          </div>
          <?php if (isset($errors['items'])): ?><p class="error" style="color:var(--red);margin:0"><?= e($errors['items']) ?></p><?php endif; ?>
          <label class="check small"><input type="checkbox" name="allow_oversell" value="1" <?= !empty($old['allow_oversell']) ? 'checked' : '' ?>> Allow selling beyond tracked stock</label>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2><i class="fa-solid fa-user"></i> Customer</h2></div>
        <div class="card-body form-grid">
          <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>"><label for="name">Name <span class="req">*</span></label><input id="name" name="name" value="<?= $v('name') ?>" required><?= $err('name') ?></div>
          <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>"><label for="phone">Mobile number <span class="req">*</span></label><input id="phone" name="phone" type="tel" inputmode="numeric" value="<?= $v('phone') ?>" placeholder="01XXXXXXXXX" required><?= $err('phone') ?>
            <p class="hint" id="customerHint"></p></div>
          <div class="field span-2 <?= isset($errors['email']) ? 'has-error' : '' ?>"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= $v('email') ?>"><?= $err('email') ?></div>
          <div class="field span-2 <?= isset($errors['address']) ? 'has-error' : '' ?>"><label for="address">Delivery address <span class="req">*</span></label><textarea id="address" name="address" rows="3" required><?= $v('address') ?></textarea><?= $err('address') ?></div>
          <div class="field span-2"><label for="notes">Customer note</label><input id="notes" name="notes" value="<?= $v('notes') ?>" placeholder="Shown on the invoice, e.g. call before delivery"></div>
          <div class="field span-2"><label for="admin_notes">Internal note</label><textarea id="admin_notes" name="admin_notes" rows="2" style="min-height:60px" placeholder="Only your team can see this"><?= $v('admin_notes') ?></textarea></div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-header"><h2><i class="fa-solid fa-truck"></i> Delivery &amp; payment</h2></div>
        <div class="card-body stack">
          <?php if ($zones): ?>
          <div class="field <?= isset($errors['zone']) ? 'has-error' : '' ?>"><label for="zone">Delivery zone</label>
            <select id="zone" name="zone">
              <?php foreach ($zones as $k => $z): ?><option value="<?= e($k) ?>" data-fee="<?= (float)$z['fee'] ?>" <?= ($old['zone'] ?? $firstZone) === $k ? 'selected' : '' ?>><?= e($z['label']) ?> — <?= money($z['fee']) ?></option><?php endforeach; ?>
            </select><?= $err('zone') ?></div>
          <?php endif; ?>
          <div class="field"><label for="delivery_fee">Delivery fee</label>
            <div class="input-group"><span class="prefix"><?= e($SITE['currency_symbol'] ?? '৳') ?></span><input id="delivery_fee" name="delivery_fee" type="number" min="0" step="1" value="<?= $v('delivery_fee') ?>" placeholder="Zone default"></div>
            <p class="hint">Leave empty to use the zone’s fee.</p></div>
          <?php if ($payments): ?>
          <div class="field <?= isset($errors['payment_method']) ? 'has-error' : '' ?>"><label for="payment_method">Payment method</label>
            <select id="payment_method" name="payment_method">
              <?php foreach ($payments as $k => $label): ?><option value="<?= e($k) ?>" <?= ($old['payment_method'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select><?= $err('payment_method') ?></div>
          <?php endif; ?>
          <div class="field"><label for="payment_status">Payment status</label>
            <select id="payment_status" name="payment_status">
              <?php foreach (payment_statuses() as $k => [$label]): ?><option value="<?= $k ?>" <?= ($old['payment_status'] ?? 'unpaid') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h2><i class="fa-solid fa-receipt"></i> Summary</h2></div>
        <div class="card-body stack">
          <div class="field <?= isset($errors['coupon_code']) ? 'has-error' : '' ?>"><label for="coupon_code">Coupon code</label><input id="coupon_code" name="coupon_code" value="<?= $v('coupon_code') ?>" style="text-transform:uppercase" placeholder="Optional"><?= $err('coupon_code') ?>
            <p class="hint">Checked when you create the order.</p></div>
          <div class="field"><label for="discount">Manual discount</label>
            <div class="input-group"><span class="prefix"><?= e($SITE['currency_symbol'] ?? '৳') ?></span><input id="discount" name="discount" type="number" min="0" step="1" value="<?= $v('discount') ?>" placeholder="0"></div></div>
          <div class="kv-rows">
            <div><span class="muted">Subtotal</span><span id="sumSubtotal">—</span></div>
            <div><span class="muted">Discount</span><span id="sumDiscount" class="text-green">—</span></div>
            <div><span class="muted">Delivery</span><span id="sumDelivery">—</span></div>
            <div class="total"><span>Total</span><span id="sumTotal">—</span></div>
          </div>
          <div class="field"><label for="initial_status">Start as</label>
            <select id="initial_status" name="initial_status">
              <?php foreach (['pending', 'confirmed', 'processing'] as $k): ?><option value="<?= $k ?>" <?= ($old['initial_status'] ?? 'confirmed') === $k ? 'selected' : '' ?>><?= e(order_status_label($k)) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="source">Order came by</label>
            <select id="source" name="source">
              <option value="phone" <?= ($old['source'] ?? 'phone') === 'phone' ? 'selected' : '' ?>>Phone / message</option>
              <option value="admin" <?= ($old['source'] ?? '') === 'admin' ? 'selected' : '' ?>>Other (entered by staff)</option>
            </select></div>
          <label class="check"><input type="checkbox" name="assign_me" value="1" <?= !$old || !empty($old['assign_me']) ? 'checked' : '' ?>> Assign this order to me</label>
        </div>
        <div class="card-footer">
          <a class="btn btn-ghost" href="<?= admin_url('orders.php') ?>">Cancel</a>
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> Create order</button>
        </div>
      </div>
    </div>
  </div>
</form>

<script>
(function () {
  var CATALOG = <?= json_encode(array_values($catalog), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var PREFILL = <?= json_encode($prefill, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var CUR = <?= json_encode($SITE['currency_symbol'] ?? '৳', JSON_UNESCAPED_UNICODE) ?>;
  var CUSTOMERS = <?= json_encode(can('customers.view') ? array_values(array_map(fn($c) => ['phone' => (string)($c['phone'] ?? ''), 'name' => (string)($c['name'] ?? ''), 'address' => (string)($c['address'] ?? ''), 'orders' => (int)($c['orders_count'] ?? 0)], store_all('customers'))) : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var byId = {}; CATALOG.forEach(function (p) { byId[p.id] = p; });
  var tbody = document.querySelector('#lines tbody'), empty = document.getElementById('linesEmpty');
  var search = document.getElementById('productSearch'), results = document.getElementById('pickerResults');
  var fmt = function (n) { return CUR + Math.round(n).toLocaleString('en-US'); };
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

  function addLine(id, qty, price) {
    var p = byId[id]; if (!p) return;
    var existing = tbody.querySelector('tr[data-id="' + CSS.escape(id) + '"]');
    if (existing && !price) { var q = existing.querySelector('.qty-input'); q.value = (+q.value || 0) + (qty || 1); recalc(); return; }
    var tr = document.createElement('tr'); tr.setAttribute('data-id', id);
    tr.innerHTML = '<td><div class="cell-main"><img class="thumb" alt="" src="' + esc(p.image) + '"><div><strong>' + esc(p.title) + '</strong><small>' + esc(p.unit) +
      (p.stock !== null ? ' · ' + p.stock + ' in stock' : '') + (p.hidden ? ' · hidden on store' : '') + '</small></div></div>' +
      '<input type="hidden" name="item_id[]" value="' + esc(id) + '"></td>' +
      '<td class="text-right"><input class="line-price" type="number" min="0" step="1" name="item_price[]" value="' + esc(price !== undefined && price !== '' ? price : p.price) + '" style="text-align:right;height:34px"></td>' +
      '<td class="text-center"><input class="qty-input" type="number" min="1" max="999" name="item_qty[]" value="' + (qty || 1) + '" style="height:34px"></td>' +
      '<td class="text-right strong nowrap line-total"></td>' +
      '<td class="actions"><button type="button" class="icon-btn danger" aria-label="Remove"><i class="fa-solid fa-xmark"></i></button></td>';
    tr.querySelector('button').onclick = function () { tr.remove(); recalc(); };
    tbody.appendChild(tr); recalc();
  }

  function recalc() {
    var sub = 0, rows = tbody.querySelectorAll('tr');
    rows.forEach(function (tr) {
      var p = byId[tr.getAttribute('data-id')];
      var q = Math.max(0, parseInt(tr.querySelector('.qty-input').value, 10) || 0);
      var pr = Math.max(0, parseFloat(tr.querySelector('.line-price').value) || 0);
      tr.querySelector('.line-total').textContent = fmt(q * pr);
      var warn = p && p.stock !== null && q > p.stock;
      tr.querySelector('.qty-input').style.borderColor = warn ? 'var(--red)' : '';
      sub += q * pr;
    });
    empty.hidden = rows.length > 0;
    document.getElementById('lines').hidden = rows.length === 0;
    var zone = document.getElementById('zone');
    var feeIn = document.getElementById('delivery_fee');
    var fee = feeIn.value !== '' ? Math.max(0, +feeIn.value) : (zone ? +zone.selectedOptions[0].getAttribute('data-fee') : 0);
    var disc = Math.min(sub, Math.max(0, +document.getElementById('discount').value || 0));
    document.getElementById('sumSubtotal').textContent = fmt(sub);
    document.getElementById('sumDiscount').textContent = disc ? '−' + fmt(disc) : fmt(0);
    document.getElementById('sumDelivery').textContent = fmt(fee);
    document.getElementById('sumTotal').textContent = fmt(Math.max(0, sub - disc + fee));
  }

  var focusIdx = -1;
  function renderResults() {
    var q = search.value.trim().toLowerCase();
    if (!q) { results.hidden = true; return; }
    var terms = q.split(/\s+/);
    var hits = CATALOG.filter(function (p) {
      var hay = (p.title + ' ' + p.unit + ' ' + p.category + ' ' + p.sku + ' ' + p.id).toLowerCase();
      return terms.every(function (t) { return hay.indexOf(t) !== -1; });
    }).slice(0, 12);
    focusIdx = -1;
    results.innerHTML = hits.length ? '' : '<div class="muted" style="padding:12px">No products found.</div>';
    hits.forEach(function (p) {
      var b = document.createElement('button'); b.type = 'button';
      b.innerHTML = '<img class="thumb" alt="" src="' + esc(p.image) + '"><span class="grow"><strong>' + esc(p.title) + '</strong><small>' + esc(p.unit) + (p.category ? ' · ' + esc(p.category) : '') +
        (p.stock !== null ? ' · ' + p.stock + ' in stock' : '') + (!p.in_stock ? ' · out of stock' : '') + '</small></span><strong class="nowrap">' + fmt(p.price) + '</strong>';
      b.onclick = function () { addLine(p.id, 1); search.value = ''; results.hidden = true; search.focus(); };
      results.appendChild(b);
    });
    results.hidden = false;
  }
  search.addEventListener('input', renderResults);
  search.addEventListener('focus', renderResults);
  search.addEventListener('keydown', function (e) {
    var btns = results.querySelectorAll('button');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); if (!btns.length) return;
      focusIdx = (focusIdx + (e.key === 'ArrowDown' ? 1 : -1) + btns.length) % btns.length;
      btns.forEach(function (b, i) { b.classList.toggle('is-focus', i === focusIdx); });
    } else if (e.key === 'Enter') {
      e.preventDefault(); var b = btns[focusIdx >= 0 ? focusIdx : 0]; if (b) b.click();
    } else if (e.key === 'Escape') { results.hidden = true; }
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.picker')) results.hidden = true; });
  document.getElementById('newOrder').addEventListener('input', function (e) { if (e.target !== search) recalc(); });
  document.getElementById('newOrder').addEventListener('change', recalc);
  document.getElementById('newOrder').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target !== search) e.preventDefault();
  });

  // Returning customer lookup by phone
  var phone = document.getElementById('phone'), hint = document.getElementById('customerHint');
  phone.addEventListener('input', function () {
    var d = phone.value.replace(/\D/g, '').replace(/^88/, '');
    var c = d.length === 11 ? CUSTOMERS.filter(function (x) { return x.phone === d; })[0] : null;
    hint.innerHTML = '';
    if (!c) return;
    hint.innerHTML = '<i class="fa-solid fa-user-check"></i> Returning customer: <strong></strong> · ' + c.orders + ' previous order(s) <a href="#">Use details</a>';
    hint.querySelector('strong').textContent = c.name;
    hint.querySelector('a').onclick = function (ev) {
      ev.preventDefault();
      var n = document.getElementById('name'), a = document.getElementById('address');
      if (!n.value) n.value = c.name; if (!a.value) a.value = c.address;
    };
  });

  PREFILL.forEach(function (l) { addLine(l.id, l.qty, l.price); });
  recalc();
})();
</script>
<?php admin_footer();
