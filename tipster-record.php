<?php
require_once __DIR__ . '/includes/init.php';
$t = tipster_by_slug((string)($_GET['slug'] ?? ''));
$viewer = current_user();
$isOwner = $t && $viewer && (int)$t['user_id'] === (int)$viewer['id'];
if (!$t || ((!tipster_is_live($t)) && !$isOwner && !(function_exists('is_staff') && is_staff()))) { require __DIR__ . '/404.php'; exit; }
$english_only = true;
$tid = (int)$t['id'];
$stats = tipster_stats($tid);
$full = tipster_full_stats($tid);
$rows = array_slice($full['rows'], 0, 1000);
$changes = tipster_changes($tid);
$months = tipster_monthly($tid, 12);
$verified = tipster_verified($t, $stats);
$last10 = array_slice(array_values(array_filter($rows, function ($r) { return in_array($r['status'], ['won', 'lost'], true); })), 0, 10);
$page_title = $t['display_name'] . ' - verified results - ' . SITE_NAME;
$page_desc = 'Every tip ever posted by ' . $t['display_name'] . ', with posting time, odds and result. Nothing can be edited or hidden.';
$active = 'tipsters';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('tipsters.php')) ?>">Tipsters</a> / <a href="<?= e(tipster_url($t)) ?>"><?= e($t['display_name']) ?></a> / Results</div>
  <h1><?= e($t['display_name']) ?>: verified results <?php if ($verified): ?><span class="tp-badge ok">&#10003; Verified record</span><?php endif; ?></h1>
  <p style="color:var(--mute);max-width:720px">This is the complete public record kept by <?= e(SITE_NAME) ?>. Tips are saved with the time they were posted, results are entered by the site (not the tipster), and a tip removed before kickoff stays listed. Profit is in units (1 unit on every tip).</p>
</div>
<section style="padding-top:6px"><div class="wrap">
  <div class="tp-nums tp-record">
    <span><b><?= $stats['win_rate'] !== null ? (int)$stats['win_rate'] . '%' : '-' ?></b> win rate</span>
    <span><b><?= (int)$stats['won'] ?>-<?= (int)$stats['lost'] ?></b> won-lost</span>
    <span><b><?= e($stats['avg_odds']) ?></b> average odds</span>
    <span><b><?= e($stats['units']) ?></b> units profit</span>
  </div>
  <?= tipster_profile_panel($full) ?>
  <?php if ($last10): ?><p>Last <?= count($last10) ?> settled: <?php foreach ($last10 as $f): ?><span class="badge <?= e($f['status']) ?>" style="margin-right:3px"><?= $f['status'] === 'won' ? 'W' : 'L' ?></span><?php endforeach; ?></p><?php endif; ?>
  <?php if (!$verified): ?><p class="note" style="margin-top:8px">The "Verified record" badge appears after 30 days on the platform and 20 settled tips.</p><?php endif; ?>
  <div class="rc-box"><div class="rc-head"><h3>Profit by month (units)</h3></div>
    <?= tipster_chart_html($months) ?>
    <?= tipster_stake_calc_html((float)$stats['units_raw'], tipster_units_days($tid, 30)) ?></div>

  <h2 style="margin-top:24px">Every tip</h2>
  <?php if ($rows): ?>
  <div class="scroll"><table class="bm-table"><thead><tr><th>Match</th><th>League</th><th>Kickoff</th><th>Posted</th><th>Market and pick</th><th>Odds</th><th>Result</th></tr></thead><tbody>
  <?php foreach ($rows as $r):
      $see = $r['status'] !== 'pending' || !$r['is_vip'] || $isOwner || tip_access(['tipster_id' => $tid, 'is_vip' => 1]);
      $removed = $r['deleted_at'] !== null; ?>
    <tr><td><?= e($r['home_team'] . ' vs ' . $r['away_team']) ?></td><td><?= e($r['league']) ?></td><td><?= e(date('j M y, H:i', strtotime($r['kickoff_at']))) ?></td>
      <td><?= e(date('j M y, H:i', strtotime($r['posted_at']))) ?></td>
      <td><?= $see ? e($r['market'] . ': ') . '<strong>' . e($r['pick']) . '</strong>' : '<em>Premium pick (hidden until the match is settled)</em>' ?><?= $r['is_vip'] ? ' <small>premium</small>' : ' <small>free</small>' ?></td>
      <td><?= $see ? e(number_format((float)$r['odds'], 2)) : '-' ?></td>
      <td><?php if ($removed): ?><small>Removed before kickoff</small><?php else: ?><span class="badge <?= e($r['status']) ?>"><?= e($r['status'] === 'void' ? (($r['void_kind'] ?? '') === 'push' ? 'Push' : 'Void') : ucfirst($r['status'])) ?></span><?php if ($r['settled_at']): ?><br><small>Settled <?= e(date('j M y, H:i', strtotime($r['settled_at']))) ?></small><?php endif; ?><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php else: ?><div class="note">No tips yet.</div><?php endif; ?>
  <h2 style="margin-top:24px">Edited and cancelled tips</h2>
  <?php if ($changes): ?>
  <div class="scroll"><table class="bm-table"><thead><tr><th>When</th><th>Match</th><th>What happened</th><th>By</th></tr></thead><tbody>
  <?php foreach ($changes as $c): ?>
    <tr><td><?= e(date('j M y, H:i', strtotime($c['created_at']))) ?></td><td><?= e($c['home_team'] . ' vs ' . $c['away_team']) ?></td>
      <td><span class="badge <?= $c['kind'] === 'cancelled' ? 'lost' : 'pending' ?>"><?= e(ucfirst($c['kind'])) ?></span> <?= e($c['detail']) ?></td><td><?= e($c['by_role'] === 'tipster' ? 'Tipster' : 'Site admin') ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php else: ?><div class="note">No tip has been edited or cancelled since this record started.</div><?php endif; ?>
  <div class="note">Past results do not promise future results. Bookmaker odds can differ from the odds posted. 18+ only.</div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
