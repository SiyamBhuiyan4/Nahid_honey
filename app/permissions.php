<?php
/**
 * Permission catalogue. Owner and Admin roles always have every permission.
 * Staff (employees) get exactly the permissions an admin ticks for them.
 */

const ROLES = [
    'owner' => 'Owner',          // created at first-run setup; cannot be removed or demoted
    'admin' => 'Administrator',  // full access
    'staff' => 'Employee',       // only the assigned permissions
];

function permission_groups(): array {
    return [
        'Dashboard' => [
            'dashboard.view' => 'View dashboard & sales summary',
            'reports.view'   => 'View sales reports',
        ],
        'Orders' => [
            'orders.view'   => 'View orders',
            'orders.create' => 'Create manual orders (phone orders)',
            'orders.edit'   => 'Update order status, notes & details',
            'orders.delete' => 'Delete / cancel orders',
            'orders.export' => 'Export orders (CSV)',
        ],
        'Catalogue' => [
            'products.view'     => 'View products',
            'products.edit'     => 'Add & edit products',
            'products.delete'   => 'Delete products',
            'inventory.manage'  => 'Update stock levels',
            'categories.manage' => 'Manage categories',
            'media.upload'      => 'Upload images',
        ],
        'Customers & marketing' => [
            'customers.view' => 'View customers',
            'customers.edit' => 'Edit customer notes & block customers',
            'reviews.manage' => 'Approve, edit & delete reviews',
            'coupons.manage' => 'Manage coupons & discounts',
        ],
        'Store' => [
            'settings.manage' => 'Edit store settings (contact, banners, delivery fees)',
            'staff.manage'    => 'Manage employees & their permissions',
            'activity.view'   => 'View activity log',
        ],
    ];
}

function all_permissions(): array {
    return array_merge(...array_values(array_map('array_keys', permission_groups())));
}

/** One-click presets in the employee form. */
function permission_presets(): array {
    return [
        'Order manager'   => ['dashboard.view', 'orders.view', 'orders.create', 'orders.edit', 'orders.export', 'customers.view'],
        'Catalogue manager' => ['dashboard.view', 'products.view', 'products.edit', 'inventory.manage', 'categories.manage', 'media.upload', 'reviews.manage'],
        'Support agent'   => ['orders.view', 'orders.edit', 'customers.view', 'reviews.manage'],
        'Packer / delivery' => ['orders.view', 'orders.edit'],
        'Marketing'       => ['dashboard.view', 'reports.view', 'coupons.manage', 'reviews.manage', 'products.view'],
    ];
}

function user_has_permission(?array $user, string $perm): bool {
    if (!$user || empty($user['active'])) return false;
    if (in_array($user['role'] ?? '', ['owner', 'admin'], true)) return true;
    return in_array($perm, (array)($user['permissions'] ?? []), true);
}

/* ------------------------------------------------------------------ */
/* Staff-management helpers (used by Admin → Employees).               */
/* ------------------------------------------------------------------ */

/** What each role means — shown on the Employees page. */
function role_descriptions(): array {
    return [
        'owner' => 'Created at first-run setup. Full access to everything, including managing administrators. Cannot be disabled, demoted or deleted.',
        'admin' => 'Full access to every part of the admin panel. Can manage employees and their permissions. Only the owner can add or change administrators.',
        'staff' => 'Can only open the pages and perform the actions that are ticked for them. Everything else is hidden from their menu and blocked.',
    ];
}

function role_label(?string $role): string {
    return ROLES[$role ?? ''] ?? ucfirst((string)$role);
}

/** Effective permission keys for a user (all for owner/admin). */
function effective_permissions(?array $user): array {
    if (!$user) return [];
    if (in_array($user['role'] ?? '', ['owner', 'admin'], true)) return all_permissions();
    return array_values(array_intersect(all_permissions(), (array)($user['permissions'] ?? [])));
}

/** Permissions $actor may hand out: owner/admin → all, others → only what they hold themselves. */
function grantable_permissions(?array $actor): array {
    return effective_permissions($actor);
}

/** Roles $actor may assign when creating/editing someone. */
function assignable_roles(?array $actor): array {
    return ($actor['role'] ?? '') === 'owner' ? ['staff', 'admin'] : ['staff'];
}

/**
 * Can $actor edit / disable / delete $target?
 *  - nobody manages themselves here (use My profile) and nobody manages the owner;
 *  - only the owner manages administrators;
 *  - a non-admin with staff.manage only manages employees whose permissions
 *    are a subset of their own (prevents privilege escalation).
 */
function can_manage_user(?array $actor, ?array $target): bool {
    if (!$actor || !$target || !user_has_permission($actor, 'staff.manage')) return false;
    if (($actor['id'] ?? '') === ($target['id'] ?? '')) return false;
    $role = $target['role'] ?? 'staff';
    if ($role === 'owner') return false;
    if ($role === 'admin') return ($actor['role'] ?? '') === 'owner';
    if (in_array($actor['role'] ?? '', ['owner', 'admin'], true)) return true;
    return !array_diff((array)($target['permissions'] ?? []), effective_permissions($actor));
}

/** Record an activity entry on behalf of a specific user (e.g. right after login). */
function log_activity_for(array $user, string $action, string $details = '', array $meta = []): void {
    try {
        store_put('activity', [
            'id' => date('YmdHis') . '-' . bin2hex(random_bytes(3)),
            'user_id' => $user['id'] ?? null,
            'user_name' => $user['name'] ?? 'System',
            'action' => $action,
            'details' => $details,
            'meta' => $meta,
            'ip' => client_ip(),
        ]);
    } catch (\Throwable $e) { /* never block the real action */ }
}

/** Best-effort client IP (Vercel puts the real address first in X-Forwarded-For). */
function client_ip(): string {
    $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($fwd !== '') return trim(explode(',', $fwd)[0]);
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Random password that is easy to read out (no 0/O/1/l). */
function generate_password(int $len = 12): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}
