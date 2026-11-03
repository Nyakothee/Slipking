<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$next = safe_next($_REQUEST['next'] ?? '');
$go   = $next !== '' ? $next : 'account/dashboard.php';

if (empty($user['mobile'])) {
    set_flash('info', 'Add your mobile number first.');
    redirect('account/profile.php');
}
if (!empty($user['mobile_verified'])) {
    set_flash('success', 'Your mobile number is already confirmed.');
    redirect($go);
}
$errors = [];
$info = '';
$mobile = (string)$user['mobile'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        $r = phone_code_send((int)$user['id'], $mobile);
        if ($r['ok']) { $info = $r['message']; } else { $errors[] = $r['message']; }
    } else {
        if (too_many_attempts(15)) {
            $errors[] = 'Too many attempts. Please wait 15 minutes and try again.';
        } else {
            record_attempt('phone-code');
            $r = phone_code_check((int)$user['id'], $mobile, (string)($_POST['code'] ?? ''));
            if ($r['ok']) {
                notify_user((int)$user['id'], 'Mobile number confirmed', 'Thank you. Your mobile number ' . phone_mask($mobile) . ' is confirmed.');
                set_flash('success', $r['message']);
                redirect($go);
            }
            $errors[] = $r['message'];
        }
    }
} elseif (sms_enabled() && ($_GET['sent'] ?? '') !== '1') {
    // First visit: the register page already sent a code. Only send here when none was sent in the last 10 minutes.
    $st = db()->prepare('SELECT 1 FROM phone_codes WHERE user_id = ? AND mobile = ? AND used = 0 AND expires_at > NOW() LIMIT 1');
    $st->execute([$user['id'], $mobile]);
    if (!$st->fetchColumn()) {
        $r = phone_code_send((int)$user['id'], $mobile);
        if ($r['ok']) { $info = $r['message']; } else { $errors[] = $r['message']; }
    }
}

$page_title = 'Confirm your mobile number - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="fcard">
      <h2>Confirm your mobile number</h2>
      <?php if (!sms_enabled()): ?>
        <p class="sub">SMS confirmation is not switched on for this site yet.</p>
        <p><a class="btn dark" href="<?= e(url($go)) ?>">Continue</a></p>
      <?php else: ?>
      <p class="sub">We sent a 6-digit code by SMS to <strong><?= e(phone_mask($mobile)) ?></strong>. It can take a minute to arrive.</p>
      <?php if ($info): ?><div class="note" role="status"><?= e($info) ?></div><?php endif; ?>
      <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" action="<?= e(url('auth/verify-phone.php')) ?>" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="code">SMS code</label>
          <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code" style="font-size:24px;letter-spacing:6px;text-align:center"></div>
        <button class="btn gold full" type="submit">Confirm</button>
      </form>
      <form method="post" action="<?= e(url('auth/verify-phone.php')) ?>" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>"><input type="hidden" name="action" value="resend">
        <button class="btn outline full" type="submit">Send a new code</button>
      </form>
      <div class="altlink">Wrong number? <a href="<?= e(url('account/profile.php')) ?>">Change it in your profile</a> &middot; <a href="<?= e(url($go)) ?>">Do this later</a></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
