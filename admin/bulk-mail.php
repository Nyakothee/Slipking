<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('mail');
$pdo = db();
$ready = bulk_ready();
$back = 'admin/bulk-mail.php';

/* ---- template download ---- */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="slipking-email-list-template.csv"');
    echo "\xEF\xBB\xBF" . "email,name\r\njohn@example.com,John Kamau\r\nmary@example.com,Mary Achieng\r\n";
    exit;
}

/* ---- JSON: send one batch ---- */
if ($ready && ($_GET['action'] ?? '') === 'batch') {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    if (!is_staff() || !can('mail') || !csrf_check()) { echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']); exit; }
    @set_time_limit(60); session_write_close();
    $c = bulk_send_batch((int)($_POST['c'] ?? 0), 8);
    if (!$c) { echo json_encode(['ok' => false, 'error' => 'Campaign not found.']); exit; }
    $left = (int)$pdo->query("SELECT COUNT(*) FROM bulk_queue WHERE campaign_id = " . (int)$c['id'] . " AND status IN ('pending','sending')")->fetchColumn();
    echo json_encode(['ok' => true, 'total' => (int)$c['total'], 'sent' => (int)$c['sent'], 'failed' => (int)$c['failed'], 'skipped' => (int)$c['skipped'], 'left' => $left, 'status' => $c['status']]);
    exit;
}

$errors = [];
$f = ['subject' => '', 'body' => "Hi {first_name},\n\nWrite your message here. Leave a blank line between paragraphs.\n\nBet smart,", 'cta_text' => '', 'cta_url' => '', 'paste' => ''];
$parsed = null;

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'cancel') {
            $pdo->prepare("UPDATE bulk_campaigns SET status = 'cancelled' WHERE id = ?")->execute([(int)$_POST['id']]);
            $pdo->prepare("UPDATE bulk_queue SET status = 'skipped', error = 'cancelled' WHERE campaign_id = ? AND status = 'pending'")->execute([(int)$_POST['id']]);
            admin_log('bulkmail.cancel', 'campaign#' . (int)$_POST['id']); set_flash('success', 'Campaign stopped.'); redirect($back);
        }
        foreach (['subject', 'body', 'cta_text', 'cta_url', 'paste'] as $k) { $f[$k] = trim((string)($_POST[$k] ?? '')); }
        if (mb_strlen($f['subject']) < 3 || mb_strlen($f['subject']) > 150) { $errors[] = 'Enter a subject (3 to 150 characters).'; }
        if (mb_strlen($f['body']) < 10) { $errors[] = 'Write the message.'; }
        if ($f['cta_text'] !== '' && !preg_match('~^https?://~i', $f['cta_url'])) { $errors[] = 'The button link must start with http:// or https://'; }
        $csv = '';
        if (!empty($_FILES['csv']) && ($_FILES['csv']['error'] ?? 4) === UPLOAD_ERR_OK) {
            if ($_FILES['csv']['size'] > 2097152) { $errors[] = 'The file is over 2 MB.'; }
            else { $csv = (string)file_get_contents($_FILES['csv']['tmp_name']); }
        } elseif ($f['paste'] !== '') { $csv = $f['paste']; }
        if ($act === 'test') {
            $me = current_user();
            $un = bulk_unsub_url((string)$me['email']);
            $s = bulk_merge($f['subject'] ?: 'Test email', (string)$me['name'], (string)$me['email']); $b = bulk_merge($f['body'], (string)$me['name'], (string)$me['email']);
            $ok = !$errors && send_mail((string)$me['email'], '[TEST] ' . $s, bulk_text($s, $b, $f['cta_text'], $f['cta_url'], $un), null, bulk_html($s, $b, $f['cta_text'], $f['cta_url'], $un), $un);
            if (!$errors) { set_flash($ok ? 'success' : 'error', $ok ? 'Test email sent to ' . $me['email'] . '.' : 'The test email could not be sent: ' . mail_last_error()); }
        } elseif ($act === 'start' && !$errors) {
            if ($csv === '') { $errors[] = 'Upload a CSV file or paste the email list.'; }
            else {
                $parsed = bulk_parse($csv);
                if (!$parsed['rows']) { $errors[] = 'No valid email addresses found in that list.'; }
                elseif (count($parsed['rows']) > BULK_MAIL_MAX) { $errors[] = 'That list has ' . count($parsed['rows']) . ' addresses. The limit per campaign is ' . BULK_MAIL_MAX . '. Split the file.'; }
            }
            if (!$errors) {
                $u = current_user();
                $pdo->beginTransaction();
                try { sk_ensure_column('bulk_campaigns', 'send_at', 'DATETIME NULL DEFAULT NULL'); } catch (Throwable $e) { }
                $sendAt = trim((string)($_POST['send_at'] ?? ''));
                $sendTs = $sendAt !== '' ? strtotime($sendAt) : false;
                $sched = $sendTs && $sendTs > time() + 120;
                $pdo->prepare('INSERT INTO bulk_campaigns (subject, body, cta_text, cta_url, total, created_by, status, send_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$f['subject'], $f['body'], $f['cta_text'], $f['cta_url'], count($parsed['rows']), $u['id'], $sched ? 'scheduled' : 'sending', $sched ? date('Y-m-d H:i:s', $sendTs) : null]);
                $cid = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO bulk_queue (campaign_id, email, name) VALUES (?,?,?)');
                foreach ($parsed['rows'] as $r) { $ins->execute([$cid, $r['email'], $r['name']]); }
                $pdo->commit();
                admin_log('bulkmail.start', 'campaign#' . $cid, $f['subject'] . ' (' . count($parsed['rows']) . ' recipients)');
                if ($sched) { set_flash('success', 'Scheduled for ' . date('j M Y, H:i', $sendTs) . '. It is sent automatically (the site sends a few every 5 minutes).'); redirect($back); }
                redirect($back . '?run=' . $cid);
            }
        }
    }
}
$run = (int)($_GET['run'] ?? 0);
$camp = null;
if ($ready && $run) { $q = $pdo->prepare('SELECT * FROM bulk_campaigns WHERE id = ?'); $q->execute([$run]); $camp = $q->fetch() ?: null; }
$list = $ready ? $pdo->query('SELECT * FROM bulk_campaigns ORDER BY id DESC LIMIT 15')->fetchAll() : [];
$sampleHtml = bulk_html(bulk_merge($f['subject'] ?: 'Your subject appears here', 'Jane Wanjiru', 'jane@example.com'), bulk_merge($f['body'], 'Jane Wanjiru', 'jane@example.com'), $f['cta_text'], $f['cta_url'], '#');
$admin_title = 'Mass email';
$admin_active = 'bulkmail';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Mass email</h1>
<p class="sub">Upload a CSV of email addresses and send one beautifully designed SlipKing email to everyone. Every email carries the SlipKing logo, and an unsubscribe link that is honoured automatically.</p>
<?php if (!$ready): ?><div class="errors" role="alert"><ul><li>Run <code>database-update-34.sql</code> once in phpMyAdmin.</li></ul></div><?php else: ?>

<?php if ($camp): ?>
<div class="panel" id="runbox" data-c="<?= (int)$camp['id'] ?>" data-url="<?= e(url('admin/bulk-mail.php?action=batch')) ?>">
  <h2>Sending: <?= e($camp['subject']) ?></h2>
  <div style="height:16px;border-radius:99px;background:#e6ede8;overflow:hidden;margin:14px 0"><div id="pbar" style="height:100%;width:0;background:linear-gradient(90deg,#f0a91c,#ffc94d);transition:width .4s"></div></div>
  <p id="pstat" style="font-size:16px">Starting...</p>
  <p class="hint">Keep this page open while it sends. If you close it, come back and press Resume. Nothing is sent twice.</p>
  <form method="post" action="<?= e(url($back)) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$camp['id'] ?>"><button class="btn loss small" type="submit">Stop</button></form>
  <button class="btn dark small" type="button" id="presume" hidden>Resume</button>
</div>
<script>
(function(){var b=document.getElementById('runbox'),bar=document.getElementById('pbar'),st=document.getElementById('pstat'),rs=document.getElementById('presume'),busy=false,tok=<?= json_encode(csrf_token()) ?>;
function step(){if(busy)return;busy=true;rs.hidden=true;var fd=new FormData();fd.append('c',b.dataset.c);fd.append('csrf',tok);
fetch(b.dataset.url,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){busy=false;
if(!j.ok){st.textContent=j.error||'Problem';rs.hidden=false;return}
var done=j.sent+j.failed+j.skipped;bar.style.width=(j.total?Math.round(done/j.total*100):100)+'%';
st.innerHTML='<b>'+done+'</b> of '+j.total+' processed. Sent <b style="color:#188a4a">'+j.sent+'</b>, failed <b style="color:#c8372d">'+j.failed+'</b>, skipped '+j.skipped+'.';
if(j.left>0&&j.status!=='cancelled'){setTimeout(step,300)}else{st.innerHTML+=' <b>'+(j.status==='cancelled'?'Stopped.':'Finished.')+'</b>'}
}).catch(function(){busy=false;st.textContent='Network problem. Press Resume.';rs.hidden=false})}
rs.addEventListener('click',step);step();})();
</script>
<?php endif; ?>

<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="grid2" style="align-items:start">
<form method="post" enctype="multipart/form-data" class="panel" action="<?= e(url($back)) ?>"><?= csrf_field() ?>
  <h2>1. Recipients</h2>
  <div class="field"><label for="m-csv">CSV file</label><input type="file" id="m-csv" name="csv" accept=".csv,.txt,text/csv,text/plain">
    <div class="hint">One address per row. Optional second column with the name. <a href="<?= e(url('admin/bulk-mail.php?template=1')) ?>" style="text-decoration:underline;font-weight:700">Download a template</a>. Up to <?= number_format(BULK_MAIL_MAX) ?> per campaign.</div></div>
  <div class="field"><label for="m-paste">...or paste the list</label><textarea id="m-paste" name="paste" rows="4" style="font-family:monospace" placeholder="john@example.com,John Kamau"><?= e($f['paste']) ?></textarea></div>
  <h2 style="margin-top:8px">2. Message</h2>
  <div class="field"><label for="m-sub">Subject</label><input type="text" id="m-sub" name="subject" value="<?= e($f['subject']) ?>" maxlength="150" placeholder="e.g. This weekend's VIP picks are live"></div>
  <div class="field"><label for="m-body">Message</label><textarea id="m-body" name="body" rows="9"><?= e($f['body']) ?></textarea>
    <div class="hint">Use <code>{first_name}</code> or <code>{name}</code> to personalise. A blank line starts a new paragraph. Links are made clickable.</div></div>
  <div class="formgrid"><div class="field"><label for="m-ct">Button text (optional)</label><input type="text" id="m-ct" name="cta_text" value="<?= e($f['cta_text']) ?>" maxlength="60" placeholder="e.g. See today's tips"></div>
    <div class="field"><label for="m-at">Send later (optional, leave empty to send now)</label><input type="datetime-local" id="m-at" name="send_at"></div>
    <div class="field"><label for="m-cu">Button link</label><input type="url" id="m-cu" name="cta_url" value="<?= e($f['cta_url']) ?>" maxlength="400" placeholder="https://..."></div></div>
  <button class="btn gold" type="submit" name="action" value="start" onclick="return confirm('Send this email to everyone in the list?')">Send to the list</button>
  <button class="btn outline" type="submit" name="action" value="test">Send a test to me</button>
  <button class="btn outline" type="submit" name="action" value="preview">Refresh preview</button>
</form>
<div class="panel"><h2>Preview</h2><iframe id="m-frame" title="Email preview" style="width:100%;height:760px;border:1px solid var(--ad-line,#e3e8e2);border-radius:12px;background:#eef1ec" srcdoc="<?= e($sampleHtml) ?>"></iframe>
  <p class="hint">Shown with the sample name "Jane Wanjiru". The logo loads from your website, so it shows on a live site (a local test site cannot show it inside other people's inboxes).</p></div>
</div>


<div class="panel"><h2>Recent campaigns</h2>
  <?php if ($list): ?><div class="scroll"><table class="tbl"><thead><tr><th>Sent on</th><th>Subject</th><th>Recipients</th><th>Sent</th><th>Failed</th><th>Skipped</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($list as $c): ?><tr><td><?= e(date('j M Y, H:i', strtotime($c['created_at']))) ?></td><td><?= e($c['subject']) ?></td><td><?= (int)$c['total'] ?></td><td><?= (int)$c['sent'] ?></td><td><?= (int)$c['failed'] ?></td><td><?= (int)$c['skipped'] ?></td>
    <td><span class="badge <?= $c['status'] === 'done' ? 'won' : ($c['status'] === 'cancelled' ? 'lost' : 'pending') ?>"><?= e(ucfirst($c['status'])) ?></span></td>
    <td><?php if ($c['status'] === 'sending'): ?><a class="btn tiny gold" href="<?= e(url('admin/bulk-mail.php?run=' . (int)$c['id'])) ?>">Resume</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><div class="empty">No campaigns yet.</div><?php endif; ?>
  <?php $oo = (int)$pdo->query('SELECT COUNT(*) FROM mail_optout')->fetchColumn(); ?><p class="hint">Unsubscribed addresses are skipped automatically (<?= $oo ?> so far).</p>
</div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
