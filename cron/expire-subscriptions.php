<?php
/**
 * Run once an hour from Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/expire-subscriptions.php
 * Marks finished plans as expired and tells the member. Access already stops on its own at the end date;
 * this keeps the records tidy and sends the "your plan ended" message.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$pdo = db();
// Members whose plan has just run out and who have nothing active left
$users = $pdo->query("SELECT DISTINCT s.user_id FROM subscriptions s
                      WHERE s.status = 'active' AND s.ends_at <= NOW()
                        AND NOT EXISTS (SELECT 1 FROM subscriptions s2 WHERE s2.user_id = s.user_id AND s2.status = 'active' AND s2.ends_at > NOW())")
             ->fetchAll(PDO::FETCH_COLUMN);
foreach ($users as $uid) {
    notify_user((int)$uid, 'Your VIP plan has ended', 'Your VIP plan has ended. You can still use the free tips. To get every VIP pick again, choose a plan on the VIP page.');
}
$n = $pdo->exec("UPDATE subscriptions SET status = 'expired' WHERE status = 'active' AND ends_at <= NOW()");
echo date('c') . " expired $n subscription(s), notified " . count($users) . " member(s)\n";
