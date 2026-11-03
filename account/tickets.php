<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user(); $uid = (int)$user['id'];
$ok = tickets_ready();
$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'new') {
        $s = trim((string)($_POST['subject'] ?? '')); $b = trim((string)($_POST['body'] ?? ''));
        if (mb_strlen($s) < 3 || mb_strlen($b) < 10) { set_flash('error', 'Add a subject and describe the problem.'); redirect('account/tickets.php'); }
        $id = ticket_open($uid, $s, $b);
        try { send_mail(SITE_EMAIL, '[Ticket #' . $id . '] ' . $s, "Member #$uid ({$user['email']})\n\n$b\n\n" . url('admin/tickets.php?id=' . $id)); } catch (Throwable $e) { }
        set_flash('success', 'Ticket #' . $id . ' opened. You will see our reply here and in your messages.');
        redirect('account/tickets.php?id=' . $id);
    }
    $t = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?'); $t->execute([$id, $uid]); $tk = $t->fetch();
    if ($tk && $act === 'reply' && $tk['status'] !== 'closed') { ticket_reply($id, false, (string)($_POST['body'] ?? '')); }
    if ($tk && $act === 'rate' && $tk['status'] === 'closed' && $tk['rating'] === null) {
        $pdo->prepare('UPDATE support_tickets SET rating = ?, rating_note = ? WHERE id = ?')->execute([max(1, min(5, (int)($_POST['rating'] ?? 5))), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300), $id]);
        set_flash('success', 'Thanks for your feedback.');
    }
    redirect('account/tickets.php?id=' . $id);
}
$tickets = []; $tk = null; $msgs = [];
if ($ok) {
    $q = $pdo->prepare('SELECT * FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 50'); $q->execute([$uid]); $tickets = $q->fetchAll();
    if ($id) { $q = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? AND user_id = ?'); $q->execute([$id, $uid]); $tk = $q->fetch() ?: null;
        if ($tk) { $m = $pdo->prepare('SELECT * FROM support_msgs WHERE ticket_id = ? ORDER BY id'); $m->execute([$id]); $msgs = $m->fetchAll(); } }
}
$page_title = 'My support tickets - ' . SITE_NAME;
$active = ''; $acct = 'tickets';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Support tickets</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <div class="two">
  <div>
  <?php if ($tk): ?>
    <div class="box2"><h3>#<?= (int)$tk['id'] ?> <?= e($tk['subject']) ?></h3>
      <p><span class="msg-tag"><?= e(ticket_status_label($tk['status'])) ?></span></p>
      <?php foreach ($msgs as $m): ?><div class="note-item" style="<?= $m['from_staff'] ? 'border-left:3px solid #f5b301;padding-left:8px' : '' ?>"><strong><?= $m['from_staff'] ? 'Support' : 'You' ?></strong> <small><?= e(date('j M H:i', strtotime($m['created_at']))) ?></small><br><?= nl2br(e($m['body'])) ?></div><?php endforeach; ?>
      <?php if ($tk['status'] !== 'closed'): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>"><div class="field"><label for="b">Your reply</label><textarea id="b" name="body" maxlength="3000" required></textarea></div><button class="btn gold" type="submit">Send</button></form>
      <?php elseif ($tk['rating'] === null): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rate"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>">
          <h4>How was our help?</h4><div class="field"><select name="rating" aria-label="Rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"><?= str_repeat('★', $i) . str_repeat('☆', 5 - $i) ?></option><?php endfor; ?></select></div>
          <div class="field"><label for="n">Comment (optional)</label><input type="text" id="n" name="note" maxlength="300"></div><button class="btn gold" type="submit">Send rating</button></form>
      <?php else: ?><p>Your rating: <?= str_repeat('★', (int)$tk['rating']) ?></p><?php endif; ?>
    </div>
  <?php else: ?>
    <form method="post" class="box2"><?= csrf_field() ?><input type="hidden" name="action" value="new"><h3>New ticket</h3>
      <div class="field"><label for="s">Subject</label><input type="text" id="s" name="subject" maxlength="120" required></div>
      <div class="field"><label for="b">What happened? (include your M-Pesa receipt for payments)</label><textarea id="b" name="body" maxlength="3000" required></textarea></div>
      <button class="btn gold" type="submit">Open ticket</button></form>
  <?php endif; ?>
  </div>
  <div class="box2"><h3>Your tickets</h3>
    <?php foreach ($tickets as $t): ?><div class="al-row"><a href="<?= e(url('account/tickets.php?id=' . (int)$t['id'])) ?>">#<?= (int)$t['id'] ?> <?= e($t['subject']) ?></a> <small><?= e(ticket_status_label($t['status'])) ?></small></div><?php endforeach; ?>
    <?php if (!$tickets): ?><p style="color:var(--mute)">No tickets yet.</p><?php else: ?><p><a href="<?= e(url('account/tickets.php')) ?>">+ New ticket</a></p><?php endif; ?>
  </div></div>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
