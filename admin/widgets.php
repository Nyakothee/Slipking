<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();
$ready = widgets_ready();
if ($ready) { wscore_feeds(); }   // makes sure the result columns exist
$back = 'admin/widgets.php';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($a === 'save') {
        $slot = array_key_exists($_POST['slot'] ?? '', widget_slots()) ? $_POST['slot'] : 'live';
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120);
        $code = trim((string)($_POST['code'] ?? ''));
        $h = max(60, min(2000, (int)($_POST['height'] ?? 500)));
        $ptitle = mb_substr(trim((string)($_POST['page_title'] ?? '')), 0, 120);
        $pslug = $slot === 'page' ? widget_slug((string)($_POST['page_slug'] ?? '') !== '' ? (string)$_POST['page_slug'] : $ptitle) : '';
        if ($slot !== 'page') { $ptitle = ''; }
        if ($title === '') { $title = (widget_slots()[$slot] ?? 'Widget') . ' widget'; }
        if ($code === '') { set_flash('error', 'Paste the embed code or link from the widget provider.'); }
        elseif (strlen($code) > 60000) { set_flash('error', 'That embed code is too long (60,000 characters maximum).'); }
        elseif ($slot === 'page' && $pslug === '') { set_flash('error', 'Give the page a title (for example League tables).'); }
        else {
            $vals = [$title, $slot, $pslug, $ptitle, $code, $h, (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0, !empty($_POST['no_scroll']) ? 1 : 0, max(0, min(600, (int)($_POST['crop_top'] ?? 0))), max(0, min(600, (int)($_POST['crop_bottom'] ?? 0))), !empty($_POST['use_for_results']) ? 1 : 0, mb_substr(trim((string)($_POST['feed_url'] ?? '')), 0, 500)];
            if ($id) {
                $pdo->prepare('UPDATE widgets SET title=?, slot=?, page_slug=?, page_title=?, code=?, height=?, sort_order=?, is_active=?, no_scroll=?, crop_top=?, crop_bottom=?, use_for_results=?, feed_url=? WHERE id=?')->execute(array_merge($vals, [$id]));
                admin_log('widget.edit', 'widget#' . $id, $title);
            } else {
                $pdo->prepare('INSERT INTO widgets (title, slot, page_slug, page_title, code, height, sort_order, is_active, no_scroll, crop_top, crop_bottom, use_for_results, feed_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                $id = (int)$pdo->lastInsertId();
                admin_log('widget.add', 'widget#' . $id, $title);
            }
            set_flash('success', 'Widget saved.' . (!empty($_POST['is_active']) ? ' It is on and shows on the site and in the app.' : ' It is saved but switched off.'));
            redirect($back);
        }
        redirect($back . ($id ? '?edit=' . $id : ''));
    } elseif ($a === 'autosettle') {
        set_setting('widget_autosettle', !empty($_POST['on']) ? '1' : '0');
        admin_log('widget.autosettle', '', !empty($_POST['on']) ? 'on' : 'off');
        set_flash('success', !empty($_POST['on']) ? 'Tips will now be settled from the widgets marked "use for results".' : 'Automatic settling from widgets is off.');
    } elseif ($a === 'checknow') {
        $s = wscore_run(true);
        set_flash($s['settled'] > 0 ? 'success' : 'info', 'Checked ' . $s['feeds'] . ' widget(s) for ' . $s['matches'] . ' match(es): ' . $s['found'] . ' finished score(s) found, ' . $s['settled'] . ' tip(s) settled. ' . implode(' ', $s['notes']));
    } elseif ($a === 'toggle' && $id) {
        $pdo->prepare('UPDATE widgets SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        admin_log('widget.toggle', 'widget#' . $id, '');
        set_flash('success', 'Switched.');
    } elseif ($a === 'delete' && $id) {
        $pdo->prepare('DELETE FROM widgets WHERE id = ?')->execute([$id]);
        admin_log('widget.delete', 'widget#' . $id, '');
        set_flash('success', 'Widget deleted.');
    }
    redirect($back);
}

$edit = null;
if ($ready && !empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM widgets WHERE id = ?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null;
}
$f = $edit ?: ['id' => 0, 'title' => '', 'slot' => (string)($_GET['slot'] ?? 'live'), 'page_slug' => '', 'page_title' => '', 'code' => '', 'height' => 500, 'sort_order' => 0, 'is_active' => 1, 'no_scroll' => 0, 'crop_top' => 0, 'crop_bottom' => 0, 'use_for_results' => 0, 'feed_url' => ''];
$rows = $ready ? $pdo->query('SELECT * FROM widgets ORDER BY slot, sort_order, id')->fetchAll() : [];
$slots = widget_slots();

$admin_title = 'Widgets';
$admin_active = 'widgets';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Widgets (embeds)</h1>
<p class="sub">Paste the embed code from a football widget provider (live scores, league table, upcoming matches, team stats, score ticker, live match tracker). No API key is needed. Switch a widget on and it appears on the site and in the app.</p>
<?php if (!$ready): ?><div class="errors" role="alert"><ul><li>Run <code>database-update-31.sql</code> once in phpMyAdmin first.</li></ul></div>
<?php else: ?>
<?php $wl = json_decode((string)setting('widget_scores_last', ''), true); $pend = 0; try { $pend = (int)$pdo->query("SELECT COUNT(*) FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.status = 'pending' AND t.deleted_at IS NULL AND m.kickoff_at < NOW() - INTERVAL 3 HOUR")->fetchColumn(); } catch (Throwable $e) { } ?>
<div class="panel">
  <h2>Settle tips from widget scores</h2>
  <p>Tips for matches that finished are settled automatically when a switched-on widget marked "use for results" shows the final score. Tips that no widget shows are left for you to settle by hand in Tips.</p>
  <form method="post" action="<?= e(url($back)) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="autosettle">
    <label><input type="checkbox" name="on" value="1" onchange="this.form.submit()"<?= wscore_enabled() ? ' checked' : '' ?>> Automatic settling is <strong><?= wscore_enabled() ? 'on' : 'off' ?></strong></label></form>
  <form method="post" action="<?= e(url($back)) ?>" style="display:inline;margin-left:14px"><?= csrf_field() ?><input type="hidden" name="action" value="checknow"><button class="btn dark tiny" type="submit">Check now</button></form>
  <?php if ($wl): ?><p class="hint" style="margin-top:8px">Last check <?= e(date('j M, H:i', strtotime($wl['time']))) ?>: <?= (int)$wl['feeds'] ?> widget(s), <?= (int)$wl['found'] ?> score(s) found, <?= (int)$wl['settled'] ?> tip(s) settled. <?= e(implode(' ', $wl['notes'] ?? [])) ?></p><?php endif; ?>
  <p class="hint">Waiting for you to settle by hand: <strong><?= $pend ?></strong> pending tip(s) from matches that ended over 3 hours ago. <a href="<?= e(url('admin/tips.php')) ?>" style="text-decoration:underline">Open tips</a></p>
</div>
<div class="panel">
  <h2><?= $edit ? 'Edit widget' : 'Add a widget' ?></h2>
  <form method="post" action="<?= e(url($back)) ?>"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <div class="formgrid">
      <div class="field full"><label for="w-preset">Start from a type (optional)</label>
        <select id="w-preset"><option value="">Choose a type to fill in sensible settings...</option>
          <?php foreach (widget_presets() as $k => $pr): ?><option value="<?= e($k) ?>"><?= e($pr[0]) ?></option><?php endforeach; ?></select>
        <div class="hint" id="w-hint">The type only fills in the name, place and height for you. You still paste the provider's code below.</div></div>
      <div class="field"><label for="w-title">Name (optional)</label><input type="text" id="w-title" name="title" value="<?= e($f['title']) ?>" maxlength="120" placeholder="e.g. Premier League table"></div>
      <div class="field"><label for="w-slot">Where it shows</label>
        <select id="w-slot" name="slot"><?php foreach ($slots as $k => $v): ?><option value="<?= e($k) ?>"<?= $f['slot'] === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
      <div class="field" id="w-pagebox"><label for="w-ptitle">Page title (for "its own page")</label><input type="text" id="w-ptitle" name="page_title" value="<?= e($f['page_title']) ?>" maxlength="120" placeholder="e.g. League tables">
        <div class="hint">Widgets with the same page title share one page, found under Results &rarr; Tables and stats.<input type="hidden" name="page_slug" value="<?= e($f['page_slug']) ?>"></div></div>
      <div class="field"><label for="w-h">Height (pixels)</label><input type="number" id="w-h" name="height" value="<?= (int)$f['height'] ?>" min="60" max="2000"><div class="hint">A score ticker is about 60 to 90. Tables and live scores are about 500 to 900.</div></div>
      <div class="field"><label for="w-sort">Order</label><input type="number" id="w-sort" name="sort_order" value="<?= (int)$f['sort_order'] ?>"><div class="hint">Smaller numbers show first.</div></div>
      <div class="field check"><label><input type="checkbox" name="is_active" value="1"<?= $f['is_active'] ? ' checked' : '' ?>> Switched on</label></div>
      <div class="field check"><label><input type="checkbox" name="no_scroll" value="1"<?= !empty($f['no_scroll']) ? ' checked' : '' ?>> Hide the inner scrollbar</label>
        <div class="hint">For widgets given as a link or &lt;iframe&gt;. Then set the height tall enough to show the whole table. Script widgets resize themselves automatically.</div></div>
      <div class="field"><label for="w-ct">Hide the provider's header (pixels from the top)</label><input type="number" id="w-ct" name="crop_top" value="<?= (int)($f['crop_top'] ?? 0) ?>" min="0" max="600"><div class="hint">Many widgets show their own coloured title bar (for example a green "Tables" band). Enter its height, such as 55, to cut it off so the widget blends into the page.</div></div>
      <div class="field"><label for="w-cb">Hide the provider's footer (pixels from the bottom)</label><input type="number" id="w-cb" name="crop_bottom" value="<?= (int)($f['crop_bottom'] ?? 0) ?>" min="0" max="600"></div>
      <div class="field check full"><label><input type="checkbox" name="use_for_results" value="1"<?= !empty($f['use_for_results']) ? ' checked' : '' ?>> Use this widget's scores to settle tips automatically</label>
        <div class="hint">Tick this on live-score or results widgets. Finished matches found in it settle your pending tips (only clear "FT" results; everything else stays for you to settle by hand).</div></div>
      <div class="field full"><label for="w-feed">Score page link (optional)</label><input type="url" name="feed_url" id="w-feed" value="<?= e($f['feed_url'] ?? '') ?>" maxlength="500" placeholder="Leave empty to read the widget's own link">
        <div class="hint">Only needed if the widget is script code, or its own page does not list scores as plain text. Paste the link of any page that lists finished scores as text.</div></div>
      <div class="field full"><label for="w-code">Embed code or link</label>
        <textarea id="w-code" name="code" rows="8" style="font-family:monospace;font-size:13px" placeholder="Paste the &lt;iframe&gt; or &lt;script&gt; code, or just the widget link (https://...)"><?= e($f['code']) ?></textarea>
        <p style="margin:8px 0 0"><button type="button" class="btn outline tiny" id="w-white">Make the widget's colour white (if the link has c=XXXXXX)</button></p>
        <div class="hint">Only embed widgets from providers you trust and whose terms allow it. Script widgets run in a sandbox that cannot read your members' sessions.</div></div>
    </div>
    <button class="btn gold" type="submit">Save widget</button>
    <?php if ($edit): ?><a class="btn outline" href="<?= e(url($back)) ?>">Cancel</a><?php endif; ?>
  </form>
</div>

<div class="panel">
  <h2>Your widgets</h2>
  <?php if ($rows): ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Name</th><th>Shows on</th><th>Height</th><th>On / off</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['title']) ?></td>
        <td><?= e($slots[$r['slot']] ?? $r['slot']) ?><?php if ($r['slot'] === 'page'): ?><br><small><a href="<?= e(url('widgets.php?p=' . urlencode($r['page_slug']))) ?>" target="_blank"><?= e($r['page_title']) ?></a></small><?php endif; ?></td>
        <td><?= (int)$r['height'] ?>px</td>
        <td><form class="inl" method="post" action="<?= e(url($back)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn tiny <?= $r['is_active'] ? 'gold' : 'outline' ?>" type="submit"><?= $r['is_active'] ? 'On' : 'Off' ?></button></form></td>
        <td class="acts"><a class="btn outline tiny" href="<?= e(url($back . '?edit=' . (int)$r['id'])) ?>">Edit</a>
          <form class="inl" method="post" action="<?= e(url($back)) ?>" onsubmit="return confirm('Delete this widget?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny loss" type="submit">Delete</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php else: ?><div class="empty">No widgets yet. Add your first one above.</div><?php endif; ?>
</div>
<script>
var WP=<?= json_encode(widget_presets(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
(function(){var pr=document.getElementById('w-preset');pr.addEventListener('change',function(){var p=WP[pr.value];if(!p)return;var t=document.getElementById('w-title');if(!t.value)t.value=p[0];document.getElementById('w-slot').value=p[1];document.getElementById('w-slot').dispatchEvent(new Event('change'));document.getElementById('w-h').value=p[2];document.getElementById('w-hint').textContent=p[3];if(p[4]&&!document.getElementById('w-code').value)document.getElementById('w-code').value=p[4];});})();
(function(){var b=document.getElementById('w-white');b.addEventListener('click',function(){var c=document.getElementById('w-code');c.value=c.value.replace(/([?&;]c=)[0-9A-Fa-f]{6}/,'$1FFFFFF');});})();
(function(){var s=document.getElementById('w-slot'),b=document.getElementById('w-pagebox');function f(){b.style.display=s.value==='page'?'':'none';}s.addEventListener('change',f);f();})();
</script>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
