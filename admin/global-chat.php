<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();
$back = 'admin/global-chat.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $a = (string)($_POST['action'] ?? '');
    if ($a === 'delete') {
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [(int)($_POST['id'] ?? 0)]))));
        $n = 0;
        foreach ($ids as $mid) {
            $q = $pdo->prepare('SELECT user_id, body FROM global_chat WHERE id = ?'); $q->execute([$mid]); $old = $q->fetch();
            if (!$old) { continue; }
            $pdo->prepare('DELETE FROM global_chat WHERE id = ?')->execute([$mid]);
            try { $pdo->prepare('DELETE FROM chat_reports WHERE msg_id = ?')->execute([$mid]); } catch (Throwable $e) { }
            admin_log('chat.delete', 'chat#' . $mid, 'user#' . (int)$old['user_id'] . ': ' . mb_substr((string)$old['body'], 0, 120));
            $n++;
        }
        set_flash('success', $n . ' message(s) deleted.');
    } elseif ($a === 'delete_user' && is_admin()) {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid) {
            $n = (int)$pdo->prepare('DELETE FROM global_chat WHERE user_id = ?')->execute([$uid]);
            admin_log('chat.delete_user', 'user#' . $uid, 'all chat messages');
            set_flash('success', 'All of that member\'s chat messages were deleted.');
        }
    }
    redirect($back . (!empty($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : ''));
}
$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT g.id, g.user_id, g.body, g.attach_kind, g.file_name, g.room, g.created_at, u.name, u.role FROM global_chat g JOIN users u ON u.id = g.user_id';
$args = [];
if ($q !== '') { $sql .= ' WHERE g.body LIKE ? OR u.name LIKE ?'; $args = ['%' . $q . '%', '%' . $q . '%']; }
$st = $pdo->prepare($sql . ' ORDER BY g.id DESC LIMIT 200'); $st->execute($args); $rows = $st->fetchAll();
$admin_title = 'Chat messages';
$admin_active = 'chatmsgs';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Chat messages</h1>
<p class="sub">The latest 200 messages from the global chat and group chats. Delete any message, at any time. Deletions are logged.</p>
<form class="toolbar" method="get" action="<?= e(url($back)) ?>"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search message or name"><button class="btn dark small" type="submit">Search</button></form>
<form method="post" action="<?= e(url($back)) ?>" class="panel" onsubmit="return confirm('Delete the selected messages?');"><?= csrf_field() ?>
  <input type="hidden" name="action" value="delete"><input type="hidden" name="q" value="<?= e($q) ?>">
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.cm').forEach(function(c){c.checked=this.checked}.bind(this))" aria-label="Select all"></th><th>When</th><th>Who</th><th>Room</th><th>Message</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><input class="cm" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
        <td><?= e(date('j M, H:i', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['name']) ?><?= $r['role'] !== 'user' ? ' <span class="vipflag">Team</span>' : '' ?><br><small>#<?= (int)$r['user_id'] ?></small></td>
        <td><?= (int)$r['room'] === 0 ? 'Global' : 'Group ' . (int)$r['room'] ?></td>
        <td><?= e($r['body']) ?><?= $r['attach_kind'] ? ' <em>[' . ($r['attach_kind'] === 'i' ? 'photo' : 'file: ' . e($r['file_name'])) . ']</em>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p style="margin-top:12px"><button class="btn loss" type="submit">Delete selected</button></p>
  <?php else: ?><div class="empty">No messages found.</div><?php endif; ?>
</form>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
