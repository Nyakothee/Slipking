<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$pdo = db();
if (!managed_cols()) {
    set_flash('info', 'This part will be available once the site owner imports database-update-15.sql.');
    redirect('account-management.php');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$req = null;
if ($id) {
    $st = $pdo->prepare('SELECT r.*, (SELECT display_name FROM tipsters tp WHERE tp.id = r.tipster_id) AS tipster_name, (SELECT slug FROM tipsters tp WHERE tp.id = r.tipster_id) AS tipster_slug
                         FROM managed_requests r WHERE r.id = ? AND r.user_id = ?');
    $st->execute([$id, $user['id']]);
    $req = $st->fetch() ?: null;
    if (!$req) { set_flash('error', 'Request not found.'); redirect('account/managed.php'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $req) {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'message') {
        if (in_array($req['status'], ['closed', 'declined'], true)) { set_flash('error', 'This request is closed.'); }
        else {
            $err = managed_post_message($req, 'client', (int)$user['id'], (string)($_POST['body'] ?? ''));
            set_flash($err ? 'error' : 'success', $err ?: 'Message sent.');
        }
    } elseif ($action === 'close' && !in_array($req['status'], ['closed', 'declined'], true)) {
        $pdo->prepare("UPDATE managed_requests SET status = 'closed' WHERE id = ?")->execute([$id]);
        if (!empty($req['tipster_id'])) {
            $tu = $pdo->prepare('SELECT user_id FROM tipsters WHERE id = ?'); $tu->execute([$req['tipster_id']]);
            if ($x = $tu->fetchColumn()) notify_user((int)$x, 'Account-management request closed', 'The client closed request #' . $id . '.');
        }
        set_flash('success', 'Request closed.');
    }
    redirect('account/managed.php?id=' . $id);
}

$labels = managed_statuses();
$mine = [];
if (!$req) {
    $st = $pdo->prepare('SELECT r.id, r.bookmaker, r.status, r.created_at, (SELECT display_name FROM tipsters tp WHERE tp.id = r.tipster_id) AS tipster_name,
                         (SELECT COUNT(*) FROM managed_messages m WHERE m.request_id = r.id) AS msgs
                         FROM managed_requests r WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 50');
    $st->execute([$user['id']]);
    $mine = $st->fetchAll();
}

$page_title = 'Account management - ' . SITE_NAME;
$active = '';
$acct = 'managed';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Account management</h2><p>Your requests and conversations.</p></div><a class="btn gold" href="<?= e(url('account-management.php')) ?>">New request</a></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
    <?php if ($req): ?>
      <p><a href="<?= e(url('account/managed.php')) ?>">&larr; All requests</a></p>
      <div class="two">
        <div class="box2">
          <h3>Request #<?= (int)$req['id'] ?></h3>
          <table class="kv">
            <tr><td>Handled by</td><td><?php if ($req['tipster_name']): ?><a href="<?= e(url('tipster.php?slug=' . rawurlencode($req['tipster_slug']))) ?>" style="text-decoration:underline"><?= e($req['tipster_name']) ?></a> (tipster)<?php else: ?><?= e(SITE_NAME) ?> team<?php endif; ?></td></tr>
            <tr><td>Status</td><td><strong><?= e($labels[$req['status']] ?? $req['status']) ?></strong></td></tr>
            <tr><td>Betting company</td><td><?= e($req['bookmaker']) ?></td></tr>
            <tr><td>Amount</td><td><?= e($req['budget_currency']) ?> <?= e(number_format((float)$req['budget'], 0)) ?></td></tr>
            <tr><td>Risk comfort</td><td><?= e(ucfirst($req['risk_level'])) ?></td></tr>
            <tr><td>Sent</td><td><?= e(date('j M Y, H:i', strtotime($req['created_at']))) ?></td></tr>
          </table>
          <?php if ($req['message']): ?><p style="margin-top:10px"><strong>Your message</strong><br><?= nl2br(e($req['message'])) ?></p><?php endif; ?>
          <?php if (!in_array($req['status'], ['closed', 'declined'], true)): ?>
            <form method="post" style="margin-top:14px" onsubmit="return confirm('Close this request? You can send a new one later.');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$req['id'] ?>"><input type="hidden" name="action" value="close"><button class="btn outline tiny" type="submit">Close this request</button></form>
          <?php endif; ?>
        </div>
        <div class="box2"><h3>Conversation</h3><?= managed_thread_html($req, 'client', url('account/managed.php'), 'account/managed.php?id=' . (int)$req['id']) ?></div>
      </div>
    <?php else: ?>
      <?php if ($mine): ?>
      <div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Handled by</th><th>Betting company</th><th>Status</th><th>Messages</th><th></th></tr></thead><tbody>
        <?php foreach ($mine as $r): ?>
          <tr><td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><?= e($r['tipster_name'] ?: SITE_NAME . ' team') ?></td><td><?= e($r['bookmaker']) ?></td><td><?= e($labels[$r['status']] ?? $r['status']) ?></td><td><?= (int)$r['msgs'] ?></td>
            <td><a class="btn outline tiny" href="<?= e(url('account/managed.php?id=' . (int)$r['id'])) ?>">Open</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php else: ?>
        <div class="note">You have not sent a request yet. <a href="<?= e(url('account-management.php')) ?>" style="text-decoration:underline;font-weight:700">Send one</a> to our team or to a tipster who offers this service.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
