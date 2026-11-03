<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? '')));
$comp = stats_on() && $code !== '' ? stats_competition($code) : null;
if (!$comp) { require __DIR__ . '/404.php'; exit; }
$type = in_array($_GET['t'] ?? '', ['HOME', 'AWAY'], true) ? $_GET['t'] : 'TOTAL';
$view = in_array($_GET['v'] ?? '', ['results'], true) ? 'results' : 'fixtures';
$table = stats_table($code, $type);
$matches = stats_matches($code, $view === 'results' ? 'results' : 'upcoming', 30);
$n = count($table);
$page_title = $comp['name'] . ' table, fixtures and predictions - ' . SITE_NAME;
$page_desc  = $comp['name'] . ' league table, upcoming fixtures, latest results and match predictions.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('league-table.php?c=' . $code)]);
$active = 'football';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('football.php')) ?>">Leagues</a> / <?= e($comp['name']) ?></div>
  <h1><?= stats_crest($comp['emblem'], 38) ?> <?= e($comp['name']) ?></h1>
  <p style="color:var(--mute)">Updated <?= e($comp['synced_at'] ? date('j M, H:i', strtotime($comp['synced_at'])) : '-') ?>. Free data can be a little behind live play.</p>
  <?= stats_tabs('football') ?>
</div>
<section style="padding-top:6px"><div class="wrap two">
  <div>
    <div class="tabs" style="margin-bottom:10px"><?php foreach (['TOTAL' => 'Overall', 'HOME' => 'Home', 'AWAY' => 'Away'] as $k => $l): ?><a href="<?= e(url('league-table.php?c=' . $code . '&t=' . $k)) ?>"<?= $type === $k ? ' class="on"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></div>
    <?php if ($table): ?>
    <div class="scroll"><table class="bm-table lt"><thead><tr><th>#</th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GF</th><th>GA</th><th>GD</th><th>Pts</th><th>Form</th></tr></thead><tbody>
    <?php foreach ($table as $r): $cls = $r['position'] <= 4 ? 'lt-top' : ($n > 12 && $r['position'] > $n - 3 ? 'lt-bot' : ''); ?>
      <tr class="<?= $cls ?>"><td><?= (int)$r['position'] ?></td>
        <td><?= stats_crest($r['crest'], 20) ?> <a href="<?= e(url('team.php?c=' . $code . '&id=' . (int)$r['team_id'])) ?>"><?= e($r['team_name']) ?></a></td>
        <td><?= (int)$r['played'] ?></td><td><?= (int)$r['won'] ?></td><td><?= (int)$r['draw'] ?></td><td><?= (int)$r['lost'] ?></td><td><?= (int)$r['gf'] ?></td><td><?= (int)$r['ga'] ?></td><td><?= (int)$r['gd'] ?></td><td><strong><?= (int)$r['points'] ?></strong></td>
        <td><?= $r['form'] ? stats_form_html(array_slice(array_reverse(array_map(function ($x) { return strtoupper(trim($x)); }, explode(',', (string)$r['form']))), 0, 5)) : '' ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php else: ?><div class="note">No table yet.</div><?php endif; ?>
  </div>
  <div>
    <div class="tabs" style="margin-bottom:10px"><a href="<?= e(url('league-table.php?c=' . $code)) ?>"<?= $view === 'fixtures' ? ' class="on"' : '' ?>>Fixtures</a><a href="<?= e(url('league-table.php?c=' . $code . '&v=results')) ?>"<?= $view === 'results' ? ' class="on"' : '' ?>>Results</a></div>
    <?php if (!$matches): ?><div class="note" style="margin-top:0">Nothing to show.</div><?php endif; ?>
    <?php foreach ($matches as $m): $p = $view === 'fixtures' ? stats_predict($m) : null; ?>
      <div class="fx-row"><a href="<?= e(url('fixture.php?id=' . (int)$m['id'])) ?>">
        <span class="fx-time"><?= e(stats_local($m['kick_utc'], $view === 'results' ? 'j M' : 'D j M, H:i')) ?></span>
        <span class="fx-teams"><?= stats_crest($m['home_crest'], 18) ?> <?= e($m['home_name']) ?> <em>vs</em> <?= stats_crest($m['away_crest'], 18) ?> <?= e($m['away_name']) ?></span>
        <?php if ($view === 'results'): ?><b class="fx-score"><?= (int)$m['home_goals'] ?> - <?= (int)$m['away_goals'] ?></b><?php endif; ?></a>
        <?php if ($p): ?><?= stats_prob_bar($p) ?><?php endif; ?></div>
    <?php endforeach; ?>
  </div>
</div></section>
<div class="wrap"><?= stats_disclaimer() ?></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
