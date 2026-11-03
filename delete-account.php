<?php
require_once __DIR__ . '/includes/init.php';
$page_title = 'Delete your account - ' . SITE_NAME;
$page_desc  = 'How to delete your ' . SITE_NAME . ' account and what happens to your data.';
$active = '';
$english_only = true;
$u = current_user();

if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'DELETE') {
        set_flash('error', 'Type DELETE in the box to confirm.');
    } else {
        $why = account_delete_member(db(), (int)$u['id'], (string)($_POST['password'] ?? ''));
        if ($why === '') {
            $_SESSION = [];
            session_regenerate_id(true);
            set_flash('success', 'Your account has been deleted. Your personal details were erased.');
            redirect('index.php');
        }
        set_flash('error', $why);
    }
    redirect('delete-account.php');
}
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <article class="article">
      <h1>Delete your account</h1>
      <p>You can delete your <?= e(SITE_NAME) ?> account at any time, from this page or from the app (Me &gt; Delete my account).</p>
      <h3>What is deleted</h3>
      <p>Your name, email, phone number, password, profile, private messages, notification and device settings, and alert preferences. Your VIP access and any tipster page are closed.</p>
      <h3>What stays</h3>
      <p>Payment records are kept without your personal details, because accounting and the law require them. Public comments you wrote stay but show "Deleted member". Published tipster results stay on the public record.</p>
      <h3>Before you go</h3>
      <p>Deleting cannot be undone, and an active VIP plan is not refunded. If you only want a break, you can just log out. Need help instead? <a href="<?= e(url('contact.php')) ?>">Contact us</a>.</p>
<?php if ($u): ?>
      <form method="post" class="panel" style="max-width:420px;margin-top:16px">
        <?= csrf_field() ?>
        <label>Your password <input type="password" name="password" autocomplete="current-password" required></label>
        <label>Type DELETE to confirm <input type="text" name="confirm" autocomplete="off" required></label>
        <button class="btn dark" type="submit" onclick="return confirm('Delete your account for good?');">Delete my account</button>
      </form>
<?php else: ?>
      <p style="margin-top:16px"><a class="btn dark" href="<?= e(url('auth/login.php?next=' . urlencode('delete-account.php'))) ?>">Log in to delete your account</a></p>
      <p style="color:var(--mute)">Cannot log in? <a href="<?= e(url('contact.php')) ?>">Contact us</a> from the email address on the account and we will delete it for you.</p>
<?php endif; ?>
    </article>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
