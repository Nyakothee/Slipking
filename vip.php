<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/pay-ui.php';

$vip   = is_vip();
$accessFree = access_on();
$plans = $accessFree ? [] : db()->query('SELECT * FROM plans WHERE is_active = 1 AND tipster_id IS NULL ORDER BY sort_order, id')->fetchAll();
[$trialOk, $trialWhy] = trial_eligibility(current_user());
$trialDays = max(1, (int)setting('trial_days', '1'));

// Current plan for logged-in members
$mine = null;
if ($u = current_user()) {
    $st = db()->prepare("SELECT s.ends_at, p.name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                         WHERE s.user_id = ? AND s.status = 'active' AND s.ends_at > NOW()
                         ORDER BY s.ends_at DESC LIMIT 1");
    $st->execute([$u['id']]);
    $mine = $st->fetch() ?: null;
}

// Public VIP record, shown as one line above the comparison
$vipStats = db()->query("SELECT SUM(status = 'won') AS w, COUNT(*) AS n FROM tips WHERE is_vip = 1 AND tipster_id IS NULL AND status IN ('won','lost')")->fetch();
$vipRate  = ((int)$vipStats['n']) ? (int)round($vipStats['w'] / $vipStats['n'] * 100) : null;

// Comparison: price, per-day cost, saving against the shortest plan, and every feature line across all plans
$cmpFeatures = [];
$cmpHas = [];
$shortest = null;
foreach ($plans as $p) {
    $days = max(1, (int)$p['duration_days']);
    $perDay = $p['price_kes'] / $days;
    if ($shortest === null || $days < $shortest['days']) {
        $shortest = ['days' => $days, 'perDay' => $perDay];
    }
    foreach (preg_split('/\R/', trim((string)$p['features'])) as $f) {
        $f = trim($f);
        if ($f === '') continue;
        $key = mb_strtolower($f);
        $cmpFeatures[$key] = $f;
        $cmpHas[$p['id']][$key] = true;
    }
}

$page_title = 'VIP betting tips plans - ' . SITE_NAME;
$page_desc  = 'Unlock every VIP prediction. Pay with M-Pesa, Airtel Money, MTN MoMo, card or PayPal. Weekly, monthly and 3-month plans.';
$active     = 'vip';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <?= vip_tabs('plans') ?>
    <div class="head"><div><h2>VIP plans</h2><p>Every plan unlocks all VIP tips. Longer plans cost less per day.</p></div></div>

    <?php if ($mine): ?>
      <div class="status-active">Your <?= e($mine['name']) ?> plan is active until <?= e(date('j F Y, H:i', strtotime($mine['ends_at']))) ?>.
        <a href="<?= e(url('account/dashboard.php')) ?>" style="text-decoration:underline">Open your VIP picks</a></div>
    <?php endif; ?>

    <?php if ($trialOk): ?>
      <div class="status-active" style="display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap"><span>New here? Try every VIP pick free for <?= $trialDays ?> day(s). No payment needed.</span>
        <form method="post" action="<?= e(url('account/trial.php')) ?>" style="margin:0"><?= csrf_field() ?><button class="btn gold small" type="submit">Start my free trial</button></form></div>
    <?php elseif ($trialWhy === 'login'): ?>
      <div class="banner"><span>Create a free account to try VIP free.</span><a class="btn gold small" href="<?= e(url('auth/register.php')) ?>">Create account</a></div>
    <?php elseif ($trialWhy === 'verify'): ?>
      <div class="banner"><span>Confirm your email address to unlock your free VIP trial.</span></div>
    <?php elseif ($trialWhy === 'phone'): ?>
      <div class="banner"><span>Add your phone number to unlock your free VIP trial.</span><a class="btn dark small" href="<?= e(url('account/profile.php')) ?>">Open profile</a></div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/includes/guarantee.php'; $gb = guarantee_badge(); if ($gb !== ''): ?><div class="note" style="margin:0 0 18px"><?= $gb ?></div><?php endif; ?>
    <div class="plans plans-modern">
      <?php foreach ($plans as $p): ?>
        <div class="plan<?= $p['is_featured'] ? ' best' : '' ?>">
          <?php if ($p['is_featured']): ?><span class="tag">Most chosen</span><?php endif; ?>
          <h3><?= e($p['name']) ?></h3>
          <div class="price"><?= e(plan_price_display($p)) ?> <small>/ <?= e(plan_period((int)$p['duration_days'])) ?></small></div>
          <p style="color:var(--mute);font-size:14px"><span>About <?= e(display_price_kes($p['price_kes'] / max(1, $p['duration_days']))) ?> per day</span><?php if ((float)$p['price_usd'] > 0): ?> <span>With PayPal: $<?= e(number_format((float)$p['price_usd'], 2)) ?></span><?php endif; ?></p>
          <ul>
            <?php foreach (preg_split('/\R/', trim($p['features'])) as $f): if ($f === '') continue; ?>
              <li><?= e($f) ?></li>
            <?php endforeach; ?>
          </ul>
          <a class="btn <?= $p['is_featured'] ? 'gold' : 'dark' ?>" href="<?= e(url('payments/checkout.php?plan=' . urlencode($p['slug']))) ?>"><?= $mine ? 'Extend with ' : 'Choose ' ?><?= e($p['name']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($accessFree): ?><div class="fcard" style="text-align:center;max-width:620px;margin:0 auto"><h2>VIP is free for everyone right now</h2><p class="sub">Every VIP tip and accumulator is unlocked for members with active weekly access.</p><a class="btn gold" href="<?= e(url(is_logged_in() ? 'access.php' : 'auth/register.php')) ?>"><?= is_logged_in() ? 'My weekly access' : 'Create a free account' ?></a></div>
    <?php elseif (!$plans): ?><div class="empty">Plans are not available right now.</div><?php endif; ?>

    <div class="note">Plans are one-off payments and do not renew by themselves. No tip is guaranteed to win. Please bet only what you can afford to lose. 18+ only.</div>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap two">
    <div class="box2">
      <h3>Free vs VIP</h3>
      <div class="scroll">
        <table class="tbl">
          <thead><tr><th>What you get</th><th>Free</th><th>VIP</th></tr></thead>
          <tbody>
            <tr><td>Daily tips</td><td>A few</td><td>All of them</td></tr>
            <tr><td>Accumulators and correct score</td><td>No</td><td>Yes</td></tr>
            <tr><td>Written analysis</td><td>Yes</td><td>Yes</td></tr>
            <tr><td>Results archive</td><td>Yes</td><td>Yes</td></tr>
            <tr><td>Alerts and private group</td><td>No</td><td>Yes</td></tr>
          </tbody>
        </table>
      </div>
    </div>
    <div class="box2">
      <h3>How paying works</h3>
      <ul>
        <li>Choose a plan and sign in or create an account.</li>
        <li>Pay with M-Pesa, Airtel Money, MTN MoMo, card or PayPal (whichever the site has switched on). Mobile money shows a prompt on your phone.</li>
        <li>Your plan starts the moment the payment is confirmed.</li>
      </ul>
      <div class="pay"><?= pay_chips_html() ?: '<span>M-Pesa</span><span>PayPal</span>' ?></div>
    </div>
  </div>
</section>

<?php if ($wb = widgets_block('vip')): ?><div class="wrap" style="padding-top:12px"><?= $wb ?></div><?php endif; ?>
<section style="padding-top:0" id="compare">
  <div class="wrap">
    <div class="head"><div><h2>Compare plans</h2><p>See exactly what each plan gives you.<?php if ($vipRate !== null): ?> <span>VIP win rate so far: <?= $vipRate ?>% of <?= (int)$vipStats['n'] ?> settled tips.</span><?php endif; ?> <a href="<?= e(url('results.php')) ?>" style="text-decoration:underline">Settled picks are public, wins and losses alike.</a></p></div></div>
    <?php if ($plans): ?>
    <div class="cmp-wrap"><table class="cmp">
      <thead><tr><th scope="col"><span class="sr-only">Feature</span></th>
        <?php foreach ($plans as $p): ?><th scope="col" class="<?= $p['is_featured'] ? 'best' : '' ?>"><?= $p['is_featured'] ? '<span class="cmp-tag">Most chosen</span>' : '' ?><?= e($p['name']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <tr><th scope="row">Price</th><?php foreach ($plans as $p): ?><td class="<?= $p['is_featured'] ? 'best' : '' ?>"><strong><?= e(plan_price_display($p)) ?></strong></td><?php endforeach; ?></tr>
        <tr><th scope="row">Access for</th><?php foreach ($plans as $p): ?><td class="<?= $p['is_featured'] ? 'best' : '' ?>"><?= e(plan_period((int)$p['duration_days'])) ?></td><?php endforeach; ?></tr>
        <tr><th scope="row">Cost per day</th><?php foreach ($plans as $p): ?><td class="<?= $p['is_featured'] ? 'best' : '' ?>"><?= e(display_price_kes($p['price_kes'] / max(1, (int)$p['duration_days']))) ?></td><?php endforeach; ?></tr>
        <tr><th scope="row">You save</th><?php foreach ($plans as $p): $pd = $p['price_kes'] / max(1, (int)$p['duration_days']); $sv = ($shortest && $shortest['perDay'] > 0) ? (int)round((1 - $pd / $shortest['perDay']) * 100) : 0; ?><td class="<?= $p['is_featured'] ? 'best' : '' ?>"><?= $sv > 0 ? '<span class="cmp-save">' . $sv . '% cheaper per day</span>' : '<span class="cmp-dash">Starter price</span>' ?></td><?php endforeach; ?></tr>
        <?php foreach ($cmpFeatures as $key => $label): ?>
        <tr><th scope="row"><?= e($label) ?></th><?php foreach ($plans as $p): $has = !empty($cmpHas[$p['id']][$key]); ?><td class="<?= $p['is_featured'] ? 'best' : '' ?>"><?= $has ? '<span class="cmp-yes" aria-label="Included">&#10003;</span>' : '<span class="cmp-no" aria-label="Not included">&ndash;</span>' ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <tr class="cmp-cta"><th scope="row"></th><?php foreach ($plans as $p): ?><td class="<?= $p['is_featured'] ? 'best' : '' ?>"><a class="btn <?= $p['is_featured'] ? 'gold' : 'dark' ?> small" href="<?= e(url('payments/checkout.php?plan=' . urlencode($p['slug']))) ?>"><?= $mine ? 'Extend' : 'Choose' ?></a></td><?php endforeach; ?></tr>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap faq">
    <div class="head"><h2>Before you pay</h2></div>
    <details><summary>What if my M-Pesa payment does not activate my plan?</summary><p>Wait two minutes and refresh. If it still shows inactive, contact us with your M-Pesa confirmation message and we will activate it.</p></details>
    <details><summary>Can I get a refund?</summary><p>Refund terms will be listed on the terms page. Please read them before you pay.</p></details>
    <details><summary>Which currency is used?</summary><p>M-Pesa payments are in Kenya shillings. PayPal payments are charged in US dollars at the price shown on the plan. Card payments (Stripe) can be charged in US dollars, euros or pounds, and Airtel Money and MTN MoMo are charged in the local currency shown at checkout (for example Tanzanian or Ugandan shillings).</p></details>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
