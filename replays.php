<?php
require_once __DIR__ . '/includes/init.php';
$page_title = 'Replays - watch past games - ' . SITE_NAME;
$active = 'results';
$rows = replays_list();
include __DIR__ . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2>Replays</h2><p>Watch past games in two halves. Tap the half you want.</p></div></div>
  <?php if ($rows): ?>
  <input type="search" id="rp-q" placeholder="Search a game..." aria-label="Search replays" style="width:100%;max-width:420px;margin:0 0 16px;padding:12px 14px;border:1px solid var(--line,#d6ded9);border-radius:12px">
  <div class="rp-grid" id="rp-list">
    <?php foreach ($rows as $r): ?>
      <div class="rp-card" data-t="<?= e(mb_strtolower($r['title'])) ?>">
        <?= !empty($r['thumb']) ? '<div class="rp-thumb">' . thumb_img($r['thumb'], 'thumb', $r['title']) . '</div>' : '' ?>
        <div class="rp-date"><?= $r['played_on'] ? e(date('D, j M Y', strtotime($r['played_on']))) : 'Date not set' ?></div>
        <div class="rp-title"><?= e($r['title']) ?></div>
        <div class="rp-btns">
          <a class="btn gold" href="<?= e(stream_watch_url($r['link1'], $r['title'] . ' - first half')) ?>" data-play="<?= e(stream_watch_url($r['link1'], $r['title'] . ' - first half', 'embed')) ?>" data-title="<?= e($r['title'] . ' - first half') ?>">&#9654; First half</a>
          <a class="btn dark" href="<?= e(stream_watch_url($r['link2'], $r['title'] . ' - second half')) ?>" data-play="<?= e(stream_watch_url($r['link2'], $r['title'] . ' - second half', 'embed')) ?>" data-title="<?= e($r['title'] . ' - second half') ?>">&#9654; Second half</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <script>document.getElementById('rp-q').addEventListener('input',function(){var q=this.value.toLowerCase();document.querySelectorAll('#rp-list .rp-card').forEach(function(c){c.style.display=c.dataset.t.indexOf(q)>-1?'':'none';});});</script>
  <?php else: ?><div class="empty">No replays have been added yet. Check back soon.</div><?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
