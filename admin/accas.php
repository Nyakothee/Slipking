<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('accas');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $aid = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM accumulators WHERE id = ?');
    $st->execute([$aid]);
    $a = $st->fetch();
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!$a) {
        set_flash('error', 'Not found.');
    } elseif (!is_admin() && acca_is_locked($a)) {
        set_flash('error', 'Only an administrator can delete an accumulator whose first match has started or that has a result.');
    } elseif (!is_admin()) {
        $pdo->prepare('UPDATE accumulators SET deleted_at = NOW() WHERE id = ?')->execute([$aid]);
        admin_log('acca.delete', 'acca#' . $aid, $a['title']);
        set_flash('success', 'Accumulator deleted.');
    } else {
        // Administrators have total control: erased for good, selections go with it (foreign key cascade).
        $pdo->prepare('DELETE FROM accumulators WHERE id = ?')->execute([$aid]);
        admin_log('acca.purge', 'acca#' . $aid, $a['title'] . ' (' . $a['kind'] . ', ' . $a['status'] . ')');
        set_flash('success', 'Accumulator erased for good.');
    }
    redirect('admin/accas.php');
}

$kind = $_GET['kind'] ?? '';
$where = "WHERE a.deleted_at IS NULL";
$args = [];
if (in_array($kind, ['million', 'vip'], true)) { $where .= ' AND a.kind = ?'; $args[] = $kind; }
$st = $pdo->prepare("SELECT a.*, (SELECT COUNT(*) FROM accumulator_legs l WHERE l.accumulator_id = a.id) AS legs,
                            (SELECT COUNT(*) FROM accumulator_legs l WHERE l.accumulator_id = a.id AND l.status = 'pending' AND l.kickoff_at < NOW()) AS needs
                     FROM accumulators a $where ORDER BY a.posted_at DESC LIMIT 100");
$st->execute($args);
$rows = $st->fetchAll();

$admin_title = 'Accumulators';
$admin_active = 'accas';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1>Accumulators</h1><p class="sub" style="margin:0">1 Million (free) and VIP accumulators.</p></div>
  <a class="btn gold" href="<?= e(url('admin/acca-edit.php')) ?>">New accumulator</a></div>
<div class="panel">
  <div class="tabs" style="margin-bottom:14px">
    <a href="<?= e(url('admin/accas.php')) ?>"<?= $kind === '' ? ' class="on"' : '' ?>>All</a>
    <a href="<?= e(url('admin/accas.php?kind=million')) ?>"<?= $kind === 'million' ? ' class="on"' : '' ?>>1 Million</a>
    <a href="<?= e(url('admin/accas.php?kind=vip')) ?>"<?= $kind === 'vip' ? ' class="on"' : '' ?>>VIP</a>
  </div>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Title</th><th>Type</th><th>Selections</th><th>Total odds</th><th>Matches</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): $locked = acca_is_locked($a); ?>
      <tr>
        <td><?= e($a['title']) ?><br><small>posted <?= e(date('j M Y, H:i', strtotime($a['posted_at']))) ?></small></td>
        <td><?= $a['kind'] === 'vip' ? '<span class="vipflag">VIP</span>' : 'Free' ?></td>
        <td><?= (int)$a['legs'] ?></td>
        <td><?= e(number_format((float)$a['total_odds'], 2)) ?></td>
        <td><?= e(date('j M', strtotime($a['first_kickoff']))) ?> - <?= e(date('j M', strtotime($a['last_kickoff']))) ?></td>
        <td><span class="badge <?= e($a['status']) ?>"><?= e(ucfirst($a['status'])) ?></span><?= (int)$a['needs'] ? '<br><small>' . (int)$a['needs'] . ' to settle</small>' : '' ?></td>
        <td class="acts">
          <a class="btn outline tiny" href="<?= e(url('admin/acca-view.php?id=' . (int)$a['id'])) ?>"><?= (int)$a['needs'] ? 'Settle' : 'Selections' ?></a>
          <a class="btn outline tiny" href="<?= e(url('admin/acca-edit.php?id=' . (int)$a['id'])) ?>"><?= $locked ? 'Notes' : 'Edit' ?></a>
          <?php if (!$locked || is_admin()): ?><form class="inl" method="post" onsubmit="return confirm('<?= $locked ? 'ERASE this accumulator for good, even though it has started or been settled? This cannot be undone.' : 'Delete this accumulator? Only do this for a mistake.' ?>');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn tiny loss" type="submit">Delete</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty">No accumulators yet. <a href="<?= e(url('admin/acca-edit.php')) ?>" style="text-decoration:underline;font-weight:700">Post the first one</a></div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
