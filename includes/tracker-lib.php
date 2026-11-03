<?php
/**
 * Personal bet tracker: pure helpers (no database, no output) so they can be tested on their own.
 * Amounts are in the member's own currency. Nothing here converts currency.
 */

const TRK_MAX_BETS   = 2000;
const TRK_STAKE_MIN  = 0.01;
const TRK_STAKE_MAX  = 10000000;
const TRK_ODDS_MIN   = 1.01;
const TRK_ODDS_MAX   = 1000;
const TRK_STATUSES   = ['pending', 'won', 'lost', 'void'];

/** Profit of one bet in the member's currency: won = stake x (odds - 1), lost = -stake, void and pending = 0. */
function trk_profit(string $status, float $stake, float $odds): float
{
    if ($status === 'won') {
        return round($stake * ($odds - 1), 2);
    }
    if ($status === 'lost') {
        return round(-$stake, 2);
    }
    return 0.0;
}

/** Parses a decimal typed by a person ("1,250.50" or "2.10"). Returns null when it is not a plain number. */
function trk_num($raw): ?float
{
    $s = trim((string)$raw);
    $s = str_replace([' ', ','], '', $s);
    if ($s === '' || !preg_match('/^\d{1,12}(\.\d{1,4})?$/', $s)) {
        return null;
    }
    return (float)$s;
}

/**
 * Validates a new bet. $in keys: description, bookmaker, stake, odds, placed_on (Y-m-d), notes.
 * Returns [clean array, list of error strings]. $today is Y-m-d (passed in so tests are repeatable).
 */
function trk_validate(array $in, string $today): array
{
    $err = [];
    $desc = trim((string)($in['description'] ?? ''));
    $desc = trim((string)preg_replace('/\s+/u', ' ', $desc));
    if ($desc === '') {
        $err[] = 'Please describe the bet.';
    } elseif (mb_strlen($desc) > 160) {
        $err[] = 'The description can be at most 160 characters.';
    }
    $bm = trim((string)preg_replace('/\s+/u', ' ', (string)($in['bookmaker'] ?? '')));
    if (mb_strlen($bm) > 60) {
        $err[] = 'The bookmaker name can be at most 60 characters.';
    }
    $notes = trim((string)($in['notes'] ?? ''));
    if (mb_strlen($notes) > 255) {
        $err[] = 'Notes can be at most 255 characters.';
    }
    $stake = trk_num($in['stake'] ?? '');
    if ($stake === null || $stake < TRK_STAKE_MIN || $stake > TRK_STAKE_MAX) {
        $err[] = 'The stake must be between 0.01 and 10,000,000.';
    }
    $odds = trk_num($in['odds'] ?? '');
    if ($odds === null || $odds < TRK_ODDS_MIN || $odds > TRK_ODDS_MAX) {
        $err[] = 'The odds must be decimal odds between 1.01 and 1000.';
    }
    $on = (string)($in['placed_on'] ?? '');
    $d  = DateTime::createFromFormat('!Y-m-d', $on);
    if (!$d || $d->format('Y-m-d') !== $on || $on < '2000-01-01' || $on > $today) {
        $err[] = 'Choose the date you placed the bet (not in the future).';
    }
    return [[
        'description' => $desc,
        'bookmaker'   => $bm === '' ? null : $bm,
        'stake'       => $stake === null ? 0.0 : round($stake, 2),
        'odds'        => $odds === null ? 0.0 : round($odds, 2),
        'placed_on'   => $on,
        'notes'       => $notes === '' ? null : $notes,
    ], $err];
}

/**
 * Summary of a list of bets (each row needs status, stake, odds).
 * Staked, ROI, strike rate and average odds only use settled bets that were not void (won + lost).
 * Pending stake is shown separately. Void bets return the stake and count as 0.
 */
function trk_summary(array $bets): array
{
    $staked = 0.0; $profit = 0.0; $won = 0; $lost = 0; $void = 0; $pending = 0; $pendingStake = 0.0; $oddsSum = 0.0;
    foreach ($bets as $b) {
        $st = (string)$b['status'];
        $stake = (float)$b['stake'];
        $odds  = (float)$b['odds'];
        if ($st === 'won' || $st === 'lost') {
            $staked  += $stake;
            $profit  += trk_profit($st, $stake, $odds);
            $oddsSum += $odds;
            if ($st === 'won') { $won++; } else { $lost++; }
        } elseif ($st === 'void') {
            $void++;
        } else {
            $pending++;
            $pendingStake += $stake;
        }
    }
    $settled = $won + $lost;
    return [
        'staked'        => round($staked, 2),
        'profit'        => round($profit, 2),
        'roi'           => $staked > 0 ? round($profit / $staked * 100, 1) : null,
        'strike'        => $settled > 0 ? round($won / $settled * 100, 1) : null,
        'avg_odds'      => $settled > 0 ? round($oddsSum / $settled, 2) : null,
        'won' => $won, 'lost' => $lost, 'void' => $void, 'pending' => $pending,
        'pending_stake' => round($pendingStake, 2),
        'settled'       => $settled,
        'total'         => count($bets),
    ];
}

/**
 * Cumulative profit points, oldest first. Only won and lost bets move the line.
 * Rows need status, stake, odds and a sort key 'at' (settled_at or placed_at). Returns [[label, cumulative], ...].
 */
function trk_series(array $bets): array
{
    $rows = [];
    foreach ($bets as $i => $b) {
        if ($b['status'] === 'won' || $b['status'] === 'lost') {
            $rows[] = ['at' => (string)$b['at'], 'i' => $i, 'p' => trk_profit($b['status'], (float)$b['stake'], (float)$b['odds'])];
        }
    }
    usort($rows, function ($a, $b) {
        return $a['at'] === $b['at'] ? $a['i'] <=> $b['i'] : strcmp($a['at'], $b['at']);
    });
    $out = []; $cum = 0.0;
    foreach ($rows as $r) {
        $cum = round($cum + $r['p'], 2);
        $out[] = [$r['at'], $cum];
    }
    return $out;
}

/** Inline SVG line chart of cumulative profit. Returns '' when there is nothing to draw. */
function trk_chart_svg(array $series): string
{
    $n = count($series);
    if ($n < 1) {
        return '';
    }
    $W = 640; $H = 240; $L = 58; $R = 14; $T = 14; $B = 26;
    $vals = [0.0];
    foreach ($series as $p) { $vals[] = $p[1]; }
    $min = min($vals); $max = max($vals);
    if ($max - $min < 0.01) { $max = $min + 1; }
    $pad = ($max - $min) * 0.08; $min -= $pad; $max += $pad;
    $x = function ($i) use ($n, $L, $W, $R) { return $n === 1 ? $L + ($W - $L - $R) / 2 : $L + ($W - $L - $R) * $i / ($n - 1); };
    $y = function ($v) use ($min, $max, $T, $H, $B) { return $T + ($H - $T - $B) * (1 - ($v - $min) / ($max - $min)); };
    $pts = [];
    foreach ($series as $i => $p) { $pts[] = round($x($i), 1) . ',' . round($y($p[1]), 1); }
    $last = $series[$n - 1][1];
    $svg  = '<svg class="trk-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-labelledby="trkct trkcd" preserveAspectRatio="xMidYMid meet">';
    $svg .= '<title id="trkct">Cumulative profit</title>';
    $svg .= '<desc id="trkcd">' . $n . ' settled bets. Running profit ends at ' . number_format($last, 2) . '. Lowest ' . number_format(min($vals), 2) . ', highest ' . number_format(max($vals), 2) . '.</desc>';
    foreach ([$min + $pad, ($min + $max) / 2, $max - $pad] as $gv) {
        $gy = round($y($gv), 1);
        $svg .= '<line class="trk-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '"/>';
        $svg .= '<text class="trk-axis" x="' . ($L - 6) . '" y="' . ($gy + 4) . '" text-anchor="end">' . number_format($gv, abs($gv) >= 100 ? 0 : 2) . '</text>';
    }
    $zy = round($y(0), 1);
    $svg .= '<line class="trk-zero" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $zy . '" y2="' . $zy . '"/>';
    $svg .= '<polyline class="trk-line ' . ($last >= 0 ? 'up' : 'down') . '" fill="none" points="' . implode(' ', $pts) . '"/>';
    $svg .= '<circle class="trk-dot ' . ($last >= 0 ? 'up' : 'down') . '" cx="' . round($x($n - 1), 1) . '" cy="' . round($y($last), 1) . '" r="4"/>';
    $svg .= '<text class="trk-axis" x="' . $L . '" y="' . ($H - 6) . '">Bet 1</text>';
    $svg .= '<text class="trk-axis" x="' . ($W - $R) . '" y="' . ($H - 6) . '" text-anchor="end">Bet ' . $n . '</text>';
    return $svg . '</svg>';
}

/** CSV text cell that cannot run as a spreadsheet formula. */
function trk_csv_text($v): string
{
    $s = (string)$v;
    if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) {
        $s = "'" . $s;
    }
    return $s;
}
