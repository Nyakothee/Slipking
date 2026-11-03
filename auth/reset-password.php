<?php
require_once dirname(__DIR__) . '/includes/init.php';

$token  = (string)($_REQUEST['token'] ?? '');
$errors = [];
$row    = null;

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW() LIMIT 1');
    $st->execute([hash('sha256', $token)]);
    $row = $st->fetch() ?: null;
}

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (strlen($pw) < 8) {
        $errors[] = 'Your password must be at least 8 characters.';
    } elseif ($pw !== $pw2) {
        $errors[] = 'The two passwords do not match.';
    } else {
        $pdo = db();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $row['user_id']]);
        $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$row['user_id']]);
        clear_attempts();
        set_flash('success', 'Your password has been changed. You can log in now.');
        redirect('auth/login.php');
    }
}

$page_title = 'Choose a new password - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="fcard">
      <h2>New password</h2>
      <?php if (!$row): ?>
        <p class="sub">This reset link is not valid or has expired.</p>
        <a class="btn dark full" href="<?= e(url('auth/forgot-password.php')) ?>">Get a new link</a>
      <?php else: ?>
        <p class="sub">Choose a password of at least 8 characters.</p>
        <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" action="<?= e(url('auth/reset-password.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="field"><label for="password">New password</label><input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"></div>
          <div class="field"><label for="password2">Repeat new password</label><input type="password" id="password2" name="password2" required minlength="8" autocomplete="new-password"></div>
          <button class="btn gold full" type="submit">Save password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
