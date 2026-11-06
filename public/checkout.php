<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$products = all_products();
$zones    = $SITE['delivery_zones'];
$payments = $SITE['payment_methods'];
$firstZone = array_key_first($zones);
$firstPay  = array_key_first($payments);

render_header('Checkout', $products);
?>
<main class="section checkout-page">
  <div class="container">
    <nav class="crumbs"><a href="index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span>Checkout</span></nav>

    <div id="checkoutEmpty" class="empty" hidden>
      <i class="fa-solid fa-bag-shopping"></i>
      <h3>Your cart is empty</h3>
      <p>Add a few products and come back to place your order.</p>
      <a href="index.php#shop" class="btn btn-primary">Start shopping</a>
    </div>

    <div id="checkoutSuccess" class="success-card" hidden>
      <span class="success-icon"><i class="fa-solid fa-check"></i></span>
      <h2>Thank you! Your order is placed.</h2>
      <p>We will call you shortly to confirm. Keep your order ID to track it.</p>
      <div class="order-id-box"><span>Order ID</span><strong id="successOrderId">—</strong><button type="button" class="icon-btn" id="copyOrderId" aria-label="Copy order ID"><i class="fa-regular fa-copy"></i></button></div>
      <dl class="summary-list" id="successSummary"></dl>
      <div class="success-actions">
        <a href="#" class="btn btn-primary" id="successTrack"><i class="fa-solid fa-location-crosshairs"></i> Track order</a>
        <a href="index.php" class="btn btn-outline">Continue shopping</a>
      </div>
    </div>

    <form id="checkoutForm" class="checkout-grid" novalidate hidden>
      <div class="panel">
        <h2 class="panel-title"><span class="step">1</span> Delivery details</h2>
        <div class="field">
          <label for="fName">Full name</label>
          <input id="fName" name="name" type="text" autocomplete="name" placeholder="Your full name" required>
        </div>
        <div class="field">
          <label for="fPhone">Mobile number</label>
          <input id="fPhone" name="phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="01XXXXXXXXX" required>
        </div>
        <div class="field">
          <label for="fAddress">Full address</label>
          <textarea id="fAddress" name="address" rows="3" autocomplete="street-address" placeholder="House, road, area, district" required></textarea>
        </div>

        <h2 class="panel-title"><span class="step">2</span> Delivery area</h2>
        <div class="choice-grid">
          <?php foreach ($zones as $key => $z): ?>
            <label class="choice">
              <input type="radio" name="zone" value="<?= e($key) ?>" data-fee="<?= (int)$z['fee'] ?>" <?= $key === $firstZone ? 'checked' : '' ?>>
              <span><strong><?= e($z['label']) ?></strong><small><?= money($z['fee']) ?></small></span>
            </label>
          <?php endforeach; ?>
        </div>

        <h2 class="panel-title"><span class="step">3</span> Payment method</h2>
        <div class="choice-grid">
          <?php foreach ($payments as $key => $label): ?>
            <label class="choice">
              <input type="radio" name="payment_method" value="<?= e($key) ?>" <?= $key === $firstPay ? 'checked' : '' ?>>
              <span><strong><?= e($label) ?></strong></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="field">
          <label for="fNotes">Order note <em>(optional)</em></label>
          <textarea id="fNotes" name="notes" rows="2" placeholder="Anything we should know?"></textarea>
        </div>
      </div>

      <aside class="panel order-summary">
        <h2 class="panel-title">Order summary</h2>
        <div id="summaryItems" class="summary-items"></div>
        <dl class="totals">
          <div><dt>Subtotal</dt><dd id="sumSubtotal">—</dd></div>
          <div><dt>Delivery</dt><dd id="sumDelivery">—</dd></div>
          <div class="grand"><dt>Total</dt><dd id="sumTotal">—</dd></div>
        </dl>
        <button type="submit" class="btn btn-primary btn-block btn-lg" id="placeOrder"><i class="fa-solid fa-lock"></i> Place Order</button>
        <p class="fine">By placing the order you agree to receive a confirmation call.</p>
      </aside>
    </form>
  </div>
</main>
<script>window.CHECKOUT = true;</script>
<?php render_footer($products);
