<?php
/** Visitor, login and daily-active statistics for the website and the app. Every function is silent on failure. */

function traffic_ready(): bool
{
    return sk_ensure_table('login_log', 'database-update-33.sql') && sk_ensure_table('site_visits', 'database-update-33.sql') && sk_ensure_table('member_days', 'database-update-33.sql');
}

function traffic_is_bot(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless/i', $ua) === 1;
}

function traffic_log_login(int $userId, string $source = ''): void
{
    try {
        if (!traffic_ready()) { return; }
        $src = $source !== '' ? $source : (function_exists('is_app') && is_app() ? 'app' : 'web');
        db()->prepare('INSERT INTO login_log (user_id, source) VALUES (?, ?)')->execute([$userId, $src]);
    } catch (Throwable $e) { }
}

/** Called at the bottom of every public page: counts the visitor once a day and the logged-in member once a day. */
function traffic_touch(): void
{
    try {
        if (PHP_SAPI === 'cli' || traffic_is_bot() || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { return; }
        if (isset($_COOKIE['sk_consent']) && strpos((string)$_COOKIE['sk_consent'], 'a') === false) { return; }   // visitor declined analytics
        if (!traffic_ready()) { return; }
        $isApp = function_exists('is_app') && is_app();
        $vid = (string)($_COOKIE['sk_v'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $vid)) {
            $vid = bin2hex(random_bytes(8));
            if (!headers_sent()) { setcookie('sk_v', $vid, ['expires' => time() + 86400 * 400, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]); }
        }
        $today = date('Y-m-d');
        if (($_SESSION['sk_tr_day'] ?? '') !== $today) {
            $_SESSION['sk_tr_day'] = $today;
            db()->prepare('INSERT INTO site_visits (day, vid) VALUES (?, ?) ON DUPLICATE KEY UPDATE hits = hits + 1')->execute([$today, ($isApp ? 'a' : 'w') . substr($vid, 1)]);
            $u = function_exists('current_user') ? current_user() : null;
            if ($u) { db()->prepare('INSERT IGNORE INTO member_days (day, user_id, source) VALUES (?, ?, ?)')->execute([$today, (int)$u['id'], $isApp ? 'app' : 'web']); }
        }
    } catch (Throwable $e) { }
}

/** Series helpers for the admin charts: returns [label => value] for the last $days days (oldest first), zero-filled. */
function traffic_series(string $sql, int $days): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) { $out[date('Y-m-d', strtotime("-$i day"))] = 0; }
    try {
        foreach (db()->query($sql) as $r) { if (isset($out[$r['d']])) { $out[$r['d']] = (int)$r['n']; } }
    } catch (Throwable $e) { }
    return $out;
}
