<?php
/**
 * Member account deletion (required by Google Play and good practice everywhere).
 * Personal details are erased and the account is closed. Payment records stay (anonymised) because accounting and
 * the law require them; public comments stay but show "Deleted member". Returns '' on success or a message to show.
 */
function account_delete_member(PDO $pdo, int $uid, string $password): string
{
    $st = $pdo->prepare('SELECT id, role, password_hash FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u) { return 'Account not found.'; }
    if (!password_verify($password, (string)$u['password_hash'])) { return 'That password is not correct.'; }
    if ($u['role'] !== 'user') { return 'Staff and administrator accounts cannot be deleted here. Ask the site owner to change your role to a normal member first (Admin > Staff and roles).'; }

    $run = function (string $sql, array $a = []) use ($pdo) {
        try { $pdo->prepare($sql)->execute($a); } catch (Throwable $e) { error_log('account-delete: ' . $e->getMessage()); }
    };
    $pdo->beginTransaction();
    try {
        // Personal rows: removed completely.
        foreach (['app_devices', 'push_subs', 'alert_prefs', 'alert_queue', 'digest_prefs', 'dm_blocks', 'notifications', 'odds_watch',
                  'password_resets', 'phone_codes', 'user_fingerprints', 'user_presence', 'member_public', 'user_limits', 'admin_recovery_codes'] as $t) {
            $run("DELETE FROM $t WHERE user_id = ?", [$uid]);
        }
        $run('DELETE FROM dm_blocks WHERE blocked_id = ?', [$uid]);
        $run('DELETE FROM dm_messages WHERE from_id = ? OR to_id = ?', [$uid, $uid]);
        // A tipster page is hidden (the published record stays, but nobody can buy or follow it any more).
        $run("UPDATE tipsters SET status = 'suspended' WHERE user_id = ?", [$uid]);
        $run("UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'", [$uid]);
        // The account row: identity wiped, login impossible.
        $tag = bin2hex(random_bytes(6));
        $pdo->prepare("UPDATE users SET name = 'Deleted member', email = ?, phone = NULL, password_hash = ?, email_verified = 0, referral_code = NULL, referred_by = NULL WHERE id = ?")
            ->execute(['deleted-' . $uid . '-' . $tag . '@deleted.invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $uid]);
        $run('UPDATE users SET mobile = NULL, mobile_verified = 0, country = NULL, email_optout = 1, totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('account-delete failed: ' . $e->getMessage());
        return 'Your account could not be deleted right now. Nothing was changed. Please try again or contact us.';
    }
    return '';
}
