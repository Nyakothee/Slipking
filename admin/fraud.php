<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('users_view');
$pdo = db();
$clusters = fraud_clusters(80);
$names = [];
$ids = [];
foreach ($clusters as $c) { foreach (explode(',', $c['ids']) as $i) { $ids[(int)$i] = true; } }
if ($ids) {
    $in = implode(',', array_keys($ids));
    foreach ($pdo->query("SELECT id, name, email, created_at FROM users WHERE id IN ($in)")->fetchAll() as $u) { $names[(int)$u['id']] = $u; }
}
$dupMobile = $pdo->query("SELECT mobile, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids FROM users WHERE mobile IS NOT NULL AND mobile <> '' GROUP BY mobile HAVING n >= 2 ORDER BY n DESC LIMIT 40")->fetchAll();
$admin_title = 'Multi-account signals';
$admin_active = 'fraud';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><h2>Same IP and same browser</h2>
  <p style="color:var(--mute)">Members who logged in from the same IP address with the same browser in the last 90 days. Shared Wi-Fi and mobile networks can cause false matches, so treat this as a hint. Referral rewards and second free trials are blocked automatically only for these matches.</p>
  <?php if (!$clusters): ?><p>Nothing found.</p><?php else: ?>
  <table class="tbl"><thead><tr><th>IP</th><th>Accounts</th><th>Last seen</th></tr></thead><tbody>
  <?php foreach ($clusters as $c): ?><tr><td><?= e($c['ip']) ?></td><td><?php foreach (explode(',', $c['ids']) as $i): $u = $names[(int)$i] ?? null; ?>
      <a href="<?= e(url('admin/users.php?q=' . urlencode($u['email'] ?? (string)$i))) ?>">#<?= (int)$i ?> <?= e($u['name'] ?? '') ?></a><br><?php endforeach; ?></td><td><?= e(date('j M H:i', strtotime($c['last_seen']))) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<div class="panel"><h2>Same mobile number on several accounts</h2>
  <?php if (!$dupMobile): ?><p>Nothing found.</p><?php else: ?>
  <table class="tbl"><thead><tr><th>Mobile</th><th>Accounts</th></tr></thead><tbody>
  <?php foreach ($dupMobile as $d): ?><tr><td><?= e(substr($d['mobile'], 0, -3) . '***') ?></td><td><?= e($d['ids']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
