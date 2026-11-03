<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_login();
$user = current_user();
$pdo  = db();

$st = $pdo->prepare('SELECT referral_code FROM users WHERE id = ?');
$st->execute([$user['id']]);
$code = $st->fetchColumn();
if (!$code) {                                   // older accounts without a code get one now
    $code = strtoupper(bin2hex(random_bytes(4)));
    $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ? AND referral_code IS NULL')->execute([$code, $user['id']]);
}

$r = referral_stats((int)$user['id']);
$st = $pdo->prepare("SELECT u.name, u.created_at, EXISTS(SELECT 1 FROM payments py WHERE py.user_id = u.id AND py.status = 'paid') AS paid
                     FROM users u WHERE u.referred_by = ? ORDER BY u.id DESC LIMIT 50");
$st->execute([$user['id']]);
$friends = $st->fetchAll();
$link       = url('auth/register.php?ref=' . $code);
$page_title = 'Invite friends - ' . SITE_NAME;
$active = '';
$acct   = 'referrals';
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><h2>Invite friends</h2></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
    <div class="two">
    <div class="box2">
      <h3>Your link</h3>
      <p style="margin-bottom:10px">Share this link. Anyone who signs up through it is counted below.</p>
      <?php $rw = (int)setting('referral_reward_days', '3'); if ($rw > 0): ?><p style="margin-bottom:10px"><strong>Reward:</strong> <span><?= $rw ?> free VIP days for you when a friend makes their first payment.</span></p><?php endif; ?>
      <div class="linkbox" id="reflink"><?= e($link) ?></div>
      <?php $msg = 'Free and VIP football tips with a public record. Join me on ' . SITE_NAME . ': ' . $link; ?>
      <div class="rf-share">
        <a class="btn dark" target="_blank" rel="noopener" href="https://wa.me/?text=<?= e(rawurlencode($msg)) ?>">WhatsApp</a>
        <a class="btn dark" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?= e(rawurlencode($link)) ?>&amp;text=<?= e(rawurlencode('Free and VIP football tips on ' . SITE_NAME)) ?>">Telegram</a>
        <a class="btn dark" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= e(rawurlencode($msg)) ?>">X</a>
        <button class="btn gold" type="button" id="refcopy">Copy link</button>
      </div>
    </div>
    <div class="box2">
      <h3>Your results</h3>
      <div class="gm-stats" style="margin:0 0 10px">
        <div><small>Friends joined</small><b><?= (int)$r['joined'] ?></b></div>
        <div><small>Became VIP</small><b><?= (int)$r['paid'] ?></b></div>
        <div><small>VIP days earned</small><b><?= (int)$r['days'] ?></b></div>
        <div><small>Your rank</small><b><?= $r['rank'] ? '#' . (int)$r['rank'] : '-' ?></b></div>
      </div>
      <p style="color:var(--mute);font-size:14px">Rank is by friends who paid. See the <a href="<?= e(url('referral-leaderboard.php')) ?>">top referrers</a>. Inviting yourself or fake accounts is not allowed and rewards can be removed.</p>
    </div>
  </div>
  <div class="box2" style="margin-top:16px"><h3>Friends you invited</h3>
    <?php if (!$friends): ?><p style="color:var(--mute)">Nobody yet. Share your link to get started.</p><?php else: ?>
    <div class="scroll"><table class="bm-table"><thead><tr><th>Friend</th><th>Joined</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($friends as $f): ?><tr><td><?= e(mask_name((string)$f['name'])) ?></td><td><?= e(date('j M Y', strtotime($f['created_at']))) ?></td><td><?= $f['paid'] ? '<strong style="color:var(--win)">Paid - reward earned</strong>' : 'Joined' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <script>(function(){var b=document.getElementById('refcopy'),l=document.getElementById('reflink');if(!b)return;b.addEventListener('click',function(){var t=l.textContent;function ok(){b.textContent='Copied';setTimeout(function(){b.textContent='Copy link';},1800);}if(navigator.clipboard){navigator.clipboard.writeText(t).then(ok,function(){});}else{var r=document.createRange();r.selectNodeContents(l);var s=window.getSelection();s.removeAllRanges();s.addRange(r);try{document.execCommand('copy');ok();}catch(e){}}});})();</script>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
