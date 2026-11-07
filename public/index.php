<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$products   = all_products();
$categories = categories_from($products);

/* ---------- Query state (also applied client-side by site.js) ---------- */
$q        = trim((string)($_GET['q'] ?? ''));
$catSlug  = trim((string)($_GET['category'] ?? ''));
$sort     = (string)($_GET['sort'] ?? '');
$minPrice = is_numeric($_GET['min'] ?? null) ? (float)$_GET['min'] : null;
$maxPrice = is_numeric($_GET['max'] ?? null) ? (float)$_GET['max'] : null;
$inStock  = !empty($_GET['instock']);
$sorts = [
    ''           => 'Featured',
    'newest'     => 'Newest',
    'popular'    => 'Most popular',
    'price_asc'  => 'Price: low to high',
    'price_desc' => 'Price: high to low',
    'discount'   => 'Biggest discount',
];
if (!isset($sorts[$sort])) $sort = '';

$isListing = $q !== '' || $catSlug !== '';
$catName = '';
foreach ($categories as $c) if ($c['slug'] === $catSlug) $catName = $c['name'];
$listTitle = $q !== '' ? 'Results for "' . $q . '"' : ($catName ?: ($catSlug !== '' ? 'Category not found' : 'All Products'));

$index  = nh_search_index();
$prices = array_column($products, 'price');
$priceFloor = $prices ? (int)floor(min($prices)) : 0;
$priceCeil  = $prices ? (int)ceil(max($prices)) : 0;

$popularity = fn($p) => ($p['reviews_count'] ?? 0) * ($p['rating'] ?? 0);
$matches = function (array $p) use ($q, $catSlug, $minPrice, $maxPrice, $inStock, $index): bool {
    if ($catSlug !== '' && $p['category_slug'] !== $catSlug) return false;
    if ($minPrice !== null && $p['price'] < $minPrice) return false;
    if ($maxPrice !== null && $p['price'] > $maxPrice) return false;
    if ($inStock && !nh_available($p)) return false;
    if ($q !== '') {
        $hay = mb_strtolower($p['title'] . ' ' . $p['category'] . ' ' . $p['unit'] . ' ' . ($index[(string)$p['id']] ?? '') . ' ' . $p['description'], 'UTF-8');
        foreach (preg_split('/\s+/u', mb_strtolower($q, 'UTF-8')) as $word) {
            if ($word !== '' && !str_contains($hay, $word)) return false;
        }
    }
    return true;
};

$shopList = $products;
switch ($sort) {
    case 'newest':     usort($shopList, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']); break;
    case 'popular':    usort($shopList, fn($a, $b) => $popularity($b) <=> $popularity($a)); break;
    case 'price_asc':  usort($shopList, fn($a, $b) => $a['price'] <=> $b['price']); break;
    case 'price_desc': usort($shopList, fn($a, $b) => $b['price'] <=> $a['price']); break;
    case 'discount':   usort($shopList, fn($a, $b) => nh_discount_pct($b) <=> nh_discount_pct($a)); break;
}
$matchCount = count(array_filter($shopList, $matches));

/* ---------- Home sections ---------- */
$featured = array_values(array_filter($products, fn($p) => $p['featured'] && nh_available($p)));
if (!$featured) $featured = array_values(array_filter($products, 'nh_available'));
usort($featured, fn($a, $b) => $popularity($b) <=> $popularity($a));
$featured = array_slice($featured, 0, 10);

$onSale = array_values(array_filter($products, fn($p) => $p['original_price'] && nh_available($p)));
usort($onSale, fn($a, $b) => nh_discount_pct($b) <=> nh_discount_pct($a));
$onSale = array_slice($onSale, 0, 10);

$reviews = array_values(array_filter(store_all('reviews'), fn($r) =>
    in_array($r['status'] ?? 'approved', ['approved', ''], true) && trim((string)($r['comment'] ?? '')) !== ''));
usort($reviews, fn($a, $b) => strcmp((string)($b['review_date'] ?? $b['created_at'] ?? ''), (string)($a['review_date'] ?? $a['created_at'] ?? '')));
$ratingVals = array_filter(array_map(fn($r) => isset($r['rating']) ? (float)$r['rating'] : null, $reviews), fn($v) => $v !== null);
$avgRating  = $ratingVals ? array_sum($ratingVals) / count($ratingVals) : null;
$reviews    = array_slice($reviews, 0, 12);

$heroImg   = nh_url($SITE['hero']['image'] ?? '') ?: nh_url($featured[0]['image'] ?? placeholder_image());
$spotlight = $featured[1] ?? $featured[0] ?? null;

/** Horizontal product rail. */
function nh_product_rail(array $items, string $label): void { ?>
  <div class="prail" data-rail>
    <button type="button" class="rail-btn rail-prev" data-rail-prev aria-label="Scroll <?= e($label) ?> left"><i class="fa-solid fa-chevron-left"></i></button>
    <div class="prail-track" data-rail-track>
      <?php foreach ($items as $p) echo product_card_html($p); ?>
    </div>
    <button type="button" class="rail-btn rail-next" data-rail-next aria-label="Scroll <?= e($label) ?> right"><i class="fa-solid fa-chevron-right"></i></button>
  </div>
<?php }

function nh_section_head(string $title, string $sub = '', string $link = '', string $linkLabel = 'View all'): void { ?>
  <div class="section-head">
    <div><h2><?= e($title) ?></h2><?php if ($sub !== ''): ?><p><?= e($sub) ?></p><?php endif; ?></div>
    <?php if ($link !== ''): ?><a href="<?= e($link) ?>" class="see-all"><?= e($linkLabel) ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
  </div>
<?php }

render_header($isListing ? $listTitle : '', $products, $catSlug ?: ($isListing ? '' : 'all'));
?>
<main id="main">
<?php if (!$isListing): ?>
  <?php $banners = array_values(array_filter($SITE['banners'] ?? [], fn($b) => !empty($b['image'])));
        $side = $SITE['side_banner'] ?? [];
  if ($banners): ?>
  <section class="hero" aria-label="Featured offers">
    <div class="container banner-grid<?= empty($side['image']) ? ' banner-grid--full' : '' ?>">
      <div class="slider" data-slider aria-roledescription="carousel">
        <div class="slider-track">
          <?php foreach ($banners as $i => $b): ?>
            <a class="slide" href="<?= e(nh_url($b['link'] ?? '') ?: '#shop') ?>" aria-label="<?= e(($b['alt'] ?? '') ?: 'Banner ' . ($i + 1)) ?>">
              <img src="<?= e(nh_url($b['image'])) ?>" alt="<?= e($b['alt'] ?? '') ?>" width="1760" height="700" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if (count($banners) > 1): ?>
          <button type="button" class="slider-arrow slider-prev" data-slide-prev aria-label="Previous banner"><i class="fa-solid fa-arrow-left"></i></button>
          <button type="button" class="slider-arrow slider-next" data-slide-next aria-label="Next banner"><i class="fa-solid fa-arrow-right"></i></button>
          <div class="slider-dots"><?php foreach ($banners as $i => $b): ?><button type="button" data-slide-to="<?= $i ?>" aria-label="Show banner <?= $i + 1 ?>"></button><?php endforeach; ?></div>
          <span class="slider-progress" aria-hidden="true"><span></span></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($side['image'])): ?>
        <a class="side-banner" href="<?= e(nh_url($side['link'] ?? '') ?: '#shop') ?>" aria-label="<?= e($side['alt'] ?? 'Offer') ?>"><img src="<?= e(nh_url($side['image'])) ?>" alt="<?= e($side['alt'] ?? '') ?>" width="860" height="700"></a>
      <?php endif; ?>
    </div>
  </section>
  <?php else: ?>
  <section class="hero">
    <div class="container hero-grid">
      <div class="hero-main">
        <div class="hero-copy">
          <?php if (!empty($SITE['hero']['eyebrow'])): ?><span class="eyebrow"><i class="fa-solid fa-leaf" aria-hidden="true"></i> <?= e($SITE['hero']['eyebrow']) ?></span><?php endif; ?>
          <h1><?= e($SITE['hero']['title'] ?? $SITE['brand_name']) ?></h1>
          <?php if (!empty($SITE['hero']['subtitle'])): ?><p><?= e($SITE['hero']['subtitle']) ?></p><?php endif; ?>
          <div class="hero-ctas">
            <a href="#shop" class="btn btn-primary btn-lg"><?= e(($SITE['hero']['cta_label'] ?? '') ?: 'Shop now') ?> <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            <a href="/track.php" class="btn btn-ghost btn-lg">Track order</a>
          </div>
        </div>
        <div class="hero-art"><span class="hero-ring"></span><img src="<?= e($heroImg) ?>" alt="" width="600" height="600"></div>
      </div>
      <?php if ($spotlight): ?>
      <a href="/product.php?id=<?= e($spotlight['id']) ?>" class="hero-side">
        <span class="hero-side-tag">Featured</span>
        <img src="<?= e(nh_url($spotlight['image'])) ?>" alt="<?= e($spotlight['title']) ?>" width="400" height="400">
        <span class="hero-side-info">
          <strong><?= e($spotlight['title']) ?></strong>
          <span><span class="price"><?= money($spotlight['price']) ?></span><?php if ($spotlight['original_price']): ?> <s><?= money($spotlight['original_price']) ?></s><?php endif; ?></span>
        </span>
        <span class="hero-side-cta">Shop now <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
      </a>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($SITE['trust_items'])): ?>
  <section class="trust" aria-label="Why shop with us">
    <div class="container"><div class="trust-grid">
      <?php foreach ($SITE['trust_items'] as $t): if (empty($t['title'])) continue; ?>
        <div class="trust-item">
          <span class="trust-icon"><i class="fa-solid <?= e($t['icon'] ?? 'fa-check') ?>" aria-hidden="true"></i></span>
          <span><strong><?= e($t['title']) ?></strong><?php if (!empty($t['text'])): ?><small><?= e($t['text']) ?></small><?php endif; ?></span>
        </div>
      <?php endforeach; ?>
    </div></div>
  </section>
  <?php endif; ?>

  <?php if ($categories): ?>
  <section class="section" aria-labelledby="catTitle">
    <div class="container">
      <div class="section-head section-head--center"><div><h2 id="catTitle">Shop by Category</h2><p>Hand-picked natural goods, sorted for you</p></div></div>
      <div class="cat-rail" data-rail>
        <button type="button" class="rail-btn rail-prev" data-rail-prev aria-label="Scroll categories left"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="cat-row" data-rail-track>
          <?php foreach ($categories as $c): ?>
            <a class="cat-tile" href="/?category=<?= e($c['slug']) ?>">
              <span class="cat-tile-img<?= $c['is_icon'] ? ' is-icon' : '' ?>"><img src="<?= e(nh_url($c['image'])) ?>" alt="" width="136" height="136" loading="lazy"></span>
              <strong><?= e($c['name']) ?></strong>
              <small><?= (int)$c['count'] ?> item<?= $c['count'] == 1 ? '' : 's' ?></small>
            </a>
          <?php endforeach; ?>
        </div>
        <button type="button" class="rail-btn rail-next" data-rail-next aria-label="Scroll categories right"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($featured): ?>
  <section class="section" aria-label="Best sellers">
    <div class="container">
      <?php nh_section_head('Best Sellers', 'Our customers keep coming back for these', '/?sort=popular#shop'); ?>
      <?php nh_product_rail($featured, 'best sellers'); ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($onSale): ?>
  <section class="section section--deal" id="sale" aria-label="On sale">
    <div class="container">
      <div class="deal-band">
        <div class="deal-intro">
          <span class="eyebrow eyebrow--light"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Limited offers</span>
          <h2>On Sale Now</h2>
          <p>Save up to <?= max(array_map('nh_discount_pct', $onSale)) ?>% on selected products.</p>
          <a href="/?sort=discount#shop" class="btn btn-primary">Shop all offers <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
        </div>
        <?php nh_product_rail($onSale, 'offers'); ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($categories as $i => $c):
      $items = array_values(array_filter($products, fn($p) => $p['category_slug'] === $c['slug']));
      if (count($items) < 2) continue; ?>
  <section class="section<?= $i % 2 ? '' : ' section--alt' ?>" aria-label="<?= e($c['name']) ?>">
    <div class="container">
      <?php nh_section_head($c['name'], '', '/?category=' . rawurlencode($c['slug']), 'View all ' . $c['count']); ?>
      <?php nh_product_rail(array_slice($items, 0, 10), $c['name']); ?>
    </div>
  </section>
  <?php endforeach; ?>

  <?php if ($reviews): ?>
  <section class="section section--reviews" aria-labelledby="revTitle">
    <div class="container">
      <div class="section-head section-head--center">
        <div>
          <h2 id="revTitle">Loved by Our Customers</h2>
          <?php if ($avgRating !== null): ?><p class="rev-summary"><?= nh_stars($avgRating) ?> <b><?= number_format($avgRating, 1) ?></b> average from <?= count($ratingVals) ?> review<?= count($ratingVals) === 1 ? '' : 's' ?></p><?php endif; ?>
        </div>
      </div>
      <div class="prail" data-rail>
        <button type="button" class="rail-btn rail-prev" data-rail-prev aria-label="Previous reviews"><i class="fa-solid fa-chevron-left"></i></button>
        <div class="prail-track rev-track" data-rail-track>
          <?php foreach ($reviews as $r):
              $name = trim((string)($r['customer_name'] ?? '')) ?: 'Customer'; ?>
            <figure class="rev-card">
              <div class="rev-top">
                <?php if (isset($r['rating'])) echo nh_stars((float)$r['rating']); ?>
                <?php if (!empty($r['verified_purchase'])): ?><span class="rev-verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Verified</span><?php endif; ?>
              </div>
              <blockquote><?= e($r['comment']) ?></blockquote>
              <?php if (!empty($r['product_title'])): ?>
                <?php $rp = !empty($r['product_id']) ? find_product($products, $r['product_id']) : null; ?>
                <p class="rev-product"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>
                  <?php if ($rp): ?><a href="/product.php?id=<?= e($rp['id']) ?>"><?= e($r['product_title']) ?></a><?php else: ?><?= e($r['product_title']) ?><?php endif; ?></p>
              <?php endif; ?>
              <figcaption>
                <?php if (!empty($r['avatar'])): ?>
                  <img src="<?= e(nh_url($r['avatar'])) ?>" alt="" width="44" height="44" loading="lazy">
                <?php else: ?>
                  <span class="rev-avatar" aria-hidden="true"><?= e(mb_substr($name, 0, 1, 'UTF-8')) ?></span>
                <?php endif; ?>
                <span><strong><?= e($name) ?></strong><?php if (!empty($r['location'])): ?><small><?= e($r['location']) ?></small><?php endif; ?></span>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
        <button type="button" class="rail-btn rail-next" data-rail-next aria-label="More reviews"><i class="fa-solid fa-chevron-right"></i></button>
      </div>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>

  <!-- Shop: filters + grid (works without JS through query params; site.js filters instantly) -->
  <section class="section shop<?= $isListing ? ' shop--listing' : '' ?>" id="shop" data-shop data-page-size="12">
    <div class="container">
      <?php if ($isListing): ?>
        <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span><?= e($listTitle) ?></span></nav>
      <?php endif; ?>
      <div class="shop-head">
        <div>
          <?php if ($isListing): ?><h1 class="shop-title"><?= e($listTitle) ?></h1><?php else: ?><h2 class="shop-title">Explore the Shop</h2><?php endif; ?>
          <p class="shop-count"><b data-shop-count><?= $matchCount ?></b> product<?= $matchCount === 1 ? '' : 's' ?> found</p>
        </div>
        <div class="shop-tools">
          <button type="button" class="btn btn-ghost btn-sm shop-filter-btn" data-open-panel="shopForm" aria-controls="shopForm"><i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters</button>
          <label class="sort-select"><span>Sort by</span>
            <select name="sort" form="shopForm" data-shop-sort onchange="if(!window.NHShop)this.form.submit()">
              <?php foreach ($sorts as $k => $label): ?><option value="<?= e($k) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
      </div>

      <div class="shop-layout">
        <form class="shop-filters panel-el" id="shopForm" method="get" action="/#shop" data-shop-form aria-label="Product filters" tabindex="-1">
          <div class="filters-head">
            <h3><i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters</h3>
            <button type="button" class="icon-btn" data-close-panel aria-label="Close filters"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="filters-body">
            <div class="fgroup">
              <label class="fgroup-title" for="fQ">Search</label>
              <div class="fsearch"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" id="fQ" name="q" value="<?= e($q) ?>" placeholder="Product name…" data-shop-q></div>
            </div>
            <?php if ($categories): ?>
            <fieldset class="fgroup">
              <legend class="fgroup-title">Category</legend>
              <label class="fopt"><input type="radio" name="category" value="" <?= $catSlug === '' ? 'checked' : '' ?> data-shop-cat><span>All products</span><small><?= count($products) ?></small></label>
              <?php foreach ($categories as $c): ?>
                <label class="fopt"><input type="radio" name="category" value="<?= e($c['slug']) ?>" <?= $catSlug === $c['slug'] ? 'checked' : '' ?> data-shop-cat><span><?= e($c['name']) ?></span><small><?= (int)$c['count'] ?></small></label>
              <?php endforeach; ?>
            </fieldset>
            <?php endif; ?>
            <fieldset class="fgroup">
              <legend class="fgroup-title">Price (<?= e($SITE['currency_symbol']) ?>)</legend>
              <div class="fprice">
                <input type="number" name="min" inputmode="numeric" min="0" step="1" placeholder="<?= $priceFloor ?>" value="<?= $minPrice !== null ? e((int)$minPrice) : '' ?>" aria-label="Minimum price" data-shop-min>
                <span aria-hidden="true">–</span>
                <input type="number" name="max" inputmode="numeric" min="0" step="1" placeholder="<?= $priceCeil ?>" value="<?= $maxPrice !== null ? e((int)$maxPrice) : '' ?>" aria-label="Maximum price" data-shop-max>
              </div>
            </fieldset>
            <fieldset class="fgroup">
              <legend class="fgroup-title">Availability</legend>
              <label class="fswitch"><input type="checkbox" name="instock" value="1" <?= $inStock ? 'checked' : '' ?> data-shop-stock><span class="fswitch-ui" aria-hidden="true"></span><span>In stock only</span></label>
            </fieldset>
          </div>
          <div class="filters-foot">
            <a href="/#shop" class="btn btn-ghost" data-shop-reset>Reset</a>
            <button type="submit" class="btn btn-primary" data-shop-apply>Show results</button>
          </div>
        </form>

        <div class="shop-main">
          <div class="shop-chips" data-shop-chips aria-live="polite"></div>
          <?php if ($products): ?>
            <div class="grid shop-grid" data-shop-grid>
              <?php foreach ($shopList as $p): ?>
                <?php $html = product_card_html($p);
                      if (!$matches($p)) $html = preg_replace('/<article /', '<article hidden ', $html, 1);
                      echo $html; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="empty" data-shop-empty <?= $matchCount ? 'hidden' : '' ?>>
            <i class="fa-solid <?= $products ? 'fa-magnifying-glass' : 'fa-box-open' ?>" aria-hidden="true"></i>
            <h3><?= $products ? 'No products match your filters' : 'No products yet' ?></h3>
            <p><?= $products ? 'Try a different search, widen the price range or clear the filters.' : 'Products you add will appear here.' ?></p>
            <?php if ($products): ?><a href="/#shop" class="btn btn-primary" data-shop-reset>Clear all filters</a><?php endif; ?>
          </div>
          <div class="shop-more" data-shop-more-wrap hidden>
            <p data-shop-progress></p>
            <div class="shop-progress" aria-hidden="true"><span data-shop-bar></span></div>
            <button type="button" class="btn btn-outline" data-shop-more>Load more products</button>
          </div>
        </div>
      </div>
    </div>
  </section>

<?php if (!$isListing && (!empty($SITE['whatsapp']) || !empty($SITE['facebook_url']))): ?>
  <section class="section">
    <div class="container">
      <div class="community">
        <div>
          <h2>Join the <?= e($SITE['brand_name']) ?> community</h2>
          <p>Get new arrivals, seasonal harvests and exclusive offers first.</p>
        </div>
        <div class="community-ctas">
          <?php if (!empty($SITE['whatsapp'])): ?><a class="btn btn-wa btn-lg" href="https://wa.me/<?= e($SITE['whatsapp']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Chat on WhatsApp</a><?php endif; ?>
          <?php if (!empty($SITE['facebook_url'])): ?><a class="btn btn-fb btn-lg" href="<?= e($SITE['facebook_url']) ?>" target="_blank" rel="noopener"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i> Follow on Facebook</a><?php endif; ?>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>
</main>
<?php render_footer($products);
