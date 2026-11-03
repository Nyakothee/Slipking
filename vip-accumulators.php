<?php
require_once __DIR__ . '/includes/init.php';

$ok = has_vip_acca();
$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$rows = []; $pages = 1; $won = $lost = 0;
$st = db()->query("SELECT SUM(status = 'won') AS w, SUM(status = 'lost') AS l FROM accumulators WHERE kind = 'vip' AND status IN ('won','lost')");
$rec = $st->fetch();
$won = (int)$rec['w']; $lost = (int)$rec['l'];
if ($ok) {
    $total = (int)db()->query("SELECT COUNT(*) FROM accumulators WHERE kind = 'vip' AND (deleted_at IS NULL OR status IN ('won','lost'))")->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $rows = db()->query("SELECT a.*, (SELECT COUNT(*) FROM accumulator_legs l WHERE l.accumulator_id = a.id) AS legs
                         FROM accumulators a WHERE a.kind = 'vip' AND (a.deleted_at IS NULL OR a.status IN ('won','lost'))
                         ORDER BY a.posted_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage))->fetchAll();
}
$page_title = 'VIP Accumulator - ' . SITE_NAME;
$page_desc  = 'The VIP accumulator is included with the Weekly plan and above.';
$active     = 'vip';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <?= vip_tabs('acca') ?>
    <div class="head"><div><h2>VIP Accumulator</h2><p>Included with the Weekly plan and every longer plan.</p></div></div>
    <div class="risk">
      <strong>Please read this first.</strong>
      <span>An accumulator wins only if every selection wins, and one loss means the whole ticket loses. Most long tickets lose. Only stake an amount you can afford to lose completely.</span>
      <span style="display:block;margin-top:6px"><strong>Our VIP accumulator record:</strong> <?= $won ?> won, <?= $lost ?> lost (<?= $won + $lost ?> settled)</span>
    </div>
    <?php if (!$ok): ?>
      <div class="box2 center" style="max-width:640px;margin:0 auto">
        <h3>For Weekly, Monthly and Elite members</h3>
        <p>Subscribe to the Weekly plan or longer to see the VIP accumulator selections, total odds, minimum stake and the cash-out estimate.<?= is_logged_in() && is_vip() ? ' Your current access is shorter than a Weekly plan, so it does not include this.' : '' ?></p>
        <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('vip.php')) ?>">See VIP plans</a></p>
      </div>
    <?php elseif ($rows): ?>
    <div class="acards">
      <?php foreach ($rows as $a): ?>
        <div class="acard">
          <div class="row"><span><?= e(date('j M Y', strtotime($a['posted_at']))) ?></span>
            <span><span class="vipflag">VIP</span> <span class="badge <?= e($a['status']) ?>"><?= e(ucfirst($a['status'])) ?></span></span></div>
          <h3><?= e($a['title']) ?></h3>
          <div class="bigodds"><?= e(number_format((float)$a['total_odds'], 2)) ?></div>
          <div class="row"><span>Total odds</span><b><?= (int)$a['legs'] ?> selections</b></div>
          <div class="row"><span>Minimum stake for <?= e(acca_target_display((int)$a['target_kes'])) ?></span><b><?= e(acca_min_stake_display((int)$a['target_kes'], (float)$a['total_odds'])) ?></b></div>
          <div class="row"><span>Matches</span><b><?= e(date('j M', strtotime($a['first_kickoff']))) ?><?= date('Y-m-d', strtotime($a['first_kickoff'])) !== date('Y-m-d', strtotime($a['last_kickoff'])) ? ' - ' . e(date('j M', strtotime($a['last_kickoff']))) : '' ?></b></div>
          <a class="btn dark full" href="<?= e(url('acca.php?id=' . (int)$a['id'])) ?>">See all selections</a>
        </div>
      <?php endforeach; ?>
    </div>
    <?= pager($page, $pages, 'vip-accumulators.php') ?>
    <?php else: ?>
      <div class="empty">No VIP accumulator has been posted yet. Check back soon.</div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
