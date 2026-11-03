<?php
/**
 * Public member profiles: a member can share the bets from their private tracker (account/tracker.php) on a public page,
 * and other members can follow them. Bets are self-reported by the member and are NOT verified by SlipKing.
 */

const MEMBER_RESERVED = ['admin', 'administrator', 'support', 'slipking', 'team', 'staff', 'moderator', 'mod', 'help', 'official', 'system', 'root', 'null'];

function members_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    return $ok = (sk_ensure_table('member_public', 'database-update-21.sql') && sk_ensure_table('member_follows', 'database-update-21.sql')
        && sk_ensure_column('user_bets', 'is_public', 'TINYINT(1) NOT NULL DEFAULT 0'));
}

function member_handle_error(string $h, int $ownUid = 0): string
{
    if (!preg_match('/^[a-z0-9_]{3,20}$/', $h)) { return 'The username must be 3 to 20 characters: lowercase letters, numbers and underscore.'; }
    if (in_array($h, MEMBER_RESERVED, true)) { return 'That username is not available.'; }
    $q = db()->prepare('SELECT user_id FROM member_public WHERE handle = ?'); $q->execute([$h]);
    $uid = $q->fetchColumn();
    if ($uid !== false && (int)$uid !== $ownUid) { return 'That username is already taken.'; }
    return '';
}

function member_row_for_user(int $uid): ?array
{
    $q = db()->prepare('SELECT * FROM member_public WHERE user_id = ?'); $q->execute([$uid]);
    return $q->fetch() ?: null;
}

function member_by_handle(string $h): ?array
{
    if (!members_ready() || !preg_match('/^[a-z0-9_]{3,20}$/', $h)) { return null; }
    $q = db()->prepare('SELECT mp.*, u.name, u.country' . (profile_cols() ? ', u.avatar, u.nationality, u.show_flag' : '') . ', u.role
                        FROM member_public mp JOIN users u ON u.id = mp.user_id WHERE mp.handle = ? AND mp.is_public = 1');
    $q->execute([$h]);
    return $q->fetch() ?: null;
}

/** Counts for the public record, from the bets the member chose to share. Units use a flat 1-unit stake, so they do not depend on money amounts. */
function member_stats(int $uid, bool $showStakes): array
{
    $q = db()->prepare("SELECT status, odds, stake, placed_at FROM user_bets WHERE user_id = ? AND is_public = 1 AND deleted_at IS NULL ORDER BY COALESCE(settled_at, placed_at) ASC, id ASC LIMIT 2000");
    $q->execute([$uid]);
    $rows = $q->fetchAll();
    $w = $l = $v = $p = 0; $units = 0.0; $oddsSum = 0.0; $profit = 0.0; $staked = 0.0; $form = []; $streak = 0; $streakType = '';
    foreach ($rows as $b) {
        $s = $b['status']; $o = (float)$b['odds'];
        if ($s === 'won') { $w++; $units += $o - 1; $oddsSum += $o; $profit += (float)$b['stake'] * ($o - 1); $staked += (float)$b['stake']; }
        elseif ($s === 'lost') { $l++; $units -= 1; $oddsSum += $o; $profit -= (float)$b['stake']; $staked += (float)$b['stake']; }
        elseif ($s === 'void') { $v++; } else { $p++; }
        if ($s === 'won' || $s === 'lost') {
            $form[] = $s === 'won' ? 'W' : 'L';
            if ($streakType === $s) { $streak++; } else { $streakType = $s; $streak = 1; }
        }
    }
    $settled = $w + $l;
    $rm = db()->prepare('SELECT COUNT(*) FROM user_bets WHERE user_id = ? AND is_public = 1 AND deleted_at IS NOT NULL AND deleted_at > NOW() - INTERVAL 30 DAY');
    $rm->execute([$uid]);
    return ['won' => $w, 'lost' => $l, 'void' => $v, 'pending' => $p, 'settled' => $settled, 'units' => round($units, 2),
        'win_rate' => $settled ? round($w / $settled * 100, 1) : null, 'avg_odds' => $settled ? round($oddsSum / $settled, 2) : null,
        'roi' => $settled ? round($units / $settled * 100, 1) : null,
        'form' => array_slice($form, -10), 'streak' => $streak, 'streak_type' => $streakType,
        'profit' => $showStakes ? round($profit, 2) : null, 'staked' => $showStakes ? round($staked, 2) : null,
        'removed' => (int)$rm->fetchColumn(), 'total' => count($rows)];
}

function member_followers(int $uid): int
{
    $q = db()->prepare('SELECT COUNT(*) FROM member_follows WHERE member_id = ?'); $q->execute([$uid]);
    return (int)$q->fetchColumn();
}

function member_is_following(int $viewer, int $uid): bool
{
    if ($viewer <= 0) { return false; }
    $q = db()->prepare('SELECT 1 FROM member_follows WHERE follower_id = ? AND member_id = ?'); $q->execute([$viewer, $uid]);
    return (bool)$q->fetchColumn();
}

/** Tells the followers of a member that a new bet was shared (at most 300 people). */
function member_notify_followers(int $uid, string $handle, string $desc, float $odds): void
{
    try {
        $q = db()->prepare('SELECT follower_id FROM member_follows WHERE member_id = ? LIMIT 300'); $q->execute([$uid]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            cmty_alert((int)$fid, '@' . $handle . ' shared a new bet', mb_substr($desc, 0, 120) . ' @ ' . number_format($odds, 2) . '. Tap to see it on their profile.', 'member.php?u=' . $handle);
        }
    } catch (Throwable $e) { error_log('member_notify_followers: ' . $e->getMessage()); }
}

/** Top public members. $sort: units | winrate | followers. Needs at least $min settled shared bets. */
function members_top(string $sort, int $min = 5, int $limit = 50, string $q = ''): array
{
    if (!members_ready()) { return []; }
    $order = ['units' => 'units DESC, settled DESC', 'winrate' => 'win_rate DESC, settled DESC', 'followers' => 'followers DESC, units DESC'][$sort] ?? 'units DESC, settled DESC';
    $sql = "SELECT mp.user_id, mp.handle, mp.bio, u.name" . (profile_cols() ? ', u.avatar, u.nationality, u.show_flag, u.country' : '') . ",
              SUM(b.status IN ('won','lost')) AS settled, SUM(b.status = 'won') AS w,
              ROUND(100 * SUM(b.status = 'won') / NULLIF(SUM(b.status IN ('won','lost')), 0), 1) AS win_rate,
              SUM(CASE WHEN b.status = 'won' THEN b.odds - 1 WHEN b.status = 'lost' THEN -1 ELSE 0 END) AS units,
              (SELECT COUNT(*) FROM member_follows f WHERE f.member_id = mp.user_id) AS followers
            FROM member_public mp JOIN users u ON u.id = mp.user_id
            LEFT JOIN user_bets b ON b.user_id = mp.user_id AND b.is_public = 1 AND b.deleted_at IS NULL
            WHERE mp.is_public = 1" . ($q !== '' ? ' AND mp.handle LIKE ?' : '') . "
            GROUP BY mp.user_id, mp.handle, mp.bio, u.name" . (profile_cols() ? ', u.avatar, u.nationality, u.show_flag, u.country' : '') . "
            HAVING settled >= " . (int)$min . " ORDER BY $order LIMIT " . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($q !== '' ? ['%' . str_replace(['%', '_'], ['\\%', '\\_'], strtolower($q)) . '%'] : []);
    return $st->fetchAll();
}

/** 'W' / 'L' form dots. */
function member_form_html(array $form): string
{
    $h = '';
    foreach ($form as $f) { $h .= '<i class="mf ' . ($f === 'W' ? 'w' : 'l') . '" title="' . ($f === 'W' ? 'Won' : 'Lost') . '">' . $f . '</i>'; }
    return $h === '' ? '<span class="mf-none">No settled bets yet</span>' : $h;
}

function member_flag(array $m, int $h = 14): string
{
    return function_exists('flag_iso') ? flag_html(flag_iso($m), $h) : '';
}
