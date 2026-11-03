<?php
require_once __DIR__ . '/includes/init.php';

$errors = [];
$sent   = false;
$user   = current_user();
$f = ['name' => $user['name'] ?? '', 'email' => $user['email'] ?? '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['name']    = post_str('name');
    $f['email']   = strtolower(post_str('email'));
    $f['subject'] = post_str('subject');
    $f['message'] = trim((string)($_POST['message'] ?? ''));

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        redirect('');                                   // hidden field filled in: a bot
    } elseif (too_many_attempts(5)) {
        $errors[] = 'Too many messages. Please wait 15 minutes and try again.';
    } else {
        if (mb_strlen($f['name']) < 2 || mb_strlen($f['name']) > 100) $errors[] = 'Enter your name.';
        if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address so we can reply.';
        if (mb_strlen($f['subject']) < 3 || mb_strlen($f['subject']) > 120) $errors[] = 'Enter a subject (3 to 120 characters).';
        if (mb_strlen($f['message']) < 10 || mb_strlen($f['message']) > 3000) $errors[] = 'Write your message (10 to 3000 characters).';

        if (!$errors) {
            record_attempt($f['email']);
            $body = "From: {$f['name']} <{$f['email']}>\n" . ($user ? 'Member ID: ' . $user['id'] . "\n" : '') . "\n" . $f['message'];
            if (send_mail(SITE_EMAIL, '[Contact] ' . $f['subject'], $body, $f['email'])) {
                $sent = true;
            } else {
                $errors[] = 'We could not send your message just now. Please email us directly at ' . SITE_EMAIL . '.';
            }
        }
    }
}

$page_title = 'Contact us - ' . SITE_NAME;
$page_desc  = 'Questions about a payment, your account or a tip? Send us a message.';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="fcard" style="max-width:560px">
      <h2>Contact us</h2>
      <?php if ($sent): ?>
        <p class="sub">Thank you. We have your message and will reply to <?= e($f['email']) ?>.</p>
        <a class="btn dark full" href="<?= e(url()) ?>">Back to the home page</a>
      <?php else: ?>
        <p class="sub">For payment problems, include your M-Pesa receipt code or PayPal receipt.</p>
        <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" action="<?= e(url('contact.php')) ?>">
          <?= csrf_field() ?>
          <div class="hp" aria-hidden="true"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <div class="field"><label for="name">Your name</label><input type="text" id="name" name="name" value="<?= e($f['name']) ?>" required maxlength="100"></div>
          <div class="field"><label for="email">Your email</label><input type="email" id="email" name="email" value="<?= e($f['email']) ?>" required maxlength="150"></div>
          <div class="field"><label for="subject">Subject</label><input type="text" id="subject" name="subject" value="<?= e($f['subject']) ?>" required maxlength="120"></div>
          <div class="field"><label for="message">Message</label><textarea id="message" name="message" required maxlength="3000" style="width:100%;min-height:150px;padding:11px 12px;border:1.5px solid var(--line);border-radius:6px;font:16px 'Source Sans 3',sans-serif"><?= e($f['message']) ?></textarea></div>
          <button class="btn gold full" type="submit">Send message</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
