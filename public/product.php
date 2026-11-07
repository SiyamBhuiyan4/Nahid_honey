<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pages.php';

$products = all_products();
$product  = find_product($products, $_GET['id'] ?? '');

if (!$product) {
    http_response_code(404);
    nh_page_header('Product not found', $products);
    ?>
  <main class="section nh-page"><div class="container"><div class="nh-empty">
    <span class="nh-empty-icon"><i class="fa-solid fa-box-open"></i></span>
    <h1>Product not found</h1>
    <p>This product may have been removed or the link is incorrect.</p>
    <a href="/index.php#shop" class="btn btn-primary">Browse products</a>
  </div></div></main>
    <?php
    nh_page_footer($products);
    exit;
}

$pid      = (string)$product['id'];
$reviews  = product_reviews($pid);
$revCount = count($reviews);
$breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$sum = 0;
foreach ($reviews as $r) {
    $s = max(1, min(5, (int)round((float)($r['rating'] ?? 0))));
    $breakdown[$s]++;
    $sum += $s;
}
$avgFromReviews = $revCount ? $sum / $revCount : null;
$rating      = $product['rating'] ?? $avgFromReviews;
$ratingCount = $product['reviews_count'] ?? $revCount;

$related = array_values(array_filter($products, fn($p) =>
    (string)$p['id'] !== $pid && $product['category_slug'] !== '' && $p['category_slug'] === $product['category_slug']));
if (count($related) < 4) {
    $more = array_filter($products, fn($p) => (string)$p['id'] !== $pid && $p['category_slug'] !== $product['category_slug'] && $p['featured']);
    $related = array_merge($related, array_values($more));
}
$related = array_slice($related, 0, 8);

$stock     = $product['stock'];
$inStock   = $product['in_stock'] && ($stock === null || $stock > 0);
$maxQty    = $stock !== null ? max(1, min(99, $stock)) : 99;
$savePct   = $product['original_price'] ? (int)round(($product['original_price'] - $product['price']) / $product['original_price'] * 100) : 0;
$zones     = (array)($SITE['delivery_zones'] ?? []);
$payments  = (array)($SITE['payment_methods'] ?? []);
$hasCod    = isset($payments['cod']);
$url       = absolute_url('/product.php?id=' . rawurlencode($pid));
$images    = array_values(array_filter($product['images'], fn($i) => !str_starts_with($i, 'data:'))) ?: $product['images'];
$descText  = $product['description'] !== '' ? $product['description'] : implode(' · ', $product['features']);
$metaDesc  = $product['seo_description'] !== '' ? $product['seo_description'] : ($descText !== '' ? $descText : ($product['title'] . ' — ' . $SITE['brand_name']));
$freeOver  = (float)($SITE['free_delivery_threshold'] ?? 0);

$jsonld = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['title'],
    'image' => array_map('absolute_url', array_values(array_filter($images, fn($i) => !str_starts_with($i, 'data:')))),
    'description' => mb_substr($descText, 0, 500, 'UTF-8'),
    'brand' => ['@type' => 'Brand', 'name' => $SITE['brand_name']],
    'offers' => [
        '@type' => 'Offer',
        'url' => $url,
        'priceCurrency' => 'BDT',
        'price' => number_format($product['price'], 2, '.', ''),
        'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'itemCondition' => 'https://schema.org/NewCondition',
    ],
];
if ($product['sku'] !== '') $jsonld['sku'] = $product['sku'];
if ($product['category'] !== '') $jsonld['category'] = $product['category'];
if ($rating !== null && $ratingCount) {
    $jsonld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => round((float)$rating, 1), 'reviewCount' => (int)$ratingCount, 'bestRating' => 5, 'worstRating' => 1];
}
if ($reviews) {
    $jsonld['review'] = array_map(fn($r) => [
        '@type' => 'Review',
        'author' => ['@type' => 'Person', 'name' => (string)($r['customer_name'] ?? 'Customer')],
        'datePublished' => (string)($r['review_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10)),
        'reviewBody' => mb_substr((string)($r['comment'] ?? ''), 0, 500, 'UTF-8'),
        'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int)($r['rating'] ?? 5), 'bestRating' => 5],
    ], array_slice($reviews, 0, 5));
}
$crumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_values(array_filter([
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => absolute_url('/')],
    $product['category'] !== '' ? ['@type' => 'ListItem', 'position' => 2, 'name' => $product['category'], 'item' => absolute_url('/index.php?category=' . $product['category_slug'])] : null,
    ['@type' => 'ListItem', 'position' => $product['category'] !== '' ? 3 : 2, 'name' => $product['title'], 'item' => $url],
]))];

nh_page_header($product['seo_title'] !== '' ? $product['seo_title'] : $product['title'], $products, $product['category_slug'], [
    'description' => $metaDesc,
    'canonical' => $url,
    'og' => [
        'og:type' => 'product',
        'og:site_name' => $SITE['brand_name'],
        'og:title' => $product['title'],
        'og:description' => mb_substr($metaDesc, 0, 200, 'UTF-8'),
        'og:url' => $url,
        'og:image' => absolute_url($images[0] ?? ''),
        'product:price:amount' => number_format($product['price'], 2, '.', ''),
        'product:price:currency' => 'BDT',
        'product:availability' => $inStock ? 'in stock' : 'out of stock',
        'twitter:card' => 'summary_large_image',
    ],
    'jsonld' => [$jsonld, $crumbLd],
    'body_class' => 'page-product',
]);
?>
<main class="nh-page pdp" data-product-id="<?= e($pid) ?>">
  <div class="container">
    <nav class="nh-crumbs" aria-label="Breadcrumb">
      <a href="/">Home</a><i class="fa-solid fa-chevron-right"></i>
      <?php if ($product['category'] !== ''): ?><a href="/index.php?category=<?= e($product['category_slug']) ?>"><?= e($product['category']) ?></a><i class="fa-solid fa-chevron-right"></i><?php endif; ?>
      <span aria-current="page"><?= e($product['title']) ?></span>
    </nav>

    <div class="pdp-grid">
      <!-- Gallery -->
      <section class="pdp-gallery" aria-label="Product images">
        <div class="pdp-stage" data-pdp-stage>
          <?php if ($product['badge'] !== ''): ?><span class="pdp-badge"><?= e($product['badge']) ?></span><?php endif; ?>
          <?php if ($savePct > 0): ?><span class="pdp-off">-<?= $savePct ?>%</span><?php endif; ?>
          <img src="<?= e($images[0]) ?>" alt="<?= e($product['title']) ?>" id="pdpMainImg" fetchpriority="high" draggable="false">
          <?php if (count($images) > 1): ?>
            <button type="button" class="pdp-nav pdp-nav--prev" data-pdp-prev aria-label="Previous image"><i class="fa-solid fa-chevron-left"></i></button>
            <button type="button" class="pdp-nav pdp-nav--next" data-pdp-next aria-label="Next image"><i class="fa-solid fa-chevron-right"></i></button>
          <?php endif; ?>
          <span class="pdp-zoom-hint"><i class="fa-solid fa-magnifying-glass-plus"></i> <span class="hide-touch">Hover to zoom</span><span class="show-touch">Tap to zoom</span></span>
        </div>
        <?php if (count($images) > 1): ?>
          <div class="pdp-thumbs" role="tablist">
            <?php foreach ($images as $i => $img): ?>
              <button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-pdp-thumb="<?= $i ?>" data-src="<?= e($img) ?>" aria-label="Image <?= $i + 1 ?>"><img src="<?= e($img) ?>" alt="" loading="lazy"></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Buy box -->
      <section class="pdp-info">
        <?php if ($product['category'] !== ''): ?><a class="pdp-cat" href="/index.php?category=<?= e($product['category_slug']) ?>"><?= e($product['category']) ?></a><?php endif; ?>
        <h1 class="pdp-title"><?= e($product['title']) ?></h1>

        <div class="pdp-subline">
          <?php if ($rating !== null): ?>
            <a href="#reviews" class="pdp-rating" data-pdp-tab-link="reviews">
              <?= stars_html((float)$rating) ?> <b><?= number_format((float)$rating, 1) ?></b>
              <?php if ($ratingCount): ?><span>(<?= (int)$ratingCount ?> reviews)</span><?php endif; ?>
            </a>
          <?php else: ?>
            <a href="#reviews" class="pdp-rating pdp-rating--none" data-pdp-tab-link="reviews">Be the first to review</a>
          <?php endif; ?>
          <?php if ($product['sku'] !== ''): ?><span class="pdp-sku">SKU: <?= e($product['sku']) ?></span><?php endif; ?>
        </div>

        <div class="pdp-price">
          <span class="pdp-price-now"><?= money($product['price']) ?></span>
          <?php if ($product['original_price']): ?>
            <s class="pdp-price-old"><?= money($product['original_price']) ?></s>
            <span class="pdp-save">Save <?= money($product['save_amount']) ?><?= $savePct > 0 ? ' (' . $savePct . '%)' : '' ?></span>
          <?php endif; ?>
          <?php if ($product['discount_text'] !== ''): ?><span class="pdp-deal"><?= e($product['discount_text']) ?></span><?php endif; ?>
        </div>

        <dl class="pdp-facts">
          <?php if ($product['unit'] !== ''): ?><div><dt>Size</dt><dd><?= e($product['unit']) ?></dd></div><?php endif; ?>
          <div><dt>Availability</dt><dd>
            <?php if (!$inStock): ?><span class="pdp-stock is-out"><i class="fa-solid fa-circle-xmark"></i> Out of stock</span>
            <?php elseif ($stock !== null && $stock <= 10): ?><span class="pdp-stock is-low"><i class="fa-solid fa-fire"></i> Only <?= (int)$stock ?> left — order soon</span>
            <?php else: ?><span class="pdp-stock is-in"><i class="fa-solid fa-circle-check"></i> In stock</span><?php endif; ?>
          </dd></div>
        </dl>

        <?php if ($product['features']): ?>
          <ul class="pdp-highlights">
            <?php foreach (array_slice($product['features'], 0, 4) as $f): ?><li><i class="fa-solid fa-check"></i><span><?= e($f) ?></span></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <div class="pdp-buy" id="pdpBuy">
          <?php if ($inStock): ?>
            <div class="nh-qty" data-pdp-qty data-max="<?= $maxQty ?>">
              <button type="button" data-step="-1" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>
              <input type="number" value="1" min="1" max="<?= $maxQty ?>" inputmode="numeric" id="pdpQty" aria-label="Quantity">
              <button type="button" data-step="1" aria-label="Increase quantity"><i class="fa-solid fa-plus"></i></button>
            </div>
            <button type="button" class="btn btn-outline btn-lg pdp-add" data-pdp-add><i class="fa-solid fa-cart-plus"></i> Add to Cart</button>
            <button type="button" class="btn btn-primary btn-lg pdp-buynow" data-pdp-buy><i class="fa-solid fa-bolt"></i> Buy Now</button>
          <?php else: ?>
            <button type="button" class="btn btn-lg btn-block pdp-soldout" disabled><i class="fa-solid fa-ban"></i> Currently unavailable</button>
          <?php endif; ?>
        </div>

        <div class="pdp-tools">
          <button type="button" class="pdp-tool" data-pdp-wish aria-pressed="false"><i class="fa-regular fa-heart"></i><span>Add to wishlist</span></button>
          <div class="pdp-share">
            <span>Share</span>
            <button type="button" class="pdp-share-btn" data-share="native" aria-label="Share" hidden><i class="fa-solid fa-share-nodes"></i></button>
            <a class="pdp-share-btn" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($url) ?>" target="_blank" rel="noopener" aria-label="Share on Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <a class="pdp-share-btn" href="https://wa.me/?text=<?= rawurlencode($product['title'] . ' ' . $url) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
            <button type="button" class="pdp-share-btn" data-share="copy" data-url="<?= e($url) ?>" aria-label="Copy link"><i class="fa-solid fa-link"></i></button>
          </div>
        </div>

        <?php if ($zones || $hasCod): ?>
        <div class="pdp-delivery">
          <?php if ($zones): ?>
            <div class="pdp-dl-row">
              <span class="pdp-dl-icon"><i class="fa-solid fa-truck-fast"></i></span>
              <div>
                <strong>Delivery charge</strong>
                <ul class="pdp-zones">
                  <?php foreach ($zones as $z): ?>
                    <li><span><?= e($z['label'] ?? '') ?><?php if (!empty($z['eta'])): ?> <small>· <?= e($z['eta']) ?></small><?php endif; ?></span><b><?= (float)($z['fee'] ?? 0) > 0 ? money($z['fee']) : 'Free' ?></b></li>
                  <?php endforeach; ?>
                </ul>
                <?php if ($freeOver > 0): ?><small class="pdp-freeship"><i class="fa-solid fa-gift"></i> Free delivery on orders of <?= money($freeOver) ?> or more</small><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($hasCod): ?>
            <div class="pdp-dl-row">
              <span class="pdp-dl-icon"><i class="fa-solid fa-hand-holding-dollar"></i></span>
              <div><strong><?= e(payment_method_label($payments['cod'], 'Cash on Delivery')) ?> available</strong><small>Pay when the parcel reaches your door.</small></div>
            </div>
          <?php endif; ?>
          <?php if (count($payments) > 1): ?>
            <div class="pdp-dl-row">
              <span class="pdp-dl-icon"><i class="fa-solid fa-wallet"></i></span>
              <div><strong>Payment options</strong><small><?= e(implode(' · ', array_map(fn($v) => payment_method_label($v), $payments))) ?></small></div>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($SITE['trust_items'])): ?>
        <ul class="pdp-trust">
          <?php foreach (array_slice($SITE['trust_items'], 0, 4) as $t): ?>
            <li><i class="fa-solid <?= e($t['icon'] ?? 'fa-circle-check') ?>"></i><span><?= e($t['title'] ?? '') ?></span></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </section>
    </div>

    <!-- Tabs -->
    <section class="pdp-tabs" id="details">
      <div class="pdp-tablist" role="tablist">
        <button type="button" role="tab" class="is-active" aria-selected="true" data-pdp-tab="description">Description</button>
        <button type="button" role="tab" aria-selected="false" data-pdp-tab="reviews" id="reviews">Reviews <span class="pdp-count"><?= $revCount ?></span></button>
      </div>

      <div class="pdp-panel" data-pdp-panel="description" role="tabpanel">
        <?php if ($product['description'] !== ''): ?><div class="pdp-desc"><?= nl2br(e($product['description'])) ?></div><?php endif; ?>
        <?php if ($product['features']): ?>
          <h3 class="pdp-h3">Key features</h3>
          <ul class="pdp-features"><?php foreach ($product['features'] as $f): ?><li><i class="fa-solid fa-circle-check"></i><span><?= e($f) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($product['description'] === '' && !$product['features']): ?><p class="pdp-muted">More details about this product will be added soon.</p><?php endif; ?>
        <dl class="pdp-spec">
          <?php if ($product['category'] !== ''): ?><div><dt>Category</dt><dd><?= e($product['category']) ?></dd></div><?php endif; ?>
          <?php if ($product['unit'] !== ''): ?><div><dt>Size</dt><dd><?= e($product['unit']) ?></dd></div><?php endif; ?>
          <?php if ($product['sku'] !== ''): ?><div><dt>SKU</dt><dd><?= e($product['sku']) ?></dd></div><?php endif; ?>
        </dl>
      </div>

      <div class="pdp-panel" data-pdp-panel="reviews" role="tabpanel" hidden>
        <div class="rv-layout">
          <aside class="rv-summary">
            <?php $shownAvg = $avgFromReviews ?? $rating; ?>
            <div class="rv-score">
              <strong><?= $shownAvg !== null ? number_format((float)$shownAvg, 1) : '–' ?></strong>
              <div><?= stars_html((float)($shownAvg ?? 0)) ?><small><?= $revCount ? 'Based on ' . $revCount . ' review' . ($revCount === 1 ? '' : 's') : 'No reviews yet' ?></small></div>
            </div>
            <ul class="rv-bars">
              <?php foreach ($breakdown as $star => $n): $pct = $revCount ? round($n / $revCount * 100) : 0; ?>
                <li><span><?= $star ?> <i class="fa-solid fa-star"></i></span><span class="rv-bar"><span style="width: <?= $pct ?>%"></span></span><span class="rv-n"><?= $n ?></span></li>
              <?php endforeach; ?>
            </ul>
            <button type="button" class="btn btn-primary btn-block" data-review-open><i class="fa-regular fa-pen-to-square"></i> Write a review</button>
          </aside>

          <div class="rv-main">
            <form class="rv-form" id="reviewForm" hidden novalidate>
              <h3>Write a review</h3>
              <input type="hidden" name="product_id" value="<?= e($pid) ?>">
              <div class="nh-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
              <div class="nh-field">
                <span class="nh-label">Your rating</span>
                <div class="rv-stars-input" role="radiogroup" aria-label="Rating">
                  <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" name="rating" value="<?= $i ?>" id="rvStar<?= $i ?>"><label for="rvStar<?= $i ?>" title="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>"><i class="fa-solid fa-star"></i></label>
                  <?php endfor; ?>
                </div>
              </div>
              <div class="nh-row2">
                <div class="nh-field"><label class="nh-label" for="rvName">Name</label><input id="rvName" name="name" type="text" maxlength="60" autocomplete="name" required></div>
                <div class="nh-field"><label class="nh-label" for="rvLoc">Location <em>(optional)</em></label><input id="rvLoc" name="location" type="text" maxlength="60" placeholder="e.g. Dhaka"></div>
              </div>
              <div class="nh-field"><label class="nh-label" for="rvComment">Your review</label><textarea id="rvComment" name="comment" rows="4" maxlength="1000" required placeholder="What did you like about this product?"></textarea></div>
              <div class="rv-form-actions">
                <button type="submit" class="btn btn-primary">Submit review</button>
                <button type="button" class="btn btn-ghost" data-review-close>Cancel</button>
              </div>
              <p class="nh-fine">Reviews are published after a quick check by our team.</p>
            </form>
            <div class="rv-thanks" id="reviewThanks" hidden><i class="fa-solid fa-circle-check"></i><div><strong>Thank you for your review!</strong><span>It has been submitted for approval and will appear here soon.</span></div></div>

            <?php if ($reviews): ?>
              <ul class="rv-list" id="reviewList">
                <?php foreach ($reviews as $i => $r): $nm = (string)($r['customer_name'] ?? 'Customer'); ?>
                  <li class="rv-item"<?= $i >= 6 ? ' hidden data-rv-more' : '' ?>>
                    <div class="rv-head">
                      <?php if (!empty($r['avatar'])): ?><img class="rv-avatar" src="<?= e($r['avatar']) ?>" alt="" loading="lazy"><?php else: ?><span class="rv-avatar"><?= e(initials($nm)) ?></span><?php endif; ?>
                      <div class="rv-who">
                        <strong><?= e($nm) ?></strong>
                        <small><?= e(implode(' · ', array_filter([(string)($r['location'] ?? ''), nice_date((string)($r['review_date'] ?? $r['created_at'] ?? ''))]))) ?></small>
                      </div>
                      <?= stars_html((float)($r['rating'] ?? 0), 'nh-stars nh-stars--sm') ?>
                    </div>
                    <?php if (!empty($r['verified_purchase'])): ?><span class="rv-verified"><i class="fa-solid fa-circle-check"></i> Verified purchase</span><?php endif; ?>
                    <p class="rv-text"><?= nl2br(e($r['comment'] ?? '')) ?></p>
                  </li>
                <?php endforeach; ?>
              </ul>
              <?php if ($revCount > 6): ?><button type="button" class="btn btn-outline" data-review-more>Show all <?= $revCount ?> reviews</button><?php endif; ?>
            <?php else: ?>
              <div class="rv-empty"><i class="fa-regular fa-comments"></i><p>No reviews yet. Bought this product? Share your experience to help other customers.</p></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <?php if ($related): ?>
    <section class="pdp-section">
      <div class="nh-section-head"><h2>You may also like</h2><?php if ($product['category'] !== ''): ?><a href="/index.php?category=<?= e($product['category_slug']) ?>">View all <i class="fa-solid fa-arrow-right"></i></a><?php endif; ?></div>
      <div class="grid nh-grid"><?php foreach ($related as $p) render_product_card($p); ?></div>
    </section>
    <?php endif; ?>

    <section class="pdp-section" id="recentlyViewed" hidden>
      <div class="nh-section-head"><h2>Recently viewed</h2><button type="button" class="nh-link" data-recent-clear>Clear</button></div>
      <div class="grid nh-grid" data-recent-grid></div>
    </section>
  </div>

  <?php if ($inStock): ?>
  <div class="pdp-sticky" id="pdpSticky" aria-hidden="true">
    <img src="<?= e($images[0]) ?>" alt="">
    <div class="pdp-sticky-info"><strong><?= e($product['title']) ?></strong><span><?= money($product['price']) ?><?php if ($product['original_price']): ?> <s><?= money($product['original_price']) ?></s><?php endif; ?></span></div>
    <button type="button" class="btn btn-outline" data-pdp-add aria-label="Add to cart"><i class="fa-solid fa-cart-plus"></i></button>
    <button type="button" class="btn btn-primary" data-pdp-buy>Buy Now</button>
  </div>
  <?php endif; ?>
</main>
<?php nh_page_footer($products, [
    'page' => 'product',
    'product' => ['id' => $pid, 'title' => $product['title'], 'max' => $maxQty, 'in_stock' => $inStock, 'images' => $images],
]);
