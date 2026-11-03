<?php
/**
 * Run once a day from Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/game-prizes.php
 * Gives the free-VIP-day prizes of the prediction game for the week and month that just ended. Safe to run many times (nobody is paid twice).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/game.php';
foreach (['week', 'month'] as $type) {
    foreach (game_award_prizes($type, -1) as $line) {
        echo date('c') . " $type: $line\n";
    }
}
// Weekly top-3 badge for everyone who finished in the paid ranks
$pdo = db();
$p = game_period('week', -1);
$rows = $pdo->prepare("SELECT user_id FROM game_prizes WHERE period_type = 'week' AND period_key = ?");
$rows->execute([$p['key']]);
foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $uid) { badge_award((int)$uid, 'top3_week'); }
