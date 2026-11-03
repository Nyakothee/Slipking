<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$u = current_user();
$ready = clubs_ready();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$u) { redirect('auth/login.php?next=' . rawurlencode('clubs.php')); }
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif ($ready && ($_POST['action'] ?? '') === 'create') {
        $name = post_str('name'); $about = mb_substr(post_str('about'), 0, 300);
        $own = db()->prepare('SELECT COUNT(*) FROM clubs WHERE owner_id = ?'); $own->execute([$u['id']]);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 60) { $errors[] = 'Club name must be 3 to 60 characters.'; }
        elseif ((int)$own->fetchColumn() >= 5) { $errors[] = 'You can own up to 5 clubs.'; }
        else {
            db()->prepare('INSERT INTO clubs (owner_id, name, about, invite_code, public_record) VALUES (?,?,?,?,?)')
                ->execute([$u['id'], $name, $about, club_new_code(), !empty($_POST['public_record']) ? 1 : 0]);
            $cid = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO club_members (club_id, user_id) VALUES (?,?)')->execute([$cid, $u['id']]);
            set_flash('success', 'Club created. Invite your friends from the club page.');
            redirect('club.php?id=' . $cid);
        }
    } elseif ($ready && ($_POST['action'] ?? '') === 'join') {
        $c = club_by_code(post_str('code'));
        if (!$c) { $errors[] = 'That invite code was not found.'; }
        else { redirect('club.php?join=' . rawurlencode($c['invite_code'])); }
    }
}
$mine = ($u && $ready) ? club_my((int)$u['id']) : [];
$pub = [];
if ($ready) {
    try { $pub = db()->query('SELECT c.id, c.name, c.about, (SELECT COUNT(*) FROM club_members x WHERE x.club_id = c.id) AS members FROM clubs c WHERE c.public_record = 1 ORDER BY c.created_at DESC LIMIT 12')->fetchAll(); } catch (Throwable $e) {}
}
$page_title = 'Private tipster clubs - ' . SITE_NAME;
$page_desc = 'Create a private club, invite friends, share selections and compete on a group leaderboard.';
$active = 'game';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Tipster clubs</h1>
  <p style="color:var(--mute);max-width:700px">Create a private club, invite friends with a link, share your 1X2 selections and compete on the club leaderboard. Owners choose whether the club record is private or public.</p>
  <?php foreach ($errors as $er): ?><div class="alert"><?= e($er) ?></div><?php endforeach; ?>
  <?php if (!$ready): ?><div class="alert">Clubs are not available yet. Import database-update-19.sql in phpMyAdmin.</div><?php endif; ?>
  <div class="cm-grid">
    <form method="post" class="cm-box" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <h3 style="margin-top:0">Create a club</h3>
      <div class="field"><label for="cn">Club name</label><input id="cn" name="name" maxlength="60" required></div>
      <div class="field"><label for="ca">About (optional)</label><input id="ca" name="about" maxlength="300"></div>
      <div class="field"><label><input type="checkbox" name="public_record" value="1"> Make the club record public (leaderboard and settled picks)</label></div>
      <button class="btn dark" type="submit">Create club</button>
    </form>
    <form method="post" class="cm-box" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="join">
      <h3 style="margin-top:0">Join with an invite code</h3>
      <div class="field"><label for="jc">Invite code</label><input id="jc" name="code" maxlength="16" required></div>
      <button class="btn dark" type="submit">Continue</button>
    </form>
  </div>
  <h2 style="margin-top:24px">My clubs</h2>
  <?php if (!$u): ?><div class="note"><a href="<?= e(url('auth/login.php?next=clubs.php')) ?>">Log in</a> to see your clubs.</div>
  <?php elseif ($mine): ?><div class="cm-grid"><?php foreach ($mine as $c): ?>
    <a class="cm-box" style="margin:0;text-decoration:none;color:inherit" href="<?= e(url('club.php?id=' . (int)$c['id'])) ?>"><strong><?= e($c['name']) ?></strong><br><small><?= (int)$c['members'] ?> member<?= (int)$c['members'] === 1 ? '' : 's' ?> &middot; <?= $c['public_record'] ? 'Public record' : 'Private' ?><?= (int)$c['owner_id'] === (int)$u['id'] ? ' &middot; You own it' : '' ?></small></a>
  <?php endforeach; ?></div>
  <?php else: ?><div class="note">You are not in a club yet.</div><?php endif; ?>
  <?php if ($pub): ?><h2 style="margin-top:24px">Clubs with a public record</h2><div class="cm-grid"><?php foreach ($pub as $c): ?>
    <a class="cm-box" style="margin:0;text-decoration:none;color:inherit" href="<?= e(url('club.php?id=' . (int)$c['id'])) ?>"><strong><?= e($c['name']) ?></strong><br><small><?= e($c['about']) ?> (<?= (int)$c['members'] ?> members)</small></a>
  <?php endforeach; ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
