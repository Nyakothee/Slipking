<?php
require_once __DIR__ . '/includes/init.php';
$page_title = '2 Odds Streak Challenge - ' . SITE_NAME;
$active = '';
include __DIR__ . '/includes/header.php';
streak_card();
$rows = streak_ready() ? db()->query('SELECT * FROM streak_days ORDER BY day DESC LIMIT 40')->fetchAll() : [];
?>
<section style="padding-top:0"><div class="wrap"><h2>Streak history</h2>
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Day</th><th>Match</th><th>Pick</th><th>Odds</th><th>Stake</th><th>Result</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= e(date('j M Y', strtotime($r['day']))) ?></td><td><?= (int)$r['len'] ?></td><td><?= e($r['home'] . ' vs ' . $r['away']) ?></td><td><?= e($r['pick']) ?></td><td><?= e(number_format((float)$r['odds'], 2)) ?></td><td><?= e(streak_money((float)$r['stake'])) ?></td><td><span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><div class="empty">The challenge has not started yet.</div><?php endif; ?></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
