<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $days   = post_str('referral_reward_days');
        $tdays  = post_str('trial_days');
        $coupon = strtoupper(post_str('winback_coupon'));
        if (!ctype_digit($days) || (int)$days > 365) $errors[] = 'Referral reward must be 0 to 365 days (0 switches it off).';
        if (!ctype_digit($tdays) || (int)$tdays < 1 || (int)$tdays > 30) $errors[] = 'Trial length must be 1 to 30 days.';
        if ($coupon !== '' && !preg_match('/^[A-Z0-9\-]{3,30}$/', $coupon)) $errors[] = 'The win-back coupon must be an existing code like COMEBACK20 (letters, numbers, hyphens), or empty.';
        if (!$errors) {
            set_setting('referral_reward_days', (string)(int)$days);
            set_setting('trial_enabled', !empty($_POST['trial_enabled']) ? '1' : '0');
            set_setting('trial_days', (string)(int)$tdays);
            set_setting('winback_coupon', $coupon);
            $cm = (float)str_replace(',', '.', post_str('cashout_margin_pct'));
            set_setting('cashout_margin_pct', (string)max(0, min(40, $cm)));
            admin_log('settings.save');
            set_flash('success', 'Settings saved.');
            redirect('admin/settings.php');
        }
    }
}

$admin_title = 'Offer settings';
$admin_active = 'settings';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Offer settings</h1>
<p class="sub">Free trial, referral rewards and the win-back message. Secret keys for payments live in the files inside <code>includes/</code>, not here.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="panel"><h2>Free trial</h2>
  <div class="formgrid">
    <div class="field check"><label><input type="checkbox" name="trial_enabled" value="1"<?= setting('trial_enabled', '1') === '1' ? ' checked' : '' ?>> Offer a free VIP trial to new members</label>
      <div class="hint">Each person gets it once. Their email must be confirmed, they must have added a phone number, and the same phone number cannot be used for another trial.</div></div>
    <div class="field"><label for="trial_days">Trial length (days)</label><input type="number" id="trial_days" name="trial_days" value="<?= e(setting('trial_days', '1')) ?>" min="1" max="30"></div>
  </div>
</div>
<div class="panel"><h2>Accumulator cash out</h2>
  <div class="field"><label for="cashout_margin_pct">Margin taken off the cash-out estimate (%)</label><input type="number" step="0.5" id="cashout_margin_pct" name="cashout_margin_pct" value="<?= e(setting('cashout_margin_pct', '6')) ?>" min="0" max="40" style="max-width:180px">
    <div class="hint">The "Cash out now" button on an accumulator page shows: stake x odds of the selections that already won x (1 - margin). Bookmakers usually keep 3 to 10%. It is information only: you do not take bets.</div></div>
</div>
<div class="panel"><h2>Referral reward</h2>
  <div class="field"><label for="referral_reward_days">VIP days given to the person who invited a friend</label><input type="number" id="referral_reward_days" name="referral_reward_days" value="<?= e(setting('referral_reward_days', '3')) ?>" min="0" max="365" style="max-width:180px">
    <div class="hint">Given once, when the invited friend makes their first real payment. 0 = off.</div></div>
</div>
<div class="panel"><h2>Reminder emails (run by the daily cron job)</h2>
  <p style="margin-bottom:10px">Two gentle messages are sent automatically (in the member's account and by email), and never to members who turned reminders off in their profile:</p>
  <ul style="margin:0 0 12px 20px"><li><strong>Unfinished checkout:</strong> about an hour after someone starts paying but does not finish (once every 3 days).</li><li><strong>Win-back:</strong> 3 to 10 days after a plan or trial ends (once a month).</li></ul>
  <div class="field"><label for="winback_coupon">Win-back coupon code (optional)</label><input type="text" id="winback_coupon" name="winback_coupon" value="<?= e(setting('winback_coupon', '')) ?>" maxlength="30" style="text-transform:uppercase;max-width:260px">
    <div class="hint">Create it first under Coupon codes. It is mentioned in the win-back message while it is active and not used up.</div></div>
</div>
<button class="btn gold" type="submit">Save settings</button>
</form>

<div class="panel" style="margin-top:22px"><h2>Payment connections</h2>
  <table class="kv">
    <tr><td>M-Pesa</td><td><?php require_once dirname(__DIR__) . '/includes/mpesa.php'; echo mpesa_ready() ? 'configured (' . e(MPESA_ENV) . ')' : 'not configured'; ?></td></tr>
    <tr><td>PayPal</td><td><?php require_once dirname(__DIR__) . '/includes/paypal.php'; echo paypal_ready() ? 'configured (' . e(PAYPAL_ENV) . ')' : 'not configured'; ?></td></tr>
    <tr><td>Paystack (naira)</td><td><?= paystack_ready() ? 'configured' : 'not configured (includes/config-gateways.php)' ?></td></tr>
    <tr><td>Razorpay (rupees)</td><td><?= razorpay_ready() ? 'configured' : 'not configured (includes/config-gateways.php)' ?></td></tr>
  </table>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
