<?php
/** Football Knowledge Arena: timed quiz rounds from a MySQL question bank with seasonal leaderboards. */
const ARENA_QUESTIONS = 10;
const ARENA_SECONDS_PER_Q = 15;
const ARENA_GRACE = 10;

function arena_ready(): bool { return sk_ensure_table('quiz_questions', 'database-update-19.sql') && sk_ensure_table('quiz_attempts', 'database-update-19.sql'); }

function arena_categories(): array
{
    return ['all' => 'Mixed', 'leagues' => 'Leagues', 'clubs' => 'Clubs', 'players' => 'Players', 'history' => 'Historical matches', 'rules' => 'Rules'];
}

/** Football season key: season runs August to July, e.g. "2026/27". */
function arena_season(?int $ts = null): string
{
    $ts = $ts ?? time();
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    $s = $m >= 8 ? $y : $y - 1;
    return $s . '/' . substr((string)($s + 1), -2);
}

/** Starts a round with random questions. Returns the attempt id, or 0 when the bank is too small. */
function arena_start(int $userId, string $cat): int
{
    $cats = arena_categories();
    if (!isset($cats[$cat])) { $cat = 'all'; }
    $sql = 'SELECT id FROM quiz_questions WHERE is_active = 1' . ($cat === 'all' ? '' : ' AND category = ?') . ' ORDER BY RAND() LIMIT ' . ARENA_QUESTIONS;
    $st = db()->prepare($sql);
    $st->execute($cat === 'all' ? [] : [$cat]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) < 3) { return 0; }
    db()->prepare('INSERT INTO quiz_attempts (user_id, season, category, qids, seconds_allowed, started_at, total) VALUES (?,?,?,?,?,NOW(),?)')
        ->execute([$userId, arena_season(), $cat, implode(',', $ids), count($ids) * ARENA_SECONDS_PER_Q, count($ids)]);
    return (int)db()->lastInsertId();
}

function arena_attempt(int $id, int $userId): ?array
{
    $st = db()->prepare('SELECT *, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed FROM quiz_attempts WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    return $st->fetch() ?: null;
}

/** Questions of an attempt in the stored order. $withAnswers is true only after the round is finished. */
function arena_questions(array $att, bool $withAnswers): array
{
    $ids = array_values(array_filter(array_map('intval', explode(',', $att['qids']))));
    if (!$ids) { return []; }
    $in = implode(',', $ids);
    $cols = 'id, category, question, opt1, opt2, opt3, opt4' . ($withAnswers ? ', correct' : '');
    $by = [];
    foreach (db()->query("SELECT $cols FROM quiz_questions WHERE id IN ($in)")->fetchAll() as $q) { $by[(int)$q['id']] = $q; }
    $out = [];
    foreach ($ids as $i) { if (isset($by[$i])) { $out[] = $by[$i]; } }
    return $out;
}

/** Scores a submission on the server. Answers after the time limit (plus a short grace) score zero. */
function arena_finish(array $att, array $answers): array
{
    if ($att['finished_at'] !== null) { return ['score' => (int)$att['score'], 'late' => false, 'already' => true]; }
    $late = (int)$att['elapsed'] > (int)$att['seconds_allowed'] + ARENA_GRACE;
    $score = 0;
    if (!$late) {
        foreach (arena_questions($att, true) as $q) {
            if ((int)($answers[$q['id']] ?? 0) === (int)$q['correct']) { $score++; }
        }
    }
    db()->prepare('UPDATE quiz_attempts SET finished_at = NOW(), score = ?, seconds_used = ? WHERE id = ? AND finished_at IS NULL')
        ->execute([$score, min((int)$att['elapsed'], 65535), $att['id']]);
    return ['score' => $score, 'late' => $late, 'already' => false];
}

/** Season leaderboard: total correct answers over finished rounds, ties broken by less time used. */
function arena_board(string $season, int $limit = 50): array
{
    $st = db()->prepare('SELECT u.name, COUNT(*) AS rounds, SUM(a.score) AS pts, SUM(a.total) AS tot, SUM(a.seconds_used) AS secs
                         FROM quiz_attempts a JOIN users u ON u.id = a.user_id
                         WHERE a.season = ? AND a.finished_at IS NOT NULL
                         GROUP BY a.user_id, u.name ORDER BY pts DESC, secs ASC LIMIT ' . (int)$limit);
    $st->execute([$season]);
    return $st->fetchAll();
}
