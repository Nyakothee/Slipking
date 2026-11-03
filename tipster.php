<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/bookmakers.php';

$t = tipster_by_slug((string)($_GET['slug'] ?? ''));
$viewer = current_user();
$isOwner = $t && $viewer && (int)$t['user_id'] === (int)$viewer['id'];
if (!$t || ((!tipster_is_live($t)) && !$isOwner && !(function_exists('is_staff') && is_staff()))) {
    require __DIR__ . '/404.php';
    exit;
}
$pdo = db();
$tid = (int)$t['id'];
$canReview = $viewer ? tipster_can_review((int)$viewer['id'], $t) : false;

$myReview = null;
if ($viewer) {
    $st = $pdo->prepare('SELECT * FROM tipster_reviews WHERE tipster_id = ? AND user_id = ?');
    $st->execute([$tid, $viewer['id']]);
    $myReview = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    require_login();
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
    } elseif (!$canReview) {
        set_flash('error', 'Only members who bought one of this tipster\'s plans can rate them.');
    } else {
        $rating  = (int)($_POST['rating'] ?? 0);
        $comment = mb_substr(trim(strip_tags((string)($_POST['comment'] ?? ''))), 0, 600);
        if ($rating < 1 || $rating > 5) {
            set_flash('error', 'Choose 1 to 5 stars.');
        } else {
            $pdo->prepare("INSERT INTO tipster_reviews (tipster_id, user_id, rating, comment, status) VALUES (?, ?, ?, ?, 'visible')
                           ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), updated_at = NOW()")
                ->execute([$tid, $viewer['id'], $rating, $comment]);
            tipster_refresh_rating($tid);
            badge_award((int)$viewer['id'], 'first_review');
            set_flash('success', 'Thank you. Your rating is saved.');
        }
    }
    redirect('tipster.php?slug=' . rawurlencode($t['slug']) . '#reviews');
}
$t = tipster_by_id($tid);   // fresh rating numbers

$stats = tipster_stats($tid);
$plans = $pdo->prepare('SELECT * FROM plans WHERE tipster_id = ? AND is_active = 1 ORDER BY price_kes');
$plans->execute([$tid]);
$plans = $plans->fetchAll();
$tips = fetch_tips(['tipster' => $tid, 'order' => 'recent', 'limit' => 12]);
$rv = $pdo->prepare("SELECT rv.rating, rv.comment, rv.created_at, u.name" . profile_sql('u') . " FROM tipster_reviews rv JOIN users u ON u.id = rv.user_id WHERE rv.tipster_id = ? AND rv.status = 'visible' ORDER BY rv.id DESC LIMIT 30");
$rv->execute([$tid]);
$reviews = $rv->fetchAll();
$verified = tipster_verified($t, $stats);
$followers = tipster_follower_count($tid);
$isFollowing = $viewer ? tipster_is_followed((int)$viewer['id'], $tid) : false;
$trialDays = (int)($t['trial_days'] ?? 0);
$trialLive = $viewer ? tipster_trial_active((int)$viewer['id'], $tid) : null;
$trialWhy  = $trialDays > 0 ? tipster_trial_block_reason($viewer, $t) : 'none';
$months = tipster_monthly($tid, 12);
$hasAccess = $viewer ? (tipster_has_access((int)$viewer['id'], $tid) || $isOwner) : false;

$page_title = $t['display_name'] . ' - tipster profile - ' . SITE_NAME;
$page_desc  = $t['tagline'] ?: ($t['display_name'] . ' sells betting tips on ' . SITE_NAME . '. See their record and ratings.');
$active     = 'tipsters';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('tipsters.php')) ?>">Tipsters</a> / <?= e($t['display_name']) ?></div>
  <?php if ($t['status'] !== 'active'): ?><div class="note">This profile is not public yet (status: <?= e($t['status']) ?>). Only you and staff can see it.</div><?php endif; ?>
  <div class="tp-head tp-big"><?= tipster_avatar($t, 84) ?>
    <div><h1 style="margin:0"><?= e($t['display_name']) ?> <span class="tp-flag"><?= tipster_flag($t, 24) ?></span></h1>
      <div><?= tipster_stars((float)$t['rating_avg'], (int)$t['rating_count']) ?> <a href="#reviews"><?= (int)$t['rating_count'] ? e(number_format((float)$t['rating_avg'], 1)) . ' from ' . (int)$t['rating_count'] . ' rating' . ((int)$t['rating_count'] === 1 ? '' : 's') : 'No ratings yet' ?></a></div>
      <?php if ($t['tagline']): ?><p style="margin:6px 0 0;color:var(--mute)"><?= e($t['tagline']) ?></p><?php endif; ?>
      <small style="color:var(--mute)"><?= tipster_flag($t, 14) ?> <?= e($t['country'] ?: '') ?><?= $t['country'] && $t['specialties'] ? ' &middot; ' : '' ?><?= e($t['specialties'] ?: '') ?></small>
      <div class="tp-badges"><?php if ($verified): ?><span class="tp-badge ok" title="At least 20 settled tips and 30 days on the platform. Every result is recorded by the site and cannot be edited.">&#10003; Verified record</span><?php endif; ?>
        <?php if (!empty($t['guarantee'])): ?><span class="tp-badge gold" title="Money-back guarantee if this tipster loses badly in your subscription period. See the rules below.">Money-back guarantee</span><?php endif; ?>
        <?php if ($trialDays > 0): ?><span class="tp-badge">Free <?= $trialDays ?>-day trial</span><?php endif; ?>
        <span class="tp-badge plain"><?= (int)$followers ?> follower<?= $followers === 1 ? '' : 's' ?></span></div>
      <?php if (!$isOwner && $t['status'] === 'active'): ?>
        <form method="post" action="<?= e(url('community/action.php')) ?>" class="tp-follow"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>"><input type="hidden" name="tipster" value="<?= $tid ?>"><input type="hidden" name="back" value="tipster.php?slug=<?= e(rawurlencode($t['slug'])) ?>">
          <button class="btn <?= $isFollowing ? 'outline' : 'dark' ?>" type="submit"><?= $isFollowing ? 'Following (tap to unfollow)' : 'Follow for alerts' ?></button>
          <?php if ($isFollowing): ?><a class="btn ghost" href="<?= e(url('account/alerts.php')) ?>">Alert settings</a><?php endif; ?></form>
      <?php endif; ?></div></div>
</div>

<section style="padding-top:16px">
  <div class="wrap two">
    <div>
      <div class="tp-nums tp-record">
        <span><b><?= $stats['win_rate'] !== null ? (int)$stats['win_rate'] . '%' : '-' ?></b> win rate</span>
        <span><b><?= (int)$stats['won'] ?>-<?= (int)$stats['lost'] ?></b> won-lost</span>
        <span><b><?= e($stats['avg_odds']) ?></b> average odds</span>
        <span><b><?= e($stats['units']) ?></b> units profit</span>
      </div>
      <?= tipster_profile_panel(tipster_full_stats($tid)) ?>
      <div class="rc-box">
        <div class="rc-head"><h3>Verified results</h3><a href="<?= e(tipster_record_url($t)) ?>">See every tip &rarr;</a></div>
        <?= tipster_chart_html($months) ?>
        <?= tipster_stake_calc_html((float)$stats['units_raw'], tipster_units_days($tid, 30)) ?>
      </div>
      <?php if ($stats['form']): ?><p>Last <?= count($stats['form']) ?>: <?php foreach ($stats['form'] as $f): ?><span class="badge <?= e($f) ?>" style="margin-right:3px"><?= $f === 'won' ? 'W' : 'L' ?></span><?php endforeach; ?></p><?php endif; ?>
      <?php if ($t['bio']): ?><div class="box2" style="margin:16px 0"><h3>About</h3><p><?= nl2br(e($t['bio'])) ?></p></div><?php endif; ?>

      <h2 style="margin-top:22px">Recent tips</h2>
      <?php if ($tips): foreach ($tips as $tp): tip_card($tp, tip_access($tp)); endforeach; else: ?>
        <div class="note">No tips posted yet.</div>
      <?php endif; ?>
      <div class="note">The record is kept by <?= e(SITE_NAME) ?> and counts deleted tips too. Units assume 1 unit per tip.</div>

      <h2 id="reviews" style="margin-top:26px">Ratings and reviews</h2>
      <?php if ($canReview): ?>
      <form method="post" class="box2" style="margin-bottom:16px"><?= csrf_field() ?><input type="hidden" name="action" value="review">
        <h3><?= $myReview ? 'Update your rating' : 'Rate this tipster' ?></h3>
        <div class="field"><label for="rating">Stars</label>
          <select id="rating" name="rating" required><option value="">Choose...</option>
            <?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"<?= $myReview && (int)$myReview['rating'] === $i ? ' selected' : '' ?>><?= $i ?> - <?= ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][$i] ?></option><?php endfor; ?></select></div>
        <div class="field"><label for="comment">Comment (optional)</label><textarea id="comment" name="comment" rows="3" maxlength="600"><?= e($myReview['comment'] ?? '') ?></textarea></div>
        <button class="btn dark" type="submit">Save rating</button>
      </form>
      <?php elseif (!$isOwner): ?>
        <p style="color:var(--mute)">Only members who bought one of this tipster's plans can leave a rating. This keeps ratings honest.</p>
      <?php endif; ?>
      <?php comments_render('tipster', $tid, 'tipster.php?slug=' . rawurlencode($t['slug'])); ?>
      <h3 style="margin-top:22px">Reviews</h3>
      <?php foreach ($reviews as $r): ?>
        <div class="tp-review"><?= tipster_stars((float)$r['rating'], 1) ?> <?= user_chip_html($r, mb_substr(explode(' ', trim($r['name']))[0], 0, 20), 24) ?> <small><?= e(date('j M Y', strtotime($r['created_at']))) ?></small>
          <?php if ($r['comment']): ?><p><?= nl2br(e($r['comment'])) ?></p><?php endif; ?></div>
      <?php endforeach; ?>
      <?php if (!$reviews): ?><p style="color:var(--mute)">No reviews yet.</p><?php endif; ?>
    </div>

    <div>
      <?php if (managed_cols() && !empty($t['accepts_managed']) && $t['status'] === 'active' && !$isOwner): ?>
      <div class="box2" id="managed" style="margin-bottom:18px">
        <h3>Account management</h3>
        <p>This tipster takes account-management requests. Send a request and chat with them here on the site.</p>
        <?php if (!empty($t['managed_note'])): ?><p><strong>Their terms:</strong> <?= e($t['managed_note']) ?></p><?php endif; ?>
        <a class="btn dark" href="<?= e(url('account-management.php?tipster=' . rawurlencode($t['slug']))) ?>">Send a request</a>
        <p style="color:var(--mute);font-size:13px;margin-top:8px">Never share passwords or codes. <?= e(SITE_NAME) ?> never holds your betting money.</p>
      </div>
      <?php endif; ?>
      <div class="box2" id="plans">
        <h3>Subscribe</h3>
        <?php if ($trialDays > 0 && !$isOwner && $t['status'] === 'active'): ?>
          <div class="tp-trial">
            <?php if ($trialLive): ?><p><strong>Your free trial is running</strong> until <?= e(date('j M, H:i', strtotime($trialLive['ends_at']))) ?>. You can see this tipster's premium tips.</p>
            <?php elseif ($trialWhy === ''): ?><p><strong>Try <?= $trialDays ?> days free.</strong> See every premium tip, no payment needed.</p>
              <form method="post" action="<?= e(url('community/action.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="trial"><input type="hidden" name="tipster" value="<?= $tid ?>"><input type="hidden" name="back" value="tipster.php?slug=<?= e(rawurlencode($t['slug'])) ?>"><button class="btn gold" type="submit">Start free trial</button></form>
            <?php else: ?><p style="color:var(--mute)">Free <?= $trialDays ?>-day trial: <?= e($trialWhy) ?></p><?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($hasAccess && !$isOwner): ?><p class="note" style="margin-top:0">You have access to this tipster's premium tips.</p><?php endif; ?>
        <?php if ($t['status'] !== 'active'): ?>
          <p style="color:var(--mute)">Plans are available once the profile is approved.</p>
        <?php elseif ($plans): ?>
          <?php $best = null; if (count($plans) > 1) { $bd = null; foreach ($plans as $k => $pp) { $d = $pp['price_kes'] / max(1, (int)$pp['duration_days']); if ($bd === null || $d < $bd) { $bd = $d; $best = $k; } } } ?>
          <div class="pl-stack">
          <?php foreach ($plans as $k => $p): $perDay = $p['price_kes'] / max(1, (int)$p['duration_days']); ?>
          <div class="pl-card<?= $best === $k ? ' pl-best' : '' ?>">
            <?php if ($best === $k): ?><span class="pl-ribbon">Best value</span><?php endif; ?>
            <div class="pl-top"><h4><?= e(tipster_plan_label($p)) ?></h4><span class="pl-days"><?= (int)$p['duration_days'] ?> days</span></div>
            <div class="pl-price"><span class="pl-cur">KES</span><span class="pl-amt"><?= e(number_format((float)$p['price_kes'], 0)) ?></span></div>
            <div class="pl-per">about KES <?= e(number_format($perDay, 0)) ?> per day</div>
            <?php if (trim((string)$p['features']) !== ''): ?><ul class="pl-feat"><?php foreach (array_slice(preg_split('/\r?\n/', trim($p['features'])), 0, 6) as $ft): if (trim($ft) === '') continue; ?><li><?= e(trim($ft)) ?></li><?php endforeach; ?></ul>
            <?php else: ?><ul class="pl-feat"><li>All this tipster's premium picks</li><li>Reasoning and odds for each tip</li></ul><?php endif; ?>
            <?php if ($isOwner): ?><p class="pl-own">This is your plan</p>
            <?php else: ?><a class="btn gold pl-btn" href="<?= e(url('payments/checkout.php?plan=' . rawurlencode($p['slug']))) ?>"><?= $hasAccess ? 'Extend access' : 'Subscribe' ?></a><?php endif; ?>
          </div>
          <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p style="color:var(--mute)">This tipster has no paid plans yet.</p>
        <?php endif; ?>
        <?php if (!empty($t['guarantee'])): ?><div class="tp-guar"><strong>Money-back guarantee.</strong> If this tipster's premium tips lose <?= e(rtrim(rtrim(number_format((float)setting('tipster_guarantee_units', '5'), 1), '0'), '.')) ?> units or more over your paid period (at least 5 settled premium tips), you can claim a refund within 7 days after your plan ends. Claims are checked by <?= e(SITE_NAME) ?>.</div><?php endif; ?>
        <p style="color:var(--mute);font-size:13px">Pay with M-Pesa, PayPal or card. Access starts as soon as the payment is confirmed. No tip is guaranteed.</p>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
