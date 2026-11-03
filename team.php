<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
$id = (int)($_GET['id'] ?? 0);
$comp = stats_on() && $code !== '' ? stats_competition($code) : null;
$row = $comp && $id ? stats_team_row($code, $id) : null;
if (!$row) { require __DIR__ . '/404.php'; exit; }
$home = null; $away = null;
foreach (['HOME', 'AWAY'] as $t) {
    $st = db()->prepare('SELECT * FROM stat_standings WHERE comp_code = ? AND type = ? AND team_id = ?');
    $st->execute([$code, $t, $id]);
    ${strtolower($t)} = $st->fetch() ?: null;
}
$form = stats_form($id, 6);
$results = stats_team_matches($id, 'results', 8);
$next = stats_team_matches($id, 'fixtures', 6);
$page_title = $row['team_name'] . ' - form, results, fixtures and predictions - ' . SITE_NAME;
$page_desc  = $row['team_name'] . ': ' . ordinal((int)$row['position']) . ' in the ' . $comp['name'] . ' with ' . (int)$row['points'] . ' points. Recent form, next fixtures and match predictions.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('team.php?c=' . $code . '&id=' . $id)]);
$active = 'football';
$gp = max(1, (int)$row['played']);
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('football.php')) ?>">Leagues</a> / <a href="<?= e(url('league-table.php?c=' . $code)) ?>"><?= e($comp['name']) ?></a> / <?= e($row['team_name']) ?></div>
  <h1><?= stats_crest($row['crest'], 44) ?> <?= e($row['team_name']) ?></h1>
  <p style="color:var(--mute)"><?= ordinal((int)$row['position']) ?> in the <?= e($comp['name']) ?> &middot; <?= (int)$row['points'] ?> points from <?= (int)$row['played'] ?> games &middot; form <?= stats_form_html($form) ?></p>
</div>
<section style="padding-top:6px"><div class="wrap two">
  <div>
    <div class="gm-stats">
      <div><small>Record</small><b><?= (int)$row['won'] ?>-<?= (int)$row['draw'] ?>-<?= (int)$row['lost'] ?></b></div>
      <div><small>Goals for / game</small><b><?= number_format($row['gf'] / $gp, 2) ?></b></div>
      <div><small>Goals against / game</small><b><?= number_format($row['ga'] / $gp, 2) ?></b></div>
      <div><small>Goal difference</small><b><?= (int)$row['gd'] > 0 ? '+' : '' ?><?= (int)$row['gd'] ?></b></div>
    </div>
    <?php if ($home && $away): ?>
    <div class="scroll"><table class="bm-table"><thead><tr><th></th><th>P</th><th>W</th><th>D</th><th>L</th><th>GF</th><th>GA</th><th>Pts</th></tr></thead><tbody>
      <?php foreach (['Home' => $home, 'Away' => $away] as $lab => $x): ?><tr><td><strong><?= $lab ?></strong></td><td><?= (int)$x['played'] ?></td><td><?= (int)$x['won'] ?></td><td><?= (int)$x['draw'] ?></td><td><?= (int)$x['lost'] ?></td><td><?= (int)$x['gf'] ?></td><td><?= (int)$x['ga'] ?></td><td><?= (int)$x['points'] ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <h2 style="margin-top:20px">Latest results</h2>
    <?php foreach ($results as $m): $isHome = (int)$m['home_id'] === $id; $f = $isHome ? $m['home_goals'] : $m['away_goals']; $a = $isHome ? $m['away_goals'] : $m['home_goals']; $r = $f > $a ? 'W' : ($f < $a ? 'L' : 'D'); ?>
      <div class="fx-row"><a href="<?= e(url('fixture.php?id=' . (int)$m['id'])) ?>"><span class="fx-time"><?= e(stats_local($m['kick_utc'], 'j M')) ?></span>
        <span class="fx-teams"><?= e($m['home_name']) ?> <em>vs</em> <?= e($m['away_name']) ?></span><b class="fx-score"><?= (int)$m['home_goals'] ?> - <?= (int)$m['away_goals'] ?></b> <span class="fm fm-<?= strtolower($r) ?>"><?= $r ?></span></a></div>
    <?php endforeach; if (!$results): ?><div class="note" style="margin-top:0">No results yet.</div><?php endif; ?>
  </div>
  <div>
    <h2>Next fixtures</h2>
    <?php foreach ($next as $m): $p = stats_predict($m); ?>
      <div class="fx-row"><a href="<?= e(url('fixture.php?id=' . (int)$m['id'])) ?>"><span class="fx-time"><?= e(stats_local($m['kick_utc'], 'D j M, H:i')) ?></span>
        <span class="fx-teams"><?= e($m['home_name']) ?> <em>vs</em> <?= e($m['away_name']) ?></span></a><?php if ($p): ?><?= stats_prob_bar($p) ?><?php endif; ?></div>
    <?php endforeach; if (!$next): ?><div class="note" style="margin-top:0">No fixtures scheduled.</div><?php endif; ?>
  </div>
</div></section>
<div class="wrap"><?= stats_disclaimer() ?></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
