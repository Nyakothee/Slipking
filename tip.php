<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/bookmakers.php';

$id = (int)($_GET['id'] ?? 0);
$t  = $id ? fetch_tip($id) : null;
if (!$t) {
    require __DIR__ . '/404.php';
    exit;
}
$vip    = tip_access($t);
$locked = $t['is_vip'] && !$vip && $t['status'] === 'pending';
$isTipster = !empty($t['tipster_slug']);

$page_title = $t['home_team'] . ' vs ' . $t['away_team'] . ' prediction - ' . SITE_NAME;
$page_desc  = $t['home_team'] . ' vs ' . $t['away_team'] . ' (' . $t['league'] . ') betting prediction and analysis.';
if (!(!empty($t['is_vip']) && $t['status'] === 'pending')) {
    $page_desc = $t['home_team'] . ' vs ' . $t['away_team'] . ' (' . $t['league'] . '): ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2) . ($t['status'] !== 'pending' ? ' - ' . strtoupper($t['status']) : '') . '. Posted before kickoff and kept on the public record.';
}
$active     = 'tips';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('tip.php?id=' . (int)$t['id']), 'image' => url('tip-image.php?id=' . (int)$t['id']), 'type' => 'article'])
    . seo_jsonld(['@context' => 'https://schema.org', '@type' => 'SportsEvent', 'name' => $t['home_team'] . ' vs ' . $t['away_team'], 'startDate' => date('c', strtotime($t['kickoff_at'])),
                  'sport' => 'Football', 'competitor' => [['@type' => 'SportsTeam', 'name' => $t['home_team']], ['@type' => 'SportsTeam', 'name' => $t['away_team']]],
                  'url' => url('tip.php?id=' . (int)$t['id']), 'image' => url('tip-image.php?id=' . (int)$t['id'])]);
include __DIR__ . '/includes/header.php';
?>
<?php if ($wb = widgets_block('tip')): ?><div class="wrap" style="padding-top:12px"><?= $wb ?></div><?php endif; ?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('tips.php')) ?>">Tips</a> / <?= e($t['sport']) ?> / <?= e($t['league']) ?></div>
  <h1 class="mtitle"><?= e($t['home_team']) ?> vs <?= e($t['away_team']) ?></h1>
  <p style="color:var(--mute)"><?= e(date('l j F Y, H:i', strtotime($t['kickoff_at']))) ?> &middot; posted <?= e(date('j M, H:i', strtotime($t['posted_at']))) ?><?php $pf = tip_proof($t); ?> &middot; <strong style="color:<?= $pf['early'] ? 'var(--win, #1b8a4b)' : 'var(--mute)' ?>"><?= $pf['early'] ? '&#10003; ' . e(span_label($pf['minutes'])) . ' before kickoff' : 'after kickoff' ?></strong></p>
  <?php require_once __DIR__ . '/includes/social.php'; echo share_buttons($t['home_team'] . ' vs ' . $t['away_team'] . ' prediction', url('tip.php?id=' . (int)$t['id'])); ?>
  <p><a class="btn dark small" href="<?= e(url('tip-image.php?id=' . (int)$t['id'] . '&dl=1')) ?>">&#128247; Save as picture</a></p>
</div>
<section style="padding-top:22px">
  <div class="wrap two">
    <div><?php tip_card($t, $vip); ?></div>
    <div class="box2">
      <?php if ($locked): ?>
        <?php if ($isTipster): ?>
        <h3>This is a premium tip</h3>
        <p>The pick, odds and analysis are for subscribers of <?= e($t['tipster_name']) ?> until the match is settled. After that they are shown here for everyone.</p>
        <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('tipster.php?slug=' . rawurlencode($t['tipster_slug']))) ?>">See their plans</a></p>
        <?php else: ?>
        <h3>This is a VIP tip</h3>
        <p>The pick, odds and analysis for this match are for VIP members until the match is settled. After that they are shown here for everyone.</p>
        <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('vip.php')) ?>">See VIP plans</a></p>
        <?php endif; ?>
      <?php else: ?>
        <h3><?= $isTipster ? 'Analysis by ' . e($t['tipster_name']) : 'Our analysis' ?></h3>
        <?php if (!empty($t['analysis'])): ?>
          <p><?= nl2br(e($t['analysis'])) ?></p>
        <?php else: ?>
          <p style="color:var(--mute)">No written analysis for this tip.</p>
        <?php endif; ?>
        <ul style="margin-top:14px">
          <li>Market: <?= e($t['market']) ?></li>
          <li>Pick: <strong><?= e($t['pick']) ?></strong> at <?= e(number_format((float)$t['odds'], 2)) ?></li>
          <li>Confidence: <?= (int)$t['confidence'] ?> out of 5</li>
          <li>Status: <?= e(ucfirst($t['status'])) ?></li>
        </ul>
        <?php if (!tip_is_locked($t)) { echo tip_odds_table($t, 'odds'); ?>
          <p style="margin-top:14px"><a class="btn dark small" href="<?= e(url('account/tracker.php?tip=' . (int)$t['id'])) ?>">Track this bet</a></p>
        <?php } ?>
      <?php endif; ?>
      <div class="note" style="margin-bottom:0">Odds change between bookmakers. Check the price you are offered before you bet. No tip is guaranteed.</div>
    </div>
  </div>
  <div class="wrap" style="margin-top:22px"><?php comments_render('tip', (int)$t['id'], 'tip.php?id=' . (int)$t['id']); ?></div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
