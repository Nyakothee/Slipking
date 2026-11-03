<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$m = stats_on() ? stats_match((int)($_GET['id'] ?? 0)) : null;
if (!$m) { require __DIR__ . '/404.php'; exit; }
$finished = $m['status'] === 'FINISHED';
$prev = stats_preview($m);
$pred = $finished ? null : stats_predict($m);
$h2h = ($m['home_id'] && $m['away_id']) ? stats_h2h((int)$m['home_id'], (int)$m['away_id'], 8) : [];
$fh = $m['home_id'] ? stats_form((int)$m['home_id']) : [];
$fa = $m['away_id'] ? stats_form((int)$m['away_id']) : [];
// is it also one of our own matches? then link to our tips
$site = null;
try {
    $q = db()->prepare("SELECT m.id, m.home_team, m.away_team FROM matches m WHERE m.kickoff_at BETWEEN ? AND ? LIMIT 120");
    $loc = strtotime($m['kick_utc'] . ' UTC');
    $q->execute([date('Y-m-d H:i:s', $loc - 36 * 3600), date('Y-m-d H:i:s', $loc + 36 * 3600)]);
    foreach ($q->fetchAll() as $x) { if (team_same($x['home_team'], $m['home_name']) && team_same($x['away_team'], $m['away_name'])) { $site = $x; break; } }
} catch (Throwable $e) {}
$oddsEv = function_exists('odds_find_event') && odds_on() ? odds_find_event($m['home_name'], $m['away_name'], $m['kick_utc']) : null;

$title = $m['home_name'] . ' vs ' . $m['away_name'] . ($finished ? ' result and stats' : ' prediction, preview and stats');
$page_title = $title . ' - ' . SITE_NAME;
$page_desc  = seo_trim(($prev[0] ?? $title), 158);
$ld = ['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => $m['home_name'] . ' vs ' . $m['away_name'], 'startDate' => gmdate('c', strtotime($m['kick_utc'] . ' UTC')),
       'sport' => 'Football', 'homeTeam' => ['@type' => 'SportsTeam', 'name' => $m['home_name']], 'awayTeam' => ['@type' => 'SportsTeam', 'name' => $m['away_name']], 'url' => url('fixture.php?id=' . (int)$m['id'])];
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('fixture.php?id=' . (int)$m['id'])]) . seo_jsonld($ld);
$active = 'football';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('football.php')) ?>">Leagues</a> / <a href="<?= e(url('league-table.php?c=' . rawurlencode($m['comp_code']))) ?>"><?= e($m['comp_name'] ?: $m['comp_code']) ?></a></div>
  <h1 class="mtitle"><?= e($m['home_name']) ?> vs <?= e($m['away_name']) ?></h1>
  <p style="color:var(--mute)"><?= e(stats_local($m['kick_utc'], 'l j F Y, H:i')) ?><?= $m['matchday'] ? ' &middot; Matchday ' . (int)$m['matchday'] : '' ?><?= $finished ? ' &middot; <strong>Final: ' . (int)$m['home_goals'] . ' - ' . (int)$m['away_goals'] . '</strong>' : '' ?></p>
  <?php if ($site): ?><p><a class="btn gold" href="<?= e(url('match.php?id=' . (int)$site['id'])) ?>">See our tips for this match</a></p><?php endif; ?>
</div>
<section style="padding-top:6px"><div class="wrap two">
  <div>
    <h2>Match preview</h2>
    <?php foreach ($prev as $para): ?><p style="margin-bottom:10px"><?= e($para) ?></p><?php endforeach; ?>
    <?php if ($pred): ?>
      <div class="box2" style="margin:14px 0"><h3>Statistical estimate</h3>
        <div class="pb-labels"><span><?= e($m['home_name']) ?></span><span>Draw</span><span><?= e($m['away_name']) ?></span></div>
        <?= stats_prob_bar($pred) ?>
        <div class="gm-stats"><div><small>Over 2.5 goals</small><b><?= e(round($pred['over25'])) ?>%</b></div><div><small>Both teams score</small><b><?= e(round($pred['btts'])) ?>%</b></div>
          <div><small>Expected goals</small><b><?= e($pred['xg_home']) ?> - <?= e($pred['xg_away']) ?></b></div></div>
      </div>
    <?php endif; ?>
    <?php if ($oddsEv): echo odds_compare_html($oddsEv, $pred); endif; ?>
  </div>
  <div>
    <div class="box2"><h3>Form</h3>
      <p><strong><?= e($m['home_name']) ?></strong> <?= stats_form_html($fh) ?></p>
      <p><strong><?= e($m['away_name']) ?></strong> <?= stats_form_html($fa) ?></p>
      <?php if ($m['home_id']): ?><p style="font-size:14px"><a href="<?= e(url('team.php?c=' . rawurlencode($m['comp_code']) . '&id=' . (int)$m['home_id'])) ?>"><?= e($m['home_name']) ?> page</a> &middot; <a href="<?= e(url('team.php?c=' . rawurlencode($m['comp_code']) . '&id=' . (int)$m['away_id'])) ?>"><?= e($m['away_name']) ?> page</a></p><?php endif; ?></div>
    <?php if ($h2h): ?><div class="box2" style="margin-top:14px"><h3>Head to head</h3>
      <?php foreach ($h2h as $x): ?><div style="font-size:14px;padding:3px 0"><?= e(stats_local($x['kick_utc'], 'j M Y')) ?> &nbsp; <?= e($x['home_name']) ?> <strong><?= (int)$x['home_goals'] ?> - <?= (int)$x['away_goals'] ?></strong> <?= e($x['away_name']) ?></div><?php endforeach; ?>
      <small style="color:var(--mute)">Only meetings stored on this site.</small></div><?php endif; ?>
  </div>
</div></section>
<div class="wrap"><?= stats_disclaimer() ?></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
