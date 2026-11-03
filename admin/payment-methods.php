<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/pay-ui.php';
require_perm('settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!is_admin()) {
        set_flash('error', 'Only an administrator can change payment methods.');
    } else {
        foreach (array_keys(pay_methods()) as $slug) {
            set_setting('pay_' . $slug . '_on', !empty($_POST['on'][$slug]) ? '1' : '0');
            $lg = trim((string)($_POST['logo'][$slug] ?? ''));
            if ($lg !== '' && (!preg_match('#^https?://#i', $lg) || mb_strlen($lg) > 500)) {
                $lg = '';
            }
            set_setting('pay_' . $slug . '_logo', $lg);
        }
        admin_log('paymethods.save', '', '');
        set_flash('success', 'Saved.');
    }
    redirect('admin/payment-methods.php');
}

$keys = [
    'mpesa'    => [function () { return mpesa_ready(); }, 'includes/config-payments.php'],
    'paypal'   => [function () { return paypal_ready(); }, 'includes/config-payments.php'],
    'paystack' => [function () { return paystack_ready(); }, 'includes/config-gateways.php'],
    'razorpay' => [function () { return razorpay_ready(); }, 'includes/config-gateways.php'],
    'stripe'   => [function () { return STRIPE_SECRET_KEY !== ''; }, 'includes/config-wallets.php'],
    'mtn'      => [function () { return MTN_MOMO_SUBSCRIPTION_KEY !== '' && MTN_MOMO_API_USER !== '' && MTN_MOMO_API_KEY !== ''; }, 'includes/config-wallets.php'],
    'airtel'   => [function () { return AIRTEL_CLIENT_ID !== '' && AIRTEL_CLIENT_SECRET !== ''; }, 'includes/config-wallets.php'],
];
$extra = [
    'stripe' => 'Charges in USD, EUR or GBP. Webhook URL: payments/stripe-webhook.php. ' . (STRIPE_WEBHOOK_SECRET === '' ? 'Webhook secret NOT set (payments are still confirmed when the customer returns, and by the cron job).' : 'Webhook secret set.'),
    'mtn'    => 'Environment: ' . MTN_MOMO_ENV . '. Charged in ' . momo_currency() . ' (' . (wallet_currency_ok(momo_currency()) ? 'currency available' : 'ADD THIS CURRENCY in Admin > Currencies') . ').',
    'airtel' => 'Environment: ' . AIRTEL_ENV . '. Country ' . AIRTEL_COUNTRY . ', charged in ' . strtoupper(AIRTEL_CURRENCY) . ' (' . (wallet_currency_ok(AIRTEL_CURRENCY) ? 'currency available' : 'ADD THIS CURRENCY in Admin > Currencies') . ').',
];
$admin_title = 'Payment methods';
$admin_active = 'paymethods';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Payment methods</h1>
<p class="sub">Keys go in the config files shown below (not here). A method appears on checkout only when its keys are filled in <strong>and</strong> it is ticked here.</p>
<form method="post" class="panel"><?= csrf_field() ?>
  <table class="table">
    <thead><tr><th>Method</th><th>Company</th><th>Keys</th><th>Show at checkout</th><th>Logo link (optional)</th></tr></thead>
    <tbody>
    <?php foreach (pay_methods() as $slug => $m): $has = $keys[$slug][0](); ?>
      <tr>
        <td><span class="paychip pb-<?= e($slug) ?>"><?= e($m['name']) ?></span></td>
        <td><?= e($m['company']) ?><?php if (isset($extra[$slug])): ?><br><small style="color:var(--mute)"><?= e($extra[$slug]) ?></small><?php endif; ?></td>
        <td><?= $has ? '<strong style="color:var(--win)">set</strong>' : '<span style="color:var(--loss)">not set</span>' ?><br><small style="color:var(--mute)"><?= e($keys[$slug][1]) ?></small></td>
        <td><label><input type="checkbox" name="on[<?= e($slug) ?>]" value="1"<?= pay_on($slug) ? ' checked' : '' ?>> On</label></td>
        <td><input type="url" name="logo[<?= e($slug) ?>]" value="<?= e((string)setting('pay_' . $slug . '_logo', '')) ?>" placeholder="<?= e(pay_logo_defaults()[$slug] ?? 'https://') ?>" style="min-width:220px"></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p style="margin-top:12px"><button class="btn gold" type="submit">Save</button></p>
</form>
<div class="panel"><h2>Button logos</h2>
  <p>Buttons use each company's colours and an online logo (letter badge if the picture cannot load). Paste your own picture link in the table above to change one, or, to host it yourself, upload it (if you have the right to use it) to an official logo, upload it (if you have the right to use it) <code>assets/img/pay/</code> named after the method: <code>mpesa</code>, <code>airtel</code>, <code>mtn</code>, <code>stripe</code>, <code>paypal</code>, <code>paystack</code>, <code>razorpay</code> with the ending <code>.svg</code>, <code>.png</code> or <code>.webp</code>. It replaces the letter automatically.</p>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
