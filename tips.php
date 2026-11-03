<?php
require_once __DIR__ . '/includes/init.php';

$vip    = is_vip();
$sports = db()->query('SELECT name, slug FROM sports ORDER BY id')->fetchAll();

$sport = $_GET['sport'] ?? '';
if (!in_array($sport, array_column($sports, 'slug'), true)) {
    $sport = '';
}
$days   = ['yesterday' => '-1 day', 'today' => 'today', 'tomorrow' => '+1 day'];
$todayD = date('Y-m-d');
$minD   = date('Y-m-d', strtotime('-14 days'));
$maxD   = date('Y-m-d', strtotime('+14 days'));
// A chosen calendar date (two weeks back to two weeks ahead); the old ?day=yesterday|tomorrow links still work
$date = $todayD;
if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date']) && $_GET['date'] >= $minD && $_GET['date'] <= $maxD && strtotime($_GET['date'])) {
    $date = (string)$_GET['date'];
} elseif (isset($days[$_GET['day'] ?? ''])) {
    $date = date('Y-m-d', strtotime($days[$_GET['day']]));
}
$day = $date === $todayD ? 'today' : ($date === date('Y-m-d', strtotime('+1 day')) ? 'tomorrow' : ($date === date('Y-m-d', strtotime('-1 day')) ? 'yesterday' : 'other'));
$market = (($_GET['market'] ?? '') === 'acca') ? 'Acca' : '';

$tips     = fetch_tips(['date' => $date, 'sport' => $sport, 'market' => $market, 'limit' => 100]);
$vipCount = count(array_filter($tips, function ($t) { return $t['is_vip']; }));

$dayWord = $day === 'other' ? date('D j M', strtotime($date)) : ucfirst($day);
$page_title = ($day === 'other' ? 'Betting tips for ' . $dayWord : $dayWord . "'s betting tips and predictions") . ' - ' . SITE_NAME;
$page_desc  = 'Free and VIP predictions for ' . date('j F Y', strtotime($date)) . ', with odds, confidence and analysis.';
$active     = 'tips';
// number of tips per day for the calendar (same sport / market filter as the list)
$counts = [];
try {
    $cq = "SELECT DATE(m.kickoff_at) AS d, COUNT(*) AS n FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
           WHERE t.deleted_at IS NULL AND t.tipster_id IS NULL AND DATE(m.kickoff_at) BETWEEN ? AND ?";
    $ca = [$minD, $maxD];
    if ($sport !== '') { $cq .= ' AND s.slug = ?'; $ca[] = $sport; }
    if ($market !== '') { $cq .= ' AND t.market = ?'; $ca[] = $market; }
    $cq .= ' GROUP BY DATE(m.kickoff_at)';
    $cs = db()->prepare($cq); $cs->execute($ca);
    foreach ($cs->fetchAll() as $r) { $counts[$r['d']] = (int)$r['n']; }
} catch (Throwable $e) { $counts = []; }
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/tips-calendar.css')) . '?v=1">' . "\n";
$prevD = $date > $minD ? date('Y-m-d', strtotime($date . ' -1 day')) : null;
$nextD = $date < $maxD ? date('Y-m-d', strtotime($date . ' +1 day')) : null;
$dl = function (string $d) { return qs_url('tips.php', ['date' => $d === date('Y-m-d') ? null : $d, 'day' => null]); };
include __DIR__ . '/includes/header.php';
?>
<?php if ($wb = widgets_block('tips')): ?><div class="wrap" style="padding-top:16px"><?= $wb ?></div><?php endif; ?>
<section>
  <div class="wrap">
    <div class="head">
      <div>
        <h2><?= $day === 'today' ? "Today's tips" : ($day === 'tomorrow' ? "Tomorrow's tips" : ($day === 'yesterday' ? "Yesterday's tips" : 'Tips for ' . e(date('D j M', strtotime($date))))) ?></h2>
        <p><?= e(date('l j F Y', strtotime($date))) ?> &middot; <?= count($tips) ?> tips (<?= count($tips) - $vipCount ?> free, <?= $vipCount ?> VIP)</p>
      </div>
      <button type="button" class="btn dark small" data-copyall data-title="<?= e(($day === 'today' ? "Today's tips" : 'Tips') . ' - ' . date('D j M', strtotime($date)) . ' - ' . SITE_NAME) ?>">Copy all tips</button>
    </div>

    <div class="filters">
      <div class="tc-row">
        <div class="tabs" aria-label="Day">
          <?php foreach (['yesterday' => 'Yesterday', 'today' => 'Today', 'tomorrow' => 'Tomorrow'] as $k => $label): ?>
            <a href="<?= e($dl(date('Y-m-d', strtotime($days[$k])))) ?>"<?= $k === $day ? ' class="on" aria-current="true"' : '' ?>><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
        <details class="tc-dd<?= $day === 'other' ? ' picked' : '' ?>" id="tc-dd">
          <summary><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="3"/><path d="M8 2v4M16 2v4M3 10h18"/></svg><span><?= $day === 'other' ? e(date('D j M', strtotime($date))) : 'Choose date' ?></span><svg class="tc-car" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>
      <div class="tc" aria-label="Choose a day">
        <div class="tc-top">
          <?php if ($prevD): ?><a class="tc-nav" href="<?= e($dl($prevD)) ?>" aria-label="Previous day">&#8249;</a><?php else: ?><span class="tc-nav off">&#8249;</span><?php endif; ?>
          <strong><?= e(date('D j F Y', strtotime($date))) ?></strong>
          <?php if ($nextD): ?><a class="tc-nav" href="<?= e($dl($nextD)) ?>" aria-label="Next day">&#8250;</a><?php else: ?><span class="tc-nav off">&#8250;</span><?php endif; ?>
          <?php if ($date !== $todayD): ?><a class="tc-today" href="<?= e($dl($todayD)) ?>">Today</a><?php endif; ?>
        </div>
        <div class="tc-grid" role="grid">
          <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $w): ?><span class="tc-w"><?= $w ?></span><?php endforeach; ?>
          <?php
            $start = strtotime($minD); $start -= ((int)date('N', $start) - 1) * 86400;      // Monday of the first week
            $end = strtotime($maxD); $end += (7 - (int)date('N', $end)) * 86400;           // Sunday of the last week
            for ($t = $start; $t <= $end; $t += 86400):
                $d = date('Y-m-d', $t); $in = ($d >= $minD && $d <= $maxD); $n = $counts[$d] ?? 0;
                $first = ($d === $minD || date('j', $t) === '1');
                $cls = 'tc-d' . (!$in ? ' out' : '') . ($d === $todayD ? ' today' : '') . ($d === $date ? ' sel' : '') . ($n ? ' has' : '') . ($in && $d < $todayD ? ' past' : '');
          ?>
            <?php if ($in): ?><a class="<?= $cls ?>" href="<?= e($dl($d)) ?>"<?= $d === $date ? ' aria-current="date"' : '' ?> title="<?= e(date('D j M', $t)) ?>: <?= $n ?> tip<?= $n === 1 ? '' : 's' ?>">
              <?php if ($first): ?><em><?= e(date('M', $t)) ?></em><?php endif; ?><b><?= (int)date('j', $t) ?></b><?php if ($n): ?><i><?= $n ?></i><?php endif; ?></a>
            <?php else: ?><span class="<?= $cls ?>" aria-hidden="true"></span><?php endif; ?>
          <?php endfor; ?>
        </div>
        <div class="tc-key"><span><i class="k today"></i>Today</span><span><i class="k has"></i>Has tips</span><span>Two weeks back and two weeks ahead</span></div>
      </div>
        </details>
      </div>
      <div class="tabs" aria-label="Sport">
        <a href="<?= e(qs_url('tips.php', ['sport' => null, 'market' => null])) ?>"<?= ($sport === '' && $market === '') ? ' class="on"' : '' ?>>All sports</a>
        <?php foreach ($sports as $s): ?>
          <a href="<?= e(qs_url('tips.php', ['sport' => $s['slug'], 'market' => null])) ?>"<?= $s['slug'] === $sport ? ' class="on"' : '' ?>><?= e($s['name']) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(qs_url('tips.php', ['market' => 'acca', 'sport' => null])) ?>"<?= $market ? ' class="on"' : '' ?>>Accumulators</a>
      </div>
    </div>

    <?php if ($tips): ?>
      <div class="grid"><?php foreach ($tips as $t) { tip_card($t, $vip); } ?></div>
    <?php else: ?>
      <div class="empty">No tips for this day and filter yet. Try another day, or check back later.</div>
    <?php endif; ?>

    <?php if (!$vip && $vipCount): ?>
      <div class="note"><span>VIP tips locked: <?= $vipCount ?>.</span> <a href="<?= e(url('vip.php')) ?>" style="font-weight:700;text-decoration:underline">See VIP plans</a> <span>Once a match is settled, the pick becomes public in the results archive.</span></div>
    <?php endif; ?>
  </div>
</section>
<script>(function(){var d=document.getElementById('tc-dd');if(!d)return;
document.addEventListener('click',function(e){if(d.open&&!d.contains(e.target))d.open=false;});
document.addEventListener('keydown',function(e){if(e.key==='Escape')d.open=false;});})();</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
