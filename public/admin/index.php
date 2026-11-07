<?php
require __DIR__ . '/inc/admin.php';
require_perm('dashboard.view');

$u = current_user();
$seeMoney  = can('reports.view');
$seeOrders = can('orders.view');
$seeStock  = can('inventory.manage') || can('products.view');

$orders = admin_orders_sorted();
$now = time();
$todayStart = strtotime('today');

/** Revenue/orders over a window [start, end). */
$window = function (int $start, int $end) use ($orders): array {
    $rev = 0.0; $n = 0; $all = 0;
    foreach ($orders as $o) {
        $t = order_ts($o);
        if ($t < $start || $t >= $end) continue;
        $all++;
        if (!order_counts_as_sale($o)) continue;
        $n++; $rev += (float)($o['total'] ?? 0);
    }
    return ['revenue' => $rev, 'orders' => $n, 'all' => $all, 'aov' => $n ? $rev / $n : 0];
};
$delta = function (float $cur, float $prev): string {
    if ($prev <= 0) return $cur > 0 ? '<span class="stat-delta up"><i class="fa-solid fa-arrow-trend-up"></i> New</span>' : '<span class="stat-delta">—</span>';
    $pct = ($cur - $prev) / $prev * 100;
    $cls = $pct >= 0 ? 'up' : 'down';
    $ico = $pct >= 0 ? 'fa-arrow-up' : 'fa-arrow-down';
    return '<span class="stat-delta ' . $cls . '"><i class="fa-solid ' . $ico . '"></i> ' . number_format(abs($pct), 0) . '%</span>';
};

$tomorrow = strtotime('tomorrow');
$today = $window($todayStart, $tomorrow);
$yday  = $window(strtotime('yesterday'), $todayStart);
$d7    = $window(strtotime('-6 days', $todayStart), $tomorrow);
$p7    = $window(strtotime('-13 days', $todayStart), strtotime('-6 days', $todayStart));
$d30   = $window(strtotime('-29 days', $todayStart), $tomorrow);
$p30   = $window(strtotime('-59 days', $todayStart), strtotime('-29 days', $todayStart));

// Status counts
$statusCounts = array_fill_keys(array_keys(order_statuses()), 0);
foreach ($orders as $o) $statusCounts[order_status_key($o['status'] ?? '')]++;
$pending = $statusCounts['pending'];
$toShip = $statusCounts['confirmed'] + $statusCounts['processing'];

// Daily series for 30 days
$series = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days", $todayStart));
    $series[$d] = ['revenue' => 0.0, 'orders' => 0];
}
$top = [];
foreach ($orders as $o) {
    $t = order_ts($o);
    if ($t < strtotime('-29 days', $todayStart) || !order_counts_as_sale($o)) continue;
    $d = date('Y-m-d', $t);
    if (isset($series[$d])) { $series[$d]['revenue'] += (float)($o['total'] ?? 0); $series[$d]['orders']++; }
    foreach ($o['items'] ?? [] as $it) {
        $k = (string)($it['id'] ?? $it['title'] ?? '');
        $top[$k] ??= ['title' => $it['title'] ?? '', 'unit' => $it['unit'] ?? '', 'image' => $it['image'] ?? '', 'qty' => 0, 'revenue' => 0.0];
        $top[$k]['qty'] += (int)($it['quantity'] ?? 0);
        $top[$k]['revenue'] += (float)($it['line_total'] ?? ((float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0)));
    }
}
uasort($top, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
$top = array_slice($top, 0, 5, true);
// Use the current product image when an order line has none
$productsById = [];
foreach (store_all('products') as $p) $productsById[(string)$p['id']] = $p;
foreach ($top as $k => &$t) {
    if ($t['image'] === '' && isset($productsById[$k])) $t['image'] = normalize_product($productsById[$k])['image'];
}
unset($t);

// Low stock
$lowStock = [];
$threshold = (int)($SITE['low_stock_threshold'] ?? setting('low_stock_threshold', 5));
foreach ($productsById as $p) {
    if (product_tracks_stock($p) && (int)$p['stock'] <= $threshold) $lowStock[] = $p;
}
usort($lowStock, fn($a, $b) => (int)$a['stock'] <=> (int)$b['stock']);

$recent = array_slice($orders, 0, 8);
$hour = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$actions = '';
if (can('orders.create')) $actions .= '<a class="btn btn-primary" href="' . admin_url('order-new.php') . '"><i class="fa-solid fa-plus"></i><span class="label">New order</span></a>';
admin_header('Dashboard', 'dashboard', ['actions' => $actions]);
?>
<div class="split">
  <div>
    <h2 style="font-size:20px;font-weight:650;letter-spacing:-.02em"><?= e($greet) ?>, <?= e(strtok((string)($u['name'] ?? ''), ' ')) ?></h2>
    <p class="muted" style="margin:2px 0 0">Here’s what’s happening at <?= e($SITE['brand_name']) ?> today, <?= e(date('l, j F')) ?>.</p>
  </div>
  <?php if ($seeOrders && $pending): ?>
    <a class="chip" href="<?= admin_url('orders.php?status=pending') ?>"><i class="fa-solid fa-bell"></i> <?= $pending ?> order<?= $pending === 1 ? '' : 's' ?> waiting for confirmation</a>
  <?php endif; ?>
</div>

<div class="grid-4">
  <?php if ($seeMoney): ?>
    <div class="stat">
      <span class="stat-icon"><i class="fa-solid fa-bangladeshi-taka-sign"></i></span>
      <span class="stat-label">Revenue today</span>
      <span class="stat-value"><?= money($today['revenue']) ?></span>
      <span class="stat-sub"><?= $delta($today['revenue'], $yday['revenue']) ?> vs yesterday</span>
    </div>
    <div class="stat">
      <span class="stat-icon teal"><i class="fa-solid fa-calendar-week"></i></span>
      <span class="stat-label">Last 7 days</span>
      <span class="stat-value"><?= money($d7['revenue']) ?></span>
      <span class="stat-sub"><?= $delta($d7['revenue'], $p7['revenue']) ?> vs previous 7 days</span>
    </div>
    <div class="stat">
      <span class="stat-icon blue"><i class="fa-solid fa-calendar"></i></span>
      <span class="stat-label">Last 30 days</span>
      <span class="stat-value"><?= money($d30['revenue']) ?></span>
      <span class="stat-sub"><?= $delta($d30['revenue'], $p30['revenue']) ?> vs previous 30 days</span>
    </div>
    <div class="stat">
      <span class="stat-icon indigo"><i class="fa-solid fa-receipt"></i></span>
      <span class="stat-label">Avg. order value (30d)</span>
      <span class="stat-value"><?= money($d30['aov']) ?></span>
      <span class="stat-sub"><?= $delta($d30['aov'], $p30['aov']) ?> vs previous 30 days</span>
    </div>
  <?php endif; ?>
  <?php if ($seeOrders): ?>
    <a class="stat" href="<?= admin_url('orders.php?from=' . date('Y-m-d')) ?>">
      <span class="stat-icon green"><i class="fa-solid fa-bag-shopping"></i></span>
      <span class="stat-label">Orders today</span>
      <span class="stat-value"><?= number_format($today['all']) ?></span>
      <span class="stat-sub"><?= number_format($d7['all']) ?> in the last 7 days</span>
    </a>
    <a class="stat" href="<?= admin_url('orders.php?status=pending') ?>">
      <span class="stat-icon amber"><i class="fa-solid fa-hourglass-half"></i></span>
      <span class="stat-label">Pending orders</span>
      <span class="stat-value"><?= number_format($pending) ?></span>
      <span class="stat-sub">Need confirmation</span>
    </a>
    <a class="stat" href="<?= admin_url('orders.php?status=processing') ?>">
      <span class="stat-icon blue"><i class="fa-solid fa-box"></i></span>
      <span class="stat-label">To pack &amp; ship</span>
      <span class="stat-value"><?= number_format($toShip) ?></span>
      <span class="stat-sub">Confirmed or processing</span>
    </a>
  <?php endif; ?>
  <?php if ($seeStock): ?>
    <a class="stat" href="<?= admin_url(can('inventory.manage') ? 'inventory.php' : 'products.php') ?>">
      <span class="stat-icon red"><i class="fa-solid fa-triangle-exclamation"></i></span>
      <span class="stat-label">Low stock</span>
      <span class="stat-value"><?= count($lowStock) ?></span>
      <span class="stat-sub">At or below <?= $threshold ?> units</span>
    </a>
  <?php endif; ?>
</div>

<?php if ($seeMoney): ?>
<?php
  $max = max(1, max(array_column($series, 'revenue')));
  // round axis max up to a "nice" number
  $mag = 10 ** floor(log10($max));
  $niceMax = ceil($max / $mag) * $mag;
  $W = 900; $H = 240; $padL = 0; $padT = 8;
  $plotW = $W; $plotH = $H - $padT;
  $n = count($series); $slot = $plotW / $n; $bw = max(4, $slot * 0.62);
  $sum30 = array_sum(array_column($series, 'revenue'));
?>
<div class="card">
  <div class="card-header">
    <div>
      <h2><i class="fa-solid fa-chart-column"></i> Sales — last 30 days</h2>
      <p class="muted"><?= money($sum30) ?> from <?= number_format(array_sum(array_column($series, 'orders'))) ?> orders · cancelled &amp; returned excluded</p>
    </div>
    <a class="btn btn-sm" href="<?= admin_url('reports.php') ?>">Full report <i class="fa-solid fa-arrow-right"></i></a>
  </div>
  <div class="card-body">
    <div class="chart-box" id="salesChart">
      <div class="chart-axis-y">
        <?php for ($g = 4; $g >= 0; $g--): ?><span style="top:<?= ($padT + $plotH - $plotH * $g / 4) / $H * 100 ?>%"><?= e(money($niceMax * $g / 4)) ?></span><?php endfor; ?>
      </div>
      <div class="chart-plot">
      <svg class="chart-svg" viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="none" role="img" aria-label="Daily revenue for the last 30 days">
        <?php for ($g = 0; $g <= 4; $g++): $y = $padT + $plotH - $plotH * $g / 4; ?>
          <line x1="<?= $padL ?>" x2="<?= $W ?>" y1="<?= $y ?>" y2="<?= $y ?>" stroke="#ECE8DF" stroke-width="1" vector-effect="non-scaling-stroke"/>
        <?php endfor; ?>
        <?php $i = 0; foreach ($series as $d => $s):
          $h = $s['revenue'] / $niceMax * $plotH;
          $x = $padL + $i * $slot + ($slot - $bw) / 2;
          $y = $padT + $plotH - $h; ?>
          <g class="bar" data-tip="<?= e(date('D, j M', strtotime($d))) ?>|<?= e(money($s['revenue'])) ?>|<?= (int)$s['orders'] ?> order<?= $s['orders'] == 1 ? '' : 's' ?>">
            <rect x="<?= $padL + $i * $slot ?>" y="<?= $padT ?>" width="<?= $slot ?>" height="<?= $plotH ?>" fill="transparent"/>
            <?php if ($h > 0): ?><rect x="<?= round($x, 2) ?>" y="<?= round($y, 2) ?>" width="<?= round($bw, 2) ?>" height="<?= round($h, 2) ?>" rx="3" fill="<?= $d === date('Y-m-d') ? '#F28C28' : '#F5A75A' ?>"/><?php endif; ?>
          </g>
        <?php $i++; endforeach; ?>
        <line x1="<?= $padL ?>" x2="<?= $W ?>" y1="<?= $padT + $plotH ?>" y2="<?= $padT + $plotH ?>" stroke="#D6D0C3" vector-effect="non-scaling-stroke"/>
      </svg>
      <div class="chart-axis-x">
        <?php $i = 0; foreach ($series as $d => $s): if ($i % 5 === 4 || $i === 0): ?>
          <span style="left:<?= ($padL + $i * $slot + $slot / 2) / $W * 100 ?>%"><?= e(date('j M', strtotime($d))) ?></span>
        <?php endif; $i++; endforeach; ?>
      </div>
      </div>
      <div class="chart-tip" hidden></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid-2">
  <?php if ($seeOrders): ?>
  <div class="card">
    <div class="card-header"><h2><i class="fa-solid fa-chart-pie"></i> Orders by status</h2><span class="muted small"><?= count($orders) ?> total</span></div>
    <div class="card-body">
      <div class="bar-list">
        <?php $tot = max(1, count($orders)); foreach (order_statuses() as $k => $s): $c = $statusCounts[$k]; ?>
          <a class="bar-row" href="<?= admin_url('orders.php?status=' . $k) ?>" style="color:inherit">
            <span><?= status_badge($k) ?></span>
            <span class="strong"><?= number_format($c) ?> <span class="muted small" style="font-weight:400">· <?= round($c / $tot * 100) ?>%</span></span>
            <span class="progress"><span class="tone-<?= e($s['tone']) ?>" style="width:<?= $c / $tot * 100 ?>%"></span></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($seeMoney): ?>
  <div class="card">
    <div class="card-header"><h2><i class="fa-solid fa-trophy"></i> Top products (30 days)</h2></div>
    <?php if ($top): ?>
      <ul class="list-plain">
        <?php $rank = 0; foreach ($top as $t): $rank++; ?>
          <li class="list-row">
            <span class="muted strong" style="width:16px"><?= $rank ?></span>
            <?php if ($t['image']): ?><img class="thumb" src="<?= e($t['image']) ?>" alt="" loading="lazy"><?php endif; ?>
            <div class="grow"><strong><?= e($t['title']) ?></strong><small><?= e($t['unit']) ?> · <?= number_format($t['qty']) ?> sold</small></div>
            <strong class="nowrap"><?= money($t['revenue']) ?></strong>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="empty-state"><i class="fa-solid fa-jar"></i><h2>No sales yet</h2><p>Best sellers from the last 30 days will appear here.</p></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($seeStock && $lowStock): ?>
  <div class="card">
    <div class="card-header"><h2><i class="fa-solid fa-boxes-stacked"></i> Low stock</h2>
      <?php if (can('inventory.manage')): ?><a class="btn btn-sm" href="<?= admin_url('inventory.php') ?>">Manage stock</a><?php endif; ?></div>
    <ul class="list-plain">
      <?php foreach (array_slice($lowStock, 0, 6) as $p): $np = normalize_product($p); ?>
        <li class="list-row">
          <img class="thumb" src="<?= e($np['image']) ?>" alt="" loading="lazy">
          <div class="grow"><strong><?= e($np['title']) ?></strong><small><?= e($np['unit']) ?></small></div>
          <span class="badge <?= (int)$p['stock'] === 0 ? 'badge-red' : 'badge-amber' ?>"><?= (int)$p['stock'] === 0 ? 'Out of stock' : (int)$p['stock'] . ' left' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><h2><i class="fa-solid fa-bolt"></i> Quick actions</h2></div>
    <div class="card-body">
      <div class="quick-grid">
        <?php
        $quick = [
          ['orders.create', 'order-new.php', 'fa-phone', 'Phone order', 'Create an order manually'],
          ['orders.view', 'orders.php?status=pending', 'fa-list-check', 'Confirm orders', 'Review pending orders'],
          ['products.edit', 'product-edit.php', 'fa-plus', 'Add product', 'List a new item'],
          ['coupons.manage', 'coupons.php', 'fa-ticket', 'Coupons', 'Create a discount'],
          ['reports.view', 'reports.php', 'fa-chart-line', 'Reports', 'Sales breakdowns'],
          ['settings.manage', 'settings.php', 'fa-sliders', 'Settings', 'Store, delivery & payment'],
        ];
        $shown = 0;
        foreach ($quick as [$perm, $href, $icon, $label, $sub]): if (!can($perm)) continue; $shown++; ?>
          <a class="quick" href="<?= admin_url($href) ?>"><i class="fa-solid <?= $icon ?>"></i><span><strong><?= e($label) ?></strong><small><?= e($sub) ?></small></span></a>
        <?php endforeach; ?>
        <a class="quick" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-store"></i><span><strong>View store</strong><small>Open the storefront</small></span></a>
      </div>
    </div>
  </div>
</div>

<?php if ($seeOrders): ?>
<div class="card">
  <div class="card-header">
    <h2><i class="fa-solid fa-clock"></i> Recent orders</h2>
    <a class="btn btn-sm" href="<?= admin_url('orders.php') ?>">View all <i class="fa-solid fa-arrow-right"></i></a>
  </div>
  <?php if ($recent): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Status</th><th>Payment</th><th class="text-right">Total</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $o): ?>
          <tr data-href="<?= e(admin_url('order.php?id=' . urlencode($o['id']))) ?>" style="cursor:pointer">
            <td><a class="row-link mono" href="<?= e(admin_url('order.php?id=' . urlencode($o['id']))) ?>"><?= e($o['id']) ?></a></td>
            <td><strong><?= e($o['name'] ?? '') ?></strong><small><?= e($o['phone'] ?? '') ?></small></td>
            <td class="nowrap"><?= e(admin_date($o['created_at'] ?? '', 'j M, g:i a')) ?></td>
            <td><?= status_badge($o['status'] ?? '') ?></td>
            <td><?= payment_badge($o['payment_status'] ?? 'unpaid') ?></td>
            <td class="text-right strong nowrap"><?= money($o['total'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-bag-shopping"></i><h2>No orders yet</h2><p>New orders from the website will show up here.</p></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$seeMoney && !$seeOrders && !$seeStock): ?>
  <div class="empty-state"><i class="fa-solid fa-hand-wave"></i><h2>Welcome, <?= e($u['name'] ?? '') ?></h2><p>Use the menu on the left to get to the sections you have access to.</p></div>
<?php endif; ?>

<?php if ($seeMoney): ?>
<script>
(function () {
  var box = document.getElementById('salesChart'); if (!box) return;
  var tip = box.querySelector('.chart-tip');
  box.querySelectorAll('.bar').forEach(function (g) {
    g.addEventListener('mouseenter', function () {
      var p = g.getAttribute('data-tip').split('|');
      tip.innerHTML = '<small></small><strong></strong><span></span>';
      tip.children[0].textContent = p[0]; tip.children[1].textContent = p[1]; tip.children[2].textContent = p[2];
      tip.hidden = false; g.classList.add('is-hover');
    });
    g.addEventListener('mousemove', function (e) {
      var r = box.getBoundingClientRect(), x = e.clientX - r.left;
      tip.style.left = Math.min(Math.max(x, 70), r.width - 70) + 'px';
      tip.style.top = Math.max(0, e.clientY - r.top - 70) + 'px';
    });
    g.addEventListener('mouseleave', function () { tip.hidden = true; g.classList.remove('is-hover'); });
  });
})();
</script>
<?php endif; ?>
<?php admin_footer();
