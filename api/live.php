<?php
/** JSON feed for the live match centre. Never includes a locked premium pick. */
define('NO_TRANSLATE', true);
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_write_close();

$ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), function ($i) { return $i > 0; }))), 0, 60);
$full = !empty($_GET['full']);      // single match page: events and tip list too
if (!$ids) { echo json_encode(['ok' => true, 'matches' => []]); exit; }

$pdo = db();
$in = implode(',', $ids);
$rows = $pdo->query("SELECT id, kickoff_at, live_status, live_minute, home_score, away_score, score_updated_at FROM matches WHERE id IN ($in)")->fetchAll();
$out = [];
foreach ($rows as $r) {
    $mid = (int)$r['id'];
    $item = ['id' => $mid, 'status' => (string)$r['live_status'], 'label' => live_status_label($r['live_status'], $r['live_minute'] !== null ? (int)$r['live_minute'] : null),
             'live' => live_is_live($r['live_status']), 'finished' => live_is_finished($r['live_status']),
             'home' => $r['home_score'] !== null ? (int)$r['home_score'] : null, 'away' => $r['away_score'] !== null ? (int)$r['away_score'] : null,
             'tips' => []];
    foreach (live_tips_public($mid) as $t) {
        $item['tips'][] = ['id' => $t['id'], 'status' => $t['status']];
    }
    if ($full) {
        $item['events'] = live_events_json(live_events($mid));
        $item['tips'] = live_tips_public($mid);
    }
    $out[] = $item;
}
echo json_encode(['ok' => true, 'now' => date('H:i:s'), 'matches' => $out], JSON_UNESCAPED_UNICODE);
