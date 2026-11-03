<?php
/**
 * Public JSON feed used by assets/js/pwa.js for "new tip" alerts while the site is open.
 * GET api/latest-tips.php?since=<last tip id seen>
 * Privacy: only match names, league, kickoff and a link. NEVER the pick, odds or analysis, so VIP picks can not leak here.
 * No session, no cookies.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('X-Content-Type-Options: nosniff');

$since = max(0, (int)($_GET['since'] ?? 0));
$out = ['ok' => true, 'latest' => $since, 'tips' => []];
try {
    $st = db()->prepare("SELECT t.id, t.is_vip, m.home_team, m.away_team, m.kickoff_at, l.name AS league
                         FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id
                         WHERE t.deleted_at IS NULL AND t.tipster_id IS NULL AND t.status = 'pending' AND m.kickoff_at > NOW() AND t.id > ?
                         ORDER BY t.id DESC LIMIT 5");
    $st->execute([$since]);
    foreach ($st->fetchAll() as $r) {
        $out['tips'][] = [
            'id'      => (int)$r['id'],
            'vip'     => (int)$r['is_vip'] === 1,
            'match'   => $r['home_team'] . ' vs ' . $r['away_team'],
            'league'  => $r['league'],
            'kickoff' => kickoff_label($r['kickoff_at']),
            'url'     => url('tip.php?id=' . (int)$r['id']),
        ];
    }
    $max = (int)db()->query('SELECT COALESCE(MAX(id), 0) FROM tips WHERE deleted_at IS NULL')->fetchColumn();
    $out['latest'] = max($since, $max);
} catch (Throwable $e) {
    error_log('latest-tips: ' . $e->getMessage());
    $out = ['ok' => false, 'latest' => $since, 'tips' => []];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
