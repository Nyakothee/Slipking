<?php
require_once dirname(__DIR__) . '/includes/init.php';
$next = safe_next($_REQUEST['next'] ?? '');
if (is_logged_in()) {
    redirect($next !== '' ? $next : 'account/dashboard.php');
}

$errors = [];
$email  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(post_str('email'));
    $pw    = (string)($_POST['password'] ?? '');

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (too_many_attempts(8)) {
        $errors[] = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT id, password_hash, role FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $u = $st->fetch();
        // Always run a password check, so a wrong email takes as long as a wrong password.
        $hash = $u ? $u['password_hash'] : password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $ok   = password_verify($pw, $hash) && $u;

        if ($ok) {
            clear_attempts();
            if (($banWhy = acct_ban((int)$u['id'])) !== null) {
                redirect('auth/appeal.php?e=' . rawurlencode($email) . '&r=' . rawurlencode($banWhy));
            }
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
            }
            log_in_user((int)$u['id']);
            $ts = db()->prepare('SELECT is_tipster_account FROM users WHERE id = ?');
            $ts->execute([$u['id']]);
            if ((int)$ts->fetchColumn() === 1 && !in_array($u['role'], STAFF_ROLES, true)) {
                redirect('tipster/index.php');          // tipsters always land in their own area
            }
            redirect($next !== '' ? $next : 'account/dashboard.php');
        }
        record_attempt($email);
        $errors[] = 'The email or password is not correct.';
    }
}

$page_title = 'Log in - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section class="auth-split">
  <div class="auth-promo" aria-hidden="false">
    <div class="auth-promo-in">
      <span class="auth-kicker">Matchday ready</span>
      <h1>Your winning edge<br>starts here.</h1>
      <ul>
        <li>50+ leagues covered</li>
        <li>Verified results and track record</li>
        <li>Pay with M-Pesa, Airtel Money, MTN MoMo or card</li>
      </ul>
    </div>
  </div>
  <div class="auth-card-col">
    <div class="fcard">
      <h2>Welcome back</h2>
      <p class="sub">Log in to see today's tips and your VIP picks.</p>
      <?php if (access_on()): ?><div class="note" style="margin:12px 0;border-left:4px solid #f0a91c"><b>Weekly access:</b> KES <?= (int)access_kes() ?> / week (Kenya, Uganda, Tanzania) or USD <?= e(number_format(access_usd(), 2)) ?> / week (other countries). <?= 'Your first '.access_free_days().' days are free after you join. ' ?>All VIP tips are free for everyone while this is on.<br><a class="btn gold" style="margin-top:10px" href="<?= e(url('access.php')) ?>">Pay weekly access (KES <?= (int)access_kes() ?> / USD <?= e(number_format(access_usd(), 2)) ?>)</a></div><?php endif; ?>
      <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" action="<?= e(url('auth/login.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($email) ?>" required autocomplete="email"></div>
        <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button class="btn gold full" type="submit">Log in</button>
      </form>
      <div class="altlink"><a href="<?= e(url('auth/forgot-password.php')) ?>">Forgot your password?</a></div>
      <div class="altlink"><a href="<?= e(url('index.php')) ?>">&larr; Back to SlipKing</a></div>
      <div class="altlink">New here? <a href="<?= e(url('auth/register.php' . ($next !== '' ? '?next=' . urlencode($next) : ''))) ?>">Create an account</a></div>
    </div>
  </div>
</section>
<script>
(function(){document.querySelectorAll('.auth-split input[type=password]').forEach(function(i){var b=document.createElement("button");b.type="button";b.className="pw-eye";b.textContent="Show";b.setAttribute("aria-label","Show or hide password");b.addEventListener("click",function(){var s=i.type==="password";i.type=s?"text":"password";b.textContent=s?"Hide":"Show";});i.parentNode.style.position="relative";i.parentNode.appendChild(b);});})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
