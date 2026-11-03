<?php
/**
 * Statistics sync (optional). Every 3 hours from Cron Jobs:
 *   php /home/YOURUSER/public_html/cron/sync-stats.php
 * Needs FOOTBALL_DATA_KEY in includes/config-data.php (free key from football-data.org). For each competition chosen in
 * Admin > Data sources it fetches the table and the season's matches (2 requests; the free plan allows 10 a minute, so the job waits between calls).
 * Add "force" to ignore the 90-minute pause:  php cron/sync-stats.php force
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/stats.php';

$pdo = db();
if (!stats_ready() || !stats_on()) { echo "stats sync is off (no key or switched off).\n"; exit(0); }
$force = in_array('force', $argv ?? [], true);
$total = 0;
foreach (stats_selected_comps() as $code) {
    if (!$force) {
        $st = $pdo->prepare('SELECT synced_at FROM stat_competitions WHERE code = ?');
        $st->execute([$code]);
        $last = $st->fetchColumn();
        if ($last && strtotime($last) > time() - 90 * 60) { continue; }
    }
    $r = fd_get("competitions/$code/standings");
    if ($r['status'] === 429) { stats_log("$code standings: rate limited, stopping"); echo "rate limited, will continue next run.\n"; break; }
    if ($r['status'] !== 200 || !$r['data']) {
        stats_log("$code standings failed: HTTP {$r['status']} {$r['error']}");
        echo "$code: standings failed (HTTP {$r['status']}) {$r['error']}\n";
        sleep(7);
        continue;
    }
    $total += stats_save_standings($pdo, $code, $r['data']);
    sleep(7);
    $m = fd_get("competitions/$code/matches");
    if ($m['status'] === 200 && $m['data']) {
        $total += stats_save_matches($pdo, $code, $m['data']);
    } else {
        stats_log("$code matches failed: HTTP {$m['status']} {$m['error']}");
        echo "$code: matches failed (HTTP {$m['status']}) {$m['error']}\n";
    }
    echo "$code ok\n";
    sleep(7);
}
try { $pdo->exec("DELETE FROM stat_matches WHERE kick_utc < NOW() - INTERVAL 800 DAY"); } catch (Throwable $e) {}
echo date('c') . " sync-stats: saved $total rows\n";
