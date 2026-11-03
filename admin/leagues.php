<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'add_sport') {
        $name = post_str('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            set_flash('error', 'Sport name must be 2 to 50 characters.');
        } else {
            $pdo->prepare('INSERT INTO sports (name, slug) VALUES (?, ?)')->execute([$name, unique_slug('sports', slugify($name))]);
            admin_log('sport.add', 'sport#' . $pdo->lastInsertId(), $name);
            set_flash('success', 'Sport added.');
        }
    } elseif ($action === 'add_league') {
        $name = post_str('name');
        $sid = (int)($_POST['sport_id'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) FROM sports WHERE id = ?');
        $st->execute([$sid]);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || !$st->fetchColumn()) {
            set_flash('error', 'Choose a sport and enter a league name (2 to 80 characters).');
        } else {
            $pdo->prepare('INSERT INTO leagues (sport_id, name) VALUES (?, ?)')->execute([$sid, $name]);
            admin_log('league.add', 'league#' . $pdo->lastInsertId(), $name);
            set_flash('success', 'League added.');
        }
    } elseif ($action === 'rename_league') {
        $lid = (int)($_POST['id'] ?? 0);
        $name = post_str('name');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            set_flash('error', 'League name must be 2 to 80 characters.');
        } else {
            $pdo->prepare('UPDATE leagues SET name = ? WHERE id = ?')->execute([$name, $lid]);
            admin_log('league.rename', 'league#' . $lid, $name);
            set_flash('success', 'League renamed.');
        }
    } elseif ($action === 'delete_league') {
        $lid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE league_id = ?');
        $st->execute([$lid]);
        if ((int)$st->fetchColumn() > 0) {
            set_flash('error', 'This league has matches, so it cannot be deleted. Rename it instead.');
        } else {
            $pdo->prepare('DELETE FROM leagues WHERE id = ?')->execute([$lid]);
            admin_log('league.delete', 'league#' . $lid);
            set_flash('success', 'League deleted.');
        }
    }
    redirect('admin/leagues.php');
}

$sports = $pdo->query('SELECT * FROM sports ORDER BY id')->fetchAll();
$leagues = $pdo->query('SELECT l.*, (SELECT COUNT(*) FROM matches m WHERE m.league_id = l.id) AS matches FROM leagues l ORDER BY l.sport_id, l.name')->fetchAll();
$bySport = [];
foreach ($leagues as $l) { $bySport[$l['sport_id']][] = $l; }

$admin_title = 'Sports and leagues';
$admin_active = 'leagues';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Sports and leagues</h1>
<p class="sub">These fill the league list when you add a tip and the sport filters on the site.</p>

<?php foreach ($sports as $s): ?>
<div class="panel">
  <h2><?= e($s['name']) ?></h2>
  <?php if (!empty($bySport[$s['id']])): ?>
  <table class="tbl"><thead><tr><th>League</th><th>Matches</th><th></th></tr></thead><tbody>
    <?php foreach ($bySport[$s['id']] as $l): ?>
      <tr>
        <td><form class="inl" method="post" style="display:flex;gap:8px"><?= csrf_field() ?><input type="hidden" name="action" value="rename_league"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <input type="text" name="name" value="<?= e($l['name']) ?>" maxlength="80" style="padding:6px 10px;border:1.5px solid var(--line);border-radius:6px;font:15px 'Source Sans 3'"><button class="btn outline tiny" type="submit">Rename</button></form></td>
        <td><?= (int)$l['matches'] ?></td>
        <td class="acts"><?php if (!(int)$l['matches']): ?><form class="inl" method="post" onsubmit="return confirm('Delete this league?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_league"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn tiny loss" type="submit">Delete</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p style="color:var(--mute)">No leagues yet.</p><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="split">
  <div class="panel"><h2>Add a league</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_league">
      <div class="field"><label for="sport_id">Sport</label><select id="sport_id" name="sport_id"><?php foreach ($sports as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="lname">League name</label><input type="text" id="lname" name="name" maxlength="80" required placeholder="e.g. Kenyan Premier League"></div>
      <button class="btn gold tiny" type="submit">Add league</button>
    </form></div>
  <div class="panel"><h2>Add a sport</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_sport">
      <div class="field"><label for="sname">Sport name</label><input type="text" id="sname" name="name" maxlength="50" required placeholder="e.g. Rugby"></div>
      <button class="btn dark tiny" type="submit">Add sport</button>
    </form></div>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
