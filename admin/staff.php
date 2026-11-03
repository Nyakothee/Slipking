<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('staff');
$pdo = db();
$me  = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';
        $role   = (string)($_POST['role'] ?? '');
        if ($action === 'add') {
            $email = mb_strtolower(post_str('email'));
            $st = $pdo->prepare('SELECT id, name, role, email_verified FROM users WHERE email = ? LIMIT 1');
            $st->execute([$email]);
            $u = $st->fetch();
            if (!$u) {
                set_flash('error', 'No member has that email. They must create an account on the site first.');
            } elseif (!in_array($role, ['editor', 'support', 'finance', 'admin'], true)) {
                set_flash('error', 'Choose a role.');
            } elseif (!(int)$u['email_verified']) {
                set_flash('error', 'That member has not confirmed their email yet. Ask them to confirm it first.');
            } elseif ((int)$u['id'] === (int)$me['id']) {
                set_flash('error', 'You cannot change your own role.');
            } else {
                $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $u['id']]);
                admin_log('staff.set', 'user#' . $u['id'], $u['role'] . ' -> ' . $role);
                notify_user((int)$u['id'], 'You now have staff access', 'You were given the ' . role_label($role) . ' role. Sign in at ' . url('admin/login.php') . '. Turn on two-factor login under Security (2FA).');
                set_flash('success', $u['name'] . ' is now ' . role_label($role) . '.');
            }
        } elseif ($action === 'change' || $action === 'remove') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $st = $pdo->prepare('SELECT id, name, role FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            $new = $action === 'remove' ? 'user' : $role;
            if (!$u || !in_array($u['role'], STAFF_ROLES, true)) {
                set_flash('error', 'That person is not on the staff list.');
            } elseif ($uid === (int)$me['id']) {
                set_flash('error', 'You cannot change your own role. Ask another admin.');
            } elseif (!in_array($new, ['user', 'editor', 'support', 'finance', 'admin'], true)) {
                set_flash('error', 'Choose a role.');
            } else {
                $admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($u['role'] === 'admin' && $new !== 'admin' && $admins <= 1) {
                    set_flash('error', 'There must always be at least one admin.');
                } else {
                    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$new, $uid]);
                    admin_log('staff.set', 'user#' . $uid, $u['role'] . ' -> ' . $new);
                    set_flash('success', $new === 'user' ? $u['name'] . ' no longer has staff access.' : $u['name'] . ' is now ' . role_label($new) . '.');
                }
            }
        }
    }
    redirect('admin/staff.php');
}

$staff = $pdo->query("SELECT id, name, email, role, totp_enabled, created_at FROM users WHERE role <> 'user' ORDER BY FIELD(role,'admin','finance','editor','support'), name")->fetchAll();

$admin_title = 'Staff and roles';
$admin_active = 'staff';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Staff and roles</h1>
<p class="sub">Give people a limited admin login instead of your own. Everyone signs in at <code>/admin/login.php</code> with their normal account.</p>

<div class="panel">
  <h2>Roles</h2>
  <table class="tbl"><thead><tr><th>Role</th><th>Can open</th></tr></thead><tbody>
  <?php foreach (STAFF_ROLES as $r): ?><tr><td><strong><?= e(role_label($r)) ?></strong></td><td><?= e(role_summary($r)) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p style="color:var(--mute);font-size:14px;margin-top:10px">Only admins can change staff, prices, plans, settings, tipster commission or backups. Staff roles always see VIP tips. Ask every staff member to turn on two-factor login.</p>
</div>

<div class="panel">
  <h2>Add or change staff</h2>
  <form method="post" class="toolbar" style="justify-content:flex-start"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <input type="text" name="email" placeholder="Member's email address" required maxlength="150" style="min-width:260px">
    <select name="role" aria-label="Role"><?php foreach (['editor', 'support', 'finance', 'admin'] as $r): ?><option value="<?= e($r) ?>"><?= e(role_label($r)) ?></option><?php endforeach; ?></select>
    <button class="btn dark" type="submit">Save</button>
  </form>
  <p style="color:var(--mute);font-size:14px">The person must already have a member account with a confirmed email.</p>
</div>

<div class="panel">
  <h2>Current staff</h2>
  <div class="scroll"><table class="tbl"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Two-factor</th><th></th></tr></thead><tbody>
  <?php foreach ($staff as $s): $self = (int)$s['id'] === (int)$me['id']; ?>
    <tr>
      <td><?= e($s['name']) ?><?= $self ? ' <small>(you)</small>' : '' ?></td>
      <td><?= e($s['email']) ?></td>
      <td><?= e(role_label($s['role'])) ?></td>
      <td><?= !empty($s['totp_enabled']) ? '<span class="chip ok">On</span>' : '<span class="chip bad">Off</span>' ?></td>
      <td class="acts">
        <?php if (!$self): ?>
          <form class="inl" method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="change"><input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>">
            <select name="role" aria-label="New role"><?php foreach (STAFF_ROLES as $r): ?><option value="<?= e($r) ?>"<?= $r === $s['role'] ? ' selected' : '' ?>><?= e(role_label($r)) ?></option><?php endforeach; ?></select>
            <button class="btn tiny dark" type="submit">Change</button></form>
          <form class="inl" method="post" onsubmit="return confirm('Remove staff access from this person?');"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?= (int)$s['id'] ?>"><button class="btn tiny loss" type="submit">Remove access</button></form>
        <?php else: ?><small>Ask another admin to change your role.</small><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
