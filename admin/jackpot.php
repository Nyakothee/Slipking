<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');
$ready = jackpot_ready();
$self = 'admin/jackpot.php';
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($self); }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM jackpot_rounds WHERE id = ?'); $st->execute([$id]); $cur = $st->fetch();
    if ($act === 'save') {
        $title = mb_substr(post_str('title'), 0, 120);
        $amount = max(0, (float)str_replace(',', '', (string)($_POST['amount_kes'] ?? 0)));
        $cons = max(0, (float)str_replace(',', '', (string)($_POST['consolation_kes'] ?? 0)));
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
        $status = in_array($_POST['status'] ?? '', ['draft', 'open'], true) ? $_POST['status'] : 'draft';
        $closeRaw = trim((string)($_POST['closes_at'] ?? ''));
        $closes = $closeRaw !== '' && strtotime($closeRaw) ? date('Y-m-d H:i:s', strtotime($closeRaw)) : null;
        if ($title === '') { set_flash('error', 'Give the round a title.'); redirect($self . ($id ? '?edit=' . $id : '')); }
        if ($cur && $cur['status'] === 'settled') {
            set_flash('error', 'A settled round cannot be edited.'); redirect($self);
        }
        $homes = (array)($_POST['home'] ?? []); $aways = (array)($_POST['away'] ?? []);
        $leagues = (array)($_POST['league'] ?? []); $kos = (array)($_POST['kickoff'] ?? []); $res = (array)($_POST['result'] ?? []);
        $rows = [];
        for ($i = 0; $i < JACKPOT_GAMES; $i++) {
            $h = mb_substr(trim((string)($homes[$i] ?? '')), 0, 80); $a = mb_substr(trim((string)($aways[$i] ?? '')), 0, 80);
            if ($h === '' && $a === '') { continue; }
            $ko = trim((string)($kos[$i] ?? '')); $ko = $ko !== '' && strtotime($ko) ? date('Y-m-d H:i:s', strtotime($ko)) : null;
            $r = (string)($res[$i] ?? ''); $r = in_array($r, ['1', 'X', '2'], true) ? $r : null;
            $rows[] = [$h, $a, mb_substr(trim((string)($leagues[$i] ?? '')), 0, 80), $ko, $r];
        }
        if ($status === 'open' && count($rows) !== JACKPOT_GAMES) { set_flash('error', 'Add all ' . JACKPOT_GAMES . ' games before opening the round (saved as draft instead).'); $status = 'draft'; }
        $pdo->beginTransaction();
        try {
            if ($cur) {
                $pdo->prepare('UPDATE jackpot_rounds SET title=?, amount_kes=?, consolation_kes=?, note=?, status=?, closes_at=? WHERE id=?')->execute([$title, $amount, $cons, $note, $status, $closes, $id]);
                if ($amount != (float)$cur['amount_kes']) { admin_log('jackpot.amount', (string)$id, jackpot_kes($cur['amount_kes']) . ' -> ' . jackpot_kes($amount)); }
            } else {
                $pdo->prepare('INSERT INTO jackpot_rounds (title, amount_kes, consolation_kes, note, status, closes_at) VALUES (?,?,?,?,?,?)')->execute([$title, $amount, $cons, $note, $status, $closes]);
                $id = (int)$pdo->lastInsertId(); admin_log('jackpot.create', (string)$id, $title);
            }
            if (jackpot_cols()) { $pdo->prepare('UPDATE jackpot_rounds SET kind = ?, analysis = ? WHERE id = ?')->execute([in_array($_POST['kind'] ?? '', ['weekly', 'midweek', 'mega'], true) ? $_POST['kind'] : 'weekly', trim(mb_substr((string)($_POST['analysis'] ?? ''), 0, 6000)), $id]); }
            // Keep game ids stable (entries reference them) when the game count is unchanged.
            $old = jackpot_games($id);
            foreach ($rows as $n => $g) {
                if (isset($old[$n])) {
                    $pdo->prepare('UPDATE jackpot_games SET sort_no=?, home=?, away=?, league=?, kickoff_at=?, result=? WHERE id=?')->execute([$n + 1, $g[0], $g[1], $g[2], $g[3], $g[4], $old[$n]['id']]);
                } else {
                    $pdo->prepare('INSERT INTO jackpot_games (round_id, sort_no, home, away, league, kickoff_at, result) VALUES (?,?,?,?,?,?,?)')->execute([$id, $n + 1, $g[0], $g[1], $g[2], $g[3], $g[4]]);
                }
            }
            for ($n = count($rows); $n < count($old); $n++) {
                $pdo->prepare('DELETE FROM jackpot_picks WHERE game_id = ?')->execute([$old[$n]['id']]);
                $pdo->prepare('DELETE FROM jackpot_games WHERE id = ?')->execute([$old[$n]['id']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) { $pdo->rollBack(); error_log('jackpot save: ' . $ex->getMessage()); set_flash('error', 'Could not save the round.'); redirect($self); }
        jackpot_rescore($id);
        set_flash('success', 'Round saved.' . ($status === 'open' ? ' It is live on the Jackpot page.' : ''));
        redirect($self . '?edit=' . $id);
    } elseif ($act === 'settle' && $cur) {
        [$ok, $msg] = jackpot_settle($id);
        if ($ok) { admin_log('jackpot.settle', (string)$id, $msg); }
        set_flash($ok ? 'success' : 'error', $msg);
        redirect($self . '?edit=' . $id);
    } elseif ($act === 'paid' && $cur) {
        $eid = (int)($_POST['entry_id'] ?? 0);
        $isPaid = !empty($_POST['paid']);
        $pdo->prepare('UPDATE jackpot_entries SET paid_at = ?, pay_note = ? WHERE id = ? AND round_id = ?')->execute([$isPaid ? date('Y-m-d H:i:s') : null, mb_substr(post_str('pay_note'), 0, 200), $eid, $id]);
        admin_log('jackpot.paid', (string)$eid, $isPaid ? 'paid' : 'unpaid');
        set_flash('success', 'Payment status updated.'); redirect($self . '?edit=' . $id);
    } elseif ($act === 'delete' && $cur && $cur['status'] === 'draft') {
        foreach (['jackpot_picks' => 'entry_id IN (SELECT id FROM jackpot_entries WHERE round_id = ?)', 'jackpot_entries' => 'round_id = ?', 'jackpot_games' => 'round_id = ?', 'jackpot_rounds' => 'id = ?'] as $t => $w) {
            $pdo->prepare("DELETE FROM $t WHERE $w")->execute([$id]);
        }
        admin_log('jackpot.delete', (string)$id, $cur['title']); set_flash('success', 'Draft deleted.');
    }
    redirect($self);
}

$edit = null; $games = []; $winners = [];
if ($ready && isset($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM jackpot_rounds WHERE id = ?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null;
    if ($edit) {
        $games = jackpot_games((int)$edit['id']);
        $wq = db()->prepare('SELECT e.*, u.name, u.email, u.phone FROM jackpot_entries e JOIN users u ON u.id = e.user_id WHERE e.round_id = ? AND e.prize_kes > 0 ORDER BY e.prize_kes DESC, e.id');
        try { $wq->execute([$edit['id']]); $winners = $wq->fetchAll(); } catch (Throwable $ex) { $q2 = db()->prepare('SELECT e.*, u.name, u.email FROM jackpot_entries e JOIN users u ON u.id = e.user_id WHERE e.round_id = ? AND e.prize_kes > 0'); $q2->execute([$edit['id']]); $winners = $q2->fetchAll(); }
    }
}
$rounds = $ready ? db()->query('SELECT r.*, (SELECT COUNT(*) FROM jackpot_entries e WHERE e.round_id = r.id) AS n FROM jackpot_rounds r ORDER BY r.id DESC LIMIT 50')->fetchAll() : [];
$locked = $edit && $edit['status'] === 'settled';
$dt = function ($v) { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; };
$admin_title = 'Jackpot'; $admin_active = 'jackpot';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Jackpot</h1>
<p class="sub">Put up 12 games. Members who predict all of them correctly share the jackpot. You can change the prize amount any time until you settle the round.</p>
<?php if (!$ready): ?><div class="note">The jackpot needs database-update-21.sql. Import it in phpMyAdmin.</div><?php else: ?>
<form method="post" class="box2"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
  <h3><?= $edit ? 'Edit round: ' . e($edit['title']) . ($locked ? ' (settled)' : '') : 'New jackpot round' ?></h3>
  <div class="grid2">
    <label>Type<select name="kind"<?= $locked ? ' disabled' : '' ?>><?php foreach (['weekly' => 'Weekly', 'midweek' => 'Midweek', 'mega' => 'Mega (weekly mega jackpot)'] as $kv => $kl): ?><option value="<?= $kv ?>"<?= ($edit['kind'] ?? 'weekly') === $kv ? ' selected' : '' ?>><?= e($kl) ?></option><?php endforeach; ?></select></label>
    <label>Analysis for members (shown on the jackpot page: form, injuries, your thoughts per game)<textarea name="analysis" rows="6" maxlength="6000"<?= $locked ? ' disabled' : '' ?>><?= e($edit['analysis'] ?? '') ?></textarea></label>
    <label>Title<input name="title" required maxlength="120" value="<?= e($edit['title'] ?? 'Weekend Jackpot') ?>"<?= $locked ? ' disabled' : '' ?>></label>
    <label>Jackpot amount (KES) &mdash; change any time<input name="amount_kes" type="number" min="0" step="1" value="<?= e((string)(int)($edit['amount_kes'] ?? 50000)) ?>"<?= $locked ? ' disabled' : '' ?>></label>
    <label>Prize for <?= JACKPOT_GAMES - 1 ?> correct (KES, 0 = none)<input name="consolation_kes" type="number" min="0" step="1" value="<?= e((string)(int)($edit['consolation_kes'] ?? 0)) ?>"<?= $locked ? ' disabled' : '' ?>></label>
    <label>Entries close at (empty = first kick-off)<input name="closes_at" type="datetime-local" value="<?= e($dt($edit['closes_at'] ?? null)) ?>"<?= $locked ? ' disabled' : '' ?>></label>
    <label>Status<select name="status"<?= $locked ? ' disabled' : '' ?>><option value="draft"<?= ($edit['status'] ?? 'draft') === 'draft' ? ' selected' : '' ?>>Draft (hidden)</option><option value="open"<?= ($edit['status'] ?? '') === 'open' ? ' selected' : '' ?>>Open (visible to members)</option><?php if ($locked): ?><option selected>Settled</option><?php endif; ?></select></label>
  </div>
  <label>Note shown on the page (optional)<textarea name="note" rows="2" maxlength="1000"<?= $locked ? ' disabled' : '' ?>><?= e($edit['note'] ?? '') ?></textarea></label>
  <div class="scroll"><table class="tbl"><thead><tr><th>#</th><th>Home</th><th>Away</th><th>League</th><th>Kick-off</th><th>Result</th></tr></thead><tbody>
  <?php for ($i = 0; $i < JACKPOT_GAMES; $i++): $g = $games[$i] ?? []; ?>
    <tr><td><?= $i + 1 ?></td>
      <td><input name="home[]" maxlength="80" value="<?= e($g['home'] ?? '') ?>"<?= $locked ? ' disabled' : '' ?>></td>
      <td><input name="away[]" maxlength="80" value="<?= e($g['away'] ?? '') ?>"<?= $locked ? ' disabled' : '' ?>></td>
      <td><input name="league[]" maxlength="80" value="<?= e($g['league'] ?? '') ?>"<?= $locked ? ' disabled' : '' ?>></td>
      <td><input name="kickoff[]" type="datetime-local" value="<?= e($dt($g['kickoff_at'] ?? null)) ?>"<?= $locked ? ' disabled' : '' ?>></td>
      <td><select name="result[]"<?= $locked ? ' disabled' : '' ?>><option value="">?</option><?php foreach (['1', 'X', '2'] as $v): ?><option<?= ($g['result'] ?? null) === $v ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></td></tr>
  <?php endfor; ?></tbody></table></div>
  <?php if (!$locked): ?><button class="btn gold" type="submit">Save round</button><?php endif; ?> <a class="btn" href="<?= e(url($self)) ?>">New round</a>
</form>

<?php if ($edit && !$locked): ?>
<form method="post" class="box2" onsubmit="return confirm('Settle this round now? Winners are notified and the amount can no longer be changed.')"><?= csrf_field() ?><input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <h3>Settle round</h3><p class="sub">Fill in every result above and save, then settle. The jackpot (<?= e(jackpot_kes($edit['amount_kes'])) ?>) is split equally between members who got all <?= JACKPOT_GAMES ?> right.</p>
  <button class="btn gold" type="submit">Settle and notify winners</button></form>
<?php if ($edit['status'] === 'draft'): ?><form method="post" onsubmit="return confirm('Delete this draft?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn" type="submit">Delete draft</button></form><?php endif; ?>
<?php endif; ?>

<?php if ($locked): ?>
<div class="box2"><h3>Winners &amp; payments</h3>
<?php if (!$winners): ?><p>No winners in this round.</p><?php else: ?><div class="scroll"><table class="tbl"><thead><tr><th>Member</th><th>Correct</th><th>Prize</th><th>Contact</th><th>Payment</th></tr></thead><tbody>
<?php foreach ($winners as $w): ?><tr><td><?= e($w['name']) ?></td><td><?= (int)$w['correct_n'] ?></td><td><?= e(jackpot_kes($w['prize_kes'])) ?></td><td><?= e($w['email'] ?? '') ?><?= !empty($w['phone']) ? '<br>' . e($w['phone']) : '' ?></td>
  <td><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="entry_id" value="<?= (int)$w['id'] ?>">
    <input name="pay_note" placeholder="M-Pesa ref" value="<?= e($w['pay_note'] ?? '') ?>"><label><input type="checkbox" name="paid" value="1"<?= $w['paid_at'] ? ' checked' : '' ?>> Paid</label> <button class="btn" type="submit">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php endif; ?>

<div class="box2"><h3>All rounds</h3><div class="scroll"><table class="tbl"><thead><tr><th>Round</th><th>Jackpot</th><th>Status</th><th>Entries</th><th></th></tr></thead><tbody>
<?php foreach ($rounds as $r): ?><tr><td><?= e($r['title']) ?></td><td><?= e(jackpot_kes($r['amount_kes'])) ?></td><td><?= e($r['status']) ?></td><td><?= (int)$r['n'] ?></td><td><a href="<?= e(url($self . '?edit=' . $r['id'])) ?>">Open</a></td></tr><?php endforeach; ?>
<?php if (!$rounds): ?><tr><td colspan="5">No rounds yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
