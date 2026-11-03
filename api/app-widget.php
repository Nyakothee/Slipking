<?php
/**
 * Data for the Android home-screen widget (public, no login): tips today, win rate and the next matches with tips.
 * Cached for a minute. Returns JSON {ok, tips_today, win_rate, next:[{match, when}]}.
 */
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60');
try {
    $st = site_stats();
    $next = [];
    foreach (upcoming_matches(3) as $m) {
        $next[] = ['match' => mb_substr($m['home_team'] . ' vs ' . $m['away_team'], 0, 60), 'when' => kickoff_label($m['kickoff_at'])];
    }
    echo json_encode(['ok' => true, 'tips_today' => (int)$st['tips_today'], 'win_rate' => $st['win_rate'], 'next' => $next], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false]); }
