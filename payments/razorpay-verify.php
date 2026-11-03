<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/gateways.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'message' => 'Invalid request.'], 405);
$user = current_user();
if (!$user) json_out(['ok' => false, 'message' => 'Please log in first.'], 401);
if (!csrf_check()) json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);

$orderId = (string)($_POST['razorpay_order_id'] ?? '');
$payId   = (string)($_POST['razorpay_payment_id'] ?? '');
$sig     = (string)($_POST['razorpay_signature'] ?? '');
$st = db()->prepare("SELECT * FROM payments WHERE gateway_ref = ? AND user_id = ? AND method = 'razorpay' LIMIT 1");
$st->execute([$orderId, $user['id']]);
$p = $st->fetch();
if (!$p) json_out(['ok' => false, 'message' => 'Payment not found.'], 404);
if ($p['status'] === 'paid') json_out(['ok' => true, 'ref' => $p['reference']]);

if (!hash_equals(hash_hmac('sha256', $orderId . '|' . $payId, RAZORPAY_KEY_SECRET), $sig)) {
    payments_log('razorpay-bad-signature', ['payment' => $p['id']]);
    json_out(['ok' => false, 'message' => 'The payment could not be confirmed.'], 400);
}
if (razorpay_confirm($p, $payId) === 'paid') {
    json_out(['ok' => true, 'ref' => $p['reference']]);
}
json_out(['ok' => false, 'message' => 'We could not confirm the payment yet. If you were charged, your plan will switch on automatically or contact us with your receipt.'], 202);
