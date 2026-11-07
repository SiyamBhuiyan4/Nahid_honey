<?php
require __DIR__ . '/inc/admin.php';
require_perm('reports.view');

// ---- Date range ----
$presets = [
    'today' => ['Today', date('Y-m-d'), date('Y-m-d')],
    '7d'    => ['Last 7 days', date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    '30d'   => ['Last 30 days', date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    'month' => ['This month', date('Y-m-01'), date('Y-m-d')],
    'lastmonth' => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    '90d'   => ['Last 90 days', date('Y-m-d', strtotime('-89 days')), date('Y-m-d')],
    'year'  => ['This year', date('Y-01-01'), date('Y-m-d')],
];
$range = (string)($_GET['range'] ?? '');
$valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
if (isset($presets[$range])) {
    [, $from, $to] = $presets[$range];
} else {
    $from = $valid($_GET['from'] ?? null) ? $_GET['from'] : $presets['30d'][1];
    $to   = $valid($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
    $range = ($valid($_GET['from'] ?? null) || $valid($_GET['to'] ?? null)) ? 'custom' : '30d';
}
if ($from > $to) [$from, $to] = [$to, $from];
$start = strtotime("$from 00:00:00");
$end = strtotime("$to 23:59:59");
$days = (int)round(($end - $start) / 86400);
// Same-length period just before, for comparison
$pStart = $start - $days * 86400;
$pEnd = $start - 1;

$orders = admin_orders_sorted();
$inRange = array_filter($orders, fn($o) => ($t = order_ts($o)) >= $start && $t <= $end);
$prevRange = array_filter($orders, fn($o) => ($t = order_ts($o)) >= $pStart && $t <= $pEnd);

$sum = function (array $list): array {
    $r = ['revenue' => 0.0, 'orders' => 0, 'items' => 0, 'discount' => 0.0, 'delivery' => 0.0, 'all' => count($list), 'lost' => 0, 'lost_value' => 0.0, 'paid' => 0.0];
    foreach ($list as $o) {
        if (!order_counts_as_sale($o)) { $r['lost']++; $r['lost_value'] += (float)($o['total'] ?? 0); continue; }
        $r['orders']++;
        $r['revenue'] += (float)($o['total'] ?? 0);
        $r['discount'] += (float)($o['discount'] ?? 0);
        $r['delivery'] += (float)($o['delivery_fee'] ?? 0);
        if (($o['payment_status'] ?? '') === 'paid') $r['paid'] += (float)($o['total'] ?? 0);
        foreach ($o['items'] ?? [] as $it) $r['items'] += (int)($it['quantity'] ?? 0);
    }
    $r['aov'] = $r['orders'] ? $r['revenue'] / $r['orders'] : 0;
    return $r;
};
$cur = $sum($inRange);
$prev = $sum($prevRange);
$delta = function (float $c, float $p, bool $inverse = false): string {
    if ($p <= 0) return '<span class="stat-delta">no prior data</span>';
    $pct = ($c - $p) / $p * 100;
    $good = $inverse ? $pct <= 0 : $pct >= 0;
    return '<span class="stat-delta ' . ($good ? 'up' : 'down') . '"><i class="fa-solid ' . ($pct >= 0 ? 'fa-arrow-up' : 'fa-arrow-down') . '"></i> ' . number_format(abs($pct)) . '%</span>';
};

// ---- Breakdowns (sales only) ----
$byDay = []; $byProduct = []; $byZone = []; $byPay = []; $bySource = []; $byStatus = [];
for ($t = $start; $t <= $end; $t += 86400) $byDay[date('Y-m-d', $t)] = ['orders' => 0, 'revenue' => 0.0, 'items' => 0, 'lost' => 0];
foreach ($inRange as $o) {
    $d = date('Y-m-d', order_ts($o));
    $k = order_status_key($o['status'] ?? '');
    $byStatus[$k] = ($byStatus[$k] ?? 0) + 1;
    if (!order_counts_as_sale($o)) { if (isset($byDay[$d])) $byDay[$d]['lost']++; continue; }
    $total = (float)($o['total'] ?? 0);
    if (isset($byDay[$d])) { $byDay[$d]['orders']++; $byDay[$d]['revenue'] += $total; }
    $zone = trim((string)($o['zone'] ?? '')) ?: 'Unknown';
    $byZone[$zone]['orders'] = ($byZone[$zone]['orders'] ?? 0) + 1;
    $byZone[$zone]['revenue'] = ($byZone[$zone]['revenue'] ?? 0) + $total;
    $pm = trim((string)($o['payment_method'] ?? '')) ?: 'Unknown';
    $byPay[$pm]['orders'] = ($byPay[$pm]['orders'] ?? 0) + 1;
    $byPay[$pm]['revenue'] = ($byPay[$pm]['revenue'] ?? 0) + $total;
    $src = order_sources()[$o['source'] ?? 'website'] ?? ucfirst((string)($o['source'] ?? 'website'));
    $bySource[$src]['orders'] = ($bySource[$src]['orders'] ?? 0) + 1;
    $bySource[$src]['revenue'] = ($bySource[$src]['revenue'] ?? 0) + $total;
    foreach ($o['items'] ?? [] as $it) {
        $pk = (string)($it['id'] ?? '') . '|' . ($it['unit'] ?? '');
        $byProduct[$pk] ??= ['title' => (string)($it['title'] ?? ''), 'unit' => (string)($it['unit'] ?? ''), 'qty' => 0, 'revenue' => 0.0, 'orders' => 0];
        $byProduct[$pk]['qty'] += (int)($it['quantity'] ?? 0);
        $byProduct[$pk]['revenue'] += (float)($it['line_total'] ?? ((float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0)));
        $byProduct[$pk]['orders']++;
        if (isset($byDay[$d])) $byDay[$d]['items'] += (int)($it['quantity'] ?? 0);
    }
}
$sortRev = fn($a, $b) => $b['revenue'] <=> $a['revenue'];
uasort($byProduct, $sortRev); uasort($byZone, $sortRev); uasort($byPay, $sortRev); uasort($bySource, $sortRev);
$cancelled = array_values(array_filter($inRange, fn($o) => !order_counts_as_sale($o)));

// ---- CSV download of the by-day / by-product tables ----
if (isset($_GET['download'])) {
    $which = $_GET['download'] === 'products' ? 'products' : 'days';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report-' . $which . '-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($which === 'days') {
        fputcsv($out, ['Date', 'Orders', 'Items', 'Revenue', 'Cancelled/returned'], ',', '"', '');
        foreach ($byDay as $d => $r) fputcsv($out, [$d, $r['orders'], $r['items'], $r['revenue'], $r['lost']], ',', '"', '');
    } else {
        fputcsv($out, ['Product', 'Unit', 'Orders', 'Quantity', 'Revenue'], ',', '"', '');
        foreach ($byProduct as $r) fputcsv($out, [$r['title'], $r['unit'], $r['orders'], $r['qty'], $r['revenue']], ',', '"', '');
    }
    log_activity('report.export', "Downloaded $which report $from to $to");
    exit;
}

$share = fn(float $v, float $t) => $t > 0 ? round($v / $t * 100) : 0;

admin_header('Reports', 'reports');
?>
<form class="card" method="get" action="">
  <div class="toolbar" style="padding:14px 20px">
    <label class="inline"><i class="fa-regular fa-calendar"></i> Period</label>
    <select name="range" data-autosubmit>
      <?php foreach ($presets as $k => [$label]): ?><option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Custom range</option>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="From" onchange="this.form.range.value='custom'">
    <span class="muted">to</span>
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="To" onchange="this.form.range.value='custom'">
    <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
    <span class="spacer"></span>
    <span class="muted small"><?= e(date('j M Y', $start)) ?> – <?= e(date('j M Y', $end)) ?> · compared with the previous <?= $days ?> day<?= $days === 1 ? '' : 's' ?></span>
  </div>
</form>

<div class="grid-4">
  <div class="stat"><span class="stat-icon"><i class="fa-solid fa-bangladeshi-taka-sign"></i></span><span class="stat-label">Revenue</span>
    <span class="stat-value"><?= money($cur['revenue']) ?></span><span class="stat-sub"><?= $delta($cur['revenue'], $prev['revenue']) ?> vs previous period</span></div>
  <div class="stat"><span class="stat-icon teal"><i class="fa-solid fa-bag-shopping"></i></span><span class="stat-label">Orders</span>
    <span class="stat-value"><?= number_format($cur['orders']) ?></span><span class="stat-sub"><?= $delta($cur['orders'], $prev['orders']) ?> · <?= number_format($cur['items']) ?> items sold</span></div>
  <div class="stat"><span class="stat-icon indigo"><i class="fa-solid fa-receipt"></i></span><span class="stat-label">Average order value</span>
    <span class="stat-value"><?= money($cur['aov']) ?></span><span class="stat-sub"><?= $delta($cur['aov'], $prev['aov']) ?> vs previous period</span></div>
  <div class="stat"><span class="stat-icon red"><i class="fa-solid fa-ban"></i></span><span class="stat-label">Cancelled / returned</span>
    <span class="stat-value"><?= number_format($cur['lost']) ?></span><span class="stat-sub"><?= money($cur['lost_value']) ?> · <?= $share($cur['lost'], $cur['all']) ?>% of orders</span></div>
</div>

<div class="grid-3">
  <div class="stat"><span class="stat-label">Collected (marked paid)</span><span class="stat-value" style="font-size:20px"><?= money($cur['paid']) ?></span><span class="stat-sub"><?= money(max(0, $cur['revenue'] - $cur['paid'])) ?> still to collect</span></div>
  <div class="stat"><span class="stat-label">Delivery fees charged</span><span class="stat-value" style="font-size:20px"><?= money($cur['delivery']) ?></span><span class="stat-sub">Included in revenue</span></div>
  <div class="stat"><span class="stat-label">Discounts given</span><span class="stat-value" style="font-size:20px"><?= money($cur['discount']) ?></span><span class="stat-sub">Coupons &amp; manual discounts</span></div>
</div>

<div class="card">
  <div class="card-header">
    <h2><i class="fa-solid fa-calendar-days"></i> Sales by day</h2>
    <a class="btn btn-sm" href="<?= e(admin_qs(['download' => 'days', 'from' => $from, 'to' => $to, 'range' => null])) ?>"><i class="fa-solid fa-download"></i> CSV</a>
  </div>
  <div class="table-wrap" style="max-height:440px">
    <table class="table table-compact">
      <thead><tr><th>Date</th><th class="text-right">Orders</th><th class="text-right">Items</th><th class="text-right">Revenue</th><th style="width:30%"></th><th class="text-right">Cancelled</th></tr></thead>
      <tbody>
        <?php $maxDay = max(1, max(array_column($byDay, 'revenue') ?: [0]));
        foreach (array_reverse($byDay, true) as $d => $r): ?>
          <tr>
            <td class="nowrap"><?= e(date('D, j M Y', strtotime($d))) ?></td>
            <td class="text-right"><?= $r['orders'] ?: '<span class="muted">0</span>' ?></td>
            <td class="text-right"><?= $r['items'] ?: '<span class="muted">0</span>' ?></td>
            <td class="text-right strong nowrap"><?= money($r['revenue']) ?></td>
            <td><div class="progress"><span style="width:<?= $r['revenue'] / $maxDay * 100 ?>%"></span></div></td>
            <td class="text-right"><?= $r['lost'] ? '<span class="text-red">' . $r['lost'] . '</span>' : '<span class="muted">0</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td>Total</td><td class="text-right"><?= $cur['orders'] ?></td><td class="text-right"><?= $cur['items'] ?></td><td class="text-right nowrap"><?= money($cur['revenue']) ?></td><td></td><td class="text-right"><?= $cur['lost'] ?></td></tr></tfoot>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h2><i class="fa-solid fa-jar"></i> Sales by product</h2>
    <?php if ($byProduct): ?><a class="btn btn-sm" href="<?= e(admin_qs(['download' => 'products', 'from' => $from, 'to' => $to, 'range' => null])) ?>"><i class="fa-solid fa-download"></i> CSV</a><?php endif; ?>
  </div>
  <?php if ($byProduct): ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Product</th><th class="text-right">Orders</th><th class="text-right">Qty sold</th><th class="text-right">Revenue</th><th class="text-right">Share</th></tr></thead>
      <tbody>
        <?php $prodTotal = array_sum(array_column($byProduct, 'revenue')); foreach ($byProduct as $r): ?>
          <tr>
            <td><strong><?= e($r['title']) ?></strong><?php if ($r['unit'] !== ''): ?><small><?= e($r['unit']) ?></small><?php endif; ?></td>
            <td class="text-right"><?= $r['orders'] ?></td>
            <td class="text-right"><?= $r['qty'] ?></td>
            <td class="text-right strong nowrap"><?= money($r['revenue']) ?></td>
            <td class="text-right muted"><?= $share($r['revenue'], $prodTotal) ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <div class="empty-state"><i class="fa-solid fa-chart-simple"></i><h2>No sales in this period</h2><p>Try a wider date range.</p></div>
  <?php endif; ?>
</div>

<div class="grid-3">
  <?php foreach (['Delivery zone' => [$byZone, 'fa-truck'], 'Payment method' => [$byPay, 'fa-wallet'], 'Order source' => [$bySource, 'fa-globe']] as $title => [$rows, $icon]): ?>
    <div class="card">
      <div class="card-header"><h2><i class="fa-solid <?= $icon ?>"></i> By <?= e(strtolower($title)) ?></h2></div>
      <?php if ($rows): ?>
        <div class="card-body"><div class="bar-list">
          <?php foreach ($rows as $label => $r): ?>
            <div class="bar-row">
              <span><?= e($label) ?> <span class="muted small">· <?= $r['orders'] ?> order<?= $r['orders'] == 1 ? '' : 's' ?></span></span>
              <span class="strong nowrap"><?= money($r['revenue']) ?></span>
              <span class="progress"><span style="width:<?= $share($r['revenue'], $cur['revenue']) ?>%"></span></span>
            </div>
          <?php endforeach; ?>
        </div></div>
      <?php else: ?>
        <div class="card-body muted">No sales in this period.</div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-header"><h2><i class="fa-solid fa-list-check"></i> Orders by status</h2><span class="muted small"><?= $cur['all'] ?> placed in period</span></div>
    <div class="card-body"><div class="bar-list">
      <?php foreach (order_statuses() as $k => $s): $c = $byStatus[$k] ?? 0; ?>
        <div class="bar-row">
          <span><?= status_badge($k) ?></span><span class="strong"><?= $c ?></span>
          <span class="progress"><span class="tone-<?= e($s['tone']) ?>" style="width:<?= $share($c, $cur['all']) ?>%"></span></span>
        </div>
      <?php endforeach; ?>
    </div></div>
  </div>

  <div class="card">
    <div class="card-header"><h2><i class="fa-solid fa-ban"></i> Cancellations &amp; returns</h2><span class="muted small">Not counted in revenue</span></div>
    <?php if ($cancelled): ?>
      <div class="table-wrap" style="max-height:360px">
        <table class="table table-compact">
          <thead><tr><th>Order</th><th>Customer</th><th>Status</th><th class="text-right">Value</th></tr></thead>
          <tbody>
            <?php foreach ($cancelled as $o): $hist = (array)($o['history'] ?? []); $last = $hist ? (array)end($hist) : []; ?>
              <tr>
                <td><?php if (can('orders.view')): ?><a class="row-link mono" href="<?= e(admin_url('order.php?id=' . urlencode($o['id']))) ?>"><?= e($o['id']) ?></a><?php else: ?><span class="mono"><?= e($o['id']) ?></span><?php endif; ?>
                  <small><?= e(admin_date($o['created_at'] ?? '', 'j M Y')) ?></small></td>
                <td><?= e($o['name'] ?? '') ?><?php if (!empty($last['note'])): ?><small title="<?= e($last['note']) ?>"><?= e(mb_strimwidth($last['note'], 0, 60, '…')) ?></small><?php endif; ?></td>
                <td><?= status_badge($o['status'] ?? '') ?></td>
                <td class="text-right nowrap"><?= money($o['total'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="empty-state"><i class="fa-solid fa-face-smile"></i><h2>No cancellations</h2><p>Nothing was cancelled or returned in this period.</p></div>
    <?php endif; ?>
  </div>
</div>
<?php admin_footer();
