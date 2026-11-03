<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();

$st = db()->prepare("SELECT py.*, p.name AS plan_name FROM payments py JOIN plans p ON p.id = py.plan_id
                     WHERE py.user_id = ? ORDER BY py.created_at DESC LIMIT 100");
$st->execute([$user['id']]);
$rows = $st->fetchAll();

$page_title = 'Payment history - ' . SITE_NAME;
$active = '';
$acct   = 'payments';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><h2>Payment history</h2></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
    <?php if ($rows): ?>
      <div class="scroll"><table class="tbl">
        <thead><tr><th>Date</th><th>Plan</th><th>Method</th><th>Amount</th><th>Receipt / reference</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $cls = ['paid' => 'won', 'pending' => 'pending', 'failed' => 'lost', 'cancelled' => 'lost'][$r['status']] ?? 'pending'; ?>
          <tr>
            <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
            <td><?= e($r['plan_name']) ?></td>
            <td><?= method_label($r['method']) ?></td>
            <td><?= e($r['currency']) ?> <?= e(number_format((float)$r['amount'], 2)) ?></td>
            <td><?= e($r['mpesa_receipt'] ?: $r['reference']) ?></td>
            <td><span class="badge <?= $cls ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <p style="margin-top:14px;color:var(--mute);font-size:14px">A payment marked Pending that never completes will show as Failed. If money left your account but your plan is not active, contact us with the M-Pesa or PayPal confirmation.</p>
    <?php else: ?>
      <div class="empty">You have not made any payments yet. <a href="<?= e(url('vip.php')) ?>" style="text-decoration:underline;font-weight:700">See VIP plans</a></div>
    <?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
