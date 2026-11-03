<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');
$ready = watch_ready();
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'clear') { db()->prepare('DELETE FROM stream_reports WHERE url_hash = ?')->execute([(string)($_POST['h'] ?? '')]); admin_log('streamreport.clear', substr((string)$_POST['h'], 0, 8)); set_flash('success', 'Reports cleared. The link is shown again.'); }
    elseif ($act === 'limit') { set_setting('stream_dead_after', (string)max(1, min(50, (int)($_POST['n'] ?? 3)))); set_flash('success', 'Saved.'); }
    redirect('admin/watch-reports.php');
}
$rows = $ready ? stream_reports_list() : [];
$admin_title = 'Broken stream links'; $admin_active = 'watchrep';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Broken stream links</h1>
<p class="sub">Members can report a link that does not play. When enough different people report the same link within 6 hours, it is hidden automatically and the backup link (if you added one) is used instead.</p>
<?php if (!$ready): ?><div class="errors"><ul><li>Run <code>database-update-38.sql</code> once in phpMyAdmin.</li></ul></div><?php else: ?>
<form method="post" class="panel" style="max-width:420px"><?= csrf_field() ?><input type="hidden" name="action" value="limit"><div class="field"><label for="n">Hide a link after this many reports</label><input id="n" type="number" min="1" max="50" name="n" value="<?= (int)setting('stream_dead_after', '3') ?>"></div><button class="btn gold" type="submit">Save</button></form>
<div class="panel"><h2>Reports in the last 24 hours</h2>
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>Link</th><th>Reports</th><th>Last</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): $dead = (int)$r['n'] >= max(1, (int)setting('stream_dead_after', '3')); ?>
<tr><td style="max-width:360px;overflow:hidden;text-overflow:ellipsis"><a href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['url']) ?></a></td><td><?= (int)$r['n'] ?></td><td><?= e(date('j M H:i', strtotime($r['last_at']))) ?></td><td><span class="badge <?= $dead ? 'lost' : 'pending' ?>"><?= $dead ? 'Hidden' : 'Watching' ?></span></td>
<td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><input type="hidden" name="h" value="<?= e($r['url_hash']) ?>"><button class="btn tiny outline" type="submit">Clear reports</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="empty">No reports. Good.</div><?php endif; ?></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
