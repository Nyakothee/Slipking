<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');
$pdo = db();
$ready = streak_ready();
$errors = [];
$lastDay = ''; try { $lastDay = (string)db()->query('SELECT MAX(day) FROM streak_days')->fetchColumn(); } catch (Throwable $e) { }
$f = ['day' => ($lastDay !== '' && $lastDay >= date('Y-m-d')) ? date('Y-m-d', strtotime($lastDay . ' +1 day')) : date('Y-m-d'), 'home' => '', 'away' => '', 'league' => '', 'kickoff' => date('Y-m-d\TH:i', strtotime('+3 hours')), 'pick' => '', 'odds' => '2.00', 'stream_url' => ''];
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'post') {
            foreach (['day', 'home', 'away', 'league', 'kickoff', 'pick', 'odds', 'stream_url'] as $k) { $f[$k] = trim((string)($_POST[$k] ?? '')); }
            $u2 = trim((string)($_POST['stream_url2'] ?? ''));
            $wFail = '';
            $wcode = trim((string)($_POST['stream_widget'] ?? ''));
            if ($wcode !== '') { [$wurl, $werr] = stream_widget_save($wcode); if ($werr !== '') { $wFail = $werr; } else { $f['stream_url'] = $wurl; } }
            [$thumbVal, $thumbErr] = thumb_from_post();
            if ($wFail !== '') { $ok = false; $msg = $wFail; } elseif ($thumbErr !== '') { $ok = false; $msg = $thumbErr; } else { [$ok, $msg] = streak_post($f); }
            if ($ok && $u2 !== '' && stream_clean($u2) !== '' && stream2_col('streak_days')) { $pdo->prepare('UPDATE streak_days SET stream_url2 = ? WHERE day = ?')->execute([stream_clean($u2), $f['day']]); }
            if ($ok) { sources_save('streak_days', 'day', $f['day']); }
            if ($ok && $thumbVal !== null && thumb_col('streak_days')) { $pdo->prepare('UPDATE streak_days SET thumb = ? WHERE day = ?')->execute([$thumbVal, $f['day']]); }
            if ($ok) { admin_log('streak.post', $f['day'], $f['home'] . ' vs ' . $f['away'] . ' ' . $f['pick'] . ' @ ' . $f['odds']); set_flash('success', $msg); redirect('admin/streak.php'); }
            $errors[] = $msg;
        } elseif ($act === 'settle') {
            $id = (int)($_POST['id'] ?? 0); $res = (string)($_POST['result'] ?? '');
            if (streak_settle($id, $res)) { admin_log('streak.settle', 'day#' . $id, $res); set_flash('success', 'Marked as ' . $res . '.'); } else { set_flash('error', 'That day is already settled.'); }
            redirect('admin/streak.php');
        } elseif ($act === 'thumb') {
            [$thumbVal, $thumbErr] = thumb_from_post();
            if ($thumbErr !== '') { set_flash('error', $thumbErr); }
            elseif ($thumbVal === null || !thumb_col('streak_days')) { set_flash('error', 'Choose a picture or paste a link first.'); }
            else { $pdo->prepare('UPDATE streak_days SET thumb = ? WHERE id = ?')->execute([$thumbVal, (int)$_POST['id']]); set_flash('success', 'Thumbnail saved.'); }
            redirect('admin/streak.php');
        } elseif ($act === 'stream') {
            $u = trim((string)($_POST['stream_url'] ?? ''));
            if ($u !== '' && stream_clean($u) === '') { set_flash('error', 'The link must start with http:// or https://'); }
            else {
                $pdo->prepare('UPDATE streak_days SET stream_url = ? WHERE id = ?')->execute([stream_clean($u), (int)$_POST['id']]);
                if (isset($_POST['stream_url2']) && stream2_col('streak_days')) { $pdo->prepare('UPDATE streak_days SET stream_url2 = ? WHERE id = ?')->execute([stream_clean(trim((string)$_POST['stream_url2'])), (int)$_POST['id']]); }
                if (!empty($_POST['src_c'])) { sources_save('streak_days', 'id', (int)$_POST['id']); }
                set_flash('success', 'Stream links saved.');
            }
            redirect('admin/streak.php');
        } elseif ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM streak_joins WHERE day_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM streak_days WHERE id = ? AND status = 'pending'")->execute([$id]);
            admin_log('streak.delete', 'day#' . $id); set_flash('success', 'Removed.'); redirect('admin/streak.php');
        } elseif ($act === 'auto') {
            set_setting('streak_autosettle', setting('streak_autosettle', '1') === '1' ? '0' : '1');
            admin_log('streak.auto', 'setting', setting('streak_autosettle', '1') === '1' ? 'off' : 'on'); set_flash('success', 'Saved.'); redirect('admin/streak.php');
        } elseif ($act === 'check') {
            require_once dirname(__DIR__) . '/includes/widget-scores.php';
            $r = wscore_run(true); set_flash('success', 'Checked the widgets: ' . (int)$r['settled'] . ' settled' . ($r['left'] ? ', ' . (int)$r['left'] . ' left for you.' : '.')); redirect('admin/streak.php');
        } elseif ($act === 'start') {
            $s = (float)($_POST['start'] ?? 0);
            if ($s < 1 || $s > 100000) { $errors[] = 'Starting stake must be between 1 and 100000.'; }
            else { set_setting('streak_start', (string)$s); admin_log('streak.start', 'setting', (string)$s); set_flash('success', 'Starting stake saved. It applies from the next reset.'); redirect('admin/streak.php'); }
        }
    }
}
$rows = $ready ? $pdo->query('SELECT d.*, (SELECT COUNT(*) FROM streak_joins j WHERE j.day_id = d.id) AS players FROM streak_days d ORDER BY day DESC LIMIT 30')->fetchAll() : [];
$admin_title = '2 Odds Streak';
$admin_active = 'streak';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>2 Odds Streak Challenge</h1>
<p class="sub">Post one pick a day at about 2.00 odds. Day 1 stakes <?= e(streak_money(streak_start())) ?>. After a win the whole return is staked on the next pick; after a loss the streak resets. It shows on the home page under Free tips today.</p>
<?php if (!$ready): ?><div class="errors"><ul><li>Run <code>database-update-36.sql</code> once in phpMyAdmin.</li></ul></div><?php else: ?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid2" style="align-items:start">
<form method="post" class="panel" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="post"><h2>Post a pick</h2>
  <?php [$pvStake, $pvLen] = $ready ? streak_terms_after(streak_prev_day($f['day'])) : [0, 1]; $pv = $ready ? streak_prev_day($f['day']) : null; ?>
  <p class="sub" style="margin-top:0">For <b><?= e(date('D j M', strtotime($f['day']))) ?></b> this will be <b>Day <?= (int)$pvLen ?></b> with a stake of <b><?= e(streak_money((float)$pvStake)) ?></b><?= $pv ? ($pv['status'] === 'won' ? ' (the whole win from Day ' . (int)$pv['len'] . ')' : ($pv['status'] === 'pending' ? '. Settle the previous day first.' : '')) : '' ?>.</p>
  <div class="formgrid">
    <div class="field"><label for="s-day">Date (the day it is played)</label><input type="date" id="s-day" name="day" value="<?= e($f['day']) ?>"></div>
    <div class="field"><label for="s-ko">Kickoff</label><input type="datetime-local" id="s-ko" name="kickoff" value="<?= e($f['kickoff']) ?>"></div>
    <div class="field"><label for="s-h">Home team</label><input id="s-h" name="home" value="<?= e($f['home']) ?>" maxlength="80"></div>
    <div class="field"><label for="s-a">Away team</label><input id="s-a" name="away" value="<?= e($f['away']) ?>" maxlength="80"></div>
    <div class="field full"><label for="s-l">League</label><?= slip_league_select('league', $f['league'], '') ?></div>
    <div class="field"><label for="s-p">Pick</label><input id="s-p" name="pick" value="<?= e($f['pick']) ?>" maxlength="120" placeholder="e.g. Over 1.5 goals"></div>
    <div class="field"><label for="s-o">Odds</label><input id="s-o" name="odds" type="number" step="0.01" min="1.2" max="5" value="<?= e($f['odds']) ?>"></div>
    <div class="field full"><label for="s-s">Live stream link (optional)</label><input id="s-s" name="stream_url" type="url" value="<?= e($f['stream_url']) ?>" maxlength="400" placeholder="https://..."><div class="hint">A "Watch live" button shows under the pick when this is filled.</div></div>
    <div class="field full"><label for="s-w">...or paste widget / embed code (optional)</label><textarea id="s-w" name="stream_widget" rows="3" style="font-family:monospace"></textarea></div>
    <div class="field full"><label for="s-s2">Backup stream link (optional)</label><input id="s-s2" name="stream_url2" type="url" maxlength="400" placeholder="https://..."></div>
    <?= sources_form('') ?>
    <?= thumb_field('') ?>
  </div>
  <button class="btn gold" type="submit">Post today's pick</button>
</form>
<div class="panel"><h2>Automatic results</h2>
  <?php $auto = streak_auto_on(); ?>
  <p>Status: <span class="badge <?= $auto ? 'won' : 'pending' ?>"><?= $auto ? 'ON' : 'OFF' ?></span></p>
  <p class="hint">120 minutes after kickoff the site reads your live-score widgets. A clear final score settles the pick as Won or Lost, and a postponed or cancelled game as Void. If the game is not in the widgets, it stays pending for you to settle by hand. Needs a widget marked "use for results" (Admin, Widgets).</p>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="auto"><button class="btn outline small" type="submit"><?= $auto ? 'Turn off' : 'Turn on' ?></button></form>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="check"><button class="btn gold small" type="submit">Check now</button></form>
</div>
<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="start"><h2>Starting stake</h2>
  <div class="field"><label for="s-st">Day 1 stake (KES)</label><input id="s-st" name="start" type="number" min="1" step="1" value="<?= e((string)streak_start()) ?>"></div>
  <button class="btn outline" type="submit">Save</button>
</form></div>
<div class="panel"><h2>Recent days</h2>
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Day</th><th>Match</th><th>Pick @ odds</th><th>Stake</th><th>Players</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
<td><?= e(date('j M', strtotime($r['day']))) ?></td><td><?= (int)$r['len'] ?></td><td><?= e($r['home'] . ' vs ' . $r['away']) ?></td><td><?= e($r['pick']) ?> @ <?= e(number_format((float)$r['odds'], 2)) ?></td><td><?= e(streak_money((float)$r['stake'])) ?></td><td><?= (int)$r['players'] ?></td>
<td><span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
<td><?php if ($r['status'] === 'pending'): ?>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny gold" name="result" value="won" type="submit">Won</button> <button class="btn tiny loss" name="result" value="lost" type="submit">Lost</button> <button class="btn tiny outline" name="result" value="void" type="submit">Void</button></form>
  <form method="post" style="display:inline" onsubmit="return confirm('Remove this pick?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny outline" type="submit">Remove</button></form>
  <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="stream"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="url" name="stream_url" value="<?= e($r['stream_url']) ?>" placeholder="Stream link" style="width:150px"> <input type="url" name="stream_url2" value="<?= e($r['stream_url2'] ?? '') ?>" placeholder="Backup link" style="width:150px"> <details style="margin-top:6px"><summary>More buttons</summary><?= sources_form($r['stream_extra'] ?? '') ?></details> <button class="btn tiny outline" type="submit">Save links</button></form>
  <form method="post" enctype="multipart/form-data" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="thumb"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?= !empty($r['thumb']) ? thumb_img($r['thumb'], 'thumb', '') . ' ' : '' ?><input type="file" name="thumb" accept="image/jpeg,image/png,image/webp" style="max-width:170px"> <button class="btn tiny outline" type="submit">Save picture</button></form>
<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty">No picks yet. Post the first one above.</div><?php endif; ?></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
