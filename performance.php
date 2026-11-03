<?php
require_once __DIR__ . '/includes/init.php';

$periods = ['30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 12 months', 'all' => 'All time'];
$p = $_GET['p'] ?? '90';
if (!isset($periods[$p])) { $p = '90'; }
$where = $p === 'all' ? '' : ' AND t.posted_at >= NOW() - INTERVAL ' . (int)$p . ' DAY';

/** Settled tips grouped by $expr. Deleted tips are included, so the record cannot be tidied up. */
function perf(string $expr, string $where, string $order = 'n DESC'): array
{
    return db()->query("SELECT $expr AS label, COUNT(*) AS n, SUM(t.status = 'won') AS w, SUM(t.status = 'lost') AS l, AVG(t.odds) AS ao,
                               SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 WHEN t.status = 'lost' THEN -1 ELSE 0 END) AS units
                        FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id JOIN sports s ON s.id = l.sport_id
                        WHERE t.status IN ('won','lost') AND t.tipster_id IS NULL $where GROUP BY label ORDER BY $order LIMIT 40")->fetchAll();
}
$groups = [
    'By sport'    => perf('s.name', $where),
    'By league'   => perf('l.name', $where),
    'By market'   => perf('t.market', $where),
    'Free vs VIP' => perf("CASE WHEN t.is_vip = 1 THEN 'VIP' ELSE 'Free' END", $where),
    'By month'    => perf("DATE_FORMAT(t.posted_at, '%Y-%m')", $where, 'label DESC'),
];
$total = record_stats($p === 'all' ? null : (int)$p);

// Monthly chart data: the last 12 months, always (independent of the period tabs)
$mrows = db()->query("SELECT DATE_FORMAT(t.posted_at, '%Y-%m') AS ym, SUM(t.status = 'won') AS w, SUM(t.status = 'lost') AS l,
                             SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 WHEN t.status = 'lost' THEN -1 ELSE 0 END) AS u
                      FROM tips t WHERE t.status IN ('won','lost') AND t.tipster_id IS NULL AND t.posted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
                      GROUP BY ym")->fetchAll(PDO::FETCH_UNIQUE);
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("first day of -$i month"));
    $r  = $mrows[$ym] ?? null;
    $n  = $r ? (int)$r['w'] + (int)$r['l'] : 0;
    $months[] = ['label' => date('m/y', strtotime($ym . '-01')), 'n' => $n, 'rate' => $n ? (int)round($r['w'] / $n * 100) : null, 'u' => $r ? round((float)$r['u'], 1) : 0.0];
}
$hasChart = array_sum(array_column($months, 'n')) > 0;
// chart geometry
$W = 720; $H = 230; $padL = 42; $padT = 14; $padB = 30; $ph = $H - $padT - $padB; $pw = $W - $padL - 10; $step = $pw / 12; $bw = $step * 0.62;
$maxU = 1.0;
foreach ($months as $m) { $maxU = max($maxU, abs($m['u'])); }

$page_title = 'Performance by sport, league and market - ' . SITE_NAME;
$page_desc  = 'Our win rate and profit broken down by sport, league, market and month. Every settled tip is counted.';
$active = 'results';
include __DIR__ . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Performance</h2><p>Where our tips do well and where they do not. Every settled tip is counted, wins and losses.</p></div>
      <a class="btn dark" href="<?= e(url('results.php')) ?>">Full results</a></div>
    <div class="tabs" style="margin-bottom:18px">
      <?php foreach ($periods as $k => $label): ?><a href="<?= e(url('performance.php?p=' . $k)) ?>"<?= $p === (string)$k ? ' class="on"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
    </div>
    <div class="stats">
      <div class="stat"><b><?= $total['win_rate'] !== null ? (int)$total['win_rate'] . '%' : '-' ?></b><span>win rate (<?= (int)$total['settled'] ?> tips)</span></div>
      <div class="stat"><b><?= e($total['units']) ?></b><span>units profit</span></div>
      <div class="stat"><b><?= e($total['avg_odds']) ?></b><span>average odds</span></div>
    </div>
    <p style="color:var(--mute);font-size:14px;margin-bottom:18px">Profit is counted as 1 unit staked on every tip at the odds shown. With fewer than 10 tips a result can be luck, so treat small samples with care.</p>

    <div class="panel">
      <h2>Monthly win rate</h2>
      <?php if ($hasChart): ?>
      <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" role="img" aria-label="Win rate by month" style="max-width:100%;height:auto;display:block">
        <?php foreach ([0, 50, 100] as $g): $y = $padT + $ph - $g / 100 * $ph; ?>
          <line x1="<?= $padL ?>" x2="<?= $W - 10 ?>" y1="<?= round($y, 1) ?>" y2="<?= round($y, 1) ?>" stroke="#dfe3dc" stroke-width="1"<?= $g === 50 ? ' stroke-dasharray="4 4"' : '' ?>/>
          <text x="<?= $padL - 6 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end" font-size="11" fill="#5f6b65" font-family="Arial,sans-serif" direction="ltr"><?= $g ?>%</text>
        <?php endforeach; ?>
        <?php foreach ($months as $i => $m): $x = $padL + $i * $step + ($step - $bw) / 2; ?>
          <?php if ($m['rate'] !== null): $bh = max(2, $m['rate'] / 100 * $ph); $by = $padT + $ph - $bh; ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($by, 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round($bh, 1) ?>" rx="3" fill="<?= $m['rate'] >= 50 ? '#188a4a' : '#c8372d' ?>"><title><?= e($m['label']) ?>: <?= (int)$m['rate'] ?>% (<?= (int)$m['n'] ?>)</title></rect>
            <text x="<?= round($x + $bw / 2, 1) ?>" y="<?= round($by - 4, 1) ?>" text-anchor="middle" font-size="11" font-weight="700" fill="#17201c" font-family="Arial,sans-serif" direction="ltr"><?= (int)$m['rate'] ?>%</text>
          <?php endif; ?>
          <text x="<?= round($x + $bw / 2, 1) ?>" y="<?= $H - 10 ?>" text-anchor="middle" font-size="11" fill="#5f6b65" font-family="Arial,sans-serif" direction="ltr"><?= e($m['label']) ?></text>
        <?php endforeach; ?>
      </svg>
      <?php else: ?><p style="color:var(--mute)">No settled tips in the last 12 months yet.</p><?php endif; ?>
    </div>

    <div class="panel">
      <h2>Monthly profit (units)</h2>
      <?php if ($hasChart): $zero = $padT + $ph / 2; $half = $ph / 2 - 16; ?>
      <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" role="img" aria-label="Profit in units by month" style="max-width:100%;height:auto;display:block">
        <line x1="<?= $padL ?>" x2="<?= $W - 10 ?>" y1="<?= round($zero, 1) ?>" y2="<?= round($zero, 1) ?>" stroke="#5f6b65" stroke-width="1.5"/>
        <text x="<?= $padL - 6 ?>" y="<?= round($zero + 4, 1) ?>" text-anchor="end" font-size="11" fill="#5f6b65" font-family="Arial,sans-serif" direction="ltr">0</text>
        <?php foreach ($months as $i => $m): $x = $padL + $i * $step + ($step - $bw) / 2; ?>
          <?php if ($m['n'] > 0): $bh = max(2, abs($m['u']) / $maxU * $half); $up = $m['u'] >= 0; $by = $up ? $zero - $bh : $zero; ?>
            <rect x="<?= round($x, 1) ?>" y="<?= round($by, 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round($bh, 1) ?>" rx="3" fill="<?= $up ? '#188a4a' : '#c8372d' ?>"><title><?= e($m['label']) ?>: <?= ($m['u'] > 0 ? '+' : '') . e(number_format($m['u'], 1)) ?></title></rect>
            <text x="<?= round($x + $bw / 2, 1) ?>" y="<?= round($up ? $by - 4 : $by + $bh + 12, 1) ?>" text-anchor="middle" font-size="11" font-weight="700" fill="#17201c" font-family="Arial,sans-serif" direction="ltr"><?= ($m['u'] > 0 ? '+' : '') . e(number_format($m['u'], 1)) ?></text>
          <?php endif; ?>
          <text x="<?= round($x + $bw / 2, 1) ?>" y="<?= $H - 10 ?>" text-anchor="middle" font-size="11" fill="#5f6b65" font-family="Arial,sans-serif" direction="ltr"><?= e($m['label']) ?></text>
        <?php endforeach; ?>
      </svg>
      <p style="color:var(--mute);font-size:14px;margin-top:8px">Last 12 months. Months with no settled tips are left empty.</p>
      <?php else: ?><p style="color:var(--mute)">No settled tips in the last 12 months yet.</p><?php endif; ?>
    </div>

    <?php foreach ($groups as $title => $rows): ?>
      <div class="panel">
        <h2><?= e($title) ?></h2>
        <?php if ($rows): ?>
        <div class="scroll"><table class="tbl">
          <thead><tr><th>Group</th><th>Tips</th><th>Won</th><th>Lost</th><th>Win rate</th><th>Average odds</th><th>Profit (units)</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): $n = (int)$r['w'] + (int)$r['l']; $rate = $n ? (int)round($r['w'] / $n * 100) : 0; $u = round((float)$r['units'], 1); ?>
            <tr>
              <td><?= e($r['label']) ?><?= $n < 10 ? ' <small style="color:var(--mute)">(small sample)</small>' : '' ?></td>
              <td><?= $n ?></td><td><?= (int)$r['w'] ?></td><td><?= (int)$r['l'] ?></td>
              <td style="min-width:130px"><div style="background:var(--paper);border-radius:6px;overflow:hidden"><div style="background:<?= $rate >= 50 ? 'var(--win)' : 'var(--loss)' ?>;color:#fff;font-weight:700;font-size:13px;padding:2px 8px;width:<?= max(12, $rate) ?>%"><?= $rate ?>%</div></div></td>
              <td><?= e(number_format((float)$r['ao'], 2)) ?></td>
              <td style="color:<?= $u >= 0 ? 'var(--win)' : 'var(--loss)' ?>;font-weight:700"><?= ($u > 0 ? '+' : '') . e(number_format($u, 1)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        <?php else: ?><p style="color:var(--mute)">No settled tips in this period yet.</p><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
