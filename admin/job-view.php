<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/jobs.php';
require_perm('jobs');
$pdo = db();
$ref = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)($_GET['ref'] ?? '')));
$st = $pdo->prepare('SELECT * FROM job_applications WHERE ref = ? LIMIT 1');
$st->execute([$ref]);
$a = $st->fetch();
if (!$a) { set_flash('error', 'Application not found.'); redirect('admin/jobs.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); }
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save') {
            $s = (string)($_POST['status'] ?? '');
            if (isset(job_statuses()[$s])) {
                $pdo->prepare('UPDATE job_applications SET status = ?, admin_note = ? WHERE id = ?')->execute([$s, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 3000), $a['id']]);
                admin_log('job.status', $ref, $a['status'] . ' -> ' . $s);
                set_flash('success', 'Saved.');
            }
        } elseif ($action === 'delete') {
            job_delete_files($a);
            $pdo->prepare('DELETE FROM job_applications WHERE id = ?')->execute([$a['id']]);
            admin_log('job.delete', $ref, $a['name']);
            set_flash('success', 'Application and its documents were deleted.');
            redirect('admin/jobs.php');
        }
    }
    redirect('admin/job-view.php?ref=' . urlencode($ref));
}
$ans = json_decode((string)$a['answers'], true) ?: [];
$form = job_form();
$admin_title = 'Application ' . $ref;
$admin_active = 'jobs';
include dirname(__DIR__) . '/includes/admin-header.php';
$core = ['role' => $a['role'], 'name' => $a['name'], 'email' => $a['email'], 'phone' => $a['phone'], 'country' => $a['country'], 'city' => $a['city'], 'dob' => $a['dob'], 'gender' => (string)$a['gender']];
$age = ''; try { $age = (new DateTime($a['dob']))->diff(new DateTime('today'))->y; } catch (Throwable $e) {}
$fileCols = ['photo' => 'photo_file', 'idscan' => 'id_file', 'cv' => 'cv_file', 'certs' => 'cert_file', 'work' => 'work_file'];
?>
<p><a href="<?= e(url('admin/jobs.php')) ?>">&larr; All applications</a></p>
<h1><?= e($a['name']) ?> <small style="font-size:16px;color:var(--mute)"><?= e($ref) ?></small></h1>
<p class="sub"><?= e($a['role']) ?> &middot; applied <?= e(date('j M Y, H:i', strtotime($a['created_at']))) ?> &middot; <?= e($a['country']) ?><?= $age !== '' ? ' &middot; age ' . (int)$age : '' ?></p>

<div class="panel" style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
  <?php if (!empty($a['photo_file'])): ?><a href="<?= e(url('admin/job-file.php?ref=' . urlencode($ref) . '&f=photo')) ?>" target="_blank" rel="noopener"><img src="<?= e(url('admin/job-file.php?ref=' . urlencode($ref) . '&f=photo')) ?>" alt="Photo of <?= e($a['name']) ?>" style="width:150px;height:190px;object-fit:cover;border-radius:12px;border:1px solid var(--line)"></a><?php endif; ?>
  <div style="flex:1;min-width:260px">
    <h2>Documents</h2>
    <ul>
    <?php foreach (job_files() as $k => $fd): $col = $fileCols[$k]; ?>
      <li><?= e($fd['label']) ?>: <?= !empty($a[$col]) ? '<a href="' . e(url('admin/job-file.php?ref=' . urlencode($ref) . '&f=' . $k)) . '" target="_blank" rel="noopener"><strong>Open</strong></a> (' . e(strtoupper(pathinfo((string)$a[$col], PATHINFO_EXTENSION))) . ')' : '<span style="color:var(--mute)">not uploaded</span>' ?></li>
    <?php endforeach; ?>
    </ul>
    <h2 style="margin-top:14px">Decision</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
      <div class="field"><label for="status">Status</label><select id="status" name="status"><?php foreach (job_statuses() as $k => $lab): ?><option value="<?= e($k) ?>"<?= $a['status'] === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="note">Private notes (only admins see them)</label><textarea id="note" name="note" rows="4" maxlength="3000" style="width:100%"><?= e((string)$a['admin_note']) ?></textarea></div>
      <button class="btn dark" type="submit">Save</button>
    </form>
    <form method="post" style="margin-top:10px" onsubmit="return confirm('Delete this application and all its documents for good?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn tiny loss" type="submit">Delete application</button></form>
  </div>
</div>

<div class="panel">
  <h2>Contact</h2>
  <table class="kv"><tr><td>Email</td><td><a href="mailto:<?= e($a['email']) ?>"><?= e($a['email']) ?></a></td></tr><tr><td>Phone</td><td><?= e($a['phone']) ?></td></tr>
  <?php if (!empty($ans['whatsapp'])): ?><tr><td>WhatsApp</td><td><?= e($ans['whatsapp']) ?></td></tr><?php endif; ?>
  <tr><td>Location</td><td><?= e($a['city']) ?>, <?= e($a['country']) ?></td></tr><tr><td>Date of birth</td><td><?= e($a['dob']) ?></td></tr><?php if ($a['gender']): ?><tr><td>Gender</td><td><?= e($a['gender']) ?></td></tr><?php endif; ?></table>
</div>

<?php foreach ($form as $sec): ?>
<div class="panel"><h2><?= e($sec['title']) ?></h2>
  <table class="kv"><?php foreach ($sec['fields'] as $k => $fd):
      if (!empty($fd['core'])) { if (in_array($k, ['role', 'country', 'city', 'dob', 'gender', 'name', 'email', 'phone'], true) && $sec['title'][0] !== '1') { continue; } $v = $core[$k] ?? ''; } else { $v = $ans[$k] ?? ''; }
      if (is_array($v)) { $v = implode(', ', $v); }
      if ($v === '' || $v === null) { continue; } ?>
    <tr><td style="width:34%;vertical-align:top"><?= e($fd['label']) ?></td><td><?php if ($fd['type'] === 'url' && preg_match('#^https?://#i', (string)$v)): ?><a href="<?= e($v) ?>" target="_blank" rel="noopener nofollow"><?= e($v) ?></a><?php else: ?><?= nl2br(e((string)$v)) ?><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
</div>
<?php endforeach; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
