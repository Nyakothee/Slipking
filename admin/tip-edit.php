<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, m.league_id, m.home_team, m.away_team, m.kickoff_at, l.name AS league_name
                     FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id
                     WHERE t.id = ? AND t.deleted_at IS NULL');
$st->execute([$id]);
$tip = $st->fetch();
if (!$tip) {
    set_flash('error', 'Tip not found.');
    redirect('admin/tips.php');
}

$leagues = $pdo->query('SELECT l.id, l.name, s.name AS sport FROM leagues l JOIN sports s ON s.id = l.sport_id ORDER BY s.id, l.name')->fetchAll();
$st = $pdo->prepare('SELECT COUNT(*) FROM tips WHERE match_id = ? AND deleted_at IS NULL');
$st->execute([$tip['match_id']]);
$sameMatch = (int)$st->fetchColumn();

$locked = tip_is_locked($tip);
$curStream = '';
if (stream_col()) { $sq = $pdo->prepare('SELECT stream_url FROM matches WHERE id = ?'); $sq->execute([$tip['match_id']]); $curStream = (string)$sq->fetchColumn(); }
$curStream2 = '';
if (stream2_col()) { $sq2 = $pdo->prepare('SELECT stream_url2 FROM matches WHERE id = ?'); $sq2->execute([$tip['match_id']]); $curStream2 = (string)$sq2->fetchColumn(); }
$curThumb = '';
if (thumb_col('matches')) { $tq = $pdo->prepare('SELECT thumb FROM matches WHERE id = ?'); $tq->execute([$tip['match_id']]); $curThumb = (string)$tq->fetchColumn(); }
$f = [
    'match_id' => $tip['match_id'], 'league_id' => $tip['league_id'], 'league_name' => $tip['league_name'],
    'home' => $tip['home_team'], 'away' => $tip['away_team'], 'kickoff' => date('Y-m-d\TH:i', strtotime($tip['kickoff_at'])),
    'market' => $tip['market'], 'pick' => $tip['pick'], 'odds' => number_format((float)$tip['odds'], 2, '.', ''),
    'confidence' => $tip['confidence'], 'is_vip' => $tip['is_vip'], 'analysis' => (string)$tip['analysis'], 'stream_url' => $curStream, 'stream_url2' => $curStream2, 'stream_extra' => (function () use ($pdo, $tip) { if (!extra_col('matches')) { return ''; } $q = $pdo->prepare('SELECT stream_extra FROM matches WHERE id = ?'); $q->execute([$tip['match_id']]); return (string)$q->fetchColumn(); })(), 'thumb' => $curThumb,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $f['analysis'] = trim((string)($_POST['analysis'] ?? ''));
        if (mb_strlen($f['analysis']) > 2000) $errors[] = 'Analysis is too long (2000 characters maximum).';
        $f['stream_url'] = trim((string)($_POST['stream_url'] ?? ''));
        $f['stream_url2'] = trim((string)($_POST['stream_url2'] ?? ''));
        $wcode = trim((string)($_POST['stream_widget'] ?? ''));
        if ($wcode !== '') { [$wurl, $werr] = stream_widget_save($wcode); if ($werr !== '') { $errors[] = $werr; } else { $f['stream_url'] = $wurl; } }
        if ($f['stream_url2'] !== '' && stream_clean($f['stream_url2']) === '') $errors[] = 'The backup stream link must start with http:// or https://';
        if ($f['stream_url'] !== '' && stream_clean($f['stream_url']) === '') $errors[] = 'The live stream link must start with http:// or https://';
        [$thumbVal, $thumbErr] = thumb_from_post();
        if ($thumbErr !== '') $errors[] = $thumbErr;

        if ($locked) {
            // Once a match has started or a result is set, only the written analysis may change.
            if (!$errors) {
                $pdo->prepare('UPDATE tips SET analysis = ? WHERE id = ?')->execute([$f['analysis'], $id]);
                if (stream_col()) { $pdo->prepare('UPDATE matches SET stream_url = ? WHERE id = ?')->execute([stream_clean($f['stream_url']), $tip['match_id']]); }
                if ($thumbVal !== null && thumb_col('matches')) { $pdo->prepare('UPDATE matches SET thumb = ? WHERE id = ?')->execute([$thumbVal, $tip['match_id']]); }
                sources_save('matches', 'id', $tip['match_id']);
                if (stream2_col()) { $pdo->prepare('UPDATE matches SET stream_url2 = ? WHERE id = ?')->execute([stream_clean($f['stream_url2']), $tip['match_id']]); }
                admin_log('tip.edit', 'tip#' . $id, 'analysis only (tip is locked)');
                set_flash('success', 'Analysis updated. The pick and odds are locked because the match has started.');
                redirect('admin/tips.php');
            }
        } else {
            $f['league_id']  = (int)($_POST['league_id'] ?? 0);
            $f['home']       = post_str('home');
            $f['away']       = post_str('away');
            $f['kickoff']    = post_str('kickoff');
            $f['market']     = post_str('market');
            $f['pick']       = post_str('pick');
            $f['odds']       = post_str('odds');
            $f['confidence'] = (int)($_POST['confidence'] ?? 3);
            $f['is_vip']     = !empty($_POST['is_vip']) ? 1 : 0;

            if (!in_array($f['league_id'], array_column($leagues, 'id'), true)) $errors[] = 'Choose a league.';
            if (mb_strlen($f['home']) < 2 || mb_strlen($f['home']) > 80 || mb_strlen($f['away']) < 2 || mb_strlen($f['away']) > 80) $errors[] = 'Enter both team names (2 to 80 characters each).';
            elseif (mb_strtolower($f['home']) === mb_strtolower($f['away'])) $errors[] = 'The two teams must be different.';
            $ts = strtotime($f['kickoff']);
            if (!$ts) $errors[] = 'Enter a valid kickoff date and time.';
            elseif ($ts <= time() + 60) $errors[] = 'Kickoff must be in the future.';
            if ($f['market'] === '' || mb_strlen($f['market']) > 40) $errors[] = 'Enter the market.';
            if ($f['pick'] === '' || mb_strlen($f['pick']) > 120) $errors[] = 'Enter the pick (up to 120 characters).';
            if (!is_numeric($f['odds']) || (float)$f['odds'] < 1.01 || (float)$f['odds'] > 1000) $errors[] = 'Odds must be between 1.01 and 1000.';
            if ($f['confidence'] < 1 || $f['confidence'] > 5) $errors[] = 'Confidence must be 1 to 5.';

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE matches SET league_id = ?, home_team = ?, away_team = ?, kickoff_at = ? WHERE id = ?')
                        ->execute([$f['league_id'], $f['home'], $f['away'], date('Y-m-d H:i:s', $ts), $tip['match_id']]);
                    $pdo->prepare('UPDATE tips SET market = ?, pick = ?, odds = ?, confidence = ?, is_vip = ?, analysis = ? WHERE id = ?')
                        ->execute([$f['market'], $f['pick'], number_format((float)$f['odds'], 2, '.', ''), $f['confidence'], $f['is_vip'], $f['analysis'], $id]);
                    if (stream_col()) { $pdo->prepare('UPDATE matches SET stream_url = ? WHERE id = ?')->execute([stream_clean($f['stream_url']), $tip['match_id']]); }
                    if ($thumbVal !== null && thumb_col('matches')) { $pdo->prepare('UPDATE matches SET thumb = ? WHERE id = ?')->execute([$thumbVal, $tip['match_id']]); }
                sources_save('matches', 'id', $tip['match_id']);
                if (stream2_col()) { $pdo->prepare('UPDATE matches SET stream_url2 = ? WHERE id = ?')->execute([stream_clean($f['stream_url2']), $tip['match_id']]); }
                    $pdo->commit();
                    try { alerts_odds_boost($id, (float)$tip['odds'], (float)$f['odds']); alerts_process(30); } catch (Throwable $ax) { }
                    $chg = [];
                    if ($tip['market'] !== $f['market']) $chg[] = 'market ' . $tip['market'] . ' -> ' . $f['market'];
                    if ($tip['pick'] !== $f['pick']) $chg[] = 'pick ' . $tip['pick'] . ' -> ' . $f['pick'];
                    if (abs((float)$tip['odds'] - (float)$f['odds']) > 0.004) $chg[] = 'odds ' . number_format((float)$tip['odds'], 2) . ' -> ' . number_format((float)$f['odds'], 2);
                    if ($tip['home_team'] !== $f['home'] || $tip['away_team'] !== $f['away']) $chg[] = 'teams changed';
                    if (date('Y-m-d H:i', strtotime($tip['kickoff_at'])) !== date('Y-m-d H:i', $ts)) $chg[] = 'kickoff ' . date('j M H:i', strtotime($tip['kickoff_at'])) . ' -> ' . date('j M H:i', $ts);
                    if ($chg) tip_change_log($id, ((int)$tip['tipster_id'] ?: (int)$tip['author_id']) ?: null, 'edited', 'admin', implode('; ', $chg));
                    admin_log('tip.edit', 'tip#' . $id, 'was: ' . $tip['pick'] . ' @ ' . number_format((float)$tip['odds'], 2) . ' | now: ' . $f['pick'] . ' @ ' . $f['odds']);
                    set_flash('success', 'Tip updated.');
                    redirect('admin/tips.php');
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('tip-edit: ' . $ex->getMessage());
                    $errors[] = 'The tip could not be saved. Please try again.';
                }
            }
        }
    }
}

$fixedMatch  = false;
$formAction  = url('admin/tip-edit.php?id=' . $id);
$submitLabel = 'Save changes';
$showAgain   = false;

$admin_title = 'Edit tip';
$admin_active = 'tips';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1><?= $locked ? 'View tip' : 'Edit tip' ?></h1>
<p class="sub">Posted <?= e(date('j M Y, H:i', strtotime($tip['posted_at']))) ?> &middot; status: <span class="badge <?= e($tip['status']) ?>"><?= e(ucfirst($tip['status'])) ?></span></p>
<?php if ($locked): ?>
  <div class="lockednote">This tip is locked because the match has started or a result is set. Pick, odds and match details cannot change, so the public record stays honest. You can still improve the written analysis.</div>
<?php elseif ($sameMatch > 1): ?>
  <div class="lockednote">This match has <?= $sameMatch ?> tips. Changing the teams or kickoff here changes them for all of them.</div>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/admin-tip-form.php'; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
