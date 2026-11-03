<?php
/**
 * Responsible gambling tools: self-exclusion (cannot be shortened once set), a monthly spend limit, and a stored 18+ confirmation.
 * These are self-service tools. They are not identity or age verification.
 */

function limits_row(int $userId): array
{
    try {
        $st = db()->prepare('SELECT * FROM user_limits WHERE user_id = ?');
        $st->execute([$userId]);
        $r = $st->fetch();
    } catch (Throwable $e) { $r = false; }
    return $r ?: ['user_id' => $userId, 'excluded_until' => null, 'excluded_forever' => 0, 'monthly_limit_kes' => null, 'age_confirmed_at' => null];
}

/** Returns an end date text, 'forever', or '' when not excluded. */
function limits_exclusion(int $userId): string
{
    $r = limits_row($userId);
    if (!empty($r['excluded_forever'])) { return 'forever'; }
    if (!empty($r['excluded_until']) && strtotime($r['excluded_until']) > time()) { return $r['excluded_until']; }
    return '';
}

function limits_confirm_age(int $userId): void
{
    try {
        db()->prepare('INSERT INTO user_limits (user_id, age_confirmed_at, updated_at) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE age_confirmed_at = COALESCE(age_confirmed_at, NOW())')->execute([$userId]);
    } catch (Throwable $e) {}
}

/** months: 1, 3, 6 or 0 (forever). An existing longer exclusion is never shortened. */
function limits_exclude(int $userId, int $months): void
{
    $r = limits_row($userId);
    if (!empty($r['excluded_forever'])) { return; }
    if ($months === 0) {
        db()->prepare('INSERT INTO user_limits (user_id, excluded_forever, updated_at) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE excluded_forever = 1, updated_at = NOW()')->execute([$userId]);
        return;
    }
    $until = date('Y-m-d H:i:s', strtotime("+$months month"));
    if (!empty($r['excluded_until']) && strtotime($r['excluded_until']) > strtotime($until)) { return; }
    db()->prepare('INSERT INTO user_limits (user_id, excluded_until, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE excluded_until = VALUES(excluded_until), updated_at = NOW()')->execute([$userId, $until]);
}

/** Setting a LOWER limit (or first limit) is instant. Raising or removing one takes effect after 24 hours via limits_pending (kept simple: stored in settings key). */
function limits_set_monthly(int $userId, ?int $kes): void
{
    $r = limits_row($userId);
    $cur = $r['monthly_limit_kes'] !== null ? (int)$r['monthly_limit_kes'] : null;
    $raise = $cur !== null && ($kes === null || $kes > $cur);
    if ($raise) {
        set_setting('limit_raise_' . $userId, json_encode(['kes' => $kes, 'at' => time() + 86400]));
        return;
    }
    db()->prepare('INSERT INTO user_limits (user_id, monthly_limit_kes, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE monthly_limit_kes = VALUES(monthly_limit_kes), updated_at = NOW()')->execute([$userId, $kes]);
    set_setting('limit_raise_' . $userId, '');
}

/** Applies a raise that has waited 24 hours. */
function limits_apply_pending(int $userId): void
{
    $raw = (string)setting('limit_raise_' . $userId, '');
    if ($raw === '') { return; }
    $j = json_decode($raw, true);
    if (is_array($j) && (int)$j['at'] <= time()) {
        db()->prepare('INSERT INTO user_limits (user_id, monthly_limit_kes, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE monthly_limit_kes = VALUES(monthly_limit_kes), updated_at = NOW()')->execute([$userId, $j['kes']]);
        set_setting('limit_raise_' . $userId, '');
    }
}

/** Plan prices paid this calendar month, in KES (uses each plan's KES price so every payment method counts the same). */
function limits_spent_month(int $userId): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(p.price_kes),0) FROM payments y JOIN plans p ON p.id = y.plan_id
                         WHERE y.user_id = ? AND y.status = 'paid' AND y.created_at >= ?");
    $st->execute([$userId, date('Y-m-01 00:00:00')]);
    return (int)$st->fetchColumn();
}

/** Message when this member must not pay now, otherwise ''. */
function limits_payment_block(int $userId, int $planKes): string
{
    try {
        limits_apply_pending($userId);
        $ex = limits_exclusion($userId);
        if ($ex === 'forever') { return 'You chose to exclude yourself from paid plans on this site, so payments are switched off for your account.'; }
        if ($ex !== '') { return 'You chose to take a break. Payments are switched off for your account until ' . date('j M Y', strtotime($ex)) . '.'; }
        $r = limits_row($userId);
        if ($r['monthly_limit_kes'] !== null && limits_spent_month($userId) + $planKes > (int)$r['monthly_limit_kes']) {
            return 'This payment would go over the monthly limit you set (KES ' . number_format((int)$r['monthly_limit_kes']) . '). You can change your limit in Account > Limits.';
        }
    } catch (Throwable $e) {}
    return '';
}
