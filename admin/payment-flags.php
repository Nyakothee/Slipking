<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('payments');
$pdo = db();
paytools_ready();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $pdo->prepare('UPDATE payment_flags SET resolved = 1 WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    admin_log('payflag.resolve', 'flag#' . (int)($_POST['id'] ?? 0), '');
    redirect('admin/payment-flags.php');
}
$rows = $pdo->query('SELECT f.*, p.user_id, p.amount, p.currency, u.email FROM payment_flags f JOIN payments p ON p.id = f.payment_id JOIN users u ON u.id = p.user_id ORDER BY f.resolved, f.id DESC LIMIT 200')->fetchAll();
$admin_title = 'Payment flags';
$admin_active = 'payflags';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel">
  <p style="color:var(--mute)">Double payments, too-low and too-high M-Pesa amounts. Handle the refund or top-up by hand, then press Done.</p>
  <table class="tbl"><tr><th>When</th><th>Type</th><th>Member</th><th>Payment</th><th>Note</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
    <tr<?= $r['resolved'] ? ' style="opacity:.5"' : '' ?>><td><?= e(date('j M H:i', strtotime($r['created_at']))) ?></td><td><b><?= e($r['kind']) ?></b></td><td><?= e($r['email']) ?></td>
      <td><a href="<?= e(url('admin/payment-view.php?id=' . (int)$r['payment_id'])) ?>">#<?= (int)$r['payment_id'] ?></a> <?= e($r['currency'] . ' ' . $r['amount']) ?></td><td><?= e($r['note']) ?></td>
      <td><?php if (!$r['resolved']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn dark small" type="submit">Done</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="6" style="color:var(--mute)">Nothing flagged.</td></tr><?php endif; ?>
  </table>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
