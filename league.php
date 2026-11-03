<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$id = (int)($_GET['id'] ?? 0);
$l  = $id ? seo_league($id) : null;
if (!$l) {
    require __DIR__ . '/404.php';
    exit;
}
$vip      = is_vip();
$upcoming = seo_tips_query(['league_id' => $id, 'when' => 'upcoming', 'limit' => 24]);
$recent   = seo_tips_query(['league_id' => $id, 'when' => 'recent', 'limit' => 12]);
$rec      = seo_league_record($id);
$rec60    = seo_league_record($id, 60);

$page_title = $l['name'] . ' betting tips and predictions - ' . SITE_NAME;
$page_desc  = $l['name'] . ' (' . $l['sport'] . ') predictions: ' . count($upcoming) . ' upcoming tips and our recent results'
            . ($rec['win_rate'] !== null ? ', ' . $rec['win_rate'] . '% won across ' . $rec['settled'] . ' settled tips.' : '.');
$page_desc  = seo_trim($page_desc, 158);
$canonical  = url('league.php?id=' . $id);
$active     = 'tips';

$head_extra  = '<link rel="stylesheet" href="' . e(url('assets/css/group-c.css')) . '">' . "\n";
$head_extra .= seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => $canonical,
                         'robots' => ($upcoming || $recent) ? '' : 'noindex,follow']);
$head_extra .= seo_breadcrumb_jsonld([['Home', url()], ['Tips', url('tips.php')], [$l['name']]]);
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('tips.php')) ?>">Tips</a> / <?= e($l['sport']) ?></div>
  <h1 class="mtitle"><?= e($l['name']) ?> tips</h1>
</div>
<section style="padding-top:22px">
  <div class="wrap">
    <div class="stats">
      <div class="stat"><b><?= $rec['win_rate'] !== null ? (int)$rec['win_rate'] . '%' : '-' ?></b><span>Win rate, all time (<?= (int)$rec['settled'] ?> settled)</span></div>
      <div class="stat"><b><?= $rec60['win_rate'] !== null ? (int)$rec60['win_rate'] . '%' : '-' ?></b><span>Win rate, last 60 days (<?= (int)$rec60['settled'] ?> settled)</span></div>
      <div class="stat"><b><?= (int)$rec['won'] ?> - <?= (int)$rec['lost'] ?></b><span>Won - lost</span></div>
    </div>

    <div class="head"><h2>Upcoming tips</h2></div>
    <?php if ($upcoming): ?>
      <div class="grid"><?php foreach ($upcoming as $t) { tip_card($t, $vip); } ?></div>
    <?php else: ?>
      <div class="empty">No upcoming tips for this league right now.</div>
    <?php endif; ?>

    <div class="head" style="margin-top:34px"><h2>Recent results</h2><a class="btn dark" href="<?= e(url('results.php')) ?>">Full results</a></div>
    <?php if ($recent): ?>
      <div class="grid"><?php foreach ($recent as $t) { tip_card($t, $vip); } ?></div>
    <?php else: ?>
      <div class="empty">No settled tips for this league yet.</div>
    <?php endif; ?>
    <div class="note">Past results do not guarantee future results. 18+ only. <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Gamble responsibly</a>.</div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
