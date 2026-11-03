<?php
require_once dirname(__DIR__) . '/includes/init.php';
$t = require_tipster(false);
$pdo = db();
$errors = [];
$mcols = managed_cols();
$f = ['name' => $t['display_name'], 'tagline' => $t['tagline'], 'bio' => $t['bio'], 'country' => $t['country'], 'specialties' => $t['specialties'],       'method' => $t['payout_method'], 'details' => $t['payout_details'], 'accepts' => !empty($t['accepts_managed']), 'mnote' => (string)($t['managed_note'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'avatar') {
    // The photo is saved on its own, straight away, so it can never be lost because of another field.
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('tipster/profile.php'); }
    $avErr = '';
    $new = tipster_save_avatar($_FILES['avatar'] ?? null, $avErr);
    if ($new) {
        tipster_delete_avatar($t['avatar_url']);
        $pdo->prepare('UPDATE tipsters SET avatar_url = ? WHERE id = ?')->execute([$new, $t['id']]);
        set_flash('success', 'Profile photo saved.');
    } else {
        set_flash('error', $avErr !== '' ? $avErr : 'Please choose a picture first.');
    }
    redirect('tipster/profile.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'tagline', 'country', 'specialties', 'details'] as $k) { $f[$k] = post_str($k); }
    $f['bio'] = trim((string)($_POST['bio'] ?? ''));
    $f['method'] = payout_method_post();
    $f['accepts'] = !empty($_POST['accepts_managed']);
    $f['mnote'] = trim((string)($_POST['managed_note'] ?? ''));
    if (mb_strlen($f['mnote']) > 300) $errors[] = 'Your account-management terms are too long (300 characters maximum).';
    if ($f['mnote'] !== '' && looks_like_secret($f['mnote'])) $errors[] = 'Never write passwords or codes in your terms.';
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        if (mb_strlen($f['name']) < 3 || mb_strlen($f['name']) > 40) $errors[] = 'Your tipster name must be 3 to 40 characters.';
        if (mb_strlen($f['tagline']) > 120) $errors[] = 'The one-line summary is too long.';
        if (mb_strlen($f['bio']) < 30 || mb_strlen($f['bio']) > 1500) $errors[] = 'The about text must be 30 to 1500 characters.';
        if (mb_strlen($f['country']) > 60 || mb_strlen($f['specialties']) > 120) $errors[] = 'Country or specialities are too long.';
        if ($m = tipster_payout_error($f['method'], $f['details'])) $errors[] = $m;
        if (empty($t['avatar_url'])) $errors[] = 'A profile photo is required. Upload it in the Profile photo box at the top.';
        if (!$errors) {
            $details = tipster_payout_valid($f['method'], $f['details']) ?: $f['details'];
            // The slug (profile link) never changes, so shared links keep working.
            $pdo->prepare('UPDATE tipsters SET display_name=?, tagline=?, bio=?, country=?, specialties=?, payout_method=?, payout_details=? WHERE id=?')
                ->execute([$f['name'], $f['tagline'], $f['bio'], $f['country'], $f['specialties'], $f['method'], $details, $t['id']]);
            if ($mcols) { $pdo->prepare('UPDATE tipsters SET accepts_managed=?, managed_note=? WHERE id=?')->execute([$f['accepts'] ? 1 : 0, $f['mnote'] !== '' ? $f['mnote'] : null, $t['id']]); }
            set_flash('success', 'Profile saved.');
            redirect('tipster/profile.php');
        }
    }
}
$page_title = 'Tipster profile - ' . SITE_NAME;
$active = 'tipster-profile';
$tnav = 'profile';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/tipster-profile.css')) . '?v=2">' . "\n";
$hasPhoto = !empty($t['avatar_url']);
$statusLabel = ['active' => 'Active', 'pending' => 'Waiting for approval', 'suspended' => 'Suspended', 'rejected' => 'Not approved'][$t['status']] ?? ucfirst((string)$t['status']);
include dirname(__DIR__) . '/includes/header.php';
?>
<section class="tpp"><div class="wrap" style="max-width:980px">
  <?php include dirname(__DIR__) . '/includes/tipster-nav.php'; ?>

  <div class="tpp-hero">
    <div class="tpp-av"><?= tipster_avatar($t, 104) ?></div>
    <div class="tpp-id">
      <h1><?= e($t['display_name']) ?> <span class="tpp-flag"><?= tipster_flag($t, 28) ?></span></h1>
      <p class="tpp-tag"><?= e($t['tagline'] !== '' ? $t['tagline'] : 'Add a one-line summary below') ?></p>
      <div class="tpp-meta">
        <span class="tpp-pill <?= e($t['status']) ?>"><?= e($statusLabel) ?></span>
        <?php if ($t['country'] !== ''): ?><span class="tpp-pill soft"><?= tipster_flag($t, 14) ?> <?= e($t['country']) ?></span><?php endif; ?>
        <a class="tpp-link" href="<?= e(tipster_url($t)) ?>">View public profile &rarr;</a>
      </div>
    </div>
  </div>

  <?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

  <div class="tpp-card tpp-photo<?= $hasPhoto ? '' : ' need' ?>">
    <div class="tpp-ch"><h2>Profile photo</h2><span class="tpp-req"><?= $hasPhoto ? 'Done' : 'Required' ?></span></div>
    <p class="tpp-sub"><?= $hasPhoto ? 'Your photo is saved. Choose a new one to replace it.' : 'Members trust tipsters with a clear photo of a person or brand. Pick a picture and it uploads straight away.' ?></p>
    <form method="post" enctype="multipart/form-data" class="tpp-up" id="avform"><?= csrf_field() ?><input type="hidden" name="action" value="avatar">
      <img id="avprev" class="tpp-prev" src="" alt="" hidden width="64" height="64">
      <label class="tpp-file"><input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp" required><span><?= $hasPhoto ? 'Change photo' : 'Choose photo' ?></span></label>
      <button class="btn dark" type="submit" id="avbtn">Upload photo</button>
      <small>JPG, PNG or WebP, up to 2 MB.</small>
    </form>
  </div>

  <form method="post" class="tpp-form"><?= csrf_field() ?>
    <div class="tpp-card">
      <div class="tpp-ch"><span class="tpp-ic">&#128100;</span><div><h2>Public profile</h2><p class="tpp-sub">This is what members see on your tipster page.</p></div></div>
      <div class="tpp-split">
        <div class="tpp-fields">
          <div class="tf"><label for="name">Tipster name</label><div class="tf-in"><input id="name" name="name" maxlength="40" required value="<?= e($f['name']) ?>" placeholder="Your public name"><i class="tf-n" data-for="name" data-max="40"></i></div></div>
          <div class="tf"><label for="country">Country</label><div class="tf-in tf-sel"><?= country_picker('country', 'country', (string)$f['country'], ['by' => 'name', 'empty' => 'Choose your country']) ?></div><small>Your country flag is shown next to your name.</small></div>
          <div class="tf"><label for="tagline">One-line summary</label><div class="tf-in"><input id="tagline" name="tagline" maxlength="120" value="<?= e($f['tagline']) ?>" placeholder="e.g. Premier League goals specialist"><i class="tf-n" data-for="tagline" data-max="120"></i></div></div>
          <div class="tf"><label for="specialties">Specialities</label><div class="tf-in"><input id="specialties" name="specialties" maxlength="120" value="<?= e($f['specialties']) ?>" placeholder="Over/Under, BTTS, Asian handicap"></div><small>Separate with commas. They appear as tags on your page.</small></div>
          <div class="tf"><label for="bio">About you and your method</label><div class="tf-in"><textarea id="bio" name="bio" rows="6" maxlength="1500" required placeholder="Tell members how you pick your tips, your experience and your results."><?= e($f['bio']) ?></textarea><i class="tf-n" data-for="bio" data-max="1500"></i></div><small>30 to 1500 characters.</small></div>
        </div>
        <aside class="tpp-prev-card" aria-label="Preview">
          <div class="tpp-pv-h">Live preview</div>
          <div class="tpp-pv">
            <div class="tpp-pv-av"><?= tipster_avatar($t, 64) ?></div>
            <div class="tpp-pv-n"><strong id="pv-name"><?= e($f['name']) ?></strong> <span id="pv-flag"><?= tipster_flag($t, 14) ?></span></div>
            <div class="tpp-pv-c" id="pv-country"><?= e($f['country']) ?></div>
            <p class="tpp-pv-t" id="pv-tag"><?= e($f['tagline']) ?></p>
            <div class="tpp-pv-tags" id="pv-tags"></div>
          </div>
        </aside>
      </div>
    </div>

    <div class="tpp-card">
      <div class="tpp-ch"><span class="tpp-ic">&#129309;</span><div><h2>Account management</h2><p class="tpp-sub">Let clients ask you to manage a betting account for them.</p></div></div>
      <?php if (!$mcols): ?><div class="note" style="margin:0">This option appears after the site owner imports <code>database-update-15.sql</code>.</div><?php else: ?>
      <label class="tpp-switch"><input type="checkbox" name="accepts_managed" value="1"<?= $f['accepts'] ? ' checked' : '' ?>><span class="tpp-sw"></span><span class="tpp-swt"><strong>I take account-management requests from clients</strong><small>Clients can pick you when they send a request, and you talk to them in your Account management tab. You agree any fees with the client yourself.</small></span></label>
      <div class="tf"><label for="managed_note">Your terms, shown to clients (optional)</label><div class="tf-in"><textarea id="managed_note" name="managed_note" rows="3" maxlength="300" placeholder="Minimum amount, how you charge, which betting companies you work with"><?= e($f['mnote']) ?></textarea><i class="tf-n" data-for="managed_note" data-max="300"></i></div>
        <div class="tpp-warn">&#9888; Never ask clients for their betting password, PIN or codes. Many betting companies do not allow anyone but the account owner to use an account, so check their rules and your local law.</div></div>
      <?php endif; ?>
    </div>

    <div class="tpp-card">
      <div class="tpp-ch"><span class="tpp-ic">&#128179;</span><div><h2>Where we pay you</h2><p class="tpp-sub">Your earnings are sent here when you withdraw.</p></div></div>
      <div class="tpp-pm" role="radiogroup" aria-label="Payout method">
        <?php foreach (['mpesa' => ['M-Pesa', 'Mobile money (Kenya)', '&#128241;'], 'airtel' => ['Airtel Money', 'Mobile money', '&#128276;'], 'paypal' => ['PayPal', 'Email account', '&#127760;']] as $k => $pm): ?>
        <label class="tpp-pmo"><input type="radio" name="method" value="<?= $k ?>"<?= $f['method'] === $k ? ' checked' : '' ?>><span><i><?= $pm[2] ?></i><b><?= e($pm[0]) ?></b><small><?= e($pm[1]) ?></small></span></label>
        <?php endforeach; ?>
      </div>
      <div class="tf" style="margin-top:14px"><label for="details" id="det-l">M-Pesa number</label><div class="tf-in"><input id="details" name="details" maxlength="120" required value="<?= e($f['details']) ?>" placeholder="07XX XXX XXX"></div><small id="det-h">We send your withdrawals to this number. Double-check it.</small></div>
    </div>

    <div class="tpp-save"><span>Changes apply to your public page straight away.</span><button class="btn dark" type="submit">Save profile</button></div>
  </form>
</div></section>
<script>
(function(){var i=document.getElementById('avatar'),p=document.getElementById('avprev'),f=document.getElementById('avform'),b=document.getElementById('avbtn');
 if(!i)return;
 i.addEventListener('change',function(){var x=i.files&&i.files[0];if(!x)return;
  if(x.size>2*1024*1024){alert('That picture is over 2 MB. Please choose a smaller one.');i.value='';return;}
  try{p.src=URL.createObjectURL(x);p.hidden=false;}catch(e){}
  b.textContent='Uploading...';b.disabled=true;f.submit();});})();
</script>
<script>
(function(){
  function $(i){return document.getElementById(i);}
  function cnt(){document.querySelectorAll('.tf-n').forEach(function(n){var el=$(n.dataset.for);if(!el)return;var u=function(){n.textContent=el.value.length+'/'+n.dataset.max;};el.addEventListener('input',u);u();});}
  cnt();
  var nm=$('name'),tg=$('tagline'),sp=$('specialties'),co=$('country');
  function tags(){var box=$('pv-tags');if(!box||!sp)return;box.innerHTML='';sp.value.split(',').map(function(x){return x.trim();}).filter(Boolean).slice(0,6).forEach(function(x){var s=document.createElement('span');s.textContent=x;box.appendChild(s);});}
  function pv(){if($('pv-name'))$('pv-name').textContent=nm.value||'Your name';if($('pv-tag'))$('pv-tag').textContent=tg.value||'Your one-line summary';
    if(co){var o=co.options[co.selectedIndex];$('pv-country').textContent=o&&o.value?o.text:'';var iso=o&&o.dataset?o.dataset.iso:'';var fb=co.closest('.cpick');var fp=(fb?fb.getAttribute('data-flags'):'').split('|'),base=fp[0],ext=fp[1]||'svg';
      $('pv-flag').innerHTML=(iso&&base)?'<img class="fl" src="'+base+iso+'.'+ext+'" alt="" height="14" onerror="this.style.display=\'none\'">':$('pv-flag').innerHTML;}
    tags();}
  [nm,tg,sp].forEach(function(e){if(e)e.addEventListener('input',pv);});if(co)co.addEventListener('change',pv);pv();
  var M={mpesa:['M-Pesa number','07XX XXX XXX','We send your withdrawals to this number. Double-check it.','tel'],airtel:['Airtel Money number','2547XX XXX XXX','Enter your Airtel Money number with the country code, like 254733123456.','tel'],paypal:['PayPal email','name@example.com','Withdrawals are sent to this PayPal email address.','email']};
  function pm(){var r=document.querySelector('input[name=method]:checked');if(!r)return;var m=M[r.value];$('det-l').textContent=m[0];$('details').placeholder=m[1];$('det-h').textContent=m[2];$('details').type=m[3]==='email'?'email':'text';$('details').setAttribute('inputmode',m[3]==='email'?'email':'tel');}
  document.querySelectorAll('input[name=method]').forEach(function(r){r.addEventListener('change',pm);});pm();
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
