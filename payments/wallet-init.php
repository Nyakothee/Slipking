<?php
/** Starts an MTN MoMo or Airtel Money payment: sends the prompt to the customer's phone. POST provider=mtn|airtel. */
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/wallets.php';

$provider = ($_POST['provider'] ?? '') === 'airtel' ? 'airtel' : (($_POST['provider'] ?? '') === 'mtn' ? 'mtn' : '');
if ($provider === '') json_out(['ok' => false, 'message' => 'Invalid request.'], 400);
if (($provider === 'mtn' && !momo_ready()) || ($provider === 'airtel' && !airtel_ready())) {
    json_out(['ok' => false, 'message' => 'This payment method is not available right now.'], 503);
}
list($user, $plan, $coupon, $pct, $kes, $usd) = wallet_preflight($provider, $provider);
$pdo = db();

$prefix = $provider === 'mtn' ? MTN_MOMO_MSISDN_PREFIX : AIRTEL_MSISDN_PREFIX;
$msisdn = wallet_msisdn(post_str('phone'), $prefix);
if (strlen($msisdn) < strlen($prefix) + 8 || strlen($msisdn) > strlen($prefix) + 10) {
    json_out(['ok' => false, 'message' => 'Enter a valid mobile money number.'], 400);
}
list($amount, $cur) = wallet_charge($provider, $plan, $kes, $usd);
$reference = new_payment_reference();
$gref = $provider === 'mtn' ? wallet_uuid() : $reference;
$pdo->prepare("INSERT INTO payments (user_id, plan_id, method, amount, currency, coupon_code, discount_percent, status, reference, phone, gateway_ref)
               VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)")
    ->execute([$user['id'], $plan['id'], $provider, $amount, $cur, $coupon['code'] ?? null, $pct, $reference, substr($msisdn, 0, 20), $gref]);
$paymentId = (int)$pdo->lastInsertId();
$p = ['id' => $paymentId, 'amount' => $amount, 'currency' => $cur, 'reference' => $reference, 'gateway_ref' => $gref];

if ($provider === 'mtn') {
    list($ok, $msg) = momo_request_to_pay($p, $msisdn);
} else {
    list($ok, $msg) = airtel_collect($p, substr($msisdn, strlen($prefix)));
}
if (!$ok) {
    fail_payment($paymentId, 'failed', 'prompt not sent');
    json_out(['ok' => false, 'message' => $msg], 502);
}
payments_log($provider . '-init', ['payment' => $paymentId, 'user' => $user['id'], 'amount' => $amount, 'cur' => $cur]);
json_out(['ok' => true, 'ref' => $reference, 'message' => $msg]);
