<?php
require_once __DIR__ . '/includes/init.php';

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$sports  = db()->query('SELECT name, slug FROM sports ORDER BY id')->fetchAll();

$sport = $_GET['sport'] ?? '';
if (!in_array($sport, array_column($sports, 'slug'), true)) {
    $sport = '';
}
$status = $_GET['status'] ?? '';
if (!in_array($status, ['won', 'lost', 'void'], true)) {
    $status = '';
}

// Archive = every settled tip, plus any tip whose match has started but has no result yet.
// Settled VIP picks are shown to everyone: the match is over, so the pick is proof of the record.
$from = 'FROM tips t
         JOIN matches m ON m.id = t.match_id
         JOIN leagues l ON l.id = m.league_id
         JOIN sports s ON s.id = l.sport_id
         WHERE (t.status <> \'pending\' OR m.kickoff_at < NOW())
           AND t.tipster_id IS NULL
           AND (t.deleted_at IS NULL OR t.status IN (\'won\',\'lost\'))';
$args = [];
if ($sport !== '') {
    $from .= ' AND s.slug = ?';
    $args[] = $sport;
}
if ($status !== '') {
    $from .= ' AND t.status = ?';
    $args[] = $status;
}

$st = db()->prepare('SELECT COUNT(*) ' . $from);
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);

$st = db()->prepare('SELECT t.*, m.home_team, m.away_team, m.kickoff_at, l.name AS league ' . $from .
    ' ORDER BY m.kickoff_at DESC, t.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
$st->execute($args);
$rows = $st->fetchAll();

$m30 = record_stats(30);
$all = record_stats(null);

$page_title = 'Results archive and track record - ' . SITE_NAME;
$page_desc  = 'Every tip we have posted, with the result. Win rate, average odds and profit in units.';
$active     = 'results';
include __DIR__ . '/includes/header.php';
?>
<?php if ($wb = widgets_block('results')): ?><div class="wrap" style="padding-top:16px"><?= $wb ?></div><?php endif; ?>
<section>
  <div class="wrap">
    <div class="head"><div><h2>Results archive</h2><p>Every tip, in the order the matches were played.</p></div><a class="btn dark" href="<?= e(url('performance.php')) ?>">Performance</a></div>

    <div class="stats">
      <div class="stat"><b><?= $m30['win_rate'] !== null ? (int)$m30['win_rate'] . '%' : '-' ?></b><span>win rate, last 30 days (<?= (int)$m30['settled'] ?> tips)</span></div>
      <div class="stat"><b><?= e($m30['units']) ?></b><span>units profit, last 30 days</span></div>
      <div class="stat"><b><?= $all['win_rate'] !== null ? (int)$all['win_rate'] . '%' : '-' ?></b><span>win rate, all time (<?= (int)$all['settled'] ?> tips)</span></div>
      <div class="stat"><b><?= e($all['units']) ?></b><span>units profit, all time</span></div>
    </div>
    <p style="color:var(--mute);font-size:14px;margin:-6px 0 22px">Profit is counted as 1 unit staked on every tip at the odds shown: a win returns odds minus 1, a loss costs 1. Void tips count as 0. Real returns depend on the odds you get.</p>

    <div class="filters">
      <div class="tabs">
        <a href="<?= e(qs_url('results.php', ['status' => null, 'page' => null])) ?>"<?= $status === '' ? ' class="on"' : '' ?>>All results</a>
        <a href="<?= e(qs_url('results.php', ['status' => 'won', 'page' => null])) ?>"<?= $status === 'won' ? ' class="on"' : '' ?>>Won</a>
        <a href="<?= e(qs_url('results.php', ['status' => 'lost', 'page' => null])) ?>"<?= $status === 'lost' ? ' class="on"' : '' ?>>Lost</a>
        <a href="<?= e(qs_url('results.php', ['status' => 'void', 'page' => null])) ?>"<?= $status === 'void' ? ' class="on"' : '' ?>>Void</a>
      </div>
      <div class="tabs">
        <a href="<?= e(qs_url('results.php', ['sport' => null, 'page' => null])) ?>"<?= $sport === '' ? ' class="on"' : '' ?>>All sports</a>
        <?php foreach ($sports as $s): ?>
          <a href="<?= e(qs_url('results.php', ['sport' => $s['slug'], 'page' => null])) ?>"<?= $s['slug'] === $sport ? ' class="on"' : '' ?>><?= e($s['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($rows): ?>
      <div class="scroll">
        <table class="tbl">
          <thead><tr><th>Date</th><th>Match</th><th>Pick</th><th>Odds</th><th>Result</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e(date('j M Y', strtotime($r['kickoff_at']))) ?></td>
              <td><a href="<?= e(url('tip.php?id=' . (int)$r['id'])) ?>"><?= e($r['home_team']) ?> vs <?= e($r['away_team']) ?></a><br><small style="color:var(--mute)"><?= e($r['league']) ?></small></td>
              <td><?= e($r['pick']) ?><?= $r['is_vip'] ? ' <span class="vipflag">VIP</span>' : '' ?></td>
              <td><?= e(number_format((float)$r['odds'], 2)) ?></td>
              <td><span class="badge <?= e($r['status']) ?>"><?= $r['status'] === 'pending' ? 'Awaiting result' : e(ucfirst($r['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= pager($page, $pages, 'results.php') ?>
    <?php else: ?>
      <div class="empty">No results match this filter yet.</div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
