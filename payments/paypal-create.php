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
if (!phone_gate_ok($user)) {
    json_out(['ok' => false, 'message' => 'Please confirm your mobile number with the SMS code first (Account > Profile).'], 403);
}
if (!paypal_ready()) {
    json_out(['ok' => false, 'message' => 'PayPal is not available right now.'], 503);
}

$pdo = db();
$st  = $pdo->prepare('SELECT * FROM plans WHERE slug = ? AND is_active = 1 LIMIT 1');
$st->execute([preg_replace('/[^a-z0-9\-]/', '', strtolower(post_str('plan')))]);
$plan = $st->fetch();
if ($plan) { $plan = tipster_fee_plan_for($plan, $user); }
if (!$plan || (float)$plan['price_usd'] <= 0) {
    json_out(['ok' => false, 'message' => 'That plan is not available.'], 400);
}
$lb = limits_payment_block((int)$user['id'], (int)$plan['price_kes']);
if ($lb !== '') { json_out(['ok' => false, 'message' => $lb], 403); }

$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND method = 'paypal' AND created_at > NOW() - INTERVAL 10 MINUTE");
$st->execute([$user['id']]);
if ((int)$st->fetchColumn() >= 8) {
    json_out(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
}

$cres = find_coupon(post_str('coupon'), (int)$user['id']);
if (!empty($plan['tipster_id'])) { $cres = ['ok' => true, 'coupon' => null, 'message' => '']; }   // coupons never apply to tipster plans
if (!$cres['ok']) {
    json_out(['ok' => false, 'message' => $cres['message']], 400);
}
$coupon    = $cres['coupon'];
$pct       = $coupon ? (int)$coupon['percent_off'] : 0;
$usd       = $pct ? discounted_usd((float)$plan['price_usd'], $pct) : number_format((float)$plan['price_usd'], 2, '.', '');
$reference = new_payment_reference();
$pdo->prepare("INSERT INTO payments (user_id, plan_id, method, amount, currency, coupon_code, discount_percent, status, reference)
               VALUES (?, ?, 'paypal', ?, 'USD', ?, ?, 'pending', ?)")
    ->execute([$user['id'], $plan['id'], $usd, $coupon['code'] ?? null, $pct, $reference]);
$paymentId = (int)$pdo->lastInsertId();

$res = paypal_create_order($reference, $usd, SITE_NAME . ' VIP - ' . $plan['name']);
if (!$res['ok']) {
    fail_payment($paymentId, 'failed', 'create failed');
    json_out(['ok' => false, 'message' => $res['message']], 502);
}

$pdo->prepare('UPDATE payments SET paypal_order_id = ? WHERE id = ?')->execute([$res['order_id'], $paymentId]);
payments_log('paypal-order-created', ['payment' => $paymentId, 'user' => $user['id'], 'order' => $res['order_id'], 'usd' => $usd]);

json_out(['ok' => true, 'orderID' => $res['order_id']]);
