<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pages.php';

$products  = all_products();
$zones     = (array)($SITE['delivery_zones'] ?? []);
$payments  = (array)($SITE['payment_methods'] ?? []);
$firstZone = (string)array_key_first($zones);
$firstPay  = (string)array_key_first($payments);
$hasCoupons = (bool)array_filter(store_all('coupons'), fn($c) => !empty($c['active']));

$payInfo = [];
foreach ($payments as $key => $label) {
    $pi = payment_instruction($SITE, (string)$key);
    if ($pi) $payInfo[$key] = $pi;
}
$payIcons = ['cod' => 'fa-solid fa-hand-holding-dollar', 'bkash' => 'fa-solid fa-mobile-screen-button', 'nagad' => 'fa-solid fa-mobile-screen-button', 'rocket' => 'fa-solid fa-mobile-screen-button', 'card' => 'fa-regular fa-credit-card', 'bank' => 'fa-solid fa-building-columns'];

nh_page_header('Checkout', $products, '', [
    'description' => 'Complete your order at ' . $SITE['brand_name'] . '.',
    'head' => '  <meta name="robots" content="noindex">' . "\n",
    'body_class' => 'page-checkout',
]);
?>
<main class="nh-page co" id="checkoutPage">
  <div class="container">
    <nav class="nh-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span aria-current="page">Checkout</span></nav>

    <div class="co-head">
      <h1>Checkout</h1>
      <ol class="co-progress" aria-hidden="true">
        <li class="is-done"><span><i class="fa-solid fa-bag-shopping"></i></span>Cart</li>
        <li class="is-current"><span>2</span>Details</li>
        <li><span>3</span>Confirmation</li>
      </ol>
    </div>

    <div class="co-loading" id="coLoading"><span class="nh-spinner"></span> Loading your cart…</div>

    <div class="nh-empty" id="checkoutEmpty" hidden>
      <span class="nh-empty-icon"><i class="fa-solid fa-bag-shopping"></i></span>
      <h2>Your cart is empty</h2>
      <p>Add a few products and come back here to place your order.</p>
      <a href="/index.php#shop" class="btn btn-primary">Start shopping</a>
    </div>

    <form id="checkoutForm" class="co-grid" novalidate hidden>
      <div class="co-main">
        <div class="nh-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <section class="co-card">
          <h2 class="co-title"><span class="co-step">1</span> Contact information</h2>
          <div class="nh-field">
            <label class="nh-label" for="fName">Full name <b>*</b></label>
            <input id="fName" name="name" type="text" autocomplete="name" maxlength="80" placeholder="Your full name" required>
          </div>
          <div class="nh-row2">
            <div class="nh-field">
              <label class="nh-label" for="fPhone">Mobile number <b>*</b></label>
              <div class="nh-input-prefix"><span>+88</span><input id="fPhone" name="phone" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="14" placeholder="01XXXXXXXXX" required></div>
              <small class="nh-hint">We will call this number to confirm your order.</small>
            </div>
            <div class="nh-field">
              <label class="nh-label" for="fEmail">Email <em>(optional)</em></label>
              <input id="fEmail" name="email" type="email" autocomplete="email" maxlength="120" placeholder="you@example.com">
            </div>
          </div>
          <button type="button" class="nh-link co-toggle" data-toggle-alt><i class="fa-solid fa-plus"></i> Add an alternative mobile number</button>
          <div class="nh-field" id="altPhoneField" hidden>
            <label class="nh-label" for="fAltPhone">Alternative mobile <em>(optional)</em></label>
            <div class="nh-input-prefix"><span>+88</span><input id="fAltPhone" name="alt_phone" type="tel" inputmode="numeric" maxlength="14" placeholder="01XXXXXXXXX"></div>
          </div>
        </section>

        <section class="co-card">
          <h2 class="co-title"><span class="co-step">2</span> Delivery address</h2>
          <div class="nh-field">
            <label class="nh-label" for="fAddress">Full address <b>*</b></label>
            <textarea id="fAddress" name="address" rows="2" autocomplete="street-address" maxlength="500" placeholder="House / flat, road, area" required></textarea>
          </div>
          <div class="nh-row2">
            <div class="nh-field">
              <label class="nh-label" for="fDistrict">District / City <em>(optional)</em></label>
              <input id="fDistrict" name="district" type="text" autocomplete="address-level2" maxlength="60" placeholder="e.g. Dhaka">
            </div>
            <div class="nh-field">
              <label class="nh-label" for="fArea">Area / Thana <em>(optional)</em></label>
              <input id="fArea" name="area" type="text" autocomplete="address-level3" maxlength="80" placeholder="e.g. Mirpur">
            </div>
          </div>

          <?php if ($zones): ?>
          <span class="nh-label">Delivery area <b>*</b></span>
          <div class="co-options co-options--zones">
            <?php foreach ($zones as $key => $z): ?>
              <label class="co-option">
                <input type="radio" name="zone" value="<?= e($key) ?>" data-fee="<?= (float)($z['fee'] ?? 0) ?>" <?= (string)$key === $firstZone ? 'checked' : '' ?>>
                <span class="co-option-box">
                  <span class="co-radio"></span>
                  <span class="co-option-text"><strong><?= e($z['label'] ?? $key) ?></strong><?php if (!empty($z['eta'])): ?><small><?= e($z['eta']) ?></small><?php endif; ?></span>
                  <b class="co-option-fee"><?= (float)($z['fee'] ?? 0) > 0 ? money($z['fee']) : 'Free' ?></b>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <section class="co-card">
          <h2 class="co-title"><span class="co-step">3</span> Payment method</h2>
          <div class="co-options">
            <?php foreach ($payments as $key => $label): ?>
              <label class="co-option">
                <input type="radio" name="payment_method" value="<?= e($key) ?>" <?= (string)$key === $firstPay ? 'checked' : '' ?>>
                <span class="co-option-box">
                  <span class="co-radio"></span>
                  <i class="<?= e($payIcons[$key] ?? 'fa-solid fa-wallet') ?> co-option-icon"></i>
                  <span class="co-option-text"><strong><?= e(payment_method_label($label, (string)$key)) ?></strong><?php if ($key === 'cod'): ?><small>Pay when you receive the parcel</small><?php endif; ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?php foreach ($payInfo as $key => $pi): ?>
            <div class="co-payinfo" data-payinfo="<?= e($key) ?>" hidden>
              <?php if ($pi['number'] !== ''): ?>
                <div class="co-paynum"><span><?= e(payment_method_label($payments[$key], (string)$key)) ?><?= $pi['type'] !== '' ? ' (' . e($pi['type']) . ')' : '' ?></span><strong><?= e($pi['number']) ?></strong><button type="button" class="nh-link" data-copy="<?= e($pi['number']) ?>"><i class="fa-regular fa-copy"></i> Copy</button></div>
              <?php endif; ?>
              <?php if ($pi['text'] !== ''): ?><p><?= nl2br(e($pi['text'])) ?></p><?php endif; ?>
              <?php if ($key !== 'cod'): ?><div class="nh-field">
                <label class="nh-label" for="fPayRef_<?= e($key) ?>">Transaction ID / sender number <em>(optional)</em></label>
                <input id="fPayRef_<?= e($key) ?>" type="text" maxlength="60" data-payref placeholder="e.g. 8N7A6B5C4D">
              </div><?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="nh-field co-notes">
            <label class="nh-label" for="fNotes">Order note <em>(optional)</em></label>
            <textarea id="fNotes" name="notes" rows="2" maxlength="500" placeholder="Delivery time preference, landmark, anything we should know"></textarea>
          </div>
          <label class="nh-check"><input type="checkbox" id="fRemember" checked> <span>Save my details on this device for faster checkout next time</span></label>
        </section>
      </div>

      <aside class="co-side">
        <section class="co-card co-summary">
          <h2 class="co-title">Order summary <span class="co-count" id="coCount"></span></h2>
          <div class="co-items" id="coItems"></div>

          <?php if ($hasCoupons): ?>
          <div class="co-coupon" id="couponBox">
            <button type="button" class="nh-link" data-coupon-toggle><i class="fa-solid fa-tag"></i> Have a coupon code?</button>
            <div class="co-coupon-form" id="couponForm" hidden>
              <input type="text" id="couponInput" placeholder="Enter coupon code" maxlength="40" autocomplete="off" aria-label="Coupon code">
              <button type="button" class="btn btn-outline" id="couponApply">Apply</button>
            </div>
            <small class="co-coupon-msg" id="couponMsg" hidden></small>
            <div class="co-coupon-applied" id="couponApplied" hidden>
              <span><i class="fa-solid fa-ticket"></i> <b id="couponCode"></b> <small id="couponLabel"></small></span>
              <button type="button" class="nh-link" id="couponRemove">Remove</button>
            </div>
          </div>
          <?php endif; ?>

          <dl class="co-totals">
            <div><dt>Subtotal</dt><dd id="sumSubtotal">—</dd></div>
            <div><dt>Delivery</dt><dd id="sumDelivery">—</dd></div>
            <div class="co-discount" id="sumDiscountRow" hidden><dt>Discount</dt><dd id="sumDiscount">—</dd></div>
            <div class="co-grand"><dt>Total</dt><dd id="sumTotal">—</dd></div>
          </dl>

          <p class="co-freehint" id="coFreeHint" hidden></p>
          <div class="co-error" id="coError" role="alert" hidden></div>
          <button type="submit" class="btn btn-primary btn-lg btn-block co-submit" id="placeOrder"><i class="fa-solid fa-lock"></i> <span>Place Order</span></button>
          <p class="nh-fine">By placing this order you agree to receive a confirmation call from us.</p>

          <ul class="co-trust">
            <?php if (isset($payments['cod'])): ?><li><i class="fa-solid fa-hand-holding-dollar"></i> Cash on delivery</li><?php endif; ?>
            <li><i class="fa-solid fa-shield-halved"></i> Secure checkout</li>
            <li><i class="fa-solid fa-phone-volume"></i> Order confirmed by call</li>
          </ul>
        </section>
      </aside>

      <div class="co-mobilebar" aria-hidden="true">
        <div><small>Total</small><strong id="mobileTotal">—</strong></div>
        <button type="submit" class="btn btn-primary btn-lg" tabindex="-1">Place Order</button>
      </div>
    </form>
  </div>
</main>
<script>window.CHECKOUT = true;</script>
<?php nh_page_footer($products, [
    'page' => 'checkout',
    'zones' => array_map(fn($z) => ['label' => $z['label'] ?? '', 'fee' => (float)($z['fee'] ?? 0)], $zones),
    'payments' => array_map(fn($v) => payment_method_label($v), $payments),
]);
