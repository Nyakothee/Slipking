<?php
/** Account extras: bans with reason + appeal, shared-account detection, daily login points, friends leaderboard. */

function acct2_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            if (setting('acct2_ready', '0') === '1') { return $ok = true; }
            $ok = sk_ensure_column('users', 'banned_at', 'DATETIME NULL DEFAULT NULL')
               && sk_ensure_column('users', 'ban_reason', "VARCHAR(300) NOT NULL DEFAULT ''")
               && sk_ensure_table('ban_appeals', 'database-update-42.sql')
               && sk_ensure_table('login_devices', 'database-update-42.sql');
            if ($ok) { set_setting('acct2_ready', '1'); }
        } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Returns the ban reason if the member is banned, else null. */
function acct_ban(int $uid): ?string
{
    if (!acct2_ready()) { return null; }
    $st = db()->prepare('SELECT banned_at, ban_reason FROM users WHERE id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    return ($r && $r['banned_at']) ? ((string)$r['ban_reason'] !== '' ? (string)$r['ban_reason'] : 'Breaking the site rules.') : null;
}

function acct_ban_set(int $uid, string $reason): void
{
    if (!acct2_ready()) { return; }
    db()->prepare("UPDATE users SET banned_at = NOW(), ban_reason = ? WHERE id = ? AND role = 'user'")->execute([mb_substr($reason, 0, 300), $uid]);
}

function acct_ban_clear(int $uid): void
{
    if (!acct2_ready()) { return; }
    db()->prepare("UPDATE users SET banned_at = NULL, ban_reason = '' WHERE id = ?")->execute([$uid]);
    notify_user($uid, 'Your account is back', 'Your account was restored. Welcome back.', 'account/dashboard.php');
}

/** Called at every sign-in: daily login points, device record, shared-account flag. */
function acct_on_login(int $uid): void
{
    try {
        $pts = (int)setting('game_points_login', '1');
        if ($pts > 0 && function_exists('game_points_add')) { game_points_add(db(), $uid, $pts, 'login', 'L' . date('Ymd')); }
        if (!acct2_ready()) { return; }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $dev = substr(hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . implode('.', array_slice(explode('.', $ip), 0, 2))), 0, 16);
        db()->prepare('INSERT INTO login_devices (user_id, dev, ip) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_seen = NOW(), ip = VALUES(ip)')->execute([$uid, $dev, substr($ip, 0, 45)]);
        $n = db()->prepare('SELECT COUNT(*) FROM login_devices WHERE user_id = ? AND last_seen > NOW() - INTERVAL 24 HOUR');
        $n->execute([$uid]);
        $max = max(2, (int)setting('shared_account_devices', '4'));
        if ((int)$n->fetchColumn() >= $max) {
            $key = 'shared-' . date('Ymd');
            $seen = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'Is someone else using your account?' AND created_at > NOW() - INTERVAL 3 DAY");
            $seen->execute([$uid]);
            if (!(int)$seen->fetchColumn()) {
                notify_user($uid, 'Is someone else using your account?', 'Your account was used on several devices today. If this was not you, change your password now. Sharing one account is not allowed.', 'account/security.php');
                if (function_exists('paytools_ready') && paytools_ready()) { /* flag lives in login_devices; admin page reads it */ }
            }
        }
    } catch (Throwable $e) { error_log('acct_on_login: ' . $e->getMessage()); }
}

/** Members seen on many devices in 24h (for the admin page). */
function acct_shared_suspects(int $min = 4, int $limit = 100): array
{
    if (!acct2_ready()) { return []; }
    $st = db()->prepare('SELECT d.user_id, u.name, u.email, COUNT(*) AS devices, COUNT(DISTINCT SUBSTRING_INDEX(d.ip, \'.\', 2)) AS nets, MAX(d.last_seen) AS last_seen
                         FROM login_devices d JOIN users u ON u.id = d.user_id WHERE d.last_seen > NOW() - INTERVAL 24 HOUR
                         GROUP BY d.user_id, u.name, u.email HAVING devices >= ? ORDER BY devices DESC LIMIT ' . (int)$limit);
    $st->execute([$min]);
    return $st->fetchAll();
}

/** Game leaderboard restricted to the member and the people they follow. */
function game_leaderboard_friends(int $uid, string $type = 'all', int $limit = 50): array
{
    $ids = [$uid];
    try { $q = db()->prepare('SELECT member_id FROM member_follows WHERE follower_id = ?'); $q->execute([$uid]); $ids = array_merge($ids, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))); } catch (Throwable $e) { }
    $ids = array_values(array_unique($ids));
    $in = implode(',', array_map('intval', $ids));
    $where = '';
    $args = [];
    if ($type !== 'all') { $p = game_period($type, 0); $where = 'AND g.created_at >= ? AND g.created_at < ?'; $args = [$p['start'], $p['end']]; }
    $st = db()->prepare("SELECT g.user_id, u.name" . profile_sql('u') . ", SUM(g.points) AS points, SUM(g.reason = 'pick') AS correct
                         FROM game_points g JOIN users u ON u.id = g.user_id WHERE g.user_id IN ($in) $where
                         GROUP BY g.user_id, u.name" . profile_group_sql('u') . " HAVING points > 0 ORDER BY points DESC, correct DESC, g.user_id ASC LIMIT " . (int)$limit);
    $st->execute($args);
    return $st->fetchAll();
}
