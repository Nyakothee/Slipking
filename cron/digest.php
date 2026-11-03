<?php
/**
 * Run once an HOUR from Cron Jobs (0 * * * *) so each member gets it at the hour they chose (command line only):
 *   php /home/YOURUSER/public_html/cron/digest.php
 * Emails the daily digest to members who turned it on (Account > Daily digest). One email per member per day.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
if (!digest_ready()) { echo "digest table missing\n"; exit(1); }
if (function_exists('alerts2_ready')) { alerts2_ready(); }
$pdo = db();
$cand = $pdo->query("SELECT u.id, u.name, u.email, COALESCE(x.digest_hour, 8) AS hr, x.tz FROM digest_prefs d JOIN users u ON u.id = d.user_id
                     LEFT JOIN alert_extra x ON x.user_id = u.id
                     WHERE d.email_daily = 1 AND u.email_optout = 0 AND COALESCE(x.digest_hour, 8) >= 0 AND (d.last_sent IS NULL OR d.last_sent < NOW() - INTERVAL 20 HOUR) LIMIT 3000")->fetchAll();
// The hour a member chose is in THEIR time zone (detected from their browser); default Africa/Nairobi.
$rows = [];
foreach ($cand as $c) {
    try { $h = (int)(new DateTime('now', new DateTimeZone($c['tz'] ?: 'Africa/Nairobi')))->format('G'); } catch (Throwable $e) { $h = (int)date('G'); }
    if ($h === (int)$c['hr']) { $rows[] = $c; if (count($rows) >= 500) { break; } }
}
$sent = 0;
foreach ($rows as $r) {
    [$subj, $body] = digest_build($r);
    if (send_mail($r['email'], $subj, $body)) {
        $pdo->prepare('UPDATE digest_prefs SET last_sent = NOW() WHERE user_id = ?')->execute([(int)$r['id']]);
        $sent++;
    }
    usleep(300000);
}
echo "digest sent: $sent\n";
// Weekly summary: Mondays, 08:00 hour
if ((int)date('N') === 1 && (int)date('G') === 8 && function_exists('alerts2_ready') && alerts2_ready()) {
    $wk = $pdo->query("SELECT u.id, u.name, u.email FROM alert_extra x JOIN users u ON u.id = x.user_id WHERE x.weekly = 1 AND u.email_optout = 0 AND (x.last_weekly IS NULL OR x.last_weekly < NOW() - INTERVAL 6 DAY) LIMIT 500")->fetchAll();
    $r = $pdo->query("SELECT SUM(status='won') w, SUM(status='lost') l FROM tips WHERE deleted_at IS NULL AND is_vip = 0 AND status IN ('won','lost') AND posted_at > NOW() - INTERVAL 7 DAY")->fetch();
    $ws = 0;
    foreach ($wk as $r2) {
        $body = "Hi " . mask_name((string)$r2['name']) . ",\n\nLast week our free tips: " . (int)$r['w'] . " won, " . (int)$r['l'] . " lost.\nThis week's tips: " . url('tips.php') . "\nResults: " . url('results.php') . "\n\nChange this in " . url('account/alerts.php');
        if (send_mail($r2['email'], 'Your ' . SITE_NAME . ' weekly summary', $body)) { $pdo->prepare('UPDATE alert_extra SET last_weekly = NOW() WHERE user_id = ?')->execute([(int)$r2['id']]); $ws++; }
        usleep(300000);
    }
    echo "weekly sent: $ws\n";
}
$v = function_exists('member_auto_verify') ? member_auto_verify() : 0;
echo "newly verified members: $v\n";
