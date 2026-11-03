<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$handle = strtolower((string)($_GET['u'] ?? ''));
$m = member_by_handle($handle);
if (!$m) { require __DIR__ . '/404.php'; exit; }
$u = current_user();
$uid = (int)$m['user_id'];
$isMe = $u && (int)$u['id'] === $uid;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$u) { set_flash('error', 'Log in to follow members.'); redirect('auth/login.php?next=' . rawurlencode('member.php?u=' . $handle)); }
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('member.php?u=' . $handle); }
    if (!$isMe) {
        if (post_str('action') === 'follow') {
            $ins = db()->prepare('INSERT IGNORE INTO member_follows (follower_id, member_id) VALUES (?, ?)'); $ins->execute([(int)$u['id'], $uid]);
            if ($ins->rowCount()) { cmty_alert($uid, 'New follower', 'Someone started following @' . $handle . '.', 'member.php?u=' . $handle); }
            set_flash('success', 'You follow @' . $handle . '. You will be told when they share a new bet.');
        } else {
            db()->prepare('DELETE FROM member_follows WHERE follower_id = ? AND member_id = ?')->execute([(int)$u['id'], $uid]);
            set_flash('success', 'You no longer follow @' . $handle . '.');
        }
    }
    redirect('member.php?u=' . $handle);
}
$showStakes = (int)$m['show_stakes'] === 1;
$st = member_stats($uid, $showStakes);
$followers = member_followers($uid);
$following = member_is_following($u ? (int)$u['id'] : 0, $uid);
$per = 25; $page = max(1, (int)($_GET['page'] ?? 1));
$cq = db()->prepare('SELECT COUNT(*) FROM user_bets WHERE user_id = ? AND is_public = 1 AND deleted_at IS NULL'); $cq->execute([$uid]);
$total = (int)$cq->fetchColumn(); $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
$bq = db()->prepare('SELECT description, bookmaker, odds, stake, status, placed_at, settled_at, notes, tip_id FROM user_bets WHERE user_id = ? AND is_public = 1 AND deleted_at IS NULL ORDER BY placed_at DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$bq->execute([$uid]); $bets = $bq->fetchAll();
$since = db()->prepare('SELECT MIN(placed_at) FROM user_bets WHERE user_id = ? AND is_public = 1 AND deleted_at IS NULL'); $since->execute([$uid]); $firstAt = $since->fetchColumn();
$page_title = '@' . $handle . ' - betting record - ' . SITE_NAME;
$page_desc = '@' . $handle . ' shares their tracked bets on ' . SITE_NAME . ': ' . $st['settled'] . ' settled bets, ' . ($st['win_rate'] ?? 0) . '% win rate.';
$active = 'members';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/members.css')) . '?v=1">' . "\n" . '<link rel="stylesheet" href="' . e(url('assets/css/tipster-profile.css')) . '?v=2">' . "\n";
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top" style="max-width:980px">
  <div class="crumbs"><a href="<?= e(url('members.php')) ?>">Top members</a> / @<?= e($handle) ?></div>
  <div class="mb-hero">
    <div class="mb-hav"><?= user_avatar_html($m, 84) ?></div>
    <div class="mb-id"><?php $mf = member_flags([$uid]); $ml = member_levels([$uid]); ?><h1>@<?= e($handle) ?> <?= member_flag($m, 22) ?><?= member_verified_html($mf[$uid] ?? null) ?></h1><p style="margin:2px 0"><?= member_level_html($ml[$uid] ?? null) ?></p>
      <?php if ($m['bio'] !== ''): ?><p><?= e($m['bio']) ?></p><?php endif; ?>
      <div class="mb-meta"><span><b><?= (int)$followers ?></b> follower<?= $followers === 1 ? '' : 's' ?></span><?php if ($firstAt): ?><span>Sharing since <?= e(date('M Y', strtotime($firstAt))) ?></span><?php endif; ?></div></div>
    <div class="mb-act">
      <?php if ($isMe): ?><a class="btn gold small" href="<?= e(url('account/public-profile.php')) ?>">Edit my profile</a>
      <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $following ? 'unfollow' : 'follow' ?>"><button class="btn <?= $following ? 'ghost' : 'gold' ?>" type="submit"><?= $following ? 'Following' : '+ Follow' ?></button></form> <?php if ($u): ?><a class="btn ghost" href="<?= e(url('account/messages.php?with=' . $uid)) ?>">&#9993; Message</a><?php endif; ?><?php endif; ?>
    </div>
  </div>
  <div class="mb-warn">&#9888; These bets are <strong>self-reported by the member</strong> and are not verified by <?= e(SITE_NAME) ?>. Past results do not guarantee future results. Not betting advice. 18+.</div>
  <div class="mb-stats big">
    <div><b class="<?= $st['units'] > 0 ? 'won' : ($st['units'] < 0 ? 'lost' : '') ?>"><?= ($st['units'] > 0 ? '+' : '') . e(number_format($st['units'], 2)) ?></b><span>Units (flat 1-unit stake)</span></div>
    <div><b><?= $st['win_rate'] === null ? '-' : e($st['win_rate']) . '%' ?></b><span>Win rate (<?= (int)$st['won'] ?>W <?= (int)$st['lost'] ?>L)</span></div>
    <div><b><?= $st['roi'] === null ? '-' : ($st['roi'] > 0 ? '+' : '') . e($st['roi']) . '%' ?></b><span>ROI per bet</span></div>
    <div><b><?= $st['avg_odds'] === null ? '-' : e($st['avg_odds']) ?></b><span>Average odds</span></div>
    <?php if ($showStakes && $st['profit'] !== null): ?><div><b class="<?= $st['profit'] >= 0 ? 'won' : 'lost' ?>"><?= ($st['profit'] > 0 ? '+' : '') . e(number_format($st['profit'], 2)) ?></b><span>Profit (own currency)</span></div><?php endif; ?>
  </div>
  <div class="mb-card"><div class="mb-formrow"><strong>Last <?= count($st['form']) ?: 10 ?> settled</strong><span class="mb-form"><?= member_form_html($st['form']) ?></span>
    <?php if ($st['streak'] >= 2): ?><span class="mb-streak"><?= (int)$st['streak'] ?> <?= $st['streak_type'] === 'won' ? 'wins' : 'losses' ?> in a row</span><?php endif; ?>
    <span class="mb-pend"><?= (int)$st['pending'] ?> pending &middot; <?= (int)$st['void'] ?> void</span>
    <?php if ($st['removed']): ?><span class="mb-rem" title="Bets the member deleted in the last 30 days, so a record cannot be quietly tidied up"><?= (int)$st['removed'] ?> removed (30 days)</span><?php endif; ?></div></div>
  <h2 style="margin:22px 0 10px">Shared bets</h2>
  <?php if ($bets): ?>
    <div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Bet</th><th>Odds</th><?php if ($showStakes): ?><th>Stake</th><?php endif; ?><th>Result</th></tr></thead><tbody>
    <?php foreach ($bets as $b): $cls = ['won' => 'won', 'lost' => 'lost'][$b['status']] ?? 'pending'; ?>
      <tr><td><?= e(date('j M Y, H:i', strtotime($b['placed_at']))) ?></td>
        <td><strong><?= e($b['description']) ?></strong><?php if (!empty($b['bookmaker'])): ?><br><small style="color:var(--mute)"><?= e($b['bookmaker']) ?></small><?php endif; ?>
          <?php if (!empty($b['notes'])): ?><br><small style="color:var(--mute)"><?= e($b['notes']) ?></small><?php endif; ?></td>
        <td><?= e(number_format((float)$b['odds'], 2)) ?></td><?php if ($showStakes): ?><td><?= e(number_format((float)$b['stake'], 2)) ?></td><?php endif; ?>
        <td><span class="badge <?= e($cls) ?>"><?= e(ucfirst($b['status'])) ?></span></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?= pager($page, $pages, 'member.php') ?>
  <?php else: ?><div class="empty">@<?= e($handle) ?> has not shared any bets yet.</div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
