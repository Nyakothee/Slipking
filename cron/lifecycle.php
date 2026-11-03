<?php
/**
 * Daily lifecycle emails and housekeeping (command line only):   php /home/YOURUSER/public_html/cron/lifecycle.php
 *  - welcome email 1 day after sign-up (once)
 *  - win-back email 3-10 days after a VIP plan ended (once per plan end)
 *  - deletes multi-account signals older than 90 days and the cache older than 2 days
 * Members who switched emails off, and members on a self-exclusion break, never get these emails.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/fraud.php';
require_once dirname(__DIR__) . '/includes/cache.php';

$pdo = db();
$base = rtrim(SITE_URL, '/');
$ok = "u.role = 'user' AND u.email_optout = 0 AND u.email_verified = 1
       AND NOT EXISTS (SELECT 1 FROM user_limits l WHERE l.user_id = u.id AND (l.excluded_forever = 1 OR l.excluded_until > NOW()))";
$already = $pdo->prepare('SELECT 1 FROM lifecycle_log WHERE user_id = ? AND kind = ? AND ref = ?');
$mark = $pdo->prepare('INSERT IGNORE INTO lifecycle_log (user_id, kind, ref, sent_at) VALUES (?,?,?,NOW())');
$opt = "\n\nTo stop these emails turn them off in your profile: $base/account/profile.php";
$w = $b = 0;

$rows = $pdo->query("SELECT u.id, u.name, u.email FROM users u WHERE $ok AND u.created_at < NOW() - INTERVAL 1 DAY AND u.created_at > NOW() - INTERVAL 7 DAY LIMIT 200")->fetchAll();
foreach ($rows as $r) {
    $already->execute([$r['id'], 'welcome', '']);
    if ($already->fetchColumn()) { continue; }
    send_mail($r['email'], 'Welcome to ' . SITE_NAME, "Hi {$r['name']},\n\nThanks for joining " . SITE_NAME . ". Three things to try:\n\n1. Today's free tips: $base/tips.php\n2. Our free prediction game (win free VIP days): $base/game.php\n3. Match statistics and predictions: $base/predictions.php\n\nTips are opinions, not guarantees. 18+ only. Bet responsibly: $base/responsible-gambling.php" . $opt);
    $mark->execute([$r['id'], 'welcome', '']);
    $w++; usleep(300000);
}

$rows = $pdo->query("SELECT u.id, u.name, u.email, MAX(s.ends_at) AS ended FROM users u JOIN subscriptions s ON s.user_id = u.id
                     WHERE $ok GROUP BY u.id, u.name, u.email
                     HAVING MAX(s.ends_at) BETWEEN NOW() - INTERVAL 10 DAY AND NOW() - INTERVAL 3 DAY LIMIT 200")->fetchAll();
foreach ($rows as $r) {
    $ref = date('Y-m-d', strtotime($r['ended']));
    $already->execute([$r['id'], 'winback', $ref]);
    if ($already->fetchColumn()) { continue; }
    send_mail($r['email'], 'We miss you at ' . SITE_NAME, "Hi {$r['name']},\n\nYour VIP plan ended on " . date('j F Y', strtotime($r['ended'])) . ". If you want the VIP picks back, you can renew here:\n\n$base/vip.php\n\nOur record is public, good days and bad: $base/performance.php\n\n18+ only. No tip is guaranteed." . $opt);
    $mark->execute([$r['id'], 'winback', $ref]);
    $b++; usleep(300000);
}

fraud_cleanup();
try { $pdo->exec("DELETE FROM lifecycle_log WHERE sent_at < NOW() - INTERVAL 400 DAY"); } catch (Throwable $e) {}
$n = 0;
foreach (glob(dirname(__DIR__) . '/cache/*.cache') ?: [] as $f) { if (filemtime($f) < time() - 2 * 86400) { @unlink($f); $n++; } }
echo date('c') . " lifecycle: $w welcome, $b win-back, $n cache files removed\n";
