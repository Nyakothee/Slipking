<?php
/** The chat panel used by Global Chat (room 0) and by each group's Chat tab (room = community id). Needs assets/js/globalchat.js and global-chat.css. */
function chat_box_html(int $room, ?array $u, string $title, string $sub = ''): string
{
    $block = gchat_block_reason($u);
    $staff = function_exists('is_staff') && is_staff() ? 1 : 0;
    ob_start(); ?>
    <div class="gc" id="gc" data-api="<?= e(url('api/globalchat.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-room="<?= (int)$room ?>" data-staff="<?= $staff ?>">
      <div class="gc-head"><span class="gc-ic">&#128172;</span><div><h1><?= e($title) ?></h1><small><i class="gc-dot"></i><span id="gc-active">0</span> online &middot; <?= e($sub !== '' ? $sub : 'be kind, no links, no tips-selling') ?></small></div></div>
      <?php if ($pins = chat_pins($room)): ?><div class="gc-pins" role="note"><?php foreach ($pins as $pn): ?><div>&#128204; <?= e($pn) ?></div><?php endforeach; ?></div><?php endif; ?>
      <div class="gc-list" id="gc-list" aria-live="polite"><p class="gc-empty" id="gc-empty">Loading messages...</p></div>
      <div class="gc-typing" id="gc-typing" hidden></div>
      <?php if ($block === ''): ?>
      <div class="gc-pre" id="gc-reply" hidden><span class="gc-rq">&#8617;</span><span class="gc-pre-n" id="gc-reply-n"></span><button type="button" id="gc-reply-x" aria-label="Cancel reply">&times;</button></div>
      <div class="gc-pre" id="gc-pre" hidden><span id="gc-pre-i"></span><span class="gc-pre-n" id="gc-pre-n"></span><button type="button" id="gc-pre-x" aria-label="Remove attachment">&times;</button></div>
      <form class="gc-form" id="gc-form" autocomplete="off">
        <label class="gc-ib" title="Attach a picture or document" aria-label="Attach a picture or document"><input type="file" id="gc-file" accept="image/jpeg,image/png,image/webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.4 11.1l-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/></svg></label>
        <button type="button" class="gc-ib" id="gc-emo" title="Emoji" aria-label="Emoji">&#128512;</button>
        <input type="text" id="gc-in" maxlength="300" placeholder="Say something..." aria-label="Message">
        <button type="submit" class="gc-send" aria-label="Send">Send</button>
      </form>
      <div class="gc-ep" id="gc-ep" hidden></div>
      <p class="gc-err" id="gc-err" role="alert" hidden></p>
      <?php else: ?>
      <div class="gc-form off"><span><?= e($block) ?></span><?php if (!$u): ?><a href="<?= e(url('auth/login.php?next=' . rawurlencode($room ? 'm.php' : 'globalchat.php'))) ?>">Log in</a><?php endif; ?></div>
      <?php endif; ?>
    </div>
<script>(function(){try{if(localStorage.getItem('sk_gc_rules'))return;var o=document.createElement('div');o.className='rules-ov';var t=<?= json_encode(chat_rules_text()) ?>;var b=document.createElement('div');b.className='rules-box';var h=document.createElement('h3');h.textContent='Chat rules';var p=document.createElement('p');p.style.whiteSpace='pre-line';p.textContent=t;var k=document.createElement('button');k.type='button';k.className='btn gold';k.textContent='I agree';k.onclick=function(){try{localStorage.setItem('sk_gc_rules','1')}catch(e){}o.remove()};b.appendChild(h);b.appendChild(p);b.appendChild(k);o.appendChild(b);document.body.appendChild(o)}catch(e){}})();</script>
<?php
    return (string)ob_get_clean();
}
