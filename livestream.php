<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$page_title = 'Livestream and match highlights - ' . SITE_NAME;
$page_desc  = 'Watch football on the official platforms and replay the best moments. Live stream links and YouTube highlights, updated daily.';
$active = 'livestream';
$ready = streams_ready();
$perPage = 12;
$hp = max(1, min(20, (int)($_GET['hp'] ?? 1)));
$all = $ready ? streams_fetch('stream', 60) : [];
$liveNow = []; $upcoming = [];
foreach ($all as $s) { if (stream_state($s) === 'live') { $liveNow[] = $s; } else { $upcoming[] = $s; } }
$hl = $ready ? streams_fetch('highlight', $perPage * $hp + 1) : [];
$more = count($hl) > $perPage * $hp;
$hl = array_slice($hl, 0, $perPage * $hp);
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/livestream.css')) . '?v=1">';
include __DIR__ . '/includes/header.php';
if ($wb = widgets_block('livestream')) { echo '<div class="wrap" style="padding-top:16px">' . $wb . '</div>'; }

function ls_card(array $s, string $mode): void
{
    $thumb = stream_thumb($s);
    $st = stream_state($s);
    $isHl = $s['kind'] === 'highlight';
    $yt   = $isHl && stream_youtube_id((string)$s['url']) !== '';
    $href = $yt ? url('highlight.php?id=' . (int)$s['id']) : (stream_watch_url((string)$s['url'], (string)$s['title']) ?: stream_go_url((int)$s['id']));
    $ext  = false;
    $play = !$yt && stream_watch_url((string)$s['url'], (string)$s['title']) !== '' ? ' data-play="' . e(stream_watch_url((string)$s['url'], (string)$s['title'], 'embed')) . '" data-title="' . e((string)$s['title']) . '"' : '';
    ?>
    <?php $off = !$isHl && $st !== 'live'; $xb = !$isHl && !$off ? stream_extra_list($s) : []; if ($xb) { echo '<div class="ls-wrap">'; } ?>
    <<?= $off ? 'div class="ls-card ls-off"' : 'a class="ls-card" href="' . e($href) . '"' . $play ?>>
      <span class="ls-thumb"<?= $thumb !== '' ? ' style="background-image:url(\'' . e($thumb) . '\')"' : '' ?>>
        <?php if ($thumb === ''): ?><span class="ls-vs"><?= e(mb_substr($s['home_team'] ?: $s['title'], 0, 3)) ?><i>vs</i><?= e(mb_substr($s['away_team'], 0, 3)) ?></span><?php endif; ?>
        <?php if ($mode === 'live'): ?><span class="ls-pill live"><i></i>LIVE</span><?php elseif ($isHl): ?><span class="ls-play" aria-hidden="true">&#9654;</span><?php endif; ?>
      </span>
      <span class="ls-body">
        <span class="ls-league"><?= e($s['league'] !== '' ? $s['league'] : ($isHl ? 'Highlights' : 'Match')) ?></span>
        <strong class="ls-title"><?= e($s['title']) ?></strong>
        <span class="ls-meta"><?php if (!empty($s['starts_at'])): ?><time class="lt" datetime="<?= e(date('c', strtotime((string)$s['starts_at']))) ?>"><?= e(kickoff_label((string)$s['starts_at'])) ?></time><?php endif; ?><?= $s['platform'] !== '' ? ' &middot; ' . e($s['platform']) : '' ?></span>
        <span class="ls-btn<?= $off ? ' off' : '' ?>"><?= $isHl ? 'Watch highlights' : ($off ? ($st === 'ended' ? 'Live ended' : 'Game not started') : 'Watch now') ?><?= !$off && $s['platform'] !== '' && !$isHl ? ' on ' . e($s['platform']) : '' ?></span>
      </span>
    </<?= $off ? 'div' : 'a' ?>>
    <?php if ($xb) { echo '<div class="ls-more">'; foreach ($xb as $x) { echo '<a class="ls-btn alt" href="' . e($x['watch']) . '" data-play="' . e($x['play']) . '" data-title="' . e((string)$s['title']) . '">' . e($x['label']) . '</a>'; } echo '</div></div>'; } ?>
    <?php
}
?>
<section class="ls-hero"><div class="wrap">
  <span class="ls-badge">Matchday ready</span>
  <h1>Livestream &amp; match highlights</h1>
  <p>Find where to watch today's games on the official platforms, and replay the best moments from YouTube.</p>
  <div class="ls-chips"><a href="#live">Live now</a><a href="#upcoming">Upcoming</a><a href="#highlights">Highlights</a><a href="<?= e(url('live.php')) ?>">Live scores</a></div>
</div></section>

<section class="ls-sec" id="live"><div class="wrap">
  <h2>Live now</h2>
  <?php if ($liveNow): ?><div class="ls-grid"><?php foreach ($liveNow as $s) { ls_card($s, 'live'); } ?></div>
  <?php else: ?><div class="empty">No streams are live right now. See what is coming up below.</div><?php endif; ?>
</div></section>

<section class="ls-sec alt" id="upcoming"><div class="wrap">
  <h2>Upcoming streams</h2>
  <?php if ($upcoming): ?><div class="ls-grid"><?php foreach ($upcoming as $s) { ls_card($s, 'up'); } ?></div>
  <?php else: ?><div class="empty">No upcoming streams have been added yet.</div><?php endif; ?>
</div></section>

<section class="ls-sec" id="highlights"><div class="wrap">
  <h2>Match highlights</h2><p class="ls-sub">Latest football highlights. Videos play from YouTube.</p>
  <?php if ($hl): ?><div class="ls-grid"><?php foreach ($hl as $s) { ls_card($s, 'hl'); } ?></div>
    <?php if ($more): ?><p style="text-align:center;margin-top:18px"><a class="btn gold" href="<?= e(url('livestream.php?hp=' . ($hp + 1))) ?>#highlights">Load more</a></p><?php endif; ?>
  <?php else: ?><div class="empty">No highlights yet. Check back after the next round of games.</div><?php endif; ?>
</div></section>

<div class="wrap"><div class="note" style="margin-bottom:26px">SlipKing does not host or restream matches. Streams and videos belong to the platforms that hold the rights, and links open on their own sites. Availability can depend on your country. 18+ only; bet responsibly.</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
