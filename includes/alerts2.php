<?php
/**
 * Alerts, part 2: mute teams/tipsters, digest time, weekly digest, new-VIP / boosted-odds / saved-tip / reacted-tip /
 * streak-reminder / missed-tips alerts, unread count, delete message, admin urgent banner.
 */
function alerts2_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = function_exists('sk_ensure_table') && sk_ensure_table('alert_mutes', 'database-update-40.sql') && sk_ensure_table('alert_extra', 'database-update-40.sql') && sk_ensure_table('tip_reactions', 'database-update-40.sql');
        if ($ok) { try { sk_ensure_column('alert_extra', 'tz', 'VARCHAR(40) NULL'); } catch (Throwable $e) { } }
    }
    return $ok;
}

/** Muted team / tipster names for a member (lowercase). */
function alert_mutes_of(int $uid): array
{
    static $c = [];
    if (!isset($c[$uid])) {
        $c[$uid] = [];
        if (alerts2_ready()) {
            $st = db()->prepare('SELECT ref FROM alert_mutes WHERE user_id = ?');
            $st->execute([$uid]);
            $c[$uid] = array_map('mb_strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    }
    return $c[$uid];
}

/** True if the alert text mentions a team or tipster the member muted. */
function alert_is_muted(int $uid, string $text): bool
{
    $text = mb_strtolower($text);
    foreach (alert_mutes_of($uid) as $m) {
        if ($m !== '' && mb_strpos($text, $m) !== false) { return true; }
    }
    return false;
}

function alert_extra_get(int $uid): array
{
    $r = ['digest_hour' => 8, 'weekly' => 0];
    if (alerts2_ready()) {
        $st = db()->prepare('SELECT digest_hour, weekly FROM alert_extra WHERE user_id = ?');
        $st->execute([$uid]);
        if ($x = $st->fetch()) { $r = $x; }
    }
    return $r;
}

/** Everyone who saved/followed a tip, reacted to it or tracked it. */
function tip_watchers(int $tipId): array
{
    $ids = [];
    foreach (['SELECT user_id FROM tip_follows WHERE tip_id = ?', 'SELECT user_id FROM tip_reactions WHERE tip_id = ?'] as $q) {
        try { $st = db()->prepare($q); $st->execute([$tipId]); $ids = array_merge($ids, $st->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) { }
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

/** A free tip's odds went up: people who saved it or follow its tipster hear it. */
function alerts_odds_boost(int $tipId, float $old, float $new): int
{
    if ($new <= $old + 0.04) { return 0; }
    $st = db()->prepare('SELECT t.id, t.tipster_id, t.is_vip, t.pick, m.home_team, m.away_team FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ? AND t.deleted_at IS NULL');
    $st->execute([$tipId]);
    $t = $st->fetch();
    if (!$t || $t['is_vip']) { return 0; }
    $users = tip_watchers($tipId);
    if (!empty($t['tipster_id'])) { $users = array_merge($users, alert_tipster_audience((int)$t['tipster_id'], 300)); }
    $n = 0;
    foreach (array_unique($users) as $u) {
        if (alert_enqueue((int)$u, 'tip', 'Odds boosted', $t['home_team'] . ' vs ' . $t['away_team'] . ': odds up from ' . number_format($old, 2) . ' to ' . number_format($new, 2) . '.', 'tip.php?id=' . $tipId, 'ob-' . $tipId . '-' . (int)($new * 100))) { $n++; }
    }
    return $n;
}

/** A VIP tip was posted: VIP members (active plan) are told; others never see the pick. */
function alerts_vip_posted(int $tipId): int
{
    $st = db()->prepare('SELECT t.is_vip, m.home_team, m.away_team, m.kickoff_at FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ? AND t.deleted_at IS NULL');
    $st->execute([$tipId]);
    $t = $st->fetch();
    if (!$t || !$t['is_vip']) { return 0; }
    $n = 0;
    try {
        $ids = db()->query("SELECT DISTINCT user_id FROM subscriptions WHERE status = 'active' AND ends_at > NOW() LIMIT 1500")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return 0; }
    foreach ($ids as $u) {
        if (alert_enqueue((int)$u, 'vip', 'New VIP tip posted', $t['home_team'] . ' vs ' . $t['away_team'] . ', kickoff ' . date('D H:i', strtotime($t['kickoff_at'])) . '. Open it now.', 'tip.php?id=' . $tipId, 'vp-' . $tipId)) { $n++; }
    }
    return $n;
}

/** Saved (bell) tips: kickoff in about an hour. */
function alerts_saved_kickoff(): int
{
    if (!function_exists('tipx_ready') || !tipx_ready()) { return 0; }
    $rows = db()->query("SELECT f.user_id, t.id, m.home_team, m.away_team, m.kickoff_at FROM tip_follows f JOIN tips t ON t.id = f.tip_id JOIN matches m ON m.id = t.match_id
                         WHERE t.deleted_at IS NULL AND m.kickoff_at > NOW() + INTERVAL 40 MINUTE AND m.kickoff_at <= NOW() + INTERVAL 80 MINUTE LIMIT 800")->fetchAll();
    $n = 0;
    foreach ($rows as $r) {
        if (alert_enqueue((int)$r['user_id'], 'kickoff', 'Your saved tip kicks off soon', $r['home_team'] . ' vs ' . $r['away_team'] . ' starts at ' . date('H:i', strtotime($r['kickoff_at'])) . '.', 'tip.php?id=' . $r['id'], 'sk-' . $r['id'])) { $n++; }
    }
    return $n;
}

/** About 90 minutes before the 2 Odds Streak locks (first kickoff of the day): reminder for members who joined yesterday's run. */
function alerts_streak_reminder(): int
{
    if (!function_exists('streak_ready') || !streak_ready()) { return 0; }
    $d = db()->query("SELECT id, kickoff_at FROM streak_days WHERE status = 'pending' AND kickoff_at > NOW() ORDER BY kickoff_at LIMIT 1")->fetch();
    if (!$d) { return 0; }
    $lock = strtotime($d['kickoff_at']);
    if (!$lock || $lock < time() + 1200 || $lock > time() + 6000) { return 0; }
    $ids = db()->query("SELECT DISTINCT user_id FROM streak_joins ORDER BY id DESC LIMIT 800")->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $u) {
        if (alert_enqueue((int)$u, 'kickoff', '2 Odds Streak locks soon', 'Join today\'s streak before ' . date('H:i', $lock) . '.', 'streak.php', 'st-' . $d['id'])) { $n++; }
    }
    return $n;
}

/** Members away for 3+ days get one "you missed these tips" nudge (at most every 5 days). */
function alerts_missed_nudge(): int
{
    if (!alerts2_ready()) { return 0; }
    $n = 0;
    try {
        $rows = db()->query("SELECT user_id AS id FROM alert_extra
            WHERE last_active < NOW() - INTERVAL 3 DAY AND last_active > NOW() - INTERVAL 30 DAY AND (last_missed IS NULL OR last_missed < NOW() - INTERVAL 5 DAY) LIMIT 150")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return 0; }
    $won = 0; $tot = 0;
    try { $r = db()->query("SELECT SUM(status='won'), COUNT(*) FROM tips WHERE deleted_at IS NULL AND is_vip = 0 AND status IN ('won','lost') AND posted_at > NOW() - INTERVAL 3 DAY")->fetch(PDO::FETCH_NUM); $won = (int)$r[0]; $tot = (int)$r[1]; } catch (Throwable $e) { }
    foreach ($rows as $u) {
        $msg = $tot ? "While you were away $won of $tot free tips won. Come and see today's picks." : "New tips are waiting for you.";
        if (alert_enqueue((int)$u, 'tip', 'You missed these tips', $msg, 'tips.php', 'ms-' . (int)$u . '-' . date('oW'))) { $n++; }
        db()->prepare('INSERT INTO alert_extra (user_id, last_missed) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_missed = NOW()')->execute([(int)$u]);
    }
    return $n;
}

function alerts2_run(): void
{
    foreach (['alerts_saved_kickoff', 'alerts_streak_reminder', 'alerts_missed_nudge', 'bulk_scheduled_run'] as $f) {
        try { $f(); } catch (Throwable $e) { error_log($f . ': ' . $e->getMessage()); }
    }
}

/* ---------- inbox helpers ---------- */
function notif_unread_count(int $uid): int
{
    try { $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'); $st->execute([$uid]); return (int)$st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/** Admin urgent banner text (empty when off or expired). */
function urgent_banner(): string
{
    $t = trim((string)setting('urgent_banner', ''));
    $until = (int)setting('urgent_banner_until', '0');
    return ($t !== '' && ($until === 0 || $until > time())) ? $t : '';
}

/** Records that a member was active (at most once an hour per session). Used by the "you missed these tips" nudge. */
function alert_touch(int $uid): void
{
    if (!empty($_SESSION['ax_t']) && $_SESSION['ax_t'] > time() - 3600) { return; }
    $_SESSION['ax_t'] = time();
    try {
        if (alerts2_ready()) {
            $tz = (string)($_COOKIE['sk_tz'] ?? '');
            if ($tz === '' || !in_array($tz, timezone_identifiers_list(), true)) { $tz = null; }
            db()->prepare('INSERT INTO alert_extra (user_id, last_active, tz) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE last_active = NOW(), tz = COALESCE(VALUES(tz), tz)')->execute([$uid, $tz]);
        }
    } catch (Throwable $e) { }
}

/* ---------- emoji reactions on tips ---------- */
function tip_react_emojis(): array { return ['👍', '🔥', '😮', '😢', '⚽']; }

/** Toggles/changes a member's reaction. Returns ['counts'=>[emoji=>n], 'mine'=>emoji] or null if the tip is missing. */
function tip_react(int $uid, int $tipId, string $emoji): ?array
{
    if (!alerts2_ready()) { return null; }
    $q = db()->prepare('SELECT 1 FROM tips WHERE id = ? AND deleted_at IS NULL'); $q->execute([$tipId]);
    if (!$q->fetchColumn()) { return null; }
    if (in_array($emoji, tip_react_emojis(), true)) {
        $cur = db()->prepare('SELECT emoji FROM tip_reactions WHERE tip_id = ? AND user_id = ?'); $cur->execute([$tipId, $uid]);
        if ($cur->fetchColumn() === $emoji) { db()->prepare('DELETE FROM tip_reactions WHERE tip_id = ? AND user_id = ?')->execute([$tipId, $uid]); }
        else { db()->prepare('INSERT INTO tip_reactions (tip_id, user_id, emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = NOW()')->execute([$tipId, $uid, $emoji]); }
    }
    return tip_reactions_of($tipId, $uid);
}

function tip_reactions_of(int $tipId, int $uid = 0): array
{
    $counts = []; $mine = '';
    if (alerts2_ready()) {
        $st = db()->prepare('SELECT emoji, COUNT(*) n FROM tip_reactions WHERE tip_id = ? GROUP BY emoji'); $st->execute([$tipId]);
        foreach ($st->fetchAll() as $r) { $counts[$r['emoji']] = (int)$r['n']; }
        if ($uid) { $m = db()->prepare('SELECT emoji FROM tip_reactions WHERE tip_id = ? AND user_id = ?'); $m->execute([$tipId, $uid]); $mine = (string)$m->fetchColumn(); }
    }
    return ['counts' => $counts, 'mine' => $mine];
}
