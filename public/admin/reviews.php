<?php
require __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/../../app/catalogue.php';
require_perm('reviews.manage');

$back = admin_url('reviews.php') . (($_POST['_return'] ?? '') !== '' ? '?' . preg_replace('/[^A-Za-z0-9_=&%.+\-\[\]]/', '', (string)$_POST['_return']) : '');
$errors = [];
$editId = (string)($_GET['edit'] ?? '');
$isNewForm = isset($_GET['new']);

/** Recomputes ratings for each distinct product id given. */
function reviews_touch(array $productIds): void {
    foreach (array_unique(array_filter(array_map('strval', $productIds), fn($v) => $v !== '')) as $pid) cat_recompute_rating($pid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if (isset($_POST['row'])) {
        [$act, $id] = array_pad(explode('|', (string)$_POST['row'], 2), 2, '');
        $r = store_get('reviews', $id);
        if (!$r) { flash('Review not found.', 'error'); redirect($back); }
        $who = $r['customer_name'] ?? 'customer';
        if ($act === 'delete') {
            store_delete('reviews', $id);
            log_activity('review.delete', "Deleted review #{$id} by {$who}", ['review_id' => $id]);
            flash('Review deleted.');
        } elseif (isset(REVIEW_STATUSES[$act])) {
            store_patch('reviews', $id, ['status' => $act]);
            log_activity('review.update', REVIEW_STATUSES[$act] . " review #{$id} by {$who}", ['review_id' => $id, 'status' => $act]);
            flash(['approved' => 'Review approved — it is now visible in the store.', 'hidden' => 'Review hidden from the store.', 'pending' => 'Review moved back to pending.'][$act]);
        }
        reviews_touch([$r['product_id'] ?? '']);
        redirect($back);
    }

    if ($action === 'bulk') {
        $ids = array_values(array_filter(array_map('strval', (array)($_POST['ids'] ?? []))));
        $op = (string)($_POST['bulk'] ?? '');
        if (!$ids || ($op !== 'delete' && !isset(REVIEW_STATUSES[$op]))) { flash('Select reviews and an action first.', 'warning'); redirect($back); }
        $pids = []; $n = 0;
        foreach ($ids as $id) {
            $r = store_get('reviews', $id); if (!$r) continue;
            $pids[] = $r['product_id'] ?? '';
            if ($op === 'delete') store_delete('reviews', $id); else store_patch('reviews', $id, ['status' => $op]);
            $n++;
        }
        reviews_touch($pids);
        $label = $op === 'delete' ? 'Deleted' : REVIEW_STATUSES[$op];
        log_activity($op === 'delete' ? 'review.delete' : 'review.update', "{$label} {$n} review(s)", ['ids' => $ids]);
        flash("{$label}: {$n} review(s).");
        redirect($back);
    }

    if ($action === 'save') {
        $id = (string)($_POST['id'] ?? '');
        $old = $id !== '' ? store_get('reviews', $id) : null;
        if ($id !== '' && !$old) { flash('Review not found.', 'error'); redirect(admin_url('reviews.php')); }
        $name = mb_substr(trim((string)($_POST['customer_name'] ?? '')), 0, 80);
        $comment = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['comment'] ?? ''))), 0, 3000);
        $rating = (float)($_POST['rating'] ?? 0);
        $pid = (string)($_POST['product_id'] ?? '');
        $product = $pid !== '' ? store_get('products', $pid) : null;
        $date = (string)($_POST['review_date'] ?? '');
        $avatar = cat_clean_image_url((string)($_POST['avatar'] ?? ''));
        if ($name === '') $errors['customer_name'] = 'Enter the customer’s name.';
        if ($comment === '') $errors['comment'] = 'Enter the review text.';
        if ($rating < 1 || $rating > 5) $errors['rating'] = 'Choose a rating from 1 to 5.';
        if ($pid !== '' && !$product) $errors['product_id'] = 'That product no longer exists.';
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors['review_date'] = 'Enter a valid date.';
        if (!$errors) {
            $status = isset(REVIEW_STATUSES[$_POST['status'] ?? '']) ? $_POST['status'] : 'approved';
            $data = [
                'customer_name' => $name,
                'location' => mb_substr(trim((string)($_POST['location'] ?? '')), 0, 80),
                'avatar' => $avatar,
                'product_id' => $product ? (ctype_digit((string)$product['id']) ? (int)$product['id'] : $product['id']) : null,
                'product_title' => $product ? trim((string)($product['title'] ?? '') . (!empty($product['weight_volume']) ? ' (' . $product['weight_volume'] . ')' : '')) : '',
                'rating' => round($rating, 1),
                'review_date' => $date !== '' ? $date : date('Y-m-d'),
                'comment' => $comment,
                'verified_purchase' => !empty($_POST['verified_purchase']),
                'likes_count' => max(0, (int)($_POST['likes_count'] ?? 0)),
                'status' => $status,
            ];
            if ($old) {
                store_put('reviews', array_replace($old, $data, ['id' => $id]));
                log_activity('review.update', "Edited review #{$id} by {$name}", ['review_id' => $id]);
                flash('Review saved.');
            } else {
                $data['source'] = 'Added by staff';
                $new = store_put('reviews', $data);
                log_activity('review.create', "Added review #{$new['id']} by {$name}", ['review_id' => $new['id']]);
                flash('Review added.');
            }
            reviews_touch([$old['product_id'] ?? '', $data['product_id'] ?? '']);
            redirect(admin_url('reviews.php'));
        }
        $editId = $id; $isNewForm = $id === '';
    }
}

// ---------- Listing ----------
$all = store_all('reviews');
$products = [];
foreach (store_all('products') as $p) $products[(string)$p['id']] = $p;

$counts = ['all' => count($all), 'pending' => 0, 'approved' => 0, 'hidden' => 0];
foreach ($all as $r) $counts[cat_review_status($r)]++;

$tab = (string)($_GET['status'] ?? ($counts['pending'] ? 'pending' : 'all'));
if ($tab !== 'all' && !isset(REVIEW_STATUSES[$tab])) $tab = 'all';
$fProduct = (string)($_GET['product'] ?? '');
$fRating = (string)($_GET['rating'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
$perPage = 25;

$rows = array_values(array_filter($all, function ($r) use ($tab, $fProduct, $fRating, $q) {
    if ($tab !== 'all' && cat_review_status($r) !== $tab) return false;
    if ($fProduct === 'none' && (string)($r['product_id'] ?? '') !== '') return false;
    if ($fProduct !== '' && $fProduct !== 'none' && (string)($r['product_id'] ?? '') !== $fProduct) return false;
    if ($fRating !== '' && (int)round((float)($r['rating'] ?? 0)) !== (int)$fRating) return false;
    if ($q !== '') {
        $hay = mb_strtolower(implode(' ', [$r['customer_name'] ?? '', $r['location'] ?? '', $r['comment'] ?? '', $r['product_title'] ?? '']));
        if (!str_contains($hay, mb_strtolower($q))) return false;
    }
    return true;
}));
usort($rows, fn($a, $b) => strcmp($b['review_date'] ?? '', $a['review_date'] ?? '') ?: strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
$total = count($rows);
[$rows, $page, $pages] = cat_paginate($rows, $perPage, $page);
$base = array_filter(['product' => $fProduct, 'rating' => $fRating, 'q' => $q], fn($v) => $v !== '');
$query = array_merge(['status' => $tab], $base);
$returnQs = http_build_query(array_merge($query, $page > 1 ? ['page' => $page] : []));

$editing = $editId !== '' ? store_get('reviews', $editId) : null;
$showForm = $editing || $isNewForm;
$form = $errors ? array_replace($editing ?? [], $_POST) : ($editing ?? ['rating' => 5, 'status' => 'approved', 'review_date' => date('Y-m-d'), 'verified_purchase' => true, 'product_id' => $fProduct !== 'none' ? $fProduct : '']);
$productList = array_values($products);
usort($productList, fn($a, $b) => strcasecmp(cat_product_title($a), cat_product_title($b)));

$stars = function ($rating): string {
    $r = (float)$rating; $h = '<span class="nowrap" title="' . e(number_format($r, 1)) . ' / 5" style="color:#E8A317">';
    for ($i = 1; $i <= 5; $i++) $h .= '<i class="fa-' . ($r >= $i - 0.25 ? 'solid fa-star' : ($r >= $i - 0.75 ? 'solid fa-star-half-stroke' : 'regular fa-star')) . '"></i>';
    return $h . '</span>';
};
$tone = ['pending' => 'amber', 'approved' => 'green', 'hidden' => 'gray'];

admin_header('Reviews', 'reviews', ['actions' => $showForm ? '' : '<a class="btn btn-primary" href="?new=1' . ($fProduct !== '' && $fProduct !== 'none' ? '&amp;product=' . e(urlencode($fProduct)) : '') . '"><i class="fa-solid fa-plus"></i> Add review</a>']);
?>
<style>
  .rv-comment { max-width: 420px; white-space: normal; color: var(--text-2, #444); line-height: 1.45; }
  .rv-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; flex: none; background: var(--surface-2, #f2efe8); display: grid; place-items: center; font-weight: 600; color: var(--muted, #888); }
  .star-pick { display: inline-flex; flex-direction: row-reverse; gap: 2px; font-size: 22px; }
  .star-pick input { position: absolute; opacity: 0; width: 0; height: 0; }
  .star-pick label { cursor: pointer; color: #D6D0C4; }
  .star-pick input:checked ~ label, .star-pick label:hover, .star-pick label:hover ~ label { color: #E8A317; }
  .star-pick input:focus-visible + label { outline: 2px solid #E8A317; outline-offset: 2px; border-radius: 4px; }
</style>

<?php if ($showForm): ?>
<section class="card">
  <div class="card-header">
    <h2><i class="fa-regular fa-star"></i> <?= $editing ? 'Edit review #' . e($editing['id']) : 'Add a review' ?></h2>
    <a class="btn btn-ghost btn-sm" href="<?= admin_url('reviews.php') ?>">Cancel</a>
  </div>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
    <div class="card-body form-grid">
      <div class="field <?= isset($errors['customer_name']) ? 'has-error' : '' ?>">
        <label for="r-name">Customer name <span class="req">*</span></label>
        <input id="r-name" name="customer_name" value="<?= e($form['customer_name'] ?? '') ?>" maxlength="80" required>
        <?php if (isset($errors['customer_name'])): ?><div class="error"><?= e($errors['customer_name']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="r-loc">Location</label>
        <input id="r-loc" name="location" value="<?= e($form['location'] ?? '') ?>" maxlength="80" placeholder="e.g. Uttara, Dhaka">
      </div>
      <div class="field <?= isset($errors['product_id']) ? 'has-error' : '' ?>">
        <label for="r-product">Product</label>
        <select id="r-product" name="product_id">
          <option value="">General review (not linked)</option>
          <?php foreach ($productList as $p): ?><option value="<?= e($p['id']) ?>" <?= (string)($form['product_id'] ?? '') === (string)$p['id'] ? 'selected' : '' ?>><?= e(cat_product_title($p)) ?><?= !empty($p['weight_volume']) ? ' · ' . e($p['weight_volume']) : '' ?></option><?php endforeach; ?>
        </select>
        <?php if (isset($errors['product_id'])): ?><div class="error"><?= e($errors['product_id']) ?></div><?php endif; ?>
        <div class="hint">Linked reviews update the product’s rating.</div>
      </div>
      <div class="field <?= isset($errors['rating']) ? 'has-error' : '' ?>">
        <label>Rating <span class="req">*</span></label>
        <div class="star-pick">
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <input type="radio" id="rs<?= $i ?>" name="rating" value="<?= $i ?>" <?= (int)round((float)($form['rating'] ?? 0)) === $i ? 'checked' : '' ?>><label for="rs<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><i class="fa-solid fa-star"></i></label>
          <?php endfor; ?>
        </div>
        <?php if (isset($errors['rating'])): ?><div class="error"><?= e($errors['rating']) ?></div><?php endif; ?>
      </div>
      <div class="field span-2 <?= isset($errors['comment']) ? 'has-error' : '' ?>">
        <label for="r-comment">Review <span class="req">*</span></label>
        <textarea id="r-comment" name="comment" rows="4" maxlength="3000"><?= e($form['comment'] ?? '') ?></textarea>
        <?php if (isset($errors['comment'])): ?><div class="error"><?= e($errors['comment']) ?></div><?php endif; ?>
      </div>
      <div class="field <?= isset($errors['review_date']) ? 'has-error' : '' ?>">
        <label for="r-date">Review date</label>
        <input id="r-date" type="date" name="review_date" value="<?= e($form['review_date'] ?? '') ?>">
        <?php if (isset($errors['review_date'])): ?><div class="error"><?= e($errors['review_date']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="r-status">Status</label>
        <select id="r-status" name="status">
          <?php foreach (REVIEW_STATUSES as $k => $label): ?><option value="<?= $k ?>" <?= cat_review_status($form) === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="r-avatar">Photo URL</label>
        <input id="r-avatar" name="avatar" value="<?= e($form['avatar'] ?? '') ?>" placeholder="Optional https://…">
      </div>
      <div class="field">
        <label for="r-likes">Helpful votes</label>
        <input id="r-likes" type="number" min="0" step="1" name="likes_count" value="<?= e($form['likes_count'] ?? 0) ?>">
      </div>
      <div class="field span-2">
        <label class="check"><input type="checkbox" name="verified_purchase" value="1" <?= !empty($form['verified_purchase']) ? 'checked' : '' ?>> <span>Verified purchase<small>Shows a “Verified” mark next to the review.</small></span></label>
      </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-check"></i> <?= $editing ? 'Save review' : 'Add review' ?></button></div>
  </form>
</section>
<?php endif; ?>

<form id="row-form" method="post" hidden><?= csrf_field() ?><input type="hidden" name="_return" value="<?= e($returnQs) ?>"></form>
<form id="filter-form" method="get" hidden><input type="hidden" name="status" value="<?= e($tab) ?>"></form>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="_return" value="<?= e($returnQs) ?>">
  <nav class="tabs">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'hidden' => 'Hidden', 'all' => 'All'] as $k => $label): ?>
      <a class="tab <?= $tab === $k ? 'is-active' : '' ?>" href="?<?= e(http_build_query(array_merge($base, ['status' => $k]))) ?>"><?= $label ?> <span class="count"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="toolbar">
    <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" form="filter-form" value="<?= e($q) ?>" placeholder="Search name, text or location…"></div>
    <select name="product" form="filter-form" data-autosubmit aria-label="Product" style="max-width:280px">
      <option value="">All products</option>
      <option value="none" <?= $fProduct === 'none' ? 'selected' : '' ?>>General (not linked)</option>
      <?php foreach ($productList as $p): ?><option value="<?= e($p['id']) ?>" <?= $fProduct === (string)$p['id'] ? 'selected' : '' ?>><?= e(cat_product_title($p)) ?></option><?php endforeach; ?>
    </select>
    <select name="rating" form="filter-form" data-autosubmit aria-label="Rating">
      <option value="">Any rating</option>
      <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= $fRating === (string)$i ? 'selected' : '' ?>><?= $i ?> star<?= $i > 1 ? 's' : '' ?></option><?php endfor; ?>
    </select>
    <button class="btn btn-secondary" type="submit" form="filter-form"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($base): ?><a class="btn btn-ghost" href="?status=<?= e($tab) ?>">Clear</a><?php endif; ?>
  </div>
  <div class="toolbar" data-bulk-show hidden>
    <strong><span data-bulk-count>0</span> selected</strong>
    <button class="btn btn-success btn-sm" type="submit" name="bulk" value="approved"><i class="fa-solid fa-check"></i> Approve</button>
    <button class="btn btn-secondary btn-sm" type="submit" name="bulk" value="hidden"><i class="fa-solid fa-eye-slash"></i> Hide</button>
    <button class="btn btn-secondary btn-sm" type="submit" name="bulk" value="pending">Move to pending</button>
    <button class="btn btn-danger btn-sm" type="submit" name="bulk" value="delete" data-confirm="Delete the selected reviews? This cannot be undone." data-confirm-tone="danger" data-confirm-ok="Delete"><i class="fa-solid fa-trash"></i> Delete</button>
  </div>

  <?php if (!$rows): ?>
    <div class="empty-state"><i class="fa-regular fa-star"></i>
      <h2><?= $tab === 'pending' && !$base ? 'No reviews waiting for approval' : 'No reviews found' ?></h2>
      <p><?= $tab === 'pending' && !$base ? 'New customer reviews appear here before they go live.' : 'Try another tab or clear the filters.' ?></p>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th class="check"><input type="checkbox" data-check-all aria-label="Select all"></th>
        <th>Customer</th><th>Review</th><th>Product</th><th>Status</th><th class="actions"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $st = cat_review_status($r); $pid = (string)($r['product_id'] ?? ''); $prod = $products[$pid] ?? null;
          $name = (string)($r['customer_name'] ?? 'Customer');
      ?>
        <tr>
          <td class="check"><input type="checkbox" name="ids[]" value="<?= e($r['id']) ?>" aria-label="Select"></td>
          <td>
            <div class="cell-main">
              <?php if (!empty($r['avatar'])): ?><img class="rv-avatar" src="<?= e(cat_img_src($r['avatar'])) ?>" alt="" loading="lazy"><?php else: ?><span class="rv-avatar"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?php endif; ?>
              <div>
                <strong><?= e($name) ?></strong>
                <small><?= e($r['location'] ?? '') ?><?= !empty($r['verified_purchase']) ? ' · <span class="text-green"><i class="fa-solid fa-circle-check"></i> Verified</span>' : '' ?></small>
              </div>
            </div>
          </td>
          <td>
            <div><?= $stars($r['rating'] ?? 0) ?> <small class="muted" style="display:inline"><?= e(!empty($r['review_date']) ? date('j M Y', strtotime($r['review_date'])) : '') ?></small></div>
            <div class="rv-comment"><?= e(mb_strimwidth((string)($r['comment'] ?? ''), 0, 220, '…')) ?></div>
          </td>
          <td>
            <?php if ($prod): ?>
              <a href="?<?= e(http_build_query(array_merge($query, ['product' => $pid]))) ?>"><?= e(cat_product_title($prod)) ?></a>
            <?php elseif ($pid !== ''): ?>
              <span class="muted"><?= e($r['product_title'] ?? ('#' . $pid)) ?> (removed)</span>
            <?php else: ?><span class="muted">General</span><?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $tone[$st] ?>"><?= REVIEW_STATUSES[$st] ?></span></td>
          <td class="actions nowrap">
            <?php if ($st !== 'approved'): ?><button class="btn btn-success btn-sm" type="submit" form="row-form" name="row" value="approved|<?= e($r['id']) ?>" title="Approve"><i class="fa-solid fa-check"></i> Approve</button><?php endif; ?>
            <?php if ($st !== 'hidden'): ?><button class="icon-btn" type="submit" form="row-form" name="row" value="hidden|<?= e($r['id']) ?>" title="Hide"><i class="fa-solid fa-eye-slash"></i></button><?php endif; ?>
            <a class="icon-btn" href="?<?= e(http_build_query(array_merge($query, ['edit' => $r['id']]))) ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
            <button class="icon-btn danger" type="submit" form="row-form" name="row" value="delete|<?= e($r['id']) ?>" title="Delete" data-confirm="Delete this review by <?= e($name) ?>?" data-confirm-tone="danger" data-confirm-ok="Delete"><i class="fa-solid fa-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= cat_pagination_html($page, $pages, $query, $total, $perPage) ?>
  <?php endif; ?>
</form>
<?php admin_footer();
