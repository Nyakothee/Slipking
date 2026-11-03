<?php
require_once dirname(__DIR__) . '/includes/init.php';
$t = require_tipster(false);
$user = current_user();
$pdo = db();
if (!managed_cols()) {
    set_flash('info', 'This part will be available once the site owner imports database-update-15.sql.');
    redirect('tipster/index.php');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$req = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM managed_requests WHERE id = ? AND tipster_id = ?');
    $st->execute([$id, $t['id']]);
    $req = $st->fetch() ?: null;
    if (!$req) { set_flash('error', 'Request not found.'); redirect('tipster/managed.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $req) {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif ($t['status'] !== 'active') {
        set_flash('error', 'Your tipster profile is not active.');
    } elseif ($action === 'message') {
        if (in_array($req['status'], ['closed', 'declined'], true)) { set_flash('error', 'This request is closed.'); }
        else {
            $err = managed_post_message($req, 'tipster', (int)$user['id'], (string)($_POST['body'] ?? ''));
            if (!$err && $req['status'] === 'new') { $pdo->prepare("UPDATE managed_requests SET status = 'contacted' WHERE id = ?")->execute([$id]); }
            set_flash($err ? 'error' : 'success', $err ?: 'Message sent.');
        }
    } elseif ($action === 'status') {
        $new = $_POST['status'] ?? '';
        $notes = trim((string)($_POST['admin_notes'] ?? ''));
        if (!in_array($new, ['contacted', 'accepted', 'declined', 'closed'], true) || mb_strlen($notes) > 3000) {
            set_flash('error', 'Check the status and keep the notes under 3000 characters.');
        } else {
            $pdo->prepare('UPDATE managed_requests SET status = ?, admin_notes = ? WHERE id = ?')->execute([$new, $notes, $id]);
            if ($new !== $req['status']) {
                $msgs = ['contacted' => 'The tipster has contacted you about your account-management request.', 'accepted' => 'The tipster accepted your account-management request. Agree the details with them in the conversation.',
                         'declined' => 'The tipster is not able to take your account-management request forward.', 'closed' => 'The tipster closed your account-management request.'];
                notify_user((int)$req['user_id'], 'Your account-management request', $msgs[$new] . ' ' . url('account/managed.php?id=' . $id));
            }
            set_flash('success', 'Saved.');
        }
    }
    redirect('tipster/managed.php?id=' . $id);
}

$labels = managed_statuses();
$rows = [];
if (!$req) {
    $st = $pdo->prepare("SELECT r.id, r.full_name, r.country, r.bookmaker, r.budget, r.budget_currency, r.status, r.created_at, (SELECT COUNT(*) FROM managed_messages m WHERE m.request_id = r.id) AS msgs
                         FROM managed_requests r WHERE r.tipster_id = ? ORDER BY (r.status = 'new') DESC, r.id DESC LIMIT 100");
    $st->execute([$t['id']]);
    $rows = $st->fetchAll();
}

$page_title = 'Account management - ' . SITE_NAME;
$active = '';
$tnav = 'managed';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Account management</h2><p>Requests clients sent to you.</p></div></div>
    <?php include dirname(__DIR__) . '/includes/tipster-nav.php'; ?>
    <?php if (empty($t['accepts_managed'])): ?><div class="note">You are not taking new requests. Switch it on in <a href="<?= e(url('tipster/profile.php')) ?>" style="text-decoration:underline">Profile</a> so clients can find you.</div><?php endif; ?>
    <?php if ($req): ?>
      <p><a href="<?= e(url('tipster/managed.php')) ?>">&larr; All requests</a></p>
      <div class="two">
        <div class="box2">
          <h3>Request #<?= (int)$req['id'] ?></h3>
          <table class="kv">
            <tr><td>Client</td><td><?= e($req['full_name']) ?></td></tr>
            <tr><td>Phone / WhatsApp</td><td><?= e($req['phone']) ?></td></tr>
            <tr><td>Country</td><td><?= e($req['country']) ?></td></tr>
            <tr><td>Betting company</td><td><?= e($req['bookmaker']) ?></td></tr>
            <tr><td>Amount</td><td><?= e($req['budget_currency']) ?> <?= e(number_format((float)$req['budget'], 2)) ?></td></tr>
            <tr><td>Risk comfort</td><td><?= e(ucfirst($req['risk_level'])) ?></td></tr>
            <tr><td>Sent</td><td><?= e(date('j M Y, H:i', strtotime($req['created_at']))) ?></td></tr>
          </table>
          <?php if ($req['message']): ?><p style="margin-top:10px"><strong>Their message</strong><br><?= nl2br(e($req['message'])) ?></p><?php endif; ?>
          <div class="note">The client confirmed they are 18+, understand the risks and agreed to share these details with you. Never ask for or store their betting password, PIN or codes. Check the betting company's rules and the law where you both live, and agree fees in writing.</div>
          <form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$req['id'] ?>"><input type="hidden" name="action" value="status">
            <div class="field"><label for="status">Status</label><select id="status" name="status">
              <?php foreach (['contacted', 'accepted', 'declined', 'closed'] as $s): ?><option value="<?= $s ?>"<?= $req['status'] === $s ? ' selected' : '' ?>><?= e($labels[$s]) ?></option><?php endforeach; ?></select>
              <div class="hint">Now: <strong><?= e($labels[$req['status']] ?? $req['status']) ?></strong>. Changing it sends the client a short message.</div></div>
            <div class="field"><label for="admin_notes">Private notes (only you see these)</label><textarea id="admin_notes" name="admin_notes" maxlength="3000"><?= e($req['admin_notes']) ?></textarea></div>
            <button class="btn gold" type="submit">Save</button>
          </form>
        </div>
        <div class="box2"><h3>Conversation</h3><?= managed_thread_html($req, 'tipster', url('tipster/managed.php'), 'tipster/managed.php?id=' . (int)$req['id']) ?></div>
      </div>
    <?php elseif ($rows): ?>
      <div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Client</th><th>Country</th><th>Betting company</th><th>Amount</th><th>Status</th><th>Messages</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><?= e($r['full_name']) ?></td><td><?= e($r['country']) ?></td><td><?= e($r['bookmaker']) ?></td>
            <td><?= e($r['budget_currency']) ?> <?= e(number_format((float)$r['budget'], 0)) ?></td><td><?= e($labels[$r['status']] ?? $r['status']) ?></td><td><?= (int)$r['msgs'] ?></td>
            <td><a class="btn outline tiny" href="<?= e(url('tipster/managed.php?id=' . (int)$r['id'])) ?>">Open</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php else: ?><div class="note">No requests yet. Clients can send you one from your public profile once you switch it on in Profile.</div><?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
