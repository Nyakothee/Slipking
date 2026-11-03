<?php
/** Private messages, part 2: live thread, typing, online dot, reactions, reply, pictures, voice notes, search, mute, delete for me, auto-moderation, chat pins and rules. */

function dm2_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            if (!dm_ready()) { return $ok = false; }
            if (setting('dm2_ready', '0') === '1') { return $ok = true; }
            $ok = sk_ensure_column('dm_messages', 'reply_to', 'BIGINT UNSIGNED NULL DEFAULT NULL')
               && sk_ensure_column('dm_messages', 'attach', 'VARCHAR(120) NULL DEFAULT NULL')
               && sk_ensure_column('dm_messages', 'attach_kind', 'CHAR(1) NULL DEFAULT NULL')
               && sk_ensure_column('dm_messages', 'hide_from', 'TINYINT(1) NOT NULL DEFAULT 0')
               && sk_ensure_column('dm_messages', 'hide_to', 'TINYINT(1) NOT NULL DEFAULT 0')
               && sk_ensure_table('dm_reactions', 'database-update-43.sql') && sk_ensure_table('dm_mutes', 'database-update-43.sql')
               && sk_ensure_table('dm_typing', 'database-update-43.sql') && sk_ensure_table('chat_pins', 'database-update-43.sql');
            if ($ok) { set_setting('dm2_ready', '1'); }
        } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Auto-moderation for chat and messages: banned words, phone numbers, shouting, repeated text. Returns a reason or ''. */
function automod_reason(string $text, int $uid = 0): string
{
    $t = mb_strtolower($text);
    foreach (preg_split('/[\s,]+/', mb_strtolower((string)setting('chat_banned_words', 'fuck,bitch,whatsapp me,dm me for tips,fixed match,sure odds')), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        if ($w !== '' && mb_strpos($t, $w) !== false) { return 'That message breaks the chat rules (language or advertising).'; }
    }
    if (preg_match('/(\+?\d[\d\s\-]{8,}\d)/', $text)) { return 'Do not share phone numbers in chat.'; }
    if (mb_strlen($text) > 12 && preg_match_all('/\p{Lu}/u', $text) / max(1, preg_match_all('/\p{L}/u', $text)) > 0.8) { return 'Please do not write in capital letters only.'; }
    if (preg_match('/(.)\1{9,}/u', $text)) { return 'Please do not repeat characters.'; }
    if ($uid > 0) {
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM dm_messages WHERE from_id = ? AND body = ? AND created_at > NOW() - INTERVAL 5 MINUTE');
            $st->execute([$uid, $text]);
            if ((int)$st->fetchColumn() >= 2) { return 'You already sent this message.'; }
        } catch (Throwable $e) { }
    }
    return '';
}

function dm2_online(int $uid): bool
{
    try { $st = db()->prepare('SELECT 1 FROM user_presence WHERE user_id = ? AND seen_at > NOW() - INTERVAL 3 MINUTE'); $st->execute([$uid]); return (bool)$st->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function dm2_typing_set(int $uid, int $to): void
{
    db()->prepare('INSERT INTO dm_typing (user_id, to_id, at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE at = NOW()')->execute([$uid, $to]);
}

function dm2_is_typing(int $from, int $to): bool
{
    $st = db()->prepare('SELECT 1 FROM dm_typing WHERE user_id = ? AND to_id = ? AND at > NOW() - INTERVAL 6 SECOND');
    $st->execute([$from, $to]);
    return (bool)$st->fetchColumn();
}

function dm2_muted(int $uid, int $other): bool
{
    $st = db()->prepare('SELECT 1 FROM dm_mutes WHERE user_id = ? AND other_id = ?');
    $st->execute([$uid, $other]);
    return (bool)$st->fetchColumn();
}

function dm2_mute_toggle(int $uid, int $other): bool
{
    if (dm2_muted($uid, $other)) { db()->prepare('DELETE FROM dm_mutes WHERE user_id = ? AND other_id = ?')->execute([$uid, $other]); return false; }
    db()->prepare('INSERT IGNORE INTO dm_mutes (user_id, other_id) VALUES (?,?)')->execute([$uid, $other]);
    return true;
}

/** Messages of one conversation as plain arrays. $afterId > 0 returns only newer ones; $q filters by text. */
function dm2_thread(int $uid, int $with, int $afterId = 0, string $q = ''): array
{
    $sql = 'SELECT m.id, m.from_id, m.body, m.created_at, m.reply_to, m.attach, m.attach_kind, m.read_at FROM dm_messages m
            WHERE m.hidden = 0 AND ((m.from_id = ? AND m.to_id = ? AND m.hide_from = 0) OR (m.from_id = ? AND m.to_id = ? AND m.hide_to = 0))';
    $args = [$uid, $with, $with, $uid];
    if ($afterId > 0) { $sql .= ' AND m.id > ?'; $args[] = $afterId; }
    if ($q !== '') { $sql .= ' AND m.body LIKE ?'; $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; }
    $sql .= ' ORDER BY m.id DESC LIMIT ' . ($q !== '' ? 40 : 100);
    $st = db()->prepare($sql);
    $st->execute($args);
    $rows = array_reverse($st->fetchAll());
    if (!$rows) { return []; }
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $rx = [];
    foreach (db()->query('SELECT msg_id, user_id, emoji FROM dm_reactions WHERE msg_id IN (' . implode(',', $ids) . ')')->fetchAll() as $r) {
        $rx[(int)$r['msg_id']][] = [(int)$r['user_id'], $r['emoji']];
    }
    $replyIds = array_filter(array_map(function ($r) { return (int)$r['reply_to']; }, $rows));
    $rep = [];
    if ($replyIds) {
        foreach (db()->query('SELECT id, from_id, body, attach_kind FROM dm_messages WHERE id IN (' . implode(',', array_unique($replyIds)) . ')')->fetchAll() as $r) {
            $rep[(int)$r['id']] = ['id' => (int)$r['id'], 'mine' => (int)$r['from_id'] === $uid, 'body' => mb_substr((string)$r['body'] !== '' ? (string)$r['body'] : ($r['attach_kind'] === 'a' ? 'Voice note' : 'Photo'), 0, 80)];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $mid = (int)$r['id'];
        $counts = []; $mine = '';
        foreach ($rx[$mid] ?? [] as [$ru, $em]) { $counts[$em] = ($counts[$em] ?? 0) + 1; if ($ru === $uid) { $mine = $em; } }
        $out[] = ['id' => $mid, 'mine' => (int)$r['from_id'] === $uid, 'body' => (string)$r['body'], 'time' => date('H:i', strtotime($r['created_at'])), 'day' => date('Y-m-d', strtotime($r['created_at'])),
                  'seen' => (int)$r['from_id'] === $uid && !empty($r['read_at']),
                  'reply' => $rep[(int)$r['reply_to']] ?? null, 'kind' => (string)($r['attach_kind'] ?? ''),
                  'url' => !empty($r['attach']) ? ($r['attach_kind'] === 'a' ? url((string)$r['attach']) : media_src((string)$r['attach'])) : '',
                  'reactions' => $counts, 'my_reaction' => $mine];
    }
    return $out;
}

/** Everything the live view needs besides messages. */
function dm2_status(int $uid, int $with): array
{
    return ['online' => dm2_online($with), 'typing' => dm2_is_typing($with, $uid), 'muted' => dm2_muted($uid, $with)];
}

/** Stores a voice note (audio blob). Returns [path|null, error]. */
function dm2_store_voice(?array $file, int $uid): array
{
    if (!$file || ($file['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { return [null, 'The voice note could not be uploaded.']; }
    if ($file['size'] > 2097152) { return [null, 'The voice note is too long (2 MB maximum).']; }
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : (string)($file['type'] ?? '');
    $map = ['audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/ogg' => 'ogg', 'application/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'video/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/aac' => 'aac', 'audio/3gpp' => '3gp'];
    if (!isset($map[$mime])) { return [null, 'That audio type is not allowed.']; }
    $dir = dirname(__DIR__) . '/uploads/voice';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents($dir . '/.htaccess', "RemoveHandler .php .phtml\nphp_flag engine off\nOptions -Indexes\n");
    $name = $uid . '-' . bin2hex(random_bytes(10)) . '.' . $map[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) { return [null, 'The voice note could not be saved.']; }
    return ['uploads/voice/' . $name, ''];
}

/** Sends one message. $files: $_FILES array. Returns [ok, error|id]. */
function dm2_send(array $u, int $to, string $body, int $replyTo = 0, array $files = [], string $voiceKey = 'voice'): array
{
    $uid = (int)$u['id'];
    $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $body));
    $body = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $body));
    $hasImg = !empty($files['file']) && ($files['file']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
    $hasVoice = !empty($files[$voiceKey]) && ($files[$voiceKey]['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
    $why = dm_block_reason($u, $to);
    $tq = db()->prepare('SELECT id FROM users WHERE id = ?'); $tq->execute([$to]);
    if ($why === '' && (!$tq->fetchColumn() || $to === $uid)) { $why = 'Choose someone else to message.'; }
    if ($why === '' && dm_has_blocked($to, $uid)) { $why = 'This member is not accepting messages from you.'; }
    if ($why === '' && dm_has_blocked($uid, $to)) { $why = 'You blocked this member. Unblock them first.'; }
    if ($body === '' && !$hasImg && !$hasVoice) { $why = $why ?: 'Type a message.'; }
    if ($why === '' && $body !== '') { $why = comment_text_error($body); if ($why !== '') { /* short texts like "ok" are fine */ if (mb_strlen($body) < 2 && ($hasImg || $hasVoice)) { $why = ''; } } }
    if ($why === '' && $body !== '') { $why = spam_block_reason($u, $body, 'dm'); }
    if ($why === '' && $body !== '') { $why = automod_reason($body, $uid); }
    if ($why !== '') { return [false, $why]; }
    $attach = null; $kind = null;
    if ($hasImg) {
        $err = '';
        $attach = media_store($files['file'], 'chat', $uid, 'fit', $err, 6291456); $kind = 'i';
        if ($attach === null) { return [false, $err ?: 'The picture could not be saved.']; }
    } elseif ($hasVoice) {
        [$attach, $err] = dm2_store_voice($files[$voiceKey], $uid); $kind = 'a';
        if ($attach === null) { return [false, $err]; }
    }
    if ($replyTo) { $rq = db()->prepare('SELECT 1 FROM dm_messages WHERE id = ? AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?))'); $rq->execute([$replyTo, $uid, $to, $to, $uid]); if (!$rq->fetchColumn()) { $replyTo = 0; } }
    $had = db()->prepare('SELECT COUNT(*) FROM dm_messages WHERE from_id = ? AND to_id = ? AND read_at IS NULL AND created_at > NOW() - INTERVAL 2 MINUTE');
    $had->execute([$uid, $to]); $recent = (int)$had->fetchColumn();
    db()->prepare('INSERT INTO dm_messages (from_id, to_id, body, reply_to, attach, attach_kind) VALUES (?,?,?,?,?,?)')->execute([$uid, $to, $body, $replyTo ?: null, $attach, $kind]);
    $id = (int)db()->lastInsertId();
    db()->prepare('DELETE FROM dm_typing WHERE user_id = ? AND to_id = ?')->execute([$uid, $to]);
    if ($recent === 0 && !dm2_muted($to, $uid)) {
        cmty_alert($to, 'New message from ' . mask_name((string)$u['name']), $body !== '' ? mb_substr($body, 0, 100) : ($kind === 'a' ? 'Voice note' : 'Photo'), 'account/messages.php?with=' . $uid);
    }
    return [true, $id];
}

function dm2_react(int $uid, int $msgId, string $emoji): bool
{
    $allowed = ['👍', '❤️', '😂', '😮', '🔥', '⚽'];
    $q = db()->prepare('SELECT 1 FROM dm_messages WHERE id = ? AND (from_id = ? OR to_id = ?)'); $q->execute([$msgId, $uid, $uid]);
    if (!$q->fetchColumn()) { return false; }
    if (!in_array($emoji, $allowed, true)) { db()->prepare('DELETE FROM dm_reactions WHERE msg_id = ? AND user_id = ?')->execute([$msgId, $uid]); return true; }
    $cur = db()->prepare('SELECT emoji FROM dm_reactions WHERE msg_id = ? AND user_id = ?'); $cur->execute([$msgId, $uid]);
    if ($cur->fetchColumn() === $emoji) { db()->prepare('DELETE FROM dm_reactions WHERE msg_id = ? AND user_id = ?')->execute([$msgId, $uid]); return true; }
    db()->prepare('INSERT INTO dm_reactions (msg_id, user_id, emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)')->execute([$msgId, $uid, $emoji]);
    return true;
}

/** "Delete for me": hides the message on my side only. */
function dm2_hide_for_me(int $uid, int $msgId): void
{
    db()->prepare('UPDATE dm_messages SET hide_from = 1 WHERE id = ? AND from_id = ?')->execute([$msgId, $uid]);
    db()->prepare('UPDATE dm_messages SET hide_to = 1 WHERE id = ? AND to_id = ?')->execute([$msgId, $uid]);
}

/* ---------- chat pins and rules ---------- */

/** Pinned announcements for a chat room (0 = Global Chat, 1e9+match = match room, group id = group chat). */
function chat_pins(int $room): array
{
    if (!dm2_ready()) { return []; }
    try {
        $st = db()->prepare('SELECT body FROM chat_pins WHERE room IN (?, 0) AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 3');
        $st->execute([$room]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

function chat_rules_text(): string
{
    return (string)setting('chat_rules', "1. Be kind. No insults or hate.\n2. No links, phone numbers or advertising.\n3. No selling tips or asking for money.\n4. Betting is risky - never share passwords or codes.\n5. Staff may remove messages and ban repeat offenders.");
}
