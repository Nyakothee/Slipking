<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('payments');

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$status = $_GET['status'] ?? '';
$method = $_GET['method'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));

$where = 'WHERE 1=1';
$args = [];
if (in_array($status, ['pending', 'paid', 'failed', 'cancelled'], true)) { $where .= ' AND py.status = ?'; $args[] = $status; }
if (in_array($method, ['mpesa', 'paypal', 'paystack', 'razorpay', 'stripe', 'mtn', 'airtel'], true)) { $where .= ' AND py.method = ?'; $args[] = $method; }
if ($q !== '') {
    $where .= ' AND (py.reference LIKE ? OR py.mpesa_receipt LIKE ? OR py.phone LIKE ? OR u.email LIKE ? OR u.name LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
$from = "FROM payments py JOIN users u ON u.id = py.user_id JOIN plans p ON p.id = py.plan_id $where";
$st = db()->prepare("SELECT COUNT(*) $from");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$st = db()->prepare("SELECT py.*, u.name, u.email, p.name AS plan_name $from ORDER BY py.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$rows = $st->fetchAll();

$admin_title = 'Payments';
$admin_active = 'payments';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Payments</h1>
<p class="sub"><?= $total ?> found</p>
<div class="panel">
  <div class="toolbar">
    <form method="get" action="<?= e(url('admin/payments.php')) ?>">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Reference, receipt, phone, member">
      <select name="status"><option value="">Any status</option>
        <?php foreach (['pending', 'paid', 'failed', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
      <select name="method"><option value="">All methods</option><option value="mpesa"<?= $method === 'mpesa' ? ' selected' : '' ?>>M-Pesa</option><option value="paypal"<?= $method === 'paypal' ? ' selected' : '' ?>>PayPal</option><option value="paystack"<?= $method === 'paystack' ? ' selected' : '' ?>>Paystack</option><option value="razorpay"<?= $method === 'razorpay' ? ' selected' : '' ?>>Razorpay</option><option value="stripe"<?= $method === 'stripe' ? ' selected' : '' ?>>Stripe (card)</option><option value="mtn"<?= $method === 'mtn' ? ' selected' : '' ?>>MTN MoMo</option><option value="airtel"<?= $method === 'airtel' ? ' selected' : '' ?>>Airtel Money</option></select>
      <button class="btn dark tiny" type="submit">Filter</button>
      <a class="btn outline tiny" href="<?= e(url('admin/payments.php')) ?>">Clear</a>
    </form>
  </div>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>When</th><th>Member</th><th>Plan</th><th>Method</th><th>Amount</th><th>Receipt / ref</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $cls = ['paid' => 'won', 'pending' => 'pending', 'failed' => 'lost', 'cancelled' => 'lost'][$r['status']] ?? 'pending'; ?>
      <tr>
        <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['name']) ?><br><small><?= e($r['email']) ?></small></td>
        <td><?= e($r['plan_name']) ?></td>
        <td><?= method_label($r['method']) ?><?= $r['phone'] ? '<br><small>' . e($r['phone']) . '</small>' : '' ?></td>
        <td><?= e($r['currency']) ?> <?= e(number_format((float)$r['amount'], 2)) ?></td>
        <td><?= e($r['mpesa_receipt'] ?: '-') ?><br><small><?= e($r['reference']) ?></small></td>
        <td><span class="badge <?= $cls ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td class="acts"><a class="btn outline tiny" href="<?= e(url('admin/payment-view.php?id=' . (int)$r['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?= pager($page, $pages, 'admin/payments.php') ?>
  <?php else: ?><div class="empty">No payments match.</div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
