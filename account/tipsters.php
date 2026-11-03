<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$u = current_user();
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('account/tipsters.php'); }
    $q = $pdo->prepare('SELECT * FROM tipster_subscriptions WHERE id = ? AND user_id = ?');
    $q->execute([(int)($_POST['sub'] ?? 0), $u['id']]);
    $sub = $q->fetch();
    $chk = $sub ? guarantee_check($sub) : ['ok' => false, 'reason' => 'Plan not found.'];
    if (!$chk['ok']) { set_flash('error', $chk['reason']); redirect('account/tipsters.php'); }
    $q = $pdo->prepare("SELECT gross_kes FROM tipster_earnings WHERE payment_id = ? AND status = 'ok'");
    $q->execute([$sub['payment_id']]);
    $gross = (float)$q->fetchColumn();
    try {
        $pdo->prepare('INSERT INTO tipster_claims (user_id, tipster_id, subscription_id, payment_id, amount_kes, units) VALUES (?,?,?,?,?,?)')
            ->execute([$u['id'], $sub['tipster_id'], $sub['id'], $sub['payment_id'], $gross, $chk['units']]);
        set_flash('success', 'Claim sent. We will check it and tell you here and in Notifications.');
    } catch (Throwable $e) { set_flash('error', 'You already sent a claim for this plan.'); }
    redirect('account/tipsters.php');
}
$st = $pdo->prepare('SELECT s.id, s.id AS sid, s.tipster_id, s.payment_id, s.starts_at, s.ends_at, p.name AS plan_name, ti.display_name, ti.slug, ti.status, ti.guarantee AS guar
                     FROM tipster_subscriptions s JOIN tipsters ti ON ti.id = s.tipster_id JOIN plans p ON p.id = s.plan_id
                     WHERE s.user_id = ? ORDER BY s.ends_at DESC LIMIT 100');
$st->execute([$u['id']]);
$rows = $st->fetchAll();
$claims = [];
$q = $pdo->prepare('SELECT subscription_id, status, note FROM tipster_claims WHERE user_id = ?');
$q->execute([$u['id']]);
foreach ($q->fetchAll() as $c) { $claims[(int)$c['subscription_id']] = $c; }
// trials running
$q = $pdo->prepare('SELECT tt.ends_at, ti.display_name, ti.slug FROM tipster_trials tt JOIN tipsters ti ON ti.id = tt.tipster_id WHERE tt.user_id = ? AND tt.ends_at > NOW()');
$q->execute([$u['id']]);
$trials = $q->fetchAll();
$page_title = 'My tipsters - ' . SITE_NAME;
$active = '';
$acct = 'tipsters';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2>My tipsters</h2><p>Tipsters you have subscribed to</p></div></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <?php foreach ($trials as $tr): ?><div class="note" style="margin-top:0">Free trial with <a href="<?= e(url('tipster.php?slug=' . rawurlencode($tr['slug']))) ?>"><?= e($tr['display_name']) ?></a> until <?= e(date('j M, H:i', strtotime($tr['ends_at']))) ?>.</div><?php endforeach; ?>
  <?php if ($rows): ?><div class="panel"><div class="scroll"><table class="tbl"><thead><tr><th>Tipster</th><th>Plan</th><th>Access until</th><th></th><th>Money-back</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $on = strtotime($r['ends_at']) > time(); ?>
    <tr><td><a href="<?= e(url('tipster.php?slug=' . rawurlencode($r['slug']))) ?>" style="text-decoration:underline"><?= e($r['display_name']) ?></a></td><td><?= e($r['plan_name']) ?></td>
      <td><?= e(date('j M Y, H:i', strtotime($r['ends_at']))) ?> <span class="badge <?= $on ? 'won' : 'lost' ?>"><?= $on ? 'Active' : 'Ended' ?></span></td>
      <td><a class="btn tiny outline" href="<?= e(url('tipster.php?slug=' . rawurlencode($r['slug']) . '#reviews')) ?>">Rate / renew</a></td>
      <td><?php $cl = $claims[(int)$r['sid']] ?? null; if ($cl): ?><span class="badge <?= $cl['status'] === 'approved' ? 'won' : ($cl['status'] === 'rejected' ? 'lost' : '') ?>">Claim <?= e($cl['status']) ?></span><?= $cl['note'] ? '<br><small>' . e($cl['note']) . '</small>' : '' ?>
        <?php else: $ck = guarantee_check($r); if ($ck['ok']): ?><form method="post" onsubmit="return confirm('Send a money-back claim for this plan?');"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="sub" value="<?= (int)$r['sid'] ?>"><button class="btn tiny gold" type="submit">Claim refund</button></form>
        <?php elseif (!empty($r['guar'])): ?><small><?= e($ck['reason']) ?></small><?php else: ?><small>-</small><?php endif; endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
  <?php else: ?><div class="note">You have not subscribed to any tipster yet. <a href="<?= e(url('tipsters.php')) ?>" style="text-decoration:underline">Browse tipsters</a></div><?php endif; ?>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
