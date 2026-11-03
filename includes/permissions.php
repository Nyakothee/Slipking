<?php
/**
 * Staff roles and what each may open in the admin panel.
 *   admin    - everything (the owner). Only admins manage staff, plans, prices, settings, tipsters and backups.
 *   editor   - posts and settles tips, bulk import, odds, fixtures, accumulators, blog, leagues, Telegram posting. No money, no member data.
 *   support  - live chat, account-management enquiries, contact messages, member profiles (read-only) and tipster review moderation.
 *   finance  - payments, subscriptions, coupons, currencies, tipster payouts and member profiles (read-only).
 * Job applications (hiring) are visible to admins only.
 * Every role can use Admin > Security to protect its own login with two-factor.
 */
const STAFF_ROLES = ['admin', 'editor', 'support', 'finance'];

/** Permission names by role. 'admin' holds all of them. */
function role_permissions(): array
{
    return [
        'editor'  => ['dashboard', 'tips', 'fixtures', 'accas', 'content', 'social', 'security'],
        'support' => ['dashboard', 'chat', 'managed', 'messages', 'users_view', 'reviews', 'security'],
        'finance' => ['dashboard', 'payments', 'subscriptions', 'coupons', 'currencies', 'payouts', 'users_view', 'security'],
    ];
}

function role_label(string $role): string
{
    return ['admin' => 'Admin', 'editor' => 'Editor', 'support' => 'Support', 'finance' => 'Finance', 'user' => 'Member'][$role] ?? ucfirst($role);
}

/** One-line description shown on the staff page. */
function role_summary(string $role): string
{
    return [
        'admin'   => 'Everything, including staff, prices, settings, tipsters and backups.',
        'editor'  => 'Tips, bulk import, odds, fixtures, accumulators, blog, leagues, Telegram.',
        'support' => 'Live chat, account-management enquiries, messages, member profiles (view), tipster reviews.',
        'finance' => 'Payments, subscriptions, coupons, currencies, tipster payouts, member profiles (view).',
    ][$role] ?? '';
}

function is_staff(): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], STAFF_ROLES, true);
}

/** Does the signed-in staff member hold this permission? Admins hold all. */
function can(string $perm): bool
{
    $u = current_user();
    if ($u === null) {
        return false;
    }
    if ($u['role'] === 'admin') {
        return true;
    }
    $map = role_permissions();
    return isset($map[$u['role']]) && in_array($perm, $map[$u['role']], true);
}

/**
 * Top of every admin page. Not signed in as staff -> login page. Staff without the permission -> back to the dashboard.
 * Use require_perm('admin_only') for pages only a full admin may open.
 */
function require_perm(string $perm): void
{
    if (!is_staff()) {
        redirect('admin/login.php');
    }
    if (!can($perm)) {
        set_flash('error', 'Your role does not include that page.');
        redirect($perm === 'dashboard' ? 'admin/login.php' : 'admin/index.php');
    }
}
