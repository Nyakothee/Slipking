<?php
/** Chat JSON endpoint (global room = 0, group rooms = community id). GET ?room=&after= ; POST action=send|typing|report|delete. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function gc_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d); exit; }
if (!gchat_ready() || !cmty2_ready()) { gc_out(['ok' => false, 'error' => 'Chat is not available yet.'], 503); }
$u = current_user();
$uid = $u ? (int)$u['id'] : 0;
$staff = function_exists('is_staff') && is_staff();
$pdo = db();
$room = (int)($_REQUEST['room'] ?? 0);
$grp = null;
$MATCH_ROOM = 1000000000;
$mroom = $room >= $MATCH_ROOM;
if ($mroom) {
    $mm = watch_match($room - $MATCH_ROOM);
    if (!$mm) { gc_out(['ok' => false, 'error' => 'That match chat is not available.'], 404); }
    $grp = ['id' => $room, 'name' => $mm['home_team'] . ' vs ' . $mm['away_team'] . ' chat', 'is_active' => 1, 'created_by' => 0];
} elseif ($room > 0) {
    $grp = cmty_by_id($room);
    if (!$grp || !(int)$grp['is_active']) { gc_out(['ok' => false, 'error' => 'That group chat is not available.'], 404); }
    if (!$u || (!cmty_is_member($room, $uid) && !cmty_is_mod($grp, $u))) { gc_out(['ok' => false, 'error' => 'Join this group to see its chat.', 'join' => true], 403); }
}
$isMod = $staff || ($grp && !$mroom && cmty_is_mod($grp, $u));

function gc_rows(PDO $pdo, array $rows, int $uid): array
{
    $replyIds = array_values(array_unique(array_filter(array_map(function ($r) { return (int)($r['reply_to'] ?? 0); }, $rows))));
    $rep = [];
    if ($replyIds) {
        foreach ($pdo->query('SELECT g.id, g.body, g.attach_kind, u.name, u.role FROM global_chat g JOIN users u ON u.id = g.user_id WHERE g.id IN (' . implode(',', $replyIds) . ')')->fetchAll() as $r) {
            $rep[(int)$r['id']] = ['id' => (int)$r['id'], 'name' => $r['role'] !== 'user' ? 'Team' : mask_name((string)$r['name']),
                                   'body' => mb_substr((string)$r['body'] !== '' ? (string)$r['body'] : ($r['attach_kind'] === 'i' ? 'Photo' : 'File'), 0, 80)];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $isStaff = isset($r['role']) && $r['role'] !== 'user';
        $out[] = ['id' => (int)$r['id'], 'uid' => (int)$r['user_id'], 'name' => $isStaff ? 'Team' : mask_name((string)$r['name']), 'staff' => $isStaff, 'mine' => $uid && (int)$r['user_id'] === $uid,
                  'time' => date('H:i', strtotime($r['created_at'])), 'day' => date('Y-m-d', strtotime($r['created_at'])), 'body' => (string)$r['body'],
                  'kind' => (string)($r['attach_kind'] ?? ''), 'name_file' => (string)($r['file_name'] ?? ''), 'size' => (int)($r['file_size'] ?? 0),
                  'reply' => $rep[(int)($r['reply_to'] ?? 0)] ?? null,
                  'url' => ($r['attach'] ?? '') === '' || $r['attach'] === null ? '' : ($r['attach_kind'] === 'f' ? url((string)$r['attach'] . '&n=' . rawurlencode((string)$r['file_name'])) : media_src((string)$r['attach']))];
    }
    return $out;
}
$sel = 'SELECT g.id, g.user_id, g.body, g.attach, g.attach_kind, g.file_name, g.file_size, g.reply_to, g.created_at, u.name, u.role FROM global_chat g JOIN users u ON u.id = g.user_id';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($u) { presence_touch($u); }
    session_write_close();
    $after = (int)($_GET['after'] ?? 0);
    if ($after > 0) { $st = $pdo->prepare("$sel WHERE g.room = ? AND g.id > ? ORDER BY g.id ASC LIMIT 100"); $st->execute([$room, $after]); $rows = $st->fetchAll(); }
    else { $st = $pdo->prepare("$sel WHERE g.room = ? ORDER BY g.id DESC LIMIT 60"); $st->execute([$room]); $rows = array_reverse($st->fetchAll()); }
    if ($uid && $room === 0 && $rows) { gchat_mark_seen($uid, (int)end($rows)['id']); }
    $ty = $pdo->prepare("SELECT u.name, u.role FROM user_presence p JOIN users u ON u.id = p.user_id WHERE p.typing_room = ? AND p.typing_at > NOW() - INTERVAL 5 SECOND AND p.user_id <> ? LIMIT 3");
    $ty->execute([$room, $uid]);
    $typing = array_map(function ($r) { return $r['role'] !== 'user' ? 'Team' : mask_name((string)$r['name']); }, $ty->fetchAll());
    gc_out(['ok' => true, 'messages' => gc_rows($pdo, $rows, $uid), 'online' => ($room > 0 && !$mroom) ? presence_online_in_group($room) : presence_online_total(), 'typing' => $typing, 'mod' => $isMod]);
}
// The native app has no page token: its custom header (which another website cannot add) plus its user agent take the place of the CSRF token.
$fromApp = ($_SERVER['HTTP_X_SLIPKING_APP'] ?? '') === '1' && is_app();
if (!$fromApp && !csrf_check()) { gc_out(['ok' => false, 'error' => 'Your session expired. Refresh the page.'], 403); }
if (!$u) { gc_out(['ok' => false, 'error' => 'Log in to chat.'], 401); }
$action = (string)($_POST['action'] ?? '');
if ($action === 'typing') { presence_touch($u, $room); gc_out(['ok' => true]); }
if ($action === 'delete') {
    if (!$isMod) { gc_out(['ok' => false, 'error' => 'Not allowed.'], 403); }
    $did = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare('SELECT user_id, body FROM global_chat WHERE id = ? AND room = ?'); $q->execute([$did, $room]); $old = $q->fetch();
    $pdo->prepare('DELETE FROM global_chat WHERE id = ? AND room = ?')->execute([$did, $room]);
    if ($old) { try { admin_log('chat.delete', 'chat#' . $did, 'user#' . (int)$old['user_id'] . ': ' . mb_substr((string)$old['body'], 0, 120)); } catch (Throwable $e) { } }
    gc_out(['ok' => true]);
}
if ($action === 'report') {
    $mid = (int)($_POST['id'] ?? 0);
    $q = $pdo->prepare('SELECT user_id FROM global_chat WHERE id = ? AND room = ?'); $q->execute([$mid, $room]); $owner = $q->fetchColumn();
    if ($owner === false || (int)$owner === $uid) { gc_out(['ok' => false, 'error' => 'You cannot report that message.'], 422); }
    sk_ensure_table('chat_reports', 'database-update-24.sql');
    $pdo->prepare('INSERT IGNORE INTO chat_reports (msg_id, user_id) VALUES (?, ?)')->execute([$mid, $uid]);
    gc_out(['ok' => true, 'message' => 'Thanks. A moderator will look at it.']);
}
if ($action === 'send') {
    $why = gchat_block_reason($u);
    $body = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($_POST['body'] ?? ''))));
    $hasFile = !empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($why === '') {
        if ($body === '' && !$hasFile) { $why = 'Type a message or attach a file.'; }
        elseif (mb_strlen($body) > 300) { $why = 'Messages can be up to 300 characters.'; }
        elseif ($body !== '' && !$staff && ($am = automod_reason($body)) !== '') { $why = $am; }
        elseif ($body !== '' && ($e1 = comment_text_error(mb_strlen($body) < 2 ? $body . ' ' : $body)) !== '' && stripos($e1, 'couple of characters') === false) { $why = $e1; }
    }
    if ($why === '') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM global_chat WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 SECOND'); $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 4) { $why = 'Slow down a little.'; }
    }
    $replyTo = (int)($_POST['reply_to'] ?? 0);
    if ($replyTo) { $rq = $pdo->prepare('SELECT 1 FROM global_chat WHERE id = ? AND room = ?'); $rq->execute([$replyTo, $room]); if (!$rq->fetchColumn()) { $replyTo = 0; } }
    $attach = null; $kind = null; $fname = null; $fsize = null;
    if ($why === '' && $hasFile) {
        $ext = strtolower(pathinfo((string)($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
        $ferr = '';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $attach = media_store($_FILES['file'], 'chat', $uid, 'fit', $ferr, 6291456); $kind = 'i';
            if ($attach !== null) { $fsize = (int)$_FILES['file']['size']; $fname = mb_substr(preg_replace('/[^A-Za-z0-9._ \-]/', '', basename((string)$_FILES['file']['name'])), 0, 100); }
        } else {
            $d = media_store_doc($_FILES['file'], 'chatdoc', $uid, $ferr, 5242880); $kind = 'f';
            if ($d !== null) { $attach = $d['path']; $fname = $d['name']; $fsize = $d['size']; }
        }
        if ($attach === null) { $why = $ferr !== '' ? $ferr : 'The file could not be saved.'; }
    }
    if ($why !== '') { gc_out(['ok' => false, 'error' => $why], 422); }
    $pdo->prepare('INSERT INTO global_chat (user_id, body, attach, attach_kind, file_name, file_size, room, reply_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$uid, $body, $attach, $kind, $fname, $fsize, $room, $replyTo ?: null]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE user_presence SET typing_at = NULL WHERE user_id = ?')->execute([$uid]);
    if ($replyTo && $u) {      // tell the person whose message was replied to
        try {
            $ro = $pdo->prepare('SELECT user_id FROM global_chat WHERE id = ?'); $ro->execute([$replyTo]); $roUid = (int)$ro->fetchColumn();
            if ($roUid && $roUid !== $uid) {
                cmty_alert($roUid, 'New reply in ' . ($room ? $grp['name'] . ' chat' : 'Global Chat'), mask_name((string)$u['name']) . ': ' . mb_substr($body !== '' ? $body : '(attachment)', 0, 100), $mroom ? 'room.php?id=' . ($room - $MATCH_ROOM) : ($room ? 'm.php?s=' . $grp['slug'] . '&tab=chat#gm' . $newId : 'globalchat.php#gm' . $newId));
            }
        } catch (Throwable $e) { error_log('chat reply alert: ' . $e->getMessage()); }
    }
    if ($body !== '') { mentions_notify($body, $u, $room ? 'in the ' . $grp['name'] . ' chat' : 'in Global Chat'); }
    gc_out(['ok' => true, 'id' => $newId]);
}
gc_out(['ok' => false, 'error' => 'Unknown action.'], 400);
