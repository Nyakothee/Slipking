<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('chat');

$admin_title = 'Live chat';
$admin_active = 'chat';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Live chat</h1>
<p class="sub">Keep this page open to get messages quickly (it refreshes by itself). You are also emailed when a new chat starts. Never ask anyone for a password or PIN.</p>
<div class="chatlayout" id="achat" data-api="<?= e(url('admin/chat-api.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-open="<?= (int)($_GET['thread'] ?? 0) ?>" data-userurl="<?= e(url('admin/user-view.php?id=')) ?>">
  <div class="panel chatlist"><h2>Conversations</h2><div id="athreads"><p style="color:var(--mute)">Loading...</p></div></div>
  <div class="panel chatconv">
    <div id="ahead" class="chathead"><span style="color:var(--mute)">Choose a conversation.</span></div>
    <div id="amsgs" class="amsgs"></div>
    <form id="areply" class="areply" hidden>
      <textarea name="body" rows="2" maxlength="1000" placeholder="Type your reply" aria-label="Your reply" required></textarea>
      <button class="btn gold" type="submit">Send</button>
    </form>
  </div>
</div>
<script src="<?= e(url('assets/js/admin-chat.js')) ?>"></script>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
