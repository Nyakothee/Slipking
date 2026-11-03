<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/chat-box.php';
$english_only = true;
$u = current_user(); $uid = $u ? (int)$u['id'] : 0;
$ready = gchat_ready();
presence_touch($u);
$gcs = gchat_stats($u);
$hubRows = cmty_hub($uid);
$myGroups = array_values(array_filter($hubRows, function ($g) { return !empty($g['joined']); }));
$page_title = 'Global Chat - ' . SITE_NAME;
$page_desc = 'Chat live with other football fans about matches, tips and anything football.';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/discuss-hub.css')) . '?v=5">' . "\n" . '<link rel="stylesheet" href="' . e(url('assets/css/global-chat.css')) . '?v=8">' . "\n";
$active = 'discuss'; $dhActive = 'chat';
include __DIR__ . '/includes/header.php';
?>
<div class="dh-page"><div class="dh"><div class="dh-layout">
<?php include __DIR__ . '/includes/dh-side.php'; ?>
  <main class="dh-main">
  <?php if (!$ready): ?><div class="note">Global Chat is not available yet. Import database-update-23.sql.</div><?php else: ?>
    <?= chat_box_html(0, $u, 'Global Chat', (int)$gcs['today'] . ' message' . ((int)$gcs['today'] === 1 ? '' : 's') . ' today') ?>
  <?php endif; ?>
  </main>
</div></div></div>
<?php if ($ready): ?><script src="<?= e(url('assets/js/globalchat.js')) ?>?v=3"></script><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
