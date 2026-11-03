<?php
/**
 * Run every 5 minutes from your hosting Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/check-pending-mpesa.php
 * Asks Safaricom about payments whose callback never arrived, and activates the ones that were paid.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa.php';
require_once dirname(__DIR__) . '/includes/gateways.php';

$rows = !mpesa_ready() ? [] : db()->query("SELECT * FROM payments
                     WHERE method = 'mpesa' AND status = 'pending' AND mpesa_checkout_id IS NOT NULL
                       AND created_at < NOW() - INTERVAL 2 MINUTE AND created_at > NOW() - INTERVAL 1 DAY
                     ORDER BY id LIMIT 50")->fetchAll();
$done = 0;
foreach ($rows as $p) {
    if (resolve_mpesa_payment($p) !== 'pending') {
        $done++;
    }
    usleep(600000);                         // be gentle with Daraja
}
// Paystack payments whose customer never came back: ask Paystack too
if (PAYSTACK_SECRET_KEY !== '') {
    foreach (db()->query("SELECT * FROM payments WHERE method = 'paystack' AND status = 'pending' AND created_at < NOW() - INTERVAL 10 MINUTE AND created_at > NOW() - INTERVAL 1 DAY LIMIT 30")->fetchAll() as $pp) {
        if (paystack_confirm($pp) !== 'pending') { $done++; }
        usleep(400000);
    }
}
// Any payment (M-Pesa, PayPal, Paystack, Razorpay) still pending after a day is treated as failed
db()->exec("UPDATE payments SET status = 'failed' WHERE status = 'pending' AND created_at < NOW() - INTERVAL 1 DAY");
echo date('c') . " checked " . count($rows) . ", resolved $done\n";
