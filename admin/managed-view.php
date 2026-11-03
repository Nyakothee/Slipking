<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('managed');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT r.*, u.email' . (managed_cols() ? ', (SELECT display_name FROM tipsters tp WHERE tp.id = r.tipster_id) AS tipster_name' : '') . ' FROM managed_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ?');
$st->execute([$id]);
$r = $st->fetch();
if (!$r) {
    set_flash('error', 'Enquiry not found.');
    redirect('admin/managed.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'message' && managed_cols()) {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); }
    else { $err = managed_post_message($r, 'staff', (int)current_user()['id'], (string)($_POST['body'] ?? '')); set_flash($err ? 'error' : 'success', $err ?: 'Message sent.'); }
    redirect('admin/managed-view.php?id=' . $id);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new   = $_POST['status'] ?? '';
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!in_array($new, ['new', 'contacted', 'accepted', 'declined', 'closed'], true) || mb_strlen($notes) > 3000) {
        set_flash('error', 'Check the status and keep the notes under 3000 characters.');
    } else {
        $pdo->prepare('UPDATE managed_requests SET status = ?, admin_notes = ? WHERE id = ?')->execute([$new, $notes, $id]);
        if ($new !== $r['status']) {
            $msgs = ['contacted' => 'We have tried to contact you about your account management enquiry. Please check your phone or WhatsApp.',
                     'accepted'  => 'Your account management enquiry has been accepted. We will agree the details with you directly.',
                     'declined'  => 'Thank you for your enquiry. We are not able to take it forward at this time.',
                     'closed'    => 'Your account management enquiry has been closed.'];
            if (isset($msgs[$new])) notify_user((int)$r['user_id'], 'Your account management enquiry', $msgs[$new]);
        }
        admin_log('managed.update', 'req#' . $id, $r['status'] . ' -> ' . $new);
        set_flash('success', 'Saved.');
    }
    redirect('admin/managed-view.php?id=' . $id);
}

$admin_title = 'Enquiry from ' . $r['full_name'];
$admin_active = 'managed';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/profile.css?v=1')) . '">';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1><?= e($r['full_name']) ?></h1><p class="sub" style="margin:0">Enquiry received <?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></p></div>
  <a class="btn outline" href="<?= e(url('admin/managed.php')) ?>">All enquiries</a></div>
<div class="split">
  <div class="panel"><h2>Details</h2>
    <table class="kv">
      <tr><td>Member</td><td><a href="<?= e(url('admin/user-view.php?id=' . (int)$r['user_id'])) ?>" style="text-decoration:underline"><?= e($r['email']) ?></a></td></tr>
      <tr><td>Handled by</td><td><?= e(($r['tipster_name'] ?? '') !== '' ? $r['tipster_name'] . ' (tipster)' : 'Team') ?></td></tr>
      <tr><td>Phone / WhatsApp</td><td><?= e($r['phone']) ?></td></tr>
      <tr><td>Country</td><td><?= e($r['country']) ?></td></tr>
      <tr><td>Betting company</td><td><?= e($r['bookmaker']) ?></td></tr>
      <tr><td>Amount</td><td><?= e($r['budget_currency']) ?> <?= e(number_format((float)$r['budget'], 2)) ?></td></tr>
      <tr><td>Risk comfort</td><td><?= e(ucfirst($r['risk_level'])) ?></td></tr>
    </table>
    <?php if ($r['message']): ?><p style="margin-top:12px"><strong>Their message</strong><br><?= nl2br(e($r['message'])) ?></p><?php endif; ?>
    <div class="note">They confirmed: 18+, they understand the risks, and they know never to send passwords. Do not ask for or store their betting login details. Agree the terms and fees in writing before doing anything, and get legal advice for your country.</div>
  </div>
  <div class="panel"><h2>Your follow-up</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label for="status">Status</label><select id="status" name="status">
        <?php foreach (['new', 'contacted', 'accepted', 'declined', 'closed'] as $s): ?><option value="<?= $s ?>"<?= $r['status'] === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
        <div class="hint">Changing the status sends the member a short message in their account.</div></div>
      <div class="field"><label for="admin_notes">Private notes (only you see these)</label><textarea id="admin_notes" name="admin_notes" maxlength="3000"><?= e($r['admin_notes']) ?></textarea></div>
      <button class="btn gold" type="submit">Save</button>
    </form>
  </div>
</div>
<?php if (managed_cols()): ?><div class="panel" style="margin-top:18px"><h2>Conversation</h2><?= managed_thread_html($r, 'staff', url('admin/managed-view.php'), 'admin/managed-view.php?id=' . $id) ?></div><?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
