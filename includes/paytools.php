<?php
/**
 * Payment extras: receipt email + SMS, duplicate and wrong-amount flags, group plan codes.
 */
function paytools_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('sk_ensure_table') && sk_ensure_table('payment_flags', 'database-update-41.sql') && sk_ensure_table('group_codes', 'database-update-41.sql');
        if ($ok && function_exists('sk_ensure_column')) { try { sk_ensure_column('plans', 'group_seats', 'TINYINT UNSIGNED NOT NULL DEFAULT 1'); } catch (Throwable $e) { } }
    }
    return $ok;
}

function payment_flag(int $paymentId, string $kind, string $note): void
{
    if (!paytools_ready()) { return; }
    try {
        db()->prepare('INSERT IGNORE INTO payment_flags (payment_id, kind, note) VALUES (?,?,?)')->execute([$paymentId, $kind, mb_substr($note, 0, 300)]);
        if (function_exists('payments_log')) { payments_log('flag-' . $kind, ['payment' => $paymentId, 'note' => $note]); }
    } catch (Throwable $e) { }
}

/** Two paid payments for the same plan by the same member within 20 minutes: flag the second for a refund review. */
function payment_check_duplicate(array $p): bool
{
    $st = db()->prepare("SELECT id FROM payments WHERE user_id = ? AND plan_id = ? AND status = 'paid' AND id <> ? AND paid_at > NOW() - INTERVAL 20 MINUTE LIMIT 1");
    $st->execute([$p['user_id'], $p['plan_id'], $p['id']]);
    if ($other = $st->fetchColumn()) {
        payment_flag((int)$p['id'], 'duplicate', 'Paid twice within 20 minutes (also payment #' . $other . '). Check whether to refund one.');
        notify_user((int)$p['user_id'], 'Possible double payment', 'We noticed two payments for the same plan within minutes. If this was a mistake, contact support and we will review a refund. Your plan days were added once for each payment.', 'support.php');
        return true;
    }
    return false;
}

/** Called after a payment was completed: receipt email, SMS, duplicate check, group codes. */
function payment_after_paid(int $paymentId): void
{
    try {
        $st = db()->prepare('SELECT p.*, u.name, u.email, u.mobile, u.mobile_verified, u.email_optout, pl.name AS plan_name, pl.duration_days, pl.slug AS plan_slug' . (paytools_ready() ? ', pl.group_seats' : ', 1 AS group_seats') . '
                             FROM payments p JOIN users u ON u.id = p.user_id JOIN plans pl ON pl.id = p.plan_id WHERE p.id = ?');
        $st->execute([$paymentId]);
        $p = $st->fetch();
        if (!$p) { return; }
        payment_check_duplicate($p);
        $cur = (string)$p['currency'];
        $amt = $cur . ' ' . number_format((float)$p['amount'], $cur === 'USD' ? 2 : 0);
        $ref = $p['mpesa_receipt'] ?: $p['reference'];
        if (function_exists('send_mail') && !empty($p['email']) && empty($p['email_optout'])) {
            $body = "Hi " . mask_name((string)$p['name']) . ",\n\nThank you. We received your payment.\n\nPlan: " . $p['plan_name'] . "\nAmount: $amt\nReceipt: $ref\nDate: " . date('j M Y, H:i', strtotime((string)($p['paid_at'] ?: 'now'))) . "\n\nSee your plan: " . url('account/subscription.php') . "\n\n" . SITE_NAME;
            @send_mail($p['email'], 'Your ' . SITE_NAME . ' receipt ' . $ref, $body);
        }
        if (function_exists('sms_enabled') && sms_enabled() && !empty($p['mobile']) && !empty($p['mobile_verified'])) {
            $err = null;
            @sms_send((string)$p['mobile'], SITE_NAME . ': payment of ' . $amt . ' received for ' . $p['plan_name'] . '. Receipt ' . $ref . '. Thank you.', $err);
        }
        $seats = (int)($p['group_seats'] ?? 1);
        if ($seats > 1 && $p['plan_slug'] !== 'app-access') {
            $have = db()->prepare('SELECT COUNT(*) FROM group_codes WHERE payment_id = ?');
            $have->execute([$paymentId]);
            if (!(int)$have->fetchColumn()) {
                $codes = [];
                for ($i = 1; $i < $seats; $i++) {
                    $c = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
                    db()->prepare('INSERT INTO group_codes (code, owner_id, plan_id, payment_id, days) VALUES (?,?,?,?,?)')->execute([$c, $p['user_id'], $p['plan_id'], $paymentId, (int)$p['duration_days']]);
                    $codes[] = $c;
                }
                notify_user((int)$p['user_id'], 'Your group plan codes', ($seats - 1) . ' invite codes are ready. Share them with friends: they enter a code on the Group plan page to get the same VIP days.', 'account/group-plan.php');
            }
        }
    } catch (Throwable $e) { error_log('payment_after_paid: ' . $e->getMessage()); }
}

/** A member redeems a group code: VIP days for them. Returns [ok, message]. */
function group_redeem(int $uid, string $code): array
{
    if (!paytools_ready()) { return [false, 'Not available yet.']; }
    $code = strtoupper(trim($code));
    $st = db()->prepare('SELECT * FROM group_codes WHERE code = ? AND used_by IS NULL');
    $st->execute([$code]);
    $g = $st->fetch();
    if (!$g) { return [false, 'That code is not valid or was already used.']; }
    if ((int)$g['owner_id'] === $uid) { return [false, 'Share this code with someone else. You already have your own days.']; }
    $upd = db()->prepare('UPDATE group_codes SET used_by = ?, used_at = NOW() WHERE id = ? AND used_by IS NULL');
    $upd->execute([$uid, $g['id']]);
    if ($upd->rowCount() !== 1) { return [false, 'That code was just used.']; }
    $ends = grant_vip($uid, (int)$g['plan_id'], (int)$g['days']);
    if (!$ends) { return [false, 'Could not add the days. Contact support.']; }
    notify_user($uid, 'VIP plan active', 'Your group plan code worked. VIP is active until ' . date('j F Y, H:i', strtotime($ends)) . '.', 'account/subscription.php');
    notify_user((int)$g['owner_id'], 'A friend used your group code', 'Code ' . $code . ' was redeemed.', 'account/group-plan.php');
    return [true, 'VIP is active until ' . date('j F Y, H:i', strtotime($ends)) . '.'];
}
