<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('users_view');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_avatar') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!is_admin() || !profile_cols()) {
        set_flash('error', 'Only an administrator can remove a profile picture.');
    } else {
        $uid = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT avatar FROM users WHERE id = ?');
        $st->execute([$uid]);
        $old = $st->fetchColumn();
        if ($old) {
            avatar_delete((string)$old);
            db()->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$uid]);
            admin_log('user.remove_avatar', 'user#' . $uid, '');
            set_flash('success', 'Profile picture removed.');
        }
    }
    redirect('admin/users.php');
}

$perPage = 30;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim((string)($_GET['q'] ?? ''));
$flt = $_GET['f'] ?? '';

$where = 'WHERE 1=1';
$args = [];
if ($q !== '') {
    $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like);
}
if ($flt === 'vip') {
    $where .= " AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'active' AND s.starts_at <= NOW() AND s.ends_at > NOW())";
} elseif ($flt === 'admin') {
    $where .= " AND u.role <> 'user'";
} elseif ($flt === 'unverified') {
    $where .= ' AND u.email_verified = 0';
}

$st = db()->prepare("SELECT COUNT(*) FROM users u $where");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$st = db()->prepare("SELECT u.*, (SELECT MAX(s.ends_at) FROM subscriptions s WHERE s.user_id = u.id AND s.status = 'active' AND s.ends_at > NOW()) AS vip_until
                     FROM users u $where ORDER BY u.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$rows = $st->fetchAll();

$admin_title = 'Members';
$admin_active = 'users';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Members</h1>
<p class="sub"><?= $total ?> found</p>
<div class="panel">
  <div class="toolbar">
    <form method="get" action="<?= e(url('admin/users.php')) ?>">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name, email or phone">
      <select name="f">
        <option value="">Everyone</option>
        <option value="vip"<?= $flt === 'vip' ? ' selected' : '' ?>>VIP now</option>
        <option value="unverified"<?= $flt === 'unverified' ? ' selected' : '' ?>>Email not confirmed</option>
        <option value="admin"<?= $flt === 'admin' ? ' selected' : '' ?>>Staff (all roles)</option>
      </select>
      <button class="btn dark tiny" type="submit">Filter</button>
      <a class="btn outline tiny" href="<?= e(url('admin/users.php')) ?>">Clear</a>
    </form>
  </div>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Joined</th><th>VIP until</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td><?= user_chip_html($u, (string)$u['name'], 28) ?><?= $u['role'] !== 'user' ? ' <span class="vipflag">' . e(role_label($u['role'])) . '</span>' : '' ?></td>
        <td><?= e($u['email']) ?><br><small><?= $u['email_verified'] ? 'confirmed' : 'not confirmed' ?></small></td>
        <td><?= e($u['phone'] ?: '-') ?></td>
        <td><?= e(date('j M Y', strtotime($u['created_at']))) ?></td>
        <td><?= $u['vip_until'] ? e(date('j M Y', strtotime($u['vip_until']))) : '-' ?></td>
        <td class="acts"><a class="btn outline tiny" href="<?= e(url('admin/user-view.php?id=' . (int)$u['id'])) ?>">Open</a>
          <?php if (!empty($u['avatar']) && is_admin()): ?><form method="post" style="display:inline" onsubmit="return confirm('Remove this member\'s profile picture?');"><?= csrf_field() ?><input type="hidden" name="action" value="remove_avatar"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn outline tiny" type="submit">Remove picture</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?= pager($page, $pages, 'admin/users.php') ?>
  <?php else: ?><div class="empty">No members match.</div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
