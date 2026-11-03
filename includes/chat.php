<?php
/** Live chat helpers (visitor side). Needs the chat_threads and chat_messages tables (database-update-4.sql). */

function chat_cookie_token(): string
{
    $t = (string)($_COOKIE['sk_chat'] ?? '');
    return preg_match('/^[a-f0-9]{32}$/', $t) ? $t : '';
}

function chat_set_token(string $t): void
{
    setcookie('sk_chat', $t, [
        'expires'  => time() + 90 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['sk_chat'] = $t;
}

/** The visitor's own conversation: by member account when logged in, otherwise by the cookie. */
function chat_find_thread(): ?array
{
    $pdo = db();
    $u = current_user();
    if ($u) {
        $st = $pdo->prepare('SELECT * FROM chat_threads WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$u['id']]);
        if ($t = $st->fetch()) {
            return $t;
        }
    }
    $tok = chat_cookie_token();
    if ($tok !== '') {
        $st = $pdo->prepare('SELECT * FROM chat_threads WHERE visitor_token = ? LIMIT 1');
        $st->execute([$tok]);
        if ($t = $st->fetch()) {
            if ($u && $t['user_id'] === null) {            // visitor logged in later: attach the chat to the account
                $pdo->prepare('UPDATE chat_threads SET user_id = ?, name = ? WHERE id = ?')->execute([$u['id'], $u['name'], $t['id']]);
                $t['user_id'] = $u['id'];
            }
            return $t;
        }
    }
    return null;
}

function chat_clean(string $s): string
{
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return mb_substr(trim($s), 0, 1000);
}
