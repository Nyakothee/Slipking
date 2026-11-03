<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/tipsters.php';
require_once dirname(__DIR__) . '/includes/match-delete.php';
require_perm('tips');

$back = (string)($_POST['back'] ?? 'admin/tips.php');
if (!preg_match('~^admin/[a-z0-9\-]+\.php(\?[A-Za-z0-9=&%_\-]*)?$~i', $back)) { $back = 'admin/tips.php'; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    set_flash('error', 'Your session expired. Please try again.');
} elseif (!is_admin()) {
    set_flash('error', 'Only an administrator can delete a game.');
} else {
    $mid = (int)($_POST['match_id'] ?? 0);
    $st = db()->prepare('SELECT home_team, away_team, kickoff_at FROM matches WHERE id = ?');
    $st->execute([$mid]);
    $m = $st->fetch();
    if (!$m) {
        set_flash('error', 'Game not found (it may already be deleted).');
    } else {
        try {
            $r = admin_delete_match(db(), $mid);
            admin_log('match.delete', 'match#' . $mid, $m['home_team'] . ' vs ' . $m['away_team'] . ' (' . $m['kickoff_at'] . '): tips ' . (int)$r['tips'] . ', game picks ' . (int)$r['picks']);
            set_flash('success', 'Game deleted: ' . $m['home_team'] . ' vs ' . $m['away_team'] . ' (' . (int)$r['tips'] . ' tips removed).');
        } catch (Throwable $e) {
            error_log('match-delete failed: ' . $e->getMessage());
            set_flash('error', 'Could not delete this game. Nothing was changed.');
        }
    }
}
redirect($back);
