<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
if (!odds_on()) { require __DIR__ . '/404.php'; exit; }
$all = odds_sports_all();
$sel = (string)($_GET['s'] ?? '');
if (!isset($all[$sel])) { $sel = ''; }
$rows = odds_upcoming($sel);
$page_title = 'Football odds comparison - best prices - ' . SITE_NAME;
$page_desc = 'Compare 1X2 odds from many bookmakers for upcoming football matches and see the best price and how odds moved.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('odds.php')]);
$active = 'football';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Odds comparison</h1>
  <p style="color:var(--mute);max-width:700px">Best 1X2 prices across bookmakers for upcoming matches. We do not take bets. 18+ only.</p>
  <?= stats_tabs('odds') ?>
  <form method="get" style="display:flex;gap:10px;margin:10px 0"><select name="s" aria-label="League"><option value="">All leagues</option>
    <?php foreach (odds_selected_sports() as $k): ?><option value="<?= e($k) ?>"<?= $sel === $k ? ' selected' : '' ?>><?= e($all[$k]) ?></option><?php endforeach; ?></select>
    <button class="btn dark" type="submit">Show</button></form>
</div>
<section style="padding-top:6px"><div class="wrap">
<?php if (!$rows): ?><div class="note" style="margin-top:0">No odds loaded right now. Check back later.</div>
<?php else: ?>
  <div class="scroll"><table class="bm-table odds-t"><thead><tr><th>Kickoff</th><th>Match</th><th>Best 1</th><th>Best X</th><th>Best 2</th><th>Books</th></tr></thead><tbody>
  <?php foreach ($rows as $ev): $s = odds_summary(odds_quotes((int)$ev['id'])); ?>
    <tr><td><?= e(stats_local($ev['commence_at'], 'D j M, H:i')) ?><br><small><?= e($all[$ev['sport_key']] ?? '') ?></small></td>
      <td><a href="<?= e(url('odds-event.php?id=' . (int)$ev['id'])) ?>"><?= e($ev['home_team']) ?> vs <?= e($ev['away_team']) ?></a></td>
      <td><?= $s['best_home'] !== null ? number_format($s['best_home'], 2) : '-' ?></td><td><?= $s['best_draw'] !== null ? number_format($s['best_draw'], 2) : '-' ?></td><td><?= $s['best_away'] !== null ? number_format($s['best_away'], 2) : '-' ?></td><td><?= (int)$ev['books'] ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php endif; ?>
  <?= bookmaker_18_line() ?>
  <div class="note">Odds come from The Odds API and can be a few hours old. Always check the price at the bookmaker. <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Gamble responsibly</a>.</div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
