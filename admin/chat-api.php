<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';      // json_out()
require_once dirname(__DIR__) . '/includes/chat.php';

header('Cache-Control: no-store');
if (!is_staff() || !can('chat')) {
    json_out(['ok' => false, 'message' => 'Not allowed.'], 403);
}
$pdo    = db();
$action = (string)($_REQUEST['action'] ?? '');
$tid    = (int)($_REQUEST['thread'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
    if ($action === 'threads') {
        $rows = $pdo->query("SELECT t.*, (SELECT body FROM chat_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS last_body
                             FROM chat_threads t ORDER BY (t.status = 'open') DESC, t.last_message_at DESC LIMIT 60")->fetchAll();
        $out = [];
        foreach ($rows as $t) {
            $out[] = ['id' => (int)$t['id'], 'name' => $t['name'], 'status' => $t['status'], 'unread' => (int)$t['unread_admin'],
                      'last' => mb_substr((string)$t['last_body'], 0, 70), 'when' => date('j M H:i', strtotime($t['last_message_at']))];
        }
        json_out(['ok' => true, 'threads' => $out]);
    }
    if ($action === 'messages') {
        $st = $pdo->prepare('SELECT * FROM chat_threads WHERE id = ?');
        $st->execute([$tid]);
        $t = $st->fetch();
        if (!$t) json_out(['ok' => false, 'message' => 'Chat not found.'], 404);
        $after = max(0, (int)($_GET['after'] ?? 0));
        $st = $pdo->prepare('SELECT id, sender, body, created_at FROM chat_messages WHERE thread_id = ? AND id > ? ORDER BY id LIMIT 200');
        $st->execute([$tid, $after]);
        $msgs = [];
        foreach ($st->fetchAll() as $m) {
            $msgs[] = ['id' => (int)$m['id'], 'from' => $m['sender'], 'body' => $m['body'], 'time' => date('j M H:i', strtotime($m['created_at']))];
        }
        if ((int)$t['unread_admin'] > 0) {
            $pdo->prepare('UPDATE chat_threads SET unread_admin = 0 WHERE id = ?')->execute([$tid]);
        }
        json_out(['ok' => true, 'messages' => $msgs, 'thread' => ['id' => (int)$t['id'], 'name' => $t['name'], 'email' => $t['email'],
                  'user_id' => $t['user_id'] ? (int)$t['user_id'] : null, 'status' => $t['status']]]);
    }
    json_out(['ok' => false, 'message' => 'Invalid request.'], 400);
}

// POST actions need the form token
if (!csrf_check()) {
    json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page.'], 403);
}
$st = $pdo->prepare('SELECT * FROM chat_threads WHERE id = ?');
$st->execute([$tid]);
$t = $st->fetch();
if (!$t) {
    json_out(['ok' => false, 'message' => 'Chat not found.'], 404);
}
if ($action === 'send') {
    $body = chat_clean((string)($_POST['body'] ?? ''));
    if ($body === '') json_out(['ok' => false, 'message' => 'Type a message first.'], 400);
    $pdo->prepare("INSERT INTO chat_messages (thread_id, sender, body) VALUES (?, 'admin', ?)")->execute([$tid, $body]);
    $pdo->prepare("UPDATE chat_threads SET last_message_at = NOW(), unread_user = unread_user + 1, status = 'open' WHERE id = ?")->execute([$tid]);
    json_out(['ok' => true]);
}
if ($action === 'close' || $action === 'reopen') {
    $pdo->prepare('UPDATE chat_threads SET status = ? WHERE id = ?')->execute([$action === 'close' ? 'closed' : 'open', $tid]);
    admin_log('chat.' . $action, 'chat#' . $tid);
    json_out(['ok' => true]);
}
json_out(['ok' => false, 'message' => 'Invalid request.'], 400);
