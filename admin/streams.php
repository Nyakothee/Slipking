<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();
$ready = streams_ready();

$back = 'admin/streams.php';
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect($back); }
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($a === 'save') {
        $kind = ($_POST['kind'] ?? '') === 'highlight' ? 'highlight' : 'stream';
        $url = stream_clean_url((string)($_POST['url'] ?? ''));
        $wc = trim((string)($_POST['widget_code'] ?? ''));
        if ($wc !== '') { [$wu, $we] = stream_widget_save($wc); if ($we !== '') { set_flash('error', $we); redirect('admin/streams.php'); } $url = $wu; }
        $home = mb_substr(trim((string)($_POST['home'] ?? '')), 0, 80);
        $away = mb_substr(trim((string)($_POST['away'] ?? '')), 0, 80);
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        if ($title === '' && $home !== '' && $away !== '') { $title = $home . ' vs ' . $away; }
        $starts = trim((string)($_POST['starts_at'] ?? ''));
        $startsSql = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $starts) ? str_replace('T', ' ', $starts) . ':00' : null;
        $status = in_array($_POST['status'] ?? '', ['scheduled', 'live', 'ended'], true) ? $_POST['status'] : 'scheduled';
        $thumb = stream_clean_url((string)($_POST['thumb_url'] ?? ''));
        $mid = (int)($_POST['match_id'] ?? 0) ?: null;
        if ($url === '') { set_flash('error', 'Paste a full link that starts with http:// or https://'); }
        elseif ($title === '') { set_flash('error', 'Add a title, or fill in both team names.'); }
        else {
            $vals = [$kind, $title, $home, $away, mb_substr(trim((string)($_POST['league'] ?? '')), 0, 100), $url, mb_substr(trim((string)($_POST['platform'] ?? '')), 0, 60),
                     $startsSql, $status, $thumb, mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500), $mid, !empty($_POST['is_active']) ? 1 : 0];
            if ($id) {
                $pdo->prepare('UPDATE streams SET kind=?, title=?, home_team=?, away_team=?, league=?, url=?, platform=?, starts_at=?, status=?, thumb_url=?, description=?, match_id=?, is_active=? WHERE id=?')->execute(array_merge($vals, [$id]));
                sources_save('streams', 'id', $id);
                admin_log('stream.edit', 'stream#' . $id, $title);
                set_flash('success', 'Saved.');
            } else {
                $pdo->prepare('INSERT INTO streams (kind, title, home_team, away_team, league, url, platform, starts_at, status, thumb_url, description, match_id, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
                sources_save('streams', 'id', (int)$pdo->lastInsertId());
                admin_log('stream.add', 'stream#' . $pdo->lastInsertId(), $title);
                set_flash('success', ($kind === 'highlight' ? 'Highlight' : 'Stream') . ' added.');
            }
        }
    } elseif ($a === 'delete' && $id) {
        $r = $pdo->prepare('SELECT title FROM streams WHERE id = ?'); $r->execute([$id]); $t = (string)$r->fetchColumn();
        $pdo->prepare('DELETE FROM streams WHERE id = ?')->execute([$id]);
        admin_log('stream.delete', 'stream#' . $id, $t);
        set_flash('success', 'Deleted.');
    } elseif ($a === 'toggle' && $id) {
        $pdo->prepare('UPDATE streams SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        set_flash('success', 'Updated.');
    } elseif ($a === 'status' && $id) {
        $s = in_array($_POST['status'] ?? '', ['scheduled', 'live', 'ended'], true) ? $_POST['status'] : 'scheduled';
        $pdo->prepare('UPDATE streams SET status = ? WHERE id = ?')->execute([$s, $id]);
        set_flash('success', 'Status changed to ' . $s . '.');
    }
    redirect($back);
}

$edit = null;
if ($ready && !empty($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM streams WHERE id = ?'); $st->execute([(int)$_GET['edit']]); $edit = $st->fetch() ?: null;
}
$rows = $ready ? $pdo->query("SELECT * FROM streams ORDER BY (kind = 'stream') DESC, COALESCE(starts_at, created_at) DESC LIMIT 200")->fetchAll() : [];
$matches = [];
try {
    $matches = $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id
                            WHERE m.kickoff_at > NOW() - INTERVAL 3 DAY AND m.kickoff_at < NOW() + INTERVAL 10 DAY ORDER BY m.kickoff_at LIMIT 150")->fetchAll();
} catch (Throwable $e) {}
$f = $edit ?: ['id' => 0, 'kind' => ($_GET['kind'] ?? 'stream') === 'highlight' ? 'highlight' : 'stream', 'title' => '', 'home_team' => '', 'away_team' => '', 'league' => '', 'url' => '', 'platform' => '', 'starts_at' => null, 'status' => 'scheduled', 'thumb_url' => '', 'description' => '', 'match_id' => null, 'is_active' => 1];

$admin_title = 'Livestream and highlights';
$admin_active = 'streams';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Livestream and highlights</h1>
<p class="sub">Add a link to a match. Members who tap it are sent to that site to watch (streams), or can play it from YouTube (highlights). SlipKing never hosts video.</p>
<div class="alert"><strong>Rights.</strong> Only link to broadcasts and videos that are free to share: the official broadcaster, a licensed streaming service, or the YouTube channel of the league or club. Do not link to pirated streams. Remove a link if its owner asks.</div>
<?php if (!$ready): ?>
  <div class="panel"><p>This feature needs one database update. In phpMyAdmin, import <code>database-update-30.sql</code> once, then refresh this page.</p></div>
<?php else: ?>
<div class="panel"><h2><?= $edit ? 'Edit' : 'Add' ?> a link</h2>
<form method="post" style="display:grid;gap:12px;max-width:720px"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
  <label>Type
    <select name="kind"><option value="stream"<?= $f['kind'] === 'stream' ? ' selected' : '' ?>>Live stream (watch on another site)</option><option value="highlight"<?= $f['kind'] === 'highlight' ? ' selected' : '' ?>>Highlights (YouTube or other video)</option></select></label>
  <label>Pick a match to fill the details (optional)
    <select id="pickmatch" name="match_id"><option value="">- none -</option>
    <?php foreach ($matches as $m): ?><option value="<?= (int)$m['id'] ?>" data-home="<?= e($m['home_team']) ?>" data-away="<?= e($m['away_team']) ?>" data-league="<?= e($m['league']) ?>" data-at="<?= e(date('Y-m-d\TH:i', strtotime($m['kickoff_at']))) ?>"<?= (int)$f['match_id'] === (int)$m['id'] ? ' selected' : '' ?>><?= e(date('j M H:i', strtotime($m['kickoff_at'])) . ' - ' . $m['home_team'] . ' vs ' . $m['away_team']) ?></option><?php endforeach; ?></select></label>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><label>Home team <input type="text" name="home" value="<?= e($f['home_team']) ?>" maxlength="80"></label><label>Away team <input type="text" name="away" value="<?= e($f['away_team']) ?>" maxlength="80"></label></div>
  <label>Title (leave empty to use "Home vs Away") <input type="text" name="title" value="<?= e($f['title']) ?>" maxlength="160"></label>
  <label>League or competition <input type="text" name="league" value="<?= e($f['league']) ?>" maxlength="100" placeholder="Premier League"></label>
  <label>Link to watch <input type="url" name="url" value="<?= e($f['url']) ?>" maxlength="480" placeholder="https://..."></label>
  <label>...or paste widget / embed code instead (leave the link as it is, or empty) <textarea name="widget_code" rows="3" placeholder="&lt;iframe src=&quot;https://...&quot;&gt;&lt;/iframe&gt; or the provider's widget code" style="font-family:monospace"></textarea></label>
  <div class="full" style="grid-column:1/-1"><?= sources_form($f['extra'] ?? '') ?></div>
  <label>Where it plays (shown on the button) <input type="text" name="platform" value="<?= e($f['platform']) ?>" maxlength="60" placeholder="YouTube, SuperSport, DAZN, the club's site"></label>
  <label>Kickoff / date <input type="datetime-local" name="starts_at" value="<?= !empty($f['starts_at']) ? e(date('Y-m-d\TH:i', strtotime((string)$f['starts_at']))) : '' ?>"></label>
  <label>Status
    <select name="status"><option value="scheduled"<?= $f['status'] === 'scheduled' ? ' selected' : '' ?>>Automatic (live from kickoff for 3 hours)</option><option value="live"<?= $f['status'] === 'live' ? ' selected' : '' ?>>Live now (force)</option><option value="ended"<?= $f['status'] === 'ended' ? ' selected' : '' ?>>Ended (hide from live and upcoming)</option></select></label>
  <label>Thumbnail picture link (optional; YouTube links get one automatically) <input type="url" name="thumb_url" value="<?= e($f['thumb_url']) ?>" maxlength="400"></label>
  <label>Short note (optional) <input type="text" name="description" value="<?= e($f['description']) ?>" maxlength="500"></label>
  <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1"<?= $f['is_active'] ? ' checked' : '' ?>> Visible to members</label>
  <div><button class="btn dark" type="submit"><?= $edit ? 'Save changes' : 'Add link' ?></button> <?php if ($edit): ?><a class="btn outline" href="<?= e(url('admin/streams.php')) ?>">Cancel</a><?php endif; ?></div>
</form></div>
<script>(function(){var s=document.getElementById('pickmatch');if(!s)return;s.addEventListener('change',function(){var o=s.options[s.selectedIndex];if(!o||!o.value)return;var f=s.form;f.home.value=o.dataset.home;f.away.value=o.dataset.away;f.league.value=o.dataset.league;f.starts_at.value=o.dataset.at;});})();</script>

<div class="panel"><h2>All links</h2>
<?php if ($rows): ?><div class="scroll"><table class="tbl"><thead><tr><th>Type</th><th>Match</th><th>When</th><th>State</th><th>Clicks</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $r): $stt = stream_state($r); ?>
  <tr<?= $r['is_active'] ? '' : ' style="opacity:.55"' ?>><td><?= $r['kind'] === 'highlight' ? 'Highlights' : 'Stream' ?></td>
  <td><strong><?= e($r['title']) ?></strong><br><small><?= e($r['league']) ?> <?= $r['platform'] !== '' ? '&middot; ' . e($r['platform']) : '' ?></small></td>
  <td><?= !empty($r['starts_at']) ? e(date('j M Y, H:i', strtotime((string)$r['starts_at']))) : '-' ?></td>
  <td><?= $r['kind'] === 'stream' ? e(ucfirst($stt)) : '-' ?><?= $r['is_active'] ? '' : ' (hidden)' ?></td>
  <td><?= (int)$r['clicks'] ?></td>
  <td class="acts"><a class="btn outline tiny" href="<?= e(url('admin/streams.php?edit=' . (int)$r['id'])) ?>">Edit</a>
    <?php if ($r['kind'] === 'stream' && $stt !== 'ended'): ?><form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" name="status" value="<?= $stt === 'live' ? 'ended' : 'live' ?>" type="submit"><?= $stt === 'live' ? 'End' : 'Go live' ?></button></form><?php endif; ?>
    <form class="inl" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny" type="submit"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form>
    <form class="inl" method="post" onsubmit="return confirm('Delete this link?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny loss" type="submit">Delete</button></form></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php else: ?><p style="color:var(--mute)">No links yet. Add the first one above.</p><?php endif; ?></div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
