<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('plans');
$pdo = db();
$errors = [];

/** Reads and checks the plan form. Returns [values, errors]. */
function read_plan_form(): array
{
    $v = [
        'name' => post_str('name'), 'price_kes' => post_str('price_kes'), 'price_usd' => post_str('price_usd'),
        'duration_days' => post_str('duration_days'), 'features' => trim((string)($_POST['features'] ?? '')),
        'group_seats' => max(1, min(20, (int)post_str('group_seats'))), 'sort_order' => post_str('sort_order'), 'is_featured' => !empty($_POST['is_featured']) ? 1 : 0, 'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ];
    $er = [];
    if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 60) $er[] = 'Plan name must be 2 to 60 characters.';
    if (!ctype_digit($v['price_kes']) || (int)$v['price_kes'] < 1 || (int)$v['price_kes'] > 1000000) $er[] = 'The KES price must be a whole number of shillings (1 to 1,000,000).';
    if (!is_numeric($v['price_usd']) || (float)$v['price_usd'] < 0 || (float)$v['price_usd'] > 10000) $er[] = 'The USD price must be 0 or more. Use 0 to hide PayPal for this plan.';
    if (!ctype_digit($v['duration_days']) || (int)$v['duration_days'] < 1 || (int)$v['duration_days'] > 3650) $er[] = 'Length must be 1 to 3650 days.';
    if ($v['features'] === '' || mb_strlen($v['features']) > 1000) $er[] = 'List the features, one per line (up to 1000 characters).';
    if ($v['sort_order'] !== '' && !preg_match('/^-?\d{1,4}$/', $v['sort_order'])) $er[] = 'Order must be a small whole number.';
    return [$v, $er];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        [$v, $er] = read_plan_form();
        if ($er) {
            $errors = $er;
        } elseif ($action === 'add') {
            $slug = unique_slug('plans', slugify($v['name']));
            $pdo->prepare('INSERT INTO plans (name, slug, price_kes, price_usd, duration_days, features, is_featured, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$v['name'], $slug, (int)$v['price_kes'], number_format((float)$v['price_usd'], 2, '.', ''), (int)$v['duration_days'], $v['features'], $v['is_featured'], $v['is_active'], (int)$v['sort_order']]);
            if (paytools_ready()) { $pdo->prepare('UPDATE plans SET group_seats = ? WHERE id = ?')->execute([$v['group_seats'], (int)$pdo->lastInsertId()]); }
            admin_log('plan.add', 'plan#' . $pdo->lastInsertId(), $v['name'] . ' KES ' . $v['price_kes']);
            set_flash('success', 'Plan added.');
            redirect('admin/plans.php');
        } elseif ($action === 'save') {
            $pid = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM plans WHERE id = ?');
            $st->execute([$pid]);
            if ($old = $st->fetch()) {
                $pdo->prepare('UPDATE plans SET name=?, price_kes=?, price_usd=?, duration_days=?, features=?, is_featured=?, is_active=?, sort_order=? WHERE id=?')
                    ->execute([$v['name'], (int)$v['price_kes'], number_format((float)$v['price_usd'], 2, '.', ''), (int)$v['duration_days'], $v['features'], $v['is_featured'], $v['is_active'], (int)$v['sort_order'], $pid]);
                if (paytools_ready()) { $pdo->prepare('UPDATE plans SET group_seats = ? WHERE id = ?')->execute([$v['group_seats'], $pid]); }
                admin_log('plan.edit', 'plan#' . $pid, 'KES ' . $old['price_kes'] . ' -> ' . $v['price_kes'] . ', USD ' . $old['price_usd'] . ' -> ' . $v['price_usd'] . ', days ' . $old['duration_days'] . ' -> ' . $v['duration_days']);
                set_flash('success', 'Plan "' . $v['name'] . '" saved.');
                redirect('admin/plans.php');
            }
        }
    }
}
$plans = $pdo->query('SELECT * FROM plans WHERE tipster_id IS NULL ORDER BY sort_order, id')->fetchAll();

function plan_fields(array $p, string $pfx): void
{ ?>
  <div class="formgrid">
    <div class="field"><label for="<?= $pfx ?>name">Name</label><input type="text" id="<?= $pfx ?>name" name="name" value="<?= e($p['name']) ?>" maxlength="60" required></div>
    <div class="field"><label for="<?= $pfx ?>days">Length (days)</label><input type="number" id="<?= $pfx ?>days" name="duration_days" value="<?= e($p['duration_days']) ?>" min="1" max="3650" required></div>
    <div class="field"><label for="<?= $pfx ?>kes">Price in KES</label><input type="number" id="<?= $pfx ?>kes" name="price_kes" value="<?= e($p['price_kes']) ?>" min="1" step="1" required></div>
    <div class="field"><label for="<?= $pfx ?>usd">Price in USD (PayPal)</label><input type="number" id="<?= $pfx ?>usd" name="price_usd" value="<?= e($p['price_usd']) ?>" min="0" step="0.01" required><div class="hint">0 = no PayPal for this plan.</div></div>
    <div class="field full"><label for="<?= $pfx ?>feat">Features (one per line)</label><textarea id="<?= $pfx ?>feat" name="features" maxlength="1000" required><?= e($p['features']) ?></textarea></div>
    <div class="field"><label for="<?= $pfx ?>seats">Group plan: people covered (1 = normal)</label><input type="number" id="<?= $pfx ?>seats" name="group_seats" value="<?= (int)($p['group_seats'] ?? 1) ?>" min="1" max="20"></div>
    <div class="field"><label for="<?= $pfx ?>sort">Order on the page</label><input type="number" id="<?= $pfx ?>sort" name="sort_order" value="<?= e($p['sort_order']) ?>"></div>
    <div class="field check"><label><input type="checkbox" name="is_featured" value="1"<?= $p['is_featured'] ? ' checked' : '' ?>> Highlight as "Most chosen"</label>
      <label><input type="checkbox" name="is_active" value="1"<?= $p['is_active'] ? ' checked' : '' ?>> Available to buy</label></div>
  </div>
<?php }

$admin_title = 'Plans and prices';
$admin_active = 'plans';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Plans and prices</h1>
<p class="sub">Price changes apply to new payments only. Payments already started keep the price they were created with.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php foreach ($plans as $p): ?>
<div class="panel">
  <h2><?= e($p['name']) ?> <?= $p['is_active'] ? '' : '<span class="badge pending">Hidden</span>' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <?php plan_fields($p, 'p' . (int)$p['id'] . '_'); ?>
    <button class="btn gold tiny" type="submit">Save plan</button>
  </form>
</div>
<?php endforeach; ?>

<div class="panel">
  <h2>Add a plan</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <?php plan_fields(['name' => '', 'duration_days' => 30, 'price_kes' => '', 'price_usd' => '0.00', 'features' => "All VIP tips\nResults archive", 'sort_order' => 10, 'is_featured' => 0, 'is_active' => 1], 'new_'); ?>
    <button class="btn dark tiny" type="submit">Add plan</button>
  </form>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
