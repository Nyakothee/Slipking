<?php
/**
 * Communities: topic rooms the admin creates (for example m/Arsenal-fans). Members post with a title, text and a picture, and reply in threads.
 * Posts are top-level rows of the comments table (target_type 'community'), so votes, reports and moderation all work the same as in match rooms.
 */

/** Makes sure comments can hold titles, pictures and the 'community' type. Returns true when ready. */
function cmty_ensure_comments(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $a = sk_ensure_column('comments', 'title', 'VARCHAR(160) NULL DEFAULT NULL');
    $b = sk_ensure_column('comments', 'image', 'VARCHAR(60) NULL DEFAULT NULL');
    $c = false;
    try {
        $r = db()->query("SHOW COLUMNS FROM comments LIKE 'target_type'")->fetch();
        $c = $r && stripos((string)$r['Type'], "'community'") !== false;
        if (!$c) {
            db()->exec("ALTER TABLE comments MODIFY target_type ENUM('tip','tipster','match','community') NOT NULL");
            $r = db()->query("SHOW COLUMNS FROM comments LIKE 'target_type'")->fetch();
            $c = $r && stripos((string)$r['Type'], "'community'") !== false;
        }
    } catch (Throwable $e) { error_log('cmty_ensure_comments: ' . $e->getMessage()); }
    return $ok = ($a && $b && $c);
}

function cmty_ready(): bool
{
    return sk_ensure_table('communities', 'database-update-20.sql') && cmty_ensure_comments();
}

function cmty_by_slug(string $slug, bool $onlyActive = true): ?array
{
    if (!cmty_ready()) { return null; }
    $st = db()->prepare('SELECT * FROM communities WHERE slug = ?' . ($onlyActive ? ' AND is_active = 1' : ''));
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function cmty_by_id(int $id): ?array
{
    if (!cmty_ready()) { return null; }
    $st = db()->prepare('SELECT * FROM communities WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** All communities with post counts, newest activity first. */
function cmty_all(bool $onlyActive = true): array
{
    if (!cmty_ready()) { return []; }
    return db()->query("SELECT c.*, (SELECT COUNT(*) FROM comments p WHERE p.target_type = 'community' AND p.target_id = c.id AND p.parent_id IS NULL AND p.status = 'visible') AS posts,
                               (SELECT COUNT(DISTINCT p2.user_id) FROM comments p2 WHERE p2.target_type = 'community' AND p2.target_id = c.id AND p2.status = 'visible') AS members,
                               (SELECT MAX(p3.created_at) FROM comments p3 WHERE p3.target_type = 'community' AND p3.target_id = c.id AND p3.status = 'visible') AS last_at
                        FROM communities c" . ($onlyActive ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_no, c.name')->fetchAll();
}

function cmty_slugify(string $name): string
{
    $s = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
    return substr($s !== '' ? $s : 'community', 0, 50);
}

function cmty_unique_slug(string $name, int $ignoreId = 0): string
{
    $base = cmty_slugify($name); $slug = $base; $n = 2;
    while (true) {
        $q = db()->prepare('SELECT id FROM communities WHERE slug = ?'); $q->execute([$slug]);
        $id = $q->fetchColumn();
        if (!$id || (int)$id === $ignoreId) { return $slug; }
        $slug = substr($base, 0, 45) . '-' . $n++;
    }
}

/** Top-level posts of a community. $sort: hot | new | top. */
function cmty_posts(int $cid, string $sort, ?array $u, int $limit = 60): array
{
    $votes = sk_ensure_table('comment_votes', 'database-update-19.sql');
    $ext = function_exists('cmty2_ready') && cmty2_ready();
    $uid = $u ? (int)$u['id'] : 0;
    $sc = $votes ? 'COALESCE((SELECT SUM(v.value) FROM comment_votes v WHERE v.comment_id = c.id), 0)' : '0';
    $mine = ($votes && $uid) ? '(SELECT v2.value FROM comment_votes v2 WHERE v2.comment_id = c.id AND v2.user_id = ' . $uid . ')' : 'NULL';
    $order = ['new' => 'c.created_at DESC, c.id DESC', 'top' => 'score DESC, c.created_at DESC',
              'hot' => '(score + 1) / POW(TIMESTAMPDIFF(HOUR, c.created_at, NOW()) + 2, 1.4) DESC, c.id DESC'][$sort] ?? 'c.created_at DESC';
    $st = db()->prepare("SELECT c.id, c.user_id, c.title, c.body, c.image, c.created_at, " . ($ext ? 'c.pinned, c.edited_at, c.attach_type, c.attach_id, ' : '0 AS pinned, NULL AS edited_at, NULL AS attach_type, NULL AS attach_id, ') . "u.name, u.role" . profile_sql('u') . ",
                                (c.likes + $sc) AS score, $mine AS myvote,
                                (SELECT COUNT(*) FROM comments r WHERE r.parent_id = c.id AND r.status = 'visible') AS replies,
                                (SELECT 1 FROM tipsters tp WHERE tp.user_id = c.user_id AND tp.status = 'active' LIMIT 1) AS is_tipster
                         FROM comments c JOIN users u ON u.id = c.user_id
                         WHERE c.target_type = 'community' AND c.target_id = ? AND c.parent_id IS NULL AND c.status = 'visible'
                         ORDER BY pinned DESC, $order LIMIT " . (int)$limit);
    $st->execute([$cid]);
    return $st->fetchAll();
}

/** Small round icon of a community. */
function cmty_icon_html(array $c, int $size = 44): string
{
    $pic = trim((string)($c['cover'] ?? ''));
    if ($pic !== '') {
        return '<span class="cm-ico has-pic" style="width:' . $size . 'px;height:' . $size . 'px;background:' . e($c['color']) . ';overflow:hidden;padding:0"><img src="' . e(media_src($pic)) . '" alt="" style="width:100%;height:100%;object-fit:cover;display:block" onerror="this.remove()"></span>';
    }
    $ic = trim((string)$c['icon']) !== '' ? $c['icon'] : mb_strtoupper(mb_substr((string)$c['name'], 0, 1));
    return '<span class="cm-ico" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . (int)($size * 0.5) . 'px;background:' . e($c['color']) . '">' . e($ic) . '</span>';
}

/** Buttons under a text box: add a picture, add an emoji. Needs assets/js/composer.js on the page and a multipart form. */
function composer_tools_html(): string
{
    return '<div class="cmp-tools"><label class="cmp-ib" title="Add a picture" aria-label="Add a picture"><input type="file" name="image" accept="image/jpeg,image/png,image/webp">'
        . '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 8"/></svg><span>Photo</span></label>'
        . '<button type="button" class="cmp-eb" title="Add an emoji" aria-label="Add an emoji">&#128512;<span>Emoji</span></button>'
        . '<span class="cmp-fn" hidden></span></div>'
        . '<div class="cmp-pv" hidden><img alt="" src=""><button type="button" class="cmp-rm" aria-label="Remove picture">&times;</button></div>';
}

/** Picture inside a comment or post. */
function comment_image_html(?string $path): string
{
    if (!media_is_path($path) && !preg_match('#^uploads/posts/p-[a-f0-9]{16}\.(jpg|png|webp)$#', (string)$path)) { return ''; }
    $src = url((string)$path);
    return '<a class="rm-img" href="' . e($src) . '" target="_blank" rel="noopener"><img src="' . e($src) . '" alt="Picture attached to this post" loading="lazy" onerror="this.parentNode.classList.add(\'bad\');this.remove()"></a>';
}

/** Membership table and the cover column. Returns true when both exist. */
function cmty_members_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    if (!cmty_ready()) { return $ok = false; }
    $a = sk_ensure_table('community_members', 'database-update-22.sql');
    $b = sk_ensure_column('communities', 'cover', 'VARCHAR(60) NULL DEFAULT NULL');
    $c = sk_ensure_column('communities', 'tags', "VARCHAR(200) NOT NULL DEFAULT ''");
    return $ok = ($a && $b && $c);
}

/** Communities for the hub: member counts come from Join, plus posts, last activity and whether $uid joined. */
function cmty_hub(int $uid = 0): array
{
    if (function_exists('cmty3_ready')) { cmty3_ready(); }
    if (!cmty_members_ready()) {
        $rows = cmty_all();
        foreach ($rows as &$r) { $r['joined_n'] = (int)$r['members']; $r['joined'] = 0; $r['cover'] = null; }
        return $rows;
    }
    $st = db()->prepare("SELECT c.*, (SELECT COUNT(*) FROM community_members m WHERE m.community_id = c.id) AS joined_n,
                (SELECT 1 FROM community_members m2 WHERE m2.community_id = c.id AND m2.user_id = ?) AS joined,
                (SELECT COUNT(*) FROM comments p WHERE p.target_type = 'community' AND p.target_id = c.id AND p.parent_id IS NULL AND p.status = 'visible') AS posts,
                (SELECT MAX(p3.created_at) FROM comments p3 WHERE p3.target_type = 'community' AND p3.target_id = c.id AND p3.status = 'visible') AS last_at
            FROM communities c WHERE c.is_active = 1 ORDER BY c.sort_no, c.name");
    $st->execute([$uid]);
    return $st->fetchAll();
}

function cmty_is_member(int $cid, int $uid): bool
{
    if (!$uid || !cmty_members_ready()) { return false; }
    $st = db()->prepare('SELECT 1 FROM community_members WHERE community_id = ? AND user_id = ?');
    $st->execute([$cid, $uid]);
    return (bool)$st->fetchColumn();
}

/** Cover picture for a group card: the uploaded photo, or the community colour with its icon. */
function cmty_cover_html(array $c): string
{
    $src = trim((string)($c['cover'] ?? ''));
    if ($src !== '') { return '<img class="dg-img" src="' . e(media_src($src)) . '" alt="" loading="lazy" onerror="this.remove()">'; }
    $ic = trim((string)$c['icon']) !== '' ? $c['icon'] : mb_strtoupper(mb_substr((string)$c['name'], 0, 1));
    return '<span class="dg-fb" style="background:linear-gradient(135deg,' . e($c['color']) . ',#0d3b2c)">' . e($ic) . '</span>';
}

/** Join / Joined button as a small POST form. $back is a page on this site. */
function cmty_join_form(array $c, ?array $u, string $back, string $cls = ''): string
{
    $joined = !empty($c['joined']);
    $priv = !empty($c['is_private']);
    if (!$u) { return '<a class="dg-btn join ' . $cls . '" href="' . e(url('auth/login.php?next=' . rawurlencode($back))) . '">' . ($priv ? 'Request to join' : 'Join') . '</a>'; }
    if ($priv && !$joined && function_exists('cmty_request_pending') && cmty_request_pending((int)$c['id'], (int)$u['id'])) {
        return '<form method="post" action="' . e(url('community/action.php')) . '" class="dg-jf">' . csrf_field()
            . '<input type="hidden" name="action" value="cancel_request"><input type="hidden" name="cid" value="' . (int)$c['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<button type="submit" class="dg-btn joined ' . $cls . '" title="Cancel your request">Requested</button></form>';
    }
    return '<form method="post" action="' . e(url('community/action.php')) . '" class="dg-jf">' . csrf_field()
        . '<input type="hidden" name="action" value="' . ($joined ? 'leave_group' : 'join_group') . '"><input type="hidden" name="cid" value="' . (int)$c['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<button type="submit" class="dg-btn ' . ($joined ? 'joined' : 'join') . ' ' . $cls . '">' . ($joined ? 'Joined' : ($priv ? 'Request to join' : 'Join')) . '</button></form>';
}

/** Tags of a community as a clean list (admin types them separated by commas). */
function cmty_tags(array $c): array
{
    $out = [];
    foreach (explode(',', (string)($c['tags'] ?? '')) as $t) {
        $t = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', ltrim(trim($t), '#')));
        if ($t !== '' && mb_strlen($t) <= 24) { $out[] = $t; }
        if (count($out) >= 8) { break; }
    }
    return $out;
}

function gchat_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    if (!sk_ensure_table('global_chat', 'database-update-23.sql')) { return $ok = false; }
    if (function_exists('presence_ready')) { presence_ready(); }
    if (function_exists('cmty2_ready')) { cmty2_ready(); }
    sk_ensure_column('global_chat', 'attach', 'VARCHAR(60) NULL DEFAULT NULL');
    sk_ensure_column('global_chat', 'attach_kind', 'CHAR(1) NULL DEFAULT NULL');
    sk_ensure_column('global_chat', 'file_name', 'VARCHAR(100) NULL DEFAULT NULL');
    sk_ensure_column('global_chat', 'file_size', 'INT UNSIGNED NULL DEFAULT NULL');
    return $ok = true;
}

/** Why this member cannot write in the global chat right now, or ''. */
function gchat_block_reason(?array $u): string
{
    if (!$u) { return 'Log in to chat.'; }
    try {
        $st = db()->prepare('SELECT 1 FROM comment_bans WHERE user_id = ?'); $st->execute([$u['id']]);
        if ($st->fetchColumn()) { return 'Your chat access has been switched off by a moderator.'; }
    } catch (Throwable $e) { /* table may not exist: nobody is banned */ }
    if (empty($u['email_verified']) && !(function_exists('is_staff') && is_staff())) { return 'Confirm your email address to chat.'; }
    return '';
}

/** For the "before you open it" numbers: unread messages for this member (last 24 h for guests), messages today, people chatting now, latest message. */
function gchat_stats(?array $u): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $out = ['unread' => 0, 'today' => 0, 'active' => 0, 'last' => null];
    if (!gchat_ready()) { return $cache = $out; }
    try {
        $pdo = db();
        $out['today'] = (int)$pdo->query('SELECT COUNT(*) FROM global_chat WHERE room = 0 AND created_at > NOW() - INTERVAL 24 HOUR')->fetchColumn();
        $out['active'] = (int)$pdo->query('SELECT COUNT(*) FROM user_presence WHERE seen_at > NOW() - INTERVAL 5 MINUTE')->fetchColumn();
        $l = $pdo->query('SELECT g.body, g.attach_kind, g.created_at, u.name, u.role FROM global_chat g JOIN users u ON u.id = g.user_id WHERE g.room = 0 ORDER BY g.id DESC LIMIT 1')->fetch();
        if ($l) { $out['last'] = ['who' => $l['role'] !== 'user' ? 'Team' : mask_name((string)$l['name']), 'body' => (string)$l['body'] !== '' ? (string)$l['body'] : ($l['attach_kind'] === 'i' ? 'Sent a photo' : 'Sent a file')]; }
        if ($u && sk_ensure_table('global_chat_seen', 'database-update-23.sql')) {
            $st = $pdo->prepare('SELECT last_id FROM global_chat_seen WHERE user_id = ?'); $st->execute([(int)$u['id']]);
            $seen = $st->fetchColumn();
            if ($seen === false) { $out['unread'] = $out['today']; }
            else { $q = $pdo->prepare('SELECT COUNT(*) FROM global_chat WHERE room = 0 AND id > ? AND user_id <> ?'); $q->execute([(int)$seen, (int)$u['id']]); $out['unread'] = (int)$q->fetchColumn(); }
        } else { $out['unread'] = $out['today']; }
    } catch (Throwable $e) { error_log('gchat_stats: ' . $e->getMessage()); }
    return $cache = $out;
}

function gchat_mark_seen(int $uid, int $lastId): void
{
    if ($uid <= 0 || $lastId <= 0 || !sk_ensure_table('global_chat_seen', 'database-update-23.sql')) { return; }
    try { db()->prepare('INSERT INTO global_chat_seen (user_id, last_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_id = GREATEST(last_id, VALUES(last_id))')->execute([$uid, $lastId]); }
    catch (Throwable $e) { error_log('gchat_mark_seen: ' . $e->getMessage()); }
}
