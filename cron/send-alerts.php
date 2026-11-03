<?php
/**
 * Run every 5 minutes from Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/send-alerts.php
 * Queues kickoff reminders (about an hour before) and VIP-ending alerts, then delivers everything waiting:
 * browser push, email and SMS (only to members who turned them on). Safe to run often: nothing is sent twice.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/phone.php';
require_once dirname(__DIR__) . '/includes/game.php';
require_once dirname(__DIR__) . '/includes/webpush.php';
require_once dirname(__DIR__) . '/includes/alerts.php';
require_once dirname(__DIR__) . '/includes/tipsters.php';

$ko = alerts_kickoff_reminders();
$started = 0;
require_once dirname(__DIR__) . '/includes/installer.php';
try { require_once dirname(__DIR__) . '/includes/streamlink.php'; require_once dirname(__DIR__) . '/includes/streak.php'; $started = alerts_match_started(); } catch (Throwable $e) { }
$vip = alerts_vip_expiring();
$sent = 0; $failed = 0;
for ($i = 0; $i < 5; $i++) {            // up to 5 x 60 alerts per run
    [$s, $f] = alerts_process(60);
    $sent += $s; $failed += $f;
    if ($s + $f < 60) { break; }
}
try { db()->exec('DELETE FROM alert_queue WHERE created_at < NOW() - INTERVAL 14 DAY AND sent_at IS NOT NULL'); } catch (Throwable $e) {}
echo date('c') . " alerts: queued kickoff $ko, started/live $started, vip $vip; delivered $sent, failed $failed\n";
