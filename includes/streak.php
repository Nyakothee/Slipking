<?php
/**
 * 2 Odds Streak Challenge (free). The admin posts ONE pick a day at about 2.00 odds.
 * Day 1 stakes KES 50 (editable). Win = the whole return is staked on the next day's pick. A loss resets to day 1.
 * Everyone sees the site's run. Logged-in members press "Play today" to follow it with their own counter and bankroll.
 */
function streak_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = function_exists('sk_ensure_table') && sk_ensure_table('streak_days', 'database-update-36.sql') && sk_ensure_table('streak_joins', 'database-update-36.sql'); if ($ok && function_exists('thumb_col')) { thumb_col('streak_days'); } if ($ok && function_exists('stream2_col')) { stream2_col('streak_days'); } }
    return $ok;
}
/** Auto-settle the streak pick from the live-score widgets 120 minutes after kickoff (on by default). */
function streak_auto_on(): bool { return streak_ready() && setting('streak_autosettle', '1') === '1'; }
function streak_start(): float { return max(1.0, (float)setting('streak_start', '50')); }
function streak_money(float $n): string { return 'KES ' . number_format($n, $n == floor($n) ? 0 : 2); }

function streak_day_by_date(string $d): ?array
{
    $st = db()->prepare('SELECT * FROM streak_days WHERE day = ?'); $st->execute([$d]);
    return $st->fetch() ?: null;
}
function streak_prev_day(string $d): ?array
{
    $st = db()->prepare('SELECT * FROM streak_days WHERE day < ? ORDER BY day DESC LIMIT 1'); $st->execute([$d]);
    return $st->fetch() ?: null;
}
/** [stake, day number] for a pick that follows $prev (the previous challenge day, or null). */
function streak_terms_after(?array $prev): array
{
    if ($prev && $prev['status'] === 'won') { return [round((float)$prev['stake'] * (float)$prev['odds'], 2), (int)$prev['len'] + 1]; }
    if ($prev && $prev['status'] === 'void') { return [(float)$prev['stake'], (int)$prev['len']]; }
    return [streak_start(), 1];
}
/** Terms for a member on this day. Everyone follows the site's run: when yesterday won, today is the next day and the stake is the whole win. */
function streak_user_terms(int $uid, array $day): array
{
    return [(float)$day['stake'], (int)$day['len']];
}
function streak_join(int $uid, array $day): bool
{
    if ($day['status'] !== 'pending' || strtotime($day['kickoff_at']) <= time()) { return false; }
    [$stake, $len] = streak_user_terms($uid, $day);
    $st = db()->prepare('INSERT IGNORE INTO streak_joins (user_id, day_id, stake, len) VALUES (?,?,?,?)');
    $st->execute([$uid, $day['id'], $stake, $len]);
    return true;
}
function streak_joined(int $uid, int $dayId): ?array
{
    $st = db()->prepare('SELECT * FROM streak_joins WHERE user_id = ? AND day_id = ?'); $st->execute([$uid, $dayId]);
    return $st->fetch() ?: null;
}

/** The numbers shown on the home page card and in the app. */
function streak_view(?array $user = null): array
{
    $today = streak_day_by_date(date('Y-m-d'));
    $last = db()->query("SELECT * FROM streak_days WHERE status <> 'pending' ORDER BY day DESC LIMIT 1")->fetch() ?: null;
    // site run so far (settled days only)
    if ($last && $last['status'] === 'won') { $days = (int)$last['len']; $bank = round((float)$last['stake'] * (float)$last['odds'], 2); }
    elseif ($last && $last['status'] === 'void') { $days = max(0, (int)$last['len'] - 1); $bank = (float)$last['stake']; }
    else { $days = 0; $bank = streak_start(); }
    // if yesterday-or-older streak was broken by a gap the run just carries; the admin posts daily.
    $v = ['today' => null, 'days' => $days, 'bank' => $bank, 'start' => streak_start(), 'history' => [], 'you' => null];
    if ($today) {
        $win = round((float)$today['stake'] * (float)$today['odds'], 2);
        $v['today'] = ['id' => (int)$today['id'], 'home' => $today['home'], 'away' => $today['away'], 'league' => $today['league'], 'kickoff_at' => $today['kickoff_at'],
            'pick' => $today['pick'], 'odds' => (float)$today['odds'], 'stream_url' => stream_pick($today), 'stream_url2' => (string)($today['stream_url2'] ?? ''), 'stream_extra' => (string)($today['stream_extra'] ?? ''), 'watch_extra' => array_map(fn($x) => ['label' => $x['label'], 'watch' => $x['app']], stream_extra_list($today + ['home' => $today['home'], 'away' => $today['away']])), 'stream_state' => stream_pick($today) === '' ? '' : stream_state_of($today), 'watch_url' => stream_app_url($today), 'thumb' => thumb_clean((string)($today['thumb'] ?? '')), 'status' => $today['status'],
            'stake' => (float)$today['stake'], 'return' => $win, 'day_no' => (int)$today['len'], 'open' => $today['status'] === 'pending' && strtotime($today['kickoff_at']) > time()];
        if ($today['status'] === 'won') { $v['days'] = (int)$today['len']; $v['bank'] = $win; }
        elseif ($today['status'] === 'lost') { $v['days'] = 0; $v['bank'] = 0.0; }
        elseif ($today['status'] === 'pending') { $v['days'] = (int)$today['len'] - 1; $v['bank'] = (float)$today['stake']; }
    }
    $h = db()->query('SELECT day, status, odds, len FROM streak_days ORDER BY day DESC LIMIT 7')->fetchAll();
    $v['history'] = array_reverse($h);
    if ($user && $today) {
        $j = streak_joined((int)$user['id'], (int)$today['id']);
        if ($j) {
            $ret = round((float)$j['stake'] * (float)$today['odds'], 2);
            $v['you'] = ['joined' => true, 'stake' => (float)$j['stake'], 'return' => $ret, 'day_no' => (int)$j['len'],
                         'days' => $today['status'] === 'won' ? (int)$j['len'] : ($today['status'] === 'lost' ? 0 : (int)$j['len'] - 1),
                         'bank' => $today['status'] === 'won' ? $ret : ($today['status'] === 'lost' ? 0.0 : (float)$j['stake'])];
        } else {
            [$s, $l] = streak_user_terms((int)$user['id'], $today);
            $v['you'] = ['joined' => false, 'stake' => $s, 'return' => round($s * (float)$today['odds'], 2), 'day_no' => $l, 'days' => $l - 1, 'bank' => $s];
        }
    }
    return $v;
}

/** Posts a day. Returns [ok, message]. */
function streak_post(array $f): array
{
    $d = (string)$f['day'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return [false, 'Choose a valid date.']; }
    if (streak_day_by_date($d)) { return [false, 'A pick already exists for that date.']; }
    $prev = streak_prev_day($d);
    if ($prev && $prev['status'] === 'pending') { return [false, 'Settle ' . date('j M', strtotime($prev['day'])) . ' (won, lost or void) before posting the next day.']; }
    if (mb_strlen($f['home']) < 2 || mb_strlen($f['away']) < 2 || mb_strlen($f['pick']) < 2) { return [false, 'Enter both teams and the pick.']; }
    $ts = strtotime((string)$f['kickoff']);
    if (!$ts) { return [false, 'Enter the kickoff date and time.']; }
    $odds = (float)$f['odds'];
    if ($odds < 1.2 || $odds > 5) { return [false, 'Odds must be between 1.20 and 5.00 (about 2.00 for this challenge).']; }
    $u = trim((string)($f['stream_url'] ?? ''));
    if ($u !== '' && stream_clean($u) === '') { return [false, 'The live stream link must start with http:// or https://']; }
    [$stake, $len] = streak_terms_after($prev);
    db()->prepare('INSERT INTO streak_days (day, home, away, league, kickoff_at, pick, odds, stream_url, stake, len) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$d, $f['home'], $f['away'], (string)$f['league'], date('Y-m-d H:i:s', $ts), $f['pick'], number_format($odds, 2, '.', ''), stream_clean($u), $stake, $len]);
    return [true, 'Posted. Day ' . $len . ' of the streak, stake ' . streak_money($stake) . '.'];
}

function streak_settle(int $id, string $status): bool
{
    if (!in_array($status, ['won', 'lost', 'void'], true)) { return false; }
    $st = db()->prepare("UPDATE streak_days SET status = ?, settled_at = ? WHERE id = ? AND status = 'pending'");
    $st->execute([$status, date('Y-m-d H:i:s'), $id]);
    if (!$st->rowCount()) { return false; }
    $d = db()->prepare('SELECT * FROM streak_days WHERE id = ?'); $d->execute([$id]); $d = $d->fetch();
    $js = db()->prepare('SELECT user_id, stake, len FROM streak_joins WHERE day_id = ?'); $js->execute([$id]);
    foreach ($js->fetchAll() as $j) {
        $ret = round((float)$j['stake'] * (float)$d['odds'], 2);
        $msg = $status === 'won' ? 'Your 2 Odds Streak is alive: day ' . $j['len'] . ' won. ' . streak_money($ret) . ' is ready for tomorrow\'s pick.'
             : ($status === 'lost' ? 'Today\'s streak pick lost. The streak resets, start again tomorrow with ' . streak_money(streak_start()) . '.' : 'Today\'s streak pick was void. Your stake carries over.');
        if (function_exists('notify_user')) { try { notify_user((int)$j['user_id'], '2 Odds Streak', $msg); } catch (Throwable $e) { } }
    }
    return true;
}
