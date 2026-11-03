<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();

$user = current_user();
$vip  = is_vip();
$pdo  = db();

$st = $pdo->prepare('SELECT email_verified FROM users WHERE id = ?');
$st->execute([$user['id']]);
$verified = (bool)$st->fetchColumn();

$st = $pdo->prepare("SELECT s.ends_at, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                     WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW() ORDER BY s.ends_at DESC LIMIT 1");
$st->execute([$user['id']]);
$plan = $st->fetch() ?: null;

$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'pending' AND created_at > NOW() - INTERVAL 30 MINUTE");
$st->execute([$user['id']]);
$pendingPay = (int)$st->fetchColumn();

$st = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$st->execute([$user['id']]);
$unread = (int)$st->fetchColumn();

$vipToday    = $vip ? fetch_tips(['vip' => 1, 'today' => true, 'limit' => 30]) : [];
$vipTomorrow = $vip ? fetch_tips(['vip' => 1, 'date' => date('Y-m-d', strtotime('+1 day')), 'limit' => 30]) : [];
$freeToday   = fetch_tips(['vip' => 0, 'today' => true, 'limit' => 6]);

$page_title = 'My account - ' . SITE_NAME;
$active = '';
$acct   = 'dashboard';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><div class="hello"><?= user_avatar_html($user, 56) ?><div><h2>Hello, <?= e(explode(' ', $user['name'])[0]) ?> <?= flag_html(flag_iso($user), 18) ?></h2><p><?= e($user['email']) ?> · <a href="<?= e(url('account/profile.php')) ?>">Edit picture and flag</a></p></div></div></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>

    <?php if (!$verified): ?>
      <div class="banner"><span>Please confirm your email address. We sent you a link when you signed up.</span>
        <form method="post" action="<?= e(url('auth/verify-email.php')) ?>"><?= csrf_field() ?><button class="btn dark small" type="submit">Send the link again</button></form></div>
    <?php endif; ?>
    <?php if (sms_enabled() && !empty($user['mobile']) && empty($user['mobile_verified'])): ?>
      <div class="banner"><span>Please confirm your mobile number with the SMS code. You need this before you can pay.</span>
        <a class="btn dark small" href="<?= e(url('auth/verify-phone.php')) ?>">Confirm now</a></div>
    <?php endif; ?>
    <?php if ($pendingPay): ?>
      <div class="banner"><span>Your payment is being confirmed. This usually takes under a minute. Refresh this page shortly.</span></div>
    <?php endif; ?>
    <?php if ($unread): ?>
      <div class="banner"><span>Unread messages: <?= $unread ?></span><a class="btn dark small" href="<?= e(url('account/notifications.php')) ?>">Read</a></div>
    <?php endif; ?>

    <?php [$trialOk, $trialWhy] = trial_eligibility($user); if ($trialOk): ?>
      <div class="banner"><span>Try every VIP pick free for <?= max(1, (int)setting('trial_days', '1')) ?> day(s).</span>
        <form method="post" action="<?= e(url('account/trial.php')) ?>"><?= csrf_field() ?><button class="btn gold small" type="submit">Start my free trial</button></form></div>
    <?php endif; ?>

    <?php if ($plan): ?>
      <div class="status-active">Your <?= e($plan['name']) ?> plan is active until <?= e(date('j F Y, H:i', strtotime($plan['ends_at']))) ?>.</div>
    <?php elseif (!$vip): ?>
      <div class="banner"><span>You are on the free plan. VIP unlocks every premium pick.</span><a class="btn gold small" href="<?= e(url('vip.php')) ?>">See VIP plans</a></div>
    <?php endif; ?>

    <?php if ($vip): ?>
      <div class="head"><h2>VIP picks today</h2><?php if (has_vip_acca()): ?><a class="btn gold" href="<?= e(url('vip-accumulators.php')) ?>">VIP Accumulator</a><?php endif; ?></div>
      <?php if ($vipToday): ?><div class="grid"><?php foreach ($vipToday as $t) { tip_card($t, true); } ?></div>
      <?php else: ?><div class="empty">No VIP picks are posted for today yet. Check back soon.</div><?php endif; ?>

      <?php if ($vipTomorrow): ?>
        <div class="head" style="margin-top:34px"><h2>VIP picks tomorrow</h2></div>
        <div class="grid"><?php foreach ($vipTomorrow as $t) { tip_card($t, true); } ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="head" style="margin-top:34px"><h2>Free tips today</h2><a class="btn dark" href="<?= e(url('tips.php')) ?>">All tips</a></div>
    <?php if ($freeToday): ?><div class="grid"><?php foreach ($freeToday as $t) { tip_card($t, $vip); } ?></div>
    <?php else: ?><div class="empty">No free tips yet today.</div><?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
