<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();

$ref = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['ref'] ?? '')));
$st  = db()->prepare('SELECT py.*, p.name AS plan_name, p.slug AS plan_slug, p.tipster_id FROM payments py JOIN plans p ON p.id = py.plan_id WHERE py.reference = ? AND py.user_id = ? LIMIT 1');
$st->execute([$ref, $user['id']]);
$p = $st->fetch();
if (!$p) {
    redirect('account/payments.php');
}

$ends = null;
$isFee = ($p['plan_slug'] ?? '') === TIPSTER_FEE_SLUG;
if ($p['status'] === 'paid' && empty($p['tipster_id']) && !$isFee) {
    $st = db()->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ? AND status = 'active' AND ends_at > NOW()");
    $st->execute([$user['id']]);
    $ends = $st->fetchColumn();
}

$page_title = 'Payment - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="fcard center">
      <?php if ($p['status'] === 'paid'): ?>
        <h2>Payment received</h2>
        <p class="sub"><?php if ($ends): ?>Thank you. Your <?= e($p['plan_name']) ?> plan is active until <?= e(date('j F Y, H:i', strtotime($ends))) ?>.<?php else: ?>Thank you. Your <?= e($p['plan_name']) ?> plan is active.<?php endif; ?></p>
        <table class="kv" style="text-align:left">
          <tr><td>Amount</td><td><?= e($p['currency']) ?> <?= e(number_format((float)$p['amount'], 2)) ?></td></tr>
          <tr><td>Method</td><td><?= method_label($p['method']) ?></td></tr>
          <tr><td>Receipt</td><td><?= e($p['mpesa_receipt'] ?: $p['reference']) ?></td></tr>
        </table>
        <p style="margin-top:18px"><a class="btn gold full" href="<?= e(url($isFee ? 'tipster/index.php' : 'account/dashboard.php')) ?>"><?= $isFee ? 'Go to my tipster dashboard' : 'See my VIP picks' ?></a></p>
      <?php elseif ($p['status'] === 'pending'): ?>
        <h2>Still confirming</h2>
        <p class="sub">We have not received the confirmation yet. If you entered your PIN, your plan will switch on by itself within a few minutes.</p>
        <p><a class="btn dark full" href="<?= e(url('account/payments.php')) ?>">Check my payments</a></p>
      <?php else: ?>
        <h2>Payment not completed</h2>
        <p class="sub">This payment did not go through.</p>
        <p><a class="btn gold full" href="<?= e(url('vip.php')) ?>">Try again</a></p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
