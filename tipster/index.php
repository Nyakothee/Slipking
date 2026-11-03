<?php
require_once dirname(__DIR__) . '/includes/init.php';
$t = require_tipster(false);
$pdo = db();
$stats = tipster_stats((int)$t['id']);
$bal = tipster_balance((int)$t['id']);
$st = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM tipster_subscriptions WHERE tipster_id = ? AND ends_at > NOW()');
$st->execute([$t['id']]);
$subs = (int)$st->fetchColumn();
$pl = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE tipster_id = ? AND is_active = 1');
$pl->execute([$t['id']]);
$plansN = (int)$pl->fetchColumn();

$page_title = 'Tipster dashboard - ' . SITE_NAME;
$active = 'tipster-index';
$tnav = 'index';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2><?= e($t['display_name']) ?></h2><p>Your tipster dashboard</p></div></div>
  <?php include dirname(__DIR__) . '/includes/tipster-nav.php'; ?>

  <?php if (empty($t['avatar_url'])): ?><div class="banner"><span><strong>Your profile photo is required.</strong> Upload one so members can see who you are.</span> <a class="btn gold small" href="<?= e(url('tipster/profile.php')) ?>">Upload photo</a></div><?php endif; ?>
  <?php if (in_array($t['status'], ['pending', 'active'], true) && tipster_fee_owed($t)): $feeA = tipster_fee_amount(current_user()); ?><div class="banner"><span><strong>One-time joining fee: <?= e($feeA['label']) ?>.</strong> Until it is paid you cannot post tips or create plans, and members cannot find or subscribe to you.<?= $t['status'] === 'pending' ? ' We review your application after it is paid.' : '' ?></span> <a class="btn gold small" href="<?= e(tipster_fee_pay_url()) ?>">Pay <?= e($feeA['label']) ?></a></div><?php endif; ?>
  <?php if ($t['status'] === 'pending'): ?><div class="banner"><span>Your application is being reviewed. You will get an email when it is approved. You can already prepare your profile.</span></div>
  <?php elseif ($t['status'] === 'rejected'): ?><div class="banner"><span>Your application was not approved.<?= $t['admin_note'] ? ' Reason: ' . e($t['admin_note']) : '' ?></span></div>
  <?php elseif ($t['status'] === 'suspended'): ?><div class="banner"><span>Your profile is paused.<?= $t['admin_note'] ? ' Reason: ' . e($t['admin_note']) : '' ?> Contact support if you think this is a mistake.</span></div>
  <?php else: ?>
    <p><a href="<?= e(tipster_url($t)) ?>">View your public profile</a></p>
    <?php if (!$plansN): ?><div class="banner"><span>You have no active plan yet. Members cannot subscribe until you create one.</span> <a class="btn dark small" href="<?= e(url('tipster/plans.php')) ?>">Create a plan</a></div><?php endif; ?>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><b><?= (int)$subs ?></b><span>active subscribers</span></div>
    <div class="stat"><b><?= (int)$t['rating_count'] ? e(number_format((float)$t['rating_avg'], 1)) . ' / 5' : '-' ?></b><span><?= (int)$t['rating_count'] ?> rating<?= (int)$t['rating_count'] === 1 ? '' : 's' ?></span></div>
    <div class="stat"><b><?= $stats['win_rate'] !== null ? (int)$stats['win_rate'] . '%' : '-' ?></b><span>win rate (<?= (int)$stats['won'] ?>-<?= (int)$stats['lost'] ?>)</span></div>
    <div class="stat"><b>KES <?= e(number_format($bal['available'], 0)) ?></b><span>available to withdraw</span></div>
  </div>
  <div class="note">Your tips are judged by the site's own record: they are timestamped when you post them, you cannot edit the pick afterwards, and an admin (or the results feed) marks them won or lost.</div>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
