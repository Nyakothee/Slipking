<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa.php';

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
if (!mpesa_ready()) {
    json_out(['ok' => false, 'message' => 'M-Pesa is not available right now.'], 503);
}

$pdo = db();
$st  = $pdo->prepare('SELECT * FROM plans WHERE slug = ? AND is_active = 1 LIMIT 1');
$st->execute([preg_replace('/[^a-z0-9\-]/', '', strtolower(post_str('plan')))]);
$plan = $st->fetch();
if ($plan) { $plan = tipster_fee_plan_for($plan, $user); }
if (!$plan) {
    json_out(['ok' => false, 'message' => 'That plan is not available.'], 400);
}
$lb = limits_payment_block((int)$user['id'], (int)$plan['price_kes']);
if ($lb !== '') { json_out(['ok' => false, 'message' => $lb], 403); }

$cres = find_coupon(post_str('coupon'), (int)$user['id']);
if (!empty($plan['tipster_id'])) { $cres = ['ok' => true, 'coupon' => null, 'message' => '']; }   // coupons never apply to tipster plans
if (!$cres['ok']) {
    json_out(['ok' => false, 'message' => $cres['message']], 400);
}
$coupon = $cres['coupon'];
$pct    = $coupon ? (int)$coupon['percent_off'] : 0;

$phone = normalize_phone(post_str('phone'));
if ($phone === null) {
    json_out(['ok' => false, 'message' => 'Enter a valid Safaricom number, like 0712 345 678.'], 400);
}

// At most 5 prompts per member every 10 minutes (stops accidental or abusive repeats)
$st = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND method = 'mpesa' AND created_at > NOW() - INTERVAL 10 MINUTE");
$st->execute([$user['id']]);
if ((int)$st->fetchColumn() >= 5) {
    json_out(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);
}

$amount    = $pct ? discounted_kes((int)$plan['price_kes'], $pct) : (int)$plan['price_kes'];
$reference = new_payment_reference();
$pdo->prepare("INSERT INTO payments (user_id, plan_id, method, amount, currency, coupon_code, discount_percent, status, reference, phone)
               VALUES (?, ?, 'mpesa', ?, 'KES', ?, ?, 'pending', ?, ?)")
    ->execute([$user['id'], $plan['id'], $amount, $coupon['code'] ?? null, $pct, $reference, $phone]);
$paymentId = (int)$pdo->lastInsertId();

$pdo->prepare('UPDATE users SET phone = ? WHERE id = ? AND phone IS NULL')->execute([$phone, $user['id']]);

$res = mpesa_stk_push($phone, $amount, $reference, SITE_NAME . ' VIP');
if (!$res['ok']) {
    fail_payment($paymentId, 'failed', $res['raw']);
    json_out(['ok' => false, 'message' => $res['message']], 502);
}

$pdo->prepare('UPDATE payments SET mpesa_checkout_id = ?, raw_response = ? WHERE id = ?')
    ->execute([$res['checkout_id'], $res['raw'], $paymentId]);
payments_log('mpesa-push-sent', ['payment' => $paymentId, 'user' => $user['id'], 'amount' => $amount, 'checkout' => $res['checkout_id']]);

json_out(['ok' => true, 'ref' => $reference, 'message' => 'Check your phone and enter your M-Pesa PIN.']);
