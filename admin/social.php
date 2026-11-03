<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('social');
require_once dirname(__DIR__) . '/includes/social.php';
$errors = [];
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (post_str('action') === 'save') {
        set_setting('telegram_autopost', !empty($_POST['telegram_autopost']) ? '1' : '0');
        admin_log('social.save');
        set_flash('success', 'Settings saved.');
        redirect('admin/social.php');
    } elseif (post_str('action') === 'test') {
        if (!tg_ready()) {
            $errors[] = 'Telegram is not set up yet (see includes/config-social.php).';
        } else {
            $msg = 'Test message from ' . SITE_NAME . ' at ' . date('Y-m-d H:i') . '. If you can read this, posting works.';
            foreach (['Free channel' => TELEGRAM_FREE_CHANNEL, 'VIP channel' => TELEGRAM_VIP_CHANNEL] as $label => $chat) {
                if ($chat === '') {
                    $results[] = $label . ': not set';
                } elseif (tg_send($chat, $msg)) {
                    $results[] = $label . ': sent';
                } else {
                    $results[] = $label . ': FAILED - ' . tg_last_error();
                }
            }
            admin_log('social.test');
        }
    }
}

$logTail = '';
$lf = dirname(__DIR__) . '/logs/social.log';
if (is_file($lf)) {
    $lines = @file($lf, FILE_IGNORE_NEW_LINES);
    $logTail = $lines ? implode("\n", array_slice($lines, -15)) : '';
}
$yes = function ($v) { return $v !== '' ? 'set' : 'not set'; };

$admin_title = 'Social and Telegram';
$admin_active = 'social';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Social and Telegram</h1>
<p class="sub">Keys live in <code>includes/config-social.php</code> (never in the database). The bot must be an administrator of each channel.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($results): ?><div class="panel"><h2>Test result</h2><ul style="margin-left:20px"><?php foreach ($results as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="panel"><h2>Status</h2>
  <table class="kv">
    <tr><td>Telegram bot token</td><td><?= e($yes(TELEGRAM_BOT_TOKEN)) ?></td></tr>
    <tr><td>Free channel</td><td><?= e(TELEGRAM_FREE_CHANNEL !== '' ? TELEGRAM_FREE_CHANNEL : 'not set') ?></td></tr>
    <tr><td>VIP channel</td><td><?= e(TELEGRAM_VIP_CHANNEL !== '' ? TELEGRAM_VIP_CHANNEL : 'not set') ?></td></tr>
    <tr><td>VIP invite link (shown to VIP members)</td><td><?= e($yes(social_https(TELEGRAM_VIP_INVITE_LINK))) ?></td></tr>
    <tr><td>WhatsApp channel link</td><td><?= e($yes(social_https(WHATSAPP_CHANNEL_LINK))) ?></td></tr>
    <tr><td>WhatsApp number</td><td><?= e($yes(preg_replace('/\D+/', '', WHATSAPP_NUMBER))) ?></td></tr>
    <tr><td>Posting ready</td><td><?= tg_ready() ? 'yes' : 'no' ?></td></tr>
  </table>
  <?php if (!function_exists('curl_init')): ?><p class="hint">The PHP curl extension is missing, so Telegram posting cannot work on this server.</p><?php endif; ?>
</div>

<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="panel"><h2>Auto-post new tips</h2>
  <div class="field check"><label><input type="checkbox" name="telegram_autopost" value="1"<?= setting('telegram_autopost', '0') === '1' ? ' checked' : '' ?>> Post every new tip to Telegram when I publish it</label>
    <div class="hint">Free tips go to the free channel with the full pick. VIP tips go to the VIP channel with the full pick; the free channel only gets a teaser without the pick or odds.</div></div>
  <button class="btn gold" type="submit">Save</button>
</div>
</form>

<div class="panel"><h2>Send a test message</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn dark" type="submit"<?= tg_ready() ? '' : ' disabled' ?>>Send test message</button></form>
</div>

<div class="panel"><h2>Your links</h2>
  <ul style="margin-left:20px">
  <?php
  $ls = ['Free Telegram channel' => telegram_channel_url(TELEGRAM_FREE_CHANNEL), 'VIP invite link' => social_https(TELEGRAM_VIP_INVITE_LINK), 'WhatsApp' => whatsapp_follow_url()];
  $any = false;
  foreach ($ls as $n => $l) { if ($l !== '') { $any = true; echo '<li>' . e($n) . ': <a href="' . e($l) . '" target="_blank" rel="noopener noreferrer">' . e($l) . '</a></li>'; } }
  if (!$any) echo '<li>No links yet.</li>';
  ?>
  </ul>
</div>
<?php if ($logTail !== ''): ?><div class="panel"><h2>Recent log (logs/social.log)</h2><pre style="white-space:pre-wrap;font-size:13px"><?= e($logTail) ?></pre></div><?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
