<?php
require_once dirname(__DIR__) . '/includes/init.php';
$email = strtolower(trim((string)($_GET['e'] ?? $_POST['e'] ?? '')));
$reason = (string)($_GET['r'] ?? '');
$done = false;
$err = '';
if ($email !== '' && acct2_ready()) {
    $st = db()->prepare("SELECT id FROM users WHERE email = ? AND banned_at IS NOT NULL AND role = 'user'");
    $st->execute([$email]);
    $uid = (int)$st->fetchColumn();
    if ($uid) {
        $why = acct_ban($uid);
        $reason = $why ?? $reason;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
            $msg = trim(mb_substr((string)($_POST['message'] ?? ''), 0, 1500));
            $open = db()->prepare("SELECT COUNT(*) FROM ban_appeals WHERE user_id = ? AND status = 'open'");
            $open->execute([$uid]);
            if (mb_strlen($msg) < 10) { $err = 'Please explain in at least a sentence.'; }
            elseif ((int)$open->fetchColumn()) { $err = 'You already have an appeal waiting. We will answer by email.'; }
            else { db()->prepare('INSERT INTO ban_appeals (user_id, message) VALUES (?,?)')->execute([$uid, $msg]); $done = true; }
        }
    } else { $email = ''; }
}
$page_title = 'Account suspended - ' . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap" style="max-width:560px">
  <div class="head"><h2>Your account is suspended</h2></div>
  <?php if ($email === ''): ?><p>This page is for suspended members. <a href="<?= e(url('auth/login.php')) ?>">Log in</a></p>
  <?php else: ?>
    <div class="note"><strong>Reason:</strong> <?= e($reason) ?></div>
    <?php if ($done): ?><div class="alert ok">Thank you. We got your appeal and will reply to <?= e($email) ?>.</div>
    <?php else: ?>
      <?php if ($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
      <form method="post" class="box2"><?= csrf_field() ?><input type="hidden" name="e" value="<?= e($email) ?>">
        <h3>Appeal</h3>
        <div class="field"><label for="message">Why should we restore your account?</label><textarea id="message" name="message" maxlength="1500" required></textarea></div>
        <button class="btn gold" type="submit">Send appeal</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
