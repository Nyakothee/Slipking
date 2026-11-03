<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$u = current_user();
$pdo = db();

$existing = tipster_for_user((int)$u['id']);
if ($existing) {
    redirect('tipster/index.php');
}
$open = setting('tipster_signups_open', '1') === '1';
$verified = !empty($u['email_verified']);
$errors = [];
$f = ['name' => '', 'tagline' => '', 'bio' => '', 'country' => '', 'specialties' => '', 'method' => 'mpesa', 'details' => '', 'agree' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name', 'tagline', 'country', 'specialties', 'details'] as $k) { $f[$k] = post_str($k); }
    $f['bio'] = trim((string)($_POST['bio'] ?? ''));
    $f['method'] = payout_method_post();
    $f['agree'] = !empty($_POST['agree']) ? 1 : 0;
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif (!$open) { $errors[] = 'Tipster applications are closed right now.'; }
    elseif (!$verified) { $errors[] = 'Please confirm your email address first.'; }
    else {
        if (mb_strlen($f['name']) < 3 || mb_strlen($f['name']) > 40) $errors[] = 'Your tipster name must be 3 to 40 characters.';
        if (mb_strlen($f['tagline']) > 120) $errors[] = 'The one-line summary is too long (120 characters).';
        if (mb_strlen($f['bio']) < 30 || mb_strlen($f['bio']) > 1500) $errors[] = 'Tell members about yourself and your method (30 to 1500 characters).';
        if (mb_strlen($f['country']) > 60 || mb_strlen($f['specialties']) > 120) $errors[] = 'Country or specialities are too long.';
        $avatar = tipster_avatar_pending($_FILES['avatar'] ?? null, $avErr);
        if ($avErr !== '') $errors[] = $avErr;
        elseif (!$avatar) $errors[] = 'A profile photo is required. Please choose a clear picture of yourself or your brand.';
        if ($m = tipster_payout_error($f['method'], $f['details'])) $errors[] = $m;
        if (!$f['agree']) $errors[] = 'Please accept the tipster terms.';
        if (!$errors) {
            $details = tipster_payout_valid($f['method'], $f['details']) ?: $f['details'];
            try {
                $slug = tipster_slug_unique($f['name']);
                $pdo->prepare("INSERT INTO tipsters (user_id, slug, display_name, tagline, bio, country, specialties, avatar_url, payout_method, payout_details, status)
                               VALUES (?,?,?,?,?,?,?,?,?,?, 'pending')")
                    ->execute([$u['id'], $slug, $f['name'], $f['tagline'], $f['bio'], $f['country'], $f['specialties'], $avatar, $f['method'], $details]);
                tipster_avatar_pending_clear();
                notify_user((int)$u['id'], 'Tipster application received', 'We will review your profile and email you when it is approved.');
                set_flash('success', 'Application sent. We will review it and let you know.');
                redirect(tipster_fee_on() ? 'payments/checkout.php?plan=' . TIPSTER_FEE_SLUG : 'tipster/index.php');
            } catch (PDOException $ex) {
                error_log('tipster apply: ' . $ex->getMessage());
                $errors[] = 'We could not save your application. You may have already applied.';
            }
        }
    }
}

$page_title = 'Become a tipster - ' . SITE_NAME;
$page_desc  = 'Apply to sell your betting tips on ' . SITE_NAME . ' and earn from subscriptions.';
$active     = 'tipsters';
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="wrap page-top" style="max-width:760px">
  <div class="crumbs"><a href="<?= e(url('tipsters.php')) ?>">Tipsters</a> / Apply</div>
  <h1>Become a tipster</h1>
  <p style="color:var(--mute)">Sell subscriptions to your tips. Members pay <?= e(SITE_NAME) ?>; we keep a commission (currently <strong><?= e(rtrim(rtrim(number_format(tipster_default_commission(), 2), '0'), '.')) ?>%</strong>, it can change for future sales) and the rest goes into your wallet on the site. After a <?= (int)setting('tipster_hold_days', '7') ?>-day holding period you can withdraw it, paid automatically to your M-Pesa, Airtel Money or PayPal, once every <?= payout_window_days() ?> days.</p>
  <?php if (!$open): ?><div class="note">Applications are closed right now.</div>
  <?php elseif (!$verified): ?><div class="note">Please confirm your email address before applying. Check your inbox for the confirmation link.</div>
  <?php else: ?>
  <?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" class="box2" enctype="multipart/form-data"><?= csrf_field() ?>
    <div class="field"><label for="name">Tipster name (public)</label><input id="name" name="name" maxlength="40" required value="<?= e($f['name']) ?>"></div>
    <div class="field"><label for="tagline">One-line summary</label><input id="tagline" name="tagline" maxlength="120" value="<?= e($f['tagline']) ?>" placeholder="e.g. Premier League goals specialist"></div>
    <div class="field"><label for="bio">About you and your method</label><textarea id="bio" name="bio" rows="5" maxlength="1500" required><?= e($f['bio']) ?></textarea></div>
    <div class="field"><label for="country">Country</label><?= country_picker('country', 'country', (string)$f['country'], ['by' => 'name', 'empty' => 'Choose your country']) ?></div>
    <div class="field"><label for="specialties">Specialities</label><input id="specialties" name="specialties" maxlength="120" value="<?= e($f['specialties']) ?>" placeholder="e.g. Football, Over/Under, BTTS"></div>
    <div class="field"><label for="avatar">Profile photo (required)</label><?= tipster_avatar_pending_html() ?><input id="avatar" name="avatar" type="file"<?= tipster_avatar_pending_path() === '' ? ' required' : '' ?> accept="image/jpeg,image/png,image/webp"><div class="hint">JPG, PNG or WebP, up to 2 MB. It is cropped to a square.</div></div>
    <div class="field"><label for="method">How should we pay you?</label>
      <select id="method" name="method"><?= payout_method_options($f['method']) ?></select></div>
    <div class="field"><label for="details">M-Pesa number, Airtel Money number or PayPal email</label><input id="details" name="details" maxlength="120" required value="<?= e($f['details']) ?>">
      <div class="hint">Only a phone number (Airtel: with the country code, like 254733123456) or a PayPal email. Never send us card numbers, bank details or passwords.</div></div>
    <div class="field"><label><input type="checkbox" name="agree" value="1"<?= $f['agree'] ? ' checked' : '' ?>> I am 18 or older. I will post honest tips before kickoff, never promise guaranteed wins, and accept that results are recorded publicly and that <?= e(SITE_NAME) ?> may suspend my profile and refund members for misleading behaviour.</label></div>
    <button class="btn gold" type="submit">Send application</button>
  </form>
  <?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
