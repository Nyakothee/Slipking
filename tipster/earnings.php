<?php
require_once dirname(__DIR__) . '/includes/init.php';
$t = require_tipster(false);
$pdo = db();
$min = max(0, (int)setting('tipster_min_payout_kes', '1000'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $r = payout_withdraw($t);
        set_flash($r['ok'] ? 'success' : 'error', $r['message']);
    }
    redirect('tipster/earnings.php');
}
$bal = tipster_balance((int)$t['id']);
$st = $pdo->prepare("SELECT e.*, p.name AS plan_name FROM tipster_earnings e JOIN plans p ON p.id = e.plan_id WHERE e.tipster_id = ? ORDER BY e.id DESC LIMIT 50");
$st->execute([$t['id']]);
$sales = $st->fetchAll();
$st = $pdo->prepare('SELECT * FROM tipster_payouts WHERE tipster_id = ? ORDER BY id DESC LIMIT 30');
$st->execute([$t['id']]);
$pays = $st->fetchAll();
$next = payout_next_allowed((int)$t['id']);
$why = payout_unavailable_reason((string)$t['payout_method']);
$days = payout_window_days();
$usdHint = $t['payout_method'] === 'paypal' ? payout_kes_to_usd($bal['available']) : 0;

$page_title = 'Earnings - ' . SITE_NAME;
$active = 'tipster-earnings';
$tnav = 'earnings';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2>Wallet and earnings</h2><p>Amounts are in KES. New sales are held for <?= (int)setting('tipster_hold_days', '7') ?> days (refund window), then join your available balance. You can withdraw once every <?= $days ?> days and the money is sent to you automatically.</p></div></div>
  <?php include dirname(__DIR__) . '/includes/tipster-nav.php'; ?>
  <div class="stats">
    <div class="stat"><b>KES <?= e(number_format($bal['net_total'], 0)) ?></b><span>earned in total (after commission)</span></div>
    <div class="stat"><b>KES <?= e(number_format($bal['held'], 0)) ?></b><span>still on hold</span></div>
    <div class="stat"><b>KES <?= e(number_format($bal['paid'], 0)) ?></b><span>already paid to you</span></div>
    <div class="stat"><b>KES <?= e(number_format($bal['available'], 0)) ?></b><span>available now</span></div>
  </div>
  <form method="post" class="box2" style="max-width:620px" onsubmit="return confirm('Withdraw KES <?= e(number_format($bal['available'], 0)) ?> to your <?= e(payout_method_label((string)$t['payout_method'])) ?> now? You can withdraw again in <?= $days ?> days.');"><?= csrf_field() ?>
    <h3 style="margin-top:0">Withdraw</h3>
    <p>Your money is sent to your <strong><?= e(payout_method_label((string)$t['payout_method'])) ?></strong>: <?= e($t['payout_details']) ?> (<a href="<?= e(url('tipster/profile.php')) ?>">change</a>)</p>
    <?php if ($t['payout_method'] === 'paypal' && $usdHint > 0): ?><p style="color:var(--mute);font-size:14px">PayPal pays in US dollars: about USD <?= e(number_format($usdHint, 2)) ?> at today's rate.</p><?php endif; ?>
    <?php if ($bal['requested'] > 0): ?><p class="note" style="margin-top:0">KES <?= e(number_format($bal['requested'], 0)) ?> is on its way to you now.</p><?php endif; ?>
    <?php $blocked = $bal['available'] < max($min, 1) || $t['status'] !== 'active' || $next > 0 || $why !== ''; ?>
    <button class="btn gold" type="submit"<?= $blocked ? ' disabled' : '' ?>>Withdraw KES <?= e(number_format($bal['available'], 0)) ?></button>
    <?php if ($next > 0): ?><p class="note"><strong>Next withdrawal:</strong> <?= e(date('j F Y, H:i', $next)) ?> (one withdrawal every <?= $days ?> days).</p>
    <?php elseif ($why !== ''): ?><p class="note"><?= e($why) ?></p>
    <?php elseif ($bal['available'] < max($min, 1)): ?><p style="color:var(--mute);font-size:14px">You can withdraw once your available balance reaches KES <?= e(number_format($min, 0)) ?>.</p><?php endif; ?>
    <p style="color:var(--mute);font-size:13px">Rules: one withdrawal every <?= $days ?> days, minimum KES <?= e(number_format($min, 0)) ?>, the whole available balance is sent at once. If a payment fails, the money stays in your balance and the waiting time does not start.</p>
  </form>

  <h3 style="margin-top:24px">Sales</h3>
  <div class="panel"><div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Plan</th><th>Paid by member</th><th>Site commission</th><th>You earn</th><th>Available from</th></tr></thead><tbody>
  <?php foreach ($sales as $r): ?><tr><td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><?= e($r['plan_name']) ?></td><td>KES <?= e(number_format((float)$r['gross_kes'], 0)) ?></td>
    <td><?= (int)$r['commission_percent'] ?>% (KES <?= e(number_format((float)$r['commission_kes'], 0)) ?>)</td>
    <td><?= $r['status'] === 'ok' ? '<strong>KES ' . e(number_format((float)$r['net_kes'], 0)) . '</strong>' : '<span class="badge lost">Reversed</span>' ?></td>
    <td><?= e(date('j M Y', strtotime($r['available_at']))) ?></td></tr>
  <?php endforeach; if (!$sales): ?><tr><td colspan="6" style="color:var(--mute)">No sales yet.</td></tr><?php endif; ?></tbody></table></div></div>

  <h3 style="margin-top:24px">Withdrawals</h3>
  <div class="panel"><div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead><tbody>
  <?php foreach ($pays as $r): ?><tr><td><?= e(date('j M Y', strtotime($r['requested_at']))) ?></td><td>KES <?= e(number_format((float)$r['amount_kes'], 0)) ?></td>
    <td><span class="badge <?= $r['status'] === 'paid' ? 'won' : (in_array($r['status'], ['requested', 'processing'], true) ? 'pending' : 'lost') ?>"><?= e(['paid' => 'Paid', 'requested' => 'Queued', 'processing' => 'Sending', 'rejected' => 'Failed'][$r['status']] ?? ucfirst($r['status'])) ?></span></td><td><?= e($r['reference'] ?: $r['note'] ?: '-') ?></td></tr>
  <?php endforeach; if (!$pays): ?><tr><td colspan="4" style="color:var(--mute)">No withdrawals yet.</td></tr><?php endif; ?></tbody></table></div></div>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
