<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/gateways.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'message' => 'Invalid request.'], 405);
$user = current_user();
if (!$user) json_out(['ok' => false, 'message' => 'Please log in first.'], 401);
if (!csrf_check()) json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
if (!phone_gate_ok($user)) json_out(['ok' => false, 'message' => 'Please confirm your mobile number with the SMS code first (Account > Profile).'], 403);
if (!paystack_ready()) json_out(['ok' => false, 'message' => 'Paystack is not available right now.'], 503);

$pdo = db();
$st = $pdo->prepare('SELECT * FROM plans WHERE slug = ? AND is_active = 1 LIMIT 1');
$st->execute([preg_replace('/[^a-z0-9\-]/', '', strtolower(post_str('plan')))]);
$plan = $st->fetch();
if ($plan) { $plan = tipster_fee_plan_for($plan, $user); }
if (!$plan) json_out(['ok' => false, 'message' => 'That plan is not available.'], 400);
$lb = limits_payment_block((int)$user['id'], (int)$plan['price_kes']);
if ($lb !== '') { json_out(['ok' => false, 'message' => $lb], 403); }

$cres = find_coupon(post_str('coupon'), (int)$user['id']);
if (!empty($plan['tipster_id'])) { $cres = ['ok' => true, 'coupon' => null, 'message' => '']; }   // coupons never apply to tipster plans
if (!$cres['ok']) json_out(['ok' => false, 'message' => $cres['message']], 400);
$coupon = $cres['coupon'];
$pct = $coupon ? (int)$coupon['percent_off'] : 0;

$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND method = 'paystack' AND created_at > NOW() - INTERVAL 10 MINUTE");
$st->execute([$user['id']]);
if ((int)$st->fetchColumn() >= 6) json_out(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);

$kes = $pct ? discounted_kes((int)$plan['price_kes'], $pct) : (int)$plan['price_kes'];
$ngn = local_price($kes, 'NGN', 10, 100);
$reference = new_payment_reference();
$pdo->prepare("INSERT INTO payments (user_id, plan_id, method, amount, currency, coupon_code, discount_percent, status, reference)
               VALUES (?, ?, 'paystack', ?, 'NGN', ?, ?, 'pending', ?)")
    ->execute([$user['id'], $plan['id'], $ngn, $coupon['code'] ?? null, $pct, $reference]);
$paymentId = (int)$pdo->lastInsertId();

$r = paystack_request('POST', '/transaction/initialize', [
    'email' => $user['email'], 'amount' => $ngn * 100, 'currency' => 'NGN', 'reference' => $reference,
    'callback_url' => url('payments/paystack-callback.php'), 'metadata' => ['payment_id' => $paymentId, 'plan' => $plan['slug']],
]);
$d = $r['data']['data'] ?? null;
if ($r['status'] !== 200 || !is_array($d) || empty($d['authorization_url'])) {
    payments_log('paystack-init-failed', ['http' => $r['status'], 'body' => substr($r['raw'], 0, 400)]);
    fail_payment($paymentId, 'failed', 'init failed');
    json_out(['ok' => false, 'message' => 'Paystack could not start the payment. Please try again.'], 502);
}
$pdo->prepare('UPDATE payments SET gateway_ref = ? WHERE id = ?')->execute([substr((string)($d['access_code'] ?? ''), 0, 80), $paymentId]);
payments_log('paystack-init', ['payment' => $paymentId, 'user' => $user['id'], 'ngn' => $ngn]);
json_out(['ok' => true, 'url' => $d['authorization_url']]);
