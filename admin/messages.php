<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('messages');
$pdo = db();
$errors = [];
$f = ['audience' => 'all', 'email' => '', 'title' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'banner' && csrf_check()) {
    $bt = trim(mb_substr((string)($_POST['banner'] ?? ''), 0, 240));
    $hrs = max(0, min(168, (int)($_POST['hours'] ?? 0)));
    set_setting('urgent_banner', $bt);
    set_setting('urgent_banner_until', $bt !== '' && $hrs ? (string)(time() + $hrs * 3600) : '0');
    admin_log('banner.set', 'site', $bt);
    set_flash('success', $bt === '' ? 'Urgent banner removed.' : 'Urgent banner is live on the site and in the app.');
    redirect('admin/messages.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f['audience'] = $_POST['audience'] ?? 'all';
    $f['email']    = strtolower(post_str('email'));
    $f['title']    = post_str('title');
    $f['message']  = trim((string)($_POST['message'] ?? ''));

    if (!csrf_check()) $errors[] = 'Your session expired. Please try again.';
    if ($f['title'] === '' || mb_strlen($f['title']) > 150) $errors[] = 'Enter a title (up to 150 characters).';
    if ($f['message'] === '' || mb_strlen($f['message']) > 2000) $errors[] = 'Enter a message (up to 2000 characters).';
    if (!in_array($f['audience'], ['all', 'vip', 'user'], true)) $errors[] = 'Choose who should get the message.';

    if (!$errors) {
        if ($f['audience'] === 'all') {
            // One shared row: every member sees it in their Messages page
            $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (NULL, ?, ?)')->execute([$f['title'], $f['message']]);
            if (function_exists('fcm_queue')) { fcm_queue(0, $f['title'], $f['message'], 'account/notifications.php'); }
            admin_log('message.broadcast', 'all', $f['title']);
            set_flash('success', 'Message sent to all members.');
            redirect('admin/messages.php');
        } elseif ($f['audience'] === 'vip') {
            $n = $pdo->prepare("INSERT INTO notifications (user_id, title, message)
                                SELECT DISTINCT user_id, ?, ? FROM subscriptions WHERE status = 'active' AND starts_at <= NOW() AND ends_at > NOW()");
            $n->execute([$f['title'], $f['message']]);
            if (function_exists('fcm_ready') && fcm_ready() && app_push_ready()) {
                try { $pdo->prepare("INSERT INTO app_push_queue (user_id, title, body, path) SELECT DISTINCT s.user_id, ?, ?, 'account/notifications.php' FROM subscriptions s JOIN app_devices d ON d.user_id = s.user_id WHERE s.status = 'active' AND s.starts_at <= NOW() AND s.ends_at > NOW()")->execute([mb_substr($f['title'], 0, 150), mb_substr($f['message'], 0, 300)]); } catch (Throwable $e) { }
            }
            admin_log('message.vip', 'vip', $f['title'] . ' (' . $n->rowCount() . ' members)');
            set_flash('success', 'Message sent to ' . $n->rowCount() . ' VIP member(s).');
            redirect('admin/messages.php');
        } else {
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$f['email']]);
            if ($uid = $st->fetchColumn()) {
                notify_user((int)$uid, $f['title'], $f['message']);
                admin_log('message.send', 'user#' . $uid, $f['title']);
                set_flash('success', 'Message sent.');
                redirect('admin/messages.php');
            }
            $errors[] = 'No member has that email address.';
        }
    }
}
$recent = $pdo->query('SELECT title, message, created_at FROM notifications WHERE user_id IS NULL ORDER BY id DESC LIMIT 10')->fetchAll();

$admin_title = 'Messages';
$admin_active = 'messages';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Messages</h1>
<p class="sub">Messages appear in each member's account under Messages, and unread ones show a notice on their dashboard.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="panel">
  <form method="post"><?= csrf_field() ?>
    <div class="field"><label for="audience">Send to</label>
      <select id="audience" name="audience">
        <option value="all"<?= $f['audience'] === 'all' ? ' selected' : '' ?>>Everyone (a notice on the site)</option>
        <option value="vip"<?= $f['audience'] === 'vip' ? ' selected' : '' ?>>Active VIP members only</option>
        <option value="user"<?= $f['audience'] === 'user' ? ' selected' : '' ?>>One member</option>
      </select></div>
    <div class="field"><label for="email">Member's email (only for "One member")</label><input type="text" id="email" name="email" value="<?= e($f['email']) ?>"></div>
    <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" value="<?= e($f['title']) ?>" maxlength="150" required></div>
    <div class="field"><label for="message">Message</label><textarea id="message" name="message" maxlength="2000" required><?= e($f['message']) ?></textarea></div>
    <button class="btn gold" type="submit">Send</button>
  </form>
</div>
<div class="panel">
  <h2>Urgent banner</h2>
  <p style="color:var(--mute)">A bar at the very top of every page (and in the app) until it expires. Leave empty and save to remove it.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="banner">
    <div class="field"><label for="banner">Banner text</label><input type="text" id="banner" name="banner" maxlength="240" value="<?= e(setting('urgent_banner', '')) ?>"></div>
    <div class="field"><label for="hours">Show for (hours, 0 = until removed)</label><input type="number" id="hours" name="hours" min="0" max="168" value="0"></div>
    <button class="btn gold" type="submit">Save banner</button></form>
</div>
<div class="panel">
  <h2>Recent notices to everyone</h2>
  <?php if ($recent): foreach ($recent as $r): ?>
    <div class="note-item"><strong><?= e($r['title']) ?></strong><br><?= nl2br(e($r['message'])) ?><br><small><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></small></div>
  <?php endforeach; else: ?><p style="color:var(--mute)">None yet.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
