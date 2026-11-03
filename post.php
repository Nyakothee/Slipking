<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$id = (int)($_GET['id'] ?? 0);
$post = null; $c = null;
if ($id && cmty_ready()) {
    $st = db()->prepare("SELECT target_id, parent_id FROM comments WHERE id = ? AND target_type = 'community' AND status = 'visible'");
    $st->execute([$id]);
    $r = $st->fetch();
    if ($r && !$r['parent_id']) { $c = cmty_by_id((int)$r['target_id']); }
}
if (!$c || !(int)$c['is_active']) { require __DIR__ . '/404.php'; exit; }
$u = current_user();
if (function_exists('cmty3_ready') && cmty3_ready() && !empty($c['is_private']) && !cmty_can_take_part($c, $u)) { require __DIR__ . '/404.php'; exit; }
$cid = (int)$c['id'];
$back = 'post.php?id=' . $id;
$rows = [];
try { $rows = room_rows($cid, $u, 'community', 2500); } catch (Throwable $e) { error_log('post.php: ' . $e->getMessage()); }
// keep only this post and its replies
$byId = []; foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
$keep = [$id => true]; $changed = true;
while ($changed) { $changed = false; foreach ($rows as $r) { if ($r['parent_id'] && isset($keep[(int)$r['parent_id']]) && !isset($keep[(int)$r['id']])) { $keep[(int)$r['id']] = true; $changed = true; } } }
$rows = array_values(array_filter($rows, function ($r) use ($keep) { return isset($keep[(int)$r['id']]); }));
$post = $byId[$id] ?? null;
if (!$post) { require __DIR__ . '/404.php'; exit; }
[$top, $kids] = room_tree($rows, 'best');
$block = comment_block_reason($u);
$page_title = $post['title'] . ' - m/' . $c['name'] . ' - ' . SITE_NAME;
$page_desc = mb_substr($post['body'] !== '' ? $post['body'] : $post['title'], 0, 150);
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n";
$active = 'discuss';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top rm">
  <div class="crumbs"><a href="<?= e(url('discuss.php?tab=communities')) ?>">Discussion</a> / <a href="<?= e(url('m.php?s=' . $c['slug'])) ?>">m/<?= e($c['name']) ?></a></div>
  <div class="rm-layout">
    <main class="rm-main">
      <div class="rm-card" style="padding:10px 14px">
        <?php foreach ($top as $p) { echo room_comment_html($p, $kids, $u, $cid, $back, 0, 'community'); } ?>
      </div>
      <?php if (!$u): ?><p class="rm-login"><strong>Join the conversation.</strong> <a class="btn dark small" href="<?= e(url('auth/login.php?next=' . rawurlencode($back))) ?>">Log in</a> <a class="btn small" href="<?= e(url('auth/register.php')) ?>">Sign up free</a></p>
      <?php elseif ($block !== ''): ?><p class="rm-login"><?= e($block) ?></p><?php endif; ?>
      <p class="rm-rulesnote">Posts are moderated. Not betting advice. Be kind, no links, no spam.</p>
    </main>
    <aside class="rm-side">
      <div class="rm-card"><h3>m/<?= e($c['name']) ?></h3><p><?= e($c['description']) ?></p><a class="btn dark small" style="width:100%;text-align:center" href="<?= e(url('m.php?s=' . $c['slug'])) ?>">Back to community</a></div>
    </aside>
  </div>
</div>
<script src="<?= e(url('assets/js/composer.js')) ?>?v=2"></script>
<script>
(function(){
  document.addEventListener('click',function(e){
    var t=e.target.closest('[data-reply],[data-cancel],[data-fold],[data-share]');if(!t)return;
    if(t.hasAttribute('data-reply')){var f=document.getElementById('reply-'+t.getAttribute('data-reply'));if(f){f.hidden=!f.hidden;if(!f.hidden){var a=f.querySelector('textarea');if(a)a.focus();}}}
    else if(t.hasAttribute('data-cancel')){var g=document.getElementById('reply-'+t.getAttribute('data-cancel'));if(g)g.hidden=true;}
    else if(t.hasAttribute('data-fold')){var art=document.getElementById('c'+t.getAttribute('data-fold'));if(art)art.classList.toggle('folded');}
    else if(t.hasAttribute('data-share')){var u=t.getAttribute('data-share');
      if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){var o=t.innerHTML;t.textContent='Link copied';setTimeout(function(){t.innerHTML=o;},1500);});}
      else{window.prompt('Copy this link',u);}}
  });
  var h=location.hash;if(h){if(h.indexOf('#reply-')===0){var f=document.querySelector(h);if(f){f.hidden=false;}}else{var el=document.querySelector(h);if(el){el.classList.add('flash-c');}}}
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
