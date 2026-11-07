<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pages.php';

$products = all_products();

nh_page_header('My Wishlist', $products, '', [
    'description' => 'Products you saved at ' . $SITE['brand_name'] . '.',
    'head' => '  <meta name="robots" content="noindex">' . "\n",
    'body_class' => 'page-wishlist',
]);
?>
<main class="nh-page wl">
  <div class="container">
    <nav class="nh-crumbs" aria-label="Breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right"></i><span aria-current="page">Wishlist</span></nav>

    <div class="wl-head">
      <div>
        <h1>My Wishlist</h1>
        <p id="wlCount">Saved products on this device</p>
      </div>
      <div class="wl-head-actions" id="wlActions" hidden>
        <button type="button" class="btn btn-primary" data-wl-all><i class="fa-solid fa-cart-plus"></i> Add all to cart</button>
      </div>
    </div>

    <div class="co-loading" id="wlLoading"><span class="nh-spinner"></span> Loading your wishlist…</div>
    <div class="wl-list" id="wlList" hidden></div>

    <div class="nh-empty" id="wlEmpty" hidden>
      <span class="nh-empty-icon"><i class="fa-regular fa-heart"></i></span>
      <h2>Your wishlist is empty</h2>
      <p>Tap the heart on any product to save it here for later.</p>
      <a href="/index.php#shop" class="btn btn-primary">Discover products</a>
    </div>
  </div>
</main>
<?php nh_page_footer($products, ['page' => 'wishlist']);
