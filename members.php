<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$sort = in_array($_GET['sort'] ?? '', ['units', 'winrate', 'followers'], true) ? $_GET['sort'] : 'units';
$q = strtolower(trim((string)($_GET['q'] ?? '')));
$q = preg_match('/^[a-z0-9_]{1,20}$/', $q) ? $q : '';
$rows = [];
try { $rows = members_top($sort, 5, 50, $q); } catch (Throwable $e) { error_log('members: ' . $e->getMessage()); }
$page_title = 'Top members - shared betting records - ' . SITE_NAME;
$page_desc = 'Members who share their tracked bets. Follow the ones you like. Self-reported, not betting advice.';
$active = 'members';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/members.css')) . '?v=1">' . "\n" . '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n";
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Top members</h1>
  <p style="color:var(--mute);max-width:720px">Members who share their tracked bets. Ranked in units (a flat 1-unit stake per bet), minimum 5 settled bets. Records are <strong>self-reported and not verified</strong>.</p>
  <div class="hub-tabs"><a href="<?= e(url('discuss.php?tab=communities')) ?>">Communities</a><a href="<?= e(url('discuss.php?tab=matches')) ?>">Match rooms &amp; polls</a><a class="on" href="<?= e(url('members.php')) ?>">Top members</a></div>
  <form class="mb-search" method="get"><input name="q" value="<?= e($q) ?>" maxlength="20" placeholder="Find a username" aria-label="Find a username"><input type="hidden" name="sort" value="<?= e($sort) ?>"><button class="btn dark small">Search</button></form>
  <div class="rm-sort"><?php foreach (['units' => 'Most units', 'winrate' => 'Win rate', 'followers' => 'Followers'] as $k => $l): ?><a href="<?= e(url('members.php?sort=' . $k . ($q !== '' ? '&q=' . $q : ''))) ?>" class="<?= $sort === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></div>
  <?php if ($rows): ?><div class="mb-list">
    <?php foreach ($rows as $i => $r): ?>
    <a class="mb-row" href="<?= e(url('member.php?u=' . $r['handle'])) ?>"><span class="mb-rank"><?= $i + 1 ?></span><?= user_avatar_html($r, 44) ?>
      <span class="mb-nm"><strong>@<?= e($r['handle']) ?> <?= member_flag($r, 13) ?></strong><small><?= $r['bio'] !== '' ? e(mb_strimwidth($r['bio'], 0, 70, '...')) : (int)$r['settled'] . ' settled bets' ?></small></span>
      <span class="mb-n"><b class="<?= (float)$r['units'] >= 0 ? 'won' : 'lost' ?>"><?= ((float)$r['units'] > 0 ? '+' : '') . e(number_format((float)$r['units'], 2)) ?></b><small>units</small></span>
      <span class="mb-n"><b><?= $r['win_rate'] !== null ? e($r['win_rate']) . '%' : '-' ?></b><small>win rate</small></span>
      <span class="mb-n"><b><?= (int)$r['followers'] ?></b><small>followers</small></span></a>
    <?php endforeach; ?></div>
  <?php else: ?><div class="note">No public members with enough shared bets yet. <?php if (current_user()): ?><a href="<?= e(url('account/public-profile.php')) ?>" style="text-decoration:underline">Make your public profile</a> and share your tracked bets.<?php else: ?><a href="<?= e(url('auth/register.php')) ?>" style="text-decoration:underline">Create an account</a> to track and share your bets.<?php endif; ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
