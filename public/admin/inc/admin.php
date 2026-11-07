<?php
/**
 * Admin bootstrap + page chrome. Every admin page starts with:
 *
 *   require __DIR__ . '/inc/admin.php';
 *   require_perm('orders.view');            // or require_login()
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_check(); ...; flash('Saved'); redirect(...); }
 *   admin_header('Orders', 'orders', ['actions' => '<a class="btn btn-primary" ...>']);
 *   ... page HTML using the shared classes in /assets/css/admin.css ...
 *   admin_footer();
 */
require_once __DIR__ . '/../../inc/bootstrap.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// Admin dates (today's sales, charts) follow the store's local time. Stored
// timestamps carry their own offset, so this only affects display/bucketing.
@date_default_timezone_set((string)($SITE['timezone'] ?? 'Asia/Dhaka'));

/** Build a URL to the current page with some query params changed (null removes). */
function admin_qs(array $changes = [], ?string $page = null): string {
    $q = array_replace($_GET, $changes);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== []);
    $path = $page ?? strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    return $path . ($q ? '?' . http_build_query($q) : '');
}

/** Slice a list for the current ?page=. Returns [rows, page, pages, total, per]. */
function admin_paginate(array $rows, int $per = 25): array {
    $total = count($rows);
    $pages = max(1, (int)ceil($total / $per));
    $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    return [array_slice(array_values($rows), ($page - 1) * $per, $per), $page, $pages, $total, $per];
}

/** Pagination bar HTML (keeps the current filters). */
function admin_pagination(int $page, int $pages, int $total, int $per): string {
    if ($total === 0) return '';
    $from = ($page - 1) * $per + 1;
    $to = min($total, $page * $per);
    $h = '<nav class="pagination" aria-label="Pagination"><span>Showing <strong>' . $from . '–' . $to . '</strong> of <strong>' . $total . '</strong></span>';
    if ($pages > 1) {
        $h .= '<div class="pages">';
        $h .= '<a class="' . ($page <= 1 ? 'is-disabled' : '') . '" href="' . e(admin_qs(['page' => $page - 1])) . '" aria-label="Previous"><i class="fa-solid fa-chevron-left"></i></a>';
        $shown = [];
        foreach ([1, $page - 1, $page, $page + 1, $pages] as $n) if ($n >= 1 && $n <= $pages) $shown[$n] = true;
        ksort($shown);
        $prev = 0;
        foreach (array_keys($shown) as $n) {
            if ($prev && $n - $prev > 1) $h .= '<span class="page">…</span>';
            $h .= '<a class="' . ($n === $page ? 'is-active' : '') . '" href="' . e(admin_qs(['page' => $n])) . '">' . $n . '</a>';
            $prev = $n;
        }
        $h .= '<a class="' . ($page >= $pages ? 'is-disabled' : '') . '" href="' . e(admin_qs(['page' => $page + 1])) . '" aria-label="Next"><i class="fa-solid fa-chevron-right"></i></a>';
        $h .= '</div>';
    }
    return $h . '</nav>';
}

/** Human date for admin tables. */
function admin_date($ts, string $fmt = 'd M Y, g:i a'): string {
    $t = is_numeric($ts) ? (int)$ts : strtotime((string)$ts);
    return $t ? date($fmt, $t) : '';
}

/* ---------------------------------------------------------------------------
 * Order helpers used by dashboard, reports, orders list, detail and export.
 * ------------------------------------------------------------------------- */

/** Statuses that do not count as revenue. */
function order_excluded_statuses(): array { return ['cancelled', 'returned']; }

function order_counts_as_sale(array $o): bool {
    return !in_array(order_status_key($o['status'] ?? ''), order_excluded_statuses(), true);
}

function order_ts(array $o): int { return (int)strtotime((string)($o['created_at'] ?? '')) ?: 0; }

/** All orders, newest first. */
function admin_orders_sorted(): array {
    $all = store_all('orders');
    usort($all, fn($a, $b) => order_ts($b) <=> order_ts($a));
    return $all;
}

/** Normalised filters from the query string (shared by list + CSV export). */
function admin_order_filters(?array $src = null): array {
    $src = $src ?? $_GET;
    $f = [
        'q'       => trim((string)($src['q'] ?? '')),
        'status'  => (string)($src['status'] ?? ''),
        'payment' => (string)($src['payment'] ?? ''),
        'source'  => (string)($src['source'] ?? ''),
        'from'    => (string)($src['from'] ?? ''),
        'to'      => (string)($src['to'] ?? ''),
        'assigned'=> (string)($src['assigned'] ?? ''),
    ];
    foreach (['from', 'to'] as $k) if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
    if ($f['status'] !== '' && !isset(order_statuses()[$f['status']])) $f['status'] = '';
    return $f;
}

function order_matches(array $o, array $f, bool $ignoreStatus = false): bool {
    if (!$ignoreStatus && $f['status'] !== '' && order_status_key($o['status'] ?? '') !== $f['status']) return false;
    if ($f['payment'] !== '' && ($o['payment_status'] ?? 'unpaid') !== $f['payment']) return false;
    if ($f['source'] !== '' && ($o['source'] ?? 'website') !== $f['source']) return false;
    if (($f['assigned'] ?? '') !== '') {
        $a = (string)($o['assigned_to'] ?? '');
        if ($f['assigned'] === 'none' ? $a !== '' : $a !== $f['assigned']) return false;
    }
    $t = order_ts($o);
    if ($f['from'] !== '' && $t < strtotime($f['from'] . ' 00:00:00')) return false;
    if ($f['to'] !== '' && $t > strtotime($f['to'] . ' 23:59:59')) return false;
    if ($f['q'] !== '') {
        $q = mb_strtolower($f['q']);
        $digits = preg_replace('/\D/', '', $f['q']);
        $hay = mb_strtolower(implode(' ', [$o['id'] ?? '', $o['order_id'] ?? '', $o['name'] ?? '', $o['email'] ?? '', $o['tracking_number'] ?? '']));
        $phone = preg_replace('/\D/', '', (string)($o['phone'] ?? ''));
        if (!str_contains($hay, $q) && !($digits !== '' && strlen($digits) >= 3 && str_contains($phone, $digits))) return false;
    }
    return true;
}

/** Payment status labels. */
function payment_statuses(): array {
    return ['unpaid' => ['Unpaid', 'amber'], 'paid' => ['Paid', 'green'], 'refunded' => ['Refunded', 'gray']];
}

function payment_badge($ps): string {
    $all = payment_statuses();
    [$label, $tone] = $all[$ps] ?? $all['unpaid'];
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

function order_sources(): array { return ['website' => 'Website', 'admin' => 'Admin', 'phone' => 'Phone']; }

/** Products whose stock is a tracked number. */
function product_tracks_stock(?array $p): bool {
    return $p && isset($p['stock']) && $p['stock'] !== '' && $p['stock'] !== null && is_numeric($p['stock']);
}

/** Add ($dir = +1) or remove ($dir = -1) an order's items from tracked stock. */
function order_adjust_stock(array $order, int $dir): void {
    foreach ($order['items'] ?? [] as $it) {
        $p = store_get('products', $it['id'] ?? '');
        if (!product_tracks_stock($p)) continue;
        $left = max(0, (int)$p['stock'] + $dir * (int)($it['quantity'] ?? 0));
        store_patch('products', $p['id'], ['stock' => $left, 'in_stock' => $left > 0]);
    }
}

/**
 * Move an order to a new status: appends history and keeps stock consistent.
 * Cancelled/returned orders put their items back in stock once (flag
 * `restocked`); moving back to an active status takes them out again.
 * Returns the saved order (unchanged if nothing changed).
 */
function order_set_status(array $order, string $newKey, string $note = '', ?string $by = null): array {
    if (!isset(order_statuses()[$newKey])) return $order;
    $old = order_status_key($order['status'] ?? '');
    $by = $by ?? (current_user()['name'] ?? 'Admin');
    if ($old === $newKey && $note === '') return $order;

    $excluded = order_excluded_statuses();
    $restocked = !empty($order['restocked']);
    if (in_array($newKey, $excluded, true) && !$restocked) {
        order_adjust_stock($order, +1);
        $order['restocked'] = true;
    } elseif (!in_array($newKey, $excluded, true) && $restocked) {
        order_adjust_stock($order, -1);
        $order['restocked'] = false;
    }

    $history = is_array($order['history'] ?? null) ? $order['history'] : [];
    $history[] = ['status' => $newKey, 'at' => date('c'), 'by' => $by, 'note' => $note];
    $order['history'] = $history;
    $order['status'] = $newKey;
    if ($newKey === 'delivered' && empty($order['delivered_at'])) $order['delivered_at'] = date('c');
    return store_put('orders', $order);
}

/** Staff members orders can be assigned to. */
function admin_assignable_users(): array {
    $list = array_values(array_filter(store_all('users'), fn($u) => !empty($u['active'])));
    usort($list, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
    return $list;
}

/** Sidebar entries: key => [label, icon, url, permission or null, group]. */
function admin_nav(): array {
    return [
        'dashboard'  => ['Dashboard',    'fa-gauge-high',        'index.php',      'dashboard.view',   'Overview'],
        'reports'    => ['Reports',      'fa-chart-line',        'reports.php',    'reports.view',     'Overview'],
        'orders'     => ['Orders',       'fa-bag-shopping',      'orders.php',     'orders.view',      'Sales'],
        'customers'  => ['Customers',    'fa-users',             'customers.php',  'customers.view',   'Sales'],
        'coupons'    => ['Coupons',      'fa-ticket',            'coupons.php',    'coupons.manage',   'Sales'],
        'products'   => ['Products',     'fa-jar',               'products.php',   'products.view',    'Catalogue'],
        'categories' => ['Categories',   'fa-layer-group',       'categories.php', 'categories.manage','Catalogue'],
        'inventory'  => ['Inventory',    'fa-boxes-stacked',     'inventory.php',  'inventory.manage', 'Catalogue'],
        'reviews'    => ['Reviews',      'fa-star',              'reviews.php',    'reviews.manage',   'Catalogue'],
        'staff'      => ['Employees',    'fa-user-shield',       'staff.php',      'staff.manage',     'Store'],
        'settings'   => ['Settings',     'fa-sliders',           'settings.php',   'settings.manage',  'Store'],
        'activity'   => ['Activity log', 'fa-clock-rotate-left', 'activity.php',   'activity.view',    'Store'],
    ];
}

/** Escaped badge for an order status key. */
function status_badge($status): string {
    $key = order_status_key($status);
    $s = order_statuses()[$key];
    return '<span class="badge badge-' . e($s['tone']) . '">' . e($s['label']) . '</span>';
}

function admin_header(string $title, string $active = '', array $opts = []): void {
    global $SITE;
    $u = current_user();
    $flash = take_flash();
    $groups = [];
    foreach (admin_nav() as $key => [$label, $icon, $url, $perm, $group]) {
        if ($perm && !can($perm)) continue;
        $groups[$group][$key] = [$label, $icon, $url];
    }
    $pending = 0;
    if (can('orders.view')) {
        foreach (store_all('orders') as $o) if (order_status_key($o['status'] ?? '') === 'pending') $pending++;
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> · <?= e($SITE['brand_name']) ?> Admin</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22><rect width=%2232%22 height=%2232%22 rx=%228%22 fill=%22%230F2A28%22/><path d=%22M16 6c-4.6 6.2-7.5 10.5-7.5 14.4a7.5 7.5 0 0 0 15 0C23.5 16.5 20.6 12.2 16 6z%22 fill=%22%23F28C28%22/></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/admin.css?v=2">
</head>
<body class="admin">
<div class="admin-shell">
  <aside class="sidebar" id="sidebar">
    <a class="sidebar-brand" href="<?= admin_url() ?>">
      <span class="sb-logo"><i class="fa-solid fa-droplet"></i></span>
      <span><strong><?= e($SITE['brand_name']) ?></strong><small>Admin panel</small></span>
    </a>
    <nav class="sidebar-nav">
      <?php foreach ($groups as $group => $items): ?>
        <div class="nav-group"><?= e($group) ?></div>
        <?php foreach ($items as $key => [$label, $icon, $url]): ?>
          <a href="<?= admin_url($url) ?>" class="nav-link <?= $active === $key ? 'is-active' : '' ?>">
            <i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span>
            <?php if ($key === 'orders' && $pending): ?><b class="nav-count"><?= $pending ?></b><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <a class="sidebar-store" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> View store</a>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>

  <div class="admin-main">
    <header class="topbar-admin">
      <button class="icon-btn sidebar-toggle" type="button" data-sidebar-toggle aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="topbar-actions">
        <?= $opts['actions'] ?? '' ?>
        <?php if ($u): ?>
        <div class="user-menu">
          <button type="button" class="user-chip" data-dropdown>
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'] ?? '?', 0, 1))) ?></span>
            <span class="user-meta"><strong><?= e($u['name'] ?? '') ?></strong><small><?= e(ROLES[$u['role']] ?? '') ?></small></span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="dropdown" hidden>
            <a href="<?= admin_url('profile.php') ?>"><i class="fa-solid fa-user"></i> My profile</a>
            <a href="<?= admin_url('logout.php') ?>"><i class="fa-solid fa-right-from-bracket"></i> Sign out</a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </header>
    <main class="admin-content">
      <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" data-autohide><?= e($flash['message']) ?></div>
      <?php endif; ?>
<?php
}

function admin_footer(): void {
    ?>
    </main>
  </div>
</div>
<script src="/assets/js/admin.js?v=2"></script>
</body>
</html>
<?php
}
