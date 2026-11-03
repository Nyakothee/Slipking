<?php
require_once __DIR__ . '/includes/init.php';

$vip  = is_vip();
if (($_GET['kind'] ?? '') === 'vip') { redirect('vip-accumulators.php'); }
$kind = 'million';
$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));

$st = db()->prepare('SELECT COUNT(*) FROM accumulators WHERE kind = ? AND (deleted_at IS NULL OR status IN (\'won\',\'lost\'))');
$st->execute([$kind]);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$st = db()->prepare("SELECT a.*, (SELECT COUNT(*) FROM accumulator_legs l WHERE l.accumulator_id = a.id) AS legs
                     FROM accumulators a WHERE a.kind = ? AND (a.deleted_at IS NULL OR a.status IN ('won','lost'))
                     ORDER BY a.posted_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute([$kind]);
$rows = $st->fetchAll();

$st = db()->prepare("SELECT SUM(status = 'won') AS w, SUM(status = 'lost') AS l FROM accumulators WHERE kind = ? AND status IN ('won','lost')");
$st->execute([$kind]);
$rec = $st->fetch();
$won = (int)$rec['w']; $lost = (int)$rec['l'];

$page_title = acca_kind_label($kind) . ' - ' . SITE_NAME;
$page_desc  = 'Long accumulators with the total odds and the minimum stake shown. Every result is published.';
$active     = 'accas';
include __DIR__ . '/includes/header.php';
?>
<?php if ($wb = widgets_block('accas')): ?><div class="wrap" style="padding-top:12px"><?= $wb ?></div><?php endif; ?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Accumulators</h2><p>One long ticket of many selections. Every selection must win.</p></div></div>
    <div class="risk">
      <strong>Please read this first.</strong>
      <span>An accumulator wins only if every selection wins, and one loss means the whole ticket loses. With dozens of selections the odds are huge because the chance is tiny, so most tickets lose. Only stake an amount you can afford to lose completely.</span>
      <span style="display:block;margin-top:6px"><strong>Our record so far:</strong> <span><?= $won ?> won, <?= $lost ?> lost (<?= $won + $lost ?> settled)</span></span>
    </div>

    <?php if ($rows): ?>
    <div class="acards">
      <?php foreach ($rows as $a):
          $locked = $a['kind'] === 'vip' && !$vip && $a['status'] === 'pending'; ?>
        <div class="acard">
          <div class="row"><span><?= e(date('j M Y', strtotime($a['posted_at']))) ?></span>
            <span><?= $a['kind'] === 'vip' ? '<span class="vipflag">VIP</span> ' : '' ?><span class="badge <?= e($a['status']) ?>"><?= e(ucfirst($a['status'])) ?></span></span></div>
          <h3><?= e($a['title']) ?></h3>
          <div class="bigodds"><?= $locked ? '<span class="vip-mask">VIP</span>' : e(number_format((float)$a['total_odds'], 2)) ?></div>
          <div class="row"><span>Total odds</span><b><?= (int)$a['legs'] ?> selections</b></div>
          <?php if ($locked): ?><div class="row"><span>Minimum stake</span><b>VIP members only</b></div><?php else: ?><div class="row"><span>Minimum stake for <?= e(acca_target_display((int)$a['target_kes'])) ?></span><b><?= e(acca_min_stake_display((int)$a['target_kes'], (float)$a['total_odds'])) ?></b></div><?php endif; ?>
          <div class="row"><span>Matches</span><b><?= e(date('j M', strtotime($a['first_kickoff']))) ?><?= date('Y-m-d', strtotime($a['first_kickoff'])) !== date('Y-m-d', strtotime($a['last_kickoff'])) ? ' - ' . e(date('j M', strtotime($a['last_kickoff']))) : '' ?></b></div>
          <?php if ($locked): ?>
            <a class="btn gold full" href="<?= e(url('vip.php')) ?>">Unlock with VIP</a>
          <?php else: ?>
            <a class="btn dark full" href="<?= e(url('acca.php?id=' . (int)$a['id'])) ?>">See all selections</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?= pager($page, $pages, 'accumulators.php') ?>
    <?php else: ?>
      <div class="empty">No <?= $kind === 'vip' ? 'VIP' : '1 Million' ?> accumulator has been posted yet. Check back soon.</div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
