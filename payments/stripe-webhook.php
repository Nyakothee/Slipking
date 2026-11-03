<?php
/** Stripe calls this when a card payment completes (even if the customer closed the browser). No login or session. */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/config-wallets.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/wallets.php';

header('Content-Type: application/json');
$raw = (string)file_get_contents('php://input');
if (!stripe_signature_ok($raw, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''))) {
    http_response_code(401);
    payments_log('stripe-webhook-bad-signature', ['ip' => client_ip()]);
    exit('{"ok":false}');
}
payments_log('stripe-webhook-in', substr($raw, 0, 800));
$ev = json_decode($raw, true);
if (in_array($ev['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
    $ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($ev['data']['object']['client_reference_id'] ?? ''));
    $st = db()->prepare("SELECT * FROM payments WHERE reference = ? AND method = 'stripe' LIMIT 1");
    $st->execute([$ref]);
    if ($p = $st->fetch()) {
        stripe_confirm($p);                       // we ask Stripe ourselves; the message alone is never trusted
    }
}
echo '{"ok":true}';
