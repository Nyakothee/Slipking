<?php
/**
 * Run every 5 minutes from your hosting Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/check-payments.php
 * Asks Stripe, MTN MoMo and Airtel Money about payments still waiting (the customer may have closed the page),
 * and activates the ones that were really paid. Pending mobile-money prompts older than 30 minutes are closed.
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
require_once dirname(__DIR__) . '/includes/wallets.php';

$rows = db()->query("SELECT * FROM payments WHERE method IN ('stripe','mtn','airtel') AND status = 'pending'
                     AND created_at < NOW() - INTERVAL 1 MINUTE AND created_at > NOW() - INTERVAL 2 DAY ORDER BY id LIMIT 60")->fetchAll();
$done = 0;
foreach ($rows as $p) {
    if (($p['method'] === 'stripe' && !stripe_ready()) || ($p['method'] === 'mtn' && !momo_ready()) || ($p['method'] === 'airtel' && !airtel_ready())) {
        continue;
    }
    if (wallet_confirm($p) !== 'pending') { $done++; }
}
echo 'checked ' . count($rows) . ' pending payment(s), settled ' . $done . ".\n";
