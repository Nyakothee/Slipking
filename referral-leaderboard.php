<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$per = ($_GET['p'] ?? '') === 'month' ? 'month' : 'all';
$rows = referral_leaderboard($per, 30);
$me = current_user();
$page_title = 'Top referrers - ' . SITE_NAME;
$page_desc  = 'Members who brought the most friends to ' . SITE_NAME . '. Invite friends and earn free VIP days.';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Top referrers</h1>
  <p style="color:var(--mute);max-width:680px">Ranked by friends who joined through a member's link and became paying VIPs. Names are shortened for privacy. Every paying friend earns the member free VIP days.</p>
  <div class="tabs" style="margin:12px 0"><a href="<?= e(url('referral-leaderboard.php?p=month')) ?>"<?= $per === 'month' ? ' class="on"' : '' ?>>This month</a><a href="<?= e(url('referral-leaderboard.php')) ?>"<?= $per === 'all' ? ' class="on"' : '' ?>>All time</a></div>
</div>
<section style="padding-top:6px"><div class="wrap">
<?php if ($rows): ?>
  <div class="scroll"><table class="bm-table lb"><thead><tr><th>#</th><th>Member</th><th>Friends who became VIP</th><th>Friends joined</th></tr></thead><tbody>
  <?php foreach ($rows as $i => $r): ?><tr><td><strong><?= $i + 1 ?></strong></td><td><?= e(mask_name((string)$r['name'])) ?></td><td><strong><?= (int)$r['paid'] ?></strong></td><td><?= (int)$r['joined'] ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="note">No paid referrals yet in this period. Be the first!</div><?php endif; ?>
  <p style="margin-top:16px"><a class="btn gold" href="<?= e(url($me ? 'account/referrals.php' : 'auth/register.php')) ?>"><?= $me ? 'Get my invite link' : 'Create an account to invite friends' ?></a></p>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
