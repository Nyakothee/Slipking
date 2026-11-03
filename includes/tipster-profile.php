<?php
/** Full public profile numbers for a tipster, computed from the real tips rows. Units assume 1 unit staked on every tip. */

function tip_changes_ready(): bool
{
    return sk_ensure_table('tip_changes', 'database-update-19.sql');
}

function tip_void_kind_ready(): bool
{
    return sk_ensure_column('tips', 'void_kind', 'VARCHAR(6) NULL DEFAULT NULL');
}

/** Writes one line in the edited/cancelled record. Never throws. */
function tip_change_log(int $tipId, ?int $tipsterId, string $kind, string $byRole, string $detail): void
{
    try {
        if (!tip_changes_ready()) { return; }
        db()->prepare('INSERT INTO tip_changes (tip_id, tipster_id, kind, by_role, detail) VALUES (?,?,?,?,?)')
            ->execute([$tipId, $tipsterId, $kind, $byRole, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) { error_log('tip_change_log: ' . $e->getMessage()); }
}

/** One blank statistics bucket. */
function tp_bucket(): array
{
    return ['n' => 0, 'won' => 0, 'lost' => 0, 'push' => 0, 'void' => 0, 'units' => 0.0, 'odds_sum' => 0.0, 'odds_n' => 0];
}

function tp_add(array &$b, array $r): void
{
    $b['n']++;
    if ($r['status'] === 'won') { $b['won']++; $b['units'] += (float)$r['odds'] - 1; }
    elseif ($r['status'] === 'lost') { $b['lost']++; $b['units'] -= 1; }
    elseif ($r['status'] === 'void') { if (($r['void_kind'] ?? '') === 'push') { $b['push']++; } else { $b['void']++; } }
    if ($r['status'] !== 'pending') { /* odds of all settled-with-result tips */ }
    if (in_array($r['status'], ['won', 'lost'], true)) { $b['odds_sum'] += (float)$r['odds']; $b['odds_n']++; }
}

function tp_final(array $b): array
{
    $dec = $b['won'] + $b['lost'];
    $b['sample'] = $dec;
    $b['win_rate'] = $dec ? round($b['won'] / $dec * 100, 1) : null;
    $b['yield'] = $dec ? round($b['units'] / $dec * 100, 1) : null;
    $b['avg_odds'] = $b['odds_n'] ? round($b['odds_sum'] / $b['odds_n'], 2) : null;
    $b['units'] = round($b['units'], 2);
    return $b;
}

/**
 * Everything the profile and the full record pages show.
 * Cancelled (removed before kickoff) tips are counted separately and are not wins, losses or pushes.
 */
function tipster_full_stats(int $tid): array
{
    $voidCol = tip_void_kind_ready() ? 't.void_kind' : 'NULL';
    $st = db()->prepare("SELECT t.id, t.market, t.pick, t.odds, t.status, t.is_vip, t.posted_at, t.settled_at, t.deleted_at, $voidCol AS void_kind,
                                m.home_team, m.away_team, m.kickoff_at, l.name AS league
                         FROM tips t JOIN matches m ON m.id = t.match_id JOIN leagues l ON l.id = m.league_id
                         WHERE (t.tipster_id = ? OR t.author_id = ?) AND t.pending_review = 0
                         ORDER BY m.kickoff_at DESC, t.id DESC");
    $st->execute([$tid, $tid]);
    $rows = $st->fetchAll();

    $all = tp_bucket();
    $win = ['7' => tp_bucket(), '30' => tp_bucket(), '90' => tp_bucket(), '365' => tp_bucket()];
    $byLeague = []; $byMarket = [];
    $cancelled = 0; $published = 0; $pending = 0;
    $now = time();
    foreach ($rows as $r) {
        $published++;
        if ($r['deleted_at'] !== null) { $cancelled++; continue; }
        if ($r['status'] === 'pending') { $pending++; continue; }
        tp_add($all, $r);
        $when = strtotime($r['settled_at'] ?: $r['kickoff_at']);
        foreach ($win as $d => &$b) {
            if ($when >= $now - (int)$d * 86400) { tp_add($b, $r); }
        }
        unset($b);
        $lk = $r['league'];
        $mk = mb_strtolower(trim($r['market']));
        $byLeague[$lk] = $byLeague[$lk] ?? tp_bucket(); tp_add($byLeague[$lk], $r);
        $byMarket[$mk] = $byMarket[$mk] ?? ['label' => trim($r['market']), 'b' => tp_bucket()]; tp_add($byMarket[$mk]['b'], $r);
    }
    $fin = function (array $groups, bool $wrapped) {
        $out = [];
        foreach ($groups as $k => $g) {
            $b = tp_final($wrapped ? $g['b'] : $g);
            $b['label'] = $wrapped ? $g['label'] : $k;
            $out[] = $b;
        }
        usort($out, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']); });
        return $out;
    };
    foreach ($win as $d => $b) { $win[$d] = tp_final($b); }
    return [
        'published' => $published, 'cancelled' => $cancelled, 'pending' => $pending,
        'all' => tp_final($all), 'windows' => $win,
        'leagues' => $fin($byLeague, false), 'markets' => $fin($byMarket, true),
        'rows' => $rows,
    ];
}

/** Edited and cancelled record for a tipster, newest first. */
function tipster_changes(int $tid, int $limit = 200): array
{
    try {
        if (!tip_changes_ready()) { return []; }
        $st = db()->prepare("SELECT c.kind, c.by_role, c.detail, c.created_at, c.tip_id, m.home_team, m.away_team
                             FROM tip_changes c JOIN tips t ON t.id = c.tip_id JOIN matches m ON m.id = t.match_id
                             WHERE c.tipster_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT " . (int)$limit);
        $st->execute([$tid]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function tp_signed(float $v, int $d = 2): string
{
    return ($v > 0 ? '+' : '') . number_format($v, $d);
}

/** Summary panel: totals, win rate, units, yield, average odds, windows, leagues, markets. */
function tipster_profile_panel(array $s): string
{
    $a = $s['all'];
    $cell = function (string $v, string $l) { return '<div class="tpf-c"><b>' . $v . '</b><span>' . $l . '</span></div>'; };
    $h = '<div class="tpf"><h3>Full track record</h3><div class="tpf-grid">'
        . $cell((int)$s['published'], 'Predictions published')
        . $cell((int)$a['won'], 'Wins') . $cell((int)$a['lost'], 'Losses')
        . $cell((int)$a['push'], 'Pushes') . $cell((int)$a['void'], 'Voided')
        . $cell((int)$s['cancelled'], 'Cancelled before kickoff')
        . $cell($a['win_rate'] !== null ? e($a['win_rate']) . '%' : '-', 'Win rate (sample ' . (int)$a['sample'] . ')')
        . $cell(e(tp_signed($a['units'])), 'Profit / loss (units)')
        . $cell($a['yield'] !== null ? e(tp_signed($a['yield'], 1)) . '%' : '-', 'Yield / ROI')
        . $cell($a['avg_odds'] !== null ? e(number_format($a['avg_odds'], 2)) : '-', 'Average odds')
        . '</div>';
    $h .= '<h4>Recent performance</h4><div class="scroll"><table class="bm-table"><thead><tr><th>Period</th><th>Tips</th><th>W-L-P-V</th><th>Win rate</th><th>Units</th><th>Yield</th></tr></thead><tbody>';
    foreach (['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last 365 days'] as $d => $lab) {
        $b = $s['windows'][$d];
        $h .= tp_row($lab, $b);
    }
    $h .= '</tbody></table></div>';
    foreach (['leagues' => 'By league', 'markets' => 'By betting market'] as $k => $title) {
        $h .= '<h4>' . $title . '</h4>';
        if (!$s[$k]) { $h .= '<p style="color:var(--mute)">No settled tips yet.</p>'; continue; }
        $h .= '<div class="scroll"><table class="bm-table"><thead><tr><th>' . ($k === 'leagues' ? 'League' : 'Market') . '</th><th>Tips</th><th>W-L-P-V</th><th>Win rate</th><th>Units</th><th>Yield</th></tr></thead><tbody>';
        foreach (array_slice($s[$k], 0, 12) as $b) { $h .= tp_row($b['label'], $b); }
        $h .= '</tbody></table></div>';
    }
    return $h . '<p class="note">Win rate = wins / (wins + losses); the sample is shown beside it. Units assume 1 unit on every tip: a win pays (odds - 1), a loss costs 1, pushes and voids return the stake. Yield = units / (wins + losses).</p></div>';
}

function tp_row(string $label, array $b): string
{
    $u = $b['units'];
    return '<tr><td>' . e($label) . '</td><td>' . (int)$b['n'] . '</td><td>' . (int)$b['won'] . '-' . (int)$b['lost'] . '-' . (int)$b['push'] . '-' . (int)$b['void'] . '</td>'
        . '<td>' . ($b['win_rate'] !== null ? e($b['win_rate']) . '% <small>(' . (int)$b['sample'] . ')</small>' : '-') . '</td>'
        . '<td style="color:var(--' . ($u >= 0 ? 'ok,#1a7f4b' : 'bad,#c0392b') . ')">' . e(tp_signed($u)) . '</td>'
        . '<td>' . ($b['yield'] !== null ? e(tp_signed($b['yield'], 1)) . '%' : '-') . '</td></tr>';
}
