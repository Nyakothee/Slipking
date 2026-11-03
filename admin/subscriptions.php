<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('subscriptions');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $sid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT user_id FROM subscriptions WHERE id = ? AND status = 'active'");
        $st->execute([$sid]);
        if ($uid = $st->fetchColumn()) {
            $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE id = ?")->execute([$sid]);
            notify_user((int)$uid, 'VIP plan ended', 'One of your VIP plans was ended by our team. Contact support if you have questions.');
            admin_log('sub.cancel', 'sub#' . $sid, 'user#' . $uid);
            set_flash('success', 'Subscription cancelled.');
        }
    }
    redirect('admin/subscriptions.php');
}

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$state = $_GET['state'] ?? 'active';
$filters = [
    'ended'     => "WHERE s.status = 'active' AND s.ends_at <= NOW()",
    'cancelled' => "WHERE s.status = 'cancelled'",
    'all'       => 'WHERE 1=1',
    'active'    => "WHERE s.status = 'active' AND s.ends_at > NOW()",
];
if (!isset($filters[$state])) {
    $state = 'active';
}
$where = $filters[$state];
$total = (int)$pdo->query("SELECT COUNT(*) FROM subscriptions s $where")->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$rows = $pdo->query("SELECT s.*, u.name, u.email, p.name AS plan_name FROM subscriptions s
                     JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id
                     $where ORDER BY s.ends_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage))->fetchAll();

$admin_title = 'Subscriptions';
$admin_active = 'subscriptions';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Subscriptions</h1>
<p class="sub"><?= $total ?> found</p>
<div class="panel">
  <div class="tabs" style="margin-bottom:16px">
    <?php foreach (['active' => 'Active now', 'ended' => 'Ended', 'cancelled' => 'Cancelled', 'all' => 'All'] as $k => $label): ?>
      <a href="<?= e(qs_url('admin/subscriptions.php', ['state' => $k, 'page' => null])) ?>"<?= $state === $k ? ' class="on"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Member</th><th>Plan</th><th>From</th><th>To</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): $live = $s['status'] === 'active' && strtotime($s['ends_at']) > time(); ?>
      <tr>
        <td><a href="<?= e(url('admin/user-view.php?id=' . (int)$s['user_id'])) ?>" style="text-decoration:underline"><?= e($s['name']) ?></a><br><small><?= e($s['email']) ?></small></td>
        <td><?= e($s['plan_name']) ?></td>
        <td><?= e(date('j M Y', strtotime($s['starts_at']))) ?></td>
        <td><?= e(date('j M Y, H:i', strtotime($s['ends_at']))) ?></td>
        <td><span class="badge <?= $live ? 'won' : 'pending' ?>"><?= $live ? 'Active' : ($s['status'] === 'cancelled' ? 'Cancelled' : 'Ended') ?></span></td>
        <td class="acts"><?php if ($live): ?>
          <form class="inl" method="post" onsubmit="return confirm('Cancel this subscription?');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn tiny loss" type="submit">Cancel</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?= pager($page, $pages, 'admin/subscriptions.php') ?>
  <?php else: ?><div class="empty">Nothing here.</div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
