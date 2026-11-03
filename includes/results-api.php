<?php
/**
 * Automatic results helpers. Only simple, unambiguous markets are settled automatically;
 * anything else (handicaps, half-time markets, corners, cards, correct score...) stays pending for you to settle by hand.
 */
require_once __DIR__ . '/config-results.php';

function results_ready(): bool
{
    return RESULTS_API_KEY !== '';
}

function rs_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\.\s\/\-]/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Does $text name this team? (exact, or one contains the other when long enough) */
function rs_is_team(string $text, string $team): bool
{
    $a = rs_norm($text);
    $b = rs_norm($team);
    if ($a === '' || $b === '') { return false; }
    if ($a === $b) { return true; }
    return (mb_strlen($a) >= 4 && mb_strpos($b, $a) !== false) || (mb_strlen($b) >= 4 && mb_strpos($a, $b) !== false);
}

/**
 * Decides a tip from the 90-minute score. Returns 'won' | 'lost' | 'void' or null when it cannot decide safely.
 */
function settle_from_score(string $market, string $pick, int $home, int $away, string $homeName, string $awayName): ?string
{
    $m = rs_norm($market);
    $p = rs_norm($pick);
    if ($m === '' || $p === '') { return null; }
    // anything that is not plain full-time goals markets: do not guess
    if (preg_match('/half|1st|2nd|first|second|corner|card|booking|player|scorer|handicap|asian|minute|team total|exact|correct|acca|set |games/u', $m . ' ' . $p)) {
        return null;
    }
    $total = $home + $away;

    // ---- Over / Under X.5 goals ----
    if (preg_match('/\b(over|under)\s*(\d+(?:\.\d+)?)/u', $p, $x) || preg_match('/\b(over|under)\s*(\d+(?:\.\d+)?)/u', $m, $x)) {
        $line = (float)$x[2];
        if (abs($line - floor($line) - 0.5) > 0.0001) { return null; }     // only x.5 lines (no pushes)
        $over = $total > $line;
        return ($x[1] === 'over') === $over ? 'won' : 'lost';
    }

    // ---- Both teams to score ----
    if (preg_match('/btts|both teams|gg\/ng|\bgg\b|\bng\b/u', $m) || preg_match('/btts|both teams to score/u', $p)) {
        $yes = ($home > 0 && $away > 0);
        if (preg_match('/^(yes|gg)\b|both teams to score\s*(yes)?$|btts\s*(yes)?$|\byes\b/u', $p) && !preg_match('/\bno\b|^ng\b/u', $p)) { return $yes ? 'won' : 'lost'; }
        if (preg_match('/^(no|ng)\b|\bno\b|not/u', $p)) { return $yes ? 'lost' : 'won'; }
        return null;
    }

    // ---- Double chance ----
    if (strpos($m, 'double') !== false || preg_match('/^(1x|x2|12)$/', $p) || preg_match('/\b(or|\/)\b.*\b(draw|x)\b|\b(draw|x)\b.*\b(or|\/)\b/u', $p)) {
        $res = $home > $away ? '1' : ($home < $away ? '2' : 'x');
        if ($p === '1x') { return in_array($res, ['1', 'x'], true) ? 'won' : 'lost'; }
        if ($p === 'x2') { return in_array($res, ['2', 'x'], true) ? 'won' : 'lost'; }
        if ($p === '12') { return $res !== 'x' ? 'won' : 'lost'; }
        $parts = preg_split('/\s+(?:or|\/)\s+|\//u', $p);
        if (count($parts) !== 2) { return null; }
        $cover = [];
        foreach ($parts as $part) {
            if ($part === 'draw' || $part === 'x') { $cover[] = 'x'; }
            elseif (rs_is_team($part, $homeName)) { $cover[] = '1'; }
            elseif (rs_is_team($part, $awayName)) { $cover[] = '2'; }
            else { return null; }
        }
        return in_array($res, $cover, true) ? 'won' : 'lost';
    }

    // ---- Draw no bet ----
    if (strpos($m, 'draw no bet') !== false || $m === 'dnb') {
        if ($home === $away) { return 'void'; }
        if (rs_is_team($p, $homeName) || $p === '1') { return $home > $away ? 'won' : 'lost'; }
        if (rs_is_team($p, $awayName) || $p === '2') { return $away > $home ? 'won' : 'lost'; }
        return null;
    }

    // ---- 1X2 / Match result ----
    if ($m === '1x2' || strpos($m, 'match result') !== false || strpos($m, 'full time result') !== false || $m === 'result' || strpos($m, 'to win') !== false) {
        $res = $home > $away ? '1' : ($home < $away ? '2' : 'x');
        $pickRes = null;
        if ($p === '1' || $p === 'home' || $p === 'home win') { $pickRes = '1'; }
        elseif ($p === '2' || $p === 'away' || $p === 'away win') { $pickRes = '2'; }
        elseif ($p === 'x' || $p === 'draw') { $pickRes = 'x'; }
        else {
            $clean = trim(preg_replace('/\b(to win|win|wins)\b/u', '', $p));
            $h = rs_is_team($clean, $homeName);
            $a = rs_is_team($clean, $awayName);
            if ($h && !$a) { $pickRes = '1'; } elseif ($a && !$h) { $pickRes = '2'; }
        }
        return $pickRes === null ? null : ($pickRes === $res ? 'won' : 'lost');
    }
    return null;
}

/** GET from API-Football. Returns decoded JSON array or null. */
function results_api_get(string $path, array $query = []): ?array
{
    $ch = curl_init('https://v3.football.api-sports.io/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['x-apisports-key: ' . RESULTS_API_KEY, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return ($code === 200 && is_array($j)) ? $j : null;
}

/**
 * Finished score of a fixture: ['home'=>int,'away'=>int,'status'=>'FT'] using the 90-minute score, or null if not finished/unknown.
 */
function results_fixture_score(int $fixtureId): ?array
{
    $j = results_api_get('fixtures', ['id' => $fixtureId]);
    $f = $j['response'][0] ?? null;
    if (!is_array($f)) { return null; }
    $status = (string)($f['fixture']['status']['short'] ?? '');
    if (!in_array($status, ['FT', 'AET', 'PEN'], true)) { return null; }
    $h = $f['score']['fulltime']['home'] ?? null;
    $a = $f['score']['fulltime']['away'] ?? null;
    if ($h === null || $a === null) { return null; }
    return ['home' => (int)$h, 'away' => (int)$a, 'status' => $status];
}

function results_log(string $line): void
{
    @file_put_contents(dirname(__DIR__) . '/logs/auto-results.log', '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}
