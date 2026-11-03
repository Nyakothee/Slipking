<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    set_flash('error', 'Your session expired. Please try again.');
    redirect('admin/tips.php');
}
$back   = safe_next(post_str('back')) ?: 'admin/tips.php';
$id     = (int)($_POST['id'] ?? 0);
$result = $_POST['result'] ?? '';
if (!in_array($result, ['won', 'lost', 'void', 'push'], true)) {
    set_flash('error', 'Choose won, lost, void or push.');
    redirect($back);
}

$st = db()->prepare('SELECT t.id, t.status, t.pick, m.kickoff_at, m.home_team, m.away_team
                     FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ? AND t.deleted_at IS NULL');
$st->execute([$id]);
$t = $st->fetch();
if (!$t) {
    set_flash('error', 'Tip not found.');
} elseif (strtotime($t['kickoff_at']) > time()) {
    set_flash('error', 'That match has not started yet, so it cannot be settled.');
} elseif ($t['status'] === ($result === 'push' ? 'void' : $result) && $result !== 'push' && $result !== 'void') {
    set_flash('info', 'That tip is already marked ' . $result . '.');
} else {
    $dbStatus = $result === 'push' ? 'void' : $result;
    if (tip_void_kind_ready()) {
        db()->prepare('UPDATE tips SET status = ?, void_kind = ?, settled_at = NOW() WHERE id = ?')->execute([$dbStatus, $result === 'push' ? 'push' : null, $id]);
    } else {
        db()->prepare('UPDATE tips SET status = ?, settled_at = NOW() WHERE id = ?')->execute([$dbStatus, $id]);
    }
    $result = $dbStatus;
    audit_tip($id, 'settle');
    try { alerts_tip_settled($id, $result); } catch (Throwable $e) { error_log('alerts_tip_settled: ' . $e->getMessage()); }
    admin_log('tip.settle', 'tip#' . $id, $t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['status'] . ' -> ' . $result);
    set_flash('success', $t['home_team'] . ' vs ' . $t['away_team'] . ' marked ' . $result . '.');
}
redirect($back);
