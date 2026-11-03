<?php
/**
 * Settles tips from live-score widgets. Every 10 minutes from Cron Jobs (optional: the site also checks by itself while it has visitors):
 *   php /home/YOURUSER/public_html/cron/widget-results.php
 * Needs: Admin > Widgets > "Settle tips from widget scores" switched on, and at least one widget marked "use for results".
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/search-alias.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/phone.php';
require_once dirname(__DIR__) . '/includes/game.php';
require_once dirname(__DIR__) . '/includes/webpush.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/alerts.php';
require_once dirname(__DIR__) . '/includes/tipsters.php';
require_once dirname(__DIR__) . '/includes/widgets.php';
require_once dirname(__DIR__) . '/includes/widget-scores.php';
$s = wscore_run();
echo date('c') . ' widget-results: found ' . $s['found'] . ', settled ' . $s['settled'] . ', left ' . $s['left'] . ' ' . implode(' ', $s['notes']) . "\n";
