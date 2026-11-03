<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('tips');
$pdo = db();

$leagues = $pdo->query('SELECT l.id, l.name, s.name AS sport FROM leagues l JOIN sports s ON s.id = l.sport_id ORDER BY s.id, l.name')->fetchAll();

// Adding one more tip to a match that already exists?
$matchId = (int)($_GET['match'] ?? $_POST['match'] ?? 0);
$match   = null;
if ($matchId) {
    $st = $pdo->prepare('SELECT m.*, l.name AS league_name FROM matches m JOIN leagues l ON l.id = m.league_id WHERE m.id = ?');
    $st->execute([$matchId]);
    $match = $st->fetch();
    if (!$match || strtotime($match['kickoff_at']) <= time()) {
        set_flash('error', 'That match is not available for new tips (it has started or does not exist).');
        redirect('admin/tips.php');
    }
}

$f = [
    'match_id' => $match['id'] ?? 0, 'league_id' => $match['league_id'] ?? ($leagues[0]['id'] ?? 0),
    'home' => $match['home_team'] ?? '', 'away' => $match['away_team'] ?? '',
    'kickoff' => $match ? date('Y-m-d\TH:i', strtotime($match['kickoff_at'])) : date('Y-m-d\TH:i', strtotime('+3 hours')),
    'league_name' => $match['league_name'] ?? '',
    'stream_url' => (stream_col() && $match) ? (string)($match['stream_url'] ?? '') : '', 'market' => '', 'pick' => '', 'odds' => '', 'confidence' => 3, 'is_vip' => 0, 'analysis' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $f['market']     = post_str('market');
        $f['pick']       = post_str('pick');
        $f['odds']       = post_str('odds');
        $f['confidence'] = (int)($_POST['confidence'] ?? 3);
        $f['is_vip']     = !empty($_POST['is_vip']) ? 1 : 0;
        $f['analysis']   = trim((string)($_POST['analysis'] ?? ''));
        $f['stream_url'] = trim((string)($_POST['stream_url'] ?? ''));
        $f['stream_url2'] = trim((string)($_POST['stream_url2'] ?? ''));
        $wcode = trim((string)($_POST['stream_widget'] ?? ''));
        if ($wcode !== '') { [$wurl, $werr] = stream_widget_save($wcode); if ($werr !== '') { $errors[] = $werr; } else { $f['stream_url'] = $wurl; } }
        if ($f['stream_url2'] !== '' && stream_clean($f['stream_url2']) === '') $errors[] = 'The backup stream link must start with http:// or https://';
        if ($f['stream_url'] !== '' && stream_clean($f['stream_url']) === '') $errors[] = 'The live stream link must start with http:// or https://';
        [$thumbVal, $thumbErr] = thumb_from_post();
        if ($thumbErr !== '') $errors[] = $thumbErr;

        if (!$match) {
            $f['league_id'] = (int)($_POST['league_id'] ?? 0);
            $f['home']      = post_str('home');
            $f['away']      = post_str('away');
            $f['kickoff']   = post_str('kickoff');
            if (!in_array($f['league_id'], array_column($leagues, 'id'), true)) $errors[] = 'Choose a league.';
            if (mb_strlen($f['home']) < 2 || mb_strlen($f['home']) > 80 || mb_strlen($f['away']) < 2 || mb_strlen($f['away']) > 80) $errors[] = 'Enter both team names (2 to 80 characters each).';
            elseif (mb_strtolower($f['home']) === mb_strtolower($f['away'])) $errors[] = 'The two teams must be different.';
            $ts = strtotime($f['kickoff']);
            if (!$ts) $errors[] = 'Enter a valid kickoff date and time.';
            elseif ($ts <= time() + 60) $errors[] = 'Kickoff must be in the future. Tips are posted before the match starts, never after.';
        }
        if ($f['market'] === '' || mb_strlen($f['market']) > 40) $errors[] = 'Enter the market (for example Over/Under).';
        if ($f['pick'] === '' || mb_strlen($f['pick']) > 120) $errors[] = 'Enter the pick (up to 120 characters).';
        if (!is_numeric($f['odds']) || (float)$f['odds'] < 1.01 || (float)$f['odds'] > 1000) $errors[] = 'Odds must be a number between 1.01 and 1000.';
        if ($f['confidence'] < 1 || $f['confidence'] > 5) $errors[] = 'Confidence must be 1 to 5.';
        if (mb_strlen($f['analysis']) > 2000) $errors[] = 'Analysis is too long (2000 characters maximum).';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if (!$match) {
                    $pdo->prepare('INSERT INTO matches (league_id, home_team, away_team, kickoff_at) VALUES (?, ?, ?, ?)')
                        ->execute([$f['league_id'], $f['home'], $f['away'], date('Y-m-d H:i:s', strtotime($f['kickoff']))]);
                    $mid = (int)$pdo->lastInsertId();
                } else {
                    $mid = (int)$match['id'];
                }
                // posted_at is always the server time. It cannot be typed in or changed later.
                $pdo->prepare('INSERT INTO tips (match_id, market, pick, odds, confidence, analysis, is_vip, status, posted_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', NOW())')
                    ->execute([$mid, $f['market'], $f['pick'], number_format((float)$f['odds'], 2, '.', ''), $f['confidence'], $f['analysis'], $f['is_vip']]);
                $tipId = (int)$pdo->lastInsertId();
                if (stream_col() && ($f['stream_url'] !== '' || $match)) { $pdo->prepare('UPDATE matches SET stream_url = ? WHERE id = ?')->execute([stream_clean($f['stream_url']), $mid]); }
                if ($thumbVal !== null && thumb_col('matches')) { $pdo->prepare('UPDATE matches SET thumb = ? WHERE id = ?')->execute([$thumbVal, $mid]); }
                sources_save('matches', 'id', $mid);
                if ($f['stream_url2'] !== '' && stream2_col()) { $pdo->prepare('UPDATE matches SET stream_url2 = ? WHERE id = ?')->execute([stream_clean($f['stream_url2']), $mid]); }
                $pdo->commit();
                audit_tip($tipId, 'post');
                try { alerts_vip_posted($tipId); alerts_process(30); } catch (Throwable $ax) { error_log('alerts_vip_posted: ' . $ax->getMessage()); }
                admin_log('tip.add', 'tip#' . $tipId, $f['pick'] . ' @ ' . $f['odds'] . ($f['is_vip'] ? ' (VIP)' : ''));
                try { require_once dirname(__DIR__) . '/includes/social.php'; if (tg_autopost_on()) { tip_to_telegram($tipId); } } catch (Throwable $tgx) { /* posting must never block saving a tip */ }
                set_flash('success', 'Tip saved and published.');
                redirect(!empty($_POST['again']) ? 'admin/tip-add.php?match=' . $mid : 'admin/tips.php');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('tip-add: ' . $ex->getMessage());
                $errors[] = 'The tip could not be saved. Please try again.';
            }
        }
    }
}

$fixedMatch  = (bool)$match;
$locked      = false;
$formAction  = url('admin/tip-add.php');
$submitLabel = 'Publish tip';
$showAgain   = true;

$admin_title = 'Add a tip';
$admin_active = 'tip-add';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Add a tip</h1>
<p class="sub">The tip goes live as soon as you save. The posting time is recorded automatically and cannot be changed.</p>
<?php include dirname(__DIR__) . '/includes/admin-tip-form.php'; ?>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
