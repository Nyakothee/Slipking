<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('accas');
$pdo = db();

/* ---------- CSV template (selections only; title, type and target are separate fields) ---------- */
if (isset($_GET['template'])) {
    $d1 = date('Y-m-d', strtotime('+2 days')) . ' 20:00';
    $d2 = date('Y-m-d', strtotime('+3 days')) . ' 15:30';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="slipking-accumulator-template.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['home', 'away', 'league', 'kickoff', 'pick', 'odds'], ',', '"', '');
    fputcsv($out, ['Team A', 'Team B', 'Premier League', $d1, 'Over 1.5 goals', '1.25'], ',', '"', '');
    fputcsv($out, ['Team C', 'Team D', 'La Liga', $d2, 'Team C or draw', '1.30'], ',', '"', '');
    fclose($out);
    exit;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$a = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM accumulators WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) {
        set_flash('error', 'Accumulator not found.');
        redirect('admin/accas.php');
    }
}
$locked = $a ? acca_is_locked($a) : false;

// Existing selections, in the same pasted format
$legText = '';
if ($a) {
    $st = $pdo->prepare('SELECT * FROM accumulator_legs WHERE accumulator_id = ? ORDER BY position');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $l) {
        $legText .= $l['home_team'] . ' vs ' . $l['away_team'] . ' | ' . $l['league'] . ' | ' . date('Y-m-d H:i', strtotime($l['kickoff_at'])) . ' | ' . $l['pick'] . ' | ' . number_format((float)$l['odds'], 2) . "\n";
    }
}
$f = [
    'kind' => $a['kind'] ?? 'million', 'title' => $a['title'] ?? '', 'target' => $a['target_kes'] ?? 1000000,
    'notes' => $a['notes'] ?? '', 'legs' => $legText,
];
$errors = [];
$preview = null;
try { $accaLeagues = $pdo->query('SELECT name FROM leagues ORDER BY name')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $ex) { $accaLeagues = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $f['notes'] = trim((string)($_POST['notes'] ?? ''));
        if (mb_strlen($f['notes']) > 2000) $errors[] = 'Notes are too long (2000 characters maximum).';

        if ($locked) {
            if (!$errors) {
                $pdo->prepare('UPDATE accumulators SET notes = ? WHERE id = ?')->execute([$f['notes'], $id]);
                admin_log('acca.edit', 'acca#' . $id, 'notes only (locked)');
                set_flash('success', 'Notes saved. The selections are locked because the first match has started.');
                redirect('admin/accas.php');
            }
        } else {
            $f['kind']   = ($_POST['kind'] ?? '') === 'vip' ? 'vip' : 'million';
            $f['title']  = post_str('title');
            $f['target'] = post_str('target');
            $f['legs']   = (string)($_POST['legs'] ?? '');
            if (mb_strlen($f['title']) < 3 || mb_strlen($f['title']) > 120) $errors[] = 'Enter a title (3 to 120 characters).';
            if (!ctype_digit((string)$f['target']) || (int)$f['target'] < 1000 || (int)$f['target'] > 1000000000) $errors[] = 'The target return (in KES) must be a whole number, for example 1000000.';
            [$legs, $legErrors] = parse_acca_legs($f['legs'], true);
            $errors = array_merge($errors, $legErrors);

            if (!$errors) {
                $total = round(acca_product(array_column($legs, 'odds')), 2);
                $first = min(array_column($legs, 'kickoff'));
                $last  = max(array_column($legs, 'kickoff'));
                try {
                    $pdo->beginTransaction();
                    if ($a) {
                        $pdo->prepare('UPDATE accumulators SET kind=?, title=?, target_kes=?, total_odds=?, notes=?, first_kickoff=?, last_kickoff=? WHERE id=?')
                            ->execute([$f['kind'], $f['title'], (int)$f['target'], $total, $f['notes'], $first, $last, $id]);
                        $pdo->prepare('DELETE FROM accumulator_legs WHERE accumulator_id = ?')->execute([$id]);
                        $aid = $id;
                    } else {
                        // posted_at is the server time and can never be typed in or changed
                        $pdo->prepare('INSERT INTO accumulators (kind, title, target_kes, total_odds, notes, posted_at, first_kickoff, last_kickoff) VALUES (?,?,?,?,?,NOW(),?,?)')
                            ->execute([$f['kind'], $f['title'], (int)$f['target'], $total, $f['notes'], $first, $last]);
                        $aid = (int)$pdo->lastInsertId();
                    }
                    $ins = $pdo->prepare('INSERT INTO accumulator_legs (accumulator_id, position, league, home_team, away_team, kickoff_at, pick, odds) VALUES (?,?,?,?,?,?,?,?)');
                    foreach ($legs as $i => $l) {
                        $ins->execute([$aid, $i + 1, $l['league'], $l['home'], $l['away'], $l['kickoff'], $l['pick'], number_format($l['odds'], 2, '.', '')]);
                    }
                    $pdo->commit();
                    admin_log($a ? 'acca.edit' : 'acca.add', 'acca#' . $aid, $f['title'] . ': ' . count($legs) . ' legs, total odds ' . $total . ' (' . $f['kind'] . ')');
                    set_flash('success', 'Accumulator saved: ' . count($legs) . ' selections, total odds ' . number_format($total, 2) . '.');
                    redirect('admin/accas.php');
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('acca-edit: ' . $ex->getMessage());
                    $errors[] = 'It could not be saved. Please try again.';
                }
            } elseif (!empty($legs)) {
                $preview = count($legs) . ' valid selections so far.';
            }
        }
    }
}

$admin_title = $a ? 'Edit accumulator' : 'New accumulator';
$admin_active = 'accas';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1><?= $a ? ($locked ? 'Accumulator (locked)' : 'Edit accumulator') : 'New accumulator' ?></h1>
<p class="sub">Paste one selection per line, or use the CSV upload and quick rows below the form. It goes live as soon as you save, and the posting time is recorded automatically.</p>
<?php if ($locked): ?><div class="lockednote">The first match has started or a result is set, so selections, odds and type are locked to keep the record honest. You can still edit the notes, and mark results on the Selections page.</div><?php endif; ?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="<?= e(url('admin/acca-edit.php' . ($id ? '?id=' . $id : ''))) ?>">
  <?= csrf_field() ?><?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <div class="panel">
    <div class="formgrid">
      <div class="field"><label for="kind">Type</label>
        <select id="kind" name="kind"<?= $locked ? ' disabled' : '' ?>>
          <option value="million"<?= $f['kind'] === 'million' ? ' selected' : '' ?>>1 Million Accumulator (free for everyone)</option>
          <option value="vip"<?= $f['kind'] === 'vip' ? ' selected' : '' ?>>VIP Accumulator (VIP members only until settled)</option>
        </select></div>
      <div class="field"><label for="target">Target return (KES)</label><input type="number" id="target" name="target" value="<?= e($f['target']) ?>" min="1000" step="1"<?= $locked ? ' disabled' : '' ?>><div class="hint">Used to work out the minimum stake. Default 1,000,000.</div></div>
      <div class="field full"><label for="title">Title</label><input type="text" id="title" name="title" value="<?= e($f['title']) ?>" maxlength="120" placeholder="e.g. Weekend 1 Million Accumulator"<?= $locked ? ' disabled' : '' ?>></div>
      <div class="field full"><label for="legs">Selections (one per line)</label>
        <textarea id="legs" name="legs" style="min-height:340px;font-family:monospace;font-size:14px" placeholder="Arsenal vs Chelsea | Premier League | 2026-10-04 17:30 | Over 1.5 goals | 1.25&#10;Real Madrid vs Sevilla | La Liga | 2026-10-04 21:00 | Real Madrid to win | 1.40"<?= $locked ? ' disabled' : '' ?>><?= e($f['legs']) ?></textarea>
        <div class="hint">Format: <code>Home vs Away | League | YYYY-MM-DD HH:MM | Pick | Odds</code>. You can paste straight from a spreadsheet (columns separated by tabs). 2 to 100 selections. Kickoffs must be in the future. Lines starting with # are ignored.</div></div>
      <div class="field full"><label for="notes">Notes (optional, shown under the table)</label><textarea id="notes" name="notes" maxlength="2000"><?= e($f['notes']) ?></textarea></div>
    </div>
  </div>
  <button class="btn gold" type="submit"><?= $locked ? 'Save notes' : 'Save and publish' ?></button>
  <a class="btn outline" href="<?= e(url('admin/accas.php')) ?>">Cancel</a>
</form>

<?php if (!$locked): ?>
<div class="panel" id="acca-tools">
  <h2>Fill the selections faster</h2>
  <p>Title, type and target stay in the fields above. These tools only fill the <strong>Selections</strong> box, where you can still check and edit every line before saving.</p>
  <h3 style="margin:14px 0 6px;font-size:15px">1. Upload a CSV</h3>
  <p class="hint">Columns: <code>home, away, league, kickoff, pick, odds</code> (kickoff like <code>2026-10-25 20:00</code>). A header row is optional.
    <a href="<?= e(url('admin/acca-edit.php?template=1')) ?>" style="text-decoration:underline;font-weight:700">Download the template</a></p>
  <div class="formgrid">
    <div class="field"><label for="acca-csv">CSV file</label><input type="file" id="acca-csv" accept=".csv,.txt,text/csv,text/plain"></div>
    <div class="field"><label for="acca-csv-mode">When filling</label>
      <select id="acca-csv-mode"><option value="append">Add to the selections already there</option><option value="replace">Replace the selections</option></select></div>
  </div>
  <p id="acca-csv-msg" class="hint" role="status"></p>
  <h3 style="margin:14px 0 6px;font-size:15px">2. Quick rows</h3>
  <p class="hint">Up to 10 selections. Leave unused rows empty, then press the button to move them into the Selections box.</p>
  <div class="scroll"><table class="tbl bulk-grid">
    <thead><tr><th>#</th><th>Home</th><th>Away</th><th>League</th><th>Kickoff</th><th>Pick</th><th>Odds</th></tr></thead>
    <tbody>
    <?php for ($i = 0; $i < 10; $i++): ?>
      <tr data-qr>
        <td><?= $i + 1 ?></td>
        <td><input type="text" data-k="home" maxlength="80" aria-label="Home, row <?= $i + 1 ?>"></td>
        <td><input type="text" data-k="away" maxlength="80" aria-label="Away, row <?= $i + 1 ?>"></td>
        <td><input type="text" data-k="league" maxlength="80" list="acca-leagues" aria-label="League, row <?= $i + 1 ?>"></td>
        <td><input type="datetime-local" data-k="kickoff" aria-label="Kickoff, row <?= $i + 1 ?>"></td>
        <td><input type="text" data-k="pick" maxlength="120" list="acca-picks" aria-label="Pick, row <?= $i + 1 ?>"></td>
        <td><input type="number" data-k="odds" step="0.01" min="1.01" max="100" aria-label="Odds, row <?= $i + 1 ?>"></td>
      </tr>
    <?php endfor; ?>
    </tbody></table></div>
  <datalist id="acca-leagues"><?php foreach ($accaLeagues as $ln): ?><option value="<?= e($ln) ?>"><?php endforeach; ?></datalist>
  <datalist id="acca-picks"><?php foreach (['Home win', 'Away win', 'Draw', 'Over 1.5 goals', 'Over 2.5 goals', 'Under 3.5 goals', 'Both teams to score', 'Home or draw', 'Away or draw'] as $pk): ?><option value="<?= e($pk) ?>"><?php endforeach; ?></datalist>
  <p style="margin-top:10px"><button type="button" class="btn dark" id="acca-quick-go">Move filled rows into Selections</button></p>
</div>
<script>
(function () {
  var box = document.getElementById('legs'); if (!box) return;
  function addLines(lines, replace) {
    var cur = replace ? '' : box.value.replace(/\s+$/, '');
    box.value = (cur ? cur + '\n' : '') + lines.join('\n') + '\n';
    box.dispatchEvent(new Event('input', {bubbles: true}));
  }
  function parseCsv(t) {
    var rows = [], row = [], f = '', q = false, i, c;
    t = t.replace(/^\uFEFF/, '');
    for (i = 0; i < t.length; i++) {
      c = t[i];
      if (q) { if (c === '"') { if (t[i + 1] === '"') { f += '"'; i++; } else q = false; } else f += c; }
      else if (c === '"') q = true;
      else if (c === ',' || c === ';' || c === '\t') { row.push(f); f = ''; }
      else if (c === '\n' || c === '\r') { if (c === '\r' && t[i + 1] === '\n') i++; row.push(f); rows.push(row); row = []; f = ''; }
      else f += c;
    }
    if (f !== '' || row.length) { row.push(f); rows.push(row); }
    return rows.filter(function (r) { return r.join('').trim() !== ''; });
  }
  function clean(v) { return String(v || '').replace(/[|\r\n\t]+/g, ' ').trim(); }
  var file = document.getElementById('acca-csv'), msg = document.getElementById('acca-csv-msg');
  file.addEventListener('change', function () {
    var f = file.files[0]; if (!f) return;
    if (f.size > 1048576) { msg.textContent = 'That file is over 1 MB.'; return; }
    var rd = new FileReader();
    rd.onload = function () {
      var rows = parseCsv(String(rd.result)), lines = [], skipped = 0;
      if (rows.length && isNaN(parseFloat(rows[0][5]))) rows.shift();
      rows.forEach(function (r) {
        if (r.length < 6) { skipped++; return; }
        lines.push(clean(r[0]) + ' vs ' + clean(r[1]) + ' | ' + clean(r[2]) + ' | ' + clean(r[3]).replace('T', ' ') + ' | ' + clean(r[4]) + ' | ' + clean(r[5]));
      });
      if (!lines.length) { msg.textContent = 'No usable rows found. Check the columns match the template.'; return; }
      addLines(lines, document.getElementById('acca-csv-mode').value === 'replace');
      msg.textContent = lines.length + ' selection(s) added to the box below' + (skipped ? ', ' + skipped + ' row(s) skipped for missing columns' : '') + '. Check them, then save.';
      file.value = '';
    };
    rd.readAsText(f);
  });
  document.getElementById('acca-quick-go').addEventListener('click', function () {
    var lines = [], trs = document.querySelectorAll('tr[data-qr]');
    Array.prototype.forEach.call(trs, function (tr) {
      var v = {};
      Array.prototype.forEach.call(tr.querySelectorAll('input'), function (i) { v[i.getAttribute('data-k')] = clean(i.value); });
      if (!v.home && !v.away && !v.pick && !v.odds) return;
      lines.push(v.home + ' vs ' + v.away + ' | ' + v.league + ' | ' + v.kickoff.replace('T', ' ') + ' | ' + v.pick + ' | ' + v.odds);
      Array.prototype.forEach.call(tr.querySelectorAll('input'), function (i) { i.value = ''; });
    });
    if (!lines.length) { msg.textContent = 'Fill at least one quick row first.'; return; }
    addLines(lines, false);
    msg.textContent = lines.length + ' row(s) moved into Selections. Check them, then save.';
    box.focus();
  });
})();
</script>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
