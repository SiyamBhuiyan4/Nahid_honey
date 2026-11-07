<?php
require __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/../../app/catalogue.php';
require_perm('categories.manage');

/** Renumbers sort_order 1..n in the current display order and returns the list. */
function cat_normalise_order(): array {
    $list = cat_categories();
    foreach ($list as $i => $c) {
        if ((int)($c['sort_order'] ?? 0) !== $i + 1) $list[$i] = store_patch('categories', $c['id'], ['sort_order' => $i + 1]);
    }
    return $list;
}

/** Moves every product in category $fromSlug to the category named $toName. */
function cat_move_products(string $fromSlug, string $toName): int {
    $n = 0;
    foreach (store_all('products') as $p) {
        if (slugify((string)($p['category'] ?? '')) !== $fromSlug || ($p['category'] ?? '') === $toName) continue;
        store_patch('products', $p['id'], ['category' => $toName]);
        $n++;
    }
    return $n;
}

$errors = [];
$editId = (string)($_GET['edit'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    $id = (string)($_POST['id'] ?? '');
    $cat = $id !== '' ? store_get('categories', $id) : null;

    if ($action === 'save') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
        $image = cat_clean_image_url((string)($_POST['image'] ?? ''));
        $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
        $visible = !empty($_POST['visible']);
        $slug = slugify($name);
        if ($name === '') $errors['name'] = 'Enter a category name.';
        elseif ((!$cat || $slug !== $cat['id']) && store_get('categories', $slug)) $errors['name'] = 'A category with this name already exists.';
        if (trim((string)($_POST['image'] ?? '')) !== '' && $image === '') $errors['image'] = 'Enter a valid image URL.';

        if (!$errors) {
            if (!$cat) {
                $max = 0; foreach (cat_categories() as $c) $max = max($max, (int)($c['sort_order'] ?? 0));
                store_put('categories', ['id' => $slug, 'name' => $name, 'image' => $image, 'description' => $desc, 'sort_order' => $max + 1, 'visible' => $visible]);
                log_activity('category.create', "Added category {$name}", ['category' => $slug]);
                flash("Category “{$name}” added.");
            } else {
                $old = $cat['name'] ?? $cat['id'];
                $doc = array_replace($cat, ['name' => $name, 'image' => $image, 'description' => $desc, 'visible' => $visible]);
                $moved = 0;
                if ($old !== $name) $moved = cat_move_products($cat['id'], $name);
                if ($slug !== $cat['id']) {
                    $doc['id'] = $slug;
                    unset($doc['created_at']);
                    store_put('categories', $doc);
                    store_delete('categories', $cat['id']);
                } else {
                    store_put('categories', $doc);
                }
                log_activity('category.update', $old !== $name ? "Renamed category {$old} to {$name} ({$moved} product(s) updated)" : "Updated category {$name}", ['category' => $slug, 'from' => $cat['id']]);
                flash($old !== $name ? "Category renamed to “{$name}”. {$moved} product(s) updated." : "Category “{$name}” saved.");
            }
            redirect(admin_url('categories.php'));
        }
        $editId = $id;
    } elseif ($cat && $action === 'toggle') {
        $vis = ($cat['visible'] ?? true) === false;
        store_patch('categories', $id, ['visible' => $vis]);
        log_activity('category.update', ($vis ? 'Showed' : 'Hid') . " category {$cat['name']}", ['category' => $id]);
        flash($vis ? "“{$cat['name']}” is visible in the store." : "“{$cat['name']}” is hidden from the store menu.");
        redirect(admin_url('categories.php'));
    } elseif ($cat && ($action === 'up' || $action === 'down')) {
        $list = cat_normalise_order();
        $idx = array_search($id, array_column($list, 'id'), true);
        $swap = $action === 'up' ? $idx - 1 : $idx + 1;
        if ($idx !== false && isset($list[$swap])) {
            store_patch('categories', $list[$idx]['id'], ['sort_order' => $swap + 1]);
            store_patch('categories', $list[$swap]['id'], ['sort_order' => $idx + 1]);
            log_activity('category.update', "Reordered category {$cat['name']}", ['category' => $id]);
        }
        redirect(admin_url('categories.php'));
    } elseif ($action === 'order') {
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['order'] ?? []))));
        foreach ($ids as $i => $cid) if (store_get('categories', $cid)) store_patch('categories', $cid, ['sort_order' => $i + 1]);
        log_activity('category.update', 'Reordered categories');
        if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) { header('Content-Type: application/json'); echo json_encode(['success' => true]); exit; }
        flash('Category order saved.');
        redirect(admin_url('categories.php'));
    } elseif ($cat && $action === 'delete') {
        $count = cat_product_counts()[$id] ?? 0;
        $target = (string)($_POST['reassign'] ?? '');
        if ($count > 0) {
            $to = $target !== '' && $target !== $id ? store_get('categories', $target) : null;
            if (!$to) { flash("“{$cat['name']}” still has {$count} product(s). Choose a category to move them to.", 'error'); redirect(admin_url('categories.php')); }
            cat_move_products($id, $to['name']);
        }
        store_delete('categories', $id);
        log_activity('category.delete', "Deleted category {$cat['name']}" . ($count ? " (moved {$count} product(s) to {$to['name']})" : ''), ['category' => $id]);
        flash("Category “{$cat['name']}” deleted." . ($count ? " {$count} product(s) moved to “{$to['name']}”." : ''));
        redirect(admin_url('categories.php'));
    } else {
        flash('Category not found.', 'error');
        redirect(admin_url('categories.php'));
    }
}

$cats = cat_categories();
$counts = cat_product_counts();
$editing = $editId !== '' ? store_get('categories', $editId) : null;
$form = $errors ? array_replace($editing ?? [], [
    'name' => $_POST['name'] ?? '', 'image' => $_POST['image'] ?? '', 'description' => $_POST['description'] ?? '', 'visible' => !empty($_POST['visible']),
]) : ($editing ?? ['visible' => true]);
$canUpload = can('media.upload');
$icons = $SITE['category_images'] ?? [];
$firstImage = [];
foreach (store_all('products') as $p) {
    $s = slugify((string)($p['category'] ?? ''));
    if ($s !== '' && !isset($firstImage[$s])) $firstImage[$s] = cat_product_images($p)[0] ?? '';
}

admin_header('Categories', 'categories', ['actions' => $editing ? '<a class="btn btn-primary" href="' . admin_url('categories.php') . '"><i class="fa-solid fa-plus"></i> New category</a>' : '']);
?>
<style>
  .cat-img { width: 48px; height: 48px; border-radius: 12px; object-fit: contain; background: var(--surface-2, #f7f5f0); border: 1px solid var(--border, #e5e1d8); padding: 4px; }
  .cat-img.is-photo { object-fit: cover; padding: 0; }
  tr.is-dragging { opacity: .45; }
  .drag-handle { cursor: grab; color: var(--muted, #999); }
  #cat-preview { width: 72px; height: 72px; flex: none; }
  .sticky-side { position: sticky; top: 84px; }
</style>
<div class="layout-main-side">
  <section class="card">
    <div class="card-header">
      <div><h2><i class="fa-solid fa-layer-group"></i> All categories</h2><p>Drag rows or use the arrows to change the order shown in the store.</p></div>
      <span class="chip"><?= count($cats) ?> total</span>
    </div>
    <?php if (!$cats): ?>
      <div class="empty-state"><i class="fa-solid fa-layer-group"></i><h2>No categories yet</h2><p>Create your first category with the form.</p></div>
    <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th style="width:28px"></th><th>Category</th><th class="text-right">Products</th><th>Visible</th><th class="text-center">Order</th><th class="actions"></th></tr></thead>
        <tbody id="cat-rows">
        <?php foreach ($cats as $i => $c):
            $slug = $c['id'];
            $img = ($c['image'] ?? '') !== '' ? $c['image'] : ($icons[$slug] ?? ($firstImage[$slug] ?? ''));
            $isIcon = str_ends_with(strtolower(parse_url($img, PHP_URL_PATH) ?? ''), '.svg');
            $n = $counts[$slug] ?? 0;
            $visible = ($c['visible'] ?? true) !== false;
        ?>
          <tr draggable="true" data-id="<?= e($slug) ?>" <?= $editing && $editing['id'] === $slug ? 'class="is-selected"' : '' ?>>
            <td><i class="fa-solid fa-grip-vertical drag-handle"></i></td>
            <td>
              <div class="cell-main">
                <img class="cat-img <?= $isIcon ? '' : 'is-photo' ?>" src="<?= e(cat_img_src($img)) ?>" alt="" loading="lazy">
                <div>
                  <strong><?= e($c['name'] ?? $slug) ?></strong>
                  <small><?= e($slug) ?><?= ($c['description'] ?? '') !== '' ? ' · ' . e(mb_strimwidth($c['description'], 0, 60, '…')) : '' ?></small>
                </div>
              </div>
            </td>
            <td class="text-right">
              <?php if (can('products.view') && $n): ?><a href="<?= admin_url('products.php?category=' . urlencode($slug)) ?>"><?= $n ?></a><?php else: ?><?= $n ?><?php endif; ?>
            </td>
            <td>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= e($slug) ?>">
                <label class="switch" title="<?= $visible ? 'Visible in the store menu' : 'Hidden from the store menu' ?>"><input type="checkbox" <?= $visible ? 'checked' : '' ?> data-autosubmit><span></span></label>
              </form>
            </td>
            <td class="text-center nowrap">
              <form method="post" style="display:inline-flex;gap:4px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($slug) ?>">
                <button class="icon-btn" name="action" value="up" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-up"></i></button>
                <button class="icon-btn" name="action" value="down" title="Move down" <?= $i === count($cats) - 1 ? 'disabled' : '' ?>><i class="fa-solid fa-arrow-down"></i></button>
              </form>
            </td>
            <td class="actions nowrap">
              <a class="icon-btn" href="?edit=<?= urlencode($slug) ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
              <?php if ($n === 0): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($slug) ?>">
                  <button class="icon-btn danger" title="Delete" data-confirm="Delete the category “<?= e($c['name'] ?? $slug) ?>”?" data-confirm-tone="danger" data-confirm-ok="Delete"><i class="fa-solid fa-trash"></i></button>
                </form>
              <?php else: ?>
                <button class="icon-btn danger" type="button" title="Delete" data-modal-open="del-<?= e($slug) ?>"><i class="fa-solid fa-trash"></i></button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="card sticky-side" id="cat-form-card">
    <div class="card-header"><h2><?= $editing ? 'Edit category' : 'New category' ?></h2></div>
    <form method="post" class="card-body stack" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
      <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <label for="c-name">Name <span class="req">*</span></label>
        <input id="c-name" name="name" value="<?= e($form['name'] ?? '') ?>" maxlength="80" required>
        <?php if (isset($errors['name'])): ?><div class="error"><?= e($errors['name']) ?></div><?php endif; ?>
        <?php if ($editing && ($counts[$editing['id']] ?? 0)): ?><div class="hint">Renaming updates the category on its <?= (int)$counts[$editing['id']] ?> product(s).</div><?php endif; ?>
      </div>
      <div class="field <?= isset($errors['image']) ? 'has-error' : '' ?>">
        <label for="c-image">Icon or image</label>
        <div class="row" style="flex-wrap:nowrap;align-items:center">
          <img id="cat-preview" class="cat-img" src="<?= e(cat_img_src((string)($form['image'] ?? '') ?: ($editing ? ($icons[$editing['id']] ?? ($firstImage[$editing['id']] ?? '')) : ''))) ?>" alt="">
          <div class="stack" style="gap:6px;flex:1;min-width:0">
            <input id="c-image" name="image" value="<?= e($form['image'] ?? '') ?>" placeholder="https://… or leave empty">
            <?php if ($canUpload): ?>
              <label class="btn btn-secondary btn-sm" style="position:relative;overflow:hidden;align-self:flex-start"><i class="fa-solid fa-upload"></i> Upload image
                <input type="file" id="c-upload" accept="image/jpeg,image/png,image/webp,image/gif" style="position:absolute;inset:0;opacity:0;cursor:pointer">
              </label>
            <?php endif; ?>
          </div>
        </div>
        <?php if (isset($errors['image'])): ?><div class="error"><?= e($errors['image']) ?></div><?php endif; ?>
        <div class="hint">Empty uses the built-in icon or the first product photo.</div>
      </div>
      <div class="field">
        <label for="c-desc">Description</label>
        <textarea id="c-desc" name="description" rows="3" maxlength="500"><?= e($form['description'] ?? '') ?></textarea>
      </div>
      <div class="switch-row">
        <div><strong>Show in store</strong><small>Hidden categories are left out of the store menu.</small></div>
        <label class="switch"><input type="checkbox" name="visible" value="1" <?= ($form['visible'] ?? true) !== false ? 'checked' : '' ?>><span></span></label>
      </div>
      <div class="row">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> <?= $editing ? 'Save category' : 'Add category' ?></button>
        <?php if ($editing): ?><a class="btn btn-ghost" href="<?= admin_url('categories.php') ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </section>
</div>

<?php foreach ($cats as $c): $n = $counts[$c['id']] ?? 0; if (!$n) continue; ?>
<div class="modal" id="del-<?= e($c['id']) ?>" hidden>
  <form method="post" class="modal-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= e($c['id']) ?>">
    <div class="card-header"><h2>Delete “<?= e($c['name']) ?>”</h2><button class="icon-btn" type="button" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="card-body stack">
      <p>This category has <strong><?= $n ?> product(s)</strong>. Choose where to move them before deleting.</p>
      <div class="field">
        <label>Move products to</label>
        <select name="reassign" required>
          <option value="">Select category…</option>
          <?php foreach ($cats as $o): if ($o['id'] === $c['id']) continue; ?><option value="<?= e($o['id']) ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="card-footer"><button class="btn btn-ghost" type="button" data-modal-close>Cancel</button><button class="btn btn-danger" type="submit"><i class="fa-solid fa-trash"></i> Move &amp; delete</button></div>
  </form>
</div>
<?php endforeach; ?>

<script>
(function () {
  var url = document.getElementById('c-image'), prev = document.getElementById('cat-preview'), up = document.getElementById('c-upload');
  var toast = function (m, t) { if (window.adminToast) window.adminToast(m, t); };
  function show(u) { if (u) prev.src = /^(https?:|\/|data:)/.test(u) ? u : '/' + u; }
  url.addEventListener('change', function () { show(url.value.trim()); });
  if (up) up.addEventListener('change', function () {
    var f = up.files[0]; if (!f) return;
    if (f.size > 8 * 1024 * 1024) { toast('Image is larger than 8 MB.', 'error'); return; }
    var fd = new FormData(); fd.append('file', f); fd.append('context', 'category ' + (document.getElementById('c-name').value || ''));
    toast('Uploading…', 'info');
    window.adminFetch(<?= json_encode(admin_url('media.php')) ?>, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res.success) { url.value = res.url; show(res.url); toast('Image uploaded'); } else toast(res.error || 'Upload failed', 'error'); })
      .catch(function () { toast('Upload failed', 'error'); });
    up.value = '';
  });

  // Drag-to-reorder rows, saved via AJAX.
  var body = document.getElementById('cat-rows'); if (!body) return;
  var dragged = null;
  body.addEventListener('dragstart', function (e) { dragged = e.target.closest('tr'); if (dragged) { dragged.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; } });
  body.addEventListener('dragover', function (e) {
    if (!dragged) return; e.preventDefault();
    var over = e.target.closest('tr'); if (!over || over === dragged) return;
    var r = over.getBoundingClientRect();
    body.insertBefore(dragged, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
  });
  body.addEventListener('dragend', function () {
    if (!dragged) return; dragged.classList.remove('is-dragging'); dragged = null;
    var fd = new FormData(); fd.append('action', 'order');
    Array.prototype.forEach.call(body.querySelectorAll('tr[data-id]'), function (tr) { fd.append('order[]', tr.dataset.id); });
    window.adminFetch(location.pathname, { method: 'POST', body: fd })
      .then(function (r) { if (!r.ok) throw 0; toast('Order saved'); setTimeout(function () { location.reload(); }, 600); })
      .catch(function () { toast('Could not save the order', 'error'); });
  });
})();
</script>
<?php admin_footer();
