<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('messages');
$pdo = db();
tickets_ready();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && $id) {
    $act = $_POST['action'] ?? '';
    if ($act === 'reply') { ticket_reply($id, true, (string)($_POST['body'] ?? '')); admin_log('ticket.reply', 'ticket#' . $id, ''); }
    if ($act === 'close') {
        $pdo->prepare("UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([$id]);
        $q = $pdo->prepare('SELECT user_id, subject FROM support_tickets WHERE id = ?'); $q->execute([$id]); if ($t = $q->fetch()) { notify_user((int)$t['user_id'], 'Ticket closed - rate our help', $t['subject'], 'account/tickets.php?id=' . $id); }
    }
    redirect('admin/tickets.php?id=' . $id);
}
$rows = $pdo->query("SELECT t.*, u.email FROM support_tickets t JOIN users u ON u.id = t.user_id ORDER BY t.status = 'closed', t.updated_at DESC LIMIT 100")->fetchAll();
$tk = null; $msgs = [];
if ($id) { $q = $pdo->prepare('SELECT t.*, u.email FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?'); $q->execute([$id]); $tk = $q->fetch() ?: null; if ($tk) { $m = $pdo->prepare('SELECT * FROM support_msgs WHERE ticket_id = ? ORDER BY id'); $m->execute([$id]); $msgs = $m->fetchAll(); } }
$avg = $pdo->query('SELECT ROUND(AVG(rating),2) a, COUNT(rating) n FROM support_tickets WHERE rating IS NOT NULL')->fetch();
$admin_title = 'Support tickets'; $admin_active = 'tickets';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><p>Satisfaction: <b><?= $avg['n'] ? e($avg['a']) . ' / 5' : '-' ?></b> from <?= (int)$avg['n'] ?> ratings.</p>
<?php if ($tk): ?><h2>#<?= (int)$tk['id'] ?> <?= e($tk['subject']) ?> <small>(<?= e($tk['email']) ?>, <?= e($tk['status']) ?>)</small></h2>
  <?php foreach ($msgs as $m): ?><div class="note-item"><strong><?= $m['from_staff'] ? 'Staff' : 'Member' ?></strong> <small><?= e($m['created_at']) ?></small><br><?= nl2br(e($m['body'])) ?></div><?php endforeach; ?>
  <?php if ($tk['rating'] !== null): ?><p>Rating: <?= (int)$tk['rating'] ?>/5 <?= e($tk['rating_note']) ?></p><?php endif; ?>
  <?php if ($tk['status'] !== 'closed'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reply"><div class="field"><textarea name="body" maxlength="3000" required></textarea></div><button class="btn gold tiny">Reply</button></form>
  <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="close"><button class="btn dark tiny">Close ticket</button></form><?php endif; ?>
<?php endif; ?></div>
<div class="panel"><table class="tbl"><tr><th>#</th><th>Member</th><th>Subject</th><th>Status</th><th>Rating</th></tr>
<?php foreach ($rows as $r): ?><tr><td><a href="<?= e(url('admin/tickets.php?id=' . (int)$r['id'])) ?>"><?= (int)$r['id'] ?></a></td><td><?= e($r['email']) ?></td><td><?= e($r['subject']) ?></td><td><?= e($r['status']) ?></td><td><?= $r['rating'] !== null ? (int)$r['rating'] : '' ?></td></tr><?php endforeach; ?></table></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
