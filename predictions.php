<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$comps = stats_on() ? stats_competitions() : [];
$sel = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
$days = in_array((int)($_GET['d'] ?? 3), [1, 3, 7], true) ? (int)($_GET['d'] ?? 3) : 3;
$rows = [];
if ($comps) {
    $sql = "SELECT m.*, c.name AS comp_name FROM stat_matches m JOIN stat_competitions c ON c.code = m.comp_code
            WHERE m.status IN ('SCHEDULED','TIMED') AND m.kick_utc > UTC_TIMESTAMP() AND m.kick_utc < UTC_TIMESTAMP() + INTERVAL $days DAY" . ($sel !== '' ? ' AND m.comp_code = ?' : '') . ' ORDER BY m.kick_utc ASC LIMIT 80';
    $st = db()->prepare($sql);
    $st->execute($sel !== '' ? [$sel] : []);
    foreach ($st->fetchAll() as $m) { $m['p'] = stats_predict($m); $rows[] = $m; }
}
$page_title = 'Football match predictions today and this week - ' . SITE_NAME;
$page_desc  = 'Statistical football predictions for upcoming matches: win, draw and loss chances, over 2.5 goals and both teams to score.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('predictions.php')]);
$active = 'football';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Match predictions</h1>
  <p style="color:var(--mute);max-width:700px">Automatic statistical estimates for upcoming matches in the leagues we cover. For hand-picked tips from our analysts and tipsters see <a href="<?= e(url('tips.php')) ?>">today's tips</a>.</p>
  <?= stats_tabs('predictions') ?>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
    <select name="c" aria-label="League"><option value="">All leagues</option><?php foreach ($comps as $c): ?><option value="<?= e($c['code']) ?>"<?= $sel === $c['code'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select name="d" aria-label="Days ahead"><?php foreach ([1 => 'Next 24 hours', 3 => 'Next 3 days', 7 => 'Next 7 days'] as $k => $l): ?><option value="<?= $k ?>"<?= $days === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn dark" type="submit">Show</button></form>
</div>
<section style="padding-top:6px"><div class="wrap">
<?php if (!$comps): ?><div class="note" style="margin-top:0">Predictions are not available yet.</div>
<?php elseif (!$rows): ?><div class="note" style="margin-top:0">No matches in this window.</div>
<?php else: ?>
  <div class="scroll"><table class="bm-table pr"><thead><tr><th>Kickoff</th><th>Match</th><th>Home / Draw / Away</th><th>Over 2.5</th><th>BTTS</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $m): $p = $m['p']; ?>
    <tr><td><?= e(stats_local($m['kick_utc'], 'D j M, H:i')) ?><br><small><?= e($m['comp_name']) ?></small></td>
      <td><a href="<?= e(url('fixture.php?id=' . (int)$m['id'])) ?>"><?= e($m['home_name']) ?> vs <?= e($m['away_name']) ?></a></td>
      <td style="min-width:200px"><?= $p ? stats_prob_bar($p) : '<small>not enough data</small>' ?></td>
      <td><?= $p ? e(round($p['over25'])) . '%' : '-' ?></td><td><?= $p ? e(round($p['btts'])) . '%' : '-' ?></td>
      <td><a class="btn tiny dark" href="<?= e(url('fixture.php?id=' . (int)$m['id'])) ?>">Preview</a></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
  <?= stats_disclaimer() ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
