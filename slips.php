<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
require_login();
$u = current_user();
$ready = slips_ready();
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('slips.php'); }
    $id = (int)($_POST['id'] ?? 0); $s = slip_get($id, (int)$u['id']);
    if ($s && ($_POST['action'] ?? '') === 'duplicate') {
        $cnt = db()->prepare('SELECT COUNT(*) FROM slips WHERE user_id = ?'); $cnt->execute([$u['id']]);
        if ((int)$cnt->fetchColumn() >= 100) { set_flash('error', 'You can keep up to 100 slips. Delete some first.'); redirect('slips.php'); }
        $new = slip_save((int)$u['id'], null, mb_substr($s['title'], 0, 70) . ' (copy)', (float)$s['stake'], $s['items']);
        set_flash('success', 'Slip duplicated.'); redirect('slip.php?id=' . $new);
    }
    if ($s && ($_POST['action'] ?? '') === 'delete') {
        db()->prepare('DELETE FROM slip_items WHERE slip_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM slips WHERE id = ? AND user_id = ?')->execute([$id, $u['id']]);
        set_flash('success', 'Slip deleted.');
    }
    redirect('slips.php');
}
$rows = [];
if ($ready) {
    $st = db()->prepare('SELECT s.id, s.title, s.stake, s.updated_at, COUNT(i.id) AS n, EXP(SUM(LN(i.odds))) AS total FROM slips s LEFT JOIN slip_items i ON i.slip_id = s.id WHERE s.user_id = ? GROUP BY s.id ORDER BY s.updated_at DESC');
    $st->execute([$u['id']]);
    $rows = $st->fetchAll();
    $prev = [];
    if ($rows) {
        $ids = implode(',', array_map('intval', array_column($rows, 'id')));
        foreach (db()->query("SELECT slip_id, home, away, outcome, odds FROM slip_items WHERE slip_id IN ($ids) ORDER BY sort_no, id")->fetchAll() as $it) { $prev[(int)$it['slip_id']][] = $it; }
    }
}
$page_title = 'Slip Builder Studio - ' . SITE_NAME;
$active = 'game';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/slips.css')) . '?v=1">' . "\n";
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top sb">
  <div class="sb-hero">
    <div><h1>Slip Builder Studio</h1>
      <p>Build your own slip: add matches, pick outcomes, enter odds and watch the combined odds. Save, duplicate, edit and download as PDF.</p></div>
    <a class="btn gold" href="<?= e(url('slip.php')) ?>">+ New slip</a>
  </div>
  <?php if (!$ready): ?><div class="alert">Slip Builder is not available yet. Import database-update-19.sql in phpMyAdmin.</div><?php endif; ?>
  <?php if ($rows): ?><div class="sb-grid">
  <?php foreach ($rows as $r): $its = $prev[(int)$r['id']] ?? []; ?>
    <article class="sb-slip">
      <div class="sb-sh"><div><h3><a href="<?= e(url('slip.php?id=' . (int)$r['id'])) ?>"><?= e($r['title']) ?></a></h3><small><?= (int)$r['n'] ?> selection<?= (int)$r['n'] === 1 ? '' : 's' ?> &middot; <?= e(date('j M Y, H:i', strtotime($r['updated_at']))) ?></small></div>
        <div class="sb-tot"><span>Total odds</span><b><?= $r['total'] ? e(number_format((float)$r['total'], 2)) : '-' ?></b></div></div>
      <ul class="sb-pv"><?php foreach (array_slice($its, 0, 3) as $it): ?><li><span><?= e($it['home'] . ' vs ' . $it['away']) ?><em><?= e($it['outcome']) ?></em></span><b><?= e(number_format((float)$it['odds'], 2)) ?></b></li><?php endforeach; ?>
        <?php if (count($its) > 3): ?><li class="more">+ <?= count($its) - 3 ?> more</li><?php endif; ?></ul>
      <div class="sb-acts">
        <a class="btn dark small" href="<?= e(url('slip.php?id=' . (int)$r['id'])) ?>">Edit</a>
        <a class="btn small ghostd" href="<?= e(url('slip-pdf.php?id=' . (int)$r['id'])) ?>">PDF</a>
        <button type="button" class="btn small ghostd" data-share data-url="<?= e(slip_share_url((int)$r['id'])) ?>" data-title="<?= e($r['title']) ?> - slip on <?= e(SITE_NAME) ?>">Share link</button>
        <a class="btn small ghostd" href="<?= e(url('calculator.php')) ?>">Stake calculator</a>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small ghostd" name="action" value="duplicate">Duplicate</button>
          <button class="btn small danger" name="action" value="delete" onclick="return confirm('Delete this slip?')">Delete</button></form>
      </div>
    </article>
  <?php endforeach; ?></div>
  <?php else: ?><div class="sb-empty"><div class="sb-eico">&#127903;</div><h3>No saved slips yet</h3><p>Create your first slip and it will appear here.</p><a class="btn gold" href="<?= e(url('slip.php')) ?>">Build a slip</a></div><?php endif; ?>
</div>
<script src="<?= e(url('assets/js/share.js')) ?>?v=1"></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
