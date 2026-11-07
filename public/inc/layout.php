<?php
/**
 * Page chrome: header, footer, drawers, modals and the product card.
 *
 * Public helpers (used by other pages):
 *   render_header($title, $products, $active)   — <head>, top bar, header, nav
 *   render_footer($products)                    — footer, drawers, scripts
 *   render_product_card($p)                     — echoes a product card
 *   product_card_html($p): string               — same card, returned as a string
 *   nh_url($path), nh_stars($rating), nh_discount_pct($p), nh_available($p)
 */

/** Make a stored path absolute so it works from any URL depth (/x, /dir/). */
function nh_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('#^([a-z][a-z0-9+.-]*:|//|/|\#|\?)#i', $path)) return $path;
    return '/' . ltrim(preg_replace('#^\./#', '', $path), '/');
}

/** Cache-busted asset URL; '' when the file does not exist. */
function nh_asset(string $rel): string {
    $file = __DIR__ . '/../' . ltrim($rel, '/');
    if (!is_file($file)) return '';
    return '/' . ltrim($rel, '/') . '?v=' . filemtime($file);
}

/** Product can be bought right now (flag on + stock not exhausted). */
function nh_available(array $p): bool {
    return !empty($p['in_stock']) && (!isset($p['stock']) || $p['stock'] === null || $p['stock'] > 0);
}

function nh_discount_pct(array $p): int {
    $o = (float)($p['original_price'] ?? 0);
    return $o > 0 && $o > $p['price'] ? (int)round(($o - $p['price']) / $o * 100) : 0;
}

function nh_stars(?float $rating): string {
    if ($rating === null) return '';
    $r = max(0, min(5, $rating));
    $out = '<span class="stars" role="img" aria-label="Rated ' . number_format($r, 1) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $icon = $r >= $i - .25 ? 'fa-solid fa-star' : ($r >= $i - .75 ? 'fa-solid fa-star-half-stroke' : 'fa-regular fa-star');
        $out .= '<i class="' . $icon . '" aria-hidden="true"></i>';
    }
    return $out . '</span>';
}

/** Extra search text per product id (English title, Bangla category…) from the raw store. */
function nh_search_index(): array {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (store_all('products') as $raw) {
        if (!isset($raw['id'])) continue;
        $map[(string)$raw['id']] = trim(implode(' ', array_filter(array_map('strval', [
            $raw['title_en'] ?? '', $raw['title_bn'] ?? '', $raw['category_bn'] ?? '', $raw['sku'] ?? '', $raw['slug'] ?? '',
        ]))));
    }
    return $map;
}

/** Product payload for client-side cart, search, wishlist and quick view (superset of client_products()). */
function nh_client_products(array $products): array {
    $index = nh_search_index();
    $base  = client_products($products);
    foreach ($products as $i => $p) {
        $desc = trim(preg_replace('/\s+/u', ' ', strip_tags($p['description'])));
        if (mb_strlen($desc, 'UTF-8') > 240) $desc = rtrim(mb_substr($desc, 0, 240, 'UTF-8')) . '…';
        $base[$i] = array_merge($base[$i], [
            'image'         => nh_url($p['image']),
            'images'        => array_map('nh_url', array_slice($p['images'], 0, 6)),
            'in_stock'      => nh_available($p),
            'stock'         => $p['stock'],
            'category_slug' => $p['category_slug'],
            'badge'         => $p['badge'],
            'rating'        => $p['rating'],
            'reviews_count' => $p['reviews_count'],
            'discount'      => nh_discount_pct($p),
            'discount_text' => $p['discount_text'],
            'short'         => $desc,
            'search'        => $index[(string)$p['id']] ?? '',
        ]);
    }
    return $base;
}

function brand_mark(): string {
    global $SITE;
    if (!empty($SITE['logo_url'])) {
        return '<span class="brand-logo"><img src="' . e(nh_url($SITE['logo_url'])) . '" alt="" width="42" height="42"></span>';
    }
    return '<span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M16 4c-5.5 7.4-9 12.6-9 17.3a9 9 0 0 0 18 0C25 16.6 21.5 11.4 16 4z" fill="currentColor"/><path d="M12.5 21.5a3.5 3.5 0 0 0 3.5 3.5" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none" opacity=".85"/></svg></span>';
}

function render_header(string $title, array $products, string $active = ''): void {
    global $SITE;
    $cats = categories_from($products);
    $pageTitle = $title ? $title . ' | ' . $SITE['brand_name'] : $SITE['brand_name'] . ' — ' . $SITE['tagline'];
    $hasSale = (bool)array_filter($products, fn($p) => $p['original_price'] && nh_available($p));
    $pagesCss = nh_asset('assets/css/pages.css');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="<?= e(($SITE['meta_description'] ?? '') ?: $SITE['tagline']) ?>">
  <meta property="og:description" content="<?= e(($SITE['meta_description'] ?? '') ?: $SITE['tagline']) ?>">
  <meta name="theme-color" content="#0F2A28">
  <meta property="og:title" content="<?= e($pageTitle) ?>">
  <meta property="og:site_name" content="<?= e($SITE['brand_name']) ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22><rect width=%2232%22 height=%2232%22 rx=%228%22 fill=%22%23F28C28%22/><path d=%22M16 6c-4.6 6.2-7.5 10.5-7.5 14.4a7.5 7.5 0 0 0 15 0C23.5 16.5 20.6 12.2 16 6z%22 fill=%22white%22/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= e(nh_asset('assets/css/site.css') ?: '/assets/css/site.css') ?>">
  <?php if ($pagesCss): ?><link rel="stylesheet" href="<?= e($pagesCss) ?>"><?php endif; ?>
  <script>document.documentElement.classList.add('js');['load','error'].forEach(function(t){document.addEventListener(t,function(e){if(e.target&&e.target.tagName==='IMG')e.target.classList.add('is-loaded');},true);});</script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
<?php if (!empty($SITE['announcement']) || !empty($SITE['phone'])): ?>
  <div class="topbar">
    <div class="container topbar-inner">
      <span class="topbar-note"><?php if (!empty($SITE['announcement'])): ?><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> <?= e($SITE['announcement']) ?><?php endif; ?></span>
      <span class="topbar-links">
        <?php if (!empty($SITE['phone'])): ?><a href="tel:<?= e($SITE['phone']) ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> <?= e($SITE['phone']) ?></a><?php endif; ?>
        <a href="/track.php"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i> Track order</a>
      </span>
    </div>
  </div>
<?php endif; ?>

  <header class="site-header" id="siteHeader">
    <div class="container header-inner">
      <button type="button" class="hdr-burger" data-open-panel="mobileMenu" aria-controls="mobileMenu" aria-expanded="false" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>

      <a href="/" class="brand" aria-label="<?= e($SITE['brand_name']) ?> home">
        <?= brand_mark() ?>
        <span class="brand-text"><strong><?= e($SITE['brand_name']) ?></strong><?php if (!empty($SITE['tagline'])): ?><small><?= e($SITE['tagline']) ?></small><?php endif; ?></span>
      </a>

      <form class="search" action="/" method="get" role="search" autocomplete="off">
        <i class="fa-solid fa-magnifying-glass search-ico" aria-hidden="true"></i>
        <input type="search" name="q" id="searchInput" placeholder="Search for honey, ghee, dates…" value="<?= e($_GET['q'] ?? '') ?>"
               aria-label="Search products" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="searchResults">
        <button type="submit" class="search-btn"><span>Search</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        <div class="search-results" id="searchResults" role="listbox" hidden></div>
      </form>

      <nav class="header-actions" aria-label="Shortcuts">
        <a href="/track.php" class="hact hact--track"><i class="fa-solid fa-truck-ramp-box" aria-hidden="true"></i><span class="hact-label"><small>Where's my</small>Order</span></a>
        <button type="button" class="hact" data-open-panel="wishDrawer" aria-label="Wishlist">
          <span class="hact-ico"><i class="fa-regular fa-heart" aria-hidden="true"></i><b class="count-badge" data-wish-count hidden>0</b></span>
          <span class="hact-label"><small>Saved</small>Wishlist</span>
        </button>
        <button type="button" class="hact hact--cart" data-open-cart aria-label="Open cart">
          <span class="hact-ico"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><b class="count-badge" data-cart-count>0</b></span>
          <span class="hact-label"><small>My cart</small><strong data-cart-total><?= e($SITE['currency_symbol']) ?>0</strong></span>
        </button>
      </nav>
    </div>

    <?php if ($cats): ?>
    <nav class="catnav" aria-label="Categories">
      <div class="container catnav-inner">
        <div class="mega" data-mega>
          <button type="button" class="mega-toggle" aria-expanded="false" aria-controls="megaPanel"><i class="fa-solid fa-grip" aria-hidden="true"></i> Categories <i class="fa-solid fa-chevron-down mega-caret" aria-hidden="true"></i></button>
          <div class="mega-panel" id="megaPanel">
            <div class="mega-grid">
              <?php foreach ($cats as $c): ?>
                <a class="mega-item" href="/?category=<?= e($c['slug']) ?>">
                  <span class="mega-ico<?= $c['is_icon'] ? ' is-icon' : '' ?>"><img src="<?= e(nh_url($c['image'])) ?>" alt="" width="48" height="48" loading="lazy"></span>
                  <span><strong><?= e($c['name']) ?></strong><small><?= (int)$c['count'] ?> product<?= $c['count'] == 1 ? '' : 's' ?></small></span>
                </a>
              <?php endforeach; ?>
            </div>
            <a class="mega-all" href="/#shop">Browse the full shop <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
          </div>
        </div>
        <div class="catnav-links">
          <a href="/#shop" class="<?= $active === 'all' ? 'is-active' : '' ?>">All Products</a>
          <?php foreach ($cats as $c): ?>
            <a href="/?category=<?= e($c['slug']) ?>" class="<?= $active === $c['slug'] ? 'is-active' : '' ?>"><?= e($c['name']) ?></a>
          <?php endforeach; ?>
        </div>
        <?php if ($hasSale): ?><a href="/?sort=discount#shop" class="catnav-offer"><i class="fa-solid fa-tags" aria-hidden="true"></i> Offers</a><?php endif; ?>
      </div>
    </nav>
    <?php endif; ?>
  </header>

  <!-- Mobile off-canvas menu -->
  <aside class="offcanvas panel-el" id="mobileMenu" aria-label="Menu" aria-hidden="true" tabindex="-1">
    <div class="offcanvas-head">
      <a href="/" class="brand"><?= brand_mark() ?><span class="brand-text"><strong><?= e($SITE['brand_name']) ?></strong></span></a>
      <button type="button" class="icon-btn" data-close-panel aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="offcanvas-body">
      <form class="oc-search" action="/" method="get" role="search">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" name="q" placeholder="Search products" aria-label="Search products" value="<?= e($_GET['q'] ?? '') ?>">
      </form>
      <?php if ($cats): ?>
        <p class="oc-label">Categories</p>
        <ul class="oc-cats" id="ocCats">
          <?php foreach ($cats as $c): ?>
            <li><a href="/?category=<?= e($c['slug']) ?>" class="<?= $active === $c['slug'] ? 'is-active' : '' ?>">
              <span class="mega-ico<?= $c['is_icon'] ? ' is-icon' : '' ?>"><img src="<?= e(nh_url($c['image'])) ?>" alt="" width="40" height="40" loading="lazy"></span>
              <span><?= e($c['name']) ?></span><small><?= (int)$c['count'] ?></small></a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p class="oc-label">Shop</p>
      <ul class="oc-links">
        <li><a href="/"><i class="fa-solid fa-house" aria-hidden="true"></i> Home</a></li>
        <li><a href="/#shop"><i class="fa-solid fa-store" aria-hidden="true"></i> All products</a></li>
        <?php if ($hasSale): ?><li><a href="/?sort=discount#shop"><i class="fa-solid fa-tags" aria-hidden="true"></i> Offers</a></li><?php endif; ?>
        <li><a href="/track.php"><i class="fa-solid fa-truck-ramp-box" aria-hidden="true"></i> Track order</a></li>
        <li><a href="/checkout.php"><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Checkout</a></li>
      </ul>
      <?php if (!empty($SITE['phone']) || !empty($SITE['whatsapp']) || !empty($SITE['email'])): ?>
        <p class="oc-label">Need help?</p>
        <ul class="oc-links">
          <?php if (!empty($SITE['phone'])): ?><li><a href="tel:<?= e($SITE['phone']) ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> <?= e($SITE['phone']) ?></a></li><?php endif; ?>
          <?php if (!empty($SITE['whatsapp'])): ?><li><a href="https://wa.me/<?= e($SITE['whatsapp']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp us</a></li><?php endif; ?>
          <?php if (!empty($SITE['email'])): ?><li><a href="mailto:<?= e($SITE['email']) ?>"><i class="fa-solid fa-envelope" aria-hidden="true"></i> <?= e($SITE['email']) ?></a></li><?php endif; ?>
        </ul>
      <?php endif; ?>
    </div>
  </aside>
<?php
}

function render_footer(array $products): void {
    global $SITE;
    $cats = categories_from($products);
    $cur  = $SITE['currency_symbol'] ?? '৳';
    $free = (float)($SITE['free_delivery_threshold'] ?? 0);
    $socials = array_filter([
        'Facebook'  => ['fa-facebook-f', $SITE['facebook_url'] ?? ''],
        'Instagram' => ['fa-instagram', $SITE['instagram_url'] ?? ''],
        'YouTube'   => ['fa-youtube', $SITE['youtube_url'] ?? ''],
        'WhatsApp'  => ['fa-whatsapp', !empty($SITE['whatsapp']) ? 'https://wa.me/' . $SITE['whatsapp'] : ''],
    ], fn($s) => $s[1] !== '');
    $pagesJs = nh_asset('assets/js/pages.js');
    ?>
  <footer class="site-footer">
    <div class="container footer-grid">
      <div class="footer-brand">
        <a href="/" class="brand brand--light"><?= brand_mark() ?><span class="brand-text"><strong><?= e($SITE['brand_name']) ?></strong><?php if (!empty($SITE['tagline'])): ?><small><?= e($SITE['tagline']) ?></small><?php endif; ?></span></a>
        <?php if ($socials): ?>
          <div class="socials">
            <?php foreach ($socials as $name => [$icon, $url]): ?>
              <a href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="<?= e($name) ?>"><i class="fa-brands <?= e($icon) ?>" aria-hidden="true"></i></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($cats): ?>
      <div class="footer-col">
        <h4>Shop</h4>
        <ul>
          <?php foreach (array_slice($cats, 0, 7) as $c): ?>
            <li><a href="/?category=<?= e($c['slug']) ?>"><?= e($c['name']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <div class="footer-col">
        <h4>Help</h4>
        <ul>
          <li><a href="/track.php">Track your order</a></li>
          <li><a href="/checkout.php">Checkout</a></li>
          <li><button type="button" class="linklike" data-open-panel="wishDrawer">My wishlist</button></li>
          <li><a href="/#shop">All products</a></li>
        </ul>
      </div>

      <?php if (!empty($SITE['phone']) || !empty($SITE['email']) || !empty($SITE['address'])): ?>
      <div class="footer-col">
        <h4>Contact</h4>
        <ul class="contact-list">
          <?php if (!empty($SITE['phone'])): ?><li><i class="fa-solid fa-phone" aria-hidden="true"></i><a href="tel:<?= e($SITE['phone']) ?>"><?= e($SITE['phone']) ?></a></li><?php endif; ?>
          <?php if (!empty($SITE['email'])): ?><li><i class="fa-solid fa-envelope" aria-hidden="true"></i><a href="mailto:<?= e($SITE['email']) ?>"><?= e($SITE['email']) ?></a></li><?php endif; ?>
          <?php if (!empty($SITE['address'])): ?><li><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span><?= e($SITE['address']) ?></span></li><?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
    <div class="container footer-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($SITE['brand_name']) ?>. All rights reserved.</span>
      <?php if (!empty($SITE['payment_methods'])): ?>
        <span class="pay-pills"><small>We accept</small><?php foreach ($SITE['payment_methods'] as $label): ?><span><?= e($label) ?></span><?php endforeach; ?></span>
      <?php endif; ?>
    </div>
  </footer>

  <!-- Floating helpers -->
  <div class="floaters">
    <?php if (!empty($SITE['whatsapp'])): ?>
      <a class="float-wa" href="https://wa.me/<?= e($SITE['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></a>
    <?php endif; ?>
    <button type="button" class="to-top" id="toTop" aria-label="Back to top"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></button>
  </div>

  <!-- Mobile tab bar -->
  <nav class="tabbar" aria-label="Quick navigation">
    <a href="/" class="tab"><i class="fa-solid fa-house" aria-hidden="true"></i><span>Home</span></a>
    <button type="button" class="tab" data-open-panel="mobileMenu" data-focus="#ocCats"><i class="fa-solid fa-grip" aria-hidden="true"></i><span>Categories</span></button>
    <button type="button" class="tab" data-open-panel="wishDrawer"><span class="tab-ico"><i class="fa-regular fa-heart" aria-hidden="true"></i><b class="count-badge" data-wish-count hidden>0</b></span><span>Wishlist</span></button>
    <button type="button" class="tab" data-open-cart><span class="tab-ico"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><b class="count-badge" data-cart-count>0</b></span><span>Cart</span></button>
    <a href="/track.php" class="tab"><i class="fa-solid fa-truck-ramp-box" aria-hidden="true"></i><span>Track</span></a>
  </nav>

  <div class="drawer-overlay" data-close-cart data-close-panel hidden></div>

  <!-- Cart drawer -->
  <aside class="drawer panel-el" id="cartDrawer" aria-label="Shopping cart" aria-hidden="true" tabindex="-1">
    <div class="drawer-head">
      <h3><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Your Cart <span>(<b data-cart-count>0</b>)</span></h3>
      <button type="button" class="icon-btn" data-close-cart aria-label="Close cart"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <?php if ($free > 0): ?>
      <div class="freeship" id="freeShip" data-threshold="<?= e($free) ?>">
        <p class="freeship-msg" data-freeship-msg>Add <?= e($cur . number_format($free)) ?> more for free delivery</p>
        <div class="freeship-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-freeship-bar></span></div>
      </div>
    <?php endif; ?>
    <div class="drawer-body" id="cartItems"></div>
    <div class="drawer-foot">
      <div class="drawer-subtotal"><span>Subtotal</span><strong data-cart-total><?= e($cur) ?>0</strong></div>
      <p class="drawer-note">Delivery charge is calculated at checkout.</p>
      <a href="/checkout.php" class="btn btn-primary btn-lg btn-block" id="drawerCheckout">Checkout <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
      <button type="button" class="btn btn-ghost btn-block drawer-continue" data-close-cart>Continue shopping</button>
    </div>
  </aside>

  <!-- Wishlist drawer -->
  <aside class="drawer panel-el" id="wishDrawer" aria-label="Wishlist" aria-hidden="true" tabindex="-1">
    <div class="drawer-head">
      <h3><i class="fa-solid fa-heart" aria-hidden="true"></i> Wishlist <span>(<b data-wish-count>0</b>)</span></h3>
      <button type="button" class="icon-btn" data-close-panel aria-label="Close wishlist"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer-body" id="wishItems"></div>
  </aside>

  <!-- Quick view -->
  <div class="modal" id="quickView" role="dialog" aria-modal="true" aria-labelledby="qvTitle" hidden>
    <div class="modal-backdrop" data-close-modal></div>
    <div class="modal-card" tabindex="-1">
      <button type="button" class="icon-btn modal-close" data-close-modal aria-label="Close quick view"><i class="fa-solid fa-xmark"></i></button>
      <div class="modal-content" id="qvBody"></div>
    </div>
  </div>

  <div class="toast-stack" id="toasts" aria-live="polite"></div>

  <script>
    window.SITE = <?= json_encode([
        'currency'     => $cur,
        'freeDelivery' => $free > 0 ? $free : 0,
        'whatsapp'     => (string)($SITE['whatsapp'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    window.SITE_PRODUCTS = <?= json_encode(nh_client_products($products), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  </script>
  <script src="<?= e(nh_asset('assets/js/site.js') ?: '/assets/js/site.js') ?>"></script>
  <?php if ($pagesJs): ?><script src="<?= e($pagesJs) ?>"></script><?php endif; ?>
</body>
</html>
<?php
}

/** Product card markup as a string. */
function product_card_html(array $p): string {
    $id    = (string)$p['id'];
    $avail = nh_available($p);
    $pct   = nh_discount_pct($p);
    $url   = '/product.php?id=' . rawurlencode($id);
    $img   = nh_url($p['image']);
    $alt   = isset($p['images'][1]) && $p['images'][1] !== $p['image'] ? nh_url($p['images'][1]) : '';
    $low   = $avail && $p['stock'] !== null && $p['stock'] <= 5;
    ob_start(); ?>
  <article class="pcard<?= $avail ? '' : ' is-out' ?><?= $alt ? ' has-alt' : '' ?>" data-pid="<?= e($id) ?>">
    <div class="pcard-media">
      <a href="<?= e($url) ?>" class="pcard-img" aria-label="<?= e($p['title']) ?>">
        <img class="pcard-img-main" src="<?= e($img) ?>" alt="<?= e($p['title']) ?>" width="600" height="600" loading="lazy" decoding="async">
        <?php if ($alt): ?><img class="pcard-img-alt" src="<?= e($alt) ?>" alt="" width="600" height="600" loading="lazy" decoding="async"><?php endif; ?>
      </a>
      <div class="pcard-flags">
        <?php if ($pct > 0): ?><span class="flag flag--off">-<?= $pct ?>%</span><?php endif; ?>
        <?php if ($p['badge'] !== ''): ?><span class="flag flag--badge"><?= e($p['badge']) ?></span><?php endif; ?>
      </div>
      <?php if (!$avail): ?><span class="pcard-soldout">Out of stock</span><?php endif; ?>
      <button type="button" class="pcard-wish" data-wish="<?= e($id) ?>" aria-pressed="false" aria-label="Save <?= e($p['title']) ?> to wishlist"><i class="fa-regular fa-heart" aria-hidden="true"></i></button>
      <button type="button" class="pcard-quick" data-quick-view="<?= e($id) ?>" aria-label="Quick view: <?= e($p['title']) ?>"><i class="fa-regular fa-eye" aria-hidden="true"></i><span>Quick view</span></button>
    </div>
    <div class="pcard-body">
      <div class="pcard-meta">
        <?php if ($p['category'] !== ''): ?><span class="pcard-cat"><?= e($p['category']) ?></span><?php endif; ?>
        <?php if ($p['rating'] !== null): ?><span class="pcard-rating"><?= nh_stars($p['rating']) ?><?php if ($p['reviews_count']): ?><small>(<?= (int)$p['reviews_count'] ?>)</small><?php endif; ?></span><?php endif; ?>
      </div>
      <h3 class="pcard-title"><a href="<?= e($url) ?>"><?= e($p['title']) ?></a></h3>
      <div class="pcard-sub">
        <?php if ($p['unit'] !== ''): ?><span class="pcard-unit"><?= e($p['unit']) ?></span><?php endif; ?>
        <?php if ($low): ?><span class="pcard-low">Only <?= (int)$p['stock'] ?> left</span><?php endif; ?>
      </div>
      <div class="price-row">
        <span class="price"><?= money($p['price']) ?></span>
        <?php if ($p['original_price']): ?><s class="price-old"><?= money($p['original_price']) ?></s><?php endif; ?>
      </div>
      <div class="pcard-cta" data-cta="<?= e($id) ?>">
        <?php if ($avail): ?>
          <button type="button" class="btn btn-primary btn-sm btn-block pcard-add" data-add-to-cart="<?= e($id) ?>"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Add to cart</button>
          <div class="qty qty--card" data-cta-qty hidden>
            <button type="button" data-cta-dec aria-label="Decrease quantity"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
            <span data-cta-count aria-live="polite">1</span>
            <button type="button" data-cta-inc aria-label="Increase quantity"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
          </div>
        <?php else: ?>
          <button type="button" class="btn btn-sm btn-block btn-soldout" disabled>Out of stock</button>
        <?php endif; ?>
      </div>
    </div>
  </article>
<?php
    return (string)ob_get_clean();
}

/** Echo a product card (kept for pages that call it as a statement). */
function render_product_card(array $p): void {
    echo product_card_html($p);
}
