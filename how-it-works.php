<?php
require_once __DIR__ . '/includes/init.php';

$page_title = 'How it works - ' . SITE_NAME;
$page_desc  = 'How SlipKing works: free tips, VIP picks, accumulators, public results and how to pay. Simple steps from sign-up to your first slip.';
$active     = '';

$steps = [
    ['Create a free account', 'Sign up with your email in under a minute. You must be 18 or older. Confirm your email and add your phone number to unlock extras like the free VIP trial.', 'auth/register.php', 'Create account'],
    ['Browse today\'s tips', 'Every tip shows the match, the pick, the odds, our confidence and a short analysis. Free tips are open to everyone.', 'tips.php', 'See today\'s tips'],
    ['Check the proof', 'Each tip is time-stamped by the server before kickoff, and it can never be changed afterwards. Settled tips, wins and losses alike, go on the public results page.', 'results.php', 'Open results'],
    ['Go VIP when you are ready', 'Pick a weekly, monthly or 3-month plan to unlock every VIP tip, accumulators and alerts. Plans are one-off payments and never renew by themselves.', 'vip.php', 'Compare plans'],
    ['Pay your way', 'Pay with M-Pesa, Airtel Money, MTN MoMo, card or PayPal. Mobile money shows a prompt on your phone, and your plan starts the moment payment is confirmed.', 'vip.php#compare', 'See payment options'],
    ['Place your own bets, responsibly', 'We never take bets. Take the pick to a licensed bookmaker of your choice, bet only what you can afford to lose, and use the betting calculator to plan your stake.', 'calculator.php', 'Betting calculator'],
];
$feat = [
    ['Free tips', 'A daily selection of free picks with written analysis.'],
    ['VIP tips', 'Every pick, plus correct score and higher-confidence selections.'],
    ['Accumulators', 'The free 1 Million accumulator and VIP accumulators, with the minimum stake worked out for you.'],
    ['Livestream and highlights', 'Links to watch matches with the rights holders, and YouTube highlights.'],
    ['Public results', 'A full archive of settled tips and a tamper-evident audit log.'],
    ['Community', 'Follow tipsters, join the chat rooms and message other members.'],
];
include __DIR__ . '/includes/header.php';
?>
<section class="hiw-hero">
  <div class="wrap">
    <span class="hiw-kicker">How it works</span>
    <h1>From sign-up to your first slip in six steps</h1>
    <p>No hidden edits, no deleted losses. Here is exactly how SlipKing works.</p>
    <p><a class="btn gold" href="<?= e(url('auth/register.php')) ?>">Create free account</a> <a class="btn outline light" href="<?= e(url('vip.php')) ?>">View VIP plans</a></p>
  </div>
</section>

<section>
  <div class="wrap">
    <ol class="hiw-steps">
      <?php foreach ($steps as $i => $st): ?>
      <li class="hiw-step">
        <span class="hiw-num"><?= $i + 1 ?></span>
        <div><h3><?= e($st[0]) ?></h3><p><?= e($st[1]) ?></p><a href="<?= e(url($st[2])) ?>"><?= e($st[3]) ?> &rarr;</a></div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap">
    <div class="head"><div><h2>What you get</h2><p>Everything is built around honest, checkable records.</p></div></div>
    <div class="hiw-feats">
      <?php foreach ($feat as $f): ?><div class="hiw-feat"><h3><?= e($f[0]) ?></h3><p><?= e($f[1]) ?></p></div><?php endforeach; ?>
    </div>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap faq">
    <div class="head"><h2>Quick answers</h2></div>
    <details><summary>Is any tip guaranteed to win?</summary><p>No. Betting always carries risk and no tip is guaranteed. Bet only what you can afford to lose.</p></details>
    <details><summary>Can you edit or hide a tip after the match?</summary><p>No. The posting time is set by the server and every tip is sealed in an audit log. Settled tips stay on the public results page.</p></details>
    <details><summary>Do plans renew automatically?</summary><p>No. Every plan is a one-off payment. You choose when to extend.</p></details>
    <details><summary>Do you take bets?</summary><p>No. SlipKing gives information and predictions only. Bets are placed with a licensed bookmaker of your own choice.</p></details>
    <details><summary>I paid but my plan is not active.</summary><p>Wait two minutes and refresh. If it still shows inactive, <a href="<?= e(url('contact.php')) ?>" style="text-decoration:underline">contact us</a> with your payment confirmation and we will fix it.</p></details>
  </div>
  <div class="wrap"><div class="note">18+ only. Please gamble responsibly. <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Get help and set limits</a>.</div></div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
