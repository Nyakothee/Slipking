<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/mpesa-b2c.php';
require_perm('payouts');
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'b2c') {
            $id = (int)($_POST['id'] ?? 0);
            $orig = 'SK' . $id . 'X' . strtoupper(bin2hex(random_bytes(6)));
            $st = $pdo->prepare("SELECT po.*, ti.user_id AS tuser, ti.display_name FROM tipster_payouts po JOIN tipsters ti ON ti.id = po.tipster_id WHERE po.id = ? AND po.status = 'requested' AND po.method = 'mpesa'");
            $st->execute([$id]);
            $po = $st->fetch();
            if (!b2c_ready()) {
                set_flash('error', 'M-Pesa B2C is not set up. See the README (section 17).');
            } elseif (!$po) {
                set_flash('error', 'That request was already handled, or it is not an M-Pesa request.');
            } else {
                // Lock first (processing) so a double click can never send twice.
                $lock = $pdo->prepare("UPDATE tipster_payouts SET status = 'processing', b2c_conversation = ?, b2c_note = NULL, processed_by = ? WHERE id = ? AND status = 'requested'");
                $lock->execute([$orig, $me['id'], $id]);
                if ($lock->rowCount() === 1) {
                    $r = b2c_send($orig, preg_replace('/\D/', '', (string)$po['details']), (int)floor((float)$po['amount_kes']), 'Payout ' . SITE_NAME);
                    if ($r['ok']) {
                        admin_log('tipster.payout_b2c_sent', 'payout#' . $id, $po['display_name'] . ' KES ' . $po['amount_kes']);
                        set_flash('success', 'Sent to M-Pesa. It shows as paid when Safaricom confirms (usually seconds).');
                    } else {
                        $pdo->prepare("UPDATE tipster_payouts SET status = 'requested', b2c_note = ? WHERE id = ? AND status = 'processing'")->execute([substr($r['message'], 0, 200), $id]);
                        set_flash('error', $r['message']);
                    }
                }
            }
        } elseif ($action === 'recheck') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM tipster_payouts WHERE id = ? AND status = 'processing'");
            $st->execute([$id]);
            $po = $st->fetch();
            $res = $po ? payout_settle($po) : 'pending';
            set_flash($res === 'pending' ? 'info' : 'success', 'Checked with ' . ($po ? payout_method_label((string)$po['method']) : 'the provider') . ': ' . $res . '.');
        } elseif ($action === 'unlock') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE tipster_payouts SET status = 'requested', b2c_note = 'Returned to the queue by an admin (check M-Pesa first).' WHERE id = ? AND status = 'processing'")->execute([$id]);
            admin_log('tipster.payout_unlock', 'payout#' . $id, '');
            set_flash('success', 'Back in the queue. Make sure the money was not sent before you pay again.');
        } elseif ($action === 'paid' || $action === 'reject') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare("SELECT po.*, ti.user_id AS tuser, ti.display_name FROM tipster_payouts po JOIN tipsters ti ON ti.id = po.tipster_id WHERE po.id = ? AND po.status IN ('requested','processing')");
            $st->execute([$id]);
            $po = $st->fetch();
            if (!$po) {
                set_flash('error', 'That request was already handled.');
            } elseif ($action === 'paid') {
                $ref = strtoupper(mb_substr(post_str('reference'), 0, 60));
                if ($ref === '') {
                    set_flash('error', 'Enter the receipt or transaction ID you used to pay.');
                } else {
                    $pdo->prepare("UPDATE tipster_payouts SET status='paid', reference=?, processed_at=NOW(), processed_by=? WHERE id=? AND status IN ('requested','processing')")->execute([$ref, $me['id'], $id]);
                    admin_log('tipster.payout_paid', 'payout#' . $id, $po['display_name'] . ' KES ' . $po['amount_kes'] . ' ref ' . $ref);
                    notify_user((int)$po['tuser'], 'Payout sent', 'We paid KES ' . number_format((float)$po['amount_kes'], 0) . ' to your ' . $po['method'] . '. Reference: ' . $ref);
                    set_flash('success', 'Marked as paid.');
                }
            } elseif ($po['status'] === 'processing') {
                set_flash('error', 'This payout is being sent by M-Pesa. Use "Back to queue" first if you are sure it was not sent.');
            } else {
                $note = mb_substr(post_str('note'), 0, 250);
                $pdo->prepare("UPDATE tipster_payouts SET status='rejected', note=?, processed_at=NOW(), processed_by=? WHERE id=? AND status='requested'")->execute([$note, $me['id'], $id]);
                admin_log('tipster.payout_reject', 'payout#' . $id, $po['display_name'] . ($note !== '' ? ': ' . $note : ''));
                notify_user((int)$po['tuser'], 'Payout request declined', $note !== '' ? $note : 'Please contact support. The money is back in your available balance.');
                set_flash('success', 'Rejected. The amount returns to the tipster\'s balance.');
            }
        } elseif ($action === 'reverse') {
            $id = (int)($_POST['id'] ?? 0);
            $note = mb_substr(post_str('note'), 0, 250);
            $st = $pdo->prepare("UPDATE tipster_earnings SET status='reversed', note=? WHERE id=? AND status='ok'");
            $st->execute([$note, $id]);
            if ($st->rowCount()) {
                admin_log('tipster.earning_reversed', 'earning#' . $id, $note);
                set_flash('success', 'Earning reversed (use this for refunds or chargebacks). Check the tipster has not already been paid more than they now earned.');
            } else {
                set_flash('error', 'Could not reverse that earning.');
            }
        }
    }
    redirect('admin/tipster-payouts.php');
}

$req = $pdo->query("SELECT po.*, ti.display_name, ti.slug FROM tipster_payouts po JOIN tipsters ti ON ti.id = po.tipster_id WHERE po.status IN ('requested','processing') ORDER BY po.id")->fetchAll();
$done = $pdo->query("SELECT po.*, ti.display_name FROM tipster_payouts po JOIN tipsters ti ON ti.id = po.tipster_id WHERE po.status <> 'requested' ORDER BY po.id DESC LIMIT 40")->fetchAll();
$tips = $pdo->query("SELECT id, display_name FROM tipsters WHERE status IN ('active','suspended') ORDER BY display_name")->fetchAll();
$earn = $pdo->query("SELECT e.*, ti.display_name, u.email AS buyer FROM tipster_earnings e JOIN tipsters ti ON ti.id = e.tipster_id JOIN users u ON u.id = e.buyer_id ORDER BY e.id DESC LIMIT 40")->fetchAll();

$admin_title = 'Tipster payouts';
$admin_active = 'payouts';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Tipster payouts</h1>
<p class="sub">Tipsters now withdraw from their own wallet and are paid automatically (M-Pesa B2C, Airtel Money or PayPal Payouts), one withdrawal every <?= payout_window_days() ?> days. This page only shows what is in progress and any exception. Settings: Admin &gt; Tipsters.</p>

<div class="panel">
  <h2>In progress or queued (<?= count($req) ?>)</h2>
  <?php if ($req): ?><div class="scroll"><table class="tbl"><thead><tr><th>Tipster</th><th>Amount</th><th>Pay to</th><th>Requested</th><th>Action</th></tr></thead><tbody>
  <?php foreach ($req as $r): ?><tr>
    <td><strong><?= e($r['display_name']) ?></strong></td>
    <td><strong>KES <?= e(number_format((float)$r['amount_kes'], 0)) ?></strong></td>
    <td><?= e(strtoupper($r['method'])) ?><br><?= e($r['details']) ?><?= $r['status'] === 'processing' ? '<br><strong style="color:var(--gold)">Sending automatically...</strong>' : '' ?><?= !empty($r['b2c_note']) ? '<br><small style="color:var(--loss)">' . e($r['b2c_note']) . '</small>' : '' ?></td>
    <td><?= e(date('j M Y, H:i', strtotime($r['requested_at']))) ?></td>
    <td>
      <?php if ($r['status'] === 'processing' && in_array($r['method'], ['paypal','airtel'], true)): ?><form method="post" style="margin-bottom:6px"><?= csrf_field() ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" type="submit">Check status</button></form>
      <?php elseif ($r['status'] === 'processing'): ?><form method="post" style="margin-bottom:6px"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" type="submit">Back to queue</button></form>
      <?php elseif ($r['method'] === 'mpesa' && b2c_ready()): ?><form method="post" style="margin-bottom:6px" onsubmit="return confirm('Send KES <?= e(number_format((float)$r['amount_kes'], 0)) ?> to <?= e($r['details']) ?> now?');"><?= csrf_field() ?><input type="hidden" name="action" value="b2c"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny gold" type="submit">Pay via M-Pesa now</button></form><?php endif; ?>
      <form method="post" style="display:flex;gap:6px" onsubmit="return confirm('Only confirm after you have sent the money.');"><?= csrf_field() ?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="text" name="reference" maxlength="60" placeholder="Receipt / transaction ID" required style="width:170px"><button class="btn tiny win" type="submit">Mark paid</button></form>
      <form method="post" style="display:flex;gap:6px;margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="text" name="note" maxlength="250" placeholder="Reason" style="width:170px"><button class="btn tiny loss" type="submit">Reject</button></form>
    </td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p style="color:var(--mute)">No payout requests right now.</p><?php endif; ?>
</div>

<div class="panel">
  <h2>What each tipster is owed</h2>
  <?php if ($tips): ?><div class="scroll"><table class="tbl"><thead><tr><th>Tipster</th><th>Sales (gross)</th><th>Your commission</th><th>Earned</th><th>Held</th><th>Paid out</th><th>Pending request</th><th>Available</th></tr></thead><tbody>
  <?php foreach ($tips as $t): $b = tipster_balance((int)$t['id']); ?><tr>
    <td><?= e($t['display_name']) ?></td><td>KES <?= e(number_format($b['gross'], 0)) ?></td><td>KES <?= e(number_format($b['commission'], 0)) ?></td>
    <td>KES <?= e(number_format($b['net_total'], 0)) ?></td><td>KES <?= e(number_format($b['held'], 0)) ?></td><td>KES <?= e(number_format($b['paid'], 0)) ?></td>
    <td>KES <?= e(number_format($b['requested'], 0)) ?></td><td><strong>KES <?= e(number_format($b['available'], 0)) ?></strong></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p style="color:var(--mute)">No active tipsters yet.</p><?php endif; ?>
</div>

<div class="panel">
  <h2>Latest sales</h2>
  <?php if ($earn): ?><div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Tipster</th><th>Buyer</th><th>Gross</th><th>Rate</th><th>You keep</th><th>Tipster gets</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($earn as $r): ?><tr>
    <td><?= e(date('j M, H:i', strtotime($r['created_at'] ?? $r['available_at']))) ?></td><td><?= e($r['display_name']) ?></td><td><?= e($r['buyer']) ?></td>
    <td>KES <?= e(number_format((float)$r['gross_kes'], 0)) ?></td><td><?= (int)$r['commission_percent'] ?>%</td><td>KES <?= e(number_format((float)$r['commission_kes'], 0)) ?></td><td>KES <?= e(number_format((float)$r['net_kes'], 0)) ?></td>
    <td><span class="badge <?= $r['status'] === 'ok' ? 'won' : 'lost' ?>"><?= e($r['status'] === 'ok' ? 'Counted' : 'Reversed') ?></span></td>
    <td><?php if ($r['status'] === 'ok'): ?><form method="post" style="display:flex;gap:4px" onsubmit="return confirm('Reverse this sale? Use it for a refund or chargeback.');"><?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="text" name="note" maxlength="250" placeholder="Why" style="width:100px"><button class="btn tiny outline" type="submit">Reverse</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?></tbody></table></div><?php else: ?><p style="color:var(--mute)">No sales yet.</p><?php endif; ?>
</div>

<div class="panel">
  <h2>Payout history</h2>
  <?php if ($done): ?><div class="scroll"><table class="tbl"><thead><tr><th>Tipster</th><th>Amount</th><th>Method</th><th>Status</th><th>Reference / note</th><th>Date</th></tr></thead><tbody>
  <?php foreach ($done as $r): ?><tr><td><?= e($r['display_name']) ?></td><td>KES <?= e(number_format((float)$r['amount_kes'], 0)) ?></td><td><?= e($r['method']) ?></td>
    <td><span class="badge <?= $r['status'] === 'paid' ? 'won' : 'lost' ?>"><?= e(ucfirst($r['status'])) ?></span></td><td><?= e($r['reference'] ?: $r['note']) ?></td>
    <td><?= $r['processed_at'] ? e(date('j M Y', strtotime($r['processed_at']))) : '-' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p style="color:var(--mute)">Nothing yet.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
