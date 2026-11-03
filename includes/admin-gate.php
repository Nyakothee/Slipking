<?php
/**
 * Admin secret-link gate. When a key is set (Admin > Security), every page under /admin/ answers
 * "not found" unless the visitor opened the secret link first (it sets a cookie for 30 days).
 * Emergency off switch: create an empty file includes/admin-gate-off.txt on the server.
 */
function admin_gate_key(): string
{
    if (is_file(__DIR__ . '/admin-gate-off.txt')) { return ''; }
    try { return (string)setting('admin_gate_key', ''); } catch (Throwable $e) { return ''; }
}
function admin_gate_token(string $key): string { return hash_hmac('sha256', 'sk-admin-gate', $key); }
function admin_gate_passed(): bool
{
    $key = admin_gate_key();
    return $key === '' || (isset($_COOKIE['sk_ag']) && is_string($_COOKIE['sk_ag']) && hash_equals(admin_gate_token($key), $_COOKIE['sk_ag']));
}
function admin_gate_link(): string
{
    $key = admin_gate_key();
    return $key === '' ? '' : url('admin/index.php?k=' . $key);
}
function admin_gate_run(): void
{
    if (strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') === false) { return; }
    $key = admin_gate_key();
    if ($key === '') { return; }
    $k = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
    if ($k !== '' && hash_equals($key, $k)) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('sk_ag', admin_gate_token($key), ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['sk_ag'] = admin_gate_token($key);
        header('Location: ' . url('admin/index.php'));
        exit;
    }
    if (!admin_gate_passed()) {
        http_response_code(404);
        header('Cache-Control: no-store');
        $page = dirname(__DIR__) . '/404.php';
        if (is_file($page)) { require $page; }
        exit;
    }
}
