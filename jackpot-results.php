<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$u = current_user();
$ready = jackpot_ready();
if ($ready) { jackpot_cols(); }
$rounds = $ready ? db()->query("SELECT * FROM jackpot_rounds WHERE status IN ('settled') OR (status = 'open' AND closes_at < NOW()) ORDER BY id DESC LIMIT 12")->fetchAll() : [];
$page_title = 'Jackpot results tracker - ' . SITE_NAME;
$page_desc = 'Every jackpot round: the games, the results, how many got it right and what you scored.';
$active = 'jackpot';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/jackpot.css')) . '?v=1">' . "\n";
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top jp">
  <div class="jp-hero"><div><h1>Jackpot results</h1><p>Games, results and winners of every round. <a href="<?= e(url('jackpot.php')) ?>">Back to the jackpot</a></p></div></div>
  <?php foreach ($rounds as $r): $games = jackpot_games((int)$r['id']);
    $mine = $u ? jackpot_entry((int)$r['id'], (int)$u['id']) : null; $dist = jackpot_distribution((int)$r['id']); ?>
    <div class="jp-card"><h3><?= e($r['title']) ?> <small>(<?= e(jackpot_kind_label((string)($r['kind'] ?? 'weekly'))) ?>, <?= e(jackpot_kes($r['amount_kes'])) ?>)</small></h3>
      <?php if ($mine): ?><p class="jp-you">You scored <b><?= (int)$mine['correct_n'] ?></b> of <?= count($games) ?>.<?= (float)$mine['prize_kes'] > 0 ? ' Prize: ' . e(jackpot_kes($mine['prize_kes'])) : '' ?></p><?php endif; ?>
      <div class="scroll"><table class="tbl"><tr><th>#</th><th>Game</th><th>Result</th><?php if ($mine): ?><th>You</th><?php endif; ?></tr>
      <?php foreach ($games as $i => $g): $p = $mine['picks'][(int)$g['id']] ?? ''; ?>
        <tr><td><?= $i + 1 ?></td><td><?= e($g['home']) ?> vs <?= e($g['away']) ?></td><td><b><?= $g['result'] !== null ? e($g['result']) : '?' ?></b></td>
          <?php if ($mine): ?><td style="color:<?= $g['result'] === null ? 'inherit' : ($p === $g['result'] ? '#43a047' : '#e53935') ?>"><?= e($p ?: '-') ?></td><?php endif; ?></tr>
      <?php endforeach; ?></table></div>
      <?php if ($dist): ?><div class="jp-dist"><?php foreach ($dist as $n => $c): ?><span><b><?= (int)$n ?>/<?= count($games) ?></b> <?= (int)$c ?> member<?= $c == 1 ? '' : 's' ?></span><?php endforeach; ?></div><?php endif; ?>
      <?php if ((int)$r['winners_n'] > 0): ?><p>Winners: <b><?= (int)$r['winners_n'] ?></b>, <?= e(jackpot_kes($r['prize_each'])) ?> each.</p><?php endif; ?>
    </div>
  <?php endforeach; if (!$rounds): ?><div class="note">No finished rounds yet.</div><?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
