<?php
/** Small JSON endpoint for the player: ?a=poll&m=ID (read), POST a=vote, POST a=report. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function w_out(array $d, int $c = 200): void { http_response_code($c); echo json_encode($d); exit; }
$a = (string)($_REQUEST['a'] ?? '');
$u = current_user(); $uid = $u ? (int)$u['id'] : 0;
if (!watch_ready()) { w_out(['ok' => false, 'error' => 'Not available yet.'], 503); }
if ($a === 'poll') { w_out(['ok' => true] + live_poll_state((int)($_GET['m'] ?? 0), $uid)); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) { w_out(['ok' => false, 'error' => 'Please refresh the page and try again.'], 400); }
if ($a === 'vote') {
    if (!$uid) { w_out(['ok' => false, 'error' => 'Log in to vote.', 'login' => true], 401); }
    $mid = (int)($_POST['m'] ?? 0); $ch = (string)($_POST['c'] ?? '');
    $s = live_poll_state($mid, $uid);
    if (!in_array($ch, ['1', 'X', '2'], true) || !$s['open']) { w_out(['ok' => false, 'error' => 'Voting is closed.'], 422); }
    db()->prepare('INSERT INTO live_poll_votes (match_id, user_id, round_no, choice) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE choice = VALUES(choice)')->execute([$mid, $uid, $s['round'], $ch]);
    w_out(['ok' => true] + live_poll_state($mid, $uid));
}
if ($a === 'report') {
    $url = stream_clean((string)base64_decode(strtr((string)($_POST['u'] ?? ''), '-_', '+/'), true));
    if ($url === '' || !hash_equals(stream_sig($url), (string)($_POST['s'] ?? ''))) { w_out(['ok' => false, 'error' => 'Unknown link.'], 422); }
    watch_report($url);
    w_out(['ok' => true, 'dead' => stream_dead($url), 'message' => 'Thank you. We will check the link.']);
}
w_out(['ok' => false, 'error' => 'Unknown action.'], 400);
