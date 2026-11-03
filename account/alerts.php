<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();
$canSms = sms_enabled() && !empty($user['mobile']) && !empty($user['mobile_verified']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (($_POST['action'] ?? '') === 'extra' && alerts2_ready()) {
        $hr = max(-1, min(23, (int)($_POST['digest_hour'] ?? 8)));
        $pdo->prepare('INSERT INTO alert_extra (user_id, digest_hour, weekly) VALUES (?,?,?) ON DUPLICATE KEY UPDATE digest_hour = VALUES(digest_hour), weekly = VALUES(weekly)')->execute([$uid, $hr, !empty($_POST['weekly']) ? 1 : 0]);
        if (digest_ready()) {
            $pdo->prepare('INSERT INTO digest_prefs (user_id, email_daily) VALUES (?,?) ON DUPLICATE KEY UPDATE email_daily = VALUES(email_daily)')->execute([$uid, $hr >= 0 ? 1 : 0]);
        }
        set_flash('success', 'Digest settings saved.');
    } elseif (($_POST['action'] ?? '') === 'mute' && alerts2_ready()) {
        $ref = trim(mb_substr((string)($_POST['ref'] ?? ''), 0, 80));
        $kind = ($_POST['kind'] ?? '') === 'tipster' ? 'tipster' : 'team';
        if ($ref !== '') { $pdo->prepare('INSERT IGNORE INTO alert_mutes (user_id, kind, ref) VALUES (?,?,?)')->execute([$uid, $kind, $ref]); set_flash('success', 'Muted: ' . $ref); }
    } elseif (($_POST['action'] ?? '') === 'unmute' && alerts2_ready()) {
        $pdo->prepare('DELETE FROM alert_mutes WHERE user_id = ? AND kind = ? AND ref = ?')->execute([$uid, (string)($_POST['kind'] ?? ''), (string)($_POST['ref'] ?? '')]);
        set_flash('success', 'Alerts back on.');
    } elseif (($_POST['action'] ?? '') === 'unfollow') {
        $pdo->prepare('DELETE FROM tipster_follows WHERE user_id = ? AND tipster_id = ?')->execute([$uid, (int)($_POST['tipster'] ?? 0)]);
        set_flash('success', 'Unfollowed.');
    } else {
        $f = function ($k) { return !empty($_POST[$k]) ? 1 : 0; };
        $pdo->prepare('INSERT INTO alert_prefs (user_id, push, email, sms, kickoff, results, vip) VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE push = VALUES(push), email = VALUES(email), sms = VALUES(sms), kickoff = VALUES(kickoff), results = VALUES(results), vip = VALUES(vip)')
            ->execute([$uid, $f('push'), $f('email'), $canSms ? $f('sms') : 0, $f('kickoff'), $f('results'), $f('vip')]);
        set_flash('success', 'Alert settings saved.');
    }
    redirect('account/alerts.php');
}
$p = alert_prefs($uid);
$st = $pdo->prepare('SELECT t.id, t.slug, t.display_name, t.avatar_url, t.rating_avg, t.rating_count FROM tipster_follows f JOIN tipsters t ON t.id = f.tipster_id WHERE f.user_id = ? ORDER BY f.created_at DESC');
$st->execute([$uid]);
$follows = $st->fetchAll();
$st = $pdo->prepare('SELECT COUNT(*) FROM push_subs WHERE user_id = ?');
$st->execute([$uid]);
$devices = (int)$st->fetchColumn();
$ax = alert_extra_get($uid);
$mutes = [];
if (alerts2_ready()) { $q = $pdo->prepare('SELECT kind, ref FROM alert_mutes WHERE user_id = ? ORDER BY kind, ref'); $q->execute([$uid]); $mutes = $q->fetchAll(); }
$mutedT = array_map('mb_strtolower', array_column(array_filter($mutes, function ($m) { return $m['kind'] === 'tipster'; }), 'ref'));

$page_title = 'Alerts - ' . SITE_NAME;
$active = '';
$acct = 'alerts';
$english_only = true;
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><h2>Alerts</h2></div>
  <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
  <div class="two">
  <form method="post" class="box2"><?= csrf_field() ?>
    <h3>How should we reach you?</h3>
    <p style="color:var(--mute)">You always see alerts in your account under Notifications. Pick extra ways below.</p>
    <label class="al-row"><input type="checkbox" name="push" value="1"<?= $p['push'] ? ' checked' : '' ?>> <span><b>Browser push</b><small>Pop-ups on this phone or computer, even when the site is closed. Turn on each device below.</small></span></label>
    <label class="al-row"><input type="checkbox" name="email" value="1"<?= $p['email'] ? ' checked' : '' ?>> <span><b>Email</b><small>Sent to <?= e($user['email']) ?>.</small></span></label>
    <label class="al-row"><input type="checkbox" name="sms" value="1"<?= $p['sms'] && $canSms ? ' checked' : '' ?><?= $canSms ? '' : ' disabled' ?>> <span><b>SMS</b><small><?= $canSms ? 'Sent to your confirmed mobile (maximum 3 a day).' : (sms_enabled() ? 'Confirm your mobile number in Profile to use SMS.' : 'SMS is not available on this site.') ?></small></span></label>
    <h3 style="margin-top:18px">What should we tell you?</h3>
    <label class="al-row"><input type="checkbox" name="kickoff" value="1"<?= $p['kickoff'] ? ' checked' : '' ?>> <span><b>Kickoff reminders</b><small>About an hour before a match you picked in the game, tracked, or that a tipster you follow has a tip on.</small></span></label>
    <label class="al-row"><input type="checkbox" name="results" value="1"<?= $p['results'] ? ' checked' : '' ?>> <span><b>Results</b><small>When a tip you tracked, or a tip from a tipster you follow, wins or loses.</small></span></label>
    <label class="al-row"><input type="checkbox" name="vip" value="1"<?= $p['vip'] ? ' checked' : '' ?>> <span><b>VIP ending</b><small>One reminder when your VIP plan ends within 24 hours.</small></span></label>
    <p style="color:var(--mute);font-size:14px">New tips from tipsters you follow are always sent (use Unfollow to stop).</p>
    <button class="btn gold" type="submit">Save settings</button>
  </form>
  <div>
    <div class="box2" id="push-box" data-key="<?= e(webpush_ready() ? webpush_public_key() : '') ?>" data-url="<?= e(url('api/push-subscribe.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
      <h3>This device</h3>
      <p style="color:var(--mute)"><?= $devices ?> device<?= $devices === 1 ? '' : 's' ?> turned on for your account.</p>
      <?php if (!webpush_ready()): ?><p class="note" style="margin-top:0">Browser push is not set up on this site yet. Email and in-account alerts still work.</p><?php endif; ?>
      <button type="button" id="push-toggle" class="btn dark"<?= webpush_ready() ? '' : ' disabled' ?>>Turn on alerts on this device</button>
      <p id="push-msg" class="push-msg" role="status"></p>
    </div>
    <form method="post" class="box2" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="extra">
      <h3>Digest</h3>
      <div class="field"><label for="dh">Daily email digest</label>
        <select id="dh" name="digest_hour"><option value="-1"<?= (int)$ax['digest_hour'] < 0 ? ' selected' : '' ?>>Off</option>
        <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>"<?= (int)$ax['digest_hour'] === $h ? ' selected' : '' ?>>Every day at <?= sprintf('%02d:00', $h) ?></option><?php endfor; ?></select></div>
      <label class="al-row"><input type="checkbox" name="weekly" value="1"<?= !empty($ax['weekly']) ? ' checked' : '' ?>> <span><b>Weekly summary email</b><small>Every Monday: last week's results and what is coming.</small></span></label>
      <button class="btn dark" type="submit">Save digest</button>
    </form>
    <div class="box2" style="margin-top:16px">
      <h3>Mute teams</h3>
      <p style="color:var(--mute);font-size:14px">No alerts that mention a team you mute.</p>
      <form method="post" style="display:flex;gap:8px"><?= csrf_field() ?><input type="hidden" name="action" value="mute"><input type="hidden" name="kind" value="team"><input type="text" name="ref" maxlength="80" placeholder="Team name, e.g. Arsenal" style="flex:1"><button class="btn dark small" type="submit">Mute</button></form>
      <?php foreach ($mutes as $m): ?><form method="post" class="mute-chip"><?= csrf_field() ?><input type="hidden" name="action" value="unmute"><input type="hidden" name="kind" value="<?= e($m['kind']) ?>"><input type="hidden" name="ref" value="<?= e($m['ref']) ?>"><?= e($m['ref']) ?> <button class="msg-del" type="submit" title="Turn alerts back on">&times;</button></form><?php endforeach; ?>
    </div>
    <div class="box2" style="margin-top:16px">
      <h3>Tipsters you follow</h3>
      <?php if (!$follows): ?><p style="color:var(--mute)">You do not follow anyone yet. Open a <a href="<?= e(url('tipsters.php')) ?>">tipster's page</a> and press Follow.</p><?php endif; ?>
      <?php foreach ($follows as $t): ?>
        <div class="al-follow"><a href="<?= e(tipster_url($t)) ?>"><?= tipster_avatar($t, 36) ?> <strong><?= e($t['display_name']) ?></strong></a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unfollow"><input type="hidden" name="tipster" value="<?= (int)$t['id'] ?>"><button class="btn tiny ghost" type="submit">Unfollow</button></form><?php $isM = in_array(mb_strtolower($t['display_name']), $mutedT, true); ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isM ? 'unmute' : 'mute' ?>"><input type="hidden" name="kind" value="tipster"><input type="hidden" name="ref" value="<?= e($t['display_name']) ?>"><button class="btn tiny ghost" type="submit"><?= $isM ? 'Alerts off - turn on' : 'Mute alerts' ?></button></form></div>
      <?php endforeach; ?>
    </div>
  </div></div>
</div></section>
<script src="<?= e(url('assets/js/push.js')) ?>"></script>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
