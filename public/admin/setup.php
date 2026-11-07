<?php
/** First-run setup: creates the Owner account. Disabled once any user exists. */
require __DIR__ . '/inc/admin.php';

if (has_any_user()) redirect(admin_url('login.php'));

// On a public deploy, set SETUP_TOKEN so only someone with the link can create the owner.
$setupToken = (string)getenv('SETUP_TOKEN');
if ($setupToken !== '' && !hash_equals($setupToken, (string)($_GET['token'] ?? $_POST['setup_token'] ?? ''))) {
    http_response_code(403);
    exit('Setup link required. Use the setup link you were given, or run: php bin/create-admin.php');
}

$errors = [];
$name = $email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if ($name === '' || mb_strlen($name) > 80) $errors['name'] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (strlen($pass) < 8) $errors['password'] = 'Use at least 8 characters.';
    elseif ($pass !== $confirm) $errors['password_confirm'] = 'The passwords do not match.';

    if (!$errors) {
        if (has_any_user()) redirect(admin_url('login.php')); // someone finished setup meanwhile
        $owner = store_put('users', [
            'id' => bin2hex(random_bytes(8)),
            'name' => $name,
            'email' => $email,
            'phone' => '',
            'role' => 'owner',
            'permissions' => [],
            'active' => true,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'last_login' => null,
            'created_by' => null,
            'notes' => '',
        ]);
        login_user($owner, true);
        log_activity_for($owner, 'auth.setup', 'Created the owner account');
        flash('Welcome! Your owner account is ready.');
        redirect(admin_url());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Set up · <?= e($SITE['brand_name']) ?> Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/admin.css?v=1">
  <style>
    .auth-brand{display:flex;align-items:center;gap:12px;margin-bottom:22px}
    .auth-brand .sb-logo{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#0F2A28;color:#F28C28;font-size:20px;flex:none}
    .auth-brand strong{display:block;font-size:18px}
    .auth-brand small{color:#6b7280}
    .auth-card h1{font-size:22px;margin:0 0 4px}
    .auth-card .lead{color:#6b7280;margin:0 0 20px}
    .auth-card form .field{margin-bottom:14px}
    .auth-steps{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:0;list-style:none;font-size:13px;color:#6b7280}
    .auth-steps li{display:flex;align-items:center;gap:6px}
    .auth-steps i{color:#16a34a}
  </style>
</head>
<body class="admin auth-page">
  <main class="auth-card">
    <div class="auth-brand">
      <span class="sb-logo"><i class="fa-solid fa-droplet"></i></span>
      <span><strong><?= e($SITE['brand_name']) ?></strong><small>Admin panel · first-time setup</small></span>
    </div>
    <h1>Create the owner account</h1>
    <p class="lead">This account has full control of the store, including adding employees and choosing what each one can do. Setup runs only once.</p>
    <ul class="auth-steps">
      <li><i class="fa-solid fa-circle-check"></i> Full access</li>
      <li><i class="fa-solid fa-circle-check"></i> Manage employees</li>
      <li><i class="fa-solid fa-circle-check"></i> Cannot be removed</li>
    </ul>
    <?php if ($errors): ?><div class="alert alert-error" role="alert">Please fix the highlighted fields.</div><?php endif; ?>
    <form method="post" action="<?= admin_url('setup.php') ?>" novalidate>
      <input type="hidden" name="setup_token" value="<?= e($_GET['token'] ?? $_POST['setup_token'] ?? '') ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label for="name">Your name</label>
        <input id="name" name="name" value="<?= e($name) ?>" autocomplete="name" required autofocus>
        <?php if (isset($errors['name'])): ?><div class="error"><?= e($errors['name']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="email">Email address <span class="muted">(used to sign in)</span></label>
        <input id="email" type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required>
        <?php if (isset($errors['email'])): ?><div class="error"><?= e($errors['email']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" required>
        <div class="hint">At least 8 characters.</div>
        <?php if (isset($errors['password'])): ?><div class="error"><?= e($errors['password']) ?></div><?php endif; ?>
      </div>
      <div class="field">
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" type="password" name="password_confirm" minlength="8" autocomplete="new-password" required>
        <?php if (isset($errors['password_confirm'])): ?><div class="error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
      </div>
      <button class="btn btn-primary btn-block" type="submit"><i class="fa-solid fa-user-shield"></i> Create owner account</button>
    </form>
  </main>
</body>
</html>
