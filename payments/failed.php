<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$page_title = 'Payment not completed - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="fcard center">
      <h2>Payment not completed</h2>
      <p class="sub">Nothing was charged, or M-Pesa / PayPal will return it. If money left your account but your plan is not active, contact us with your confirmation message.</p>
      <p><a class="btn gold full" href="<?= e(url('vip.php')) ?>">Choose a plan again</a></p>
      <p class="altlink"><a href="<?= e(url('contact.php')) ?>">Contact support</a></p>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
