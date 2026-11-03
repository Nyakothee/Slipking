<?php
require_once dirname(__DIR__) . '/includes/init.php';
if (is_logged_in()) {
    redirect('account/profile.php');
}

$errors = [];
$done   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(post_str('email'));
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (too_many_attempts(5)) {
        $errors[] = 'Too many requests. Please wait 15 minutes and try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } else {
        record_attempt($email);
        $st = db()->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        if ($u = $st->fetch()) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)')
                ->execute([$u['id'], hash('sha256', $token)]);
            send_mail($email, 'Reset your ' . SITE_NAME . ' password',
                "Hi {$u['name']},\n\nOpen this link to choose a new password (valid for 1 hour):\n\n" .
                url('auth/reset-password.php?token=' . $token) . "\n\nIf you did not ask for this, ignore this message. Your password stays the same.\n\n" . SITE_NAME);
        }
        $done = true;      // same answer whether or not the email exists
    }
}

$page_title = 'Forgot password - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="fcard">
      <h2>Forgot password</h2>
      <?php if ($done): ?>
        <p class="sub">If that email has an account, a reset link is on its way. It is valid for 1 hour. Check your spam folder too.</p>
        <a class="btn dark full" href="<?= e(url('auth/login.php')) ?>">Back to log in</a>
      <?php else: ?>
        <p class="sub">Enter your email and we will send you a link to choose a new password.</p>
        <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" action="<?= e(url('auth/forgot-password.php')) ?>">
          <?= csrf_field() ?>
          <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" required autocomplete="email"></div>
          <button class="btn gold full" type="submit">Send reset link</button>
        </form>
        <div class="altlink"><a href="<?= e(url('auth/login.php')) ?>">Back to log in</a></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
