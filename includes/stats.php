<?php
/**
 * Football statistics from the free football-data.org API (fixtures, results, tables) and a simple statistical model.
 * Everything here reads from our own database tables (filled by cron/sync-stats.php), so pages are fast and never hit the API.
 * The model is an estimate from goals scored and conceded this season. It is NOT a tip and gives no guarantee.
 */
require_once __DIR__ . '/config-data.php';

function stats_ready(): bool
{
    return FOOTBALL_DATA_KEY !== '';
}

function stats_on(): bool
{
    return setting('stats_enabled', '1') === '1';
}

/** Competitions the free plan can read, with a friendly name used before the first sync. */
function stats_free_competitions(): array
{
    return ['PL' => 'Premier League', 'PD' => 'La Liga', 'SA' => 'Serie A', 'BL1' => 'Bundesliga', 'FL1' => 'Ligue 1', 'CL' => 'Champions League',
            'ELC' => 'Championship', 'DED' => 'Eredivisie', 'PPL' => 'Primeira Liga', 'BSA' => 'Brasileirao Serie A', 'EC' => 'European Championship', 'WC' => 'FIFA World Cup'];
}

/** Codes the admin chose to sync (setting stats_comps). */
function stats_selected_comps(): array
{
    $all = stats_free_competitions();
    $out = [];
    foreach (explode(',', (string)setting('stats_comps', 'PL,PD,SA,BL1,FL1,CL')) as $c) {
        $c = strtoupper(trim($c));
        if (isset($all[$c])) { $out[] = $c; }
    }
    return array_values(array_unique($out));
}

/* ---------- API client (used by the cron job and the admin test button only) ---------- */

/** GET from football-data.org. Returns ['status'=>int,'data'=>array|null,'error'=>string,'remaining'=>?int]. */
function fd_get(string $path, array $query = []): array
{
    $url = 'https://api.football-data.org/v4/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['X-Auth-Token: ' . FOOTBALL_DATA_KEY, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) { $hdr[strtolower(trim($p[0]))] = trim($p[1]); }
            return strlen($line);
        }]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $code, 'data' => is_array($j) ? $j : null, 'error' => $err !== '' ? $err : (is_array($j) && isset($j['message']) ? (string)$j['message'] : ''),
            'remaining' => isset($hdr['x-requests-available-minute']) ? (int)$hdr['x-requests-available-minute'] : null];
}

function stats_log(string $line): void
{
    @file_put_contents(dirname(__DIR__) . '/logs/stats.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Season start year from a standings/matches payload. */
function fd_season_year(array $j): ?int
{
    $d = $j['season']['startDate'] ?? ($j['filters']['season'] ?? null);
    return $d !== null && preg_match('/^(\d{4})/', (string)$d, $m) ? (int)$m[1] : null;
}

/** 90-minute score of a football-data match object: ['h'=>int,'a'=>int] or null if not finished. */
function fd_match_score(array $m): ?array
{
    if (($m['status'] ?? '') !== 'FINISHED') { return null; }
    $s = $m['score'] ?? [];
    $src = isset($s['regularTime']['home']) ? $s['regularTime'] : ($s['fullTime'] ?? []);
    if (!isset($src['home'], $src['away'])) { return null; }
    return ['h' => (int)$src['home'], 'a' => (int)$src['away']];
}

/** Saves a /competitions/{code}/standings payload. Returns number of rows. */
function stats_save_standings(PDO $pdo, string $code, array $j): int
{
    $n = 0;
    $season = fd_season_year($j);
    $name = (string)($j['competition']['name'] ?? '');
    $pdo->prepare('INSERT INTO stat_competitions (code, name, area, emblem, season, synced_at) VALUES (?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE name = VALUES(name), area = VALUES(area), emblem = VALUES(emblem), season = VALUES(season), synced_at = NOW()')
        ->execute([$code, mb_substr($name !== '' ? $name : (stats_free_competitions()[$code] ?? $code), 0, 80), mb_substr((string)($j['area']['name'] ?? ''), 0, 60),
                   mb_substr((string)($j['competition']['emblem'] ?? ''), 0, 255) ?: null, $season]);
    $ins = $pdo->prepare('INSERT INTO stat_standings (comp_code, type, team_id, position, team_name, crest, played, won, draw, lost, points, gf, ga, gd, form)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE position = VALUES(position), team_name = VALUES(team_name), crest = VALUES(crest), played = VALUES(played), won = VALUES(won),
                          draw = VALUES(draw), lost = VALUES(lost), points = VALUES(points), gf = VALUES(gf), ga = VALUES(ga), gd = VALUES(gd), form = VALUES(form)');
    foreach (($j['standings'] ?? []) as $block) {
        $type = in_array($block['type'] ?? '', ['TOTAL', 'HOME', 'AWAY'], true) ? $block['type'] : null;
        if ($type === null) { continue; }
        foreach (($block['table'] ?? []) as $r) {
            $tid = (int)($r['team']['id'] ?? 0);
            if (!$tid) { continue; }
            $ins->execute([$code, $type, $tid, (int)($r['position'] ?? 0), mb_substr((string)($r['team']['shortName'] ?? $r['team']['name'] ?? ''), 0, 80),
                           mb_substr((string)($r['team']['crest'] ?? ''), 0, 255) ?: null, (int)($r['playedGames'] ?? 0), (int)($r['won'] ?? 0), (int)($r['draw'] ?? 0), (int)($r['lost'] ?? 0),
                           (int)($r['points'] ?? 0), (int)($r['goalsFor'] ?? 0), (int)($r['goalsAgainst'] ?? 0), (int)($r['goalDifference'] ?? 0), mb_substr((string)($r['form'] ?? ''), 0, 20) ?: null]);
            $n++;
        }
    }
    return $n;
}

/** Saves a /competitions/{code}/matches payload. Returns number of rows. */
function stats_save_matches(PDO $pdo, string $code, array $j): int
{
    $n = 0;
    $season = fd_season_year($j);
    $ins = $pdo->prepare('INSERT INTO stat_matches (id, comp_code, season, matchday, kick_utc, status, home_id, home_name, home_crest, away_id, away_name, away_crest, home_goals, away_goals, ht_home, ht_away)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE matchday = VALUES(matchday), kick_utc = VALUES(kick_utc), status = VALUES(status), home_name = VALUES(home_name), home_crest = VALUES(home_crest),
                          away_name = VALUES(away_name), away_crest = VALUES(away_crest), home_goals = VALUES(home_goals), away_goals = VALUES(away_goals),
                          ht_home = VALUES(ht_home), ht_away = VALUES(ht_away), updated_at = NOW()');
    foreach (($j['matches'] ?? []) as $m) {
        $id = (int)($m['id'] ?? 0);
        $dt = strtotime((string)($m['utcDate'] ?? ''));
        if (!$id || !$dt || empty($m['homeTeam']['name']) || empty($m['awayTeam']['name'])) { continue; }
        $sc = fd_match_score($m);
        $ht = $m['score']['halfTime'] ?? [];
        $ins->execute([$id, $code, $season, isset($m['matchday']) ? (int)$m['matchday'] : null, gmdate('Y-m-d H:i:s', $dt), mb_substr((string)$m['status'], 0, 16),
                       (int)($m['homeTeam']['id'] ?? 0) ?: null, mb_substr((string)($m['homeTeam']['shortName'] ?? $m['homeTeam']['name']), 0, 80), mb_substr((string)($m['homeTeam']['crest'] ?? ''), 0, 255) ?: null,
                       (int)($m['awayTeam']['id'] ?? 0) ?: null, mb_substr((string)($m['awayTeam']['shortName'] ?? $m['awayTeam']['name']), 0, 80), mb_substr((string)($m['awayTeam']['crest'] ?? ''), 0, 255) ?: null,
                       $sc ? $sc['h'] : null, $sc ? $sc['a'] : null, isset($ht['home']) ? (int)$ht['home'] : null, isset($ht['away']) ? (int)$ht['away'] : null]);
        $n++;
    }
    return $n;
}

/* ---------- team names ---------- */

/** Lower-case, accent-free, club-prefix-free key so "FC Bayern München" and "Bayern Munich" can be compared. */
function team_key(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'ae', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
                    'ó' => 'o', 'ò' => 'o', 'ö' => 'oe', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'ue', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss', 'š' => 's', 'ž' => 'z', 'č' => 'c', 'ć' => 'c', 'ł' => 'l', 'ğ' => 'g', 'ı' => 'i', 'ş' => 's']);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', str_replace(['&', '-', "'"], [' and ', ' ', ''], $s));
    $alias = ['munchen' => 'munich', 'muenchen' => 'munich', 'internazionale' => 'inter', 'internazionale milano' => 'inter', 'inter milan' => 'inter', 'man utd' => 'manchester united', 'man united' => 'manchester united',
              'man city' => 'manchester city', 'spurs' => 'tottenham', 'tottenham hotspur' => 'tottenham', 'wolves' => 'wolverhampton wanderers', 'wolverhampton' => 'wolverhampton wanderers',
              'brighton and hove albion' => 'brighton', 'paris saint germain' => 'psg', 'paris sg' => 'psg', 'atletico de madrid' => 'atletico madrid', 'athletic bilbao' => 'athletic club',
              'borussia monchengladbach' => 'gladbach', 'borussia moenchengladbach' => 'gladbach', 'monchengladbach' => 'gladbach', 'moenchengladbach' => 'gladbach', 'rb leipzig' => 'leipzig', 'bayer leverkusen' => 'leverkusen',
              'bayer 04 leverkusen' => 'leverkusen', 'eintracht frankfurt' => 'frankfurt', 'newcastle united' => 'newcastle', 'west ham united' => 'west ham', 'leeds united' => 'leeds', 'nottingham forest' => 'nottingham',
              'ssc napoli' => 'napoli', 'as roma' => 'roma', 'ss lazio' => 'lazio', 'olympique marseille' => 'marseille', 'olympique de marseille' => 'marseille', 'olympique lyonnais' => 'lyon', 'olympique lyon' => 'lyon'];
    $drop = ['fc', 'cf', 'afc', 'ac', 'sc', 'sv', 'fk', 'bk', 'ssc', 'ss', 'as', 'cd', 'ud', 'rc', 'rcd', 'sd', 'club', 'de', 'the', 'calcio', 'football', 'and'];
    $toks = array_values(array_filter(explode(' ', $s), function ($t) use ($drop) { return $t !== '' && !in_array($t, $drop, true); }));
    $tokMap = ['munchen' => 'munich', 'muenchen' => 'munich', 'milano' => 'milan', 'internazionale' => 'inter', 'moenchengladbach' => 'gladbach', 'monchengladbach' => 'gladbach'];
    $toks = array_map(function ($t) use ($tokMap) { return $tokMap[$t] ?? $t; }, $toks);
    $s = implode(' ', $toks);
    return $alias[$s] ?? $s;
}

/** Are these two names the same club? Equal keys, or all words of the shorter key appear in the longer one. */
function team_same(string $a, string $b): bool
{
    $ka = team_key($a); $kb = team_key($b);
    if ($ka === '' || $kb === '') { return false; }
    if ($ka === $kb) { return true; }
    $ta = explode(' ', $ka); $tb = explode(' ', $kb);
    [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
    if (strlen(implode('', $short)) < 4) { return false; }
    foreach ($short as $t) {
        if (!in_array($t, $long, true)) { return false; }
    }
    return true;
}

/* ---------- reading ---------- */

function stats_competitions(): array
{
    try {
        $rows = db()->query('SELECT * FROM stat_competitions WHERE enabled = 1 AND synced_at IS NOT NULL ORDER BY FIELD(code, "PL","PD","SA","BL1","FL1","CL"), name')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}

function stats_competition(string $code): ?array
{
    $st = db()->prepare('SELECT * FROM stat_competitions WHERE code = ? AND enabled = 1');
    $st->execute([strtoupper($code)]);
    return $st->fetch() ?: null;
}

function stats_table(string $code, string $type = 'TOTAL'): array
{
    $st = db()->prepare('SELECT * FROM stat_standings WHERE comp_code = ? AND type = ? ORDER BY position ASC, points DESC');
    $st->execute([$code, in_array($type, ['TOTAL', 'HOME', 'AWAY'], true) ? $type : 'TOTAL']);
    return $st->fetchAll();
}

function stats_team_row(string $code, int $teamId): ?array
{
    $st = db()->prepare("SELECT * FROM stat_standings WHERE comp_code = ? AND type = 'TOTAL' AND team_id = ?");
    $st->execute([$code, $teamId]);
    return $st->fetch() ?: null;
}

/** Fixtures of a competition. $mode 'upcoming' | 'results'. */
function stats_matches(string $code, string $mode, int $limit = 30): array
{
    $sql = $mode === 'results'
        ? "SELECT * FROM stat_matches WHERE comp_code = ? AND status = 'FINISHED' ORDER BY kick_utc DESC LIMIT " . (int)$limit
        : "SELECT * FROM stat_matches WHERE comp_code = ? AND status IN ('SCHEDULED','TIMED','IN_PLAY','PAUSED') AND kick_utc > UTC_TIMESTAMP() - INTERVAL 3 HOUR ORDER BY kick_utc ASC LIMIT " . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute([$code]);
    return $st->fetchAll();
}

function stats_match(int $id): ?array
{
    $st = db()->prepare('SELECT m.*, c.name AS comp_name FROM stat_matches m LEFT JOIN stat_competitions c ON c.code = m.comp_code WHERE m.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Last finished matches of a team (any competition we hold). */
function stats_team_matches(int $teamId, string $mode = 'results', int $limit = 10): array
{
    $cond = $mode === 'results' ? "status = 'FINISHED'" : "status IN ('SCHEDULED','TIMED','IN_PLAY','PAUSED') AND kick_utc > UTC_TIMESTAMP() - INTERVAL 3 HOUR";
    $order = $mode === 'results' ? 'DESC' : 'ASC';
    $st = db()->prepare("SELECT * FROM stat_matches WHERE (home_id = ? OR away_id = ?) AND $cond ORDER BY kick_utc $order LIMIT " . (int)$limit);
    $st->execute([$teamId, $teamId]);
    return $st->fetchAll();
}

/** W/D/L letters, newest first, for a team from its finished matches. */
function stats_form(int $teamId, int $n = 5): array
{
    $out = [];
    foreach (stats_team_matches($teamId, 'results', $n) as $m) {
        $home = (int)$m['home_id'] === $teamId;
        $f = $home ? $m['home_goals'] : $m['away_goals'];
        $a = $home ? $m['away_goals'] : $m['home_goals'];
        $out[] = $f > $a ? 'W' : ($f < $a ? 'L' : 'D');
    }
    return $out;
}

/** Previous meetings of two teams that we hold, newest first. */
function stats_h2h(int $a, int $b, int $limit = 6): array
{
    $st = db()->prepare("SELECT * FROM stat_matches WHERE status = 'FINISHED' AND ((home_id = ? AND away_id = ?) OR (home_id = ? AND away_id = ?)) ORDER BY kick_utc DESC LIMIT " . (int)$limit);
    $st->execute([$a, $b, $b, $a]);
    return $st->fetchAll();
}

/** Looks for a stats fixture that matches one of our own site matches (same two teams, kickoff within 36 hours). */
function stats_find_fixture(string $home, string $away, string $kickoffLocal): ?array
{
    $ts = strtotime($kickoffLocal);
    if (!$ts) { return null; }
    $utc = gmdate('Y-m-d H:i:s', $ts);      // a timestamp is always UTC; gmdate prints it in UTC
    try {
        $st = db()->prepare("SELECT * FROM stat_matches WHERE kick_utc BETWEEN DATE_SUB(?, INTERVAL 36 HOUR) AND DATE_ADD(?, INTERVAL 36 HOUR) ORDER BY ABS(TIMESTAMPDIFF(MINUTE, kick_utc, ?)) ASC LIMIT 120");
        $st->execute([$utc, $utc, $utc]);
        foreach ($st->fetchAll() as $m) {
            if (team_same($m['home_name'], $home) && team_same($m['away_name'], $away)) { return $m; }
        }
    } catch (Throwable $e) {}
    return null;
}

/* ---------- the model ---------- */

/**
 * Team strengths from this season's finished matches of one competition.
 * Returns null if there are fewer than 30 finished matches (too little to say anything).
 */
function stats_strengths(string $code): ?array
{
    static $cache = [];
    if (array_key_exists($code, $cache)) { return $cache[$code]; }
    $st = db()->prepare("SELECT home_id, away_id, home_goals, away_goals FROM stat_matches WHERE comp_code = ? AND status = 'FINISHED' AND home_goals IS NOT NULL
                         AND season = (SELECT MAX(season) FROM stat_matches WHERE comp_code = ?)");
    $st->execute([$code, $code]);
    return $cache[$code] = stats_strengths_from($st->fetchAll());
}

/** Pure function (also used by tests): rows of [home_id, away_id, home_goals, away_goals] -> strengths. */
function stats_strengths_from(array $rows): ?array
{
    if (count($rows) < 30) { return null; }
    $hg = 0; $ag = 0;
    $t = [];
    foreach ($rows as $r) {
        $h = (int)$r['home_id']; $a = (int)$r['away_id'];
        $hg += (int)$r['home_goals']; $ag += (int)$r['away_goals'];
        foreach ([$h, $a] as $id) { if (!isset($t[$id])) { $t[$id] = ['hn' => 0, 'hf' => 0, 'ha' => 0, 'an' => 0, 'af' => 0, 'aa' => 0]; } }
        $t[$h]['hn']++; $t[$h]['hf'] += (int)$r['home_goals']; $t[$h]['ha'] += (int)$r['away_goals'];
        $t[$a]['an']++; $t[$a]['af'] += (int)$r['away_goals']; $t[$a]['aa'] += (int)$r['home_goals'];
    }
    $n = count($rows);
    $lh = max(0.5, $hg / $n); $la = max(0.4, $ag / $n);
    $k = 4;     // pull each team towards the league average, so a team with 3 games is not rated on luck
    $out = ['lh' => $lh, 'la' => $la, 'teams' => []];
    foreach ($t as $id => $x) {
        $out['teams'][$id] = [
            'h_att' => (($x['hf'] + $k * $lh) / ($x['hn'] + $k)) / $lh,
            'h_def' => (($x['ha'] + $k * $la) / ($x['hn'] + $k)) / $la,
            'a_att' => (($x['af'] + $k * $la) / ($x['an'] + $k)) / $la,
            'a_def' => (($x['aa'] + $k * $lh) / ($x['an'] + $k)) / $lh,
        ];
    }
    return $out;
}

function poisson_pmf(float $lambda, int $k): float
{
    $p = exp(-$lambda);
    for ($i = 1; $i <= $k; $i++) { $p *= $lambda / $i; }
    return $p;
}

/**
 * Win/draw/loss, over 2.5 and both-teams-to-score chances from two expected-goal numbers (independent Poisson). Percentages as floats.
 */
function stats_poisson_outcome(float $lamH, float $lamA, int $max = 9): array
{
    $ph = []; $pa = [];
    for ($i = 0; $i <= $max; $i++) { $ph[$i] = poisson_pmf($lamH, $i); $pa[$i] = poisson_pmf($lamA, $i); }
    $home = $draw = $away = $over = $btts = $tot = 0.0;
    $scores = [];
    for ($i = 0; $i <= $max; $i++) {
        for ($j = 0; $j <= $max; $j++) {
            $p = $ph[$i] * $pa[$j];
            $tot += $p;
            if ($i > $j) { $home += $p; } elseif ($i === $j) { $draw += $p; } else { $away += $p; }
            if ($i + $j >= 3) { $over += $p; }
            if ($i > 0 && $j > 0) { $btts += $p; }
            $scores[$i . '-' . $j] = $p;
        }
    }
    arsort($scores);
    $top = [];
    foreach (array_slice($scores, 0, 3, true) as $k => $p) { $top[] = ['score' => $k, 'pct' => round($p / $tot * 100, 1)]; }
    return ['home' => round($home / $tot * 100, 1), 'draw' => round($draw / $tot * 100, 1), 'away' => round($away / $tot * 100, 1),
            'over25' => round($over / $tot * 100, 1), 'btts' => round($btts / $tot * 100, 1), 'xg_home' => round($lamH, 2), 'xg_away' => round($lamA, 2), 'scores' => $top];
}

/** Prediction for a stats fixture, or null when the model has too little data. */
function stats_predict(array $m): ?array
{
    $s = stats_strengths($m['comp_code']);
    if (!$s || !isset($s['teams'][(int)$m['home_id']], $s['teams'][(int)$m['away_id']])) { return null; }
    $h = $s['teams'][(int)$m['home_id']]; $a = $s['teams'][(int)$m['away_id']];
    $lamH = $s['lh'] * $h['h_att'] * $a['a_def'];
    $lamA = $s['la'] * $a['a_att'] * $h['h_def'];
    $lamH = max(0.2, min(4.5, $lamH)); $lamA = max(0.2, min(4.5, $lamA));
    return stats_poisson_outcome($lamH, $lamA);
}

/** Plain-language match preview built only from our own stored numbers. Returns an array of paragraphs (plain text). */
function stats_preview(array $m): array
{
    $out = [];
    $home = $m['home_name']; $away = $m['away_name'];
    $when = date('l j F', strtotime($m['kick_utc'] . ' UTC'));
    $lead = $home . ' host ' . $away . ' on ' . $when . (!empty($m['comp_name']) ? ' in the ' . $m['comp_name'] : '') . '.';
    $th = $m['home_id'] ? stats_team_row($m['comp_code'], (int)$m['home_id']) : null;
    $ta = $m['away_id'] ? stats_team_row($m['comp_code'], (int)$m['away_id']) : null;
    if ($th && $ta && $th['played'] > 0 && $ta['played'] > 0) {
        $lead .= ' ' . $home . ' are ' . ordinal((int)$th['position']) . ' with ' . (int)$th['points'] . ' points from ' . (int)$th['played'] . ' games, while ' . $away . ' are '
              . ordinal((int)$ta['position']) . ' with ' . (int)$ta['points'] . ' from ' . (int)$ta['played'] . '.';
    }
    $out[] = $lead;
    if ($th && $ta && $th['played'] > 0 && $ta['played'] > 0) {
        $out[] = sprintf('%s have scored %.1f and conceded %.1f goals per game this season. %s have scored %.1f and conceded %.1f.',
                         $home, $th['gf'] / $th['played'], $th['ga'] / $th['played'], $away, $ta['gf'] / $ta['played'], $ta['ga'] / $ta['played']);
    }
    $fh = $m['home_id'] ? stats_form((int)$m['home_id']) : []; $fa = $m['away_id'] ? stats_form((int)$m['away_id']) : [];
    if ($fh && $fa) {
        $out[] = $home . ' recent form (newest first): ' . implode(' ', $fh) . '. ' . $away . ': ' . implode(' ', $fa) . '.';
    }
    if ($m['home_id'] && $m['away_id']) {
        $h2h = stats_h2h((int)$m['home_id'], (int)$m['away_id'], 5);
        if ($h2h) {
            $hw = $aw = $dr = 0;
            foreach ($h2h as $x) {
                if ($x['home_goals'] == $x['away_goals']) { $dr++; continue; }
                $winner = $x['home_goals'] > $x['away_goals'] ? (int)$x['home_id'] : (int)$x['away_id'];
                if ($winner === (int)$m['home_id']) { $hw++; } else { $aw++; }
            }
            $out[] = 'In the last ' . count($h2h) . ' meetings we hold: ' . $home . ' won ' . $hw . ', ' . $away . ' won ' . $aw . ', ' . $dr . ' drawn.';
        }
    }
    $p = stats_predict($m);
    if ($p) {
        $out[] = sprintf('Our statistical model (goals scored and conceded this season) gives %s %s%%, a draw %s%% and %s %s%%. Expected goals: %s %s, %s %s. Chance of over 2.5 goals: %s%%; both teams to score: %s%%. Most likely scores: %s.',
                         $home, $p['home'], $p['draw'], $away, $p['away'], $home, $p['xg_home'], $away, $p['xg_away'], $p['over25'], $p['btts'],
                         implode(', ', array_map(function ($s) { return $s['score'] . ' (' . $s['pct'] . '%)'; }, $p['scores'])));
    }
    return $out;
}

function ordinal(int $n): string
{
    $s = ['th', 'st', 'nd', 'rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}

function stats_form_html(array $form): string
{
    $h = '';
    foreach ($form as $f) {
        $h .= '<span class="fm fm-' . strtolower($f) . '">' . e($f) . '</span>';
    }
    return $h !== '' ? '<span class="fm-row">' . $h . '</span>' : '';
}

function stats_crest(?string $url, int $size = 22): string
{
    if (!$url || !preg_match('#^https://#', $url)) { return ''; }
    return '<img class="crest" src="' . e($url) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" referrerpolicy="no-referrer">';
}

/** Local time of a UTC datetime string, formatted. */
function stats_local(string $utc, string $fmt = 'D j M, H:i'): string
{
    return date($fmt, strtotime($utc . ' UTC'));
}

/** Tab row shared by the statistics pages. */
function stats_tabs(string $on): string
{
    $items = ['football' => ['football.php', 'Leagues'], 'predictions' => ['predictions.php', 'Match predictions']];
    if (function_exists('odds_on') && odds_on()) { $items['odds'] = ['odds.php', 'Odds comparison']; }
    $h = '<div class="tabs" style="margin:10px 0 16px">';
    foreach ($items as $k => $it) {
        $h .= '<a href="' . e(url($it[0])) . '"' . ($k === $on ? ' class="on" aria-current="page"' : '') . '>' . e($it[1]) . '</a>';
    }
    return $h . '</div>';
}

/** Short disclaimer shown under every prediction. */
function stats_disclaimer(): string
{
    return '<div class="note">Percentages come from a simple statistical model using goals scored and conceded this season. They are estimates for information, not tips and not guarantees, and the model knows nothing about injuries or line-ups. Data: football-data.org. 18+ only. <a href="' . e(url('responsible-gambling.php')) . '" style="text-decoration:underline">Gamble responsibly</a>.</div>';
}

/** Small three-way probability bar. */
function stats_prob_bar(array $p): string
{
    return '<div class="pb" role="img" aria-label="Home ' . e($p['home']) . '%, draw ' . e($p['draw']) . '%, away ' . e($p['away']) . '%">'
        . '<span class="pb-h" style="width:' . max(4, (float)$p['home']) . '%">' . e(round($p['home'])) . '%</span>'
        . '<span class="pb-d" style="width:' . max(4, (float)$p['draw']) . '%">' . e(round($p['draw'])) . '%</span>'
        . '<span class="pb-a" style="width:' . max(4, (float)$p['away']) . '%">' . e(round($p['away'])) . '%</span></div>';
}

/** Syncs one competition now (table + matches, about 8 seconds because of the free plan's rate limit). Returns a message for the admin. */
function stats_sync_comp(PDO $pdo, string $code): string
{
    $r = fd_get("competitions/$code/standings");
    if ($r['status'] !== 200 || !$r['data']) { return "$code: standings failed (HTTP {$r['status']}) {$r['error']}"; }
    $n = stats_save_standings($pdo, $code, $r['data']);
    sleep(7);
    $m = fd_get("competitions/$code/matches");
    if ($m['status'] !== 200 || !$m['data']) { return "$code: table saved, matches failed (HTTP {$m['status']}) {$m['error']}"; }
    $n += stats_save_matches($pdo, $code, $m['data']);
    return "$code: saved $n rows.";
}
