<?php
/**
 * Live scores (optional). Every 2 minutes from Cron Jobs:
 *   php /home/YOURUSER/public_html/cron/live-scores.php
 * Needs RESULTS_API_KEY (API-Football) in includes/config-results.php, matches linked to a fixture number (Admin > Match fixtures)
 * and the setting live_enabled = 1 (Admin > Live scores). It only asks the service about matches that are about to start or live,
 * in one batched request, to stay inside the plan's daily request limit (free plans are small: about 100 requests a day).
 * Without a paid plan, enter scores by hand in Admin > Live scores instead.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/results-api.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/game.php';
require_once dirname(__DIR__) . '/includes/installer.php';
require_once dirname(__DIR__) . '/includes/phone.php';
require_once dirname(__DIR__) . '/includes/webpush.php';
require_once dirname(__DIR__) . '/includes/alerts.php';
require_once dirname(__DIR__) . '/includes/tipx.php';

$pdo = db();
if (setting('live_enabled', '0') !== '1' || !results_ready()) {
    echo "live scores are off.\n";
    exit(0);
}
$ms = $pdo->query("SELECT id, api_fixture_id, live_status FROM matches
                   WHERE api_fixture_id IS NOT NULL
                     AND kickoff_at < NOW() + INTERVAL 10 MINUTE AND kickoff_at > NOW() - INTERVAL 4 HOUR
                     AND (live_status IS NULL OR live_status NOT IN ('FT','AET','PEN','PST','CANC','ABD'))
                   ORDER BY kickoff_at ASC LIMIT 20")->fetchAll();
// Lineups: for matches starting within 75 minutes that have a followed tip and no lineup alert yet
try {
    $lq = $pdo->query("SELECT m.id, m.api_fixture_id FROM matches m WHERE m.api_fixture_id IS NOT NULL AND m.kickoff_at > NOW() AND m.kickoff_at < NOW() + INTERVAL 75 MINUTE
                       AND NOT EXISTS (SELECT 1 FROM lineup_alerts a WHERE a.match_id = m.id) AND EXISTS (SELECT 1 FROM tip_follows f JOIN tips t ON t.id = f.tip_id WHERE t.match_id = m.id) LIMIT 4");
    foreach ($lq->fetchAll() as $lm) {
        $lj = results_api_get('fixtures/lineups', ['fixture' => (int)$lm['api_fixture_id']]);
        if ($lj !== null && !empty($lj['response'][0]['startXI'])) { tipx_lineup_alert((int)$lm['id']); }
    }
} catch (Throwable $lx) { results_log('lineups: ' . $lx->getMessage()); }
if (!$ms) { try { alerts_process(40); } catch (Throwable $px) { } echo "nothing live.\n"; exit(0); }
$byFx = [];
foreach ($ms as $m) { $byFx[(int)$m['api_fixture_id']] = $m; }
$j = results_api_get('fixtures', ['ids' => implode('-', array_keys($byFx))]);
if ($j === null) { results_log('live-scores: service did not answer'); echo "service did not answer.\n"; exit(0); }
$updated = 0; $events = 0; $liveIds = [];
foreach (($j['response'] ?? []) as $f) {
    $fx = (int)($f['fixture']['id'] ?? 0);
    if (!isset($byFx[$fx])) { continue; }
    $mid = (int)$byFx[$fx]['id'];
    $status = substr((string)($f['fixture']['status']['short'] ?? ''), 0, 8);
    $min = $f['fixture']['status']['elapsed'] ?? null;
    $h = $f['goals']['home'] ?? null; $a = $f['goals']['away'] ?? null;
    $pdo->prepare('UPDATE matches SET live_status = ?, live_minute = ?, home_score = ?, away_score = ?, score_updated_at = NOW() WHERE id = ?')
        ->execute([$status ?: null, $min !== null ? min(255, (int)$min) : null, $h !== null ? (int)$h : null, $a !== null ? (int)$a : null, $mid]);
    $updated++;
    if (in_array($status, ['1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE'], true)) { $liveIds[$mid] = $fx; }
    if (in_array($status, ['FT', 'AET', 'PEN'], true)) {
        $ft = $f['score']['fulltime'] ?? [];
        if (($ft['home'] ?? null) !== null && ($ft['away'] ?? null) !== null) {
            match_set_final($pdo, $mid, (int)$ft['home'], (int)$ft['away']);     // settles the free game; betting tips are settled by auto-results / by hand
            $pdo->prepare('UPDATE matches SET live_status = ? WHERE id = ?')->execute([$status, $mid]);
        }
    }
}
// Events for up to 10 live matches (one request each)
$n = 0;
foreach ($liveIds as $mid => $fx) {
    if (++$n > 10) { break; }
    $ej = results_api_get('fixtures/events', ['fixture' => $fx]);
    if ($ej === null) { continue; }
    foreach (($ej['response'] ?? []) as $ev) {
        $t = strtolower((string)($ev['type'] ?? '')); $d = (string)($ev['detail'] ?? '');
        $type = 'info';
        if ($t === 'goal') { $type = stripos($d, 'own') !== false ? 'own_goal' : (stripos($d, 'penalty') !== false ? 'penalty' : 'goal'); }
        elseif ($t === 'card') { $type = stripos($d, 'red') !== false ? 'red' : 'yellow'; }
        elseif ($t === 'subst') { $type = 'sub'; }
        elseif ($t === 'var') { $type = 'var'; }
        $min = (int)($ev['time']['elapsed'] ?? 0); $extra = $ev['time']['extra'] ?? null;
        $player = mb_substr((string)($ev['player']['name'] ?? ''), 0, 60);
        $assist = (string)($ev['assist']['name'] ?? '');
        $detail = mb_substr($type === 'sub' ? 'in: ' . $assist : ($t === 'goal' && $assist !== '' ? 'assist ' . $assist : ($d !== 'Normal Goal' ? $d : '')), 0, 120);
        $homeId = $f['teams']['home']['id'] ?? null;
        $team = 'none';
        // team side: compare with the fixture's home team id (fetched in the first batch)
        foreach (($j['response'] ?? []) as $ff) {
            if ((int)$ff['fixture']['id'] === $fx) { $team = ((int)($ev['team']['id'] ?? 0) === (int)($ff['teams']['home']['id'] ?? -1)) ? 'home' : 'away'; break; }
        }
        $key = substr(md5($min . '|' . var_export($extra, true) . '|' . $t . '|' . $d . '|' . $player . '|' . $assist), 0, 24);
        $ins = $pdo->prepare('INSERT IGNORE INTO match_events (match_id, minute, extra, type, team, player, detail, ext_key) VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([$mid, min(255, $min), $extra !== null ? (int)$extra : null, $type, $team, $player, $detail, $key]);
        $events += $ins->rowCount();
        if ($ins->rowCount() && in_array($type, ['goal', 'penalty', 'own_goal'], true)) { try { tipx_goal_alert($mid, (int)$pdo->lastInsertId(), $player, $min); } catch (Throwable $gx) { results_log('goal alert: ' . $gx->getMessage()); } }
    }
}
// Live statistics (shots, possession, corners): one request per live match, at most 6 per run
$sn = 0;
foreach ($liveIds as $mid => $fx) {
    if (++$sn > 6) { break; }
    try {
        $sj = results_api_get('fixtures/statistics', ['fixture' => $fx]);
        $homeId = 0; foreach (($j['response'] ?? []) as $ff) { if ((int)$ff['fixture']['id'] === $fx) { $homeId = (int)($ff['teams']['home']['id'] ?? 0); } }
        if ($sj !== null) { tipx_store_stats($mid, (array)($sj['response'] ?? []), $homeId); }
    } catch (Throwable $sx) { results_log('stats: ' . $sx->getMessage()); }
}
try { alerts_process(40); } catch (Throwable $px) { }
echo date('c') . " live-scores: updated $updated matches, $events new events\n";
