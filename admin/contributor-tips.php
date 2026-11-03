<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $act = $_POST['action'] ?? '';
        if ($act === 'review_toggle') { set_setting('tipster_tips_review', !empty($_POST['on']) ? '1' : '0'); set_flash('success', 'Saved.'); redirect('admin/contributor-tips.php'); }
        $st = $pdo->prepare('SELECT t.*, m.kickoff_at, m.home_team, m.away_team, ti.user_id AS tuser FROM tips t JOIN matches m ON m.id = t.match_id JOIN tipsters ti ON ti.id = COALESCE(t.author_id, t.tipster_id) WHERE t.id = ? AND t.pending_review = 1');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            set_flash('error', 'That tip was already handled.');
        } elseif ($act === 'approve') {
            if (strtotime($t['kickoff_at']) <= time() + 60) {
                set_flash('error', 'The match has started or is about to. A tip cannot be published after kickoff. Reject it.');
            } else {
                // Goes live now: the posting time is the approval time, so nobody can post after seeing a result.
                $pdo->prepare('UPDATE tips SET deleted_at = NULL, pending_review = 0, posted_at = NOW() WHERE id = ? AND pending_review = 1')->execute([$id]);
                admin_log('tip.contributor_approve', 'tip#' . $id, $t['pick']);
                if (empty($t['author_id'])) { try { alerts_new_tip($id); alerts_vip_posted($id); } catch (Throwable $ax) { } }
                notify_user((int)$t['tuser'], 'Your platform tip is live', $t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['pick']);
                set_flash('success', 'Approved and published.');
            }
        } elseif ($act === 'reject') {
            $pdo->prepare('UPDATE tips SET pending_review = 0 WHERE id = ?')->execute([$id]);
            admin_log('tip.contributor_reject', 'tip#' . $id, $t['pick']);
            notify_user((int)$t['tuser'], 'Platform tip not published', $t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['pick'] . ' was not approved.');
            set_flash('success', 'Rejected.');
        }
    }
    redirect('admin/contributor-tips.php');
}
$rows = $pdo->query("SELECT t.*, m.kickoff_at, m.home_team, m.away_team, l.name AS league, ti.display_name FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN tipsters ti ON ti.id = COALESCE(t.author_id, t.tipster_id) WHERE t.pending_review = 1 ORDER BY m.kickoff_at")->fetchAll();
$pool = (float)setting('contributor_pool_percent', '0');
$contribs = $pdo->query("SELECT display_name, slug FROM tipsters WHERE is_contributor = 1 AND status = 'active' ORDER BY display_name")->fetchAll();

$admin_title = 'Contributor tips';
$revOn = setting('tipster_tips_review', '0') === '1';
$admin_active = 'contributor-tips';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="review_toggle"><label><input type="checkbox" name="on" value="1"<?= $revOn ? ' checked' : '' ?>> Every tipster's own tips wait here for approval before going public</label> <button class="btn dark tiny" type="submit">Save</button></form></div>
<?php
?>
<h1>Contributor tips</h1>
<p class="sub">Tips written by contributors, waiting for your approval. Approved tips appear in the site's own feed with the author's name, and count in the site's record.</p>
<div class="note">Contributor pool: <strong><?= e(rtrim(rtrim(number_format($pool, 2), '0'), '.') ?: '0') ?>%</strong> of each VIP sale<?= $pool <= 0 ? ' (switched off)' : '' ?>. Change it in Admin &gt; Tipsters.
  Contributors: <?= $contribs ? e(implode(', ', array_column($contribs, 'display_name'))) : 'none yet. Open Admin > Tipsters and click "Make contributor" on an approved tipster.' ?></div>
<div class="panel">
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>By</th><th>Match</th><th>Kickoff</th><th>Market and pick</th><th>Odds</th><th>Type</th><th>Reasoning</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><?= e($r['display_name']) ?></td><td><?= e($r['home_team']) ?> vs <?= e($r['away_team']) ?><br><small><?= e($r['league']) ?></small></td>
  <td><?= e(date('j M, H:i', strtotime($r['kickoff_at']))) ?></td><td><?= e($r['market']) ?><br><strong><?= e($r['pick']) ?></strong></td>
  <td><?= e(number_format((float)$r['odds'], 2)) ?></td><td><?= $r['is_vip'] ? 'VIP' : 'Free' ?></td><td style="max-width:260px"><?= e($r['analysis'] ?: '-') ?></td>
  <td style="white-space:nowrap">
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="approve"><button class="btn tiny win" type="submit">Approve</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="reject"><button class="btn tiny loss" type="submit">Reject</button></form></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">Nothing waiting for review.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
