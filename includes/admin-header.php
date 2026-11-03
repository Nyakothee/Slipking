<?php
/** Admin layout top. Set $admin_title and $admin_active before including. Call require_admin() first. */
$admin_title  = $admin_title ?? 'Admin';
$admin_active = $admin_active ?? '';
function admin_nav(string $key, string $file, string $label, string $active): string
{
    return '<a href="' . e(url('admin/' . $file)) . '"' . ($key === $active ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($admin_title) ?> - Admin - <?= e(SITE_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>?v=2">
<link rel="stylesheet" href="<?= e(url('assets/css/group-d.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/group-e.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/group-f.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/admin-pro.css')) ?>?v=1">
</head>
<body class="admin">
<div class="adm">
  <aside class="side">
    <a class="brand" href="<?= e(url('admin/index.php')) ?>"><?= e(SITE_NAME) ?></a>
    <small>Admin panel<?php $meNav = current_user(); echo $meNav ? ' - ' . e(role_label($meNav['role'])) : ''; ?></small>
<?php
    $adminCounts = admin_counts();
    // [key, file, label, permission, badge count]
    $navGroups = [
        [['dashboard', 'index.php', 'Dashboard' . (function_exists('admin_pending_total') && ($pn = admin_pending_total()) ? ' (' . $pn . ')' : ''), 'dashboard'], ['tips', 'tips.php', 'Tips', 'tips'], ['tip-add', 'tip-add.php', 'Add a tip', 'tips'], ['streak', 'streak.php', '2 Odds Streak', 'tips'], ['replays', 'replays.php', 'Replays', 'content'], ['channels', 'channels.php', 'Channels', 'content'], ['pages', 'pages.php', 'Pages on / off', 'settings'], ['watchrep', 'watch-reports.php', 'Broken links', 'tips'], ['chatpins', 'chat-pins.php', 'Chat pins &amp; rules', 'messages'], ['ops', 'ops.php', 'Maintenance &amp; what\'s new', 'settings'], ['tickets', 'tickets.php', 'Support tickets', 'messages'], ['appeals', 'appeals.php', 'Appeals &amp; shared accounts', 'users_view'],
         ['tip-bulk', 'tip-bulk.php', 'Bulk tips (CSV)', 'tips'], ['contributor-tips', 'contributor-tips.php', 'Contributor tips', 'tips'], ['fixtures', 'fixtures.php', 'Match fixtures', 'fixtures']],
        [['users', 'users.php', 'Members', 'users_view'], ['subscriptions', 'subscriptions.php', 'Subscriptions', 'subscriptions'], ['payments', 'payments.php', 'Payments', 'payments'], ['payflags', 'payment-flags.php', 'Payment flags', 'payments'], ['accessfee', 'access-fee.php', 'Weekly access', 'payments'],
         ['plans', 'plans.php', 'Plans and prices', 'plans'], ['coupons', 'coupons.php', 'Coupon codes', 'coupons'], ['currencies', 'currencies.php', 'Currencies', 'currencies'], ['paymethods', 'payment-methods.php', 'Payment methods', 'settings'],
         ['settings', 'settings.php', 'Offer settings', 'settings'], ['bookmakers', 'bookmakers.php', 'Bookmakers', 'bookmakers'], ['guarantee', 'guarantee.php', 'Guarantee', 'guarantee']],
        [['tipsters', 'tipsters.php', 'Tipsters', 'tipsters', (int)$adminCounts['tipsters']], ['tipster-payouts', 'tipster-payouts.php', 'Tipster payouts', 'payouts', (int)$adminCounts['payouts']], ['reviews', 'reviews.php', 'Tipster ratings', 'reviews'],
         ['tipster-claims', 'tipster-claims.php', 'Money-back claims', 'payouts'], ['comments', 'comments.php', 'Comments', 'reviews']],
        [['game', 'game.php', 'Prediction game', 'tips'], ['quiz', 'quiz.php', 'Arena questions', 'content'], ['communities', 'communities.php', 'Communities (m/)', 'content'], ['jackpot', 'jackpot.php', 'Jackpot', 'tips'], ['chatreports', 'chat-reports.php', 'Chat reports', 'content'], ['communitystats', 'community-stats.php', 'Community stats', 'content'], ['app', 'app.php', 'Android app', 'settings'], ['appstats', 'app-stats.php', 'Traffic and app usage', 'settings'], ['live', 'live.php', 'Live scores', 'fixtures'], ['widgets', 'widgets.php', 'Widgets (embeds)', 'content'], ['chatmsgs', 'global-chat.php', 'Chat messages', 'content'], ['streams', 'streams.php', 'Livestream', 'content'], ['push', 'push.php', 'Push notifications', 'settings'],
         ['datasources', 'data-sources.php', 'Data sources', 'settings'], ['analytics', 'analytics.php', 'Analytics', 'payments'], ['fraud', 'fraud.php', 'Multi-account signals', 'users_view']],
        [['accas', 'accas.php', 'Accumulators', 'accas'], ['chat', 'chat.php', 'Live chat', 'chat', (int)$adminCounts['chat']], ['managed', 'managed.php', 'Account management', 'managed', (int)$adminCounts['managed']]],
        [['leagues', 'leagues.php', 'Sports and leagues', 'content'], ['posts', 'posts.php', 'Blog', 'content'], ['messages', 'messages.php', 'Messages', 'messages'], ['log', 'log.php', 'Activity log', 'log'], ['deletions', 'deletions.php', 'Deleted items', 'log'], ['health', 'health.php', 'System health', 'settings'], ['results', 'results.php', 'Manage results', 'tips']],
        [['social', 'social.php', 'Social and Telegram', 'social'], ['bulkmail', 'bulk-mail.php', 'Mass email', 'mail'], ['mail-test', 'mail-test.php', 'Email test', 'mail'], ['sms-test', 'sms-test.php', 'SMS test', 'mail'], ['security', 'security.php', 'Security (2FA)', 'security'],
         ['backup', 'backup.php', 'Backups', 'backup'], ['staff', 'staff.php', 'Staff and roles', 'staff'], ['jobs', 'jobs.php', 'Job applications', 'jobs', jobs_new_count()]],
    ];
    $first = true;
    foreach ($navGroups as $grp) {
        $items = array_filter($grp, function ($it) { return can($it[3]); });
        if (!$items) { continue; }
        if (!$first) { echo "    <hr>\n"; }
        $first = false;
        foreach ($items as $it) {
            echo '    <a href="' . e(url('admin/' . $it[1])) . '"' . ($it[0] === $admin_active ? ' class="on" aria-current="page"' : '') . '>' . e($it[2])
               . (!empty($it[4]) ? ' <span class="nbadge">' . (int)$it[4] . '</span>' : '') . "</a>\n";
        }
    }
?>
    <hr>
    <a href="<?= e(url()) ?>" target="_blank" rel="noopener">View site</a>
    <a href="<?= e(url('admin/logout.php')) ?>">Log out</a>
  </aside>
  <div class="amain">
<?php foreach (get_flash() as $f): ?>
    <div class="flash <?= e($f['type']) ?>" role="status" style="border-radius:8px;padding:10px 14px;margin-bottom:16px"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
