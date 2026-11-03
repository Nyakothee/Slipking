<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$u = current_user();
$ready = jackpot_ready();
$openRounds = $ready ? jackpot_open_rounds() : [];
$round = $openRounds ? $openRounds[0] : null;
if ($openRounds && !empty($_GET['r'])) { foreach ($openRounds as $orr) { if ((int)$orr['id'] === (int)$_GET['r']) { $round = $orr; } } }
$games = $round ? jackpot_games((int)$round['id']) : [];
$closed = $round ? jackpot_is_closed($round, $games) : false;
$entry = ($round && $u) ? jackpot_entry((int)$round['id'], (int)$u['id']) : null;

if ($round && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$u) { set_flash('error', 'Log in to enter the jackpot.'); redirect('auth/login.php?next=' . rawurlencode('jackpot.php')); }
    if (!csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('jackpot.php'); }
    if (($why = comment_block_reason($u)) !== '' && strpos($why, 'Confirm your email') === 0) { set_flash('error', 'Confirm your email address to enter the jackpot.'); redirect('jackpot.php'); }
    $err = jackpot_save_picks($round, $games, (int)$u['id'], (array)($_POST['pick'] ?? []));
    set_flash($err === '' ? 'success' : 'error', $err === '' ? 'Your ' . count($games) . ' picks are saved. Good luck! You can change them until entries close.' : $err);
    redirect('jackpot.php');
}

$count = 0; $dist = []; $top = [];
if ($round) {
    $q = db()->prepare('SELECT COUNT(*) FROM jackpot_entries WHERE round_id = ?'); $q->execute([$round['id']]); $count = (int)$q->fetchColumn();
    if ($closed) {
        jackpot_rescore((int)$round['id']);
        $dist = jackpot_distribution((int)$round['id']);
        $tq = db()->prepare('SELECT e.correct_n, u.name FROM jackpot_entries e JOIN users u ON u.id = e.user_id WHERE e.round_id = ? ORDER BY e.correct_n DESC, e.id ASC LIMIT 10');
        $tq->execute([$round['id']]); $top = $tq->fetchAll();
        $entry = ($u) ? jackpot_entry((int)$round['id'], (int)$u['id']) : null;
    }
}
$past = $ready ? db()->query("SELECT id, title, amount_kes, winners_n, prize_each, settled_at FROM jackpot_rounds WHERE status = 'settled' ORDER BY settled_at DESC LIMIT 8")->fetchAll() : [];
$closesTs = $round ? jackpot_closes_at($round, $games) : null;
$nGames = count($games);
$page_title = 'Jackpot - predict all ' . ($nGames ?: JACKPOT_GAMES) . ' games and win - ' . SITE_NAME;
$page_desc = 'Free to enter. Predict all ' . ($nGames ?: JACKPOT_GAMES) . ' games correctly and win the jackpot.';
$active = 'jackpot';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/jackpot.css')) . '?v=1">' . "\n";
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top jp">
<?php if (!$ready): ?><div class="note">The jackpot is not available yet.</div>
<?php elseif (!$round): ?>
  <div class="jp-hero"><div><h1>Jackpot</h1><p>No jackpot round is open right now. Check back soon for the next one.</p></div></div>
<?php else: ?>
  <div class="jp-hero">
    <div class="jp-cup">&#127942;</div>
    <div class="jp-ht"><span class="jp-tag"><?= e($round['title']) ?></span><h1><?= e(jackpot_kes($round['amount_kes'])) ?></h1>
      <p>Predict all <?= $nGames ?> games correctly to win the jackpot. Free to enter, one entry per member.<?= (float)$round['consolation_kes'] > 0 ? ' Get ' . ($nGames - 1) . ' right and win ' . e(jackpot_kes($round['consolation_kes'])) . '.' : '' ?></p></div>
    <div class="jp-side"><div class="jp-box"><b><?= (int)$count ?></b><span>entr<?= $count === 1 ? 'y' : 'ies' ?></span></div>
      <?php if ($closesTs && !$closed): ?><div class="jp-box"><b id="jp-cd" data-ts="<?= (int)$closesTs ?>">--</b><span>until entries close</span></div><?php endif; ?></div>
  </div>
  <?php if (count($openRounds) > 1): ?><div class="tabs" style="margin:10px 0"><?php foreach ($openRounds as $orr): ?><a href="<?= e(url('jackpot.php?r=' . (int)$orr['id'])) ?>"<?= (int)$orr['id'] === (int)$round['id'] ? ' class="on"' : '' ?>><?= e(jackpot_kind_label((string)($orr['kind'] ?? 'weekly'))) ?>: <?= e(jackpot_kes($orr['amount_kes'])) ?></a><?php endforeach; ?></div><?php endif; ?>
  <?php if (($round['kind'] ?? '') === 'mega' && $closesTs && !$closed): ?><div class="jp-card" style="text-align:center"><h3>&#127942; Mega jackpot closes in</h3><div class="jp-mega" id="jp-mega" data-ts="<?= (int)$closesTs ?>" style="font-size:34px;font-weight:800">--</div></div><?php endif; ?>
  <?php if (!empty($round['analysis'])): ?><div class="jp-card"><h3>Analysis</h3><div><?= nl2br(e($round['analysis'])) ?></div></div><?php endif; ?>
  <?php if ($round['note'] !== ''): ?><div class="note"><?= nl2br(e($round['note'])) ?></div><?php endif; ?>

  <?php if (!$closed): ?>
    <?php if ($entry): ?><div class="alert ok">&#10003; You are in! Your picks are saved. You can change them until entries close.</div><?php endif; ?>
    <form method="post" id="jp-form"><?= csrf_field() ?>
      <div class="jp-prog"><div class="jp-bar"><i id="jp-fill"></i></div><span><b id="jp-n">0</b> of <?= $nGames ?> picked</span></div>
      <div class="jp-games">
      <?php foreach ($games as $i => $g): $mine = $entry['picks'][(int)$g['id']] ?? ''; ?>
        <div class="jp-g" data-gid="<?= (int)$g['id'] ?>">
          <span class="jp-no"><?= $i + 1 ?></span>
          <div class="jp-t"><strong><?= e($g['home']) ?></strong><em>vs</em><strong><?= e($g['away']) ?></strong>
            <small><?= e($g['league']) ?><?= $g['league'] !== '' && $g['kickoff_at'] ? ' &middot; ' : '' ?><?= $g['kickoff_at'] ? e(date('D j M, H:i', strtotime($g['kickoff_at']))) : '' ?></small></div>
          <div class="jp-p" role="radiogroup" aria-label="Pick for game <?= $i + 1 ?>">
            <?php foreach (['1' => 'Home', 'X' => 'Draw', '2' => 'Away'] as $v => $lb): ?>
            <label title="<?= e($lb) ?>"><input type="radio" name="pick[<?= (int)$g['id'] ?>]" value="<?= $v ?>"<?= $mine === $v ? ' checked' : '' ?>><span><?= $v ?></span></label>
            <?php endforeach; ?></div>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="jp-submit">
        <?php if ($u): ?><button class="btn gold" type="submit"><?= $entry ? 'Update my picks' : 'Submit my ' . $nGames . ' picks' ?></button>
        <?php else: ?><a class="btn gold" href="<?= e(url('auth/login.php?next=' . rawurlencode('jackpot.php'))) ?>">Log in to enter</a> <a class="btn ghost-d" href="<?= e(url('auth/register.php')) ?>">Create a free account</a><?php endif; ?>
        <small>1 = home win, X = draw, 2 = away win (full time, 90 minutes). Free to enter. 18+. Winners are contacted and paid by <?= e(SITE_NAME) ?>.</small>
      </div>
    </form>
  <?php else: ?>
    <div class="alert"><strong>Entries are closed.</strong> Results are being added as games finish.</div>
    <div class="jp-games">
    <?php foreach ($games as $i => $g): $mine = $entry['picks'][(int)$g['id']] ?? ''; $res = $g['result'];
      $state = $res === null ? '' : ($mine === $res ? ' ok' : ($mine !== '' ? ' bad' : '')); ?>
      <div class="jp-g<?= $state ?>"><span class="jp-no"><?= $i + 1 ?></span>
        <div class="jp-t"><strong><?= e($g['home']) ?></strong><em>vs</em><strong><?= e($g['away']) ?></strong><small><?= $g['kickoff_at'] ? e(date('D j M, H:i', strtotime($g['kickoff_at']))) : '' ?></small></div>
        <div class="jp-r"><span>Your pick<b><?= $mine !== '' ? e($mine) : '-' ?></b></span><span>Result<b><?= $res !== null ? e($res) : '?' ?></b></span></div></div>
    <?php endforeach; ?></div>
    <?php if ($entry): ?><p class="jp-you">You have <b><?= (int)$entry['correct_n'] ?></b> of <?= $nGames ?> correct so far.</p><?php elseif ($u): ?><p class="jp-you">You did not enter this round.</p><?php endif; ?>
    <?php if ($dist): ?><div class="jp-card"><h3>How everyone is doing</h3><div class="jp-dist"><?php foreach ($dist as $n => $c): ?><span><b><?= (int)$n ?>/<?= $nGames ?></b> <?= (int)$c ?> member<?= (int)$c === 1 ? '' : 's' ?></span><?php endforeach; ?></div></div><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

  <?php if ($past): ?><div class="jp-card"><h3>Past jackpots <small><a href="<?= e(url('jackpot-results.php')) ?>">Full results tracker</a></small></h3><div class="scroll"><table class="tbl"><thead><tr><th>Round</th><th>Jackpot</th><th>Winners</th><th>Each won</th></tr></thead><tbody>
    <?php foreach ($past as $p): ?><tr><td><?= e($p['title']) ?><br><small style="color:var(--mute)"><?= e(date('j M Y', strtotime($p['settled_at']))) ?></small></td><td><?= e(jackpot_kes($p['amount_kes'])) ?></td><td><?= (int)$p['winners_n'] ?></td><td><?= (int)$p['winners_n'] ? e(jackpot_kes($p['prize_each'])) : '-' ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div>
<script>
(function(){
  var f=document.getElementById('jp-form');
  if(f){var n=document.getElementById('jp-n'),fill=document.getElementById('jp-fill'),tot=f.querySelectorAll('.jp-g').length;
    function upd(){var c=f.querySelectorAll('input[type=radio]:checked').length;n.textContent=c;fill.style.width=(tot?c/tot*100:0)+'%';}
    f.addEventListener('change',upd);upd();}
  var mg=document.getElementById('jp-mega');
  if(mg){var mts=parseInt(mg.getAttribute('data-ts'),10)*1000;(function mt(){var d=mts-Date.now();if(d<=0){mg.textContent='closed';return;}var s=Math.floor(d/1000),dd=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60),c=s%60;mg.textContent=(dd?dd+'d ':'')+h+'h '+m+'m '+c+'s';setTimeout(mt,1000);})();}
  var cd=document.getElementById('jp-cd');
  if(cd){var ts=parseInt(cd.getAttribute('data-ts'),10)*1000;
    function tick(){var d=ts-Date.now();if(d<=0){cd.textContent='closed';return;}var s=Math.floor(d/1000),dd=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);
      cd.textContent=(dd?dd+'d ':'')+h+'h '+m+'m';setTimeout(tick,30000);}
    tick();}
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
