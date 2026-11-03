<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_perm('users_view');
$pdo = db();
$me  = current_user();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$id]);
$u = $st->fetch();
if (!$u) {
    set_flash('error', 'Member not found.');
    redirect('admin/users.php');
}
$plans = $pdo->query('SELECT id, name, duration_days FROM plans WHERE tipster_id IS NULL ORDER BY sort_order, id')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif ($action !== 'message' && !is_admin()) {
        set_flash('error', 'Only an admin can change a member\'s account. You can view it and send a message.');
    } elseif ($action === 'grant') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $daysIn = post_str('days');
        $days   = ($daysIn === '') ? null : (int)$daysIn;
        if ($days !== null && ($days < 1 || $days > 3650)) {
            set_flash('error', 'Days must be between 1 and 3650.');
        } elseif ($ends = grant_vip($id, $planId, $days)) {
            notify_user($id, 'VIP days added', 'We added VIP time to your account. Your plan is now active until ' . date('j F Y, H:i', strtotime($ends)) . '.');
            admin_log('vip.grant', 'user#' . $id, ($days ?: 'plan length') . ' days, plan#' . $planId . ', until ' . $ends);
            set_flash('success', 'VIP added. Active until ' . date('j M Y, H:i', strtotime($ends)) . '.');
        } else {
            set_flash('error', 'Choose a plan.');
        }
    } elseif ($action === 'ban') {
        acct_ban_set($id, post_str('reason'));
        admin_log('user.ban', 'user#' . $id, post_str('reason'));
        set_flash('success', 'Member banned. They see the reason and can appeal.');
    } elseif ($action === 'unban') {
        acct_ban_clear($id);
        admin_log('user.unban', 'user#' . $id, '');
        set_flash('success', 'Ban lifted.');
    } elseif ($action === 'revoke') {
        $n = $pdo->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ? AND status = 'active' AND ends_at > NOW()");
        $n->execute([$id]);
        if ($n->rowCount()) {
            notify_user($id, 'VIP plan ended', 'Your VIP plan has been ended by our team. Contact support if you have questions.');
            admin_log('vip.revoke', 'user#' . $id, $n->rowCount() . ' subscription(s) cancelled');
            set_flash('success', 'VIP access ended.');
        } else {
            set_flash('info', 'This member has no active VIP plan.');
        }
    } elseif ($action === 'message') {
        $title = post_str('title');
        $msg   = trim((string)($_POST['message'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150 || $msg === '' || mb_strlen($msg) > 2000) {
            set_flash('error', 'Enter a title (up to 150 characters) and a message (up to 2000).');
        } else {
            notify_user($id, $title, $msg);
            admin_log('message.send', 'user#' . $id, $title);
            set_flash('success', 'Message sent. The member sees it in their account.');
        }
    } elseif ($action === 'verify') {
        $pdo->prepare('UPDATE users SET email_verified = 1 WHERE id = ?')->execute([$id]);
        admin_log('user.verify', 'user#' . $id);
        set_flash('success', 'Email marked as confirmed.');
    }
    redirect('admin/user-view.php?id=' . $id);
}

$st = $pdo->prepare('SELECT s.*, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 20');
$st->execute([$id]);
$subs = $st->fetchAll();
$st = $pdo->prepare('SELECT py.*, p.name AS plan_name FROM payments py JOIN plans p ON p.id = py.plan_id WHERE py.user_id = ? ORDER BY py.id DESC LIMIT 20');
$st->execute([$id]);
$pays = $st->fetchAll();
$st = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
$st->execute([(int)$u['referred_by']]);
$refBy = $st->fetch();
$st = $pdo->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id = ? AND status = 'active' AND ends_at > NOW()");
$st->execute([$id]);
$vipUntil = $st->fetchColumn();

$admin_title = $u['name'];
$admin_active = 'users';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1><?= e($u['name']) ?></h1><p class="sub" style="margin:0"><?= e($u['email']) ?></p></div><a class="btn outline" href="<?= e(url('admin/users.php')) ?>">All members</a></div>

<div class="split">
  <div class="panel">
    <h2>Details</h2>
    <table class="kv">
      <tr><td>M-Pesa phone</td><td><?= e($u['phone'] ?: '-') ?></td></tr>
      <tr><td>Mobile</td><td><?= !empty($u['mobile']) ? '+' . e($u['mobile']) . ' (' . e($u['country'] ?: '?') . ') ' . ($u['mobile_verified'] ? '<span class="badge won">SMS confirmed</span>' : '<span class="badge pending">Not confirmed</span>') : '-' ?></td></tr>
      <tr><td>Joined</td><td><?= e(date('j M Y, H:i', strtotime($u['created_at']))) ?></td></tr>
      <tr><td>Email</td><td><?= $u['email_verified'] ? 'Confirmed' : 'Not confirmed' ?></td></tr>
      <tr><td>Role</td><td><?= e(role_label($u['role'])) ?></td></tr>
      <tr><td>VIP</td><td><?= $vipUntil ? 'Active until ' . e(date('j M Y, H:i', strtotime($vipUntil))) : 'Free plan' ?></td></tr>
      <tr><td>Invited by</td><td><?= $refBy ? e($refBy['name']) . ' (' . e($refBy['email']) . ')' : '-' ?></td></tr>
    </table>
    <?php if (is_admin()): ?><div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
      <?php if (!$u['email_verified']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="verify"><button class="btn outline tiny" type="submit">Mark email confirmed</button></form>
      <?php endif; ?>
      <a class="btn outline tiny" href="<?= e(url('admin/staff.php')) ?>">Staff roles</a>
    </div><?php endif; ?>
  </div>

  <?php if (is_admin()): ?><div class="panel">
    <h2>VIP access</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="grant">
      <div class="formgrid">
        <div class="field"><label for="plan_id">Plan</label><select id="plan_id" name="plan_id"><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= (int)$p['duration_days'] ?> days)</option><?php endforeach; ?></select></div>
        <div class="field"><label for="days">Days (optional)</label><input type="number" id="days" name="days" min="1" max="3650" placeholder="plan length"><div class="hint">Leave empty to use the plan's length.</div></div>
      </div>
      <button class="btn gold tiny" type="submit">Add VIP days</button>
    </form>
    <?php if ($vipUntil): ?>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('End this member\'s VIP access now?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="revoke"><button class="btn loss tiny" type="submit">End VIP now</button></form>
    <?php endif; ?>
    <p style="color:var(--mute);font-size:14px;margin-top:10px">Use this for gifts or to fix a payment that did not activate. Real payments activate by themselves.</p>
  </div><?php endif; ?>
</div>

<?php if (is_admin() && $u['role'] === 'user' && acct2_ready()): $banWhy = acct_ban($id); ?>
<div class="panel">
  <h2>Ban</h2>
  <?php if ($banWhy !== null): ?>
    <p><b>Banned.</b> Reason shown to the member: <?= e($banWhy) ?></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="unban"><button class="btn dark tiny" type="submit">Lift ban</button></form>
  <?php else: ?>
    <form method="post" onsubmit="return confirm('Ban this member?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="ban">
      <div class="field"><label for="reason">Reason (the member sees this)</label><input type="text" id="reason" name="reason" maxlength="300" required></div>
      <button class="btn dark tiny" type="submit">Ban member</button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="panel">
  <h2>Send a message</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="message">
    <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" maxlength="150" required></div>
    <div class="field"><label for="message">Message</label><textarea id="message" name="message" maxlength="2000" required></textarea></div>
    <button class="btn dark tiny" type="submit">Send</button>
  </form>
</div>

<div class="split">
  <div class="panel"><h2>Plans</h2>
    <?php if ($subs): ?><table class="tbl"><thead><tr><th>Plan</th><th>From</th><th>To</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($subs as $s): $live = $s['status'] === 'active' && strtotime($s['ends_at']) > time(); ?>
        <tr><td><?= e($s['plan_name']) ?></td><td><?= e(date('j M Y', strtotime($s['starts_at']))) ?></td><td><?= e(date('j M Y', strtotime($s['ends_at']))) ?></td>
            <td><span class="badge <?= $live ? 'won' : 'pending' ?>"><?= $live ? 'Active' : e(ucfirst($s['status'])) ?></span></td></tr>
      <?php endforeach; ?></tbody></table>
    <?php else: ?><p style="color:var(--mute)">No plans yet.</p><?php endif; ?>
  </div>
  <div class="panel"><h2>Payments</h2>
    <?php if ($pays): ?><table class="tbl"><thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($pays as $r): $cls = ['paid' => 'won', 'pending' => 'pending', 'failed' => 'lost', 'cancelled' => 'lost'][$r['status']] ?? 'pending'; ?>
        <tr><td><a href="<?= e(url('admin/payment-view.php?id=' . (int)$r['id'])) ?>" style="text-decoration:underline"><?= e(date('j M Y', strtotime($r['created_at']))) ?></a></td>
            <td><?= e($r['currency']) ?> <?= e(number_format((float)$r['amount'], 2)) ?></td><td><?= method_label($r['method']) ?></td>
            <td><span class="badge <?= $cls ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr>
      <?php endforeach; ?></tbody></table>
    <?php else: ?><p style="color:var(--mute)">No payments yet.</p><?php endif; ?>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
