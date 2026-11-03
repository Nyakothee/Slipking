<?php
/** Verified, public, unchangeable record of a tipster. Deleted tips stay in it; settled results cannot be edited by the tipster. */

/** Every tip a tipster ever posted (own plans and platform tips they authored), newest kickoff first. */
function tipster_record_rows(int $tipsterId, int $limit = 400): array
{
    $st = db()->prepare("SELECT t.id, t.market, t.pick, t.odds, t.status, t.is_vip, t.posted_at, t.settled_at, t.deleted_at,
                                m.home_team, m.away_team, m.kickoff_at
                         FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE (t.tipster_id = ? OR t.author_id = ?) AND t.pending_review = 0
                         ORDER BY m.kickoff_at DESC, t.id DESC LIMIT " . (int)$limit);
    $st->execute([$tipsterId, $tipsterId]);
    return $st->fetchAll();
}

/** Profit in units (1 unit staked on every tip) per month for the last $months months. Oldest first, empty months included. */
function tipster_monthly(int $tipsterId, int $months = 12): array
{
    $st = db()->prepare("SELECT DATE_FORMAT(m.kickoff_at, '%Y-%m') AS ym, COUNT(*) AS n, SUM(t.status = 'won') AS w,
                                SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 WHEN t.status = 'lost' THEN -1 ELSE 0 END) AS units
                         FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE (t.tipster_id = ? OR t.author_id = ?) AND t.pending_review = 0 AND t.status IN ('won','lost')
                           AND m.kickoff_at >= ?
                         GROUP BY ym");
    $first = date('Y-m-01', strtotime('-' . ($months - 1) . ' month'));
    $st->execute([$tipsterId, $tipsterId, $first . ' 00:00:00']);
    $by = [];
    foreach ($st->fetchAll() as $r) {
        $by[$r['ym']] = $r;
    }
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
        $r = $by[$k] ?? null;
        $out[] = ['ym' => $k, 'label' => date('M y', strtotime($k . '-01')), 'n' => (int)($r['n'] ?? 0), 'w' => (int)($r['w'] ?? 0), 'units' => round((float)($r['units'] ?? 0), 2)];
    }
    return $out;
}

/** Units over the last N days (by kickoff). */
function tipster_units_days(int $tipsterId, int $days): float
{
    $st = db()->prepare("SELECT SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 WHEN t.status = 'lost' THEN -1 ELSE 0 END)
                         FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE (t.tipster_id = ? OR t.author_id = ?) AND t.pending_review = 0 AND t.status IN ('won','lost')
                           AND m.kickoff_at >= NOW() - INTERVAL " . (int)$days . ' DAY');
    $st->execute([$tipsterId, $tipsterId]);
    return round((float)$st->fetchColumn(), 2);
}

/** "Verified record": approved at least 30 days ago and at least 20 settled tips. Shown as a badge. */
function tipster_verified(array $t, ?array $stats = null): bool
{
    if (empty($t['approved_at']) || strtotime($t['approved_at']) > time() - 30 * 86400) {
        return false;
    }
    $stats = $stats ?? tipster_stats((int)$t['id']);
    return $stats['settled'] >= 20;
}

function tipster_record_url(array $t): string
{
    return url('tipster-record.php?slug=' . rawurlencode((string)$t['slug']));
}

/** CSS bar chart of monthly profit (units). Pure HTML/CSS so it works without scripts. */
function tipster_chart_html(array $months): string
{
    $max = 1.0;
    foreach ($months as $m) {
        $max = max($max, abs($m['units']));
    }
    $h = '<div class="rc-chart" role="img" aria-label="Monthly profit in units">';
    foreach ($months as $m) {
        $pct = $m['n'] ? max(3, (int)round(abs($m['units']) / $max * 100)) : 0;
        $cls = $m['units'] >= 0 ? 'pos' : 'neg';
        $h .= '<div class="rc-col" title="' . e($m['label'] . ': ' . ($m['units'] > 0 ? '+' : '') . number_format($m['units'], 1) . ' units, ' . $m['n'] . ' tips') . '">'
            . '<div class="rc-val">' . ($m['n'] ? e(($m['units'] > 0 ? '+' : '') . number_format($m['units'], 1)) : '') . '</div>'
            . '<div class="rc-bar-wrap"><div class="rc-bar ' . $cls . '" style="height:' . $pct . '%"></div></div>'
            . '<div class="rc-lab">' . e($m['label']) . '</div></div>';
    }
    return $h . '</div>';
}

/** "If you had staked X on every tip" calculator. $total/$last30 are units. */
function tipster_stake_calc_html(float $total, float $last30, string $currency = 'KES'): string
{
    ob_start(); ?>
    <div class="rc-calc" data-total="<?= e((string)$total) ?>" data-30="<?= e((string)$last30) ?>">
      <label for="rc-stake">If you had staked</label>
      <input id="rc-stake" type="number" min="1" max="1000000" value="100" inputmode="numeric">
      <span><?= e($currency) ?> on every tip:</span>
      <div class="rc-res"><div><small>Last 30 days</small><b data-out="30">-</b></div><div><small>Whole record</small><b data-out="total">-</b></div></div>
      <p class="rc-fine">Based on settled tips at the odds posted. Past results do not promise future results. Bookmaker odds may have moved.</p>
    </div>
    <script>
    (function(){var c=document.querySelector('.rc-calc');if(!c)return;var i=c.querySelector('input');
    function f(n){var s=Math.round(n);return (s>0?'+':'')+s.toLocaleString();}
    function u(){var s=parseFloat(i.value)||0;c.querySelector('[data-out="30"]').textContent=f(parseFloat(c.dataset['30'])*s);c.querySelector('[data-out="total"]').textContent=f(parseFloat(c.dataset.total)*s);}
    i.addEventListener('input',u);u();})();
    </script>
    <?php
    return (string)ob_get_clean();
}

/* ---------- money-back guarantee ---------- */

/**
 * Looks at one tipster subscription and says whether a refund claim is allowed.
 * Returns ['ok'=>bool,'reason'=>string,'units'=>float,'n'=>int]. Rules: tipster opted in, plan has ended (claim within 7 days after),
 * at least 5 settled premium tips in the paid period, and the units for those tips are at or below minus the site limit.
 */
function guarantee_check(array $sub): array
{
    $pdo = db();
    $limit = max(1.0, (float)setting('tipster_guarantee_units', '5'));
    $out = ['ok' => false, 'reason' => '', 'units' => 0.0, 'n' => 0];
    $st = $pdo->prepare('SELECT guarantee FROM tipsters WHERE id = ?');
    $st->execute([$sub['tipster_id']]);
    if (!(int)$st->fetchColumn()) { $out['reason'] = 'This tipster does not offer the guarantee.'; return $out; }
    if (empty($sub['payment_id'])) { $out['reason'] = 'Only paid plans are covered (not free trials).'; return $out; }
    if (strtotime($sub['ends_at']) > time()) { $out['reason'] = 'You can claim after your plan has ended.'; return $out; }
    if (strtotime($sub['ends_at']) < time() - 7 * 86400) { $out['reason'] = 'The 7-day claim window has closed.'; return $out; }
    $st = $pdo->prepare('SELECT 1 FROM tipster_claims WHERE subscription_id = ?');
    $st->execute([$sub['id']]);
    if ($st->fetchColumn()) { $out['reason'] = 'You already sent a claim for this plan.'; return $out; }
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN t.status = 'won' THEN t.odds - 1 ELSE -1 END), 0) AS units
                         FROM tips t JOIN matches m ON m.id = t.match_id
                         WHERE t.tipster_id = ? AND t.is_vip = 1 AND t.status IN ('won','lost') AND m.kickoff_at >= ? AND m.kickoff_at < ?");
    $st->execute([$sub['tipster_id'], $sub['starts_at'], $sub['ends_at']]);
    $r = $st->fetch();
    $out['n'] = (int)$r['n'];
    $out['units'] = round((float)$r['units'], 2);
    if ($out['n'] < 5) { $out['reason'] = 'Not enough settled premium tips in your period (at least 5 needed).'; return $out; }
    if ($out['units'] > -$limit) { $out['reason'] = 'The tipster did not lose enough in your period (limit: ' . rtrim(rtrim(number_format($limit, 1), '0'), '.') . ' units).'; return $out; }
    $out['ok'] = true;
    return $out;
}
