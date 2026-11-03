<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$pdo = db();
$u = current_user();
if (!clubs_ready()) { require __DIR__ . '/404.php'; exit; }

// Join through an invite link.
if (isset($_GET['join'])) {
    $c = club_by_code((string)$_GET['join']);
    if (!$c) { set_flash('error', 'That invite link is not valid.'); redirect('clubs.php'); }
    if (!$u) { redirect('auth/login.php?next=' . rawurlencode('clubs.php')); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
        if ((int)$c['members'] >= 50) { set_flash('error', 'This club is full (50 members).'); redirect('clubs.php'); }
        $pdo->prepare('INSERT IGNORE INTO club_members (club_id, user_id) VALUES (?,?)')->execute([$c['id'], $u['id']]);
        set_flash('success', 'Welcome to ' . $c['name'] . '!');
        redirect('club.php?id=' . (int)$c['id']);
    }
    if (club_is_member((int)$c['id'], (int)$u['id'])) { redirect('club.php?id=' . (int)$c['id']); }
    $page_title = 'Join ' . $c['name']; $active = 'game';
    include __DIR__ . '/includes/header.php'; ?>
    <div class="wrap page-top"><h1>Join "<?= e($c['name']) ?>"?</h1><p style="color:var(--mute)">Owned by <?= e($c['owner_name']) ?> &middot; <?= (int)$c['members'] ?> members</p>
    <form method="post"><?= csrf_field() ?><button class="btn dark" type="submit">Join this club</button></form></div>
    <?php include __DIR__ . '/includes/footer.php'; exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? club_get($id) : null;
if (!$c) { require __DIR__ . '/404.php'; exit; }
$member = $u && club_is_member($id, (int)$u['id']);
$owner = $u && (int)$c['owner_id'] === (int)$u['id'];
if (!$member && !$c['public_record']) {
    $page_title = 'Private club'; $active = 'game';
    include __DIR__ . '/includes/header.php';
    echo '<div class="wrap page-top"><h1>Private club</h1><p style="color:var(--mute)">This club is private. Ask a member for an invite link.</p></div>';
    include __DIR__ . '/includes/footer.php'; exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$member) { redirect('club.php?id=' . $id); }
    $act = $_POST['action'] ?? '';
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif ($act === 'pick') {
        $mid = (int)($_POST['match_id'] ?? 0); $pk = (string)($_POST['pick'] ?? ''); $odds = (float)($_POST['odds'] ?? 0);
        $q = $pdo->prepare('SELECT kickoff_at FROM matches WHERE id = ?'); $q->execute([$mid]); $ko = $q->fetchColumn();
        if (!$ko || strtotime($ko) <= time()) { $errors[] = 'That match is not open for selections.'; }
        elseif (!in_array($pk, ['1', 'X', '2'], true)) { $errors[] = 'Choose home, draw or away.'; }
        elseif ($odds < 1.01 || $odds > 100) { $errors[] = 'Odds must be between 1.01 and 100.'; }
        else {
            $pdo->prepare('INSERT INTO club_picks (club_id, user_id, match_id, pick, odds, note) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pick = VALUES(pick), odds = VALUES(odds), note = VALUES(note)')
                ->execute([$id, $u['id'], $mid, $pk, number_format($odds, 2, '.', ''), mb_substr(post_str('note'), 0, 160)]);
            set_flash('success', 'Selection shared with the club.'); redirect('club.php?id=' . $id);
        }
    } elseif ($act === 'invite') {
        $sent = 0;
        foreach (array_slice(preg_split('/[\s,;]+/', (string)($_POST['emails'] ?? '')), 0, 5) as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) { continue; }
            $link = url('club.php?join=' . $c['invite_code']);
            if (send_mail($em, $u['name'] . ' invited you to the club "' . $c['name'] . '"', $u['name'] . " invited you to join the club \"" . $c['name'] . "\" on " . SITE_NAME . ".\n\nJoin here: " . $link . "\n\nIf you do not know this person, ignore this email.")) { $sent++; }
        }
        set_flash($sent ? 'success' : 'error', $sent ? "Invitation sent to $sent address" . ($sent === 1 ? '' : 'es') . '.' : 'No valid email address, or the email could not be sent. Share the invite link instead.');
        redirect('club.php?id=' . $id);
    } elseif ($act === 'leave' && !$owner) {
        $pdo->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')->execute([$id, $u['id']]);
        set_flash('success', 'You left the club.'); redirect('clubs.php');
    } elseif ($owner && $act === 'settings') {
        $pdo->prepare('UPDATE clubs SET name = ?, about = ?, public_record = ? WHERE id = ?')
            ->execute([mb_substr(post_str('name'), 0, 60) ?: $c['name'], mb_substr(post_str('about'), 0, 300), !empty($_POST['public_record']) ? 1 : 0, $id]);
        set_flash('success', 'Club settings saved.'); redirect('club.php?id=' . $id);
    } elseif ($owner && $act === 'kick') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid !== (int)$u['id']) { $pdo->prepare('DELETE FROM club_members WHERE club_id = ? AND user_id = ?')->execute([$id, $uid]); $pdo->prepare('DELETE FROM club_picks WHERE club_id = ? AND user_id = ?')->execute([$id, $uid]); }
        set_flash('success', 'Member removed.'); redirect('club.php?id=' . $id);
    } elseif ($owner && $act === 'delete') {
        foreach (['club_picks', 'club_members', 'clubs'] as $t) { $pdo->prepare("DELETE FROM $t WHERE " . ($t === 'clubs' ? 'id' : 'club_id') . ' = ?')->execute([$id]); }
        set_flash('success', 'Club deleted.'); redirect('clubs.php');
    }
}

$allPicks = club_picks($id);
$picks = $member ? $allPicks : array_values(array_filter($allPicks, function ($p) { return $p['result'] !== 'pending'; }));
$board = club_leaderboard($allPicks);
$open = [];
if ($member) {
    $open = $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id WHERE m.kickoff_at > NOW() + INTERVAL 5 MINUTE AND m.kickoff_at < NOW() + INTERVAL 7 DAY ORDER BY m.kickoff_at LIMIT 100")->fetchAll();
}
$mem = $member ? $pdo->prepare('SELECT u.id, u.name FROM club_members cm JOIN users u ON u.id = cm.user_id WHERE cm.club_id = ? ORDER BY cm.joined_at') : null;
if ($mem) { $mem->execute([$id]); $mem = $mem->fetchAll(); }
$link = url('club.php?join=' . $c['invite_code']);
$page_title = $c['name'] . ' - club - ' . SITE_NAME;
$active = 'game';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('clubs.php')) ?>">Clubs</a> / <?= e($c['name']) ?></div>
  <h1><?= e($c['name']) ?> <small style="font-size:14px;color:var(--mute)"><?= $c['public_record'] ? 'Public record' : 'Private club' ?></small></h1>
  <?php if ($c['about'] !== ''): ?><p style="color:var(--mute)"><?= e($c['about']) ?></p><?php endif; ?>
  <?php foreach ($errors as $er): ?><div class="alert"><?= e($er) ?></div><?php endforeach; ?>
  <div class="cm-box"><h3 style="margin-top:0">Leaderboard</h3><?= club_board_html($board) ?></div>
  <?php if ($member): ?>
  <div class="cm-grid">
    <form method="post" class="cm-box" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="pick"><input type="hidden" name="id" value="<?= $id ?>">
      <h3 style="margin-top:0">Share a selection</h3>
      <div class="field"><label for="mp">Match</label><select id="mp" name="match_id" required><option value="">Choose a match...</option>
        <?php foreach ($open as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['home_team'] . ' vs ' . $m['away_team'] . ' (' . date('D j M H:i', strtotime($m['kickoff_at'])) . ')') ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="pk">Selection</label><select id="pk" name="pick"><option value="1">Home win</option><option value="X">Draw</option><option value="2">Away win</option></select></div>
      <div class="field"><label for="od">Odds</label><input id="od" name="odds" type="number" step="0.01" min="1.01" max="100" required></div>
      <div class="field"><label for="nt">Note (optional)</label><input id="nt" name="note" maxlength="160"></div>
      <button class="btn dark" type="submit">Share</button>
    </form>
    <div class="cm-box" style="margin:0"><h3 style="margin-top:0">Invite friends</h3>
      <p style="font-size:14px">Invite link (anyone logged in can join):</p>
      <input readonly value="<?= e($link) ?>" onclick="this.select()" style="width:100%">
      <p style="font-size:14px">Code: <strong><?= e($c['invite_code']) ?></strong></p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="invite"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label for="em">Email addresses (up to 5)</label><input id="em" name="emails" placeholder="a@x.com, b@y.com"></div>
        <button class="btn" type="submit">Send invitations</button></form>
    </div>
  </div>
  <?php endif; ?>
  <h2 style="margin-top:22px">Shared selections</h2>
  <?= club_picks_html($picks) ?>
  <?php if (!$member && $c['public_record']): ?><p class="note">This club shares its record publicly. Only settled selections are shown.</p><?php endif; ?>
  <?php if ($member): ?>
  <h2 style="margin-top:22px">Members (<?= count($mem) ?>)</h2>
  <ul><?php foreach ($mem as $mm): ?><li><?= e($mm['name']) ?><?= (int)$mm['id'] === (int)$c['owner_id'] ? ' (owner)' : '' ?>
    <?php if ($owner && (int)$mm['id'] !== (int)$u['id']): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="kick"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="user_id" value="<?= (int)$mm['id'] ?>"><button class="btn tiny" type="submit">Remove</button></form><?php endif; ?></li><?php endforeach; ?></ul>
  <?php if ($owner): ?>
  <form method="post" class="cm-box"><?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="id" value="<?= $id ?>">
    <h3 style="margin-top:0">Club settings</h3>
    <div class="field"><label for="sn">Name</label><input id="sn" name="name" value="<?= e($c['name']) ?>" maxlength="60"></div>
    <div class="field"><label for="sa">About</label><input id="sa" name="about" value="<?= e($c['about']) ?>" maxlength="300"></div>
    <div class="field"><label><input type="checkbox" name="public_record" value="1"<?= $c['public_record'] ? ' checked' : '' ?>> Public record: anyone can see the leaderboard and settled picks. Unticked = members only.</label></div>
    <button class="btn dark" type="submit">Save</button></form>
  <form method="post" onsubmit="return confirm('Delete this club and all its selections?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn" type="submit">Delete club</button></form>
  <?php else: ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leave"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn" type="submit">Leave club</button></form><?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
