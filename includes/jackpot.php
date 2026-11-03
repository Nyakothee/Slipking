<?php
/**
 * Jackpot: the admin publishes 12 games, every member makes one free entry (1, X or 2 for each game).
 * Everyone who gets ALL games right shares the jackpot (an equal split). The prize amount is set per round in Admin > Jackpot and can be changed
 * any time before the round is settled. Optional consolation prize for getting all but one right. Winners are paid by the admin by hand.
 */

const JACKPOT_GAMES = 12;

function jackpot_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    return $ok = (sk_ensure_table('jackpot_rounds', 'database-update-21.sql') && sk_ensure_table('jackpot_games', 'database-update-21.sql')
        && sk_ensure_table('jackpot_entries', 'database-update-21.sql') && sk_ensure_table('jackpot_picks', 'database-update-21.sql'));
}

function jackpot_kes($n): string { return 'KES ' . number_format((float)$n, 0); }

function jackpot_games(int $rid): array
{
    $q = db()->prepare('SELECT * FROM jackpot_games WHERE round_id = ? ORDER BY sort_no, id'); $q->execute([$rid]);
    return $q->fetchAll();
}

/** Moment entries stop: the round's own closing time, otherwise the first kickoff. NULL when neither is set. */
function jackpot_closes_at(array $round, array $games): ?int
{
    if (!empty($round['closes_at'])) { return strtotime($round['closes_at']); }
    $t = null;
    foreach ($games as $g) { if (!empty($g['kickoff_at'])) { $k = strtotime($g['kickoff_at']); $t = $t === null ? $k : min($t, $k); } }
    return $t;
}

function jackpot_is_closed(array $round, array $games): bool
{
    if ($round['status'] === 'settled') { return true; }
    $c = jackpot_closes_at($round, $games);
    return $c !== null && time() >= $c;
}

/** The round members can play now: the newest open one. */
function jackpot_open_round(): ?array
{
    if (!jackpot_ready()) { return null; }
    if (function_exists('jackpot_open_rounds')) { $all = jackpot_open_rounds(); return $all ? $all[0] : null; }
    $r = db()->query("SELECT * FROM jackpot_rounds WHERE status = 'open' ORDER BY id DESC LIMIT 1")->fetch();
    return $r ?: null;
}

function jackpot_entry(int $rid, int $uid): ?array
{
    $q = db()->prepare('SELECT * FROM jackpot_entries WHERE round_id = ? AND user_id = ?'); $q->execute([$rid, $uid]);
    $e = $q->fetch();
    if (!$e) { return null; }
    $p = db()->prepare('SELECT game_id, pick FROM jackpot_picks WHERE entry_id = ?'); $p->execute([$e['id']]);
    $e['picks'] = [];
    foreach ($p->fetchAll() as $r) { $e['picks'][(int)$r['game_id']] = $r['pick']; }
    return $e;
}

/** Saves a member's picks. Every game needs a pick. Returns an error message or ''. */
function jackpot_save_picks(array $round, array $games, int $uid, array $posted): string
{
    if (jackpot_is_closed($round, $games)) { return 'Entries for this round are closed.'; }
    $picks = [];
    foreach ($games as $g) {
        $v = strtoupper((string)($posted[$g['id']] ?? ''));
        if (!in_array($v, ['1', 'X', '2'], true)) { return 'Pick 1, X or 2 for every game (' . count($picks) . ' of ' . count($games) . ' picked).'; }
        $picks[(int)$g['id']] = $v;
    }
    $pdo = db();
    $pdo->prepare('INSERT INTO jackpot_entries (round_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()')->execute([$round['id'], $uid]);
    $q = $pdo->prepare('SELECT id FROM jackpot_entries WHERE round_id = ? AND user_id = ?'); $q->execute([$round['id'], $uid]);
    $eid = (int)$q->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO jackpot_picks (entry_id, game_id, pick) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pick = VALUES(pick)');
    foreach ($picks as $gid => $v) { $ins->execute([$eid, $gid, $v]); }
    return '';
}

/** Recounts every entry's correct picks from the results entered so far. */
function jackpot_rescore(int $rid): void
{
    db()->prepare("UPDATE jackpot_entries e SET e.correct_n = (SELECT COUNT(*) FROM jackpot_picks p JOIN jackpot_games g ON g.id = p.game_id
                   WHERE p.entry_id = e.id AND g.result IS NOT NULL AND g.result = p.pick) WHERE e.round_id = ?")->execute([$rid]);
}

/** Distribution: how many entries have N correct. */
function jackpot_distribution(int $rid): array
{
    $q = db()->prepare('SELECT correct_n, COUNT(*) AS n FROM jackpot_entries WHERE round_id = ? GROUP BY correct_n ORDER BY correct_n DESC'); $q->execute([$rid]);
    return $q->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Closes the round: scores everyone, splits the jackpot between perfect entries, optional consolation. Returns [ok, message]. */
function jackpot_settle(int $rid): array
{
    $pdo = db();
    $r = $pdo->prepare('SELECT * FROM jackpot_rounds WHERE id = ?'); $r->execute([$rid]); $round = $r->fetch();
    if (!$round) { return [false, 'Round not found.']; }
    if ($round['status'] === 'settled') { return [false, 'This round is already settled.']; }
    $games = jackpot_games($rid);
    foreach ($games as $g) { if ($g['result'] === null) { return [false, 'Enter the result of every game before settling.']; } }
    if (count($games) < 1) { return [false, 'This round has no games.']; }
    jackpot_rescore($rid);
    $total = count($games);
    $w = $pdo->prepare('SELECT id, user_id FROM jackpot_entries WHERE round_id = ? AND correct_n = ?'); $w->execute([$rid, $total]);
    $winners = $w->fetchAll();
    $each = $winners ? floor((float)$round['amount_kes'] / count($winners)) : 0;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE jackpot_entries SET prize_kes = 0 WHERE round_id = ?')->execute([$rid]);
        $up = $pdo->prepare('UPDATE jackpot_entries SET prize_kes = ? WHERE id = ?');
        foreach ($winners as $x) { $up->execute([$each, $x['id']]); }
        $cons = 0;
        if ((float)$round['consolation_kes'] > 0 && $total > 1) {
            $c = $pdo->prepare('SELECT id, user_id FROM jackpot_entries WHERE round_id = ? AND correct_n = ?'); $c->execute([$rid, $total - 1]);
            foreach ($c->fetchAll() as $x) { $up->execute([(float)$round['consolation_kes'], $x['id']]); notify_user((int)$x['user_id'], 'Jackpot: ' . ($total - 1) . ' of ' . $total . ' correct', 'So close! You win a consolation prize of ' . jackpot_kes($round['consolation_kes']) . ' in "' . $round['title'] . '". We will contact you to pay it.'); $cons++; }
        }
        $pdo->prepare("UPDATE jackpot_rounds SET status = 'settled', settled_at = NOW(), winners_n = ?, prize_each = ? WHERE id = ?")->execute([count($winners), $each, $rid]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); error_log('jackpot_settle: ' . $e->getMessage()); return [false, 'Settling failed. Nothing was changed.']; }
    foreach ($winners as $x) {
        notify_user((int)$x['user_id'], 'You won the Jackpot!', 'All ' . $total . ' of your picks were right in "' . $round['title'] . '". Your prize is ' . jackpot_kes($each) . '. We will contact you to pay it.');
    }
    return [true, count($winners) ? count($winners) . ' winner' . (count($winners) === 1 ? '' : 's') . ', ' . jackpot_kes($each) . ' each.' : 'No one got all ' . $total . ' right. No winners this round (you can raise the amount for the next round).'];
}

/** Small teaser for the home page when a round is open. */
function jackpot_banner_html(): string
{
    $r = jackpot_open_round();
    if (!$r) { return ''; }
    $games = jackpot_games((int)$r['id']);
    if (jackpot_is_closed($r, $games)) { return ''; }
    return '<a class="jp-ban" href="' . e(url('jackpot.php')) . '"><span class="jp-ban-ic">&#127942;</span><span class="jp-ban-t"><strong>Jackpot: win ' . e(jackpot_kes($r['amount_kes'])) . '</strong><small>Predict all ' . count($games) . ' games right. Free to enter.</small></span><span class="jp-ban-go">Play now &rarr;</span></a>';
}
