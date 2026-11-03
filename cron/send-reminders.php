<?php
/**
 * Run once a day from Cron Jobs (command line only):
 *   php /home/YOURUSER/public_html/cron/send-reminders.php
 * Tells VIP members (in their account and by email) that their plan ends within 3 days.
 * One reminder per member per plan period.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/offers.php';

$pdo = db();
$rows = $pdo->query("SELECT u.id, u.name, u.email, MAX(s.ends_at) AS ends_at
                     FROM subscriptions s JOIN users u ON u.id = s.user_id
                     WHERE s.status = 'active' AND s.ends_at > NOW()
                     GROUP BY u.id, u.name, u.email
                     HAVING MAX(s.ends_at) < NOW() + INTERVAL 3 DAY")->fetchAll();
$sent = 0;
foreach ($rows as $r) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Your VIP plan ends soon' AND created_at > NOW() - INTERVAL 5 DAY");
    $st->execute([$r['id']]);
    if ((int)$st->fetchColumn() > 0) {
        continue;                                   // already reminded for this period
    }
    $when = date('j F Y, H:i', strtotime($r['ends_at']));
    notify_user((int)$r['id'], 'Your VIP plan ends soon', "Your VIP plan ends on $when. To keep every VIP pick, buy another plan before then. The new days are added after your current plan.");
    send_mail($r['email'], 'Your ' . SITE_NAME . ' VIP plan ends soon',
        "Hi {$r['name']},\n\nYour VIP plan ends on $when.\nTo keep getting every VIP pick, choose a plan here (new days are added after your current plan):\n\n" .
        rtrim(SITE_URL, '/') . "/vip.php\n\n" . SITE_NAME);
    $sent++;
    usleep(300000);
}

$optoutLine = "\n\nTo stop these reminder emails, turn them off here: " . rtrim(SITE_URL, '/') . '/account/profile.php';
$notQuiet = "u.role = 'user' AND u.email_optout = 0
             AND NOT EXISTS (SELECT 1 FROM subscriptions s0 WHERE s0.user_id = u.id AND s0.status = 'active' AND s0.ends_at > NOW())";
$recent = function (int $uid, string $title, int $days) use ($pdo) {
    $q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = ? AND created_at > NOW() - INTERVAL ' . (int)$days . ' DAY');
    $q->execute([$uid, $title]);
    return (int)$q->fetchColumn() > 0;
};

// 1) Started buying a plan 1-24 hours ago but never finished
$abandoned = 0;
$rows = $pdo->query("SELECT DISTINCT u.id, u.name, u.email FROM payments py JOIN users u ON u.id = py.user_id
                     WHERE py.status IN ('pending','failed','cancelled') AND py.created_at < NOW() - INTERVAL 1 HOUR AND py.created_at > NOW() - INTERVAL 24 HOUR
                       AND NOT EXISTS (SELECT 1 FROM payments p2 WHERE p2.user_id = u.id AND p2.status = 'paid' AND p2.created_at > py.created_at)
                       AND $notQuiet LIMIT 100")->fetchAll();
foreach ($rows as $r) {
    if ($recent((int)$r['id'], 'Finish your VIP purchase', 3)) continue;
    $msg = "You started choosing a VIP plan but the payment did not finish. If something went wrong with your payment, use the chat button on the site or contact us with your receipt and we will help. If you changed your mind, you can ignore this.";
    notify_user((int)$r['id'], 'Finish your VIP purchase', $msg . ' Plans: ' . rtrim(SITE_URL, '/') . '/vip.php');
    send_mail($r['email'], 'Need help finishing your ' . SITE_NAME . ' VIP purchase?', "Hi {$r['name']},\n\n$msg\n\nPlans: " . rtrim(SITE_URL, '/') . "/vip.php" . $optoutLine);
    $abandoned++;
    usleep(300000);
}

// 2) VIP ended 3-10 days ago (including free trials): a gentle win-back message, once a month
$winback = 0;
$code = (string)setting('winback_coupon', '');
$couponLine = '';
if ($code !== '') {
    $q = $pdo->prepare('SELECT percent_off FROM coupons WHERE code = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW()) AND (max_uses IS NULL OR used < max_uses)');
    $q->execute([$code]);
    if ($pct = $q->fetchColumn()) {
        $couponLine = " Use code $code at checkout for $pct% off.";
    }
}
$rows = $pdo->query("SELECT u.id, u.name, u.email FROM users u JOIN (SELECT user_id, MAX(ends_at) AS e FROM subscriptions GROUP BY user_id) s ON s.user_id = u.id
                     WHERE s.e < NOW() - INTERVAL 3 DAY AND s.e > NOW() - INTERVAL 10 DAY AND $notQuiet LIMIT 100")->fetchAll();
foreach ($rows as $r) {
    if ($recent((int)$r['id'], 'We miss you', 30)) continue;
    $msg = "Your VIP plan ended a few days ago. If you would like every VIP pick again, you can choose a plan any time." . $couponLine . " Check our public results first so you can judge for yourself.";
    notify_user((int)$r['id'], 'We miss you', $msg);
    send_mail($r['email'], 'Your ' . SITE_NAME . ' VIP picks', "Hi {$r['name']},\n\n$msg\n\nPlans: " . rtrim(SITE_URL, '/') . "/vip.php\nResults: " . rtrim(SITE_URL, '/') . "/results.php" . $optoutLine);
    $winback++;
    usleep(300000);
}
echo date('c') . " reminders sent: $sent, checkout nudges: $abandoned, win-back: $winback\n";
