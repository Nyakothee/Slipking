<?php
/** Live match centre helpers: status labels, which matches are live, events and the safe (no locked picks) tip summary. */

function live_status_label(?string $s, ?int $minute): string
{
    $map = ['NS' => 'Not started', 'HT' => 'Half time', 'FT' => 'Full time', 'AET' => 'Full time (extra time)', 'PEN' => 'Full time (penalties)',
            'PST' => 'Postponed', 'CANC' => 'Cancelled', 'SUSP' => 'Suspended', 'INT' => 'Interrupted', 'ABD' => 'Abandoned', 'BT' => 'Break', 'P' => 'Penalties'];
    if ($s === null || $s === '') {
        return '';
    }
    if (in_array($s, ['1H', '2H', 'ET', 'LIVE'], true)) {
        return $minute ? $minute . "'" : 'Live';
    }
    return $map[$s] ?? $s;
}

function live_is_live(?string $s): bool
{
    return in_array((string)$s, ['1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE'], true);
}

function live_is_finished(?string $s): bool
{
    return in_array((string)$s, ['FT', 'AET', 'PEN'], true);
}

/** Matches to show on the live page: live now, plus today's, plus finished in the last 3 hours. */
function live_matches(): array
{
    $st = db()->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, m.live_status, m.live_minute, m.home_score, m.away_score, l.name AS league
                       FROM matches m JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
                       WHERE s.name LIKE 'Football%' AND (
                             m.live_status IN ('1H','2H','HT','ET','BT','P','LIVE')
                          OR (m.kickoff_at >= CURDATE() AND m.kickoff_at < CURDATE() + INTERVAL 1 DAY)
                          OR (m.kickoff_at > NOW() - INTERVAL 3 HOUR AND m.kickoff_at < NOW() + INTERVAL 12 HOUR))
                       ORDER BY (m.live_status IN ('1H','2H','HT','ET','BT','P','LIVE')) DESC, m.kickoff_at ASC, m.id ASC LIMIT 80");
    return $st->fetchAll();
}

function live_events(int $matchId): array
{
    $st = db()->prepare('SELECT id, minute, extra, type, team, player, detail FROM match_events WHERE match_id = ? ORDER BY minute ASC, extra ASC, id ASC LIMIT 120');
    $st->execute([$matchId]);
    return $st->fetchAll();
}

function live_event_icon(string $type): string
{
    $m = ['goal' => '&#9917;', 'own_goal' => '&#9917;', 'penalty' => '&#9917;', 'yellow' => '&#129000;', 'red' => '&#128997;', 'sub' => '&#128260;', 'var' => '&#128250;', 'info' => '&#8505;'];
    return $m[$type] ?? '&#8226;';
}

/**
 * Tips on a match, safe for the public: the pick of a locked premium tip is NEVER included.
 * Each item: id, status, market, pick (null when locked), odds (null when locked), vip, by.
 */
function live_tips_public(int $matchId): array
{
    $st = db()->prepare("SELECT t.id, t.market, t.pick, t.odds, t.status, t.is_vip, t.tipster_id, ti.display_name
                         FROM tips t LEFT JOIN tipsters ti ON ti.id = t.tipster_id
                         WHERE t.match_id = ? AND t.deleted_at IS NULL AND t.pending_review = 0 ORDER BY t.id ASC LIMIT 12");
    $st->execute([$matchId]);
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $see = $t['status'] !== 'pending' || !$t['is_vip'] || tip_access($t);
        $out[] = ['id' => (int)$t['id'], 'status' => $t['status'], 'market' => $see ? $t['market'] : 'Premium tip',
                  'pick' => $see ? $t['pick'] : null, 'odds' => $see ? number_format((float)$t['odds'], 2) : null,
                  'vip' => (bool)$t['is_vip'], 'by' => $t['display_name'] ?: SITE_NAME];
    }
    return $out;
}

function live_events_json(array $events): array
{
    $out = [];
    foreach ($events as $ev) {
        $out[] = ['id' => (int)$ev['id'], 'minute' => (int)$ev['minute'], 'extra' => $ev['extra'] !== null ? (int)$ev['extra'] : null, 'type' => $ev['type'],
                  'team' => $ev['team'], 'player' => (string)$ev['player'], 'detail' => (string)$ev['detail']];
    }
    return $out;
}
