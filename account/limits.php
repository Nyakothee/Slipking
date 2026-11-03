<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$english_only = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $a = $_POST['action'] ?? '';
        if ($a === 'limit') {
            $raw = trim((string)($_POST['limit'] ?? ''));
            $kes = $raw === '' ? null : max(0, min(10000000, (int)$raw));
            limits_set_monthly($uid, $kes === 0 ? null : $kes);
            set_flash('success', 'Saved. A lower limit starts now. Raising or removing a limit takes 24 hours.');
        } elseif ($a === 'exclude') {
            $m = (int)($_POST['months'] ?? -1);
            if (!in_array($m, [1, 3, 6, 0], true) || ($_POST['sure'] ?? '') !== 'yes') {
                set_flash('error', 'Choose a period and tick the box to confirm.');
            } else {
                limits_exclude($uid, $m);
                admin_log('limits.exclude', 'user#' . $uid, $m === 0 ? 'forever' : $m . ' months');
                set_flash('success', 'Your break has started. Payments are switched off for your account. It cannot be shortened.');
            }
        }
    }
    redirect('account/limits.php');
}
limits_apply_pending($uid);
$r = limits_row($uid);
$ex = limits_exclusion($uid);
$spent = limits_spent_month($uid);
$pending = json_decode((string)setting('limit_raise_' . $uid, ''), true);
$page_title = 'Limits and breaks - ' . SITE_NAME;
$active = '';
$acct = 'limits';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Limits and breaks</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <p style="color:var(--mute);max-width:720px">Our plans are paid subscriptions for tips, not bets. Still, you can set a monthly spending limit or take a break. These are tools for you. They do not replace help if gambling is a problem: see <a href="<?= e(url('responsible-gambling.php')) ?>">responsible gambling</a>.</p>
  <div class="two">
    <div class="box2"><h3>Monthly spending limit</h3>
      <p>Spent on plans this month: <strong>KES <?= number_format($spent) ?></strong><?= $r['monthly_limit_kes'] !== null ? ' of KES ' . number_format((int)$r['monthly_limit_kes']) : '' ?></p>
      <?php if (is_array($pending)): ?><p class="note" style="margin:8px 0">A change is waiting and starts on <?= e(date('j M, H:i', (int)$pending['at'])) ?>.</p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="limit">
        <label for="lim">Limit in KES (leave empty for none)</label>
        <input id="lim" name="limit" type="number" min="0" max="10000000" value="<?= $r['monthly_limit_kes'] !== null ? (int)$r['monthly_limit_kes'] : '' ?>" inputmode="numeric">
        <p style="margin-top:10px"><button class="btn dark" type="submit">Save limit</button></p></form></div>
    <div class="box2"><h3>Take a break</h3>
      <?php if ($ex === 'forever'): ?><p><strong>You excluded yourself permanently.</strong> Payments are off for this account. Contact support if you need help.</p>
      <?php elseif ($ex !== ''): ?><p><strong>Break active until <?= e(date('j M Y', strtotime($ex))) ?>.</strong> It cannot be shortened.</p>
      <?php else: ?>
      <p>Switch payments off for your account. Once started it cannot be undone early.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="exclude">
        <label for="mo">For how long?</label>
        <select id="mo" name="months"><option value="1">1 month</option><option value="3">3 months</option><option value="6">6 months</option><option value="0">Forever</option></select>
        <p><label><input type="checkbox" name="sure" value="yes"> I understand this cannot be shortened</label></p>
        <button class="btn dark" type="submit">Start my break</button></form>
      <?php endif; ?></div>
  </div>
  <p style="color:var(--mute);margin-top:12px;font-size:14px"><?= $r['age_confirmed_at'] ? 'You confirmed you are 18 or older on ' . e(date('j M Y', strtotime($r['age_confirmed_at']))) . '.' : '' ?></p>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
