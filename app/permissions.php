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
