<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$english_only = true;
$ready = digest_ready();
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); }
    else {
        $on = !empty($_POST['email_daily']) ? 1 : 0;
        db()->prepare('INSERT INTO digest_prefs (user_id, email_daily) VALUES (?, ?) ON DUPLICATE KEY UPDATE email_daily = VALUES(email_daily)')->execute([$uid, $on]);
        set_flash('success', $on ? 'Daily digest is on. We email you once a day.' : 'Daily digest is off.');
    }
    redirect('account/digest.php');
}
$on = 0;
if ($ready) { $q = db()->prepare('SELECT email_daily FROM digest_prefs WHERE user_id = ?'); $q->execute([$uid]); $on = (int)$q->fetchColumn(); }
[$subj, $txt] = digest_build($user);
$wa = 'https://wa.me/?text=' . rawurlencode($subj . "\n\n" . preg_replace("/\n\nTurn this email off.*$/s", '', $txt));
$page_title = 'Daily digest - ' . SITE_NAME;
$active = ''; $acct = 'digest';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Daily digest</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <p style="color:var(--mute);max-width:720px">One short message a day with today's tips, new posts in the groups you joined and the jackpot. You choose whether you get it.</p>
  <?php if (!$ready): ?><div class="note">The digest is not available yet.</div><?php else: ?>
  <div class="two">
    <div class="box2"><h3>Email digest</h3>
      <form method="post"><?= csrf_field() ?>
        <label style="display:flex;gap:10px;align-items:center;margin:10px 0"><input type="checkbox" name="email_daily" value="1"<?= $on ? ' checked' : '' ?>> Email me the digest once a day (<?= e($user['email']) ?>)</label>
        <button class="btn dark" type="submit">Save</button>
      </form></div>
    <div class="box2"><h3>WhatsApp</h3>
      <p>We do not send WhatsApp messages by ourselves. Tap the button to send today's digest to yourself or a friend on WhatsApp.</p>
      <a class="btn" href="<?= e($wa) ?>" target="_blank" rel="noopener">Send today's digest on WhatsApp</a></div>
  </div>
  <div class="box2"><h3>What today's digest looks like</h3><pre style="white-space:pre-wrap;font:inherit"><?= e($txt) ?></pre></div>
  <?php endif; ?>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
