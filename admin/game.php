<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_perm('tips');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
        redirect('admin/game.php');
    }
    $a = $_POST['action'] ?? '';
    if ($a === 'final') {
        $mid = (int)($_POST['match_id'] ?? 0);
        $h = $_POST['home'] ?? ''; $aw = $_POST['away'] ?? '';
        $st = $pdo->prepare('SELECT home_team, away_team, kickoff_at FROM matches WHERE id = ?');
        $st->execute([$mid]);
        $m = $st->fetch();
        if (!$m) { set_flash('error', 'Match not found.'); }
        elseif (!ctype_digit((string)$h) || !ctype_digit((string)$aw) || (int)$h > 30 || (int)$aw > 30) { set_flash('error', 'Enter the final score as two whole numbers.'); }
        elseif (strtotime($m['kickoff_at']) > time()) { set_flash('error', 'That match has not started yet.'); }
        else {
            $n = match_set_final($pdo, $mid, (int)$h, (int)$aw);
            admin_log('game.final', 'match#' . $mid, $m['home_team'] . ' ' . (int)$h . '-' . (int)$aw . ' ' . $m['away_team'] . ', picks settled: ' . $n);
            set_flash('success', 'Final score saved. ' . $n . ' game picks settled.');
        }
    } elseif ($a === 'void') {
        $mid = (int)($_POST['match_id'] ?? 0);
        $n = game_void_match($pdo, $mid);
        admin_log('game.void', 'match#' . $mid, 'picks voided: ' . $n);
        set_flash('success', 'Match voided. ' . $n . ' picks cancelled.');
    } elseif ($a === 'settings') {
        $clean = function ($s) { return implode(',', array_filter(array_map('intval', preg_split('/[\s,]+/', (string)$s)), function ($d) { return $d > 0 && $d <= 365; })); };
        set_setting('game_points_correct', (string)max(1, min(20, (int)($_POST['points'] ?? 3))));
        set_setting('game_min_picks', (string)max(1, min(100, (int)($_POST['min'] ?? 5))));
        set_setting('game_weekly_prizes', $clean($_POST['weekly'] ?? ''));
        set_setting('game_monthly_prizes', $clean($_POST['monthly'] ?? ''));
        admin_log('game.settings', '', 'updated');
        set_flash('success', 'Game settings saved.');
    } elseif ($a === 'award' && is_admin()) {
        $type = ($_POST['type'] ?? '') === 'month' ? 'month' : 'week';
        $lines = game_award_prizes($type, -1);
        admin_log('game.award', $type, implode(' | ', $lines));
        set_flash('success', 'Last ' . $type . ': ' . implode(' ', $lines));
    }
    redirect('admin/game.php');
}

$ms = $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score, m.live_status,
                          (SELECT COUNT(*) FROM game_picks p WHERE p.match_id = m.id AND p.status = 'open') AS open_picks,
                          (SELECT COUNT(*) FROM game_picks p WHERE p.match_id = m.id) AS all_picks
                   FROM matches m
                   WHERE m.kickoff_at < NOW() AND m.kickoff_at > NOW() - INTERVAL 5 DAY
                     AND EXISTS (SELECT 1 FROM game_picks p WHERE p.match_id = m.id)
                   ORDER BY (SELECT COUNT(*) FROM game_picks p WHERE p.match_id = m.id AND p.status = 'open') DESC, m.kickoff_at DESC LIMIT 60")->fetchAll();
$prizes = $pdo->query("SELECT g.*, u.name FROM game_prizes g JOIN users u ON u.id = g.user_id ORDER BY g.awarded_at DESC LIMIT 30")->fetchAll();
$wk = game_period('week', -1); $mo = game_period('month', -1);

$admin_title = 'Prediction game';
$admin_active = 'game';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Free prediction game</h1>
<p class="sub">Members pick 1/X/2 for points. Scores are entered here (or by the optional cron job). Prizes are free VIP days.</p>

<div class="panel"><h2>Matches waiting for a final score</h2>
<?php if ($ms): ?><div class="scroll"><table class="tbl"><thead><tr><th>Match</th><th>Kickoff</th><th>Picks</th><th>Final score</th></tr></thead><tbody>
<?php foreach ($ms as $m): ?>
<tr><td><?= e($m['home_team'] . ' vs ' . $m['away_team']) ?></td><td><?= e(date('j M, H:i', strtotime($m['kickoff_at']))) ?></td>
  <td><?= (int)$m['open_picks'] ?> open / <?= (int)$m['all_picks'] ?></td>
  <td><form method="post" class="inl" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="final"><input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
    <input type="number" name="home" min="0" max="30" value="<?= $m['home_score'] !== null ? (int)$m['home_score'] : '' ?>" style="width:60px" required aria-label="Home goals"> -
    <input type="number" name="away" min="0" max="30" value="<?= $m['away_score'] !== null ? (int)$m['away_score'] : '' ?>" style="width:60px" required aria-label="Away goals">
    <button class="btn tiny dark" type="submit">Save final</button></form>
    <form method="post" class="inl" onsubmit="return confirm('Void this match? All picks on it are cancelled.');"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>"><button class="btn tiny" type="submit" style="margin-top:6px">Void (postponed)</button></form></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">No finished matches with picks in the last 5 days.</p><?php endif; ?>
<p style="color:var(--mute);margin-top:8px">Saving a final score also shows it on the live page and settles every open pick. It does not settle betting tips (use Tips for that).</p></div>

<div class="panel"><h2>Points and prizes</h2>
<form method="post" class="grid2" style="display:grid;gap:12px;max-width:560px"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <label>Points for a correct pick <input type="number" name="points" min="1" max="20" value="<?= (int)game_points_correct() ?>"></label>
  <label>Settled picks needed to win a prize <input type="number" name="min" min="1" max="100" value="<?= (int)setting('game_min_picks', '5') ?>"></label>
  <label>Weekly prizes (VIP days for #1, #2, #3, comma separated) <input type="text" name="weekly" value="<?= e(setting('game_weekly_prizes', '3,2,1')) ?>"></label>
  <label>Monthly prizes (VIP days) <input type="text" name="monthly" value="<?= e(setting('game_monthly_prizes', '10,5,3')) ?>"></label>
  <div><button class="btn dark" type="submit">Save</button></div></form></div>

<div class="panel"><h2>Give prizes</h2>
<p>Prizes are given automatically by <code>cron/game-prizes.php</code> (once a day). You can also do it by hand. It is safe to press twice: nobody is paid twice.</p>
<?php if (is_admin()): ?>
<form method="post" class="inl" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="award"><input type="hidden" name="type" value="week"><button class="btn dark" type="submit">Award last week (<?= e($wk['label']) ?>)</button></form>
<form method="post" class="inl" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="award"><input type="hidden" name="type" value="month"><button class="btn dark" type="submit">Award last month (<?= e($mo['label']) ?>)</button></form>
<?php else: ?><p style="color:var(--mute)">Only an administrator can give prizes.</p><?php endif; ?>
<?php if ($prizes): ?><div class="scroll" style="margin-top:14px"><table class="tbl"><thead><tr><th>When</th><th>Period</th><th>Rank</th><th>Member</th><th>Points</th><th>VIP days</th></tr></thead><tbody>
<?php foreach ($prizes as $p): ?><tr><td><?= e(date('j M Y', strtotime($p['awarded_at']))) ?></td><td><?= e($p['period_type'] . ' ' . $p['period_key']) ?></td><td>#<?= (int)$p['rank_no'] ?></td><td><?= e($p['name']) ?> (<?= (int)$p['user_id'] ?>)</td><td><?= (int)$p['points'] ?></td><td><?= (int)$p['days'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
