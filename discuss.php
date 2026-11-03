<?php
require_once __DIR__ . '/includes/init.php';
$english_only = (function_exists('lang_code') && lang_code() !== 'sw');
$pdo = db();
$tabq = (string)($_GET['tab'] ?? 'groups');
$hub = ($tabq === 'matches' || isset($_GET['when'])) ? 'matches' : ($tabq === 'feed' ? 'feed' : ($tabq === 'following' ? 'following' : 'groups'));
$u = current_user(); $uid = $u ? (int)$u['id'] : 0;
$tab = ($_GET['when'] ?? 'upcoming') === 'recent' ? 'recent' : 'upcoming';
$where = $tab === 'recent' ? "m.kickoff_at <= NOW() AND m.kickoff_at >= NOW() - INTERVAL 3 DAY" : "m.kickoff_at > NOW() - INTERVAL 3 HOUR AND m.kickoff_at <= NOW() + INTERVAL 5 DAY";
$order = $tab === 'recent' ? 'm.kickoff_at DESC' : 'm.kickoff_at ASC';
$pv = poll_ready() ? "(SELECT COUNT(*) FROM poll_votes pv WHERE pv.match_id = m.id)" : "0";
try {
    $rows = $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league,
                                (SELECT COUNT(*) FROM comments c WHERE c.target_type = 'match' AND c.target_id = m.id AND c.status = 'visible') AS cc, $pv AS pc
                         FROM matches m JOIN leagues l ON l.id = m.league_id WHERE $where ORDER BY $order LIMIT 80")->fetchAll();
} catch (Throwable $e) { $rows = []; }
$cms = cmty_hub($uid);
$myGroups = array_values(array_filter($cms, function ($c) { return !empty($c['joined']); }));
$top = $cms; usort($top, function ($a, $b) { return [(int)$b['joined_n'], (int)$b['posts']] <=> [(int)$a['joined_n'], (int)$a['posts']]; });
$top = array_slice($top, 0, 3);
$feed = [];
if ($hub === 'feed' && $uid && $myGroups) {
    try {
        $ids = implode(',', array_map(function ($c) { return (int)$c['id']; }, $myGroups));
        $feed = $pdo->query("SELECT c.id, c.title, c.body, c.image, c.created_at, c.target_id, u.name, cm.name AS gname, cm.slug, cm.icon, cm.color,
                (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id AND r.status = 'visible') AS replies
            FROM comments c JOIN users u ON u.id = c.user_id JOIN communities cm ON cm.id = c.target_id
            WHERE c.target_type = 'community' AND c.parent_id IS NULL AND c.status = 'visible' AND c.target_id IN ($ids) ORDER BY c.created_at DESC LIMIT 40")->fetchAll();
    } catch (Throwable $e) { $feed = []; }
}
$fPosts = []; $fBets = []; $nFollow = 0;
if ($hub === 'following' && $uid && function_exists('members_ready') && members_ready()) {
    try {
        $fids = array_map('intval', $pdo->query('SELECT member_id FROM member_follows WHERE follower_id = ' . $uid . ' LIMIT 300')->fetchAll(PDO::FETCH_COLUMN));
        $nFollow = count($fids);
        if ($fids) {
            $in = implode(',', $fids); $hide = cmty_hidden_group_ids($u);
            $fPosts = $pdo->query("SELECT c.id, c.title, c.body, c.image, c.created_at, cm.name AS gname, cm.slug, u.name, mp.handle,
                    (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id AND r.status = 'visible') AS replies
                FROM comments c JOIN users u ON u.id = c.user_id JOIN communities cm ON cm.id = c.target_id LEFT JOIN member_public mp ON mp.user_id = c.user_id
                WHERE c.target_type = 'community' AND c.parent_id IS NULL AND c.status = 'visible' AND c.user_id IN ($in)" . ($hide ? ' AND c.target_id NOT IN (' . implode(',', $hide) . ')' : '') . " ORDER BY c.created_at DESC LIMIT 30")->fetchAll();
            $fBets = $pdo->query("SELECT b.description, b.odds, b.status, b.placed_at, mp.handle FROM user_bets b JOIN member_public mp ON mp.user_id = b.user_id AND mp.is_public = 1
                WHERE b.user_id IN ($in) AND b.is_public = 1 AND b.deleted_at IS NULL ORDER BY b.placed_at DESC, b.id DESC LIMIT 12")->fetchAll();
        }
    } catch (Throwable $e) { error_log('discuss following: ' . $e->getMessage()); }
}
$page_title = 'Discussion - communities, match rooms and polls - ' . SITE_NAME;
$page_desc = 'Join football communities, talk about every match, reply to other fans, share photos and vote in the community prediction poll.';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n";
$head_extra .= '<link rel="stylesheet" href="' . e(url('assets/css/discuss-hub.css')) . '?v=5">' . "\n";
$active = 'discuss';
include __DIR__ . '/includes/header.php';
?>
<div class="dh-page">
<div class="dh">
 <div class="dh-layout">
<?php $dhActive = $hub; include __DIR__ . '/includes/dh-side.php'; ?>
  <main class="dh-main">
<?php $gcs = gchat_stats($u); presence_touch($u); if ($hub !== 'matches' && gchat_ready()): ?>
  <a class="dh-chatbar" href="<?= e(url('globalchat.php')) ?>"><span class="dh-cb-i">&#128172;</span>
    <span class="dh-cb-t"><strong>Global Chat</strong><small><?= (int)$gcs['today'] ?> message<?= (int)$gcs['today'] === 1 ? '' : 's' ?> today &middot; <?= (int)$gcs['active'] ?> chatting now<?= $gcs['last'] ? ' &middot; ' . e($gcs['last']['who']) . ': ' . e(mb_strimwidth($gcs['last']['body'], 0, 60, '...')) : '' ?></small></span>
    <?php if ($gcs['unread'] > 0): ?><b class="dh-badge big"><?= $gcs['unread'] > 99 ? '99+' : (int)$gcs['unread'] ?> new</b><?php endif; ?><span class="dh-cb-go">Open chat &rarr;</span></a>
<?php endif; ?>
<?php if ($hub === 'groups'): ?>
  <?php if (!$cms): ?><div class="note">No communities yet. Check back soon.</div><?php else: ?>
  <?php if (count($cms) >= 3): ?>
  <section class="dh-top"><h2><span>&#9733;</span> Top groups</h2>
    <div class="dh-podium">
    <?php foreach ($top as $n => $c): ?>
      <a class="dh-pc r<?= $n + 1 ?>" href="<?= e(url('m.php?s=' . $c['slug'])) ?>">
        <span class="dh-cov"><?= cmty_cover_html($c) ?><b class="dh-rank"><?= $n + 1 ?></b><span class="dh-ic"><?= cmty_icon_html($c, 40) ?></span></span>
        <span class="dh-pb"><strong><?= e($c['name']) ?></strong><em class="dh-sub"><i>&#9917;</i> m/<?= e($c['slug']) ?></em><small><?= (int)$c['posts'] ?> post<?= (int)$c['posts'] === 1 ? '' : 's' ?></small>
          <span class="dh-pm"><span><?= (int)$c['joined_n'] ?> member<?= (int)$c['joined_n'] === 1 ? '' : 's' ?></span><em>#<?= $n + 1 ?></em></span></span></a>
    <?php endforeach; ?></div></section>
  <?php endif; ?>
  <section class="dh-all">
    <div class="dh-bar"><h2>All groups (<span id="dh-n"><?= count($cms) ?></span>)</h2>
      <div class="dh-chips" role="tablist" aria-label="Filter groups"><button type="button" class="on" data-f="all">All</button><button type="button" data-f="joined">Joined</button><button type="button" data-f="active">Most active</button><button type="button" data-f="new">New</button></div></div>
    <label class="dh-search"><span>&#128269;</span><input type="search" id="dh-q" placeholder="Search groups..." aria-label="Search groups"></label>
    <div class="dh-grid" id="dh-grid">
    <?php foreach ($cms as $c): $newTs = strtotime((string)$c['created_at']); ?>
      <article class="dh-card" data-name="<?= e(mb_strtolower($c['name'] . ' ' . $c['description'])) ?>" data-joined="<?= !empty($c['joined']) ? 1 : 0 ?>" data-posts="<?= (int)$c['posts'] ?>" data-ts="<?= (int)$newTs ?>">
        <a class="dh-ct" href="<?= e(url('m.php?s=' . $c['slug'])) ?>"><span class="dh-th lg"><?= cmty_cover_html($c) ?><span class="dh-ic sm"><?= cmty_icon_html($c, 22) ?></span></span>
          <span class="dh-tx"><strong><?= !empty($c['is_private']) ? '&#128274; ' : '' ?><?= e($c['name']) ?></strong><em class="dh-sub"><i>&#9917;</i> m/<?= e($c['slug']) ?></em><small><?= (int)$c['joined_n'] ?> member<?= (int)$c['joined_n'] === 1 ? '' : 's' ?> &middot; <?= (int)$c['posts'] ?> post<?= (int)$c['posts'] === 1 ? '' : 's' ?></small>
          <span class="dh-ds"><?= e($c['description'] !== '' ? $c['description'] : 'Join the conversation.') ?></span></span></a>
        <div class="dh-act"><a class="dg-btn view" href="<?= e(url('m.php?s=' . $c['slug'])) ?>">View Group</a><?= cmty_join_form($c, $u, 'discuss.php?tab=groups') ?><details class="dh-more"><summary aria-label="More">&middot;&middot;&middot;</summary><div><a href="<?= e(url('m.php?s=' . $c['slug'])) ?>">Open group</a><button type="button" data-copy="<?= e(url('m.php?s=' . $c['slug'])) ?>">Copy link</button></div></details></div>
      </article>
    <?php endforeach; ?></div>
    <p class="dh-none" id="dh-none" hidden>No groups match your search.</p>
  </section>
  <?php endif; ?>
<?php elseif ($hub === 'feed'): ?>
  <section class="dh-all"><div class="dh-bar"><h2>Your feed</h2></div>
  <?php if (!$u): ?><div class="note"><a href="<?= e(url('auth/login.php?next=' . rawurlencode('discuss.php?tab=feed'))) ?>">Log in</a> to see posts from the groups you joined.</div>
  <?php elseif (!$myGroups): ?><div class="note">Join a group to fill your feed. <a href="<?= e(url('discuss.php?tab=groups')) ?>">Browse groups</a></div>
  <?php elseif (!$feed): ?><div class="note">No posts yet in your groups.</div>
  <?php else: foreach ($feed as $p): ?>
    <a class="dh-post" href="<?= e(url('post.php?id=' . (int)$p['id'])) ?>">
      <span class="dh-pg">m/<?= e($p['gname']) ?> &middot; <?= e($p['name']) ?> &middot; <?= e(date('j M, H:i', strtotime($p['created_at']))) ?></span>
      <strong><?= e($p['title'] ?? '') ?></strong>
      <?php if (trim((string)$p['body']) !== ''): ?><span class="dh-pt"><?= e(mb_strimwidth((string)$p['body'], 0, 180, '...')) ?></span><?php endif; ?>
      <?php if (!empty($p['image'])): ?><img class="dh-pi" src="<?= e(media_src($p['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
      <small><?= (int)$p['replies'] ?> repl<?= (int)$p['replies'] === 1 ? 'y' : 'ies' ?></small></a>
  <?php endforeach; endif; ?></section>
<?php elseif ($hub === 'following'): ?>
  <section class="dh-all"><div class="dh-bar"><h2>Following</h2></div>
  <?php if (!$u): ?><div class="note"><a href="<?= e(url('auth/login.php?next=' . rawurlencode('discuss.php?tab=following'))) ?>">Log in</a> to follow members and see their posts and bets here.</div>
  <?php elseif (!$nFollow): ?><div class="note">You do not follow anyone yet. Tap <b>Follow</b> next to a member's name in a group, or open the <a href="<?= e(url('members.php')) ?>">Top members</a> list.</div>
  <?php else: ?>
    <?php if ($fBets): ?><h3 class="dh-sub2">Bets they shared</h3><?php foreach ($fBets as $b): ?>
      <a class="dh-post" href="<?= e(url('member.php?u=' . $b['handle'])) ?>"><span class="dh-pg">@<?= e($b['handle']) ?> &middot; <?= e(date('j M, H:i', strtotime($b['placed_at']))) ?></span><strong><?= e(mb_strimwidth((string)$b['description'], 0, 120, '...')) ?></strong><small>Odds <?= e(number_format((float)$b['odds'], 2)) ?> &middot; <?= e(ucfirst((string)$b['status'])) ?></small></a>
    <?php endforeach; endif; ?>
    <h3 class="dh-sub2">Group posts</h3>
    <?php if (!$fPosts): ?><div class="note">No group posts from the people you follow yet.</div><?php else: foreach ($fPosts as $p): ?>
      <a class="dh-post" href="<?= e(url('m.php?s=' . $p['slug'] . '&pos=' . (int)$p['id'] . '#c' . (int)$p['id'])) ?>">
        <span class="dh-pg">m/<?= e($p['gname']) ?> &middot; <?= e($p['handle'] ? '@' . $p['handle'] : $p['name']) ?> &middot; <?= e(date('j M, H:i', strtotime($p['created_at']))) ?></span>
        <strong><?= e($p['title'] ?? '') ?></strong>
        <?php if (trim((string)$p['body']) !== ''): ?><span class="dh-pt"><?= e(mb_strimwidth((string)$p['body'], 0, 180, '...')) ?></span><?php endif; ?>
        <?php if (!empty($p['image'])): ?><img class="dh-pi" src="<?= e(media_src($p['image'])) ?>" alt="" loading="lazy"><?php endif; ?>
        <small><?= (int)$p['replies'] ?> repl<?= (int)$p['replies'] === 1 ? 'y' : 'ies' ?></small></a>
    <?php endforeach; endif; ?>
  <?php endif; ?></section>
<?php else: ?>
  <section class="dh-all"><div class="dh-bar"><h2>Match rooms &amp; polls</h2>
  <div class="dh-chips"><a class="<?= $tab === 'upcoming' ? 'on' : '' ?>" href="<?= e(url('discuss.php?tab=matches&when=upcoming')) ?>">Upcoming and live</a><a class="<?= $tab === 'recent' ? 'on' : '' ?>" href="<?= e(url('discuss.php?tab=matches&when=recent')) ?>">Finished (3 days)</a></div></div>
  <?php if ($rows): ?><div class="dh-grid">
    <?php foreach ($rows as $r): ?>
    <a class="dh-card dh-match" href="<?= e(url('room.php?id=' . (int)$r['id'])) ?>">
      <strong><?= e($r['home_team'] . ' vs ' . $r['away_team']) ?></strong>
      <small><?= e($r['league']) ?> &middot; <?= e(date('D j M, H:i', strtotime($r['kickoff_at']))) ?></small>
      <small><?= (int)$r['cc'] ?> comment<?= (int)$r['cc'] === 1 ? '' : 's' ?> &middot; <?= (int)$r['pc'] ?> poll vote<?= (int)$r['pc'] === 1 ? '' : 's' ?></small>
    </a><?php endforeach; ?></div>
  <?php else: ?><div class="note">No matches in this window yet.</div><?php endif; ?></section>
<?php endif; ?>
  </main>
 </div>
</div>
</div>
<script>
(function(){
  var g=document.getElementById('dh-grid'); if(!g) return;
  var cards=[].slice.call(g.querySelectorAll('.dh-card')),q=document.getElementById('dh-q'),f='all',n=document.getElementById('dh-n'),none=document.getElementById('dh-none');
  var newest=cards.map(function(c){return +c.dataset.ts}).sort(function(a,b){return b-a}).slice(0,Math.max(3,Math.ceil(cards.length/3)));
  function run(){var t=(q.value||'').toLowerCase().trim(),shown=0;
    cards.forEach(function(c){var ok=(!t||c.dataset.name.indexOf(t)>-1);
      if(f==='joined')ok=ok&&c.dataset.joined==='1'; if(f==='active')ok=ok&&+c.dataset.posts>0; if(f==='new')ok=ok&&newest.indexOf(+c.dataset.ts)>-1;
      c.hidden=!ok; if(ok)shown++;});
    if(f==='active'){cards.sort(function(a,b){return b.dataset.posts-a.dataset.posts}).forEach(function(c){g.appendChild(c)});}
    n.textContent=shown; none.hidden=shown>0;}
  [].forEach.call(document.querySelectorAll('[data-copy]'),function(b){b.addEventListener('click',function(){var u=new URL(b.dataset.copy,location.href).href;if(navigator.clipboard)navigator.clipboard.writeText(u);b.textContent='Copied';});});
  q.addEventListener('input',run);
  [].forEach.call(document.querySelectorAll('.dh-chips button'),function(b){b.addEventListener('click',function(){f=b.dataset.f;[].forEach.call(document.querySelectorAll('.dh-chips button'),function(x){x.classList.toggle('on',x===b)});run();});});
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
