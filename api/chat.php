<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';      // json_out()
require_once dirname(__DIR__) . '/includes/chat.php';

header('Cache-Control: no-store');
$pdo    = db();
$action = (string)($_REQUEST['action'] ?? 'poll');

if ($action === 'poll' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $thread = chat_find_thread();
    session_write_close();
    if (!$thread) {
        json_out(['ok' => true, 'has' => false, 'messages' => [], 'unread' => 0, 'status' => 'none']);
    }
    $after = max(0, (int)($_GET['after'] ?? 0));
    $st = $pdo->prepare('SELECT id, sender, body, created_at FROM chat_messages WHERE thread_id = ? AND id > ? ORDER BY id LIMIT 100');
    $st->execute([$thread['id'], $after]);
    $msgs = [];
    foreach ($st->fetchAll() as $m) {
        $msgs[] = ['id' => (int)$m['id'], 'from' => $m['sender'], 'body' => $m['body'], 'time' => date('H:i', strtotime($m['created_at']))];
    }
    $unread = (int)$thread['unread_user'];
    if (!empty($_GET['open']) && $unread > 0) {
        $pdo->prepare('UPDATE chat_threads SET unread_user = 0 WHERE id = ?')->execute([$thread['id']]);
        $unread = 0;
    }
    json_out(['ok' => true, 'has' => true, 'messages' => $msgs, 'unread' => $unread, 'status' => $thread['status']]);
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
    }
    $body = chat_clean((string)($_POST['body'] ?? ''));
    if ($body === '') {
        json_out(['ok' => false, 'message' => 'Type a message first.'], 400);
    }
    if (looks_like_secret($body)) {
        json_out(['ok' => false, 'message' => 'For your safety, never send passwords or PINs in chat. Please remove them and send again.'], 400);
    }

    $user   = current_user();
    $thread = chat_find_thread();
    $notify = false;

    if (!$thread) {
        $name  = $user ? $user['name'] : post_str('name');
        $email = $user ? $user['email'] : strtolower(post_str('email'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            json_out(['ok' => false, 'message' => 'Please enter your name (2 to 60 characters).'], 400);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'message' => 'That email address does not look right. You can leave it empty.'], 400);
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM chat_threads WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR');
        $st->execute([client_ip()]);
        if ((int)$st->fetchColumn() >= 5) {
            json_out(['ok' => false, 'message' => 'Too many chats started from your connection. Please try again later.'], 429);
        }
        $token = bin2hex(random_bytes(16));
        $pdo->prepare('INSERT INTO chat_threads (user_id, visitor_token, name, email, ip) VALUES (?,?,?,?,?)')
            ->execute([$user['id'] ?? null, $token, $name, $email !== '' ? $email : null, client_ip()]);
        chat_set_token($token);
        $st = $pdo->prepare('SELECT * FROM chat_threads WHERE id = ?');
        $st->execute([(int)$pdo->lastInsertId()]);
        $thread = $st->fetch();
        $notify = true;
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE thread_id = ? AND sender = 'visitor' AND created_at > NOW() - INTERVAL 2 MINUTE");
        $st->execute([$thread['id']]);
        if ((int)$st->fetchColumn() >= 12) {
            json_out(['ok' => false, 'message' => 'You are sending messages too fast. Please wait a moment.'], 429);
        }
        $notify = (strtotime($thread['last_message_at']) < time() - 1800) || $thread['status'] === 'closed';
    }

    $pdo->prepare("INSERT INTO chat_messages (thread_id, sender, body) VALUES (?, 'visitor', ?)")->execute([$thread['id'], $body]);
    $pdo->prepare("UPDATE chat_threads SET last_message_at = NOW(), unread_admin = unread_admin + 1, status = 'open' WHERE id = ?")->execute([$thread['id']]);

    if ($notify) {
        send_mail(SITE_EMAIL, '[Live chat] New message from ' . $thread['name'],
                  "{$thread['name']} wrote:\n\n$body\n\nReply in the admin panel: " . url('admin/chat.php?thread=' . (int)$thread['id']));
    }
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'message' => 'Invalid request.'], 400);
