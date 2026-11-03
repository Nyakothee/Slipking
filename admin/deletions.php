<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('log');

$q = trim((string)($_GET['q'] ?? ''));
$kind = (string)($_GET['kind'] ?? '');
$rows = [];
$missing = false;
try {
    $sql = "SELECT a.*, u.name FROM admin_log a LEFT JOIN users u ON u.id = a.admin_id WHERE (a.action LIKE '%delete%' OR a.action LIKE '%remove%')";
    $args = [];
    if ($kind === 'game') { $sql .= " AND a.action = 'match.delete'"; }
    elseif ($kind === 'tip') { $sql .= " AND a.action = 'tip.delete'"; }
    elseif ($kind === 'other') { $sql .= " AND a.action NOT IN ('match.delete','tip.delete')"; }
    if ($q !== '') { $sql .= ' AND (a.details LIKE ? OR a.target LIKE ? OR u.name LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
    $sql .= ' ORDER BY a.id DESC LIMIT 300';
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    $missing = true;
}

$admin_title = 'Deleted items';
$admin_active = 'deletions';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Deleted items</h1>
<p class="sub">Every game, tip and other item removed from the admin panel: who did it, when, and what it was. These records are never cleaned up automatically.</p>
<div class="panel">
  <form method="get" class="toolbar">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search team, pick or admin name">
    <select name="kind"><option value="">Everything deleted</option>
      <option value="game"<?= $kind === 'game' ? ' selected' : '' ?>>Games</option>
      <option value="tip"<?= $kind === 'tip' ? ' selected' : '' ?>>Tips</option>
      <option value="other"<?= $kind === 'other' ? ' selected' : '' ?>>Other items</option></select>
    <button class="btn dark tiny" type="submit">Filter</button>
    <a class="btn outline tiny" href="<?= e(url('admin/deletions.php')) ?>">Clear</a>
  </form>
<?php if ($missing): ?>
  <p>The log table does not exist yet. In phpMyAdmin, import <code>database-update-1.sql</code> once.</p>
<?php elseif ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>When</th><th>Deleted by</th><th>What</th><th>Item</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['name'] ?? 'unknown') ?><br><small><?= e($r['ip']) ?></small></td>
        <td><?= e($r['action'] === 'match.delete' ? 'Game' : ($r['action'] === 'tip.delete' ? 'Tip' : $r['action'])) ?></td>
        <td><?= e($r['target']) ?></td>
        <td><?= e($r['details']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php else: ?>
  <p style="color:var(--mute)">Nothing has been deleted yet.</p>
<?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
