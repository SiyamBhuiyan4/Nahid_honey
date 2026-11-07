<?php
/** My profile — any signed-in user edits their own details and password. */
require __DIR__ . '/inc/admin.php';
$me = require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name === '' || mb_strlen($name) > 80) $errors['name'] = 'Enter your name.';
        if ($phone !== '' && !preg_match('/^\+?[0-9 \-]{6,20}$/', $phone)) $errors['phone'] = 'Enter a valid phone number.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        $emailChanged = $email !== strtolower($me['email'] ?? '');
        if (!$errors && $emailChanged) {
            foreach (store_all('users') as $u) {
                if ($u['id'] !== $me['id'] && strtolower($u['email'] ?? '') === $email) { $errors['email'] = 'Another account already uses this email.'; break; }
            }
            if (!isset($errors['email']) && !password_verify((string)($_POST['current_password_email'] ?? ''), $me['password_hash'] ?? '')) {
                $errors['current_password_email'] = 'Enter your current password to change your sign-in email.';
            }
        }
        if (!$errors) {
            store_patch('users', $me['id'], ['name' => $name, 'phone' => $phone, 'email' => $email]);
            log_activity('profile.update', 'Updated own profile' . ($emailChanged ? ' (sign-in email changed)' : ''));
            flash('Your profile has been updated.');
            redirect(admin_url('profile.php'));
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['new_password_confirm'] ?? '');
        if (!password_verify($current, $me['password_hash'] ?? '')) $errors['current_password'] = 'Your current password is incorrect.';
        if (strlen($new) < 8) $errors['new_password'] = 'Use at least 8 characters.';
        elseif ($new !== $confirm) $errors['new_password_confirm'] = 'The passwords do not match.';
        elseif ($new === $current) $errors['new_password'] = 'Choose a password different from the current one.';
        if (!$errors) {
            $updated = store_patch('users', $me['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT)]);
            login_user($updated, true); // fingerprint changed: re-issue this device's cookie, other devices are signed out
            log_activity('profile.password', 'Changed own password');
            flash('Password changed. Other devices have been signed out.');
            redirect(admin_url('profile.php'));
        }
    }
}

$err = fn($k) => isset($errors[$k]) ? '<div class="error">' . e($errors[$k]) . '</div>' : '';
$val = fn($k, $fallback) => e($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'details' ? ($_POST[$k] ?? '') : $fallback);
$perms = effective_permissions($me);
$labels = [];
foreach (permission_groups() as $g => $items) foreach ($items as $k => $l) $labels[$k] = [$g, $l];

admin_header('My profile', '');
?>
<?php if ($errors): ?><div class="alert alert-error">Please fix the highlighted fields.</div><?php endif; ?>
<div class="grid-2">
  <div class="stack">
    <form class="card" method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="details">
      <div class="card-header"><h2>Your details</h2></div>
      <div class="card-body form-grid">
        <div class="field span-2"><label for="name">Name</label><input id="name" name="name" value="<?= $val('name', $me['name'] ?? '') ?>" required><?= $err('name') ?></div>
        <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= $val('phone', $me['phone'] ?? '') ?>" inputmode="tel"><?= $err('phone') ?></div>
        <div class="field"><label for="email">Sign-in email</label><input id="email" type="email" name="email" value="<?= $val('email', $me['email'] ?? '') ?>" required><?= $err('email') ?></div>
        <div class="field span-2"><label for="cpe">Current password <span class="muted">(only needed if you change your email)</span></label><input id="cpe" type="password" name="current_password_email" autocomplete="current-password"><?= $err('current_password_email') ?></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save details</button></div>
    </form>

    <form class="card" method="post" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="card-header"><h2>Change password</h2></div>
      <div class="card-body form-grid">
        <div class="field span-2"><label for="cp">Current password</label><input id="cp" type="password" name="current_password" autocomplete="current-password" required><?= $err('current_password') ?></div>
        <div class="field"><label for="np">New password</label><input id="np" type="password" name="new_password" minlength="8" autocomplete="new-password" required><div class="hint">At least 8 characters.</div><?= $err('new_password') ?></div>
        <div class="field"><label for="np2">Confirm new password</label><input id="np2" type="password" name="new_password_confirm" minlength="8" autocomplete="new-password" required><?= $err('new_password_confirm') ?></div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-key"></i> Update password</button><span class="muted">Changing your password signs you out on other devices.</span></div>
    </form>
  </div>

  <div class="card">
    <div class="card-header"><h2>Your access</h2></div>
    <div class="card-body">
      <dl class="kv">
        <dt>Role</dt><dd><span class="badge badge-<?= ($me['role'] ?? '') === 'staff' ? 'blue' : 'indigo' ?>"><?= e(role_label($me['role'] ?? '')) ?></span></dd>
        <dt>Last sign-in</dt><dd><?= !empty($me['last_login']) ? e(date('d M Y, h:i A', strtotime($me['last_login']))) : '—' ?></dd>
      </dl>
      <p class="muted"><?= e(role_descriptions()[$me['role'] ?? 'staff'] ?? '') ?></p>
      <?php if (($me['role'] ?? '') === 'staff'): ?>
        <?php if (!$perms): ?>
          <div class="empty-state"><i class="fa-solid fa-lock"></i><p>No permissions have been assigned to you yet. Ask an administrator.</p></div>
        <?php else: ?>
          <div class="chips"><?php foreach ($perms as $p): ?><span class="chip" title="<?= e($labels[$p][0] ?? '') ?>"><?= e($labels[$p][1] ?? $p) ?></span> <?php endforeach; ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="alert alert-info"><i class="fa-solid fa-shield-halved"></i> Full access to every section.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<style>.chips{display:flex;flex-wrap:wrap;gap:6px}</style>
<?php admin_footer();
