<?php
/** Staff sign-in. */
require __DIR__ . '/inc/admin.php';

if (!has_any_user()) redirect(admin_url('setup.php'));

/** Only same-site admin paths are accepted for ?next= (no open redirects). */
function safe_next(?string $next): ?string {
    $next = (string)$next;
    if ($next === '' || !str_starts_with($next, '/admin/')) return null;
    if (str_contains($next, '//') || str_contains($next, '\\') || str_contains($next, '..') || preg_match('/[\x00-\x1F\x7F]/', $next)) return null;
    $path = parse_url($next, PHP_URL_PATH) ?: '';
    foreach (['login.php', 'logout.php', 'setup.php'] as $skip) if (str_ends_with($path, $skip)) return null;
    return $next;
}

/** Dashboard if allowed, otherwise the first sidebar page the user can open. */
function landing_url(array $u): string {
    if (user_has_permission($u, 'dashboard.view')) return admin_url();
    foreach (admin_nav() as [$label, $icon, $url, $perm]) {
        if (!$perm || user_has_permission($u, $perm)) return admin_url($url);
    }
    return admin_url('profile.php');
}

$next = safe_next($_GET['next'] ?? $_POST['next'] ?? null);
if ($me = current_user()) redirect($next ?? landing_url($me));

$error = '';
$email = '';
$ip = client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if (login_throttled($ip)) {
        http_response_code(429);
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $user = null;
        foreach (store_all('users') as $u) {
            if (strtolower($u['email'] ?? '') === $email && $email !== '') { $user = $u; break; }
        }
        // Verify against a dummy hash when the email is unknown so timing does not leak accounts.
        $hash = $user['password_hash'] ?? password_hash(random_bytes(8), PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash) && $user;

        if ($ok && empty($user['active'])) {
            $error = 'This account has been disabled. Please contact the store owner.';
            login_failed($ip);
        } elseif ($ok) {
            login_cleared($ip);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $user = store_patch('users', $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            }
            login_user($user, $remember);
            log_activity_for($user, 'auth.login', 'Signed in');
            redirect($next ?? landing_url($user));
        } else {
            login_failed($ip);
            log_activity('auth.failed', 'Failed sign-in attempt' . ($email !== '' ? ' for ' . $email : ''));
            $error = login_throttled($ip)
                ? 'Too many failed attempts. Please wait 15 minutes and try again.'
                : 'The email or password you entered is incorrect.';
        }
    }
}
$flash = take_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · <?= e($SITE['brand_name']) ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/admin.css?v=1">
  <style>
    .auth-brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
    .auth-brand .sb-logo{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#0F2A28;color:#F28C28;font-size:20px;flex:none}
    .auth-brand img{max-height:44px;max-width:160px}
    .auth-brand strong{display:block;font-size:18px}
    .auth-brand small{color:#6b7280}
    .auth-card h1{font-size:22px;margin:0 0 4px}
    .auth-card .lead{color:#6b7280;margin:0 0 20px}
    .auth-card form .field{margin-bottom:14px}
    .auth-row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin:4px 0 18px;font-size:14px}
    .pw-wrap{position:relative}.pw-wrap input{padding-right:42px;width:100%}
    .pw-toggle{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;cursor:pointer;color:#6b7280;padding:6px}
    .auth-foot{margin-top:18px;font-size:13px;color:#6b7280;text-align:center}
  </style>
</head>
<body class="admin auth-page">
  <main class="auth-card">
    <div class="auth-brand">
      <?php if (!empty($SITE['logo_url'])): ?>
        <img src="<?= e($SITE['logo_url']) ?>" alt="<?= e($SITE['brand_name']) ?>">
      <?php else: ?>
        <span class="sb-logo"><i class="fa-solid fa-droplet"></i></span>
      <?php endif; ?>
      <span><strong><?= e($SITE['brand_name']) ?></strong><small>Admin panel</small></span>
    </div>
    <h1>Welcome back</h1>
    <p class="lead">Sign in to manage your store.</p>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div><?php endif; ?>

    <form method="post" action="<?= admin_url('login.php') ?>" novalidate>
      <?= csrf_field() ?>
      <?php if ($next): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
      <div class="field">
        <label for="email">Email address</label>
        <input id="email" type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="pw-wrap">
          <input id="password" type="password" name="password" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" aria-label="Show password" onclick="var i=document.getElementById('password');i.type=i.type==='password'?'text':'password';this.firstElementChild.className='fa-solid '+(i.type==='password'?'fa-eye':'fa-eye-slash')"><i class="fa-solid fa-eye"></i></button>
        </div>
      </div>
      <div class="auth-row">
        <label class="check"><input type="checkbox" name="remember" value="1" checked> Keep me signed in</label>
      </div>
      <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-right-to-bracket"></i> Sign in</button>
    </form>
    <p class="auth-foot">Forgot your password? Ask the store owner or an administrator to reset it from <em>Employees</em>.</p>
  </main>
</body>
</html>
