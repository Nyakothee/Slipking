<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa.php';
require_once dirname(__DIR__) . '/includes/paypal.php';
require_once dirname(__DIR__) . '/includes/gateways.php';
require_once dirname(__DIR__) . '/includes/wallets.php';
require_once dirname(__DIR__) . '/includes/pay-ui.php';
require_login();

$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['plan'] ?? '')));
$user  = current_user();
$st   = db()->prepare('SELECT * FROM plans WHERE slug = ? AND is_active = 1 LIMIT 1');
$st->execute([$slug]);
$plan = $st->fetch();
if ($plan) { $isFee = in_array($plan['slug'], [TIPSTER_FEE_SLUG, ACCESS_FEE_SLUG], true); $isAccess = $plan['slug'] === ACCESS_FEE_SLUG; $plan = tipster_fee_plan_for($plan, $user); } else { $isFee = false; $isAccess = false; }
if (!$plan) {
    if (access_on() && !$isFee) { set_flash('info', 'VIP is free for everyone right now. You do not need to buy a plan.'); redirect('vip.php'); }
    set_flash('error', $isFee ? 'There is nothing to pay here right now.' : 'Please choose a plan first.');
    redirect($isFee ? (access_on() ? 'access.php' : 'tipster/index.php') : 'vip.php');
}

if (!phone_gate_ok($user)) {
    set_flash('info', 'Please confirm your mobile number with the SMS code before you pay.');
    redirect('auth/verify-phone.php?next=' . urlencode('payments/checkout.php?plan=' . $slug));
}
$lb = limits_payment_block((int)$user['id'], (int)$plan['price_kes']);
if ($lb !== '') {
    set_flash('error', $lb);
    redirect('account/limits.php');
}
$phone = $user['phone'] ? preg_replace('/^254/', '0', $user['phone']) : '';
$vip   = is_vip();

// Coupon (optional): checked again on the server when the payment starts
$couponIn  = trim((string)($_GET['coupon'] ?? ''));
$cres      = find_coupon($couponIn, (int)$user['id']);
if (!empty($plan['tipster_id']) || $isFee) { $cres = ['ok' => true, 'coupon' => null, 'message' => '']; $couponIn = ''; }
$coupon    = $cres['ok'] ? $cres['coupon'] : null;
$couponMsg = $cres['ok'] ? '' : $cres['message'];
$pct       = $coupon ? (int)$coupon['percent_off'] : 0;
$kes       = $pct ? discounted_kes((int)$plan['price_kes'], $pct) : (int)$plan['price_kes'];
$usd       = $pct ? discounted_usd((float)$plan['price_usd'], $pct) : number_format((float)$plan['price_usd'], 2, '.', '');
$couponCode = $coupon ? $coupon['code'] : '';

$mpesaOn  = mpesa_ready() && pay_on('mpesa');
$paypalOn = paypal_ready() && (float)$plan['price_usd'] > 0 && pay_on('paypal');
$paystackOn = paystack_ready() && pay_on('paystack');
$razorOn    = razorpay_ready() && pay_on('razorpay');
$stripeOn = stripe_ready();
$mtnOn    = momo_ready();
$airtelOn = airtel_ready();
$stripeAmts = [];
if ($stripeOn) { foreach (['USD', 'EUR', 'GBP'] as $sc) { if ($sc === 'USD' || wallet_currency_ok($sc)) { $stripeAmts[$sc] = wallet_charge('stripe', $plan, $kes, $usd, $sc); } } }
$mtnCharge    = $mtnOn ? wallet_charge('mtn', $plan, $kes, $usd) : [0, ''];
$airtelCharge = $airtelOn ? wallet_charge('airtel', $plan, $kes, $usd) : [0, ''];
function pay_amt_text(float $amt, string $cur): string { $r = currency_row($cur); return $r ? format_money($amt, $r) : $cur . ' ' . number_format($amt, 2); }
$ngnAmt = $paystackOn ? local_price($kes, 'NGN', 10, 100) : 0;
$inrAmt = $razorOn ? local_price($kes, 'INR', 1, 10) : 0;

$page_title = 'Checkout - ' . SITE_NAME;
$payOrder = [];
foreach (['mpesa' => $mpesaOn, 'airtel' => $airtelOn, 'mtn' => $mtnOn, 'stripe' => $stripeOn, 'paypal' => $paypalOn, 'paystack' => $paystackOn, 'razorpay' => $razorOn] as $k => $on) { if ($on) $payOrder[] = $k; }
$payFirst = $payOrder ? $payOrder[0] : '';
$active     = 'vip';
$extra_js   = ['assets/js/checkout.js'];
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Checkout</h2><p>Signed in as <?= e($user['email']) ?></p></div></div>
    <div class="two">
      <div class="box2">
        <h3>Your order</h3>
        <table class="kv">
          <tr><td>Plan</td><td><strong><?= e($plan['name']) ?></strong></td></tr>
          <?php if (!$isFee): ?><tr><td>Length</td><td><?= (int)$plan['duration_days'] ?> days</td></tr><?php endif; ?>
          <tr><td>Price</td><td>
            <?php if ($pct): ?>
              <s style="color:var(--mute)"><?= e(money_kes($plan['price_kes'])) ?></s> <strong><?= e(money_kes($kes)) ?></strong>
              <?= $paypalOn ? '<br>or $' . e($usd) . ' with PayPal' : '' ?>
              <br><small style="color:var(--win);font-weight:700"><?= $pct ?>% off with code <?= e($couponCode) ?></small>
            <?php else: ?>
              <strong><?= e(money_kes($kes)) ?></strong><?= $paypalOn ? ' or $' . e($usd) . ' with PayPal' : '' ?>
            <?php endif; ?>
          </td></tr>
        </table>
        <ul style="margin-top:12px">
          <?php foreach (preg_split('/\R/', trim($plan['features'])) as $f): if ($f === '') continue; ?><li><?= e($f) ?></li><?php endforeach; ?>
        </ul>

        <?php if (empty($plan['tipster_id']) && !$isFee): ?>
        <form method="get" action="<?= e(url('payments/checkout.php')) ?>" style="margin-top:14px">
          <input type="hidden" name="plan" value="<?= e($plan['slug']) ?>">
          <div class="field" style="margin-bottom:8px"><label for="coupon">Have a coupon code?</label>
            <div style="display:flex;gap:8px"><input type="text" id="coupon" name="coupon" value="<?= e($couponIn) ?>" maxlength="30" autocomplete="off" style="text-transform:uppercase"><button class="btn dark small" type="submit">Apply</button></div></div>
          <?php if ($couponMsg): ?><div class="errors" role="alert" style="margin:0"><?= e($couponMsg) ?></div><?php endif; ?>
        </form>
        <?php endif; ?>

        <?php if ($vip && empty($plan['tipster_id']) && !$isFee): ?><div class="note">You already have VIP. These days will be added after your current plan ends.</div><?php endif; ?>
        <p style="color:var(--mute);font-size:14px;margin-top:10px">One-off payment. It does not renew by itself. You must be 18 or older.</p>
      </div>

      <div class="box2">
        <?php if ($payOrder): ?>
        <h3 class="payhead">Choose how to pay</h3>
        <p class="paysub">Pick a company below. You are only charged after you confirm.</p>
        <div class="paygrid" role="group" aria-label="Payment methods">
          <?php foreach ($payOrder as $slug) {
              if ($slug === 'mpesa')         echo pay_choice_button('mpesa', money_kes($kes), '', $slug === $payFirst);
              elseif ($slug === 'airtel')    echo pay_choice_button('airtel', pay_amt_text($airtelCharge[0], $airtelCharge[1]), 'Mobile money · ' . $airtelCharge[1], $slug === $payFirst);
              elseif ($slug === 'mtn')       echo pay_choice_button('mtn', pay_amt_text($mtnCharge[0], $mtnCharge[1]), 'Mobile money · ' . $mtnCharge[1], $slug === $payFirst);
              elseif ($slug === 'stripe')    echo pay_choice_button('stripe', pay_amt_text($stripeAmts['USD'][0], 'USD'), '', $slug === $payFirst);
              elseif ($slug === 'paypal')    echo pay_choice_button('paypal', '$' . $usd, '', $slug === $payFirst);
              elseif ($slug === 'paystack')  echo pay_choice_button('paystack', format_money((float)$ngnAmt, currency_row('NGN')), '', $slug === $payFirst);
              elseif ($slug === 'razorpay')  echo pay_choice_button('razorpay', format_money((float)$inrAmt, currency_row('INR')), '', $slug === $payFirst);
          } ?>
        </div>
        <?php endif; ?>
        <?php $soon = pay_soon_html(); if ($soon): ?>
        <h3 class="payhead" style="margin-top:18px"><?= $payOrder ? 'Coming soon' : 'Payment methods' ?></h3>
        <p class="paysub">These are being connected. They will become available here automatically.</p>
        <div class="paygrid is-soon-grid" aria-label="Payment methods coming soon"><?= $soon ?></div>
        <?php endif; ?>

        <div class="paypanels" id="paypanels" data-first="<?= e($payFirst) ?>">
        <?php if ($mpesaOn): ?>
          <div class="paypanel<?= $payFirst === 'mpesa' ? ' on' : '' ?>" id="panel-mpesa">
          <h3>Pay with M-Pesa</h3>
          <form id="mpesaForm" method="post" action="<?= e(url('payments/mpesa-stkpush.php')) ?>"
                data-status="<?= e(url('payments/mpesa-status.php')) ?>" data-success="<?= e(url('payments/success.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="plan" value="<?= e($plan['slug']) ?>">
            <input type="hidden" name="coupon" value="<?= e($couponCode) ?>">
            <div class="field">
              <label for="phone">M-Pesa phone number</label>
              <input type="tel" id="phone" name="phone" value="<?= e($phone) ?>" placeholder="0712 345 678" required autocomplete="tel">
              <div class="hint">A prompt will appear on this phone. Enter your M-Pesa PIN to pay.</div>
            </div>
            <?= pay_submit_button('mpesa', 'Pay ' . money_kes($kes) . ' with M-Pesa', '', 'submit') ?>
            <div id="mpesaMsg" class="msg" role="status" aria-live="polite"></div>
          </form>
          </div>
        <?php endif; ?>

        <?php foreach (['airtel' => [$airtelOn, $airtelCharge, 'Airtel Money', 'airtel'], 'mtn' => [$mtnOn, $mtnCharge, 'MTN MoMo', 'mtn']] as $wslug => $w): if (!$w[0]) continue; ?>
          <div class="paypanel<?= $payFirst === $wslug ? ' on' : '' ?>" id="panel-<?= e($wslug) ?>">
          <h3>Pay with <?= e($w[2]) ?></h3>
          <p style="color:var(--mute);font-size:15px;margin-bottom:12px">Mobile money, charged in <?= e($w[1][1]) ?>: <?= e(pay_amt_text($w[1][0], $w[1][1])) ?>.<?= ($wslug === 'mtn' && MTN_MOMO_ENV === 'sandbox') || ($wslug === 'airtel' && AIRTEL_ENV !== 'production') ? ' <strong>(test mode)</strong>' : '' ?></p>
          <form class="walletForm" method="post" action="<?= e(url('payments/wallet-init.php')) ?>" data-status="<?= e(url('payments/wallet-status.php')) ?>" data-success="<?= e(url('payments/success.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="provider" value="<?= e($wslug) ?>">
            <input type="hidden" name="plan" value="<?= e($plan['slug']) ?>">
            <input type="hidden" name="coupon" value="<?= e($couponCode) ?>">
            <div class="field">
              <label for="phone-<?= e($wslug) ?>"><?= e($w[2]) ?> number</label>
              <input type="tel" id="phone-<?= e($wslug) ?>" name="phone" placeholder="+<?= e($wslug === 'mtn' ? MTN_MOMO_MSISDN_PREFIX : AIRTEL_MSISDN_PREFIX) ?> ..." required autocomplete="tel">
              <div class="hint">A prompt appears on this phone. Enter your <?= e($w[2]) ?> PIN to pay.</div>
            </div>
            <?= pay_submit_button($wslug, 'Pay ' . pay_amt_text($w[1][0], $w[1][1]) . ' with ' . $w[2], '', 'submit') ?>
            <div class="msg walletMsg" role="status" aria-live="polite"></div>
          </form>
          </div>
        <?php endforeach; ?>

        <?php if ($stripeOn): ?>
          <div class="paypanel<?= $payFirst === 'stripe' ? ' on' : '' ?>" id="panel-stripe">
          <h3>Pay by card</h3>
          <p style="color:var(--mute);font-size:15px;margin-bottom:12px">Secure card payment by Stripe (Visa, Mastercard and more). Choose the currency you want to be charged in.</p>
          <div id="stripe-box" class="pb-stripe" data-init="<?= e(url('payments/stripe-init.php')) ?>" data-plan="<?= e($plan['slug']) ?>" data-coupon="<?= e($couponCode) ?>" data-csrf="<?= e(csrf_token()) ?>">
            <div class="paycur" role="radiogroup" aria-label="Currency">
              <?php $firstC = true; foreach ($stripeAmts as $sc => $sa): ?>
                <label><input type="radio" name="stripecur" value="<?= e($sc) ?>" data-label="<?= e('Pay ' . pay_amt_text($sa[0], $sc) . ' by card') ?>"<?= $firstC ? ' checked' : '' ?>><span><?= e($sc) ?> <?= e(pay_amt_text($sa[0], $sc)) ?></span></label>
              <?php $firstC = false; endforeach; ?>
            </div>
            <?= pay_submit_button('stripe', 'Pay ' . pay_amt_text($stripeAmts['USD'][0], 'USD') . ' by card', 'stripe-btn') ?>
            <div id="stripeMsg" class="msg" role="status" aria-live="polite"></div></div>
          </div>
        <?php endif; ?>

        <?php if ($paypalOn): ?>
          <div class="paypanel<?= $payFirst === 'paypal' ? ' on' : '' ?>" id="panel-paypal">
          <h3>Pay with PayPal</h3>
          <p style="color:var(--mute);font-size:15px;margin-bottom:12px">Charged in US dollars: $<?= e($usd) ?>. You can use your PayPal balance or a card.</p>
          <div id="paypal-buttons"
               data-create="<?= e(url('payments/paypal-create.php')) ?>"
               data-capture="<?= e(url('payments/paypal-capture.php')) ?>"
               data-success="<?= e(url('payments/success.php')) ?>"
               data-plan="<?= e($plan['slug']) ?>"
               data-coupon="<?= e($couponCode) ?>"
               data-csrf="<?= e(csrf_token()) ?>"></div>
          <div id="paypalMsg" class="msg" role="status" aria-live="polite"></div>
          </div>
        <?php endif; ?>

        <?php if ($paystackOn): ?>
          <div class="paypanel<?= $payFirst === 'paystack' ? ' on' : '' ?>" id="panel-paystack">
          <h3>Pay in Naira</h3>
          <p style="color:var(--mute);font-size:15px;margin-bottom:12px">Card or bank transfer, charged in Nigerian naira: <?= e(format_money((float)$ngnAmt, currency_row('NGN'))) ?>.</p>
          <div id="paystack-box" data-init="<?= e(url('payments/paystack-init.php')) ?>" data-plan="<?= e($plan['slug']) ?>" data-coupon="<?= e($couponCode) ?>" data-csrf="<?= e(csrf_token()) ?>">
            <?= pay_submit_button('paystack', 'Pay ' . format_money((float)$ngnAmt, currency_row('NGN')) . ' with Paystack', 'paystack-btn') ?>
            <div id="paystackMsg" class="msg" role="status" aria-live="polite"></div></div>
          </div>
        <?php endif; ?>

        <?php if ($razorOn): ?>
          <div class="paypanel<?= $payFirst === 'razorpay' ? ' on' : '' ?>" id="panel-razorpay">
          <h3>Pay in Indian rupees</h3>
          <p style="color:var(--mute);font-size:15px;margin-bottom:12px">UPI, card or net banking, charged in Indian rupees: <?= e(format_money((float)$inrAmt, currency_row('INR'))) ?>.</p>
          <div id="razorpay-box" data-create="<?= e(url('payments/razorpay-create.php')) ?>" data-verify="<?= e(url('payments/razorpay-verify.php')) ?>" data-success="<?= e(url('payments/success.php')) ?>" data-plan="<?= e($plan['slug']) ?>" data-coupon="<?= e($couponCode) ?>" data-csrf="<?= e(csrf_token()) ?>">
            <?= pay_submit_button('razorpay', 'Pay ' . format_money((float)$inrAmt, currency_row('INR')) . ' with Razorpay', 'razorpay-btn') ?>
            <div id="razorpayMsg" class="msg" role="status" aria-live="polite"></div></div>
          </div>
        <?php endif; ?>
        </div>

        <?php if (!$payOrder): ?>
          <h3>Payments</h3>
          <p>Payments are not switched on yet. Please check back soon.</p>
          <?php if (is_admin()): ?><div class="note">Admin: add your keys in <code>includes/config-payments.php</code>, <code>includes/config-gateways.php</code> or <code>includes/config-wallets.php</code>.</div><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
<?php if ($razorOn): ?><script src="https://checkout.razorpay.com/v1/checkout.js"></script><?php endif; ?>
<?php if ($paypalOn): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?= e(rawurlencode(PAYPAL_CLIENT_ID)) ?>&amp;currency=USD&amp;intent=capture"></script>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
