<?php
/**
 * Run once a week from Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/cleanup.php
 * Removes old closed chats (90+ days), old login attempts and old password-reset links.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pdo = db();
$a = $pdo->exec("DELETE FROM chat_threads WHERE status = 'closed' AND last_message_at < NOW() - INTERVAL 90 DAY");
$b = $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 2 DAY');
$pdo->exec('DELETE FROM phone_codes WHERE created_at < NOW() - INTERVAL 2 DAY');
$c = $pdo->exec('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY');
echo date('c') . " cleanup: chats $a, attempts $b, reset links $c\n";
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/housekeeping.php';
foreach (housekeeping(true) as $k => $n) { echo "  $k: $n\n"; }
