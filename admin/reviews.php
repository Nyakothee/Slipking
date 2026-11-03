<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('reviews');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['action'] ?? '';
        $st = $pdo->prepare('SELECT * FROM tipster_reviews WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r && in_array($act, ['hide', 'show'], true)) {
            $pdo->prepare('UPDATE tipster_reviews SET status = ? WHERE id = ?')->execute([$act === 'hide' ? 'hidden' : 'visible', $id]);
            tipster_refresh_rating((int)$r['tipster_id']);
            admin_log('review.' . $act, 'review#' . $id, 'tipster#' . $r['tipster_id']);
            set_flash('success', $act === 'hide' ? 'Review hidden and the rating recalculated.' : 'Review is visible again.');
        }
    }
    redirect('admin/reviews.php' . (!empty($_POST['f']) ? '?f=' . urlencode((string)$_POST['f']) : ''));
}

$f = ($_GET['f'] ?? '') === 'hidden' ? 'hidden' : (($_GET['f'] ?? '') === 'visible' ? 'visible' : '');
$st = $pdo->prepare('SELECT rv.*, ti.display_name, u.name AS rname, u.email FROM tipster_reviews rv JOIN tipsters ti ON ti.id = rv.tipster_id JOIN users u ON u.id = rv.user_id' . ($f ? ' WHERE rv.status = ?' : '') . ' ORDER BY rv.id DESC LIMIT 200');
$st->execute($f ? [$f] : []);
$rows = $st->fetchAll();

$admin_title = 'Tipster reviews';
$admin_active = 'reviews';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1>Tipster reviews</h1><p class="sub" style="margin:0">Only buyers can rate. Hide abusive or fake reviews; the star average updates at once.</p></div>
  <form method="get"><select name="f" aria-label="Filter"><option value="">All</option><option value="visible"<?= $f === 'visible' ? ' selected' : '' ?>>Visible</option><option value="hidden"<?= $f === 'hidden' ? ' selected' : '' ?>>Hidden</option></select> <button class="btn dark tiny" type="submit">Filter</button></form></div>
<div class="panel">
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Tipster</th><th>Reviewer</th><th>Stars</th><th>Comment</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><?= e($r['display_name']) ?></td><td><?= e($r['rname']) ?><br><small><?= e($r['email']) ?></small></td>
  <td><?= (int)$r['rating'] ?>/5</td><td style="max-width:360px"><?= e($r['comment'] ?: '-') ?></td>
  <td><span class="badge <?= $r['status'] === 'visible' ? 'won' : 'lost' ?>"><?= e(ucfirst($r['status'])) ?></span></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><input type="hidden" name="action" value="<?= $r['status'] === 'visible' ? 'hide' : 'show' ?>">
    <button class="btn tiny <?= $r['status'] === 'visible' ? 'loss' : 'win' ?>" type="submit"><?= $r['status'] === 'visible' ? 'Hide' : 'Show' ?></button></form></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">No reviews yet.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
