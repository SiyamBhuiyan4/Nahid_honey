<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pages.php';

$products = all_products();
$id = strtoupper(trim((string)($_GET['id'] ?? '')));
$raw = $id !== '' && preg_match('/^[A-Z0-9\-]{3,40}$/', $id) ? store_get('orders', $id) : null;
$order = $raw ? public_order_view($raw, true) : null;
$payments = (array)($SITE['payment_methods'] ?? []);
$payKey = (string)($raw['payment_method_key'] ?? '');
$payInfo = ($raw && $payKey !== '' && $payKey !== 'cod') ? payment_instruction($SITE, $payKey) : null;

nh_page_header($order ? 'Order placed' : 'Order not found', $products, '', [
    'head' => '  <meta name="robots" content="noindex">' . "\n",
    'body_class' => 'page-success',
]);
?>
<main class="nh-page os">
  <div class="container nh-narrow">
    <?php if (!$order): ?>
      <div class="nh-empty">
        <span class="nh-empty-icon"><i class="fa-solid fa-receipt"></i></span>
        <h1>We could not find that order</h1>
        <p>Check the order ID, or look it up with your mobile number.</p>
        <a href="/track.php" class="btn btn-primary">Track an order</a>
      </div>
    <?php else: ?>
      <section class="os-card">
        <div class="os-hero">
          <span class="os-check"><i class="fa-solid fa-check"></i></span>
          <h1>Thank you, <?= e(explode(' ', $order['name'])[0] ?: 'there') ?>!</h1>
          <p>Your order has been placed. We will call <b><?= e($order['phone_masked']) ?></b> shortly to confirm it.</p>
        </div>

        <div class="os-id">
          <span>Order ID</span>
          <strong id="osOrderId"><?= e($order['order_id']) ?></strong>
          <button type="button" class="btn btn-ghost btn-sm" data-copy="<?= e($order['order_id']) ?>"><i class="fa-regular fa-copy"></i> Copy</button>
        </div>
        <p class="nh-fine os-keep">Keep this ID to track your order any time.</p>

        <?php if ($payInfo): ?>
          <div class="co-payinfo os-payinfo">
            <strong>Complete your <?= e($order['payment_method']) ?> payment</strong>
            <?php if ($payInfo['number'] !== ''): ?>
              <div class="co-paynum"><span>Send <?= money($order['total']) ?> to</span><strong><?= e($payInfo['number']) ?></strong><button type="button" class="nh-link" data-copy="<?= e($payInfo['number']) ?>"><i class="fa-regular fa-copy"></i> Copy</button></div>
            <?php endif; ?>
            <?php if ($payInfo['text'] !== ''): ?><p><?= nl2br(e($payInfo['text'])) ?></p><?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="os-grid">
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
            <h2 class="os-h">Summary</h2>
            <dl class="co-totals">
              <div><dt>Subtotal</dt><dd><?= money($order['subtotal']) ?></dd></div>
              <div><dt>Delivery<?= $order['zone'] !== '' ? ' (' . e($order['zone']) . ')' : '' ?></dt><dd><?= $order['delivery_fee'] > 0 ? money($order['delivery_fee']) : 'Free' ?></dd></div>
              <?php if ($order['discount'] > 0): ?><div class="co-discount"><dt>Discount<?= $order['coupon_code'] !== '' ? ' (' . e($order['coupon_code']) . ')' : '' ?></dt><dd>−<?= money($order['discount']) ?></dd></div><?php endif; ?>
              <div class="co-grand"><dt>Total</dt><dd><?= money($order['total']) ?></dd></div>
            </dl>
            <dl class="nh-dl">
              <div><dt>Payment</dt><dd><?= e($order['payment_method']) ?></dd></div>
              <div><dt>Deliver to</dt><dd><?= e($order['name']) ?><br><?= e($order['address'] ?? '') ?><?php $pl = implode(', ', array_filter([$order['area'], $order['district']])); if ($pl !== ''): ?><br><?= e($pl) ?><?php endif; ?></dd></div>
            </dl>
          </div>
        </div>

        <div class="os-actions">
          <a href="/track.php?id=<?= e(rawurlencode($order['order_id'])) ?>" class="btn btn-primary btn-lg"><i class="fa-solid fa-location-crosshairs"></i> Track order</a>
          <a href="/index.php#shop" class="btn btn-outline btn-lg">Continue shopping</a>
        </div>
      </section>
    <?php endif; ?>
  </div>
</main>
<?php nh_page_footer($products, ['page' => 'success', 'order_id' => $order['order_id'] ?? '']);
