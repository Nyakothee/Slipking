<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/tip-bulk-lib.php';
$t = require_tipster(false);
$pdo = db();
$isActive = $t['status'] === 'active';
const TIPSTER_DAILY_TIPS = 15;
$leagues = $pdo->query('SELECT l.id, l.name, s.name AS sport FROM leagues l JOIN sports s ON s.id = l.sport_id ORDER BY s.id, l.name')->fetchAll();
$errors = [];
$f = ['league_id' => $leagues[0]['id'] ?? 0, 'home' => '', 'away' => '', 'kickoff' => date('Y-m-d\TH:i', strtotime('+3 hours')),
      'market' => '', 'pick' => '', 'odds' => '', 'confidence' => 3, 'is_vip' => 1, 'analysis' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    elseif (!$isActive) { $errors[] = 'You can post tips once your profile is approved.'; }
    elseif ($action === 'delete') {
        $pd = $pdo->prepare('UPDATE tips SET pending_review = 0 WHERE id = ? AND author_id = ? AND pending_review = 1');
        $pd->execute([(int)($_POST['id'] ?? 0), $t['id']]);
        if ($pd->rowCount()) { set_flash('success', 'Submission withdrawn.'); redirect('tipster/tips.php'); }
        // Only before kickoff and only while pending. After that the tip stays as part of your public record.
        $st = $pdo->prepare("UPDATE tips t JOIN matches m ON m.id = t.match_id SET t.deleted_at = NOW()
                             WHERE t.id = ? AND t.tipster_id = ? AND t.status = 'pending' AND t.deleted_at IS NULL AND m.kickoff_at > NOW()");
        $st->execute([(int)($_POST['id'] ?? 0), $t['id']]);
        if ($st->rowCount()) {
            $ti = $pdo->prepare('SELECT market, pick, odds FROM tips WHERE id = ?'); $ti->execute([(int)($_POST['id'] ?? 0)]);
            if ($row = $ti->fetch()) tip_change_log((int)($_POST['id'] ?? 0), (int)$t['id'], 'cancelled', 'tipster', 'Removed by the tipster before kickoff: ' . $row['market'] . ' / ' . $row['pick'] . ' @ ' . number_format((float)$row['odds'], 2));
        }
        set_flash($st->rowCount() ? 'success' : 'error', $st->rowCount() ? 'Tip removed. Removed tips still count in your record.' : 'That tip can no longer be removed.');
        redirect('tipster/tips.php');
    } else {
        $f['league_id'] = (int)($_POST['league_id'] ?? 0);
        foreach (['home', 'away', 'kickoff', 'market', 'pick', 'odds'] as $k) { $f[$k] = post_str($k); }
        $f['confidence'] = (int)($_POST['confidence'] ?? 3);
        $f['is_vip'] = !empty($_POST['is_vip']) ? 1 : 0;
        $toPlatform = !empty($t['is_contributor']) && !empty($_POST['platform']);
        $f['analysis'] = trim((string)($_POST['analysis'] ?? ''));
        if (!in_array($f['league_id'], array_column($leagues, 'id'), true)) $errors[] = 'Choose a league.';
        if (mb_strlen($f['home']) < 2 || mb_strlen($f['home']) > 80 || mb_strlen($f['away']) < 2 || mb_strlen($f['away']) > 80) $errors[] = 'Enter both team names (2 to 80 characters each).';
        elseif (mb_strtolower($f['home']) === mb_strtolower($f['away'])) $errors[] = 'The two teams must be different.';
        $ts = strtotime($f['kickoff']);
        if (!$ts) $errors[] = 'Enter a valid kickoff date and time.';
        elseif ($ts <= time() + 600) $errors[] = 'Kickoff must be at least 10 minutes away. Tips are posted before the match, never after.';
        elseif ($ts > time() + 14 * 86400) $errors[] = 'Kickoff is too far ahead (14 days maximum).';
        if ($f['market'] === '' || mb_strlen($f['market']) > 40) $errors[] = 'Enter the market (for example Over/Under).';
        if ($f['pick'] === '' || mb_strlen($f['pick']) > 120) $errors[] = 'Enter the pick (up to 120 characters).';
        if (!is_numeric($f['odds']) || (float)$f['odds'] < 1.01 || (float)$f['odds'] > 100) $errors[] = 'Odds must be between 1.01 and 100.';
        if ($f['confidence'] < 1 || $f['confidence'] > 5) $errors[] = 'Confidence must be 1 to 5.';
        if (mb_strlen($f['analysis']) > 1500) $errors[] = 'Analysis is too long (1500 characters).';
        if (!$errors) {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM tips WHERE (tipster_id = ? OR author_id = ?) AND posted_at > NOW() - INTERVAL 1 DAY');
            $cnt->execute([$t['id'], $t['id']]);
            if ((int)$cnt->fetchColumn() >= TIPSTER_DAILY_TIPS) $errors[] = 'Daily limit reached (' . TIPSTER_DAILY_TIPS . ' tips per 24 hours).';
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $kick = date('Y-m-d H:i:s', $ts);
                $mid = bulk_find_match($pdo, ['league_id' => $f['league_id'], 'home' => $f['home'], 'away' => $f['away'], 'kickoff_sql' => $kick]);
                if (!$mid) {
                    $pdo->prepare('INSERT INTO matches (league_id, home_team, away_team, kickoff_at) VALUES (?,?,?,?)')->execute([$f['league_id'], $f['home'], $f['away'], $kick]);
                    $mid = (int)$pdo->lastInsertId();
                }
                $dup = $pdo->prepare('SELECT 1 FROM tips WHERE match_id = ? AND (tipster_id = ? OR author_id = ?) AND (deleted_at IS NULL OR pending_review = 1) AND LOWER(market) = LOWER(?) AND LOWER(pick) = LOWER(?) LIMIT 1');
                $dup->execute([$mid, $t['id'], $t['id'], $f['market'], $f['pick']]);
                if ($dup->fetchColumn()) {
                    $pdo->rollBack();
                    $errors[] = 'You already posted this tip.';
                } elseif ($toPlatform) {
                    // Platform tip: hidden (deleted_at set) until an admin approves it; tipster_id stays empty so it joins the site's own feed.
                    $pdo->prepare("INSERT INTO tips (match_id, market, pick, odds, confidence, analysis, is_vip, status, posted_at, tipster_id, author_id, pending_review, deleted_at) VALUES (?,?,?,?,?,?,?, 'pending', NOW(), NULL, ?, 1, NOW())")
                        ->execute([$mid, $f['market'], $f['pick'], number_format((float)$f['odds'], 2, '.', ''), $f['confidence'], $f['analysis'], $f['is_vip'], $t['id']]);
                    $pdo->commit();
                    set_flash('success', 'Sent for review. It goes live on the platform once an admin approves it (before kickoff).');
                    redirect('tipster/tips.php');
                } elseif (setting('tipster_tips_review', '0') === '1') {
                    $pdo->prepare("INSERT INTO tips (match_id, market, pick, odds, confidence, analysis, is_vip, status, posted_at, tipster_id, pending_review, deleted_at) VALUES (?,?,?,?,?,?,?, 'pending', NOW(), ?, 1, NOW())")
                        ->execute([$mid, $f['market'], $f['pick'], number_format((float)$f['odds'], 2, '.', ''), $f['confidence'], $f['analysis'], $f['is_vip'], $t['id']]);
                    $pdo->commit();
                    set_flash('success', 'Sent for review. It goes live once an admin approves it (before kickoff).');
                    redirect('tipster/tips.php');
                } else {
                    $pdo->prepare("INSERT INTO tips (match_id, market, pick, odds, confidence, analysis, is_vip, status, posted_at, tipster_id) VALUES (?,?,?,?,?,?,?, 'pending', NOW(), ?)")
                        ->execute([$mid, $f['market'], $f['pick'], number_format((float)$f['odds'], 2, '.', ''), $f['confidence'], $f['analysis'], $f['is_vip'], $t['id']]);
                    $pdo->commit();
                    try { audit_tip((int)$pdo->lastInsertId(), 'post'); } catch (Throwable $e) {}
                    try { alerts_new_tip((int)$pdo->lastInsertId()); } catch (Throwable $e) { error_log('alerts_new_tip: ' . $e->getMessage()); }
                    set_flash('success', 'Tip posted. ' . ($f['is_vip'] ? 'Only your subscribers can see the pick until the match is settled.' : 'Everyone can see this free tip.'));
                    redirect('tipster/tips.php');
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('tipster tip: ' . $ex->getMessage());
                $errors[] = 'The tip could not be saved. Please try again.';
            }
        }
    }
}
$st = $pdo->prepare("SELECT t.*, m.home_team, m.away_team, m.kickoff_at FROM tips t JOIN matches m ON m.id = t.match_id WHERE (t.tipster_id = ? OR t.author_id = ?) AND (t.deleted_at IS NULL OR t.pending_review = 1) ORDER BY m.kickoff_at DESC LIMIT 40");
$st->execute([$t['id'], $t['id']]);
$mine = $st->fetchAll();

$page_title = 'My tips - ' . SITE_NAME;
$active = 'tipster-tips';
$tnav = 'tips';
include dirname(__DIR__) . '/includes/header.php';
?>
<section><div class="wrap">
  <div class="head"><div><h2>My tips</h2><p>Posted before kickoff, timestamped by the server, and settled by the site. You cannot edit a tip after posting.</p></div></div>
  <?php include dirname(__DIR__) . '/includes/tipster-nav.php'; ?>
  <?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
  <?php if (!$isActive): ?><div class="banner"><span>You can post tips once your profile is approved.</span></div><?php else: ?>
  <form method="post" class="box2" style="max-width:700px"><?= csrf_field() ?><input type="hidden" name="action" value="add"><h3>Post a tip</h3>
    <div class="field"><label for="league_id">League</label><select id="league_id" name="league_id"><?php foreach ($leagues as $l): ?><option value="<?= (int)$l['id'] ?>"<?= (int)$f['league_id'] === (int)$l['id'] ? ' selected' : '' ?>><?= e($l['sport'] . ' - ' . $l['name']) ?></option><?php endforeach; ?></select></div>
    <div class="formgrid">
      <div class="field"><label for="home">Home team</label><input id="home" name="home" maxlength="80" required value="<?= e($f['home']) ?>"></div>
      <div class="field"><label for="away">Away team</label><input id="away" name="away" maxlength="80" required value="<?= e($f['away']) ?>"></div>
      <div class="field"><label for="kickoff">Kickoff (<?= e(defined('TIMEZONE') ? TIMEZONE : 'Africa/Nairobi') ?>)</label><input id="kickoff" name="kickoff" type="datetime-local" required value="<?= e($f['kickoff']) ?>"></div>
      <div class="field"><label for="market">Market</label><input id="market" name="market" maxlength="40" required value="<?= e($f['market']) ?>" placeholder="Over/Under"></div>
      <div class="field"><label for="pick">Pick</label><input id="pick" name="pick" maxlength="120" required value="<?= e($f['pick']) ?>" placeholder="Over 2.5 goals"></div>
      <div class="field"><label for="odds">Odds</label><input id="odds" name="odds" type="number" step="0.01" min="1.01" max="100" required value="<?= e($f['odds']) ?>"></div>
      <div class="field"><label for="confidence">Confidence (1-5)</label><select id="confidence" name="confidence"><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= (int)$f['confidence'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
    </div>
    <div class="field"><label for="analysis">Reasoning (optional)</label><textarea id="analysis" name="analysis" rows="3" maxlength="1500"><?= e($f['analysis']) ?></textarea></div>
    <div class="field"><label><input type="checkbox" name="is_vip" value="1"<?= $f['is_vip'] ? ' checked' : '' ?>> Premium tip: only paying members (your subscribers, or VIP members for a platform tip) see the pick until the match is settled</label></div>
    <?php if (!empty($t['is_contributor'])): ?><div class="field"><label><input type="checkbox" name="platform" value="1"> Post this as a <strong><?= e(SITE_NAME) ?> platform tip</strong> (needs admin approval; premium platform tips earn you a share of VIP sales)</label></div><?php endif; ?>
    <button class="btn gold" type="submit">Post tip</button>
  </form>
  <?php endif; ?>

  <h3 style="margin-top:24px">Your latest tips</h3>
  <div class="panel"><div class="scroll"><table class="tbl"><thead><tr><th>Match</th><th>Kickoff</th><th>Pick</th><th>Odds</th><th>Type</th><th>Result</th><th></th></tr></thead><tbody>
  <?php foreach ($mine as $r): $canDel = $isActive && !$r['author_id'] && $r['status'] === 'pending' && strtotime($r['kickoff_at']) > time(); ?>
    <tr><td><?= e($r['home_team']) ?> vs <?= e($r['away_team']) ?></td><td><?= e(date('j M, H:i', strtotime($r['kickoff_at']))) ?></td><td><?= e($r['pick']) ?></td><td><?= e(number_format((float)$r['odds'], 2)) ?></td>
      <td><?= $r['author_id'] ? 'Platform ' : '' ?><?= $r['is_vip'] ? 'premium' : 'free' ?></td><td><?php if ($r['pending_review']): ?><span class="badge pending">In review</span><?php else: ?><span class="badge <?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span><?php endif; ?></td>
      <td><?php if ($r['pending_review']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny outline" type="submit">Withdraw</button></form><?php elseif ($canDel): ?><form method="post" onsubmit="return confirm('Remove this tip?');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn tiny outline" type="submit">Remove</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$mine): ?><tr><td colspan="7" style="color:var(--mute)">No tips yet.</td></tr><?php endif; ?></tbody></table></div></div>
</div></section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
