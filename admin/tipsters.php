<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tipsters');
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'settings') {
            $c = post_str('commission');
            $h = post_str('hold');
            $m = post_str('minpayout');
            if (!is_numeric($c) || (float)$c < 0 || (float)$c > 95) {
                set_flash('error', 'The site commission must be between 0 and 95 percent.');
            } elseif (!ctype_digit($h) || (int)$h > 90) {
                set_flash('error', 'Holding days must be a whole number from 0 to 90.');
            } elseif (!ctype_digit($m) || (int)$m < 0 || (int)$m > 1000000) {
                set_flash('error', 'Minimum payout must be a whole number of KES.');
            } elseif (!ctype_digit(post_str('wdays')) || (int)post_str('wdays') < 1 || (int)post_str('wdays') > 90) {
                set_flash('error', 'Days between withdrawals must be a whole number from 1 to 90.');
            } elseif (!is_numeric(post_str('pool')) || (float)post_str('pool') < 0 || (float)post_str('pool') > 50) {
                set_flash('error', 'The contributor pool must be between 0 and 50 percent.');
            } else {
                $old = setting('tipster_commission_percent', '30');
                set_setting('tipster_commission_percent', rtrim(rtrim(number_format((float)$c, 2, '.', ''), '0'), '.'));
                set_setting('tipster_hold_days', (string)(int)$h);
                set_setting('tipster_min_payout_kes', (string)(int)$m);
                set_setting('tipster_withdraw_days', (string)(int)post_str('wdays'));
                set_setting('tipster_auto_payout', !empty($_POST['autopay']) ? '1' : '0');
                set_setting('paypal_payouts_on', !empty($_POST['ppay']) ? '1' : '0');
                set_setting('airtel_payouts_on', !empty($_POST['apay']) ? '1' : '0');
                set_setting('tipster_manual_fallback', !empty($_POST['manual']) ? '1' : '0');
                set_setting('contributor_pool_percent', rtrim(rtrim(number_format((float)post_str('pool'), 2, '.', ''), '0'), '.') ?: '0');
                set_setting('tipster_signups_open', !empty($_POST['signups']) ? '1' : '0');
                admin_log('tipsters.settings', '', 'commission ' . $old . ' -> ' . $c . '%, hold ' . (int)$h . 'd, min ' . (int)$m);
                set_flash('success', 'Saved. A new commission rate applies to FUTURE sales only; past sales keep the rate they were sold at.');
            }
        } elseif ($action === 'fee_toggle') {
            if (!tipster_fee_cols()) {
                set_flash('error', 'Import database-update-18.sql first (phpMyAdmin > Import), then you can switch the joining fee on.');
            } else {
                $on = setting('tipster_fee_on', '1') === '1' ? '0' : '1';
                if ($on === '1') { tipster_fee_plan_id(); }
                set_setting('tipster_fee_on', $on);
                admin_log('tipsters.fee_toggle', '', $on === '1' ? 'joining fee ON' : 'joining fee OFF');
                set_flash('success', $on === '1' ? 'The tipster joining fee is now ACTIVE. New tipsters must pay before you can approve them.' : 'The tipster joining fee is now OFF.');
            }
        } elseif ($action === 'fee_amounts') {
            $k = post_str('fee_kes'); $u = post_str('fee_usd');
            if (!ctype_digit($k) || (int)$k < 1 || (int)$k > 100000 || !is_numeric($u) || (float)$u < 0.5 || (float)$u > 10000) {
                set_flash('error', 'Enter the fee in whole KES (for Kenyans) and in USD (for everyone else, at least 0.50).');
            } else {
                set_setting('tipster_fee_kes', (string)(int)$k);
                set_setting('tipster_fee_usd', rtrim(rtrim(number_format((float)$u, 2, '.', ''), '0'), '.'));
                set_flash('success', 'Joining fee amounts saved.');
            }
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $t  = tipster_by_id($id);
            if (!$t) {
                set_flash('error', 'Tipster not found.');
            } elseif ($action === 'waive_fee') {
                if (tipster_fee_cols()) { $pdo->prepare('UPDATE tipsters SET fee_paid_at = NOW() WHERE id = ? AND fee_paid_at IS NULL')->execute([$id]); }
                admin_log('tipster.fee_waived', 'tipster#' . $id, $t['display_name']);
                set_flash('success', 'Joining fee waived for ' . $t['display_name'] . '. You can approve them now.');
            } elseif ($action === 'approve' && empty($t['avatar_url'])) {
                set_flash('error', $t['display_name'] . ' has no profile photo yet. A photo is required before approval.');
            } elseif ($action === 'approve' && tipster_fee_owed($t)) {
                set_flash('error', $t['display_name'] . ' has not paid the joining fee yet. Wait for the payment, or press "Waive fee".');
            } elseif ($action === 'approve' || $action === 'reactivate') {
                $pdo->prepare("UPDATE tipsters SET status = 'active', approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")->execute([$id]);
                admin_log('tipster.' . $action, 'tipster#' . $id, $t['display_name']);
                notify_user((int)$t['user_id'], 'Your tipster profile is live', 'You can now post tips and create plans from your tipster dashboard: ' . url('tipster/index.php'));
                $em = $pdo->prepare('SELECT email FROM users WHERE id = ?'); $em->execute([$t['user_id']]);
                send_mail((string)$em->fetchColumn(), 'Your ' . SITE_NAME . ' tipster profile is approved', "Your tipster profile is now live.\n\nOpen your dashboard to create plans and post tips:\n" . url('tipster/index.php'));
                set_flash('success', $t['display_name'] . ' is active.');
            } elseif ($action === 'reject' || $action === 'suspend') {
                $note = mb_substr(post_str('note'), 0, 250);
                $pdo->prepare('UPDATE tipsters SET status = ?, admin_note = ? WHERE id = ?')->execute([$action === 'reject' ? 'rejected' : 'suspended', $note, $id]);
                $pdo->prepare('UPDATE plans SET is_active = 0 WHERE tipster_id = ?')->execute([$id]);   // no new sales
                admin_log('tipster.' . $action, 'tipster#' . $id, $t['display_name'] . ($note !== '' ? ': ' . $note : ''));
                notify_user((int)$t['user_id'], $action === 'reject' ? 'Tipster application not approved' : 'Your tipster profile is paused', $note !== '' ? $note : 'Please contact support for details.');
                set_flash('success', $t['display_name'] . ($action === 'reject' ? ' was rejected.' : ' was suspended. Their plans are switched off.'));
            } elseif ($action === 'contributor') {
                $on = empty($t['is_contributor']) ? 1 : 0;
                $pdo->prepare('UPDATE tipsters SET is_contributor = ? WHERE id = ?')->execute([$on, $id]);
                admin_log('tipster.contributor', 'tipster#' . $id, $t['display_name'] . ($on ? ' made contributor' : ' removed as contributor'));
                notify_user((int)$t['user_id'], $on ? 'You are now a platform contributor' : 'Contributor access removed', $on ? 'You can now post tips under ' . SITE_NAME . ' from your tipster tips page (they are reviewed first) and earn a share of VIP sales.' : 'You can still sell your own plans as a tipster.');
                set_flash('success', $t['display_name'] . ($on ? ' is now a contributor.' : ' is no longer a contributor.'));
            } elseif ($action === 'commission') {
                $c = trim((string)($_POST['commission'] ?? ''));
                if ($c === '') {
                    $pdo->prepare('UPDATE tipsters SET commission_percent = NULL WHERE id = ?')->execute([$id]);
                    admin_log('tipster.commission', 'tipster#' . $id, 'default rate');
                    set_flash('success', 'Now uses the default commission.');
                } elseif (!is_numeric($c) || (float)$c < 0 || (float)$c > 95) {
                    set_flash('error', 'The commission must be between 0 and 95 percent, or empty for the default.');
                } else {
                    $pdo->prepare('UPDATE tipsters SET commission_percent = ? WHERE id = ?')->execute([(int)round((float)$c), $id]);
                    admin_log('tipster.commission', 'tipster#' . $id, round((float)$c) . '%');
                    set_flash('success', 'Commission for ' . $t['display_name'] . ' set to ' . (int)round((float)$c) . '% (future sales).');
                }
            }
        }
    }
    redirect('admin/tipsters.php' . (!empty($_POST['back']) ? '?status=' . urlencode((string)$_POST['back']) : ''));
}

$status = in_array($_GET['status'] ?? '', ['pending', 'active', 'suspended', 'rejected'], true) ? $_GET['status'] : '';
$sql = "SELECT ti.*, u.email, u.name AS user_name,
          (SELECT COUNT(*) FROM tips t WHERE t.tipster_id = ti.id) AS tips_n,
          (SELECT COUNT(*) FROM tipster_subscriptions s WHERE s.tipster_id = ti.id AND s.ends_at > NOW()) AS subs_now,
          (SELECT COALESCE(SUM(e.gross_kes),0) FROM tipster_earnings e WHERE e.tipster_id = ti.id AND e.status = 'ok') AS gross
        FROM tipsters ti JOIN users u ON u.id = ti.user_id" . ($status ? ' WHERE ti.status = ?' : '') . ' ORDER BY FIELD(ti.status,\'pending\',\'active\',\'suspended\',\'rejected\'), ti.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($status ? [$status] : []);
$rows = $st->fetchAll();
$tot = $pdo->query("SELECT COALESCE(SUM(gross_kes),0) AS g, COALESCE(SUM(commission_kes),0) AS c, COALESCE(SUM(net_kes),0) AS n FROM tipster_earnings WHERE status = 'ok'")->fetch();
$paid = (float)$pdo->query("SELECT COALESCE(SUM(amount_kes),0) FROM tipster_payouts WHERE status = 'paid'")->fetchColumn();
$defaultC = tipster_default_commission();

$admin_title = 'Tipsters';
$admin_active = 'tipsters';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Tipsters</h1>
<p class="sub">Members who sell their own tips. The site keeps a commission of every sale; the rest is owed to the tipster.</p>

<div class="stats">
  <div class="stat"><b style="font-size:26px">KES <?= e(number_format((float)$tot['g'], 0)) ?></b><span>tipster sales (gross)</span></div>
  <div class="stat"><b style="font-size:26px">KES <?= e(number_format((float)$tot['c'], 0)) ?></b><span>your commission</span></div>
  <div class="stat"><b style="font-size:26px">KES <?= e(number_format((float)$tot['n'] - $paid, 0)) ?></b><span>still owed to tipsters</span></div>
</div>

<div class="panel" style="border:2px solid <?= tipster_fee_on() ? 'var(--win)' : 'var(--line)' ?>">
  <h2>Tipster joining fee</h2>
  <?php $feeCols = tipster_fee_cols(); $feeOn = tipster_fee_on(); ?>
  <p>Every tipster pays this ONE-TIME fee, based on the country they registered from. Until it is paid they cannot be approved, listed, subscribed to, or post tips and plans: <strong>KES <?= (int)tipster_fee_kes() ?></strong> for Kenyans and <strong>USD <?= e(number_format(tipster_fee_usd(), 2)) ?></strong> for everyone else. They pay with the normal checkout (M-Pesa, Airtel, MTN, cards, PayPal ...).</p>
  <p>Status: <span class="badge <?= $feeOn ? 'won' : 'pending' ?>"><?= $feeOn ? 'ACTIVE' : 'OFF (not charged)' ?></span></p>
  <?php if (!$feeCols): ?><div class="note">Import <code>database-update-18.sql</code> once to be able to switch this on.</div><?php endif; ?>
  <form method="post" style="display:inline" onsubmit="return confirm('<?= $feeOn ? 'Switch the joining fee OFF?' : 'Switch the joining fee ON? New tipsters will have to pay.' ?>');"><?= csrf_field() ?><input type="hidden" name="action" value="fee_toggle">
    <button class="btn <?= $feeOn ? 'loss' : 'win' ?>" type="submit"><?= $feeOn ? 'Deactivate joining fee' : 'Activate joining fee' ?></button></form>
  <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="fee_amounts">
    <div class="field" style="margin:0"><label for="fee_kes">Kenyans pay (KES)</label><input type="number" id="fee_kes" name="fee_kes" min="1" value="<?= (int)tipster_fee_kes() ?>" style="width:120px"></div>
    <div class="field" style="margin:0"><label for="fee_usd">Others pay (USD)</label><input type="number" id="fee_usd" name="fee_usd" min="0.5" step="0.5" value="<?= e((string)tipster_fee_usd()) ?>" style="width:120px"></div>
    <button class="btn dark small" type="submit">Save amounts</button></form>
</div>

<div class="panel">
  <h2>Commission and rules</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <div class="formgrid">
      <div class="field"><label for="commission">Site commission (%)</label><input type="number" id="commission" name="commission" min="0" max="95" step="0.5" value="<?= e((string)$defaultC) ?>" required>
        <div class="hint">The share YOU keep of every tipster sale. Change it any time: it only affects future sales. You can also give one tipster their own rate below.</div></div>
      <div class="field"><label for="hold">Holding days</label><input type="number" id="hold" name="hold" min="0" max="90" value="<?= (int)setting('tipster_hold_days', '7') ?>" required>
        <div class="hint">Earnings can be paid out only after this many days (your refund window).</div></div>
      <div class="field"><label for="minpayout">Minimum payout (KES)</label><input type="number" id="minpayout" name="minpayout" min="0" max="1000000" value="<?= (int)setting('tipster_min_payout_kes', '1000') ?>" required></div>
      <div class="field"><label for="wdays">Days between withdrawals</label><input type="number" id="wdays" name="wdays" min="1" max="90" value="<?= payout_window_days() ?>" required>
        <div class="hint">A tipster can withdraw their wallet balance once every this many days (default 14). Failed withdrawals do not count.</div></div>
      <div class="field"><label><input type="checkbox" name="autopay" value="1"<?= setting('tipster_auto_payout', '1') === '1' ? ' checked' : '' ?>> Pay withdrawals automatically</label>
        <div class="hint">M-Pesa: needs B2C set up (README section 17, then tick "M-Pesa B2C" in Data sources). Currently: <strong><?= b2c_ready() ? 'ready' : 'not ready' ?></strong>.</div>
        <label style="display:block;margin-top:6px"><input type="checkbox" name="ppay" value="1"<?= setting('paypal_payouts_on', '0') === '1' ? ' checked' : '' ?>> PayPal Payouts is enabled on my PayPal business account</label>
        <label style="display:block;margin-top:6px"><input type="checkbox" name="apay" value="1"<?= setting('airtel_payouts_on', '0') === '1' ? ' checked' : '' ?>> Airtel Money withdrawals are enabled (needs AIRTEL_DISBURSE_PIN in includes/config-wallets.php and Disbursements switched on by Airtel)</label>
        <div class="hint">PayPal must approve "Payouts" for your account first. Payouts are sent in US dollars. Currently: <strong><?= payout_auto_ready('paypal') ? 'ready' : 'not ready' ?></strong>.</div>
        <label style="display:block;margin-top:6px"><input type="checkbox" name="manual" value="1"<?= setting('tipster_manual_fallback', '0') === '1' ? ' checked' : '' ?>> If automatic payment is not ready, queue the withdrawal for me to pay by hand</label>
        <div class="hint">Off = tipsters simply cannot withdraw until automatic payment works. Turn on only as a stop-gap.</div></div>
      <div class="field"><label for="pool">Contributor pool (% of each VIP sale)</label><input type="number" id="pool" name="pool" min="0" max="50" step="0.5" value="<?= e(rtrim(rtrim(number_format((float)setting('contributor_pool_percent', '0'), 2, '.', ''), '0'), '.') ?: '0') ?>" required>
        <div class="hint">0 = off. Contributors are tipsters you allow to post tips under YOUR platform. This share of every site VIP sale is split between them by how many approved VIP tips each posted in the last 30 days.</div></div>
      <div class="field"><label><input type="checkbox" name="signups" value="1"<?= setting('tipster_signups_open', '1') === '1' ? ' checked' : '' ?>> New tipster applications are open</label></div>
    </div>
    <button class="btn dark" type="submit">Save</button>
  </form>
</div>

<div class="panel">
  <div class="toolbar"><h2 style="margin:0">All tipsters</h2>
    <form method="get"><select name="status" aria-label="Status"><option value="">Every status</option>
      <?php foreach (['pending', 'active', 'suspended', 'rejected'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
      <button class="btn dark tiny" type="submit">Filter</button></form></div>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Tipster</th><th>Status</th><th>Rating</th><th>Tips</th><th>Subscribers</th><th>Sales (gross)</th><th>Commission</th><th>Actions</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><strong><?= e($r['display_name']) ?></strong><br><small><?= e($r['email']) ?></small><?= $r['is_contributor'] ? '<br><span class="badge won">Contributor</span>' : '' ?><br><small>Payout: <?= e($r['payout_method']) ?> <?= e($r['payout_details'] ?: '-') ?></small>
        <?php if ($r['status'] === 'active'): ?><br><a href="<?= e(tipster_url($r)) ?>" target="_blank" rel="noopener"><small>Public profile</small></a><?php endif; ?></td>
      <td><span class="badge <?= $r['status'] === 'active' ? 'won' : ($r['status'] === 'pending' ? 'pending' : 'lost') ?>"><?= e(ucfirst($r['status'])) ?></span>
        <?php if (tipster_fee_cols()): ?><br><small><?= !empty($r['fee_paid_at']) ? 'Fee paid ' . e(date('j M', strtotime($r['fee_paid_at']))) : (tipster_fee_on() && in_array($r['status'], ['pending', 'active'], true) ? '<strong>Fee NOT paid</strong>' : 'No fee') ?></small><?php endif; ?>
        <?php if (empty($r['avatar_url'])): ?><br><small style="color:var(--loss)">No photo</small><?php endif; ?>
        <?php if (in_array($r['status'], ['pending', 'active'], true) && tipster_fee_on() && empty($r['fee_paid_at'])): ?><form class="inl" method="post" style="margin-top:4px"><?= csrf_field() ?><input type="hidden" name="action" value="waive_fee"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny outline" type="submit">Waive fee</button></form><?php endif; ?></td>
      <td><?= (int)$r['rating_count'] ? e(number_format((float)$r['rating_avg'], 1)) . ' (' . (int)$r['rating_count'] . ')' : '-' ?></td>
      <td><?= (int)$r['tips_n'] ?></td>
      <td><?= (int)$r['subs_now'] ?></td>
      <td>KES <?= e(number_format((float)$r['gross'], 0)) ?></td>
      <td>
        <form class="inl" method="post" style="display:flex;gap:6px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="commission"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="number" name="commission" min="0" max="95" step="1" value="<?= $r['commission_percent'] !== null ? (int)$r['commission_percent'] : '' ?>" placeholder="<?= e((string)$defaultC) ?>" style="width:70px" aria-label="Commission percent">%
          <button class="btn tiny outline" type="submit">Set</button></form>
        <small><?= $r['commission_percent'] === null ? 'default rate' : 'own rate' ?></small>
      </td>
      <td class="acts">
        <?php if (in_array($r['status'], ['pending', 'suspended', 'rejected'], true)): ?>
          <form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $r['status'] === 'pending' ? 'approve' : 'reactivate' ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
            <button class="btn tiny win" type="submit"><?= $r['status'] === 'pending' ? 'Approve' : 'Reactivate' ?></button></form>
        <?php endif; ?>
        <?php if ($r['status'] === 'pending'): ?>
          <form class="inl" method="post" style="display:flex;gap:6px;margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
            <input type="text" name="note" maxlength="250" placeholder="Reason (they see it)" style="width:140px"><button class="btn tiny loss" type="submit">Reject</button></form>
        <?php elseif ($r['status'] === 'active'): ?>
          <form class="inl" method="post" style="display:flex;gap:6px" onsubmit="return confirm('Suspend this tipster? Their plans are switched off.');"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
            <input type="text" name="note" maxlength="250" placeholder="Reason (they see it)" style="width:140px"><button class="btn tiny loss" type="submit">Suspend</button></form>
        <?php endif; ?>
        <?php if ($r['status'] === 'active'): ?><form class="inl" method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="contributor"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($status) ?>">
          <button class="btn tiny outline" type="submit"><?= $r['is_contributor'] ? 'Remove contributor' : 'Make contributor' ?></button></form><?php endif; ?>
        <?php if ($r['admin_note']): ?><br><small>Note: <?= e($r['admin_note']) ?></small><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p style="color:var(--mute)">No tipsters yet.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
