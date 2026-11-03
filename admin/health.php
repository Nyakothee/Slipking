<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
$pdo = db();

function hc_ago(?string $dt): string
{
    if (!$dt) { return 'never'; }
    $s = time() - strtotime($dt);
    if ($s < 90) { return 'just now'; }
    if ($s < 5400) { return round($s / 60) . ' min ago'; }
    if ($s < 172800) { return round($s / 3600) . ' h ago'; }
    return round($s / 86400) . ' days ago';
}
function hc_val(PDO $pdo, string $sql)
{
    try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return null; }
}

$checks = [];
$add = function (string $name, string $state, string $detail) use (&$checks) { $checks[] = [$name, $state, $detail]; };

$add('PHP version', version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'bad', PHP_VERSION . (version_compare(PHP_VERSION, '7.4', '>=') ? '' : ' - 7.4 or newer is needed'));
$add('Image library (GD)', function_exists('imagecreatetruecolor') ? 'ok' : 'warn', function_exists('imagecreatetruecolor') ? 'Pictures are resized and compressed.' : 'Missing: uploaded pictures are stored as they are.');
foreach (['cache/data' => 'Cache folder', 'cache/sessions' => 'App login sessions', 'logs' => 'Log folder', 'uploads' => 'Uploads folder'] as $d => $label) {
    $p = dirname(__DIR__) . '/' . $d;
    if (!is_dir($p)) { @mkdir($p, 0755, true); }
    $add($label . ' writable', is_writable($p) ? 'ok' : 'bad', $d . (is_writable($p) ? '' : ' cannot be written to: set its permissions to 755 or 775'));
}
$add('HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'ok' : 'warn', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Secure connection.' : 'This page was opened over plain http: passwords are not encrypted in transit.');

$lastTip = hc_val($pdo, "SELECT MAX(settled_at) FROM tips WHERE status <> 'pending'");
$pendingOld = (int)hc_val($pdo, "SELECT COUNT(*) FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.deleted_at IS NULL AND t.status = 'pending' AND m.kickoff_at < NOW() - INTERVAL 1 DAY");
$add('Results being settled', $pendingOld === 0 ? 'ok' : ($pendingOld > 10 ? 'bad' : 'warn'), $pendingOld . ' tips are still pending more than a day after kickoff. Last result set ' . hc_ago($lastTip) . '.');

$live = hc_val($pdo, 'SELECT MAX(score_updated_at) FROM matches');
$liveOn = setting('live_enabled', '0') === '1';
$add('Live scores', !$liveOn ? 'warn' : ((time() - strtotime((string)$live) < 86400 || !$live) ? 'ok' : 'warn'), ($liveOn ? 'On' : 'Switched off') . '. Last score update ' . hc_ago($live) . '.');

$pushFail = hc_val($pdo, "SELECT COUNT(*) FROM app_push_queue WHERE sent_at IS NULL AND tries >= 3");
$pushWait = hc_val($pdo, "SELECT COUNT(*) FROM app_push_queue WHERE sent_at IS NULL AND tries < 3");
$pushLast = hc_val($pdo, 'SELECT MAX(sent_at) FROM app_push_queue');
if ($pushFail === null) { $add('App push notifications', 'warn', 'Not set up yet (database-update-27.sql).'); }
else { $add('App push notifications', ((int)$pushFail > 20) ? 'bad' : (((int)$pushWait > 50) ? 'warn' : 'ok'), $pushWait . ' waiting, ' . $pushFail . ' failed. Last sent ' . hc_ago($pushLast) . '.'); }

$alertWait = hc_val($pdo, "SELECT COUNT(*) FROM alert_queue WHERE sent_at IS NULL AND tries < 3 AND created_at < NOW() - INTERVAL 1 HOUR");
if ($alertWait !== null) { $add('Alert queue (email/SMS/push)', (int)$alertWait > 50 ? 'warn' : 'ok', (int)$alertWait . ' alerts waiting more than an hour. If this grows, the alerts cron job is not running.'); }

$pendPay = hc_val($pdo, "SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at < NOW() - INTERVAL 1 DAY");
if ($pendPay !== null) { $add('Stuck payments', (int)$pendPay > 0 ? 'warn' : 'ok', (int)$pendPay . ' payments have been pending more than a day (check Admin > Payments).'); }

$hk = setting('last_housekeeping', '');
$add('Daily cleanup', $hk === date('Y-m-d') ? 'ok' : 'warn', $hk === '' ? 'Has not run yet (it runs when someone opens the home page).' : 'Last ran ' . $hk . '.');

$errLog = dirname(__DIR__) . '/logs';
$big = 0;
foreach ((array)@glob($errLog . '/*.log') as $f) { $big = max($big, (int)@filesize($f)); }
$add('Log file size', $big > 20 * 1024 * 1024 ? 'warn' : 'ok', 'Largest log ' . round($big / 1048576, 1) . ' MB' . ($big > 20 * 1024 * 1024 ? ' - delete old logs to save space' : '') . '.');

$crashes = [];
try { $crashes = $pdo->query('SELECT * FROM app_crashes ORDER BY id DESC LIMIT 20')->fetchAll(); } catch (Throwable $e) {}
$crash24 = hc_val($pdo, "SELECT COUNT(*) FROM app_crashes WHERE created_at > NOW() - INTERVAL 1 DAY");
$add('App crashes (24 h)', (int)$crash24 > 5 ? 'bad' : ((int)$crash24 > 0 ? 'warn' : 'ok'), (int)$crash24 . ' reported by phones in the last day.');

$admin_title = 'System health';
$admin_active = 'health';
include dirname(__DIR__) . '/includes/admin-header.php';
$icon = ['ok' => '&#10003;', 'warn' => '!', 'bad' => '&#10007;'];
$col = ['ok' => '#1b8a4b', 'warn' => '#c98a00', 'bad' => '#c0392b'];
?>
<h1>System health</h1>
<p class="sub">A quick check that the things that run quietly in the background are still working.</p>
<div class="panel"><table class="tbl"><tbody>
<?php foreach ($checks as $c): ?>
  <tr><td style="width:36px"><span style="display:inline-block;width:24px;height:24px;border-radius:50%;background:<?= $col[$c[1]] ?>;color:#fff;text-align:center;line-height:24px;font-weight:800"><?= $icon[$c[1]] ?></span></td>
  <td><strong><?= e($c[0]) ?></strong></td><td><?= e($c[2]) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($crashes): ?>
<div class="panel"><h2>Latest app crashes</h2><div class="scroll"><table class="tbl"><thead><tr><th>When</th><th>App</th><th>Phone</th><th>Error</th></tr></thead><tbody>
<?php foreach ($crashes as $c): ?><tr><td><?= e(date('j M, H:i', strtotime($c['created_at']))) ?></td><td><?= e($c['app_version']) ?></td><td><?= e($c['device']) ?><br><small><?= e($c['os']) ?></small></td>
<td><pre style="white-space:pre-wrap;max-width:560px;font-size:12px;margin:0"><?= e(mb_substr((string)$c['message'], 0, 600)) ?></pre></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
