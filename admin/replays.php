<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();
$ready = replays_ready();
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="slipking-replays-template.csv"');
    echo "\xEF\xBB\xBF" . "game name,date played,first half link,second half link,thumbnail link\r\nArsenal vs Chelsea,2026-10-25,https://example.com/first-half,https://example.com/second-half,https://example.com/picture.jpg\r\n";
    exit;
}
$errors = []; $f = ['title' => '', 'played_on' => '', 'link1' => '', 'link2' => '', 'paste' => ''];
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'add') {
            foreach (['title', 'played_on', 'link1', 'link2'] as $k) { $f[$k] = trim((string)($_POST[$k] ?? '')); }
            $l1 = replays_link($f['link1']); $l2 = replays_link($f['link2']);
            if (mb_strlen($f['title']) < 3) { $errors[] = 'Enter the game name.'; }
            if ($l1 === '' && $l2 === '') { $errors[] = 'Add at least one link starting with http:// or https://'; }
            if ($f['link1'] !== '' && $l1 === '') { $errors[] = 'The first-half link is not a valid http(s) link.'; }
            if ($f['link2'] !== '' && $l2 === '') { $errors[] = 'The second-half link is not a valid http(s) link.'; }
            [$thumbVal, $thumbErr] = thumb_from_post();
            if ($thumbErr !== '') { $errors[] = $thumbErr; }
            if (replays_count() >= REPLAYS_MAX) { $errors[] = 'The page already has ' . REPLAYS_MAX . ' games. Delete some first.'; }
            if (!$errors) {
                replays_add_rows([['title' => mb_substr($f['title'], 0, 160), 'played_on' => replays_date($f['played_on']), 'link1' => $l1 ?: $l2, 'link2' => $l2 ?: $l1, 'thumb' => (string)$thumbVal]]);
                admin_log('replay.add', $f['title']); set_flash('success', 'Replay added.'); redirect('admin/replays.php');
            }
        } elseif ($act === 'csv') {
            $csv = '';
            if (!empty($_FILES['csv']) && ($_FILES['csv']['error'] ?? 4) === UPLOAD_ERR_OK) {
                if ($_FILES['csv']['size'] > 1048576) { $errors[] = 'The file is over 1 MB.'; } else { $csv = (string)file_get_contents($_FILES['csv']['tmp_name']); }
            } elseif (trim((string)($_POST['paste'] ?? '')) !== '') { $csv = (string)$_POST['paste']; $f['paste'] = $csv; }
            else { $errors[] = 'Choose a CSV file or paste the rows.'; }
            if (!$errors) {
                [$rows, $bad] = replays_parse($csv);
                if (!$rows) { $errors = $bad ?: ['No valid rows found.']; }
                else {
                    [$added, $skipped] = replays_add_rows($rows);
                    admin_log('replay.csv', $added . ' added');
                    $msg = $added . ' replay(s) added.' . ($skipped ? ' ' . $skipped . ' skipped because the page holds at most ' . REPLAYS_MAX . ' games.' : '') . ($bad ? ' ' . count($bad) . ' row(s) had problems: ' . implode(' ', array_slice($bad, 0, 3)) : '');
                    set_flash($added ? 'success' : 'error', $msg); redirect('admin/replays.php');
                }
            }
        } elseif ($act === 'delete') {
            $pdo->prepare('DELETE FROM replays WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]); admin_log('replay.delete', '#' . (int)$_POST['id']); set_flash('success', 'Removed.'); redirect('admin/replays.php');
        } elseif ($act === 'clear') {
            $pdo->exec('DELETE FROM replays'); admin_log('replay.clear', 'all'); set_flash('success', 'All replays removed.'); redirect('admin/replays.php');
        }
    }
}
$rows = replays_list(); $n = count($rows);
$admin_title = 'Replays'; $admin_active = 'replays';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Replays</h1>
<p class="sub">Past games members can watch, in two halves. The page holds up to <?= REPLAYS_MAX ?> games (<b><?= $n ?></b> used). It appears under Results on the site and in the app.</p>
<?php if (!$ready): ?><div class="errors"><ul><li>Run <code>database-update-37.sql</code> once in phpMyAdmin.</li></ul></div><?php else: ?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid2" style="align-items:start">
<form method="post" class="panel" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="add"><h2>Add one game</h2>
  <div class="field"><label for="r-t">Game name</label><input id="r-t" name="title" value="<?= e($f['title']) ?>" maxlength="160" placeholder="Arsenal vs Chelsea"></div>
  <div class="field"><label for="r-d">Date played</label><input id="r-d" type="date" name="played_on" value="<?= e($f['played_on']) ?>"></div>
  <div class="field"><label for="r-1">First half link</label><input id="r-1" type="url" name="link1" value="<?= e($f['link1']) ?>" maxlength="500" placeholder="https://..."></div>
  <div class="field"><label for="r-2">Second half link</label><input id="r-2" type="url" name="link2" value="<?= e($f['link2']) ?>" maxlength="500" placeholder="https://..."><div class="hint">If you only have one link, put it in either box and it is used for both buttons.</div></div>
  <?= thumb_field('') ?>
  <button class="btn gold" type="submit">Add replay</button>
</form>
<form method="post" enctype="multipart/form-data" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="csv"><h2>Upload a CSV</h2>
  <div class="field"><label for="r-f">CSV file</label><input type="file" id="r-f" name="csv" accept=".csv,.txt,text/csv,text/plain"><div class="hint">Columns: game name, date played, first half link, second half link, and optionally a picture link (5th column). <a href="<?= e(url('admin/replays.php?template=1')) ?>" style="text-decoration:underline;font-weight:700">Download a template</a>.</div></div>
  <div class="field"><label for="r-p">...or paste rows</label><textarea id="r-p" name="paste" rows="5" style="font-family:monospace" placeholder="Arsenal vs Chelsea,2026-10-25,https://...,https://..."><?= e($f['paste']) ?></textarea></div>
  <button class="btn gold" type="submit">Add from CSV</button>
</form></div>
<div class="panel"><h2>Games on the page</h2>
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th></th><th>Game</th><th>Played</th><th>First half</th><th>Second half</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= thumb_img($r['thumb'] ?? '', 'thumb sm', '') ?></td><td><?= e($r['title']) ?></td><td><?= $r['played_on'] ? e(date('j M Y', strtotime($r['played_on']))) : '-' ?></td><td><a href="<?= e($r['link1']) ?>" target="_blank" rel="noopener noreferrer">Open</a></td><td><a href="<?= e($r['link2']) ?>" target="_blank" rel="noopener noreferrer">Open</a></td>
<td><form method="post" onsubmit="return confirm('Remove this game?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny outline" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post" style="margin-top:12px" onsubmit="return confirm('Remove ALL replays?')"><?= csrf_field() ?><input type="hidden" name="action" value="clear"><button class="btn tiny loss" type="submit">Remove all</button></form>
<?php else: ?><div class="empty">No replays yet.</div><?php endif; ?></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
