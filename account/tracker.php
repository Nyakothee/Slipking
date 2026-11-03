<?php
// A CSV download must not be passed through the French/Arabic page translator.
if (isset($_GET['export'])) {
    define('NO_TRANSLATE', true);
}
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/tracker-lib.php';
require_login();
$mpOk = members_ready();                                 // adds user_bets.is_public when missing
$user = current_user();
$uid  = (int)$user['id'];
$pdo  = db();
$vip  = is_vip();
$myPub = $mpOk ? member_row_for_user($uid) : null;      // public profile settings, if the member made one
$pubOn = $myPub && (int)$myPub['is_public'] === 1;

/** A tip this member may see: VIP tips stay hidden until unlocked, exactly like tip.php. */
function trk_tip_visible(array $t, bool $vip): bool
{
    return !($t['is_vip'] && !$vip && $t['status'] === 'pending');
}

/** Filters from the query string (or hidden fields), whitelisted. */
function trk_filters(array $src): array
{
    $st = (string)($src['st'] ?? '');
    $mo = (string)($src['month'] ?? '');
    return [
        'st'    => in_array($st, TRK_STATUSES, true) ? $st : '',
        'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mo) ? $mo : '',
    ];
}

$flt   = trk_filters($_GET);
$today = date('Y-m-d');

/* ---------- CSV export of this member's own bets ---------- */
if (isset($_GET['export'])) {
    $st = $pdo->prepare('SELECT * FROM user_bets WHERE user_id = ? AND deleted_at IS NULL ORDER BY placed_at ASC, id ASC LIMIT ' . TRK_MAX_BETS);
    $st->execute([$uid]);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="slipking-my-bets-' . date('Ymd') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date placed', 'Description', 'Bookmaker', 'Stake', 'Odds', 'Status', 'Profit or loss', 'Date settled', 'Notes']);
    foreach ($st->fetchAll() as $b) {
        fputcsv($out, [
            substr($b['placed_at'], 0, 10), trk_csv_text($b['description']), trk_csv_text($b['bookmaker']),
            number_format((float)$b['stake'], 2, '.', ''), number_format((float)$b['odds'], 2, '.', ''), $b['status'],
            number_format(trk_profit($b['status'], (float)$b['stake'], (float)$b['odds']), 2, '.', ''),
            $b['settled_at'] ? substr($b['settled_at'], 0, 10) : '', trk_csv_text($b['notes']),
        ]);
    }
    fclose($out);
    exit;
}

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'account/tracker.php';
    $pf   = array_filter(trk_filters($_POST), function ($v) { return $v !== ''; });
    if ($pf) {
        $back .= '?' . http_build_query($pf);
    }
    if (!csrf_check()) {
        set_flash('error', 'Your session expired. Please try again.');
        redirect($back);
    }
    $action = post_str('action');

    if ($action === 'add') {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM user_bets WHERE user_id = ? AND deleted_at IS NULL');
        $cnt->execute([$uid]);
        $active = (int)$cnt->fetchColumn();
        $pdo->prepare('DELETE FROM user_bets WHERE user_id = ? AND deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 30 DAY')->execute([$uid]);
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM user_bets WHERE user_id = ?');
        $cnt->execute([$uid]);
        $all = (int)$cnt->fetchColumn();
        [$clean, $errs] = trk_validate($_POST, $today);
        $status = post_str('status');
        if (!in_array($status, TRK_STATUSES, true)) {
            $status = 'pending';
        }
        if ($active >= TRK_MAX_BETS) {
            $errs[] = 'You have reached the limit of 2000 bets. Delete some old bets to add more.';
        } elseif ($all >= TRK_MAX_BETS * 2) {
            $errs[] = 'Too many bets were added and removed recently. Please try again in a few days.';
        }
        $tipId = null;
        $tid = (int)($_POST['tip_id'] ?? 0);
        if ($tid > 0) {
            $t = fetch_tip($tid);
            if ($t && trk_tip_visible($t, $vip)) {
                $tipId = $tid;
            }
        }
        if ($errs) {
            set_flash('error', implode(' ', $errs));
            $_SESSION['trk_old'] = array_intersect_key($_POST, array_flip(['description', 'bookmaker', 'stake', 'odds', 'placed_on', 'notes', 'status', 'tip_id']));
            redirect($back);
        }
        $placed = $clean['placed_on'] . ' ' . ($clean['placed_on'] === $today ? date('H:i:s') : '12:00:00');
        $share = ($pubOn && !empty($_POST['share'])) ? 1 : 0;
        if ($mpOk) {
            $pdo->prepare('INSERT INTO user_bets (user_id, tip_id, description, bookmaker, stake, odds, status, placed_at, settled_at, notes, is_public)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uid, $tipId, $clean['description'], $clean['bookmaker'], $clean['stake'], $clean['odds'], $status,
                           $placed, $status === 'pending' ? null : date('Y-m-d H:i:s'), $clean['notes'], $share]);
            if ($share) { member_notify_followers($uid, (string)$myPub['handle'], $clean['description'], (float)$clean['odds']); }
        } else {
            $pdo->prepare('INSERT INTO user_bets (user_id, tip_id, description, bookmaker, stake, odds, status, placed_at, settled_at, notes)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uid, $tipId, $clean['description'], $clean['bookmaker'], $clean['stake'], $clean['odds'], $status,
                           $placed, $status === 'pending' ? null : date('Y-m-d H:i:s'), $clean['notes']]);
        }
        set_flash('success', 'Bet added to your tracker.');
        redirect($back);
    }

    if ($action === 'share') {
        $u = $pdo->prepare('UPDATE user_bets SET is_public = 1 - is_public WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
        $u->execute([(int)($_POST['id'] ?? 0), $uid]);
        set_flash('success', $pubOn ? 'Updated. Shared bets appear on your public profile.' : 'Updated. Turn on your public profile to show shared bets.');
        redirect($back);
    }

    if ($action === 'status' || $action === 'delete') {
        $bid = (int)($_POST['id'] ?? 0);
        if ($action === 'delete') {
            $u = $pdo->prepare('UPDATE user_bets SET deleted_at = NOW() WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
            $u->execute([$bid, $uid]);
            set_flash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? 'Bet deleted.' : 'That bet was not found.');
        } else {
            $status = post_str('status');
            if (!in_array($status, TRK_STATUSES, true)) {
                set_flash('error', 'Choose a valid result.');
                redirect($back);
            }
            $u = $pdo->prepare('UPDATE user_bets SET status = ?, settled_at = ? WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
            $u->execute([$status, $status === 'pending' ? null : date('Y-m-d H:i:s'), $bid, $uid]);
            set_flash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? 'Bet updated.' : 'That bet was not found or nothing changed.');
        }
        redirect($back);
    }
    set_flash('error', 'Unknown action.');
    redirect($back);
}

/* ---------- read ---------- */
$sql = 'SELECT b.* FROM user_bets b WHERE b.user_id = ? AND b.deleted_at IS NULL';
$par = [$uid];
if ($flt['st'] !== '') {
    $sql .= ' AND b.status = ?';
    $par[] = $flt['st'];
}
if ($flt['month'] !== '') {
    $sql .= ' AND b.placed_at >= ? AND b.placed_at < ?';
    $par[] = $flt['month'] . '-01 00:00:00';
    $par[] = date('Y-m-d 00:00:00', strtotime($flt['month'] . '-01 +1 month'));
}
$st = $pdo->prepare($sql . ' ORDER BY b.placed_at DESC, b.id DESC LIMIT ' . TRK_MAX_BETS);
$st->execute($par);
$bets = $st->fetchAll();
foreach ($bets as $i => $b) {
    $bets[$i]['at'] = $b['settled_at'] ?: $b['placed_at'];
}
$sum  = trk_summary($bets);
$svg  = trk_chart_svg(trk_series($bets));

$st = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(placed_at, '%Y-%m') AS ym FROM user_bets WHERE user_id = ? AND deleted_at IS NULL ORDER BY ym DESC LIMIT 60");
$st->execute([$uid]);
$months = $st->fetchAll(PDO::FETCH_COLUMN);
$st = $pdo->prepare('SELECT COUNT(*) FROM user_bets WHERE user_id = ? AND deleted_at IS NULL');
$st->execute([$uid]);
$totalBets = (int)$st->fetchColumn();

$perPage = 50;
$pages   = max(1, (int)ceil(count($bets) / $perPage));
$page    = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$shown   = array_slice($bets, ($page - 1) * $perPage, $perPage);

/* form values: previous failed attempt, or a tip prefill, or defaults */
$old = $_SESSION['trk_old'] ?? null;
unset($_SESSION['trk_old']);
$f = ['description' => '', 'bookmaker' => '', 'stake' => '', 'odds' => '', 'placed_on' => $today, 'notes' => '', 'status' => 'pending', 'tip_id' => ''];
$tipNote = '';
if (is_array($old)) {
    foreach ($f as $k => $_) {
        if (isset($old[$k]) && is_string($old[$k])) {
            $f[$k] = $old[$k];
        }
    }
} elseif (isset($_GET['tip'])) {
    $t = fetch_tip((int)$_GET['tip']);
    if ($t && trk_tip_visible($t, $vip) && !tip_is_locked($t)) {
        $f['description'] = mb_substr($t['home_team'] . ' vs ' . $t['away_team'] . ': ' . $t['pick'], 0, 160);
        $f['odds']        = number_format((float)$t['odds'], 2, '.', '');
        $f['tip_id']      = (string)(int)$t['id'];
        $tipNote          = 'Filled in from the tip. Check the odds with your bookmaker and enter your own stake.';
    } else {
        $tipNote = 'That tip is closed or not available to track. You can still add the bet by hand.';
    }
}

function trk_money(float $n): string
{
    return ($n > 0 ? '+' : '') . number_format($n, 2);
}
function trk_cls(float $n): string
{
    return $n > 0 ? 'won' : ($n < 0 ? 'lost' : '');
}
$hidden = '<input type="hidden" name="st" value="' . e($flt['st']) . '"><input type="hidden" name="month" value="' . e($flt['month']) . '">';

$page_title = 'My bet tracker - ' . SITE_NAME;
$page_desc  = 'Track your own bets, profit and loss.';
$active     = '';
$acct       = 'tracker';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/group-c.css')) . '">' . "\n" . '<meta name="robots" content="noindex,nofollow">' . "\n";
include dirname(__DIR__) . '/includes/header.php';
?>
<section>
  <div class="wrap">
    <div class="head"><h2>My bet tracker</h2></div>
    <?php include dirname(__DIR__) . '/includes/account-nav.php'; ?>
    <div class="note" style="margin-top:0">Amounts are in your own currency. SlipKing does not convert them, so use the same currency for every bet. This tracker is private to you unless you share bets on a public profile. 18+ only. <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">Gamble responsibly</a>.</div>

    <div class="filters">
      <form method="get" action="<?= e(url('account/tracker.php')) ?>" class="trk-filter">
        <div class="field"><label for="fSt">Result</label>
          <select id="fSt" name="st">
            <option value="">All results</option>
            <?php foreach (TRK_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $flt['st'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="fMo">Month placed</label>
          <select id="fMo" name="month">
            <option value="">All months</option>
            <?php foreach ($months as $ym): ?><option value="<?= e($ym) ?>"<?= $flt['month'] === $ym ? ' selected' : '' ?>><?= e(date('F Y', strtotime($ym . '-01'))) ?></option><?php endforeach; ?>
          </select></div>
        <button class="btn dark small" type="submit">Filter</button>
        <?php if ($flt['st'] !== '' || $flt['month'] !== ''): ?><a class="btn ghost small trk-clear" href="<?= e(url('account/tracker.php')) ?>">Clear filters</a><?php endif; ?>
      </form>
      <?php if ($totalBets > 0): ?><a class="btn dark small" href="<?= e(url('account/tracker.php?export=csv')) ?>">Download all my bets (CSV)</a><?php endif; ?>
    </div>

    <?php if ($totalBets > 0): ?>
    <div class="stats trk-stats">
      <div class="stat"><b><?= e(number_format($sum['staked'], 2)) ?></b><span>Staked (settled bets)</span></div>
      <div class="stat"><b class="<?= e(trk_cls($sum['profit'])) ?>"><?= e(trk_money($sum['profit'])) ?></b><span>Profit or loss</span></div>
      <div class="stat"><b class="<?= $sum['roi'] === null ? '' : e(trk_cls($sum['roi'])) ?>"><?= $sum['roi'] === null ? '-' : e(trk_money($sum['roi'])) . '%' ?></b><span>ROI (profit / staked)</span></div>
      <div class="stat"><b><?= $sum['strike'] === null ? '-' : e(number_format($sum['strike'], 1)) . '%' ?></b><span>Strike rate (<?= (int)$sum['won'] ?> won, <?= (int)$sum['lost'] ?> lost)</span></div>
      <div class="stat"><b><?= $sum['avg_odds'] === null ? '-' : e(number_format($sum['avg_odds'], 2)) ?></b><span>Average odds</span></div>
    </div>
    <p class="trk-sub"><?= (int)$sum['pending'] ?> pending (<?= e(number_format($sum['pending_stake'], 2)) ?> at stake) &middot; <?= (int)$sum['void'] ?> void. Void and pending bets are left out of ROI, strike rate and average odds. The cards follow the filters above.</p>

    <div class="box2 trk-chartbox">
      <h3>Cumulative profit</h3>
      <?php if ($svg !== ''): ?><?= $svg ?><?php else: ?><p style="color:var(--mute)">The chart appears once you have a won or lost bet.</p><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="two trk-grid2">
      <form class="box2" method="post" action="<?= e(url('account/tracker.php')) ?>" novalidate>
        <h3>Add a bet</h3>
        <?php if ($tipNote !== ''): ?><p class="note" style="margin:0 0 14px"><?= e($tipNote) ?></p><?php endif; ?>
        <?= csrf_field() ?><?= $hidden ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="tip_id" value="<?= e($f['tip_id']) ?>">
        <div class="field"><label for="bDesc">Bet</label><input type="text" id="bDesc" name="description" maxlength="160" required value="<?= e($f['description']) ?>" placeholder="e.g. Arsenal to win"></div>
        <div class="field"><label for="bBook">Bookmaker (optional)</label><input type="text" id="bBook" name="bookmaker" maxlength="60" value="<?= e($f['bookmaker']) ?>"></div>
        <div class="trk-row2">
          <div class="field"><label for="bStake">Stake</label><input type="text" id="bStake" name="stake" inputmode="decimal" required value="<?= e($f['stake']) ?>" placeholder="e.g. 100"><div class="hint">0.01 to 10,000,000</div></div>
          <div class="field"><label for="bOdds">Decimal odds</label><input type="text" id="bOdds" name="odds" inputmode="decimal" required value="<?= e($f['odds']) ?>" placeholder="e.g. 2.10"><div class="hint">1.01 to 1000</div></div>
        </div>
        <div class="trk-row2">
          <div class="field"><label for="bDate">Date placed</label><input type="date" id="bDate" name="placed_on" required max="<?= e($today) ?>" value="<?= e($f['placed_on']) ?>"></div>
          <div class="field"><label for="bStatus">Result</label>
            <select id="bStatus" name="status"><?php foreach (TRK_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label for="bNotes">Notes (optional)</label><input type="text" id="bNotes" name="notes" maxlength="255" value="<?= e($f['notes']) ?>"></div>
        <?php if ($pubOn): ?><label class="trk-share"><input type="checkbox" name="share" value="1" checked> Share this bet on my public profile</label>
        <?php else: ?><p class="hint">Want a following? <a href="<?= e(url('account/public-profile.php')) ?>" style="text-decoration:underline">Make a public profile</a> to share bets.</p><?php endif; ?>
        <button class="btn dark" type="submit">Add bet</button>
        <p class="hint" style="margin-top:8px">Profit: won = stake x (odds - 1), lost = minus the stake, void and pending = 0. You can hold up to 2000 bets.</p>
      </form>
      <div class="box2">
        <h3>How it works</h3>
        <ul>
          <li>Add each bet you place, then update its result when it settles.</li>
          <li>Only won and lost bets count towards profit, ROI and strike rate.</li>
          <li>ROI is your profit divided by the total staked on settled bets.</li>
          <li>Your bets are private unless you turn on your public profile and share them.</li>
        </ul>
      </div>
    </div>

    <div class="head" style="margin-top:34px"><h2>My bets</h2></div>
    <?php if ($shown): ?>
      <div class="scroll"><table class="tbl trk-tbl">
        <thead><tr><th>Date</th><th>Bet</th><th>Stake</th><th>Odds</th><th>Result</th><th>Profit or loss</th><th>Update</th></tr></thead>
        <tbody>
        <?php foreach ($shown as $b):
            $p = trk_profit($b['status'], (float)$b['stake'], (float)$b['odds']);
            $cls = ['won' => 'won', 'lost' => 'lost'][$b['status']] ?? 'pending'; ?>
          <tr>
            <td><?= e(date('j M Y', strtotime($b['placed_at']))) ?></td>
            <td><strong><?= e($b['description']) ?></strong>
              <?php if ($b['bookmaker'] !== null && $b['bookmaker'] !== ''): ?><br><small class="trk-mute"><?= e($b['bookmaker']) ?></small><?php endif; ?>
              <?php if (!empty($b['tip_id'])): ?><br><small><a href="<?= e(url('tip.php?id=' . (int)$b['tip_id'])) ?>" style="text-decoration:underline">View the tip</a></small><?php endif; ?>
              <?php if ($b['notes'] !== null && $b['notes'] !== ''): ?><br><small class="trk-mute"><?= e($b['notes']) ?></small><?php endif; ?></td>
            <td><?= e(number_format((float)$b['stake'], 2)) ?></td>
            <td><?= e(number_format((float)$b['odds'], 2)) ?></td>
            <td><span class="badge <?= e($cls) ?>"><?= e(ucfirst($b['status'])) ?></span></td>
            <td class="trk-pl <?= e(trk_cls($p)) ?>"><?= e(trk_money($p)) ?></td>
            <td class="trk-act">
              <form method="post" action="<?= e(url('account/tracker.php')) ?>">
                <?= csrf_field() ?><?= $hidden ?>
                <input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <label class="trk-sr" for="s<?= (int)$b['id'] ?>">Result</label>
                <select id="s<?= (int)$b['id'] ?>" name="status"><?php foreach (TRK_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $b['status'] === $s ? ' selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?></select>
                <button class="btn dark small" type="submit">Save</button>
              </form>
              <?php if ($mpOk): ?><form method="post" action="<?= e(url('account/tracker.php')) ?>">
                <?= csrf_field() ?><?= $hidden ?><input type="hidden" name="action" value="share"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn ghost small" type="submit" title="Show or hide this bet on your public profile"><?= !empty($b['is_public']) ? '&#127760; Public' : '&#128274; Private' ?></button>
              </form><?php endif; ?>
              <form method="post" action="<?= e(url('account/tracker.php')) ?>" onsubmit="return confirm('Delete this bet? This cannot be undone.');">
                <?= csrf_field() ?><?= $hidden ?>
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button class="btn ghost small trk-del" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
      <?= pager($page, $pages, 'account/tracker.php') ?>
    <?php elseif ($totalBets > 0): ?>
      <div class="empty">No bets match these filters.</div>
    <?php else: ?>
      <div class="empty">You have not added any bets yet. Use the form above to add your first one.</div>
    <?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
