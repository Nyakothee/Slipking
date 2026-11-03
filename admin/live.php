<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/results-api.php';
require_perm('fixtures');
$pdo = db();
$statuses = ['NS' => 'Not started', '1H' => 'First half', 'HT' => 'Half time', '2H' => 'Second half', 'ET' => 'Extra time', 'FT' => 'Full time', 'PST' => 'Postponed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'admin/live.php' . (!empty($_POST['m']) ? '?m=' . (int)$_POST['m'] : '');
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
        redirect($back);
    }
    $a = $_POST['action'] ?? '';
    $mid = (int)($_POST['match_id'] ?? 0);
    if ($a === 'toggle') {
        set_setting('live_enabled', !empty($_POST['enabled']) ? '1' : '0');
        admin_log('live.toggle', '', !empty($_POST['enabled']) ? 'on' : 'off');
        set_flash('success', 'Saved.');
    } elseif ($a === 'score') {
        $st = $pdo->prepare('SELECT home_team, away_team FROM matches WHERE id = ?');
        $st->execute([$mid]);
        $m = $st->fetch();
        $s = $_POST['status'] ?? 'NS';
        $h = $_POST['home'] ?? ''; $aw = $_POST['away'] ?? '';
        $min = $_POST['minute'] ?? '';
        if (!$m || !isset($statuses[$s])) { set_flash('error', 'Choose a match and a status.'); }
        elseif (($h !== '' && !ctype_digit((string)$h)) || ($aw !== '' && !ctype_digit((string)$aw)) || ($min !== '' && !ctype_digit((string)$min)) || (int)$h > 30 || (int)$aw > 30 || (int)$min > 130) { set_flash('error', 'Score and minute must be numbers.'); }
        elseif ($s === 'FT') {
            if ($h === '' || $aw === '') { set_flash('error', 'Enter the final score.'); }
            else {
                $n = match_set_final($pdo, $mid, (int)$h, (int)$aw);
                admin_log('live.final', 'match#' . $mid, $m['home_team'] . ' ' . (int)$h . '-' . (int)$aw . ' ' . $m['away_team'] . ' (game picks settled: ' . $n . ')');
                set_flash('success', 'Full time saved. ' . $n . ' game picks settled.');
            }
        } elseif ($s === 'PST') {
            $n = game_void_match($pdo, $mid);
            admin_log('live.postponed', 'match#' . $mid, 'picks voided: ' . $n);
            set_flash('success', 'Marked postponed. ' . $n . ' game picks cancelled.');
        } else {
            $pdo->prepare('UPDATE matches SET live_status = ?, live_minute = ?, home_score = ?, away_score = ?, score_updated_at = NOW() WHERE id = ?')
                ->execute([$s === 'NS' ? null : $s, $min === '' ? null : (int)$min, $h === '' ? null : (int)$h, $aw === '' ? null : (int)$aw, $mid]);
            set_flash('success', 'Score updated.');
        }
    } elseif ($a === 'event') {
        $type = $_POST['type'] ?? '';
        $team = $_POST['team'] ?? 'none';
        $min = $_POST['minute'] ?? '';
        if (!in_array($type, ['goal', 'own_goal', 'penalty', 'yellow', 'red', 'sub', 'var', 'info'], true) || !in_array($team, ['home', 'away', 'none'], true) || !ctype_digit((string)$min) || (int)$min > 130) {
            set_flash('error', 'Check the event: type, side and minute.');
        } else {
            $pdo->prepare('INSERT INTO match_events (match_id, minute, extra, type, team, player, detail, ext_key) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$mid, (int)$min, ($_POST['extra'] ?? '') !== '' && ctype_digit((string)$_POST['extra']) ? (int)$_POST['extra'] : null, $type, $team,
                           mb_substr(post_str('player'), 0, 60), mb_substr(post_str('detail'), 0, 120), 'm-' . bin2hex(random_bytes(8))]);
            set_flash('success', 'Event added.');
        }
    } elseif ($a === 'del_event') {
        $pdo->prepare('DELETE FROM match_events WHERE id = ? AND match_id = ?')->execute([(int)($_POST['id'] ?? 0), $mid]);
        set_flash('success', 'Event removed.');
    }
    redirect($back);
}

$sel = (int)($_GET['m'] ?? 0);
$ms = $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, m.live_status, m.live_minute, m.home_score, m.away_score, l.name AS league
                   FROM matches m JOIN leagues l ON l.id = m.league_id
                   WHERE m.kickoff_at > NOW() - INTERVAL 1 DAY AND m.kickoff_at < NOW() + INTERVAL 1 DAY ORDER BY m.kickoff_at ASC LIMIT 150")->fetchAll();
$cur = null;
foreach ($ms as $m) { if ((int)$m['id'] === $sel) { $cur = $m; } }
$events = $cur ? live_events((int)$cur['id']) : [];
$on = setting('live_enabled', '0') === '1';

$admin_title = 'Live scores';
$admin_active = 'live';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Live scores</h1>
<p class="sub">Scores and events shown on the Live page and match pages. They can come from the API-Football feed (cron) or from you, by hand.</p>
<div class="panel">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
    <label><input type="checkbox" name="enabled" value="1"<?= $on ? ' checked' : '' ?>> Fetch live scores automatically (needs <code>cron/live-scores.php</code> every 2 minutes, a service key and fixture numbers)</label>
    <button class="btn dark tiny" type="submit" style="margin-left:10px">Save</button></form>
  <p style="color:var(--mute);margin-top:8px">Service key: <?= (function_exists('results_ready') && results_ready()) ? 'set' : 'not set (includes/config-results.php)' ?>. Live updates use many requests; check your API-Football plan limits. Free plans are small. Fully manual entry below always works.</p>
</div>
<div class="panel"><h2>Choose a match (yesterday to tomorrow)</h2>
<?php if ($ms): ?><div class="scroll"><table class="tbl"><thead><tr><th>Kickoff</th><th>Match</th><th>Score</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($ms as $m): ?><tr<?= $cur && (int)$cur['id'] === (int)$m['id'] ? ' style="background:#fff8e6"' : '' ?>><td><?= e(date('D j M H:i', strtotime($m['kickoff_at']))) ?></td><td><?= e($m['home_team'] . ' vs ' . $m['away_team']) ?><br><small><?= e($m['league']) ?></small></td>
  <td><?= $m['home_score'] !== null ? (int)$m['home_score'] . ' - ' . (int)$m['away_score'] : '-' ?></td><td><?= e(live_status_label($m['live_status'], $m['live_minute'] !== null ? (int)$m['live_minute'] : null) ?: '-') ?></td>
  <td><a class="btn tiny dark" href="<?= e(url('admin/live.php?m=' . (int)$m['id'])) ?>#edit">Edit</a></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">No matches in this window.</p><?php endif; ?></div>

<?php if ($cur): ?>
<div class="panel" id="edit"><h2><?= e($cur['home_team'] . ' vs ' . $cur['away_team']) ?></h2>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="score"><input type="hidden" name="match_id" value="<?= (int)$cur['id'] ?>"><input type="hidden" name="m" value="<?= (int)$cur['id'] ?>">
    <label>Status <select name="status"><?php foreach ($statuses as $k => $v): ?><option value="<?= e($k) ?>"<?= ($cur['live_status'] ?? 'NS') === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label>Minute <input type="number" name="minute" min="0" max="130" value="<?= $cur['live_minute'] !== null ? (int)$cur['live_minute'] : '' ?>" style="width:80px"></label>
    <label>Home <input type="number" name="home" min="0" max="30" value="<?= $cur['home_score'] !== null ? (int)$cur['home_score'] : '' ?>" style="width:70px"></label>
    <label>Away <input type="number" name="away" min="0" max="30" value="<?= $cur['away_score'] !== null ? (int)$cur['away_score'] : '' ?>" style="width:70px"></label>
    <button class="btn dark" type="submit">Save</button></form>
  <p style="color:var(--mute);margin-top:6px">"Full time" also settles the free prediction game. Betting tips are settled in Tips (or by the auto-results cron).</p>
  <h3 style="margin-top:18px">Events</h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><?= csrf_field() ?><input type="hidden" name="action" value="event"><input type="hidden" name="match_id" value="<?= (int)$cur['id'] ?>"><input type="hidden" name="m" value="<?= (int)$cur['id'] ?>">
    <label>Minute <input type="number" name="minute" min="0" max="130" required style="width:70px"></label>
    <label>+ <input type="number" name="extra" min="0" max="20" style="width:60px"></label>
    <label>Type <select name="type"><?php foreach (['goal' => 'Goal', 'penalty' => 'Penalty goal', 'own_goal' => 'Own goal', 'yellow' => 'Yellow card', 'red' => 'Red card', 'sub' => 'Substitution', 'var' => 'VAR', 'info' => 'Note'] as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
    <label>Side <select name="team"><option value="home"><?= e($cur['home_team']) ?></option><option value="away"><?= e($cur['away_team']) ?></option><option value="none">-</option></select></label>
    <label>Player <input type="text" name="player" maxlength="60"></label>
    <label>Detail <input type="text" name="detail" maxlength="120"></label>
    <button class="btn dark" type="submit">Add event</button></form>
  <?php if ($events): ?><table class="tbl" style="margin-top:12px"><tbody><?php foreach ($events as $ev): ?><tr><td><?= (int)$ev['minute'] . ($ev['extra'] ? '+' . (int)$ev['extra'] : '') ?>'</td><td><?= live_event_icon($ev['type']) ?> <?= e($ev['type']) ?></td><td><?= e($ev['team']) ?></td><td><?= e($ev['player']) ?> <small><?= e($ev['detail']) ?></small></td>
    <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="action" value="del_event"><input type="hidden" name="match_id" value="<?= (int)$cur['id'] ?>"><input type="hidden" name="m" value="<?= (int)$cur['id'] ?>"><input type="hidden" name="id" value="<?= (int)$ev['id'] ?>"><button class="btn tiny" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
