<?php
/**
 * Livestream and highlights. The admin adds a LINK; SlipKing never hosts or restreams video.
 * Streams send members to the platform that holds the broadcast rights. Highlights are YouTube (or other) links;
 * YouTube ones can be played in an embedded YouTube player, which is the official way to show YouTube content.
 */
function streams_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT 1 FROM streams LIMIT 1'); $ok = true; } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Only http(s) links are ever accepted or followed. Returns '' for anything else. */
function stream_clean_url(string $u): string
{
    $u = trim($u);
    if (!preg_match('~^https?://[^\s<>"\']{4,480}$~i', $u)) { return ''; }
    return $u;
}

function stream_youtube_id(string $url): string
{
    if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) { return $m[1]; }
    return '';
}

function stream_thumb(array $s): string
{
    if (!empty($s['thumb_url'])) { return (string)$s['thumb_url']; }
    $id = stream_youtube_id((string)$s['url']);
    return $id !== '' ? 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg' : '';
}

/** 'live' | 'upcoming' | 'ended' for a stream row. A "scheduled" stream counts as live from kickoff until 3 hours later. */
function stream_state(array $s): string
{
    $t = !empty($s['starts_at']) ? strtotime((string)$s['starts_at']) : 0;
    if ($s['status'] === 'ended') { return 'ended'; }
    if ($t && time() - $t > 9000) { return 'ended'; }       // a live is over 2 hours 30 minutes after it starts
    if ($s['status'] === 'live') { return 'live'; }
    if (!$t || $t > time()) { return 'upcoming'; }
    return 'live';
}

function stream_go_url(int $id): string { return url('go-stream.php?id=' . $id); }

/** Rows for a list. $kind 'stream' | 'highlight'. */
function streams_fetch(string $kind, int $limit = 30, int $offset = 0): array
{
    if (!streams_ready()) { return []; }
    $order = $kind === 'highlight' ? 'COALESCE(starts_at, created_at) DESC, id DESC' : 'COALESCE(starts_at, created_at) ASC, id ASC';
    $where = $kind === 'highlight' ? '' : " AND (status <> 'ended') AND (starts_at IS NULL OR starts_at > NOW() - INTERVAL 3 HOUR OR status = 'live')";
    $st = db()->prepare("SELECT * FROM streams WHERE kind = ? AND is_active = 1" . $where . " ORDER BY $order LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute([$kind]);
    return $st->fetchAll();
}

function stream_item_json(array $s): array
{
    return [
        'id' => (int)$s['id'], 'kind' => $s['kind'], 'title' => $s['title'], 'home' => $s['home_team'], 'away' => $s['away_team'], 'league' => $s['league'],
        'platform' => $s['platform'], 'state' => stream_state($s), 'thumb' => stream_thumb($s), 'desc' => $s['description'],
        'when' => !empty($s['starts_at']) ? ['iso' => date('c', strtotime((string)$s['starts_at'])), 'label' => kickoff_label((string)$s['starts_at'])] : null,
        'go' => stream_go_url((int)$s['id']), 'youtube' => stream_youtube_id((string)$s['url']),
    ];
}
