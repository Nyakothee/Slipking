<?php
/**
 * Bulk tips: CSV upload / paste / quick rows -> PREVIEW -> confirm.
 * Nothing is saved until the preview is confirmed. On confirm the rows are re-validated on the server from the copy
 * kept in the session (the browser never sends the rows back), and only valid rows are inserted, in one transaction.
 */
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/tip-bulk-lib.php';
require_perm('tips');
$pdo = db();

$leagues   = $pdo->query('SELECT l.id, l.name, s.name AS sport FROM leagues l JOIN sports s ON s.id = l.sport_id ORDER BY s.id, l.name')->fetchAll();
$leagueMap = bulk_league_map($leagues);

/* ---------- CSV template ---------- */
if (isset($_GET['template'])) {
    $d1 = date('Y-m-d', strtotime('+2 days')) . ' 20:00';
    $d2 = date('Y-m-d', strtotime('+3 days')) . ' 15:30';
    $ln = $leagues ? $leagues[0]['name'] : 'Premier League';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="slipking-tips-template.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, BULK_COLUMNS, ',', '"', '');
    fputcsv($out, [$ln, 'Team A', 'Team B', $d1, 'Over/Under', 'Over 2.5 goals', '1.85', '3', '0', 'Both sides score freely.'], ',', '"', '');
    fputcsv($out, [$ln, 'Team C', 'Team D', $d2, '1X2', 'Team C', '2.10', '4', '1', ''], ',', '"', '');
    fclose($out);
    exit;
}

$errors  = [];
$pasted  = '';
$quick   = [];
$token   = (string)($_GET['t'] ?? '');
$preview = null;

if (!isset($_SESSION['bulk']) || !is_array($_SESSION['bulk'])) {
    $_SESSION['bulk'] = [];
}

/** Keeps only the 3 newest staged imports (and drops anything older than 1 hour). */
function bulk_session_trim(): void
{
    foreach ($_SESSION['bulk'] as $k => $v) {
        if (!is_array($v) || ($v['at'] ?? 0) < time() - 3600) {
            unset($_SESSION['bulk'][$k]);
        }
    }
    while (count($_SESSION['bulk']) > 3) {
        reset($_SESSION['bulk']);
        unset($_SESSION['bulk'][key($_SESSION['bulk'])]);
    }
}

/** Stages parsed rows in the session and redirects to the preview. */
function bulk_stage(array $rows, array $lines, string $label): void
{
    bulk_session_trim();
    $t = bin2hex(random_bytes(16));
    $_SESSION['bulk'][$t] = ['rows' => $rows, 'lines' => $lines, 'at' => time(), 'label' => $label];
    redirect('admin/tip-bulk.php?t=' . $t);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'csv') {
        $text = '';
        $label = 'pasted text';
        $f = $_FILES['csvfile'] ?? null;
        if ($f && is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || (int)$f['size'] > BULK_MAX_BYTES) {
                $errors[] = 'The file is larger than 1 MB.';
            } elseif ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                $errors[] = 'The file could not be uploaded. Please try again.';
            } else {
                $text = (string)file_get_contents($f['tmp_name'], false, null, 0, BULK_MAX_BYTES + 1);
                $label = 'file ' . substr(preg_replace('/[^A-Za-z0-9._ -]/', '', (string)$f['name']), 0, 60);
            }
        } else {
            $pasted = (string)($_POST['paste'] ?? '');
            $text = $pasted;
        }
        if (!$errors) {
            $p = bulk_parse_csv($text);
            if ($p['errors']) {
                $errors = $p['errors'];
            } else {
                bulk_stage($p['rows'], $p['line'], $label);
            }
        }
    } elseif ($action === 'quick') {
        $rows = [];
        $lines = [];
        $in = $_POST['q'] ?? [];
        $in = is_array($in) ? $in : [];
        for ($i = 0; $i < 10; $i++) {
            $r = isset($in[$i]) && is_array($in[$i]) ? $in[$i] : [];
            $row = array_fill_keys(BULK_COLUMNS, '');
            foreach (BULK_COLUMNS as $c) {
                $row[$c] = isset($r[$c]) && is_string($r[$c]) ? trim($r[$c]) : '';
            }
            if (!empty($r['vip'])) {
                $row['vip'] = '1';
            }
            $row['_extra'] = false;
            $quick[$i] = $row;
            if ($row['home'] !== '' || $row['away'] !== '' || $row['kickoff'] !== '' || $row['market'] !== '' || $row['pick'] !== '' || $row['odds'] !== '' || $row['analysis'] !== '') {
                $rows[] = $row;
                $lines[] = $i + 1;
            }
        }
        if (!$rows) {
            $errors[] = 'Fill in at least one row.';
        } else {
            bulk_stage($rows, $lines, 'quick rows');
        }
    } elseif ($action === 'confirm') {
        $token = (string)($_POST['t'] ?? '');
        $staged = $_SESSION['bulk'][$token] ?? null;
        if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_array($staged)) {
            set_flash('error', 'That import has expired. Please upload it again.');
            redirect('admin/tip-bulk.php');
        }
        // Re-validate everything now (the clock has moved on and the database may have changed since the preview).
        $res = bulk_validate_all($pdo, $staged['rows'], $leagueMap, time());
        $made = 0; $newMatches = 0; $skipped = 0; $newIds = [];
        $matchCache = [];
        try {
            $pdo->beginTransaction();
            foreach ($res as $r) {
                if (!$r['ok']) { $skipped++; continue; }
                $c = $r['clean'];
                $mk = mb_strtolower($c['league_id'] . '|' . $c['home'] . '|' . $c['away'] . '|' . $c['kickoff_sql']);
                $mid = $matchCache[$mk] ?? bulk_find_match($pdo, $c);
                if (!$mid) {
                    $pdo->prepare('INSERT INTO matches (league_id, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?)')
                        ->execute([$c['league_id'], $c['home'], $c['away'], $c['kickoff_sql']]);
                    $mid = (int)$pdo->lastInsertId();
                    $newMatches++;
                } elseif (!isset($matchCache[$mk]) && bulk_tip_exists($pdo, $mid, $c)) {
                    $skipped++;     // appeared since validation
                    continue;
                }
                $matchCache[$mk] = $mid;
                // posted_at is the server time, exactly as in "Add a tip". It is never taken from the file.
                $pdo->prepare('INSERT INTO tips (match_id, market, pick, odds, confidence, analysis, is_vip, status, posted_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', NOW())')
                    ->execute([$mid, $c['market'], $c['pick'], $c['odds'], $c['confidence'], $c['analysis'], $c['vip']]);
                $newIds[] = (int)$pdo->lastInsertId();
                $made++;
            }
            $pdo->commit();
            foreach ($newIds as $nid) { audit_tip($nid, 'post'); }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('tip-bulk: ' . $ex->getMessage());
            set_flash('error', 'The import failed and nothing was saved. Please try again.');
            redirect('admin/tip-bulk.php?t=' . $token);
        }
        unset($_SESSION['bulk'][$token]);
        admin_log('tip.bulk', 'import', $made . ' tips, ' . $newMatches . ' new matches, ' . $skipped . ' rows skipped (' . ($staged['label'] ?? '') . ')');
        set_flash($made ? 'success' : 'info', $made
            ? 'Imported ' . $made . ' tip' . ($made === 1 ? '' : 's') . ' (' . $newMatches . ' new match' . ($newMatches === 1 ? '' : 'es') . ').' . ($skipped ? ' ' . $skipped . ' row' . ($skipped === 1 ? ' was' : 's were') . ' skipped.' : '')
            : 'Nothing was imported: no row was valid any more.');
        redirect('admin/tips.php');
    } elseif ($action === 'discard') {
        unset($_SESSION['bulk'][(string)($_POST['t'] ?? '')]);
        set_flash('info', 'Import discarded. Nothing was saved.');
        redirect('admin/tip-bulk.php');
    }
}

/* ---------- preview ---------- */
if ($token !== '' && !$errors) {
    $staged = preg_match('/^[a-f0-9]{32}$/', $token) ? ($_SESSION['bulk'][$token] ?? null) : null;
    if (!is_array($staged)) {
        set_flash('error', 'That import has expired. Please upload it again.');
        redirect('admin/tip-bulk.php');
    }
    $res = bulk_validate_all($pdo, $staged['rows'], $leagueMap, time());
    $validCount = 0;
    foreach ($res as $r) { if ($r['ok']) { $validCount++; } }
    $preview = ['res' => $res, 'lines' => $staged['lines'], 'label' => $staged['label'] ?? '', 'valid' => $validCount];
}

$admin_title = 'Bulk tips';
$admin_active = 'tip-bulk';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<div class="toolbar">
  <div><h1>Bulk tips</h1><p class="sub" style="margin:0">Add many tips at once. You see a preview first and nothing is saved until you confirm.</p></div>
  <a class="btn outline" href="<?= e(url('admin/tip-add.php')) ?>">Add one tip</a>
</div>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($preview): $n = count($preview['res']); $bad = $n - $preview['valid']; ?>
<div class="panel">
  <h2>Preview</h2>
  <p><strong><?= (int)$preview['valid'] ?></strong> of <?= $n ?> row<?= $n === 1 ? '' : 's' ?> can be imported<?= $bad ? ', <strong>' . $bad . '</strong> will be skipped' : '' ?>. Source: <?= e($preview['label']) ?>.</p>
  <div class="lockednote">Kickoff times are read as server time (East Africa Time). The posting time is set by the server when you confirm. Rows marked "Skip" are never saved.</div>
  <div class="scroll"><table class="tbl bulk-prev">
    <thead><tr><th>Row</th><th>Status</th><th>League</th><th>Match</th><th>Kickoff</th><th>Market / pick</th><th>Odds</th><th>Conf.</th><th>VIP</th><th>Notes</th></tr></thead>
    <tbody>
    <?php foreach ($preview['res'] as $i => $r): $c = $r['clean']; ?>
      <tr class="<?= $r['ok'] ? 'bulk-ok' : 'bulk-bad' ?>">
        <td><?= (int)($preview['lines'][$i] ?? $i + 1) ?></td>
        <td><span class="badge <?= $r['ok'] ? 'won' : 'lost' ?>"><?= $r['ok'] ? 'OK' : 'Skip' ?></span></td>
        <td><?= e($c['league']) ?></td>
        <td><?= e($c['home']) ?> vs <?= e($c['away']) ?></td>
        <td><?= e($c['kickoff']) ?></td>
        <td><?= e($c['pick']) ?><br><small><?= e($c['market']) ?></small></td>
        <td><?= e($c['odds'] !== '' ? $c['odds'] : '-') ?></td>
        <td><?= (int)$c['confidence'] ?></td>
        <td><?= $c['vip'] ? 'VIP' : 'Free' ?></td>
        <td><?php if ($r['ok']): ?><small><?= !empty($r['match_id']) ? 'Uses existing match #' . (int)$r['match_id'] : 'New match' ?></small>
            <?php else: ?><ul class="bulk-reasons"><?php foreach ($r['errors'] as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <form method="post" action="<?= e(url('admin/tip-bulk.php')) ?>" style="margin-top:16px">
    <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
    <?php if ($preview['valid'] > 0): ?>
      <button class="btn gold" type="submit" name="action" value="confirm" onclick="return confirm('Publish <?= (int)$preview['valid'] ?> tip(s) now? They go live immediately.');">Publish <?= (int)$preview['valid'] ?> valid tip<?= $preview['valid'] === 1 ? '' : 's' ?></button>
    <?php endif; ?>
    <button class="btn outline" type="submit" name="action" value="discard">Discard</button>
  </form>
</div>
<?php else: ?>

<div class="panel">
  <h2>1. Upload or paste CSV</h2>
  <p>Columns: <code>league, home, away, kickoff, market, pick, odds, confidence, vip, analysis</code>. Kickoff is <code>Y-m-d H:i</code> (e.g. 2026-10-25 20:00) in server time. Confidence (1-5, default 3), vip (0/1) and analysis are optional. League must match a name in
    <a href="<?= e(url('admin/leagues.php')) ?>" style="text-decoration:underline">Sports and leagues</a> (capitals do not matter). Up to <?= BULK_MAX_ROWS ?> rows and 1 MB. A header row is optional.
    <a href="<?= e(url('admin/tip-bulk.php?template=1')) ?>" style="text-decoration:underline;font-weight:700">Download a template</a></p>
  <form method="post" action="<?= e(url('admin/tip-bulk.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="csv"><input type="hidden" name="MAX_FILE_SIZE" value="<?= BULK_MAX_BYTES ?>">
    <div class="formgrid">
      <div class="field"><label for="csvfile">CSV file</label><input type="file" id="csvfile" name="csvfile" accept=".csv,.txt,text/csv,text/plain"></div>
      <div class="field full"><label for="paste">...or paste rows here (used when no file is chosen)</label><textarea id="paste" name="paste" rows="7" style="font-family:monospace"><?= e($pasted) ?></textarea></div>
    </div>
    <button class="btn dark" type="submit">Preview</button>
  </form>
</div>

<div class="panel">
  <h2>2. Quick rows</h2>
  <p>Up to 10 tips. Leave unused rows empty. Kickoff must be in the future.</p>
  <form method="post" action="<?= e(url('admin/tip-bulk.php')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="quick">
    <div class="scroll"><table class="tbl bulk-grid">
      <thead><tr><th>#</th><th>League</th><th>Home</th><th>Away</th><th>Kickoff</th><th>Market</th><th>Pick</th><th>Odds</th><th>Conf.</th><th>VIP</th></tr></thead>
      <tbody>
      <?php for ($i = 0; $i < 10; $i++): $q = $quick[$i] ?? []; $qv = function ($k, $d = '') use ($q) { return $q[$k] ?? $d; }; ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><select name="q[<?= $i ?>][league]" aria-label="League, row <?= $i + 1 ?>">
            <?php foreach ($leagues as $l): ?><option value="<?= e($l['name']) ?>"<?= mb_strtolower((string)$qv('league')) === mb_strtolower($l['name']) ? ' selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></td>
          <td><input type="text" name="q[<?= $i ?>][home]" value="<?= e($qv('home')) ?>" maxlength="80" aria-label="Home, row <?= $i + 1 ?>"></td>
          <td><input type="text" name="q[<?= $i ?>][away]" value="<?= e($qv('away')) ?>" maxlength="80" aria-label="Away, row <?= $i + 1 ?>"></td>
          <td><input type="datetime-local" name="q[<?= $i ?>][kickoff]" value="<?= e($qv('kickoff')) ?>" aria-label="Kickoff, row <?= $i + 1 ?>"></td>
          <td><input type="text" name="q[<?= $i ?>][market]" value="<?= e($qv('market')) ?>" maxlength="40" list="bulk-markets" aria-label="Market, row <?= $i + 1 ?>"></td>
          <td><input type="text" name="q[<?= $i ?>][pick]" value="<?= e($qv('pick')) ?>" maxlength="120" aria-label="Pick, row <?= $i + 1 ?>"></td>
          <td><input type="number" name="q[<?= $i ?>][odds]" value="<?= e($qv('odds')) ?>" step="0.01" min="1.01" max="1000" aria-label="Odds, row <?= $i + 1 ?>"></td>
          <td><select name="q[<?= $i ?>][confidence]" aria-label="Confidence, row <?= $i + 1 ?>"><?php for ($k = 1; $k <= 5; $k++): ?><option value="<?= $k ?>"<?= (int)$qv('confidence', 3) === $k ? ' selected' : '' ?>><?= $k ?></option><?php endfor; ?></select></td>
          <td><input type="checkbox" name="q[<?= $i ?>][vip]" value="1"<?= $qv('vip') === '1' ? ' checked' : '' ?> aria-label="VIP, row <?= $i + 1 ?>"></td>
        </tr>
      <?php endfor; ?>
      </tbody></table></div>
    <datalist id="bulk-markets"><?php foreach (['1X2', 'Double chance', 'Over/Under', 'BTTS', 'Draw no bet', 'Handicap', 'Correct score'] as $mk): ?><option value="<?= e($mk) ?>"><?php endforeach; ?></datalist>
    <button class="btn dark" type="submit" style="margin-top:12px">Preview</button>
  </form>
</div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
