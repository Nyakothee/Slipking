<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('users_view');
$pdo = db();
acct2_ready();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && is_admin()) {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare('SELECT user_id FROM ban_appeals WHERE id = ?');
    $st->execute([$id]);
    if ($uid = (int)$st->fetchColumn()) {
        if (($_POST['do'] ?? '') === 'accept') { acct_ban_clear($uid); $pdo->prepare("UPDATE ban_appeals SET status = 'accepted' WHERE id = ?")->execute([$id]); admin_log('appeal.accept', 'user#' . $uid, ''); }
        else { $pdo->prepare("UPDATE ban_appeals SET status = 'rejected' WHERE id = ?")->execute([$id]); admin_log('appeal.reject', 'user#' . $uid, ''); }
    }
    redirect('admin/appeals.php');
}
$rows = $pdo->query("SELECT a.*, u.email, u.name, u.ban_reason FROM ban_appeals a JOIN users u ON u.id = a.user_id ORDER BY a.status = 'open' DESC, a.id DESC LIMIT 100")->fetchAll();
$sus = acct_shared_suspects((int)setting('shared_account_devices', '4'));
$admin_title = 'Appeals and shared accounts';
$admin_active = 'appeals';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><h2>Ban appeals</h2>
<?php foreach ($rows as $r): ?>
  <div class="note-item"><strong><?= e($r['email']) ?></strong> <small>(<?= e($r['status']) ?>, <?= e(date('j M H:i', strtotime($r['created_at']))) ?>)</small><br>
    <small>Ban reason: <?= e($r['ban_reason']) ?></small><br><?= nl2br(e($r['message'])) ?>
    <?php if ($r['status'] === 'open' && is_admin()): ?><form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="btn gold tiny" name="do" value="accept">Restore account</button> <button class="btn dark tiny" name="do" value="reject">Reject</button></form><?php endif; ?></div>
<?php endforeach; if (!$rows): ?><p style="color:var(--mute)">No appeals.</p><?php endif; ?>
</div>
<div class="panel"><h2>Possible shared accounts (many devices in 24 hours)</h2>
<table class="tbl"><tr><th>Member</th><th>Devices</th><th>Networks</th><th>Last seen</th></tr>
<?php foreach ($sus as $s): ?><tr><td><a href="<?= e(url('admin/user-view.php?id=' . (int)$s['user_id'])) ?>"><?= e($s['email']) ?></a></td><td><?= (int)$s['devices'] ?></td><td><?= (int)$s['nets'] ?></td><td><?= e(date('j M H:i', strtotime($s['last_seen']))) ?></td></tr>
<?php endforeach; if (!$sus): ?><tr><td colspan="4" style="color:var(--mute)">None right now.</td></tr><?php endif; ?></table></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
