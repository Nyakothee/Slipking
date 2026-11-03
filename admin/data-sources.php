<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/bookmakers.php';
require_once dirname(__DIR__) . '/includes/mpesa-b2c.php';
require_perm('settings');
$pdo = db();
$report = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!is_admin()) {
        set_flash('error', 'Only an administrator can change data sources.');
    } else {
        $a = $_POST['action'] ?? '';
        if ($a === 'save') {
            set_setting('stats_enabled', !empty($_POST['stats_enabled']) ? '1' : '0');
            $comps = array_values(array_intersect(array_keys(stats_free_competitions()), array_map('strtoupper', (array)($_POST['comps'] ?? []))));
            set_setting('stats_comps', implode(',', $comps));
            set_setting('odds_enabled', !empty($_POST['odds_enabled']) ? '1' : '0');
            $sp = array_values(array_intersect(array_keys(odds_sports_all()), (array)($_POST['sports'] ?? [])));
            set_setting('odds_sports', implode(',', $sp));
            set_setting('odds_min_credits', (string)max(0, min(500, (int)($_POST['min_credits'] ?? 30))));
            $bl = array_filter(array_map(function ($c) { return strtoupper(trim($c)); }, explode(',', (string)($_POST['blocked'] ?? ''))), function ($c) { return preg_match('/^[A-Z]{2}$/', $c); });
            set_setting('odds_blocked_countries', implode(',', array_unique($bl)));
            set_setting('lang_autodetect', !empty($_POST['lang_auto']) ? '1' : '0');
            set_setting('b2c_enabled', !empty($_POST['b2c_enabled']) ? '1' : '0');
            admin_log('datasources.save', '', '');
            set_flash('success', 'Saved.');
        } elseif ($a === 'test_stats') {
            if (!stats_ready()) { $report = 'FOOTBALL_DATA_KEY is empty in includes/config-data.php.'; }
            else { $r = fd_get('competitions/PL'); $report = 'football-data.org: HTTP ' . $r['status'] . ($r['error'] ? ' - ' . $r['error'] : '') . ($r['data'] ? "\n" . substr(json_encode(['name' => $r['data']['name'] ?? null, 'season' => $r['data']['currentSeason'] ?? null], JSON_PRETTY_PRINT), 0, 600) : ''); }
        } elseif ($a === 'test_odds') {
            if (!odds_ready()) { $report = 'ODDS_API_KEY is empty in includes/config-data.php.'; }
            else {
                $s = odds_selected_sports() ?: ['soccer_epl'];
                $r = odds_api_get($s[0]);
                if ($r['remaining'] !== null) { set_setting('odds_credits_left', (string)$r['remaining']); }
                $report = 'The Odds API (' . $s[0] . '): HTTP ' . $r['status'] . ($r['error'] ? ' - ' . $r['error'] : '') . '. Credits left: ' . ($r['remaining'] ?? 'unknown') . '. Events returned: ' . (is_array($r['data']) ? count($r['data']) : 0) . ' (this test used 1 credit).';
                if (!empty($r['data'][0])) { $report .= "\nFirst event: " . ($r['data'][0]['home_team'] ?? '?') . ' vs ' . ($r['data'][0]['away_team'] ?? '?') . ', bookmakers: ' . count($r['data'][0]['bookmakers'] ?? []); }
            }
        } elseif ($a === 'sync_comp') {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));
            if (!stats_ready() || !isset(stats_free_competitions()[$code])) { $report = 'Unknown competition or no key.'; }
            else { @set_time_limit(120); $report = stats_sync_comp($pdo, $code); }
        } elseif ($a === 'sync_odds') {
            if (!odds_ready()) { $report = 'ODDS_API_KEY is empty.'; }
            else {
                $out = [];
                foreach (odds_selected_sports() as $s) {
                    $r = odds_api_get($s);
                    if ($r['remaining'] !== null) { set_setting('odds_credits_left', (string)$r['remaining']); }
                    $out[] = $s . ': ' . ($r['status'] === 200 && $r['data'] !== null ? odds_save_payload($pdo, $s, $r['data']) . ' events' : 'HTTP ' . $r['status'] . ' ' . $r['error']);
                }
                set_setting('odds_last_run', (string)time());
                $report = implode("\n", $out) ?: 'No leagues selected.';
            }
        } elseif ($a === 'clear_cache') {
            $report = 'Removed ' . cache_clear() . ' cached files.';
        }
    }
    if ($report === '') { redirect('admin/data-sources.php'); }
}
$selComps = stats_selected_comps();
$selSports = odds_selected_sports();
$last = [];
try { $last = $pdo->query('SELECT code, name, synced_at FROM stat_competitions ORDER BY code')->fetchAll(); } catch (Throwable $e) {}
$oddsRun = (int)setting('odds_last_run', '0');
$admin_title = 'Data sources';
$admin_active = 'datasources';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Data sources</h1>
<p class="sub">Free APIs that power league tables, match pages, predictions and odds comparison. Keys go in <code>includes/config-data.php</code> (not here). Each part stays hidden until its key is added and it is switched on.</p>
<?php if ($report !== ''): ?><div class="panel"><h2>Result</h2><pre style="white-space:pre-wrap"><?= e($report) ?></pre></div><?php endif; ?>
<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h2>Football statistics (football-data.org)</h2>
  <p>Key: <strong><?= stats_ready() ? 'set' : 'NOT SET' ?></strong>. Free plan: 10 requests a minute, 12 competitions. Scores can be delayed.</p>
  <label><input type="checkbox" name="stats_enabled" value="1"<?= stats_on() ? ' checked' : '' ?>> Show statistics pages</label>
  <p style="margin-top:8px"><?php foreach (stats_free_competitions() as $c => $n): ?><label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="comps[]" value="<?= e($c) ?>"<?= in_array($c, $selComps, true) ? ' checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?></p>
  <h2 style="margin-top:18px">Odds comparison (The Odds API)</h2>
  <p>Key: <strong><?= odds_ready() ? 'set' : 'NOT SET' ?></strong>. Free plan: 500 credits a month. Each league per sync costs 1 credit. Credits left at last check: <strong><?= e(setting('odds_credits_left', '') !== '' ? setting('odds_credits_left') : 'unknown') ?></strong>. Last sync: <?= $oddsRun ? e(date('j M H:i', $oddsRun)) : 'never' ?>.</p>
  <label><input type="checkbox" name="odds_enabled" value="1"<?= setting('odds_enabled', '0') === '1' ? ' checked' : '' ?>> Show odds pages</label>
  <p style="margin-top:8px"><?php foreach (odds_sports_all() as $k => $n): ?><label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="sports[]" value="<?= e($k) ?>"<?= in_array($k, $selSports, true) ? ' checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?></p>
  <p style="color:var(--mute);font-size:14px">Budget: leagues x syncs a day x 30 must stay under 500. Example: 4 leagues x 4 a day = 480.</p>
  <p><label>Stop syncing when credits left are under <input type="number" name="min_credits" value="<?= (int)setting('odds_min_credits', '30') ?>" min="0" max="500" style="width:80px"></label></p>
  <p><label>Hide odds pages in these countries (2-letter codes, comma separated, e.g. US,GB)<br><input type="text" name="blocked" value="<?= e(setting('odds_blocked_countries', '')) ?>" style="width:320px"></label><br>
  <small style="color:var(--mute)">Needs Cloudflare (or any proxy that sends the CF-IPCountry header) or the member's profile country. Not a legal compliance tool: you remain responsible for the laws where you operate.</small></p>
  <h2 style="margin-top:18px">Language</h2>
  <label><input type="checkbox" name="lang_auto" value="1"<?= setting('lang_autodetect', '1') === '1' ? ' checked' : '' ?>> Show a new visitor French, Arabic or Kiswahili automatically from their browser language (or country if the browser gives none). Their own choice always wins.</label>
  <h2 style="margin-top:18px">M-Pesa B2C payouts</h2>
  <p>Status: <strong><?= B2C_INITIATOR !== '' && B2C_SHORTCODE !== '' ? 'configured in config-data.php' : 'not configured' ?></strong>. Certificate file: <strong><?= B2C_CERT_PATH !== '' && is_readable(B2C_CERT_PATH) ? 'found' : 'not found' ?></strong>.</p>
  <label><input type="checkbox" name="b2c_enabled" value="1"<?= setting('b2c_enabled', '0') === '1' ? ' checked' : '' ?>> Show the "Pay via M-Pesa now" button on the payouts page</label>
  <p style="margin-top:14px"><button class="btn dark" type="submit">Save</button></p>
</form>
<div class="panel"><h2>Tests and manual sync</h2>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="test_stats"><button class="btn" type="submit">Test football-data.org</button></form>
  <form method="post" style="display:inline" onsubmit="return confirm('This uses 1 odds credit. Continue?')"><?= csrf_field() ?><input type="hidden" name="action" value="test_odds"><button class="btn" type="submit">Test The Odds API (1 credit)</button></form>
  <form method="post" style="display:inline" onsubmit="return confirm('Fetches every selected league and uses 1 credit each. Continue?')"><?= csrf_field() ?><input type="hidden" name="action" value="sync_odds"><button class="btn" type="submit">Sync odds now</button></form>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="clear_cache"><button class="btn" type="submit">Clear cache</button></form>
  <h3 style="margin-top:16px">Sync one competition now (takes about 10 seconds)</h3>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="sync_comp"><select name="code"><?php foreach ($selComps as $c): ?><option value="<?= e($c) ?>"><?= e(stats_free_competitions()[$c]) ?></option><?php endforeach; ?></select><button class="btn dark" type="submit">Sync</button></form>
  <?php if ($last): ?><table class="tbl" style="margin-top:12px"><thead><tr><th>Competition</th><th>Last synced</th></tr></thead><tbody><?php foreach ($last as $l): ?><tr><td><?= e($l['name'] ?: $l['code']) ?></td><td><?= $l['synced_at'] ? e(date('j M H:i', strtotime($l['synced_at']))) : 'never' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<div class="panel"><h2>Cron jobs to add</h2><pre>php /home/YOURUSER/public_html/cron/sync-stats.php      every 3 hours
php /home/YOURUSER/public_html/cron/sync-odds.php       every 6 hours (4 leagues = 480 credits a month)
php /home/YOURUSER/public_html/cron/lifecycle.php       once a day</pre></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
