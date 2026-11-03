<?php
/**
 * Follow links box. Include where wanted:  include __DIR__ . '/includes/social-box.php';
 * Everyone sees the free Telegram channel and WhatsApp links; logged-in VIP members also see the VIP Telegram invite.
 */
require_once __DIR__ . '/social.php';
$sb_free = telegram_channel_url(TELEGRAM_FREE_CHANNEL);
$sb_wa   = whatsapp_follow_url();
$sb_vip  = (is_logged_in() && is_vip()) ? social_https(TELEGRAM_VIP_INVITE_LINK) : '';
if ($sb_free !== '' || $sb_wa !== '' || $sb_vip !== ''):
?>
<div class="social-box">
  <h3>Follow us</h3>
  <ul>
    <?php if ($sb_vip !== ''): ?><li><a class="sb-vip" href="<?= e($sb_vip) ?>" target="_blank" rel="noopener noreferrer">Join the VIP Telegram channel</a></li><?php endif; ?>
    <?php if ($sb_free !== ''): ?><li><a href="<?= e($sb_free) ?>" target="_blank" rel="noopener noreferrer">Free tips on Telegram</a></li><?php endif; ?>
    <?php if ($sb_wa !== ''): ?><li><a href="<?= e($sb_wa) ?>" target="_blank" rel="noopener noreferrer">Follow on WhatsApp</a></li><?php endif; ?>
  </ul>
</div>
<?php endif; ?>
