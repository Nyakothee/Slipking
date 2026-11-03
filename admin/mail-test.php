<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('mail');
$me = current_user();
$to = (string)($me['email'] ?? '');
$result = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (too_many_attempts(8)) {
        $errors[] = 'Too many attempts. Please wait 15 minutes.';
    } else {
        $to = post_str('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } else {
            record_attempt('mail-test');
            $result = mail_test($to);
            admin_log('mail.test', 'mail', ($result['ok'] ? 'sent' : 'failed') . ' via ' . $result['driver']);
        }
    }
}
$log = mail_log_tail(20);

$admin_title = 'Email test';
$admin_active = 'mail-test';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Email test</h1>
<p class="sub">Sends a test message with the settings in <code>includes/config-mail.php</code>. The password is never shown here.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($result): ?>
  <div class="flash <?= $result['ok'] ? 'success' : 'error' ?>" role="status" style="border-radius:8px;padding:10px 14px;margin-bottom:16px">
    <?= $result['ok'] ? 'The message was handed to the mail system. Check the inbox and the spam folder.' : 'Sending failed.' ?>
    <?= $result['error'] !== '' ? ' ' . e($result['error']) : '' ?>
  </div>
<?php endif; ?>
<div class="panel"><h2>Current setup</h2>
  <table class="kv">
    <tr><td>Method</td><td><?= MAIL_DRIVER === 'smtp' ? 'SMTP' : 'PHP mail()' ?></td></tr>
    <?php if (MAIL_DRIVER === 'smtp'): ?>
    <tr><td>Server</td><td><?= e(MAIL_SMTP_HOST) ?>:<?= (int)MAIL_SMTP_PORT ?> (<?= e(MAIL_SMTP_SECURE !== '' ? MAIL_SMTP_SECURE : 'no encryption') ?>)</td></tr>
    <tr><td>Username</td><td><?= e(MAIL_SMTP_USER) ?></td></tr>
    <tr><td>Password</td><td><?= MAIL_SMTP_PASS !== '' ? 'set' : 'not set' ?></td></tr>
    <?php endif; ?>
    <tr><td>From address</td><td><?= e(SITE_EMAIL) ?></td></tr>
  </table>
</div>
<form method="post" action="<?= e(url('admin/mail-test.php')) ?>"><?= csrf_field() ?>
  <div class="panel"><h2>Send a test</h2>
    <div class="field"><label for="to">Send to</label><input type="email" id="to" name="to" value="<?= e($to) ?>" maxlength="150" required style="max-width:360px"></div>
  </div>
  <button class="btn gold" type="submit">Send test email</button>
</form>
<?php if ($log !== ''): ?>
<div class="panel" style="margin-top:22px"><h2>Latest lines of logs/mail.log</h2><pre style="white-space:pre-wrap;font-size:13px;overflow:auto"><?= e($log) ?></pre></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
