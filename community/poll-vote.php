<?php
require_once dirname(__DIR__) . '/includes/init.php';
$u = current_user();
$mid = (int)($_POST['match_id'] ?? 0);
$back = (community_back(post_str('back')) ?: 'match.php?id=' . $mid) . '#poll';
if (!$u) { redirect('auth/login.php?next=' . rawurlencode('match.php?id=' . $mid)); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($back); }
$choice = (string)($_POST['choice'] ?? '');
$st = db()->prepare('SELECT kickoff_at FROM matches WHERE id = ?');
$st->execute([$mid]);
$k = $st->fetchColumn();
if (!$k || !in_array($choice, ['1', 'X', '2'], true)) { set_flash('error', 'Invalid vote.'); redirect($back); }
if (strtotime($k) <= time()) { set_flash('error', 'Voting is closed: the match has started.'); redirect($back); }
if (poll_ready()) {
    db()->prepare('INSERT INTO poll_votes (match_id, user_id, choice) VALUES (?,?,?) ON DUPLICATE KEY UPDATE choice = VALUES(choice), updated_at = NOW()')
        ->execute([$mid, $u['id'], $choice]);
    set_flash('success', 'Vote saved.');
}
redirect($back);
