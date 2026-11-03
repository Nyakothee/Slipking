<?php
/**
 * Tipster joining fee: ONE-TIME, KES 150 for tipsters who registered from Kenya, USD 1 for every other country (both editable by the admin).
 * It is ON by default (Admin > Tipsters can switch it off). Until it is paid a tipster can register and open their dashboard, but
 * cannot be approved, listed, picked for managed service, subscribed to, or post tips and plans.
 * The fee goes through the normal checkout and every payment method, using a hidden plan with the slug 'tipster-fee'
 * (its tipster_id is 0, so it never appears on the VIP pages or in the plan lists). Paying it sets tipsters.fee_paid_at.
 */
const TIPSTER_FEE_SLUG = 'tipster-fee';

/** Has the update-18 column been added? Without it the fee stays switched off, so nothing breaks. */
function tipster_fee_cols(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT fee_paid_at FROM tipsters LIMIT 0');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function tipster_fee_on(): bool
{
    return tipster_fee_cols() && setting('tipster_fee_on', '1') === '1';
}

function tipster_fee_kes(): int { return max(1, (int)setting('tipster_fee_kes', '150')); }
function tipster_fee_usd(): float { return max(0.5, (float)setting('tipster_fee_usd', '1')); }

function tipster_fee_is_kenyan(array $u): bool
{
    $c = strtoupper((string)($u['country'] ?? ''));
    if ($c !== '') {
        return $c === 'KE';
    }
    return strpos((string)($u['mobile'] ?? $u['phone'] ?? ''), '254') === 0;
}

/** What this person pays: ['kes' => int, 'usd' => float, 'label' => 'KES 100' | 'USD 5.00']. */
function tipster_fee_amount(array $u): array
{
    $krow = currency_row('KES');
    $rate = $krow && (float)$krow['rate_per_usd'] > 0 ? (float)$krow['rate_per_usd'] : 129.0;
    if (tipster_fee_is_kenyan($u)) {
        $kes = tipster_fee_kes();
        return ['kes' => $kes, 'usd' => max(0.5, round($kes / $rate, 2)), 'label' => 'KES ' . number_format($kes)];
    }
    $usd = tipster_fee_usd();
    return ['kes' => (int)ceil($usd * $rate), 'usd' => $usd, 'label' => 'USD ' . number_format($usd, 2)];
}

/** The fee for a registration country (ISO code such as KE). Same rule as tipster_fee_amount(): Kenya pays KES, everyone else USD. */
function tipster_fee_for_country(string $iso): array
{
    return tipster_fee_amount(['country' => strtoupper($iso)]);
}

/** Has this tipster paid (or is there no fee)? Approved AND paid = live: listed, subscribable, can post. */
function tipster_is_live(array $t): bool
{
    return ($t['status'] ?? '') === 'active' && !tipster_fee_owed($t);
}

/** SQL to add to a query on tipsters so unpaid tipsters are left out. Empty when the fee is off. */
function tipster_live_sql(string $alias = 'ti'): string
{
    return tipster_fee_on() ? ' AND ' . $alias . '.fee_paid_at IS NOT NULL' : '';
}

/** Makes sure the hidden fee plan exists (the SQL update adds it; this covers a missed import). Returns its id or 0. */
function tipster_fee_plan_id(): int
{
    try {
        $st = db()->prepare('SELECT id FROM plans WHERE slug = ? LIMIT 1');
        $st->execute([TIPSTER_FEE_SLUG]);
        $id = (int)$st->fetchColumn();
        if ($id) {
            return $id;
        }
        db()->prepare("INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order, tipster_id) VALUES ('Tipster joining fee', ?, 150, 1.00, 0, 'One-time fee to join as a tipster', 0, 1, 999, 0)")->execute([TIPSTER_FEE_SLUG]);
        return (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('tipster fee plan: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Called after a plan was loaded by slug. Normal plans come back unchanged. The fee plan comes back priced for this person,
 * or null when it cannot be paid (fee off, not a tipster, or already paid).
 */
function tipster_fee_plan_for(array $plan, array $user): ?array
{
    if (($plan['slug'] ?? '') === 'app-access') {
        return function_exists('access_plan_for') ? access_plan_for($plan, $user) : null;
    }
    // while the weekly access fee is ON, VIP plans are free for everyone and cannot be bought
    if (empty($plan['tipster_id']) && ($plan['slug'] ?? '') !== TIPSTER_FEE_SLUG && function_exists('access_on') && access_on()) {
        return null;
    }
    if (($plan['slug'] ?? '') !== TIPSTER_FEE_SLUG) {
        // a tipster's own plan cannot be bought while that tipster has not paid the joining fee
        if (!empty($plan['tipster_id']) && tipster_fee_on()) {
            $owner = tipster_by_id((int)$plan['tipster_id']);
            if ($owner && tipster_fee_owed($owner)) {
                return null;
            }
        }
        return $plan;
    }
    if (!tipster_fee_on()) {
        return null;
    }
    $t = tipster_for_user((int)$user['id']);
    if (!$t || !empty($t['fee_paid_at']) || in_array($t['status'], ['rejected'], true)) {
        return null;
    }
    $a = tipster_fee_amount($user);
    $plan['price_kes'] = $a['kes'];
    $plan['price_usd'] = number_format($a['usd'], 2, '.', '');
    return $plan;
}

/** Must this tipster still pay before they can be approved? */
function tipster_fee_owed(array $t): bool
{
    return tipster_fee_on() && empty($t['fee_paid_at']);
}

/** Called by complete_payment() when the fee was paid. */
function tipster_fee_mark_paid(int $userId, int $paymentId): void
{
    db()->prepare('UPDATE tipsters SET fee_paid_at = NOW() WHERE user_id = ? AND fee_paid_at IS NULL')->execute([$userId]);
    $st = db()->prepare('SELECT status FROM tipsters WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $status = (string)$st->fetchColumn();
    notify_user($userId, 'Joining fee received', $status === 'active'
        ? 'Thank you. Your one-time tipster joining fee is paid. You can post tips and members can find you again.'
        : 'Thank you. Your one-time tipster joining fee is paid. An admin will now review your application.');
    payments_log('tipster-fee-paid', ['payment' => $paymentId, 'user' => $userId]);
}

/** Where to send a tipster to pay. */
function tipster_fee_pay_url(): string
{
    return url('payments/checkout.php?plan=' . TIPSTER_FEE_SLUG);
}
