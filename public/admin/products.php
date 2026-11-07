<?php
require __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/../../app/catalogue.php';
require_perm('products.view');

$back = admin_url('products.php') . (($_POST['_return'] ?? '') !== '' ? '?' . preg_replace('/[^A-Za-z0-9_=&%.+\-\[\]]/', '', (string)$_POST['_return']) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Row actions: "<action>|<id>"
    if (isset($_POST['row'])) {
        [$act, $id] = array_pad(explode('|', (string)$_POST['row'], 2), 2, '');
        $p = store_get('products', $id);
        if (!$p) { flash('Product not found.', 'error'); redirect($back); }
        $name = cat_product_title($p);

        if ($act === 'feature') {
            if (!can('products.edit')) { flash('You don’t have permission to edit products.', 'error'); redirect($back); }
            $on = empty($p['featured']);
            store_patch('products', $id, ['featured' => $on, 'top_selling' => $on]);
            log_activity('product.update', ($on ? 'Featured ' : 'Unfeatured ') . $name, ['product_id' => $id]);
            flash($on ? "“{$name}” is now featured." : "“{$name}” is no longer featured.");
        } elseif ($act === 'duplicate') {
            if (!can('products.edit')) { flash('You don’t have permission to add products.', 'error'); redirect($back); }
            $copy = $p;
            unset($copy['id'], $copy['created_at'], $copy['updated_at']);
            foreach (['title', 'title_en', 'title_bn'] as $f) if (!empty($copy[$f])) $copy[$f] .= ' (Copy)';
            $copy['slug'] = cat_unique_slug(((($p['slug'] ?? '') ?: slugify($name)) . '-copy'), null);
            $copy['sku'] = '';
            $copy['status'] = 'draft';
            $new = store_put('products', $copy);
            log_activity('product.create', "Duplicated {$name} as draft #{$new['id']}", ['product_id' => $new['id'], 'from' => $id]);
            flash('Duplicate created as a draft. Review it before publishing.');
            redirect(admin_url('product-edit.php?id=' . urlencode($new['id'])));
        } elseif ($act === 'delete') {
            if (!can('products.delete')) { flash('You don’t have permission to delete products.', 'error'); redirect($back); }
            store_delete('products', $id);
            log_activity('product.delete', "Deleted product {$name}", ['product_id' => $id]);
            flash("“{$name}” was deleted.");
        }
        redirect($back);
    }

    // Bulk actions
    $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
    $bulk = (string)($_POST['bulk'] ?? '');
    if (!$ids || $bulk === '') { flash('Select products and an action first.', 'warning'); redirect($back); }
    $need = $bulk === 'delete' ? 'products.delete' : 'products.edit';
    if (!can($need)) { flash('You don’t have permission for that action.', 'error'); redirect($back); }

    $done = 0;
    $category = trim((string)($_POST['bulk_category'] ?? ''));
    if ($bulk === 'set_category' && $category === '') { flash('Choose a category to move the products to.', 'warning'); redirect($back); }
    foreach ($ids as $id) {
        if (!store_get('products', $id)) continue;
        switch ($bulk) {
            case 'publish':      store_patch('products', $id, ['status' => 'published']); break;
            case 'draft':        store_patch('products', $id, ['status' => 'draft']); break;
            case 'feature':      store_patch('products', $id, ['featured' => true, 'top_selling' => true]); break;
            case 'unfeature':    store_patch('products', $id, ['featured' => false, 'top_selling' => false]); break;
            case 'set_category': store_patch('products', $id, ['category' => $category]); break;
            case 'delete':       store_delete('products', $id); break;
            default: continue 2;
        }
        $done++;
    }
    if ($bulk === 'set_category' && $done) cat_ensure_category($category);
    $labels = ['publish' => 'Published', 'draft' => 'Moved to draft', 'feature' => 'Featured', 'unfeature' => 'Unfeatured',
               'set_category' => "Moved to “{$category}”", 'delete' => 'Deleted'];
    log_activity($bulk === 'delete' ? 'product.delete' : 'product.update', ($labels[$bulk] ?? $bulk) . " — {$done} product(s)", ['ids' => $ids]);
    flash(($labels[$bulk] ?? 'Updated') . ": {$done} product(s).");
    redirect($back);
}

// ---------- Listing ----------
$q        = trim((string)($_GET['q'] ?? ''));
$fCat     = (string)($_GET['category'] ?? '');
$fStatus  = (string)($_GET['status'] ?? '');
$fStock   = (string)($_GET['stock'] ?? '');
$sort     = (string)($_GET['sort'] ?? 'manual');
$page     = (int)($_GET['page'] ?? 1);
$perPage  = 25;

$all = store_all('products');
$counts = ['all' => count($all), 'published' => 0, 'draft' => 0, 'low' => 0, 'out' => 0];
foreach ($all as $p) {
    $counts[cat_status($p)]++;
    $st = cat_stock_state($p);
    if ($st !== 'in') $counts[$st]++;
}

$rows = array_values(array_filter($all, function ($p) use ($q, $fCat, $fStatus, $fStock) {
    if ($fCat !== '' && slugify((string)($p['category'] ?? '')) !== $fCat) return false;
    if ($fStatus !== '' && cat_status($p) !== $fStatus) return false;
    if ($fStock !== '' && cat_stock_state($p) !== $fStock) return false;
    if ($q !== '') {
        $hay = mb_strtolower(implode(' ', [$p['id'], $p['title'] ?? '', $p['title_en'] ?? '', $p['title_bn'] ?? '', $p['sku'] ?? '', $p['slug'] ?? '', $p['category'] ?? '']));
        if (!str_contains($hay, mb_strtolower($q))) return false;
    }
    return true;
}));

$price = fn($p) => (float)($p['sale_price'] ?? $p['regular_price'] ?? 0);
usort($rows, match ($sort) {
    'newest'     => fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? '') ?: ((int)$b['id'] <=> (int)$a['id']),
    'title'      => fn($a, $b) => strcasecmp(cat_product_title($a), cat_product_title($b)),
    'price_asc'  => fn($a, $b) => $price($a) <=> $price($b),
    'price_desc' => fn($a, $b) => $price($b) <=> $price($a),
    'stock'      => fn($a, $b) => (cat_stock($a) ?? PHP_INT_MAX) <=> (cat_stock($b) ?? PHP_INT_MAX),
    'updated'    => fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''),
    default      => fn($a, $b) => ((int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0)) ?: ((int)$a['id'] <=> (int)$b['id']),
});

$total = count($rows);
[$rows, $page, $pages] = cat_paginate($rows, $perPage, $page);
$query = array_filter(['q' => $q, 'category' => $fCat, 'status' => $fStatus, 'stock' => $fStock, 'sort' => $sort !== 'manual' ? $sort : ''], fn($v) => $v !== '');
$returnQs = http_build_query(array_merge($query, $page > 1 ? ['page' => $page] : []));
$catNames = cat_category_names();
$canEdit = can('products.edit');
$canDelete = can('products.delete');
$filtered = $q !== '' || $fCat !== '' || $fStatus !== '' || $fStock !== '';

admin_header('Products', 'products', ['actions' => $canEdit ? '<a class="btn btn-primary" href="' . admin_url('product-edit.php') . '"><i class="fa-solid fa-plus"></i> Add product</a>' : '']);
?>
<div class="grid-4">
  <a class="stat" href="?"><span class="stat-icon"><i class="fa-solid fa-jar"></i></span><span class="stat-label">All products</span><span class="stat-value"><?= $counts['all'] ?></span></a>
  <a class="stat" href="?status=published"><span class="stat-icon green"><i class="fa-solid fa-eye"></i></span><span class="stat-label">Published</span><span class="stat-value"><?= $counts['published'] ?></span></a>
  <a class="stat" href="?status=draft"><span class="stat-icon indigo"><i class="fa-solid fa-pen-ruler"></i></span><span class="stat-label">Drafts</span><span class="stat-value"><?= $counts['draft'] ?></span></a>
  <a class="stat" href="?stock=<?= $counts['out'] ? 'out' : 'low' ?>"><span class="stat-icon red"><i class="fa-solid fa-triangle-exclamation"></i></span><span class="stat-label">Low / out of stock</span><span class="stat-value"><?= $counts['low'] ?> <small class="muted">/ <?= $counts['out'] ?></small></span></a>
</div>

<form id="row-form" method="post" hidden><?= csrf_field() ?><input type="hidden" name="_return" value="<?= e($returnQs) ?>"></form>

<form method="post" class="card" id="bulk-form">
  <?= csrf_field() ?>
  <input type="hidden" name="_return" value="<?= e($returnQs) ?>">
  <div class="toolbar">
    <div class="search"><i class="fa-solid fa-magnifying-glass"></i>
      <input type="search" name="q" form="filter-form" value="<?= e($q) ?>" placeholder="Search title, SKU or ID…">
    </div>
    <select name="category" form="filter-form" data-autosubmit aria-label="Category">
      <option value="">All categories</option>
      <?php foreach ($catNames as $slug => $name): ?><option value="<?= e($slug) ?>" <?= $fCat === $slug ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
    </select>
    <select name="status" form="filter-form" data-autosubmit aria-label="Status">
      <option value="">Any status</option>
      <?php foreach (PRODUCT_STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
    </select>
    <select name="stock" form="filter-form" data-autosubmit aria-label="Stock">
      <option value="">Any stock</option>
      <option value="in" <?= $fStock === 'in' ? 'selected' : '' ?>>In stock</option>
      <option value="low" <?= $fStock === 'low' ? 'selected' : '' ?>>Low stock</option>
      <option value="out" <?= $fStock === 'out' ? 'selected' : '' ?>>Out of stock</option>
    </select>
    <select name="sort" form="filter-form" data-autosubmit aria-label="Sort">
      <?php foreach (['manual' => 'Store order', 'newest' => 'Newest first', 'updated' => 'Recently updated', 'title' => 'Title A–Z', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'stock' => 'Stock: lowest first'] as $k => $label): ?>
        <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" form="filter-form" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="?">Clear</a><?php endif; ?>
  </div>

  <?php if ($canEdit || $canDelete): ?>
  <div class="toolbar bulk-bar" data-bulk-show hidden>
    <strong><span data-bulk-count>0</span> selected</strong>
    <select name="bulk" id="bulk-action" aria-label="Bulk action">
      <option value="">Choose action…</option>
      <?php if ($canEdit): ?>
        <option value="publish">Publish</option>
        <option value="draft">Move to draft</option>
        <option value="feature">Mark as featured</option>
        <option value="unfeature">Remove from featured</option>
        <option value="set_category">Change category…</option>
      <?php endif; ?>
      <?php if ($canDelete): ?><option value="delete">Delete</option><?php endif; ?>
    </select>
    <select name="bulk_category" id="bulk-category" hidden aria-label="New category">
      <option value="">Select category…</option>
      <?php foreach ($catNames as $name): ?><option value="<?= e($name) ?>"><?= e($name) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Apply</button>
  </div>
  <?php endif; ?>

  <?php if (!$rows): ?>
    <div class="empty-state">
      <i class="fa-solid fa-jar"></i>
      <h2><?= $filtered ? 'No products match these filters' : 'No products yet' ?></h2>
      <p><?= $filtered ? 'Try a different search or clear the filters.' : 'Add your first product to start selling.' ?></p>
      <?php if (!$filtered && $canEdit): ?><a class="btn btn-primary" href="<?= admin_url('product-edit.php') ?>"><i class="fa-solid fa-plus"></i> Add product</a><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <?php if ($canEdit || $canDelete): ?><th class="check"><input type="checkbox" data-check-all aria-label="Select all"></th><?php endif; ?>
          <th>Product</th>
          <th>Category</th>
          <th class="text-right">Price</th>
          <th>Stock</th>
          <th>Status</th>
          <th class="text-center">Featured</th>
          <th class="actions"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $p):
          $imgs = cat_product_images($p);
          $sale = (float)($p['sale_price'] ?? 0);
          $reg = (float)($p['regular_price'] ?? 0);
          $title = cat_product_title($p);
          $featured = !empty($p['featured']);
      ?>
        <tr>
          <?php if ($canEdit || $canDelete): ?><td class="check"><input type="checkbox" name="ids[]" value="<?= e($p['id']) ?>" aria-label="Select"></td><?php endif; ?>
          <td>
            <div class="cell-main">
              <img class="thumb-lg" src="<?= e(cat_img_src($imgs[0] ?? '')) ?>" alt="" loading="lazy">
              <div style="min-width:0">
                <?php if ($canEdit): ?>
                  <a class="row-link" href="<?= admin_url('product-edit.php?id=' . urlencode($p['id'])) ?>"><strong><?= e($title ?: 'Untitled') ?></strong></a>
                <?php else: ?><strong><?= e($title ?: 'Untitled') ?></strong><?php endif; ?>
                <?php if (!empty($p['title_bn']) && $p['title_bn'] !== $title): ?><small><?= e($p['title_bn']) ?></small><?php endif; ?>
                <small>#<?= e($p['id']) ?><?= !empty($p['sku']) ? ' · SKU ' . e($p['sku']) : '' ?><?= !empty($p['weight_volume']) ? ' · ' . e($p['weight_volume']) : '' ?></small>
              </div>
            </div>
          </td>
          <td><?= ($p['category'] ?? '') !== '' ? '<span class="chip">' . e($p['category']) . '</span>' : '<span class="muted">—</span>' ?></td>
          <td class="text-right nowrap">
            <strong><?= money($sale ?: $reg) ?></strong>
            <?php if ($reg > $sale && $sale > 0): ?><small><s><?= money($reg) ?></s> · <?= round(($reg - $sale) / $reg * 100) ?>% off</small><?php endif; ?>
          </td>
          <td><?= cat_stock_badge($p) ?></td>
          <td><?= cat_status_badge($p) ?></td>
          <td class="text-center">
            <?php if ($canEdit): ?>
              <button class="icon-btn" type="submit" form="row-form" name="row" value="feature|<?= e($p['id']) ?>" title="<?= $featured ? 'Remove from featured' : 'Mark as featured' ?>" aria-pressed="<?= $featured ? 'true' : 'false' ?>">
                <i class="fa-<?= $featured ? 'solid' : 'regular' ?> fa-star" style="<?= $featured ? 'color:#E8A317' : '' ?>"></i>
              </button>
            <?php else: ?>
              <i class="fa-<?= $featured ? 'solid' : 'regular' ?> fa-star muted"></i>
            <?php endif; ?>
          </td>
          <td class="actions nowrap">
            <?php if ($canEdit): ?><a class="icon-btn" href="<?= admin_url('product-edit.php?id=' . urlencode($p['id'])) ?>" title="Edit"><i class="fa-solid fa-pen"></i></a><?php endif; ?>
            <?php if (cat_status($p) === 'published'): ?><a class="icon-btn" href="/product.php?id=<?= urlencode($p['id']) ?>" target="_blank" rel="noopener" title="View in store"><i class="fa-solid fa-arrow-up-right-from-square"></i></a><?php endif; ?>
            <?php if ($canEdit): ?><button class="icon-btn" type="submit" form="row-form" name="row" value="duplicate|<?= e($p['id']) ?>" title="Duplicate"><i class="fa-regular fa-copy"></i></button><?php endif; ?>
            <?php if ($canDelete): ?><button class="icon-btn danger" type="submit" form="row-form" name="row" value="delete|<?= e($p['id']) ?>" title="Delete" data-confirm="Delete “<?= e($title) ?>”? This cannot be undone." data-confirm-tone="danger" data-confirm-ok="Delete"><i class="fa-solid fa-trash"></i></button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= cat_pagination_html($page, $pages, $query, $total, $perPage) ?>
  <?php endif; ?>
</form>
<form id="filter-form" method="get" hidden></form>

<script>
(function () {
  var action = document.getElementById('bulk-action'), cat = document.getElementById('bulk-category'), form = document.getElementById('bulk-form');
  if (!action) return;
  action.addEventListener('change', function () {
    cat.hidden = action.value !== 'set_category';
    if (action.value === 'delete') {
      form.setAttribute('data-confirm', 'Delete the selected products? This cannot be undone.');
      form.setAttribute('data-confirm-tone', 'danger');
    } else form.removeAttribute('data-confirm');
  });
})();
</script>
<?php admin_footer();
