<?php
require __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/../../app/catalogue.php';
require_perm('inventory.manage');

$back = admin_url('inventory.php') . (($_POST['_return'] ?? '') !== '' ? '?' . preg_replace('/[^A-Za-z0-9_=&%.+\-\[\]]/', '', (string)$_POST['_return']) : '');
$intOrNull = function ($v): ?int {
    $v = trim((string)$v);
    return $v === '' || !is_numeric($v) ? null : max(0, (int)round((float)$v));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'default_threshold') {
        $t = $intOrNull($_POST['default_threshold'] ?? '') ?? 0;
        setting_set('low_stock_threshold', $t);
        log_activity('inventory.update', "Set default low-stock threshold to {$t}");
        flash("Default low-stock alert set to {$t}.");
        redirect($back);
    }

    if (isset($_POST['row'])) {
        [$act, $id] = array_pad(explode('|', (string)$_POST['row'], 2), 2, '');
        $p = store_get('products', $id);
        if (!$p) { flash('Product not found.', 'error'); redirect($back); }
        $name = cat_product_title($p);
        if ($act === 'out') {
            store_patch('products', $id, cat_stock($p) !== null ? ['stock' => 0, 'in_stock' => false] : ['in_stock' => false]);
            log_activity('inventory.update', "Marked {$name} out of stock", ['product_id' => $id]);
            flash("“{$name}” marked out of stock.");
        } elseif ($act === 'in') {
            if (cat_stock($p) !== null && cat_stock($p) <= 0) { flash("Enter a quantity for “{$name}” — it tracks stock.", 'warning'); redirect($back); }
            store_patch('products', $id, ['in_stock' => true]);
            log_activity('inventory.update', "Marked {$name} in stock", ['product_id' => $id]);
            flash("“{$name}” marked in stock.");
        }
        redirect($back);
    }

    if ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        $op = (string)($_POST['bulk'] ?? '');
        if (!$ids || !in_array($op, ['out', 'in', 'untrack'], true)) { flash('Select products and an action first.', 'warning'); redirect($back); }
        $done = 0; $skipped = 0;
        foreach ($ids as $id) {
            $p = store_get('products', $id); if (!$p) continue;
            $tracked = cat_stock($p) !== null;
            if ($op === 'out') store_patch('products', $id, $tracked ? ['stock' => 0, 'in_stock' => false] : ['in_stock' => false]);
            elseif ($op === 'in') { if ($tracked && cat_stock($p) <= 0) { $skipped++; continue; } store_patch('products', $id, ['in_stock' => true]); }
            else store_patch('products', $id, ['stock' => null, 'in_stock' => $tracked ? cat_stock($p) > 0 : ($p['in_stock'] ?? true) !== false]);
            $done++;
        }
        $labels = ['out' => 'Marked out of stock', 'in' => 'Marked in stock', 'untrack' => 'Stopped tracking stock'];
        log_activity('inventory.update', "{$labels[$op]} — {$done} product(s)", ['ids' => $ids]);
        flash("{$labels[$op]}: {$done} product(s)." . ($skipped ? " {$skipped} skipped — they track stock and have none left; enter a quantity instead." : ''));
        redirect($back);
    }

    // Inline bulk save of stock levels.
    $changed = [];
    foreach ((array)($_POST['rows'] ?? []) as $id => $r) {
        $id = (string)$id;
        $p = store_get('products', $id);
        if (!$p || !is_array($r)) continue;
        $stock = $intOrNull($r['stock'] ?? '');
        $threshold = $intOrNull($r['threshold'] ?? '');
        $patch = [
            'stock' => $stock,
            'low_stock_threshold' => $threshold,
            'in_stock' => $stock !== null ? $stock > 0 : !empty($r['in_stock']),
        ];
        $diff = [];
        if (cat_stock($p) !== $stock) $diff[] = 'stock ' . (cat_stock($p) ?? '—') . ' → ' . ($stock ?? '—');
        $oldT = isset($p['low_stock_threshold']) && $p['low_stock_threshold'] !== '' && $p['low_stock_threshold'] !== null ? (int)$p['low_stock_threshold'] : null;
        if ($oldT !== $threshold) $diff[] = 'alert ' . ($oldT ?? 'default') . ' → ' . ($threshold ?? 'default');
        if ((($p['in_stock'] ?? true) !== false) !== $patch['in_stock']) $diff[] = $patch['in_stock'] ? 'in stock' : 'out of stock';
        if (!$diff) continue;
        store_patch('products', $id, $patch);
        $changed[] = cat_product_title($p) . ': ' . implode(', ', $diff);
    }
    if ($changed) {
        log_activity('inventory.update', 'Updated stock for ' . count($changed) . ' product(s) — ' . mb_strimwidth(implode('; ', $changed), 0, 400, '…'));
        flash('Stock updated for ' . count($changed) . ' product(s).');
    } else {
        flash('No changes to save.', 'info');
    }
    redirect($back);
}

// ---------- Listing ----------
$q      = trim((string)($_GET['q'] ?? ''));
$fState = (string)($_GET['state'] ?? '');
$fTrack = (string)($_GET['track'] ?? '');
$fCat   = (string)($_GET['category'] ?? '');
$page   = (int)($_GET['page'] ?? 1);
$perPage = 50;

$all = store_all('products');
$stats = ['units' => 0, 'value' => 0, 'low' => 0, 'out' => 0, 'untracked' => 0];
foreach ($all as $p) {
    $s = cat_stock($p);
    if ($s === null) $stats['untracked']++;
    else { $stats['units'] += max(0, $s); $stats['value'] += max(0, $s) * (float)($p['sale_price'] ?? $p['regular_price'] ?? 0); }
    $st = cat_stock_state($p);
    if ($st !== 'in') $stats[$st]++;
}

$rows = array_values(array_filter($all, function ($p) use ($q, $fState, $fTrack, $fCat) {
    if ($fState !== '' && cat_stock_state($p) !== $fState) return false;
    if ($fTrack === 'tracked' && cat_stock($p) === null) return false;
    if ($fTrack === 'untracked' && cat_stock($p) !== null) return false;
    if ($fCat !== '' && slugify((string)($p['category'] ?? '')) !== $fCat) return false;
    if ($q !== '') {
        $hay = mb_strtolower(implode(' ', [$p['id'], $p['title'] ?? '', $p['title_en'] ?? '', $p['sku'] ?? '']));
        if (!str_contains($hay, mb_strtolower($q))) return false;
    }
    return true;
}));
$rank = ['out' => 0, 'low' => 1, 'in' => 2];
usort($rows, fn($a, $b) => ($rank[cat_stock_state($a)] <=> $rank[cat_stock_state($b)]) ?: ((cat_stock($a) ?? PHP_INT_MAX) <=> (cat_stock($b) ?? PHP_INT_MAX)) ?: strcasecmp(cat_product_title($a), cat_product_title($b)));

$total = count($rows);
[$rows, $page, $pages] = cat_paginate($rows, $perPage, $page);
$query = array_filter(['q' => $q, 'state' => $fState, 'track' => $fTrack, 'category' => $fCat], fn($v) => $v !== '');
$returnQs = http_build_query(array_merge($query, $page > 1 ? ['page' => $page] : []));
$default = cat_low_stock_default();
$filtered = (bool)$query;

admin_header('Inventory', 'inventory', ['actions' => '<button class="btn btn-secondary" type="button" data-modal-open="threshold-modal"><i class="fa-solid fa-bell"></i> Alert threshold: ' . $default . '</button>']);
?>
<style>
  .stock-input { width: 92px; text-align: right; }
  .thr-input { width: 76px; text-align: right; }
  tr.is-changed td { background: var(--brand-50, #fff7ec); }
  .save-bar { position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 20px; border-top: 1px solid var(--border, #e5e1d8); background: var(--surface, #fff); border-radius: 0 0 var(--radius-lg, 14px) var(--radius-lg, 14px); }
</style>

<div class="grid-4">
  <div class="stat"><span class="stat-icon teal"><i class="fa-solid fa-cubes"></i></span><span class="stat-label">Units in stock</span><span class="stat-value"><?= number_format($stats['units']) ?></span><span class="stat-sub">Retail value <?= money($stats['value']) ?></span></div>
  <a class="stat" href="?state=low"><span class="stat-icon amber"><i class="fa-solid fa-arrow-trend-down"></i></span><span class="stat-label">Low stock</span><span class="stat-value"><?= $stats['low'] ?></span><span class="stat-sub">At or below the alert level</span></a>
  <a class="stat" href="?state=out"><span class="stat-icon red"><i class="fa-solid fa-ban"></i></span><span class="stat-label">Out of stock</span><span class="stat-value"><?= $stats['out'] ?></span><span class="stat-sub">Shown as unavailable</span></a>
  <a class="stat" href="?track=untracked"><span class="stat-icon"><i class="fa-solid fa-infinity"></i></span><span class="stat-label">Not tracked</span><span class="stat-value"><?= $stats['untracked'] ?></span><span class="stat-sub">Availability set manually</span></a>
</div>

<form id="row-form" method="post" hidden><?= csrf_field() ?><input type="hidden" name="_return" value="<?= e($returnQs) ?>"></form>
<form id="filter-form" method="get" hidden></form>

<form method="post" class="card" id="inv-form">
  <?= csrf_field() ?>
  <input type="hidden" name="_return" value="<?= e($returnQs) ?>">
  <button type="submit" name="action" value="save" hidden tabindex="-1" aria-hidden="true"></button>
  <div class="toolbar">
    <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" form="filter-form" value="<?= e($q) ?>" placeholder="Search product or SKU…"></div>
    <select name="state" form="filter-form" data-autosubmit aria-label="Stock level">
      <option value="">All stock levels</option>
      <option value="in" <?= $fState === 'in' ? 'selected' : '' ?>>In stock</option>
      <option value="low" <?= $fState === 'low' ? 'selected' : '' ?>>Low stock</option>
      <option value="out" <?= $fState === 'out' ? 'selected' : '' ?>>Out of stock</option>
    </select>
    <select name="track" form="filter-form" data-autosubmit aria-label="Tracking">
      <option value="">Tracked &amp; untracked</option>
      <option value="tracked" <?= $fTrack === 'tracked' ? 'selected' : '' ?>>Tracked quantity</option>
      <option value="untracked" <?= $fTrack === 'untracked' ? 'selected' : '' ?>>Not tracked</option>
    </select>
    <select name="category" form="filter-form" data-autosubmit aria-label="Category">
      <option value="">All categories</option>
      <?php foreach (cat_category_names() as $slug => $name): ?><option value="<?= e($slug) ?>" <?= $fCat === $slug ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" form="filter-form" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="?">Clear</a><?php endif; ?>
  </div>
  <div class="toolbar" data-bulk-show hidden>
    <strong><span data-bulk-count>0</span> selected</strong>
    <select name="bulk" aria-label="Bulk action">
      <option value="out">Mark out of stock</option>
      <option value="in">Mark in stock</option>
      <option value="untrack">Stop tracking quantity</option>
    </select>
    <button class="btn btn-primary btn-sm" type="submit" name="action" value="bulk">Apply</button>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state"><i class="fa-solid fa-boxes-stacked"></i><h2><?= $filtered ? 'Nothing matches these filters' : 'No products yet' ?></h2><p><?= $filtered ? 'Try clearing the filters.' : 'Add products to manage their stock here.' ?></p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th class="check"><input type="checkbox" data-check-all="tbody .row-check" aria-label="Select all"></th>
        <th>Product</th>
        <th>Status</th>
        <th class="text-right">Quantity</th>
        <th class="text-right">Alert at</th>
        <th>Available</th>
        <th class="actions"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $p):
          $id = (string)$p['id']; $stock = cat_stock($p); $state = cat_stock_state($p);
          $thr = isset($p['low_stock_threshold']) && $p['low_stock_threshold'] !== '' && $p['low_stock_threshold'] !== null ? (int)$p['low_stock_threshold'] : '';
      ?>
        <tr data-row>
          <td class="check"><input type="checkbox" class="row-check" name="ids[]" value="<?= e($id) ?>" aria-label="Select"></td>
          <td>
            <div class="cell-main">
              <img class="thumb" src="<?= e(cat_img_src(cat_product_images($p)[0] ?? '')) ?>" alt="" loading="lazy">
              <div>
                <?php if (can('products.edit')): ?><a class="row-link" href="<?= admin_url('product-edit.php?id=' . urlencode($id)) ?>"><strong><?= e(cat_product_title($p)) ?></strong></a><?php else: ?><strong><?= e(cat_product_title($p)) ?></strong><?php endif; ?>
                <small>#<?= e($id) ?><?= !empty($p['sku']) ? ' · ' . e($p['sku']) : '' ?><?= !empty($p['weight_volume']) ? ' · ' . e($p['weight_volume']) : '' ?><?= cat_status($p) === 'draft' ? ' · Draft' : '' ?></small>
              </div>
            </div>
          </td>
          <td><?= cat_stock_badge($p) ?></td>
          <td class="text-right"><input class="stock-input" type="number" min="0" step="1" name="rows[<?= e($id) ?>][stock]" value="<?= $stock === null ? '' : $stock ?>" placeholder="Not tracked" aria-label="Quantity" data-stock></td>
          <td class="text-right"><input class="thr-input" type="number" min="0" step="1" name="rows[<?= e($id) ?>][threshold]" value="<?= e($thr) ?>" placeholder="<?= $default ?>" aria-label="Low-stock alert"></td>
          <td>
            <label class="switch" title="Used when the quantity is not tracked"><input type="checkbox" name="rows[<?= e($id) ?>][in_stock]" value="1" <?= ($p['in_stock'] ?? true) !== false ? 'checked' : '' ?> <?= $stock !== null ? 'disabled' : '' ?> data-avail><span></span></label>
          </td>
          <td class="actions nowrap">
            <?php if ($state !== 'out'): ?>
              <button class="btn btn-ghost btn-sm" type="submit" form="row-form" name="row" value="out|<?= e($id) ?>">Mark out</button>
            <?php elseif ($stock === null): ?>
              <button class="btn btn-ghost btn-sm" type="submit" form="row-form" name="row" value="in|<?= e($id) ?>">Mark in</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= cat_pagination_html($page, $pages, $query, $total, $perPage) ?>
  <div class="save-bar">
    <span class="muted small">Leave quantity empty to stop tracking — then the switch sets availability. Changed rows are highlighted.</span>
    <button class="btn btn-primary" type="submit" name="action" value="save" id="inv-save"><i class="fa-solid fa-floppy-disk"></i> Save stock changes <span id="changed-count"></span></button>
  </div>
  <?php endif; ?>
</form>

<div class="modal" id="threshold-modal" hidden>
  <form method="post" class="modal-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="default_threshold"><input type="hidden" name="_return" value="<?= e($returnQs) ?>">
    <div class="card-header"><h2>Default low-stock alert</h2><button class="icon-btn" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="card-body stack">
      <p class="muted">Products that track stock are flagged as “Low” when their quantity reaches this number. Each product can override it.</p>
      <div class="field"><label for="dt">Alert when quantity is at or below</label><input id="dt" type="number" name="default_threshold" min="0" step="1" value="<?= $default ?>"></div>
    </div>
    <div class="card-footer"><button class="btn btn-ghost" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</div>

<script>
(function () {
  var form = document.getElementById('inv-form'); if (!form) return;
  var count = document.getElementById('changed-count'), save = document.getElementById('inv-save');
  function mark(tr) {
    var changed = Array.prototype.some.call(tr.querySelectorAll('input:not(.row-check)'), function (i) {
      return i.type === 'checkbox' ? i.checked !== i.defaultChecked : i.value !== i.defaultValue;
    });
    tr.classList.toggle('is-changed', changed);
    var n = form.querySelectorAll('tr.is-changed').length;
    if (count) count.textContent = n ? '(' + n + ')' : '';
  }
  form.addEventListener('input', function (e) {
    var tr = e.target.closest('tr[data-row]'); if (!tr || e.target.classList.contains('row-check')) return;
    if (e.target.matches('[data-stock]')) {
      var av = tr.querySelector('[data-avail]');
      av.disabled = e.target.value.trim() !== '';
    }
    mark(tr);
  });
  form.addEventListener('change', function (e) { var tr = e.target.closest('tr[data-row]'); if (tr && !e.target.classList.contains('row-check')) mark(tr); });
  form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches('tr[data-row] input') && save) { e.preventDefault(); save.click(); }
  });
  window.addEventListener('beforeunload', function (e) { if (form.querySelector('tr.is-changed') && !form.__submitting) { e.preventDefault(); e.returnValue = ''; } });
  form.addEventListener('submit', function () { form.__submitting = true; });
})();
</script>
<?php admin_footer();
