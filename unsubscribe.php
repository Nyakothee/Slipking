<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/bulk-mail.php';
$page_title = 'Unsubscribe - ' . SITE_NAME;
$active = '';
$email = strtolower((string)base64_decode(strtr((string)($_GET['e'] ?? ''), '-_', '+/'), true));
$sig = (string)($_GET['s'] ?? '');
$valid = filter_var($email, FILTER_VALIDATE_EMAIL) && hash_equals(bulk_unsub_sig($email), $sig);
$done = false;
if ($valid && bulk_ready() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    db()->prepare('INSERT IGNORE INTO mail_optout (email) VALUES (?)')->execute([$email]);
    $done = true;
}
include __DIR__ . '/includes/header.php';
?>
<section><div class="wrap" style="max-width:520px"><div class="fcard" style="text-align:center">
<?php if (!$valid): ?><h2>Link not valid</h2><p class="sub">This unsubscribe link is not valid or has expired.</p>
<?php elseif ($done): ?><h2>You are unsubscribed</h2><p class="sub"><?= e($email) ?> will no longer receive our email campaigns.</p>
<?php else: ?><h2>Unsubscribe</h2><p class="sub">Stop email campaigns to <b><?= e($email) ?></b>?</p>
  <form method="post"><button class="btn gold full" type="submit">Yes, unsubscribe me</button></form><?php endif; ?>
</div></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
