<?php
/**
 * SlipKing setup check - finds out WHY the site will not start.
 * 1) Change the key on the next line to any long secret text.
 * 2) Upload, then open  https://yourdomain.com/check.php?key=THE_KEY_YOU_CHOSE
 * 3) DELETE THIS FILE from the server when you are done (it shows technical details).
 */
$KEY = 'change-me-to-something-long';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
if ($KEY === 'change-me-to-something-long' || !hash_equals($KEY, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Open check.php, change the $KEY line near the top to your own secret text, upload it again, then visit check.php?key=YOURSECRET');
}

$rows = [];
function row(bool $ok, string $what, string $detail = ''): void
{
    global $rows;
    $rows[] = [$ok, $what, $detail];
}
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* 1. PHP and extensions */
row(version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP version ' . PHP_VERSION, 'Needs 7.4 or newer (8.x is best). Change it in your hosting panel: "Select PHP Version".');
foreach (['pdo_mysql' => 'Needed to talk to the database. Enable it in "Select PHP Version" > Extensions.',
          'curl' => 'Needed for M-Pesa, PayPal and exchange rates.',
          'mbstring' => 'Needed for text handling.',
          'json' => '', 'openssl' => ''] as $ext => $hint) {
    row(extension_loaded($ext), "PHP extension: $ext", $hint);
}

/* 1b. Are all the files there? (a 404 "Not Found" from Apache means a file is missing from the folder) */
$must = ['api/v1/index.php', 'includes/api.php', 'api/app-widget.php', 'api/app-register-token.php', 'database-update-27.sql', 'assets/css/app.css', 'includes/app.php', 'admin/app.php', 'admin/app-upload.php', 'download-app.php', 'app-download.php', 'database-update-26.sql', 'includes/community3.php', 'account/messages.php', 'account/odds-watch.php', 'community-search.php', 'slip-share.php', 'admin/community-stats.php', 'database-update-25.sql', 'includes/community2.php', 'includes/group-render.php', 'includes/chat-box.php', 'admin/chat-reports.php', 'account/digest.php', 'cron/digest.php', 'assets/js/share.js', 'globalchat.php', 'api/globalchat.php', 'includes/dh-side.php', 'assets/css/group-page.css', 'assets/css/global-chat.css', 'assets/js/globalchat.js', 'assets/css/discuss-hub.css', 'jackpot.php', 'member.php', 'members.php', 'admin/jackpot.php', 'account/public-profile.php', 'includes/jackpot.php', 'includes/members.php', 'assets/css/jackpot.css', 'assets/css/members.css', 'game.php', 'live.php', 'tipster-record.php', 'referral-leaderboard.php', 'community/action.php', 'api/live.php', 'api/push-subscribe.php', 'account/alerts.php', 'account/badges.php', 'admin/game.php', 'admin/live.php', 'admin/comments.php', 'admin/push.php', 'admin/tipster-claims.php', 'includes/game.php', 'includes/alerts.php', 'includes/webpush.php', 'includes/community.php', 'includes/record.php', 'includes/live.php', 'assets/css/group-f.css', 'assets/js/live.js', 'assets/js/push.js', 'cron/send-alerts.php', 'cron/live-scores.php', 'cron/game-prizes.php', 'tipsters.php', 'tipster.php', 'leaderboard.php', 'auth/register-tipster.php', 'auth/verify-phone.php', 'tipster/index.php', 'tipster/apply.php',
         'tipster/profile.php', 'tipster/tips.php', 'tipster/plans.php', 'tipster/earnings.php', 'account/tipsters.php', 'admin/tipsters.php',
         'admin/tipster-payouts.php', 'admin/reviews.php', 'admin/staff.php', 'includes/tipsters.php', 'includes/phone.php', 'includes/countries.php',
         'includes/permissions.php', 'assets/css/group-e.css'];
$lost = [];
foreach ($must as $f) { if (!is_file(__DIR__ . '/' . $f)) { $lost[] = $f; } }
row(!$lost, 'All version 4 files are present (tipsters, SMS, staff roles)', $lost ? 'MISSING: ' . implode(', ', $lost) . '. Upload the whole zip again and overwrite existing files (do not skip any).' : '');

$must2 = ['football.php', 'league-table.php', 'team.php', 'fixture.php', 'predictions.php', 'odds.php', 'odds-event.php', 'audit.php', 'account/limits.php', 'admin/data-sources.php', 'admin/analytics.php', 'admin/fraud.php',
          'includes/stats.php', 'includes/odds.php', 'includes/cache.php', 'includes/audit.php', 'includes/fraud.php', 'includes/limits.php', 'includes/mpesa-b2c.php', 'includes/config-data.php',
          'payments/b2c-result.php', 'cron/sync-stats.php', 'cron/sync-odds.php', 'cron/lifecycle.php', 'lang/sw.txt', 'database-update-12.sql'];
$lost2 = [];
foreach ($must2 as $f) { if (!is_file(__DIR__ . '/' . $f)) { $lost2[] = $f; } }
row(!$lost2, 'All version 5 files are present (statistics, odds, audit, limits)', $lost2 ? 'MISSING: ' . implode(', ', $lost2) . '. Upload the whole zip again and overwrite existing files.' : '');
$cd = __DIR__ . '/includes/config-data.php';
if (is_file($cd)) {
    require_once $cd;
    row(true, 'Free data keys (optional): football-data.org ' . (FOOTBALL_DATA_KEY !== '' ? 'SET' : 'empty') . ', The Odds API ' . (ODDS_API_KEY !== '' ? 'SET' : 'empty'));
}
$must3 = ['includes/wallets.php', 'includes/pay-ui.php', 'includes/config-wallets.php', 'assets/css/pay.css', 'payments/stripe-init.php', 'payments/stripe-return.php', 'payments/stripe-webhook.php',
          'payments/wallet-init.php', 'payments/wallet-status.php', 'cron/check-payments.php', 'admin/payment-methods.php', 'database-update-13.sql',
          'includes/profile.php', 'assets/css/profile.css', 'assets/css/forms.css', 'assets/css/nav-extras.css', 'assets/js/country-picker.js', 'database-update-17.sql', 'database-update-18.sql', 'database-update-19.sql', 'room.php', 'includes/room.php', 'assets/css/room.css', 'assets/css/tipster-profile.css', 'assets/css/nav-extras.css', 'includes/installer.php', 'includes/tipster-profile.php', 'includes/polls.php', 'includes/clubs.php', 'includes/arena.php', 'includes/slips.php', 'includes/media.php', 'media.php', 'includes/communities.php', 'm.php', 'post.php', 'admin/communities.php', 'assets/js/composer.js', 'assets/css/slips.css', 'assets/css/tips-calendar.css', 'assets/css/messages.css', 'database-update-20.sql', 'discuss.php', 'clubs.php', 'club.php', 'arena.php', 'slips.php', 'slip.php', 'slip-pdf.php', 'admin/quiz.php', 'includes/jobs.php', 'includes/tipster-fee.php', 'work-with-us.php', 'admin/jobs.php', 'uploads/avatars/.htaccess', 'database-update-14.sql',
          'includes/blog.php', 'assets/css/blog.css', 'assets/js/blog-editor.js', 'admin/blog-upload.php', 'admin/blog-preview.php', 'uploads/blog/.htaccess',
          'includes/managed.php', 'account/managed.php', 'tipster/managed.php', 'database-update-15.sql',
          'includes/payouts.php', 'cron/check-payouts.php', 'database-update-16.sql'];
$lost3 = [];
foreach ($must3 as $f) { if (!is_file(__DIR__ . '/' . $f)) { $lost3[] = $f; } }
row(!$lost3, 'All version 6 files are present (Stripe, MTN MoMo, Airtel Money)', $lost3 ? 'MISSING: ' . implode(', ', $lost3) . '. Upload the whole zip again and overwrite existing files.' : '');
$cw = __DIR__ . '/includes/config-wallets.php';
if (is_file($cw)) {
    require_once $cw;
    row(true, 'Extra payment keys (optional): Stripe ' . (STRIPE_SECRET_KEY !== '' ? 'SET' : 'empty') . ', MTN MoMo ' . (MTN_MOMO_API_KEY !== '' ? 'SET' : 'empty') . ', Airtel Money ' . (AIRTEL_CLIENT_ID !== '' ? 'SET' : 'empty'));
}
$cdir = __DIR__ . '/cache';
row(is_dir($cdir) && is_writable($cdir), 'cache/ folder exists and is writable', 'Create a folder named "cache" and set its permissions to 755 (or 775).');
$upj = __DIR__ . '/private-uploads/jobs';
row(is_dir($upj) && is_writable($upj), 'private-uploads/jobs folder exists and is writable (job application documents)', 'Create the folder private-uploads/jobs and make it writable (permission 755, or 775 if uploads fail).');
$mediaOk = false; $mediaN = 0;
try { $mediaN = (int)db()->query('SELECT COUNT(*) FROM media_blobs')->fetchColumn(); $mediaOk = true; } catch (Throwable $e) {}
row($mediaOk, 'Pictures table media_blobs exists (' . $mediaN . ' stored pictures; photos and post pictures live here)', 'Import database-update-20.sql in phpMyAdmin.');
$imgCol = false; try { $imgCol = (bool)db()->query("SHOW COLUMNS FROM comments LIKE 'image'")->fetch(); } catch (Throwable $e) {}
row($imgCol, 'comments table can hold pictures (image column)', 'Import database-update-20.sql in phpMyAdmin.');
$upa = __DIR__ . '/uploads/avatars';
row(is_dir($upa) && is_writable($upa), 'uploads/avatars folder exists and is writable (member pictures)', 'Create the folder uploads/avatars and make it writable (permission 755, or 775 if uploads fail).');
$upb = __DIR__ . '/uploads/blog';
row(is_dir($upb) && is_writable($upb), 'uploads/blog folder exists and is writable (blog pictures and videos)', 'Create the folder uploads/blog and make it writable (permission 755, or 775 if uploads fail).');
$up = __DIR__ . '/uploads/tipsters';
row(is_dir($up) && is_writable($up), 'uploads/tipsters folder exists and is writable (tipster pictures)', 'Create the folder uploads/tipsters and make it writable (permission 755, or 775 if uploads fail).');

/* 2. Config file */
$cfg = __DIR__ . '/includes/config.php';
if (!is_file($cfg)) {
    row(false, 'includes/config.php exists', 'Not found. Upload the whole "includes" folder.');
} else {
    row(true, 'includes/config.php exists');
    require_once $cfg;
    row(defined('DB_NAME') && DB_NAME !== 'your_database_name', 'Database name is filled in', 'includes/config.php still has the placeholder your_database_name.');
    row(defined('DB_USER') && DB_USER !== 'your_database_user', 'Database user is filled in', 'includes/config.php still has the placeholder your_database_user.');
    row(defined('DB_PASS') && DB_PASS !== 'your_database_password', 'Database password is filled in', 'includes/config.php still has the placeholder your_database_password.');
    row(defined('SITE_URL') && strpos(SITE_URL, 'yourdomain.com') === false, 'SITE_URL is your real address', 'Set SITE_URL in includes/config.php (no slash at the end).');
}
$cp = __DIR__ . '/includes/config-payments.php';
row(is_file($cp), 'includes/config-payments.php exists', 'Upload it. (The site stops without it.)');

/* 3. Folders */
row(is_dir(__DIR__ . '/backups') && is_writable(__DIR__ . '/backups'), 'backups/ folder exists and is writable', 'Create a folder named "backups" and set its permissions to 755 (or 775).');
row(is_dir(__DIR__ . '/logs') && is_writable(__DIR__ . '/logs'), 'logs/ folder exists and is writable', 'Create a folder named "logs" and set its permissions to 755 (or 775).');

/* 4. Database */
$pdo = null;
if (defined('DB_NAME') && extension_loaded('pdo_mysql')) {
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        row(true, 'Connected to the database');
    } catch (PDOException $e) {
        $m = $e->getMessage();
        $hint = 'Check DB_HOST, DB_NAME, DB_USER and DB_PASS in includes/config.php.';
        if (stripos($m, 'Access denied') !== false) $hint = 'Wrong username or password, OR the user has not been ADDED to this database with all privileges (cPanel: MySQL Databases > "Add User To Database"). On shared hosting the names usually start with your account name, like myaccount_slip.';
        elseif (stripos($m, 'Unknown database') !== false) $hint = 'The database name is wrong or the database was not created. On shared hosting it usually starts with your account name, like myaccount_slip.';
        elseif (stripos($m, 'getaddrinfo') !== false || stripos($m, 'Connection refused') !== false || strpos($m, '2002') !== false) $hint = 'DB_HOST is wrong. Most hosts use "localhost", but some give you a special host name in the database section of the panel.';
        elseif (stripos($m, 'could not find driver') !== false) $hint = 'The pdo_mysql PHP extension is off. Enable it in "Select PHP Version".';
        row(false, 'Connected to the database', $hint . '  [Server said: ' . $m . ']');
    }
}
if ($pdo) {
    try {
        $pdo->exec("SET time_zone = '" . (defined('DB_TZ_OFFSET') ? DB_TZ_OFFSET : '+03:00') . "'");
        row(true, 'Database time zone can be set');
    } catch (PDOException $e) {
        row(false, 'Database time zone can be set', $e->getMessage());
    }
    $have = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need = ['users', 'plans', 'subscriptions', 'payments', 'sports', 'leagues', 'matches', 'tips', 'posts', 'coupons', 'referrals',
             'notifications', 'password_resets', 'login_attempts', 'admin_log', 'currencies', 'accumulators', 'accumulator_legs', 'managed_requests', 'chat_threads', 'chat_messages', 'settings', 'admin_recovery_codes', 'bookmakers', 'bookmaker_clicks', 'tip_odds', 'guarantee_log', 'user_bets', 'tipsters', 'tipster_subscriptions', 'tipster_earnings', 'tipster_payouts', 'tipster_reviews', 'phone_codes', 'match_events', 'game_picks', 'game_points', 'game_prizes', 'user_streaks', 'user_badges', 'comments', 'comment_likes', 'comment_reports', 'comment_bans', 'tipster_follows', 'alert_prefs', 'alert_queue', 'push_subs', 'tipster_trials', 'tipster_claims', 'stat_competitions', 'stat_matches', 'stat_standings', 'odds_events', 'odds_quotes', 'odds_history', 'audit_chain', 'user_limits', 'user_fingerprints', 'lifecycle_log'];
    $missing = array_values(array_diff($need, $have));
    row(!$missing, 'All database tables exist', $missing ? 'Missing: ' . implode(', ', $missing) . '. New install: import database.sql. Older install: import the database-update-1/2/3/4/5/6/7/8/9/10/11/12.sql files you have not run yet.' : '');
    if (in_array('payments', $have, true)) {
        $cols = $pdo->query('SHOW COLUMNS FROM payments')->fetchAll(PDO::FETCH_COLUMN);
        row(in_array('coupon_code', $cols, true), 'payments table has the coupon columns', 'Import database-update-2.sql once.');
    }
    if (in_array('users', $have, true)) {
        $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        row(in_array('mobile', $cols, true), 'users table has the mobile number columns', 'Import database-update-8.sql once.');
        row(in_array('is_tipster_account', $cols, true), 'users table has the tipster-account column', 'Import database-update-10.sql once.');
    }
    if (in_array('matches', $have, true)) {
        $cols = $pdo->query('SHOW COLUMNS FROM matches')->fetchAll(PDO::FETCH_COLUMN);
        row(in_array('live_status', $cols, true), 'matches table has the live score columns', 'Import database-update-11.sql once.');
    }
    if (in_array('tipster_payouts', $have, true)) {
        $cols = $pdo->query('SHOW COLUMNS FROM tipster_payouts')->fetchAll(PDO::FETCH_COLUMN);
        row(in_array('b2c_conversation', $cols, true), 'tipster_payouts table has the M-Pesa B2C columns', 'Import database-update-12.sql once.');
    }
    row(function_exists('openssl_pkey_derive') && function_exists('curl_init'), 'PHP can send browser push alerts (openssl and curl)', 'Optional. Enable the openssl and curl extensions (PHP 7.3+) to use push notifications.');
    if (in_array('tips', $have, true)) {
        $cols = $pdo->query('SHOW COLUMNS FROM tips')->fetchAll(PDO::FETCH_COLUMN);
        row(in_array('author_id', $cols, true), 'tips table supports contributor tipsters', 'Import database-update-9.sql once.');
        row(in_array('tipster_id', $cols, true), 'tips table supports tipsters', 'Import database-update-7.sql once.');
    }
}

$allOk = !in_array(false, array_column($rows, 0), true);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>SlipKing setup check</title>
<style>body{font:16px/1.5 Arial,sans-serif;max-width:860px;margin:30px auto;padding:0 16px;color:#17201c}
table{border-collapse:collapse;width:100%}td{padding:9px 10px;border-bottom:1px solid #ddd;vertical-align:top}
.ok{color:#188a4a;font-weight:700}.bad{color:#c8372d;font-weight:700}.hint{color:#555;font-size:14px}
.box{padding:14px 18px;border-radius:8px;margin:18px 0}.good{background:#dcf3e5}.fail{background:#fbe1de}</style></head><body>
<h1>SlipKing setup check</h1>
<div class="box <?= $allOk ? 'good' : 'fail' ?>"><strong><?= $allOk ? 'Everything checked looks fine.' : 'Something needs fixing. Start with the first red line.' ?></strong></div>
<table>
<?php foreach ($rows as $r): ?>
  <tr><td class="<?= $r[0] ? 'ok' : 'bad' ?>"><?= $r[0] ? 'OK' : 'FIX' ?></td><td><?= h($r[1]) ?><?php if (!$r[0] && $r[2] !== ''): ?><div class="hint"><?= h($r[2]) ?></div><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
<p class="hint">When everything is OK, open your home page. Then <strong>delete check.php</strong> from the server.</p>
</body></html>
