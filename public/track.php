<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pages.php';

$products = all_products();
$query    = mb_substr(trim((string)($_GET['id'] ?? $_GET['query'] ?? '')), 0, 40);
$order    = null;
if ($query !== '') {
    [$raw, $byId] = lookup_order_public($query);
    if ($raw) $order = public_order_view($raw, $byId);
}

$statuses  = order_statuses();
$mainSteps = array_values(array_filter(array_keys($statuses), fn($k) => !in_array($k, ['cancelled', 'returned'], true)));
$icons = [
    'pending' => 'fa-receipt', 'confirmed' => 'fa-clipboard-check', 'processing' => 'fa-box',
    'shipped' => 'fa-truck-fast', 'delivered' => 'fa-house-circle-check',
    'cancelled' => 'fa-circle-xmark', 'returned' => 'fa-rotate-left',
];

$steps = [];
$special = null;
if ($order) {
    $key = $order['status'];
    $when = [];
    foreach ($order['history'] as $h) $when[$h['status']] = $h['at']; // latest time per status
    if (!isset($when['pending'])) $when['pending'] = $order['created_at'];

    $isSpecial = in_array($key, ['cancelled', 'returned'], true);
    // Furthest normal step reached (for cancelled/returned orders, from history).
    $reached = 0;
    foreach ($mainSteps as $i => $s) {
        if ($s === $key || isset($when[$s])) $reached = max($reached, $i);
    }
    if (!$isSpecial) $reached = max($reached, (int)array_search($key, $mainSteps, true));
    if ($isSpecial) $special = ['key' => $key, 'label' => $statuses[$key]['label'], 'at' => $when[$key] ?? $order['updated_at']];

    foreach ($mainSteps as $i => $s) {
        $state = $i < $reached ? 'done' : ($i === $reached ? ($isSpecial ? 'done' : ($s === 'delivered' ? 'done' : 'current')) : 'todo');
        $steps[] = ['key' => $s, 'label' => $statuses[$s]['label'], 'icon' => $icons[$s] ?? 'fa-circle', 'state' => $state, 'at' => $when[$s] ?? ''];
    }
    if ($isSpecial) {
        $steps = array_slice($steps, 0, $reached + 1);
        $steps[] = ['key' => $key, 'label' => $statuses[$key]['label'], 'icon' => $icons[$key], 'state' => 'special', 'at' => $special['at']];
    }
}
$progress = 0;
if ($steps) {
    $last = 0;
    foreach ($steps as $i => $s) if ($s['state'] !== 'todo') $last = $i;
    $progress = count($steps) > 1 ? round($last / (count($steps) - 1), 3) : 1;
}

nh_page_header('Track Order', $products, '', [
    'description' => 'Track your ' . $SITE['brand_name'] . ' order with your order ID or mobile number.',
    'body_class' => 'page-track',
]);
?>
<main class="nh-page trk">
  <div class="container nh-narrow">
    <section class="trk-hero">
      <span class="trk-hero-icon"><i class="fa-solid fa-location-crosshairs"></i></span>
      <h1>Track your order</h1>
      <p>Enter your order ID or the mobile number you used when ordering.</p>
      <form method="get" action="/track.php" class="trk-form" role="search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" name="id" value="<?= e($query) ?>" placeholder="Order ID (NH-XXXXXX) or 01XXXXXXXXX" required maxlength="40" aria-label="Order ID or mobile number" autocomplete="off">
        <button type="submit" class="btn btn-primary">Track</button>
      </form>
      <div class="trk-recent" id="trackRecent" hidden></div>
    </section>

    <?php if ($query !== '' && !$order): ?>
      <div class="nh-empty nh-empty--inline">
        <span class="nh-empty-icon"><i class="fa-solid fa-circle-question"></i></span>
        <h2>No order found</h2>
        <p>Please check the order ID or mobile number and try again. Orders can take a moment to appear right after placing them.</p>
      </div>
    <?php elseif ($order): ?>
      <section class="trk-card">
        <div class="trk-head">
          <div><small>Order ID</small><strong><?= e($order['order_id']) ?></strong></div>
          <div><small>Placed on</small><strong><?= e(nice_date($order['created_at'], 'd M Y, h:i A')) ?></strong></div>
          <div><small>Total</small><strong><?= money($order['total']) ?></strong></div>
          <span class="trk-status trk-status--<?= e($order['status']) ?>"><?= e($order['status_label']) ?></span>
        </div>

        <?php if ($special): ?>
          <div class="trk-alert trk-alert--<?= e($special['key']) ?>">
            <i class="fa-solid <?= $icons[$special['key']] ?>"></i>
            <div><strong>This order was <?= e(strtolower($special['label'])) ?></strong><?php if ($special['at']): ?><span><?= e(nice_date($special['at'], 'd M Y, h:i A')) ?></span><?php endif; ?></div>
          </div>
        <?php endif; ?>

        <ol class="trk-timeline<?= $special ? ' is-special' : '' ?>" style="--progress: <?= $progress ?>">
          <?php foreach ($steps as $s): ?>
            <li class="is-<?= e($s['state']) ?>">
              <span class="trk-dot"><i class="fa-solid <?= e($s['icon']) ?>"></i></span>
              <b><?= e($s['label']) ?></b>
              <?php if ($s['at'] !== '' && $s['state'] !== 'todo'): ?><small><?= e(nice_date($s['at'], 'd M, h:i A')) ?></small><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>

        <?php if ($order['courier'] !== '' || $order['tracking_number'] !== ''): ?>
          <div class="trk-courier">
            <i class="fa-solid fa-truck"></i>
            <div>
              <?php if ($order['courier'] !== ''): ?><span>Courier: <b><?= e($order['courier']) ?></b></span><?php endif; ?>
              <?php if ($order['tracking_number'] !== ''): ?><span>Tracking number: <b><?= e($order['tracking_number']) ?></b> <button type="button" class="nh-link" data-copy="<?= e($order['tracking_number']) ?>"><i class="fa-regular fa-copy"></i></button></span><?php endif; ?>
            </div>
            <?php if ($order['tracking_url'] !== '' && preg_match('~^https?://~i', $order['tracking_url'])): ?>
              <a href="<?= e($order['tracking_url']) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">Track parcel <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="trk-cols">
          <div>
            <h2 class="os-h">Items</h2>
            <ul class="nh-lines">
              <?php foreach ($order['items'] as $it): ?>
                <li>
                  <img src="<?= e($it['image'] ?: placeholder_image()) ?>" alt="" loading="lazy">
                  <div><strong><?= e($it['title']) ?></strong><small><?= e(trim(($it['unit'] !== '' ? $it['unit'] . ' · ' : '') . 'Qty ' . $it['quantity'])) ?></small></div>
                  <b><?= money($it['line_total']) ?></b>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div>
            <h2 class="os-h">Delivery &amp; payment</h2>
            <dl class="nh-dl">
              <div><dt>Name</dt><dd><?= e($order['name']) ?></dd></div>
              <div><dt>Mobile</dt><dd><?= e($order['phone_masked']) ?></dd></div>
              <?php if (!empty($order['address'])): ?><div><dt>Address</dt><dd><?= e($order['address']) ?></dd></div><?php endif; ?>
              <?php $pl = implode(', ', array_filter([$order['area'], $order['district']])); if ($pl !== ''): ?><div><dt>Area</dt><dd><?= e($pl) ?></dd></div><?php endif; ?>
              <?php if ($order['zone'] !== ''): ?><div><dt>Delivery</dt><dd><?= e($order['zone']) ?></dd></div><?php endif; ?>
              <?php if ($order['payment_method'] !== ''): ?><div><dt>Payment</dt><dd><?= e($order['payment_method']) ?><?= $order['payment_status'] === 'paid' ? ' · <span class="trk-paid">Paid</span>' : '' ?></dd></div><?php endif; ?>
            </dl>
            <dl class="co-totals">
              <div><dt>Subtotal</dt><dd><?= money($order['subtotal']) ?></dd></div>
              <div><dt>Delivery</dt><dd><?= $order['delivery_fee'] > 0 ? money($order['delivery_fee']) : 'Free' ?></dd></div>
              <?php if ($order['discount'] > 0): ?><div class="co-discount"><dt>Discount<?= $order['coupon_code'] !== '' ? ' (' . e($order['coupon_code']) . ')' : '' ?></dt><dd>−<?= money($order['discount']) ?></dd></div><?php endif; ?>
              <div class="co-grand"><dt>Total</dt><dd><?= money($order['total']) ?></dd></div>
            </dl>
          </div>
        </div>

        <?php if (count($order['history']) > 1): ?>
          <details class="trk-history">
            <summary>Status history</summary>
            <ul>
              <?php foreach (array_reverse($order['history']) as $h): ?>
                <li><b><?= e($h['label']) ?></b><span><?= e(nice_date($h['at'], 'd M Y, h:i A')) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </div>
</main>
<?php nh_page_footer($products, ['page' => 'track', 'order_id' => $order['order_id'] ?? '']);
