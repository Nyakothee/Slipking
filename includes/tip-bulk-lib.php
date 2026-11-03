<?php
/**
 * Bulk tip import helpers (used by admin/tip-bulk.php). Pure functions first (easy to test), database helpers last.
 * Kickoff times are typed in server time (East Africa Time, Africa/Nairobi), the same as the single "Add a tip" form.
 */

const BULK_MAX_ROWS  = 500;
const BULK_MAX_BYTES = 1048576;
const BULK_COLUMNS   = ['league', 'home', 'away', 'kickoff', 'market', 'pick', 'odds', 'confidence', 'vip', 'analysis'];

/** Header text -> canonical column name, or null. */
function bulk_header_name(string $h): ?string
{
    $h = preg_replace('/[^a-z0-9]/', '', strtolower(trim($h, " \t\n\r\0\x0B\xEF\xBB\xBF")));
    $alias = [
        'league' => 'league', 'competition' => 'league',
        'home' => 'home', 'hometeam' => 'home', 'hometeamplayer' => 'home',
        'away' => 'away', 'awayteam' => 'away', 'awayteamplayer' => 'away',
        'kickoff' => 'kickoff', 'kickofftime' => 'kickoff', 'kickoffeat' => 'kickoff', 'datetime' => 'kickoff', 'date' => 'kickoff',
        'market' => 'market', 'pick' => 'pick', 'tip' => 'pick',
        'odds' => 'odds', 'confidence' => 'confidence', 'conf' => 'confidence',
        'vip' => 'vip', 'isvip' => 'vip',
        'analysis' => 'analysis', 'notes' => 'analysis',
    ];
    return $alias[$h] ?? null;
}

/**
 * Parses pasted/uploaded CSV text (comma, semicolon or tab separated; quotes allowed).
 * Returns ['rows' => list of assoc arrays keyed by BULK_COLUMNS, 'line' => list of source line numbers, 'errors' => list of strings].
 */
function bulk_parse_csv(string $text): array
{
    $out = ['rows' => [], 'line' => [], 'errors' => []];
    if (strlen($text) > BULK_MAX_BYTES) {
        $out['errors'][] = 'The data is larger than 1 MB.';
        return $out;
    }
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
        $text = substr($text, 3);
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    }
    $text = str_replace("\0", '', $text);
    if (trim($text) === '') {
        $out['errors'][] = 'Nothing to import: the data is empty.';
        return $out;
    }
    // delimiter guess from the first non-empty line
    $first = '';
    foreach (preg_split('/\r\n|\r|\n/', $text) as $l) {
        if (trim($l) !== '') { $first = $l; break; }
    }
    $delim = ',';
    $best  = substr_count($first, ',');
    foreach (["\t", ';'] as $d) {
        if (substr_count($first, $d) > $best) { $best = substr_count($first, $d); $delim = $d; }
    }
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $text);
    rewind($fh);
    $map = null;            // column index -> name
    $n = 0;
    $lineNo = 0;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $lineNo++;
        if ($cells === [null] || (count($cells) === 1 && trim((string)$cells[0]) === '')) {
            continue;       // blank line
        }
        $cells = array_map(function ($c) { return trim((string)$c); }, $cells);
        if ($map === null) {
            $isHeader = bulk_header_name($cells[0]) === 'league' && count(array_filter($cells, function ($c) { return bulk_header_name($c) !== null; })) >= 4;
            if ($isHeader) {
                $map = [];
                foreach ($cells as $i => $c) {
                    $name = bulk_header_name($c);
                    if ($name !== null && !in_array($name, $map, true)) { $map[$i] = $name; }
                }
                foreach (['league', 'home', 'away', 'kickoff', 'market', 'pick', 'odds'] as $req) {
                    if (!in_array($req, $map, true)) { $out['errors'][] = 'The header row has no "' . $req . '" column.'; }
                }
                if ($out['errors']) { fclose($fh); return $out; }
                continue;
            }
            $map = BULK_COLUMNS;    // no header: fixed column order
        }
        $n++;
        if ($n > BULK_MAX_ROWS) {
            $out['errors'][] = 'Too many rows: the limit is ' . BULK_MAX_ROWS . ' per import. Split the file and import it in parts.';
            fclose($fh);
            $out['rows'] = []; $out['line'] = [];
            return $out;
        }
        $row = array_fill_keys(BULK_COLUMNS, '');
        $extra = false;
        foreach ($cells as $i => $c) {
            if (isset($map[$i])) { $row[$map[$i]] = $c; }
            elseif ($c !== '') { $extra = true; }
        }
        $row['_extra'] = $extra;
        $out['rows'][] = $row;
        $out['line'][] = $lineNo;
    }
    fclose($fh);
    if (!$out['rows'] && !$out['errors']) {
        $out['errors'][] = 'No data rows found.';
    }
    return $out;
}

/** Builds lower-case league name -> list of ids from rows having id and name. */
function bulk_league_map(array $leagues): array
{
    $m = [];
    foreach ($leagues as $l) {
        $m[mb_strtolower(trim((string)$l['name']))][] = (int)$l['id'];
    }
    return $m;
}

/** Strict "Y-m-d H:i" (a "T" instead of the space is accepted) -> unix time in $tz, or null. */
function bulk_parse_kickoff(string $s, string $tz = 'Africa/Nairobi'): ?int
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/', trim($s), $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i', $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5], new DateTimeZone($tz));
    return $dt ? $dt->getTimestamp() : null;
}

/**
 * Validates one raw row. Returns ['ok' => bool, 'errors' => [...], 'clean' => [...]].
 * 'clean' always holds the best-effort normalised values (used for display); only trust it when ok is true.
 * $now is passed in so tests can fix the clock.
 */
function bulk_validate_row(array $raw, array $leagueMap, int $now, string $tz = 'Africa/Nairobi'): array
{
    $err = [];
    $g = function ($k) use ($raw) { return trim((string)($raw[$k] ?? '')); };
    $strip = function ($s) { return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s); };
    $c = [
        'league' => $strip($g('league')), 'league_id' => 0,
        'home' => $strip($g('home')), 'away' => $strip($g('away')),
        'kickoff' => $g('kickoff'), 'kickoff_ts' => 0, 'kickoff_sql' => '',
        'market' => $strip($g('market')), 'pick' => $strip($g('pick')),
        'odds' => '', 'confidence' => 3, 'vip' => 0,
        'analysis' => str_replace(["\r\n", "\r"], "\n", $strip(str_replace(["\r\n", "\r"], "\n", $g('analysis')))),
    ];
    if (!empty($raw['_extra'])) {
        $err[] = 'More columns than expected. Put "quotes" around any text that contains commas.';
    }
    // league
    $ln = mb_strtolower($c['league']);
    if ($ln === '') { $err[] = 'League is empty.'; }
    elseif (!isset($leagueMap[$ln])) { $err[] = 'Unknown league "' . $c['league'] . '" (use a name exactly as in Sports and leagues).'; }
    elseif (count($leagueMap[$ln]) > 1) { $err[] = 'League name "' . $c['league'] . '" matches more than one league.'; }
    else { $c['league_id'] = $leagueMap[$ln][0]; }
    // teams
    foreach (['home' => 'Home', 'away' => 'Away'] as $k => $label) {
        $len = mb_strlen($c[$k]);
        if ($len < 2 || $len > 80) { $err[] = $label . ' team must be 2 to 80 characters.'; }
    }
    if ($c['home'] !== '' && mb_strtolower($c['home']) === mb_strtolower($c['away'])) { $err[] = 'Home and away must be different.'; }
    // kickoff
    $ts = bulk_parse_kickoff($c['kickoff'], $tz);
    if ($ts === null) { $err[] = 'Kickoff must look like 2026-10-25 20:00.'; }
    elseif ($ts <= $now + 60) { $err[] = 'Kickoff is not in the future. Tips are posted before the match starts.'; }
    elseif ($ts > $now + 400 * 86400) { $err[] = 'Kickoff is more than 400 days away. Check the year.'; }
    else {
        $c['kickoff_ts'] = $ts;
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone(new DateTimeZone($tz));
        $c['kickoff_sql'] = $dt->format('Y-m-d H:i:00');
    }
    // market / pick
    if ($c['market'] === '' || mb_strlen($c['market']) > 40) { $err[] = 'Market is required (up to 40 characters).'; }
    if ($c['pick'] === '' || mb_strlen($c['pick']) > 120) { $err[] = 'Pick is required (up to 120 characters).'; }
    // odds
    $o = str_replace(',', '.', $g('odds'));
    if (!preg_match('/^\d{1,4}(\.\d{1,3})?$/', $o) || (float)$o < 1.01 || (float)$o > 1000) { $err[] = 'Odds must be a number from 1.01 to 1000.'; }
    else { $c['odds'] = number_format((float)$o, 2, '.', ''); }
    // confidence
    $cf = $g('confidence');
    if ($cf === '') { $c['confidence'] = 3; }
    elseif (!preg_match('/^[1-5]$/', $cf)) { $err[] = 'Confidence must be 1 to 5 (or empty for 3).'; }
    else { $c['confidence'] = (int)$cf; }
    // vip
    $v = strtolower($g('vip'));
    if ($v === '' || in_array($v, ['0', 'no', 'n', 'false', 'free'], true)) { $c['vip'] = 0; }
    elseif (in_array($v, ['1', 'yes', 'y', 'true', 'vip'], true)) { $c['vip'] = 1; }
    else { $err[] = 'VIP must be 0 or 1.'; }
    // analysis
    if (mb_strlen($c['analysis']) > 2000) { $err[] = 'Analysis is longer than 2000 characters.'; }

    return ['ok' => !$err, 'errors' => $err, 'clean' => $c];
}

/** Key identifying a tip inside a batch (league, teams, kickoff, market, pick - case-insensitive). */
function bulk_dup_key(array $c): string
{
    return mb_strtolower($c['league_id'] . '|' . $c['home'] . '|' . $c['away'] . '|' . $c['kickoff_sql'] . '|' . $c['market'] . '|' . $c['pick']);
}

/** Existing match id for league + teams + kickoff, or 0. */
function bulk_find_match(PDO $pdo, array $c): int
{
    $st = $pdo->prepare('SELECT id FROM matches WHERE league_id = ? AND LOWER(home_team) = LOWER(?) AND LOWER(away_team) = LOWER(?) AND kickoff_at = ? ORDER BY id LIMIT 1');
    $st->execute([$c['league_id'], $c['home'], $c['away'], $c['kickoff_sql']]);
    return (int)$st->fetchColumn();
}

/** True if a live (not deleted) tip with the same market and pick already exists on that match. */
function bulk_tip_exists(PDO $pdo, int $matchId, array $c): bool
{
    $st = $pdo->prepare('SELECT 1 FROM tips WHERE match_id = ? AND deleted_at IS NULL AND LOWER(market) = LOWER(?) AND LOWER(pick) = LOWER(?) LIMIT 1');
    $st->execute([$matchId, $c['market'], $c['pick']]);
    return (bool)$st->fetchColumn();
}

/**
 * Validates every raw row (format + duplicates, in the batch and in the database). Read-only.
 * Returns a list parallel to $rows: ['ok', 'errors', 'clean', 'match_id'].
 */
function bulk_validate_all(PDO $pdo, array $rows, array $leagueMap, int $now): array
{
    $seen = [];
    $res = [];
    foreach ($rows as $raw) {
        $r = bulk_validate_row($raw, $leagueMap, $now, defined('TIMEZONE') ? TIMEZONE : 'Africa/Nairobi');
        $r['match_id'] = 0;
        if ($r['ok']) {
            $key = bulk_dup_key($r['clean']);
            if (isset($seen[$key])) {
                $r['ok'] = false; $r['errors'][] = 'Duplicate of row ' . $seen[$key] . ' in this import.';
            } else {
                $seen[$key] = count($res) + 1;
                $r['match_id'] = bulk_find_match($pdo, $r['clean']);
                if ($r['match_id'] && bulk_tip_exists($pdo, $r['match_id'], $r['clean'])) {
                    $r['ok'] = false; $r['errors'][] = 'Duplicate: this tip already exists on that match.';
                }
            }
        }
        $res[] = $r;
    }
    return $res;
}
