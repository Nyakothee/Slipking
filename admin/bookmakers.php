<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/bookmakers.php';
require_perm('bookmakers');
$pdo = db();
sk_ensure_table('bookmakers', 'database-update-6.sql');
$errors = [];

/** Reads and checks the bookmaker form. Returns [values, errors]. */
function read_bookmaker_form(): array
{
    $v = [
        'name' => post_str('name'), 'affiliate_url' => post_str('affiliate_url'), 'logo_text' => post_str('logo_text'),
        'bonus_text' => post_str('bonus_text'), 'countries' => strtoupper(str_replace(' ', '', post_str('countries'))),
        'sort_order' => post_str('sort_order'), 'is_active' => !empty($_POST['is_active']) ? 1 : 0,
    ];
    $er = [];
    if (mb_strlen($v['name']) < 2 || mb_strlen($v['name']) > 60) $er[] = 'Name must be 2 to 60 characters.';
    if (!bookmaker_valid_url($v['affiliate_url'])) $er[] = 'The affiliate link must be a full http:// or https:// address (up to 500 characters, no spaces).';
    elseif ($v['is_active'] && bookmaker_is_placeholder($v['affiliate_url'])) $er[] = 'Replace the placeholder link with your real affiliate link before switching this bookmaker on.';
    if (mb_strlen($v['logo_text']) > 40) $er[] = 'Short logo text is up to 40 characters.';
    if (mb_strlen($v['bonus_text']) > 160) $er[] = 'Bonus text is up to 160 characters.';
    if ($v['countries'] !== '' && (strlen($v['countries']) > 80 || !preg_match('/^[A-Z]{2}(,[A-Z]{2})*$/', $v['countries']))) $er[] = 'Countries must be two-letter codes separated by commas, like KE,NG,IN (or empty for all).';
    if ($v['sort_order'] === '') $v['sort_order'] = '0';
    if (!preg_match('/^-?\d{1,4}$/', $v['sort_order'])) $er[] = 'Order must be a small whole number.';
    return [$v, $er];
}

function bookmaker_unique_slug(PDO $pdo, string $base, int $exceptId = 0): string
{
    $slug = $base;
    for ($i = 2; $i < 200; $i++) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM bookmakers WHERE slug = ? AND id <> ?');
        $st->execute([$slug, $exceptId]);
        if ((int)$st->fetchColumn() === 0) {
            return $slug;
        }
        $slug = substr($base, 0, 55) . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(2));
}

$editId = (int)($_GET['edit'] ?? 0);
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'toggle') {
        $bid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM bookmakers WHERE id = ?');
        $st->execute([$bid]);
        if ($b = $st->fetch()) {
            $new = $b['is_active'] ? 0 : 1;
            if ($new && (bookmaker_is_placeholder($b['affiliate_url']) || !bookmaker_valid_url($b['affiliate_url']))) {
                set_flash('error', 'Edit "' . $b['name'] . '" and set a real affiliate link before switching it on.');
            } else {
                $pdo->prepare('UPDATE bookmakers SET is_active = ? WHERE id = ?')->execute([$new, $bid]);
                admin_log('bookmaker.toggle', 'bookmaker#' . $bid, $b['name'] . ($new ? ' on' : ' off'));
                set_flash('success', $b['name'] . ($new ? ' is now shown.' : ' is now hidden.'));
            }
        }
        redirect('admin/bookmakers.php');
    } elseif ($action === 'add' || $action === 'save') {
        [$v, $er] = read_bookmaker_form();
        $form = $v;
        if ($er) {
            $errors = $er;
            if ($action === 'save') $editId = (int)($_POST['id'] ?? 0);
        } elseif ($action === 'add') {
            $slug = bookmaker_unique_slug($pdo, slugify($v['name']));
            $pdo->prepare('INSERT INTO bookmakers (name, slug, affiliate_url, logo_text, bonus_text, countries, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$v['name'], $slug, $v['affiliate_url'], $v['logo_text'] ?: null, $v['bonus_text'] ?: null, $v['countries'] ?: null, (int)$v['sort_order'], $v['is_active']]);
            admin_log('bookmaker.add', 'bookmaker#' . $pdo->lastInsertId(), $v['name']);
            set_flash('success', 'Bookmaker added.');
            redirect('admin/bookmakers.php');
        } else {
            $bid = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT id FROM bookmakers WHERE id = ?');
            $st->execute([$bid]);
            if ($st->fetchColumn()) {
                $pdo->prepare('UPDATE bookmakers SET name=?, affiliate_url=?, logo_text=?, bonus_text=?, countries=?, sort_order=?, is_active=? WHERE id=?')
                    ->execute([$v['name'], $v['affiliate_url'], $v['logo_text'] ?: null, $v['bonus_text'] ?: null, $v['countries'] ?: null, (int)$v['sort_order'], $v['is_active'], $bid]);
                admin_log('bookmaker.edit', 'bookmaker#' . $bid, $v['name']);
                set_flash('success', 'Bookmaker "' . $v['name'] . '" saved.');
                redirect('admin/bookmakers.php');
            }
            $errors[] = 'Bookmaker not found.';
        }
    }
}

$rows = [];
$tableMissing = false;
try {
    $rows = $pdo->query("SELECT b.*,
        (SELECT COUNT(*) FROM bookmaker_clicks c WHERE c.bookmaker_id = b.id AND c.clicked_at > NOW() - INTERVAL 7 DAY) AS c7,
        (SELECT COUNT(*) FROM bookmaker_clicks c WHERE c.bookmaker_id = b.id AND c.clicked_at > NOW() - INTERVAL 30 DAY) AS c30,
        (SELECT COUNT(*) FROM bookmaker_clicks c WHERE c.bookmaker_id = b.id) AS call
        FROM bookmakers b ORDER BY b.sort_order, b.id")->fetchAll();
} catch (Throwable $ex) {
    $tableMissing = true;
}

$editing = null;
if ($editId) {
    foreach ($rows as $r) {
        if ((int)$r['id'] === $editId) $editing = $r;
    }
}
function bm_fields(array $b, string $pfx): void
{ ?>
  <div class="formgrid">
    <div class="field"><label for="<?= $pfx ?>name">Name</label><input type="text" id="<?= $pfx ?>name" name="name" value="<?= e($b['name']) ?>" maxlength="60" required></div>
    <div class="field"><label for="<?= $pfx ?>logo">Short logo text (optional)</label><input type="text" id="<?= $pfx ?>logo" name="logo_text" value="<?= e($b['logo_text'] ?? '') ?>" maxlength="40"></div>
    <div class="field" style="grid-column:1/-1"><label for="<?= $pfx ?>url">Affiliate link</label><input type="url" id="<?= $pfx ?>url" name="affiliate_url" value="<?= e($b['affiliate_url']) ?>" maxlength="500" required>
      <div class="hint">The full link your partner programme gave you, starting with https://. Visitors are sent here after we count the click.</div></div>
    <div class="field" style="grid-column:1/-1"><label for="<?= $pfx ?>bonus">Bonus text (optional)</label><input type="text" id="<?= $pfx ?>bonus" name="bonus_text" value="<?= e($b['bonus_text'] ?? '') ?>" maxlength="160"></div>
    <div class="field"><label for="<?= $pfx ?>cty">Countries (optional)</label><input type="text" id="<?= $pfx ?>cty" name="countries" value="<?= e($b['countries'] ?? '') ?>" maxlength="80" placeholder="KE,NG,IN">
      <div class="hint">Saved for later. Right now every active bookmaker is shown to every visitor, so only switch on bookmakers that accept your visitors.</div></div>
    <div class="field"><label for="<?= $pfx ?>ord">Order</label><input type="number" id="<?= $pfx ?>ord" name="sort_order" value="<?= e($b['sort_order']) ?>" min="-9999" max="9999"></div>
    <div class="field check"><label><input type="checkbox" name="is_active" value="1"<?= !empty($b['is_active']) ? ' checked' : '' ?>> Active (shown on the site)</label></div>
  </div>
<?php }
$blank = ['name' => '', 'affiliate_url' => '', 'logo_text' => '', 'bonus_text' => '', 'countries' => '', 'sort_order' => '0', 'is_active' => 0];

$admin_title = 'Bookmakers';
$admin_active = 'bookmakers';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Bookmakers</h1>
<p class="sub">Affiliate links shown as "Bet on ..." buttons on tips that visitors can see (never on locked VIP picks). Clicks are counted without storing anyone's IP address.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($tableMissing): ?><div class="errors" role="alert">The bookmaker tables do not exist yet. Import database-update-6.sql in phpMyAdmin (SQL or Import tab) first.</div><?php else: ?>

<div class="panel">
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Bookmaker</th><th>Link</th><th>Status</th><th>Clicks 7d</th><th>30d</th><th>All</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): $ph = bookmaker_is_placeholder($b['affiliate_url']); ?>
      <tr>
        <td><strong><?= e($b['name']) ?></strong><br><small><?= e($b['bonus_text'] ?? '') ?></small></td>
        <td><small><?= $ph ? '<em>placeholder: edit me</em>' : e(parse_url($b['affiliate_url'], PHP_URL_HOST)) ?></small></td>
        <td><?= $b['is_active'] ? 'Active' : 'Hidden' ?></td>
        <td><?= (int)$b['c7'] ?></td><td><?= (int)$b['c30'] ?></td><td><?= (int)$b['call'] ?></td>
        <td class="acts">
          <a class="btn outline tiny" href="<?= e(url('admin/bookmakers.php?edit=' . (int)$b['id'])) ?>#editbox">Edit</a>
          <form class="inl" method="post" action="<?= e(url('admin/bookmakers.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
            <button class="btn tiny <?= $b['is_active'] ? 'loss' : 'win' ?>" type="submit"><?= $b['is_active'] ? 'Hide' : 'Show' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty">No bookmakers yet.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<?php if ($editing): $b = $form ?: $editing; ?>
<div class="panel" id="editbox"><h2>Edit <?= e($editing['name']) ?></h2>
  <form method="post" action="<?= e(url('admin/bookmakers.php?edit=' . (int)$editing['id'])) ?>"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
    <?php bm_fields($b, 'e'); ?>
    <button class="btn gold" type="submit">Save</button> <a class="btn outline" href="<?= e(url('admin/bookmakers.php')) ?>">Cancel</a>
  </form>
</div>
<?php endif; ?>

<div class="panel"><h2>Add a bookmaker</h2>
  <form method="post" action="<?= e(url('admin/bookmakers.php')) ?>"><?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <?php bm_fields(($form && ($_POST['action'] ?? '') === 'add') ? $form : $blank, 'a'); ?>
    <button class="btn gold" type="submit">Add bookmaker</button>
  </form>
</div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
