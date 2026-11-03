<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$pid]);
        admin_log('post.delete', 'post#' . $pid);
        set_flash('success', 'Post deleted.');
    } elseif ($action === 'toggle') {
        $pdo->prepare("UPDATE posts SET status = IF(status = 'published', 'draft', 'published') WHERE id = ?")->execute([$pid]);
        admin_log('post.toggle', 'post#' . $pid);
        set_flash('success', 'Post status changed.');
    }
    redirect('admin/posts.php');
}
$rows = $pdo->query('SELECT id, title, slug, status, created_at FROM posts ORDER BY created_at DESC LIMIT 200')->fetchAll();

$admin_title = 'Blog';
$admin_active = 'posts';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1>Blog</h1><p class="sub" style="margin:0">Articles that bring in search traffic.</p></div><a class="btn gold" href="<?= e(url('admin/post-edit.php')) ?>">New article</a></div>
<div class="panel">
<?php if ($rows): ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Title</th><th>Status</th><th>Date</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= e($r['title']) ?><br><small>/post.php?slug=<?= e($r['slug']) ?></small></td>
      <td><span class="badge <?= $r['status'] === 'published' ? 'won' : 'pending' ?>"><?= $r['status'] === 'published' ? 'Published' : 'Draft' ?></span></td>
      <td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
      <td class="acts">
        <a class="btn outline tiny" href="<?= e(url('admin/post-edit.php?id=' . (int)$r['id'])) ?>">Edit</a>
        <form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn outline tiny" type="submit"><?= $r['status'] === 'published' ? 'Unpublish' : 'Publish' ?></button></form>
        <form class="inl" method="post" onsubmit="return confirm('Delete this article for good?');"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn tiny loss" type="submit">Delete</button></form>
      </td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="empty">No articles yet. <a href="<?= e(url('admin/post-edit.php')) ?>" style="text-decoration:underline;font-weight:700">Write the first one</a></div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
