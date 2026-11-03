<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$english_only = true;
$user = current_user(); $uid = (int)$user['id'];
$ready = odds_watch_ready();
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    db()->prepare('DELETE FROM odds_watch WHERE user_id = ? AND event_id = ?')->execute([$uid, (int)($_POST['event'] ?? 0)]);
    set_flash('success', 'Odds alert removed.');
    redirect('account/odds-watch.php');
}
$rows = [];
if ($ready) {
    try { $q = db()->prepare('SELECT e.id, e.home_team, e.away_team, e.commence_at FROM odds_watch w JOIN odds_events e ON e.id = w.event_id WHERE w.user_id = ? ORDER BY e.commence_at ASC'); $q->execute([$uid]); $rows = $q->fetchAll(); } catch (Throwable $e) { }
}
$page_title = 'Odds alerts - ' . SITE_NAME;
$active = ''; $acct = 'oddswatch';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Odds alerts</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <p style="color:var(--mute);max-width:720px">You are told when the best price for a match you watch moves by 5% or more (at most once every 3 hours per match). Open a match on the <a href="<?= e(url('odds.php')) ?>">odds page</a> and tap "Alert me when the odds move". Delivery follows your <a href="<?= e(url('account/alerts.php')) ?>">Alerts</a> settings.</p>
  <?php if (!$rows): ?><div class="note">You are not watching any match yet.</div><?php else: ?>
  <div class="box2"><?php foreach ($rows as $r): ?>
    <form method="post" style="display:flex;gap:10px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line,#ddd)"><?= csrf_field() ?><input type="hidden" name="event" value="<?= (int)$r['id'] ?>">
      <a href="<?= e(url('odds-event.php?id=' . (int)$r['id'])) ?>"><b><?= e($r['home_team'] . ' vs ' . $r['away_team']) ?></b></a><small><?= e(stats_local($r['commence_at'], 'D j M, H:i')) ?></small><button class="btn small ghost" type="submit">Stop</button></form>
  <?php endforeach; ?></div><?php endif; ?>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
