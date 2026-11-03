<?php
/** Follow / unfollow a tip (bell on the card): POST tip=ID, csrf. Followed tips get kickoff, goal and result alerts. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'login' => true, 'error' => 'Log in to follow tips.']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check() || !tipx_ready()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Please refresh the page and try again.']); exit; }
$tid = (int)($_POST['tip'] ?? 0);
$st = db()->prepare('SELECT 1 FROM tips WHERE id = ? AND deleted_at IS NULL'); $st->execute([$tid]);
if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Tip not found.']); exit; }
$d = db()->prepare('DELETE FROM tip_follows WHERE user_id = ? AND tip_id = ?'); $d->execute([(int)$u['id'], $tid]);
$on = false;
if ($d->rowCount() === 0) { db()->prepare('INSERT IGNORE INTO tip_follows (user_id, tip_id) VALUES (?,?)')->execute([(int)$u['id'], $tid]); $on = true; }
echo json_encode(['ok' => true, 'on' => $on]);
