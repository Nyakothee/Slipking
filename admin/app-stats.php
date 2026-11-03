<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/admin-charts2.php';
require_perm('settings');
$pdo = db();
$ready = app_ready() && app_stats_ready() && traffic_ready();
$days = (int)($_GET['d'] ?? 30); if (!in_array($days, [7, 14, 30, 60, 90], true)) { $days = 30; }
$since = "CURDATE() - INTERVAL " . ($days - 1) . " DAY";
$one = function (string $sql) use ($pdo) { try { $v = $pdo->query($sql)->fetchColumn(); return (int)$v; } catch (Throwable $e) { return 0; } };
$rows = function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { return []; } };
$gold = '#f0a91c'; $green = '#155240'; $blue = '#2f6fcb'; $red = '#c8372d';

if ($ready) {
    $sVisW = traffic_series("SELECT day d, COUNT(*) n FROM site_visits WHERE vid LIKE 'w%' AND day >= $since GROUP BY day", $days);
    $sVisA = traffic_series("SELECT day d, COUNT(*) n FROM site_visits WHERE vid LIKE 'a%' AND day >= $since GROUP BY day", $days);
    $sMemW = traffic_series("SELECT day d, COUNT(*) n FROM member_days WHERE source = 'web' AND day >= $since GROUP BY day", $days);
    $sMemA = traffic_series("SELECT day d, COUNT(*) n FROM member_days WHERE source = 'app' AND day >= $since GROUP BY day", $days);
    $sLogW = traffic_series("SELECT DATE(created_at) d, COUNT(*) n FROM login_log WHERE source = 'web' AND created_at >= $since GROUP BY d", $days);
    $sLogA = traffic_series("SELECT DATE(created_at) d, COUNT(*) n FROM login_log WHERE source = 'app' AND created_at >= $since GROUP BY d", $days);
    $sReg  = traffic_series("SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= $since GROUP BY d", $days);
    $sDl   = traffic_series("SELECT DATE(created_at) d, COUNT(*) n FROM app_downloads WHERE created_at >= $since GROUP BY d", $days);
    $sDev  = traffic_series("SELECT day d, COUNT(*) n FROM app_usage WHERE day >= $since GROUP BY day", $days);
    $sOpen = traffic_series("SELECT day d, SUM(opens) n FROM app_usage WHERE day >= $since GROUP BY day", $days);

    $k = [
        'members'   => $one("SELECT COUNT(*) FROM users"),
        'newp'      => $one("SELECT COUNT(*) FROM users WHERE created_at >= $since"),
        'visToday'  => $one("SELECT COUNT(*) FROM site_visits WHERE day = CURDATE() AND vid LIKE 'w%'"),
        'visPeriod' => $one("SELECT COUNT(DISTINCT vid) FROM site_visits WHERE day >= $since AND vid LIKE 'w%'"),
        'memToday'  => $one("SELECT COUNT(DISTINCT user_id) FROM member_days WHERE day = CURDATE()"),
        'mem7'      => $one("SELECT COUNT(DISTINCT user_id) FROM member_days WHERE day >= CURDATE() - INTERVAL 6 DAY"),
        'mem30'     => $one("SELECT COUNT(DISTINCT user_id) FROM member_days WHERE day >= CURDATE() - INTERVAL 29 DAY"),
        'loginsP'   => $one("SELECT COUNT(*) FROM login_log WHERE created_at >= $since"),
        'loginsT'   => $one("SELECT COUNT(*) FROM login_log WHERE created_at >= CURDATE()"),
        'dlAll'     => $one("SELECT COUNT(*) FROM app_downloads"),
        'dlToday'   => $one("SELECT COUNT(*) FROM app_downloads WHERE created_at >= CURDATE()"),
        'dlP'       => $one("SELECT COUNT(*) FROM app_downloads WHERE created_at >= $since"),
        'appDau'    => $one("SELECT COUNT(DISTINCT dev) FROM app_usage WHERE day = CURDATE()"),
        'appWau'    => $one("SELECT COUNT(DISTINCT dev) FROM app_usage WHERE day >= CURDATE() - INTERVAL 6 DAY"),
        'appMau'    => $one("SELECT COUNT(DISTINCT dev) FROM app_usage WHERE day >= CURDATE() - INTERVAL 29 DAY"),
        'appEver'   => $one("SELECT COUNT(DISTINCT dev) FROM app_usage"),
        'appMemT'   => $one("SELECT COUNT(DISTINCT user_id) FROM member_days WHERE source = 'app' AND day = CURDATE()"),
    ];
    $bySrc = []; foreach ($rows("SELECT source, COUNT(*) n FROM app_downloads GROUP BY source") as $x) { $bySrc[['nav' => 'Top menu button', 'footer' => 'Footer', 'page' => 'Get the app page', 'home' => 'Home page', 'vip' => 'VIP page', 'other' => 'Direct link'][$x['source']] ?? ($x['source'] ?: 'Direct link')] = (int)$x['n']; }
    $byDev = []; foreach ($rows("SELECT device, COUNT(*) n FROM app_downloads GROUP BY device") as $x) { $byDev[ucfirst($x['device'] ?: 'other')] = (int)$x['n']; }
    $vers = []; foreach ($rows("SELECT version, COUNT(DISTINCT dev) n FROM app_usage WHERE day >= CURDATE() - INTERVAL 29 DAY AND version <> '' GROUP BY version") as $x) { $vers[$x['version']] = (int)$x['n']; }
    $rel = $rows("SELECT r.version, r.downloads, r.created_at, r.is_current, (SELECT COUNT(*) FROM app_downloads a WHERE a.release_id = r.id) AS logged FROM app_releases r ORDER BY r.id DESC LIMIT 10");
    $hours = array_fill(0, 24, 0); foreach ($rows("SELECT HOUR(created_at) h, COUNT(*) n FROM login_log WHERE created_at >= $since GROUP BY h") as $x) { $hours[(int)$x['h']] = (int)$x['n']; }
    $hrs = []; foreach ($hours as $h => $n) { $hrs[sprintf('%02d:00', $h)] = $n; }
}
$admin_title = 'Traffic, logins and app usage';
$admin_active = 'appstats';
include dirname(__DIR__) . '/includes/admin-header.php';
$kp = function (string $big, string $label, string $cls = '', string $note = '') { return '<div class="kpi ' . $cls . '"><b>' . e($big) . '</b><span>' . e($label) . '</span>' . ($note !== '' ? '<em>' . e($note) . '</em>' : '') . '</div>'; };
?>
<div class="toolbar"><div><h1>Traffic, logins and app usage</h1>
  <p class="sub" style="margin:0">Website and Android app: visitors, logins, daily active members, registrations and app downloads. Visitors are counted once per browser per day; app usage counts each phone once per day.</p></div>
  <div class="tabs"><?php foreach ([7, 14, 30, 60, 90] as $dd): ?><a href="<?= e(url('admin/app-stats.php?d=' . $dd)) ?>"<?= $dd === $days ? ' class="on"' : '' ?>><?= $dd ?> days</a><?php endforeach; ?></div></div>
<?php if (!$ready): ?><div class="errors" role="alert"><ul><li>The tracking tables could not be created. Run <code>database-update-32.sql</code> and <code>database-update-33.sql</code> once in phpMyAdmin.</li></ul></div><?php else: ?>

<h2 style="margin:6px 0 12px;font:800 28px 'Barlow Condensed',sans-serif">Website</h2>
<div class="kpis">
  <?= $kp(number_format($k['visToday']), 'Visitors today') ?>
  <?= $kp(number_format($k['visPeriod']), 'Unique visitors, ' . $days . ' days', 'blue') ?>
  <?= $kp(number_format($k['loginsT']), 'Logins today', 'green') ?>
  <?= $kp(number_format($k['loginsP']), 'Logins, ' . $days . ' days', 'green') ?>
  <?= $kp(number_format($k['memToday']), 'Active members today') ?>
  <?= $kp(number_format($k['mem7']), 'Active members, 7 days') ?>
  <?= $kp(number_format($k['mem30']), 'Active members, 30 days') ?>
  <?= $kp(number_format($k['newp']), 'New registrations, ' . $days . ' days', 'red', number_format($k['members']) . ' members in total') ?>
</div>
<div class="grid2">
  <div class="chart-card"><h3>Daily visitors</h3><div class="cs">Unique browsers per day</div><?= chart_lines(['Website' => $sVisW, 'App' => $sVisA], [$gold, $green]) ?></div>
  <div class="chart-card"><h3>Members logged in each day</h3><div class="cs">Members seen using the site or app that day</div><?= chart_lines(['Website' => $sMemW, 'App' => $sMemA], [$green, $gold]) ?></div>
  <div class="chart-card"><h3>Logins per day</h3><div class="cs">Every time someone logs in</div><?= chart_bars(['Website' => $sLogW, 'App' => $sLogA], [$green, $gold]) ?></div>
  <div class="chart-card"><h3>New registrations</h3><div class="cs">Accounts created per day</div><?= chart_bars(['Registrations' => $sReg], [$blue]) ?></div>
</div>
<div class="chart-card"><h3>When people log in</h3><div class="cs">Logins by hour of day, server time, last <?= $days ?> days</div><?= chart_bars(['Logins' => $hrs], [$gold], 200) ?></div>

<h2 style="margin:22px 0 12px;font:800 28px 'Barlow Condensed',sans-serif">Android app</h2>
<div class="kpis">
  <?= $kp(number_format($k['dlAll']), 'Downloads, all time', 'green') ?>
  <?= $kp(number_format($k['dlToday']), 'Downloads today') ?>
  <?= $kp(number_format($k['dlP']), 'Downloads, ' . $days . ' days') ?>
  <?= $kp(number_format($k['appDau']), 'Active phones today', 'blue') ?>
  <?= $kp(number_format($k['appWau']), 'Active phones, 7 days', 'blue') ?>
  <?= $kp(number_format($k['appMau']), 'Active phones, 30 days', 'blue') ?>
  <?= $kp(number_format($k['appEver']), 'Phones seen ever') ?>
  <?= $kp(number_format($k['appMemT']), 'Members using the app today', 'red') ?>
</div>
<div class="grid2">
  <div class="chart-card"><h3>App downloads per day</h3><div class="cs">APK downloads started on the website</div><?= chart_bars(['Downloads' => $sDl], [$gold]) ?></div>
  <div class="chart-card"><h3>Daily app usage</h3><div class="cs">Active phones and total opens</div><?= chart_lines(['Active phones' => $sDev, 'App opens' => $sOpen], [$green, $gold]) ?></div>
  <div class="chart-card"><h3>Where downloads came from</h3><?= chart_hbars($bySrc) ?></div>
  <div class="chart-card"><h3>Devices that downloaded</h3><?= chart_hbars($byDev) ?></div>
  <div class="chart-card"><h3>App versions in use (30 days)</h3><?= $vers ? chart_hbars($vers) : '<p class="hint">Fills in as members open the app.</p>' ?></div>
</div>
<div class="panel"><h2>Releases</h2><div class="scroll"><table class="tbl"><thead><tr><th>Version</th><th>Published</th><th>Downloads (counter)</th><th>Downloads (logged)</th><th></th></tr></thead><tbody>
  <?php foreach ($rel as $r): ?><tr><td><?= e($r['version']) ?></td><td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><?= number_format((int)$r['downloads']) ?></td><td><?= number_format((int)$r['logged']) ?></td><td><?= $r['is_current'] ? '<span class="badge won">Current</span>' : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$rel): ?><tr><td colspan="5">No release uploaded yet.</td></tr><?php endif; ?>
</tbody></table></div><p class="hint">The counter includes downloads from before detailed tracking was switched on. Statistics start from the day this version was installed.</p></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
