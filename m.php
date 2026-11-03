<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/group-render.php';
require_once __DIR__ . '/includes/chat-box.php';
$english_only = (function_exists('lang_code') && lang_code() !== 'sw');
$slug = (string)($_GET['s'] ?? '');
$cg = preg_match('/^[a-z0-9\-]{1,60}$/', $slug) ? cmty_by_slug($slug) : null;
if (!$cg) { require __DIR__ . '/404.php'; exit; }
$c = $cg;
$u = current_user();
$cid = (int)$c['id'];
cmty2_ready();
presence_touch($u);
$gtab = in_array($_GET['tab'] ?? '', ['chat', 'members', 'rules', 'about'], true) ? $_GET['tab'] : 'posts';
$sort = in_array($_GET['sort'] ?? '', ['hot', 'new', 'top'], true) ? $_GET['sort'] : 'hot';
$sortq = $sort !== 'hot' ? '&sort=' . $sort : '';
$back = 'm.php?s=' . $slug . $sortq;
$isMod = cmty_is_mod($c, $u);
if ($gtab === 'posts') { cmty_match_threads_sync($c); }
$posts = [];
try { $posts = cmty_posts($cid, $sort, $u); } catch (Throwable $e) { error_log('m.php: ' . $e->getMessage()); }
$block = comment_block_reason($u);

$hubRows = cmty_hub($u ? (int)$u['id'] : 0); $me = null;
foreach ($hubRows as $row) { if ((int)$row['id'] === $cid) { $me = $row; } }
$me = $me ?: array_merge($c, ['joined' => 0, 'joined_n' => 0, 'posts' => count($posts)]);
$myGroups = array_values(array_filter($hubRows, function ($g) { return !empty($g['joined']); }));
$joinedN = (int)$me['joined_n'];
$isMember = !empty($me['joined']);
$tags = cmty_tags($c);
$rules = array_values(array_filter(array_map('trim', preg_split('/\r?\n|\\\\n/', (string)$c['rules']))));
$media = []; $mem = []; $nReplies = 0; $kids = []; $allRep = [];
try {
    $mq = db()->prepare("SELECT id, image FROM comments WHERE target_type = 'community' AND target_id = ? AND parent_id IS NULL AND status = 'visible' AND image IS NOT NULL AND image <> '' ORDER BY id DESC LIMIT 6");
    $mq->execute([$cid]); $media = $mq->fetchAll();
    if (cmty_members_ready()) {
        $uq = db()->prepare('SELECT u.id, u.name' . profile_sql('u') . ' FROM community_members m JOIN users u ON u.id = m.user_id WHERE m.community_id = ? ORDER BY m.joined_at DESC LIMIT ' . ($gtab === 'members' ? 200 : 10));
        $uq->execute([$cid]); $mem = $uq->fetchAll();
    }
    if ($posts) {
        $rq = db()->prepare("SELECT c.id, c.parent_id, c.user_id, c.body, c.image, c.created_at, c.edited_at, u.name, u.role" . profile_sql('u') . "
            FROM comments c JOIN users u ON u.id = c.user_id WHERE c.target_type = 'community' AND c.target_id = ? AND c.parent_id IS NOT NULL AND c.status = 'visible' ORDER BY c.id ASC LIMIT 2000");
        $rq->execute([$cid]); $allRep = $rq->fetchAll();
        foreach ($allRep as $rp) { $kids[(int)$rp['parent_id']][] = $rp; }
    }
    $nReplies = count($allRep);
} catch (Throwable $e) { error_log('m.php side: ' . $e->getMessage()); }

$ids = array_merge(array_map(function ($p) { return (int)$p['id']; }, $posts), array_map(function ($r) { return (int)$r['id']; }, $allRep));
$likeN = []; $likers = []; $iLike = [];
if ($ids && sk_ensure_table('comment_votes', 'database-update-19.sql')) {
    try {
        foreach (db()->query("SELECT comment_id, user_id FROM comment_votes WHERE value = 1 AND comment_id IN (" . implode(',', $ids) . ") LIMIT 20000")->fetchAll() as $lr) {
            $k = (int)$lr['comment_id']; $likeN[$k] = ($likeN[$k] ?? 0) + 1;
            if ($u && (int)$lr['user_id'] === (int)$u['id']) { $iLike[$k] = true; }
        }
    } catch (Throwable $e) { error_log('m.php likes: ' . $e->getMessage()); }
}
$postIds = array_map(function ($p) { return (int)$p['id']; }, $posts);
$react = cmty_reactions($postIds, $u ? (int)$u['id'] : 0);
$polls = cmty_polls($postIds, $u ? (int)$u['id'] : 0);
$cards = cmty_attach_cards($posts);
$mySlips = [];
if ($u && $gtab === 'posts' && function_exists('slips_ready') && slips_ready()) {
    try { $sq = db()->prepare('SELECT id, title FROM slips WHERE user_id = ? ORDER BY updated_at DESC LIMIT 20'); $sq->execute([(int)$u['id']]); $mySlips = $sq->fetchAll(); } catch (Throwable $e) { }
}
$onlineN = presence_online_in_group($cid);
$isPriv = cmty_is_private($c);
$locked = $isPriv && !$isMember && !$isMod;
if ($locked) { $posts = []; $media = []; $mem = []; $allRep = []; $kids = []; $cards = []; $react = []; $polls = []; $gtab = 'posts'; }
$allUids = array_merge(array_map(function ($p) { return (int)$p['user_id']; }, $posts), array_map(function ($r) { return (int)$r['user_id']; }, $allRep));
$flags = member_flags($allUids); $lvls = member_levels($allUids); $topC = cmty_top_contributors($cid); $following = [];
if ($u && $flags) {
    try { $fq = db()->prepare('SELECT member_id FROM member_follows WHERE follower_id = ?'); $fq->execute([(int)$u['id']]); foreach ($fq->fetchAll(PDO::FETCH_COLUMN) as $fm) { $following[(int)$fm] = true; } } catch (Throwable $e) { }
}
$events = $locked ? [] : cmty_events($cid, $u ? (int)$u['id'] : 0);
$pending = ($isMod && $isPriv) ? cmty_pending_requests($cid) : [];
$myTips = ($u && $gtab === 'posts' && !$locked) ? cmty_my_tips((int)$u['id']) : [];
$board = $locked ? [] : cmty_challenge_board($cid, 5);
$openId = (int)($_GET['open'] ?? 0); $posId = (int)($_GET['pos'] ?? 0); $rposId = (int)($_GET['rpos'] ?? 0);
$canEdit = $u && ((int)$c['created_by'] === (int)$u['id'] || (function_exists('is_staff') && is_staff() && can('content')));
$groupUrl = url('m.php?s=' . $slug);
$tabUrl = function ($t) use ($slug) { return url('m.php?s=' . $slug . ($t === 'posts' ? '' : '&tab=' . $t)); };
$cover = trim((string)($c['cover'] ?? ''));
$act = e(url('community/action.php'));
$X = ['u' => $u, 'block' => $block, 'act' => $act, 'slug' => $slug, 'cid' => $cid, 'isMod' => $isMod, 'kids' => $kids, 'likeN' => $likeN, 'iLike' => $iLike, 'react' => $react, 'sortq' => $sortq, 'flags' => $flags, 'lv' => $lvls, 'topc' => $topC, 'following' => $following];

$page_title = $c['name'] . ' - community - ' . SITE_NAME;
$page_desc = $c['description'] !== '' ? $c['description'] : ('Join the ' . $c['name'] . ' community on ' . SITE_NAME . '.');
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n"
    . '<link rel="stylesheet" href="' . e(url('assets/css/discuss-hub.css')) . '?v=5">' . "\n"
    . '<link rel="stylesheet" href="' . e(url('assets/css/group-page.css')) . '?v=9">' . "\n"
    . ($gtab === 'chat' ? '<link rel="stylesheet" href="' . e(url('assets/css/global-chat.css')) . '?v=8">' . "\n" : '');
$active = 'discuss'; $dhActive = 'groups';
include __DIR__ . '/includes/header.php';
?>
<div class="dh-page">
<div class="dh">
 <div class="dh-layout">
<?php include __DIR__ . '/includes/dh-side.php'; ?>
  <main class="dh-main gp">
    <div class="gp-banner" style="--cc:<?= e($c['color']) ?>">
      <?php if ($cover !== ''): ?><img class="gp-bg" src="<?= e(media_src($cover)) ?>" alt="" onerror="this.remove()"><?php endif; ?>
      <div class="gp-shade"></div>
      <div class="gp-bn">
        <span class="gp-crest"><?= cmty_icon_html($c, 124) ?>
          <?php if ($canEdit): ?><form class="gp-photo" method="post" action="<?= $act ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="group_photo"><input type="hidden" name="cid" value="<?= $cid ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
            <label class="gp-cam" title="Change group picture" aria-label="Change group picture"><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()">&#128247;</label></form><?php endif; ?></span>
        <div class="gp-bt">
          <h1><?= e($c['name']) ?> <?php if ($isPriv): ?><i class="gp-lock" title="Private group: members are approved by a moderator">&#128274;</i><?php else: ?><i class="gp-ok" title="Official group">&#10003;</i><?php endif; ?></h1>
          <div class="gp-bm"><?= $joinedN ?> member<?= $joinedN === 1 ? '' : 's' ?> &bull; <?= $onlineN ?> online &bull; <?= (int)$me['posts'] ?> post<?= (int)$me['posts'] === 1 ? '' : 's' ?></div>
          <?php if ($c['description'] !== ''): ?><p><?= e($c['description']) ?></p><?php endif; ?>
          <?php if ($tags): ?><div class="gp-tags"><?php foreach ($tags as $t): ?><span>#<?= e(str_replace(' ', '', $t)) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
        <div class="gp-bj"><?= cmty_join_form($me, $u, $back, 'big') ?>
          <details class="dh-more up"><summary aria-label="More">&middot;&middot;&middot;</summary><div><button type="button" data-share data-url="<?= e($groupUrl) ?>" data-title="<?= e($c['name']) ?> on <?= e(SITE_NAME) ?>">Share group</button><button type="button" data-copy="<?= e($groupUrl) ?>">Copy group link</button><a href="<?= e(url('discuss.php?tab=groups')) ?>">All groups</a></div></details></div>
      </div>
    </div>
    <div class="gp-tabs" role="navigation" aria-label="Group sections">
      <?php foreach (['posts' => 'Posts', 'chat' => 'Chat', 'members' => 'Members', 'rules' => 'Rules', 'about' => 'About'] as $k => $l): ?><a href="<?= e($tabUrl($k)) ?>" class="<?= $gtab === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?>
    </div>

    <div class="gp-cols<?= $gtab === 'chat' ? ' chatmode' : '' ?>">
      <section class="gp-left">
        <div class="gp-card gp-info">
          <h3>Group Info</h3>
          <div class="gp-ih"><span class="gp-ic"><?= cmty_icon_html($c, 64) ?></span><strong><?= e($c['name']) ?></strong></div>
          <div class="gp-stats"><div><b><?= $joinedN ?></b><span>Members</span></div><div><b><?= $onlineN ?></b><span>Online</span></div><div><b><?= (int)$me['posts'] ?></b><span>Posts</span></div></div>
          <button type="button" class="gp-btn" data-share data-url="<?= e($groupUrl) ?>" data-title="<?= e($c['name']) ?> on <?= e(SITE_NAME) ?>">Invite Friends</button>
          <h4>Group Description</h4><p><?= e($c['description'] !== '' ? $c['description'] : 'A community on ' . SITE_NAME . '.') ?></p>
          <?php if ($tags): ?><h4>Group Tags</h4><div class="gp-chips"><?php foreach ($tags as $t): ?><span>#<?= e(str_replace(' ', '', $t)) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div>
      </section>

      <section class="gp-mid">
<?php if ($locked): ?>
        <div class="gp-card gp-empty"><strong>&#128274; Private group</strong><br>Posts, members and chat of <?= e($c['name']) ?> are only visible to approved members.<div style="margin-top:12px"><?= cmty_join_form($me, $u, 'm.php?s=' . $slug, 'big') ?></div></div>
<?php elseif ($gtab === 'chat'): ?>
  <?php if (!$isMember && !$isMod): ?>
        <div class="gp-card gp-empty"><strong>Members only</strong><br>Join <?= e($c['name']) ?> to chat with the group.<div style="margin-top:12px"><?= cmty_join_form($me, $u, 'm.php?s=' . $slug . '&tab=chat', 'big') ?></div></div>
  <?php else: ?>
        <div class="gc-in-group"><?= chat_box_html($cid, $u, $c['name'] . ' chat', $joinedN . ' member' . ($joinedN === 1 ? '' : 's')) ?></div>
  <?php endif; ?>
<?php elseif ($gtab === 'posts'): ?>
        <?php if ($pending): ?><div class="gp-card gp-req"><h3>Join requests (<?= count($pending) ?>)</h3>
          <?php foreach ($pending as $rq): ?><div class="gp-rqi"><?= user_avatar_html($rq, 36) ?><span><?= e(mask_name((string)$rq['name'])) ?></span>
            <form method="post" action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_join"><input type="hidden" name="cid" value="<?= $cid ?>"><input type="hidden" name="uid" value="<?= (int)$rq['user_id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="gp-post sm" type="submit">Approve</button></form>
            <form method="post" action="<?= $act ?>"><?= csrf_field() ?><input type="hidden" name="action" value="decline_join"><input type="hidden" name="cid" value="<?= $cid ?>"><input type="hidden" name="uid" value="<?= (int)$rq['user_id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><button class="gp-alt sm" type="submit">Decline</button></form></div><?php endforeach; ?></div><?php endif; ?>
        <div class="gp-card gp-compose">
        <?php if ($block === ''): ?>
          <form method="post" action="<?= $act ?>" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="comment"><input type="hidden" name="type" value="community"><input type="hidden" name="target" value="<?= $cid ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="gp-cr"><?= user_avatar_html($u, 42) ?><input class="gp-title" name="title" maxlength="160" required placeholder="Share something with this group..." aria-label="Post title"></div>
            <textarea id="rm-new" name="body" rows="3" maxlength="<?= COMMENT_MAX_LEN ?>" placeholder="Add more details (optional). Tag a member with @username."></textarea>
            <?= composer_tools_html() ?>
            <div class="gp-extras">
              <button type="button" class="gp-xb" data-toggle="pollbox">&#128202; Poll</button>
              <?php if ($mySlips): ?><select name="attach_slip" class="gp-sel" aria-label="Attach one of your bet slips"><option value="">&#129534; Attach a slip</option><?php foreach ($mySlips as $sl): ?><option value="<?= (int)$sl['id'] ?>"><?= e($sl['title']) ?></option><?php endforeach; ?></select><?php endif; ?>
              <?php if ($myTips): ?><select name="attach_tip" class="gp-sel" aria-label="Attach one of your tips"><option value="">&#9989; Attach my tip</option><?php foreach ($myTips as $mt): ?><option value="<?= (int)$mt['id'] ?>"><?= e($mt['label']) ?></option><?php endforeach; ?></select><?php endif; ?>
            </div>
            <div class="gp-pollbox" id="pollbox" hidden><small>Add 2 to 4 options. Members can vote for 7 days.</small>
              <?php for ($i = 0; $i < 4; $i++): ?><input name="poll[]" maxlength="60" placeholder="Option <?= $i + 1 ?><?= $i < 2 ? '' : ' (optional)' ?>"><?php endfor; ?></div>
            <div class="gp-cb"><span class="rm-cc"><b id="rm-cnt">0</b>/<?= COMMENT_MAX_LEN ?></span><button class="gp-post" type="submit">Post</button></div>
          </form>
        <?php elseif (!$u): ?>
          <p class="gp-login"><strong>Join the conversation.</strong> <a class="gp-post" href="<?= e(url('auth/login.php?next=' . rawurlencode($back))) ?>">Log in</a> <a class="gp-alt" href="<?= e(url('auth/register.php')) ?>">Sign up free</a></p>
        <?php else: ?><p class="gp-login"><?= e($block) ?></p><?php endif; ?>
        </div>
        <div class="gp-sort" role="tablist" aria-label="Sort posts">
          <?php foreach (['hot' => 'Hot', 'new' => 'New', 'top' => 'Top'] as $k => $l): ?><a href="<?= e(url('m.php?s=' . $slug . '&sort=' . $k)) ?>" class="<?= $sort === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
        </div>
        <div id="comments">
        <?php if (!$posts): ?><div class="gp-card gp-empty"><strong>No posts yet</strong><br>Be the first to post in <?= e($c['name']) ?>.</div><?php endif; ?>
        <?php foreach ($posts as $p):
          $pid = (int)$p['id']; $staff = $p['role'] !== 'user';
          $who = $staff ? 'Team' : mask_name((string)$p['name']);
          $ln = (int)($likeN[$pid] ?? 0); $nc = count($kids[$pid] ?? []); $postUrl = $groupUrl . '#c' . $pid; ?>
          <article class="gp-card gp-post-card<?= !empty($p['pinned']) ? ' pinned' : '' ?>" id="c<?= $pid ?>">
            <?php if (!empty($p['pinned'])): ?><div class="gp-pin">&#128204; Pinned</div><?php endif; ?>
            <div class="gp-ph"><?= user_avatar_html($p, 42) ?>
              <div class="gp-pn"><strong><?= gp_author($X, $p, $who, $staff) ?></strong><small><?= $staff ? '<span class="gp-role staff">Admin</span>' : (!empty($p['is_tipster']) ? '<span class="gp-role tip">Tipster</span>' : '<span class="gp-role">Group Member</span>') ?> &middot; <?= e(room_ago($p['created_at'])) ?><?= !empty($p['edited_at']) ? ' &middot; edited' : '' ?></small></div>
              <?= gp_follow_btn($X, (int)$p['user_id']) ?><?= gp_menu($X, $p, $pid, true) ?></div>
            <h3><?= e($p['title']) ?></h3>
            <?php if ($p['body'] !== ''): ?><p class="gp-body"><?= mention_html((string)$p['body']) ?></p><?php endif; ?>
            <?= comment_image_html($p['image'] ?? null) ?>
            <?= $cards[$pid] ?? '' ?>
            <?php if (isset($polls[$pid])): ?><?= cmty_poll_html($pid, $polls[$pid], $u, $act, 'm.php?s=' . $slug . $sortq . '&pos=' . $pid) ?><?php endif; ?>
            <?= gp_edit_form($X, $p, $pid, true) ?>
            <div class="gp-rx"><span class="gp-likes"><i>&#128077;</i> <b><?= $ln ?></b> <span class="gp-lw"><?= $ln === 1 ? 'like' : 'likes' ?></span></span><button type="button" class="gp-cc" data-toggle="cm<?= $pid ?>"><?= $nc ?> comment<?= $nc === 1 ? '' : 's' ?></button></div>
            <?= gp_reactions_row($X, $pid) ?>
            <div class="gp-ac">
              <?= gp_like_btn($X, $pid, $pid, true) ?>
              <button type="button" data-toggle="cm<?= $pid ?>"><i>&#128172;</i> Comment</button>
              <button type="button" data-share data-url="<?= e($postUrl) ?>" data-title="<?= e($p['title']) ?>"><i>&#8599;</i> Share</button>
            </div>
            <div class="gp-cm" id="cm<?= $pid ?>"<?= ($openId === $pid) ? '' : ' hidden' ?>>
              <?= gp_replies($X, $pid, 1, $pid) ?>
              <?= gp_comment_form($X, $pid, $pid, 'Write a comment...') ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
        <p class="gp-note">Posts are moderated. Not betting advice. Be kind, no links, no spam.</p>
<?php elseif ($gtab === 'members'): ?>
        <div class="gp-card"><h3>Members (<?= $joinedN ?>)</h3>
        <?php if (!$mem): ?><p class="gp-muted">No members yet. Be the first to join.</p><?php endif; ?>
        <div class="gp-mlist"><?php foreach ($mem as $m): ?><div class="gp-mi"><?= user_avatar_html($m, 44) ?><span><?= e(mask_name((string)$m['name'])) ?></span></div><?php endforeach; ?></div></div>
<?php elseif ($gtab === 'rules'): ?>
        <div class="gp-card"><h3>Group Rules</h3>
        <?php if ($rules): ?><ol class="gp-rules"><?php foreach ($rules as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ol><?php else: ?><p class="gp-muted">No special rules. Be respectful.</p><?php endif; ?></div>
<?php else: ?>
        <div class="gp-card"><h3>About <?= e($c['name']) ?></h3><p><?= e($c['description'] !== '' ? $c['description'] : 'A community on ' . SITE_NAME . '.') ?></p>
          <ul class="gp-facts"><li><span>Created</span><b><?= e(date('j M Y', strtotime($c['created_at']))) ?></b></li><li><span>Members</span><b><?= $joinedN ?></b></li><li><span>Posts</span><b><?= (int)$me['posts'] ?></b></li></ul></div>
<?php endif; ?>
      </section>

      <aside class="gp-right">
        <div class="gp-card"><div class="gp-rh"><h3>Members (<?= $joinedN ?>)</h3><a href="<?= e($tabUrl('members')) ?>">See all &rsaquo;</a></div>
          <div class="gp-stack"><?php foreach (array_slice($mem, 0, 9) as $m): ?><span><?= user_avatar_html($m, 36) ?></span><?php endforeach; ?><?php if ($joinedN > 9): ?><b>+<?= $joinedN - 9 ?></b><?php endif; ?></div>
          <button type="button" class="gp-btn ghost" data-share data-url="<?= e($groupUrl) ?>" data-title="<?= e($c['name']) ?> on <?= e(SITE_NAME) ?>">Invite Members</button></div>
        <div class="gp-card"><div class="gp-rh"><h3>Weekly challenge</h3><a href="<?= e(url('game.php')) ?>">Play &rsaquo;</a></div>
          <?php if ($board): $rk = 0; ?><ol class="gp-board"><?php foreach ($board as $b): $rk++; ?><li><span><?= $rk ?></span><em><?= e(mask_name((string)$b['name'])) ?></em><b><?= (int)$b['pts'] ?> pts</b></li><?php endforeach; ?></ol>
          <?php else: ?><p class="gp-muted">Play the free prediction game. Points earned by members of this group this week appear here.</p><?php endif; ?></div>
        <?php if ($media): ?><div class="gp-card"><div class="gp-rh"><h3>Recent Media</h3></div>
          <div class="gp-media"><?php foreach ($media as $m): ?><a href="<?= e(url('m.php?s=' . $slug . '&pos=' . (int)$m['id'] . '#c' . (int)$m['id'])) ?>"><img src="<?= e(media_src($m['image'])) ?>" alt="" loading="lazy" onerror="this.parentNode.remove()"></a><?php endforeach; ?></div></div><?php endif; ?>
        <?php if (!$locked): ?><?= cmty_events_html($events, $c, $u, $isMod, $isMember, $act, $back) ?><?php endif; ?>
        <?php if ($rules): ?><div class="gp-card"><div class="gp-rh"><h3>Group Rules</h3><a href="<?= e($tabUrl('rules')) ?>">See all &rsaquo;</a></div>
          <ol class="gp-rules"><?php foreach (array_slice($rules, 0, 5) as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ol></div><?php endif; ?>
        <?php if ($tags): ?><div class="gp-card"><h3>Popular Topics</h3><div class="gp-chips"><?php foreach ($tags as $t): ?><span><?= e($t) ?></span><?php endforeach; ?></div></div><?php endif; ?>
      </aside>
    </div>
  </main>
 </div>
</div>
</div>
<script>(function(){var els=document.querySelectorAll('[data-count]');function tick(){var now=Date.now()/1000;[].forEach.call(els,function(el){var d=+el.getAttribute('data-count')-now,t;if(d<=0)t='started';else if(d<3600)t='in '+Math.ceil(d/60)+' min';else if(d<86400)t='in '+Math.floor(d/3600)+' h '+Math.floor(d%3600/60)+' min';else t='in '+Math.floor(d/86400)+' days';el.textContent=t;});}tick();setInterval(tick,30000);})();</script>
<script src="<?= e(url('assets/js/composer.js')) ?>?v=2"></script>
<script src="<?= e(url('assets/js/share.js')) ?>?v=1"></script>
<?php if ($gtab === 'chat' && ($isMember || $isMod)): ?><script src="<?= e(url('assets/js/globalchat.js')) ?>?v=3"></script><?php endif; ?>
<script>(function(){var n=document.getElementById('rm-new'),c=document.getElementById('rm-cnt');if(n&&c){n.addEventListener('input',function(){c.textContent=n.value.length;});}
document.addEventListener('click',function(ev){var b=ev.target.closest&&ev.target.closest('[data-toggle]');if(!b)return;var el=document.getElementById(b.getAttribute('data-toggle'));if(!el)return;el.hidden=!el.hidden;if(!el.hidden){var t=el.querySelector('textarea,input');if(t)t.focus();}var d=b.closest('details');if(d)d.open=false;});
[].forEach.call(document.querySelectorAll('[data-copy]'),function(b){b.addEventListener('click',function(){var u=new URL(b.getAttribute('data-copy'),location.href).href,o=b.innerHTML;if(navigator.clipboard)navigator.clipboard.writeText(u);b.textContent='Link copied';setTimeout(function(){b.innerHTML=o;},1500);});});
function go(id){var e=document.getElementById(id);if(e)e.scrollIntoView({block:'center'});}
var rp=<?= (int)$rposId ?>,pid=<?= (int)$posId ?>;if(rp)go('r'+rp);else if(pid)go('c'+pid);else if(location.hash&&/^#c\d+$/.test(location.hash))go(location.hash.slice(1));
})();</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
