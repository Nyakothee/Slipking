<?php
require_once __DIR__ . '/includes/init.php';
$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['cat'] ?? ''));
$rows = channels_fetch($q, $cat);
$cats = channel_categories();
$page_title = 'Channels - watch live games - ' . SITE_NAME;
$active = 'channels';
include __DIR__ . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2>Channels</h2><p>Pick a channel and tap Watch. It plays right here on the site.</p></div></div>
  <form method="get" class="ch-search" role="search">
    <input type="search" name="q" id="ch-q" value="<?= e($q) ?>" placeholder="Search channels..." aria-label="Search channels" autocomplete="off">
    <?php if ($cats): ?><select name="cat" aria-label="Category" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"<?= $c === $cat ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select><?php endif; ?>
    <button class="btn gold" type="submit">Search</button>
  </form>
  <?php if ($rows): ?>
  <div class="ch-grid" id="ch-list">
    <?php foreach ($rows as $c): $bs = channel_buttons($c); if (!$bs) { continue; } ?>
      <div class="ch-card" data-t="<?= e(mb_strtolower($c['name'] . ' ' . $c['category'])) ?>">
        <div class="ch-pic"><?= thumb_img($c['thumb'], 'thumb', $c['name']) ?><?php if ($c['category'] !== ''): ?><span class="ch-cat"><?= e($c['category']) ?></span><?php endif; ?></div>
        <div class="ch-name"><?= e($c['name']) ?></div>
        <div class="ch-btns"><?php foreach ($bs as $i => $b): ?><a class="btn <?= $i ? 'dark' : 'gold' ?> tiny" href="<?= e($b['watch']) ?>" data-play="<?= e($b['play']) ?>" data-title="<?= e($c['name']) ?>">&#9654; <?= e($b['label']) ?></a><?php endforeach; ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <script>(function(){var q=document.getElementById('ch-q'),cs=document.querySelectorAll('#ch-list .ch-card');q.addEventListener('input',function(){var v=q.value.toLowerCase().trim();cs.forEach(function(c){c.style.display=c.dataset.t.indexOf(v)>-1?'':'none';});});})();</script>
  <?php else: ?><div class="empty"><?= $q !== '' || $cat !== '' ? 'No channels match your search.' : 'No channels have been added yet. Check back soon.' ?></div><?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
