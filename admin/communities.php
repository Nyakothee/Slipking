<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$ready = cmty_ready();
$edit = null;
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('admin/communities.php'); }
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = post_str('name'); $desc = mb_substr(post_str('description'), 0, 300);
        $rules = mb_substr(trim((string)($_POST['rules'] ?? '')), 0, 1500);
        $icon = mb_substr(post_str('icon'), 0, 4);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#155240';
        $sort = (int)($_POST['sort_no'] ?? 0);
        $teamKeys = mb_substr(preg_replace('/\s*,\s*/', ',', trim(post_str('team_keys'))), 0, 200);
        $modEmails = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['mods'] ?? ''))));
        $tags = mb_substr(preg_replace('/\s*,\s*/', ',', trim(post_str('tags'))), 0, 200);
        $priv = !empty($_POST['is_private']) ? 1 : 0;
        if (mb_strlen($name) < 3 || mb_strlen($name) > 80) { set_flash('error', 'The name must be 3 to 80 characters.'); redirect('admin/communities.php' . ($id ? '?edit=' . $id : '')); }
        $coverSql = ''; $coverArg = [];
        if (!empty($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && cmty_members_ready()) {
            $cerr = ''; $cu = current_user();
            $cv = media_store($_FILES['cover'], 'cover', (int)$cu['id'], 'square', $cerr, 4194304);
            if ($cv === null) { set_flash('error', $cerr !== '' ? $cerr : 'The cover picture could not be saved.'); redirect('admin/communities.php' . ($id ? '?edit=' . $id : '')); }
            $coverSql = ', cover=?'; $coverArg = [$cv];
        } elseif (!empty($_POST['remove_cover']) && $id && cmty_members_ready()) { $coverSql = ', cover=NULL'; }
        if ($id) {
            if (cmty_members_ready()) { db()->prepare('UPDATE communities SET tags=? WHERE id=?')->execute([$tags, $id]); }
            if (cmty3_ready()) { db()->prepare('UPDATE communities SET is_private=? WHERE id=?')->execute([$priv, $id]); }
            if (cmty2_ready()) {
                db()->prepare('UPDATE communities SET team_keys=? WHERE id=?')->execute([$teamKeys, $id]);
                db()->prepare('DELETE FROM community_mods WHERE community_id = ?')->execute([$id]);
                foreach (array_slice($modEmails, 0, 10) as $em) {
                    $uq = db()->prepare('SELECT id FROM users WHERE email = ?'); $uq->execute([$em]); $mid = (int)$uq->fetchColumn();
                    if ($mid) { db()->prepare('INSERT IGNORE INTO community_mods (community_id, user_id) VALUES (?, ?)')->execute([$id, $mid]); }
                }
            }
            db()->prepare('UPDATE communities SET name=?, description=?, rules=?, icon=?, color=?, sort_no=?' . $coverSql . ' WHERE id=?')->execute(array_merge([$name, $desc, $rules, $icon, $color, $sort], $coverArg, [$id]));
            admin_log('community.edit', (string)$id, $name); set_flash('success', 'Community updated.');
        } else {
            $slug = cmty_unique_slug($name);
            $u = current_user();
            db()->prepare('INSERT INTO communities (slug, name, description, rules, icon, color, sort_no, created_by) VALUES (?,?,?,?,?,?,?,?)')->execute([$slug, $name, $desc, $rules, $icon, $color, $sort, (int)$u['id']]);
            if ($coverArg) { db()->prepare('UPDATE communities SET cover=? WHERE slug=?')->execute([$coverArg[0], $slug]); }
            if (cmty2_ready()) { db()->prepare('UPDATE communities SET team_keys=? WHERE slug=?')->execute([$teamKeys, $slug]); }
            if ($tags !== '' && cmty_members_ready()) { db()->prepare('UPDATE communities SET tags=? WHERE slug=?')->execute([$tags, $slug]); }
            if ($priv && cmty3_ready()) { db()->prepare('UPDATE communities SET is_private=1 WHERE slug=?')->execute([$slug]); }
            admin_log('community.create', $slug, $name); set_flash('success', 'Community created: m/' . $slug);
        }
    } elseif ($act === 'toggle') {
        db()->prepare('UPDATE communities SET is_active = 1 - is_active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    }
    redirect('admin/communities.php');
}
if ($ready && isset($_GET['edit'])) { $edit = cmty_by_id((int)$_GET['edit']); }
$rows = $ready ? cmty_all(false) : [];
$admin_title = 'Communities'; $admin_active = 'communities';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Communities (m/...)</h1>
<p class="sub">Create topic rooms such as m/Arsenal-fans. Members join the conversation, post pictures and reply in threads. Reported posts are moderated like all comments.</p>
<?php if (!$ready): ?><div class="note">Communities need database-update-20.sql. Import it in phpMyAdmin (or open this page again after your host allows table changes).</div><?php else: ?>
<form method="post" class="box2" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
  <h3><?= $edit ? 'Edit m/' . e($edit['slug']) : 'Create a community' ?></h3>
  <div class="formgrid">
    <div class="field"><label>Name</label><input name="name" maxlength="80" required value="<?= e($edit['name'] ?? '') ?>" placeholder="Arsenal fans"></div>
    <div class="field"><label>Icon (one emoji, optional)</label><input name="icon" maxlength="4" value="<?= e($edit['icon'] ?? '') ?>" placeholder="🔴"></div>
    <div class="field"><label>Colour</label><input type="color" name="color" value="<?= e($edit['color'] ?? '#155240') ?>" style="height:42px;padding:2px"></div>
    <div class="field"><label>Order (small numbers first)</label><input type="number" name="sort_no" value="<?= (int)($edit['sort_no'] ?? 0) ?>"></div>
  </div>
  <div class="field"><label>Group picture (profile photo of the group, optional)</label><input type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif">
    <?php if (!empty($edit['cover'])): ?><img src="<?= e(media_src($edit['cover'])) ?>" alt="" style="max-height:90px;border-radius:8px;margin-top:6px"> <label style="font-weight:400"><input type="checkbox" name="remove_cover" value="1"> Remove picture</label><?php endif; ?></div>
  <div class="field"><label>Tags (comma separated, shown on the group page)</label><input name="tags" maxlength="200" value="<?= e($edit['tags'] ?? '') ?>" placeholder="Premier League, Tactics, Transfers"></div>
  <div class="field"><label><input type="checkbox" name="is_private" value="1"<?= !empty($edit['is_private']) ? ' checked' : '' ?>> Private group (new members must be approved by a moderator; posts are hidden from non-members)</label></div>
  <div class="field"><label>Team names for automatic match threads (comma separated, optional)</label><input name="team_keys" maxlength="200" value="<?= e($edit['team_keys'] ?? '') ?>" placeholder="Manchester United, Man Utd"><small>When one of these teams plays in the next 36 hours, a pinned "Match thread" post is created in the group by itself.</small></div>
  <?php $modList = ''; if ($edit && cmty2_ready()) { $mq = db()->prepare('SELECT u.email FROM community_mods m JOIN users u ON u.id = m.user_id WHERE m.community_id = ?'); $mq->execute([(int)$edit['id']]); $modList = implode(", ", array_column($mq->fetchAll(), 'email')); } ?>
  <div class="field"><label>Group moderators (member emails, comma separated)</label><input name="mods" value="<?= e($modList) ?>" placeholder="mod1@example.com, mod2@example.com"><small>Moderators can pin posts and remove posts and comments in this group. The group's creator is always a moderator.</small></div>
  <div class="field"><label>Short description</label><input name="description" maxlength="300" value="<?= e($edit['description'] ?? '') ?>" placeholder="Everything Gunners: match talk, transfers and banter."></div>
  <div class="field"><label>Rules (one per line)</label><textarea name="rules" rows="4" maxlength="1500"><?= e($edit['rules'] ?? "Be respectful.\nNo links, ads or tout accounts.\nNever share passwords or codes.") ?></textarea></div>
  <button class="btn dark" type="submit"><?= $edit ? 'Save changes' : 'Create community' ?></button>
  <?php if ($edit): ?><a class="btn ghost" href="<?= e(url('admin/communities.php')) ?>">Cancel</a><?php endif; ?>
</form>
<div class="scroll"><table class="bm-table"><thead><tr><th>Community</th><th>Link</th><th>Posts</th><th>Members</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr style="<?= $r['is_active'] ? '' : 'opacity:.5' ?>">
  <td><?= e(($r['icon'] !== '' ? $r['icon'] . ' ' : '') . $r['name']) ?></td>
  <td><a href="<?= e(url('m.php?s=' . $r['slug'])) ?>" target="_blank" rel="noopener">m/<?= e($r['slug']) ?></a></td>
  <td><?= (int)$r['posts'] ?></td><td><?= (int)$r['members'] ?></td>
  <td><a class="btn tiny" href="<?= e(url('admin/communities.php?edit=' . (int)$r['id'])) ?>">Edit</a>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" type="submit"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
