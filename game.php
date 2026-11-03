<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$pdo  = db();
$user = current_user();
$tab  = $_GET['tab'] ?? 'pick';
if (!in_array($tab, ['pick', 'mine', 'week', 'month', 'all', 'friends'], true)) { $tab = 'pick'; }
$prev = !empty($_GET['prev']) ? -1 : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) { redirect('auth/login.php?next=' . rawurlencode('game.php')); }
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('game.php'); }
    $open = [];
    foreach (game_open_matches(72) as $m) { $open[(int)$m['id']] = $m; }
    $saved = 0; $late = 0;
    foreach ((array)($_POST['p'] ?? []) as $mid => $pick) {
        $mid = (int)$mid;
        if (!in_array($pick, ['1', 'X', '2'], true) || !isset($open[$mid])) { continue; }
        if (!game_can_pick($open[$mid])) { $late++; continue; }
        $st = $pdo->prepare("INSERT INTO game_picks (user_id, match_id, pick) VALUES (?,?,?)
                             ON DUPLICATE KEY UPDATE pick = IF(status = 'open', VALUES(pick), pick), updated_at = IF(status = 'open', NOW(), updated_at)");
        $st->execute([$user['id'], $mid, $pick]);
        $saved++;
    }
    if ($saved) { badge_award((int)$user['id'], 'first_pick'); }
    set_flash($saved ? 'success' : 'error', $saved ? 'Saved ' . $saved . ' pick' . ($saved === 1 ? '' : 's') . '. Good luck!' : ($late ? 'Those matches are about to start, picks are closed.' : 'Choose 1, X or 2 for at least one match.'));
    redirect('game.php?tab=pick');
}

$ppc = game_points_correct();
$page_title = 'Free prediction game - ' . SITE_NAME;
$page_desc  = 'Pick match results for free, earn points and climb the weekly and monthly leaderboard. Win free VIP days. No money involved.';
$active = 'game';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Free prediction game</h1>
  <p style="color:var(--mute);max-width:680px">Pick the result of the day's matches: <strong>1</strong> = home win, <strong>X</strong> = draw, <strong>2</strong> = away win. Each correct pick earns <?= (int)$ppc ?> points. No money, no stake. Top players each week and month win free VIP days.</p>
  <div class="tabs gm-tabs" style="margin:14px 0">
    <?php foreach (['pick' => 'Pick matches', 'mine' => 'My picks', 'week' => 'Weekly leaderboard', 'month' => 'Monthly leaderboard', 'all' => 'All time'] + ($user ? ['friends' => 'Friends'] : []) as $k => $l): ?>
      <a href="<?= e(url('game.php?tab=' . $k)) ?>"<?= $tab === $k ? ' class="on" aria-current="page"' : '' ?>><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<section style="padding-top:4px"><div class="wrap">

<?php if ($tab === 'pick'): $ms = game_open_matches(72); ?>
  <?php if (!$user): ?><div class="note"><a href="<?= e(url('auth/login.php?next=game.php')) ?>">Log in</a> or <a href="<?= e(url('auth/register.php')) ?>">create a free account</a> to save your picks.</div><?php endif; ?>
  <?php if (!$ms): ?>
    <div class="note">No matches are open for picks right now. New matches appear as they are added.</div>
  <?php else:
    $mine = [];
    if ($user) {
        $ids = implode(',', array_map('intval', array_column($ms, 'id')));
        $q = $pdo->prepare("SELECT match_id, pick FROM game_picks WHERE user_id = ? AND match_id IN ($ids)");
        $q->execute([$user['id']]);
        foreach ($q->fetchAll() as $r) { $mine[(int)$r['match_id']] = $r['pick']; }
    } ?>
  <form method="post"><?= csrf_field() ?>
    <div class="gm-list">
    <?php $lastDay = ''; foreach ($ms as $m): $day = date('l j F', strtotime($m['kickoff_at'])); if ($day !== $lastDay): $lastDay = $day; ?>
      <h3 class="gm-day"><?= e($day) ?></h3><?php endif; $cur = $mine[(int)$m['id']] ?? ''; $can = game_can_pick($m); ?>
      <div class="gm-row">
        <div class="gm-info"><span class="gm-time"><?= e(date('H:i', strtotime($m['kickoff_at']))) ?></span>
          <span class="gm-teams"><?= e($m['home_team']) ?> <em>vs</em> <?= e($m['away_team']) ?></span><small><?= e($m['league']) ?></small></div>
        <div class="gm-opts" role="radiogroup" aria-label="<?= e($m['home_team'] . ' vs ' . $m['away_team']) ?>">
          <?php foreach (['1' => 'Home', 'X' => 'Draw', '2' => 'Away'] as $v => $lab): ?>
            <label class="gm-opt"><input type="radio" name="p[<?= (int)$m['id'] ?>]" value="<?= $v ?>"<?= $cur === $v ? ' checked' : '' ?><?= $can && $user ? '' : ' disabled' ?>><span><b><?= $v ?></b><small><?= $lab ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if ($user): ?><p style="margin-top:16px"><button class="btn gold" type="submit">Save my picks</button> <small style="color:var(--mute)">You can change a pick until 1 minute before kickoff.</small></p><?php endif; ?>
  </form>
  <?php endif; ?>

<?php elseif ($tab === 'mine'): ?>
  <?php if (!$user): ?><div class="note"><a href="<?= e(url('auth/login.php?next=game.php%3Ftab%3Dmine')) ?>">Log in</a> to see your picks.</div>
  <?php else:
    $st = $pdo->prepare("SELECT p.pick, p.status, m.id, m.home_team, m.away_team, m.kickoff_at, m.home_score, m.away_score
                         FROM game_picks p JOIN matches m ON m.id = p.match_id WHERE p.user_id = ? ORDER BY m.kickoff_at DESC LIMIT 60");
    $st->execute([$user['id']]); $rows = $st->fetchAll();
    $wk = game_user_points((int)$user['id'], 'week'); $mo = game_user_points((int)$user['id'], 'month'); $al = game_user_points((int)$user['id']); ?>
    <div class="gm-stats"><div><small>This week</small><b><?= $wk ?></b></div><div><small>This month</small><b><?= $mo ?></b></div><div><small>All time</small><b><?= $al ?></b></div>
      <div><small>Day streak</small><b><?= (int)user_streak((int)$user['id'])['current_streak'] ?></b></div></div>
    <?php if (!$rows): ?><div class="note">You have not made any picks yet. Go to "Pick matches".</div><?php else: ?>
    <div class="scroll"><table class="bm-table"><thead><tr><th>Match</th><th>Kickoff</th><th>My pick</th><th>Score</th><th>Result</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a href="<?= e(url('match.php?id=' . (int)$r['id'])) ?>"><?= e($r['home_team'] . ' vs ' . $r['away_team']) ?></a></td>
        <td><?= e(date('j M, H:i', strtotime($r['kickoff_at']))) ?></td><td><strong><?= e($r['pick']) ?></strong></td>
        <td><?= $r['home_score'] !== null ? (int)$r['home_score'] . ' - ' . (int)$r['away_score'] : '-' ?></td>
        <td><?php $c = ['open' => '', 'won' => 'win', 'lost' => 'loss', 'void' => '']; ?><span style="font-weight:700;color:var(--<?= $c[$r['status']] ?: 'mute' ?>)"><?= e($r['status'] === 'won' ? '+' . $ppc . ' points' : ($r['status'] === 'open' ? 'Waiting' : ucfirst($r['status']))) ?></span></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  <?php endif; ?>

<?php else:
  $type = $tab;
  $per = in_array($type, ['all', 'friends'], true) ? null : game_period($type, $prev);
  $rows = ($type === 'friends' && $user) ? game_leaderboard_friends((int)$user['id'], 'all', 50) : game_leaderboard($type, $prev, 50);
  $prizes = in_array($type, ['all', 'friends'], true) ? [] : array_values(array_filter(array_map('intval', explode(',', (string)setting($type === 'month' ? 'game_monthly_prizes' : 'game_weekly_prizes', ''))), function ($d) { return $d > 0; }));
  $mineRank = null;
  if ($user) { foreach ($rows as $i => $r) { if ((int)$r['user_id'] === (int)$user['id']) { $mineRank = $i + 1; } } } ?>
  <?php if ($per): ?>
    <p style="margin-bottom:10px"><strong><?= e($per['label']) ?></strong>
      &nbsp;<a href="<?= e(url('game.php?tab=' . $type . ($prev ? '' : '&prev=1'))) ?>"><?= $prev ? 'Show current' : 'Show previous' ?> <?= $type === 'month' ? 'month' : 'week' ?></a></p>
    <?php if ($prizes): ?><div class="note" style="margin-top:0"><strong>Prizes:</strong> <?php foreach ($prizes as $i => $d): ?>#<?= $i + 1 ?> = <?= (int)$d ?> free VIP days<?= $i < count($prizes) - 1 ? ', ' : '' ?><?php endforeach; ?>.
      You need at least <?= (int)setting('game_min_picks', '5') ?> settled picks to qualify. Prizes are added automatically after the <?= $type === 'month' ? 'month' : 'week' ?> ends.</div><?php endif; ?>
  <?php endif; ?>
  <?php if ($rows): ?>
  <div class="scroll"><table class="bm-table lb"><thead><tr><th>#</th><th>Player</th><th>Points</th><th>Correct picks</th></tr></thead><tbody>
  <?php foreach ($rows as $i => $r): $me = $user && (int)$r['user_id'] === (int)$user['id']; ?>
    <tr<?= $me ? ' style="background:rgba(212,175,55,.15)"' : '' ?>><td><strong><?= $i + 1 ?></strong><?= $i < 3 ? ' ' . ['&#129351;', '&#129352;', '&#129353;'][$i] : '' ?></td>
      <td><?= user_chip_html($r, mask_name((string)$r['name']) . ($me ? ' (you)' : ''), 26) ?></td><td><strong><?= (int)$r['points'] ?></strong></td><td><?= (int)$r['correct'] ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($user && $mineRank === null): ?><p style="color:var(--mute);margin-top:10px">You are not on this list yet. Make picks to earn points.</p><?php endif; ?>
  <?php else: ?><div class="note">Nobody has points in this period yet.</div><?php endif; ?>
<?php endif; ?>
  <div class="note">This game is free to play and uses no money. Prizes are free VIP days given by <?= e(SITE_NAME) ?>. 18+ only. Rules and prizes can change; abuse (many accounts) leads to removal.</div>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
