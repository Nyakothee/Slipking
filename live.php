<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$ms = live_matches();
$page_title = 'Live match centre - ' . SITE_NAME;
$page_desc  = 'Live football scores and match events with our tips attached. Updates by itself, no refresh needed.';
$active = 'live';
$live_on = setting('live_enabled', '0') === '1';
include __DIR__ . '/includes/header.php';
$tipsBy = [];
foreach ($ms as $m) { $tipsBy[(int)$m['id']] = live_tips_public((int)$m['id']); }
?>
<div class="wrap page-top">
  <h1>Live match centre</h1>
  <p style="color:var(--mute);max-width:700px">Scores and key events refresh on their own, with the tips we posted on each match. <span id="lv-state" class="lv-state">Updating</span></p>
</div>
<section style="padding-top:6px"><div class="wrap">
<?= widgets_block('live') ?>
<?php if (!$live_on): ?><div class="note" style="margin-top:0">Automatic live scores are switched off right now, so scores may be added by hand by our team after matches.</div><?php endif; ?>
<?php if (!$ms): ?>
  <div class="empty">No matches today. Check back soon, or see <a href="<?= e(url('tips.php')) ?>">today's tips</a>.</div>
<?php else: $lastLeague = ''; ?>
  <div class="lv-list" id="lv-list" data-url="<?= e(url('api/live.php')) ?>">
  <?php foreach ($ms as $m): $mid = (int)$m['id']; $lab = live_status_label($m['live_status'], $m['live_minute'] !== null ? (int)$m['live_minute'] : null); ?>
    <?php if ($m['league'] !== $lastLeague): $lastLeague = $m['league']; ?><h3 class="lv-league"><?= e($lastLeague) ?></h3><?php endif; ?>
    <article class="lv-card<?= live_is_live($m['live_status']) ? ' is-live' : '' ?>" data-id="<?= $mid ?>">
      <a class="lv-main" href="<?= e(url('match.php?id=' . $mid)) ?>">
        <span class="lv-time" data-label><?= $lab !== '' ? e($lab) : e(date('H:i', strtotime($m['kickoff_at']))) ?></span>
        <span class="lv-teams"><span><?= e($m['home_team']) ?></span><span><?= e($m['away_team']) ?></span></span>
        <span class="lv-score"><b data-home><?= $m['home_score'] !== null ? (int)$m['home_score'] : '-' ?></b><b data-away><?= $m['away_score'] !== null ? (int)$m['away_score'] : '-' ?></b></span>
      </a>
      <?php if ($tipsBy[$mid]): ?><div class="lv-tips"><?php foreach ($tipsBy[$mid] as $t): ?>
        <a class="lv-tip st-<?= e($t['status']) ?>" data-tip="<?= (int)$t['id'] ?>" href="<?= e(url('tip.php?id=' . $t['id'])) ?>">
          <?php if ($t['pick'] !== null): ?><?= e($t['market']) ?>: <b><?= e($t['pick']) ?></b> @ <?= e($t['odds']) ?><?php else: ?><b>Premium tip</b> (VIP)<?php endif; ?>
          <span class="lv-res" data-res><?= e($t['status'] === 'pending' ? '' : ucfirst($t['status'])) ?></span></a>
      <?php endforeach; ?></div><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
  <div class="note">Scores come from a third-party feed or from our team and can be late or wrong. Betting tips are settled separately. 18+ only.</div>
</div></section>
<script src="<?= e(url('assets/js/live.js')) ?>"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
