<?php
/**
 * Automatic results (optional). Every 30 minutes from Cron Jobs:
 *   php /home/YOURUSER/public_html/cron/auto-results.php
 * Needs RESULTS_API_KEY in includes/config-results.php, Admin > Match fixtures filled in, and the setting
 * "auto_results_enabled" = 1 (Admin > Match fixtures). Only simple markets are settled; the rest stay pending.
 * Same rules as settling by hand: only pending tips, only after kickoff, never changes a settled tip.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/results-api.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/phone.php';
require_once dirname(__DIR__) . '/includes/game.php';
require_once dirname(__DIR__) . '/includes/webpush.php';
require_once dirname(__DIR__) . '/includes/audit.php';
require_once dirname(__DIR__) . '/includes/alerts.php';
require_once dirname(__DIR__) . '/includes/tipsters.php';

$pdo = db();
$on = $pdo->query("SELECT value FROM settings WHERE name = 'auto_results_enabled'")->fetchColumn();
if ($on !== '1' || !results_ready()) {
    echo "auto-results is off.\n";
    exit(0);
}
$ms = $pdo->query("SELECT m.id, m.api_fixture_id, m.home_team, m.away_team
                   FROM matches m
                   WHERE m.api_fixture_id IS NOT NULL
                     AND m.kickoff_at < NOW() - INTERVAL 150 MINUTE AND m.kickoff_at > NOW() - INTERVAL 3 DAY
                     AND (EXISTS (SELECT 1 FROM tips t WHERE t.match_id = m.id AND t.status = 'pending' AND t.deleted_at IS NULL)
                          OR EXISTS (SELECT 1 FROM game_picks g WHERE g.match_id = m.id AND g.status = 'open'))
                   ORDER BY m.kickoff_at ASC LIMIT 25")->fetchAll();
$settled = 0; $left = 0;
foreach ($ms as $m) {
    $score = results_fixture_score((int)$m['api_fixture_id']);
    if ($score === null) { results_log('match#' . $m['id'] . ' not finished or no score yet'); continue; }
    try { match_set_final($pdo, (int)$m['id'], (int)$score['home'], (int)$score['away']); } catch (Throwable $e) { results_log('game settle failed: ' . $e->getMessage()); }
    $tips = $pdo->prepare("SELECT id, market, pick, status FROM tips WHERE match_id = ? AND status = 'pending' AND deleted_at IS NULL");
    $tips->execute([$m['id']]);
    foreach ($tips->fetchAll() as $t) {
        $r = settle_from_score($t['market'], $t['pick'], $score['home'], $score['away'], $m['home_team'], $m['away_team']);
        if ($r === null) { $left++; results_log('tip#' . $t['id'] . ' left for manual settling (' . $t['market'] . ' / ' . $t['pick'] . ')'); continue; }
        $u = $pdo->prepare("UPDATE tips SET status = ?, settled_at = NOW() WHERE id = ? AND status = 'pending'");
        $u->execute([$r, $t['id']]);
        if ($u->rowCount()) {
            $settled++;
            audit_tip((int)$t['id'], 'settle');
            try { alerts_tip_settled((int)$t['id'], $r); } catch (Throwable $e) { results_log('alert failed: ' . $e->getMessage()); }
            $pdo->prepare('INSERT INTO admin_log (admin_id, action, target, details, ip) VALUES (NULL, ?, ?, ?, ?)')
                ->execute(['tip.autosettle', 'tip#' . $t['id'], substr($m['home_team'] . ' vs ' . $m['away_team'] . ' ' . $score['home'] . '-' . $score['away'] . ': pending -> ' . $r, 0, 500), 'cron']);
            results_log('tip#' . $t['id'] . ' ' . $m['home_team'] . ' ' . $score['home'] . '-' . $score['away'] . ' ' . $m['away_team'] . ' => ' . $r);
        }
    }
}
echo date('c') . " auto-results: settled $settled, left for you $left\n";
