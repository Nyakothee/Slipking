<?php
require_once dirname(__DIR__) . '/includes/init.php';
if (is_staff()) {
    redirect('admin/index.php');
}

require_once dirname(__DIR__) . '/includes/totp.php';

$errors = [];
$email  = '';

// Step 2 state: the password was right and this admin has two-factor on. NOT logged in yet.
$pending = $_SESSION['admin_2fa'] ?? null;
if ($pending && (!is_array($pending) || ($pending['exp'] ?? 0) < time())) {
    unset($_SESSION['admin_2fa']);
    $pending = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (post_str('step') === 'cancel') {
        unset($_SESSION['admin_2fa']);
        $pending = null;
    } elseif (post_str('step') === '2') {
        if (!$pending) {
            $errors[] = 'Your sign-in took too long. Please enter your password again.';
        } elseif (too_many_attempts(6)) {
            unset($_SESSION['admin_2fa']);
            $pending = null;
            $errors[] = 'Too many attempts. Please wait 15 minutes.';
        } else {
            $uid    = (int)$pending['uid'];
            $secret = totp_secret_for($uid);
            if ($secret !== null && second_factor_ok($uid, $secret, (string)($_POST['code'] ?? ''))) {
                unset($_SESSION['admin_2fa']);
                clear_attempts();
                log_in_user($uid);
                admin_log('admin.login', '', 'two-factor');
                redirect('admin/index.php');
            }
            record_attempt('2fa#' . $uid);
            $errors[] = 'That code is not correct. Try the current code from your app, or a recovery code.';
        }
    } elseif (too_many_attempts(6)) {
        $errors[] = 'Too many attempts. Please wait 15 minutes.';
    } else {
        $email = strtolower(post_str('email'));
        $pw    = (string)($_POST['password'] ?? '');
        $st = db()->prepare("SELECT id, password_hash, role FROM users WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        $u    = $st->fetch();
        $hash = $u ? $u['password_hash'] : password_hash('not-a-real-password', PASSWORD_DEFAULT);
        if (password_verify($pw, $hash) && $u && in_array($u['role'], STAFF_ROLES, true)) {
            if (totp_secret_for((int)$u['id']) !== null) {
                // Password alone does not log in. Attempts are NOT cleared here, so codes cannot be guessed without limit.
                session_regenerate_id(true);
                $_SESSION['admin_2fa'] = ['uid' => (int)$u['id'], 'exp' => time() + 300];
                $pending = $_SESSION['admin_2fa'];
            } else {
                clear_attempts();
                log_in_user((int)$u['id']);
                admin_log('admin.login');
                redirect('admin/index.php');
            }
        } else {
            record_attempt($email);
            $errors[] = 'The email or password is not correct, or this account does not have staff access.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Admin login - <?= e(SITE_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/admin.css')) ?>">
</head>
<body class="admin">
<div class="alogin">
  <div class="fcard" style="width:100%">
    <h2>Admin login</h2>
    <p class="sub"><?= e(SITE_NAME) ?> control panel</p>
    <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($pending): ?>
    <form method="post" action="<?= e(url('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="2">
      <p class="sub">Two-factor check. Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
      <div class="field"><label for="code">Code</label><input type="text" id="code" name="code" required autocomplete="one-time-code" inputmode="text" maxlength="12" autofocus></div>
      <button class="btn gold full" type="submit">Verify and log in</button>
    </form>
    <form method="post" action="<?= e(url('admin/login.php')) ?>" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="step" value="cancel">
      <button class="btn ghost full" type="submit" style="color:inherit">Start over</button>
    </form>
    <?php else: ?>
    <form method="post" action="<?= e(url('admin/login.php')) ?>">
      <?= csrf_field() ?>
      <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="email"></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
      <button class="btn gold full" type="submit">Log in</button>
    </form>
    <?php endif; ?>
    <div class="altlink"><a href="<?= e(url()) ?>">Back to the site</a></div>
  </div>
</div>
</body>
</html>
