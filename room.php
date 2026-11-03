<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$id = (int)($_GET['id'] ?? 0);
$m = $id ? seo_match($id) : null;
if (!$m) { require __DIR__ . '/404.php'; exit; }
$u = current_user();
$sort = in_array($_GET['sort'] ?? '', ['best', 'new', 'top', 'old'], true) ? $_GET['sort'] : 'best';
$back = 'room.php?id=' . $id . ($sort !== 'best' ? '&sort=' . $sort : '');
$home = $m['home_team']; $away = $m['away_team'];
$past = strtotime($m['kickoff_at']) <= time();
$lv = null;
try { $q = db()->prepare('SELECT live_status, live_minute, home_score, away_score FROM matches WHERE id = ?'); $q->execute([$id]); $lv = $q->fetch() ?: null; } catch (Throwable $e) {}
$rows = []; $tree = [[], []];
try { $rows = room_rows($id, $u); $tree = room_tree($rows, $sort); } catch (Throwable $e) { error_log('room: ' . $e->getMessage()); }
[$top, $kids] = $tree;
$block = comment_block_reason($u);
$members = room_top_members($id);
$ini = function ($s) { return mb_strtoupper(mb_substr(trim($s), 0, 2)); };
$page_title = $home . ' vs ' . $away . ' discussion room - ' . SITE_NAME;
$page_desc = 'Join the ' . $home . ' vs ' . $away . ' match discussion: comments, replies, votes and a community poll.';
$canonical = url('room.php?id=' . $id);
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/room.css')) . '?v=3">' . "\n" . '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
$active = 'discuss';
include __DIR__ . '/includes/header.php';
$liveTxt = '';
if ($lv && $lv['home_score'] !== null) { $liveTxt = (int)$lv['home_score'] . ' - ' . (int)$lv['away_score']; }
?>
<div class="wrap page-top rm">
  <div class="crumbs"><a href="<?= e(url('discuss.php?tab=matches')) ?>">Discussion</a> / <a href="<?= e(url('match.php?id=' . $id)) ?>"><?= e($home . ' vs ' . $away) ?></a></div>
  <div class="rm-banner">
    <div class="rm-teams">
      <span class="rm-crest"><?= e($ini($home)) ?></span><span class="rm-vs">vs</span><span class="rm-crest alt"><?= e($ini($away)) ?></span>
    </div>
    <div class="rm-title">
      <h1>m/<?= e(str_replace(' ', '', $home)) ?>-vs-<?= e(str_replace(' ', '', $away)) ?></h1>
      <p><?= e($home) ?> vs <?= e($away) ?> &middot; <?= e($m['league']) ?> &middot; <?= e(date('D j M, H:i', strtotime($m['kickoff_at']))) ?>
        <?php if ($lv && live_is_live($lv['live_status'])): ?><span class="rm-live">LIVE <?= e($liveTxt) ?></span><?php elseif ($liveTxt !== ''): ?><span class="rm-live ft">FT <?= e($liveTxt) ?></span><?php endif; ?></p>
    </div>
    <div class="rm-stats"><b><?= count($rows) ?></b><span>comments</span></div>
  </div>

  <div class="rm-layout">
    <main class="rm-main">
      <div class="rm-card rm-compose">
        <?php if ($block === ''): ?>
        <form method="post" action="<?= e(url('community/action.php')) ?>" enctype="multipart/form-data">
          <?= csrf_field() ?><input type="hidden" name="action" value="comment"><input type="hidden" name="type" value="match"><input type="hidden" name="target" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
          <div class="rm-cr"><?= user_avatar_html($u, 36) ?><textarea id="rm-new" name="body" rows="2" maxlength="<?= COMMENT_MAX_LEN ?>" placeholder="What do you think about <?= e($home . ' vs ' . $away) ?>? Add a photo or emoji too."></textarea></div>
          <?= composer_tools_html() ?>
          <div class="rm-cb"><span class="rm-cc"><b id="rm-cnt">0</b>/<?= COMMENT_MAX_LEN ?></span><button class="btn dark" type="submit">Post</button></div>
        </form>
        <?php elseif (!$u): ?>
        <p class="rm-login"><strong>Join the conversation.</strong> <a class="btn dark small" href="<?= e(url('auth/login.php?next=' . rawurlencode($back))) ?>">Log in</a> <a class="btn small" href="<?= e(url('auth/register.php')) ?>">Sign up free</a></p>
        <?php else: ?><p class="rm-login"><?= e($block) ?></p><?php endif; ?>
      </div>

      <div class="rm-sort" role="tablist" aria-label="Sort comments">
        <?php foreach (['best' => 'Best', 'new' => 'New', 'top' => 'Top', 'old' => 'Oldest'] as $k => $l): ?>
          <a href="<?= e(url('room.php?id=' . $id . '&sort=' . $k)) ?>" class="<?= $sort === $k ? 'on' : '' ?>"><?= e($l) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="rm-list" id="comments">
        <?php if (!$top): ?><div class="rm-empty"><strong>No comments yet</strong><br>Be the first to start the discussion for this match.</div><?php endif; ?>
        <?php foreach ($top as $c) { echo room_comment_html($c, $kids, $u, $id, $back); } ?>
      </div>
      <p class="rm-rulesnote">Comments are moderated. Not betting advice. Be kind, no links, no spam.</p>
    </main>

    <aside class="rm-side">
      <div class="rm-card"><h3>About this room</h3>
        <p><?= e($home) ?> vs <?= e($away) ?><br><small><?= e($m['league']) ?></small></p>
        <ul class="rm-facts"><li><span>Kickoff</span><b><?= e(date('D j M, H:i', strtotime($m['kickoff_at']))) ?></b></li>
          <li><span>Status</span><b><?= $past ? ($lv && live_is_live($lv['live_status']) ? 'Live' : 'Started / finished') : 'Not started' ?></b></li>
          <li><span>Comments</span><b><?= count($rows) ?></b></li></ul>
        <a class="btn dark small" style="width:100%;text-align:center" href="<?= e(url('match.php?id=' . $id)) ?>">Tips for this match</a></div>
      <?= poll_html($m, $u, $back) ?>
      <div class="rm-card"><h3>Room rules</h3>
        <ol class="rm-rules"><li>Be respectful. No insults or hate.</li><li>No links, ads or tout accounts.</li><li>Never share passwords, PINs or codes.</li><li>Tips here are opinions, not advice. Bet responsibly, 18+.</li><li>Report anything that breaks the rules.</li></ol></div>
      <?php if ($members): ?><div class="rm-card"><h3>Top contributors</h3>
        <ul class="rm-top"><?php foreach ($members as $i => $mm): ?><li><span><?= $i + 1 ?></span> u/<?= e(mask_name((string)$mm['name'])) ?><b><?= (int)$mm['n'] ?></b></li><?php endforeach; ?></ul></div><?php endif; ?>
    </aside>
  </div>
</div>
<script src="<?= e(url('assets/js/composer.js')) ?>?v=2"></script>
<script>
(function(){
  var n=document.getElementById('rm-new'),c=document.getElementById('rm-cnt');
  if(n&&c){n.addEventListener('input',function(){c.textContent=n.value.length;});}
  document.addEventListener('click',function(e){
    var t=e.target.closest('[data-reply],[data-cancel],[data-fold],[data-share]');if(!t)return;
    if(t.hasAttribute('data-reply')){var f=document.getElementById('reply-'+t.getAttribute('data-reply'));if(f){f.hidden=!f.hidden;if(!f.hidden){var a=f.querySelector('textarea');if(a)a.focus();}}}
    else if(t.hasAttribute('data-cancel')){var g=document.getElementById('reply-'+t.getAttribute('data-cancel'));if(g)g.hidden=true;}
    else if(t.hasAttribute('data-fold')){var art=document.getElementById('c'+t.getAttribute('data-fold'));if(art)art.classList.toggle('folded');}
    else if(t.hasAttribute('data-share')){var u=t.getAttribute('data-share');
      if(navigator.clipboard){navigator.clipboard.writeText(u).then(function(){var o=t.innerHTML;t.textContent='Link copied';setTimeout(function(){t.innerHTML=o;},1500);});}
      else{window.prompt('Copy this link',u);}}
  });
  if(location.hash){var h=document.querySelector(location.hash);if(h){h.classList.add('flash-c');}}
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
