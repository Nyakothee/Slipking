<?php
/** Emoji reaction on a tip. POST id, emoji (one of the allowed; same emoji again removes it). Returns counts. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Log in to react.']); exit; }
if (!csrf_check() || !alerts2_ready()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Please refresh the page.']); exit; }
$tid = (int)($_POST['id'] ?? 0);
$res = tip_react((int)$u['id'], $tid, (string)($_POST['emoji'] ?? ''));
echo json_encode(['ok' => $res !== null] + ($res ?? []), JSON_UNESCAPED_UNICODE);
