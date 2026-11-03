<?php
/**
 * Group extras: reactions, moderators, pinned/edited posts, polls, slip and match cards, @mentions, match threads,
 * presence ("online"), weekly group challenge and the daily digest text.
 */

const CMTY_REACTIONS = ['❤️', '😂', '🔥', '😮', '😢'];

function cmty2_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    if (!cmty_ready()) { return $ok = false; }
    $t = sk_ensure_table('comment_reactions', 'database-update-24.sql');
    foreach (['pinned' => 'TINYINT(1) NOT NULL DEFAULT 0', 'edited_at' => 'DATETIME NULL DEFAULT NULL', 'attach_type' => 'VARCHAR(8) NULL DEFAULT NULL', 'attach_id' => 'INT UNSIGNED NULL DEFAULT NULL'] as $c => $d) { sk_ensure_column('comments', $c, $d); }
    sk_ensure_column('communities', 'team_keys', "VARCHAR(200) NOT NULL DEFAULT ''");
    sk_ensure_column('global_chat', 'room', 'INT UNSIGNED NOT NULL DEFAULT 0');
    sk_ensure_column('global_chat', 'reply_to', 'BIGINT UNSIGNED NULL DEFAULT NULL');
    if (function_exists('cmty3_ready')) { cmty3_ready(); }
    return $ok = $t;
}

/* ---------- moderators ---------- */

function cmty_is_mod(array $c, ?array $u): bool
{
    if (!$u) { return false; }
    if ((int)($c['created_by'] ?? 0) === (int)$u['id']) { return true; }
    if (function_exists('is_staff') && is_staff() && function_exists('can') && can('content')) { return true; }
    if (!cmty2_ready()) { return false; }
    $st = db()->prepare('SELECT 1 FROM community_mods WHERE community_id = ? AND user_id = ?');
    $st->execute([(int)$c['id'], (int)$u['id']]);
    return (bool)$st->fetchColumn();
}

/* ---------- presence / online ---------- */

function presence_ready(): bool { return sk_ensure_table('user_presence', 'database-update-24.sql'); }

function presence_touch(?array $u, int $typingRoom = -1): void
{
    if (!$u || !presence_ready()) { return; }
    try {
        if ($typingRoom >= 0) {
            db()->prepare('INSERT INTO user_presence (user_id, seen_at, typing_at, typing_room) VALUES (?, NOW(), NOW(), ?) ON DUPLICATE KEY UPDATE seen_at = NOW(), typing_at = NOW(), typing_room = VALUES(typing_room)')->execute([(int)$u['id'], $typingRoom]);
        } else {
            db()->prepare('INSERT INTO user_presence (user_id, seen_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE seen_at = NOW()')->execute([(int)$u['id']]);
        }
    } catch (Throwable $e) { error_log('presence_touch: ' . $e->getMessage()); }
}

function presence_online_total(): int
{
    if (!presence_ready()) { return 0; }
    try { return (int)db()->query('SELECT COUNT(*) FROM user_presence WHERE seen_at > NOW() - INTERVAL 5 MINUTE')->fetchColumn(); } catch (Throwable $e) { return 0; }
}

function presence_online_in_group(int $cid): int
{
    if (!presence_ready() || !cmty_members_ready()) { return 0; }
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM community_members m JOIN user_presence p ON p.user_id = m.user_id WHERE m.community_id = ? AND p.seen_at > NOW() - INTERVAL 5 MINUTE');
        $st->execute([$cid]); return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* ---------- @mentions (by public username from Account > Public profile) ---------- */

function mention_handles(string $text): array
{
    if (!preg_match_all('/(?<![\w@])@([A-Za-z0-9_]{3,20})\b/', $text, $m)) { return []; }
    return array_slice(array_values(array_unique(array_map('strtolower', $m[1]))), 0, 6);
}

function mention_lookup(array $handles): array
{
    static $cache = [];
    $out = []; $need = [];
    foreach ($handles as $h) { if (isset($cache[$h])) { if ($cache[$h]) { $out[$h] = $cache[$h]; } } else { $need[] = $h; } }
    if ($need && function_exists('members_ready') && members_ready()) {
        try {
            $in = implode(',', array_fill(0, count($need), '?'));
            $st = db()->prepare("SELECT user_id, handle FROM member_public WHERE is_public = 1 AND LOWER(handle) IN ($in)");
            $st->execute($need);
            foreach ($st->fetchAll() as $r) { $h = strtolower($r['handle']); $cache[$h] = (int)$r['user_id']; $out[$h] = (int)$r['user_id']; }
        } catch (Throwable $e) { /* no profiles yet */ }
    }
    foreach ($need as $h) { if (!isset($cache[$h])) { $cache[$h] = 0; } }
    return $out;
}

/** Escaped text with @username turned into links to the member's public page; line breaks kept. */
function mention_html(string $text): string
{
    $safe = e($text);
    if (strpos($safe, '#') !== false) {
        $safe = preg_replace_callback('/(?<![\w&#\/])#([\p{L}][\p{L}\p{N}_]{1,29})/u', function ($m) { return '<a class="gp-tag" href="' . e(url('community-search.php?q=' . rawurlencode('#' . $m[1]))) . '">#' . $m[1] . '</a>'; }, $safe);
    }
    $found = mention_lookup(mention_handles($text));
    if ($found) {
        $safe = preg_replace_callback('/(?<![\w@])@([A-Za-z0-9_]{3,20})\b/', function ($m) use ($found) {
            $h = strtolower($m[1]);
            return isset($found[$h]) ? '<a class="gp-men" href="' . e(url('member.php?u=' . $m[1])) . '">@' . $m[1] . '</a>' : $m[0];
        }, $safe);
    }
    return nl2br($safe);
}

function mentions_notify(string $body, array $from, string $where, array $skip = [], string $path = 'discuss.php'): void
{
    foreach (mention_lookup(mention_handles($body)) as $uid) {
        if ((int)$uid === (int)$from['id'] || in_array((int)$uid, $skip, true)) { continue; }
        cmty_alert((int)$uid, 'You were mentioned', mask_name((string)$from['name']) . ' mentioned you ' . $where . ': ' . mb_substr($body, 0, 100), $path);
    }
}

/* ---------- reactions ---------- */

/** [commentId => ['counts' => [emoji => n], 'mine' => [emoji => true]]] for the given comment ids. */
function cmty_reactions(array $ids, int $uid): array
{
    $out = [];
    if (!$ids || !cmty2_ready()) { return $out; }
    try {
        $rs = db()->query('SELECT comment_id, user_id, emoji FROM comment_reactions WHERE comment_id IN (' . implode(',', array_map('intval', $ids)) . ') LIMIT 20000');
        foreach ($rs->fetchAll() as $r) {
            $k = (int)$r['comment_id'];
            $out[$k]['counts'][$r['emoji']] = ($out[$k]['counts'][$r['emoji']] ?? 0) + 1;
            if ($uid && (int)$r['user_id'] === $uid) { $out[$k]['mine'][$r['emoji']] = true; }
        }
    } catch (Throwable $e) { error_log('cmty_reactions: ' . $e->getMessage()); }
    return $out;
}

/* ---------- polls ---------- */

function cmty_polls(array $postIds, int $uid): array
{
    $out = [];
    if (!$postIds || !cmty2_ready()) { return $out; }
    try {
        $in = implode(',', array_map('intval', $postIds));
        foreach (db()->query("SELECT post_id, options, closes_at FROM community_polls WHERE post_id IN ($in)")->fetchAll() as $r) {
            $opts = json_decode((string)$r['options'], true); if (!is_array($opts)) { continue; }
            $out[(int)$r['post_id']] = ['options' => $opts, 'votes' => array_fill(0, count($opts), 0), 'mine' => null, 'closed' => $r['closes_at'] && strtotime($r['closes_at']) < time()];
        }
        if ($out) {
            foreach (db()->query("SELECT post_id, user_id, opt FROM community_poll_votes WHERE post_id IN ($in)")->fetchAll() as $v) {
                $k = (int)$v['post_id']; if (!isset($out[$k]) || !isset($out[$k]['votes'][(int)$v['opt']])) { continue; }
                $out[$k]['votes'][(int)$v['opt']]++;
                if ($uid && (int)$v['user_id'] === $uid) { $out[$k]['mine'] = (int)$v['opt']; }
            }
        }
    } catch (Throwable $e) { error_log('cmty_polls: ' . $e->getMessage()); }
    return $out;
}

function cmty_poll_html(int $postId, array $poll, ?array $u, string $act, string $back): string
{
    $total = array_sum($poll['votes']); $can = $u && $poll['mine'] === null && !$poll['closed'];
    $h = '<div class="gp-poll">';
    foreach ($poll['options'] as $i => $label) {
        $n = (int)$poll['votes'][$i]; $pct = $total ? (int)round($n * 100 / $total) : 0;
        if ($can) {
            $h .= '<form method="post" action="' . $act . '">' . csrf_field() . '<input type="hidden" name="action" value="poll_vote"><input type="hidden" name="id" value="' . $postId . '"><input type="hidden" name="opt" value="' . (int)$i . '"><input type="hidden" name="back" value="' . e($back) . '"><button type="submit" class="gp-po">' . e($label) . '</button></form>';
        } else {
            $h .= '<div class="gp-pr' . ($poll['mine'] === $i ? ' mine' : '') . '"><i style="width:' . $pct . '%"></i><span>' . e($label) . ($poll['mine'] === $i ? ' &#10003;' : '') . '</span><b>' . $pct . '%</b></div>';
        }
    }
    return $h . '<small>' . $total . ' vote' . ($total === 1 ? '' : 's') . (!$u ? ' &middot; log in to vote' : '') . '</small></div>';
}

/* ---------- attachment cards ---------- */

/** Public cards for posts that carry a bet slip or a match. Returns [postId => html]. */
function cmty_attach_cards(array $posts): array
{
    $out = []; $slips = []; $matches = [];
    foreach ($posts as $p) {
        $t = $p['attach_type'] ?? null; $id = (int)($p['attach_id'] ?? 0);
        if ($t === 'slip' && $id) { $slips[(int)$p['id']] = $id; } elseif ($t === 'match' && $id) { $matches[(int)$p['id']] = $id; } elseif ($t === 'tip' && $id) { $out[(int)$p['id']] = cmty_tip_card($id, function_exists('is_vip') && is_vip()); }
    }
    try {
        if ($slips && function_exists('slips_ready') && slips_ready()) {
            $sids = implode(',', array_unique($slips));
            $rows = db()->query("SELECT id, title, stake FROM slips WHERE id IN ($sids)")->fetchAll(); $byId = [];
            foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
            $its = [];
            foreach (db()->query("SELECT slip_id, home, away, outcome, odds FROM slip_items WHERE slip_id IN ($sids) ORDER BY sort_no, id")->fetchAll() as $it) { $its[(int)$it['slip_id']][] = $it; }
            foreach ($slips as $pid => $sid) {
                if (!isset($byId[$sid])) { continue; }
                $items = $its[$sid] ?? []; $tot = slip_total_odds($items);
                $h = '<div class="gp-att slip"><div class="gp-ah"><b>&#129534; ' . e($byId[$sid]['title']) . '</b><span>' . count($items) . ' pick' . (count($items) === 1 ? '' : 's') . ' &middot; ' . number_format($tot, 2) . ' odds</span></div>';
                foreach (array_slice($items, 0, 6) as $it) { $h .= '<div class="gp-ai"><span>' . e($it['home'] . ' v ' . $it['away']) . '</span><em>' . e($it['outcome']) . '</em><b>' . number_format((float)$it['odds'], 2) . '</b></div>'; }
                if (count($items) > 6) { $h .= '<small>+' . (count($items) - 6) . ' more</small>'; }
                $out[$pid] = $h . '<small class="gp-adis">Shared by the member. Not betting advice. <a href="' . e(slip_share_url($sid)) . '" target="_blank" rel="noopener">Open share page</a></small></div>';
            }
        }
        if ($matches) {
            $mids = implode(',', array_unique($matches));
            $byM = [];
            foreach (db()->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id WHERE m.id IN ($mids)")->fetchAll() as $r) { $byM[(int)$r['id']] = $r; }
            foreach ($matches as $pid => $mid) {
                if (!isset($byM[$mid])) { continue; }
                $m = $byM[$mid]; $sc = $m['home_score'] !== null ? ((int)$m['home_score'] . ' - ' . (int)$m['away_score']) : date('D j M, H:i', strtotime($m['kickoff_at']));
                $out[$pid] = '<a class="gp-att match" href="' . e(url('room.php?id=' . $mid)) . '"><span class="gp-ah"><b>&#9917; ' . e($m['home_team'] . ' vs ' . $m['away_team']) . '</b><span>' . e($m['league']) . '</span></span><span class="gp-sc">' . e($sc) . '</span><span class="gp-go">Match room &rarr;</span></a>';
            }
        }
    } catch (Throwable $e) { error_log('cmty_attach_cards: ' . $e->getMessage()); }
    return $out;
}

/* ---------- match-day threads ---------- */

/** Creates "Match thread" posts for upcoming games of the teams listed in the group's team_keys. Safe to call on every page view. */
function cmty_match_threads_sync(array $c): void
{
    $keys = array_values(array_filter(array_map('trim', explode(',', (string)($c['team_keys'] ?? '')))));
    if (!$keys || !cmty2_ready() || !sk_ensure_table('community_match_threads', 'database-update-24.sql')) { return; }
    try {
        $likes = []; $args = [];
        foreach (array_slice($keys, 0, 5) as $k) { $likes[] = '(m.home_team LIKE ? OR m.away_team LIKE ?)'; $args[] = '%' . $k . '%'; $args[] = '%' . $k . '%'; }
        $st = db()->prepare('SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id
            WHERE (' . implode(' OR ', $likes) . ") AND m.kickoff_at BETWEEN NOW() - INTERVAL 3 HOUR AND NOW() + INTERVAL 36 HOUR
              AND NOT EXISTS (SELECT 1 FROM community_match_threads t WHERE t.community_id = " . (int)$c['id'] . ' AND t.match_id = m.id) LIMIT 3');
        $st->execute($args);
        foreach ($st->fetchAll() as $m) {
            $ins = db()->prepare('INSERT IGNORE INTO community_match_threads (community_id, match_id, post_id) VALUES (?, ?, 0)');
            $ins->execute([(int)$c['id'], (int)$m['id']]);
            if ($ins->rowCount() !== 1) { continue; }           // another visitor created it a moment ago
            db()->prepare("INSERT INTO comments (user_id, target_type, target_id, parent_id, body, title, attach_type, attach_id) VALUES (?, 'community', ?, NULL, ?, ?, 'match', ?)")
                ->execute([(int)$c['created_by'], (int)$c['id'], 'Kick-off ' . date('D j M, H:i', strtotime($m['kickoff_at'])) . ' (' . $m['league'] . '). Share your prediction and talk about the game here.', 'Match thread: ' . $m['home_team'] . ' vs ' . $m['away_team'], (int)$m['id']]);
            $pid = (int)db()->lastInsertId();
            db()->prepare('UPDATE community_match_threads SET post_id = ? WHERE community_id = ? AND match_id = ?')->execute([$pid, (int)$c['id'], (int)$m['id']]);
            db()->prepare('UPDATE comments SET pinned = 1 WHERE id = ?')->execute([$pid]);
        }
    } catch (Throwable $e) { error_log('match_threads: ' . $e->getMessage()); }
}

/* ---------- weekly group challenge (free prediction game points) ---------- */

function cmty_challenge_board(int $cid, int $limit = 5): array
{
    if (!cmty_members_ready()) { return []; }
    try {
        $st = db()->prepare("SELECT u.name, SUM(g.points) AS pts FROM community_members m JOIN game_points g ON g.user_id = m.user_id JOIN users u ON u.id = m.user_id
            WHERE m.community_id = ? AND g.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) GROUP BY m.user_id, u.name HAVING pts > 0 ORDER BY pts DESC LIMIT " . (int)$limit);
        $st->execute([$cid]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ---------- daily digest ---------- */

function digest_ready(): bool { return sk_ensure_table('digest_prefs', 'database-update-24.sql'); }

/** Plain-text digest for one member. Returns [subject, body]. */
function digest_build(array $user): array
{
    $pdo = db(); $lines = [];
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM tips WHERE deleted_at IS NULL AND DATE(created_at) = CURDATE()")->fetchColumn();
        $lines[] = "Today's tips: $n new tip" . ($n === 1 ? '' : 's') . ' - ' . url('tips.php');
    } catch (Throwable $e) { /* tips table differs: skip */ }
    try {
        if (cmty_members_ready()) {
            $st = $pdo->prepare("SELECT cm.name, COUNT(*) AS n FROM comments c JOIN community_members m ON m.community_id = c.target_id AND m.user_id = ? JOIN communities cm ON cm.id = c.target_id
                WHERE c.target_type = 'community' AND c.parent_id IS NULL AND c.status = 'visible' AND c.created_at > NOW() - INTERVAL 24 HOUR GROUP BY cm.id, cm.name ORDER BY n DESC LIMIT 5");
            $st->execute([(int)$user['id']]);
            foreach ($st->fetchAll() as $g) { $lines[] = $g['name'] . ': ' . $g['n'] . ' new post' . ((int)$g['n'] === 1 ? '' : 's'); }
        }
    } catch (Throwable $e) { }
    try {
        if (function_exists('jackpot_open_round') && ($r = jackpot_open_round())) { $lines[] = 'Jackpot: win ' . jackpot_kes($r['amount_kes']) . ' by predicting all games - ' . url('jackpot.php'); }
    } catch (Throwable $e) { }
    $lines[] = 'Join the conversation: ' . url('discuss.php');
    return ['Your ' . SITE_NAME . ' daily digest', "Hi " . mask_name((string)$user['name']) . ",\n\nHere is your day on " . SITE_NAME . ":\n\n- " . implode("\n- ", $lines) . "\n\nTurn this email off any time: " . url('account/digest.php')];
}

/** Tells the author of a post or comment that someone liked or reacted to it (never yourself). */
function cmty_notify_reaction(PDO $pdo, int $commentId, array $from, string $what): void
{
    try {
        $q = $pdo->prepare('SELECT user_id, title, body FROM comments WHERE id = ?'); $q->execute([$commentId]); $r = $q->fetch();
        if (!$r || (int)$r['user_id'] === (int)$from['id']) { return; }
        $snippet = mb_substr((string)($r['title'] ?: $r['body']), 0, 60);
        cmty_alert((int)$r['user_id'], 'New ' . (strpos($what, 'liked') === 0 ? 'like' : 'reaction'), mask_name((string)$from['name']) . ' ' . $what . ' your ' . ($r['title'] ? 'post' : 'comment') . ': ' . $snippet, cmty_comment_path($commentId));
    } catch (Throwable $e) { error_log('cmty_notify_reaction: ' . $e->getMessage()); }
}
