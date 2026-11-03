<?php
/**
 * Community round 3: push/e-mail alerts for community events, spam guard, member levels and verified badge,
 * private groups (join requests), group events, direct messages, hashtags and search, slip share links, odds watch,
 * community statistics for the admin.
 * Everything here is defensive: a missing table or column never breaks a page.
 */

/* ---------- setup ---------- */

function cmty3_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    if (!function_exists('cmty_ready') || !cmty_ready()) { return $ok = false; }
    $t = sk_ensure_table('community_requests', 'database-update-25.sql');
    sk_ensure_column('communities', 'is_private', 'TINYINT(1) NOT NULL DEFAULT 0');
    if (function_exists('members_ready') && members_ready()) { sk_ensure_column('member_public', 'verified', 'TINYINT NOT NULL DEFAULT 0'); }
    return $ok = $t;
}

/* ---------- alerts: in-app + browser push + e-mail, as each member chose in Account > Alerts ---------- */

/** Sends one community alert. $path is relative to the site root, for example m.php?s=general&pos=12. Never throws. */
function cmty_alert(int $uid, string $title, string $body, string $path = 'discuss.php'): void
{
    static $hooked = false;
    try {
        if (function_exists('alert_enqueue')) {
            alert_enqueue($uid, 'community', $title, $body, $path, 'cm-' . substr(md5($uid . '|' . $title . '|' . $body . '|' . microtime(true) . mt_rand()), 0, 40));
            if (!$hooked && function_exists('alerts_process')) {
                $hooked = true;
                register_shutdown_function(function () { try { alerts_process(10); } catch (Throwable $e) { } });
            }
        } else {
            notify_user($uid, $title, $body);
        }
    } catch (Throwable $e) {
        error_log('cmty_alert: ' . $e->getMessage());
        try { notify_user($uid, $title, $body); } catch (Throwable $e2) { }
    }
}

/** Address of any comment (tip, tipster page, match room, group) so a notification opens the exact one to reply to. */
function cmty_target_path(string $type, int $target, int $cid, string $fallback = 'discuss.php'): string
{
    try {
        if ($type === 'community') { return cmty_comment_path($cid); }
        if ($type === 'tip') { return 'tip.php?id=' . $target . '#c' . $cid; }
        if ($type === 'match') { return 'room.php?id=' . $target . '#c' . $cid; }
        if ($type === 'tipster') { $q = db()->prepare('SELECT slug FROM tipsters WHERE id = ?'); $q->execute([$target]); $sl = (string)$q->fetchColumn(); if ($sl !== '') { return 'tipster.php?slug=' . rawurlencode($sl) . '#c' . $cid; } }
    } catch (Throwable $e) { }
    return $fallback;
}

/** Address of a group post or comment, for alert links. */
function cmty_comment_path(int $commentId): string
{
    try {
        $st = db()->prepare("SELECT c.id, c.parent_id, c.target_id, cm.slug FROM comments c JOIN communities cm ON cm.id = c.target_id WHERE c.id = ? AND c.target_type = 'community'");
        $st->execute([$commentId]); $r = $st->fetch();
        if ($r) { return 'm.php?s=' . $r['slug'] . ($r['parent_id'] ? '&open=' . (int)$r['parent_id'] . '&rpos=' . (int)$r['id'] : '&pos=' . (int)$r['id']) . '#c' . (int)$r['id']; }
    } catch (Throwable $e) { }
    return 'discuss.php';
}

/* ---------- spam guard ---------- */

/** Extra checks for posts, comments, chat and direct messages. Returns an error text or ''. Staff are never blocked. */
/**
 * Private messages: any logged-in member can read and reply to anyone. Unlike public comments there is no email-confirmation or
 * comment-speed rule here. Only a moderator ban (comment_bans) stops it; the spam checks and member blocks still apply.
 */
function dm_block_reason(?array $u, int $to = 0): string
{
    if (!$u) { return 'Log in to send messages.'; }
    try {
        $st = db()->prepare('SELECT 1 FROM comment_bans WHERE user_id = ?');
        $st->execute([$u['id']]);
        if ($st->fetchColumn()) { return 'Messaging has been switched off for your account by a moderator.'; }
    } catch (Throwable $e) { /* never block because a check failed */ }
    // Anti-spam limits for messaging many people at once (staff are exempt): 40 messages and 10 different people per hour.
    if ($to > 0 && !(function_exists('is_staff') && is_staff())) {
        try {
            $st = db()->prepare('SELECT COUNT(*) AS n, COUNT(DISTINCT to_id) AS p, SUM(to_id = ?) AS same FROM dm_messages WHERE from_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
            $st->execute([$to, $u['id']]);
            $r = $st->fetch();
            if ($r) {
                if ((int)$r['n'] >= 40) { return 'You are sending messages very fast. Please wait a little and try again.'; }
                if ((int)$r['p'] >= 10 && (int)$r['same'] === 0) { return 'You have messaged many different people in the last hour. Please wait a little before starting new chats.'; }
            }
        } catch (Throwable $e) { /* never block because a check failed */ }
    }
    return '';
}

function spam_block_reason(?array $u, string $text, string $where = 'post'): string
{
    if (!$u || (function_exists('is_staff') && is_staff())) { return ''; }
    $uid = (int)$u['id'];
    $hasLink = (bool)preg_match('~(https?:|www\.|\b[a-z0-9\-]+\.(com|net|org|co|io|ke|ng|info|biz|me|tv|bet|xyz|ly|gl|app|link)\b|t\.me/|wa\.me/|bit\.ly)~i', $text);
    try {
        if ($hasLink) {
            $st = db()->prepare('SELECT created_at FROM users WHERE id = ?'); $st->execute([$uid]);
            $created = $st->fetchColumn();
            if ($created && strtotime((string)$created) > time() - 86400) { return 'New accounts cannot post links in the first 24 hours.'; }
        }
        if ($where === 'post') {
            $st = db()->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ? AND target_type = 'community' AND created_at > NOW() - INTERVAL 2 MINUTE");
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() >= 6) { return 'You are posting very fast. Please wait a minute.'; }
            // someone whose posts keep being hidden after reports is slowed down
            $st = db()->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ? AND status = 'hidden' AND reports >= " . (int)COMMENT_AUTOHIDE_REPORTS . ' AND created_at > NOW() - INTERVAL 7 DAY');
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() >= 3) { return 'Several of your recent posts were reported and hidden. Posting is paused for a few days.'; }
        } elseif ($where === 'dm') {
            $st = db()->prepare('SELECT COUNT(*) FROM dm_messages WHERE from_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE');
            $st->execute([$uid]);
            if ((int)$st->fetchColumn() >= 20) { return 'You are sending messages very fast. Please wait a few minutes.'; }
        }
    } catch (Throwable $e) { /* never block because a check failed */ }
    return '';
}

/* ---------- member flags (public username, verified badge) and levels ---------- */

/** [userId => ['handle' => string, 'verified' => bool]] for members who made their profile public. */
function member_flags(array $uids): array
{
    static $cache = [];
    $out = []; $need = [];
    foreach (array_unique(array_map('intval', $uids)) as $id) { if ($id <= 0) { continue; } if (array_key_exists($id, $cache)) { if ($cache[$id]) { $out[$id] = $cache[$id]; } } else { $need[] = $id; } }
    if ($need && function_exists('members_ready') && members_ready()) {
        cmty3_ready();
        try {
            $rs = db()->query('SELECT user_id, handle, verified FROM member_public WHERE is_public = 1 AND user_id IN (' . implode(',', array_slice($need, 0, 300)) . ')')->fetchAll();
            foreach ($rs as $r) { $cache[(int)$r['user_id']] = ['handle' => $r['handle'], 'verified' => ((int)$r['verified'] === 1)]; $out[(int)$r['user_id']] = $cache[(int)$r['user_id']]; }
        } catch (Throwable $e) { /* column missing: ignore */ }
    }
    foreach ($need as $id) { if (!array_key_exists($id, $cache)) { $cache[$id] = null; } }
    return $out;
}

function member_verified_html(?array $flag): string
{
    return ($flag && !empty($flag['verified'])) ? ' <i class="vf" title="Verified member: a proven record of shared bets">&#10003;</i>' : '';
}

const MEMBER_LEVELS = [0 => 'Rookie', 30 => 'Regular', 120 => 'Pro', 350 => 'Expert', 900 => 'Legend'];

/** [userId => ['pts' => int, 'n' => level number 0-4, 'name' => string]]. Points: 5 per post, 2 per reply, 1 per like received. */
function member_levels(array $uids): array
{
    static $cache = [];
    $out = []; $need = [];
    foreach (array_unique(array_map('intval', $uids)) as $id) { if ($id <= 0) { continue; } if (isset($cache[$id])) { $out[$id] = $cache[$id]; } else { $need[] = $id; } }
    if ($need) {
        $likes = sk_ensure_table('comment_votes', 'database-update-19.sql');
        try {
            $lk = $likes ? "(SELECT COUNT(*) FROM comment_votes v JOIN comments c3 ON c3.id = v.comment_id WHERE c3.user_id = u.id AND v.value = 1)" : '0';
            $rs = db()->query("SELECT u.id,
                (SELECT COUNT(*) FROM comments c1 WHERE c1.user_id = u.id AND c1.target_type = 'community' AND c1.parent_id IS NULL AND c1.status = 'visible') AS posts,
                (SELECT COUNT(*) FROM comments c2 WHERE c2.user_id = u.id AND c2.target_type = 'community' AND c2.parent_id IS NOT NULL AND c2.status = 'visible') AS replies,
                $lk AS likes FROM users u WHERE u.id IN (" . implode(',', array_slice($need, 0, 200)) . ')')->fetchAll();
            foreach ($rs as $r) {
                $pts = (int)$r['posts'] * 5 + (int)$r['replies'] * 2 + (int)$r['likes'];
                $n = 0; $i = 0;
                foreach (MEMBER_LEVELS as $min => $nm) { if ($pts >= $min) { $n = $i; } $i++; }
                $cache[(int)$r['id']] = $out[(int)$r['id']] = ['pts' => $pts, 'n' => $n, 'name' => array_values(MEMBER_LEVELS)[$n]];
            }
        } catch (Throwable $e) { error_log('member_levels: ' . $e->getMessage()); }
    }
    return $out;
}

function member_level_html(?array $lv): string
{
    if (!$lv || $lv['n'] < 1) { return ''; }
    return '<span class="lv lv' . (int)$lv['n'] . '" title="Level ' . (int)$lv['n'] . ' - ' . (int)$lv['pts'] . ' community points">' . e($lv['name']) . '</span>';
}

/** The three most active members of a group over the last 30 days (needs at least 5 posts or replies each). */
function cmty_top_contributors(int $cid): array
{
    try {
        $st = db()->prepare("SELECT user_id FROM comments WHERE target_type = 'community' AND target_id = ? AND status = 'visible' AND created_at > NOW() - INTERVAL 30 DAY GROUP BY user_id HAVING COUNT(*) >= 5 ORDER BY COUNT(*) DESC LIMIT 3");
        $st->execute([$cid]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}

/** Marks members with a good shared record as verified. Admin choices win: -1 = never, 1 = verified by admin. Returns how many were newly verified. */
function member_auto_verify(): int
{
    if (!function_exists('members_ready') || !members_ready() || !cmty3_ready()) { return 0; }
    $min = max(10, (int)setting('verify_min_bets', '30')); $n = 0;
    try {
        foreach (db()->query('SELECT user_id, handle FROM member_public WHERE is_public = 1 AND verified = 0 ORDER BY user_id LIMIT 300')->fetchAll() as $r) {
            $st = member_stats((int)$r['user_id'], false);
            if ($st['settled'] >= $min && $st['roi'] !== null && $st['roi'] > 0) {
                db()->prepare('UPDATE member_public SET verified = 1 WHERE user_id = ? AND verified = 0')->execute([(int)$r['user_id']]);
                cmty_alert((int)$r['user_id'], 'You are now a verified member', 'Your shared bets show a proven record. A verified tick now appears next to your name.', 'member.php?u=' . $r['handle']);
                $n++;
            }
        }
    } catch (Throwable $e) { error_log('auto_verify: ' . $e->getMessage()); }
    return $n;
}

/* ---------- private groups ---------- */

function cmty_is_private(array $c): bool { return !empty($c['is_private']); }

function cmty_request_pending(int $cid, int $uid): bool
{
    static $set = null;
    if ($uid <= 0 || !cmty3_ready()) { return false; }
    if ($set === null) {
        $set = [];
        try { foreach (db()->query('SELECT community_id FROM community_requests WHERE user_id = ' . $uid)->fetchAll(PDO::FETCH_COLUMN) as $x) { $set[(int)$x] = true; } } catch (Throwable $e) { }
    }
    return !empty($set[$cid]);
}

/** [[user_id, name, created_at, ...], ...] waiting for approval. */
function cmty_pending_requests(int $cid): array
{
    if (!cmty3_ready()) { return []; }
    try {
        $st = db()->prepare('SELECT r.user_id, r.created_at, u.name' . profile_sql('u') . ' FROM community_requests r JOIN users u ON u.id = r.user_id WHERE r.community_id = ? ORDER BY r.created_at ASC LIMIT 50');
        $st->execute([$cid]); return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** May this person take part in the group (post, comment, react)? Public groups: any logged-in member. Private: joined members and moderators only. */
function cmty_can_take_part(array $c, ?array $u): bool
{
    if (!$u) { return false; }
    if (!cmty_is_private($c)) { return true; }
    if (cmty_is_mod($c, $u)) { return true; }
    try {
        $st = db()->prepare('SELECT 1 FROM community_members WHERE community_id = ? AND user_id = ?'); $st->execute([(int)$c['id'], (int)$u['id']]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/** Ids of private groups the person is NOT in (used to keep their posts out of feeds and search). */
function cmty_hidden_group_ids(?array $u): array
{
    if (!cmty3_ready()) { return []; }
    try {
        $uid = $u ? (int)$u['id'] : 0;
        $sql = 'SELECT c.id FROM communities c WHERE c.is_private = 1' . ($uid ? ' AND c.created_by <> ' . $uid . ' AND NOT EXISTS (SELECT 1 FROM community_members m WHERE m.community_id = c.id AND m.user_id = ' . $uid . ')' : '');
        $ids = array_map('intval', db()->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        if ($u && function_exists('is_staff') && is_staff() && can('content')) { return []; }
        return $ids;
    } catch (Throwable $e) { return []; }
}

/* ---------- group events ---------- */

function cmty_events(int $cid, int $uid, int $limit = 4): array
{
    if (!cmty3_ready()) { return []; }
    try {
        $st = db()->prepare("SELECT e.id, e.title, e.note, e.starts_at,
              (SELECT COUNT(*) FROM community_event_rsvp r WHERE r.event_id = e.id) AS going,
              " . ($uid ? '(SELECT 1 FROM community_event_rsvp r2 WHERE r2.event_id = e.id AND r2.user_id = ' . $uid . ')' : '0') . " AS mine
              FROM community_events e WHERE e.community_id = ? AND e.starts_at > NOW() - INTERVAL 2 HOUR ORDER BY e.starts_at ASC LIMIT " . (int)$limit);
        $st->execute([$cid]); return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function cmty_events_html(array $events, array $c, ?array $u, bool $isMod, bool $isMember, string $act, string $back): string
{
    $h = '<div class="gp-card gp-events"><div class="gp-rh"><h3>Events</h3>' . ($isMod ? '<button type="button" class="gp-link" data-toggle="evnew">+ Add</button>' : '') . '</div>';
    if ($isMod) {
        $h .= '<form class="gp-evf" id="evnew" hidden method="post" action="' . $act . '">' . csrf_field() . '<input type="hidden" name="action" value="event_add"><input type="hidden" name="cid" value="' . (int)$c['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<input name="title" maxlength="120" required placeholder="Event name, e.g. Derby watch party"><input type="datetime-local" name="starts" required aria-label="Start time (your time zone)"><input name="note" maxlength="300" placeholder="Details (optional)"><button class="gp-post sm" type="submit">Create event</button></form>';
    }
    if (!$events) { return $h . '<p class="gp-muted">No upcoming events.' . ($isMod ? ' Add one for the group.' : '') . '</p></div>'; }
    foreach ($events as $ev) {
        $ts = strtotime($ev['starts_at']);
        $h .= '<div class="gp-ev"><div class="gp-evd"><b>' . e(date('j', $ts)) . '</b><span>' . e(date('M', $ts)) . '</span></div><div class="gp-evb"><strong>' . e($ev['title']) . '</strong><small>' . e(date('D H:i', $ts)) . ' &middot; <span data-count="' . (int)$ts . '"></span></small>'
            . ($ev['note'] !== '' ? '<small>' . e($ev['note']) . '</small>' : '') . '<small>' . (int)$ev['going'] . ' going</small>';
        if ($u && $isMember) {
            $h .= '<form method="post" action="' . $act . '">' . csrf_field() . '<input type="hidden" name="action" value="event_rsvp"><input type="hidden" name="id" value="' . (int)$ev['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button type="submit" class="gp-rsvp' . ($ev['mine'] ? ' on' : '') . '">' . ($ev['mine'] ? 'Going &#10003;' : 'I am going') . '</button></form>';
        }
        if ($isMod) {
            $h .= '<form method="post" action="' . $act . '" onsubmit="return confirm(\'Delete this event?\')">' . csrf_field() . '<input type="hidden" name="action" value="event_delete"><input type="hidden" name="id" value="' . (int)$ev['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button type="submit" class="gp-link danger">Delete</button></form>';
        }
        $h .= '</div></div>';
    }
    return $h . '</div>';
}

/* ---------- hashtags ---------- */

/** #tag words found in a text (lower case, without the #). */
function hashtags_in(string $text): array
{
    if (!preg_match_all('/(?<![\w&#\/])#([\p{L}][\p{L}\p{N}_]{1,29})/u', $text, $m)) { return []; }
    return array_values(array_unique(array_map('mb_strtolower', $m[1])));
}

/** Tags used in group posts over the last 7 days, most used first. [tag => count] */
function trending_tags(int $limit = 10): array
{
    $hide = cmty_hidden_group_ids(current_user());
    try {
        $sql = "SELECT CONCAT(COALESCE(title, ''), ' ', body) FROM comments WHERE target_type = 'community' AND parent_id IS NULL AND status = 'visible' AND created_at > NOW() - INTERVAL 7 DAY"
            . ($hide ? ' AND target_id NOT IN (' . implode(',', $hide) . ')' : '') . ' AND (title LIKE \'%#%\' OR body LIKE \'%#%\') ORDER BY id DESC LIMIT 400';
        $c = [];
        foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $t) { foreach (hashtags_in((string)$t) as $tag) { $c[$tag] = ($c[$tag] ?? 0) + 1; } }
        arsort($c);
        return array_slice($c, 0, $limit, true);
    } catch (Throwable $e) { return []; }
}

/* ---------- direct messages ---------- */

function dm_ready(): bool { return sk_ensure_table('dm_messages', 'database-update-25.sql'); }

function dm_unread(int $uid): int
{
    static $c = [];
    if ($uid <= 0 || !dm_ready()) { return 0; }
    if (!isset($c[$uid])) {
        try { $st = db()->prepare('SELECT COUNT(*) FROM dm_messages WHERE to_id = ? AND read_at IS NULL AND hidden = 0'); $st->execute([$uid]); $c[$uid] = (int)$st->fetchColumn(); } catch (Throwable $e) { $c[$uid] = 0; }
    }
    return $c[$uid];
}

/** True when $by has blocked $other. */
function dm_has_blocked(int $by, int $other): bool
{
    try { $st = db()->prepare('SELECT 1 FROM dm_blocks WHERE user_id = ? AND blocked_id = ?'); $st->execute([$by, $other]); return (bool)$st->fetchColumn(); } catch (Throwable $e) { return false; }
}

/** Latest message of each conversation. */
function dm_conversations(int $uid): array
{
    try {
        $st = db()->prepare("SELECT m.id, m.from_id, m.to_id, m.body, m.created_at, m.read_at, u.id AS oid, u.name" . profile_sql('u') . ",
              (SELECT COUNT(*) FROM dm_messages x WHERE x.from_id = u.id AND x.to_id = ? AND x.read_at IS NULL AND x.hidden = 0) AS unread
            FROM dm_messages m JOIN users u ON u.id = IF(m.from_id = ?, m.to_id, m.from_id)
            WHERE m.id IN (SELECT MAX(id) FROM dm_messages WHERE (from_id = ? OR to_id = ?) AND hidden = 0 GROUP BY IF(from_id = ?, to_id, from_id))
            ORDER BY m.id DESC LIMIT 60");
        $st->execute([$uid, $uid, $uid, $uid, $uid]); return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/* ---------- slip share links (public page with a preview card) ---------- */

function slip_share_key(int $slipId): string { return substr(hash_hmac('sha256', 'slip-share|' . $slipId, app_secret()), 0, 16); }

function slip_share_url(int $slipId, ?string $refCode = null): string
{
    return url('slip-share.php?id=' . $slipId . '&k=' . slip_share_key($slipId));
}

/* ---------- odds watch ---------- */

function odds_watch_ready(): bool { return sk_ensure_table('odds_watch', 'database-update-25.sql'); }

/** Called after each odds sync: tells watchers when the best price moved by 5 percent or more since they last heard. Returns alerts sent. */
function odds_watch_process(): int
{
    if (!odds_watch_ready()) { return 0; }
    $sent = 0;
    try {
        $rows = db()->query("SELECT w.user_id, w.event_id, w.base_home, w.base_draw, w.base_away, w.notified_at, e.home_team, e.away_team,
              (SELECT h.best_home FROM odds_history h WHERE h.event_id = e.id ORDER BY h.fetched_at DESC, h.id DESC LIMIT 1) AS nh,
              (SELECT h.best_draw FROM odds_history h WHERE h.event_id = e.id ORDER BY h.fetched_at DESC, h.id DESC LIMIT 1) AS nd,
              (SELECT h.best_away FROM odds_history h WHERE h.event_id = e.id ORDER BY h.fetched_at DESC, h.id DESC LIMIT 1) AS na
            FROM odds_watch w JOIN odds_events e ON e.id = w.event_id WHERE e.commence_at > UTC_TIMESTAMP() LIMIT 2000")->fetchAll();
        foreach ($rows as $r) {
            $moves = []; $set = [];
            foreach (['home' => $r['home_team'] . ' win', 'draw' => 'Draw', 'away' => $r['away_team'] . ' win'] as $k => $label) {
                $old = $r['base_' . $k]; $new = $r[['home' => 'nh', 'draw' => 'nd', 'away' => 'na'][$k]];
                if ($new === null) { continue; }
                if ($old === null || (float)$old <= 0) { $set[$k] = (float)$new; continue; }      // first reading: just remember it
                if (abs((float)$new - (float)$old) / (float)$old >= 0.05) {
                    $moves[] = $label . ' ' . number_format((float)$old, 2) . ' to ' . number_format((float)$new, 2) . ((float)$new < (float)$old ? ' (shortened)' : ' (drifted)');
                    $set[$k] = (float)$new;
                }
            }
            if ($moves && (!$r['notified_at'] || strtotime($r['notified_at']) < time() - 3 * 3600)) {
                cmty_alert((int)$r['user_id'], 'Odds moved: ' . $r['home_team'] . ' vs ' . $r['away_team'], 'Best price now: ' . implode('; ', $moves) . '.', 'odds-event.php?id=' . (int)$r['event_id']);
                db()->prepare('UPDATE odds_watch SET base_home = COALESCE(?, base_home), base_draw = COALESCE(?, base_draw), base_away = COALESCE(?, base_away), notified_at = NOW() WHERE user_id = ? AND event_id = ?')
                    ->execute([$set['home'] ?? null, $set['draw'] ?? null, $set['away'] ?? null, (int)$r['user_id'], (int)$r['event_id']]);
                $sent++;
            } elseif ($set && !$moves) {
                db()->prepare('UPDATE odds_watch SET base_home = COALESCE(?, base_home), base_draw = COALESCE(?, base_draw), base_away = COALESCE(?, base_away) WHERE user_id = ? AND event_id = ?')
                    ->execute([$set['home'] ?? null, $set['draw'] ?? null, $set['away'] ?? null, (int)$r['user_id'], (int)$r['event_id']]);
            }
        }
    } catch (Throwable $e) { error_log('odds_watch_process: ' . $e->getMessage()); }
    return $sent;
}

/* ---------- tip cards inside group posts (verified tipsters) ---------- */

/** Public card for a tipster's tip attached to a group post. VIP picks stay hidden from non-VIP viewers until settled. */
function cmty_tip_card(int $tipId, bool $vip): string
{
    try {
        $st = db()->prepare("SELECT t.id, t.pick, t.odds, t.status, t.is_vip, m.home_team, m.away_team, m.kickoff_at, ti.display_name, ti.slug
            FROM tips t JOIN matches m ON m.id = t.match_id JOIN tipsters ti ON ti.id = t.tipster_id WHERE t.id = ? AND t.deleted_at IS NULL AND ti.status = 'active'");
        $st->execute([$tipId]); $t = $st->fetch();
        if (!$t) { return ''; }
        $locked = !empty($t['is_vip']) && !$vip && $t['status'] === 'pending';
        $badge = ['won' => 'Won', 'lost' => 'Lost', 'void' => 'Void', 'pending' => 'Pending'][$t['status']] ?? ucfirst((string)$t['status']);
        return '<a class="gp-att tip" href="' . e(url('tip.php?id=' . (int)$t['id'])) . '"><span class="gp-ah"><b>&#9989; Verified tipster: ' . e($t['display_name']) . '</b><span>' . e($t['home_team'] . ' vs ' . $t['away_team']) . ' &middot; ' . e(date('D j M, H:i', strtotime($t['kickoff_at']))) . '</span></span>'
            . '<span class="gp-sc">' . ($locked ? 'Premium pick - unlock to see it' : e($t['pick']) . ' @ ' . number_format((float)$t['odds'], 2)) . '</span><span class="gp-go">' . e($badge) . ' &middot; open tip &rarr;</span></a>';
    } catch (Throwable $e) { return ''; }
}

/** Tips the logged-in verified tipster may attach (own, recent, not deleted). [[id, label], ...] */
function cmty_my_tips(int $uid): array
{
    try {
        $st = db()->prepare("SELECT t.id, t.pick, t.odds, m.home_team, m.away_team FROM tips t JOIN tipsters ti ON ti.id = t.tipster_id JOIN matches m ON m.id = t.match_id
            WHERE ti.user_id = ? AND ti.status = 'active' AND t.deleted_at IS NULL AND m.kickoff_at > NOW() - INTERVAL 2 DAY ORDER BY t.id DESC LIMIT 15");
        $st->execute([$uid]);
        return array_map(function ($r) { return ['id' => (int)$r['id'], 'label' => $r['home_team'] . ' v ' . $r['away_team'] . ' - ' . $r['pick'] . ' @ ' . number_format((float)$r['odds'], 2)]; }, $st->fetchAll());
    } catch (Throwable $e) { return []; }
}

/* ---------- statistics for Admin > Community stats ---------- */

function cmty_stats(): array
{
    $pdo = db(); $o = [];
    $one = function (string $sql) use ($pdo) { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; } };
    $o['groups']   = $one('SELECT COUNT(*) FROM communities WHERE is_active = 1');
    $o['members']  = $one('SELECT COUNT(DISTINCT user_id) FROM community_members');
    $o['posts7']   = $one("SELECT COUNT(*) FROM comments WHERE target_type = 'community' AND parent_id IS NULL AND status = 'visible' AND created_at > NOW() - INTERVAL 7 DAY");
    $o['replies7'] = $one("SELECT COUNT(*) FROM comments WHERE target_type = 'community' AND parent_id IS NOT NULL AND status = 'visible' AND created_at > NOW() - INTERVAL 7 DAY");
    $o['dau']      = $one('SELECT COUNT(*) FROM user_presence WHERE seen_at >= CURDATE()');
    $o['online']   = $one('SELECT COUNT(*) FROM user_presence WHERE seen_at > NOW() - INTERVAL 5 MINUTE');
    $o['dm7']      = $one('SELECT COUNT(*) FROM dm_messages WHERE created_at > NOW() - INTERVAL 7 DAY');
    $o['pending']  = $one('SELECT COUNT(*) FROM community_requests');
    $o['dmrep']    = $one('SELECT COUNT(*) FROM dm_reports');
    $o['top'] = []; $o['reported'] = [];
    try {
        $o['top'] = $pdo->query("SELECT cm.id, cm.name, cm.slug, cm.is_private,
            (SELECT COUNT(*) FROM comments c WHERE c.target_type = 'community' AND c.target_id = cm.id AND c.status = 'visible' AND c.created_at > NOW() - INTERVAL 7 DAY) AS act7,
            (SELECT COUNT(*) FROM community_members m WHERE m.community_id = cm.id AND m.joined_at > NOW() - INTERVAL 7 DAY) AS new7,
            (SELECT COUNT(*) FROM community_members m2 WHERE m2.community_id = cm.id) AS total
            FROM communities cm WHERE cm.is_active = 1 ORDER BY act7 DESC, total DESC LIMIT 10")->fetchAll();
    } catch (Throwable $e) { }
    try {
        $o['reported'] = $pdo->query("SELECT u.id, u.name, u.email, SUM(c.reports) AS rep, COUNT(*) AS posts
            FROM comments c JOIN users u ON u.id = c.user_id WHERE c.reports > 0 AND c.created_at > NOW() - INTERVAL 30 DAY GROUP BY u.id, u.name, u.email ORDER BY rep DESC LIMIT 10")->fetchAll();
    } catch (Throwable $e) { }
    return $o;
}
