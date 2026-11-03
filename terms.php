<?php
/*
 * TEMPLATE - NOT LEGAL ADVICE.
 * Edit the wording to match how you really run the business (especially section 6, Refunds),
 * and have a lawyer in your country review it before you launch. Gambling-related services
 * are regulated in many countries; check what applies to you.
 */
require_once __DIR__ . '/includes/init.php';
$page_title = 'Terms of use - ' . SITE_NAME;
$page_desc  = 'The rules for using ' . SITE_NAME . ', VIP plans and payments.';
$active = '';
$english_only = true;
include __DIR__ . '/includes/header.php';
$n = SITE_NAME;
?>
<section>
  <div class="wrap">
    <article class="article">
      <h1>Terms of use</h1>
      <div class="by">Last updated: <?= e(date('j F Y')) ?></div>

      <p><strong>1. Who can use the site.</strong> You must be 18 or older. By creating an account or buying a plan you confirm that you are.</p>
      <p><strong>2. What we provide.</strong> <?= e($n) ?> publishes sports predictions for information and entertainment. We are not a bookmaker, we do not take bets, and we do not hold your money for betting.</p>
      <p><strong>3. No guarantees.</strong> Predictions are opinions. We do not promise any result or profit. Past results do not guarantee future results. You are fully responsible for any bet you place and for any money you lose.</p>
      <p><strong>4. Your account.</strong> Give true details and keep your password private. You are responsible for what happens on your account. Tell us at once if you think someone else is using it.</p>
      <p><strong>5. VIP plans and payments.</strong> VIP plans are one-off payments for a fixed period (7, 30 or 90 days, or as shown when you buy). They do not renew by themselves. Payment is by M-Pesa (in Kenya shillings) or PayPal (in US dollars). Your plan starts when payment is confirmed. If you buy while you already have VIP, the new days are added after your current plan ends.</p>
      <p><strong>6. Refunds.</strong> Because VIP tips are delivered as soon as your plan starts, payments are generally not refundable. We will correct mistakes we cause, such as a double charge or a payment that did not activate your plan. To ask, contact us with your M-Pesa receipt code or PayPal receipt.</p>
      <p><strong>7. Fair use.</strong> VIP tips are for your own use. Do not copy, resell, or publish them (for example in groups, channels or other sites) before the match is over. Do not try to break, overload or get around the site, or use another person's payment details. We may suspend accounts that break these rules.</p>
      <p><strong>7a. Live chat and enquiries.</strong> Live chat and enquiry forms are for support and information. Sending an enquiry (for example about account management) does not create an agreement; any service is agreed separately and in writing. Never send passwords, PINs or codes through this site. We will never ask for them.</p>
      <p><strong>8. Our responsibility.</strong> We work hard to keep the site running and accurate, but it is provided as it is. To the extent the law allows, we are not responsible for betting losses, missed tips, or interruptions to the site or to payment services run by others.</p>
      <p><strong>9. Changes.</strong> We may update these terms or our prices. New prices apply to new purchases only. The date above shows the latest version.</p>
      <p><strong>10. Law.</strong> These terms follow the laws of the country where <?= e($n) ?> is operated.</p>
      <p><strong>11. Contact.</strong> Questions? Use our <a href="<?= e(url('contact.php')) ?>" style="text-decoration:underline">contact page</a>.</p>
    </article>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
