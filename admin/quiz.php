<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$ready = arena_ready();
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); }
    elseif (($_POST['action'] ?? '') === 'add') {
        $q = post_str('question'); $o = [post_str('o1'), post_str('o2'), post_str('o3'), post_str('o4')]; $c = (int)($_POST['correct'] ?? 0); $cat = (string)($_POST['category'] ?? '');
        if ($q === '' || in_array('', $o, true) || $c < 1 || $c > 4 || !isset(arena_categories()[$cat]) || $cat === 'all') { set_flash('error', 'Fill every field and choose a category and the correct option.'); }
        else {
            $ins = db()->prepare('INSERT IGNORE INTO quiz_questions (qhash, category, question, opt1, opt2, opt3, opt4, correct) VALUES (MD5(?),?,?,?,?,?,?,?)');
            $ins->execute([$q, $cat, mb_substr($q, 0, 300), mb_substr($o[0], 0, 120), mb_substr($o[1], 0, 120), mb_substr($o[2], 0, 120), mb_substr($o[3], 0, 120), $c]);
            set_flash($ins->rowCount() ? 'success' : 'error', $ins->rowCount() ? 'Question added.' : 'That question already exists.');
            if ($ins->rowCount()) { admin_log('quiz.add', '', mb_substr($q, 0, 80)); }
        }
    } elseif (($_POST['action'] ?? '') === 'toggle') {
        db()->prepare('UPDATE quiz_questions SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect('admin/quiz.php');
}
$rows = $ready ? db()->query('SELECT * FROM quiz_questions ORDER BY id DESC LIMIT 300')->fetchAll() : [];
$admin_title = 'Arena questions'; $admin_active = 'quiz';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Arena question bank</h1>
<p class="sub"><?= count($rows) ?> questions shown (newest first). Disabled questions are never asked.</p>
<form method="post" class="box2"><?= csrf_field() ?><input type="hidden" name="action" value="add">
  <h3>Add a question</h3>
  <div class="field"><label>Category</label><select name="category"><?php foreach (arena_categories() as $k => $l): if ($k === 'all') continue; ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>Question</label><input name="question" maxlength="300" required></div>
  <?php for ($i = 1; $i <= 4; $i++): ?><div class="field"><label>Option <?= $i ?></label><input name="o<?= $i ?>" maxlength="120" required></div><?php endfor; ?>
  <div class="field"><label>Correct option</label><select name="correct"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>">Option <?= $i ?></option><?php endfor; ?></select></div>
  <button class="btn dark" type="submit">Add</button></form>
<div class="scroll"><table class="bm-table"><thead><tr><th>Category</th><th>Question</th><th>Correct answer</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr style="<?= $r['is_active'] ? '' : 'opacity:.5' ?>"><td><?= e($r['category']) ?></td><td><?= e($r['question']) ?></td><td><?= e($r['opt' . (int)$r['correct']]) ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" type="submit"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
