<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/match-delete.php';
require_perm('tips');
if (!is_admin()) { set_flash('error', 'Only an administrator can manage results.'); redirect('admin/index.php'); }
$pdo = db();

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
$status = in_array($_GET['status'] ?? $_POST['status'] ?? '', ['won', 'lost', 'void'], true) ? (string)($_GET['status'] ?? $_POST['status']) : '';
$back = 'admin/results.php?' . http_build_query(array_filter(['q' => $q, 'status' => $status]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $a = (string)($_POST['action'] ?? '');
    try {
        if ($a === 'delete_selected') {
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            $n = admin_purge_tips($pdo, $ids, true);
            admin_log('results.delete', 'tips', $n . ' result(s) erased: ids ' . implode(',', array_slice($ids, 0, 40)));
            set_flash($n ? 'success' : 'error', $n ? $n . ' result(s) erased.' : 'Tick at least one result first.');
        } elseif ($a === 'delete_team' && $q !== '') {
            if (strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'DELETE') { set_flash('error', 'Type DELETE to confirm removing every result for that team.'); redirect($back); }
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $st = $pdo->prepare("SELECT t.id FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.status <> 'pending' AND (m.home_team LIKE ? OR m.away_team LIKE ?)");
            $st->execute([$like, $like]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $n = admin_purge_tips($pdo, $ids, true);
            admin_log('results.delete', 'team:' . $q, $n . ' result(s) erased for team search "' . $q . '"');
            set_flash('success', $n . ' result(s) erased for "' . $q . '".');
        } elseif ($a === 'clear_score') {
            $mid = (int)($_POST['match_id'] ?? 0);
            $pdo->prepare('UPDATE matches SET home_score = NULL, away_score = NULL, live_status = NULL, live_minute = NULL, score_updated_at = NULL WHERE id = ?')->execute([$mid]);
            admin_log('results.score_clear', 'match#' . $mid, 'final score cleared');
            cache_flush();
            set_flash('success', 'Score cleared.');
        }
    } catch (Throwable $e) {
        error_log('admin results: ' . $e->getMessage());
        set_flash('error', 'That could not be done. Nothing was changed.');
    }
    redirect($back);
}

$where = "WHERE t.status <> 'pending'"; $args = [];
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where .= ' AND (m.home_team LIKE ? OR m.away_team LIKE ? OR t.pick LIKE ?)'; array_push($args, $like, $like, $like); }
if ($status !== '') { $where .= ' AND t.status = ?'; $args[] = $status; }
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$st = $pdo->prepare("SELECT COUNT(*) FROM tips t JOIN matches m ON m.id = t.match_id $where");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT t.id, t.pick, t.market, t.odds, t.status, t.tipster_id, m.id AS match_id, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score
                     FROM tips t JOIN matches m ON m.id = t.match_id $where ORDER BY m.kickoff_at DESC, t.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$rows = $st->fetchAll();

$admin_title = 'Manage results';
$admin_active = 'results';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Manage results</h1>
<p class="sub">Search a team, then erase results one by one, in bulk, or for the whole team. Erased results disappear from the results page, the track record numbers, tipster records and the audit trail. This cannot be undone. Every erase is written to <a href="<?= e(url('admin/deletions.php')) ?>">Deleted items</a>.</p>
<div class="panel">
  <form method="get" class="toolbar">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Team or pick, e.g. Arsenal">
    <select name="status"><option value="">Won, lost and void</option><?php foreach (['won', 'lost', 'void'] as $s): ?><option value="<?= $s ?>"<?= $status === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
    <button class="btn dark tiny" type="submit">Search</button> <a class="btn outline tiny" href="<?= e(url('admin/results.php')) ?>">Clear</a>
  </form>
  <?php if ($q !== '' && $total): ?>
  <form method="post" class="toolbar" onsubmit="return confirm('Erase ALL <?= (int)$total ?> results matching this search?');" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete_team"><input type="hidden" name="q" value="<?= e($q) ?>">
    <span><strong><?= (int)$total ?></strong> results match "<?= e($q) ?>".</span>
    <input type="text" name="confirm" placeholder="Type DELETE" style="width:130px" autocomplete="off">
    <button class="btn tiny loss" type="submit">Erase all for this team</button>
  </form>
  <?php endif; ?>
</div>
<div class="panel">
<?php if ($rows): ?>
<form method="post" id="bulk"><?= csrf_field() ?><input type="hidden" name="action" value="delete_selected"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="status" value="<?= e($status) ?>">
  <div class="scroll"><table class="tbl"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.rc').forEach(function(c){c.checked=this.checked}.bind(this))" aria-label="Select all"></th><th>Kickoff</th><th>Match</th><th>Pick</th><th>Result</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><input class="rc" type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
    <td><?= e(date('j M Y', strtotime($r['kickoff_at']))) ?></td>
    <td><?= e($r['home_team']) ?> vs <?= e($r['away_team']) ?><?= $r['home_score'] !== null ? '<br><small>Score ' . (int)$r['home_score'] . ' - ' . (int)$r['away_score'] . '</small>' : '' ?><?= $r['tipster_id'] ? '<br><small>Tipster tip</small>' : '' ?></td>
    <td><?= e($r['pick']) ?> <small>@ <?= e(number_format((float)$r['odds'], 2)) ?></small><br><small><?= e($r['market']) ?></small></td>
    <td><span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
    <td class="acts">
      <button class="btn tiny loss" type="submit" formaction="<?= e(url('admin/tip-delete.php')) ?>" name="id" value="<?= (int)$r['id'] ?>" formnovalidate onclick="return confirm('Erase this result for good?');">Erase</button>
      <?php if ($r['home_score'] !== null): ?><button class="btn tiny" type="submit" name="action" value="clear_score" formaction="<?= e(url('admin/results.php')) ?>" onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=match_id value=<?= (int)$r['match_id'] ?>>');return confirm('Clear the final score of this match?');">Clear score</button><?php endif; ?>
      <button class="btn tiny void" type="submit" formaction="<?= e(url('admin/match-delete.php')) ?>" name="match_id" value="<?= (int)$r['match_id'] ?>" formnovalidate onclick="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=back value=admin/results.php>');return confirm('Delete the WHOLE game and everything on it?');">Delete game</button>
    </td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p style="margin-top:12px"><button class="btn loss" type="submit" onclick="return confirm('Erase all ticked results for good?');">Erase ticked results</button></p>
</form>
<?= pager($page, $pages, 'admin/results.php') ?>
<?php else: ?><p style="color:var(--mute)">No settled results match.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
