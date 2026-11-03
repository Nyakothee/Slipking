<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('managed');

$status = $_GET['status'] ?? '';
$where = '';
$args = [];
if (in_array($status, ['new', 'contacted', 'accepted', 'declined', 'closed'], true)) { $where = 'WHERE r.status = ?'; $args[] = $status; }
$st = db()->prepare("SELECT r.*, u.email" . (managed_cols() ? ", (SELECT display_name FROM tipsters tp WHERE tp.id = r.tipster_id) AS tipster_name" : '') . " FROM managed_requests r JOIN users u ON u.id = r.user_id $where ORDER BY (r.status = 'new') DESC, r.id DESC LIMIT 200");
$st->execute($args);
$rows = $st->fetchAll();

$admin_title = 'Account management';
$admin_active = 'managed';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Account management enquiries</h1>
<p class="sub">People who asked about the service. Contact them yourself, outside the site. Never collect their betting passwords.</p>
<div class="panel">
  <div class="tabs" style="margin-bottom:14px">
    <a href="<?= e(url('admin/managed.php')) ?>"<?= $status === '' ? ' class="on"' : '' ?>>All</a>
    <?php foreach (['new', 'contacted', 'accepted', 'declined', 'closed'] as $s): ?><a href="<?= e(url('admin/managed.php?status=' . $s)) ?>"<?= $status === $s ? ' class="on"' : '' ?>><?= ucfirst($s) ?></a><?php endforeach; ?>
  </div>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Date</th><th>Name</th><th>Country</th><th>Company</th><th>Handled by</th><th>Amount</th><th>Risk</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['full_name']) ?><br><small><?= e($r['phone']) ?></small></td>
        <td><?= e($r['country']) ?></td>
        <td><?= e($r['bookmaker']) ?></td>
        <td><?= e(($r['tipster_name'] ?? '') !== '' ? $r['tipster_name'] . ' (tipster)' : 'Team') ?></td>
        <td><?= e($r['budget_currency']) ?> <?= e(number_format((float)$r['budget'], 0)) ?></td>
        <td><?= e(ucfirst($r['risk_level'])) ?></td>
        <td><span class="badge <?= $r['status'] === 'new' ? 'pending' : ($r['status'] === 'accepted' ? 'won' : ($r['status'] === 'declined' ? 'lost' : 'pending')) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td class="acts"><a class="btn outline tiny" href="<?= e(url('admin/managed-view.php?id=' . (int)$r['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty">No enquiries yet.</div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
