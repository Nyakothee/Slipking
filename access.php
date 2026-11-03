<?php
require_once __DIR__ . '/includes/init.php';
require_login();
if (!access_on()) { redirect('account/dashboard.php'); }
access_plan_id();
$u = current_user();
$s = access_status($u);
$a = access_amount($u);
$page_title = 'Weekly access - ' . SITE_NAME;
$active = '';
include __DIR__ . '/includes/header.php';
?>
<section><div class="wrap" style="max-width:560px"><div class="fcard" style="text-align:center">
  <h2>Weekly access</h2>
  <?php if ($s['state'] === 'due'): ?>
    <p class="sub">Your free week has ended. Pay for the next 7 days to keep using SlipKing.</p>
  <?php elseif ($s['state'] === 'paid'): ?>
    <p class="sub">You are all set. Your access is active until <b><?= e(date('j M Y, H:i', (int)$s['ends'])) ?></b> (<?= (int)$s['days'] ?> day<?= (int)$s['days'] === 1 ? '' : 's' ?> left).</p>
  <?php elseif ($s['state'] === 'free'): ?>
    <p class="sub">Your free week runs until <b><?= e(date('j M Y, H:i', (int)$s['ends'])) ?></b> (<?= (int)$s['days'] ?> day<?= (int)$s['days'] === 1 ? '' : 's' ?> left). After that it is <?= e($a['label']) ?>.</p>
  <?php else: ?>
    <p class="sub">Your access is included.</p>
  <?php endif; ?>
  <div style="margin:18px 0;padding:18px;border-radius:16px;background:#fff8e6;border:1px solid #f1d58a">
    <div style="font-size:34px;font-weight:800;color:#0d3b2c"><?= e($a['label']) ?></div>
    <div style="color:#5b6b64;margin-top:4px"><?= $a['local'] ? 'Kenya, Uganda and Tanzania price' : 'International price' ?>. While this is on, <b>every VIP tip and accumulator is free</b> for you.</div>
  </div>
  <?php if (in_array($s['state'], ['due', 'free', 'paid'], true)): ?><a class="btn gold full" href="<?= e(access_pay_url()) ?>"><?= $s['state'] === 'due' ? 'Pay now and continue' : 'Pay for the next 7 days' ?></a><?php endif; ?>
  <?php if ($s['state'] !== 'due'): ?><p style="margin-top:14px"><a href="<?= e(url('account/dashboard.php')) ?>">Back to my account</a></p>
  <?php else: ?><p style="margin-top:14px"><a href="<?= e(url('auth/logout.php')) ?>">Log out</a></p><?php endif; ?>
</div></div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
