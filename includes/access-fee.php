<?php
/**
 * Weekly access fee (OFF by default; the admin switches it on in Admin > Weekly access).
 *  - Members from Kenya, Uganda and Tanzania pay KES 20 a week, every other country USD 1 a week (both editable).
 *  - A new member (or every member, on the day the admin first switches it on) gets 7 days free. After that they pay for the next 7 days at a time.
 *  - While the fee is ON, VIP is free for everyone who has access, and VIP plans cannot be bought.
 *  - While it is OFF, nothing here shows or works.
 * It uses the normal checkout (every payment method) through a hidden plan with the slug 'app-access'.
 */
const ACCESS_FEE_SLUG = 'app-access';

function access_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = function_exists('sk_ensure_table') && sk_ensure_table('access_passes', 'database-update-35.sql'); }
    return $ok;
}
function access_on(): bool { return setting('access_fee_on', '0') === '1' && access_ready(); }
function access_kes(): int { return max(1, (int)setting('access_fee_kes', '20')); }
function access_usd(): float { return max(0.5, (float)setting('access_fee_usd', '1')); }
/** While the fee is ON, visitors who are not logged in see only the login and register pages (the whole site is for paid-up members). */
function access_lock(): bool { return access_on() && setting('access_lock', '1') === '1'; }
function access_free_days(): int { return max(0, (int)setting('access_free_days', '7')); }
function access_local_countries(): array { return ['KE', 'UG', 'TZ']; }

function access_is_local(array $u): bool
{
    $c = strtoupper((string)($u['country'] ?? ''));
    if ($c !== '') { return in_array($c, access_local_countries(), true); }
    $n = strtoupper((string)($u['nationality'] ?? ''));
    if ($n !== '') { return in_array($n, access_local_countries(), true); }
    $m = (string)($u['mobile'] ?? $u['phone'] ?? '');
    return (bool)preg_match('/^(254|256|255)/', $m);
}

/** ['kes' => int, 'usd' => float, 'label' => 'KES 20 / week' | 'USD 1.00 / week', 'local' => bool]. */
function access_amount(array $u): array
{
    $krow = function_exists('currency_row') ? currency_row('KES') : null;
    $rate = $krow && (float)$krow['rate_per_usd'] > 0 ? (float)$krow['rate_per_usd'] : 129.0;
    if (access_is_local($u)) {
        $kes = access_kes();
        return ['kes' => $kes, 'usd' => max(0.5, round($kes / $rate, 2)), 'label' => 'KES ' . number_format($kes) . ' / week', 'local' => true];
    }
    $usd = access_usd();
    return ['kes' => (int)ceil($usd * $rate), 'usd' => $usd, 'label' => 'USD ' . number_format($usd, 2) . ' / week', 'local' => false];
}

function access_plan_id(): int
{
    try {
        $st = db()->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1'); $st->execute([ACCESS_FEE_SLUG]);
        $id = (int)$st->fetchColumn();
        if ($id) { return $id; }
        db()->prepare("INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order, tipster_id) VALUES ('Weekly access', ?, 20, 1.00, 7, 'Weekly access to SlipKing', 0, 1, 998, 0)")->execute([ACCESS_FEE_SLUG]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) { error_log('access plan: ' . $e->getMessage()); return 0; }
}

/** Plan priced for this person, or null when the fee is off. Called by tipster_fee_plan_for() so every gateway uses it. */
function access_plan_for(array $plan, array $user): ?array
{
    if (!access_on()) { return null; }
    $a = access_amount($user);
    $plan['price_kes'] = $a['kes'];
    $plan['price_usd'] = number_format($a['usd'], 2, '.', '');
    $plan['duration_days'] = 7;
    $plan['tipster_id'] = 0;
    return $plan;
}

/** Where a member stands. ['state' => 'off'|'staff'|'free'|'paid'|'due', 'ends' => timestamp|null, 'days' => int days left]. */
function access_status(?array $u = null): array
{
    static $cache = [];
    $u = $u ?: current_user();
    if (!$u || !access_on()) { return ['state' => 'off', 'ends' => null, 'days' => 0]; }
    $uid = (int)$u['id'];
    if (isset($cache[$uid])) { return $cache[$uid]; }
    if (in_array($u['role'] ?? '', ['admin', 'editor', 'support', 'finance'], true)) { return $cache[$uid] = ['state' => 'staff', 'ends' => null, 'days' => 0]; }
    $now = time();
    $paid = 0;
    try {
        $st = db()->prepare('SELECT MAX(ends_at) FROM access_passes WHERE user_id = ?'); $st->execute([$uid]);
        $paid = (int)strtotime((string)$st->fetchColumn());
    } catch (Throwable $e) { }
    $created = (string)($u['created_at'] ?? '');
    if ($created === '') { try { $cq = db()->prepare('SELECT created_at FROM users WHERE id = ?'); $cq->execute([$uid]); $created = (string)$cq->fetchColumn(); } catch (Throwable $e) { } }
    // The free week counts from the day the member joined, or from the day the fee was switched on if that is later.
    // A missing start date or join date must never mean "free forever", so it counts as long ago.
    $fs = (string)setting('access_fee_start', '');
    $start = max((int)($created !== '' ? strtotime($created) : 0), (int)($fs !== '' ? strtotime($fs) : 0));
    $freeEnd = $start + access_free_days() * 86400;
    if ($paid > $now) { return $cache[$uid] = ['state' => 'paid', 'ends' => $paid, 'days' => (int)ceil(($paid - $now) / 86400)]; }
    if ($freeEnd > $now) { return $cache[$uid] = ['state' => 'free', 'ends' => $freeEnd, 'days' => (int)ceil(($freeEnd - $now) / 86400)]; }
    return $cache[$uid] = ['state' => 'due', 'ends' => max($paid, $freeEnd), 'days' => 0];
}
/** True while the fee is on and this member may use the site and apps (free week or paid week, or staff). */
function access_ok(?array $u = null): bool { $s = access_status($u)['state']; return $s !== 'due'; }
/** VIP content is free for everyone who has access while the fee is on. */
function access_vip_free(): bool { return access_on() && is_logged_in() && access_ok(); }

/** Called by complete_payment() for the 'app-access' plan: adds 7 days (stacking on any time left). */
function access_grant(int $userId, int $paymentId): void
{
    $st = db()->prepare('SELECT MAX(ends_at) FROM access_passes WHERE user_id = ? AND ends_at > NOW()'); $st->execute([$userId]);
    $from = $st->fetchColumn() ?: null;
    db()->prepare('INSERT IGNORE INTO access_passes (user_id, payment_id, starts_at, ends_at) VALUES (?, ?, COALESCE(?, NOW()), DATE_ADD(COALESCE(?, NOW()), INTERVAL 7 DAY))')->execute([$userId, $paymentId, $from, $from]);
    if (function_exists('notify_user')) { notify_user($userId, 'Weekly access paid', 'Thank you. Your SlipKing access is active for 7 more days.'); }
    payments_log('access-paid', ['payment' => $paymentId, 'user' => $userId]);
}

function access_pay_url(): string { return url('payments/checkout.php?plan=' . ACCESS_FEE_SLUG); }

/** Pages anyone may open while the site is locked (login, legal pages, payment callbacks, downloads). */
function access_open_script(string $script): bool
{
    return (bool)preg_match('#/(admin|payments|api|cron|auth|assets|uploads)/#', $script)
        || (bool)preg_match('#/(access|logout|app-download|unsubscribe|terms|privacy|contact|responsible-gambling|widgets|404|manifest|sw|offline|robots|sitemap|streak-join)\.php$#', $script);
}

/**
 * While the weekly fee is ON:
 *  - a visitor who is not logged in is sent to the login page (nothing else can be viewed),
 *  - a member whose free week has ended and who has not paid is sent to the pay page (nothing else can be viewed),
 *  - once they pay they see everything, VIP included.
 */
function access_gate_run(): void
{
    if (PHP_SAPI === 'cli' || !access_on()) { return; }
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    // Data endpoints used by the pages and apps: no data for visitors or for members whose access has ended.
    if (preg_match('#/api/(chat|globalchat|latest-tips|live|app-widget)\.php$#', $script)) {
        $deny = !is_logged_in() ? (access_lock() ? 401 : 0) : (access_ok() ? 0 : 402);
        if ($deny) { http_response_code($deny); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $deny === 402 ? 'Your access has ended. Pay to continue.' : 'Please log in.', 'access_due' => $deny === 402]); exit; }
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { return; }
    if (access_open_script($script)) { return; }
    if (!is_logged_in()) {
        if (access_lock()) { redirect('auth/login.php?next=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? ''))); }
        return;
    }
    if (access_ok()) { return; }
    redirect('access.php');
}

/** For the app: null when the fee is off, otherwise the member's access state and price. */
function access_api_info(?array $u = null): ?array
{
    if (!access_on()) { return null; }
    $u = $u ?: current_user();
    $a = $u ? access_amount($u) : ['label' => 'KES ' . access_kes() . ' / week', 'local' => true];
    $s = $u ? access_status($u) : ['state' => 'free', 'ends' => null, 'days' => 0];
    return ['on' => true, 'state' => $s['state'], 'days' => (int)$s['days'], 'ends' => $s['ends'] ? date('j M Y, H:i', (int)$s['ends']) : '',
            'price' => $a['label'], 'ke' => 'KES ' . access_kes() . ' / week', 'other' => 'USD ' . number_format(access_usd(), 2) . ' / week',
            'free_days' => access_free_days(), 'pay_url' => access_pay_url(), 'lock' => access_lock()];
}
