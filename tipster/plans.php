<?php
require_once dirname(__DIR__) . '/includes/init.php';
$t = require_tipster(false);
$pdo = db();
const TIPSTER_MAX_PLANS = 4;
$isActive = $t['status'] === 'active';
$errors = [];
$f = ['label' => '', 'price' => '', 'days' => '30', 'features' => ''];
$commission = tipster_commission($t);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif (!$isActive) { $errors[] = 'You can create plans once your profile is approved.'; }
    elseif ($action === 'create') {
        $f['label'] = post_str('label'); $f['price'] = post_str('price'); $f['days'] = post_str('days');
        $f['features'] = trim((string)($_POST['features'] ?? ''));
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE tipster_id = ?'); $cnt->execute([$t['id']]);
        if ((int)$cnt->fetchColumn() >= TIPSTER_MAX_PLANS) $errors[] = 'You can have up to ' . TIPSTER_MAX_PLANS . ' plans. Switch one off or edit it instead.';
        if (mb_strlen($f['label']) < 2 || mb_strlen($f['label']) > 20) $errors[] = 'Plan label must be 2 to 20 characters (for example Weekly).';
        if (!ctype_digit($f['price']) || (int)$f['price'] < 100 || (int)$f['price'] > 100000) $errors[] = 'Price must be a whole number of KES from 100 to 100,000.';
        if (!ctype_digit($f['days']) || (int)$f['days'] < 1 || (int)$f['days'] > 365) $errors[] = 'Duration must be 1 to 365 days.';
        if (mb_strlen($f['features']) > 600) $errors[] = 'Description is too long (600 characters).';
        if (!$errors) {
            $name = mb_substr($t['display_name'], 0, 36) . ' - ' . $f['label'];
            $slug = 'tp' . (int)$t['id'] . '-' . slugify($f['label']) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $pdo->prepare('INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order, tipster_id) VALUES (?,?,?,?,?,?,0,1,0,?)')
                ->execute([mb_substr($name, 0, 60), $slug, (int)$f['price'], tipster_usd_for_kes((int)$f['price']), (int)$f['days'], $f['features'], $t['id']]);
            set_flash('success', 'Plan created. It is on your public profile now.');
            redirect('tipster/plans.php');
        }
    } elseif ($action === 'perks') {
        $trial = max(0, min(3, (int)($_POST['trial_days'] ?? 0)));
        $guar = !empty($_POST['guarantee']) ? 1 : 0;
        if (!empty($t['guarantee']) && !$guar) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM tipster_subscriptions WHERE tipster_id = ? AND ends_at > NOW() - INTERVAL 7 DAY");
            $q->execute([$t['id']]);
            if ((int)$q->fetchColumn() > 0) { $guar = 1; set_flash('info', 'The guarantee stays on while members who bought it can still claim (until 7 days after their plans end).'); }
        }
        $pdo->prepare('UPDATE tipsters SET trial_days = ?, guarantee = ? WHERE id = ?')->execute([$trial, $guar, $t['id']]);
        set_flash('success', 'Free trial and guarantee settings saved.');
        redirect('tipster/plans.php');
    } elseif ($action === 'toggle') {
        $pdo->prepare('UPDATE plans SET is_active = 1 - is_active WHERE id = ? AND tipster_id = ?')->execute([(int)($_POST['id'] ?? 0), $t['id']]);
        set_flash('success', 'Plan updated. Members who already paid keep their access.');
        redirect('tipster/plans.php');
    }
}
$st = $pdo->prepare('SELECT * FROM plans WHERE tipster_id = ? ORDER BY id DESC');
$st->execute([$t['id']]);
$plans = $st->fetchAll();

$page_title = 'My plans - ' . SITE_NAME;
$active = 'tipster-plans';
$tnav = 'plans';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2>My plans</h2><p>Members subscribe to your plans. You earn the price minus the site's <?= e(rtrim(rtrim(number_format($commission, 2), '0'), '.')) ?>% commission (the rate at the time of each sale).</p></div></div>
  <?php include dirname(__DIR__) . '/includes/tipster-nav.php'; ?>
  <?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

  <?php if ($plans): ?>
  <div class="pl-grid">
    <?php foreach ($plans as $p): $net = $p['price_kes'] * (1 - $commission / 100); ?>
    <div class="pl-card<?= $p['is_active'] ? '' : ' pl-off' ?>">
      <span class="pl-state <?= $p['is_active'] ? 'on' : 'off' ?>"><?= $p['is_active'] ? 'On sale' : 'Switched off' ?></span>
      <div class="pl-top"><h4><?= e(tipster_plan_label($p)) ?></h4><span class="pl-days"><?= (int)$p['duration_days'] ?> days</span></div>
      <div class="pl-price"><span class="pl-cur">KES</span><span class="pl-amt"><?= e(number_format((float)$p['price_kes'], 0)) ?></span></div>
      <div class="pl-earn">You earn <strong>KES <?= e(number_format($net, 0)) ?></strong> per sale</div>
      <?php if (trim((string)$p['features']) !== ''): ?><ul class="pl-feat"><?php foreach (array_slice(preg_split('/\r?\n/', trim($p['features'])), 0, 6) as $ft): if (trim($ft) === '') continue; ?><li><?= e(trim($ft)) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?php if ($isActive): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn outline pl-btn" type="submit"><?= $p['is_active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="pl-note">Prices cannot be edited after creation, so members always pay what they saw. To change a price, switch the plan off and create a new one.</p>
  <?php else: ?><div class="note">You have no plans yet. Create your first plan below.</div><?php endif; ?>

  <?php if ($isActive): ?>
  <form method="post" class="pl-form"><?= csrf_field() ?><input type="hidden" name="action" value="perks">
    <h3>Free trial and money-back guarantee</h3>
    <div class="field"><label for="trial_days">Free trial for new members (days, 0 = off)</label><input id="trial_days" name="trial_days" type="number" min="0" max="3" value="<?= (int)($t['trial_days'] ?? 0) ?>">
      <small>New members can see your premium tips for this many days, once each. Good for winning trust. You earn nothing from trial days.</small></div>
    <label class="al-row"><input type="checkbox" name="guarantee" value="1"<?= !empty($t['guarantee']) ? ' checked' : '' ?>> <span><b>Offer the money-back guarantee</b><small>If you lose <?= e(rtrim(rtrim(number_format((float)setting('tipster_guarantee_units', '5'), 1), '0'), '.')) ?> units or more (at least 5 settled premium tips) during a member's paid period, they can claim a refund up to 7 days after their plan ends. If approved, that sale's earning is removed from your balance. It shows a badge on your profile.</small></span></label>
    <button class="btn dark" type="submit">Save</button>
  </form>
  <form method="post" class="pl-form"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h3>Create a new plan</h3>
    <div class="pl-fields">
      <div class="field"><label for="label">Plan name</label><input id="label" name="label" maxlength="20" required value="<?= e($f['label']) ?>" placeholder="Weekly"></div>
      <div class="field"><label for="price">Price (KES)</label><input id="price" name="price" type="number" min="100" max="100000" required value="<?= e($f['price']) ?>" placeholder="500"></div>
      <div class="field"><label for="days">Length (days)</label><input id="days" name="days" type="number" min="1" max="365" required value="<?= e($f['days']) ?>"></div>
    </div>
    <div class="field"><label for="features">What members get (one point per line)</label><textarea id="features" name="features" rows="4" maxlength="600" placeholder="3 to 5 premium tips a day&#10;Reasoning for each pick"><?= e($f['features']) ?></textarea></div>
    <div class="pl-calc" id="calc" data-rate="<?= e((string)$commission) ?>">Members pay <b id="c-price">KES 0</b>. The site keeps <b><?= e(rtrim(rtrim(number_format($commission, 2), '0'), '.')) ?>%</b>. <span class="pl-calc-net">You earn <b id="c-net">KES 0</b> per sale.</span></div>
    <button class="btn gold" type="submit">Create plan</button>
  </form>
  <script>
  (function(){
    var c = document.getElementById('calc'); if (!c) return;
    var rate = parseFloat(c.getAttribute('data-rate')) || 0, price = document.getElementById('price');
    function fmt(n){ return 'KES ' + Math.round(n).toLocaleString('en-US'); }
    function upd(){ var p = parseFloat(price.value) || 0; document.getElementById('c-price').textContent = fmt(p); document.getElementById('c-net').textContent = fmt(p * (1 - rate / 100)); }
    price.addEventListener('input', upd); upd();
  })();
  </script>
  <?php endif; ?>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
