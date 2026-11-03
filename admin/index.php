<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('dashboard');
require_once dirname(__DIR__) . '/includes/admin-charts.php';
$pdo = db();

$users    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$new7     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
$vipNow   = (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE status = 'active' AND starts_at <= NOW() AND ends_at > NOW()")->fetchColumn();
$pendPay  = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending' AND created_at > NOW() - INTERVAL 1 DAY AND created_at < NOW() - INTERVAL 15 MINUTE")->fetchColumn();
$tipsToday = (int)$pdo->query("SELECT COUNT(*) FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.deleted_at IS NULL AND DATE(m.kickoff_at) = CURDATE()")->fetchColumn();
$needs    = (int)$pdo->query("SELECT COUNT(*) FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.deleted_at IS NULL AND t.status = 'pending' AND m.kickoff_at < NOW()")->fetchColumn();

$rev30 = $pdo->query("SELECT currency, SUM(amount) AS total FROM payments WHERE status = 'paid' AND paid_at > NOW() - INTERVAL 30 DAY GROUP BY currency")->fetchAll(PDO::FETCH_KEY_PAIR);
$revToday = $pdo->query("SELECT currency, SUM(amount) AS total FROM payments WHERE status = 'paid' AND DATE(paid_at) = CURDATE() GROUP BY currency")->fetchAll(PDO::FETCH_KEY_PAIR);
function money_line(array $by): string
{
    $parts = [];
    foreach ($by as $cur => $tot) {
        $parts[] = $cur . ' ' . number_format((float)$tot, $cur === 'KES' ? 0 : 2);
    }
    return $parts ? implode(' + ', $parts) : '0';
}

$stats = site_stats();
$pending = admin_pending();

$needList = $pdo->query("SELECT t.id, t.pick, t.odds, t.is_vip, m.home_team, m.away_team, m.kickoff_at
                         FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE t.deleted_at IS NULL AND t.status = 'pending' AND m.kickoff_at < NOW()
                         ORDER BY m.kickoff_at ASC LIMIT 8")->fetchAll();
$payments = $pdo->query("SELECT py.*, u.name, p.name AS plan_name FROM payments py
                         JOIN users u ON u.id = py.user_id JOIN plans p ON p.id = py.plan_id
                         ORDER BY py.created_at DESC LIMIT 8")->fetchAll();
$signups  = $pdo->query("SELECT name, email, created_at FROM users WHERE role = 'user' ORDER BY id DESC LIMIT 6")->fetchAll();

// ---- charts (last 30 days) ----
$days30  = admin_last_days(30);
$labels30 = array_map(function ($d) { return date('j M', strtotime($d)); }, $days30);
$rates   = admin_rates($pdo);
$revByDay = array_fill_keys($days30, 0.0);
$st = $pdo->query("SELECT DATE(paid_at) AS d, currency, SUM(amount) AS total FROM payments WHERE status = 'paid' AND paid_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(paid_at), currency");
foreach ($st->fetchAll() as $r) {
    if (isset($revByDay[$r['d']])) { $revByDay[$r['d']] += admin_to_kes((float)$r['total'], $r['currency'], $rates); }
}
$memByDay = array_fill_keys($days30, 0);
foreach ($pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM users WHERE role = 'user' AND created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)")->fetchAll() as $r) {
    if (isset($memByDay[$r['d']])) { $memByDay[$r['d']] = (int)$r['c']; }
}
$winByDay = array_fill_keys($days30, null);
foreach ($pdo->query("SELECT DATE(m.kickoff_at) AS d, SUM(t.status = 'won') AS w, SUM(t.status IN ('won','lost')) AS s
                      FROM tips t JOIN matches m ON m.id = t.match_id
                      WHERE t.status IN ('won','lost') AND m.kickoff_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(m.kickoff_at)")->fetchAll() as $r) {
    if (array_key_exists($r['d'], $winByDay) && (int)$r['s'] > 0) { $winByDay[$r['d']] = round(100 * (int)$r['w'] / (int)$r['s'], 1); }
}
$planRev = [];
foreach ($pdo->query("SELECT p.name, py.currency, SUM(py.amount) AS total FROM payments py JOIN plans p ON p.id = py.plan_id WHERE py.status = 'paid' AND py.paid_at > NOW() - INTERVAL 30 DAY GROUP BY p.id, p.name, py.currency")->fetchAll() as $r) {
    $planRev[$r['name']] = ($planRev[$r['name']] ?? 0) + admin_to_kes((float)$r['total'], $r['currency'], $rates);
}
arsort($planRev);
$reg30  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND created_at > NOW() - INTERVAL 30 DAY")->fetchColumn();
$paid30 = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE u.role = 'user' AND u.created_at > NOW() - INTERVAL 30 DAY AND EXISTS (SELECT 1 FROM payments py WHERE py.user_id = u.id AND py.status = 'paid')")->fetchColumn();
$conv30 = $reg30 > 0 ? round(100 * $paid30 / $reg30, 1) : null;
$pendAll = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn();

$admin_title = 'Dashboard';
$admin_active = 'dashboard';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><h2>Needs your attention <?php if (($pt = array_sum(array_column($pending, 1))) > 0): ?><span class="nbadge"><?= (int)$pt ?></span><?php endif; ?></h2>
  <div style="display:flex;flex-wrap:wrap;gap:8px"><?php foreach ($pending as $p): ?><a class="btn <?= $p[1] ? 'gold' : 'dark' ?> tiny" href="<?= e(url($p[2])) ?>"><?= e($p[0]) ?>: <b><?= (int)$p[1] ?></b></a><?php endforeach; ?></div></div>
<?php
?>
<h1>Dashboard</h1>
<p class="sub"><?= e(date('l j F Y, H:i')) ?></p>

<?php if ($needs && can('tips')): ?>
  <div class="alert"><strong><?= $needs ?> tip<?= $needs === 1 ? '' : 's' ?> waiting for a result.</strong> Mark them below so the record stays up to date.</div>
<?php endif; ?>
<?php if ($pendPay && can('payments')): ?>
  <div class="alert"><strong><?= $pendPay ?> payment<?= $pendPay === 1 ? '' : 's' ?> still pending after 15 minutes.</strong> Check <code>logs/payments.log</code> and the cron job.</div>
<?php endif; ?>

<?php $ac = admin_counts(); if ($ac['tipsters'] && can('tipsters')): ?>
  <div class="alert"><strong><?= (int)$ac['tipsters'] ?> tipster application<?= $ac['tipsters'] === 1 ? '' : 's' ?> waiting for review.</strong> <a href="<?= e(url('admin/tipsters.php?status=pending')) ?>" style="text-decoration:underline">Review</a></div>
<?php endif; if ($ac['payouts'] && can('payouts')): ?>
  <div class="alert"><strong><?= (int)$ac['payouts'] ?> tipster payout request<?= $ac['payouts'] === 1 ? '' : 's' ?> to pay.</strong> <a href="<?= e(url('admin/tipster-payouts.php')) ?>" style="text-decoration:underline">Open payouts</a></div>
<?php endif; if ($ac['chat'] && can('chat')): ?>
  <div class="alert"><strong><?= $ac['chat'] ?> chat<?= $ac['chat'] === 1 ? '' : 's' ?> waiting for a reply.</strong> <a href="<?= e(url('admin/chat.php')) ?>" style="text-decoration:underline">Open live chat</a></div>
<?php endif; if ($ac['managed'] && can('managed')): ?>
  <div class="alert"><strong><?= $ac['managed'] ?> new account-management enquir<?= $ac['managed'] === 1 ? 'y' : 'ies' ?>.</strong> <a href="<?= e(url('admin/managed.php')) ?>" style="text-decoration:underline">Open them</a></div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><b><?= $users ?></b><span>members (<?= $new7 ?> new this week)</span></div>
  <div class="stat"><b><?= $vipNow ?></b><span>active VIP members</span></div>
  <?php if (can('payments')): ?>
  <div class="stat"><b style="font-size:26px"><?= e(money_line($revToday)) ?></b><span>received today</span></div>
  <div class="stat"><b style="font-size:26px"><?= e(money_line($rev30)) ?></b><span>received, last 30 days</span></div>
  <?php endif; ?>
  <div class="stat"><b><?= $tipsToday ?></b><span>tips today</span></div>
  <div class="stat"><b><?= $stats['win_rate'] !== null ? (int)$stats['win_rate'] . '%' : '-' ?></b><span>win rate, 30 days (<?= (int)$stats['settled'] ?> tips)</span></div>
</div>

<?php if (can('payments')): ?>
<div class="stats">
  <div class="stat"><b><?= $conv30 !== null ? $conv30 . '%' : '-' ?></b><span>of new members (30 days) have paid (<?= $paid30 ?> of <?= $reg30 ?>)</span></div>
  <div class="stat"><b><?= $pendAll ?></b><span>payments pending right now</span></div>
</div>
<?php endif; ?>

<div class="charts">
  <?php if (can('payments')): ?><div class="panel"><h2>Revenue per day (KES)</h2>
    <p class="sub" style="margin-bottom:8px">Other currencies are converted with the rates in Admin &gt; Currencies, so this is an estimate.</p>
    <?= admin_bar_chart('Revenue per day, last 30 days', 'Paid payments per day in Kenya shillings', $labels30, array_values($revByDay), ' KES', 0) ?></div><?php endif; ?>
  <div class="panel"><h2>New members per day</h2>
    <?= admin_bar_chart('New members per day, last 30 days', 'Members who registered each day', $labels30, array_values($memByDay), '', 0) ?></div>
  <div class="panel"><h2>Win rate per day</h2>
    <p class="sub" style="margin-bottom:8px">Share of settled tips that won, by kickoff day. Days without settled tips are left blank.</p>
    <?= admin_line_chart('Win rate per day, last 30 days', 'Percentage of settled tips that won each day', $labels30, array_values($winByDay), '%', 100.0, 0) ?></div>
  <?php if (can('payments')): ?><div class="panel"><h2>Top plans, last 30 days (KES)</h2>
    <?php if ($planRev): ?>
      <table class="tbl"><thead><tr><th>Plan</th><th>Revenue</th></tr></thead><tbody>
      <?php foreach (array_slice($planRev, 0, 6, true) as $pn => $pv): ?><tr><td><?= e($pn) ?></td><td>KES <?= e(number_format($pv, 0)) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php else: ?><p style="color:var(--mute)">No paid plans in the last 30 days.</p><?php endif; ?></div><?php endif; ?>
</div>

<?php if (can('tips')): ?>
<div class="panel">
  <div class="toolbar"><h2 style="margin:0">Waiting for a result</h2><a class="btn dark tiny" href="<?= e(url('admin/tips.php?view=needs-result')) ?>">See all</a></div>
  <?php if ($needList): ?>
    <div class="scroll"><table class="tbl">
      <thead><tr><th>Kickoff</th><th>Match</th><th>Pick</th><th>Result</th></tr></thead>
      <tbody>
      <?php foreach ($needList as $t): ?>
        <tr>
          <td><?= e(date('j M, H:i', strtotime($t['kickoff_at']))) ?></td>
          <td><?= e($t['home_team']) ?> vs <?= e($t['away_team']) ?></td>
          <td><?= e($t['pick']) ?> @ <?= e(number_format((float)$t['odds'], 2)) ?><?= $t['is_vip'] ? ' <span class="vipflag">VIP</span>' : '' ?></td>
          <td class="acts">
            <form class="inl" method="post" action="<?= e(url('admin/tip-settle.php')) ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="back" value="admin/index.php">
              <button class="btn tiny win" name="result" value="won" type="submit">Won</button>
              <button class="btn tiny loss" name="result" value="lost" type="submit">Lost</button>
              <button class="btn tiny void" name="result" value="void" type="submit">Void</button>
              <button class="btn tiny void" name="result" value="push" type="submit">Push</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php else: ?>
    <p style="color:var(--mute)">Nothing is waiting. All started matches have a result.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="split">
  <?php if (can('payments')): ?><div class="panel">
    <h2>Latest payments</h2>
    <?php if ($payments): ?>
      <div class="scroll"><table class="tbl">
        <thead><tr><th>When</th><th>Member</th><th>Plan</th><th>Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $r):
            $cls = ['paid' => 'won', 'pending' => 'pending', 'failed' => 'lost', 'cancelled' => 'lost'][$r['status']] ?? 'pending'; ?>
          <tr>
            <td><?= e(date('j M, H:i', strtotime($r['created_at']))) ?></td>
            <td><?= e($r['name']) ?><br><small><?= method_label($r['method']) ?></small></td>
            <td><?= e($r['plan_name']) ?></td>
            <td><?= e($r['currency']) ?> <?= e(number_format((float)$r['amount'], 2)) ?></td>
            <td><span class="badge <?= $cls ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?><p style="color:var(--mute)">No payments yet.</p><?php endif; ?>
  </div><?php endif; ?>
  <?php if (can('users_view')): ?><div class="panel">
    <h2>New members</h2>
    <?php if ($signups): ?>
      <table class="tbl">
        <thead><tr><th>Name</th><th>Email</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($signups as $s): ?>
          <tr><td><?= e($s['name']) ?></td><td><?= e($s['email']) ?></td><td><?= e(date('j M', strtotime($s['created_at']))) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php else: ?><p style="color:var(--mute)">No members yet.</p><?php endif; ?>
  </div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
