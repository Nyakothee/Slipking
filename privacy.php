<?php
/*
 * TEMPLATE - NOT LEGAL ADVICE. It describes what this software actually does.
 * If you add analytics, ads or new services, update it. Have a lawyer check it against the
 * data protection law of your country before launch.
 */
require_once __DIR__ . '/includes/init.php';
$page_title = 'Privacy policy - ' . SITE_NAME;
$page_desc  = 'What information ' . SITE_NAME . ' collects, why, and your choices.';
$active = '';
$english_only = true;
include __DIR__ . '/includes/header.php';
$n = SITE_NAME;
?>
<section>
  <div class="wrap">
    <article class="article">
      <h1>Privacy policy</h1>
      <div class="by">Last updated: <?= e(date('j F Y')) ?></div>

      <p><strong>What we collect.</strong> When you create an account: your name, email address, password (stored only in scrambled form, we cannot read it) and, if you give it, your phone number. When you pay: the plan, amount, time, and the M-Pesa receipt code, phone number or PayPal order reference. We keep the address of the device you connect from for a short time to stop abuse such as repeated login attempts.</p>
      <p><strong>What we do not collect.</strong> We never see or store your M-Pesa PIN or your card or PayPal login details. Those are handled by Safaricom and PayPal.</p>
      <p><strong>Why we use it.</strong> To run your account, deliver VIP tips, confirm and record payments, answer your messages, send account emails (such as email confirmation and password reset), keep the site secure, and meet legal duties.</p>
      <p><strong>Who else sees it.</strong> Payments are processed by Safaricom (M-Pesa) and PayPal, who receive the details needed to take the payment. Our hosting company stores the site's data. We do not sell your information.</p>
      <p><strong>Cookies and other services.</strong> We use a session cookie so you stay logged in, plus small cookies that remember your chosen currency and language and, if you use live chat, your conversation. Match statistics come from football-data.org and odds from The Odds API, loaded by our server, not by your browser. Our pages also load fonts from Google Fonts, small team-initial pictures from ui-avatars.com, some photos from Unsplash, and the PayPal button from PayPal on the checkout page. Your browser contacts those services directly when you load a page.</p>
      <p><strong>Multi-account protection.</strong> When you log in we store your IP address and a short fingerprint of your browser for 90 days. We use it only to spot people running many accounts to abuse free trials and referral rewards, and for security. If you set a monthly limit or take a break (Account &gt; Limits) we store that choice and your confirmation that you are 18 or older.</p>
      <p><strong>Live chat and enquiries.</strong> If you use live chat we keep the messages, the name (and email, if you give one) you enter, and the address of your connection, so we can answer you and prevent abuse. If you send an account-management enquiry we keep what you type (name, phone or WhatsApp number, country, the name of your betting company, the amount you are considering, and your message) so we can respond. Never send us passwords, PINs or codes; we will never ask for them.</p>
      <p><strong>Android app.</strong> The app uses the same account and data as the website. In addition it stores a push-notification token for your device (only if you allow notifications), your chosen language and theme, and temporary copies of public screens so they open offline; these copies are wiped when you log out. The app contains no advertising or analytics software and does not read your contacts, location or files.</p>
      <p><strong>How long we keep it.</strong> Account details until you ask us to delete your account. Closed chats are deleted after about 90 days. Payment records for as long as needed for accounting and legal reasons.</p>
      <p><strong>Your choices.</strong> You can see and change your name and phone number in your account. You can delete your account yourself at any time on the <a href="<?= e(url('delete-account.php')) ?>">Delete account</a> page or in the app (Me &gt; Delete my account); your personal details are erased and payment records are kept without them. To ask for a copy of your information, contact us.</p>
      <p><strong>Security.</strong> Passwords are stored hashed, forms are protected against forged requests, and payment results are confirmed on our server. No system is perfectly secure, so please use a strong, unique password.</p>
      <p><strong>Age.</strong> <?= e($n) ?> is for people 18 or older. We do not knowingly collect information from anyone younger.</p>
      <p><strong>Changes and contact.</strong> If this policy changes we will update the date above. Questions: <a href="<?= e(url('contact.php')) ?>" style="text-decoration:underline">contact page</a>.</p>
    </article>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
