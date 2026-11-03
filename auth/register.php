<?php
require_once dirname(__DIR__) . '/includes/init.php';
if (is_logged_in()) {
    redirect('account/dashboard.php');
}

// Two entry pages share this code: auth/register.php (members who want tips) and auth/register-tipster.php (people who want to sell tips).
$regType = (isset($REG_TYPE) && $REG_TYPE === 'tipster') ? 'tipster' : 'member';
$isTip   = $regType === 'tipster';
$tipOpen = setting('tipster_signups_open', '1') === '1';
$self    = $isTip ? 'auth/register-tipster.php' : 'auth/register.php';
$errors = [];
$old    = ['name' => '', 'email' => '', 'country' => country_default(), 'mobile' => '',
         'tname' => '', 'tagline' => '', 'bio' => '', 'specialties' => '', 'method' => 'mpesa', 'details' => ''];
$next   = safe_next($_REQUEST['next'] ?? '');
$ref    = preg_replace('/[^A-Za-z0-9]/', '', (string)($_REQUEST['ref'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['name']  = post_str('name');
    $old['email'] = strtolower(post_str('email'));
    $old['country'] = strtoupper(post_str('country'));
    $old['mobile']  = post_str('mobile');
    if ($isTip) {
        foreach (['tname', 'tagline', 'specialties', 'details'] as $k) { $old[$k] = post_str($k); }
        $old['bio'] = trim((string)($_POST['bio'] ?? ''));
        $old['method'] = payout_method_post();
    }
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!empty($_POST['website'])) {
        redirect('');                       // hidden field filled in: a bot
    } elseif (too_many_attempts(10)) {
        $errors[] = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        record_attempt($old['email']);
        $phone = null;
        $mobile = '';
        $country = isset(countries()[$old['country']]) ? $old['country'] : '';
        if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 100) $errors[] = 'Enter your name (2 to 100 characters).';
        if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || strlen($old['email']) > 150) $errors[] = 'Enter a valid email address.';
        $pp = phone_parse($country, $old['mobile']);
        if (!$pp['ok']) {
            $errors[] = $pp['error'];
        } else {
            $mobile = $pp['mobile'];
            if ($country === 'KE') { $phone = $mobile; }          // Kenyan numbers also pre-fill M-Pesa payments
        }
        $avatar = null;
        if ($isTip) {
            if (!$tipOpen) $errors[] = 'Tipster applications are closed right now.';
            if (mb_strlen($old['tname']) < 3 || mb_strlen($old['tname']) > 40) $errors[] = 'Your tipster name must be 3 to 40 characters.';
            if (mb_strlen($old['tagline']) > 120) $errors[] = 'The one-line summary is too long (120 characters).';
            if (mb_strlen($old['bio']) < 30 || mb_strlen($old['bio']) > 1500) $errors[] = 'Tell members about yourself and your method (30 to 1500 characters).';
            if (mb_strlen($old['specialties']) > 120) $errors[] = 'Specialities are too long.';
            if ($m = tipster_payout_error($old['method'], $old['details'])) $errors[] = $m;
            $avatar = tipster_avatar_pending($_FILES['avatar'] ?? null, $avErr);
            if ($avErr !== '') $errors[] = $avErr;
            elseif (!$avatar) $errors[] = 'A profile photo is required to register as a tipster. Please choose a clear picture of yourself or your brand.';
        }
        if (strlen($pw) < 8) $errors[] = 'Your password must be at least 8 characters.';
        if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';
        if (empty($_POST['age'])) $errors[] = 'You must confirm that you are 18 or older.';
        if (empty($_POST['terms'])) $errors[] = 'Please accept the terms.';

        if (!$errors) {
            $st = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
            $st->execute([$old['email']]);
            if ($st->fetchColumn()) {
                $errors[] = 'An account with this email already exists. Try logging in.';
            }
        }

        if (!$errors && sms_enabled()) {
            $st = db()->prepare('SELECT 1 FROM users WHERE mobile = ? AND mobile_verified = 1 LIMIT 1');
            $st->execute([$mobile]);
            if ($st->fetchColumn()) $errors[] = 'This mobile number is already confirmed on another account. Try logging in.';
        }

        if (!$errors) {
            $referrerId = null;
            if ($ref !== '') {
                $st = db()->prepare('SELECT id FROM users WHERE referral_code = ? LIMIT 1');
                $st->execute([strtoupper($ref)]);
                $referrerId = $st->fetchColumn() ?: null;
            }
            $userId = 0;
            for ($try = 0; $try < 3 && !$userId; $try++) {
                try {
                    $st = db()->prepare('INSERT INTO users (name, email, phone, country, mobile, password_hash, referral_code, referred_by, is_tipster_account) VALUES (?,?,?,?,?,?,?,?,?)');
                    $st->execute([$old['name'], $old['email'], $phone, $country, $mobile, password_hash($pw, PASSWORD_DEFAULT),
                                  strtoupper(bin2hex(random_bytes(4))), $referrerId, $isTip ? 1 : 0]);
                    $userId = (int)db()->lastInsertId();
                } catch (PDOException $ex) {
                    if ($ex->getCode() !== '23000') throw $ex;      // 23000 = duplicate key: retry with a new code
                    $st = db()->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
                    $st->execute([$old['email']]);
                    if ($st->fetchColumn()) { $errors[] = 'An account with this email already exists. Try logging in.'; break; }
                }
            }
            if ($userId && $isTip) {
                try {
                    $details = tipster_payout_valid($old['method'], $old['details']) ?: $old['details'];
                    db()->prepare("INSERT INTO tipsters (user_id, slug, display_name, tagline, bio, country, specialties, avatar_url, payout_method, payout_details, status)
                                   VALUES (?,?,?,?,?,?,?,?,?,?, 'pending')")
                        ->execute([$userId, tipster_slug_unique($old['tname']), $old['tname'], $old['tagline'], $old['bio'],
                                   $country !== '' ? countries()[$country]['name'] : '', $old['specialties'], $avatar, $old['method'], $details]);
                    tipster_avatar_pending_clear();
                    notify_user($userId, 'Tipster application received', 'We will review your profile and email you when it is approved.');
                } catch (Throwable $ex) {
                    error_log('register tipster: ' . $ex->getMessage());
                    tipster_delete_avatar($avatar);
                    $isTip = false;       // the account exists; they can apply again from the tipster page
                }
            }
            if ($userId) {
                if ($referrerId) {
                    db()->prepare('INSERT INTO referrals (referrer_id, referred_id) VALUES (?, ?)')->execute([$referrerId, $userId]);
                }
                limits_confirm_age($userId);
                send_verification_email($userId, $old['email'], $old['name']);
                clear_attempts();
                log_in_user($userId);
                $goto = $isTip ? (tipster_fee_on() ? 'payments/checkout.php?plan=' . TIPSTER_FEE_SLUG : 'tipster/index.php') : ($next !== '' ? $next : 'account/dashboard.php');
                $okMsg = $isTip ? 'Welcome, ' . $old['name'] . '! Your tipster application was sent.' . (tipster_fee_on() ? ' The last step is the one-time joining fee (' . tipster_fee_amount(['country' => $country])['label'] . '), then we review your application.' : ' We will review it and let you know.') : 'Welcome, ' . $old['name'] . '! Your account is ready.';
                if (sms_enabled()) {
                    $sms = phone_code_send($userId, $mobile);       // a failure here is fine: the next page offers "Send a new code"
                    set_flash('success', $okMsg . ' Now confirm your mobile number. We also sent a link to confirm your email.');
                    redirect('auth/verify-phone.php?sent=' . ($sms['ok'] ? '1' : '0') . '&next=' . urlencode($goto));
                }
                set_flash('success', $okMsg . ' We sent a link to confirm your email.');
                redirect($goto);
            } elseif (!$errors) {
                $errors[] = 'We could not create your account. Please try again.';
            }
        }
    }
}

$page_title = ($isTip ? 'Register as a tipster - ' : 'Create account - ') . SITE_NAME;
$active = '';
include dirname(__DIR__) . '/includes/header.php';
?>
<section class="auth-split">
  <div class="auth-promo" aria-hidden="false">
    <div class="auth-promo-in">
      <span class="auth-kicker">Matchday ready</span>
      <h1>Your winning edge<br>starts here.</h1>
      <ul>
        <li>50+ leagues covered</li>
        <li>Verified results and track record</li>
        <li>Pay with M-Pesa, Airtel Money, MTN MoMo or card</li>
      </ul>
    </div>
  </div>
  <div class="auth-card-col">
    <div class="fcard">
      <h2><?= $isTip ? 'Register as a tipster' : 'Create account' ?></h2>
      <p class="sub"><?= $isTip ? 'Sell your tips, build a following and get paid. An admin approves every tipster.' : 'Free to join. VIP is optional.' ?></p>
      <div class="tabs" role="tablist" aria-label="Account type" style="margin-bottom:14px">
        <a href="<?= e(url('auth/register.php' . ($next !== '' ? '?next=' . urlencode($next) : ''))) ?>"<?= !$isTip ? ' class="on" aria-current="page"' : '' ?>>I want to get tips</a>
        <a href="<?= e(url('auth/register-tipster.php')) ?>"<?= $isTip ? ' class="on" aria-current="page"' : '' ?>>I am a tipster</a>
      </div>
      <?php if (access_on()): ?><div class="note" style="margin:12px 0;border-left:4px solid #f0a91c"><b>Weekly access:</b> KES <?= (int)access_kes() ?> / week (Kenya, Uganda, Tanzania) or USD <?= e(number_format(access_usd(), 2)) ?> / week (other countries). <?= 'Your first '.access_free_days().' days are free, then the weekly fee starts. ' ?>All VIP tips are free for everyone while this is on.<br><a class="btn gold" style="margin-top:10px" href="<?= e(url('access.php')) ?>">Pay weekly access (KES <?= (int)access_kes() ?> / USD <?= e(number_format(access_usd(), 2)) ?>)</a></div><?php endif; ?>
      <?php if ($isTip && !$tipOpen): ?><div class="note">Tipster applications are closed right now. You can still <a href="<?= e(url('auth/register.php')) ?>" style="text-decoration:underline">register to get tips</a>.</div><?php endif; ?>
      <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" action="<?= e(url($self)) ?>" autocomplete="on"<?= $isTip ? ' enctype="multipart/form-data"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <input type="hidden" name="ref" value="<?= e($ref) ?>">
        <div class="hp" aria-hidden="true"><label>Leave empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="field"><label for="name">Full name</label><input type="text" id="name" name="name" value="<?= e($old['name']) ?>" required maxlength="100" autocomplete="name"></div>
        <div class="field"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= e($old['email']) ?>" required maxlength="150" autocomplete="email"></div>
        <div class="field"><label for="country">Country</label><?= country_picker('country', 'country', $old['country'], ['required' => true, 'autocomplete' => 'country']) ?></div>
        <div class="field"><label for="mobile">Mobile number</label>
          <div style="display:flex;gap:8px;align-items:stretch"><span id="dial" class="dialbox" aria-hidden="true" style="display:inline-flex;align-items:center;padding:0 12px;border:1px solid var(--line);border-radius:6px;background:var(--paper);font-weight:700;min-width:64px;justify-content:center">+</span>
          <input type="tel" id="mobile" name="mobile" value="<?= e($old['mobile']) ?>" required maxlength="20" inputmode="tel" autocomplete="tel-national" placeholder="712 345 678" style="flex:1"></div>
          <div class="hint"><?= sms_enabled() ? 'We will send you an SMS code to confirm this number. Type it without the country code.' : 'Type it without the country code.' ?></div></div>
        <?php if ($isTip): ?>
        <?php if (tipster_fee_on()): ?><div class="note" id="tipfee" style="margin:10px 0">One-time joining fee: <strong id="tipfee-amt"><?= e(tipster_fee_for_country($old['country'])['label']) ?></strong> (KES <?= (int)tipster_fee_kes() ?> if you register from Kenya, USD <?= e(number_format(tipster_fee_usd(), 2)) ?> from any other country). You pay it right after registering. Until it is paid you cannot post tips or be listed to members.</div><?php endif; ?>
        <h3 style="margin:14px 0 6px">Your tipster profile</h3>
        <div class="field"><label for="tname">Tipster name (public)</label><input type="text" id="tname" name="tname" value="<?= e($old['tname']) ?>" required maxlength="40"></div>
        <div class="field"><label for="tagline">One-line summary</label><input type="text" id="tagline" name="tagline" value="<?= e($old['tagline']) ?>" maxlength="120" placeholder="e.g. Premier League goals specialist"></div>
        <div class="field"><label for="bio">About you and your method</label><textarea id="bio" name="bio" rows="4" maxlength="1500" required><?= e($old['bio']) ?></textarea></div>
        <div class="field"><label for="specialties">Specialities</label><input type="text" id="specialties" name="specialties" value="<?= e($old['specialties']) ?>" maxlength="120" placeholder="e.g. Football, Over/Under"></div>
        <div class="field"><label for="avatar">Profile photo (required)</label><?= tipster_avatar_pending_html() ?><input type="file" id="avatar" name="avatar"<?= tipster_avatar_pending_path() === '' ? ' required' : '' ?> accept="image/jpeg,image/png,image/webp"><div class="hint">JPG, PNG or WebP, up to 2 MB.</div></div>
        <div class="field"><label for="method">How should we pay you?</label><select id="method" name="method"><?= payout_method_options($old['method']) ?></select></div>
        <div class="field"><label for="details">M-Pesa number, Airtel Money number or PayPal email</label><input type="text" id="details" name="details" value="<?= e($old['details']) ?>" required maxlength="120"><div class="hint">Only a phone number (Airtel: with the country code, like 254733123456) or a PayPal email. Never share card or bank details.</div></div>
        <h3 style="margin:14px 0 6px">Your login</h3>
        <?php endif; ?>
        <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"><div class="hint">At least 8 characters.</div></div>
        <div class="field"><label for="password2">Repeat password</label><input type="password" id="password2" name="password2" required minlength="8" autocomplete="new-password"></div>
        <div class="field check"><label><input type="checkbox" name="age" value="1"> I am 18 years or older.</label></div>
        <div class="field check"><label><input type="checkbox" name="terms" value="1"> <span>I accept the</span> <a href="<?= e(url('terms.php')) ?>" style="text-decoration:underline">terms</a> <span>and understand that tips are not guaranteed.</span></label></div>
        <button class="btn gold full" type="submit"><?= $isTip ? 'Send my tipster application' : 'Create account' ?></button>
      </form>
      <div class="altlink"><a href="<?= e(url('index.php')) ?>">&larr; Back to SlipKing</a></div>
      <div class="altlink">Already have an account? <a href="<?= e(url('auth/login.php' . ($next !== '' ? '?next=' . urlencode($next) : ''))) ?>">Log in</a></div>
    </div>
  </div>
</section>
<script>
(function(){document.querySelectorAll('.auth-split input[type=password]').forEach(function(i){var b=document.createElement("button");b.type="button";b.className="pw-eye";b.textContent="Show";b.setAttribute("aria-label","Show or hide password");b.addEventListener("click",function(){var s=i.type==="password";i.type=s?"text":"password";b.textContent=s?"Hide":"Show";});i.parentNode.style.position="relative";i.parentNode.appendChild(b);});})();
</script>
<script>
(function(){
  var D = <?= json_encode(array_map(function ($c) { return $c['dial']; }, countries())) ?>;
  var sel = document.getElementById('country'), box = document.getElementById('dial');
  function show(){ var d = D[sel.value] || ''; if (d.length === 4 && d.charAt(0) === '1') { d = '1 ' + d.substr(1); } box.textContent = '+' + d; }
  var FEE = <?= json_encode(['KE' => tipster_fee_for_country('KE')['label'], 'X' => tipster_fee_for_country('US')['label']]) ?>;
  var fb = document.getElementById('tipfee-amt');
  function fee(){ if (fb) { fb.textContent = sel.value === 'KE' ? FEE.KE : FEE.X; } }
  sel.addEventListener('change', function(){ show(); fee(); }); show(); fee();
})();
</script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
