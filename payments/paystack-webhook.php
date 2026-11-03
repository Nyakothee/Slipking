<?php
/** Paystack calls this when a payment succeeds (even if the customer closed the browser). No login or session. */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/gateways.php';

header('Content-Type: application/json');
$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
if (PAYSTACK_SECRET_KEY === '' || $sig === '' || !hash_equals(hash_hmac('sha512', $raw, PAYSTACK_SECRET_KEY), $sig)) {
    http_response_code(401);
    payments_log('paystack-webhook-bad-signature', ['ip' => client_ip()]);
    exit('{"ok":false}');
}
payments_log('paystack-webhook-in', substr($raw, 0, 800));
$ev = json_decode($raw, true);
if (($ev['event'] ?? '') === 'charge.success') {
    $ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($ev['data']['reference'] ?? ''));
    $st = db()->prepare("SELECT * FROM payments WHERE reference = ? AND method = 'paystack' LIMIT 1");
    $st->execute([$ref]);
    if ($p = $st->fetch()) {
        paystack_confirm($p);                     // we verify with Paystack ourselves; the message alone is never trusted
    }
}
echo '{"ok":true}';
