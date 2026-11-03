<?php
/**
 * Safaricom posts the payment result here. No login, no session: it is called by their servers.
 * Protection: the secret key in the address, a match on the CheckoutRequestID we stored,
 * a check of the amount, and complete_payment() which can only pay once.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa.php';

header('Content-Type: application/json');
$accepted = '{"ResultCode":0,"ResultDesc":"Accepted"}';

if (!hash_equals(mpesa_callback_key(), (string)($_GET['k'] ?? ''))) {
    http_response_code(403);
    payments_log('callback-bad-key', ['ip' => client_ip()]);
    exit('{"ResultCode":1,"ResultDesc":"Rejected"}');
}

$body = (string)file_get_contents('php://input');
payments_log('callback-in', substr($body, 0, 3000));

$data = json_decode($body, true);
$cb   = $data['Body']['stkCallback'] ?? null;
if (!is_array($cb) || empty($cb['CheckoutRequestID'])) {
    exit($accepted);
}

$st = db()->prepare("SELECT * FROM payments WHERE mpesa_checkout_id = ? AND method = 'mpesa' LIMIT 1");
$st->execute([$cb['CheckoutRequestID']]);
$p = $st->fetch();
if (!$p) {
    payments_log('callback-unknown-checkout', $cb['CheckoutRequestID']);
    exit($accepted);
}
if ($p['status'] === 'paid') {
    exit($accepted);                              // repeated callback: nothing to do
}

$code = (string)($cb['ResultCode'] ?? '');
if ($code === '0') {
    $meta = [];
    foreach (($cb['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'])) {
            $meta[$item['Name']] = $item['Value'] ?? null;
        }
    }
    if (isset($meta['Amount']) && abs((float)$meta['Amount'] - (float)$p['amount']) > 0.01) {
        payments_log('AMOUNT-MISMATCH-CHECK-MANUALLY', ['payment' => $p['id'], 'expected' => $p['amount'], 'got' => $meta['Amount']]);
        $got = (float)$meta['Amount'];
        if ($got < (float)$p['amount']) {
            // Paid too little: keep it pending, tell the member, flag for staff.
            payment_flag((int)$p['id'], 'underpaid', 'Expected ' . $p['amount'] . ' got ' . $got . ' (receipt ' . ($meta['MpesaReceiptNumber'] ?? '?') . ')');
            notify_user((int)$p['user_id'], 'Payment amount was too low', 'We received KES ' . number_format($got) . ' but this plan costs KES ' . number_format((float)$p['amount']) . '. Please pay the difference or contact support with receipt ' . ($meta['MpesaReceiptNumber'] ?? '') . '.', 'support.php');
            exit($accepted);
        }
        // Paid more than needed: activate the plan and flag the extra for a refund review.
        payment_flag((int)$p['id'], 'overpaid', 'Expected ' . $p['amount'] . ' got ' . $got . ' (receipt ' . ($meta['MpesaReceiptNumber'] ?? '?') . ')');
        notify_user((int)$p['user_id'], 'You paid a little extra', 'We received KES ' . number_format($got) . ' for a KES ' . number_format((float)$p['amount']) . ' plan. Your plan is active. Contact support about the difference.', 'support.php');
    }
    complete_payment((int)$p['id'], [
        'receipt' => isset($meta['MpesaReceiptNumber']) ? substr((string)$meta['MpesaReceiptNumber'], 0, 30) : null,
        'phone'   => isset($meta['PhoneNumber']) ? substr((string)$meta['PhoneNumber'], 0, 20) : null,
        'raw'     => substr($body, 0, 3000),
    ]);
} else {
    fail_payment((int)$p['id'], $code === '1032' ? 'cancelled' : 'failed', 'code ' . $code . ': ' . ($cb['ResultDesc'] ?? ''));
}

exit($accepted);
