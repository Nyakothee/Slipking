<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$have = user_badges($uid);
$cat = badge_catalog();
$streak = user_streak($uid);
$pts = ['week' => game_user_points($uid, 'week'), 'month' => game_user_points($uid, 'month'), 'all' => game_user_points($uid)];
$page_title = 'Rewards - ' . SITE_NAME;
$active = '';
$acct = 'badges';
$english_only = true;
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Rewards</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <div class="gm-stats">
    <div><small>Day streak</small><b><?= (int)$streak['current_streak'] ?></b></div>
    <div><small>Best streak</small><b><?= (int)$streak['best_streak'] ?></b></div>
    <div><small>Game points (week)</small><b><?= (int)$pts['week'] ?></b></div>
    <div><small>Game points (all time)</small><b><?= (int)$pts['all'] ?></b></div>
  </div>
  <p style="color:var(--mute)">Visit on consecutive days to grow your streak. Badges are earned automatically. <a href="<?= e(url('game.php')) ?>">Play the free prediction game</a> to earn points.</p>
  <div class="bd-grid">
  <?php foreach ($cat as $code => $b): $got = isset($have[$code]); ?>
    <div class="bd-card<?= $got ? ' got' : '' ?>"><div class="bd-ico"><?= badge_icon($b[2]) ?></div><strong><?= e($b[0]) ?></strong><small><?= e($b[1]) ?></small>
      <span class="bd-state"><?= $got ? 'Earned ' . e(date('j M Y', strtotime($have[$code]))) : 'Not yet' ?><?= $b[3] > 0 ? ' &middot; +' . (int)$b[3] . ' pts' : '' ?></span></div>
  <?php endforeach; ?>
  </div>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
