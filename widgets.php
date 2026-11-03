<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$slug = widget_slug((string)($_GET['p'] ?? ''));
$pages = widget_pages();
$title = 'Tables and stats';
$ws = [];
if ($slug !== '') {
    $ws = widgets_for('page', $slug);
    if (!$ws) { http_response_code(404); require __DIR__ . '/404.php'; exit; }
    foreach ($pages as $pg) { if ($pg['page_slug'] === $slug) { $title = $pg['page_title'] ?: $title; } }
}
$page_title = $title . ' - ' . SITE_NAME;
$page_desc  = 'League tables, upcoming matches, team stats and live match trackers.';
$active = 'livestream';
include __DIR__ . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h1><?= e($title) ?></h1><p>Live data from our football widget partners.</p></div></div>
  <?php if ($slug === ''): ?>
    <?php if ($pages): ?><div class="wgt-pages"><?php foreach ($pages as $pg): ?>
      <a class="wgt-page" href="<?= e(url('widgets.php?p=' . urlencode($pg['page_slug']))) ?>"><strong><?= e($pg['page_title']) ?></strong><span><?= (int)$pg['n'] ?> widget<?= (int)$pg['n'] === 1 ? '' : 's' ?></span></a>
    <?php endforeach; ?></div>
    <?php else: ?><div class="empty">Tables and stats will appear here soon.</div><?php endif; ?>
  <?php else: ?>
    <?= widgets_block('page', $slug) ?>
  <?php endif; ?>
  <div class="note">Widgets are provided by third parties and can be late or wrong. 18+ only.</div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
