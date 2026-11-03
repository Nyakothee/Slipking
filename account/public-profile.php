<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$pdo = db();
$ready = members_ready();
$me = $ready ? member_row_for_user($uid) : null;
$f = ['handle' => $me['handle'] ?? '', 'bio' => $me['bio'] ?? '', 'on' => !empty($me['is_public']), 'stakes' => !empty($me['show_stakes'])];
$errors = [];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('account/public-profile.php'); }
    $act = post_str('action');
    if ($act === 'share_all' || $act === 'share_none') {
        $pdo->prepare('UPDATE user_bets SET is_public = ? WHERE user_id = ? AND deleted_at IS NULL')->execute([$act === 'share_all' ? 1 : 0, $uid]);
        set_flash('success', $act === 'share_all' ? 'All your tracked bets are now shared.' : 'None of your bets are shared now.');
        redirect('account/public-profile.php');
    }
    $f['handle'] = strtolower(post_str('handle'));
    $f['bio'] = trim((string)($_POST['bio'] ?? ''));
    $f['on'] = !empty($_POST['is_public']);
    $f['stakes'] = !empty($_POST['show_stakes']);
    if ($f['handle'] === '' && !$f['on']) { /* nothing to validate while the profile stays off and unnamed */ }
    else {
        if ($m = member_handle_error($f['handle'], $uid)) { $errors[] = $m; }
    }
    if (mb_strlen($f['bio']) > 160) { $errors[] = 'Your bio is too long (160 characters maximum).'; }
    if (preg_match('~(https?:|www\.|\b[a-z0-9\-]+\.(com|net|org|co|io|ke|info|me|tv|bet|xyz)\b)~i', $f['bio'])) { $errors[] = 'Links are not allowed in your bio.'; }
    if (function_exists('looks_like_secret') && looks_like_secret($f['bio'])) { $errors[] = 'Never share passwords or codes.'; }
    if (!$errors && $f['handle'] !== '') {
        $pdo->prepare('INSERT INTO member_public (user_id, handle, bio, is_public, show_stakes) VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE handle = VALUES(handle), bio = VALUES(bio), is_public = VALUES(is_public), show_stakes = VALUES(show_stakes)')
            ->execute([$uid, $f['handle'], $f['bio'], $f['on'] ? 1 : 0, $f['stakes'] ? 1 : 0]);
        set_flash('success', $f['on'] ? 'Your public profile is live.' : 'Saved. Your profile is hidden from everyone.');
        redirect('account/public-profile.php');
    } elseif (!$errors) { set_flash('error', 'Choose a username first.'); redirect('account/public-profile.php'); }
}
$stats = ($ready && $me) ? member_stats($uid, !empty($me['show_stakes'])) : null;
$shared = 0; $allBets = 0;
if ($ready) {
    $q = $pdo->prepare('SELECT COUNT(*), SUM(is_public = 1) FROM user_bets WHERE user_id = ? AND deleted_at IS NULL'); $q->execute([$uid]);
    [$allBets, $shared] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
}
$followers = ($ready && $me) ? member_followers($uid) : 0;
$page_title = 'My public profile - ' . SITE_NAME;
$active = ''; $acct = 'public';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/tipster-profile.css')) . '?v=2">' . "\n" . '<link rel="stylesheet" href="' . e(url('assets/css/members.css')) . '?v=1">' . "\n" . '<meta name="robots" content="noindex,nofollow">' . "\n";
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap" style="max-width:920px">
  <div class="head"><h2>Public profile</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <?php if (!$ready): ?><div class="note">Public profiles need database-update-21.sql. Import it in phpMyAdmin.</div><?php else: ?>
  <?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
  <div class="mb-hero">
    <div class="mb-hav"><?= user_avatar_html($user, 72) ?></div>
    <div><h1><?= $f['handle'] !== '' ? '@' . e($f['handle']) : 'Create your public profile' ?></h1>
      <p><?= $f['on'] ? 'Live. ' . (int)$followers . ' follower' . ($followers === 1 ? '' : 's') . ' &middot; ' . (int)$shared . ' shared bet' . ($shared === 1 ? '' : 's') : 'Share your tracked bets, build a record and get followers.' ?></p>
      <?php if ($me && $f['on']): ?><a class="btn gold small" href="<?= e(url('member.php?u=' . $me['handle'])) ?>">View my public page</a><?php endif; ?></div>
  </div>
  <form method="post" class="mb-card"><?= csrf_field() ?>
    <div class="tf"><label for="handle">Username</label><div class="tf-in"><input id="handle" name="handle" maxlength="20" value="<?= e($f['handle']) ?>" placeholder="e.g. sharp_kev" autocomplete="off" style="text-transform:lowercase"></div><small>3 to 20 characters: lowercase letters, numbers, underscore. This is the only name shown, not your real name.</small></div>
    <div class="tf"><label for="bio">Short bio (optional)</label><div class="tf-in"><input id="bio" name="bio" maxlength="160" value="<?= e($f['bio']) ?>" placeholder="e.g. Premier League goals and BTTS"></div></div>
    <label class="tpp-switch"><input type="checkbox" name="is_public" value="1"<?= $f['on'] ? ' checked' : '' ?>><span class="tpp-sw"></span><span class="tpp-swt"><strong>Show my public profile</strong><small>Anyone can see your username, country flag and the bets you choose to share. Turn it off any time.</small></span></label>
    <label class="tpp-switch"><input type="checkbox" name="show_stakes" value="1"<?= $f['stakes'] ? ' checked' : '' ?>><span class="tpp-sw"></span><span class="tpp-swt"><strong>Also show my stakes and profit</strong><small>Off by default. Your record then shows in units (a flat 1-unit stake per bet), so nobody sees your money amounts.</small></span></label>
    <button class="btn dark" type="submit">Save</button>
  </form>
  <div class="mb-card">
    <h3>Which bets are public?</h3>
    <p class="mb-sub">You have <?= (int)$allBets ?> tracked bet<?= $allBets === 1 ? '' : 's' ?>; <?= (int)$shared ?> shared. You can also switch single bets in your <a href="<?= e(url('account/tracker.php')) ?>" style="text-decoration:underline">Bet tracker</a>.</p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><?= csrf_field() ?>
      <button class="btn dark small" name="action" value="share_all" onclick="return confirm('Share ALL your tracked bets on your public profile?')">Share all my bets</button>
      <button class="btn ghost small" name="action" value="share_none">Make all private</button></form>
    <p class="mb-sub" style="margin-top:10px">Shared bets are self-reported by you. SlipKing does not verify them, and says so on your page. Removed bets are counted publicly for 30 days, so a record cannot be quietly tidied up.</p>
  </div>
  <?php if ($stats && $stats['settled']): ?><div class="mb-card"><h3>Your record right now</h3>
    <div class="mb-stats"><div><b class="<?= $stats['units'] >= 0 ? 'won' : 'lost' ?>"><?= ($stats['units'] > 0 ? '+' : '') . e(number_format($stats['units'], 2)) ?></b><span>Units</span></div><div><b><?= e($stats['win_rate']) ?>%</b><span>Win rate</span></div><div><b><?= (int)$stats['settled'] ?></b><span>Settled bets</span></div><div><b><?= e($stats['avg_odds']) ?></b><span>Average odds</span></div></div></div><?php endif; ?>
  <?php endif; ?>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
