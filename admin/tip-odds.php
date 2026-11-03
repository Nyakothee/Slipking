<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/bookmakers.php';
require_perm('tips');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT t.*, m.home_team, m.away_team, m.kickoff_at
                     FROM tips t JOIN matches m ON m.id = t.match_id WHERE t.id = ? AND t.deleted_at IS NULL');
$st->execute([$id]);
$tip = $st->fetch();
if (!$tip) {
    set_flash('error', 'Tip not found.');
    redirect('admin/tips.php');
}
$locked = tip_is_locked($tip);       // same rule as tip-edit.php: pending and before kickoff only
$errors = [];

$books = [];
sk_ensure_table('bookmakers', 'database-update-6.sql');
try {
    $books = $pdo->query('SELECT id, name, affiliate_url FROM bookmakers WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
} catch (Throwable $ex) {
    $errors[] = 'The bookmaker tables do not exist yet. Import database-update-6.sql in phpMyAdmin first.';
}
$st = $pdo->prepare('SELECT bookmaker_id, odds FROM tip_odds WHERE tip_id = ?');
try {
    $st->execute([$id]);
    $saved = $st->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Throwable $ex) {
    $saved = [];
}
$vals = [];
foreach ($books as $b) {
    $vals[$b['id']] = isset($saved[$b['id']]) ? number_format((float)$saved[$b['id']], 2, '.', '') : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($locked) {
        $errors[] = 'This tip is locked (match started or result set), so odds can no longer change.';
    } elseif (!$errors) {
        $in = isset($_POST['odds']) && is_array($_POST['odds']) ? $_POST['odds'] : [];
        $clean = [];
        foreach ($books as $b) {
            $raw = trim((string)($in[$b['id']] ?? ''));
            $vals[$b['id']] = $raw;
            if ($raw === '') {
                $clean[$b['id']] = null;
            } elseif (!is_numeric($raw) || (float)$raw < 1.01 || (float)$raw > 1000) {
                $errors[] = $b['name'] . ': odds must be between 1.01 and 1000, or empty.';
            } else {
                $clean[$b['id']] = number_format((float)$raw, 2, '.', '');
            }
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                foreach ($clean as $bid => $odds) {
                    if ($odds === null) {
                        $pdo->prepare('DELETE FROM tip_odds WHERE tip_id = ? AND bookmaker_id = ?')->execute([$id, $bid]);
                    } else {
                        $pdo->prepare('INSERT INTO tip_odds (tip_id, bookmaker_id, odds) VALUES (?, ?, ?)
                                       ON DUPLICATE KEY UPDATE odds = VALUES(odds), updated_at = NOW()')->execute([$id, $bid, $odds]);
                    }
                }
                $pdo->commit();
                admin_log('tip.odds', 'tip#' . $id, count(array_filter($clean, function ($x) { return $x !== null; })) . ' bookmaker price(s) saved');
                set_flash('success', 'Odds saved.');
                redirect('admin/tips.php');
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('tip-odds: ' . $ex->getMessage());
                $errors[] = 'The odds could not be saved. Please try again.';
            }
        }
    }
}

$admin_title = 'Compare odds';
$admin_active = 'tips';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1>Compare odds</h1>
<p class="sub"><?= e($tip['home_team']) ?> vs <?= e($tip['away_team']) ?> &middot; <?= e($tip['pick']) ?> (our odds <?= e(number_format((float)$tip['odds'], 2)) ?>) &middot; kickoff <?= e(date('j M Y, H:i', strtotime($tip['kickoff_at']))) ?></p>
<?php if ($locked): ?>
  <div class="lockednote">This tip is locked because the match has started or a result is set, so odds are shown for reference only.</div>
<?php endif; ?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="<?= e(url('admin/tip-odds.php?id=' . $id)) ?>"><?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $id ?>">
  <div class="panel">
    <h2>Odds per bookmaker</h2>
    <?php if ($books): ?>
    <div class="formgrid">
      <?php foreach ($books as $b): ?>
        <div class="field"><label for="o<?= (int)$b['id'] ?>"><?= e($b['name']) ?><?= bookmaker_is_placeholder($b['affiliate_url']) ? ' (link not set yet)' : '' ?></label>
          <input type="text" inputmode="decimal" id="o<?= (int)$b['id'] ?>" name="odds[<?= (int)$b['id'] ?>]" value="<?= e($vals[$b['id']]) ?>" placeholder="e.g. 1.85"<?= $locked ? ' disabled' : '' ?>></div>
      <?php endforeach; ?>
    </div>
    <p class="sub" style="margin-top:10px">Leave a box empty to show nothing for that bookmaker. Only bookmakers that are active and have a real affiliate link appear on the public tip page, and only for tips the visitor can see.</p>
    <?php else: ?>
      <p style="color:var(--mute)">No active bookmakers. <a href="<?= e(url('admin/bookmakers.php')) ?>" style="text-decoration:underline">Set one up first</a>.</p>
    <?php endif; ?>
  </div>
  <?php if (!$locked && $books): ?><button class="btn gold" type="submit">Save odds</button> <?php endif; ?>
  <a class="btn outline" href="<?= e(url('admin/tips.php')) ?>">Back to tips</a>
</form>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
