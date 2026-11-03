<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('messages');
$pdo = db();
dm2_ready();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';
    if ($act === 'add') {
        $b = trim(mb_substr((string)($_POST['body'] ?? ''), 0, 300));
        $hrs = max(0, min(720, (int)($_POST['hours'] ?? 0)));
        if ($b !== '') {
            $pdo->prepare('INSERT INTO chat_pins (room, body, expires_at) VALUES (?,?,' . ($hrs ? 'DATE_ADD(NOW(), INTERVAL ' . $hrs . ' HOUR)' : 'NULL') . ')')->execute([max(0, (int)($_POST['room'] ?? 0)), $b]);
            admin_log('chatpin.add', 'room#' . (int)($_POST['room'] ?? 0), $b);
        }
    } elseif ($act === 'del') {
        $pdo->prepare('DELETE FROM chat_pins WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    } elseif ($act === 'rules') {
        set_setting('chat_rules', trim(mb_substr((string)($_POST['rules'] ?? ''), 0, 1200)));
        set_setting('chat_banned_words', trim(mb_substr((string)($_POST['words'] ?? ''), 0, 1500)));
        admin_log('chatrules.save', 'chat', '');
        set_flash('success', 'Saved.');
    }
    redirect('admin/chat-pins.php');
}
$pins = $pdo->query('SELECT * FROM chat_pins ORDER BY id DESC LIMIT 50')->fetchAll();
$admin_title = 'Chat pins and rules';
$admin_active = 'chatpins';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="panel"><h2>Pinned announcement</h2>
  <p style="color:var(--mute)">Shown at the top of a chat. Room 0 = Global Chat and every other chat. Or type a group's number to pin only there.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="field"><label for="body">Text</label><input type="text" id="body" name="body" maxlength="300" required></div>
    <div class="field"><label for="room">Room number</label><input type="number" id="room" name="room" value="0" min="0"></div>
    <div class="field"><label for="hours">Show for (hours, 0 = until removed)</label><input type="number" id="hours" name="hours" value="0" min="0" max="720"></div>
    <button class="btn gold" type="submit">Pin</button></form>
  <?php foreach ($pins as $p): ?><div class="note-item">Room <?= (int)$p['room'] ?>: <?= e($p['body']) ?> <small>(<?= $p['expires_at'] ? 'until ' . e($p['expires_at']) : 'no end' ?>)</small>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn dark tiny">Remove</button></form></div><?php endforeach; ?>
</div>
<div class="panel"><h2>Chat rules popup and word filter</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="rules">
    <div class="field"><label for="rules">Rules members must accept the first time they chat</label><textarea id="rules" name="rules" rows="6"><?= e(chat_rules_text()) ?></textarea></div>
    <div class="field"><label for="words">Blocked words or phrases (comma separated)</label><textarea id="words" name="words" rows="3"><?= e(setting('chat_banned_words', 'fuck,bitch,whatsapp me,dm me for tips,fixed match,sure odds')) ?></textarea></div>
    <button class="btn gold" type="submit">Save</button></form>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
