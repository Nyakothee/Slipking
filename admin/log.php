<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('log');

$rows = [];
$missing = false;
try {
    $rows = db()->query('SELECT a.*, u.name FROM admin_log a LEFT JOIN users u ON u.id = a.admin_id ORDER BY a.id DESC LIMIT 200')->fetchAll();
} catch (Throwable $e) {
    $missing = true;
}

$admin_title = 'Activity log';
$admin_active = 'log';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Activity log</h1>
<p class="sub">Every change made in the admin panel. The last 200 entries.</p>
<div class="panel">
<?php if ($missing): ?>
  <p>The log table does not exist yet. In phpMyAdmin, import <code>database-update-1.sql</code> once.</p>
<?php elseif ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Item</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['name'] ?? 'unknown') ?><br><small><?= e($r['ip']) ?></small></td>
        <td><?= e($r['action']) ?></td>
        <td><?= e($r['target']) ?></td>
        <td><?= e($r['details']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php else: ?>
  <p style="color:var(--mute)">Nothing logged yet.</p>
<?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
