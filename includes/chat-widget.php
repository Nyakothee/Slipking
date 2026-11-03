<?php
/** The floating "Chat with us" button and panel. Included by footer.php on every public page. */
$cw_user = current_user();
?>
<div class="skchat" id="skchat" data-api="<?= e(url('api/chat.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-user="<?= $cw_user ? '1' : '0' ?>">
  <button class="skchat-btn" type="button" aria-expanded="false" aria-controls="skchat-panel">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg><span>Chat with us</span><i class="skchat-badge" hidden>0</i>
  </button>
  <div class="skchat-panel" id="skchat-panel" role="dialog" aria-label="Live chat" hidden>
    <div class="skchat-head"><span class="skchat-av" aria-hidden="true">SK</span><div class="skchat-ht"><strong>Chat with us</strong><small><i class="skchat-dot"></i>We reply as soon as we can</small></div><button class="skchat-x" type="button" aria-label="Close chat">&times;</button></div>
    <div class="skchat-msgs" id="skchat-msgs" aria-live="polite">
      <div class="skchat-hello"><b>Hello! &#128075;</b><span>How can we help? Send us a message and our team will get back to you.</span></div>
    </div>
    <form class="skchat-form" id="skchat-form" autocomplete="off">
      <?php if (!$cw_user): ?>
      <div class="skchat-who" id="skchat-who">
        <input type="text" name="name" maxlength="60" placeholder="Your name" aria-label="Your name">
        <input type="email" name="email" maxlength="150" placeholder="Email (optional)" aria-label="Email (optional)">
      </div>
      <?php endif; ?>
      <div class="skchat-row">
        <textarea name="body" rows="2" maxlength="1000" placeholder="Type your message" aria-label="Type your message" required></textarea>
        <button type="submit" aria-label="Send message"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg></button>
      </div>
      <p class="skchat-err" id="skchat-err" role="alert" hidden></p>
    </form>
    <p class="skchat-warn">Never share passwords or PINs in chat.</p>
  </div>
</div>
