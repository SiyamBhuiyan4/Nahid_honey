<?php
/** Coupons & discounts — list, add/edit (?id= or ?new=1), delete. Schema: app/coupons.php */
require __DIR__ . '/inc/admin.php';
require_perm('coupons.manage');

$types = ['percent' => 'Percentage off', 'fixed' => 'Fixed amount off', 'free_shipping' => 'Free delivery'];

function coupon_state(array $c): array {
    $today = date('Y-m-d');
    if (empty($c['active'])) return ['Inactive', 'gray'];
    if (!empty($c['ends_at']) && $today > $c['ends_at']) return ['Expired', 'red'];
    if (!empty($c['starts_at']) && $today < $c['starts_at']) return ['Scheduled', 'blue'];
    if (!empty($c['usage_limit']) && (int)($c['used_count'] ?? 0) >= (int)$c['usage_limit']) return ['Used up', 'amber'];
    return ['Active', 'green'];
}

$editId = (string)($_GET['id'] ?? '');
$editing = $editId !== '' ? store_get('coupons', $editId) : null;
if ($editId !== '' && !$editing) { flash('That coupon no longer exists.', 'error'); redirect(admin_url('coupons.php')); }
$showForm = $editing || isset($_GET['new']);
$errors = [];
$form = $editing ?? ['code' => '', 'type' => 'percent', 'value' => '', 'min_order' => '', 'max_discount' => '', 'starts_at' => '', 'ends_at' => '', 'usage_limit' => '', 'active' => true, 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'delete' || $action === 'toggle') {
        $c = store_get('coupons', (string)($_POST['id'] ?? ''));
        if (!$c) { flash('That coupon no longer exists.', 'error'); redirect(admin_url('coupons.php')); }
        if ($action === 'delete') {
            store_delete('coupons', $c['id']);
            log_activity('coupon.delete', 'Deleted coupon ' . $c['code'], ['coupon_id' => $c['id']]);
            flash('Coupon ' . $c['code'] . ' deleted.');
        } else {
            store_patch('coupons', $c['id'], ['active' => empty($c['active'])]);
            log_activity('coupon.update', (empty($c['active']) ? 'Activated' : 'Deactivated') . ' coupon ' . $c['code'], ['coupon_id' => $c['id']]);
            flash('Coupon ' . $c['code'] . (empty($c['active']) ? ' activated.' : ' deactivated.'));
        }
        redirect(admin_url('coupons.php'));
    }

    $showForm = true;
    $num = function (string $k, bool $int = false) use (&$errors) {
        $raw = trim((string)($_POST[$k] ?? ''));
        if ($raw === '') return 0;
        if (!is_numeric($raw) || (float)$raw < 0) { $errors[$k] = 'Enter a positive number.'; return $raw; }
        return $int ? (int)$raw : 0 + $raw;
    };
    $form = array_replace($form, [
        'code' => strtoupper(preg_replace('/\s+/', '', (string)($_POST['code'] ?? ''))),
        'type' => (string)($_POST['type'] ?? 'percent'),
        'value' => $num('value'),
        'min_order' => $num('min_order'),
        'max_discount' => $num('max_discount'),
        'usage_limit' => $num('usage_limit', true),
        'starts_at' => trim((string)($_POST['starts_at'] ?? '')),
        'ends_at' => trim((string)($_POST['ends_at'] ?? '')),
        'active' => !empty($_POST['active']),
        'description' => trim((string)($_POST['description'] ?? '')),
    ]);
    if (!preg_match('/^[A-Z0-9_-]{3,30}$/', $form['code'])) $errors['code'] = 'Use 3–30 letters, numbers, dashes or underscores.';
    elseif (($dup = find_coupon($form['code'])) && $dup['id'] !== ($editing['id'] ?? '')) $errors['code'] = 'Another coupon already uses this code.';
    if (!isset($types[$form['type']])) $errors['type'] = 'Choose a coupon type.';
    if ($form['type'] === 'free_shipping') { $form['value'] = 0; $form['max_discount'] = 0; }
    elseif (!isset($errors['value']) && (float)$form['value'] <= 0) $errors['value'] = 'Enter the discount value.';
    if ($form['type'] === 'percent' && !isset($errors['value']) && (float)$form['value'] > 100) $errors['value'] = 'A percentage cannot be more than 100.';
    if ($form['type'] === 'fixed') $form['max_discount'] = 0;
    foreach (['starts_at', 'ends_at'] as $d) {
        if ($form[$d] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form[$d])) $errors[$d] = 'Use a valid date.';
    }
    if (!isset($errors['ends_at']) && $form['starts_at'] !== '' && $form['ends_at'] !== '' && $form['ends_at'] < $form['starts_at']) $errors['ends_at'] = 'The end date must be after the start date.';

    if (!$errors) {
        $doc = array_replace($editing ?? ['used_count' => 0], $form);
        if (!$editing) $doc['id'] = 'c' . bin2hex(random_bytes(5));
        $doc = store_put('coupons', $doc);
        log_activity($editing ? 'coupon.update' : 'coupon.create', ($editing ? 'Updated' : 'Created') . ' coupon ' . $doc['code'], ['coupon_id' => $doc['id']]);
        flash('Coupon ' . $doc['code'] . ($editing ? ' updated.' : ' created.'));
        redirect(admin_url('coupons.php'));
    }
}

$coupons = store_all('coupons');
usort($coupons, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$q = strtoupper(trim((string)($_GET['q'] ?? '')));
if ($q !== '') $coupons = array_filter($coupons, fn($c) => str_contains(strtoupper(($c['code'] ?? '') . ' ' . ($c['description'] ?? '')), $q));

$valueLabel = function (array $c) {
    $t = $c['type'] ?? 'fixed';
    if ($t === 'percent') return rtrim(rtrim(number_format((float)$c['value'], 2), '0'), '.') . '%' . (!empty($c['max_discount']) ? ' <span class="muted">(max ' . e(money($c['max_discount'])) . ')</span>' : '');
    if ($t === 'free_shipping') return 'Free delivery';
    return e(money($c['value'] ?? 0));
};

admin_header($showForm ? ($editing ? 'Edit coupon' : 'New coupon') : 'Coupons', 'coupons', ['actions' => $showForm
    ? '<a class="btn btn-ghost" href="' . admin_url('coupons.php') . '"><i class="fa-solid fa-arrow-left"></i> All coupons</a>'
    : '<a class="btn btn-primary" href="' . admin_url('coupons.php?new=1') . '"><i class="fa-solid fa-plus"></i> New coupon</a>']);
$err = fn($k) => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$v = fn($k) => e(($form[$k] ?? '') === 0 || ($form[$k] ?? '') === 0.0 ? '' : ($form[$k] ?? ''));

if ($showForm): ?>
<?php if ($errors): ?><div class="alert alert-error">Please fix the highlighted fields.</div><?php endif; ?>
<form class="card" method="post" novalidate id="coupon-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <div class="card-header"><h2><?= $editing ? 'Coupon ' . e($editing['code']) : 'Create a coupon' ?></h2>
    <?php if ($editing): ?><span class="muted">Used <?= (int)($editing['used_count'] ?? 0) ?> time(s)</span><?php endif; ?></div>
  <div class="card-body form-grid">
    <div class="field">
      <label for="code">Code</label>
      <div class="input-group">
        <input id="code" name="code" value="<?= e($form['code']) ?>" style="text-transform:uppercase" maxlength="30" required>
        <button type="button" class="btn btn-secondary" id="gen-code"><i class="fa-solid fa-dice"></i> Generate</button>
      </div>
      <div class="hint">Customers type this at checkout. Not case-sensitive.</div><?= $err('code') ?>
    </div>
    <div class="field">
      <label for="type">Type</label>
      <select id="type" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $form['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('type') ?>
    </div>
    <div class="field" data-for="percent fixed">
      <label for="value">Discount value <span class="muted" id="value-unit"></span></label>
      <input id="value" name="value" type="number" step="0.01" min="0" value="<?= $v('value') ?>"><?= $err('value') ?>
    </div>
    <div class="field" data-for="percent">
      <label for="max_discount">Maximum discount (<?= e($SITE['currency_symbol'] ?? '') ?>)</label>
      <input id="max_discount" name="max_discount" type="number" step="1" min="0" value="<?= $v('max_discount') ?>">
      <div class="hint">Leave empty for no cap.</div><?= $err('max_discount') ?>
    </div>
    <div class="field">
      <label for="min_order">Minimum order subtotal (<?= e($SITE['currency_symbol'] ?? '') ?>)</label>
      <input id="min_order" name="min_order" type="number" step="1" min="0" value="<?= $v('min_order') ?>"><div class="hint">Leave empty for no minimum.</div><?= $err('min_order') ?>
    </div>
    <div class="field">
      <label for="usage_limit">Total usage limit</label>
      <input id="usage_limit" name="usage_limit" type="number" step="1" min="0" value="<?= $v('usage_limit') ?>"><div class="hint">Leave empty for unlimited.</div><?= $err('usage_limit') ?>
    </div>
    <div class="field"><label for="starts_at">Starts on</label><input id="starts_at" name="starts_at" type="date" value="<?= e($form['starts_at']) ?>"><?= $err('starts_at') ?></div>
    <div class="field"><label for="ends_at">Ends on</label><input id="ends_at" name="ends_at" type="date" value="<?= e($form['ends_at']) ?>"><div class="hint">The coupon works through the end of this day.</div><?= $err('ends_at') ?></div>
    <div class="field span-2"><label for="description">Internal description</label><input id="description" name="description" value="<?= e($form['description']) ?>" maxlength="200" placeholder="e.g. Eid campaign on Facebook"></div>
    <div class="field span-2"><label class="check"><input type="checkbox" name="active" value="1" <?= !empty($form['active']) ? 'checked' : '' ?>> Active — customers can use this coupon</label></div>
  </div>
  <div class="card-footer">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $editing ? 'Save coupon' : 'Create coupon' ?></button>
    <a class="btn btn-ghost" href="<?= admin_url('coupons.php') ?>">Cancel</a>
  </div>
</form>
<script>
(function () {
  var type = document.getElementById('type'), unit = document.getElementById('value-unit');
  var cur = <?= json_encode($SITE['currency_symbol'] ?? '') ?>;
  function sync() {
    document.querySelectorAll('#coupon-form [data-for]').forEach(function (f) { f.hidden = f.getAttribute('data-for').split(' ').indexOf(type.value) === -1; });
    unit.textContent = type.value === 'percent' ? '(%)' : '(' + cur + ')';
  }
  type.addEventListener('change', sync); sync();
  document.getElementById('gen-code').addEventListener('click', function () {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', out = '', r = new Uint32Array(8);
    crypto.getRandomValues(r);
    for (var i = 0; i < r.length; i++) out += chars[r[i] % chars.length];
    document.getElementById('code').value = out;
  });
})();
</script>
<?php else: ?>
<div class="card">
  <form class="toolbar" method="get">
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search code or description">
    <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>
  <?php if (!$coupons): ?>
    <div class="empty-state"><i class="fa-solid fa-ticket"></i><h3>No coupons yet</h3><p>Create discount codes for campaigns, loyal customers or free delivery offers.</p>
      <a class="btn btn-primary" href="<?= admin_url('coupons.php?new=1') ?>"><i class="fa-solid fa-plus"></i> New coupon</a></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Code</th><th>Discount</th><th>Min. order</th><th>Usage</th><th>Validity</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
    <tbody>
    <?php foreach ($coupons as $c): [$st, $tone] = coupon_state($c); ?>
      <tr>
        <td><strong><code><?= e($c['code']) ?></code></strong> <button type="button" class="icon-btn" data-copy="<?= e($c['code']) ?>" title="Copy code"><i class="fa-regular fa-copy"></i></button>
          <?php if (!empty($c['description'])): ?><div class="muted"><?= e($c['description']) ?></div><?php endif; ?></td>
        <td><?= $valueLabel($c) ?></td>
        <td><?= !empty($c['min_order']) ? e(money($c['min_order'])) : '<span class="muted">—</span>' ?></td>
        <td><?= (int)($c['used_count'] ?? 0) ?> / <?= !empty($c['usage_limit']) ? (int)$c['usage_limit'] : '∞' ?></td>
        <td><?php if (empty($c['starts_at']) && empty($c['ends_at'])): ?><span class="muted">Always</span><?php else: ?>
          <?= !empty($c['starts_at']) ? e(date('d M Y', strtotime($c['starts_at']))) : 'Now' ?> → <?= !empty($c['ends_at']) ? e(date('d M Y', strtotime($c['ends_at']))) : 'No end' ?><?php endif; ?></td>
        <td><span class="badge badge-<?= $tone ?>"><?= $st ?></span></td>
        <td class="text-right">
          <a class="btn btn-sm btn-secondary" href="<?= admin_url('coupons.php?id=' . urlencode($c['id'])) ?>"><i class="fa-solid fa-pen"></i> Edit</a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
            <button class="btn btn-sm btn-ghost" type="submit"><?= !empty($c['active']) ? 'Deactivate' : 'Activate' ?></button></form>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
            <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete coupon <?= e($c['code']) ?>?"><i class="fa-solid fa-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif;
admin_footer();
