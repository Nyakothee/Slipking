<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!is_admin()) {
        set_flash('error', 'Only an administrator can change push keys.');
    } elseif (($_POST['action'] ?? '') === 'generate') {
        $has = webpush_ready();
        if ($has && ($_POST['confirm'] ?? '') !== 'yes') {
            set_flash('error', 'Keys already exist. Tick the box to replace them (all devices must turn alerts on again).');
        } else {
            $k = webpush_generate_keys();
            if (!$k) {
                set_flash('error', 'This server could not create the keys (PHP OpenSSL with elliptic-curve support is needed; on Windows/XAMPP check that openssl.cnf is found).');
            } else {
                set_setting('vapid_private_pem', $k[0]);
                set_setting('vapid_public', $k[1]);
                if ($has) { $pdo->exec('DELETE FROM push_subs'); }
                admin_log('push.keys', '', $has ? 'replaced' : 'created');
                set_flash('success', 'Push keys created.');
            }
        }
    } elseif (($_POST['action'] ?? '') === 'test') {
        $me = (int)current_user()['id'];
        $st = $pdo->prepare('SELECT * FROM push_subs WHERE user_id = ?');
        $st->execute([$me]);
        $subs = $st->fetchAll();
        if (!$subs) { set_flash('error', 'You have no device with alerts turned on. Open Account > Alerts on your phone or computer first.'); }
        else {
            $ok = 0; $codes = [];
            foreach ($subs as $s) {
                $c = webpush_send($s, json_encode(['title' => SITE_NAME . ' test', 'body' => 'Push alerts work.', 'url' => 'account/alerts.php']), 600);
                $codes[] = $c; if ($c >= 200 && $c < 300) { $ok++; }
                if ($c === 404 || $c === 410) { $pdo->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]); }
            }
            set_flash($ok ? 'success' : 'error', 'Sent to ' . $ok . ' of ' . count($subs) . ' devices (codes: ' . implode(', ', $codes) . ').');
        }
    } elseif (($_POST['action'] ?? '') === 'process') {
        [$s, $f] = alerts_process(100);
        set_flash('success', "Queue processed: sent $s, failed $f.");
    }
    redirect('admin/push.php');
}
$subs = (int)$pdo->query('SELECT COUNT(*) FROM push_subs')->fetchColumn();
$q = $pdo->query("SELECT SUM(sent_at IS NULL AND tries < 3) AS waiting, SUM(sent_at IS NOT NULL) AS sent, SUM(sent_at IS NULL AND tries >= 3) AS failed FROM alert_queue WHERE created_at > NOW() - INTERVAL 2 DAY")->fetch();
$recent = $pdo->query("SELECT q.channel, q.title, q.sent_at, q.tries, q.error, q.created_at, u.name FROM alert_queue q JOIN users u ON u.id = q.user_id ORDER BY q.id DESC LIMIT 15")->fetchAll();
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

$admin_title = 'Push notifications';
$admin_active = 'push';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Push notifications and alerts</h1>
<p class="sub">Browser pop-ups for kickoff reminders, new tips from followed tipsters, results and VIP ending.</p>
<div class="panel"><h2>Status</h2>
  <table class="tbl"><tbody>
    <tr><td>PHP can do push (openssl + curl)</td><td><?= webpush_supported() ? 'Yes' : '<strong style="color:#c0392b">No</strong> - needs the openssl and curl extensions (PHP 7.3+)' ?></td></tr>
    <tr><td>Site on HTTPS</td><td><?= $https ? 'Yes' : '<strong style="color:#c0392b">No</strong> - browsers only allow push on https sites (localhost is fine for testing)' ?></td></tr>
    <tr><td>Keys</td><td><?= webpush_ready() ? 'Created' : 'Not created yet' ?></td></tr>
    <tr><td>Devices with alerts on</td><td><?= $subs ?></td></tr>
    <tr><td>Alerts in the last 2 days</td><td>waiting <?= (int)$q['waiting'] ?>, sent <?= (int)$q['sent'] ?>, failed <?= (int)$q['failed'] ?></td></tr>
  </tbody></table></div>
<?php if (is_admin()): ?>
<div class="panel"><h2>Keys</h2>
  <p>Create the site's push keys once. Never share the private key. The keys are stored in the database settings table.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="generate">
    <?php if (webpush_ready()): ?><label><input type="checkbox" name="confirm" value="yes"> Replace the existing keys (every member must turn alerts on again)</label><br><?php endif; ?>
    <button class="btn dark" type="submit"><?= webpush_ready() ? 'Replace keys' : 'Create keys' ?></button></form></div>
<?php endif; ?>
<div class="panel"><h2>Test</h2>
  <p>Turn alerts on for your own account on <a href="<?= e(url('account/alerts.php')) ?>">Account &gt; Alerts</a>, then send yourself a test.</p>
  <form method="post" class="inl" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn dark" type="submit">Send me a test push</button></form>
  <form method="post" class="inl" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="process"><button class="btn" type="submit">Send waiting alerts now</button></form>
  <p style="color:var(--mute);margin-top:10px">Cron job (every 5 minutes): <code>php cron/send-alerts.php</code></p></div>
<?php if ($recent): ?><div class="panel"><h2>Latest alerts</h2><table class="tbl"><thead><tr><th>When</th><th>Member</th><th>Channel</th><th>Alert</th><th>State</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?= e(date('j M H:i', strtotime($r['created_at']))) ?></td><td><?= e(mask_name($r['name'])) ?></td><td><?= e($r['channel']) ?></td><td><?= e($r['title']) ?></td><td><?= $r['sent_at'] ? 'sent' . ($r['error'] ? ' (' . e($r['error']) . ')' : '') : 'waiting (tries ' . (int)$r['tries'] . ')' . ($r['error'] ? ' ' . e($r['error']) : '') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
