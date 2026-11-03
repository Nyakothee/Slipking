<?php
/**
 * The Android app sends its Firebase token here (POST token=..., action=register|unregister).
 * Needs the member's login cookie and the X-SlipKing-App header, which a web page on another site cannot add.
 * Returns {ok, push}: push=true means the server can send Firebase messages, so the app stops its 15-minute checks.
 */
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_SLIPKING_APP'] ?? '') !== '1' || !is_app()) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
$u = current_user();
$push = function_exists('fcm_ready') && fcm_ready();
$token = (string)($_POST['token'] ?? '');
$act = (string)($_POST['action'] ?? 'register');
if (!preg_match('/^[A-Za-z0-9_:\-.]{20,255}$/', $token)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'token']); exit; }
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'login', 'push' => $push]); exit; }
try {
    if (!app_push_ready()) { echo json_encode(['ok' => true, 'push' => false]); exit; }
    $pdo = db();
    if ($act === 'unregister') {
        $pdo->prepare('DELETE FROM app_devices WHERE token = ? AND user_id = ?')->execute([$token, (int)$u['id']]);
    } else {
        $ver = mb_substr(preg_replace('/[^0-9A-Za-z.\-+]/', '', (string)($_POST['version'] ?? '')), 0, 30);
        $pdo->prepare('INSERT INTO app_devices (user_id, token, platform, app_version) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), app_version = VALUES(app_version), updated_at = NOW()')
            ->execute([(int)$u['id'], $token, 'android', $ver]);
        // keep it tidy: one member, at most 5 phones (newest win)
        $pdo->prepare('DELETE FROM app_devices WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM app_devices WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 5) t)')->execute([(int)$u['id'], (int)$u['id']]);
    }
    echo json_encode(['ok' => true, 'push' => $push]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false]); }
