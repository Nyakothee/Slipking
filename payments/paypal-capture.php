<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/paypal.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'message' => 'Invalid request.'], 405);
}
$user = current_user();
if (!$user) {
    json_out(['ok' => false, 'message' => 'Please log in first.'], 401);
}
if (!csrf_check()) {
    json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
}

$orderId = preg_replace('/[^A-Z0-9]/', '', strtoupper(post_str('orderID')));
$st = db()->prepare("SELECT * FROM payments WHERE paypal_order_id = ? AND user_id = ? AND method = 'paypal' LIMIT 1");
$st->execute([$orderId, $user['id']]);
$p = $st->fetch();
if (!$orderId || !$p) {
    json_out(['ok' => false, 'message' => 'Payment not found.'], 404);
}
if ($p['status'] === 'paid') {
    json_out(['ok' => true, 'ref' => $p['reference']]);
}

$r = paypal_capture_order($orderId, $p['reference']);
$d = $r['data'] ?? [];
$order = null;

if (in_array($r['status'], [200, 201], true) && is_array($d)) {
    $order = $d;
} else {
    $issue = $d['details'][0]['issue'] ?? '';
    payments_log('paypal-capture-failed', ['payment' => $p['id'], 'http' => $r['status'], 'issue' => $issue, 'body' => substr($r['raw'], 0, 500)]);
    if ($issue === 'ORDER_ALREADY_CAPTURED') {
        $order = paypal_get_order($orderId);              // captured a moment ago (double click, retry): just confirm it
    } elseif ($issue === 'INSTRUMENT_DECLINED') {
        json_out(['ok' => false, 'message' => 'PayPal could not use that payment method. Please choose another one.'], 402);
    }
}

if (!$order) {
    json_out(['ok' => false, 'message' => 'PayPal could not complete the payment. You have not been charged. Please try again.'], 402);
}

if (paypal_finalize($p, $order) === 'paid') {
    json_out(['ok' => true, 'ref' => $p['reference']]);
}
json_out(['ok' => false, 'message' => 'We could not confirm the payment yet. If PayPal shows a charge, your plan will switch on automatically or contact us with your PayPal receipt.'], 202);
