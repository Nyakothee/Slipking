<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa.php';
require_once dirname(__DIR__) . '/includes/paypal.php';
require_once dirname(__DIR__) . '/includes/gateways.php';
require_once dirname(__DIR__) . '/includes/wallets.php';
require_perm('payments');
$pdo = db();
$me  = current_user();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$load = function () use ($pdo, $id) {
    $st = $pdo->prepare('SELECT py.*, u.name, u.email, p.name AS plan_name, p.duration_days
                         FROM payments py JOIN users u ON u.id = py.user_id JOIN plans p ON p.id = py.plan_id WHERE py.id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$p = $load();
if (!$p) {
    set_flash('error', 'Payment not found.');
    redirect('admin/payments.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif ($p['status'] === 'paid') {
        set_flash('info', 'This payment is already paid.');
    } elseif ($action === 'mark_paid') {
        $receipt = strtoupper(post_str('receipt'));
        if ($receipt !== '' && !preg_match('/^[A-Z0-9]{8,12}$/', $receipt)) {
            set_flash('error', 'An M-Pesa receipt looks like QGH7XY1234 (8 to 12 letters and numbers).');
        } elseif (complete_payment($id, ['receipt' => $receipt ?: null, 'raw' => 'manually confirmed by admin #' . $me['id'] . ($receipt ? ', receipt ' . $receipt : '')])) {
            admin_log('payment.mark_paid', 'payment#' . $id, $p['method'] . ' ' . $p['currency'] . ' ' . $p['amount'] . ' user#' . $p['user_id']);
            set_flash('success', 'Marked as paid. VIP is now active for the member.');
        } else {
            set_flash('error', 'Could not mark it paid. If you typed a receipt, it may already be used on another payment. See logs/payments.log.');
        }
    } elseif ($action === 'mark_failed' && $p['status'] === 'pending') {
        fail_payment($id, 'failed', 'marked failed by admin #' . $me['id']);
        admin_log('payment.mark_failed', 'payment#' . $id);
        set_flash('success', 'Marked as failed.');
    } elseif ($action === 'recheck' && $p['status'] === 'pending') {
        $result = 'pending';
        if ($p['method'] === 'mpesa' && mpesa_ready()) {
            $result = resolve_mpesa_payment($p);
        } elseif ($p['method'] === 'paypal' && paypal_ready() && $p['paypal_order_id']) {
            $order = paypal_get_order($p['paypal_order_id']);
            $result = $order ? paypal_finalize($p, $order) : 'pending';
        } elseif ($p['method'] === 'paystack' && paystack_ready()) {
            $result = paystack_confirm($p);
        } elseif (in_array($p['method'], ['stripe', 'mtn', 'airtel'], true)) {
            $result = wallet_confirm($p);
        }
        admin_log('payment.recheck', 'payment#' . $id, 'result: ' . $result);
        set_flash($result === 'pending' ? 'info' : 'success', 'Checked with ' . ($p['method'] === 'mpesa' ? 'Safaricom' : method_label($p['method'])) . ': ' . $result . '.');
    }
    redirect('admin/payment-view.php?id=' . $id);
}

$sub = null;
if ($p['status'] === 'paid') {
    $st = $pdo->prepare('SELECT ends_at FROM subscriptions WHERE user_id = ? AND plan_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$p['user_id'], $p['plan_id']]);
    $sub = $st->fetchColumn();
}
$cls = ['paid' => 'won', 'pending' => 'pending', 'failed' => 'lost', 'cancelled' => 'lost'][$p['status']] ?? 'pending';

$admin_title = 'Payment ' . $p['reference'];
$admin_active = 'payments';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1>Payment <?= e($p['reference']) ?></h1><p class="sub" style="margin:0"><span class="badge <?= $cls ?>"><?= e(ucfirst($p['status'])) ?></span></p></div>
  <a class="btn outline" href="<?= e(url('admin/payments.php')) ?>">All payments</a></div>

<div class="split">
  <div class="panel">
    <h2>Details</h2>
    <table class="kv">
      <tr><td>Member</td><td><a href="<?= e(url('admin/user-view.php?id=' . (int)$p['user_id'])) ?>" style="text-decoration:underline"><?= e($p['name']) ?></a><br><small><?= e($p['email']) ?></small></td></tr>
      <tr><td>Plan</td><td><?= e($p['plan_name']) ?> (<?= (int)$p['duration_days'] ?> days)</td></tr>
      <tr><td>Method</td><td><?= method_label($p['method']) ?></td></tr>
      <tr><td>Amount</td><td><?= e($p['currency']) ?> <?= e(number_format((float)$p['amount'], 2)) ?></td></tr>
      <?php if ($p['coupon_code']): ?><tr><td>Coupon</td><td><?= e($p['coupon_code']) ?> (<?= (int)$p['discount_percent'] ?>% off)</td></tr><?php endif; ?>
      <tr><td>Started</td><td><?= e(date('j M Y, H:i:s', strtotime($p['created_at']))) ?></td></tr>
      <tr><td>Paid</td><td><?= $p['paid_at'] ? e(date('j M Y, H:i:s', strtotime($p['paid_at']))) : '-' ?></td></tr>
      <?php if ($p['phone']): ?><tr><td>Phone</td><td><?= e($p['phone']) ?></td></tr><?php endif; ?>
      <?php if ($p['mpesa_receipt']): ?><tr><td>M-Pesa receipt</td><td><?= e($p['mpesa_receipt']) ?></td></tr><?php endif; ?>
      <?php if ($p['mpesa_checkout_id']): ?><tr><td>Checkout ID</td><td style="word-break:break-all"><?= e($p['mpesa_checkout_id']) ?></td></tr><?php endif; ?>
      <?php if ($p['gateway_ref']): ?><tr><td>Gateway reference</td><td style="word-break:break-all"><?= e($p['gateway_ref']) ?></td></tr><?php endif; ?>
      <?php if ($p['paypal_order_id']): ?><tr><td>PayPal order</td><td><?= e($p['paypal_order_id']) ?></td></tr><?php endif; ?>
      <?php if ($sub): ?><tr><td>VIP until</td><td><?= e(date('j M Y, H:i', strtotime($sub))) ?></td></tr><?php endif; ?>
    </table>
  </div>

  <div class="panel">
    <h2>Actions</h2>
    <?php if ($p['status'] === 'paid'): ?>
      <p style="color:var(--mute)">This payment is complete. To change a member's VIP time, use the member page.</p>
    <?php else: ?>
      <?php if ($p['status'] === 'pending'): ?>
        <form method="post" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="recheck">
          <button class="btn dark tiny" type="submit">Check again with <?= $p['method'] === 'mpesa' ? 'Safaricom' : e(method_label($p['method'])) ?></button></form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Only do this after you have seen the money in your M-Pesa or PayPal account. Continue?');">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="mark_paid">
        <?php if ($p['method'] === 'mpesa'): ?>
          <div class="field"><label for="receipt">M-Pesa receipt code (optional)</label><input type="text" id="receipt" name="receipt" maxlength="12" placeholder="QGH7XY1234"></div>
        <?php endif; ?>
        <button class="btn gold tiny" type="submit">Mark as paid and activate VIP</button>
      </form>
      <p style="color:var(--mute);font-size:14px;margin-top:8px">Use this when a customer paid but the system did not confirm it. Check your M-Pesa statement or PayPal first.</p>
      <?php if ($p['status'] === 'pending'): ?>
        <form method="post" style="margin-top:14px" onsubmit="return confirm('Mark this payment as failed?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="mark_failed">
          <button class="btn loss tiny" type="submit">Mark as failed</button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h2>Technical record</h2>
  <pre style="white-space:pre-wrap;word-break:break-all;font-size:13px;background:var(--paper);padding:12px;border-radius:8px;max-height:300px;overflow:auto"><?= e($p['raw_response'] ?: '(empty)') ?></pre>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
