<?php
/** Private-message live endpoint. GET ?a=thread&with=&after=&q= ; POST a=send|typing|react|hide|mute|seen. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function dm_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$u = current_user();
if (!$u) { dm_out(['ok' => false, 'error' => 'Log in first.'], 401); }
if (!dm2_ready()) { dm_out(['ok' => false, 'error' => 'Messages are not available yet.'], 503); }
$uid = (int)$u['id'];
$a = (string)($_REQUEST['a'] ?? 'thread');
$with = (int)($_REQUEST['with'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_check()) { dm_out(['ok' => false, 'error' => 'Your session expired. Refresh the page.'], 403); }
presence_touch($u);
if ($a === 'thread') {
    if (!$with || $with === $uid) { dm_out(['ok' => false, 'error' => 'Choose someone.'], 404); }
    $after = (int)($_GET['after'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));
    $msgs = dm2_thread($uid, $with, $after, $q);
    if ($q === '') { db()->prepare('UPDATE dm_messages SET read_at = NOW() WHERE to_id = ? AND from_id = ? AND read_at IS NULL')->execute([$uid, $with]); }
    dm_out(['ok' => true, 'messages' => $msgs] + dm2_status($uid, $with));
}
if ($a === 'typing') { if ($with && $with !== $uid) { dm2_typing_set($uid, $with); } dm_out(['ok' => true]); }
if ($a === 'send') {
    [$ok, $r] = dm2_send($u, $with, (string)($_POST['body'] ?? ''), (int)($_POST['reply_to'] ?? 0), $_FILES);
    dm_out($ok ? ['ok' => true, 'id' => $r] : ['ok' => false, 'error' => $r], $ok ? 200 : 422);
}
if ($a === 'react') { dm_out(['ok' => dm2_react($uid, (int)($_POST['id'] ?? 0), (string)($_POST['emoji'] ?? ''))]); }
if ($a === 'hide') { dm2_hide_for_me($uid, (int)($_POST['id'] ?? 0)); dm_out(['ok' => true]); }
if ($a === 'mute') { dm_out(['ok' => true, 'muted' => dm2_mute_toggle($uid, $with)]); }
dm_out(['ok' => false, 'error' => 'Unknown action.'], 400);
