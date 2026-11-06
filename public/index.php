<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$products   = all_products();
$categories = categories_from($products);

$q       = trim($_GET['q'] ?? '');
$catSlug = trim($_GET['category'] ?? '');
$sort    = $_GET['sort'] ?? '';

$isListing = $q !== '' || $catSlug !== '';
$listing   = $products;
$listTitle = 'All Products';

if ($catSlug !== '') {
    $listing = array_values(array_filter($listing, fn($p) => $p['category_slug'] === $catSlug));
    foreach ($categories as $c) if ($c['slug'] === $catSlug) $listTitle = $c['name'];
}
if ($q !== '') {
    $needle  = mb_strtolower($q, 'UTF-8');
    $listing = array_values(array_filter($listing, fn($p) =>
        str_contains(mb_strtolower($p['title'] . ' ' . $p['category'] . ' ' . $p['description'], 'UTF-8'), $needle)));
    $listTitle = 'Results for "' . $q . '"';
}
if ($sort === 'price_asc')  usort($listing, fn($a, $b) => $a['price'] <=> $b['price']);
if ($sort === 'price_desc') usort($listing, fn($a, $b) => $b['price'] <=> $a['price']);

$featured = array_values(array_filter($products, fn($p) => $p['featured'] && $p['in_stock']));
if (!$featured) $featured = $products;
$featured = array_slice($featured, 0, 8);

$heroImg   = $SITE['hero']['image'] ?: ($featured[0]['image'] ?? placeholder_image());
$spotlight = $featured[1] ?? $featured[0] ?? null;

render_header($isListing ? $listTitle : '', $products, $catSlug ?: ($isListing ? '' : 'all'));
?>
<main>
<?php if ($isListing): ?>
  <section class="section">
    <div class="container">
      <nav class="crumbs"><a href="index.php">Home</a><i class="fa-solid fa-chevron-right"></i><span><?= e($listTitle) ?></span></nav>
      <div class="section-head">
        <h2><?= e($listTitle) ?> <small><?= count($listing) ?> item<?= count($listing) === 1 ? '' : 's' ?></small></h2>
        <form method="get" class="sort-form">
          <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
          <?php if ($catSlug !== ''): ?><input type="hidden" name="category" value="<?= e($catSlug) ?>"><?php endif; ?>
          <label for="sort">Sort</label>
          <select name="sort" id="sort" onchange="this.form.submit()">
            <option value="">Default</option>
            <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
            <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
          </select>
        </form>
      </div>
      <?php if ($listing): ?>
        <div class="grid"><?php foreach ($listing as $p) render_product_card($p); ?></div>
      <?php else: ?>
        <div class="empty">
          <i class="fa-solid fa-magnifying-glass"></i>
          <h3>No products found</h3>
          <p>Try a different search or browse all products.</p>
          <a href="index.php#shop" class="btn btn-primary">Browse all products</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

<?php else: ?>
  <?php $banners = array_values(array_filter($SITE['banners'] ?? [], fn($b) => !empty($b['image'])));
        $side = $SITE['side_banner'] ?? [];
  if ($banners): ?>
  <!-- Banner slider -->
  <section class="hero">
    <div class="container banner-grid<?= empty($side['image']) ? ' banner-grid--full' : '' ?>">
      <div class="slider" data-slider>
        <div class="slider-track">
          <?php foreach ($banners as $i => $b): ?>
            <a class="slide" href="<?= e($b['link'] ?? '#shop') ?>" aria-label="<?= e($b['alt'] ?? '') ?>">
              <img src="<?= e($b['image']) ?>" alt="<?= e($b['alt'] ?? '') ?>" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if (count($banners) > 1): ?>
          <button type="button" class="slider-arrow slider-prev" data-slide-prev aria-label="Previous banner"><i class="fa-solid fa-arrow-left"></i></button>
          <button type="button" class="slider-arrow slider-next" data-slide-next aria-label="Next banner"><i class="fa-solid fa-arrow-right"></i></button>
          <div class="slider-dots"><?php foreach ($banners as $i => $b): ?><button type="button" data-slide-to="<?= $i ?>" aria-label="Banner <?= $i + 1 ?>"></button><?php endforeach; ?></div>
        <?php endif; ?>
      </div>
      <?php if (!empty($side['image'])): ?>
        <a class="side-banner" href="<?= e($side['link'] ?? '#shop') ?>"><img src="<?= e($side['image']) ?>" alt="<?= e($side['alt'] ?? '') ?>"></a>
      <?php endif; ?>
    </div>
  </section>
  <?php else: ?>
  <!-- Hero -->
  <section class="hero">
    <div class="container hero-grid">
      <div class="hero-main">
        <div class="hero-copy">
          <?php if (!empty($SITE['hero']['eyebrow'])): ?><span class="eyebrow"><i class="fa-solid fa-leaf"></i> <?= e($SITE['hero']['eyebrow']) ?></span><?php endif; ?>
          <h1><?= e($SITE['hero']['title']) ?></h1>
          <?php if (!empty($SITE['hero']['subtitle'])): ?><p><?= e($SITE['hero']['subtitle']) ?></p><?php endif; ?>
          <div class="hero-ctas">
            <a href="#shop" class="btn btn-primary btn-lg"><?= e($SITE['hero']['cta_label'] ?: 'Shop now') ?> <i class="fa-solid fa-arrow-right"></i></a>
            <a href="track.php" class="btn btn-ghost btn-lg">Track order</a>
          </div>
        </div>
        <div class="hero-art"><span class="hero-ring"></span><img src="<?= e($heroImg) ?>" alt=""></div>
      </div>

      <?php if ($spotlight): ?>
      <a href="product.php?id=<?= e($spotlight['id']) ?>" class="hero-side">
        <span class="hero-side-tag">Featured</span>
        <img src="<?= e($spotlight['image']) ?>" alt="<?= e($spotlight['title']) ?>">
        <span class="hero-side-info">
          <strong><?= e($spotlight['title']) ?></strong>
          <span><span class="price"><?= money($spotlight['price']) ?></span><?php if ($spotlight['original_price']): ?> <s><?= money($spotlight['original_price']) ?></s><?php endif; ?></span>
        </span>
        <span class="hero-side-cta">Shop now <i class="fa-solid fa-arrow-right"></i></span>
      </a>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($SITE['trust_items'])): ?>
  <section class="trust">
    <div class="container"><div class="trust-grid">
      <?php foreach ($SITE['trust_items'] as $t): ?>
        <div class="trust-item">
          <span class="trust-icon"><i class="fa-solid <?= e($t['icon']) ?>"></i></span>
          <span><strong><?= e($t['title']) ?></strong><small><?= e($t['text']) ?></small></span>
        </div>
      <?php endforeach; ?>
    </div></div>
  </section>
  <?php endif; ?>

  <?php if ($categories): ?>
  <section class="section">
    <div class="container">
      <h2 class="center-title">Featured Categories</h2>
      <div class="cat-rail" data-rail>
        <button type="button" class="rail-btn rail-prev" data-rail-prev aria-label="Scroll left"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="cat-row" data-rail-track>
          <?php foreach ($categories as $c): ?>
            <a class="cat-tile" href="index.php?category=<?= e($c['slug']) ?>">
              <span class="cat-tile-img<?= $c['is_icon'] ? ' is-icon' : '' ?>"><img src="<?= e($c['image']) ?>" alt="" loading="lazy"></span>
              <strong><?= e($c['name']) ?></strong>
            </a>
          <?php endforeach; ?>
        </div>
        <button type="button" class="rail-btn rail-next" data-rail-next aria-label="Scroll right"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($featured): ?>
  <section class="section">
    <div class="container">
      <div class="section-head"><h2>Best Sellers</h2><a href="#shop" class="see-all">See all <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="grid"><?php foreach ($featured as $p) render_product_card($p); ?></div>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($categories as $c):
      $items = array_values(array_filter($products, fn($p) => $p['category_slug'] === $c['slug']));
      if (!$items) continue; ?>
  <section class="section section--alt">
    <div class="container">
      <div class="section-head"><h2><?= e($c['name']) ?></h2><a href="index.php?category=<?= e($c['slug']) ?>" class="see-all">See all <i class="fa-solid fa-arrow-right"></i></a></div>
      <div class="grid"><?php foreach (array_slice($items, 0, 8) as $p) render_product_card($p); ?></div>
    </div>
  </section>
  <?php endforeach; ?>

  <section class="section" id="shop">
    <div class="container">
      <div class="section-head"><h2>All Products <small><?= count($products) ?> items</small></h2></div>
      <?php if ($products): ?>
        <div class="grid"><?php foreach ($products as $p) render_product_card($p); ?></div>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-box-open"></i><h3>No products yet</h3><p>Products you add will appear here.</p></div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>
</main>
<?php render_footer($products);
