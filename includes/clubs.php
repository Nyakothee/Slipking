<?php
/** Private Tipster Clubs: friends share 1X2 selections and compete on a group leaderboard. */

function clubs_ready(): bool
{
    return sk_ensure_table('clubs', 'database-update-19.sql') && sk_ensure_table('club_members', 'database-update-19.sql') && sk_ensure_table('club_picks', 'database-update-19.sql');
}

function club_get(int $id): ?array
{
    $st = db()->prepare('SELECT c.*, u.name AS owner_name, (SELECT COUNT(*) FROM club_members cm WHERE cm.club_id = c.id) AS members FROM clubs c JOIN users u ON u.id = c.owner_id WHERE c.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function club_by_code(string $code): ?array
{
    $st = db()->prepare('SELECT id FROM clubs WHERE invite_code = ?');
    $st->execute([$code]);
    $id = $st->fetchColumn();
    return $id ? club_get((int)$id) : null;
}

function club_is_member(int $clubId, int $userId): bool
{
    $st = db()->prepare('SELECT 1 FROM club_members WHERE club_id = ? AND user_id = ?');
    $st->execute([$clubId, $userId]);
    return (bool)$st->fetchColumn();
}

function club_my(int $userId): array
{
    $st = db()->prepare('SELECT c.id, c.name, c.public_record, c.owner_id, (SELECT COUNT(*) FROM club_members x WHERE x.club_id = c.id) AS members
                         FROM clubs c JOIN club_members m ON m.club_id = c.id WHERE m.user_id = ? ORDER BY c.name');
    $st->execute([$userId]);
    return $st->fetchAll();
}

function club_new_code(): string
{
    return substr(strtr(base64_encode(random_bytes(9)), '+/=', 'abc'), 0, 12);
}

/** Every pick of the club with its computed result. Result comes from the real finished match. */
function club_picks(int $clubId, bool $settledOnly = false): array
{
    $st = db()->prepare("SELECT p.*, u.name, m.home_team, m.away_team, m.kickoff_at, m.live_status, m.home_score, m.away_score
                         FROM club_picks p JOIN users u ON u.id = p.user_id JOIN matches m ON m.id = p.match_id
                         WHERE p.club_id = ? ORDER BY m.kickoff_at DESC, p.id DESC LIMIT 500");
    $st->execute([$clubId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['result'] = 'pending';
        if (live_is_finished($r['live_status']) && $r['home_score'] !== null && $r['away_score'] !== null) {
            $o = $r['home_score'] > $r['away_score'] ? '1' : ($r['home_score'] < $r['away_score'] ? '2' : 'X');
            $r['result'] = $o === $r['pick'] ? 'won' : 'lost';
        }
        if ($settledOnly && $r['result'] === 'pending') { continue; }
        $out[] = $r;
    }
    return $out;
}

function club_leaderboard(array $picks): array
{
    $b = [];
    foreach ($picks as $p) {
        if ($p['result'] === 'pending') { continue; }
        $u = (int)$p['user_id'];
        $b[$u] = $b[$u] ?? ['name' => $p['name'], 'n' => 0, 'won' => 0, 'units' => 0.0];
        $b[$u]['n']++;
        if ($p['result'] === 'won') { $b[$u]['won']++; $b[$u]['units'] += (float)$p['odds'] - 1; } else { $b[$u]['units'] -= 1; }
    }
    usort($b, function ($x, $y) { return $y['units'] <=> $x['units'] ?: $y['won'] <=> $x['won']; });
    return $b;
}

function club_board_html(array $board): string
{
    if (!$board) { return '<p style="color:var(--mute)">No settled selections yet. The board fills as matches finish.</p>'; }
    $h = '<div class="scroll"><table class="bm-table"><thead><tr><th>#</th><th>Member</th><th>Picks</th><th>Won</th><th>Units</th></tr></thead><tbody>';
    foreach ($board as $i => $r) {
        $h .= '<tr><td>' . ($i + 1) . '</td><td>' . e($r['name']) . '</td><td>' . (int)$r['n'] . '</td><td>' . (int)$r['won'] . '</td><td>' . e(tp_signed(round($r['units'], 2))) . '</td></tr>';
    }
    return $h . '</tbody></table></div><p class="note">Units: 1 unit per selection, a win pays (odds - 1), a loss costs 1. Results are taken from the finished match score.</p>';
}

function club_picks_html(array $picks, bool $showUser = true): string
{
    if (!$picks) { return '<p style="color:var(--mute)">Nothing shared yet.</p>'; }
    $lab = ['1' => 'Home win', 'X' => 'Draw', '2' => 'Away win'];
    $h = '<div class="scroll"><table class="bm-table"><thead><tr><th>Match</th><th>Kickoff</th>' . ($showUser ? '<th>Member</th>' : '') . '<th>Selection</th><th>Odds</th><th>Result</th></tr></thead><tbody>';
    foreach ($picks as $p) {
        $h .= '<tr><td>' . e($p['home_team'] . ' vs ' . $p['away_team']) . '</td><td>' . e(date('j M, H:i', strtotime($p['kickoff_at']))) . '</td>'
            . ($showUser ? '<td>' . e($p['name']) . '</td>' : '')
            . '<td><strong>' . e($lab[$p['pick']] ?? $p['pick']) . '</strong>' . ($p['note'] !== '' ? '<br><small>' . e($p['note']) . '</small>' : '') . '</td>'
            . '<td>' . e(number_format((float)$p['odds'], 2)) . '</td><td><span class="badge ' . e($p['result']) . '">' . e(ucfirst($p['result'])) . '</span></td></tr>';
    }
    return $h . '</tbody></table></div>';
}
