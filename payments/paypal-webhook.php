<?php
/**
 * PayPal calls this when a payment is captured, even if the customer closed the browser too early.
 * In PayPal's developer dashboard add a webhook for  https://yourdomain.com/payments/paypal-webhook.php
 * with the event "Payment capture completed", then put its Webhook ID in includes/config-payments.php.
 * No login or session here: it is called by PayPal's servers.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/paypal.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !paypal_ready()) {
    http_response_code(405);
    exit('{"ok":false}');
}

$raw = (string)file_get_contents('php://input');
$ev  = json_decode($raw, true);
payments_log('paypal-webhook-in', substr($raw, 0, 1500));
if (!is_array($ev) || empty($ev['event_type'])) {
    http_response_code(400);
    exit('{"ok":false}');
}

// If a Webhook ID is set, the signature must check out. Without one, we still only act on data
// we fetch from PayPal ourselves (below), so a fake message cannot activate anything.
if (PAYPAL_WEBHOOK_ID !== '' && !paypal_verify_webhook($raw)) {
    payments_log('paypal-webhook-bad-signature', ['ip' => client_ip()]);
    http_response_code(400);
    exit('{"ok":false}');
}

if ($ev['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
    $orderId = (string)($ev['resource']['supplementary_data']['related_ids']['order_id'] ?? '');
    if (preg_match('/^[A-Z0-9]{10,30}$/', $orderId)) {
        $st = db()->prepare("SELECT * FROM payments WHERE paypal_order_id = ? AND method = 'paypal' LIMIT 1");
        $st->execute([$orderId]);
        $p = $st->fetch();
        if ($p && $p['status'] !== 'paid') {
            $order = paypal_get_order($orderId);        // ask PayPal directly: this is the trusted source
            if ($order) {
                paypal_finalize($p, $order);
            }
        }
    }
}

echo '{"ok":true}';
