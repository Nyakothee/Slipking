<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); }
    else {
        $on = (array)($_POST['on'] ?? []);
        foreach (site_pages() as $k => $p) { set_setting('page_off_' . $k, in_array($k, $on, true) ? '0' : '1'); }
        admin_log('pages.switch', implode(',', pages_off_list()) ?: 'all on');
        set_flash('success', 'Saved. Switched-off sections are hidden from the site menu, the footer and the app.');
    }
    redirect('admin/pages.php');
}
$admin_title = 'Pages on / off';
$admin_active = 'pages';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Pages on / off</h1>
<p class="sub">Turn a whole section off and it disappears from the menu, the footer and the app, and its page shows "not available". Payment confirmations from the payment providers keep working, and staff can still open switched-off pages to check them.</p>
<form method="post" class="card form"><?= csrf_field() ?>
  <table class="tbl"><thead><tr><th>Section</th><th style="width:110px">Shown</th></tr></thead><tbody>
  <?php foreach (site_pages() as $k => $p): ?>
    <tr><td><strong><?= e($p[0]) ?></strong></td><td><label><input type="checkbox" name="on[]" value="<?= e($k) ?>"<?= page_on($k) ? ' checked' : '' ?>> On</label></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <p class="hint">Tip: switching off "VIP, plans and payments" hides the VIP page, checkout and the subscription pages. Members who already paid keep their VIP tips on the tips pages.</p>
  <button class="btn dark" type="submit">Save</button>
</form>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
