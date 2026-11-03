<?php
/** Tabs for the tipster area. Set $tnav before including. */
$tnavItems = [
    'index'    => ['tipster/index.php', 'Overview'],
    'tips'     => ['tipster/tips.php', 'My tips'],
    'plans'    => ['tipster/plans.php', 'My plans'],
    'earnings' => ['tipster/earnings.php', 'Wallet'],
    'managed'  => ['tipster/managed.php', 'Account management'],
    'profile'  => ['tipster/profile.php', 'Profile'],
];
?>
<div class="tabs acct" aria-label="Tipster area">
  <?php foreach ($tnavItems as $k => $it): ?>
    <a href="<?= e(url($it[0])) ?>"<?= ($tnav ?? '') === $k ? ' class="on" aria-current="page"' : '' ?>><?= e($it[1]) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(url('account/notifications.php')) ?>">Messages</a>
  <a href="<?= e(url('account/profile.php')) ?>">Account settings</a>
  <a href="<?= e(url('auth/logout.php')) ?>">Log out</a>
</div>
