<?php
/**
 * Odds sync (optional). 3 or 4 times a day from Cron Jobs:   php /home/YOURUSER/public_html/cron/sync-odds.php
 * Free plan = 500 credits a month and each league fetch costs 1 credit. 4 leagues x 4 runs x 30 days = 480, so keep to 4 leagues at 4 runs a day,
 * or 8 leagues at 2 runs a day. The job stops by itself when the credits left fall under "odds_min_credits" (Admin > Data sources).
 * Add "force" to skip the 3-hour pause.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/stats.php';
require_once dirname(__DIR__) . '/includes/bookmakers.php';
require_once dirname(__DIR__) . '/includes/odds.php';

$pdo = db();
if (!odds_ready() || setting('odds_enabled', '0') !== '1') { echo "odds sync is off (no key or switched off).\n"; exit(0); }
$force = in_array('force', $argv ?? [], true);
$last = (int)setting('odds_last_run', '0');
if (!$force && $last > time() - 3 * 3600 + 120) { echo "ran less than 3 hours ago, skipping.\n"; exit(0); }
$guard = max(0, (int)setting('odds_min_credits', '30'));
$left = setting('odds_credits_left', '');
if ($left !== '' && (int)$left < $guard && !$force) { echo "credits left ($left) under the guard ($guard), skipping.\n"; exit(0); }
$saved = 0;
foreach (odds_selected_sports() as $sport) {
    $r = odds_api_get($sport);
    if ($r['remaining'] !== null) { set_setting('odds_credits_left', (string)$r['remaining']); }
    if ($r['status'] === 401 || $r['status'] === 429) { stats_log("odds $sport: HTTP {$r['status']} {$r['error']}"); echo "$sport: HTTP {$r['status']} {$r['error']} - stopping\n"; break; }
    if ($r['status'] !== 200 || $r['data'] === null) { stats_log("odds $sport failed: HTTP {$r['status']} {$r['error']}"); echo "$sport: failed (HTTP {$r['status']}) {$r['error']}\n"; continue; }
    $n = odds_save_payload($pdo, $sport, $r['data']);
    $saved += $n;
    echo "$sport: $n events\n";
    if ($r['remaining'] !== null && $r['remaining'] < $guard) { echo "credits left {$r['remaining']} under guard, stopping.\n"; break; }
}
set_setting('odds_last_run', (string)time());
try {
    $pdo->exec("DELETE FROM odds_quotes WHERE event_id IN (SELECT id FROM odds_events WHERE commence_at < UTC_TIMESTAMP() - INTERVAL 3 DAY)");
    $pdo->exec("DELETE FROM odds_history WHERE event_id IN (SELECT id FROM odds_events WHERE commence_at < UTC_TIMESTAMP() - INTERVAL 3 DAY)");
    $pdo->exec("DELETE FROM odds_events WHERE commence_at < UTC_TIMESTAMP() - INTERVAL 3 DAY");
} catch (Throwable $e) {}
$ow = function_exists('odds_watch_process') ? odds_watch_process() : 0;
echo date('c') . " sync-odds: $saved events, $ow odds alerts\n";
