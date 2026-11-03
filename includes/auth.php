<?php
/** Sessions and login helpers. */
if (session_status() === PHP_SESSION_NONE) {
    // The Android app (user agent contains SlipKingApp) stays signed in for 30 days; its sessions live in a private folder so server clean-up never ends them early.
    $skApp = strpos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'SlipKingApp') !== false;
    if ($skApp) {
        $skDir = dirname(__DIR__) . '/cache/sessions';
        if (!is_dir($skDir)) { @mkdir($skDir, 0700, true); }
        if (is_dir($skDir) && is_writable($skDir)) { ini_set('session.save_path', $skDir); }
        ini_set('session.gc_maxlifetime', '2592000');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $skHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => $skApp ? 2592000 : 0,
        'path'     => '/',
        'secure'   => $skHttps,   // switches on by itself as soon as the site is served over HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('slipking_sid');
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['user_id'])) {
        $st = db()->prepare('SELECT id, name, email, phone, role, email_optout, email_verified, country, mobile, mobile_verified, is_tipster_account' . (profile_cols() ? ', avatar, nationality, show_flag' : '') . ' FROM users WHERE id = ? LIMIT 1');
        $st->execute([(int)$_SESSION['user_id']]);
        $user = $st->fetch() ?: null;
        if ($user && function_exists('acct_ban') && setting('acct2_ready', '0') === '1' && acct_ban((int)$user['id']) !== null && $user['role'] === 'user') {
            unset($_SESSION['user_id']);
            $user = null;
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** True if the visitor has an active paid plan (admins always see VIP). */
function is_vip(): bool
{
    static $vip = null;
    if ($vip !== null) {
        return $vip;
    }
    $u = current_user();
    if (!$u) {
        return $vip = false;
    }
    if (in_array($u['role'], ['admin', 'editor', 'support', 'finance'], true)) {
        return $vip = true;                      // staff can always see VIP content
    }
    if (function_exists('access_vip_free') && access_vip_free()) {
        return $vip = true;                      // weekly access fee is ON: VIP is free for everyone with access
    }
    $st = db()->prepare("SELECT 1 FROM subscriptions WHERE user_id = ? AND status = 'active' AND ends_at > NOW() LIMIT 1");
    $st->execute([$u['id']]);
    return $vip = (bool)$st->fetchColumn();
}

function require_login(): void
{
    if (!is_logged_in()) {
        $base = rtrim((string)parse_url(SITE_URL, PHP_URL_PATH), '/');
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        if ($base !== '' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }
        $uri = ltrim($uri, '/');
        redirect('auth/login.php' . (safe_next($uri) !== '' ? '?next=' . urlencode($uri) : ''));
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('admin/login.php');
    }
}

require_once __DIR__ . '/permissions.php';
