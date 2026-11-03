<?php
require_once __DIR__ . '/includes/init.php';
$page_title = 'Responsible gambling - ' . SITE_NAME;
$page_desc  = 'Tips are not guarantees. How to keep betting under control and where to get help.';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <article class="article">
      <h1>Responsible gambling</h1>
      <p>Betting should be entertainment, never a way to make a living or to fix money problems. You must be 18 or older to use this site.</p>

      <h3>Our tips are not guarantees.</h3>
      <p>They are opinions. Any tip can lose, including our most confident ones. Never bet because a tip "cannot lose".</p>

      <h3>Keep it under control</h3>
      <p>Decide a budget before you start and stick to it. Only bet money you can afford to lose completely. Never borrow to bet. Do not chase losses with bigger stakes. Take regular breaks, and keep a record of what you spend.</p>

      <h3>Warning signs</h3>
      <p>Betting more than you planned. Hiding it from family or friends. Feeling anxious or angry when you cannot bet. Betting to win back losses. Money for rent, food or school fees going to bets. If any of these sound familiar, stop and talk to someone.</p>

      <h3>Where to get help</h3>
      <p>Speak to a trusted friend or family member. Licensed bookmakers offer tools to set deposit limits, take a break, or exclude yourself completely, so use them. Look for a gambling support organisation or counselling service in your own country and contact them. In Kenya, betting is regulated by the Betting Control and Licensing Board (BCLB), and counselling support is offered by organisations such as Befrienders Kenya. Search for their current contact details, as numbers change.</p>

      <h3>Your tools</h3>
      <p>You can set a monthly spending limit on our plans or take a break from paying for 1, 3 or 6 months, or for good, in <a href="<?= e(url('account/limits.php')) ?>" style="text-decoration:underline">Account &gt; Limits</a>. A break cannot be shortened once it starts. Please also use the limits and exclusion tools of every bookmaker you use.</p>

      <h3>Our part</h3>
      <p>We show every result, wins and losses, so you can judge us honestly. We never pressure anyone to buy VIP, and plans never renew by themselves.</p>
      <a class="btn dark" href="<?= e(url('contact.php')) ?>">Contact us</a>
    </article>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
