<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$ev = odds_on() ? odds_event((int)($_GET['id'] ?? 0)) : null;
if (!$ev) { require __DIR__ . '/404.php'; exit; }
$ou = current_user(); $watching = false; $owReady = odds_watch_ready(); $eid = (int)$ev['id'];
if ($owReady && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['watch', 'unwatch'], true)) {
    if (!$ou) { set_flash('error', 'Log in to get odds alerts.'); redirect('auth/login.php?next=' . rawurlencode('odds-event.php?id=' . $eid)); }
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('odds-event.php?id=' . $eid); }
    if ($_POST['action'] === 'watch') {
        $cnt = db()->prepare('SELECT COUNT(*) FROM odds_watch WHERE user_id = ?'); $cnt->execute([(int)$ou['id']]);
        if ((int)$cnt->fetchColumn() >= 30) { set_flash('error', 'You can watch up to 30 matches. Remove some first.'); redirect('odds-event.php?id=' . $eid); }
        $last = db()->prepare('SELECT best_home, best_draw, best_away FROM odds_history WHERE event_id = ? ORDER BY fetched_at DESC, id DESC LIMIT 1'); $last->execute([$eid]); $lr = $last->fetch() ?: ['best_home' => null, 'best_draw' => null, 'best_away' => null];
        db()->prepare('INSERT IGNORE INTO odds_watch (user_id, event_id, base_home, base_draw, base_away) VALUES (?,?,?,?,?)')->execute([(int)$ou['id'], $eid, $lr['best_home'], $lr['best_draw'], $lr['best_away']]);
        set_flash('success', 'Done. We will alert you when the best odds move by 5% or more.');
    } else {
        db()->prepare('DELETE FROM odds_watch WHERE user_id = ? AND event_id = ?')->execute([(int)$ou['id'], $eid]);
        set_flash('success', 'Odds alert removed.');
    }
    redirect('odds-event.php?id=' . $eid);
}
if ($owReady && $ou) { $wq = db()->prepare('SELECT 1 FROM odds_watch WHERE user_id = ? AND event_id = ?'); $wq->execute([(int)$ou['id'], $eid]); $watching = (bool)$wq->fetchColumn(); }
$fx = null;
try {
    $ts = strtotime($ev['commence_at'] . ' UTC');
    $q = db()->prepare("SELECT m.*, c.name AS comp_name FROM stat_matches m LEFT JOIN stat_competitions c ON c.code = m.comp_code WHERE m.kick_utc BETWEEN ? AND ? LIMIT 200");
    $q->execute([gmdate('Y-m-d H:i:s', $ts - 36 * 3600), gmdate('Y-m-d H:i:s', $ts + 36 * 3600)]);
    foreach ($q->fetchAll() as $x) { if (team_same($x['home_name'], $ev['home_team']) && team_same($x['away_name'], $ev['away_team'])) { $fx = $x; break; } }
} catch (Throwable $e) {}
$pred = $fx ? stats_predict($fx) : null;
$page_title = $ev['home_team'] . ' vs ' . $ev['away_team'] . ' odds comparison - ' . SITE_NAME;
$page_desc = 'Compare bookmaker odds for ' . $ev['home_team'] . ' vs ' . $ev['away_team'] . ', see the best price and how the odds moved.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('odds-event.php?id=' . (int)$ev['id'])]);
$active = 'football';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('odds.php')) ?>">Odds comparison</a></div>
  <h1 class="mtitle"><?= e($ev['home_team']) ?> vs <?= e($ev['away_team']) ?></h1>
  <p style="color:var(--mute)"><?= e(stats_local($ev['commence_at'], 'l j F Y, H:i')) ?><?= $fx ? ' &middot; <a href="' . e(url('fixture.php?id=' . (int)$fx['id'])) . '">Match preview and stats</a>' : '' ?></p>
</div>
<section style="padding-top:6px"><div class="wrap">
  <?php if ($owReady): ?><form method="post" style="margin:0 0 12px"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $watching ? 'unwatch' : 'watch' ?>"><button class="btn <?= $watching ? 'ghost' : 'gold' ?>" type="submit"><?= $watching ? '&#128276; Watching the odds (tap to stop)' : '&#128276; Alert me when the odds move' ?></button> <small style="color:var(--mute)">Push, e-mail or in-app, as set in <a href="<?= e(url('account/alerts.php')) ?>">Alerts</a>. 5% move or more.</small></form><?php endif; ?>
  <?= odds_compare_html($ev, $pred) ?>
  <div class="box2" style="margin:14px 0"><h3>Odds movement (average of all bookmakers)</h3>
    <?php $c = odds_chart_svg(odds_history_rows((int)$ev['id'])); echo $c !== '' ? $c : '<p style="color:var(--mute)">The chart appears after a few updates.</p>'; ?></div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
