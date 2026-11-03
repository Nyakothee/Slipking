<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
cmty2_ready(); presence_ready(); dm_ready();
$mok = function_exists('members_ready') && members_ready() && cmty3_ready();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('admin/community-stats.php'); }
    $act = (string)($_POST['action'] ?? '');
    if ($mok && in_array($act, ['verify', 'unverify', 'neverify'], true)) {
        $h = strtolower(trim(post_str('handle')));
        $mq = db()->prepare('SELECT user_id FROM member_public WHERE handle = ?'); $mq->execute([$h]); $mid = (int)$mq->fetchColumn();
        if (!$mid) { set_flash('error', 'No public member with that username.'); }
        else {
            $val = ['verify' => 1, 'unverify' => 0, 'neverify' => -1][$act];
            db()->prepare('UPDATE member_public SET verified = ? WHERE user_id = ?')->execute([$val, $mid]);
            admin_log('member.verify', (string)$mid, $act . ' @' . $h);
            if ($val === 1) { cmty_alert($mid, 'You are now a verified member', 'A verified tick now appears next to your name.', 'member.php?u=' . $h); }
            set_flash('success', $val === 1 ? '@' . $h . ' is verified.' : ($val === 0 ? '@' . $h . ' is no longer verified (automatic checks can verify again).' : '@' . $h . ' will never be verified automatically.'));
        }
    } elseif ($act === 'minbets') {
        set_setting('verify_min_bets', (string)max(10, min(500, (int)($_POST['min'] ?? 30)))); set_flash('success', 'Saved.');
    } elseif ($act === 'autoverify') {
        $n = member_auto_verify(); set_flash('success', $n . ' member' . ($n === 1 ? '' : 's') . ' newly verified.');
    } elseif ($act === 'dm_delete' || $act === 'dm_dismiss' || $act === 'dm_ban') {
        $mid = (int)($_POST['msg'] ?? 0);
        $fq = db()->prepare('SELECT from_id FROM dm_messages WHERE id = ?'); $fq->execute([$mid]); $from = (int)$fq->fetchColumn();
        if ($act === 'dm_delete') { db()->prepare('UPDATE dm_messages SET hidden = 1 WHERE id = ?')->execute([$mid]); db()->prepare('DELETE FROM dm_reports WHERE msg_id = ?')->execute([$mid]); admin_log('dm.delete', (string)$mid, 'reported message'); set_flash('success', 'Message hidden.'); }
        elseif ($act === 'dm_dismiss') { db()->prepare('DELETE FROM dm_reports WHERE msg_id = ?')->execute([$mid]); set_flash('success', 'Reports dismissed.'); }
        elseif ($from) { try { db()->prepare('INSERT IGNORE INTO comment_bans (user_id) VALUES (?)')->execute([$from]); db()->prepare('UPDATE dm_messages SET hidden = 1 WHERE id = ?')->execute([$mid]); db()->prepare('DELETE FROM dm_reports WHERE msg_id = ?')->execute([$mid]); admin_log('dm.ban', (string)$from, 'from reported message'); set_flash('success', 'Member switched off from commenting and chatting.'); } catch (Throwable $e) { set_flash('error', 'Could not ban this member.'); } }
    }
    redirect('admin/community-stats.php');
}
$s = cmty_stats();
$dmr = [];
try { $dmr = db()->query('SELECT r.msg_id, COUNT(*) AS n, m.body, m.created_at, u.name, u.email FROM dm_reports r JOIN dm_messages m ON m.id = r.msg_id JOIN users u ON u.id = m.from_id WHERE m.hidden = 0 GROUP BY r.msg_id, m.body, m.created_at, u.name, u.email ORDER BY n DESC, r.msg_id DESC LIMIT 50')->fetchAll(); } catch (Throwable $e) { }
$ver = [];
if ($mok) { try { $ver = db()->query('SELECT handle, verified FROM member_public WHERE verified <> 0 ORDER BY verified DESC, handle LIMIT 100')->fetchAll(); } catch (Throwable $e) { } }
$admin_title = 'Community stats'; $admin_active = 'communitystats';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Community stats</h1>
<p class="sub">How the groups, chat and messages are doing, who is reported most, and who is verified.</p>
<div class="stats">
  <?php foreach ([['Active groups', $s['groups']], ['Members in groups', $s['members']], ['Posts (7 days)', $s['posts7']], ['Replies (7 days)', $s['replies7']], ['Active today', $s['dau']], ['Online now', $s['online']], ['Messages (7 days)', $s['dm7']], ['Join requests waiting', $s['pending']], ['Reported messages', $s['dmrep']]] as $c): ?>
  <div class="stat"><b><?= (int)$c[1] ?></b><span><?= e($c[0]) ?></span></div><?php endforeach; ?>
</div>
<h2>Top groups this week</h2>
<div class="scroll"><table class="bm-table"><thead><tr><th>Group</th><th>Posts + replies (7 days)</th><th>New members (7 days)</th><th>Members</th></tr></thead><tbody>
<?php foreach ($s['top'] as $g): ?><tr><td><a href="<?= e(url('m.php?s=' . $g['slug'])) ?>"><?= !empty($g['is_private']) ? '&#128274; ' : '' ?><?= e($g['name']) ?></a></td><td><?= (int)$g['act7'] ?></td><td><?= (int)$g['new7'] ?></td><td><?= (int)$g['total'] ?></td></tr><?php endforeach; ?>
<?php if (!$s['top']): ?><tr><td colspan="4">No groups yet.</td></tr><?php endif; ?></tbody></table></div>
<h2>Most reported members (30 days)</h2>
<div class="scroll"><table class="bm-table"><thead><tr><th>Member</th><th>Reports on their posts</th><th>Reported posts</th><th></th></tr></thead><tbody>
<?php foreach ($s['reported'] as $r): ?><tr><td><?= e($r['name']) ?><br><small><?= e($r['email']) ?></small></td><td><?= (int)$r['rep'] ?></td><td><?= (int)$r['posts'] ?></td><td><a href="<?= e(url('admin/user-view.php?id=' . (int)$r['id'])) ?>">Open member</a></td></tr><?php endforeach; ?>
<?php if (!$s['reported']): ?><tr><td colspan="4">Nothing reported lately.</td></tr><?php endif; ?></tbody></table></div>
<h2>Reported private messages</h2>
<?php if (!$dmr): ?><div class="note">No reported private messages.</div><?php else: ?>
<div class="scroll"><table class="bm-table"><thead><tr><th>Message</th><th>From</th><th>Reports</th><th></th></tr></thead><tbody>
<?php foreach ($dmr as $r): ?><tr><td><?= e($r['body']) ?><br><small><?= e($r['created_at']) ?></small></td><td><?= e($r['name']) ?><br><small><?= e($r['email']) ?></small></td><td><?= (int)$r['n'] ?></td>
  <td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="msg" value="<?= (int)$r['msg_id'] ?>"><button class="btn small" name="action" value="dm_delete">Hide</button><button class="btn small ghost" name="action" value="dm_dismiss">Dismiss</button><button class="btn small danger" name="action" value="dm_ban" onclick="return confirm('Switch this member off from commenting and chatting?')">Ban</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<h2>Verified members</h2>
<?php if (!$mok): ?><div class="note">Public profiles are not set up yet.</div><?php else: ?>
<p class="sub">A member is verified automatically when they have at least <?= (int)max(10, (int)setting('verify_min_bets', '30')) ?> settled shared bets with a positive ROI. You can also verify, remove or block a member by username.</p>
<form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><?= csrf_field() ?><label>Minimum settled bets <input type="number" name="min" min="10" max="500" value="<?= (int)max(10, (int)setting('verify_min_bets', '30')) ?>" style="width:90px"></label><button class="btn small" name="action" value="minbets">Save</button></form>
<form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px"><?= csrf_field() ?><input name="handle" placeholder="username (without @)" maxlength="20" required style="max-width:220px">
  <button class="btn small" name="action" value="verify">Verify</button><button class="btn small ghost" name="action" value="unverify">Remove tick</button><button class="btn small danger" name="action" value="neverify">Never verify</button><button class="btn small ghost" name="action" value="autoverify" formnovalidate>Run automatic check now</button></form>
<?php if ($ver): ?><div class="scroll"><table class="bm-table"><thead><tr><th>Member</th><th>Status</th></tr></thead><tbody><?php foreach ($ver as $v): ?><tr><td><a href="<?= e(url('member.php?u=' . $v['handle'])) ?>">@<?= e($v['handle']) ?></a></td><td><?= (int)$v['verified'] === 1 ? 'Verified' : 'Blocked from automatic verification' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
