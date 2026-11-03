<?php
/**
 * Free prediction game, streaks, badges and final scores.
 * No money is involved: points only, small prizes are free VIP days given by the site.
 */

/** "John Smith" -> "John S." so public lists never show full names. */
function mask_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') {
        return 'Member';
    }
    $parts = explode(' ', $name);
    $first = mb_substr($parts[0], 0, 14);
    return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $first;
}

/** 1, X or 2 for a score. */
function game_result_code(int $home, int $away): string
{
    return $home > $away ? '1' : ($home < $away ? '2' : 'X');
}

function game_points_correct(): int
{
    return max(1, (int)setting('game_points_correct', '3'));
}

/** Writes one line to the points ledger. The UNIQUE key makes it safe to run twice. Returns true if it was new. */
function game_points_add(PDO $pdo, int $userId, int $points, string $reason, string $ref): bool
{
    $st = $pdo->prepare('INSERT IGNORE INTO game_points (user_id, points, reason, ref) VALUES (?, ?, ?, ?)');
    $st->execute([$userId, $points, substr($reason, 0, 20), substr($ref, 0, 30)]);
    return $st->rowCount() === 1;
}

/**
 * Stores the final score of a match, marks it finished and settles every open game pick.
 * Safe to call again: nothing is paid twice. Returns the number of picks settled now.
 */
function match_set_final(PDO $pdo, int $matchId, int $home, int $away): int
{
    $pdo->prepare("UPDATE matches SET home_score = ?, away_score = ?, live_status = 'FT', score_updated_at = NOW() WHERE id = ?")
        ->execute([max(0, min(99, $home)), max(0, min(99, $away)), $matchId]);
    return game_settle_match($pdo, $matchId, $home, $away);
}

function game_settle_match(PDO $pdo, int $matchId, int $home, int $away): int
{
    $res = game_result_code($home, $away);
    $pts = game_points_correct();
    $st = $pdo->prepare("SELECT id, user_id, pick FROM game_picks WHERE match_id = ? AND status = 'open'");
    $st->execute([$matchId]);
    $n = 0;
    foreach ($st->fetchAll() as $p) {
        $won = $p['pick'] === $res;
        $up = $pdo->prepare("UPDATE game_picks SET status = ? WHERE id = ? AND status = 'open'");
        $up->execute([$won ? 'won' : 'lost', $p['id']]);
        if ($up->rowCount() !== 1) {
            continue;
        }
        $n++;
        if ($won) {
            game_points_add($pdo, (int)$p['user_id'], $pts, 'pick', (string)$matchId);
        }
        game_after_settle((int)$p['user_id']);
    }
    return $n;
}

/** A void match (postponed, abandoned): picks are cancelled, nobody gains or loses. */
function game_void_match(PDO $pdo, int $matchId): int
{
    $st = $pdo->prepare("UPDATE game_picks SET status = 'void' WHERE match_id = ? AND status = 'open'");
    $st->execute([$matchId]);
    $pdo->prepare("UPDATE matches SET live_status = 'PST', score_updated_at = NOW() WHERE id = ?")->execute([$matchId]);
    return $st->rowCount();
}

/** Badges that depend on how many picks a member has had settled. */
function game_after_settle(int $userId): void
{
    $st = db()->prepare("SELECT COUNT(*) FROM game_picks WHERE user_id = ? AND status IN ('won','lost')");
    $st->execute([$userId]);
    $n = (int)$st->fetchColumn();
    if ($n >= 10) {
        badge_award($userId, 'picks_10');
    }
}

/* ---------- periods and leaderboard ---------- */

/** Start, end (exclusive), key and label for 'week' (Monday to Sunday) or 'month'. $offset: 0 = this period, -1 = the previous one. */
function game_period(string $type, int $offset = 0): array
{
    if ($type === 'month') {
        $start = strtotime(date('Y-m-01 00:00:00') . ' ' . ($offset >= 0 ? '+' : '') . $offset . ' month');
        $end   = strtotime('+1 month', $start);
        return ['start' => date('Y-m-d H:i:s', $start), 'end' => date('Y-m-d H:i:s', $end), 'key' => date('Y-m', $start), 'label' => date('F Y', $start)];
    }
    $monday = strtotime('monday this week 00:00:00');
    $start  = strtotime(($offset >= 0 ? '+' : '') . $offset . ' week', $monday);
    $end    = strtotime('+1 week', $start);
    return ['start' => date('Y-m-d H:i:s', $start), 'end' => date('Y-m-d H:i:s', $end), 'key' => date('o-\WW', $start),
            'label' => date('j M', $start) . ' - ' . date('j M Y', $end - 86400)];
}

/**
 * Leaderboard rows: [user_id, name, points, correct, picks]. $type = 'week', 'month' or 'all'.
 * Points come from the ledger, so they cannot change after the fact.
 */
function game_leaderboard(string $type, int $offset = 0, int $limit = 50): array
{
    $pdo = db();
    $where = ''; $args = [];
    if ($type !== 'all') {
        $p = game_period($type, $offset);
        $where = 'AND g.created_at >= ? AND g.created_at < ?';
        $args = [$p['start'], $p['end']];
    }
    $st = $pdo->prepare("SELECT g.user_id, u.name" . profile_sql('u') . ", SUM(g.points) AS points, SUM(g.reason = 'pick') AS correct
                         FROM game_points g JOIN users u ON u.id = g.user_id
                         WHERE u.role = 'user' $where
                         GROUP BY g.user_id, u.name" . profile_group_sql('u') . " HAVING points > 0
                         ORDER BY points DESC, correct DESC, g.user_id ASC LIMIT " . (int)$limit);
    $st->execute($args);
    return $st->fetchAll();
}

function game_user_points(int $userId, string $type = 'all'): int
{
    $sql = 'SELECT COALESCE(SUM(points), 0) FROM game_points WHERE user_id = ?';
    $args = [$userId];
    if ($type !== 'all') {
        $p = game_period($type, 0);
        $sql .= ' AND created_at >= ? AND created_at < ?';
        $args[] = $p['start'];
        $args[] = $p['end'];
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    return (int)$st->fetchColumn();
}

/** Settled picks a member had in a period (needed for the prize minimum). */
function game_settled_picks(int $userId, string $start, string $end): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM game_picks p JOIN matches m ON m.id = p.match_id
                         WHERE p.user_id = ? AND p.status IN ('won','lost') AND m.kickoff_at >= ? AND m.kickoff_at < ?");
    $st->execute([$userId, $start, $end]);
    return (int)$st->fetchColumn();
}

/**
 * Gives the prize (free VIP days, plan 'bonus') to the top members of a finished period. Safe to run twice.
 * Setting "game_weekly_prizes" / "game_monthly_prizes" is a comma list of days for rank 1, 2, 3...
 * Returns a list of lines describing what happened.
 */
function game_award_prizes(string $type, int $offset = -1): array
{
    $pdo = db();
    $p = game_period($type, $offset);
    if (strtotime($p['end']) > time()) {
        return ['That period has not finished yet.'];
    }
    $prizes = array_values(array_filter(array_map('intval', explode(',', (string)setting($type === 'month' ? 'game_monthly_prizes' : 'game_weekly_prizes', ''))), function ($d) { return $d > 0; }));
    if (!$prizes) {
        return ['No prizes are set for this period type.'];
    }
    $min = max(1, (int)setting('game_min_picks', '5'));
    $st = $pdo->prepare("SELECT id FROM plans WHERE slug = 'bonus' LIMIT 1");
    $st->execute();
    $bonusId = (int)$st->fetchColumn();
    if (!$bonusId) {
        return ['The "bonus" plan is missing, so VIP days cannot be given.'];
    }
    $out = [];
    $rank = 0;
    foreach (game_leaderboard($type, $offset, 40) as $row) {
        if ($rank >= count($prizes)) {
            break;
        }
        if (game_settled_picks((int)$row['user_id'], $p['start'], $p['end']) < $min) {
            continue;       // not enough picks to qualify
        }
        $rank++;
        $days = $prizes[$rank - 1];
        $ins = $pdo->prepare('INSERT IGNORE INTO game_prizes (period_type, period_key, rank_no, user_id, points, days) VALUES (?,?,?,?,?,?)');
        $ins->execute([$type, $p['key'], $rank, $row['user_id'], (int)$row['points'], $days]);
        if ($ins->rowCount() !== 1) {
            $out[] = '#' . $rank . ' ' . mask_name($row['name']) . ': already awarded.';
            continue;
        }
        grant_vip((int)$row['user_id'], $bonusId, $days);
        notify_user((int)$row['user_id'], 'You won a prediction game prize',
            'You finished #' . $rank . ' in the ' . ($type === 'month' ? 'monthly' : 'weekly') . ' prediction game (' . $p['label'] . ') with ' . (int)$row['points'] . ' points. We added ' . $days . ' free VIP days to your account.');
        $out[] = '#' . $rank . ' ' . mask_name($row['name']) . ': ' . $days . ' VIP days.';
    }
    return $out ?: ['Nobody qualified (a member needs at least ' . $min . ' settled picks).'];
}

/* ---------- matches the game offers ---------- */

/** Football-style 1X2 matches kicking off in the next 72 hours, for the Pick tab. */
function game_open_matches(int $hours = 72): array
{
    $st = db()->prepare("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league
                         FROM matches m JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
                         WHERE m.kickoff_at > NOW() AND m.kickoff_at < NOW() + INTERVAL " . (int)$hours . " HOUR
                           AND s.name LIKE 'Football%' AND (m.live_status IS NULL OR m.live_status NOT IN ('PST','CANC'))
                         ORDER BY m.kickoff_at ASC, m.id ASC LIMIT 60");
    $st->execute();
    return $st->fetchAll();
}

/** Picks are accepted until one minute before kickoff. */
function game_can_pick(array $match): bool
{
    return strtotime($match['kickoff_at']) - time() >= 60;
}

/* ---------- streaks and badges ---------- */

function badge_catalog(): array
{
    return [
        'first_pick'  => ['First prediction',   'Made your first pick in the game.',       'target',  0],
        'picks_10'    => ['Ten settled picks',   'Ten of your picks have been settled.',    'ten',     5],
        'streak_7'    => ['7-day streak',        'Visited seven days in a row.',            'flame',   5],
        'streak_30'   => ['30-day streak',       'Visited thirty days in a row.',           'flame2',  20],
        'first_review'=> ['First review',        'Rated a tipster you subscribed to.',      'star',    0],
        'first_follow'=> ['First follow',        'Followed your first tipster.',            'follow',  0],
        'first_comment'=> ['First comment',      'Joined the conversation.',                'chat',    0],
        'ambassador'  => ['Ambassador',          'A friend you invited became a VIP.',      'gift',    10],
        'top3_week'   => ['Weekly top 3',        'Finished in the top 3 of a weekly game.', 'trophy',  0],
    ];
}

/** Gives a badge once. Bonus points (if any) go to the ledger. Returns true if it was new. */
function badge_award(int $userId, string $code, string $path = ''): bool
{
    $cat = badge_catalog();
    if (!isset($cat[$code])) {
        return false;
    }
    try {
        $pdo = db();
        $st = $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, code) VALUES (?, ?)');
        $st->execute([$userId, $code]);
        if ($st->rowCount() !== 1) {
            return false;
        }
        if ($cat[$code][3] > 0) {
            game_points_add($pdo, $userId, $cat[$code][3], 'badge', $code);
        }
        notify_user($userId, 'New badge: ' . $cat[$code][0], $cat[$code][1] . ($cat[$code][3] > 0 ? ' You earned ' . $cat[$code][3] . ' game points.' : ''), $path !== '' ? $path : ($code === 'first_comment' ? 'discuss.php' : 'account/badges.php'));
        return true;
    } catch (Throwable $e) {
        error_log('badge_award: ' . $e->getMessage());
        return false;
    }
}

function user_badges(int $userId): array
{
    $st = db()->prepare('SELECT code, awarded_at FROM user_badges WHERE user_id = ? ORDER BY awarded_at ASC');
    $st->execute([$userId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['code']] = $r['awarded_at'];
    }
    return $out;
}

/**
 * Counts a visit for the day streak. Called once per session from the page header for logged-in members.
 * Same day: nothing. Next day: streak + 1. Missed a day: back to 1.
 */
function streak_touch(int $userId): void
{
    if (!empty($_SESSION['streak_day']) && $_SESSION['streak_day'] === date('Y-m-d')) {
        return;
    }
    $_SESSION['streak_day'] = date('Y-m-d');
    try {
        $pdo = db();
        $today = date('Y-m-d');
        $st = $pdo->prepare('SELECT current_streak, best_streak, last_day FROM user_streaks WHERE user_id = ?');
        $st->execute([$userId]);
        $r = $st->fetch();
        if (!$r) {
            $pdo->prepare('INSERT IGNORE INTO user_streaks (user_id, current_streak, best_streak, last_day) VALUES (?, 1, 1, ?)')->execute([$userId, $today]);
            return;
        }
        if ($r['last_day'] === $today) {
            return;
        }
        $cur = ($r['last_day'] === date('Y-m-d', strtotime('-1 day'))) ? (int)$r['current_streak'] + 1 : 1;
        $best = max((int)$r['best_streak'], $cur);
        $pdo->prepare('UPDATE user_streaks SET current_streak = ?, best_streak = ?, last_day = ? WHERE user_id = ?')->execute([$cur, $best, $today, $userId]);
        if ($cur >= 7) { badge_award($userId, 'streak_7'); }
        if ($cur >= 30) { badge_award($userId, 'streak_30'); }
    } catch (Throwable $e) {
        error_log('streak_touch: ' . $e->getMessage());
    }
}

function user_streak(int $userId): array
{
    $st = db()->prepare('SELECT current_streak, best_streak, last_day FROM user_streaks WHERE user_id = ?');
    $st->execute([$userId]);
    $r = $st->fetch() ?: ['current_streak' => 0, 'best_streak' => 0, 'last_day' => null];
    // A streak that was not continued yesterday or today has already ended.
    if ($r['last_day'] && $r['last_day'] < date('Y-m-d', strtotime('-1 day'))) {
        $r['current_streak'] = 0;
    }
    return $r;
}

/** Small inline icon for a badge (plain symbols, no image files needed). */
function badge_icon(string $key): string
{
    $map = ['target' => '&#127919;', 'ten' => '&#128287;', 'flame' => '&#128293;', 'flame2' => '&#127775;', 'star' => '&#11088;',
            'follow' => '&#10133;', 'chat' => '&#128172;', 'gift' => '&#127873;', 'trophy' => '&#127942;'];
    return $map[$key] ?? '&#127941;';
}

/* ---------- referral statistics (affiliate dashboard and leaderboard) ---------- */

/** Top referrers: [user_id, name, joined, paid]. $period 'month' or 'all'. Only people with at least one paid friend. */
function referral_leaderboard(string $period = 'all', int $limit = 30): array
{
    $where = $period === 'month' ? 'AND f.created_at >= ' . "'" . date('Y-m-01 00:00:00') . "'" : '';
    $st = db()->query("SELECT r.id AS user_id, r.name, COUNT(*) AS joined,
                              SUM(EXISTS(SELECT 1 FROM payments py WHERE py.user_id = f.id AND py.status = 'paid')) AS paid
                       FROM users f JOIN users r ON r.id = f.referred_by
                       WHERE r.role = 'user' $where
                       GROUP BY r.id, r.name HAVING paid > 0
                       ORDER BY paid DESC, joined DESC, r.id ASC LIMIT " . (int)$limit);
    return $st->fetchAll();
}

/** Stats for one referrer: joined, paid, days earned, rank by paid friends (null if none paid). */
function referral_stats(int $userId): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) AS joined, COALESCE(SUM(EXISTS(SELECT 1 FROM payments py WHERE py.user_id = u.id AND py.status = 'paid')), 0) AS paid
                         FROM users u WHERE u.referred_by = ?");
    $st->execute([$userId]);
    $r = $st->fetch() ?: ['joined' => 0, 'paid' => 0];
    $st = $pdo->prepare('SELECT COUNT(*) FROM referrals WHERE referrer_id = ? AND reward_given = 1');
    $st->execute([$userId]);
    $rewards = (int)$st->fetchColumn();
    $rank = null;
    if ((int)$r['paid'] > 0) {
        foreach (referral_leaderboard('all', 200) as $i => $row) {
            if ((int)$row['user_id'] === $userId) { $rank = $i + 1; break; }
        }
    }
    return ['joined' => (int)$r['joined'], 'paid' => (int)$r['paid'], 'rewards' => $rewards, 'days' => $rewards * max(0, (int)setting('referral_reward_days', '3')), 'rank' => $rank];
}
