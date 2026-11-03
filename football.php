<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$comps = stats_on() ? stats_competitions() : [];
$page_title = 'Football leagues: tables, fixtures and predictions - ' . SITE_NAME;
$page_desc  = 'League tables, fixtures, results, team form and statistical match predictions for the top football leagues.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('football.php')]);
$active = 'football';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Football leagues</h1>
  <p style="color:var(--mute);max-width:700px">Live league tables, fixtures and results, team pages with form, and a statistical estimate for every upcoming match.</p>
  <?= stats_tabs('football') ?>
</div>
<section style="padding-top:6px"><div class="wrap">
<?php if (!$comps): ?>
  <div class="note" style="margin-top:0">League data is not available yet. It appears here once the site owner has connected the free football-data.org feed.</div>
<?php else: ?>
  <div class="grid">
  <?php foreach ($comps as $c): $top = stats_table($c['code']); $top = array_slice($top, 0, 3); $next = stats_matches($c['code'], 'upcoming', 3); ?>
    <div class="box2">
      <h3><?= stats_crest($c['emblem'], 30) ?> <a href="<?= e(url('league-table.php?c=' . rawurlencode($c['code']))) ?>"><?= e($c['name']) ?></a></h3>
      <p style="color:var(--mute);font-size:14px"><?= e($c['area'] ?: '') ?><?= $c['season'] ? ' &middot; ' . (int)$c['season'] . '/' . substr((string)((int)$c['season'] + 1), -2) : '' ?></p>
      <?php if ($top): ?><ol style="margin:8px 0"><?php foreach ($top as $r): ?><li><?= stats_crest($r['crest'], 18) ?> <a href="<?= e(url('team.php?c=' . rawurlencode($c['code']) . '&id=' . (int)$r['team_id'])) ?>"><?= e($r['team_name']) ?></a> <small><?= (int)$r['points'] ?> pts</small></li><?php endforeach; ?></ol><?php endif; ?>
      <?php if ($next): ?><p style="margin:8px 0 2px;font-weight:700">Next matches</p><?php foreach ($next as $m): ?><div style="font-size:14px"><a href="<?= e(url('fixture.php?id=' . (int)$m['id'])) ?>"><?= e($m['home_name'] . ' vs ' . $m['away_name']) ?></a> <small style="color:var(--mute)"><?= e(stats_local($m['kick_utc'], 'D j M')) ?></small></div><?php endforeach; endif; ?>
      <p style="margin-top:10px"><a class="btn dark tiny" href="<?= e(url('league-table.php?c=' . rawurlencode($c['code']))) ?>">Full table</a></p>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
