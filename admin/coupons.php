<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('coupons');
$pdo = db();
$errors = [];
$f = ['code' => '', 'percent' => '', 'max_uses' => '', 'expires' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'add') {
        $f['code']     = strtoupper(post_str('code'));
        $f['percent']  = post_str('percent');
        $f['max_uses'] = post_str('max_uses');
        $f['expires']  = post_str('expires');

        if (!preg_match('/^[A-Z0-9\-]{3,30}$/', $f['code'])) $errors[] = 'The code must be 3 to 30 letters, numbers or hyphens, like WELCOME10.';
        if (!ctype_digit($f['percent']) || (int)$f['percent'] < 1 || (int)$f['percent'] > 90) $errors[] = 'Discount must be a whole number from 1 to 90. For a free plan, add VIP days from the member page instead.';
        if ($f['max_uses'] !== '' && (!ctype_digit($f['max_uses']) || (int)$f['max_uses'] < 1)) $errors[] = 'Maximum uses must be 1 or more, or empty for no limit.';
        $exp = null;
        if ($f['expires'] !== '') {
            $ts = strtotime($f['expires'] . ' 23:59:59');
            if (!$ts || $ts < time()) $errors[] = 'The expiry date must be today or later.';
            else $exp = date('Y-m-d H:i:s', $ts);
        }
        if (!$errors) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM coupons WHERE code = ?');
            $st->execute([$f['code']]);
            if ($st->fetchColumn()) {
                $errors[] = 'That code already exists.';
            } else {
                $pdo->prepare('INSERT INTO coupons (code, percent_off, max_uses, expires_at) VALUES (?, ?, ?, ?)')
                    ->execute([$f['code'], (int)$f['percent'], $f['max_uses'] === '' ? null : (int)$f['max_uses'], $exp]);
                admin_log('coupon.add', $f['code'], $f['percent'] . '% off');
                set_flash('success', 'Coupon ' . $f['code'] . ' created.');
                redirect('admin/coupons.php');
            }
        }
    } elseif ($action === 'toggle') {
        $cid = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?')->execute([$cid]);
        admin_log('coupon.toggle', 'coupon#' . $cid);
        redirect('admin/coupons.php');
    } elseif ($action === 'delete') {
        $cid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT code, used FROM coupons WHERE id = ?');
        $st->execute([$cid]);
        $c = $st->fetch();
        if ($c && (int)$c['used'] === 0) {
            $pdo->prepare('DELETE FROM coupons WHERE id = ?')->execute([$cid]);
            admin_log('coupon.delete', $c['code']);
            set_flash('success', 'Coupon deleted.');
        } else {
            set_flash('error', 'A coupon that has been used cannot be deleted. Switch it off instead.');
        }
        redirect('admin/coupons.php');
    }
}
$rows = $pdo->query('SELECT * FROM coupons ORDER BY id DESC LIMIT 200')->fetchAll();

$admin_title = 'Coupon codes';
$admin_active = 'coupons';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Coupon codes</h1>
<p class="sub">Members type a code on the checkout page. The discount applies to both M-Pesa and PayPal. Each member can use a code once.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="panel">
  <h2>Create a code</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="formgrid">
      <div class="field"><label for="code">Code</label><input type="text" id="code" name="code" value="<?= e($f['code']) ?>" maxlength="30" required placeholder="WELCOME10" style="text-transform:uppercase"></div>
      <div class="field"><label for="percent">Discount (%)</label><input type="number" id="percent" name="percent" value="<?= e($f['percent']) ?>" min="1" max="90" required></div>
      <div class="field"><label for="max_uses">Maximum uses (optional)</label><input type="number" id="max_uses" name="max_uses" value="<?= e($f['max_uses']) ?>" min="1"><div class="hint">Empty = unlimited.</div></div>
      <div class="field"><label for="expires">Last day it works (optional)</label><input type="date" id="expires" name="expires" value="<?= e($f['expires']) ?>" style="width:100%;padding:11px 12px;border:1.5px solid var(--line);border-radius:6px;font:16px 'Source Sans 3',sans-serif"></div>
    </div>
    <button class="btn gold tiny" type="submit">Create code</button>
  </form>
</div>

<div class="panel">
  <h2>Your codes</h2>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Code</th><th>Discount</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $expired = $c['expires_at'] && strtotime($c['expires_at']) < time(); $full = $c['max_uses'] !== null && (int)$c['used'] >= (int)$c['max_uses']; ?>
      <tr>
        <td><strong><?= e($c['code']) ?></strong></td>
        <td><?= (int)$c['percent_off'] ?>%</td>
        <td><?= (int)$c['used'] ?><?= $c['max_uses'] !== null ? ' / ' . (int)$c['max_uses'] : '' ?></td>
        <td><?= $c['expires_at'] ? e(date('j M Y', strtotime($c['expires_at']))) : 'never' ?></td>
        <td><span class="badge <?= ($c['is_active'] && !$expired && !$full) ? 'won' : 'pending' ?>"><?= !$c['is_active'] ? 'Off' : ($expired ? 'Expired' : ($full ? 'Fully used' : 'Working')) ?></span></td>
        <td class="acts">
          <form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn outline tiny" type="submit"><?= $c['is_active'] ? 'Switch off' : 'Switch on' ?></button></form>
          <?php if ((int)$c['used'] === 0): ?><form class="inl" method="post" onsubmit="return confirm('Delete this code?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn tiny loss" type="submit">Delete</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><p style="color:var(--mute)">No codes yet.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
