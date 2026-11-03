<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('payments');
$pdo = db();
$ready = access_ready();
$errors = [];
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'toggle') {
            $on = setting('access_fee_on', '0') === '1' ? '0' : '1';
            if ($on === '1') { access_plan_id(); if (setting('access_fee_start', '') === '') { set_setting('access_fee_start', date('Y-m-d H:i:s')); } }
            set_setting('access_fee_on', $on);
            admin_log('accessfee.toggle', 'setting', $on === '1' ? 'ON' : 'OFF');
            set_flash('success', $on === '1' ? 'Weekly access is ON. VIP is now free for everyone with access.' : 'Weekly access is OFF. VIP plans are on sale again.');
            redirect('admin/access-fee.php');
        } elseif ($act === 'save') {
            $k = (int)($_POST['kes'] ?? 0); $d = (float)($_POST['usd'] ?? 0); $fd = (int)($_POST['free_days'] ?? 7);
            if ($k < 1 || $k > 100000) { $errors[] = 'Enter the weekly KES price (1 or more).'; }
            if ($d < 0.5 || $d > 1000) { $errors[] = 'Enter the weekly USD price (0.50 or more).'; }
            if ($fd < 0 || $fd > 60) { $errors[] = 'Free days must be 0 to 60.'; }
            if (!$errors) {
                set_setting('access_fee_kes', (string)$k); set_setting('access_fee_usd', rtrim(rtrim(number_format($d, 2, '.', ''), '0'), '.')); set_setting('access_free_days', (string)$fd);
                admin_log('accessfee.save', 'setting', "KES $k, USD $d, free $fd days");
                set_flash('success', 'Saved.'); redirect('admin/access-fee.php');
            }
        } elseif ($act === 'lock') {
            set_setting('access_lock', !empty($_POST['lock']) ? '1' : '0'); admin_log('accessfee.lock', 'setting', !empty($_POST['lock']) ? 'on' : 'off'); set_flash('success', 'Saved.'); redirect('admin/access-fee.php');
        } elseif ($act === 'restart') {
            set_setting('access_fee_start', date('Y-m-d H:i:s'));
            admin_log('accessfee.restart', 'setting', 'free week restarted for everyone');
            set_flash('success', 'Everyone now gets a fresh free period starting now.'); redirect('admin/access-fee.php');
        } elseif ($act === 'grant') {
            $em = trim((string)($_POST['email'] ?? ''));
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $st->execute([$em]); $uid = (int)$st->fetchColumn();
            if (!$uid) { $errors[] = 'No member with that email.'; }
            else { $pdo->prepare('INSERT INTO access_passes (user_id, starts_at, ends_at) VALUES (?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))')->execute([$uid]); admin_log('accessfee.grant', 'user#' . $uid, '7 days free'); set_flash('success', '7 days of access added.'); redirect('admin/access-fee.php'); }
        }
    }
}
$on = $ready && setting('access_fee_on', '0') === '1';
$stats = ['paid_now' => 0, 'revenue_kes' => 0, 'payments' => 0, 'due' => 0, 'members' => 0];
$recent = [];
if ($ready) {
    try {
        $stats['paid_now'] = (int)$pdo->query('SELECT COUNT(DISTINCT user_id) FROM access_passes WHERE ends_at > NOW()')->fetchColumn();
        $pid = access_plan_id();
        $r = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN currency = 'KES' THEN amount ELSE 0 END),0) FROM payments WHERE plan_id = ? AND status = 'paid'"); $r->execute([$pid]);
        [$stats['payments'], $stats['revenue_kes']] = array_map('floatval', $r->fetch(PDO::FETCH_NUM));
        $recent = $pdo->prepare("SELECT py.amount, py.currency, py.method, py.paid_at, u.name, u.email FROM payments py JOIN users u ON u.id = py.user_id WHERE py.plan_id = ? AND py.status = 'paid' ORDER BY py.paid_at DESC LIMIT 15");
        $recent->execute([$pid]); $recent = $recent->fetchAll();
        $stats['members'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
        $start = (string)setting('access_fee_start', '') ?: '2000-01-01 00:00:00';
        $fd = access_free_days();
        $q = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role = 'user' AND GREATEST(u.created_at, ?) + INTERVAL $fd DAY <= NOW() AND NOT EXISTS (SELECT 1 FROM access_passes a WHERE a.user_id = u.id AND a.ends_at > NOW())");
        $q->execute([$start]); $stats['due'] = (int)$q->fetchColumn();
    } catch (Throwable $e) { }
}
$admin_title = 'Weekly access';
$admin_active = 'accessfee';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Weekly access</h1>
<p class="sub">Charge members a small weekly fee to use SlipKing (website and app). While it is ON, <b>all VIP tips and accumulators are free for everyone</b> who has access, and VIP plans cannot be bought. While it is OFF, nothing here is visible anywhere.</p>
<?php if (!$ready): ?><div class="errors"><ul><li>Run <code>database-update-35.sql</code> once in phpMyAdmin.</li></ul></div><?php else: ?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php $diag = [['Weekly access table', access_ready()], ['Fee switched on', setting('access_fee_on','0') === '1'], ['Hidden weekly plan exists', (bool)access_plan_id()], ['Login page shows the note and button', is_file(dirname(__DIR__) . '/auth/login.php') && strpos((string)file_get_contents(dirname(__DIR__) . '/auth/login.php'), 'access_on()') !== false], ['Pay page file present', is_file(dirname(__DIR__) . '/access.php')]]; ?>
<div class="panel"><h2>Setup check</h2><ul style="margin:0;padding-left:18px"><?php foreach ($diag as $d): ?><li><?= $d[1] ? '&#10003;' : '&#10007;' ?> <?= e($d[0]) ?></li><?php endforeach; ?></ul></div>
<div class="panel" style="border:2px solid <?= $on ? '#188a4a' : '#d6ded9' ?>">
  <h2>Status: <span class="badge <?= $on ? 'won' : 'pending' ?>"><?= $on ? 'ON' : 'OFF' ?></span></h2>
  <p><?= $on ? 'Members see the weekly access option at login and registration, and are asked to pay when their free week ends.' : 'Turn it on to start charging. Everyone gets a free period first, counted from the day you turn it on (new members: from the day they register).' ?></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><button class="btn <?= $on ? 'loss' : 'gold' ?>" type="submit" onclick="return confirm('<?= $on ? 'Turn weekly access OFF? VIP plans go back on sale.' : 'Turn weekly access ON? VIP becomes free for everyone and members start paying after their free period.' ?>')"><?= $on ? 'Turn OFF' : 'Turn ON' ?></button></form>
</div>
<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="lock"><h2>Who can see the site</h2>
  <div class="field check"><label><input type="checkbox" name="lock" value="1"<?= setting('access_lock', '1') === '1' ? ' checked' : '' ?>> Lock the whole site: visitors must log in, and members must have paid (or be in their free week) to see anything, including free tips</label></div>
  <p class="hint">Untick it if you want guests to keep seeing the public pages (Google can only index pages guests can open). Members whose time is up are always sent to the pay page.</p>
  <button class="btn outline small" type="submit">Save</button>
</form>
<div class="grid2" style="align-items:start">
<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="save"><h2>Prices</h2>
  <div class="formgrid">
    <div class="field"><label for="kes">Kenya, Uganda, Tanzania (KES per week)</label><input type="number" id="kes" name="kes" min="1" value="<?= (int)access_kes() ?>"></div>
    <div class="field"><label for="usd">All other countries (USD per week)</label><input type="number" id="usd" name="usd" min="0.5" step="0.5" value="<?= e((string)access_usd()) ?>"></div>
    <div class="field"><label for="fd">Free days for new members</label><input type="number" id="fd" name="free_days" min="0" max="60" value="<?= (int)access_free_days() ?>"></div>
  </div>
  <p class="hint">The country comes from the member's profile (country, nationality or phone code).</p>
  <button class="btn gold" type="submit">Save</button>
</form>
<div class="panel"><h2>Numbers</h2>
  <div class="grid2" style="gap:10px">
    <div class="stat"><b><?= (int)$stats['paid_now'] ?></b><span>Paid and active now</span></div>
    <div class="stat"><b><?= (int)$stats['due'] ?></b><span>Members who must pay</span></div>
    <div class="stat"><b><?= (int)$stats['payments'] ?></b><span>Weekly payments received</span></div>
    <div class="stat"><b>KES <?= number_format((float)$stats['revenue_kes']) ?></b><span>Collected in KES</span></div>
  </div>
  <form method="post" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="action" value="grant"><div class="field"><label for="ge">Give a member 7 free days (email)</label><input type="email" id="ge" name="email" placeholder="member@example.com"></div><button class="btn outline small" type="submit">Give 7 days</button></form>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="restart"><button class="btn outline small" type="submit" onclick="return confirm('Give every member a fresh free period starting now?')">Restart free period for everyone</button></form>
</div></div>
<div class="panel"><h2>Recent weekly payments</h2>
<?php if ($recent): ?><div class="scroll"><table class="tbl"><thead><tr><th>Paid</th><th>Member</th><th>Amount</th><th>Method</th></tr></thead><tbody>
<?php foreach ($recent as $r): ?><tr><td><?= e(date('j M, H:i', strtotime($r['paid_at']))) ?></td><td><?= e($r['name']) ?><br><small><?= e($r['email']) ?></small></td><td><?= e($r['currency']) ?> <?= number_format((float)$r['amount'], 2) ?></td><td><?= e($r['method']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><div class="empty">No weekly payments yet.</div><?php endif; ?></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
