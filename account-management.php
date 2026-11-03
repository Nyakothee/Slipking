<?php
require_once __DIR__ . '/includes/init.php';

$user   = current_user();
$errors = [];
$curs   = currencies_active();
$codes  = array_column($curs, 'code');
$f = ['full_name' => $user['name'] ?? '', 'phone' => $user['phone'] ?? '', 'country' => '', 'bookmaker' => '',
      'budget' => '', 'budget_currency' => current_currency_code(), 'risk_level' => 'medium', 'message' => ''];

$handlers = managed_tipsters();
$hIds = array_map('intval', array_column($handlers, 'id'));
$f['tipster_id'] = '';
if (!empty($_GET['tipster'])) { foreach ($handlers as $h) { if ($h['slug'] === (string)$_GET['tipster']) { $f['tipster_id'] = (string)$h['id']; } } }
$mine = [];
$hasOpen = false;
$openByHandler = [];
if ($user) {
    $tcol = managed_cols() ? ', tipster_id, (SELECT display_name FROM tipsters tp WHERE tp.id = managed_requests.tipster_id) AS tipster_name' : '';
    $st = db()->prepare('SELECT id, bookmaker, status, created_at' . $tcol . ' FROM managed_requests WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$user['id']]);
    $mine = $st->fetchAll();
    foreach ($mine as $r) {
        if (in_array($r['status'], ['new', 'contacted', 'accepted'], true)) {
            $openByHandler[(int)($r['tipster_id'] ?? 0)] = true;
        }
    }
    $hasOpen = count($openByHandler) >= 3;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) {
        redirect('auth/login.php?next=account-management.php');
    }
    foreach (['full_name', 'phone', 'country', 'bookmaker', 'budget', 'budget_currency', 'risk_level'] as $k) { $f[$k] = post_str($k); }
    $f['message'] = trim((string)($_POST['message'] ?? ''));
    $f['tipster_id'] = (string)(int)($_POST['tipster_id'] ?? 0);
    if ($f['tipster_id'] === '0') { $f['tipster_id'] = ''; }

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($hasOpen) {
        $errors[] = 'You already have an enquiry we are working on. Please wait for our reply.';
    } else {
        $st = db()->prepare('SELECT COUNT(*) FROM managed_requests WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY');
        $st->execute([$user['id']]);
        if ((int)$st->fetchColumn() >= 3) $errors[] = 'Too many enquiries today. Please try again tomorrow.';

        if (mb_strlen($f['full_name']) < 2 || mb_strlen($f['full_name']) > 100) $errors[] = 'Enter your full name.';
        if (!preg_match('/^\+?[0-9 ()\-]{7,20}$/', $f['phone'])) $errors[] = 'Enter a phone or WhatsApp number we can reach you on, like +254 712 345 678.';
        if (mb_strlen($f['country']) < 2 || mb_strlen($f['country']) > 60) $errors[] = 'Enter your country.';
        if (mb_strlen($f['bookmaker']) < 2 || mb_strlen($f['bookmaker']) > 80) $errors[] = 'Enter the name of the betting company you use.';
        if (!is_numeric($f['budget']) || (float)$f['budget'] <= 0 || (float)$f['budget'] > 1000000000) $errors[] = 'Enter the amount you are thinking of using (a number above 0).';
        if (!in_array($f['budget_currency'], $codes, true)) $errors[] = 'Choose a currency.';
        if (!in_array($f['risk_level'], ['low', 'medium', 'high'], true)) $errors[] = 'Choose how much risk you are comfortable with.';
        if (mb_strlen($f['message']) > 1500) $errors[] = 'Your message is too long (1500 characters maximum).';
        if (looks_like_secret($f['message'] . ' ' . $f['bookmaker'])) $errors[] = 'For your safety, never write passwords, PINs or codes here. Remove them and send again.';
        if ($f['tipster_id'] !== '' && !in_array((int)$f['tipster_id'], $hIds, true)) $errors[] = 'That tipster is not taking account-management requests right now.';
        if (!empty($openByHandler[(int)$f['tipster_id']])) $errors[] = $f['tipster_id'] === '' ? 'You already have an open enquiry with our team.' : 'You already have an open enquiry with this tipster. Continue it in My account > Account management.';
        if ($f['tipster_id'] !== '' && empty($_POST['share'])) $errors[] = 'Please agree that your details are shared with the tipster you chose.';
        if (empty($_POST['age'])) $errors[] = 'You must confirm that you are 18 or older.';
        if (empty($_POST['risk'])) $errors[] = 'Please confirm that you understand the risks.';
        if (empty($_POST['nopass'])) $errors[] = 'Please confirm that you understand we never ask for your password on this site.';

        if (!$errors) {
            $tid = $f['tipster_id'] !== '' ? (int)$f['tipster_id'] : null;
            if (managed_cols()) {
                db()->prepare('INSERT INTO managed_requests (user_id, full_name, phone, country, bookmaker, budget, budget_currency, risk_level, message, tipster_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$user['id'], $f['full_name'], $f['phone'], $f['country'], $f['bookmaker'], number_format((float)$f['budget'], 2, '.', ''),
                               $f['budget_currency'], $f['risk_level'], $f['message'], $tid]);
            } else {
                db()->prepare('INSERT INTO managed_requests (user_id, full_name, phone, country, bookmaker, budget, budget_currency, risk_level, message) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$user['id'], $f['full_name'], $f['phone'], $f['country'], $f['bookmaker'], number_format((float)$f['budget'], 2, '.', ''),
                               $f['budget_currency'], $f['risk_level'], $f['message']]);
            }
            $newId = (int)db()->lastInsertId();
            if ($tid) {
                foreach ($handlers as $h) {
                    if ((int)$h['id'] === $tid) {
                        notify_user((int)$h['user_id'], 'New account-management request', 'A client sent you an account-management request. Open it in your tipster area to reply: ' . url('tipster/managed.php?id=' . $newId));
                    }
                }
                set_flash('success', 'Your request has been sent to the tipster. You can follow it and chat with them in My account > Account management.');
                redirect('account/managed.php?id=' . $newId);
            }
            send_mail(SITE_EMAIL, '[Account management] New enquiry from ' . $f['full_name'],
                "New account-management enquiry.\n\nName: {$f['full_name']}\nPhone: {$f['phone']}\nCountry: {$f['country']}\nBetting company: {$f['bookmaker']}\n" .
                "Budget: {$f['budget_currency']} {$f['budget']}\nRisk: {$f['risk_level']}\n\nOpen it in the admin panel: " . url('admin/managed.php'));
            set_flash('success', 'Thank you. We have your enquiry and will contact you on the number you gave. You can also follow it in My account > Account management.');
            redirect(managed_cols() ? 'account/managed.php?id=' . $newId : 'account-management.php');
        }
    }
}

$labels = ['new' => 'Received', 'contacted' => 'We have contacted you', 'accepted' => 'Accepted', 'declined' => 'Not accepted', 'closed' => 'Closed'];

$page_title = 'Account management enquiry - ' . SITE_NAME;
$page_desc  = 'Ask about our betting account management service. Read the risks first.';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Account management</h2><p>Tell us what you are looking for. We will talk to you directly before anything starts.</p></div></div>

    <div class="two">
      <div>
        <div class="box2" style="margin-bottom:18px">
          <h3>How it works</h3>
          <ul>
            <li>You send an enquiry here. Nothing is agreed and no money moves.</li>
            <li>We contact you on the phone or WhatsApp number you give, and talk through what you want.</li>
            <li>If we both agree, the terms (including fees) are written down and agreed before anything starts.</li>
            <li>You can ask to stop at any time.</li>
          </ul>
        </div>
        <div class="box2" style="margin-bottom:18px">
          <h3>What we never do</h3>
          <ul>
            <li>We never ask for your password, PIN or codes through this website, the chat or this form. If anyone asks you to send them here, do not.</li>
            <li>This website never holds your betting money.</li>
            <li>We never promise profit or guarantee results.</li>
          </ul>
        </div>
        <div class="risk">
          <strong>Risks you must understand.</strong>
          <span>Betting can lose all of the money used. Past results do not guarantee future results. Many betting companies do not allow anyone other than the account owner to operate an account, so check your company's rules, because your account could be limited or closed. Laws about betting and about managing other people's money differ between countries, so make sure you understand the rules where you live. Only use money you can afford to lose. 18+ only.</span>
          <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Responsible gambling</a>
        </div>
      </div>

      <div class="box2">
        <h3>Send an enquiry</h3>
        <?php if (!$user): ?>
          <p>Please log in or create a free account so we can reach you and keep your enquiry safe.</p>
          <p style="margin-top:14px"><a class="btn gold" href="<?= e(url('auth/login.php?next=account-management.php')) ?>">Log in</a>
            <a class="btn dark" href="<?= e(url('auth/register.php?next=account-management.php')) ?>">Create account</a></p>
        <?php elseif ($hasOpen): ?>
          <p>You have several open enquiries already. Please wait for replies in <a href="<?= e(url('account/managed.php')) ?>" style="text-decoration:underline">My account &gt; Account management</a>.</p>
        <?php else: ?>
          <?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
          <form method="post" action="<?= e(url('account-management.php')) ?>">
            <?= csrf_field() ?>
            <?php if ($handlers): ?>
            <div class="field"><label for="tipster_id">Who should handle it?</label>
              <select id="tipster_id" name="tipster_id"><option value="">The <?= e(SITE_NAME) ?> team</option>
                <?php foreach ($handlers as $h): ?><option value="<?= (int)$h['id'] ?>"<?= (string)$h['id'] === $f['tipster_id'] ? ' selected' : '' ?>><?= e($h['display_name']) ?> (tipster)</option><?php endforeach; ?></select>
              <div class="hint">Tipsters set their own terms. You will see them on their profile, and you chat with them here on the site.</div>
              <?php foreach ($handlers as $h): if ($h['managed_note']): ?><div class="hint tn" data-for="<?= (int)$h['id'] ?>" hidden><strong><?= e($h['display_name']) ?>:</strong> <?= e($h['managed_note']) ?></div><?php endif; endforeach; ?></div>
            <?php endif; ?>
            <div class="field"><label for="full_name">Full name</label><input type="text" id="full_name" name="full_name" value="<?= e($f['full_name']) ?>" maxlength="100" required></div>
            <div class="field"><label for="phone">Phone or WhatsApp number</label><input type="tel" id="phone" name="phone" value="<?= e($f['phone']) ?>" maxlength="20" required></div>
            <div class="field"><label for="country">Country</label><input type="text" id="country" name="country" value="<?= e($f['country']) ?>" maxlength="60" required></div>
            <div class="field"><label for="bookmaker">Betting company you use</label><input type="text" id="bookmaker" name="bookmaker" value="<?= e($f['bookmaker']) ?>" maxlength="80" required><div class="hint">Only the name. Never your login details.</div></div>
            <div class="field"><label for="budget">Amount you are thinking of using</label>
              <div style="display:flex;gap:8px"><input type="number" id="budget" name="budget" value="<?= e($f['budget']) ?>" min="1" step="any" required>
                <select id="budget_currency" name="budget_currency" style="width:110px;padding:11px 8px;border:1.5px solid var(--line);border-radius:6px"><?php foreach ($codes as $cd): ?><option value="<?= e($cd) ?>"<?= $f['budget_currency'] === $cd ? ' selected' : '' ?>><?= e($cd) ?></option><?php endforeach; ?></select></div></div>
            <div class="field"><label for="risk_level">Risk you are comfortable with</label>
              <select id="risk_level" name="risk_level"><option value="low"<?= $f['risk_level'] === 'low' ? ' selected' : '' ?>>Low</option><option value="medium"<?= $f['risk_level'] === 'medium' ? ' selected' : '' ?>>Medium</option><option value="high"<?= $f['risk_level'] === 'high' ? ' selected' : '' ?>>High</option></select></div>
            <div class="field"><label for="message">Anything else we should know (optional)</label><textarea id="message" name="message" maxlength="1500"><?= e($f['message']) ?></textarea></div>
            <?php if ($handlers): ?><div class="field check"><label><input type="checkbox" name="share" value="1"> If I chose a tipster, I agree my name, phone number and the details above are shared with that tipster.</label></div><?php endif; ?>
            <div class="field check"><label><input type="checkbox" name="age" value="1"> I am 18 years or older.</label></div>
            <div class="field check"><label><input type="checkbox" name="risk" value="1"> I understand I can lose all the money used and that no result is guaranteed.</label></div>
            <div class="field check"><label><input type="checkbox" name="nopass" value="1"> I understand that I must never send my password, PIN or codes on this site.</label></div>
            <button class="btn gold full" type="submit">Send enquiry</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($mine): ?>
      <div class="head" style="margin-top:34px"><h2 style="font-size:30px">Your enquiries</h2></div>
      <div class="scroll"><table class="tbl"><thead><tr><th>Date</th><th>Betting company</th><th>Handled by</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($mine as $r): ?>
          <tr><td><?= e(date('j M Y', strtotime($r['created_at']))) ?></td><td><?= e($r['bookmaker']) ?></td><td><?= e(($r['tipster_name'] ?? '') !== '' ? $r['tipster_name'] : SITE_NAME . ' team') ?></td><td><?= e($labels[$r['status']] ?? $r['status']) ?></td><td><?php if (managed_cols()): ?><a class="btn outline tiny" href="<?= e(url('account/managed.php?id=' . (int)$r['id'])) ?>">Open</a><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</section>
<script>
(function(){var s=document.getElementById('tipster_id');if(!s)return;function u(){var v=s.value;[].forEach.call(document.querySelectorAll('.tn'),function(n){n.hidden=n.getAttribute('data-for')!==v;});}s.addEventListener('change',u);u();})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
