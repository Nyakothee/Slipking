<?php
require_once __DIR__ . '/includes/init.php';

$periods = ['30' => ['Last 30 days', 30], '90' => ['Last 90 days', 90], 'all' => ['All time', 0]];
$per  = isset($periods[$_GET['period'] ?? '']) ? $_GET['period'] : '30';
$sort = in_array($_GET['sort'] ?? '', ['units', 'winrate', 'rating'], true) ? $_GET['sort'] : 'units';
$rows = leaderboard_rows($periods[$per][1], $sort);
$min  = $periods[$per][1] > 0 ? 5 : 10;

$page_title = 'Tipster leaderboard - ' . SITE_NAME;
$page_desc  = 'See which tipsters are on top: profit, win rate and member ratings, from results recorded by ' . SITE_NAME . '.';
$active     = 'tipsters';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('tipsters.php')) ?>">Tipsters</a> / Leaderboard</div>
  <h1>Tipster leaderboard</h1>
  <p style="color:var(--mute)">Ranked from results recorded by <?= e(SITE_NAME) ?>. Profit is in units (1 unit staked on every tip). Only tipsters with at least <?= (int)$min ?> settled tips in the period are listed, and removed tips still count.</p>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0">
    <select name="period" aria-label="Period"><?php foreach ($periods as $k => $v): ?><option value="<?= e($k) ?>"<?= $per === (string)$k ? ' selected' : '' ?>><?= e($v[0]) ?></option><?php endforeach; ?></select>
    <select name="sort" aria-label="Rank by"><option value="units"<?= $sort === 'units' ? ' selected' : '' ?>>Rank by profit</option><option value="winrate"<?= $sort === 'winrate' ? ' selected' : '' ?>>Rank by win rate</option><option value="rating"<?= $sort === 'rating' ? ' selected' : '' ?>>Rank by member rating</option></select>
    <button class="btn dark" type="submit">Show</button>
  </form>
</div>
<section style="padding-top:8px"><div class="wrap">
  <?php if ($rows): ?>
  <div class="scroll"><table class="bm-table lb"><thead><tr><th>#</th><th>Tipster</th><th>Profit</th><th>Win rate</th><th>Record</th><th>Avg odds</th><th>Rating</th><th>Subscribers</th></tr></thead><tbody>
  <?php foreach ($rows as $i => $r): $u = round((float)$r['units'], 1); ?>
    <tr><td><strong><?= $i + 1 ?></strong></td>
      <td><a href="<?= e(tipster_url($r)) ?>" class="lb-name"><?= tipster_avatar($r, 32) ?> <strong><?= e($r['display_name']) ?></strong> <?= tipster_flag($r, 13) ?></a></td>
      <td style="color:var(--<?= $u >= 0 ? 'win' : 'loss' ?>);font-weight:700"><?= ($u > 0 ? '+' : '') . e(number_format($u, 1)) ?></td>
      <td><?= (int)$r['win_rate'] ?>%</td><td><?= (int)$r['w'] ?>-<?= (int)$r['l'] ?></td><td><?= e(number_format((float)$r['avg_odds'], 2)) ?></td>
      <td><?= (int)$r['rating_count'] ? tipster_stars((float)$r['rating_avg'], (int)$r['rating_count']) . ' <small>' . e(number_format((float)$r['rating_avg'], 1)) . '</small>' : '<small>-</small>' ?></td>
      <td><?= (int)$r['subs'] ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php else: ?><div class="note">Nobody has enough settled tips in this period yet. Try "All time" or come back soon.</div><?php endif; ?>
  <div class="note">Past results do not guarantee future results. Betting involves risk. 18+ only.</div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
