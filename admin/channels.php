<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();
$ready = channels_ready();
$errors = [];
$f = ['id' => 0, 'name' => '', 'category' => '', 'thumb' => '', 'url' => '', 'extra' => '', 'sort_order' => 0, 'is_active' => 1];
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $act = (string)($_POST['action'] ?? 'save');
        $id = (int)($_POST['id'] ?? 0);
        if ($act === 'delete' && $id) {
            $pdo->prepare('DELETE FROM channels WHERE id = ?')->execute([$id]); admin_log('channel.delete', 'channel#' . $id); set_flash('success', 'Channel deleted.'); redirect('admin/channels.php');
        } elseif ($act === 'toggle' && $id) {
            $pdo->prepare('UPDATE channels SET is_active = 1 - is_active WHERE id = ?')->execute([$id]); redirect('admin/channels.php');
        } else {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
            $cat = mb_substr(trim((string)($_POST['category'] ?? '')), 0, 40);
            $code = trim((string)($_POST['url'] ?? ''));
            $url = '';
            if ($code !== '') {
                if (preg_match('~^https?://\S+$~i', $code)) { $url = stream_clean($code); if ($url === '') { $errors[] = 'The main link is not valid.'; } }
                else { [$url, $we] = stream_widget_save($code); if ($we !== '') { $errors[] = $we; } }
            }
            [$extra, $xe] = sources_from_post(); if ($xe !== '') { $errors[] = $xe; }
            [$thumbVal, $te] = thumb_from_post(); if ($te !== '') { $errors[] = $te; }
            $old = '';
            if ($id) { $o = $pdo->prepare('SELECT thumb FROM channels WHERE id = ?'); $o->execute([$id]); $old = (string)$o->fetchColumn(); }
            $thumb = $thumbVal === null ? $old : (string)$thumbVal;
            if (mb_strlen($name) < 2) { $errors[] = 'Enter the channel name.'; }
            if ($url === '' && $extra === '') { $errors[] = 'Add at least one watch link or widget code.'; }
            if (thumb_src($thumb) === '') { $errors[] = 'A thumbnail picture is required: upload one or paste a picture link.'; }
            $sort = (int)($_POST['sort_order'] ?? 0); $act1 = !empty($_POST['is_active']) ? 1 : 0;
            if (!$errors) {
                if ($id) { $pdo->prepare('UPDATE channels SET name=?, category=?, thumb=?, url=?, extra=?, sort_order=?, is_active=? WHERE id=?')->execute([$name, $cat, $thumb, $url, $extra ?: null, $sort, $act1, $id]); admin_log('channel.edit', 'channel#' . $id, $name); }
                else { $pdo->prepare('INSERT INTO channels (name, category, thumb, url, extra, sort_order, is_active) VALUES (?,?,?,?,?,?,?)')->execute([$name, $cat, $thumb, $url, $extra ?: null, $sort, $act1]); admin_log('channel.add', $name); }
                set_flash('success', 'Channel saved.'); redirect('admin/channels.php');
            }
            $f = ['id' => $id, 'name' => $name, 'category' => $cat, 'thumb' => $thumb, 'url' => $url, 'extra' => $extra, 'sort_order' => $sort, 'is_active' => $act1];
        }
    }
} elseif ($ready && isset($_GET['edit'])) {
    $q = $pdo->prepare('SELECT * FROM channels WHERE id = ?'); $q->execute([(int)$_GET['edit']]); $f = $q->fetch() ?: $f;
}
$rows = $ready ? $pdo->query('SELECT * FROM channels ORDER BY sort_order, name LIMIT 500')->fetchAll() : [];
$cats = $ready ? $pdo->query("SELECT DISTINCT category FROM channels WHERE category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN) : [];
$admin_title = 'Channels';
$admin_active = 'channels';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Channels</h1>
<p class="sub">Game channels shown on the Channels page, like a movie site: a picture card with a Watch button. Every channel needs a thumbnail. Add as many watch buttons per channel as you like.</p>
<?php foreach ($errors as $er): ?><div class="alert error"><?= e($er) ?></div><?php endforeach; ?>
<?php if (!$ready): ?><div class="alert error">The channels table could not be created. Import database-update-45.sql.</div><?php else: ?>
<form method="post" enctype="multipart/form-data" class="card form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <h2><?= $f['id'] ? 'Edit channel' : 'Add a channel' ?></h2>
  <div class="formgrid">
    <div class="field"><label>Channel name</label><input type="text" name="name" value="<?= e($f['name']) ?>" maxlength="100" required placeholder="e.g. Premier League 1"></div>
    <div class="field"><label>Category (optional)</label><input type="text" name="category" value="<?= e($f['category']) ?>" list="chcats" maxlength="40" placeholder="Football, Basketball, Boxing..."><datalist id="chcats"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist></div>
    <div class="field full"><label>Main watch link or widget / embed code</label><textarea name="url" rows="2" style="font-family:monospace" maxlength="20000" placeholder="https://... or the provider's widget code"><?= e($f['url']) ?></textarea></div>
    <?= sources_form($f['extra'] ?? '') ?>
    <?= thumb_field((string)$f['thumb']) ?>
    <div class="field"><label>Order (small numbers first)</label><input type="number" name="sort_order" value="<?= (int)$f['sort_order'] ?>"></div>
    <div class="field check"><label><input type="checkbox" name="is_active" value="1"<?= $f['is_active'] ? ' checked' : '' ?>> Visible to members</label></div>
  </div>
  <button class="btn dark" type="submit"><?= $f['id'] ? 'Save changes' : 'Add channel' ?></button> <?php if ($f['id']): ?><a class="btn outline" href="<?= e(url('admin/channels.php')) ?>">Cancel</a><?php endif; ?>
</form>
<table class="tbl"><thead><tr><th></th><th>Channel</th><th>Category</th><th>Buttons</th><th>Opens</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr><td style="width:70px"><?= thumb_img($r['thumb'], 'thumb', $r['name']) ?></td><td><strong><?= e($r['name']) ?></strong><?= $r['is_active'] ? '' : ' (hidden)' ?></td><td><?= e($r['category']) ?></td><td><?= count(channel_buttons($r)) ?></td><td><?= (int)$r['clicks'] ?></td>
<td class="acts"><a class="btn outline tiny" href="<?= e(url('admin/channels.php?edit=' . (int)$r['id'])) ?>">Edit</a>
<form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" name="action" value="toggle"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form>
<form class="inl" method="post" onsubmit="return confirm('Delete this channel?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny danger" name="action" value="delete">Delete</button></form></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="6">No channels yet.</td></tr><?php endif; ?>
</tbody></table>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
