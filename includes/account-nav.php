<?php
if (function_exists('is_tipster_only') && is_tipster_only()) { $tnav = ''; include __DIR__ . '/tipster-nav.php'; return; }
/** Tabs for the member area. Set $acct = 'dashboard'|'subscription'|... before including. */
$acctItems = [
    'dashboard'     => ['account/dashboard.php', 'Dashboard'],
    'subscription'  => ['account/subscription.php', 'Subscription'],
    'groupplan'     => ['account/group-plan.php', 'Group plan'],
    'tickets'       => ['account/tickets.php', 'Support tickets'],
    'payments'      => ['account/payments.php', 'Payments'],
    'notifications' => ['account/notifications.php', 'Notifications'],
    'tracker'       => ['account/tracker.php', 'Bet tracker'],
    'public'        => ['account/public-profile.php', 'Public profile'],
    'tipsters'      => ['account/tipsters.php', 'My tipsters'],
    'managed'       => ['account/managed.php', 'Account management'],
    'alerts'        => ['account/alerts.php', 'Alerts'],
    'digest'        => ['account/digest.php', 'Daily digest'],
    'messages'      => ['account/messages.php', 'Messages'],
    'oddswatch'     => ['account/odds-watch.php', 'Odds alerts'],
    'badges'        => ['account/badges.php', 'Rewards'],
    'referrals'     => ['account/referrals.php', 'Referrals'],
    'limits'        => ['account/limits.php', 'Limits'],
    'profile'       => ['account/profile.php', 'Profile'],
    'delete'        => ['delete-account.php', 'Delete account'],
];
?>
<div class="tabs acct" aria-label="My account">
  <?php foreach ($acctItems as $k => $it): ?>
    <a href="<?= e(url($it[0])) ?>"<?= ($acct ?? '') === $k ? ' class="on" aria-current="page"' : '' ?>><?= e($it[1]) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(url('auth/logout.php')) ?>">Log out</a>
</div>
