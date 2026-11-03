<?php
/**
 * Run once a day shortly after midnight East Africa Time (command line only):
 *   php /home/YOURUSER/public_html/cron/guarantee.php
 * Checks yesterday's VIP tips against the guarantee rule (Admin > Guarantee) and, if it applies, adds the
 * free VIP days. Safe to run more than once: each day is only ever paid out once.
 * Optional: pass a date to settle a specific day, e.g.  php cron/guarantee.php 2026-10-10
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/guarantee.php';

$day = $argv[1] ?? date('Y-m-d', strtotime('yesterday'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day >= date('Y-m-d')) {
    fwrite(STDERR, "Give a past date as YYYY-MM-DD.\n");
    exit(1);
}
try {
    $r = guarantee_run(db(), $day);
} catch (Throwable $e) {
    error_log('guarantee cron: ' . $e->getMessage());
    echo date('c') . " guarantee for $day FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
if ($r['status'] === 'granted') {
    payments_log('guarantee', ['day' => $day, 'members' => $r['members'], 'days' => $r['days']]);
}
echo date('c') . " guarantee for $day: {$r['status']} (settled VIP tips {$r['tips']}, members {$r['members']}, days each {$r['days']})\n";
