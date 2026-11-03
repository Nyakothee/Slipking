<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('security');
require_once dirname(__DIR__) . '/includes/totp.php';
$pdo   = db();
$me    = current_user();
$uid   = (int)$me['id'];
$errors = [];
$newCodes = null;                 // recovery codes to show ONCE, right after they are created

$dbReady = true;
try {
    $st = $pdo->prepare('SELECT password_hash, totp_secret, totp_enabled FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    $pdo->query('SELECT 1 FROM admin_recovery_codes LIMIT 1');
} catch (Throwable $ex) {
    $dbReady = false;
    $row = ['password_hash' => '', 'totp_secret' => null, 'totp_enabled' => 0];
}
$enabled = $dbReady && (int)$row['totp_enabled'] === 1 && !empty($row['totp_secret']);

if ($dbReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_str('action');
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'gate_on') {
        set_setting('admin_gate_key', bin2hex(random_bytes(16)));
        setcookie('sk_ag', admin_gate_token(admin_gate_key()), ['expires' => time() + 86400 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        admin_log('security.gate', 'admin', 'secret link turned on / renewed');
        set_flash('success', 'The secret admin link is on. Save the link below now.');
        redirect('admin/security.php');
    } elseif ($action === 'gate_off') {
        set_setting('admin_gate_key', '');
        admin_log('security.gate', 'admin', 'secret link turned off');
        set_flash('success', 'The secret admin link is off.');
        redirect('admin/security.php');
    } elseif (too_many_attempts(8)) {
        $errors[] = 'Too many attempts. Please wait 15 minutes.';
    } elseif ($action === 'start' && !$enabled) {
        $_SESSION['totp_pending'] = totp_new_secret();
        redirect('admin/security.php');
    } elseif ($action === 'cancel') {
        unset($_SESSION['totp_pending']);
        redirect('admin/security.php');
    } elseif ($action === 'confirm' && !$enabled) {
        $secret = (string)($_SESSION['totp_pending'] ?? '');
        if ($secret === '') {
            $errors[] = 'Start the setup again.';
        } elseif (!totp_verify($secret, (string)($_POST['code'] ?? ''))) {
            record_attempt('2fa-setup#' . $uid);
            $errors[] = 'That code is not correct. Check that your phone clock is automatic and try the newest code.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?')->execute([$secret, $uid]);
                $newCodes = recovery_codes_generate($uid);
                $pdo->commit();
                unset($_SESSION['totp_pending']);
                $enabled = true;
                admin_log('2fa.enable');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('2fa enable: ' . $ex->getMessage());
                $errors[] = 'Two-factor could not be saved. Please try again.';
            }
        }
    } elseif (($action === 'disable' || $action === 'regen') && $enabled) {
        $pwOk   = password_verify((string)($_POST['password'] ?? ''), (string)$row['password_hash']);
        $codeOk = $pwOk && second_factor_ok($uid, (string)$row['totp_secret'], (string)($_POST['code'] ?? ''));
        if (!$pwOk || !$codeOk) {
            record_attempt('2fa-manage#' . $uid);
            $errors[] = 'Password or code not correct.';
        } elseif ($action === 'disable') {
            $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM admin_recovery_codes WHERE user_id = ?')->execute([$uid]);
            admin_log('2fa.disable');
            set_flash('success', 'Two-factor login is now off.');
            redirect('admin/security.php');
        } else {
            try {
                $newCodes = recovery_codes_generate($uid);
                admin_log('2fa.recovery-regenerate');
            } catch (Throwable $ex) {
                error_log('2fa regen: ' . $ex->getMessage());
                $errors[] = 'Recovery codes could not be created. Please try again.';
            }
        }
    }
}

$pendingSecret = (!$enabled && !empty($_SESSION['totp_pending'])) ? (string)$_SESSION['totp_pending'] : '';

$admin_title = 'Security';
$admin_active = 'security';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Security: two-factor login</h1>
<p class="sub">Adds a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Aegis, 1Password...) after your password.</p>
<?php if (!$dbReady): ?><div class="errors" role="alert"><ul><li>The database is not updated yet. Run the SQL from the file A-sql.sql first.</li></ul></div><?php endif; ?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($newCodes): ?>
<div class="panel"><h2>Your recovery codes</h2>
  <p>Save these now, somewhere safe (password manager or printed). Each code works <strong>once</strong>, if you lose your phone. They are stored only as hashes and cannot be shown again.</p>
  <pre class="rcodes" style="font:700 18px/1.8 monospace;margin:12px 0"><?php foreach ($newCodes as $c) { echo e($c) . "\n"; } ?></pre>
</div>
<?php endif; ?>

<?php if ($dbReady && $enabled): ?>
<div class="panel"><h2>Status: ON</h2>
  <p>Admin login asks for a code. Unused recovery codes left: <strong><?= (int)recovery_codes_left($uid) ?></strong>.</p>
</div>
<div class="panel"><h2>New recovery codes</h2>
  <p>Creates 8 new codes and cancels all the old ones.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="regen">
    <div class="formgrid">
      <div class="field"><label for="rp">Your password</label><input type="password" id="rp" name="password" required autocomplete="current-password"></div>
      <div class="field"><label for="rc">Authenticator code</label><input type="text" id="rc" name="code" required inputmode="numeric" maxlength="12" autocomplete="one-time-code"></div>
    </div>
    <button class="btn dark" type="submit">Create new recovery codes</button>
  </form>
</div>
<div class="panel"><h2>Turn two-factor off</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disable">
    <div class="formgrid">
      <div class="field"><label for="dp">Your password</label><input type="password" id="dp" name="password" required autocomplete="current-password"></div>
      <div class="field"><label for="dc">Authenticator or recovery code</label><input type="text" id="dc" name="code" required maxlength="12" autocomplete="one-time-code"></div>
    </div>
    <button class="btn dark" type="submit">Turn off</button>
  </form>
</div>

<?php elseif ($dbReady && $pendingSecret !== ''): ?>
<div class="panel"><h2>Set up your authenticator app</h2>
  <ol style="margin:0 0 14px 20px">
    <li>In your app choose "Add account" then "Enter a setup key" (or paste the link below on a phone where the app is installed).</li>
    <li>Account: <strong><?= e($me['email']) ?></strong>, key type: time based.</li>
    <li>Setup key: <code style="font-size:18px;letter-spacing:1px" id="tsecret"><?= e(totp_secret_grouped($pendingSecret)) ?></code></li>
    <li>Or the full link: <code style="word-break:break-all"><?= e(totp_uri($pendingSecret, $me['email'], SITE_NAME)) ?></code></li>
    <li>Type the 6-digit code the app shows to confirm.</li>
  </ol>
  <p class="hint">No QR code is shown on purpose: making one would need a third-party website, which would see your secret.</p>
  <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="confirm">
    <div class="field"><label for="cc">6-digit code</label><input type="text" id="cc" name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" style="max-width:200px"></div>
    <button class="btn gold" type="submit">Confirm and turn on</button>
  </form>
  <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn ghost" type="submit" style="color:inherit;border-color:#999">Cancel</button></form>
</div>

<?php elseif ($dbReady): ?>
<div class="panel"><h2>Status: OFF</h2>
  <p>Anyone with your password can open this panel. Turn on two-factor to protect it.</p>
  <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="start"><button class="btn gold" type="submit">Set up two-factor</button></form>
</div>
<?php endif; ?>
<div class="panel"><h2>Secret admin link</h2>
<?php $gl = admin_gate_link(); if ($gl !== ''): ?>
  <p>Status: <span class="badge won">ON</span> &mdash; the admin panel answers &ldquo;page not found&rdquo; to anyone who has not opened this private link first. It remembers your browser for 30 days.</p>
  <div class="field"><label>Your private link (keep it secret, bookmark it)</label><input type="text" readonly value="<?= e($gl) ?>" onclick="this.select()"></div>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="gate_on"><button class="btn outline small" type="submit" onclick="return confirm('Create a new link? The old link stops working and other browsers must use the new one.')">Make a new link</button></form>
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="gate_off"><button class="btn loss small" type="submit">Turn off</button></form>
  <p class="hint">Locked out? Create an empty file called <code>admin-gate-off.txt</code> inside the <code>includes</code> folder on the server. The gate switches off until you delete that file.</p>
<?php else: ?>
  <p>Status: <span class="badge pending">OFF</span> &mdash; when you turn this on, the admin panel can only be reached through a private link that only you know. Everyone else sees an ordinary &ldquo;page not found&rdquo;.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="gate_on"><button class="btn gold" type="submit">Turn on secret link</button></form>
<?php endif; ?>
</div>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
