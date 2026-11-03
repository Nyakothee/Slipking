<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/jobs.php';
require_perm('jobs');
$pdo = db();
$ready = jobs_table_ok();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); }
    elseif (($_POST['action'] ?? '') === 'toggle') {
        $on = jobs_open() ? '0' : '1';
        set_setting('jobs_open', $on);
        admin_log('jobs.toggle', '', $on === '1' ? 'applications open' : 'applications closed');
        set_flash('success', $on === '1' ? 'The job form is open.' : 'The job form is closed. Visitors with the link see "Applications are closed".');
    }
    redirect('admin/jobs.php');
}

$status = (string)($_GET['status'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$role = trim((string)($_GET['role'] ?? ''));
$where = ['1=1']; $args = [];
if (isset(job_statuses()[$status])) { $where[] = 'status = ?'; $args[] = $status; }
if ($role !== '') { $where[] = 'role = ?'; $args[] = $role; }
if ($q !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR ref LIKE ? OR phone LIKE ? OR country LIKE ?)'; $l = '%' . $q . '%'; array_push($args, $l, $l, $l, $l, $l); }
$rows = []; $counts = []; $roles = [];
if ($ready) {
    $st = $pdo->prepare('SELECT * FROM job_applications WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 500');
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($pdo->query('SELECT status, COUNT(*) c FROM job_applications GROUP BY status')->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; }
    $roles = $pdo->query('SELECT DISTINCT role FROM job_applications ORDER BY role')->fetchAll(PDO::FETCH_COLUMN);
    if (($_GET['export'] ?? '') === 'csv') {
        admin_log('jobs.export', '', count($rows) . ' rows');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="job-applications-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Reference', 'Date', 'Status', 'Position', 'Name', 'Email', 'Phone', 'Country', 'City', 'Date of birth', 'Gender', 'Expected pay', 'Experience (years in field)', 'Start'], ',', '"', '');
        foreach ($rows as $r) {
            $a = json_decode((string)$r['answers'], true) ?: [];
            fputcsv($out, [$r['ref'], $r['created_at'], $r['status'], $r['role'], $r['name'], $r['email'], $r['phone'], $r['country'], $r['city'], $r['dob'], $r['gender'], $a['salary'] ?? '', $a['exp_field'] ?? '', $a['start'] ?? ''], ',', '"', '');
        }
        exit;
    }
}
$admin_title = 'Job applications';
$admin_active = 'jobs';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Job applications</h1>
<p class="sub">People who applied through the hidden job form. Only admins can see this page and the documents.</p>
<?php if (!$ready): ?><div class="alert">Import <code>database-update-18.sql</code> in phpMyAdmin first. The job form switches on after that.</div><?php endif; ?>

<div class="panel">
  <h2>The application link</h2>
  <p>Send this link to the people you want to apply. It is not shown anywhere on the website and search engines are told to ignore it.</p>
  <p><input type="text" readonly value="<?= e(jobs_link()) ?>" onclick="this.select()" style="width:100%;max-width:520px;padding:8px 10px;border:1.5px solid var(--line);border-radius:8px"></p>
  <p>Status: <span class="badge <?= jobs_open() ? 'won' : 'lost' ?>"><?= jobs_open() ? 'OPEN' : 'CLOSED' ?></span>
    <form method="post" style="display:inline;margin-left:10px"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><button class="btn tiny <?= jobs_open() ? 'loss' : 'win' ?>" type="submit"><?= jobs_open() ? 'Close the form' : 'Open the form' ?></button></form></p>
</div>

<div class="stats">
  <?php foreach (job_statuses() as $k => $lab): ?><div class="stat"><b style="font-size:26px"><?= (int)($counts[$k] ?? 0) ?></b><span><a href="?status=<?= e($k) ?>"><?= e($lab) ?></a></span></div><?php endforeach; ?>
</div>

<div class="panel">
  <form method="get" class="toolbar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, email, reference, country" style="padding:8px 10px;border:1.5px solid var(--line);border-radius:8px">
    <select name="status"><option value="">Every status</option><?php foreach (job_statuses() as $k => $lab): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
    <select name="role"><option value="">Every position</option><?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"<?= $role === $r ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
    <button class="btn dark tiny" type="submit">Filter</button>
    <a class="btn tiny outline" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Download CSV</a>
  </form>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Applicant</th><th>Position</th><th>Country</th><th>Applied</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr>
      <td><strong><?= e($r['name']) ?></strong><br><small><?= e($r['email']) ?> &middot; <?= e($r['phone']) ?></small><br><small><?= e($r['ref']) ?></small></td>
      <td><?= e($r['role']) ?></td><td><?= e($r['country']) ?><br><small><?= e($r['city']) ?></small></td>
      <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td>
      <td><span class="badge <?= $r['status'] === 'hired' || $r['status'] === 'shortlisted' ? 'won' : ($r['status'] === 'rejected' ? 'lost' : 'pending') ?>"><?= e(job_statuses()[$r['status']] ?? $r['status']) ?></span></td>
      <td><a class="btn tiny dark" href="<?= e(url('admin/job-view.php?ref=' . urlencode($r['ref']))) ?>">Open</a></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p style="color:var(--mute)">No applications<?= $ready ? ' match' : ' yet' ?>.</p><?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
