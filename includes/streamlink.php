<?php
/** Optional live-stream link on a match ("Watch live" button under the tip). Column is created on first use. */
function stream_col(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        if (setting('stream_col', '0') === '1') { return $ok = true; }
        $ok = sk_ensure_column('matches', 'stream_url', "VARCHAR(400) NOT NULL DEFAULT ''");
        if ($ok) { set_setting('stream_col', '1'); }
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
/** Returns a clean http(s) URL or '' (never javascript:, data: etc). */
function stream_clean(string $u): string
{
    $u = trim($u);
    if ($u === '' || mb_strlen($u) > 400 || !preg_match('~^https?://[^\s<>"\']+$~i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) { return ''; }
    return $u;
}
/** Secret used to sign player links, so nobody can build a page of their own around our player. Created once. */
function stream_salt(): string
{
    $s = (string)setting('stream_salt', '');
    if (strlen($s) < 20) { $s = bin2hex(random_bytes(16)); set_setting('stream_salt', $s); }
    return $s;
}
function stream_sig(string $url): string { return substr(hash_hmac('sha256', $url, stream_salt()), 0, 20); }

/**
 * Link to our own player page (watch.php) for a stream or replay address. $mode: '' = full page, 'embed' = bare player for the pop-up,
 * 'app' = bare player for the Android app. The address is signed, so only links the admin saved can be played.
 */
function stream_watch_url(string $url, string $title = '', string $mode = '', array $opt = []): string
{
    $u = stream_clean($url);
    if ($u === '') { return ''; }
    $q = 'u=' . rtrim(strtr(base64_encode($u), '+/', '-_'), '=') . '&s=' . stream_sig($u) . ($title !== '' ? '&t=' . rawurlencode(mb_substr($title, 0, 90)) : '') . ($mode !== '' ? '&m=' . $mode : '');
    if (!empty($opt['mid'])) { $q .= '&i=' . (int)$opt['mid']; }
    if (!empty($opt['k'])) { $q .= '&k=' . (int)$opt['k']; }
    $b = stream_clean((string)($opt['backup'] ?? ''));
    if ($b !== '' && $b !== $u) { $q .= '&b=' . rtrim(strtr(base64_encode($b), '+/', '-_'), '=') . '&bs=' . stream_sig($b); }
    return url('watch.php?' . $q);
}

/**
 * How to show a link inside our own page: ['kind' => 'iframe'|'video'|'hls', 'src' => address] or null.
 * Known sites (YouTube, Vimeo, Facebook, Dailymotion, Twitch) use their official embed. Direct video files and .m3u8 streams play in a
 * normal video player. Any other page is shown in a frame (a site that forbids framing will stay blank; the player page then offers its original link).
 */
function stream_embed(string $url): ?array
{
    $u = stream_clean($url);
    if ($u === '') { return null; }
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $u, $m)) {
        return ['kind' => 'iframe', 'src' => 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?autoplay=1&rel=0&playsinline=1'];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d{5,})~', $u, $m)) { return ['kind' => 'iframe', 'src' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1']; }
    if (preg_match('~dailymotion\.com/(?:embed/)?video/([A-Za-z0-9]+)~', $u, $m)) { return ['kind' => 'iframe', 'src' => 'https://www.dailymotion.com/embed/video/' . $m[1] . '?autoplay=1']; }
    if (preg_match('~(?:facebook\.com|fb\.watch)/~i', $u)) { return ['kind' => 'iframe', 'src' => 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($u) . '&show_text=false&autoplay=true']; }
    if (preg_match('~twitch\.tv/videos/(\d+)~', $u, $m)) { return ['kind' => 'iframe', 'src' => 'https://player.twitch.tv/?video=v' . $m[1] . '&parent=' . rawurlencode($host) . '&autoplay=true']; }
    if (preg_match('~twitch\.tv/([A-Za-z0-9_]{3,25})/?$~', $u, $m)) { return ['kind' => 'iframe', 'src' => 'https://player.twitch.tv/?channel=' . $m[1] . '&parent=' . rawurlencode($host) . '&autoplay=true']; }
    $path = strtolower((string)parse_url($u, PHP_URL_PATH));
    if (substr($path, -5) === '.m3u8') { return ['kind' => 'hls', 'src' => $u]; }
    if (preg_match('~\.(mp4|webm|ogv|m4v)$~', $path)) { return ['kind' => 'video', 'src' => $u]; }
    return ['kind' => 'iframe', 'src' => $u];
}

/** Options (match id, kickoff time, backup link) for a tip or streak row. */
function stream_opts(array $t, string $picked): array
{
    $b = stream_clean((string)($t['stream_url2'] ?? ''));
    $k = strtotime((string)($t['kickoff_at'] ?? '')) ?: 0;
    return ['mid' => (int)($t['match_id'] ?? 0), 'k' => $k > time() ? $k : 0, 'backup' => ($b !== '' && $b !== $picked && !stream_dead($b)) ? $b : ''];
}
function stream_title(array $t): string
{
    $title = trim((string)($t['home_team'] ?? $t['home'] ?? '') . ' vs ' . (string)($t['away_team'] ?? $t['away'] ?? ''), ' v');
    return $title === 'vs' ? '' : $title;
}
/** Watch address for the app (signed player page in "app" mode). */
function stream_app_url(array $t): string
{
    $u = stream_pick($t);
    return ($u === '' || in_array(stream_state_of($t), ['soon', 'ended'], true)) ? '' : stream_watch_url($u, stream_title($t), 'app', stream_opts($t, $u));
}

/** Where a game's stream is: 'soon' (kickoff not reached), 'live', 'ended' (over 2 h 30 min after kickoff or full time) or 'open' (no kickoff known). */
function stream_state_of(array $t): string
{
    $k = strtotime((string)($t['kickoff_at'] ?? ''));
    if (!$k) { return 'open'; }
    if ($k > time()) { return 'soon'; }
    if (time() - $k > 9000) { return 'ended'; }
    return (function_exists('live_is_finished') && live_is_finished((string)($t['live_status'] ?? ''))) ? 'ended' : 'live';
}

/** The "Watch live" button. It opens the game in a player on top of the current page, so nobody is sent away. */
function stream_button(array $t, string $label = 'Watch live'): string
{
    $u = stream_pick($t);
    if ($u === '') { return ''; }
    $state = stream_state_of($t);
    if ($state === 'ended') { return '<span class="watch-live off ended" aria-disabled="true">Live ended</span>'; }
    if ($state === 'soon') { return '<span class="watch-live off soon" aria-disabled="true">Game not started <span class="wl-cd" data-ts="' . (int)strtotime((string)$t['kickoff_at']) . '"></span></span>'; }
    $title = stream_title($t); $o = stream_opts($t, $u);
    $cd = !empty($o['k']) ? ' <span class="wl-cd" data-ts="' . (int)$o['k'] . '"></span>' : '';
    return '<a class="watch-live" href="' . htmlspecialchars(stream_watch_url($u, $title, '', $o), ENT_QUOTES) . '" data-play="' . htmlspecialchars(stream_watch_url($u, $title, 'embed', $o), ENT_QUOTES) . '" data-title="' . htmlspecialchars($title, ENT_QUOTES) . '"><span class="wl-dot"></span>' . htmlspecialchars($label, ENT_QUOTES) . $cd . '</a>' . (function_exists('stream_extra_buttons') ? stream_extra_buttons($t) : '');
}

/**
 * Where a game is right now, for the tip card: 'live' (kickoff has passed and it is not over), 'ended' (full time, or about 2.5 hours
 * after kickoff) or '' (not started yet, or a long time ago). The badge only appears once the game has begun.
 */
function match_phase(array $t): string
{
    $k = strtotime((string)($t['kickoff_at'] ?? ''));
    if (!$k || $k > time()) { return ''; }
    $age = time() - $k;
    if ($age > 86400) { return ''; }
    $ls = (string)($t['live_status'] ?? '');
    if (function_exists('live_is_finished') && live_is_finished($ls)) { return 'ended'; }
    if (in_array($ls, ['PST', 'CANC', 'ABD'], true)) { return ''; }
    return $age <= 9000 ? 'live' : 'ended';
}

function live_badge(array $t): string
{
    $p = match_phase($t);
    if ($p === 'live') { return '<span class="live-pill"><i></i>LIVE</span>'; }
    if ($p === 'ended') { return '<span class="end-pill">FULL TIME</span>'; }
    return '';
}

/** Games with a stream that are live now, or start later today: for the moving banner. [['name','url','live','when'], ...] */
function live_stream_items(int $limit = 12): array
{
    $out = [];
    try {
        if (!stream_col()) { return []; }
        $rs = db()->query("SELECT m.id AS match_id, m.home_team, m.away_team, m.kickoff_at, m.stream_url, " . (stream2_col() ? 'm.stream_url2, ' : '') . "m.live_status FROM matches m
                           WHERE m.stream_url <> '' AND m.kickoff_at > NOW() - INTERVAL 150 MINUTE AND m.kickoff_at < NOW() + INTERVAL 18 HOUR
                             AND EXISTS (SELECT 1 FROM tips t WHERE t.match_id = m.id AND t.deleted_at IS NULL)
                           ORDER BY m.kickoff_at ASC LIMIT " . (int)$limit)->fetchAll();
        foreach ($rs as $m) {
            $u = stream_pick($m);
            if ($u === '') { continue; }
            $k = strtotime($m['kickoff_at']);
            $live = $k <= time() && !(function_exists('live_is_finished') && live_is_finished((string)$m['live_status']));
            $out[] = ['name' => $m['home_team'] . ' vs ' . $m['away_team'], 'url' => $u, 'watch' => stream_watch_url($u, stream_title($m), '', stream_opts($m, $u)), 'play' => stream_watch_url($u, stream_title($m), 'embed', stream_opts($m, $u)), 'app' => stream_watch_url($u, stream_title($m), 'app', stream_opts($m, $u)), 'live' => $live, 'when' => date('H:i', $k)];
        }
        if (function_exists('streak_ready') && streak_ready()) {
            $d = db()->query("SELECT home, away, stream_url, kickoff_at FROM streak_days WHERE stream_url <> '' AND status = 'pending' AND kickoff_at > NOW() - INTERVAL 150 MINUTE AND kickoff_at < NOW() + INTERVAL 18 HOUR")->fetchAll();
            foreach ($d as $m) {
                $u = stream_clean((string)$m['stream_url']); if ($u === '') { continue; }
                $k = strtotime($m['kickoff_at']);
                $out[] = ['name' => '2 Odds Streak: ' . $m['home'] . ' vs ' . $m['away'], 'url' => $u, 'watch' => stream_watch_url($u, $m['home'] . ' vs ' . $m['away']), 'play' => stream_watch_url($u, $m['home'] . ' vs ' . $m['away'], 'embed'), 'app' => stream_watch_url($u, $m['home'] . ' vs ' . $m['away'], 'app'), 'live' => $k <= time(), 'when' => date('H:i', $k)];
            }
        }
    } catch (Throwable $e) { }
    usort($out, function ($a, $b) { return ($b['live'] <=> $a['live']); });
    return $out;
}
