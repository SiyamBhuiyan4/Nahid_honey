<?php
/**
 * Page chrome: header, footer and the product card.
 */

function brand_mark(): string {
    return '<span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M16 4c-5.5 7.4-9 12.6-9 17.3a9 9 0 0 0 18 0C25 16.6 21.5 11.4 16 4z" fill="currentColor"/><path d="M12.5 21.5a3.5 3.5 0 0 0 3.5 3.5" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none" opacity=".85"/></svg></span>';
}

function render_header(string $title, array $products, string $active = ''): void {
    global $SITE;
    $cats = categories_from($products);
    $pageTitle = $title ? $title . ' | ' . $SITE['brand_name'] : $SITE['brand_name'] . ' — ' . $SITE['tagline'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e($SITE['tagline']) ?>">
  <meta name="theme-color" content="#0F2A28">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22><rect width=%2232%22 height=%2232%22 rx=%228%22 fill=%22%23F28C28%22/><path d=%22M16 6c-4.6 6.2-7.5 10.5-7.5 14.4a7.5 7.5 0 0 0 15 0C23.5 16.5 20.6 12.2 16 6z%22 fill=%22white%22/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="assets/css/site.css?v=3">
</head>
<body>
<?php if (!empty($SITE['announcement']) || !empty($SITE['phone'])): ?>
  <div class="topbar">
    <div class="container topbar-inner">
      <span class="topbar-note"><?php if (!empty($SITE['announcement'])): ?><i class="fa-solid fa-truck-fast"></i> <?= e($SITE['announcement']) ?><?php endif; ?></span>
      <span class="topbar-links">
        <?php if (!empty($SITE['phone'])): ?><a href="tel:<?= e($SITE['phone']) ?>"><i class="fa-solid fa-phone"></i> <?= e($SITE['phone']) ?></a><?php endif; ?>
        <a href="track.php"><i class="fa-solid fa-location-crosshairs"></i> Track order</a>
      </span>
    </div>
  </div>
<?php endif; ?>

  <header class="site-header">
    <div class="container header-inner">
      <a href="index.php" class="brand" aria-label="<?= e($SITE['brand_name']) ?> home">
        <?= brand_mark() ?>
        <span class="brand-text"><strong><?= e($SITE['brand_name']) ?></strong><small><?= e($SITE['tagline']) ?></small></span>
      </a>

      <form class="search" action="index.php" method="get" role="search" autocomplete="off">
        <input type="search" name="q" id="searchInput" placeholder="Search products..." value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search products">
        <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
        <div class="search-results" id="searchResults" hidden></div>
      </form>

      <nav class="header-actions" aria-label="Account">
        <a href="track.php" class="hact"><i class="fa-solid fa-truck-ramp-box"></i><span>Track Order</span></a>
        <button type="button" class="hact" data-open-cart><i class="fa-solid fa-bag-shopping"></i><span>Cart</span><b class="count-badge" data-cart-count>0</b></button>
      </nav>
    </div>
  </header>

  <nav class="catnav" aria-label="Categories">
    <div class="container catnav-inner">
      <a href="index.php#shop" class="<?= $active === 'all' ? 'is-active' : '' ?>">All Products</a>
      <?php foreach ($cats as $c): ?>
        <a href="index.php?category=<?= e($c['slug']) ?>" class="<?= $active === $c['slug'] ? 'is-active' : '' ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  </nav>
<?php
}

function render_footer(array $products): void {
    global $SITE;
    $cats = categories_from($products);
    $socials = array_filter([
        'fa-facebook-f' => $SITE['facebook_url'] ?? '',
        'fa-instagram'  => $SITE['instagram_url'] ?? '',
        'fa-youtube'    => $SITE['youtube_url'] ?? '',
        'fa-whatsapp'   => !empty($SITE['whatsapp']) ? 'https://wa.me/' . $SITE['whatsapp'] : '',
    ]);
    ?>
  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-brand">
        <a href="index.php" class="brand brand--light"><?= brand_mark() ?><span class="brand-text"><strong><?= e($SITE['brand_name']) ?></strong><small><?= e($SITE['tagline']) ?></small></span></a>
        <?php if ($socials): ?>
          <div class="socials">
            <?php foreach ($socials as $icon => $url): ?>
              <a href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="<?= e($icon) ?>"><i class="fa-brands <?= e($icon) ?>"></i></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($cats): ?>
      <div>
        <h4>Shop</h4>
        <ul>
          <?php foreach (array_slice($cats, 0, 6) as $c): ?>
            <li><a href="index.php?category=<?= e($c['slug']) ?>"><?= e($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div>
        <h4>Help</h4>
        <ul>
          <li><a href="track.php">Track your order</a></li>
          <li><a href="checkout.php">Checkout</a></li>
          <li><a href="index.php#shop">All products</a></li>
        </ul>
      </div>

      <?php if (!empty($SITE['phone']) || !empty($SITE['email']) || !empty($SITE['address'])): ?>
      <div>
        <h4>Contact</h4>
        <ul class="contact-list">
          <?php if (!empty($SITE['phone'])): ?><li><i class="fa-solid fa-phone"></i><a href="tel:<?= e($SITE['phone']) ?>"><?= e($SITE['phone']) ?></a></li><?php endif; ?>
          <?php if (!empty($SITE['email'])): ?><li><i class="fa-solid fa-envelope"></i><a href="mailto:<?= e($SITE['email']) ?>"><?= e($SITE['email']) ?></a></li><?php endif; ?>
          <?php if (!empty($SITE['address'])): ?><li><i class="fa-solid fa-location-dot"></i><span><?= e($SITE['address']) ?></span></li><?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
    <div class="container footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($SITE['brand_name']) ?>. All rights reserved.</span>
      <span class="pay-pills"><?php foreach ($SITE['payment_methods'] as $label): ?><span><?= e($label) ?></span><?php endforeach; ?></span>
    </div>
  </footer>

  <!-- Floating mini cart -->
  <button type="button" class="float-cart" data-open-cart aria-label="Open cart">
    <span class="float-cart-top"><i class="fa-solid fa-bag-shopping"></i><span><b data-cart-count>0</b> items</span></span>
    <span class="float-cart-total" data-cart-total><?= e($SITE['currency_symbol']) ?>0</span>
  </button>

  <?php if (!empty($SITE['whatsapp'])): ?>
    <a class="float-wa" href="https://wa.me/<?= e($SITE['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
  <?php endif; ?>

  <!-- Cart drawer -->
  <div class="drawer-overlay" data-close-cart hidden></div>
  <aside class="drawer" id="cartDrawer" aria-label="Shopping cart" aria-hidden="true">
    <div class="drawer-head">
      <h3>Your Cart <span>(<b data-cart-count>0</b>)</span></h3>
      <button type="button" class="icon-btn" data-close-cart aria-label="Close cart"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="cartItems"></div>
    <div class="drawer-foot">
      <div class="drawer-subtotal"><span>Subtotal</span><strong data-cart-total><?= e($SITE['currency_symbol']) ?>0</strong></div>
      <p class="drawer-note">Delivery charge is added at checkout.</p>
      <a href="checkout.php" class="btn btn-primary btn-block" id="drawerCheckout">Proceed to Checkout <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </aside>

  <div class="toast-stack" id="toasts" aria-live="polite"></div>

  <script>
    window.SITE = <?= json_encode(['currency' => $SITE['currency_symbol']], JSON_UNESCAPED_UNICODE) ?>;
    window.SITE_PRODUCTS = <?= json_encode(client_products($products), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  </script>
  <script src="assets/js/site.js?v=3"></script>
</body>
</html>
<?php
}

function render_product_card(array $p): void {
    ?>
  <article class="pcard<?= $p['in_stock'] ? '' : ' is-out' ?>">
    <a href="product.php?id=<?= e($p['id']) ?>" class="pcard-media">
      <?php if ($p['badge'] !== ''): ?><span class="ribbon"><i class="fa-solid fa-fire"></i> <?= e($p['badge']) ?></span><?php endif; ?>
      <?php if (!$p['in_stock']): ?><span class="ribbon ribbon--muted">Out of stock</span><?php endif; ?>
      <img src="<?= e($p['image']) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
    </a>
    <div class="pcard-body">
      <?php if ($p['category'] !== ''): ?><span class="pcard-cat"><?= e($p['category']) ?></span><?php endif; ?>
      <h3 class="pcard-title"><a href="product.php?id=<?= e($p['id']) ?>"><?= e($p['title']) ?></a></h3>
      <?php if ($p['unit'] !== ''): ?><span class="pcard-unit"><?= e($p['unit']) ?></span><?php endif; ?>
      <div class="price-row">
        <span class="price"><?= money($p['price']) ?></span>
        <?php if ($p['original_price']): ?><s class="price-old"><?= money($p['original_price']) ?></s><?php endif; ?>
      </div>
      <?php if ($p['discount_text'] !== ''): ?>
        <span class="save-pill"><?= e($p['discount_text']) ?></span>
      <?php elseif ($p['save_amount'] > 0): ?>
        <span class="save-pill">Save <?= money($p['save_amount']) ?></span>
      <?php endif; ?>
      <div class="pcard-actions">
        <button type="button" class="btn btn-outline btn-sm" data-add-to-cart="<?= e($p['id']) ?>" <?= $p['in_stock'] ? '' : 'disabled' ?>><i class="fa-solid fa-cart-plus"></i> Add to Cart</button>
        <button type="button" class="btn btn-primary btn-sm" data-buy-now="<?= e($p['id']) ?>" <?= $p['in_stock'] ? '' : 'disabled' ?>><i class="fa-solid fa-bolt"></i> Buy Now</button>
      </div>
    </div>
  </article>
<?php
}
