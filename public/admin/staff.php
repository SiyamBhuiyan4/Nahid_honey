<?php
/** Employees — list, enable/disable, delete. */
require __DIR__ . '/inc/admin.php';
require_perm('staff.manage');
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $target = store_get('users', (string)($_POST['id'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if (!$target) {
        flash('That employee no longer exists.', 'error');
    } elseif (!can_manage_user($me, $target)) {
        flash(($target['id'] ?? '') === $me['id'] ? 'You cannot change or remove your own account here.' : 'You are not allowed to change this account.', 'error');
    } elseif ($action === 'toggle') {
        $active = empty($target['active']);
        store_patch('users', $target['id'], ['active' => $active]);
        log_activity($active ? 'staff.enable' : 'staff.disable', ($active ? 'Enabled ' : 'Disabled ') . $target['name'] . ' (' . $target['email'] . ')', ['user_id' => $target['id']]);
        flash($active ? $target['name'] . ' can sign in again.' : $target['name'] . ' has been disabled and signed out.');
    } elseif ($action === 'delete') {
        store_delete('users', $target['id']);
        log_activity('staff.delete', 'Deleted ' . role_label($target['role'] ?? '') . ' ' . $target['name'] . ' (' . $target['email'] . ')', ['user_id' => $target['id']]);
        flash($target['name'] . ' has been deleted.');
    }
    redirect(admin_url('staff.php'));
}

$users = store_all('users');
$rank = ['owner' => 0, 'admin' => 1, 'staff' => 2];
usort($users, fn($a, $b) => (($rank[$a['role'] ?? 'staff'] ?? 3) <=> ($rank[$b['role'] ?? 'staff'] ?? 3)) ?: strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

$q = trim((string)($_GET['q'] ?? ''));
$roleFilter = (string)($_GET['role'] ?? '');
$list = array_filter($users, function ($u) use ($q, $roleFilter) {
    if ($roleFilter !== '' && ($u['role'] ?? '') !== $roleFilter) return false;
    if ($q === '') return true;
    return stripos(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['phone'] ?? ''), $q) !== false;
});

$total = count($users);
$activeCount = count(array_filter($users, fn($u) => !empty($u['active'])));
$adminCount = count(array_filter($users, fn($u) => in_array($u['role'] ?? '', ['owner', 'admin'], true)));
$staffCount = $total - $adminCount;
$allCount = count(all_permissions());

admin_header('Employees', 'staff', ['actions' => '<a class="btn btn-primary" href="' . admin_url('staff-edit.php') . '"><i class="fa-solid fa-user-plus"></i> Add employee</a>']);
?>
<div class="grid-4">
  <div class="stat"><span>Total accounts</span><strong><?= $total ?></strong></div>
  <div class="stat"><span>Active</span><strong><?= $activeCount ?></strong></div>
  <div class="stat"><span>Owner &amp; admins</span><strong><?= $adminCount ?></strong></div>
  <div class="stat"><span>Employees</span><strong><?= $staffCount ?></strong></div>
</div>

<div class="card">
  <form class="toolbar" method="get">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email or phone">
    <select name="role" data-autosubmit>
      <option value="">All roles</option>
      <?php foreach (ROLES as $k => $l): ?><option value="<?= e($k) ?>" <?= $roleFilter === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
  </form>
  <?php if (!$list): ?>
    <div class="empty-state"><i class="fa-solid fa-users"></i><h3>No accounts found</h3><p>Try a different search, or add your first employee.</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Permissions</th><th>Last sign-in</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($list as $u):
          $isSelf = $u['id'] === $me['id'];
          $manage = can_manage_user($me, $u);
          $role = $u['role'] ?? 'staff';
          $pc = count(effective_permissions($u));
      ?>
        <tr>
          <td>
            <strong><?= e($u['name'] ?? '') ?></strong><?php if ($isSelf): ?> <span class="chip">You</span><?php endif; ?>
            <div class="muted"><?= e($u['email'] ?? '') ?><?= !empty($u['phone']) ? ' · ' . e($u['phone']) : '' ?></div>
          </td>
          <td><span class="badge badge-<?= $role === 'owner' ? 'amber' : ($role === 'admin' ? 'indigo' : 'blue') ?>"><?= e(role_label($role)) ?></span></td>
          <td><?= !empty($u['active']) ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">Disabled</span>' ?></td>
          <td><?= $role === 'staff' ? ($pc ? $pc . ' of ' . $allCount : '<span class="muted">None</span>') : '<span class="muted">Full access</span>' ?></td>
          <td><?= !empty($u['last_login']) ? e(date('d M Y, h:i A', strtotime($u['last_login']))) : '<span class="muted">Never</span>' ?></td>
          <td class="text-right">
            <?php if ($isSelf): ?>
              <a class="btn btn-sm btn-ghost" href="<?= admin_url('profile.php') ?>"><i class="fa-solid fa-user"></i> My profile</a>
            <?php elseif ($manage): ?>
              <a class="btn btn-sm btn-secondary" href="<?= admin_url('staff-edit.php?id=' . urlencode($u['id'])) ?>"><i class="fa-solid fa-pen"></i> Edit</a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>"><input type="hidden" name="action" value="toggle">
                <?php if (!empty($u['active'])): ?>
                  <button class="btn btn-sm btn-ghost" type="submit" data-confirm="Disable <?= e($u['name']) ?>? They will be signed out immediately and cannot sign in until re-enabled."><i class="fa-solid fa-user-slash"></i> Disable</button>
                <?php else: ?>
                  <button class="btn btn-sm btn-success" type="submit"><i class="fa-solid fa-user-check"></i> Enable</button>
                <?php endif; ?>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($u['id']) ?>"><input type="hidden" name="action" value="delete">
                <button class="btn btn-sm btn-danger" type="submit" data-confirm="Delete <?= e($u['name']) ?> permanently? This cannot be undone."><i class="fa-solid fa-trash"></i></button>
              </form>
            <?php else: ?>
              <span class="muted" title="<?= $role === 'owner' ? 'The owner account cannot be changed by others.' : 'Only the owner can change administrators.' ?>"><i class="fa-solid fa-lock"></i> Protected</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><h2>How roles work</h2></div>
  <div class="card-body grid-3">
    <?php foreach (role_descriptions() as $k => $d): ?>
      <div>
        <h3><i class="fa-solid <?= $k === 'owner' ? 'fa-crown' : ($k === 'admin' ? 'fa-shield-halved' : 'fa-user') ?>"></i> <?= e(role_label($k)) ?></h3>
        <p class="muted"><?= e($d) ?></p>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card-footer muted">Employees with “Manage employees” permission (but not admin) can only manage employees whose access is within their own, and can only grant permissions they have themselves.</div>
</div>
<?php admin_footer();
