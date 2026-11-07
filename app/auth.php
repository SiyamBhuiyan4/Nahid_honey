<?php
/**
 * Staff authentication — stateless signed cookie (works on serverless,
 * where PHP file sessions do not survive between requests).
 *
 *   current_user()        -> user doc or null
 *   require_login()       -> redirects to /admin/login.php if not signed in
 *   can('orders.edit')    -> bool for the signed-in user
 *   require_perm('...')   -> 403 page if missing
 *   csrf_field() / csrf_check()
 *   flash('Saved', 'success') / take_flash()
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';

const AUTH_COOKIE = 'nh_admin';
const AUTH_TTL = 60 * 60 * 24 * 7; // 7 days

function app_secret(): string {
    $env = getenv('APP_SECRET');
    if ($env) return $env;
    $s = setting('app_secret');
    if (!$s) { $s = bin2hex(random_bytes(32)); setting_set('app_secret', $s); }
    return $s;
}

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

function is_https(): bool {
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function set_app_cookie(string $name, string $value, int $ttl): void {
    setcookie($name, $value, [
        'expires' => $ttl ? time() + $ttl : 0, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[$name] = $value;
}

/** Fingerprint changes when the password changes or the account is disabled -> old cookies die. */
function user_fingerprint(array $u): string {
    return substr(hash('sha256', ($u['password_hash'] ?? '') . '|' . (int)!empty($u['active'])), 0, 12);
}

function login_user(array $user, bool $remember = true): void {
    $payload = b64u(json_encode(['u' => $user['id'], 'e' => time() + AUTH_TTL, 'f' => user_fingerprint($user)]));
    $sig = b64u(hash_hmac('sha256', $payload, app_secret(), true));
    set_app_cookie(AUTH_COOKIE, "$payload.$sig", $remember ? AUTH_TTL : 0);
    store_patch('users', $user['id'], ['last_login' => date('c')]);
}

function logout_user(): void {
    set_app_cookie(AUTH_COOKIE, '', -3600);
    unset($_COOKIE[AUTH_COOKIE]);
}

function current_user(): ?array {
    static $resolved = false, $user = null;
    if ($resolved) return $user;
    $resolved = true;
    $raw = $_COOKIE[AUTH_COOKIE] ?? '';
    if (!str_contains($raw, '.')) return null;
    [$payload, $sig] = explode('.', $raw, 2);
    if (!hash_equals(b64u(hash_hmac('sha256', $payload, app_secret(), true)), $sig)) return null;
    $data = json_decode(b64u_dec($payload), true);
    if (!$data || ($data['e'] ?? 0) < time()) return null;
    $u = store_get('users', $data['u'] ?? '');
    if (!$u || empty($u['active']) || !hash_equals(user_fingerprint($u), (string)($data['f'] ?? ''))) return null;
    return $user = $u;
}

function can(string $perm): bool {
    return user_has_permission(current_user(), $perm);
}

function is_admin_user(?array $u = null): bool {
    $u = $u ?? current_user();
    return $u && in_array($u['role'] ?? '', ['owner', 'admin'], true);
}

function admin_url(string $path = ''): string {
    return '/admin/' . ltrim($path, '/');
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}

function has_any_user(): bool {
    return count(store_all('users')) > 0;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        $next = $_SERVER['REQUEST_URI'] ?? admin_url();
        redirect(admin_url(has_any_user() ? 'login.php?next=' . urlencode($next) : 'setup.php'));
    }
    return $u;
}

function require_perm(string $perm): void {
    require_login();
    if (can($perm)) return;
    http_response_code(403);
    if (function_exists('admin_header')) {
        admin_header('No access', '');
        echo '<div class="empty-state"><i class="fa-solid fa-lock"></i><h2>You don’t have access to this page</h2>'
           . '<p>Ask an administrator to give you the “' . htmlspecialchars($perm) . '” permission.</p>'
           . '<a class="btn" href="' . admin_url() . '">Back to dashboard</a></div>';
        admin_footer();
    } else {
        echo 'Forbidden';
    }
    exit;
}

function csrf_token(): string {
    $u = current_user();
    $bucket = intdiv(time(), 86400); // rotates daily
    return b64u(hash_hmac('sha256', 'csrf|' . ($u['id'] ?? 'guest') . '|' . $bucket, app_secret(), true));
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void {
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $u = current_user();
    $ok = false;
    foreach ([0, 1] as $back) { // accept today's and yesterday's token
        $bucket = intdiv(time(), 86400) - $back;
        $expect = b64u(hash_hmac('sha256', 'csrf|' . ($u['id'] ?? 'guest') . '|' . $bucket, app_secret(), true));
        if (hash_equals($expect, (string)$sent)) $ok = true;
    }
    if (!$ok) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void {
    set_app_cookie('nh_flash', b64u(json_encode(['m' => $message, 't' => $type])), 120);
}

function take_flash(): ?array {
    $raw = $_COOKIE['nh_flash'] ?? '';
    if ($raw === '') return null;
    set_app_cookie('nh_flash', '', -3600);
    $d = json_decode(b64u_dec($raw), true);
    return $d ? ['message' => (string)$d['m'], 'type' => (string)$d['t']] : null;
}

/** Simple login throttle: 8 failed attempts per IP per 15 minutes. */
function login_throttled(string $ip): bool {
    $d = store_get('login_attempts', sha1($ip));
    return $d && ($d['count'] ?? 0) >= 8 && strtotime($d['updated_at'] ?? 'now') > time() - 900;
}

function login_failed(string $ip): void {
    $id = sha1($ip);
    $d = store_get('login_attempts', $id);
    $fresh = !$d || strtotime($d['updated_at'] ?? 'now') < time() - 900;
    store_put('login_attempts', ['id' => $id, 'count' => $fresh ? 1 : ($d['count'] + 1)]);
}

function login_cleared(string $ip): void {
    store_delete('login_attempts', sha1($ip));
}
