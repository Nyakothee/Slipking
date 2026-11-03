<?php
/** Left menu shared by the Discussion hub, group pages and Global Chat. Needs $dhActive, $u and (optional) $myGroups. */
$dhActive = $dhActive ?? '';
$gcs = gchat_stats(function_exists('current_user') ? current_user() : null);
$myGroups = $myGroups ?? [];
$dhItems = [
    'feed'    => ['discuss.php?tab=feed', '&#128240;', 'Your feed'],
    'following' => ['discuss.php?tab=following', '&#10133;', 'Following'],
    'messages' => ['account/messages.php', '&#9993;', 'Messages'],
    'chat'    => ['globalchat.php', '&#128172;', 'Global Chat'],
    'groups'  => ['discuss.php?tab=groups', '&#128101;', 'Groups'],
    'matches' => ['discuss.php?tab=matches', '&#9917;', 'Match rooms &amp; polls'],
    'members' => ['members.php', '&#127942;', 'Top members'],
    'slips'   => ['slips.php', '&#129534;', 'Slips'],
    'tracker' => ['account/tracker.php', '&#128200;', 'My bet tracker'],
    'search'  => ['community-search.php', '&#128269;', 'Search &amp; #tags'],
];
$dmN = (!empty($u) && function_exists('dm_unread')) ? dm_unread((int)$u['id']) : 0;
?>
  <aside class="dh-side">
    <h2>Community</h2>
    <div class="dh-nav" role="navigation" aria-label="Community sections">
    <?php foreach ($dhItems as $k => $it): ?>
      <a href="<?= e(url($it[0])) ?>" class="<?= $dhActive === $k ? 'on' : '' ?>"><i><?= $it[1] ?></i><span><?= $it[2] ?></span><?php if ($k === 'messages' && $dmN > 0): ?><b class="dh-badge"><?= $dmN > 99 ? '99+' : $dmN ?></b><?php endif; ?><?php if ($k === 'chat' && $gcs['unread'] > 0 && $dhActive !== 'chat'): ?><b class="dh-badge"><?= $gcs['unread'] > 99 ? '99+' : (int)$gcs['unread'] ?></b><?php endif; ?></a>
    <?php endforeach; ?>
    </div>
    <?php if (function_exists('is_staff') && is_staff()): ?><a class="dh-create" href="<?= e(url('admin/communities.php')) ?>">+ Create group</a><?php endif; ?>
    <div class="dh-joined"><h3>Groups you've joined</h3>
    <?php if ($myGroups): foreach ($myGroups as $g): ?>
      <a href="<?= e(url('m.php?s=' . $g['slug'])) ?>" class="dh-jg"><span class="dh-th"><?= cmty_cover_html($g) ?></span><span><strong><?= e($g['name']) ?></strong><small><?= (int)$g['joined_n'] ?> member<?= (int)$g['joined_n'] === 1 ? '' : 's' ?></small></span></a>
    <?php endforeach; else: ?><p class="dh-empty"><?= !empty($u) ? 'You have not joined any group yet.' : 'Log in to join groups.' ?></p><?php endif; ?></div>
  </aside>
