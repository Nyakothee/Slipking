<?php
require_once __DIR__ . '/includes/init.php';
$english_only = (function_exists('lang_code') && lang_code() !== 'sw');
$u = current_user();
presence_touch($u);
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 60) { $q = mb_substr($q, 0, 60); }
$hide = cmty_hidden_group_ids($u);
$posts = []; $groups = []; $members = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    try {
        $st = db()->prepare("SELECT c.id, c.title, c.body, c.created_at, cm.name AS gname, cm.slug, u.name, mp.handle
            FROM comments c JOIN communities cm ON cm.id = c.target_id AND cm.is_active = 1 JOIN users u ON u.id = c.user_id LEFT JOIN member_public mp ON mp.user_id = c.user_id AND mp.is_public = 1
            WHERE c.target_type = 'community' AND c.parent_id IS NULL AND c.status = 'visible' AND (c.title LIKE ? OR c.body LIKE ?)" . ($hide ? ' AND c.target_id NOT IN (' . implode(',', $hide) . ')' : '') . " ORDER BY c.created_at DESC LIMIT 40");
        $st->execute([$like, $like]); $posts = $st->fetchAll();
    } catch (Throwable $e) { error_log('search posts: ' . $e->getMessage()); }
    if ($q[0] !== '#') {
        try {
            $g = db()->prepare("SELECT slug, name, description, is_private FROM communities WHERE is_active = 1 AND (name LIKE ? OR description LIKE ? OR tags LIKE ?)" . ($hide ? ' AND id NOT IN (' . implode(',', $hide) . ')' : '') . ' LIMIT 10');
            $g->execute([$like, $like, $like]); $groups = $g->fetchAll();
        } catch (Throwable $e) { }
        if (function_exists('members_ready') && members_ready()) {
            try { $mm = db()->prepare('SELECT handle, bio FROM member_public WHERE is_public = 1 AND handle LIKE ? ORDER BY handle LIMIT 10'); $mm->execute([ltrim($like, '%') === $like ? $like : '%' . ltrim(addcslashes($q, '%_\\'), '@') . '%']); $members = $mm->fetchAll(); } catch (Throwable $e) { }
        }
    }
}
$trend = $q === '' ? trending_tags(12) : [];
$page_title = 'Search the community - ' . SITE_NAME;
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n"
    . '<link rel="stylesheet" href="' . e(url('assets/css/discuss-hub.css')) . '?v=5">' . "\n"
    . '<link rel="stylesheet" href="' . e(url('assets/css/group-page.css')) . '?v=9">' . "\n";
$active = 'discuss'; $dhActive = 'search';
include __DIR__ . '/includes/header.php';
?>
<div class="dh-page">
<div class="dh">
 <div class="dh-layout">
<?php include __DIR__ . '/includes/dh-side.php'; ?>
  <main class="dh-main">
    <form class="dh-search big" method="get" action="<?= e(url('community-search.php')) ?>"><span>&#128269;</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search posts, groups, members or #tags" aria-label="Search the community" maxlength="60"></form>
<?php if ($q === ''): ?>
    <section class="dh-all"><div class="dh-bar"><h2>Trending this week</h2></div>
      <?php if ($trend): ?><div class="gp-chips big"><?php foreach ($trend as $tg => $n): ?><a class="gp-tag" href="<?= e(url('community-search.php?q=' . rawurlencode('#' . $tg))) ?>">#<?= e($tg) ?> <small><?= (int)$n ?></small></a><?php endforeach; ?></div>
      <?php else: ?><div class="note">No #tags yet. Add a tag to a post, like <b>#derby</b> or <b>#BTTS</b>, and it shows up here.</div><?php endif; ?></section>
<?php else: ?>
    <?php if (mb_strlen($q) < 2): ?><div class="note">Type at least 2 characters.</div><?php else: ?>
    <?php if ($groups): ?><section class="dh-all"><div class="dh-bar"><h2>Groups</h2></div><?php foreach ($groups as $g): ?>
      <a class="dh-post" href="<?= e(url('m.php?s=' . $g['slug'])) ?>"><strong><?= !empty($g['is_private']) ? '&#128274; ' : '' ?><?= e($g['name']) ?></strong><span class="dh-pt"><?= e(mb_strimwidth((string)$g['description'], 0, 140, '...')) ?></span></a><?php endforeach; ?></section><?php endif; ?>
    <?php if ($members): ?><section class="dh-all"><div class="dh-bar"><h2>Members</h2></div><?php foreach ($members as $m): ?>
      <a class="dh-post" href="<?= e(url('member.php?u=' . $m['handle'])) ?>"><strong>@<?= e($m['handle']) ?></strong><span class="dh-pt"><?= e((string)$m['bio']) ?></span></a><?php endforeach; ?></section><?php endif; ?>
    <section class="dh-all"><div class="dh-bar"><h2>Posts (<?= count($posts) ?>)</h2></div>
      <?php if (!$posts): ?><div class="note">No posts found for <b><?= e($q) ?></b>.</div><?php endif; ?>
      <?php foreach ($posts as $p): ?>
      <a class="dh-post" href="<?= e(url('m.php?s=' . $p['slug'] . '&pos=' . (int)$p['id'] . '#c' . (int)$p['id'])) ?>"><span class="dh-pg">m/<?= e($p['gname']) ?> &middot; <?= e($p['handle'] ? '@' . $p['handle'] : mask_name((string)$p['name'])) ?> &middot; <?= e(date('j M, H:i', strtotime($p['created_at']))) ?></span>
        <strong><?= e((string)$p['title']) ?></strong><?php if (trim((string)$p['body']) !== ''): ?><span class="dh-pt"><?= e(mb_strimwidth((string)$p['body'], 0, 160, '...')) ?></span><?php endif; ?></a>
      <?php endforeach; ?></section>
    <?php endif; ?>
<?php endif; ?>
  </main>
 </div>
</div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
