<?php
/** Livestream extras: broken-link reports with auto-hide, backup link, live "who scores next" poll. */

function watch_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = function_exists('sk_ensure_table') && sk_ensure_table('stream_reports', 'database-update-38.sql') && sk_ensure_table('live_poll_votes', 'database-update-38.sql'); }
    return $ok;
}
/** The backup-link column on matches and streak days is created on first use. */
function stream2_col(string $table = 'matches'): bool
{
    static $ok = [];
    if (!isset($ok[$table])) {
        $flag = 'stream2_col_' . $table;
        if (setting($flag, '0') === '1') { return $ok[$table] = true; }
        $ok[$table] = function_exists('sk_ensure_column') && sk_ensure_column($table, 'stream_url2', "VARCHAR(400) NOT NULL DEFAULT ''");
        if ($ok[$table]) { set_setting($flag, '1'); }
    }
    return $ok[$table];
}
function watch_reporter(): string
{
    $u = function_exists('current_user') ? current_user() : null;
    return $u ? 'u' . (int)$u['id'] : 'i' . substr(sha1((string)($_SERVER['REMOTE_ADDR'] ?? '') . stream_salt()), 0, 16);
}
function watch_report(string $url): bool
{
    if (!watch_ready() || stream_clean($url) === '') { return false; }
    db()->prepare('INSERT INTO stream_reports (url_hash, url, reporter) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at = NOW()')->execute([sha1($url), $url, watch_reporter()]);
    return true;
}
/** A link counts as dead when enough different people reported it in the last 6 hours. */
function stream_dead(string $url): bool
{
    static $c = [];
    $url = stream_clean($url);
    if ($url === '' || !watch_ready()) { return false; }
    $h = sha1($url);
    if (!isset($c[$h])) {
        $st = db()->prepare('SELECT COUNT(*) FROM stream_reports WHERE url_hash = ? AND created_at > NOW() - INTERVAL 6 HOUR'); $st->execute([$h]);
        $c[$h] = (int)$st->fetchColumn();
    }
    return $c[$h] >= max(1, (int)setting('stream_dead_after', '3'));
}
/** The link to show for a game: the main one, or the backup when the main one was reported dead. '' = hide the button. */
function stream_pick(array $t): string
{
    $a = stream_clean((string)($t['stream_url'] ?? '')); $b = stream_clean((string)($t['stream_url2'] ?? ''));
    if ($a !== '' && !stream_dead($a)) { return $a; }
    if ($b !== '' && !stream_dead($b)) { return $b; }
    return '';
}
function stream_reports_list(int $limit = 40): array
{
    if (!watch_ready()) { return []; }
    return db()->query('SELECT url_hash, MAX(url) url, COUNT(*) n, MAX(created_at) last_at FROM stream_reports WHERE created_at > NOW() - INTERVAL 24 HOUR GROUP BY url_hash ORDER BY n DESC LIMIT ' . (int)$limit)->fetchAll();
}

/** Match data for the watch page: id, teams, kickoff, live score. */
function watch_match(int $id): ?array
{
    try {
        $st = db()->prepare('SELECT id, home_team, away_team, kickoff_at, live_status, live_minute, home_score, away_score FROM matches WHERE id = ?');
        $st->execute([$id]); return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
}
/** Live poll state: ['round' => goals so far, 'open' => bool, 'counts' => [1,X,2], 'mine' => choice|null]. Choices: 1 = home scores next, X = no more goals, 2 = away. */
function live_poll_state(int $matchId, int $userId): array
{
    $m = watch_match($matchId);
    $round = $m ? (int)$m['home_score'] + (int)$m['away_score'] : 0;
    $open = $m && strtotime($m['kickoff_at']) <= time() + 600 && !(function_exists('live_is_finished') && live_is_finished((string)$m['live_status'])) && strtotime($m['kickoff_at']) > time() - 9000;
    $c = ['1' => 0, 'X' => 0, '2' => 0]; $mine = null;
    if (watch_ready()) {
        $st = db()->prepare('SELECT choice, COUNT(*) FROM live_poll_votes WHERE match_id = ? AND round_no = ? GROUP BY choice'); $st->execute([$matchId, $round]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { if (isset($c[$r[0]])) { $c[$r[0]] = (int)$r[1]; } }
        if ($userId) { $q = db()->prepare('SELECT choice FROM live_poll_votes WHERE match_id = ? AND user_id = ? AND round_no = ?'); $q->execute([$matchId, $userId, $round]); $v = $q->fetchColumn(); $mine = $v === false ? null : (string)$v; }
    }
    return ['round' => $round, 'open' => (bool)$open, 'counts' => $c, 'mine' => $mine, 'home' => $m['home_team'] ?? '', 'away' => $m['away_team'] ?? '', 'score' => $m && $m['home_score'] !== null ? (int)$m['home_score'] . ' - ' . (int)$m['away_score'] : ''];
}

/* ---------- widget embeds ---------- */
function stream_widgets_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = function_exists('sk_ensure_table') && sk_ensure_table('stream_widgets', 'database-update-38.sql'); }
    return $ok;
}
/**
 * Turns pasted embed / widget code into an address the player can show. Returns [address, error].
 * - A lone <iframe src="https://..."></iframe> becomes a normal link.
 * - Anything else (script widgets, player code) is saved and shown through widget.php inside a sandboxed frame (no pop-ups, no redirects).
 */
function stream_widget_save(string $code): array
{
    $code = trim($code);
    if ($code === '') { return ['', '']; }
    if (strlen($code) > 20000) { return ['', 'The widget code is too long (20,000 characters at most).']; }
    if (preg_match('~^<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\'][^>]*>\s*</iframe>\s*$~is', $code, $m)) {
        $src = html_entity_decode($m[1], ENT_QUOTES);
        if (strpos($src, '//') === 0) { $src = 'https:' . $src; }
        $c = stream_clean($src);
        return $c !== '' ? [$c, ''] : ['', 'The frame address in that code is not a normal http(s) link.'];
    }
    if (!preg_match('~<(iframe|script|video|div|embed|object)\b~i', $code)) { return ['', 'That does not look like widget or embed code. Paste the full code from the provider, or use the link box.']; }
    if (!stream_widgets_ready()) { return ['', 'Run database-update-38.sql first (widget storage).']; }
    db()->prepare('INSERT INTO stream_widgets (html) VALUES (?)')->execute([$code]);
    $id = (int)db()->lastInsertId();
    return [url('widget.php?id=' . $id . '&s=' . stream_sig('widget:' . $id)), ''];
}
