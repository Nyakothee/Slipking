<?php
/**
 * Odds comparison from The Odds API free plan (500 credits a month). Pages read our own tables only; cron/sync-odds.php fills them.
 * One credit = one league fetched once (1X2 market, one region). The sync stops when the credits left drop under the guard setting.
 * Odds are for information. We are not a bookmaker and do not take bets.
 */
require_once __DIR__ . '/config-data.php';

function odds_ready(): bool { return ODDS_API_KEY !== ''; }

/** Country of the visitor (2 letters) from Cloudflare, or from the logged-in profile. '' when unknown. */
function geo_country(): string
{
    $c = strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
    if (preg_match('/^[A-Z]{2}$/', $c) && $c !== 'XX' && $c !== 'T1') { return $c; }
    try {
        $u = function_exists('current_user') ? current_user() : null;
        if ($u && !empty($u['country']) && preg_match('/^[A-Za-z]{2}$/', $u['country'])) { return strtoupper($u['country']); }
    } catch (Throwable $e) {}
    return '';
}

/** True when the owner blocked odds and betting links for the visitor's country (setting odds_blocked_countries, e.g. "US,GB"). */
function odds_hidden_here(): bool
{
    $list = array_filter(array_map('trim', explode(',', strtoupper((string)setting('odds_blocked_countries', '')))));
    if (!$list) { return false; }
    $c = geo_country();
    return $c !== '' && in_array($c, $list, true);
}

function odds_on(): bool
{
    return odds_ready() && setting('odds_enabled', '0') === '1' && !odds_hidden_here();
}

function odds_sports_all(): array
{
    return ['soccer_epl' => 'Premier League', 'soccer_spain_la_liga' => 'La Liga', 'soccer_italy_serie_a' => 'Serie A', 'soccer_germany_bundesliga' => 'Bundesliga',
            'soccer_france_ligue_one' => 'Ligue 1', 'soccer_uefa_champs_league' => 'Champions League', 'soccer_uefa_europa_league' => 'Europa League',
            'soccer_efl_champ' => 'Championship', 'soccer_netherlands_eredivisie' => 'Eredivisie', 'soccer_portugal_primeira_liga' => 'Primeira Liga',
            'soccer_brazil_campeonato' => 'Brasileirao', 'soccer_kenya_premier_league' => 'Kenya Premier League'];
}

function odds_selected_sports(): array
{
    $all = odds_sports_all();
    $out = [];
    foreach (explode(',', (string)setting('odds_sports', 'soccer_epl,soccer_spain_la_liga,soccer_italy_serie_a,soccer_germany_bundesliga')) as $k) {
        $k = trim($k);
        if (isset($all[$k])) { $out[] = $k; }
    }
    return array_values(array_unique($out));
}

/** GET odds for one sport. Returns ['status','data','error','remaining']. */
function odds_api_get(string $sport): array
{
    $url = 'https://api.the-odds-api.com/v4/sports/' . rawurlencode($sport) . '/odds/?' . http_build_query(['apiKey' => ODDS_API_KEY, 'regions' => 'eu', 'markets' => 'h2h', 'oddsFormat' => 'decimal', 'dateFormat' => 'iso']);
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
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
            'remaining' => isset($hdr['x-requests-remaining']) ? (int)$hdr['x-requests-remaining'] : null];
}

/** Saves one API response (list of events). Returns number of events saved. */
function odds_save_payload(PDO $pdo, string $sport, array $events): int
{
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach ($events as $ev) {
        if (!is_array($ev) || empty($ev['id']) || empty($ev['home_team']) || empty($ev['away_team']) || empty($ev['commence_time'])) { continue; }
        $ts = strtotime((string)$ev['commence_time']);
        if (!$ts) { continue; }
        $home = (string)$ev['home_team']; $away = (string)$ev['away_team'];
        $pdo->prepare('INSERT INTO odds_events (ext_id, sport_key, commence_at, home_team, away_team, updated_at) VALUES (?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE commence_at = VALUES(commence_at), home_team = VALUES(home_team), away_team = VALUES(away_team), updated_at = VALUES(updated_at)')
            ->execute([substr((string)$ev['id'], 0, 40), $sport, gmdate('Y-m-d H:i:s', $ts), substr($home, 0, 80), substr($away, 0, 80), $now]);
        $q = $pdo->prepare('SELECT id FROM odds_events WHERE ext_id = ?');
        $q->execute([substr((string)$ev['id'], 0, 40)]);
        $eid = (int)$q->fetchColumn();
        if (!$eid) { continue; }
        $books = 0;
        foreach ((array)($ev['bookmakers'] ?? []) as $b) {
            $row = odds_parse_bookmaker($b, $home, $away);
            if (!$row) { continue; }
            $pdo->prepare('REPLACE INTO odds_quotes (event_id, bookmaker_key, bookmaker_title, home_odds, draw_odds, away_odds, updated_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$eid, substr($row['key'], 0, 40), substr($row['title'], 0, 60), $row['home'], $row['draw'], $row['away'], $now]);
            $books++;
        }
        if ($books) {
            $pdo->prepare('DELETE FROM odds_quotes WHERE event_id = ? AND updated_at < ?')->execute([$eid, $now]);
            odds_add_history($pdo, $eid, $now);
        }
        $n++;
    }
    return $n;
}

/** Reads one bookmaker block of the API into ['key','title','home','draw','away'] (h2h market only) or null. */
function odds_parse_bookmaker($b, string $home, string $away): ?array
{
    if (!is_array($b) || empty($b['key'])) { return null; }
    foreach ((array)($b['markets'] ?? []) as $mk) {
        if (($mk['key'] ?? '') !== 'h2h') { continue; }
        $r = ['key' => (string)$b['key'], 'title' => (string)($b['title'] ?? $b['key']), 'home' => null, 'draw' => null, 'away' => null];
        foreach ((array)($mk['outcomes'] ?? []) as $o) {
            $price = isset($o['price']) ? (float)$o['price'] : 0.0;
            if ($price < 1.01 || $price > 1000) { continue; }
            $name = (string)($o['name'] ?? '');
            if (strcasecmp($name, 'Draw') === 0) { $r['draw'] = round($price, 2); }
            elseif ($name === $home) { $r['home'] = round($price, 2); }
            elseif ($name === $away) { $r['away'] = round($price, 2); }
        }
        if ($r['home'] !== null && $r['away'] !== null) { return $r; }
    }
    return null;
}

/** best/average per outcome from quote rows */
function odds_summary(array $quotes): array
{
    $s = ['best_home' => null, 'best_draw' => null, 'best_away' => null, 'avg_home' => null, 'avg_draw' => null, 'avg_away' => null, 'books' => count($quotes)];
    foreach (['home', 'draw', 'away'] as $k) {
        $v = [];
        foreach ($quotes as $q) { if ($q[$k . '_odds'] !== null && (float)$q[$k . '_odds'] > 0) { $v[] = (float)$q[$k . '_odds']; } }
        if ($v) { $s['best_' . $k] = max($v); $s['avg_' . $k] = round(array_sum($v) / count($v), 2); }
    }
    return $s;
}

function odds_add_history(PDO $pdo, int $eid, string $now): void
{
    $quotes = odds_quotes($eid);
    if (!$quotes) { return; }
    $s = odds_summary($quotes);
    $last = $pdo->prepare('SELECT avg_home, avg_draw, avg_away, fetched_at FROM odds_history WHERE event_id = ? ORDER BY fetched_at DESC, id DESC LIMIT 1');
    $last->execute([$eid]);
    $l = $last->fetch();
    if ($l && (float)$l['avg_home'] === (float)$s['avg_home'] && (float)$l['avg_away'] === (float)$s['avg_away'] && (float)$l['avg_draw'] === (float)$s['avg_draw'] && strtotime($l['fetched_at']) > time() - 6 * 3600) { return; }
    $pdo->prepare('INSERT INTO odds_history (event_id, fetched_at, best_home, best_draw, best_away, avg_home, avg_draw, avg_away, books) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$eid, $now, $s['best_home'], $s['best_draw'], $s['best_away'], $s['avg_home'], $s['avg_draw'], $s['avg_away'], $s['books']]);
}

/* ---------- reading ---------- */

function odds_quotes(int $eid): array
{
    $st = db()->prepare('SELECT * FROM odds_quotes WHERE event_id = ? ORDER BY bookmaker_title');
    $st->execute([$eid]);
    return $st->fetchAll();
}

function odds_event(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM odds_events WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function odds_history_rows(int $eid): array
{
    $st = db()->prepare('SELECT * FROM odds_history WHERE event_id = ? ORDER BY fetched_at ASC, id ASC LIMIT 200');
    $st->execute([$eid]);
    return $st->fetchAll();
}

/** Upcoming events that have quotes. */
function odds_upcoming(string $sport = '', int $limit = 80): array
{
    $sql = 'SELECT e.*, (SELECT COUNT(*) FROM odds_quotes q WHERE q.event_id = e.id) AS books FROM odds_events e
            WHERE e.commence_at > UTC_TIMESTAMP() - INTERVAL 2 HOUR AND e.commence_at < UTC_TIMESTAMP() + INTERVAL 10 DAY' . ($sport !== '' ? ' AND e.sport_key = ?' : '') . '
            HAVING books > 0 ORDER BY e.commence_at ASC LIMIT ' . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($sport !== '' ? [$sport] : []);
    return $st->fetchAll();
}

/** Finds our odds event for two team names and a UTC kickoff (within 36h). */
function odds_find_event(string $home, string $away, string $utcDate): ?array
{
    try {
        $ts = strtotime($utcDate . ' UTC');
        if (!$ts) { return null; }
        $st = db()->prepare('SELECT * FROM odds_events WHERE commence_at BETWEEN ? AND ? LIMIT 200');
        $st->execute([gmdate('Y-m-d H:i:s', $ts - 36 * 3600), gmdate('Y-m-d H:i:s', $ts + 36 * 3600)]);
        foreach ($st->fetchAll() as $e) {
            if (team_same($e['home_team'], $home) && team_same($e['away_team'], $away)) { return $e; }
        }
    } catch (Throwable $e) {}
    return null;
}

/** Slug of one of our affiliate bookmakers that matches the API's bookmaker name, or ''. */
function odds_affiliate_slug(string $title): string
{
    $norm = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower($s)); };
    $t = $norm($title);
    if ($t === '') { return ''; }
    foreach (bookmakers_active() as $b) {
        $n = $norm($b['name']);
        if ($n !== '' && ($n === $t || strpos($t, $n) !== false || strpos($n, $t) !== false)) { return $b['slug']; }
    }
    return '';
}

/** Implied probabilities (percent) with the bookmaker margin removed, from average odds. */
function odds_implied(array $s): ?array
{
    if (!$s['avg_home'] || !$s['avg_away']) { return null; }
    $ph = 1 / $s['avg_home']; $pa = 1 / $s['avg_away']; $pd = $s['avg_draw'] ? 1 / $s['avg_draw'] : 0.0;
    $t = $ph + $pa + $pd;
    return ['home' => round($ph / $t * 100, 1), 'draw' => round($pd / $t * 100, 1), 'away' => round($pa / $t * 100, 1), 'margin' => round(($t - 1) * 100, 1)];
}

/** Comparison table. $pred = optional statistical model result for the same match. */
function odds_compare_html(array $ev, ?array $pred = null): string
{
    $quotes = odds_quotes((int)$ev['id']);
    if (!$quotes) { return ''; }
    $s = odds_summary($quotes);
    $imp = odds_implied($s);
    $h = '<div class="box2 odds-box" style="margin:14px 0"><h3>Odds comparison <small style="font-weight:400;color:var(--mute)">(1X2, ' . count($quotes) . ' bookmakers)</small></h3>'
       . '<div class="scroll"><table class="bm-table odds-t"><thead><tr><th>Bookmaker</th><th>1</th><th>X</th><th>2</th><th></th></tr></thead><tbody>';
    foreach ($quotes as $q) {
        $slug = odds_affiliate_slug($q['bookmaker_title']);
        $cell = function ($k) use ($q, $s) {
            $v = $q[$k . '_odds'];
            if ($v === null) { return '<td>-</td>'; }
            $best = $s['best_' . $k] !== null && abs((float)$v - (float)$s['best_' . $k]) < 0.001;
            return '<td' . ($best ? ' class="odds-best"' : '') . '>' . number_format((float)$v, 2) . '</td>';
        };
        $h .= '<tr><td>' . e($q['bookmaker_title']) . '</td>' . $cell('home') . $cell('draw') . $cell('away') . '<td>'
            . ($slug ? '<a class="btn tiny gold" rel="sponsored nofollow noopener" target="_blank" href="' . e(bookmaker_go_url($slug, null, 'odds')) . '">Bet</a>' : '') . '</td></tr>';
    }
    $h .= '<tr class="odds-avg"><td><strong>Best price</strong></td><td>' . ($s['best_home'] !== null ? number_format($s['best_home'], 2) : '-') . '</td><td>' . ($s['best_draw'] !== null ? number_format($s['best_draw'], 2) : '-') . '</td><td>' . ($s['best_away'] !== null ? number_format($s['best_away'], 2) : '-') . '</td><td></td></tr>';
    $h .= '</tbody></table></div>';
    if ($imp) {
        $h .= '<p style="font-size:14px;margin-top:8px">Market view (margin removed): ' . e($ev['home_team']) . ' ' . $imp['home'] . '%, draw ' . $imp['draw'] . '%, ' . e($ev['away_team']) . ' ' . $imp['away'] . '%.'
            . ($pred ? ' Our model: ' . $pred['home'] . '% / ' . $pred['draw'] . '% / ' . $pred['away'] . '%.' : '') . ' Average bookmaker margin ' . $imp['margin'] . '%.</p>';
    }
    $h .= '<p style="font-size:13px;color:var(--mute)">Updated ' . e(date('j M, H:i', strtotime($quotes[0]['updated_at']))) . '. Prices change fast, always check the bookmaker before you bet. 18+ only. <a href="' . e(url('odds-event.php?id=' . (int)$ev['id'])) . '">Odds movement</a></p>';
    return $h . bookmaker_18_line() . '</div>';
}

/** SVG line chart of average odds over time. Returns '' with fewer than 2 points. */
function odds_chart_svg(array $hist): string
{
    if (count($hist) < 2) { return ''; }
    $W = 640; $H = 220; $pl = 40; $pr = 12; $pt = 14; $pb = 26;
    $series = ['avg_home' => ['Home', '#0e6b37'], 'avg_draw' => ['Draw', '#9a7b00'], 'avg_away' => ['Away', '#b3261e']];
    $vals = [];
    foreach ($hist as $r) { foreach ($series as $k => $_) { if ($r[$k] !== null) { $vals[] = (float)$r[$k]; } } }
    if (!$vals) { return ''; }
    $min = min($vals); $max = max($vals);
    if ($max - $min < 0.2) { $min -= 0.1; $max += 0.1; }
    $t0 = strtotime($hist[0]['fetched_at']); $t1 = strtotime($hist[count($hist) - 1]['fetched_at']);
    $span = max(1, $t1 - $t0);
    $x = function ($t) use ($t0, $span, $W, $pl, $pr) { return $pl + ($t - $t0) / $span * ($W - $pl - $pr); };
    $y = function ($v) use ($min, $max, $H, $pt, $pb) { return $pt + ($max - $v) / ($max - $min) * ($H - $pt - $pb); };
    $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Average odds movement" class="odds-chart" xmlns="http://www.w3.org/2000/svg">';
    for ($i = 0; $i <= 3; $i++) {
        $v = $min + ($max - $min) * $i / 3; $yy = round($y($v), 1);
        $svg .= '<line x1="' . $pl . '" x2="' . ($W - $pr) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="currentColor" stroke-opacity=".12"/><text x="' . ($pl - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" font-size="11" fill="currentColor" fill-opacity=".6">' . number_format($v, 2) . '</text>';
    }
    foreach ($series as $k => $meta) {
        $pts = [];
        foreach ($hist as $r) { if ($r[$k] !== null) { $pts[] = round($x(strtotime($r['fetched_at'])), 1) . ',' . round($y((float)$r[$k]), 1); } }
        if (count($pts) >= 2) { $svg .= '<polyline fill="none" stroke="' . $meta[1] . '" stroke-width="2" points="' . implode(' ', $pts) . '"/>'; }
    }
    $svg .= '<text x="' . $pl . '" y="' . ($H - 6) . '" font-size="11" fill="currentColor" fill-opacity=".6">' . e(date('j M H:i', $t0)) . '</text><text x="' . ($W - $pr) . '" y="' . ($H - 6) . '" text-anchor="end" font-size="11" fill="currentColor" fill-opacity=".6">' . e(date('j M H:i', $t1)) . '</text></svg>';
    $leg = '<p class="odds-legend">';
    foreach ($series as $k => $meta) { $leg .= '<span><i style="background:' . $meta[1] . '"></i>' . $meta[0] . '</span> '; }
    return $svg . $leg . '</p>';
}
