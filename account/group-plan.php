<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    [$ok, $m] = group_redeem($uid, (string)($_POST['code'] ?? ''));
    set_flash($ok ? 'success' : 'error', $m);
    redirect('account/group-plan.php');
}
$mine = [];
if (paytools_ready()) {
    $st = db()->prepare('SELECT g.code, g.used_by, g.used_at, p.name AS plan FROM group_codes g JOIN plans p ON p.id = g.plan_id WHERE g.owner_id = ? ORDER BY g.id DESC LIMIT 60');
    $st->execute([$uid]);
    $mine = $st->fetchAll();
}
$page_title = 'Group plan - ' . SITE_NAME;
$active = '';
$acct = 'groupplan';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Group plan</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <div class="two">
    <form method="post" class="box2"><?= csrf_field() ?>
      <h3>Got a code from a friend?</h3>
      <div class="field"><label for="code">Group code</label><input type="text" id="code" name="code" maxlength="16" autocomplete="off" required></div>
      <button class="btn gold" type="submit">Use code</button>
    </form>
    <div class="box2">
      <h3>Your codes</h3>
      <?php if (!$mine): ?><p style="color:var(--mute)">Buy a group plan on the <a href="<?= e(url('vip.php')) ?>">VIP page</a> and your invite codes appear here.</p>
      <?php else: foreach ($mine as $g): ?>
        <div class="al-row"><b style="font-family:monospace;letter-spacing:1px"><?= e($g['code']) ?></b> <small><?= e($g['plan']) ?> - <?= $g['used_by'] ? 'used ' . e(date('j M', strtotime($g['used_at']))) : 'not used yet' ?></small></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
