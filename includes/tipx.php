<?php
/** Extras for tip cards: recent form, "on track" progress for goals picks, followed tips (bell), live statistics. */

function tipx_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = function_exists('sk_ensure_table') && sk_ensure_table('tip_follows', 'database-update-39.sql') && sk_ensure_table('match_stats', 'database-update-39.sql') && sk_ensure_table('lineup_alerts', 'database-update-39.sql'); }
    return $ok;
}

/** Last five results of a team as ['W','D','L', ...] (newest first), from finished matches on this site. Cached for 10 minutes. */
function team_form(string $team): array
{
    static $memo = [];
    $team = trim($team);
    if ($team === '') { return []; }
    if (isset($memo[$team])) { return $memo[$team]; }
    $make = function () use ($team) {
        $st = db()->prepare("SELECT home_team, away_team, home_score, away_score FROM matches WHERE (home_team = ? OR away_team = ?) AND live_status IN ('FT','AET','PEN') AND home_score IS NOT NULL AND away_score IS NOT NULL ORDER BY kickoff_at DESC LIMIT 5");
        $st->execute([$team, $team]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $mine = strcasecmp($r['home_team'], $team) === 0 ? (int)$r['home_score'] : (int)$r['away_score'];
            $opp = strcasecmp($r['home_team'], $team) === 0 ? (int)$r['away_score'] : (int)$r['home_score'];
            $out[] = $mine > $opp ? 'W' : ($mine < $opp ? 'L' : 'D');
        }
        return $out;
    };
    try { $memo[$team] = function_exists('cache_remember') ? cache_remember('form_' . md5(mb_strtolower($team)), 600, $make) : $make(); }
    catch (Throwable $e) { $memo[$team] = []; }
    return $memo[$team];
}
function form_dots_html(string $team): string
{
    $f = team_form($team);
    if (!$f) { return ''; }
    $h = '<span class="fm" title="Last ' . count($f) . ' results (newest first)">';
    foreach ($f as $x) { $h .= '<i class="fm-' . $x . '">' . $x . '</i>'; }
    return $h . '</span>';
}

/**
 * "On track" for a goals pick while the game is on or finished. Returns null if the pick is not an Over/Under goals pick or no score is known.
 * ['label' => '2 of 3 goals', 'pct' => 0-100, 'state' => 'on'|'won'|'lost'|'live']
 */
function tip_progress(array $t): ?array
{
    if (!isset($t['home_score']) || $t['home_score'] === null || !isset($t['away_score']) || $t['away_score'] === null) { return null; }
    $goals = (int)$t['home_score'] + (int)$t['away_score'];
    $pick = (string)($t['pick'] ?? '');
    $fin = function_exists('live_is_finished') && live_is_finished((string)($t['live_status'] ?? ''));
    if (preg_match('/\bover\s*(\d+(?:\.\d+)?)/i', $pick, $m) && !preg_match('/\b(corner|card|booking)/i', $pick . ' ' . ($t['market'] ?? ''))) {
        $need = (int)floor((float)$m[1]) + 1;
        $pct = (int)min(100, round($goals / max(1, $need) * 100));
        $state = $goals >= $need ? 'won' : ($fin ? 'lost' : 'on');
        return ['label' => $goals . ' of ' . $need . ' goals needed', 'pct' => $pct, 'state' => $state];
    }
    if (preg_match('/\bunder\s*(\d+(?:\.\d+)?)/i', $pick, $m) && !preg_match('/\b(corner|card|booking)/i', $pick . ' ' . ($t['market'] ?? ''))) {
        $max = (int)floor((float)$m[1]);
        $pct = (int)min(100, round($goals / max(1, $max + 1) * 100));
        $state = $goals > $max ? 'lost' : ($fin ? 'won' : 'on');
        return ['label' => $goals . ' goal' . ($goals === 1 ? '' : 's') . ' so far (max ' . $max . ')', 'pct' => $pct, 'state' => $state];
    }
    if (preg_match('/\b(btts|both teams to score)\b/i', $pick . ' ' . ($t['market'] ?? '')) && !preg_match('/\bno\b/i', $pick)) {
        $n = ((int)$t['home_score'] > 0 ? 1 : 0) + ((int)$t['away_score'] > 0 ? 1 : 0);
        return ['label' => $n . ' of 2 teams scored', 'pct' => $n * 50, 'state' => $n === 2 ? 'won' : ($fin ? 'lost' : 'on')];
    }
    return null;
}

/** Ids of tips this member follows (static per request). */
function tipx_followed(int $uid): array
{
    static $c = [];
    if (!$uid || !tipx_ready()) { return []; }
    if (!isset($c[$uid])) { $st = db()->prepare('SELECT tip_id FROM tip_follows WHERE user_id = ?'); $st->execute([$uid]); $c[$uid] = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); }
    return $c[$uid];
}

/** One line of text for "copy all tips". Locked picks are never included. */
function tip_copy_line(array $t, bool $unlocked): string
{
    if (!$unlocked) { return ''; }
    return $t['home_team'] . ' vs ' . $t['away_team'] . ' (' . date('H:i', strtotime($t['kickoff_at'])) . ') - ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2);
}

function match_stats_get(int $mid): ?array
{
    if (!tipx_ready()) { return null; }
    $st = db()->prepare('SELECT * FROM match_stats WHERE match_id = ?'); $st->execute([$mid]);
    return $st->fetch() ?: null;
}

/* ---------- alerts for followed tips (called from cron/live-scores.php) ---------- */
/** Members who follow a tip on this match (the bell), plus anyone who follows the tipster who posted a tip on it. */
function tipx_audience(int $mid): array
{
    if (!tipx_ready()) { return []; }
    $ids = db()->prepare('SELECT DISTINCT f.user_id FROM tip_follows f JOIN tips t ON t.id = f.tip_id WHERE t.match_id = ? AND t.deleted_at IS NULL');
    $ids->execute([$mid]);
    return array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
}
function tipx_match_alert(int $mid, string $kind, string $title, string $body, string $dedupe, string $path = 'tips.php'): int
{
    $n = 0;
    foreach (tipx_audience($mid) as $uid) { if (alert_enqueue($uid, $kind, $title, $body, $path, $dedupe . '-' . $uid)) { $n++; } }
    return $n;
}
/** A goal was scored in a match with followed tips. */
function tipx_goal_alert(int $mid, int $evId, string $player, int $minute): void
{
    $m = db()->prepare('SELECT home_team, away_team, home_score, away_score FROM matches WHERE id = ?'); $m->execute([$mid]); $r = $m->fetch();
    if (!$r) { return; }
    tipx_match_alert($mid, 'goal', 'GOAL! ' . $r['home_team'] . ' ' . (int)$r['home_score'] . ' - ' . (int)$r['away_score'] . ' ' . $r['away_team'],
        ($player !== '' ? $player . ' scored' : 'A goal was scored') . ' in minute ' . $minute . '. See how your tip is doing.', 'goal-' . $mid . '-' . $evId);
}
/** Saves live statistics from API-Football's fixtures/statistics response and returns true when something was stored. */
function tipx_store_stats(int $mid, array $resp, int $homeTeamId): bool
{
    if (!tipx_ready() || count($resp) < 2) { return false; }
    $pick = function (array $team, array $names) {
        foreach (($team['statistics'] ?? []) as $s) { if (in_array($s['type'] ?? '', $names, true)) { $v = $s['value']; return $v === null ? null : (int)str_replace('%', '', (string)$v); } }
        return null;
    };
    $h = $resp[0]; $a = $resp[1];
    if ((int)($resp[0]['team']['id'] ?? 0) !== $homeTeamId && $homeTeamId) { [$h, $a] = [$a, $h]; }
    $v = [$pick($h, ['Total Shots']), $pick($a, ['Total Shots']), $pick($h, ['Shots on Goal']), $pick($a, ['Shots on Goal']), $pick($h, ['Ball Possession']), $pick($a, ['Ball Possession']), $pick($h, ['Corner Kicks']), $pick($a, ['Corner Kicks'])];
    db()->prepare('INSERT INTO match_stats (match_id, shots_h, shots_a, sot_h, sot_a, poss_h, poss_a, corners_h, corners_a) VALUES (?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE shots_h = VALUES(shots_h), shots_a = VALUES(shots_a), sot_h = VALUES(sot_h), sot_a = VALUES(sot_a), poss_h = VALUES(poss_h), poss_a = VALUES(poss_a), corners_h = VALUES(corners_h), corners_a = VALUES(corners_a), updated_at = NOW()')
        ->execute(array_merge([$mid], $v));
    return true;
}
/** Lineups were released for a match: tell members who follow a tip on it (once). */
function tipx_lineup_alert(int $mid): int
{
    if (!tipx_ready()) { return 0; }
    $ins = db()->prepare('INSERT IGNORE INTO lineup_alerts (match_id) VALUES (?)'); $ins->execute([$mid]);
    if ($ins->rowCount() !== 1) { return 0; }
    $m = db()->prepare('SELECT home_team, away_team, kickoff_at FROM matches WHERE id = ?'); $m->execute([$mid]); $r = $m->fetch();
    if (!$r) { return 0; }
    return tipx_match_alert($mid, 'lineup', 'Lineups are out: ' . $r['home_team'] . ' vs ' . $r['away_team'], 'The starting teams were announced. Kickoff ' . date('H:i', strtotime($r['kickoff_at'])) . '.', 'lineup-' . $mid);
}
