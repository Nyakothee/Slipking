<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('payouts');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $a = $_POST['action'] ?? '';
        $note = mb_substr(post_str('note'), 0, 250);
        $st = $pdo->prepare("SELECT c.*, ti.display_name FROM tipster_claims c JOIN tipsters ti ON ti.id = c.tipster_id WHERE c.id = ? AND c.status = 'requested'");
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) { set_flash('error', 'That claim was already handled.'); }
        elseif ($a === 'approve') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE tipster_claims SET status = 'approved', note = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND status = 'requested'")
                    ->execute([$note ?: 'Approved', current_user()['id'], $id]);
                $pdo->prepare("UPDATE tipster_earnings SET status = 'reversed', note = ? WHERE payment_id = ? AND status = 'ok'")
                    ->execute(['Money-back claim #' . $id, $c['payment_id']]);
                $pdo->commit();
                notify_user((int)$c['user_id'], 'Your money-back claim was approved', 'Your claim for ' . $c['display_name'] . ' was approved. We will refund KES ' . number_format((float)$c['amount_kes'], 0) . ' to your original payment method or contact you shortly.');
                admin_log('claim.approve', 'claim#' . $id, 'KES ' . $c['amount_kes']);
                set_flash('success', 'Approved. The tipster earning was reversed. Now REFUND the member by hand (M-Pesa / PayPal / card) and tell them.');
            } catch (Throwable $e) { $pdo->rollBack(); error_log('claim approve: ' . $e->getMessage()); set_flash('error', 'Could not save. Try again.'); }
        } elseif ($a === 'reject') {
            $pdo->prepare("UPDATE tipster_claims SET status = 'rejected', note = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND status = 'requested'")
                ->execute([$note ?: 'Not eligible', current_user()['id'], $id]);
            notify_user((int)$c['user_id'], 'Your money-back claim was not approved', 'Your claim for ' . $c['display_name'] . ' was not approved. Reason: ' . ($note ?: 'Not eligible') . '. Contact us if you think this is wrong.');
            admin_log('claim.reject', 'claim#' . $id, $note);
            set_flash('success', 'Rejected and the member was told.');
        }
    }
    redirect('admin/tipster-claims.php');
}
$rows = $pdo->query("SELECT c.*, u.name, u.email, ti.display_name, s.starts_at, s.ends_at FROM tipster_claims c
                     JOIN users u ON u.id = c.user_id JOIN tipsters ti ON ti.id = c.tipster_id JOIN tipster_subscriptions s ON s.id = c.subscription_id
                     ORDER BY (c.status = 'requested') DESC, c.created_at DESC LIMIT 100")->fetchAll();
$admin_title = 'Money-back claims';
$admin_active = 'tipster-claims';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Money-back claims</h1>
<p class="sub">Members of tipsters who offer the guarantee can claim after a bad period. The system checks the rules (plan ended, at least 5 settled premium tips, loss at or over the limit of <?= e(setting('tipster_guarantee_units', '5')) ?> units). You approve or reject, and you pay the refund yourself.</p>
<div class="panel">
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>Sent</th><th>Member</th><th>Tipster</th><th>Period</th><th>Units</th><th>Refund</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?><tr><td><?= e(date('j M, H:i', strtotime($c['created_at']))) ?></td><td><?= e($c['name']) ?><br><small><?= e($c['email']) ?></small></td><td><?= e($c['display_name']) ?></td>
  <td><?= e(date('j M', strtotime($c['starts_at']))) ?> - <?= e(date('j M', strtotime($c['ends_at']))) ?></td><td><?= e(number_format((float)$c['units'], 1)) ?></td><td>KES <?= e(number_format((float)$c['amount_kes'], 0)) ?></td>
  <td><?php if ($c['status'] === 'requested'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="text" name="note" placeholder="Note to member (optional)" maxlength="250" style="width:180px">
      <button class="btn tiny dark" name="action" value="approve" type="submit" onclick="return confirm('Approve? The tipster loses this earning and you must refund the member yourself.');">Approve</button>
      <button class="btn tiny" name="action" value="reject" type="submit">Reject</button></form>
    <?php else: ?><?= e(ucfirst($c['status'])) ?><br><small><?= e($c['note']) ?></small><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">No claims yet.</p><?php endif; ?></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
