<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$id = (int)($_GET['id'] ?? 0); $k = (string)($_GET['k'] ?? '');
$slip = null; $items = [];
if ($id && function_exists('slips_ready') && slips_ready() && hash_equals(slip_share_key($id), $k)) {
    $st = db()->prepare('SELECT s.id, s.title, s.stake, s.user_id, u.name, u.referral_code FROM slips s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $st->execute([$id]); $slip = $st->fetch() ?: null;
    if ($slip) { $it = db()->prepare('SELECT home, away, outcome, odds FROM slip_items WHERE slip_id = ? ORDER BY sort_no, id'); $it->execute([$id]); $items = $it->fetchAll(); }
}
if (!$slip) { require __DIR__ . '/404.php'; exit; }
$tot = slip_total_odds($items);
$by = mask_name((string)$slip['name']);
$page_title = $slip['title'] . ' - ' . count($items) . ' picks @ ' . number_format($tot, 2) . ' - ' . SITE_NAME;
$page_desc = $by . "'s slip: " . implode(', ', array_map(function ($i) { return $i['home'] . ' v ' . $i['away'] . ' (' . $i['outcome'] . ')'; }, array_slice($items, 0, 3))) . (count($items) > 3 ? ' and ' . (count($items) - 3) . ' more' : '') . '. Total odds ' . number_format($tot, 2) . '.';
$img = is_file(__DIR__ . '/assets/img/og-default.png') ? url('assets/img/og-default.png') : '';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => slip_share_url($id), 'image' => $img, 'robots' => 'noindex, follow']) . '<link rel="stylesheet" href="' . e(url('assets/css/group-page.css')) . '?v=9">' . "\n";
$ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($slip['referral_code'] ?? ''));
$active = 'game';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top" style="max-width:640px">
  <div class="gp-att slip" style="margin:16px 0">
    <div class="gp-ah"><b>&#129534; <?= e($slip['title']) ?></b><span><?= count($items) ?> pick<?= count($items) === 1 ? '' : 's' ?> &middot; <?= e(number_format($tot, 2)) ?> total odds</span></div>
    <?php foreach ($items as $i): ?><div class="gp-ai"><span><?= e($i['home'] . ' v ' . $i['away']) ?></span><em><?= e($i['outcome']) ?></em><b><?= e(number_format((float)$i['odds'], 2)) ?></b></div><?php endforeach; ?>
    <small class="gp-adis">Shared by <?= e($by) ?>. This is a member's own slip, not betting advice. 18+ only, bet responsibly.</small>
  </div>
  <p><a class="btn gold" href="<?= e(url($ref !== '' ? 'auth/register.php?ref=' . $ref : 'auth/register.php')) ?>">Join <?= e(SITE_NAME) ?> free</a> <a class="btn ghost" href="<?= e(url('slips.php')) ?>">Build your own slip</a></p>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
