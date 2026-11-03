<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('payments');
$pdo = db();
$days = in_array((int)($_GET['d'] ?? 30), [14, 30, 90], true) ? (int)$_GET['d'] : 30;

function an_one(PDO $pdo, string $sql, array $p = []) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function an_series(PDO $pdo, string $sql, int $days): array
{
    $st = $pdo->prepare($sql);
    $st->execute([$days]);
    $by = [];
    foreach ($st->fetchAll() as $r) { $by[$r['d']] = (float)$r['v']; }
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $out[] = ['label' => date('j M', strtotime($d)), 'units' => $by[$d] ?? 0.0, 'n' => 1];
    }
    return $out;
}
// "Plan value in KES" = the KES list price of each paid plan, so M-Pesa, PayPal, Paystack and Razorpay sales add up in one currency.
$rev = an_series($pdo, "SELECT DATE(y.created_at) AS d, SUM(p.price_kes) AS v FROM payments y JOIN plans p ON p.id = y.plan_id WHERE y.status = 'paid' AND y.created_at >= CURDATE() - INTERVAL ? DAY GROUP BY d", $days);
$sig = an_series($pdo, "SELECT DATE(created_at) AS d, COUNT(*) AS v FROM users WHERE created_at >= CURDATE() - INTERVAL ? DAY GROUP BY d", $days);
$totalRev = array_sum(array_column($rev, 'units'));
$signups = (int)array_sum(array_column($sig, 'units'));
$cohort = (int)an_one($pdo, "SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL $days DAY");
$cohortPaid = (int)an_one($pdo, "SELECT COUNT(DISTINCT u.id) FROM users u JOIN payments y ON y.user_id = u.id AND y.status = 'paid' WHERE u.created_at >= NOW() - INTERVAL $days DAY");
$activeVip = (int)an_one($pdo, "SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE status = 'active' AND ends_at > NOW()");
$ended = (int)an_one($pdo, "SELECT COUNT(DISTINCT s.user_id) FROM subscriptions s WHERE s.ends_at BETWEEN NOW() - INTERVAL $days DAY AND NOW()
        AND NOT EXISTS (SELECT 1 FROM subscriptions n WHERE n.user_id = s.user_id AND n.ends_at > NOW())");
$endedAll = (int)an_one($pdo, "SELECT COUNT(DISTINCT user_id) FROM subscriptions WHERE ends_at BETWEEN NOW() - INTERVAL $days DAY AND NOW()");
$topPlans = $pdo->query("SELECT p.name, COUNT(*) AS n, SUM(p.price_kes) AS v FROM payments y JOIN plans p ON p.id = y.plan_id WHERE y.status = 'paid' AND y.created_at >= NOW() - INTERVAL $days DAY GROUP BY p.id, p.name ORDER BY v DESC LIMIT 8")->fetchAll();
$methods = $pdo->query("SELECT y.method, COUNT(*) AS n FROM payments y WHERE y.status = 'paid' AND y.created_at >= NOW() - INTERVAL $days DAY GROUP BY y.method ORDER BY n DESC")->fetchAll();
$tipSales = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(gross_kes),0) AS g, COALESCE(SUM(commission_kes),0) AS c FROM tipster_earnings WHERE status = 'ok' AND created_at >= NOW() - INTERVAL $days DAY")->fetch();
$picks = (int)an_one($pdo, "SELECT COUNT(*) FROM game_picks WHERE created_at >= NOW() - INTERVAL $days DAY");
$players = (int)an_one($pdo, "SELECT COUNT(DISTINCT user_id) FROM game_picks WHERE created_at >= NOW() - INTERVAL $days DAY");
$clicks = 0;
try { $clicks = (int)an_one($pdo, "SELECT COUNT(*) FROM bookmaker_clicks WHERE created_at >= NOW() - INTERVAL $days DAY"); } catch (Throwable $e) {}
$pct = function ($a, $b) { return $b > 0 ? number_format($a / $b * 100, 1) . '%' : '-'; };

$admin_title = 'Analytics';
$admin_active = 'analytics';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px"><h2>Last <?= $days ?> days</h2>
  <div><?php foreach ([14, 30, 90] as $d): ?><a class="btn tiny<?= $d === $days ? ' dark' : '' ?>" href="?d=<?= $d ?>"><?= $d ?> days</a> <?php endforeach; ?></div></div>
  <div class="gm-stats">
    <div><small>Sales (plan value, KES)</small><b><?= number_format($totalRev) ?></b></div>
    <div><small>New members</small><b><?= number_format($signups) ?></b></div>
    <div><small>New members who paid</small><b><?= $pct($cohortPaid, $cohort) ?></b></div>
    <div><small>Active VIPs now</small><b><?= number_format($activeVip) ?></b></div>
    <div><small>Lapsed (no active plan)</small><b><?= $pct($ended, $endedAll) ?></b></div>
    <div><small>Bookmaker clicks</small><b><?= number_format($clicks) ?></b></div>
  </div>
  <p style="color:var(--mute);font-size:13px">Sales use each plan's KES list price so every payment method adds up in one currency. Coupon discounts and currency conversion are not subtracted, so treat this as an estimate. Your payment provider's report is the exact figure.</p></div>
<div class="panel"><h2>Sales per day</h2><?= tipster_chart_html($rev) ?></div>
<div class="panel"><h2>New members per day</h2><?= tipster_chart_html($sig) ?></div>
<div class="two" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">
  <div class="panel"><h2>Top plans</h2><?php if (!$topPlans): ?><p>No sales yet.</p><?php else: ?><table class="tbl"><thead><tr><th>Plan</th><th>Sold</th><th>Value KES</th></tr></thead><tbody>
    <?php foreach ($topPlans as $p): ?><tr><td><?= e($p['name']) ?></td><td><?= (int)$p['n'] ?></td><td><?= number_format((float)$p['v']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="panel"><h2>Payment methods</h2><?php if (!$methods): ?><p>No sales yet.</p><?php else: ?><table class="tbl"><tbody>
    <?php foreach ($methods as $m): ?><tr><td><?= e(method_label($m['method'])) ?></td><td><?= (int)$m['n'] ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?></div>
  <div class="panel"><h2>Tipster marketplace</h2><p><?= (int)$tipSales['n'] ?> plans sold, KES <?= number_format((float)$tipSales['g']) ?> gross, KES <?= number_format((float)$tipSales['c']) ?> commission to you.</p></div>
  <div class="panel"><h2>Prediction game</h2><p><?= number_format($picks) ?> picks by <?= number_format($players) ?> players.</p></div>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
