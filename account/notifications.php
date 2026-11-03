<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'delete') {
    db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $user['id']]);
    redirect('account/notifications.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['action'] ?? '') === 'delete') {
    db()->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), $user['id']]);
    redirect('account/notifications.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$user['id']]);
    set_flash('success', 'All messages marked as read.');
    redirect('account/notifications.php');
}

// Personal messages, plus broadcasts sent to everyone (user_id empty)
$st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 60');
$st->execute([$user['id']]);
$items  = $st->fetchAll();
$unread = count(array_filter($items, function ($n) { return $n['user_id'] !== null && !$n['is_read']; }));

$page_title = 'Messages - ' . SITE_NAME;
$active = '';
$acct   = 'notifications';
include dirname(__DIR__) . '/includes/header.php';
?>
<?php
function msg_kind($t) {
    $t = strtolower($t);
    if (preg_match('/payment|paid|vip|premium|subscri|receipt|refund/', $t)) return ['pay', '&#128179;', 'Payment'];
    if (preg_match('/tip|won|lost|slip|prediction|odds/', $t)) return ['tip', '&#9917;', 'Tips'];
    if (preg_match('/reply|replied|comment|chat|support|message from/', $t)) return ['rep', '&#128172;', 'Reply'];
    if (preg_match('/welcome|account|password|verify|login/', $t)) return ['acc', '&#128100;', 'Account'];
    return ['gen', '&#128276;', 'Update'];
}
/** Where a message leads and what the button says: [address, label] or null. Newer messages carry their own address; older ones are matched by title. */
function msg_target(array $n) {
    $p = ltrim((string)($n['path'] ?? ''), '/');
    $t = strtolower($n['title'] . ' ' . $n['message']);
    $reply = (bool)preg_match('/new message|replied|new reply|reply to|new comment|mentioned|conversation|first comment|chat|new join request/', $t);
    if ($p !== '' && !preg_match('#^(https?:)?//#i', $p) && strpos($p, '..') === false) { return [url($p), $reply ? 'Reply' : 'Open']; }
    if (preg_match('/new message/', $t)) return [url('account/messages.php'), 'Reply'];
    if (preg_match('/first comment|new comment|replied|new reply|mentioned|global chat/', $t)) return [url('discuss.php'), 'Reply'];
    if (preg_match('/new badge/', $t)) return [url('account/badges.php'), 'View'];
    if (preg_match('/2 odds streak/', $t)) return [url('index.php#streak'), 'Open'];
    if (preg_match('/weekly access/', $t)) return [url('access.php'), 'Open'];
    if (preg_match('/vip plan|vip days|free vip/', $t)) return [url('vip.php'), 'Open'];
    if (preg_match('/tip|odds moved/', $t)) return [url('tips.php'), 'Open'];
    return null;
}
function msg_ago($ts) {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' h ago';
    if ($d < 604800) return floor($d / 86400) . ' d ago';
    return date('j M Y', strtotime($ts));
}
?>
<section>
  <div class="wrap">
    <div class="head"><h2>Messages</h2>
      <?php if ($unread): ?><form method="post"><?= csrf_field() ?><button class="btn dark small" type="submit">Mark all as read</button></form><?php endif; ?></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
    <div class="inbox">
    <div class="inbox-top"><b>Inbox</b><span><?= $unread ? $unread . ' unread' : 'All caught up' ?></span></div>
    <?php if ($items): foreach ($items as $n): $k = msg_kind($n['title']); $un = $n['user_id'] !== null && !$n['is_read']; ?>
      <div class="msg<?= $un ? ' unread' : '' ?>">
        <span class="msg-ico k-<?= $k[0] ?>" aria-hidden="true"><?= $k[1] ?></span>
        <div class="msg-body">
          <div class="msg-h"><strong><?= e($n['title']) ?></strong><time title="<?= e(date('j M Y, H:i', strtotime($n['created_at']))) ?>"><?= e(msg_ago($n['created_at'])) ?></time></div>
          <p><?= nl2br(e($n['message'])) ?></p>
          <div class="msg-f"><span class="msg-tag k-<?= $k[0] ?>"><?= $k[2] ?></span><?php if ($n['user_id'] === null): ?><span class="msg-tag all">For everyone</span><?php endif; ?><?php if ($un): ?><span class="msg-new">New</span><?php endif; ?></div>
          <?php if ($n['user_id'] !== null): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="msg-del" type="submit">Delete</button></form><?php endif; ?>
          <?php if ($n['user_id'] !== null): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="msg-del" type="submit">Delete</button></form><?php endif; ?>
          <?php if ($tg = msg_target($n)): ?><a class="msg-go<?= $tg[1] === 'Reply' ? ' reply' : '' ?>" href="<?= e($tg[0]) ?>"><?= $tg[1] === 'Reply' ? '&#8617; ' : '' ?><?= e($tg[1]) ?></a><?php endif; ?>
        </div>
      </div>
    <?php endforeach; else: ?>
      <div class="msg-empty"><div>&#128236;</div><b>No messages yet</b><span>Updates about your tips, payments and replies will show up here.</span></div>
    <?php endif; ?>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
