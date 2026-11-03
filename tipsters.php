<?php
require_once __DIR__ . '/includes/init.php';

$sort = in_array($_GET['sort'] ?? '', ['rating', 'winrate', 'newest', 'subs'], true) ? $_GET['sort'] : 'rating';
$q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$list = tipsters_directory($sort, $q);
$mine = ($u = current_user()) ? tipster_for_user((int)$u['id']) : null;

$page_title = 'Tipsters - follow top betting tipsters - ' . SITE_NAME;
$page_desc  = 'Compare independent tipsters by verified record and member ratings, then subscribe to the ones you trust.';
$active     = 'tipsters';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Tipsters</h1>
  <p><a class="btn outline" href="<?= e(url('leaderboard.php')) ?>">View the leaderboard</a> <a class="btn outline" href="<?= e(url('account-management.php')) ?>">Account management</a></p>
  <p style="color:var(--mute)">Independent tipsters sell their own picks here. Every result is recorded by the site and ratings come only from members who bought a plan. Tips from tipsters are not our own tips.</p>
  <form class="filters" method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">
    <input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Search name, country or speciality" aria-label="Search tipsters" style="flex:1;min-width:200px">
    <select name="sort" aria-label="Sort by">
      <option value="rating"<?= $sort === 'rating' ? ' selected' : '' ?>>Best rated</option>
      <option value="winrate"<?= $sort === 'winrate' ? ' selected' : '' ?>>Highest win rate</option>
      <option value="subs"<?= $sort === 'subs' ? ' selected' : '' ?>>Most subscribers</option>
      <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest</option>
    </select>
    <button class="btn dark" type="submit">Show</button>
  </form>
</div>
<section style="padding-top:8px">
  <div class="wrap">
    <?php if ($list): ?>
    <div class="tp-grid">
      <?php foreach ($list as $t): ?>
      <a class="tp-card" href="<?= e(tipster_url($t)) ?>">
        <div class="tp-head"><?= tipster_avatar($t, 56) ?>
          <div><strong><?= e($t['display_name']) ?></strong> <?= tipster_flag($t, 14) ?>
            <div><?= tipster_stars((float)$t['rating_avg'], (int)$t['rating_count']) ?> <small><?= (int)$t['rating_count'] ? e(number_format((float)$t['rating_avg'], 1)) . ' (' . (int)$t['rating_count'] . ')' : 'No ratings yet' ?></small></div></div></div>
        <?php if ($t['tagline']): ?><p class="tp-tag"><?= e($t['tagline']) ?></p><?php endif; ?>
        <div class="tp-nums">
          <span><b><?= $t['win_rate'] !== null ? (int)$t['win_rate'] . '%' : '-' ?></b> win rate</span>
          <span><b><?= (int)$t['settled'] ?></b> settled tips</span>
          <span><b><?= (int)$t['subs'] ?></b> subscribers</span>
        </div>
        <div class="tp-foot"><?= $t['from_kes'] !== null ? 'From KES ' . e(number_format((float)$t['from_kes'], 0)) : 'No plans yet' ?><?= $t['specialties'] ? ' &middot; ' . e($t['specialties']) : '' ?></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="note">No tipsters match your search yet.</div>
    <?php endif; ?>
    <div class="box2" style="margin-top:26px">
      <h3>Are you a tipster?</h3>
      <?php if ($mine): ?>
        <p>Your tipster profile status: <strong><?= e(ucfirst($mine['status'])) ?></strong>.</p>
        <p><a class="btn dark" href="<?= e(url('tipster/index.php')) ?>">Open your tipster dashboard</a></p>
      <?php elseif (setting('tipster_signups_open', '1') === '1'): ?>
        <p>Build a following, sell subscriptions and get paid. The site keeps a commission of each sale and pays you the rest.</p>
        <p><a class="btn gold" href="<?= e(url('auth/register-tipster.php')) ?>">Become a tipster</a></p>
      <?php else: ?>
        <p>Tipster applications are closed right now. Please check back later.</p>
      <?php endif; ?>
    </div>
    <div class="note">Betting involves risk. Past results do not guarantee future results. 18+ only.</div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
