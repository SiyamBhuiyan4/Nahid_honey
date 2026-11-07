<?php
require __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/../../app/catalogue.php';
require_perm('products.edit');

$id = (string)($_POST['id'] ?? $_GET['id'] ?? '');
$existing = $id !== '' ? store_get('products', $id) : null;
if ($id !== '' && !$existing) {
    flash('That product no longer exists.', 'error');
    redirect(admin_url('products.php'));
}
$isNew = !$existing;
$errors = [];

$num = function ($v, bool $int = false) {
    $v = trim(str_replace(',', '', (string)$v));
    if ($v === '' || !is_numeric($v)) return null;
    return $int ? (int)round((float)$v) : round((float)$v, 2) + 0;
};
$str = fn($k, $max = 500) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'delete') {
        if (!can('products.delete')) { flash('You don’t have permission to delete products.', 'error'); redirect(admin_url('product-edit.php?id=' . urlencode($id))); }
        if ($existing) {
            store_delete('products', $id);
            log_activity('product.delete', 'Deleted product ' . cat_product_title($existing), ['product_id' => $id]);
            flash('“' . cat_product_title($existing) . '” was deleted.');
        }
        redirect(admin_url('products.php'));
    }

    $title   = $str('title', 200);
    $titleEn = $str('title_en', 200);
    $titleBn = $str('title_bn', 200);
    if ($title === '') $title = $titleEn !== '' ? $titleEn : $titleBn;
    if ($title === '') $errors['title'] = 'Enter a product title.';

    $regular = $num($_POST['regular_price'] ?? '');
    $sale    = $num($_POST['sale_price'] ?? '');
    $cost    = $num($_POST['cost_price'] ?? '');
    if ($regular === null && $sale === null) $errors['regular_price'] = 'Enter the product price.';
    if ($regular === null) $regular = $sale;
    if ($sale === null) $sale = $regular;
    if (($regular ?? 0) < 0 || ($sale ?? 0) < 0) $errors['regular_price'] = 'Prices cannot be negative.';
    if ($regular !== null && $sale !== null && $sale > $regular) $errors['sale_price'] = 'The sale price cannot be higher than the regular price.';

    $category = $str('category', 80);
    if ($category === '__new') $category = $str('new_category', 80);
    if ($category === '') $errors['category'] = 'Choose or create a category.';

    $track = !empty($_POST['track_stock']);
    $stock = $track ? $num($_POST['stock'] ?? '', true) : null;
    if ($track && $stock === null) $errors['stock'] = 'Enter the quantity in stock (0 if none).';
    if ($stock !== null && $stock < 0) $errors['stock'] = 'Stock cannot be negative.';
    $threshold = $num($_POST['low_stock_threshold'] ?? '', true);

    $images = [];
    foreach ((array)($_POST['images'] ?? []) as $u) {
        $u = cat_clean_image_url((string)$u);
        if ($u !== '' && !in_array($u, $images, true)) $images[] = $u;
    }
    $features = array_values(array_filter(array_map(fn($f) => mb_substr(trim((string)$f), 0, 300), (array)($_POST['features'] ?? [])), fn($f) => $f !== ''));
    $status = isset(PRODUCT_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'draft';

    $data = [
        'title' => $title, 'title_en' => $titleEn, 'title_bn' => $titleBn,
        'slug' => cat_unique_slug($str('slug', 160) ?: ($titleEn ?: $title), $isNew ? null : $id),
        'category' => $category, 'category_bn' => $str('category_bn', 80),
        'weight_volume' => $str('weight_volume', 40), 'weight' => $str('weight_volume', 40),
        'regular_price' => $regular, 'sale_price' => $sale, 'cost_price' => $cost,
        'discount_text' => $str('discount_text', 60),
        'sku' => $str('sku', 60),
        'stock' => $stock,
        'low_stock_threshold' => $threshold,
        'in_stock' => $track ? ($stock > 0) : !empty($_POST['in_stock']),
        'status' => $status,
        'featured' => !empty($_POST['featured']), 'top_selling' => !empty($_POST['featured']),
        'sort_order' => $num($_POST['sort_order'] ?? '', true) ?? 0,
        'badge' => $str('badge', 40),
        'description' => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['description'] ?? ''))), 0, 20000),
        'features' => $features,
        'image' => $images[0] ?? '',
        'images' => array_slice($images, 1),
        'seo_title' => $str('seo_title', 120), 'seo_description' => $str('seo_description', 320),
    ];

    if ($data['sku'] !== '') {
        foreach (store_all('products') as $p) {
            if ((string)$p['id'] !== $id && strcasecmp((string)($p['sku'] ?? ''), $data['sku']) === 0) {
                $errors['sku'] = 'Another product (#' . $p['id'] . ') already uses this SKU.'; break;
            }
        }
    }

    if (!$errors) {
        $doc = $isNew ? $data : array_replace($existing, $data, ['id' => $id]);
        if ($isNew) {
            if (!isset($_POST['sort_order']) || trim((string)$_POST['sort_order']) === '') {
                $max = 0; foreach (store_all('products') as $p) $max = max($max, (int)($p['sort_order'] ?? 0));
                $doc['sort_order'] = $max + 1;
            }
        }
        $saved = store_put('products', $doc);
        cat_ensure_category($category);

        if ($isNew) {
            log_activity('product.create', "Added product {$title}", ['product_id' => $saved['id']]);
            flash("“{$title}” was added" . ($status === 'draft' ? ' as a draft.' : ' and is live in the store.'));
        } else {
            $changed = [];
            foreach ($data as $k => $v) if (($existing[$k] ?? null) != $v && $k !== 'weight' && $k !== 'top_selling') $changed[] = $k;
            log_activity('product.update', "Updated product {$title}" . ($changed ? ' (' . implode(', ', $changed) . ')' : ''), ['product_id' => $id, 'fields' => $changed]);
            flash("“{$title}” was saved.");
        }
        redirect(admin_url(($_POST['after'] ?? '') === 'new' ? 'product-edit.php' : 'product-edit.php?id=' . urlencode($saved['id'])));
    }

    // Re-render with what was posted.
    $form = array_replace($existing ?? [], $data, ['id' => $id]);
    $form['category'] = $category;
    $form['_track'] = $track;
    $form['stock'] = $_POST['stock'] ?? '';
    $form['regular_price'] = $_POST['regular_price'] ?? '';
    $form['sale_price'] = $_POST['sale_price'] ?? '';
} else {
    $form = $existing ?? ['status' => 'published', 'in_stock' => true, 'features' => [], 'regular_price' => '', 'sale_price' => ''];
    $form['_track'] = cat_stock($form) !== null;
}

$images = cat_product_images($form);
$catNames = cat_category_names();
$curCat = trim((string)($form['category'] ?? ''));
if ($curCat !== '' && !in_array($curCat, $catNames, true)) $catNames[slugify($curCat)] = $curCat;
$badges = [];
foreach (store_all('products') as $p) if (!empty($p['badge'])) $badges[$p['badge']] = true;
$canUpload = can('media.upload');
$v = fn($k) => e($form[$k] ?? '');
$err = fn($k) => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$title = $isNew ? 'Add product' : 'Edit product';

admin_header($title, 'products', ['actions' => '<a class="btn btn-ghost" href="' . admin_url('products.php') . '"><i class="fa-solid fa-arrow-left"></i> All products</a>']);
?>
<style>
  .img-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
  .img-item { position: relative; aspect-ratio: 1; border-radius: 12px; overflow: hidden; border: 1px solid var(--border, #e5e1d8); background: var(--surface-2, #f7f5f0); cursor: grab; }
  .img-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .img-item.is-dragging { opacity: .4; }
  .img-item.is-loading { display: grid; place-items: center; color: var(--muted, #888); cursor: default; }
  .img-item .img-tag { position: absolute; left: 8px; top: 8px; background: rgba(15,42,40,.85); color: #fff; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; }
  .img-item .img-tools { position: absolute; inset: auto 0 0 0; display: flex; justify-content: center; gap: 4px; padding: 6px; background: linear-gradient(transparent, rgba(0,0,0,.55)); opacity: 0; transition: opacity .15s; }
  .img-item:hover .img-tools, .img-item:focus-within .img-tools { opacity: 1; }
  .img-item .img-tools button { width: 28px; height: 28px; border: 0; border-radius: 8px; background: rgba(255,255,255,.92); color: #222; cursor: pointer; font-size: 12px; }
  .img-item .img-tools button:hover { background: #fff; }
  .feature-row { display: flex; gap: 8px; align-items: center; }
  .feature-row input { flex: 1; }
  .feature-list { display: flex; flex-direction: column; gap: 8px; }
  .serp { border: 1px solid var(--border, #e5e1d8); border-radius: 10px; padding: 12px 14px; background: var(--surface, #fff); }
  .serp .serp-title { color: #1a0dab; font-size: 16px; line-height: 1.3; }
  .serp .serp-url { color: #006621; font-size: 12.5px; }
  .serp .serp-desc { color: #545454; font-size: 13px; }
  .price-summary { display: flex; gap: 8px; flex-wrap: wrap; }
  .sticky-side { position: sticky; top: 84px; }
</style>

<?php if ($errors): ?>
  <div class="alert alert-error">Please fix the highlighted fields.<ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" id="product-form" class="layout-main-side" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($isNew ? '' : $id) ?>">

  <div class="stack">
    <section class="card">
      <div class="card-header"><h2><i class="fa-solid fa-circle-info"></i> Basic details</h2></div>
      <div class="card-body form-grid">
        <div class="field span-2 <?= isset($errors['title']) ? 'has-error' : '' ?>">
          <label for="f-title">Title shown in store <span class="req">*</span></label>
          <input id="f-title" name="title" value="<?= $v('title') ?>" required maxlength="200" placeholder="e.g. Sundarban Natural Kholisa Flower Honey">
          <?= $err('title') ?>
        </div>
        <div class="field">
          <label for="f-title-en">English title</label>
          <input id="f-title-en" name="title_en" value="<?= $v('title_en') ?>" maxlength="200">
        </div>
        <div class="field">
          <label for="f-title-bn">Bangla title</label>
          <input id="f-title-bn" name="title_bn" value="<?= $v('title_bn') ?>" maxlength="200" lang="bn">
        </div>
        <div class="field span-2">
          <label for="f-slug">URL slug</label>
          <div class="input-group">
            <input id="f-slug" name="slug" value="<?= $v('slug') ?>" maxlength="160" <?= !empty($form['slug']) ? 'data-touched="1"' : '' ?>>
            <button class="btn btn-ghost btn-sm" type="button" id="slug-regen" title="Generate from title"><i class="fa-solid fa-rotate"></i></button>
          </div>
          <div class="hint">Generated from the English title. Must be unique — a number is added automatically if it is taken.</div>
        </div>
        <div class="field span-2">
          <label for="f-desc">Description</label>
          <textarea id="f-desc" name="description" rows="7"><?= $v('description') ?></textarea>
        </div>
        <div class="field span-2">
          <label>Key features</label>
          <div class="feature-list" id="feature-list">
            <?php foreach (array_merge((array)($form['features'] ?? []), [''] ) as $f): ?>
              <div class="feature-row">
                <i class="fa-solid fa-check muted"></i>
                <input name="features[]" value="<?= e($f) ?>" maxlength="300" placeholder="e.g. 100% raw and unprocessed">
                <button class="icon-btn danger" type="button" data-feature-remove title="Remove"><i class="fa-solid fa-xmark"></i></button>
              </div>
            <?php endforeach; ?>
          </div>
          <div><button class="btn btn-ghost btn-sm" type="button" id="feature-add"><i class="fa-solid fa-plus"></i> Add feature</button></div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <div><h2><i class="fa-regular fa-images"></i> Images</h2><p>The first image is the main photo. Drag to reorder.</p></div>
      </div>
      <div class="card-body stack">
        <div class="img-list" id="img-list">
          <?php foreach ($images as $i => $src): ?>
            <div class="img-item" draggable="true">
              <img src="<?= e(cat_img_src($src)) ?>" alt="" loading="lazy">
              <input type="hidden" name="images[]" value="<?= e($src) ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <p class="muted" id="img-empty" <?= $images ? 'hidden' : '' ?>>No images yet — add one below.</p>
        <div class="grid-2">
          <?php if ($canUpload): ?>
          <label class="dropzone" id="dropzone">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <strong>Upload images</strong>
            <span class="small">JPG, PNG, WebP or GIF · up to 8 MB each</span>
            <input type="file" id="img-upload" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
          </label>
          <?php endif; ?>
          <div class="field">
            <label for="img-url">Add image by URL</label>
            <div class="input-group">
              <input id="img-url" type="url" placeholder="https://…">
              <button class="btn btn-secondary btn-sm" type="button" id="img-url-add">Add</button>
            </div>
            <div class="hint">Paste a direct link to an image.</div>
          </div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><i class="fa-solid fa-tag"></i> Pricing</h2></div>
      <div class="card-body form-grid">
        <div class="field <?= isset($errors['regular_price']) ? 'has-error' : '' ?>">
          <label for="f-regular">Regular price <span class="req">*</span></label>
          <div class="input-group"><span class="prefix"><?= e($SITE['currency_symbol'] ?? '৳') ?></span><input id="f-regular" name="regular_price" inputmode="decimal" value="<?= $v('regular_price') ?>"></div>
          <?= $err('regular_price') ?>
        </div>
        <div class="field <?= isset($errors['sale_price']) ? 'has-error' : '' ?>">
          <label for="f-sale">Sale price</label>
          <div class="input-group"><span class="prefix"><?= e($SITE['currency_symbol'] ?? '৳') ?></span><input id="f-sale" name="sale_price" inputmode="decimal" value="<?= $v('sale_price') ?>"></div>
          <?= $err('sale_price') ?>
          <div class="hint">Leave empty or equal to the regular price for no discount.</div>
        </div>
        <div class="field span-2">
          <div class="price-summary" id="price-summary"></div>
        </div>
        <div class="field">
          <label for="f-discount-text">Discount label</label>
          <input id="f-discount-text" name="discount_text" value="<?= $v('discount_text') ?>" maxlength="60" placeholder="Shown on the product card">
          <div class="hint"><a href="#" id="discount-fill">Use calculated discount</a></div>
        </div>
        <div class="field">
          <label for="f-cost">Cost price <small class="muted">(private)</small></label>
          <div class="input-group"><span class="prefix"><?= e($SITE['currency_symbol'] ?? '৳') ?></span><input id="f-cost" name="cost_price" inputmode="decimal" value="<?= $v('cost_price') ?>"></div>
          <div class="hint" id="margin-hint">Only visible to staff. Used to show your margin.</div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><i class="fa-solid fa-boxes-stacked"></i> Inventory</h2></div>
      <div class="card-body form-grid">
        <div class="field <?= isset($errors['sku']) ? 'has-error' : '' ?>">
          <label for="f-sku">SKU</label>
          <input id="f-sku" name="sku" value="<?= $v('sku') ?>" maxlength="60" placeholder="Optional stock code">
          <?= $err('sku') ?>
        </div>
        <div class="field">
          <label for="f-weight">Weight / volume</label>
          <input id="f-weight" name="weight_volume" value="<?= e($form['weight_volume'] ?? $form['weight'] ?? '') ?>" maxlength="40" placeholder="e.g. 500g, 1kg, 1L">
        </div>
        <div class="field span-2">
          <div class="switch-row">
            <div><strong>Track stock quantity</strong><small>Availability updates automatically from the quantity.</small></div>
            <label class="switch"><input type="checkbox" name="track_stock" value="1" id="f-track" <?= $form['_track'] ? 'checked' : '' ?>><span></span></label>
          </div>
        </div>
        <div class="field <?= isset($errors['stock']) ? 'has-error' : '' ?>" data-track="on">
          <label for="f-stock">Quantity in stock</label>
          <input id="f-stock" name="stock" type="number" min="0" step="1" value="<?= e($form['stock'] ?? '') ?>">
          <?= $err('stock') ?>
        </div>
        <div class="field" data-track="on">
          <label for="f-threshold">Low-stock alert at</label>
          <input id="f-threshold" name="low_stock_threshold" type="number" min="0" step="1" value="<?= $v('low_stock_threshold') ?>" placeholder="Default: <?= cat_low_stock_default() ?>">
        </div>
        <div class="field span-2" data-track="off">
          <div class="switch-row">
            <div><strong>In stock</strong><small>Turn off to show the product as out of stock.</small></div>
            <label class="switch"><input type="checkbox" name="in_stock" value="1" <?= ($form['in_stock'] ?? true) !== false ? 'checked' : '' ?>><span></span></label>
          </div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><div><h2><i class="fa-solid fa-magnifying-glass"></i> Search engine listing</h2><p>Optional — defaults to the title and description.</p></div></div>
      <div class="card-body stack">
        <div class="serp" aria-hidden="true">
          <div class="serp-url"><?= e(preg_replace('#^https?://#', '', rtrim((is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? ''), '/'))) ?> › product</div>
          <div class="serp-title" id="serp-title"></div>
          <div class="serp-desc" id="serp-desc"></div>
        </div>
        <div class="field">
          <label for="f-seo-title">SEO title <small class="muted" id="seo-title-count"></small></label>
          <input id="f-seo-title" name="seo_title" value="<?= $v('seo_title') ?>" maxlength="120">
        </div>
        <div class="field">
          <label for="f-seo-desc">Meta description <small class="muted" id="seo-desc-count"></small></label>
          <textarea id="f-seo-desc" name="seo_description" rows="3" maxlength="320"><?= $v('seo_description') ?></textarea>
        </div>
      </div>
    </section>
  </div>

  <aside class="stack sticky-side">
    <section class="card">
      <div class="card-header"><h2>Publish</h2><?php if (!$isNew): ?><?= cat_status_badge($form) ?><?php endif; ?></div>
      <div class="card-body stack">
        <div class="field">
          <label for="f-status">Status</label>
          <select id="f-status" name="status">
            <?php foreach (PRODUCT_STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= cat_status($form) === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
          </select>
          <div class="hint">Drafts are hidden from the store.</div>
        </div>
        <div class="switch-row">
          <div><strong>Featured</strong><small>Show in the featured section on the home page.</small></div>
          <label class="switch"><input type="checkbox" name="featured" value="1" <?= !empty($form['featured']) ? 'checked' : '' ?>><span></span></label>
        </div>
        <div class="field">
          <label for="f-sort">Display order</label>
          <input id="f-sort" name="sort_order" type="number" step="1" value="<?= $v('sort_order') ?>" placeholder="Auto">
          <div class="hint">Lower numbers appear first.</div>
        </div>
      </div>
      <div class="card-footer stack">
        <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-check"></i> <?= $isNew ? 'Save product' : 'Save changes' ?></button>
        <button class="btn btn-secondary btn-block" type="submit" name="after" value="new">Save &amp; add another</button>
        <?php if (!$isNew): ?>
          <div class="split">
            <?php if (cat_status($form) === 'published'): ?><a class="btn btn-ghost btn-sm" href="/product.php?id=<?= urlencode($id) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> View</a><?php else: ?><span></span><?php endif; ?>
            <?php if (can('products.delete')): ?>
              <button class="btn btn-danger btn-sm" type="submit" name="action" value="delete" formnovalidate data-confirm="Delete this product permanently? This cannot be undone." data-confirm-tone="danger" data-confirm-ok="Delete"><i class="fa-solid fa-trash"></i> Delete</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2>Organisation</h2></div>
      <div class="card-body stack">
        <div class="field <?= isset($errors['category']) ? 'has-error' : '' ?>">
          <label for="f-category">Category <span class="req">*</span></label>
          <select id="f-category" name="category">
            <option value="">Select category…</option>
            <?php foreach ($catNames as $name): ?><option value="<?= e($name) ?>" <?= $curCat === $name ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
            <option value="__new">+ New category…</option>
          </select>
          <input id="f-new-category" name="new_category" placeholder="New category name" maxlength="80" hidden style="margin-top:8px">
          <?= $err('category') ?>
        </div>
        <div class="field">
          <label for="f-category-bn">Category (Bangla)</label>
          <input id="f-category-bn" name="category_bn" value="<?= $v('category_bn') ?>" maxlength="80" lang="bn">
        </div>
        <div class="field">
          <label for="f-badge">Badge</label>
          <input id="f-badge" name="badge" value="<?= $v('badge') ?>" maxlength="40" list="badge-list" placeholder="e.g. Best Seller">
          <datalist id="badge-list"><?php foreach (array_keys($badges) as $b): ?><option value="<?= e($b) ?>"><?php endforeach; ?></datalist>
        </div>
      </div>
    </section>

    <?php if (!$isNew): ?>
    <section class="card">
      <div class="card-header"><h2>Ratings</h2></div>
      <div class="card-body">
        <dl class="kv">
          <dt>Rating</dt><dd><?= isset($form['rating']) ? e(number_format((float)$form['rating'], 1)) . ' <i class="fa-solid fa-star" style="color:#E8A317"></i>' : '—' ?></dd>
          <dt>Reviews</dt><dd><?= e($form['reviews_count'] ?? 0) ?></dd>
          <dt>Created</dt><dd><?= e(!empty($form['created_at']) ? date('j M Y', strtotime($form['created_at'])) : '—') ?></dd>
          <dt>Updated</dt><dd><?= e(!empty($form['updated_at']) ? date('j M Y, g:i a', strtotime($form['updated_at'])) : '—') ?></dd>
        </dl>
        <?php if (can('reviews.manage')): ?><p style="margin-top:12px"><a href="<?= admin_url('reviews.php?product=' . urlencode($id)) ?>">Manage this product’s reviews →</a></p><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </aside>
</form>

<script>
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var currency = <?= json_encode($SITE['currency_symbol'] ?? '৳') ?>;
  var toast = function (m, t) { if (window.adminToast) window.adminToast(m, t); else alert(m); };

  /* ---- Slug ---- */
  var slug = $('#f-slug'), tEn = $('#f-title-en'), tMain = $('#f-title');
  function slugify(s) {
    s = (s || '').toLowerCase().trim().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '');
    return s;
  }
  function autoSlug() { if (!slug.dataset.touched) slug.value = slugify(tEn.value || tMain.value); }
  tEn.addEventListener('input', autoSlug); tMain.addEventListener('input', autoSlug);
  slug.addEventListener('input', function () { slug.dataset.touched = slug.value ? '1' : ''; });
  slug.addEventListener('blur', function () { slug.value = slugify(slug.value); });
  $('#slug-regen').addEventListener('click', function () { slug.dataset.touched = ''; autoSlug(); });

  /* ---- Features ---- */
  var list = $('#feature-list');
  $('#feature-add').addEventListener('click', function () {
    var row = list.querySelector('.feature-row').cloneNode(true);
    row.querySelector('input').value = '';
    list.appendChild(row); row.querySelector('input').focus();
  });
  list.addEventListener('click', function (e) {
    var b = e.target.closest('[data-feature-remove]'); if (!b) return;
    var rows = $$('.feature-row', list);
    if (rows.length > 1) b.closest('.feature-row').remove(); else rows[0].querySelector('input').value = '';
  });
  list.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); $('#feature-add').click(); }
  });

  /* ---- Pricing ---- */
  var reg = $('#f-regular'), sale = $('#f-sale'), cost = $('#f-cost'), summary = $('#price-summary');
  function n(v) { v = parseFloat(String(v || '').replace(/,/g, '')); return isNaN(v) ? null : v; }
  function fmt(v) { return currency + Math.round(v).toLocaleString('en-US'); }
  function priceInfo() {
    var r = n(reg.value), s = n(sale.value); if (r === null) r = s; if (s === null) s = r;
    var html = '';
    if (r && s !== null && s < r) {
      var pct = Math.round((r - s) / r * 100);
      html += '<span class="badge badge-green">' + pct + '% off</span><span class="badge badge-gray">Customer saves ' + fmt(r - s) + '</span>';
    } else if (r && s > r) {
      html += '<span class="badge badge-red">Sale price is higher than the regular price</span>';
    } else if (r) html += '<span class="badge badge-gray">No discount</span>';
    var c = n(cost.value);
    if (c !== null && s) {
      var m = s - c, mp = Math.round(m / s * 100);
      html += '<span class="badge ' + (m >= 0 ? 'badge-blue' : 'badge-red') + '">Margin ' + fmt(m) + ' (' + mp + '%)</span>';
    }
    summary.innerHTML = html;
    return { r: r, s: s };
  }
  [reg, sale, cost].forEach(function (i) { i.addEventListener('input', priceInfo); });
  priceInfo();
  $('#discount-fill').addEventListener('click', function (e) {
    e.preventDefault();
    var p = priceInfo();
    if (p.r && p.s < p.r) $('#f-discount-text').value = fmt(p.r - p.s) + ' off';
    else toast('Set a sale price lower than the regular price first.', 'info');
  });

  /* ---- Stock tracking ---- */
  var track = $('#f-track');
  function syncTrack() {
    $$('[data-track]').forEach(function (el) { el.hidden = (el.dataset.track === 'on') !== track.checked; });
  }
  track.addEventListener('change', syncTrack); syncTrack();

  /* ---- Category ---- */
  var cat = $('#f-category'), newCat = $('#f-new-category');
  function syncCat() { newCat.hidden = cat.value !== '__new'; if (!newCat.hidden) newCat.focus(); }
  cat.addEventListener('change', syncCat);

  /* ---- SEO preview ---- */
  var seoT = $('#f-seo-title'), seoD = $('#f-seo-desc'), desc = $('#f-desc');
  function seo() {
    var t = seoT.value || tEn.value || tMain.value || 'Product title';
    var d = seoD.value || desc.value.replace(/\s+/g, ' ').slice(0, 160) || 'Product description';
    $('#serp-title').textContent = t; $('#serp-desc').textContent = d;
    $('#seo-title-count').textContent = seoT.value.length + '/60 recommended';
    $('#seo-desc-count').textContent = seoD.value.length + '/160 recommended';
  }
  [seoT, seoD, desc, tEn, tMain].forEach(function (i) { i.addEventListener('input', seo); }); seo();

  /* ---- Images ---- */
  var grid = $('#img-list'), empty = $('#img-empty');
  var tools = '<div class="img-tools">'
    + '<button type="button" data-img="left" title="Move left"><i class="fa-solid fa-arrow-left"></i></button>'
    + '<button type="button" data-img="main" title="Make main image"><i class="fa-solid fa-star"></i></button>'
    + '<button type="button" data-img="right" title="Move right"><i class="fa-solid fa-arrow-right"></i></button>'
    + '<button type="button" data-img="remove" title="Remove"><i class="fa-solid fa-trash"></i></button></div>';
  function decorate(item) {
    if (!item.querySelector('.img-tools')) item.insertAdjacentHTML('beforeend', tools);
  }
  function refresh() {
    var items = $$('.img-item:not(.is-loading)', grid);
    items.forEach(function (it, i) {
      var tag = it.querySelector('.img-tag');
      if (i === 0 && !tag) it.insertAdjacentHTML('afterbegin', '<span class="img-tag">Main</span>');
      if (i !== 0 && tag) tag.remove();
    });
    empty.hidden = $$('.img-item', grid).length > 0;
  }
  function addImage(url, before) {
    if ($$('input[name="images[]"]', grid).some(function (i) { return i.value === url; })) { toast('That image is already added.', 'info'); return; }
    var it = document.createElement('div');
    it.className = 'img-item'; it.draggable = true;
    var img = document.createElement('img'); img.alt = ''; img.src = url;
    var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'images[]'; inp.value = url;
    it.appendChild(img); it.appendChild(inp); decorate(it);
    if (before) grid.replaceChild(it, before); else grid.appendChild(it);
    refresh();
  }
  $$('.img-item', grid).forEach(decorate); refresh();

  grid.addEventListener('click', function (e) {
    var b = e.target.closest('[data-img]'); if (!b) return;
    var it = b.closest('.img-item'), act = b.dataset.img;
    if (act === 'remove') it.remove();
    else if (act === 'main') grid.insertBefore(it, grid.firstElementChild);
    else if (act === 'left' && it.previousElementSibling) grid.insertBefore(it, it.previousElementSibling);
    else if (act === 'right' && it.nextElementSibling) grid.insertBefore(it.nextElementSibling, it);
    refresh();
  });
  var dragged = null;
  grid.addEventListener('dragstart', function (e) { dragged = e.target.closest('.img-item'); if (dragged) { dragged.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; } });
  grid.addEventListener('dragend', function () { if (dragged) dragged.classList.remove('is-dragging'); dragged = null; refresh(); });
  grid.addEventListener('dragover', function (e) {
    if (!dragged) return; e.preventDefault();
    var over = e.target.closest('.img-item'); if (!over || over === dragged) return;
    var r = over.getBoundingClientRect();
    grid.insertBefore(dragged, (e.clientX - r.left) > r.width / 2 ? over.nextSibling : over);
  });

  var urlIn = $('#img-url');
  function addUrl() {
    var u = urlIn.value.trim(); if (!u) return;
    if (!/^https?:\/\/\S+$/i.test(u) && !/^\/\S+$/.test(u)) { toast('Enter a valid image URL (https://…).', 'error'); return; }
    addImage(u); urlIn.value = '';
  }
  $('#img-url-add').addEventListener('click', addUrl);
  urlIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addUrl(); } });

  var up = $('#img-upload'), dz = $('#dropzone');
  function upload(file) {
    if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) { toast(file.name + ': only JPG, PNG, WebP or GIF images.', 'error'); return; }
    if (file.size > 8 * 1024 * 1024) { toast(file.name + ' is larger than 8 MB.', 'error'); return; }
    var ph = document.createElement('div');
    ph.className = 'img-item is-loading'; ph.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    grid.appendChild(ph); empty.hidden = true;
    var fd = new FormData(); fd.append('file', file); fd.append('context', tEn.value || tMain.value || 'product');
    var req = window.adminFetch ? window.adminFetch(<?= json_encode(admin_url('media.php')) ?>, { method: 'POST', body: fd })
      : fetch(<?= json_encode(admin_url('media.php')) ?>, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').content } });
    req.then(function (r) { return r.json().catch(function () { return { success: false, error: 'Upload failed (' + r.status + ').' }; }); })
      .then(function (res) {
        if (res.success && res.url) { addImage(res.url, ph); toast('Image uploaded'); }
        else { ph.remove(); refresh(); toast(res.error || 'Upload failed.', 'error'); }
      })
      .catch(function () { ph.remove(); refresh(); toast('Upload failed. Check your connection.', 'error'); });
  }
  if (up) {
    up.addEventListener('change', function () { Array.prototype.forEach.call(up.files, upload); up.value = ''; });
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { if (dragged) return; e.preventDefault(); dz.classList.add('is-dragover'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function () { dz.classList.remove('is-dragover'); }); });
    dz.addEventListener('drop', function (e) { if (dragged) return; e.preventDefault(); Array.prototype.forEach.call(e.dataTransfer.files, upload); });
  }

  /* ---- Unsaved changes guard ---- */
  var form = $('#product-form'), dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function (e) {
    if ($$('.img-item.is-loading', grid).length) { e.preventDefault(); toast('Please wait for the image upload to finish.', 'info'); return; }
    dirty = false;
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php admin_footer();
