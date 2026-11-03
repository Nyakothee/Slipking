<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$hid = (int)($_GET['id'] ?? 0);
$hv = null;
if ($hid && streams_ready()) {
    $hst = db()->prepare("SELECT * FROM streams WHERE id = ? AND is_active = 1 AND kind = 'highlight'");
    $hst->execute([$hid]);
    $hv = $hst->fetch();
}
if (!$hv) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
$hyt = stream_youtube_id((string)$hv['url']);
if ($hyt === '') { redirect('go-stream.php?id=' . $hid); }
$page_title = $hv['title'] . ' - highlights - ' . SITE_NAME;
$page_desc  = ($hv['description'] !== '' ? $hv['description'] : $hv['title'] . ' match highlights.');
$active = 'livestream';
try {
    $hrel = array_values(array_filter(streams_fetch('highlight', 7), function ($hr) use ($hid) { return (int)$hr['id'] !== $hid; }));
    $hrel = array_slice($hrel, 0, 6);
} catch (Throwable $ex) {
    error_log('highlight related: ' . $ex->getMessage());
    $hrel = [];
}
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/livestream.css')) . '?v=1">' . seo_meta(['title' => $page_title, 'description' => $page_desc, 'image' => stream_thumb($hv), 'type' => 'article']);
include __DIR__ . '/includes/header.php';
?>
<section class="ls-sec"><div class="wrap" style="max-width:920px">
  <div class="crumbs"><a href="<?= e(url('livestream.php')) ?>">Livestream</a> / <a href="<?= e(url('livestream.php#highlights')) ?>">Highlights</a></div>
  <h1 class="mtitle" style="margin-top:6px"><?= e($hv['title']) ?></h1>
  <p class="ls-meta" style="margin:4px 0 14px"><?= e($hv['league']) ?><?php if (!empty($hv['starts_at'])): ?> &middot; <?= e(date('j M Y', strtotime((string)$hv['starts_at']))) ?><?php endif; ?></p>
  <div class="ls-player"><iframe src="https://www.youtube-nocookie.com/embed/<?= e($hyt) ?>?rel=0" title="<?= e($hv['title']) ?>" loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>
  <?php if ($hv['description'] !== ''): ?><p style="margin-top:12px"><?= e($hv['description']) ?></p><?php endif; ?>
  <p class="ls-meta" style="margin-top:10px">Video from YouTube. <a href="<?= e(stream_go_url($hid)) ?>" target="_blank" rel="nofollow noopener">Open on YouTube</a></p>
</div></section>
<?php if ($hrel): ?><section class="ls-sec alt"><div class="wrap"><h2>More highlights</h2><div class="ls-grid"><?php
foreach ($hrel as $hr) {
    $hth = stream_thumb($hr); ?>
    <a class="ls-card" href="<?= e(url('highlight.php?id=' . (int)$hr['id'])) ?>"><span class="ls-thumb"<?= $hth !== '' ? ' style="background-image:url(\'' . e($hth) . '\')"' : '' ?>><span class="ls-play" aria-hidden="true">&#9654;</span></span>
    <span class="ls-body"><span class="ls-league"><?= e($hr['league'] ?: 'Highlights') ?></span><strong class="ls-title"><?= e($hr['title']) ?></strong></span></a>
<?php } ?></div></div></section><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
