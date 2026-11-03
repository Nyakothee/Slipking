<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$english_only = (function_exists('lang_code') && lang_code() !== 'sw');
$u = current_user(); $uid = (int)$u['id'];
$ready = dm_ready();
if ($ready) { dm2_ready(); }
$with = (int)($_GET['with'] ?? 0);
$pdo = db();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = (int)($_POST['to'] ?? 0); $act = (string)($_POST['action'] ?? '');
    $go = 'account/messages.php' . ($to ? '?with=' . $to : '');
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($go); }
    if ($act === 'send') {
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)($_POST['body'] ?? '')));
        $body = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $body));
        $why = dm_block_reason($u, $to);
        $tq = $pdo->prepare('SELECT id, name FROM users WHERE id = ?'); $tq->execute([$to]); $tu = $tq->fetch();
        if ($why === '' && (!$tu || $to === $uid)) { $why = 'Choose someone else to message.'; }
        if ($why === '' && dm_has_blocked($to, $uid)) { $why = 'This member is not accepting messages from you.'; }
        if ($why === '' && dm_has_blocked($uid, $to)) { $why = 'You blocked this member. Unblock them first.'; }
        if ($why === '') { $why = comment_text_error($body); }
        if ($why === '') { $why = spam_block_reason($u, $body, 'dm'); }
        if ($why !== '') { set_flash('error', $why); redirect($go); }
        $had = $pdo->prepare('SELECT COUNT(*) FROM dm_messages WHERE from_id = ? AND to_id = ? AND read_at IS NULL AND created_at > NOW() - INTERVAL 2 MINUTE');
        $had->execute([$uid, $to]); $recent = (int)$had->fetchColumn();
        $pdo->prepare('INSERT INTO dm_messages (from_id, to_id, body) VALUES (?, ?, ?)')->execute([$uid, $to, $body]);
        if ($recent === 0) { cmty_alert($to, 'New message from ' . mask_name((string)$u['name']), mb_substr($body, 0, 100), 'account/messages.php?with=' . $uid); }
        redirect('account/messages.php?with=' . $to . '#end');
    }
    if ($act === 'block' || $act === 'unblock') {
        if ($to && $to !== $uid) {
            if ($act === 'block') { $pdo->prepare('INSERT IGNORE INTO dm_blocks (user_id, blocked_id) VALUES (?, ?)')->execute([$uid, $to]); set_flash('success', 'Blocked. They can no longer message you.'); }
            else { $pdo->prepare('DELETE FROM dm_blocks WHERE user_id = ? AND blocked_id = ?')->execute([$uid, $to]); set_flash('success', 'Unblocked.'); }
        }
        redirect($go);
    }
    if ($act === 'report') {
        $mid = (int)($_POST['id'] ?? 0);
        $mq = $pdo->prepare('SELECT 1 FROM dm_messages WHERE id = ? AND to_id = ?'); $mq->execute([$mid, $uid]);
        if ($mq->fetchColumn()) { $pdo->prepare('INSERT IGNORE INTO dm_reports (msg_id, user_id) VALUES (?, ?)')->execute([$mid, $uid]); set_flash('success', 'Thanks. Our team will look at this message.'); }
        redirect($go);
    }
    if ($act === 'delete') {
        $pdo->prepare('UPDATE dm_messages SET hidden = 1 WHERE id = ? AND from_id = ?')->execute([(int)($_POST['id'] ?? 0), $uid]);
        redirect($go);
    }
    redirect('account/messages.php');
}

$other = null; $msgs = []; $iBlocked = false;
if ($ready && $with && $with !== $uid) {
    $oq = $pdo->prepare('SELECT id, name' . profile_sql('u') . ' FROM users u WHERE u.id = ?'); $oq->execute([$with]); $other = $oq->fetch() ?: null;
    if ($other) {
        $mq = $pdo->prepare('SELECT id, from_id, body, created_at FROM dm_messages WHERE hidden = 0 AND ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) ORDER BY id DESC LIMIT 100');
        $mq->execute([$uid, $with, $with, $uid]); $msgs = array_reverse($mq->fetchAll());
        $pdo->prepare('UPDATE dm_messages SET read_at = NOW() WHERE to_id = ? AND from_id = ? AND read_at IS NULL')->execute([$uid, $with]);
        $iBlocked = dm_has_blocked($uid, $with);
    }
}
$convs = $ready ? dm_conversations($uid) : [];
$fl = $other ? member_flags([$with]) : [];
presence_touch($u);

$page_title = 'Messages - ' . SITE_NAME;
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n"
    . '<link rel="stylesheet" href="' . e(url('assets/css/discuss-hub.css')) . '?v=5">' . "\n"
    . '<link rel="stylesheet" href="' . e(url('assets/css/group-page.css')) . '?v=9">' . "\n";
$active = 'discuss'; $dhActive = 'messages';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="dh-page">
<div class="dh">
 <div class="dh-layout">
<?php include dirname(__DIR__) . '/includes/dh-side.php'; ?>
  <main class="dh-main dm">
<?php if (!$ready): ?><div class="note">Messages are not available yet. Import database-update-25.sql.</div><?php else: ?>
    <div class="dm-cols<?= $other ? ' open' : '' ?>">
      <section class="dm-list gp-card"><h3>Messages</h3>
        <?php if (!$convs): ?><p class="gp-muted">No conversations yet. Open a member's post menu (&middot;&middot;&middot;) and choose "Send a message".</p><?php endif; ?>
        <?php foreach ($convs as $cv): ?>
          <a class="dm-c<?= (int)$cv['oid'] === $with ? ' on' : '' ?>" href="<?= e(url('account/messages.php?with=' . (int)$cv['oid'])) ?>"><span class="dm-av"><?= user_avatar_html($cv, 40) ?><?= dm2_online((int)$cv['oid']) ? '<i class="on-dot" title="Online"></i>' : '' ?></span>
            <span><strong><?= e(mask_name((string)$cv['name'])) ?></strong><small><?= (int)$cv['from_id'] === $uid ? 'You: ' : '' ?><?= e(mb_strimwidth((string)$cv['body'], 0, 46, '...')) ?></small></span>
            <?php if ((int)$cv['unread'] > 0): ?><b class="dh-badge"><?= (int)$cv['unread'] ?></b><?php endif; ?></a>
        <?php endforeach; ?>
      </section>
      <section class="dm-thread gp-card">
      <?php if (!$other): ?><div class="gp-empty"><strong>Pick a conversation</strong><br>Your private messages with other members appear here. Be kind. No links, no betting-site ads.</div>
      <?php else: ?>
        <div class="dm-head"><a class="dm-back" href="<?= e(url('account/messages.php')) ?>" aria-label="Back to messages">&larr;</a><?= user_avatar_html($other, 40) ?>
          <div><strong><?= !empty($fl[$with]) ? '<a href="' . e(url('member.php?u=' . $fl[$with]['handle'])) . '">' . e(mask_name((string)$other['name'])) . '</a>' . member_verified_html($fl[$with]) : e(mask_name((string)$other['name'])) ?></strong></div>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $iBlocked ? 'unblock' : 'block' ?>"><input type="hidden" name="to" value="<?= $with ?>"><button type="submit" class="gp-alt sm"><?= $iBlocked ? 'Unblock' : 'Block' ?></button></form></div>
        <div class="dm-tools"><span id="dm-st" class="dm-st"></span>
          <input type="search" id="dm-q" placeholder="Search this chat" aria-label="Search this chat">
          <button type="button" class="gp-alt sm" id="dm-mute"></button></div>
        <div class="dm-msgs" id="dm-msgs" aria-live="polite"></div>
        <?php if ($iBlocked): ?><p class="gp-muted">You blocked this member.</p><?php else: ?>
        <div class="dm-rep" id="dm-rep" hidden><span id="dm-rep-t"></span> <button type="button" id="dm-rep-x" aria-label="Cancel reply">&times;</button></div>
        <form class="dm-form" id="dm-form" autocomplete="off">
          <label class="gc-ib" title="Send a picture"><input type="file" id="dm-file" accept="image/jpeg,image/png,image/webp" hidden>&#128247;</label>
          <button type="button" class="gc-ib" id="dm-mic" title="Record a voice note" aria-label="Record a voice note">&#127908;</button>
          <textarea id="dm-body" rows="1" maxlength="1000" placeholder="Write a message..." aria-label="Message"></textarea><button class="gp-post" type="submit">Send</button></form>
        <p class="gc-err" id="dm-err" role="alert" hidden></p><?php endif; ?>
        <div id="dm-boot" data-api="<?= e(url('api/dm.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-with="<?= (int)$with ?>" data-rules="<?= e(chat_rules_text()) ?>" hidden></div>
      <?php endif; ?>
      </section>
    </div>
<?php endif; ?>
  </main>
 </div>
</div>
</div>
<script src="<?= e(url('assets/js/dm.js')) ?>?v=1"></script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
