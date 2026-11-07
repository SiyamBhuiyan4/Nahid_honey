<?php
/** Activity log — who did what, newest first. */
require __DIR__ . '/inc/admin.php';
require_perm('activity.view');

$all = store_all('activity');
usort($all, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')) ?: strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? '')));

$userNames = array_values(array_unique(array_filter(array_map(fn($a) => (string)($a['user_name'] ?? ''), $all))));
sort($userNames, SORT_NATURAL | SORT_FLAG_CASE);
$actionGroups = array_values(array_unique(array_filter(array_map(fn($a) => explode('.', (string)($a['action'] ?? ''))[0], $all))));
sort($actionGroups);
$actions = array_values(array_unique(array_filter(array_map(fn($a) => (string)($a['action'] ?? ''), $all))));
sort($actions);

$fUser = (string)($_GET['user'] ?? '');
$fAction = (string)($_GET['action'] ?? '');
$fFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$fTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$q = trim((string)($_GET['q'] ?? ''));

$list = array_values(array_filter($all, function ($a) use ($fUser, $fAction, $fFrom, $fTo, $q) {
    if ($fUser !== '' && ($a['user_name'] ?? '') !== $fUser) return false;
    if ($fAction !== '') {
        $act = (string)($a['action'] ?? '');
        if ($act !== $fAction && !str_starts_with($act, $fAction . '.')) return false;
    }
    $day = substr(date('Y-m-d', strtotime($a['created_at'] ?? 'now')), 0, 10);
    if ($fFrom !== '' && $day < $fFrom) return false;
    if ($fTo !== '' && $day > $fTo) return false;
    if ($q !== '' && mb_stripos(($a['details'] ?? '') . ' ' . ($a['action'] ?? '') . ' ' . ($a['ip'] ?? ''), $q) === false) return false;
    return true;
}));

$perPage = 50;
$pages = max(1, (int)ceil(count($list) / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$rows = array_slice($list, ($page - 1) * $perPage, $perPage);
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_replace(['user' => $fUser, 'action' => $fAction, 'from' => $fFrom, 'to' => $fTo, 'q' => $q, 'page' => $page], $over), fn($v) => $v !== '' && $v !== null));

$tone = function (string $action): string {
    if (preg_match('/(delete|failed|block|disable)/', $action)) return 'red';
    if (preg_match('/(create|setup|enable)/', $action)) return 'green';
    if (str_starts_with($action, 'auth.')) return 'gray';
    if (str_starts_with($action, 'order')) return 'blue';
    if (str_starts_with($action, 'settings')) return 'amber';
    return 'indigo';
};

admin_header('Activity log', 'activity');
?>
<div class="card">
  <form class="toolbar" method="get">
    <select name="user" aria-label="User">
      <option value="">All users</option>
      <?php foreach ($userNames as $n): ?><option value="<?= e($n) ?>" <?= $fUser === $n ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
    </select>
    <select name="action" aria-label="Action">
      <option value="">All actions</option>
      <?php foreach ($actionGroups as $g): ?>
        <option value="<?= e($g) ?>" <?= $fAction === $g ? 'selected' : '' ?>><?= e(ucfirst($g)) ?> (all)</option>
        <?php foreach ($actions as $act): if (!str_starts_with($act, $g . '.')) continue; ?>
          <option value="<?= e($act) ?>" <?= $fAction === $act ? 'selected' : '' ?>>&nbsp;&nbsp;<?= e($act) ?></option>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($fFrom) ?>" aria-label="From date">
    <input type="date" name="to" value="<?= e($fTo) ?>" aria-label="To date">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search details">
    <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
    <?php if ($fUser || $fAction || $fFrom || $fTo || $q !== ''): ?><a class="btn btn-ghost" href="<?= admin_url('activity.php') ?>">Clear</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i><h3>No activity found</h3><p>Actions taken in the admin panel are recorded here.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): $act = (string)($a['action'] ?? ''); ?>
      <tr>
        <td style="white-space:nowrap"><?= !empty($a['created_at']) ? e(date('d M Y, h:i A', strtotime($a['created_at']))) : '—' ?></td>
        <td><?= e($a['user_name'] ?? 'System') ?></td>
        <td><span class="badge badge-<?= $tone($act) ?>"><?= e($act) ?></span></td>
        <td><?= e($a['details'] ?? '') ?></td>
        <td class="muted"><?= e($a['ip'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-footer muted"><?= count($list) ?> entr<?= count($list) === 1 ? 'y' : 'ies' ?></div>
  <?php if ($pages > 1): ?>
    <nav class="pagination">
      <?php if ($page > 1): ?><a href="<?= e($qs(['page' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= e($qs(['page' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?>
    </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php admin_footer();
