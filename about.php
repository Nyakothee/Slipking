<?php
require_once __DIR__ . '/includes/init.php';
$page_title = 'About - ' . SITE_NAME;
$page_desc  = 'How ' . SITE_NAME . ' works and why every result is published.';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <article class="article">
      <h1>About <?= e(SITE_NAME) ?></h1>
      <p><?= e(SITE_NAME) ?> publishes sports betting predictions. Some are free and open to everyone. The rest are VIP tips for members who take a paid plan.</p>
      <p>Every tip is stamped with the time it was posted, before the match starts. After kickoff a tip cannot be edited or deleted, and every result, win or loss, goes into a public archive.</p>
      <p><a href="<?= e(url('results.php')) ?>" style="text-decoration:underline">See our full results</a> before you spend anything.</p>
      <p>No tipster wins every time, and neither do we. Tips are opinions based on form and statistics, not promises. Only bet money you can afford to lose.</p>
      <p><a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Read about responsible gambling</a></p>
      <p>We do not take bets and we are not a bookmaker. You place any bet yourself, with a bookmaker that is licensed where you live.</p>
      <a class="btn dark" href="<?= e(url('contact.php')) ?>">Contact us</a>
    </article>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
