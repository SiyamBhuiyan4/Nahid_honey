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
  <link rel="stylesheet" href="/assets/css/admin.css?v=1">
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
<script src="/assets/js/admin.js?v=1"></script>
</body>
</html>
<?php
}
