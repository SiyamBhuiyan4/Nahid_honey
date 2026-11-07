<?php
/**
 * Create or reset an Owner / Administrator account from the command line —
 * e.g. when the owner has forgotten their password.
 *
 *   php bin/create-admin.php owner@example.com                  # prompts for a password
 *   php bin/create-admin.php owner@example.com --password='S3cret!pass' --name='Nahid' --role=owner
 *   php bin/create-admin.php owner@example.com --generate         # prints a random password
 *
 * Existing account (matched by email): password is reset, account re-enabled
 * (role/name changed only if given). New account: role defaults to 'owner'
 * when there are no users yet, otherwise 'admin'.
 *
 * Works against whichever store the environment selects (see README):
 *   production data on Telegram:  STORE_DRIVER=telegram TELEGRAM_BOT_TOKEN=... TELEGRAM_CHANNEL_ID=... php bin/create-admin.php ...
 *   Postgres:                     DATABASE_URL=postgres://... php bin/create-admin.php ...
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/permissions.php';

$args = array_slice($argv, 1);
$email = '';
$opt = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
    elseif ($email === '') $email = strtolower(trim($a));
}
if ($email === '' || isset($opt['help']) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php EMAIL [--name=NAME] [--role=owner|admin] [--password=PASS | --generate]\n");
    exit(1);
}
$role = isset($opt['role']) ? (string)$opt['role'] : null;
if ($role !== null && !in_array($role, ['owner', 'admin'], true)) { fwrite(STDERR, "--role must be owner or admin\n"); exit(1); }

if (!empty($opt['generate'])) {
    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), '=');
} elseif (isset($opt['password']) && is_string($opt['password'])) {
    $password = $opt['password'];
} else {
    fwrite(STDOUT, 'New password (min 8 chars): ');
    if (DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $password = trim((string)fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') system('stty echo');
    fwrite(STDOUT, "\n");
}
if (strlen($password) < 8) { fwrite(STDERR, "Password must be at least 8 characters.\n"); exit(1); }

$users = store_all('users');
$user = null;
foreach ($users as $u) if (strtolower($u['email'] ?? '') === $email) { $user = $u; break; }

if ($user) {
    $changes = ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'active' => true];
    if ($role) $changes['role'] = $role;
    if (isset($opt['name']) && is_string($opt['name'])) $changes['name'] = $opt['name'];
    $user = store_patch('users', $user['id'], $changes);
    $what = 'Reset password for';
} else {
    $user = store_put('users', [
        'id' => bin2hex(random_bytes(8)),
        'name' => is_string($opt['name'] ?? null) ? $opt['name'] : ucfirst(strstr($email, '@', true)),
        'email' => $email,
        'phone' => '',
        'role' => $role ?? ($users ? 'admin' : 'owner'),
        'permissions' => [],
        'active' => true,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'last_login' => null,
        'created_by' => null,
        'notes' => 'Created with bin/create-admin.php',
    ]);
    $what = 'Created';
}
log_activity('staff.cli', "$what {$user['role']} account {$email} from the command line", ['user_id' => $user['id']]);
store_flush();

echo "$what {$user['role']} account: {$email} (store: " . store_driver() . ")\n";
if (!empty($opt['generate'])) echo "Password: {$password}\n";
echo "Sign in at /admin/login.php — existing sessions for this account are signed out.\n";
