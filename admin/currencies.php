<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('currencies');
$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'refresh') {
        $r = update_rates_from_api();
        admin_log('currency.refresh', '', $r['message']);
        set_flash($r['ok'] ? 'success' : 'error', $r['message']);
        redirect('admin/currencies.php');
    } elseif ($action === 'save') {
        $cid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM currencies WHERE id = ?');
        $st->execute([$cid]);
        $c = $st->fetch();
        $rate = post_str('rate');
        if (!$c) {
            $errors[] = 'Currency not found.';
        } elseif (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > 100000000) {
            $errors[] = 'The rate must be a number above 0.';
        } else {
            $active = !empty($_POST['is_active']) ? 1 : 0;
            if ($c['code'] === default_currency_code()) $active = 1;          // the default currency can never be switched off
            if ($c['code'] === 'USD') $rate = '1';                              // all rates are "per 1 US dollar"
            $auto = ($c['code'] === 'USD') ? 0 : (!empty($_POST['auto_update']) ? 1 : 0);
            $pdo->prepare('UPDATE currencies SET rate_per_usd = ?, is_active = ?, auto_update = ?, symbol = ?, updated_at = NOW() WHERE id = ?')
                ->execute([number_format((float)$rate, 6, '.', ''), $active, $auto, substr(post_str('symbol') !== '' ? (string)$_POST['symbol'] : $c['symbol'], 0, 8), $cid]);
            admin_log('currency.edit', $c['code'], 'rate ' . $c['rate_per_usd'] . ' -> ' . $rate . ', active ' . $active . ', auto ' . $auto);
            set_flash('success', $c['code'] . ' saved.');
            redirect('admin/currencies.php');
        }
    } elseif ($action === 'add') {
        $code = strtoupper(post_str('code'));
        $name = post_str('name');
        $sym  = trim((string)($_POST['symbol'] ?? ''));
        $rate = post_str('rate');
        $dec  = (int)($_POST['decimals'] ?? 2);
        if (!preg_match('/^[A-Z]{3}$/', $code)) $errors[] = 'The currency code must be 3 letters, like GHS.';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 40) $errors[] = 'Enter the currency name.';
        if ($sym === '' || mb_strlen($sym) > 8) $errors[] = 'Enter a symbol (up to 8 characters).';
        if (!is_numeric($rate) || (float)$rate <= 0) $errors[] = 'Enter the rate: how many of this currency equal 1 US dollar.';
        if ($dec < 0 || $dec > 4) $errors[] = 'Decimals must be 0 to 4.';
        $st = $pdo->prepare('SELECT COUNT(*) FROM currencies WHERE code = ?');
        $st->execute([$code]);
        if (!$errors && $st->fetchColumn()) $errors[] = 'That currency already exists.';
        if (!$errors) {
            $pdo->prepare('INSERT INTO currencies (code, name, symbol, rate_per_usd, decimals, sort_order, updated_at) VALUES (?,?,?,?,?,?,NOW())')
                ->execute([$code, $name, $sym, number_format((float)$rate, 6, '.', ''), $dec, 50]);
            admin_log('currency.add', $code, $name);
            set_flash('success', $code . ' added.');
            redirect('admin/currencies.php');
        }
    }
}
$rows = $pdo->query('SELECT * FROM currencies ORDER BY sort_order, code')->fetchAll();

$admin_title = 'Currencies';
$admin_active = 'currencies';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Currencies</h1>
<p class="sub">Visitors can view prices in these currencies. Prices are stored in KES (and USD for PayPal), so other currencies are <strong>estimates</strong>. People are always charged in KES with M-Pesa or in USD with PayPal. Rates are "how many of this currency equal 1 US dollar".</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="panel">
  <div class="toolbar"><h2 style="margin:0">Exchange rates</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh"><button class="btn gold tiny" type="submit">Update rates now</button></form></div>
  <p style="color:var(--mute);font-size:14px;margin-bottom:12px">"Update rates now" fetches today's rates for every currency that has Auto-update ticked. A daily cron job does the same automatically (see README). Rates come from the free Exchange Rate API (attribution link is shown in the site footer). Turn Auto-update off for a currency if you want to set its rate by hand.</p>
  <?php foreach ($rows as $c): ?>
    <form method="post" style="border-top:1px solid var(--line);padding:12px 0;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <div style="min-width:150px"><strong><?= e($c['code']) ?></strong><br><small><?= e($c['name']) ?></small>
        <?php if ($c['updated_at'] === null && $c['code'] !== 'USD'): ?><br><small style="color:var(--loss)">placeholder rate, update it</small><?php endif; ?></div>
      <div class="field" style="margin:0"><label for="r<?= (int)$c['id'] ?>">Rate per 1 USD</label>
        <input type="number" id="r<?= (int)$c['id'] ?>" name="rate" value="<?= e(rtrim(rtrim((string)$c['rate_per_usd'], '0'), '.')) ?>" step="any" min="0.000001" style="width:150px;padding:8px 10px;border:1.5px solid var(--line);border-radius:6px"<?= $c['code'] === 'USD' ? ' readonly' : '' ?>></div>
      <div class="field" style="margin:0"><label for="s<?= (int)$c['id'] ?>">Symbol</label><input type="text" id="s<?= (int)$c['id'] ?>" name="symbol" value="<?= e($c['symbol']) ?>" maxlength="8" style="width:80px;padding:8px 10px;border:1.5px solid var(--line);border-radius:6px"></div>
      <div class="field check" style="margin:0"><label><input type="checkbox" name="is_active" value="1"<?= $c['is_active'] ? ' checked' : '' ?><?= $c['code'] === default_currency_code() ? ' disabled' : '' ?>> Show to visitors</label>
        <?php if ($c['code'] !== 'USD'): ?><label><input type="checkbox" name="auto_update" value="1"<?= $c['auto_update'] ? ' checked' : '' ?>> Auto-update</label><?php endif; ?></div>
      <div><button class="btn dark tiny" type="submit">Save</button> <small style="color:var(--mute)"><?= $c['updated_at'] ? 'updated ' . e(date('j M Y, H:i', strtotime($c['updated_at']))) : '' ?></small></div>
    </form>
  <?php endforeach; ?>
</div>

<div class="panel">
  <h2>Add a currency</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="formgrid">
      <div class="field"><label for="code">Code</label><input type="text" id="code" name="code" maxlength="3" placeholder="GHS" style="text-transform:uppercase"></div>
      <div class="field"><label for="name">Name</label><input type="text" id="name" name="name" maxlength="40" placeholder="Ghana cedi"></div>
      <div class="field"><label for="symbol">Symbol</label><input type="text" id="symbol" name="symbol" maxlength="8" placeholder="GH₵"></div>
      <div class="field"><label for="rate">Rate per 1 USD</label><input type="number" id="rate" name="rate" step="any" min="0.000001"></div>
      <div class="field"><label for="decimals">Decimals shown</label><input type="number" id="decimals" name="decimals" value="2" min="0" max="4"></div>
    </div>
    <button class="btn gold tiny" type="submit">Add currency</button>
  </form>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
