<?php
/** Razorpay calls this when a payment is captured. Set RAZORPAY_WEBHOOK_SECRET and add this address in the Razorpay dashboard. No login or session. */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/gateways.php';

header('Content-Type: application/json');
$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
if (RAZORPAY_WEBHOOK_SECRET === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, RAZORPAY_WEBHOOK_SECRET), $sig)) {
    http_response_code(401);
    payments_log('razorpay-webhook-bad-signature', ['ip' => client_ip()]);
    exit('{"ok":false}');
}
payments_log('razorpay-webhook-in', substr($raw, 0, 800));
$ev = json_decode($raw, true);
$ent = $ev['payload']['payment']['entity'] ?? null;
if (in_array($ev['event'] ?? '', ['payment.captured', 'order.paid'], true) && is_array($ent)) {
    $st = db()->prepare("SELECT * FROM payments WHERE gateway_ref = ? AND method = 'razorpay' LIMIT 1");
    $st->execute([(string)($ent['order_id'] ?? '')]);
    if ($p = $st->fetch()) {
        razorpay_confirm($p, (string)($ent['id'] ?? ''));
    }
}
echo '{"ok":true}';
