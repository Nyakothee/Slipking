<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('mail');
$errors = [];
$result = null;
$country = country_default();
$num = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $country = strtoupper(post_str('country'));
    $num = post_str('mobile');
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!sms_enabled()) {
        $errors[] = 'SMS is switched off. Set SMS_DRIVER in includes/config-sms.php first.';
    } elseif (too_many_attempts(6)) {
        $errors[] = 'Too many attempts. Please wait 15 minutes.';
    } else {
        $pp = phone_parse($country, $num);
        if (!$pp['ok']) {
            $errors[] = $pp['error'];
        } else {
            record_attempt('sms-test');
            $err = '';
            $ok = sms_send($pp['mobile'], 'Test message from ' . SITE_NAME . '. SMS is working.', $err);
            $result = ['ok' => $ok, 'err' => $err, 'to' => phone_mask($pp['mobile'])];
            admin_log('sms.test', 'sms', ($ok ? 'sent' : 'failed') . ' via ' . SMS_DRIVER);
        }
    }
}
$logf = dirname(__DIR__) . '/logs/sms.log';
$tail = is_file($logf) ? implode("\n", array_slice(file($logf, FILE_IGNORE_NEW_LINES) ?: [], -15)) : '';

$admin_title = 'SMS test';
$admin_active = 'sms-test';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>SMS test</h1>
<p class="sub">Current driver: <strong><?= e(SMS_DRIVER) ?></strong>. Settings are in <code>includes/config-sms.php</code>. Each test sends one real SMS and may cost money.</p>
<?php if (SMS_DRIVER === 'log'): ?><div class="note">Driver "log" is for testing only: codes are written to logs/sms.log and nothing is sent. Switch to a real provider before you go live.</div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<?php if ($result): ?><div class="alert <?= $result['ok'] ? 'success' : 'error' ?>" role="status"><?= $result['ok'] ? 'The provider accepted the message to ' . e($result['to']) . '.' : 'Sending failed: ' . e($result['err']) ?></div><?php endif; ?>
<div class="panel" style="max-width:560px">
  <form method="post"><?= csrf_field() ?>
    <div class="field"><label for="country">Country</label><select id="country" name="country"><?= country_options($country) ?></select></div>
    <div class="field"><label for="mobile">Mobile number (without country code)</label><input id="mobile" name="mobile" value="<?= e($num) ?>" required maxlength="20"></div>
    <button class="btn dark" type="submit">Send a test SMS</button>
  </form>
</div>
<?php if ($tail !== ''): ?><div class="panel"><h2>Recent SMS log</h2><pre style="white-space:pre-wrap;word-break:break-all;font-size:13px;max-height:280px;overflow:auto"><?= e($tail) ?></pre></div><?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
