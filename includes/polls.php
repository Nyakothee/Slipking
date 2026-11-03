<?php
/** Community prediction polls: one vote per member per match, locked at kickoff, compared with the real result. */

function poll_ready(): bool { return sk_ensure_table('poll_votes', 'database-update-19.sql'); }

/** Counts of votes: ['1'=>n,'X'=>n,'2'=>n]. */
function poll_counts(int $matchId): array
{
    $c = ['1' => 0, 'X' => 0, '2' => 0];
    if (!poll_ready()) { return $c; }
    $st = db()->prepare('SELECT choice, COUNT(*) FROM poll_votes WHERE match_id = ? GROUP BY choice');
    $st->execute([$matchId]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) { if (isset($c[$r[0]])) { $c[$r[0]] = (int)$r[1]; } }
    return $c;
}

function poll_my(int $matchId, int $userId): ?string
{
    if (!poll_ready()) { return null; }
    $st = db()->prepare('SELECT choice FROM poll_votes WHERE match_id = ? AND user_id = ?');
    $st->execute([$matchId, $userId]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** '1', 'X', '2' once the match is finished with a score, otherwise null. */
function poll_outcome(int $matchId): ?string
{
    try {
        $st = db()->prepare('SELECT live_status, home_score, away_score FROM matches WHERE id = ?');
        $st->execute([$matchId]);
        $r = $st->fetch();
    } catch (Throwable $e) { return null; }
    if (!$r || !live_is_finished($r['live_status']) || $r['home_score'] === null || $r['away_score'] === null) { return null; }
    return $r['home_score'] > $r['away_score'] ? '1' : ($r['home_score'] < $r['away_score'] ? '2' : 'X');
}

/** The poll box for a match page. */
function poll_html(array $m, ?array $user, string $back = ''): string
{
    $id = (int)$m['id'];
    $open = strtotime($m['kickoff_at']) > time();
    $c = poll_counts($id);
    $tot = array_sum($c);
    $pct = function ($n) use ($tot) { return $tot ? round($n / $tot * 100) : 0; };
    $mine = $user ? poll_my($id, (int)$user['id']) : null;
    $labels = ['1' => $m['home_team'] . ' win', 'X' => 'Draw', '2' => $m['away_team'] . ' win'];
    $h = '<div class="cm-box rm-poll" id="poll"><h3 style="margin-top:0">Community poll: who wins?</h3>';
    if ($tot) {
        $h .= '<div class="cm-bar" role="img" aria-label="Community votes">';
        foreach (['1' => 'h', 'X' => 'd', '2' => 'a'] as $k => $cls) {
            if ($c[$k]) { $h .= '<i class="' . $cls . '" style="width:' . ($c[$k] / $tot * 100) . '%">' . $pct($c[$k]) . '%</i>'; }
        }
        $h .= '</div><p style="font-size:14px;color:var(--mute)">' . e($labels['1']) . ': ' . (int)$c['1'] . ' (' . $pct($c['1']) . '%) &middot; Draw: ' . (int)$c['X'] . ' (' . $pct($c['X']) . '%) &middot; ' . e($labels['2']) . ': ' . (int)$c['2'] . ' (' . $pct($c['2']) . '%) &middot; ' . $tot . ' vote' . ($tot === 1 ? '' : 's') . '</p>';
    } else {
        $h .= '<p style="color:var(--mute)">' . ($open ? 'No votes yet. Be the first.' : 'Nobody voted before kickoff.') . '</p>';
    }
    if ($open) {
        if ($user) {
            $h .= '<form method="post" action="' . e(url('community/poll-vote.php')) . '" class="cm-vote">' . csrf_field() . '<input type="hidden" name="match_id" value="' . $id . '">' . ($back !== '' ? '<input type="hidden" name="back" value="' . e($back) . '">' : '');
            foreach ($labels as $k => $l) {
                $h .= '<button class="btn' . ($mine === $k ? ' dark on' : '') . '" name="choice" value="' . $k . '" type="submit">' . e($l) . '</button>';
            }
            $h .= '</form><p style="font-size:13px;color:var(--mute)">' . ($mine ? 'You can change your vote until kickoff.' : 'Voting closes at kickoff.') . '</p>';
        } else {
            $h .= '<p><a class="btn dark" href="' . e(url('auth/login.php?next=' . rawurlencode('match.php?id=' . $id))) . '">Log in to vote</a></p>';
        }
    } else {
        $h .= '<p><strong>Voting is closed</strong> (locked at kickoff).' . ($mine ? ' You voted: ' . e($labels[$mine]) . '.' : '') . '</p>';
        $res = poll_outcome($id);
        if ($res !== null && $tot) {
            $top = array_keys($c, max($c))[0];
            $h .= '<p>Actual result: <strong>' . e($labels[$res]) . '</strong>. The community favourite was <strong>' . e($labels[$top]) . '</strong> &ndash; '
                . ($top === $res ? '<span class="badge won">community was right</span>' : '<span class="badge lost">community was wrong</span>')
                . ' ' . $pct($c[$res]) . '% had voted for the result that happened.</p>';
        } elseif ($res === null) {
            $h .= '<p style="color:var(--mute)">The comparison with the real result appears once the match is finished.</p>';
        }
    }
    return $h . '</div>';
}
