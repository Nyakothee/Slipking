<?php
/**
 * Run every 15 minutes from your hosting Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/check-payouts.php
 * Asks PayPal or Airtel how tipster withdrawals ended and marks them paid (or failed, which returns the money to the tipster's balance).
 * M-Pesa withdrawals are finished by Safaricom's result message (payments/b2c-result.php); this job does not need to touch them.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/config-wallets.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/currency.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/tipsters.php';
require_once dirname(__DIR__) . '/includes/payouts.php';

$rows = db()->query("SELECT * FROM tipster_payouts WHERE status = 'processing' AND method IN ('paypal','airtel') AND requested_at > NOW() - INTERVAL 10 DAY ORDER BY id LIMIT 50")->fetchAll();
$done = 0;
foreach ($rows as $po) { if (payout_settle($po) !== 'pending') { $done++; } }
echo 'checked ' . count($rows) . ' PayPal/Airtel withdrawal(s), settled ' . $done . ".\n";
