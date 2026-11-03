<?php
/** Saves or removes this browser's push subscription for the logged-in member. POST: csrf, action=subscribe|unsubscribe, endpoint, p256dh, auth. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ps_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a); exit; }

$user = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ps_out(['ok' => false, 'error' => 'POST only'], 405); }
if (!$user) { ps_out(['ok' => false, 'error' => 'Please log in.'], 401); }
if (!csrf_check()) { ps_out(['ok' => false, 'error' => 'Session expired. Reload the page.'], 403); }
$endpoint = trim((string)($_POST['endpoint'] ?? ''));
$action = $_POST['action'] ?? 'subscribe';
if ($endpoint === '' || strlen($endpoint) > 700 || !webpush_endpoint_ok($endpoint)) { ps_out(['ok' => false, 'error' => 'This browser is not supported.'], 400); }
$hash = hash('sha256', $endpoint);
$pdo = db();
if ($action === 'unsubscribe') {
    $pdo->prepare('DELETE FROM push_subs WHERE endpoint_hash = ? AND user_id = ?')->execute([$hash, $user['id']]);
    ps_out(['ok' => true]);
}
$p256 = trim((string)($_POST['p256dh'] ?? ''));
$auth = trim((string)($_POST['auth'] ?? ''));
if (strlen(b64u_dec($p256)) !== 65 || strlen(b64u_dec($auth)) < 8 || strlen($auth) > 40) { ps_out(['ok' => false, 'error' => 'Bad subscription.'], 400); }
$cnt = $pdo->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id = ? AND endpoint_hash <> ?');
$cnt->execute([$user['id'], $hash]);
if ((int)$cnt->fetchColumn() >= 8) { ps_out(['ok' => false, 'error' => 'Too many devices. Turn off alerts on an old device first.'], 400); }
$pdo->prepare('INSERT INTO push_subs (user_id, endpoint_hash, endpoint, p256dh, auth) VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth)')
    ->execute([$user['id'], $hash, $endpoint, $p256, $auth]);
ps_out(['ok' => true]);
