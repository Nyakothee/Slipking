<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user   = current_user();
$errors = [];
$pwErrs = [];
$mobErrs = [];
$picErrs = [];
$avErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'profile') {
        $name  = post_str('name');
        $raw   = post_str('phone');
        $phone = null;
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors[] = 'Enter your name (2 to 100 characters).';
        if ($raw !== '') {
            $phone = normalize_phone($raw);
            if ($phone === null) $errors[] = 'Enter a valid Kenyan phone number, like 0712 345 678.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone, $user['id']]);
            set_flash('success', 'Your details have been saved.');
            redirect('account/profile.php');
        }
    } elseif (($_POST['action'] ?? '') === 'picture') {
        if (!profile_cols()) {
            $picErrs[] = 'Profile pictures are not switched on yet. The site owner needs to import database-update-14.sql.';
        } else {
            $iso = strtoupper(post_str('nationality'));
            if ($iso !== '' && !isset(countries()[$iso])) $picErrs[] = 'Choose your country from the list.';
            $newPic = avatar_save($_FILES['avatar'] ?? null, $avErr);
            if ($avErr !== '') $picErrs[] = $avErr;
            if (!$picErrs) {
                $old = (string)($user['avatar'] ?? '');
                $pic = $old;
                if ($newPic) { $pic = $newPic; }
                elseif (!empty($_POST['remove_avatar'])) { $pic = ''; }
                db()->prepare('UPDATE users SET avatar = ?, nationality = ?, show_flag = ? WHERE id = ?')
                    ->execute([$pic !== '' ? $pic : null, $iso !== '' ? $iso : null, !empty($_POST['show_flag']) ? 1 : 0, $user['id']]);
                if ($old !== '' && $old !== $pic) avatar_delete($old);
                set_flash('success', 'Your picture and flag have been saved.');
                redirect('account/profile.php');
            }
            if ($newPic) avatar_delete($newPic);
        }
    } elseif (($_POST['action'] ?? '') === 'mobile') {
        $country = strtoupper(post_str('country'));
        $pp = phone_parse($country, post_str('mobile'));
        if (!$pp['ok']) {
            $mobErrs[] = $pp['error'];
        } elseif ($pp['mobile'] === (string)($user['mobile'] ?? '') && !empty($user['mobile_verified'])) {
            set_flash('info', 'That number is already confirmed.');
            redirect('account/profile.php');
        } else {
            $st = db()->prepare('SELECT 1 FROM users WHERE mobile = ? AND mobile_verified = 1 AND id <> ? LIMIT 1');
            $st->execute([$pp['mobile'], $user['id']]);
            if ($st->fetchColumn() && sms_enabled()) {
                $mobErrs[] = 'This mobile number is already confirmed on another account.';
            } else {
                $kenya = $country === 'KE' ? $pp['mobile'] : null;
                // A changed number is unconfirmed until the new SMS code is typed.
                db()->prepare('UPDATE users SET country = ?, mobile = ?, mobile_verified = 0, mobile_verified_at = NULL, phone = COALESCE(?, phone) WHERE id = ?')
                    ->execute([$country, $pp['mobile'], $kenya, $user['id']]);
                if (sms_enabled()) {
                    $r = phone_code_send((int)$user['id'], $pp['mobile']);
                    redirect('auth/verify-phone.php?sent=' . ($r['ok'] ? '1' : '0'));
                }
                set_flash('success', 'Your mobile number has been saved.');
                redirect('account/profile.php');
            }
        }
    } elseif (($_POST['action'] ?? '') === 'emails') {
        db()->prepare('UPDATE users SET email_optout = ? WHERE id = ?')->execute([!empty($_POST['optout']) ? 1 : 0, $user['id']]);
        set_flash('success', 'Email choice saved.');
        redirect('account/profile.php');
    } elseif (($_POST['action'] ?? '') === 'password') {
        $cur = (string)($_POST['current'] ?? '');
        $pw  = (string)($_POST['password'] ?? '');
        $pw2 = (string)($_POST['password2'] ?? '');
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        if (too_many_attempts(8)) {
            $pwErrs[] = 'Too many attempts. Please wait 15 minutes.';
        } elseif (!password_verify($cur, (string)$st->fetchColumn())) {
            record_attempt($user['email']);
            $pwErrs[] = 'Your current password is not correct.';
        } elseif (strlen($pw) < 8) {
            $pwErrs[] = 'Your new password must be at least 8 characters.';
        } elseif ($pw !== $pw2) {
            $pwErrs[] = 'The two new passwords do not match.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            set_flash('success', 'Your password has been changed.');
            redirect('account/profile.php');
        }
    }
}

$st = db()->prepare('SELECT name, email, phone, country, mobile, mobile_verified' . (profile_cols() ? ', avatar, nationality, show_flag' : '') . ' FROM users WHERE id = ?');
$st->execute([$user['id']]);
$me = $st->fetch();
if ($errors) { $me['name'] = post_str('name'); $me['phone'] = post_str('phone'); }

$page_title = 'My profile - ' . SITE_NAME;
$active = '';
$acct   = 'profile';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><h2>Profile</h2></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
    <div class="box2 picbox" style="margin-bottom:22px;max-width:760px">
      <h3>Profile picture and flag</h3>
      <?php if ($picErrs): ?><div class="errors" role="alert"><ul><?php foreach ($picErrs as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if (!profile_cols()): ?><div class="note">Profile pictures will be available as soon as the site owner imports <code>database-update-14.sql</code>.</div><?php else: ?>
      <form method="post" action="<?= e(url('account/profile.php')) ?>" enctype="multipart/form-data" class="picform">
        <?= csrf_field() ?><input type="hidden" name="action" value="picture">
        <div class="picnow"><?= user_avatar_html($me + ['name' => $me['name']], 96) ?><div class="picflag"><?= flag_html(flag_iso($me), 20) ?></div></div>
        <div class="picfields">
          <div class="field"><label for="avatar">Choose a picture</label><input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"><div class="hint">JPG, PNG or WebP, up to 2 MB. It is cropped to a square. It is shown next to your name in comments and rankings.</div>
            <?php if (!empty($me['avatar'])): ?><label style="display:block;margin-top:6px"><input type="checkbox" name="remove_avatar" value="1"> Remove my picture</label><?php endif; ?></div>
          <div class="field"><label for="nationality">Nationality (shows your country's flag)</label>
            <?= country_picker('nationality', 'nationality', strtoupper((string)($me['nationality'] ?? '')), ['empty' => '- Not set -']) ?>
            <div class="hint">If you leave it empty, the flag of your mobile number's country is used.</div></div>
          <div class="field check"><label><input type="checkbox" name="show_flag" value="1"<?= (int)($me['show_flag'] ?? 1) === 1 ? ' checked' : '' ?>> Show my flag next to my name</label></div>
          <button class="btn gold" type="submit">Save picture and flag</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    <div class="two">
      <div class="box2">
        <h3>Your details</h3>
        <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" action="<?= e(url('account/profile.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="profile">
          <div class="field"><label for="name">Full name</label><input type="text" id="name" name="name" value="<?= e($me['name']) ?>" required maxlength="100"></div>
          <div class="field"><label for="email">Email</label><input type="email" id="email" value="<?= e($me['email']) ?>" disabled><div class="hint">To change your email, contact support.</div></div>
          <div class="field"><label for="phone">M-Pesa phone number</label><input type="tel" id="phone" name="phone" value="<?= e($me['phone']) ?>" placeholder="0712 345 678"></div>
          <button class="btn gold" type="submit">Save details</button>
        </form>
      </div>
      <div class="box2">
        <h3>Change password</h3>
        <?php if ($pwErrs): ?><div class="errors" role="alert"><ul><?php foreach ($pwErrs as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <form method="post" action="<?= e(url('account/profile.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <div class="field"><label for="current">Current password</label><input type="password" id="current" name="current" required autocomplete="current-password"></div>
          <div class="field"><label for="password">New password</label><input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"></div>
          <div class="field"><label for="password2">Repeat new password</label><input type="password" id="password2" name="password2" required minlength="8" autocomplete="new-password"></div>
          <button class="btn dark" type="submit">Change password</button>
        </form>
      </div>
    </div>
    <div class="box2" style="margin-top:22px;max-width:620px">
      <h3>Mobile number</h3>
      <?php $mc = $me['country'] && isset(countries()[$me['country']]) ? $me['country'] : country_default(); $mn = (string)$me['mobile']; if ($mn !== '' && isset(countries()[$mc]) && strpos($mn, countries()[$mc]['dial']) === 0) { $mn = substr($mn, strlen(countries()[$mc]['dial'])); } ?>
      <?php if ($me['mobile']): ?><p>+<?= e($me['mobile']) ?> &middot; <?php if ($me['mobile_verified']): ?><span class="badge won">Confirmed</span><?php else: ?><span class="badge pending">Not confirmed</span><?php if (sms_enabled()): ?> <a href="<?= e(url('auth/verify-phone.php')) ?>" style="text-decoration:underline">Confirm now</a><?php endif; endif; ?></p><?php endif; ?>
      <?php if ($mobErrs): ?><div class="errors" role="alert"><ul><?php foreach ($mobErrs as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <form method="post" action="<?= e(url('account/profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="mobile">
        <div class="field"><label for="country">Country</label><?= country_picker('country', 'country', $mc, ['required' => true]) ?></div>
        <div class="field"><label for="mobile">Mobile number (without the country code)</label><input type="tel" id="mobile" name="mobile" value="<?= e($mn) ?>" required maxlength="20" inputmode="tel" placeholder="712 345 678"></div>
        <button class="btn dark" type="submit"><?= sms_enabled() ? 'Save and send SMS code' : 'Save number' ?></button>
      </form>
    </div>
    <div class="box2" style="margin-top:22px;max-width:620px">
      <h3>Emails</h3>
      <form method="post" action="<?= e(url('account/profile.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="emails">
        <div class="field check"><label><input type="checkbox" name="optout" value="1"<?= !empty($user['email_optout']) ? ' checked' : '' ?>> Do not send me reminder or "we miss you" emails. (Account and payment emails are still sent.)</label></div>
        <button class="btn dark tiny" type="submit">Save</button></form>
    </div>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
