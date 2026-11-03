<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'maint') {
        set_setting('maintenance_on', !empty($_POST['on']) ? '1' : '0');
        set_setting('maintenance_msg', trim(mb_substr((string)($_POST['msg'] ?? ''), 0, 300)));
        admin_log('maintenance', 'site', !empty($_POST['on']) ? 'on' : 'off');
        set_flash('success', !empty($_POST['on']) ? 'Maintenance mode is ON. Only staff can see the site.' : 'Maintenance mode is off.');
    } elseif ($act === 'new') {
        $t = trim(mb_substr((string)($_POST['text'] ?? ''), 0, 800));
        set_setting('whats_new_text', $t);
        set_setting('whats_new_id', $t === '' ? '' : (string)time());
        admin_log('whatsnew', 'site', mb_substr($t, 0, 80));
        set_flash('success', $t === '' ? 'Popup removed.' : 'Popup is live: every member sees it once (web and app).');
    }
    redirect('admin/ops.php');
}
$admin_title = 'Maintenance and what\'s new'; $admin_active = 'ops';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><h2>Maintenance mode</h2>
  <p style="color:var(--mute)">Visitors see a "back soon" page and the app shows your message. Staff and the admin area keep working. Payment callbacks and cron are not blocked.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="maint">
    <label><input type="checkbox" name="on" value="1"<?= setting('maintenance_on', '0') === '1' ? ' checked' : '' ?>> Maintenance mode ON</label>
    <div class="field"><label for="msg">Message</label><input type="text" id="msg" name="msg" maxlength="300" value="<?= e(setting('maintenance_msg', 'We are making the site better. Back in a few minutes.')) ?>"></div>
    <button class="btn gold" type="submit">Save</button></form></div>
<div class="panel"><h2>What's new popup</h2>
  <p style="color:var(--mute)">Shown once to each member on the site and in the app after you save. Leave empty to remove it.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="new">
    <div class="field"><textarea name="text" rows="5" maxlength="800"><?= e(setting('whats_new_text', '')) ?></textarea></div>
    <button class="btn gold" type="submit">Publish</button></form></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
