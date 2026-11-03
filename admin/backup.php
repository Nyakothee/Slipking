<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/backup.php';
require_perm('backup');
$pdo = db();

// Download: GET ?dl=<file>&t=<token>. The token ties the link to this admin session.
if (isset($_GET['dl'])) {
    $name = (string)$_GET['dl'];
    $tok  = (string)($_GET['t'] ?? '');
    $ok   = preg_match(BACKUP_NAME_RE, $name) && hash_equals(hash_hmac('sha256', 'dl|' . $name, csrf_token()), $tok);
    $path = backup_dir() . '/' . $name;
    if (!$ok || !is_file($path)) {
        http_response_code(404);
        exit('Not found.');
    }
    admin_log('backup.download', $name);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $a = $_POST['action'] ?? '';
        if ($a === 'create') {
            try {
                $name = backup_create($pdo);
                $keep = (int)(setting('backup_keep', '7'));
                backup_prune($keep);
                admin_log('backup.create', $name);
                set_flash('success', 'Backup created: ' . $name);
            } catch (Throwable $e) {
                error_log('backup failed: ' . $e->getMessage());
                set_flash('error', 'The backup failed. Check that the backups folder is writable and see logs/php-errors.log.');
            }
        } elseif ($a === 'delete') {
            $name = (string)($_POST['name'] ?? '');
            if (preg_match(BACKUP_NAME_RE, $name) && is_file(backup_dir() . '/' . $name) && @unlink(backup_dir() . '/' . $name)) {
                admin_log('backup.delete', $name);
                set_flash('success', 'Backup deleted.');
            } else {
                set_flash('error', 'Could not delete that file.');
            }
        } elseif ($a === 'keep') {
            $k = (int)($_POST['keep'] ?? 7);
            if ($k < 1 || $k > 60) {
                set_flash('error', 'Keep between 1 and 60 backups.');
            } else {
                set_setting('backup_keep', (string)$k);
                set_flash('success', 'Saved.');
            }
        }
    }
    redirect('admin/backup.php');
}

$list = backup_list();
$admin_title = 'Backups';
$admin_active = 'backup';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Backups</h1>
<p class="sub">A copy of the whole database (members, payments, tips and settings). Cron runs it daily if you add <code>cron/backup.php</code>.</p>
<div class="alert"><strong>Download a copy regularly.</strong> Backups stored on this same server are lost if the server is lost. Keep a recent file on your own computer or another service.</div>
<div class="panel">
  <div class="toolbar">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><button class="btn dark" type="submit">Create backup now</button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="keep">
      <label for="keep">Keep the newest</label> <input id="keep" name="keep" type="number" min="1" max="60" value="<?= (int)setting('backup_keep', '7') ?>" style="width:70px"> <button class="btn outline" type="submit">Save</button></form>
  </div>
  <?php if ($list): ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>File</th><th>Made</th><th>Size</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $b): ?>
    <tr>
      <td><?= e($b['name']) ?></td>
      <td><?= e(date('j M Y, H:i', $b['time'])) ?></td>
      <td><?= e(number_format($b['size'] / 1024, 1)) ?> KB</td>
      <td class="acts">
        <a class="btn tiny dark" href="<?= e(url('admin/backup.php?dl=' . rawurlencode($b['name']) . '&t=' . hash_hmac('sha256', 'dl|' . $b['name'], csrf_token()))) ?>">Download</a>
        <form class="inl" method="post" onsubmit="return confirm('Delete this backup?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn tiny loss" type="submit">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p style="color:var(--mute)">No backups yet.</p><?php endif; ?>
</div>
<p class="sub">To restore: open phpMyAdmin, choose the database, Import, and select the .sql.gz file (phpMyAdmin reads gzip files directly). This replaces the current tables.</p>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
