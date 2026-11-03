<?php
/** Android app releases: the APK is kept outside public access in private-uploads/app/ and handed out by app-download.php. */

const APP_MAX_BYTES = 314572800;      // 300 MB

function app_ready(): bool { return sk_ensure_table('app_releases', 'database-update-26.sql'); }

function app_dir(): string
{
    $d = dirname(__DIR__) . '/private-uploads/app';
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    return $d;
}

/** The release members should get, or null when none is published. */
function app_current(): ?array
{
    static $r = false;
    if ($r !== false) { return $r; }
    $r = null;
    if (!app_ready()) { return null; }
    try {
        $row = db()->query('SELECT * FROM app_releases WHERE is_current = 1 ORDER BY id DESC LIMIT 1')->fetch();
        if ($row && ($row['file_name'] !== '' ? is_file(app_dir() . '/' . $row['file_name']) : $row['external_url'] !== '')) { $r = $row; }
    } catch (Throwable $e) { error_log('app_current: ' . $e->getMessage()); }
    return $r;
}

function app_size_text(int $b): string
{
    return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format(max(1, $b) / 1024, 0) . ' KB';
}

/** True when a file starts like a ZIP (every APK is a ZIP). */
function app_looks_like_apk(string $path): bool
{
    $h = @fopen($path, 'rb'); if (!$h) { return false; }
    $m = fread($h, 4); fclose($h);
    return $m === "PK\x03\x04";
}

function app_download_name(array $r): string
{
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', SITE_NAME)); $slug = trim($slug, '-') ?: 'app';
    return $slug . '-' . preg_replace('/[^A-Za-z0-9.\-]/', '', (string)$r['version']) . '.apk';
}

/** True when the page is opened inside the SlipKing Android app (its WebView adds SlipKingApp to the user agent). */
function is_app(): bool
{
    return strpos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'SlipKingApp') !== false;
}

/** Theme the app is showing: 'light' or 'dark' (the app adds SlipKingTheme/light|dark to its browser name). */
function app_theme(): string
{
    return preg_match('~SlipKingTheme/light~', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) ? 'light' : 'dark';
}


/* ---------- Download and usage tracking ---------- */

function app_stats_ready(): bool { return sk_ensure_table('app_downloads', 'database-update-32.sql') && sk_ensure_table('app_usage', 'database-update-32.sql'); }

function app_device_class(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (stripos($ua, 'Android') !== false) { return 'android'; }
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) { return 'ios'; }
    if (stripos($ua, 'Windows') !== false || stripos($ua, 'Macintosh') !== false || stripos($ua, 'Linux') !== false) { return 'desktop'; }
    return 'other';
}

/** One row per download that really starts (the APK handler calls this). Never breaks the download. */
function app_log_download(int $releaseId, string $src): void
{
    try {
        if (!app_stats_ready()) { return; }
        $src = in_array($src, ['nav', 'footer', 'page', 'home', 'vip'], true) ? $src : 'other';
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        db()->prepare('INSERT INTO app_downloads (release_id, source, device, ip_hash) VALUES (?,?,?,?)')
            ->execute([$releaseId, $src, app_device_class(), substr(hash('sha256', $ip . '|' . (defined('SITE_URL') ? SITE_URL : '')), 0, 16)]);
    } catch (Throwable $e) { error_log('app_log_download: ' . $e->getMessage()); }
}

/** Counts an app open (called when the app loads its home screen): one row per device per day. */
function app_log_open(?array $user, string $version = ''): void
{
    try {
        if (!app_stats_ready()) { return; }
        $uid = $user ? (int)$user['id'] : 0;
        $key = $uid ? 'u' . $uid : 'i' . (string)($_SERVER['REMOTE_ADDR'] ?? '') . (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $dev = substr(hash('sha256', $key), 0, 16);
        if ($uid && function_exists('traffic_ready')) { traffic_ready(); }
        if ($uid) { try { db()->prepare('INSERT IGNORE INTO member_days (day, user_id, source) VALUES (CURDATE(), ?, ?)')->execute([$uid, 'app']); } catch (Throwable $e2) { } }
        db()->prepare('INSERT INTO app_usage (day, dev, user_id, version) VALUES (CURDATE(), ?, ?, ?) ON DUPLICATE KEY UPDATE opens = opens + 1, last_seen = NOW(), user_id = GREATEST(user_id, VALUES(user_id))')
            ->execute([$dev, $uid, mb_substr($version, 0, 20)]);
    } catch (Throwable $e) { error_log('app_log_open: ' . $e->getMessage()); }
}

/** Gold "Install app" button for the top menu: a direct APK download, website only. Nothing is shown unless an APK file is uploaded. */
function app_install_button(): string
{
    if (is_app()) { return ''; }
    $r = app_current();
    if (!$r || (string)$r['file_name'] === '') { return ''; }
    return '<a class="nav-install" href="' . e(url('app-download.php?src=nav')) . '" download rel="nofollow"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 20h14"/></svg><span>Install app</span></a>';
}
