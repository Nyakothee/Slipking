<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
$ok = app_ready();
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('admin/app.php'); }
    $act = (string)($_POST['action'] ?? ''); $rid = (int)($_POST['id'] ?? 0); $pdo = db();
    if ($act === 'current' && $rid) {
        $pdo->exec('UPDATE app_releases SET is_current = 0'); $pdo->prepare('UPDATE app_releases SET is_current = 1 WHERE id = ?')->execute([$rid]);
        admin_log('app.current', (string)$rid); set_flash('success', 'This version is now the one members download.');
    } elseif ($act === 'unpublish') {
        $pdo->exec('UPDATE app_releases SET is_current = 0'); admin_log('app.unpublish'); set_flash('success', 'The download page now shows "coming soon".');
    } elseif ($act === 'delete' && $rid) {
        $q = $pdo->prepare('SELECT file_name FROM app_releases WHERE id = ?'); $q->execute([$rid]); $fn = (string)$q->fetchColumn();
        if ($fn !== '' && preg_match('/^app-[a-f0-9]{16}\.apk$/', $fn)) { @unlink(app_dir() . '/' . $fn); }
        $pdo->prepare('DELETE FROM app_releases WHERE id = ?')->execute([$rid]); admin_log('app.delete', (string)$rid); set_flash('success', 'Version deleted.');
    } elseif ($act === 'notes' && $rid) {
        $pdo->prepare('UPDATE app_releases SET notes = ?, min_android = ? WHERE id = ?')->execute([mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000), mb_substr(trim((string)($_POST['min_android'] ?? '')), 0, 20), $rid]); set_flash('success', 'Saved.');
    } elseif ($act === 'external') {
        $v = trim((string)($_POST['version'] ?? '')); $link = trim((string)($_POST['url'] ?? ''));
        if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-+ ]{0,29}$/', $v) || !preg_match('~^https://[^\s<>"]{4,280}$~i', $link)) { set_flash('error', 'Enter a version and a full https:// link.'); }
        else {
            $pdo->exec('UPDATE app_releases SET is_current = 0');
            $pdo->prepare('INSERT INTO app_releases (version, notes, external_url, is_current) VALUES (?,?,?,1)')->execute([$v, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000), $link]);
            admin_log('app.external', $v, $link); set_flash('success', 'Link published.');
        }
    } elseif ($act === 'force') {
        set_setting('app_force_update', !empty($_POST['force']) ? '1' : '0'); admin_log('app.force', !empty($_POST['force']) ? 'on' : 'off'); set_flash('success', 'Saved.'); redirect('admin/app.php');
    } elseif ($act === 'play') {
        $link = trim((string)($_POST['play_url'] ?? ''));
        if ($link !== '' && !preg_match('~^https://play\.google\.com/[^\s<>"]{2,250}$~i', $link)) { set_flash('error', 'Use the full Google Play link (https://play.google.com/...).'); }
        else { set_setting('app_play_url', $link); set_flash('success', 'Saved.'); }
    } elseif ($act === 'fcm_save') {
        $raw = trim((string)($_POST['fcm_json'] ?? '')); $j = json_decode($raw, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['project_id']) || empty($j['client_email']) || empty($j['private_key']) || !@openssl_pkey_get_private((string)$j['private_key'])) {
            set_flash('error', 'That is not a valid Firebase service-account key. Paste the whole .json file from Firebase > Project settings > Service accounts > Generate new private key.');
        } else {
            $keep = ['type' => 'service_account', 'project_id' => $j['project_id'], 'client_email' => $j['client_email'], 'private_key' => $j['private_key'], 'token_uri' => $j['token_uri'] ?? 'https://oauth2.googleapis.com/token'];
            app_dir(); @file_put_contents(fcm_file(), json_encode($keep), LOCK_EX); @chmod(fcm_file(), 0600); @unlink(dirname(__DIR__) . '/cache/fcm-token.json');
            admin_log('app.fcm.save', (string)$j['project_id']); set_flash('success', 'Firebase push is on. Press "Send a test" to check it.');
        }
    } elseif ($act === 'fcm_remove') {
        @unlink(fcm_file()); @unlink(dirname(__DIR__) . '/cache/fcm-token.json'); admin_log('app.fcm.remove'); set_flash('success', 'Firebase key removed. The app goes back to checking every 15 minutes.');
    } elseif ($act === 'fcm_test') {
        $me = current_user();
        if (!fcm_ready() || !app_push_ready()) { set_flash('error', 'Add the Firebase key first.'); }
        else {
            $n = $pdo->prepare('SELECT COUNT(*) FROM app_devices WHERE user_id = ?'); $n->execute([(int)$me['id']]);
            if (!(int)$n->fetchColumn()) { set_flash('error', 'No phone is registered for your account. Open the app, sign in as yourself, wait a few seconds, then try again.'); }
            else { fcm_queue((int)$me['id'], SITE_NAME, 'Test notification: Firebase push is working.', 'account/notifications.php'); fcm_process(5); set_flash('success', 'Test sent. Your phone should buzz within seconds.'); }
        }
    }
    redirect('admin/app.php');
}
$rows = $ok ? db()->query('SELECT * FROM app_releases ORDER BY id DESC LIMIT 50')->fetchAll() : [];
$dirOk = is_dir(app_dir()) && is_writable(app_dir());
$admin_title = 'Android app'; $admin_active = 'app';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Android app</h1>
<p class="sub">Upload the app file (.apk) here. Members download it from <a href="<?= e(url('download-app.php')) ?>" target="_blank" rel="noopener">the download page</a>, which is also linked in the website footer.</p>
<?php if (!$ok): ?><div class="note">Import database-update-26.sql first.</div><?php else: ?>
<?php if (!$dirOk): ?><div class="alert">The folder <code>private-uploads/app</code> is missing or cannot be written to. Create it and give it write permission (755 or 775).</div><?php endif; ?>
<div class="box2" style="margin-bottom:16px"><h3>Upload a new version</h3>
  <form id="appf" onsubmit="return false"><?= csrf_field() ?>
    <div class="field"><label>App file (.apk, up to <?= e(app_size_text(APP_MAX_BYTES)) ?>)</label><input type="file" id="apkfile" accept=".apk,application/vnd.android.package-archive"></div>
    <div class="field"><label>Version</label><input id="apkver" maxlength="30" placeholder="1.0.0" style="max-width:160px"></div>
    <div class="field"><label>Needs Android (optional)</label><input id="apkmin" maxlength="20" placeholder="8.0 or higher" style="max-width:200px"></div>
    <div class="field"><label>What is new (optional)</label><textarea id="apknotes" rows="3" maxlength="2000"></textarea></div>
    <label style="display:flex;gap:8px;align-items:center;margin:6px 0 12px"><input type="checkbox" id="apkcur" checked> Make this the version members download</label>
    <button class="btn dark" type="button" id="apkgo">Upload</button>
    <div id="apkbar" style="display:none;margin-top:12px"><div style="height:10px;background:#e5e5e5;border-radius:6px;overflow:hidden"><i id="apkfill" style="display:block;height:100%;width:0;background:#0fb583"></i></div><small id="apktxt"></small></div>
  </form>
  <p style="color:var(--mute);font-size:13px;margin:10px 0 0">The file is sent in small pieces, so your hosting's upload size limit does not matter. Keep this page open until it says done. Sign the app with the same key every time, or phones will refuse to update it.</p>
</div>
<script>
(function(){
  var f=document.getElementById('apkfile'),go=document.getElementById('apkgo'),bar=document.getElementById('apkbar'),fill=document.getElementById('apkfill'),txt=document.getElementById('apktxt'),form=document.getElementById('appf');
  var CH=2*1024*1024,url=<?= json_encode(url('admin/app-upload.php')) ?>;
  function rid(){var a=new Uint8Array(12),s='';(window.crypto||window.msCrypto).getRandomValues(a);for(var i=0;i<a.length;i++)s+=('0'+a[i].toString(16)).slice(-2);return s;}
  function say(t,bad){txt.textContent=t;txt.style.color=bad?'#c0392b':'';}
  function post(fd,tries){return fetch(url,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json().then(function(j){return{status:r.status,j:j};});}).catch(function(e){if(tries>0)return new Promise(function(ok){setTimeout(ok,1500);}).then(function(){return post(fd,tries-1);});throw e;});}
  go.addEventListener('click',function(){
    var file=f.files[0],ver=document.getElementById('apkver').value.trim();
    bar.style.display='block';
    if(!file){say('Choose the .apk file first.',1);return;}
    if(!/\.apk$/i.test(file.name)){say('The file must end in .apk',1);return;}
    if(!/^[0-9A-Za-z][0-9A-Za-z.\-+ ]{0,29}$/.test(ver)){say('Enter the version, for example 1.0.0',1);return;}
    go.disabled=true;var id=rid(),off=0,csrf=form.querySelector('[name=csrf]').value;
    function next(){
      if(off>=file.size){return finish();}
      var fd=new FormData();fd.append('csrf',csrf);fd.append('action','chunk');fd.append('id',id);fd.append('offset',off);fd.append('chunk',file.slice(off,off+CH),'part');
      return post(fd,3).then(function(r){
        if(r.status!==200){throw new Error(r.j.error||'Upload failed');}
        off=r.j.size;var p=Math.min(100,Math.round(off/file.size*100));fill.style.width=p+'%';say('Uploading... '+p+'%');return next();});
    }
    function finish(){
      say('Checking the file...');
      var fd=new FormData();fd.append('csrf',csrf);fd.append('action','finish');fd.append('id',id);fd.append('version',ver);fd.append('notes',document.getElementById('apknotes').value);fd.append('min_android',document.getElementById('apkmin').value);if(document.getElementById('apkcur').checked)fd.append('make_current','1');
      return post(fd,1).then(function(r){if(r.status!==200){throw new Error(r.j.error||'Could not finish');}say('Done. Reloading...');location.href=location.pathname+'?done=1';});
    }
    next().catch(function(e){go.disabled=false;say(e.message||'Upload failed. Try again.',1);});
  });
})();
</script>
<h2>Versions</h2>
<?php if (!$rows): ?><div class="note">No version uploaded yet.</div><?php else: ?>
<div class="scroll"><table class="bm-table"><thead><tr><th>Version</th><th>File</th><th>Uploaded</th><th>Downloads</th><th>Notes</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr>
  <td><b><?= e($r['version']) ?></b><?= (int)$r['is_current'] ? '<br><span style="color:#0a7a56;font-weight:700">Live</span>' : '' ?></td>
  <td><?= $r['file_name'] !== '' ? e(app_size_text((int)$r['file_size'])) . '<br><small style="word-break:break-all">SHA-256 ' . e(substr($r['sha256'], 0, 16)) . '...</small>' : 'Link: <small style="word-break:break-all">' . e($r['external_url']) . '</small>' ?></td>
  <td><?= e(date('j M Y, H:i', strtotime($r['created_at']))) ?></td><td><?= (int)$r['downloads'] ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><textarea name="notes" rows="2" style="width:100%;min-width:180px" maxlength="2000"><?= e((string)$r['notes']) ?></textarea><input name="min_android" value="<?= e($r['min_android']) ?>" placeholder="Needs Android" maxlength="20" style="width:130px"> <button class="btn small ghost" type="submit">Save</button></form></td>
  <td><form method="post" style="display:flex;gap:6px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
    <?php if (!(int)$r['is_current']): ?><button class="btn small" name="action" value="current">Make live</button><?php else: ?><button class="btn small ghost" name="action" value="unpublish">Take down</button><?php endif; ?>
    <button class="btn small danger" name="action" value="delete" onclick="return confirm('Delete this version and its file?')">Delete</button></form></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="two" style="margin-top:18px">
  <div class="box2"><h3>Or publish a link instead</h3><p style="color:var(--mute);font-size:14px">If the app is hosted somewhere else (Google Drive, GitHub, your own server), publish its https link. Members are sent there.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="external">
      <div class="field"><label>Version</label><input name="version" maxlength="30" required placeholder="1.0.0" style="max-width:160px"></div>
      <div class="field"><label>Download link (https://...)</label><input name="url" maxlength="300" required></div>
      <div class="field"><label>What is new (optional)</label><textarea name="notes" rows="2" maxlength="2000"></textarea></div>
      <button class="btn dark" type="submit">Publish link</button></form></div>
  <div class="box2"><h3>Force members to update</h3><p style="color:var(--mute);font-size:14px">When this is on and you publish a new version, anyone still using an older app sees a screen that only lets them download the new version. They cannot use the old app until they update.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="force"><label style="display:flex;gap:8px;align-items:center;margin-bottom:10px"><input type="checkbox" name="force" value="1"<?= setting('app_force_update', '1') === '1' ? ' checked' : '' ?>> Force an update when a newer version is published</label><button class="btn dark" type="submit">Save</button></form></div>
  <div class="box2"><h3>Google Play link (optional)</h3><p style="color:var(--mute);font-size:14px">Once the app is on the Play Store, add its link and a "Get it on Google Play" button appears on the download page.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="play"><div class="field"><input name="play_url" maxlength="300" value="<?= e(setting('app_play_url', '')) ?>" placeholder="https://play.google.com/store/apps/details?id=..."></div><button class="btn dark" type="submit">Save</button></form></div>
</div>
<?php endif; ?>
<?php
$devN = 0; try { if (app_push_ready()) { $devN = (int)db()->query('SELECT COUNT(DISTINCT user_id) FROM app_devices')->fetchColumn(); } } catch (Throwable $e) {}
$fcmOn = fcm_ready(); $fcmCfg = fcm_config();
?>
<div class="box2" style="margin-top:18px"><h3>Firebase push (instant phone notifications)</h3>
  <p style="color:var(--mute);font-size:14px">Status: <b><?= $fcmOn ? 'ON (project ' . e($fcmCfg['project_id']) . ')' : 'off, the app checks every 15 minutes instead' ?></b> &middot; members with the app: <?= (int)$devN ?></p>
  <p style="color:var(--mute);font-size:14px">With this on, tips, results, replies, messages and your broadcasts reach phones within seconds. One-time setup: in <a href="https://console.firebase.google.com" target="_blank" rel="noopener">Firebase console</a> create a project, add an Android app with package <code>com.slipking.app</code>, download <code>google-services.json</code> into the Android project's <code>app</code> folder and rebuild. Then open Project settings &gt; Service accounts &gt; Generate new private key and paste the whole file below. Keep this key secret.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fcm_save"><div class="field"><textarea name="fcm_json" rows="5" placeholder='{"type":"service_account", ...}' autocomplete="off" spellcheck="false" style="font-family:monospace;font-size:12px"></textarea></div><button class="btn dark" type="submit"><?= $fcmOn ? 'Replace key' : 'Save key' ?></button></form>
  <?php if ($fcmOn): ?><form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><?= csrf_field() ?><button class="btn small" name="action" value="fcm_test">Send a test to my phone</button><button class="btn small danger" name="action" value="fcm_remove" onclick="return confirm('Remove the Firebase key?')">Remove key</button></form><?php endif; ?>
  <p style="color:var(--mute);font-size:13px;margin:10px 0 0">Needs the cron job <code>php cron/send-alerts.php</code> (already in the setup) for kick-off and result alerts.</p>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
