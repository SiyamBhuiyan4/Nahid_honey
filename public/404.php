<?php
/** Not-found page (the router in api/index.php sends unknown paths here). */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

if (http_response_code() !== 404) http_response_code(404);
header('X-Robots-Tag: noindex');
$products = all_products();

render_header('Page not found', $products);
?>
<main class="container" style="padding:64px 16px;text-align:center;min-height:45vh">
  <p style="font-size:56px;line-height:1;margin:0;opacity:.35;font-weight:700">404</p>
  <h1 style="margin:12px 0 8px">We couldn’t find that page</h1>
  <p style="opacity:.75;margin:0 0 24px">The link may be old or mistyped. Try the shop or search for a product.</p>
  <p style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
    <a class="btn btn-primary" href="/">Go to the shop</a>
    <a class="btn btn-outline" href="/track.php">Track an order</a>
  </p>
</main>
<?php
render_footer($products);
