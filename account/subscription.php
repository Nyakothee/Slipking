<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();

$st = db()->prepare("SELECT s.starts_at, s.ends_at, s.status, p.name, p.duration_days
                     FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                     WHERE s.user_id = ? ORDER BY s.starts_at DESC LIMIT 50");
$st->execute([$user['id']]);
$subs = $st->fetchAll();

// Extra days are stored as a follow-on row, so show the running plan and the LAST end date.
$current = null;
$lastEnd = 0;
foreach ($subs as $s) {
    if ($s['status'] === 'active' && strtotime($s['ends_at']) > time()) {
        $lastEnd = max($lastEnd, strtotime($s['ends_at']));
        if (strtotime($s['starts_at']) <= time()) { $current = $s; }
    }
}
if ($current) { $current['ends_at'] = date('Y-m-d H:i:s', $lastEnd); }

$page_title = 'Subscription - ' . SITE_NAME;
$active = '';
$acct   = 'subscription';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><h2>Subscription</h2></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>

    <div class="box2" style="margin-bottom:26px;max-width:620px">
      <?php if ($current): ?>
        <h3>VIP is active</h3>
        <table class="kv">
          <tr><td>Plan</td><td><?= e($current['name']) ?></td></tr>
          <tr><td>Started</td><td><?= e(date('j F Y, H:i', strtotime($current['starts_at']))) ?></td></tr>
          <tr><td>Ends</td><td><?= e(date('j F Y, H:i', strtotime($current['ends_at']))) ?></td></tr>
          <tr><td>Days left</td><td><?= max(0, (int)ceil((strtotime($current['ends_at']) - time()) / 86400)) ?></td></tr>
        </table>
        <p style="margin-top:12px;color:var(--mute);font-size:15px">Your plan does not renew by itself. Buy another before it ends and the days are added on.</p>
        <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('vip.php')) ?>">Extend my plan</a></p>
      <?php else: ?>
        <h3>You are on the free plan</h3>
        <p>Unlock every VIP pick with a weekly, monthly or 3-month plan.</p>
        <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('vip.php')) ?>">See VIP plans</a></p>
      <?php endif; ?>
    </div>

    <?php if ($subs): ?>
      <div class="head"><h2 style="font-size:30px">History</h2></div>
      <div class="scroll"><table class="tbl">
        <thead><tr><th>Plan</th><th>From</th><th>To</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($subs as $s): $live = $s['status'] === 'active' && strtotime($s['ends_at']) > time(); ?>
          <tr><td><?= e($s['name']) ?></td><td><?= e(date('j M Y', strtotime($s['starts_at']))) ?></td><td><?= e(date('j M Y', strtotime($s['ends_at']))) ?></td>
              <td><span class="badge <?= $live ? 'won' : 'pending' ?>"><?= $live ? 'Active' : 'Ended' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
