<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('accas');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM accumulators WHERE id = ? AND deleted_at IS NULL');
$st->execute([$id]);
$a = $st->fetch();
if (!$a) {
    set_flash('error', 'Accumulator not found.');
    redirect('admin/accas.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $_POST['result'] ?? '';
    $legId  = (int)($_POST['leg_id'] ?? 0);
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!in_array($result, ['won', 'lost', 'void'], true)) {
        set_flash('error', 'Choose won, lost or void.');
    } else {
        $st = $pdo->prepare('SELECT * FROM accumulator_legs WHERE id = ? AND accumulator_id = ?');
        $st->execute([$legId, $id]);
        $leg = $st->fetch();
        if (!$leg) {
            set_flash('error', 'Selection not found.');
        } elseif (strtotime($leg['kickoff_at']) > time()) {
            set_flash('error', 'That match has not started yet, so it cannot be settled.');
        } elseif ($leg['status'] === $result) {
            set_flash('info', 'Already marked ' . $result . '.');
        } else {
            $pdo->prepare('UPDATE accumulator_legs SET status = ? WHERE id = ?')->execute([$result, $legId]);
            $new = recompute_acca($id);
            admin_log('acca.settle', 'acca#' . $id, $leg['home_team'] . ' vs ' . $leg['away_team'] . ': ' . $leg['status'] . ' -> ' . $result . ' (ticket now ' . $new . ')');
            set_flash('success', 'Marked ' . $result . '. The accumulator is now ' . $new . '.');
        }
    }
    redirect('admin/acca-view.php?id=' . $id . '#leg' . $legId);
}

$st = $pdo->prepare('SELECT * FROM accumulators WHERE id = ?');
$st->execute([$id]);
$a = $st->fetch();
$st = $pdo->prepare('SELECT * FROM accumulator_legs WHERE accumulator_id = ? ORDER BY position');
$st->execute([$id]);
$legs = $st->fetchAll();
$odds = acca_effective_odds($legs);

$admin_title = $a['title'];
$admin_active = 'accas';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar"><div><h1><?= e($a['title']) ?></h1>
  <p class="sub" style="margin:0"><?= e(acca_kind_label($a['kind'])) ?> &middot; <?= count($legs) ?> selections &middot; total odds <?= e(number_format($odds, 2)) ?> &middot; <span class="badge <?= e($a['status']) ?>"><?= e(ucfirst($a['status'])) ?></span></p></div>
  <div><a class="btn outline" href="<?= e(url('admin/accas.php')) ?>">All accumulators</a> <a class="btn outline" href="<?= e(url('acca.php?id=' . $id)) ?>" target="_blank" rel="noopener">View on site</a></div></div>
<p class="sub">Mark each selection once its match is over. A lost selection loses the whole accumulator at once. Void selections count as odds 1.00.</p>
<div class="panel">
  <div class="scroll"><table class="tbl legs">
    <thead><tr><th>#</th><th>Match</th><th>Kickoff</th><th>Pick</th><th>Odds</th><th>Result</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($legs as $i => $l): $started = strtotime($l['kickoff_at']) <= time(); ?>
      <tr id="leg<?= (int)$l['id'] ?>" class="<?= e($l['status']) ?>">
        <td><?= $i + 1 ?></td>
        <td><?= e($l['home_team']) ?> vs <?= e($l['away_team']) ?><br><small><?= e($l['league']) ?></small></td>
        <td><?= e(date('j M, H:i', strtotime($l['kickoff_at']))) ?></td>
        <td><?= e($l['pick']) ?></td>
        <td><?= e(number_format((float)$l['odds'], 2)) ?></td>
        <td><span class="badge <?= e($l['status']) ?>"><?= e(ucfirst($l['status'])) ?></span></td>
        <td class="acts"><?php if ($started): ?>
          <form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="leg_id" value="<?= (int)$l['id'] ?>">
            <button class="btn tiny win" name="result" value="won" type="submit">W</button>
            <button class="btn tiny loss" name="result" value="lost" type="submit">L</button>
            <button class="btn tiny void" name="result" value="void" type="submit">V</button></form>
        <?php else: ?><small>not started</small><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
