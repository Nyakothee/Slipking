<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    set_flash('error', 'Your session expired. Please try again.');
    redirect('admin/tips.php');
}
$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare('SELECT t.id, t.status, t.pick, t.market, t.odds, t.tipster_id, t.author_id, m.kickoff_at, m.home_team, m.away_team
                     FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ? AND t.deleted_at IS NULL');
$st->execute([$id]);
$t = $st->fetch();

if (!$t) {
    set_flash('error', 'Tip not found.');
} elseif (tip_is_locked($t) && !is_admin()) {
    set_flash('error', 'This tip cannot be deleted: the match has started or a result is set. Results stay on the record.');
} elseif (tip_is_locked($t)) {
    // Admin removing a started / settled tip: it is erased completely (results, record, tipster stats, audit trail).
    admin_purge_tips(db(), [$id], true);
    admin_log('tip.delete', 'tip#' . $id, $t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2) . ' [was ' . $t['status'] . ', kickoff ' . $t['kickoff_at'] . ', erased from results]');
    set_flash('success', 'Tip erased from the results and the record.');
} else {
    db()->prepare('UPDATE tips SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
    tip_change_log($id, ((int)$t['tipster_id'] ?: (int)$t['author_id']) ?: null, 'cancelled', 'admin', (tip_is_locked($t) ? 'Removed by admin after kickoff/result (' . $t['status'] . '): ' : 'Removed before kickoff: ') . $t['market'] . ' / ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2));
    admin_log('tip.delete', 'tip#' . $id, $t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2) . ' [was ' . $t['status'] . ', kickoff ' . $t['kickoff_at'] . ']');
    if (!empty($t['tipster_id']) && function_exists('tipster_refresh_rating')) { try { tipster_refresh_rating((int)$t['tipster_id']); } catch (Throwable $e) {} }
    set_flash('success', 'Tip deleted.');
}
redirect('admin/tips.php');
