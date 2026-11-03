<?php
require_once __DIR__ . '/includes/init.php';

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM accumulators WHERE id = ? AND (deleted_at IS NULL OR status IN ('won','lost')) LIMIT 1");
$st->execute([$id]);
$a = $st->fetch();
if (!$a) {
    require __DIR__ . '/404.php';
    exit;
}

$vip    = is_vip();
$locked = $a['kind'] === 'vip' && !has_vip_acca() && $a['status'] === 'pending';   // a VIP ticket becomes public once it is settled

$legs = [];
if (!$locked) {                                    // locked tickets: the selections are never sent to the browser
    $st = db()->prepare('SELECT * FROM accumulator_legs WHERE accumulator_id = ? ORDER BY position');
    $st->execute([$id]);
    $legs = $st->fetchAll();
}
$odds     = $legs ? acca_effective_odds($legs) : (float)$a['total_odds'];
$hasVoid  = $legs && abs($odds - (float)$a['total_odds']) > 0.004;
$cur      = current_currency();
$minStake = max(1, (int)ceil(convert_kes_to((float)$a['target_kes'], $cur['code']) / max(1.01, $odds)));
$won = $lostN = $pend = 0;
foreach ($legs as $l) { if ($l['status'] === 'won') $won++; elseif ($l['status'] === 'lost') $lostN++; elseif ($l['status'] === 'pending') $pend++; }
$wonProduct = 1.0;
foreach ($legs as $l) { if ($l['status'] === 'won') { $wonProduct *= (float)$l['odds']; } }
$cashMargin = max(0.0, min(40.0, (float)setting('cashout_margin_pct', '6'))) / 100;
$legCount = $legs ? count($legs) : (int)db()->query('SELECT COUNT(*) FROM accumulator_legs WHERE accumulator_id = ' . (int)$id)->fetchColumn();

$page_title = $a['title'] . ' - ' . SITE_NAME;
$page_desc  = $locked ? acca_kind_label($a['kind']) . ': ' . $legCount . ' selections, for VIP members.' : acca_kind_label($a['kind']) . ': ' . $legCount . ' selections, total odds ' . number_format((float)$a['total_odds'], 2) . '.';
$active     = 'accas';
$extra_js   = ['assets/js/acca.js'];
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url($a['kind'] === 'vip' ? 'vip-accumulators.php' : 'accumulators.php')) ?>"><?= e(acca_kind_label($a['kind'])) ?></a></div>
  <h1 class="mtitle"><?= e($a['title']) ?></h1>
  <p style="color:var(--mute)">Posted <?= e(date('j M Y, H:i', strtotime($a['posted_at']))) ?> &middot; <?= $legCount ?> selections &middot;
    <span class="badge <?= e($a['status']) ?>"><?= e(ucfirst($a['status'])) ?></span><?= $a['kind'] === 'vip' ? ' <span class="vipflag">VIP</span>' : '' ?></p>
</div>

<section style="padding-top:18px">
  <div class="wrap">
    <div class="stats">
      <?php if ($locked): ?>
      <div class="stat"><b>VIP</b><span>total odds are for VIP members</span></div>
      <div class="stat"><b style="font-size:28px">VIP</b><span>minimum stake and chances are shown to members</span></div>
      <?php else: ?>
      <div class="stat"><b><?= e(number_format($odds, 2)) ?></b><span>total odds</span><?= $hasVoid ? ' <span>(void selections removed)</span>' : '' ?></div>
      <div class="stat"><b style="font-size:28px"><?= e(acca_min_stake_display((int)$a['target_kes'], $odds)) ?></b><span>minimum stake to return <?= e(acca_target_display((int)$a['target_kes'])) ?> or more</span></div>
      <div class="stat"><b style="font-size:28px">1 in <?= e(number_format($odds)) ?></b><span>roughly the chance all selections win, as the odds see it</span></div>
      <?php if ($legs): ?><div class="stat"><b style="font-size:28px"><?= $won ?> / <?= $legCount ?></b><span>won: <?= $won ?>, lost: <?= $lostN ?>, still to play: <?= $pend ?></span></div><?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="risk"><span>Every selection must win. One loss loses the whole ticket. The "minimum stake" is only the smallest stake that would reach the target if every selection wins, and it is not advice to stake that amount. Odds change between bookmakers, so check your price. 18+ only.</span> <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Responsible gambling</a></div>

    <?php if ($locked): ?>
      <div class="box2 center" style="max-width:640px;margin:0 auto">
        <h3>This is a VIP accumulator</h3>
        <p>The <?= $legCount ?> selections are included with the Weekly plan and above, until the ticket is settled. After that they are shown here for everyone, whether it won or lost.</p>
        <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('vip.php')) ?>">See Weekly and longer plans</a></p>
      </div>
    <?php else: ?>
      <div class="calc" data-odds="<?= e(number_format($odds, 2, '.', '')) ?>" data-symbol="<?= e($cur['symbol']) ?>" data-decimals="<?= (int)$cur['decimals'] ?>" data-min="<?= $minStake ?>"
           data-state="<?= e($a['status']) ?>" data-wonprod="<?= e(number_format($wonProduct, 6, '.', '')) ?>" data-margin="<?= e((string)$cashMargin) ?>" data-won="<?= $won ?>" data-pend="<?= $pend ?>">
        <div class="calc-main">
          <div class="calc-stake">
            <label for="stake">Your stake (<?= e($cur['code']) ?>)</label>
            <input type="number" id="stake" min="1" step="1" value="<?= $minStake ?>" inputmode="numeric">
            <div class="out" id="ret" aria-live="polite"></div>
          </div>
          <?php if ($legs): ?>
          <div class="calc-cash">
            <span class="cash-lab">Cash out now</span>
            <button type="button" id="cashbtn" class="cash-btn" aria-describedby="cashinfo"><span id="cashval">-</span></button>
            <small id="cashsub" aria-live="polite"></small>
          </div>
          <?php endif; ?>
        </div>
        <div style="font-size:14px;color:#b9d3c7;margin-top:6px">Return if every selection wins = stake x total odds. Estimate only.</div>
        <?php if ($legs): ?><div id="cashinfo" class="cash-info" hidden>This is an estimate of the cash-out a bookmaker would typically offer: your stake multiplied by the odds of the selections that already won, less a margin (<?= e(rtrim(rtrim(number_format($cashMargin * 100, 1), '0'), '.')) ?>%), as bookmakers do. It moves up as selections win and drops to nothing if one loses. We do not take bets, so this is information only. Your bookmaker's real cash-out depends on its own live odds and can be different.</div><?php endif; ?>
      </div>

      <div class="scroll"><table class="tbl legs">
        <thead><tr><th>#</th><th>Match</th><th>Kickoff</th><th>Pick</th><th>Odds</th><th>Result</th></tr></thead>
        <tbody>
        <?php foreach ($legs as $i => $l): ?>
          <tr class="<?= e($l['status']) ?>">
            <td><?= $i + 1 ?></td>
            <td><?= e($l['home_team']) ?> vs <?= e($l['away_team']) ?><br><small style="color:var(--mute)"><?= e($l['league']) ?></small></td>
            <td><?= e(date('j M, H:i', strtotime($l['kickoff_at']))) ?></td>
            <td><?= e($l['pick']) ?></td>
            <td><?= e(number_format((float)$l['odds'], 2)) ?></td>
            <td><span class="badge <?= e($l['status']) ?>"><?= e(ucfirst($l['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4">Total odds</td><td colspan="2"><?= e(number_format($odds, 2)) ?></td></tr></tfoot>
      </table></div>
      <?php if (!empty($a['notes'])): ?><div class="note"><?= nl2br(e($a['notes'])) ?></div><?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
