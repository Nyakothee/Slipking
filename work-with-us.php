<?php
/**
 * Hidden job application page. Not linked from anywhere on the site, not in the sitemap, and marked noindex.
 * Share the link only with the people you want to apply. Admins read the applications in Admin > Job applications.
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/jobs.php';

$errors = [];
$done = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)($_GET['done'] ?? '')));
$form = job_form();
$files = job_files();
$old = [];
$ready = jobs_table_ok();
$open = $ready && jobs_open();

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Your files are too large for this server to accept. Please use smaller files (each under 5 MB, together under 20 MB).';
    } elseif (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website_url2'])) {
        redirect('work-with-us.php');                                   // honeypot
    } else {
        $ip = client_ip();
        $st = db()->prepare('SELECT COUNT(*) FROM job_applications WHERE ip = ? AND created_at > NOW() - INTERVAL 1 DAY');
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 3) { $errors[] = 'Too many applications from this connection today. Please try again tomorrow.'; }

        $core = []; $ans = [];
        foreach ($form as $sec) {
            foreach ($sec['fields'] as $k => $fd) {
                if ($fd['type'] === 'checks') { $v = array_values(array_intersect((array)($_POST[$k] ?? []), $fd['opts'])); }
                else { $v = trim((string)($_POST[$k] ?? '')); if (!empty($fd['max'])) { $v = mb_substr($v, 0, (int)$fd['max']); } }
                $old[$k] = $v;
                $empty = is_array($v) ? !$v : $v === '';
                if ($empty) {
                    if (!empty($fd['req'])) { $errors[] = 'Please fill in: ' . $fd['label']; }
                } else {
                    if (!is_array($v) && in_array($fd['type'], ['select', 'radio'], true) && !in_array($v, $fd['opts'], true)) { $errors[] = 'Please choose a valid answer for: ' . $fd['label']; }
                    if ($fd['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
                    if ($fd['type'] === 'tel' && !preg_match('/^\+?[0-9 ()\-]{7,25}$/', $v)) { $errors[] = 'Enter a valid phone number for: ' . $fd['label']; }
                    if ($fd['type'] === 'country' && !array_filter(countries(), function ($c) use ($v) { return strcasecmp($c['name'], $v) === 0; })) { $errors[] = 'Choose your country from the list.'; }
                    if ($fd['type'] === 'url') {
                        if (!preg_match('#^https?://#i', $v)) { $v = 'https://' . $v; $old[$k] = $v; }
                        if (!filter_var($v, FILTER_VALIDATE_URL)) { $errors[] = 'Enter a valid web address for: ' . $fd['label']; }
                    }
                    if ($fd['type'] === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $errors[] = 'Enter a valid date for: ' . $fd['label']; }
                    if (!empty($fd['min']) && mb_strlen($v) < (int)$fd['min']) { $errors[] = $fd['label'] . ' needs at least ' . (int)$fd['min'] . ' characters. Please say a little more.'; }
                }
                if (!empty($fd['core'])) { $core[$k] = $v; } else { $ans[$k] = $v; }
            }
        }
        // age
        if (!empty($old['dob']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $old['dob'])) {
            try {
                $age = (new DateTime($old['dob']))->diff(new DateTime('today'))->y;
                if ($age < 18) { $errors[] = 'You must be 18 or older to apply.'; }
                if ($age > 90 || (new DateTime($old['dob'])) > new DateTime('today')) { $errors[] = 'Check your date of birth.'; }
            } catch (Throwable $e) { $errors[] = 'Enter a valid date of birth.'; }
        }
        if (($old['rg'] ?? '') === 'No') { $errors[] = 'We can only hire people who agree to promote betting responsibly (18+).'; }
        foreach (['d_true' => 'Please confirm that everything you wrote is true.', 'd_consent' => 'Please agree that we may store and process your application.', 'd_bg' => 'Please agree to reference and identity checks.'] as $k => $msg) {
            if (empty($_POST[$k])) { $errors[] = $msg; }
        }
        // duplicate
        if (!$errors && !empty($core['email'])) {
            $st = db()->prepare("SELECT 1 FROM job_applications WHERE email = ? AND role = ? AND created_at > NOW() - INTERVAL 30 DAY LIMIT 1");
            $st->execute([strtolower($core['email']), $core['role']]);
            if ($st->fetchColumn()) { $errors[] = 'You already applied for this position. We will contact you if you are shortlisted.'; }
        }
        // files
        $saved = [];
        foreach ($files as $k => $fd) {
            $ferr = '';
            $n = job_save_file($_FILES[$k] ?? null, $fd['kinds'], $ferr);
            if ($ferr !== '') { $errors[] = $fd['label'] . ' ' . $ferr . '.'; }
            elseif ($n === null && !empty($fd['req'])) { $errors[] = 'Please upload: ' . $fd['label']; }
            if ($n) { $saved[$k] = $n; }
        }
        if ($errors) {
            foreach ($saved as $n) { if ($p = job_file_path($n)) { @unlink($p); } }
        } else {
            $ref = job_new_ref();
            try {
                db()->prepare('INSERT INTO job_applications (ref, status, role, name, email, phone, country, city, dob, gender, answers, photo_file, id_file, cv_file, cert_file, work_file, ip, user_id)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$ref, 'new', $core['role'], $core['name'], strtolower($core['email']), $core['phone'], $core['country'], $core['city'], $core['dob'], $core['gender'] !== '' ? $core['gender'] : null,
                               json_encode($ans, JSON_UNESCAPED_UNICODE), $saved['photo'] ?? null, $saved['idscan'] ?? null, $saved['cv'] ?? null, $saved['certs'] ?? null, $saved['work'] ?? null, $ip, is_logged_in() ? (int)current_user()['id'] : null]);
                try {
                    send_mail(SITE_EMAIL, '[Job application] ' . $core['role'] . ' - ' . $core['name'], "New job application $ref\n\nName: {$core['name']}\nPosition: {$core['role']}\nCountry: {$core['country']}\nEmail: {$core['email']}\n\nOpen it in Admin > Job applications:\n" . url('admin/job-view.php?ref=' . $ref), $core['email']);
                    send_mail($core['email'], 'We received your application - ' . SITE_NAME, "Hello {$core['name']},\n\nThank you for applying for the position of " . $core['role'] . " at " . SITE_NAME . ".\nYour reference number is $ref.\n\nWe read every application. If you are shortlisted we will contact you on the email or phone number you gave us.\n\n" . SITE_NAME);
                } catch (Throwable $me) { error_log('job mail: ' . $me->getMessage()); }
                redirect('work-with-us.php?done=' . $ref);
            } catch (PDOException $ex) {
                error_log('job apply: ' . $ex->getMessage());
                foreach ($saved as $n) { if ($p = job_file_path($n)) { @unlink($p); } }
                $errors[] = 'We could not save your application. Please try again in a moment.';
            }
        }
        $errors = array_values(array_unique($errors));
    }
}

$page_title = 'Job application - ' . SITE_NAME;
$page_desc = 'Apply to work with ' . SITE_NAME . ' in marketing and sales.';
$active = '';
$head_extra = '<meta name="robots" content="noindex,nofollow,noarchive"><link rel="stylesheet" href="' . e(url('assets/css/jobs.css')) . '?v=1">';
include __DIR__ . '/includes/header.php';

function job_val(array $old, string $k): string { return is_array($old[$k] ?? '') ? '' : (string)($old[$k] ?? ''); }
?>
<section>
  <div class="wrap jobwrap">
    <?php if ($done !== ''): ?>
      <div class="fcard center jobdone">
        <h2>Application received</h2>
        <p class="sub">Thank you for applying to <?= e(SITE_NAME) ?>.</p>
        <p>Your reference number is <strong><?= e($done) ?></strong>. Keep it. We read every application and will contact you by email or phone if you are shortlisted.</p>
      </div>
    <?php elseif (!$ready || !$open): ?>
      <div class="fcard center jobdone">
        <h2>Applications are closed</h2>
        <p class="sub">We are not taking job applications at the moment. Please check back later.</p>
      </div>
    <?php else: ?>
    <div class="jobhead">
      <h1>Work with <?= e(SITE_NAME) ?></h1>
      <p>We are hiring people to market and sell <?= e(SITE_NAME) ?>: social media, paid ads, content, SEO, partnerships, community and sales. Fill in this application fully and honestly. It takes about 20 minutes. Have your CV, a photo of yourself and a picture of your ID ready.</p>
      <ul class="jobtips"><li>You must be 18 or older.</li><li>Files: JPG, PNG, WebP, PDF, DOC or DOCX, each up to 5 MB.</li><li>Your documents are private. Only our hiring team can see them.</li></ul>
    </div>
    <?php if ($errors): ?><div class="errors" role="alert"><strong>Please fix this:</strong><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      <?php if ($_POST): ?><p style="margin-top:6px">Your answers are kept, but please choose your files again.</p><?php endif; ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="jobform" autocomplete="on" novalidate>
      <?= csrf_field() ?>
      <div class="hp" aria-hidden="true"><label>Leave empty <input type="text" name="website_url2" tabindex="-1" autocomplete="off"></label></div>
      <?php foreach ($form as $sid => $sec): ?>
      <fieldset class="jobsec"><legend><?= e($sec['title']) ?></legend>
        <div class="jobgrid">
        <?php foreach ($sec['fields'] as $k => $fd):
            $req = !empty($fd['req']); $v = $old[$k] ?? ''; $wide = in_array($fd['type'], ['textarea', 'checks', 'radio'], true) || !empty($fd['wide']); ?>
          <div class="field<?= $wide ? ' wide' : '' ?>">
            <label for="f_<?= e($k) ?>"><?= e($fd['label']) ?><?= $req ? ' <span class="rq" title="Required">*</span>' : '' ?></label>
            <?php if ($fd['type'] === 'select'): ?>
              <select id="f_<?= e($k) ?>" name="<?= e($k) ?>"<?= $req ? ' required' : '' ?>><option value="">Choose...</option>
                <?php foreach ($fd['opts'] as $o): ?><option value="<?= e($o) ?>"<?= $v === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
            <?php elseif ($fd['type'] === 'textarea'): ?>
              <textarea id="f_<?= e($k) ?>" name="<?= e($k) ?>" rows="<?= (int)($fd['rows'] ?? 4) ?>" maxlength="<?= (int)($fd['max'] ?? 2000) ?>"<?= $req ? ' required' : '' ?>><?= e((string)$v) ?></textarea>
            <?php elseif ($fd['type'] === 'radio'): ?>
              <div class="opts"><?php foreach ($fd['opts'] as $i => $o): ?><label class="opt"><input type="radio" name="<?= e($k) ?>" value="<?= e($o) ?>"<?= $v === $o ? ' checked' : '' ?>> <span><?= e($o) ?></span></label><?php endforeach; ?></div>
            <?php elseif ($fd['type'] === 'checks'): ?>
              <div class="opts chips"><?php foreach ($fd['opts'] as $o): ?><label class="opt"><input type="checkbox" name="<?= e($k) ?>[]" value="<?= e($o) ?>"<?= is_array($v) && in_array($o, $v, true) ? ' checked' : '' ?>> <span><?= e($o) ?></span></label><?php endforeach; ?></div>
            <?php elseif ($fd['type'] === 'country'): ?>
              <?= country_picker('f_' . $k, $k, (string)$v, ['by' => 'name', 'empty' => 'Choose your country', 'required' => $req]) ?>
            <?php else: $t = ['email' => 'email', 'tel' => 'tel', 'date' => 'date', 'url' => 'text'][$fd['type']] ?? 'text'; ?>
              <input type="<?= $t ?>" id="f_<?= e($k) ?>" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"<?= !empty($fd['max']) ? ' maxlength="' . (int)$fd['max'] . '"' : '' ?><?= $req ? ' required' : '' ?><?= !empty($fd['ac']) ? ' autocomplete="' . e($fd['ac']) . '"' : '' ?><?= $fd['type'] === 'date' ? ' max="' . e(date('Y-m-d', strtotime('-18 years'))) . '"' : '' ?><?= $fd['type'] === 'url' ? ' inputmode="url" placeholder="https://"' : '' ?>>
            <?php endif; ?>
            <?php if (!empty($fd['hint'])): ?><div class="hint"><?= e($fd['hint']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
      </fieldset>
      <?php endforeach; ?>

      <fieldset class="jobsec"><legend>7. Your documents</legend>
        <div class="jobgrid">
        <?php foreach ($files as $k => $fd): ?>
          <div class="field wide filefield">
            <label for="u_<?= e($k) ?>"><?= e($fd['label']) ?><?= !empty($fd['req']) ? ' <span class="rq" title="Required">*</span>' : '' ?></label>
            <input type="file" id="u_<?= e($k) ?>" name="<?= e($k) ?>"<?= !empty($fd['req']) ? ' required' : '' ?> accept="<?= e(implode(',', array_merge(in_array('img', $fd['kinds'], true) ? ['image/jpeg', 'image/png', 'image/webp'] : [], in_array('pdf', $fd['kinds'], true) ? ['application/pdf'] : [], in_array('doc', $fd['kinds'], true) ? ['.doc', '.docx'] : []))) ?>">
            <?php if ($k === 'photo'): ?><img id="photoprev" class="photoprev" alt="" hidden><?php endif; ?>
            <div class="hint"><?= e($fd['hint']) ?></div>
          </div>
        <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="jobsec"><legend>8. Declaration</legend>
        <div class="field check"><label><input type="checkbox" name="d_true" value="1"<?= !empty($_POST['d_true']) ? ' checked' : '' ?>> <span>I confirm that everything in this application is true and complete. I know that false information can end my application or my job.</span></label></div>
        <div class="field check"><label><input type="checkbox" name="d_consent" value="1"<?= !empty($_POST['d_consent']) ? ' checked' : '' ?>> <span>I agree that <?= e(SITE_NAME) ?> may store and use my application and documents to decide about hiring me, as described in the <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener" style="text-decoration:underline">privacy policy</a>.</span></label></div>
        <div class="field check"><label><input type="checkbox" name="d_bg" value="1"<?= !empty($_POST['d_bg']) ? ' checked' : '' ?>> <span>I agree that you may contact my referees and check my identity.</span></label></div>
      </fieldset>
      <button class="btn gold full" type="submit">Send my application</button>
      <p class="jobfoot">Fields marked <span class="rq">*</span> are required.</p>
    </form>
    <script>
    (function(){
      var f=document.getElementById('u_photo'), p=document.getElementById('photoprev');
      if(f&&p){ f.addEventListener('change',function(){ var x=f.files&&f.files[0]; if(x&&x.type.indexOf('image/')===0){ p.src=URL.createObjectURL(x); p.hidden=false; } else { p.hidden=true; } }); }
    })();
    </script>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
