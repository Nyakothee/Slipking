<?php
require_once __DIR__ . '/includes/init.php';

$page_title = SITE_NAME . ' - Football & Sports Predictions with a Public Record';
$page_desc  = 'Free daily betting predictions and VIP tips. Every result is published, win or lose.';
$active     = 'home';
require_once __DIR__ . '/includes/seo.php';
$head_extra = seo_site_jsonld() . '<link rel="stylesheet" href="' . e(url('assets/css/jackpot.css')) . '?v=1">';

$vip   = is_vip();
// Public, member-independent data is kept for 60 seconds (cleared automatically by any admin change).
$home = cache_remember('home-public', 60, function () {
    $free = fetch_tips(['vip' => 0, 'today' => true, 'limit' => 3]);
    if (!$free) { $free = fetch_tips(['vip' => 0, 'limit' => 3, 'order' => 'recent']); }
    $vipTips = fetch_tips(['vip' => 1, 'today' => true, 'limit' => 3]);
    if (!$vipTips) { $vipTips = fetch_tips(['vip' => 1, 'limit' => 3, 'order' => 'recent']); }
    return [
        'stats'   => site_stats(),
        'free'    => $free,
        'vipTips' => $vipTips,
        'million' => latest_acca('million'),
        'form'    => recent_form(8),
        'months'  => monthly_rates(),
        'plans'   => db()->query('SELECT * FROM plans WHERE is_active = 1 AND tipster_id IS NULL ORDER BY sort_order, id')->fetchAll(),
    ];
});
$stats = $home['stats']; $free = $home['free']; $vipTips = $home['vipTips']; $million = $home['million'];
$form = $home['form']; $months = $home['months']; $plans = $home['plans'];
try { housekeeping(); } catch (Throwable $e) { /* never break the home page */ }

// Add REAL member reviews here (with permission). The section stays hidden while this is empty.
// Example: ['name' => 'Brian, Nairobi', 'text' => 'Their record matched what they promised.']
$testimonials = [];

include __DIR__ . '/includes/header.php';
?>
<?= jackpot_banner_html() ?>

<div class="hero">
  <div class="wrap">
    <h1>Predictions you can check, match by match.</h1>
    <p>Free tips every day. Every pick is timestamped before kickoff and stays on the record, win or lose.</p>
    <div class="cta">
      <a class="btn gold" href="<?= e(url('tips.php')) ?>">See today's free tips</a>
      <?php if ($vip): ?>
        <a class="btn ghost" href="<?= e(url('account/dashboard.php')) ?>">Open your VIP picks</a>
      <?php else: ?>
        <a class="btn ghost" href="<?= e(url('vip.php')) ?>">View VIP plans</a>
      <?php endif; ?>
    </div>
    <div class="proof">
      <div><b><?= $stats['win_rate'] !== null ? (int)$stats['win_rate'] . '%' : '-' ?></b><span>won, last 30 days</span></div>
      <div><b><?= (int)$stats['settled'] ?></b><span>tips settled, last 30 days</span></div>
      <div><b><?= e($stats['avg_odds']) ?></b><span>average odds</span></div>
    </div>
  </div>
</div>

<section>
  <div class="wrap">
    <div class="head">
      <div><h2>Free tips today</h2><p>No sign-up needed. Refreshed every morning.</p></div>
      <a class="btn dark" href="<?= e(url('tips.php')) ?>">All tips</a>
    </div>
    <?php if ($free): ?>
      <div class="grid"><?php foreach ($free as $t) { tip_card($t, $vip); } ?></div>
    <?php else: ?>
      <div class="empty">Today's free tips are being prepared. Check back in a little while.</div>
    <?php endif; ?>
  </div>
</section>

<?php streak_card(); ?>

<section style="padding-top:0">
  <div class="wrap">
    <div class="head">
      <div><h2>VIP picks</h2><p><span>Higher confidence, fewer tips.</span> <span><?= $vip ? 'Your plan is active.' : 'Unlock with a plan.' ?></span></p></div>
    </div>
    <?php if ($vipTips): ?>
      <div class="grid"><?php foreach ($vipTips as $t) { tip_card($t, $vip); } ?></div>
    <?php else: ?>
      <div class="empty">VIP picks for today will appear here.</div>
    <?php endif; ?>
  </div>
</section>

<?php if ($million): ?>
<section style="padding-top:0">
  <div class="wrap">
    <div class="head"><div><h2>1 Million Accumulator</h2><p>Free for everyone. A long ticket where every selection must win.</p></div><a class="btn dark" href="<?= e(url('accumulators.php')) ?>">All accumulators</a></div>
    <div class="acards">
      <div class="acard">
        <div class="row"><span><?= e(date('j M Y', strtotime($million['posted_at']))) ?></span><span class="badge <?= e($million['status']) ?>"><?= e(ucfirst($million['status'])) ?></span></div>
        <h3><?= e($million['title']) ?></h3>
        <div class="bigodds"><?= e(number_format((float)$million['total_odds'], 2)) ?></div>
        <div class="row"><span>Total odds</span><b><?= (int)$million['legs'] ?> selections</b></div>
        <div class="row"><span>Minimum stake for <?= e(acca_target_display((int)$million['target_kes'])) ?></span><b><?= e(acca_min_stake_display((int)$million['target_kes'], (float)$million['total_odds'])) ?></b></div>
        <a class="btn gold full" href="<?= e(url('acca.php?id=' . (int)$million['id'])) ?>">See all selections</a>
      </div>
    </div>
    <p style="color:var(--mute);font-size:14px;margin-top:10px">Most long accumulators lose. Only stake what you can afford to lose.</p>
  </div>
</section>
<?php endif; ?>

<?php if ($wb = widgets_block('home')): ?><section><div class="wrap"><?= $wb ?></div></section><?php endif; ?>
<section class="record">
  <div class="wrap rec-grid">
    <div>
      <h2>Our record is public</h2>
      <p>Every tip we post is kept in the archive with its posting time. Nothing is edited or hidden after the match.</p>
      <?php if ($form): ?>
        <div class="form" aria-label="Last <?= count($form) ?> results">
          <?php foreach ($form as $r): ?><i class="<?= $r === 'won' ? 'w' : 'l' ?>"><?= $r === 'won' ? 'W' : 'L' ?></i><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <a class="btn gold" href="<?= e(url('results.php')) ?>">Open full results</a>
    </div>
    <div class="chart-wrap">
      <div class="stat-chart">
        <div class="sc-head"><strong>Win rate by month</strong><span><?= $months ? 'Last ' . count($months) . ' month(s), settled tips only' : 'Updates as tips settle' ?></span></div>
        <div class="sc-plot" role="img" aria-label="Win rate by month">
          <div class="sc-grid" aria-hidden="true"><i style="bottom:100%"><b>100%</b></i><i style="bottom:75%"><b>75%</b></i><i style="bottom:50%" class="mid"><b>50%</b></i><i style="bottom:25%"><b>25%</b></i><i style="bottom:0"><b>0%</b></i></div>
          <div class="sc-bars">
          <?php if ($months): foreach ($months as $i => $m): $rt = max(0, min(100, (int)$m['rate'])); ?>
            <div class="sc-col" style="--h:<?= $rt ?>%;--d:<?= $i * 90 ?>ms" title="<?= e($m['label']) ?>: <?= (int)$m['w'] ?> won of <?= (int)$m['n'] ?> (<?= $rt ?>%)">
              <div class="sc-stack"><em><?= $rt ?>%</em><span class="sc-bar<?= $rt >= 50 ? '' : ' low' ?>"></span></div><label><?= e($m['label']) ?></label><small><?= (int)$m['w'] ?>/<?= (int)$m['n'] ?></small>
            </div>
          <?php endforeach; else: for ($i = 0; $i < 6; $i++): ?>
            <div class="sc-col ghost" style="--h:<?= [35, 55, 45, 70, 60, 80][$i] ?>%;--d:<?= $i * 90 ?>ms"><div class="sc-stack"><span class="sc-bar"></span></div><label>&nbsp;</label></div>
          <?php endfor; endif; ?>
          </div>
          <?php if (!$months): ?><div class="sc-empty">Monthly results appear here once tips are settled.</div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<section>
  <div class="wrap">
    <div class="head"><h2>Pick your sport</h2></div>
    <div class="tiles">
      <a class="tile" href="<?= e(url('tips.php?sport=football')) ?>" style="background-image:linear-gradient(transparent 30%,rgba(0,0,0,.7)),url(https://images.unsplash.com/photo-1574629810360-7efbbe195018?auto=format&fit=crop&w=600&q=60);background-color:#155240">Football</a>
      <a class="tile" href="<?= e(url('tips.php?sport=basketball')) ?>" style="background-image:linear-gradient(transparent 30%,rgba(0,0,0,.7)),url(https://images.unsplash.com/photo-1546519638-68e109498ffc?auto=format&fit=crop&w=600&q=60);background-color:#8a4b12">Basketball</a>
      <a class="tile" href="<?= e(url('tips.php?sport=tennis')) ?>" style="background-image:linear-gradient(transparent 30%,rgba(0,0,0,.7)),url(https://images.unsplash.com/photo-1554068865-24cecd4e34b8?auto=format&fit=crop&w=600&q=60);background-color:#3f6b2a">Tennis</a>
      <a class="tile" href="<?= e(url('vip.php')) ?>" style="background-image:linear-gradient(transparent 30%,rgba(0,0,0,.7)),url(https://images.unsplash.com/photo-1540747913346-19e32dc3e97e?auto=format&fit=crop&w=600&q=60);background-color:#2a4b6b">Accumulators</a>
    </div>
  </div>
</section>

<?php $homeTipsters = array_slice(tipsters_directory('rating', ''), 0, 6); ?>
<section id="tipsters" style="padding-top:0">
  <div class="wrap">
    <div class="head"><h2>Tipsters</h2><p>Follow independent tipsters, check their record and ratings, then choose the one you trust. <a href="<?= e(url('tipsters.php')) ?>" style="text-decoration:underline">See all tipsters</a> &middot; <a href="<?= e(url('leaderboard.php')) ?>" style="text-decoration:underline">Leaderboard</a></p></div>
    <?php if ($homeTipsters): ?>
    <div class="tp-grid">
      <?php foreach ($homeTipsters as $t): ?>
      <div class="tp-card">
        <div class="tp-head"><?= tipster_avatar($t, 56) ?>
          <div><strong><?= e($t['display_name']) ?></strong>
            <div><?= tipster_stars((float)$t['rating_avg'], (int)$t['rating_count']) ?> <small><?= (int)$t['rating_count'] ? e(number_format((float)$t['rating_avg'], 1)) . ' (' . (int)$t['rating_count'] . ')' : 'No ratings yet' ?></small></div></div></div>
        <?php if ($t['tagline']): ?><p class="tp-tag"><?= e($t['tagline']) ?></p><?php endif; ?>
        <div class="tp-nums">
          <span><b><?= $t['win_rate'] !== null ? (int)$t['win_rate'] . '%' : '-' ?></b> win rate</span>
          <span><b><?= (int)$t['settled'] ?></b> settled tips</span>
          <span><b><?= (int)$t['subs'] ?></b> subscribers</span>
        </div>
        <div class="tp-foot"><?= $t['from_kes'] !== null ? 'From KES ' . e(number_format((float)$t['from_kes'], 0)) : 'No plans yet' ?></div>
        <div class="tp-btns"><a class="btn outline small" href="<?= e(tipster_url($t)) ?>">View performance</a><?php if ($t['from_kes'] !== null): ?><a class="btn gold small" href="<?= e(tipster_url($t)) ?>#plans">Choose</a><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="note">Our first tipsters will appear here soon.</div>
    <?php endif; ?>
    <?php if (setting('tipster_signups_open', '1') === '1' && !current_tipster()): ?><p style="margin-top:16px"><a class="btn dark" href="<?= e(url('auth/register-tipster.php')) ?>">Become a tipster</a></p><?php endif; ?>
  </div>
</section>

<?php if ($plans && !$vip): ?>
<section style="padding-top:0">
  <div class="wrap">
    <div class="head"><div><h2>Choose your plan</h2><p>Pay with M-Pesa, Airtel Money, MTN MoMo, card or PayPal. Your plan runs for the period you pay for and does not renew by itself.</p></div></div>
    <div class="plans">
      <?php foreach ($plans as $p): ?>
        <div class="plan<?= $p['is_featured'] ? ' best' : '' ?>">
          <?php if ($p['is_featured']): ?><span class="tag">Most chosen</span><?php endif; ?>
          <h3><?= e($p['name']) ?></h3>
          <div class="price"><?= e(plan_price_display($p)) ?> <small>/ <?= e(plan_period((int)$p['duration_days'])) ?></small></div>
          <ul>
            <?php foreach (preg_split('/\R/', trim($p['features'])) as $f): if ($f === '') continue; ?>
              <li><?= e($f) ?></li>
            <?php endforeach; ?>
          </ul>
          <a class="btn <?= $p['is_featured'] ? 'gold' : 'dark' ?>" href="<?= e(url('payments/checkout.php?plan=' . urlencode($p['slug']))) ?>">Choose <?= e($p['name']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($testimonials): ?>
<section style="padding-top:0">
  <div class="wrap">
    <div class="head"><h2>What members say</h2></div>
    <div class="quotes">
      <?php foreach ($testimonials as $q): ?>
        <div class="quote">"<?= e($q['text']) ?>"<div class="who"><?= e($q['name']) ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section style="padding-top:0">
  <div class="wrap faq">
    <div class="head"><h2>Questions</h2></div>
    <details><summary>Are the tips guaranteed to win?</summary><p>No. No tip is guaranteed. We publish every result so you can judge the record yourself.</p></details>
    <details><summary>How do I pay for VIP?</summary><p>With M-Pesa, Airtel Money or MTN MoMo (you get a prompt on your phone), by card (Stripe), or with PayPal. Your plan activates as soon as payment is confirmed.</p></details>
    <details><summary>Does my plan renew automatically?</summary><p>No. Your plan ends when the period you paid for ends, and you choose whether to buy another.</p></details>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
