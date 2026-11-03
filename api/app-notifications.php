<?php
/**
 * Used by the Android app (background check every ~15 minutes) to show phone notifications.
 * GET ?since=<last id seen>. Needs the member's login cookie. Returns JSON {ok, latest, items:[{id,title,message}]}.
 * since=0 only returns the newest id (so a fresh install never floods the phone with old messages).
 */
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u = current_user();
if (!$u) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'login']); exit; }
$uid = (int)$u['id']; $since = max(0, (int)($_GET['since'] ?? 0));
try {
    $pdo = db();
    $st = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ? OR user_id IS NULL'); $st->execute([$uid]);
    $latest = (int)$st->fetchColumn(); $items = [];
    if ($since > 0 && $latest > $since) {
        $q = $pdo->prepare('SELECT id, title, message FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND id > ? ORDER BY id ASC LIMIT 15');
        $q->execute([$uid, $since]);
        foreach ($q->fetchAll() as $r) { $items[] = ['id' => (int)$r['id'], 'title' => mb_substr((string)$r['title'], 0, 100), 'message' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)$r['message'])), 0, 220)]; }
    }
    echo json_encode(['ok' => true, 'latest' => $latest, 'items' => $items]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false]); }
