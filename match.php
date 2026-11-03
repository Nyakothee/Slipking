<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';

$id = (int)($_GET['id'] ?? 0);
$m  = $id ? seo_match($id) : null;
if (!$m) {
    require __DIR__ . '/404.php';
    exit;
}
$vip   = is_vip();
$tips  = seo_tips_query(['match_id' => $id, 'when' => 'all', 'limit' => 50]);
$home  = $m['home_team'];
$away  = $m['away_team'];
$past  = strtotime($m['kickoff_at']) <= time();
$when  = date('D j M Y, H:i', strtotime($m['kickoff_at']));

// Description: only ever built from public tips (free, or VIP already settled). Never the locked VIP pick or odds.
$pub = array_values(array_filter($tips, 'seo_tip_is_public'));
$desc = $home . ' vs ' . $away . ' (' . $m['league'] . ', ' . $when . '): ';
if ($pub) {
    $desc .= 'our prediction ' . $pub[0]['pick'] . ' at odds ' . number_format((float)$pub[0]['odds'], 2) . ', with analysis.';
} elseif ($tips) {
    $desc .= 'a VIP prediction is available for members.';
} else {
    $desc .= 'match preview and betting tips.';
}
$page_title = $home . ' vs ' . $away . ' prediction and betting tips - ' . SITE_NAME;
$page_desc  = seo_trim($desc, 158);
$canonical  = url('match.php?id=' . $id);
$active     = 'tips';

$ld = [
    '@context'    => 'https://schema.org',
    '@type'       => 'SportsEvent',
    'name'        => $home . ' vs ' . $away,
    'startDate'   => seo_iso($m['kickoff_at']),
    'eventStatus' => 'https://schema.org/EventScheduled',
    'sport'       => $m['sport'],
    'url'         => $canonical,
    'homeTeam'    => ['@type' => 'SportsTeam', 'name' => $home],
    'awayTeam'    => ['@type' => 'SportsTeam', 'name' => $away],
    'competitor'  => [['@type' => 'SportsTeam', 'name' => $home], ['@type' => 'SportsTeam', 'name' => $away]],
    'superEvent'  => ['@type' => 'SportsEvent', 'name' => $m['league'], 'url' => url('league.php?id=' . (int)$m['league_id'])],
    'description' => $page_desc,
];
$head_extra  = '<link rel="stylesheet" href="' . e(url('assets/css/group-c.css')) . '">' . "\n";
$head_extra .= seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => $canonical,
                         'robots' => $tips ? '' : 'noindex,follow']);
$head_extra .= seo_jsonld($ld);
$head_extra .= seo_breadcrumb_jsonld([['Home', url()], ['Tips', url('tips.php')], [$m['league'], url('league.php?id=' . (int)$m['league_id'])], [$home . ' vs ' . $away]]);
include __DIR__ . '/includes/header.php';
?>
<?php if ($wb = widgets_block('match')): ?><div class="wrap" style="padding-top:12px"><?= $wb ?></div><?php endif; ?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url('tips.php')) ?>">Tips</a> / <?= e($m['sport']) ?> / <a href="<?= e(url('league.php?id=' . (int)$m['league_id'])) ?>"><?= e($m['league']) ?></a></div>
  <h1 class="mtitle"><?= e($home) ?> vs <?= e($away) ?></h1>
  <p><a href="#poll">Vote in the poll</a> &middot; <a href="<?= e(url('room.php?id=' . $id)) ?>">Join the discussion (<?= (int)comments_count('match', $id) ?>)</a></p>
  <p style="color:var(--mute)"><?= e(date('l j F Y, H:i', strtotime($m['kickoff_at']))) ?> &middot; <?= e($m['league']) ?><?= $past ? ' &middot; ' . e('Kick-off has passed') : '' ?></p>
</div>
<?php
$lv = null;
try {
    $q = db()->prepare('SELECT live_status, live_minute, home_score, away_score FROM matches WHERE id = ?');
    $q->execute([$id]);
    $lv = $q->fetch() ?: null;
} catch (Throwable $e) { $lv = null; }
$showLive = $lv && ($lv['live_status'] !== null || (strtotime($m['kickoff_at']) < time() + 7200 && strtotime($m['kickoff_at']) > time() - 6 * 3600));
if ($showLive):
    $lvEvents = live_events($id);
?>
<section style="padding-top:10px"><div class="wrap">
  <div class="lv-panel" id="lv-panel" data-id="<?= (int)$id ?>" data-url="<?= e(url('api/live.php')) ?>">
    <div class="lv-ph"><span id="lv-label" class="lv-pill<?= live_is_live($lv['live_status']) ? ' live' : '' ?>"><?= e(live_status_label($lv['live_status'], $lv['live_minute'] !== null ? (int)$lv['live_minute'] : null) ?: 'Not started') ?></span>
      <span class="lv-state" id="lv-state">Updating</span></div>
    <div class="lv-big"><span><?= e($home) ?></span><b id="lv-scoreline"><?= $lv['home_score'] !== null ? (int)$lv['home_score'] . ' : ' . (int)$lv['away_score'] : '- : -' ?></b><span><?= e($away) ?></span></div>
    <ul class="lv-events" id="lv-events">
      <?php if (!$lvEvents): ?><li class="lv-none">No events yet.</li><?php endif; ?>
      <?php foreach ($lvEvents as $ev): ?><li class="lv-ev <?= $ev['team'] === 'away' ? 'away' : 'home' ?>"><span class="lv-min"><?= (int)$ev['minute'] . ($ev['extra'] ? '+' . (int)$ev['extra'] : '') ?>'</span><span class="lv-ico"><?= live_event_icon($ev['type']) ?></span><span><?= e($ev['player']) ?><?= $ev['detail'] !== '' && $ev['detail'] !== null ? ' <small>' . e($ev['detail']) . '</small>' : '' ?></span></li><?php endforeach; ?>
    </ul>
    <div class="lv-tips" id="lv-tipstatus"></div>
  </div>
</div></section>
<script src="<?= e(url('assets/js/live.js')) ?>"></script>
<?php endif; ?>
<section style="padding-top:22px">
  <div class="wrap">
    <div class="head"><h2>Tips for this match</h2></div>
    <?php if ($tips): ?>
      <div class="grid"><?php foreach ($tips as $t) { tip_card($t, $vip); } ?></div>
      <?php if (!$vip && count($pub) < count($tips)): ?>
        <div class="note"><a href="<?= e(url('vip.php')) ?>" style="font-weight:700;text-decoration:underline">See VIP plans</a> to unlock the VIP picks. Once the match is settled, every pick becomes public.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="empty">No tips for this match yet. Check back closer to kick-off.</div>
    <?php endif; ?>

    <div class="mp-form">
      <div class="box2">
        <h3><?= e($home) ?> form</h3>
        <p style="color:var(--mute);font-size:14px">Our recent settled tips on this team's matches.</p>
        <?= seo_form_strip(seo_team_form($home, $id)) ?>
      </div>
      <div class="box2">
        <h3><?= e($away) ?> form</h3>
        <p style="color:var(--mute);font-size:14px">Our recent settled tips on this team's matches.</p>
        <?= seo_form_strip(seo_team_form($away, $id)) ?>
      </div>
    </div>
    <?php
    $sfx = stats_on() ? stats_find_fixture($home, $away, $m['kickoff_at']) : null;
    if ($sfx):
        $sfx = stats_match((int)$sfx['id']) ?: $sfx; $spred = stats_predict($sfx); ?>
    <div class="box2" style="margin-top:18px"><h3>Statistical estimate</h3>
      <?php if ($spred): ?><div class="pb-labels"><span><?= e($home) ?></span><span>Draw</span><span><?= e($away) ?></span></div><?= stats_prob_bar($spred) ?><?php endif; ?>
      <p style="margin-top:8px"><a href="<?= e(url('fixture.php?id=' . (int)$sfx['id'])) ?>">Full preview, form and head to head</a></p>
      <?= stats_disclaimer() ?></div>
    <?php endif;
    if (function_exists('odds_on') && odds_on() && ($oev = odds_find_event($home, $away, gmdate('Y-m-d H:i:s', strtotime($m['kickoff_at']))))): echo odds_compare_html($oev, $spred ?? null); endif; ?>
    <?= poll_html($m, current_user()) ?>
    <div class="cm-box" id="comments" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;justify-content:space-between">
      <div><h3 style="margin:0">Match discussion room</h3>
        <p style="color:var(--mute);margin:4px 0 0"><?= (int)comments_count('match', $id) ?> comment<?= (int)comments_count('match', $id) === 1 ? '' : 's' ?>. Threads, replies, up/down votes and fan chat for <?= e($home . ' vs ' . $away) ?>.</p></div>
      <a class="btn dark" href="<?= e(url('room.php?id=' . $id)) ?>">Open the discussion room &rarr;</a>
    </div>
    <p style="margin-top:18px"><a class="btn dark" href="<?= e(url('league.php?id=' . (int)$m['league_id'])) ?>">More <?= e($m['league']) ?> tips</a></p>
    <div class="note">Odds change between bookmakers. Check the price you are offered before you bet. No tip is guaranteed. 18+ only. <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Gamble responsibly</a>.</div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
