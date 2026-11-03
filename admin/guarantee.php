<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/guarantee.php';
require_perm('guarantee');
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $days = post_str('guarantee_days');
        $min  = post_str('guarantee_min_tips');
        if (!ctype_digit($days) || (int)$days < 1 || (int)$days > 30) $errors[] = 'Free days must be 1 to 30.';
        if (!ctype_digit($min) || (int)$min < 1 || (int)$min > 50) $errors[] = 'Minimum tips must be 1 to 50.';
        if (!$errors) {
            set_setting('guarantee_enabled', !empty($_POST['guarantee_enabled']) ? '1' : '0');
            set_setting('guarantee_days', (string)(int)$days);
            set_setting('guarantee_min_tips', (string)(int)$min);
            admin_log('guarantee.save', '', 'enabled=' . (!empty($_POST['guarantee_enabled']) ? 1 : 0) . ' days=' . (int)$days . ' min=' . (int)$min);
            set_flash('success', 'Guarantee settings saved.');
            redirect('admin/guarantee.php');
        }
    }
}

sk_ensure_table('guarantee_log', 'database-update-6.sql');
$enabled = setting('guarantee_enabled', '0') === '1';
$days    = (int)setting('guarantee_days', '1');
$min     = (int)setting('guarantee_min_tips', '3');

$log = [];
$tableMissing = false;
try {
    $log = $pdo->query('SELECT * FROM guarantee_log ORDER BY day DESC LIMIT 30')->fetchAll();
} catch (Throwable $ex) {
    $tableMissing = true;
}

// Plain-English cost estimate
$est = null;
if (!$tableMissing) {
    try {
        $members = count(guarantee_member_ids($pdo, date('Y-m-d')));
        $rows = $pdo->query("SELECT DATE(m.kickoff_at) AS d, t.status FROM tips t JOIN matches m ON m.id = t.match_id
                             WHERE t.is_vip = 1 AND t.status IN ('won','lost','void') AND m.kickoff_at >= CURDATE() - INTERVAL 90 DAY AND m.kickoff_at < CURDATE()")->fetchAll();
        $byDay = [];
        foreach ($rows as $r) $byDay[$r['d']][] = $r['status'];
        $hits = 0;
        foreach ($byDay as $st) {
            if (guarantee_evaluate($st, max(1, $min))['applies']) $hits++;
        }
        $perDay = $pdo->query("SELECT AVG(p.price_kes / p.duration_days) FROM subscriptions s JOIN plans p ON p.id = s.plan_id
                               WHERE s.status = 'active' AND s.ends_at > NOW() AND p.slug NOT IN ('trial','bonus')")->fetchColumn();
        $est = ['members' => $members, 'hits' => $hits, 'perDay' => (float)$perDay, 'tipDays' => count($byDay)];
    } catch (Throwable $ex) {
        $est = null;
    }
}

$admin_title = 'Guarantee';
$admin_active = 'guarantee';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Bad-day guarantee</h1>
<p class="sub">If every VIP tip of a day loses, paying members get free VIP days automatically. It is checked once a day after midnight (East Africa time) for the previous day.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($tableMissing): ?><div class="errors" role="alert">The guarantee table does not exist yet. Import database-update-6.sql in phpMyAdmin first.</div><?php endif; ?>
<form method="post" action="<?= e(url('admin/guarantee.php')) ?>"><?= csrf_field() ?>
<div class="panel"><h2>Rule</h2>
  <div class="formgrid">
    <div class="field check"><label><input type="checkbox" name="guarantee_enabled" value="1"<?= $enabled ? ' checked' : '' ?>> Switch the guarantee on (also shows a short line on the site)</label></div>
    <div class="field"><label for="gd">Free VIP days given</label><input type="number" id="gd" name="guarantee_days" value="<?= e((string)$days) ?>" min="1" max="30"></div>
    <div class="field"><label for="gm">Minimum settled VIP tips that day</label><input type="number" id="gm" name="guarantee_min_tips" value="<?= e((string)$min) ?>" min="1" max="50">
      <div class="hint">The rule applies only when every settled VIP tip that day lost and there were at least this many. Void tips are ignored. Make sure yesterday's tips are marked won, lost or void.</div></div>
  </div>
  <p class="sub" style="margin-top:10px">Who gets the days: members with a paid plan at any point that day. Free trials and bonus days do not count. The days are added to the end of their current plan.</p>
</div>
<button class="btn gold" type="submit">Save</button>
</form>

<?php if ($est): ?>
<div class="panel" style="margin-top:22px"><h2>What it could cost</h2>
  <p>Right now <strong><?= (int)$est['members'] ?></strong> member<?= $est['members'] === 1 ? ' has' : 's have' ?> a paid plan. On a qualifying day each would get <strong><?= (int)$days ?></strong> free day<?= $days === 1 ? '' : 's' ?>,
  about <strong><?= (int)($est['members'] * $days) ?></strong> member-days in total<?php if ($est['perDay'] > 0): ?>, worth roughly <strong>KES <?= e(number_format($est['members'] * $days * $est['perDay'])) ?></strong> at your sale prices (not cash out of your pocket, but time you give away)<?php endif; ?>.</p>
  <p style="margin-top:8px">With today's rule, the last 90 days had <strong><?= (int)$est['hits'] ?></strong> qualifying day<?= $est['hits'] === 1 ? '' : 's' ?> out of <?= (int)$est['tipDays'] ?> day<?= $est['tipDays'] === 1 ? '' : 's' ?> with settled VIP tips. This is only a guide: past results do not predict future days, and a higher minimum makes the rule trigger less often.</p>
</div>
<?php endif; ?>

<div class="panel"><h2>Last 30 checks</h2>
<?php if ($log): ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Day</th><th>Settled VIP tips</th><th>Result</th><th>Days each</th><th>Members</th><th>Checked</th></tr></thead><tbody>
  <?php foreach ($log as $r): ?>
    <tr><td><?= e(date('D j M Y', strtotime($r['day']))) ?></td><td><?= (int)$r['tips_count'] ?></td>
      <td><?= (int)$r['days_granted'] > 0 ? 'Guarantee paid' : 'Not triggered' ?></td><td><?= (int)$r['days_granted'] ?></td><td><?= (int)$r['members_count'] ?></td>
      <td><small><?= e(date('j M, H:i', strtotime($r['created_at']))) ?></small></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php else: ?><div class="empty">Nothing checked yet. The daily job fills this list.</div><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
