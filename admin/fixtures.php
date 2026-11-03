<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/results-api.php';
require_perm('fixtures');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $a = $_POST['action'] ?? '';
        if ($a === 'toggle') {
            set_setting('auto_results_enabled', !empty($_POST['enabled']) ? '1' : '0');
            admin_log('autoresults.toggle', '', !empty($_POST['enabled']) ? 'on' : 'off');
            set_flash('success', 'Saved.');
        } elseif ($a === 'set') {
            $mid = (int)($_POST['match_id'] ?? 0);
            $fx  = trim((string)($_POST['fixture_id'] ?? ''));
            if ($fx !== '' && !ctype_digit($fx)) {
                set_flash('error', 'The fixture number must be digits only.');
            } else {
                $pdo->prepare('UPDATE matches SET api_fixture_id = ? WHERE id = ?')->execute([$fx === '' ? null : (int)$fx, $mid]);
                set_flash('success', 'Fixture saved.');
            }
        }
    }
    redirect('admin/fixtures.php' . (isset($_POST['date']) ? '?date=' . urlencode((string)$_POST['date']) . '&league=' . (int)($_POST['league'] ?? 0) : ''));
}

$date   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
$league = (int)($_GET['league'] ?? 0);
$found  = null;
if (results_ready() && $league > 0 && isset($_GET['search'])) {
    $j = results_api_get('fixtures', ['date' => $date, 'league' => $league, 'season' => (int)date('Y', strtotime($date . ' -6 months'))]);
    $found = [];
    foreach (($j['response'] ?? []) as $f) {
        $found[] = ['id' => (int)$f['fixture']['id'], 'home' => (string)$f['teams']['home']['name'], 'away' => (string)$f['teams']['away']['name'], 'time' => (string)$f['fixture']['date']];
    }
    if ($j === null) { set_flash('error', 'The results service did not answer. Check the key and your plan limits.'); }
}
$matches = $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, m.api_fixture_id, l.name AS league,
                               (SELECT COUNT(*) FROM tips t WHERE t.match_id = m.id AND t.deleted_at IS NULL AND t.status = 'pending') AS pend
                        FROM matches m JOIN leagues l ON l.id = m.league_id
                        WHERE m.kickoff_at > NOW() - INTERVAL 3 DAY AND m.kickoff_at < NOW() + INTERVAL 14 DAY
                        ORDER BY m.kickoff_at ASC LIMIT 200")->fetchAll();
$on = setting('auto_results_enabled', '0') === '1';

$admin_title = 'Match fixtures';
$admin_active = 'fixtures';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Match fixtures (automatic results)</h1>
<p class="sub">Optional. Link a match to its API-Football fixture number and finished games can be settled for you.</p>
<div class="alert"><strong>Please read.</strong> API-Football is a paid service with request limits, and results from any feed can be wrong or late. Only simple markets (1X2, double chance, over/under x.5 goals, both teams to score, draw no bet) are settled automatically; everything else stays pending for you. Check the activity log and <code>logs/auto-results.log</code>, and fix mistakes by hand.</div>
<div class="panel">
  <p>Service key: <strong><?= results_ready() ? 'set in includes/config-results.php' : 'not set (add RESULTS_API_KEY in includes/config-results.php)' ?></strong></p>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
    <label><input type="checkbox" name="enabled" value="1"<?= $on ? ' checked' : '' ?>> Settle finished matches automatically (needs the cron job <code>cron/auto-results.php</code>)</label>
    <button class="btn dark tiny" type="submit" style="margin-left:10px">Save</button></form>
</div>
<?php if (results_ready()): ?>
<div class="panel"><h2>Find fixture numbers</h2>
  <form method="get" class="toolbar"><label>Date <input type="date" name="date" value="<?= e($date) ?>"></label>
    <label>API league number <input type="number" name="league" value="<?= $league ?: '' ?>" min="1" style="width:110px" placeholder="e.g. 39"></label>
    <button class="btn dark" name="search" value="1" type="submit">List fixtures</button></form>
  <?php if (is_array($found)): ?>
    <?php if ($found): ?><table class="tbl"><thead><tr><th>Fixture number</th><th>Match</th><th>Kickoff (UTC)</th></tr></thead><tbody>
    <?php foreach ($found as $f): ?><tr><td><?= (int)$f['id'] ?></td><td><?= e($f['home']) ?> vs <?= e($f['away']) ?></td><td><?= e($f['time']) ?></td></tr><?php endforeach; ?></tbody></table>
    <?php else: ?><p style="color:var(--mute)">Nothing found for that date and league.</p><?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="panel"><h2>Matches</h2>
<?php if ($matches): ?><div class="scroll"><table class="tbl"><thead><tr><th>Kickoff</th><th>Match</th><th>Pending tips</th><th>Fixture number</th><th></th></tr></thead><tbody>
<?php foreach ($matches as $m): ?>
  <tr><td><?= e(date('j M, H:i', strtotime($m['kickoff_at']))) ?></td><td><?= e($m['home_team']) ?> vs <?= e($m['away_team']) ?><br><small><?= e($m['league']) ?></small></td><td><?= (int)$m['pend'] ?></td>
  <td><form method="post" class="inl" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>">
    <input type="text" name="fixture_id" value="<?= e((string)($m['api_fixture_id'] ?? '')) ?>" inputmode="numeric" style="width:110px" aria-label="Fixture number"><button class="btn tiny dark" type="submit">Save</button></form></td>
  <td><?php if (is_admin()): ?><form method="post" action="<?= e(url('admin/match-delete.php')) ?>" class="inl" onsubmit="return confirm('Delete this whole game and everything on it?');"><?= csrf_field() ?><input type="hidden" name="match_id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="back" value="admin/fixtures.php"><button class="btn tiny void" type="submit">Delete game</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">No recent or upcoming matches.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
