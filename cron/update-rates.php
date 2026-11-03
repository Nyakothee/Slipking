<?php
/**
 * Run once a day from Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/update-rates.php
 * Refreshes the exchange rates of every currency that has "Auto-update" ticked.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/currency.php';

$r = update_rates_from_api();
echo date('c') . ' ' . $r['message'] . "\n";
