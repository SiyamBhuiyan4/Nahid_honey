<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

$products = all_products();
$product  = find_product($products, $_GET['id'] ?? '');

if (!$product) {
    http_response_code(404);
    render_header('Product not found', $products);
    ?>
  <main class="section"><div class="container"><div class="empty">
    <i class="fa-solid fa-box-open"></i><h3>Product not found</h3>
    <p>This product may have been removed.</p>
    <a href="index.php#shop" class="btn btn-primary">Browse products</a>
  </div></div></main>
    <?php
    render_footer($products);
    exit;
}

$related = array_slice(array_values(array_filter($products, fn($p) =>
    $p['id'] !== $product['id'] && $p['category_slug'] === $product['category_slug'])), 0, 4);

render_header($product['title'], $products, $product['category_slug']);
?>
<main class="section">
  <div class="container">
    <nav class="crumbs">
      <a href="index.php">Home</a><i class="fa-solid fa-chevron-right"></i>
      <?php if ($product['category'] !== ''): ?><a href="index.php?category=<?= e($product['category_slug']) ?>"><?= e($product['category']) ?></a><i class="fa-solid fa-chevron-right"></i><?php endif; ?>
      <span><?= e($product['title']) ?></span>
    </nav>

    <div class="pd">
      <div class="pd-gallery">
        <div class="pd-main">
          <?php if ($product['badge'] !== ''): ?><span class="ribbon"><i class="fa-solid fa-fire"></i> <?= e($product['badge']) ?></span><?php endif; ?>
          <img src="<?= e($product['images'][0]) ?>" alt="<?= e($product['title']) ?>" id="pdMainImg">
        </div>
        <?php if (count($product['images']) > 1): ?>
          <div class="pd-thumbs">
            <?php foreach ($product['images'] as $i => $img): ?>
              <button type="button" class="<?= $i === 0 ? 'is-active' : '' ?>" data-thumb="<?= e($img) ?>"><img src="<?= e($img) ?>" alt=""></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="pd-info">
        <?php if ($product['category'] !== ''): ?><span class="pcard-cat"><?= e($product['category']) ?></span><?php endif; ?>
        <h1><?= e($product['title']) ?></h1>
        <?php if ($product['rating'] !== null): ?>
          <div class="rating"><i class="fa-solid fa-star"></i> <?= number_format($product['rating'], 1) ?><?php if ($product['reviews_count']): ?> <span>(<?= $product['reviews_count'] ?> reviews)</span><?php endif; ?></div>
        <?php endif; ?>

        <div class="price-row price-row--lg">
          <span class="price"><?= money($product['price']) ?></span>
          <?php if ($product['original_price']): ?><s class="price-old"><?= money($product['original_price']) ?></s><?php endif; ?>
          <?php if ($product['discount_text'] !== ''): ?><span class="save-pill"><?= e($product['discount_text']) ?></span>
          <?php elseif ($product['save_amount'] > 0): ?><span class="save-pill">Save <?= money($product['save_amount']) ?></span><?php endif; ?>
        </div>

        <dl class="pd-meta">
          <?php if ($product['unit'] !== ''): ?><div><dt>Size</dt><dd><?= e($product['unit']) ?></dd></div><?php endif; ?>
          <div><dt>Availability</dt><dd class="<?= $product['in_stock'] ? 'ok' : 'no' ?>"><?= $product['in_stock'] ? 'In stock' : 'Out of stock' ?></dd></div>
        </dl>

        <?php if ($product['in_stock']): ?>
        <div class="pd-buy">
          <div class="qty" data-qty>
            <button type="button" data-qty-dec aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>
            <input type="number" value="1" min="1" max="99" id="pdQty" aria-label="Quantity">
            <button type="button" data-qty-inc aria-label="Increase quantity"><i class="fa-solid fa-plus"></i></button>
          </div>
          <button type="button" class="btn btn-outline btn-lg" data-add-to-cart="<?= e($product['id']) ?>" data-qty-from="#pdQty"><i class="fa-solid fa-cart-plus"></i> Add to Cart</button>
          <button type="button" class="btn btn-primary btn-lg" data-buy-now="<?= e($product['id']) ?>" data-qty-from="#pdQty"><i class="fa-solid fa-bolt"></i> Buy Now</button>
        </div>
        <?php endif; ?>

        <?php if (!empty($SITE['trust_items'])): ?>
        <ul class="pd-trust">
          <?php foreach (array_slice($SITE['trust_items'], 0, 3) as $t): ?>
            <li><i class="fa-solid <?= e($t['icon']) ?>"></i> <?= e($t['title']) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($product['description'] !== '' || $product['features']): ?>
    <section class="pd-details">
      <h2>Product Details</h2>
      <?php if ($product['description'] !== ''): ?><p><?= nl2br(e($product['description'])) ?></p><?php endif; ?>
      <?php if ($product['features']): ?>
        <ul class="checklist"><?php foreach ($product['features'] as $f): ?><li><i class="fa-solid fa-circle-check"></i> <?= e($f) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($related): ?>
    <section class="section-tight">
      <div class="section-head"><h2>You may also like</h2></div>
      <div class="grid"><?php foreach ($related as $p) render_product_card($p); ?></div>
    </section>
    <?php endif; ?>
  </div>
</main>
<?php render_footer($products);
