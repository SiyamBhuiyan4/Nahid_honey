<?php
/** Add / edit an employee and choose exactly what they can do. */
require __DIR__ . '/inc/admin.php';
require_perm('staff.manage');
$me = current_user();

$id = (string)($_GET['id'] ?? '');
$user = $id !== '' ? store_get('users', $id) : null;
if ($id !== '' && !$user) { flash('That employee no longer exists.', 'error'); redirect(admin_url('staff.php')); }
if ($user && $user['id'] === $me['id']) redirect(admin_url('profile.php'));
if ($user && !can_manage_user($me, $user)) {
    flash(($user['role'] ?? '') === 'owner' ? 'The owner account can only be changed by the owner.' : 'Only the owner can change administrator accounts.', 'error');
    redirect(admin_url('staff.php'));
}
$isNew = !$user;
$roles = assignable_roles($me);
$grantable = grantable_permissions($me);

$form = $user ?? ['name' => '', 'email' => '', 'phone' => '', 'role' => 'staff', 'active' => true, 'permissions' => [], 'notes' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form['name'] = trim((string)($_POST['name'] ?? ''));
    $form['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
    $form['phone'] = trim((string)($_POST['phone'] ?? ''));
    $form['role'] = (string)($_POST['role'] ?? 'staff');
    $form['active'] = !empty($_POST['active']);
    $form['notes'] = trim((string)($_POST['notes'] ?? ''));
    $posted = array_values(array_intersect(all_permissions(), (array)($_POST['permissions'] ?? [])));
    $password = (string)($_POST['password'] ?? '');

    if ($form['name'] === '' || mb_strlen($form['name']) > 80) $errors['name'] = 'Enter a name.';
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    else foreach (store_all('users') as $u) {
        if ($u['id'] !== ($user['id'] ?? '') && strtolower($u['email'] ?? '') === $form['email']) { $errors['email'] = 'Another account already uses this email.'; break; }
    }
    if ($form['phone'] !== '' && !preg_match('/^\+?[0-9 \-]{6,20}$/', $form['phone'])) $errors['phone'] = 'Enter a valid phone number.';
    if (!in_array($form['role'], $roles, true)) $errors['role'] = 'You are not allowed to assign this role.';
    if ($isNew && $password === '') $errors['password'] = 'Set a password (or generate one).';
    if ($password !== '' && strlen($password) < 8) $errors['password'] = 'Use at least 8 characters.';

    // Only grant what the current user may grant; keep anything else they cannot see unchanged.
    $kept = array_diff((array)($user['permissions'] ?? []), $grantable);
    $form['permissions'] = $form['role'] === 'admin'
        ? array_values((array)($user['permissions'] ?? [])) // admins have full access; keep the old list in case they are moved back to Employee
        : array_values(array_unique(array_merge($kept, array_intersect($posted, $grantable))));

    if (!$errors) {
        $doc = $user ?? ['id' => bin2hex(random_bytes(8)), 'created_by' => $me['id'], 'last_login' => null];
        $before = $user;
        $doc = array_replace($doc, [
            'name' => $form['name'], 'email' => $form['email'], 'phone' => $form['phone'],
            'role' => $form['role'], 'active' => $form['active'],
            'permissions' => $form['permissions'], 'notes' => $form['notes'],
        ]);
        if ($password !== '') $doc['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        store_put('users', $doc);

        if ($isNew) {
            log_activity('staff.create', 'Added ' . role_label($doc['role']) . ' ' . $doc['name'] . ' (' . $doc['email'] . ')', ['user_id' => $doc['id'], 'permissions' => $doc['permissions']]);
            flash($doc['name'] . ' has been added. Share the sign-in email and password with them securely.');
        } else {
            $changes = [];
            foreach (['name', 'email', 'phone', 'role'] as $k) if (($before[$k] ?? '') !== $doc[$k]) $changes[] = $k;
            if (!empty($before['active']) !== $doc['active']) $changes[] = $doc['active'] ? 'enabled' : 'disabled';
            $added = array_diff($doc['permissions'], (array)($before['permissions'] ?? []));
            $removed = array_diff((array)($before['permissions'] ?? []), $doc['permissions']);
            if ($added || $removed) $changes[] = 'permissions';
            if ($password !== '') $changes[] = 'password reset';
            log_activity('staff.update', 'Updated ' . $doc['name'] . ($changes ? ': ' . implode(', ', $changes) : ''), [
                'user_id' => $doc['id'], 'added' => array_values($added), 'removed' => array_values($removed),
            ]);
            flash($doc['name'] . ' has been updated.' . ($password !== '' ? ' Their new password is active and old sessions were signed out.' : ''));
        }
        redirect(admin_url('staff.php'));
    }
}

$checked = array_flip((array)$form['permissions']);
$grantSet = array_flip($grantable);
$presets = array_map(fn($p) => array_values(array_intersect($p, $grantable)), permission_presets());

admin_header($isNew ? 'Add employee' : 'Edit employee', 'staff', ['actions' => '<a class="btn btn-ghost" href="' . admin_url('staff.php') . '"><i class="fa-solid fa-arrow-left"></i> All employees</a>']);
$err = fn($k) => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
?>
<?php if ($errors): ?><div class="alert alert-error">Please fix the highlighted fields.</div><?php endif; ?>
<form method="post" novalidate id="staff-form">
  <?= csrf_field() ?>
  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-header"><h2>Account</h2></div>
        <div class="card-body form-grid">
          <div class="field"><label for="name">Full name</label><input id="name" name="name" value="<?= e($form['name']) ?>" required><?= $err('name') ?></div>
          <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= e($form['phone']) ?>" inputmode="tel"><?= $err('phone') ?></div>
          <div class="field span-2"><label for="email">Sign-in email</label><input id="email" type="email" name="email" value="<?= e($form['email']) ?>" autocomplete="off" required><?= $err('email') ?></div>
          <div class="field">
            <label for="role">Role</label>
            <select id="role" name="role">
              <?php foreach ($roles as $r): ?><option value="<?= e($r) ?>" <?= $form['role'] === $r ? 'selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?>
            </select>
            <?php if (!in_array('admin', $roles, true)): ?><div class="hint">Only the owner can create administrators.</div><?php endif; ?>
            <?= $err('role') ?>
          </div>
          <div class="field">
            <label>Status</label>
            <label class="switch"><input type="checkbox" name="active" value="1" <?= !empty($form['active']) ? 'checked' : '' ?>><span></span></label>
            <div class="hint">Disabled accounts are signed out and cannot sign in.</div>
          </div>
          <div class="field span-2">
            <label for="password"><?= $isNew ? 'Password' : 'Reset password' ?></label>
            <div class="input-group">
              <input id="password" type="text" name="password" minlength="8" autocomplete="new-password" placeholder="<?= $isNew ? 'At least 8 characters' : 'Leave empty to keep the current password' ?>">
              <button type="button" class="btn btn-secondary" id="gen-pass"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate</button>
              <button type="button" class="btn btn-ghost" id="copy-pass" title="Copy"><i class="fa-regular fa-copy"></i></button>
            </div>
            <div class="hint">Copy the password before saving and share it privately. <?= $isNew ? '' : 'Resetting signs them out everywhere.' ?></div>
            <?= $err('password') ?>
          </div>
          <div class="field span-2"><label for="notes">Internal notes</label><textarea id="notes" name="notes" rows="2" placeholder="Optional — e.g. shift, responsibilities"><?= e($form['notes'] ?? '') ?></textarea></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h2>Permissions</h2>
          <span class="muted" id="perm-count"></span>
        </div>
        <div class="card-body">
          <div class="alert alert-info" id="admin-note" hidden><i class="fa-solid fa-shield-halved"></i> <strong>Full access.</strong> Administrators can do everything in the admin panel, so individual permissions don’t apply.</div>
          <div id="perm-area">
            <div class="preset-row">
              <span class="muted">Quick fill:</span>
              <?php foreach ($presets as $label => $perms): ?>
                <button type="button" class="chip" data-preset='<?= e(json_encode($perms)) ?>'><?= e($label) ?></button>
              <?php endforeach; ?>
              <button type="button" class="chip" data-preset='<?= e(json_encode(array_values($grantable))) ?>'>Everything</button>
              <button type="button" class="chip" data-preset='[]'>Clear</button>
            </div>
            <div class="perm-groups">
            <?php foreach (permission_groups() as $group => $items): ?>
              <fieldset class="perm-group">
                <legend>
                  <span><?= e($group) ?></span>
                  <label class="check small"><input type="checkbox" class="group-all"> Select all</label>
                </legend>
                <div class="checkbox-list">
                  <?php foreach ($items as $key => $label): $allowed = isset($grantSet[$key]); ?>
                    <label class="check<?= $allowed ? '' : ' is-locked' ?>" <?= $allowed ? '' : 'title="You don’t have this permission yourself, so you can’t change it."' ?>>
                      <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" <?= isset($checked[$key]) ? 'checked' : '' ?> <?= $allowed ? '' : 'disabled data-locked' ?>>
                      <span><?= e($label) ?> <code class="muted"><?= e($key) ?></code></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <aside class="stack">
      <div class="card">
        <div class="card-header"><h2>Roles</h2></div>
        <div class="card-body">
          <?php foreach (['admin', 'staff'] as $r): ?>
            <p><strong><?= e(role_label($r)) ?>:</strong> <span class="muted"><?= e(role_descriptions()[$r]) ?></span></p>
          <?php endforeach; ?>
        </div>
      </div>
      <?php if ($user): ?>
      <div class="card">
        <div class="card-body">
          <dl class="kv">
            <dt>Created</dt><dd><?= !empty($user['created_at']) ? e(date('d M Y', strtotime($user['created_at']))) : '—' ?></dd>
            <dt>Last sign-in</dt><dd><?= !empty($user['last_login']) ? e(date('d M Y, h:i A', strtotime($user['last_login']))) : 'Never' ?></dd>
          </dl>
        </div>
      </div>
      <?php endif; ?>
      <div class="card"><div class="card-body">
        <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $isNew ? 'Create employee' : 'Save changes' ?></button>
        <a class="btn btn-ghost btn-block" href="<?= admin_url('staff.php') ?>">Cancel</a>
      </div></div>
    </aside>
  </div>
</form>

<style>
  .preset-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:16px}
  .preset-row .chip{cursor:pointer;border:1px solid var(--border,#e5e7eb)}
  .perm-groups{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
  .perm-group{border:1px solid var(--border,#e5e7eb);border-radius:12px;padding:12px 14px;margin:0;min-width:0}
  .perm-group legend{display:flex;justify-content:space-between;align-items:center;gap:10px;width:100%;padding:0 4px;font-weight:600}
  .perm-group .check{display:flex;gap:8px;align-items:flex-start;padding:4px 0}
  .perm-group code{font-size:11px;display:block}
  .perm-group .small{font-weight:400;font-size:12px}
  .is-locked{opacity:.55}
  #perm-area.is-disabled{opacity:.45;pointer-events:none}
</style>
<script>
(function () {
  var form = document.getElementById('staff-form');
  var role = document.getElementById('role');
  var area = document.getElementById('perm-area');
  var note = document.getElementById('admin-note');
  var countEl = document.getElementById('perm-count');
  var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('input[name="permissions[]"]')); };

  function sync() {
    var isAdmin = role.value === 'admin';
    note.hidden = !isAdmin;
    area.classList.toggle('is-disabled', isAdmin);
    boxes().forEach(function (b) { if (!b.hasAttribute('data-locked')) b.disabled = isAdmin; });
    form.querySelectorAll('.perm-group').forEach(function (g) {
      var items = g.querySelectorAll('input[name="permissions[]"]');
      var on = g.querySelectorAll('input[name="permissions[]"]:checked').length;
      var all = g.querySelector('.group-all');
      all.checked = on === items.length; all.indeterminate = on > 0 && on < items.length;
      all.disabled = isAdmin;
    });
    var n = boxes().filter(function (b) { return b.checked; }).length;
    countEl.textContent = isAdmin ? 'Full access' : n + ' of ' + boxes().length + ' selected';
  }
  form.querySelectorAll('.group-all').forEach(function (all) {
    all.addEventListener('change', function () {
      all.closest('.perm-group').querySelectorAll('input[name="permissions[]"]:not([data-locked])').forEach(function (b) { b.checked = all.checked; });
      sync();
    });
  });
  form.querySelectorAll('[data-preset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var want = JSON.parse(btn.getAttribute('data-preset'));
      boxes().forEach(function (b) { if (!b.hasAttribute('data-locked')) b.checked = want.indexOf(b.value) !== -1; });
      sync();
    });
  });
  form.addEventListener('change', function (e) { if (e.target.name === 'permissions[]' || e.target === role) sync(); });

  var pw = document.getElementById('password');
  document.getElementById('gen-pass').addEventListener('click', function () {
    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789', out = '', rnd = new Uint32Array(12);
    crypto.getRandomValues(rnd);
    for (var i = 0; i < rnd.length; i++) out += chars[rnd[i] % chars.length];
    pw.value = out; pw.focus(); pw.select();
  });
  document.getElementById('copy-pass').addEventListener('click', function () {
    if (!pw.value) return;
    var done = function () { if (window.adminToast) window.adminToast('Password copied'); };
    if (navigator.clipboard) navigator.clipboard.writeText(pw.value).then(done, function () { pw.select(); document.execCommand('copy'); done(); });
    else { pw.select(); document.execCommand('copy'); done(); }
  });
  sync();
})();
</script>
<?php admin_footer();
