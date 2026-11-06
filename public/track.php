<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$products = all_products();
$query    = trim($_GET['id'] ?? $_GET['query'] ?? '');
$order    = null;

if ($query !== '') {
    $clean = preg_replace('/[^\d+A-Za-z\-]/', '', $query);
    if (preg_match('/^(?:\+?88)?(01[3-9]\d{8})$/', $clean, $m)) $clean = $m[1];
    $order = $db->findOrder($clean) ?? $db->findOrder($query);
}

$steps = [
    'pending'          => ['Order placed', 'fa-receipt'],
    'processing'       => ['Processing', 'fa-box'],
    'out for delivery' => ['Out for delivery', 'fa-truck-fast'],
    'delivered'        => ['Delivered', 'fa-house-circle-check'],
];
$statusKey = strtolower(str_replace('_', ' ', $order['status'] ?? 'pending'));
$reached   = array_search($statusKey, array_keys($steps), true);
$reached   = $reached === false ? 0 : $reached;
$cancelled = $statusKey === 'cancelled';

render_header('Track Order', $products);
?>
<main class="section">
  <div class="container narrow">
    <div class="track-hero">
      <span class="trust-icon"><i class="fa-solid fa-location-crosshairs"></i></span>
      <h1>Track your order</h1>
      <p>Enter your order ID or the mobile number you ordered with.</p>
      <form method="get" class="track-form">
        <input type="text" name="id" value="<?= e($query) ?>" placeholder="e.g. NH-AB12CD or 01XXXXXXXXX" required aria-label="Order ID or mobile number">
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Track</button>
      </form>
    </div>

    <?php if ($query !== '' && !$order): ?>
      <div class="empty empty--inline">
        <i class="fa-solid fa-circle-question"></i>
        <h3>No order found</h3>
        <p>Please check the order ID or mobile number and try again.</p>
      </div>
    <?php elseif ($order): ?>
      <div class="panel">
        <div class="track-head">
          <div><small>Order ID</small><strong><?= e($order['order_id']) ?></strong></div>
          <div><small>Placed on</small><strong><?= e(date('d M Y, h:i A', strtotime($order['created_at'] ?? 'now'))) ?></strong></div>
          <span class="status-pill <?= $cancelled ? 'is-cancelled' : '' ?>"><?= e($order['status'] ?? 'Pending') ?></span>
        </div>

        <?php if (!$cancelled): ?>
        <ol class="timeline" style="--progress: <?= round($reached / (count($steps) - 1), 3) ?>">
          <?php $i = 0; foreach ($steps as [$label, $icon]): ?>
            <li class="<?= $i <= $reached ? 'is-done' : '' ?>"><span><i class="fa-solid <?= $icon ?>"></i></span><b><?= e($label) ?></b></li>
          <?php $i++; endforeach; ?>
        </ol>
        <?php endif; ?>

        <div class="track-cols">
          <div>
            <h3>Items</h3>
            <div class="summary-items">
              <?php foreach ($order['items'] ?? [] as $it): ?>
                <div class="sitem">
                  <img src="<?= e($it['image'] ?: placeholder_image()) ?>" alt="">
                  <div><strong><?= e($it['title']) ?></strong><small><?= e(trim(($it['unit'] ?? '') . ' × ' . $it['quantity'])) ?></small></div>
                  <span><?= money($it['line_total'] ?? $it['price'] * $it['quantity']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <h3>Delivery</h3>
            <dl class="summary-list">
              <div><dt>Name</dt><dd><?= e($order['name']) ?></dd></div>
              <div><dt>Address</dt><dd><?= e($order['address']) ?></dd></div>
              <div><dt>Area</dt><dd><?= e($order['zone']) ?></dd></div>
              <div><dt>Payment</dt><dd><?= e($order['payment_method']) ?></dd></div>
            </dl>
            <dl class="totals">
              <div><dt>Subtotal</dt><dd><?= money($order['subtotal']) ?></dd></div>
              <div><dt>Delivery</dt><dd><?= money($order['delivery_fee']) ?></dd></div>
              <div class="grand"><dt>Total</dt><dd><?= money($order['total']) ?></dd></div>
            </dl>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>
<?php render_footer($products);
