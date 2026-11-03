<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$english_only = true;
$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 60);
$like = '%' . addcslashes($q, '%_\\') . '%';
$matches = $leagues = $tipsters = [];
if (mb_strlen($q) >= 2) {
    try {
        $sa = [];
        $st = $pdo->prepare("SELECT m.id, m.home_team, m.away_team, m.kickoff_at, l.name AS league FROM matches m JOIN leagues l ON l.id = m.league_id
                             WHERE " . search_like_sql(['m.home_team', 'm.away_team', 'l.name'], search_terms($q), $sa) . " ORDER BY ABS(TIMESTAMPDIFF(HOUR, m.kickoff_at, NOW())) ASC LIMIT 20");
        $st->execute($sa);
        $matches = $st->fetchAll();
        $st = $pdo->prepare('SELECT l.id, l.name, s.name AS sport FROM leagues l JOIN sports s ON s.id = l.sport_id WHERE l.name LIKE ? ORDER BY l.name LIMIT 10');
        $st->execute([$like]);
        $leagues = $st->fetchAll();
        $st = $pdo->prepare("SELECT * FROM tipsters WHERE status = 'active'" . tipster_live_sql('tipsters') . " AND display_name LIKE ? ORDER BY display_name LIMIT 10");
        $st->execute([$like]);
        $tipsters = $st->fetchAll();
    } catch (Throwable $e) { error_log('search: ' . $e->getMessage()); }
}
$page_title = ($q !== '' ? 'Search: ' . $q . ' - ' : 'Search - ') . SITE_NAME;
$head_extra = '<meta name="robots" content="noindex,follow">';
$active = '';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Search</h1>
  <form method="get" class="cm-box" style="display:flex;gap:10px;flex-wrap:wrap"><input type="search" name="q" value="<?= e($q) ?>" maxlength="60" placeholder="Team, league or tipster" style="flex:1;min-width:200px" autofocus><button class="btn dark" type="submit">Search</button></form>
  <?php if ($q !== '' && mb_strlen($q) < 2): ?><div class="note">Type at least 2 letters.</div>
  <?php elseif ($q !== '' && !$matches && !$leagues && !$tipsters): ?><div class="empty">Nothing found for "<?= e($q) ?>".</div><?php endif; ?>
  <?php if ($matches): ?><h2>Matches</h2><div class="cm-grid"><?php foreach ($matches as $m): ?>
    <a class="cm-box" style="margin:0;text-decoration:none;color:inherit" href="<?= e(url('match.php?id=' . (int)$m['id'])) ?>"><strong><?= e($m['home_team'] . ' vs ' . $m['away_team']) ?></strong><br><small style="color:var(--mute)"><?= e($m['league']) ?> &middot; <?= e(date('D j M Y, H:i', strtotime($m['kickoff_at']))) ?></small></a>
  <?php endforeach; ?></div><?php endif; ?>
  <?php if ($leagues): ?><h2 style="margin-top:22px">Leagues</h2><div class="cm-grid"><?php foreach ($leagues as $l): ?>
    <a class="cm-box" style="margin:0;text-decoration:none;color:inherit" href="<?= e(url('league.php?id=' . (int)$l['id'])) ?>"><strong><?= e($l['name']) ?></strong><br><small style="color:var(--mute)"><?= e($l['sport']) ?></small></a>
  <?php endforeach; ?></div><?php endif; ?>
  <?php if ($tipsters): ?><h2 style="margin-top:22px">Tipsters</h2><div class="cm-grid"><?php foreach ($tipsters as $t): ?>
    <a class="cm-box" style="margin:0;text-decoration:none;color:inherit" href="<?= e(tipster_url($t)) ?>"><strong><?= e($t['display_name']) ?></strong></a>
  <?php endforeach; ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
