<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
$u = current_user();
$ready = arena_ready();
$cats = arena_categories();
$season = arena_season();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$u) { redirect('auth/login.php?next=' . rawurlencode('arena.php')); }
    if (!$ready || !csrf_check()) { set_flash('error', 'Your session expired. Please try again.'); redirect('arena.php'); }
    if (($_POST['action'] ?? '') === 'start') {
        $id = arena_start((int)$u['id'], (string)($_POST['cat'] ?? 'all'));
        if (!$id) { set_flash('error', 'Not enough questions in that category yet.'); redirect('arena.php'); }
        redirect('arena.php?play=' . $id);
    }
    if (($_POST['action'] ?? '') === 'finish') {
        $id = (int)($_POST['attempt'] ?? 0);
        $att = arena_attempt($id, (int)$u['id']);
        if ($att) {
            $r = arena_finish($att, (array)($_POST['a'] ?? []));
            if ($r['late']) { set_flash('error', 'Time was up before your answers were received, so this round scored 0.'); }
        }
        redirect('arena.php?result=' . $id);
    }
}

$play = (int)($_GET['play'] ?? 0);
$result = (int)($_GET['result'] ?? 0);
$att = null; $questions = [];
if ($u && $ready && ($play || $result)) {
    $att = arena_attempt($play ?: $result, (int)$u['id']);
    if ($att && $play && $att['finished_at'] !== null) { redirect('arena.php?result=' . (int)$att['id']); }
    if ($att && $result && $att['finished_at'] === null) { redirect('arena.php?play=' . (int)$att['id']); }
    if ($att) { $questions = arena_questions($att, (bool)$result); }
}
$viewSeason = preg_match('#^\d{4}/\d{2}$#', (string)($_GET['season'] ?? '')) ? $_GET['season'] : $season;
$board = $ready ? arena_board($viewSeason) : [];
$page_title = 'Football Knowledge Arena - ' . SITE_NAME;
$page_desc = 'Timed football quizzes on leagues, clubs, players, history and rules with a seasonal leaderboard.';
$active = 'game';
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <h1>Football Knowledge Arena</h1>
  <?php if (!$ready): ?><div class="alert">The Arena is not available yet. Import database-update-19.sql in phpMyAdmin.</div>
  <?php elseif ($att && $play): $left = max(0, (int)$att['seconds_allowed'] - (int)$att['elapsed']); ?>
    <p>Answer everything before the timer ends. Your answers are checked on the server.</p>
    <div class="qz-timer">Time left: <span id="qz-t"><?= $left ?></span>s</div>
    <form method="post" id="qz-form"><?= csrf_field() ?><input type="hidden" name="action" value="finish"><input type="hidden" name="attempt" value="<?= (int)$att['id'] ?>">
      <?php foreach ($questions as $i => $q): ?>
      <div class="cm-box"><strong><?= $i + 1 ?>. <?= e($q['question']) ?></strong>
        <?php for ($o = 1; $o <= 4; $o++): ?><label class="qz-opt"><input type="radio" name="a[<?= (int)$q['id'] ?>]" value="<?= $o ?>"> <?= e($q['opt' . $o]) ?></label><?php endfor; ?></div>
      <?php endforeach; ?>
      <button class="btn dark" type="submit">Finish round</button>
    </form>
    <script>(function(){var t=<?= $left ?>,el=document.getElementById('qz-t'),f=document.getElementById('qz-form'),done=false;
      f.addEventListener('submit',function(){done=true;});
      var iv=setInterval(function(){t--;el.textContent=Math.max(t,0);if(t<=0&&!done){clearInterval(iv);done=true;f.submit();}},1000);})();</script>
  <?php elseif ($att && $result): ?>
    <div class="cm-box"><h2 style="margin-top:0">You scored <?= (int)$att['score'] ?> / <?= (int)$att['total'] ?></h2>
      <p style="color:var(--mute)">Season <?= e($att['season']) ?> &middot; <?= e($cats[$att['category']] ?? 'Mixed') ?> &middot; <?= (int)$att['seconds_used'] ?>s</p></div>
    <?php
    $given = []; // answers are not stored, so the review shows the right answers only
    foreach ($questions as $i => $q): ?>
      <div class="cm-box"><strong><?= $i + 1 ?>. <?= e($q['question']) ?></strong>
        <?php for ($o = 1; $o <= 4; $o++): ?><div class="qz-opt<?= $o === (int)$q['correct'] ? ' ok' : '' ?>"><?= e($q['opt' . $o]) ?></div><?php endfor; ?></div>
    <?php endforeach; ?>
    <p><a class="btn dark" href="<?= e(url('arena.php')) ?>">Play again</a></p>
  <?php else: ?>
    <p style="color:var(--mute);max-width:700px"><?= ARENA_QUESTIONS ?> random questions per round, <?= ARENA_SECONDS_PER_Q ?> seconds each. Every correct answer earns a point on the <?= e($season) ?> season leaderboard.</p>
    <form method="post" class="cm-box"><?= csrf_field() ?><input type="hidden" name="action" value="start">
      <div class="field"><label for="qc">Category</label><select id="qc" name="cat"><?php foreach ($cats as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <?php if ($u): ?><button class="btn dark" type="submit">Start round</button><?php else: ?><a class="btn dark" href="<?= e(url('auth/login.php?next=arena.php')) ?>">Log in to play</a><?php endif; ?>
    </form>
  <?php endif; ?>
  <?php if ($ready && !$att): ?>
  <h2 style="margin-top:24px">Season <?= e($viewSeason) ?> leaderboard</h2>
  <p><?php $prev = arena_season(strtotime('-1 year')); ?><a href="<?= e(url('arena.php?season=' . rawurlencode($season))) ?>">This season</a> &middot; <a href="<?= e(url('arena.php?season=' . rawurlencode($prev))) ?>">Last season</a></p>
  <?php if ($board): ?><div class="scroll"><table class="bm-table"><thead><tr><th>#</th><th>Player</th><th>Rounds</th><th>Correct</th><th>Accuracy</th></tr></thead><tbody>
    <?php foreach ($board as $i => $r): ?><tr><td><?= $i + 1 ?></td><td><?= e($r['name']) ?></td><td><?= (int)$r['rounds'] ?></td><td><?= (int)$r['pts'] ?></td><td><?= $r['tot'] ? round($r['pts'] / $r['tot'] * 100) : 0 ?>%</td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><div class="note">No finished rounds this season yet.</div><?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
