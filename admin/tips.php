<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');

$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$view    = $_GET['view'] ?? '';
$vipf    = $_GET['vip'] ?? '';
$q       = trim((string)($_GET['q'] ?? ''));
$when    = $_GET['when'] ?? '';
$status  = $_GET['status'] ?? '';

$from = 'FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id WHERE t.deleted_at IS NULL';
$args = [];
if ($view === 'needs-result') {
    $from .= " AND t.status = 'pending' AND m.kickoff_at < NOW()";
}
if (in_array($status, ['pending', 'won', 'lost', 'void'], true)) {
    $from .= ' AND t.status = ?';
    $args[] = $status;
}
if ($vipf === '1' || $vipf === '0') {
    $from .= ' AND t.is_vip = ?';
    $args[] = (int)$vipf;
}
if ($when === 'today') {
    $from .= ' AND DATE(m.kickoff_at) = CURDATE()';
} elseif ($when === 'upcoming') {
    $from .= ' AND m.kickoff_at >= NOW()';
}
if ($q !== '') {
    $from .= ' AND (m.home_team LIKE ? OR m.away_team LIKE ? OR t.pick LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like);
}

$st = db()->prepare('SELECT COUNT(*) ' . $from);
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$st = db()->prepare('SELECT t.*, m.home_team, m.away_team, m.kickoff_at, l.name AS league ' . $from .
                    ' ORDER BY m.kickoff_at DESC, t.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($args);
$rows = $st->fetchAll();

$admin_title = 'Tips';
$admin_active = 'tips';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar">
  <div><h1>Tips</h1><p class="sub" style="margin:0"><?= $total ?> found</p></div>
  <a class="btn gold" href="<?= e(url('admin/tip-add.php')) ?>">Add a tip</a>
</div>

<div class="panel">
  <div class="toolbar">
    <form method="get" action="<?= e(url('admin/tips.php')) ?>">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search team or pick">
      <select name="view"><option value="">All</option><option value="needs-result"<?= $view === 'needs-result' ? ' selected' : '' ?>>Needs a result</option></select>
      <select name="status"><option value="">Any status</option>
        <?php foreach (['pending', 'won', 'lost', 'void'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
      <select name="vip"><option value="">Free + VIP</option><option value="0"<?= $vipf === '0' ? ' selected' : '' ?>>Free only</option><option value="1"<?= $vipf === '1' ? ' selected' : '' ?>>VIP only</option></select>
      <select name="when"><option value="">Any date</option><option value="today"<?= $when === 'today' ? ' selected' : '' ?>>Today</option><option value="upcoming"<?= $when === 'upcoming' ? ' selected' : '' ?>>Upcoming</option></select>
      <button class="btn dark tiny" type="submit">Filter</button>
      <a class="btn outline tiny" href="<?= e(url('admin/tips.php')) ?>">Clear</a>
    </form>
  </div>

  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Kickoff</th><th>Match</th><th>Pick</th><th>Odds</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): $locked = tip_is_locked($t); $started = strtotime($t['kickoff_at']) <= time(); ?>
      <tr>
        <td><?= e(date('j M Y', strtotime($t['kickoff_at']))) ?><br><small><?= e(date('H:i', strtotime($t['kickoff_at']))) ?></small></td>
        <td><?= e($t['home_team']) ?> vs <?= e($t['away_team']) ?><br><small><?= e($t['league']) ?></small></td>
        <td><?= e($t['pick']) ?><?= $t['is_vip'] ? ' <span class="vipflag">VIP</span>' : '' ?><br><small><?= e($t['market']) ?></small></td>
        <td><?= e(number_format((float)$t['odds'], 2)) ?></td>
        <td><span class="badge <?= e($t['status']) ?>"><?= e(ucfirst($t['status'])) ?></span></td>
        <td class="acts">
          <a class="btn outline tiny" href="<?= e(url('admin/tip-edit.php?id=' . (int)$t['id'])) ?>"><?= $locked ? 'View' : 'Edit' ?></a>
          <a class="btn outline tiny" href="<?= e(url('admin/tip-odds.php?id=' . (int)$t['id'])) ?>">Odds</a>
          <?php if ($started): ?>
            <form class="inl" method="post" action="<?= e(url('admin/tip-settle.php')) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="<?= e(ltrim(substr($_SERVER['REQUEST_URI'], strlen((string)parse_url(SITE_URL, PHP_URL_PATH))), '/')) ?>">
              <button class="btn tiny win" name="result" value="won" type="submit" title="Mark won">W</button>
              <button class="btn tiny loss" name="result" value="lost" type="submit" title="Mark lost">L</button>
              <button class="btn tiny void" name="result" value="void" type="submit" title="Mark void">V</button>
              <button class="btn tiny void" name="result" value="push" type="submit" title="Mark push (stake returned, match result exactly on the line)">P</button>
            </form>
          <?php else: ?>
            <a class="btn outline tiny" href="<?= e(url('admin/tip-add.php?match=' . (int)$t['match_id'])) ?>" title="Another tip on this match">+ tip</a>
            <form class="inl" method="post" action="<?= e(url('admin/tip-delete.php')) ?>" onsubmit="return confirm('Delete this tip? Only do this for a mistake.');">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn tiny loss" type="submit">Delete</button>
            </form>
          <?php endif; ?>
          <?php if (is_admin()): ?>
            <?php if ($started): ?>
            <form class="inl" method="post" action="<?= e(url('admin/tip-delete.php')) ?>" onsubmit="return confirm('Delete this tip even though it has started or has a result? It will disappear from records and tipster stats.');">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn tiny loss" type="submit" title="Delete this tip">Delete</button>
            </form>
            <?php endif; ?>
            <form class="inl" method="post" action="<?= e(url('admin/match-delete.php')) ?>" onsubmit="return confirm('DELETE THE WHOLE GAME (<?= e(addslashes($t['home_team'] . ' vs ' . $t['away_team'])) ?>)? Every tip, odds, live event and game pick on it is removed for good.');">
              <?= csrf_field() ?><input type="hidden" name="match_id" value="<?= (int)$t['match_id'] ?>"><input type="hidden" name="back" value="admin/tips.php">
              <button class="btn tiny void" type="submit" title="Delete the whole game and everything on it">Delete game</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?= pager($page, $pages, 'admin/tips.php') ?>
  <?php else: ?>
    <div class="empty">No tips match. <a href="<?= e(url('admin/tip-add.php')) ?>" style="text-decoration:underline;font-weight:700">Add the first one</a></div>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
