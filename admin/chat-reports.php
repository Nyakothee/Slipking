<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$ok = gchat_ready() && cmty2_ready() && sk_ensure_table('chat_reports', 'database-update-24.sql');
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('admin/chat-reports.php'); }
    $act = (string)($_POST['action'] ?? ''); $mid = (int)($_POST['msg'] ?? 0);
    if ($act === 'delete') { db()->prepare('DELETE FROM global_chat WHERE id = ?')->execute([$mid]); db()->prepare('DELETE FROM chat_reports WHERE msg_id = ?')->execute([$mid]); admin_log('chat.delete', (string)$mid, 'reported message'); set_flash('success', 'Message deleted.'); }
    elseif ($act === 'dismiss') { db()->prepare('DELETE FROM chat_reports WHERE msg_id = ?')->execute([$mid]); set_flash('success', 'Reports dismissed.'); }
    elseif ($act === 'ban') {
        $uq = db()->prepare('SELECT user_id FROM global_chat WHERE id = ?'); $uq->execute([$mid]); $uid = (int)$uq->fetchColumn();
        if ($uid) { try { db()->prepare('INSERT IGNORE INTO comment_bans (user_id) VALUES (?)')->execute([$uid]); admin_log('chat.ban', (string)$uid, 'from reported message'); set_flash('success', 'Member switched off from commenting and chatting.'); } catch (Throwable $e) { set_flash('error', 'Could not ban this member.'); } }
    }
    redirect('admin/chat-reports.php');
}
$rows = $ok ? db()->query('SELECT r.msg_id, COUNT(*) AS n, g.body, g.attach_kind, g.room, g.created_at, u.name, u.email FROM chat_reports r JOIN global_chat g ON g.id = r.msg_id JOIN users u ON u.id = g.user_id GROUP BY r.msg_id, g.body, g.attach_kind, g.room, g.created_at, u.name, u.email ORDER BY n DESC, r.msg_id DESC LIMIT 100')->fetchAll() : [];
$admin_title = 'Chat reports'; $admin_active = 'chatreports';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Chat reports</h1>
<p class="sub">Messages members reported in Global Chat and group chats.</p>
<?php if (!$ok): ?><div class="note">Chat is not set up yet.</div><?php elseif (!$rows): ?><div class="note">No reported messages.</div><?php else: ?>
<div class="scroll"><table class="bm-table"><thead><tr><th>Message</th><th>From</th><th>Where</th><th>Reports</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><?= e($r['body'] !== '' ? $r['body'] : ($r['attach_kind'] === 'i' ? '(picture)' : '(file)')) ?><br><small><?= e($r['created_at']) ?></small></td>
  <td><?= e($r['name']) ?><br><small><?= e($r['email']) ?></small></td><td><?= (int)$r['room'] === 0 ? 'Global' : 'Group #' . (int)$r['room'] ?></td><td><?= (int)$r['n'] ?></td>
  <td><?php foreach (['delete' => 'Delete message', 'dismiss' => 'Dismiss', 'ban' => 'Ban member'] as $a => $l): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('<?= e($l) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $a ?>"><input type="hidden" name="msg" value="<?= (int)$r['msg_id'] ?>"><button class="btn tiny" type="submit"><?= e($l) ?></button></form><?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
