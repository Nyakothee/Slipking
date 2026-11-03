<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/wallets.php';

if (!stripe_ready()) json_out(['ok' => false, 'message' => 'Card payments are not available right now.'], 503);
list($user, $plan, $coupon, $pct, $kes, $usd) = wallet_preflight('stripe', 'Stripe');
$pdo = db();

list($amount, $cur) = wallet_charge('stripe', $plan, $kes, $usd, strtoupper(post_str('currency')));
if ($cur !== 'USD' && !wallet_currency_ok($cur)) json_out(['ok' => false, 'message' => 'That currency is not available.'], 400);
$reference = new_payment_reference();
$pdo->prepare("INSERT INTO payments (user_id, plan_id, method, amount, currency, coupon_code, discount_percent, status, reference)
               VALUES (?, ?, 'stripe', ?, ?, ?, ?, 'pending', ?)")
    ->execute([$user['id'], $plan['id'], $amount, $cur, $coupon['code'] ?? null, $pct, $reference]);
$paymentId = (int)$pdo->lastInsertId();

$r = stripe_request('POST', '/checkout/sessions', [
    'mode' => 'payment',
    'success_url' => url('payments/stripe-return.php?reference=' . $reference),
    'cancel_url' => url('payments/checkout.php?plan=' . $plan['slug']),
    'client_reference_id' => $reference,
    'customer_email' => $user['email'],
    'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($cur), 'unit_amount' => (int)round($amount * 100),
                      'product_data' => ['name' => SITE_NAME . ' - ' . $plan['name']]]]],
    'metadata' => ['payment_id' => $paymentId, 'plan' => $plan['slug']],
]);
$d = $r['data'] ?? null;
if ($r['status'] !== 200 || !is_array($d) || empty($d['url']) || empty($d['id'])) {
    payments_log('stripe-init-failed', ['http' => $r['status'], 'body' => substr($r['raw'], 0, 400)]);
    fail_payment($paymentId, 'failed', 'init failed');
    json_out(['ok' => false, 'message' => 'Stripe could not start the payment. Please try again.'], 502);
}
$pdo->prepare('UPDATE payments SET gateway_ref = ? WHERE id = ?')->execute([substr((string)$d['id'], 0, 80), $paymentId]);
payments_log('stripe-init', ['payment' => $paymentId, 'user' => $user['id'], 'amount' => $amount, 'cur' => $cur]);
json_out(['ok' => true, 'url' => $d['url']]);
