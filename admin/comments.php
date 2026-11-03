<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('reviews');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $a = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if ($a === 'hide' || $a === 'show') {
            $pdo->prepare('UPDATE comments SET status = ? WHERE id = ?')->execute([$a === 'hide' ? 'hidden' : 'visible', $id]);
            $pdo->prepare('UPDATE comment_reports SET handled = 1 WHERE comment_id = ?')->execute([$id]);
            if ($a === 'show') { $pdo->prepare('UPDATE comments SET reports = 0 WHERE id = ?')->execute([$id]); }
            admin_log('comment.' . $a, 'comment#' . $id);
            set_flash('success', $a === 'hide' ? 'Comment hidden.' : 'Comment restored.');
        } elseif ($a === 'delete') {
            $pdo->prepare('DELETE FROM comment_likes WHERE comment_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM comment_reports WHERE comment_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM comments WHERE id = ? OR parent_id = ?')->execute([$id, $id]);
            admin_log('comment.delete', 'comment#' . $id);
            set_flash('success', 'Comment deleted.');
        } elseif ($a === 'dismiss') {
            $pdo->prepare('UPDATE comment_reports SET handled = 1 WHERE comment_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE comments SET reports = 0 WHERE id = ?')->execute([$id]);
            set_flash('success', 'Reports dismissed.');
        } elseif ($a === 'ban') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid > 0) {
                $pdo->prepare('INSERT IGNORE INTO comment_bans (user_id, reason) VALUES (?, ?)')->execute([$uid, mb_substr(post_str('reason'), 0, 120)]);
                $pdo->prepare("UPDATE comments SET status = 'hidden' WHERE user_id = ? AND created_at > NOW() - INTERVAL 7 DAY")->execute([$uid]);
                admin_log('comment.ban', 'user#' . $uid);
                set_flash('success', 'Member can no longer comment. Their last 7 days of comments are hidden.');
            }
        } elseif ($a === 'unban') {
            $pdo->prepare('DELETE FROM comment_bans WHERE user_id = ?')->execute([(int)($_POST['user_id'] ?? 0)]);
            admin_log('comment.unban', 'user#' . (int)($_POST['user_id'] ?? 0));
            set_flash('success', 'Commenting switched back on.');
        }
    }
    redirect('admin/comments.php' . (isset($_GET['all']) ? '?all=1' : ''));
}

$view = isset($_GET['all']) ? 'all' : 'reported';
$where = $view === 'all' ? '1=1' : "(c.reports > 0 OR c.status = 'hidden') AND EXISTS (SELECT 1 FROM comment_reports r WHERE r.comment_id = c.id AND r.handled = 0)";
$rows = $pdo->query("SELECT c.*, u.name, u.email, (SELECT 1 FROM comment_bans b WHERE b.user_id = c.user_id) AS banned
                     FROM comments c JOIN users u ON u.id = c.user_id WHERE $where ORDER BY c.created_at DESC LIMIT 100")->fetchAll();
$bans = $pdo->query('SELECT b.user_id, b.reason, b.created_at, u.name FROM comment_bans b JOIN users u ON u.id = b.user_id ORDER BY b.created_at DESC LIMIT 50')->fetchAll();

function comment_target_link(array $c): string
{
    $map = ['tip' => 'tip.php?id=', 'match' => 'match.php?id='];
    if ($c['target_type'] === 'tipster') {
        $s = db()->prepare('SELECT slug FROM tipsters WHERE id = ?'); $s->execute([$c['target_id']]); $slug = $s->fetchColumn();
        return $slug ? url('tipster.php?slug=' . rawurlencode($slug)) : '#';
    }
    return url($map[$c['target_type']] . (int)$c['target_id']);
}

$admin_title = 'Comments';
$admin_active = 'comments';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Comments</h1>
<p class="sub">Members' comments on tips, matches and tipster pages. Three reports hide a comment automatically until you review it.</p>
<div class="toolbar"><div><a class="btn <?= $view === 'reported' ? 'dark' : 'ghost' ?> tiny" href="<?= e(url('admin/comments.php')) ?>">Reported</a>
  <a class="btn <?= $view === 'all' ? 'dark' : 'ghost' ?> tiny" href="<?= e(url('admin/comments.php?all=1')) ?>">All recent</a></div></div>
<div class="panel">
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>When</th><th>Member</th><th>Comment</th><th>On</th><th>Reports</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $c): ?>
  <tr><td><?= e(date('j M, H:i', strtotime($c['created_at']))) ?></td>
    <td><?= e($c['name']) ?><br><small><?= e($c['email']) ?></small><?= $c['banned'] ? '<br><small style="color:#c0392b">commenting off</small>' : '' ?></td>
    <td style="max-width:340px"><?= nl2br(e($c['body'])) ?><br><small><?= $c['status'] === 'hidden' ? 'HIDDEN' : 'visible' ?></small></td>
    <td><a href="<?= e(comment_target_link($c)) ?>" target="_blank" rel="noopener"><?= e($c['target_type']) ?> #<?= (int)$c['target_id'] ?></a></td>
    <td><?= (int)$c['reports'] ?></td>
    <td>
      <form method="post" class="inl" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <?php if ($c['status'] === 'visible'): ?><button class="btn tiny dark" name="action" value="hide" type="submit">Hide</button>
        <?php else: ?><button class="btn tiny dark" name="action" value="show" type="submit">Restore</button><?php endif; ?>
        <?php if ($c['reports'] > 0): ?><button class="btn tiny" name="action" value="dismiss" type="submit">Dismiss reports</button><?php endif; ?>
        <button class="btn tiny" name="action" value="delete" type="submit" onclick="return confirm('Delete this comment and its replies for good?');">Delete</button></form>
      <?php if (!$c['banned']): ?><form method="post" class="inl" style="display:inline" onsubmit="return confirm('Stop this member from commenting?');"><?= csrf_field() ?><input type="hidden" name="action" value="ban"><input type="hidden" name="user_id" value="<?= (int)$c['user_id'] ?>"><input type="hidden" name="reason" value="moderator"><button class="btn tiny" type="submit">Stop commenting</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)"><?= $view === 'all' ? 'No comments yet.' : 'Nothing is waiting for review.' ?></p><?php endif; ?>
</div>
<?php if ($bans): ?><div class="panel"><h2>Members who cannot comment</h2><table class="tbl"><tbody>
<?php foreach ($bans as $b): ?><tr><td><?= e($b['name']) ?> (<?= (int)$b['user_id'] ?>)</td><td><?= e(date('j M Y', strtotime($b['created_at']))) ?></td><td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="action" value="unban"><input type="hidden" name="user_id" value="<?= (int)$b['user_id'] ?>"><button class="btn tiny" type="submit">Allow again</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
