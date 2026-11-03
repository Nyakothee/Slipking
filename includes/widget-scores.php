<?php
/**
 * Tips settled from live-score widgets, without any API key.
 *
 * Each widget can be marked "use for results". Its page is read (like a visitor would see it), and for every match that
 * has pending tips we look for BOTH team names with a final score between them and a "FT / Full time / Finished" marker.
 * Only clear, finished results are used. Anything unclear is left for the admin to settle by hand (as before).
 * Works when the widget's page contains the scores as ordinary text. Widgets that draw their scores with JavaScript only
 * cannot be read this way; for those, settle by hand or paste a feed link that does contain the scores.
 */

require_once __DIR__ . '/results-api.php';

function wscore_ready(): bool
{
    return widgets_ready();
}

function wscore_enabled(): bool { return setting('widget_autosettle', '0') === '1'; }

/** Pages to read: widgets switched on and marked for results. */
function wscore_feeds(): array
{
    if (!wscore_ready()) { return []; }
    try {
        db()->query('SELECT use_for_results FROM widgets LIMIT 1');
    } catch (Throwable $e) {
        try { db()->exec('ALTER TABLE widgets ADD COLUMN use_for_results TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN feed_url VARCHAR(500) NOT NULL DEFAULT \'\''); } catch (Throwable $e2) { return []; }
    }
    $out = [];
    foreach (db()->query('SELECT id, title, code, feed_url FROM widgets WHERE is_active = 1 AND use_for_results = 1')->fetchAll() as $w) {
        $url = trim((string)$w['feed_url']);
        if ($url === '') { $p = widget_parse((string)$w['code']); $url = $p['type'] === 'url' ? $p['src'] : ''; }
        if (preg_match('~^https?://~i', $url)) { $out[] = ['id' => (int)$w['id'], 'title' => $w['title'], 'url' => $url]; }
    }
    return $out;
}

function wscore_fetch(string $url): ?string
{
    if (!function_exists('curl_init')) { return null; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SlipKingResults/1.0)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.8', 'Accept-Language: en']]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) { return null; }
    return substr((string)$body, 0, 2000000);
}

/** Visible text of a page, lower-case, ASCII, with cell boundaries marked by " | ". */
function wscore_text(string $html): string
{
    $html = preg_replace('~<(script|style|noscript|svg)\b.*?</\1>~is', ' ', $html);
    $html = preg_replace('~<!--.*?-->~s', ' ', $html);
    $t = preg_replace('~<[^>]+>~', ' | ', $html);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $a = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if ($a !== false) { $t = $a; }
    $t = strtolower($t);
    $t = preg_replace('~[\x00-\x1f]+~', ' ', $t);
    $t = preg_replace('~[^a-z0-9\.\'\|:\-\(\)/ &]+~', ' ', $t);
    return trim(preg_replace('~\s+~', ' ', $t));
}

function wscore_norm_name(string $s): string
{
    $a = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($a !== false ? $a : $s);
    $s = preg_replace('~[^a-z0-9 ]+~', ' ', $s);
    return trim(preg_replace('~\s+~', ' ', $s));
}

/** Names a widget might use for a team. $firstWordOk: allow the first word alone (only when it is not shared with another team). */
function wscore_variants(string $team, bool $firstWordOk): array
{
    $n = wscore_norm_name($team);
    $v = [$n];
    $core = trim(preg_replace('~\b(fc|cf|sc|afc|fk|sk|ac|as|ud|cd|club|de|the)\b~', ' ', $n));
    $core = trim(preg_replace('~\s+~', ' ', $core));
    if ($core !== '' && $core !== $n) { $v[] = $core; }
    $v[] = str_replace(['manchester ', 'united', 'wanderers', 'albion'], ['man ', 'utd', 'wands', 'alb'], $core !== '' ? $core : $n);
    foreach (search_alias_map() as $alias => $full) {
        if (wscore_norm_name($full) !== '' && (strpos($n, wscore_norm_name($full)) !== false)) { $v[] = wscore_norm_name($alias); }
    }
    $w = explode(' ', $core !== '' ? $core : $n);
    if ($firstWordOk && count($w) > 1 && strlen($w[0]) >= 4) { $v[] = $w[0]; }
    return array_values(array_unique(array_filter($v, function ($x) { return strlen($x) >= 3; })));
}

/**
 * Looks for a finished score for home vs away in the page text. Returns [homeGoals, awayGoals] or null.
 */
function wscore_find(string $text, array $homeV, array $awayV): ?array
{
    $found = [];
    $fin = '~\b(ft|full time|full-time|finished|ended|final|aet|after extra time|after pen\w*)\b~';
    $bad = '~\b(postponed|postp|ppd|cancelled|canceled|abandoned|suspended|walkover|w/o|live|ht|half time|1st half|2nd half)\b|\d+\'~';
    foreach ($homeV as $h) {
        foreach ($awayV as $a) {
            foreach ([[$h, $a, false], [$a, $h, true]] as [$first, $second, $swap]) {
                $re = '~(?<![a-z0-9])' . preg_quote($first, '~') . '(?![a-z0-9])(.{0,70}?)(?<![a-z0-9])' . preg_quote($second, '~') . '(?![a-z0-9])~';
                if (!preg_match_all($re, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { continue; }
                foreach ($ms as $m) {
                    $seg = $m[1][0];
                    $start = $m[0][1]; $end = $start + strlen($m[0][0]);
                    if (preg_match($bad, $seg)) { continue; }
                    // The "FT / Full time / Finished" marker must be the very next thing after the second team (same row),
                    // or sit between the teams with the score. A marker belonging to a neighbouring row is never used.
                    $after = substr($text, $end, 30);
                    $isFinal = preg_match('~^[\s|]{0,10}' . trim($fin, '~') . '~', $after) === 1 || preg_match($fin, $seg) === 1;
                    $clean = preg_replace('~\([^)]*\)~', ' ', $seg);
                    $clean = preg_replace($fin, ' ', $clean);
                    if (!preg_match('~^[\s|]*(\d{1,2})[\s|]*[-:]?[\s|]*(\d{1,2})[\s|]*$~', $clean, $sc)) { continue; }
                    if (!$isFinal) { continue; }
                    $g = $swap ? [(int)$sc[2], (int)$sc[1]] : [(int)$sc[1], (int)$sc[2]];
                    $found[$g[0] . '-' . $g[1]] = $g;
                }
            }
        }
    }
    return count($found) === 1 ? array_values($found)[0] : null;   // none, or conflicting scores: do not guess
}

/** True when a feed row for these two teams says postponed / cancelled / abandoned (a clear sign the bet is void). */
function wscore_find_void(string $text, array $homeV, array $awayV): bool
{
    foreach ($homeV as $h) {
        foreach ($awayV as $a) {
            foreach ([[$h, $a], [$a, $h]] as [$first, $second]) {
                $re = '~(?<![a-z0-9])' . preg_quote($first, '~') . '(?![a-z0-9])(.{0,70}?)(?<![a-z0-9])' . preg_quote($second, '~') . '(?![a-z0-9])(.{0,25})~';
                if (!preg_match_all($re, $text, $ms, PREG_SET_ORDER)) { continue; }
                foreach ($ms as $m) {
                    if (preg_match('~\b(postponed|postp|ppd|cancelled|canceled|abandoned|walkover)\b~', $m[1] . ' ' . $m[2])) { return true; }
                }
            }
        }
    }
    return false;
}

/** Reads the feeds and settles what is clear. Returns a summary array. */
function wscore_run(bool $force = false): array
{
    $sum = ['time' => date('c'), 'feeds' => 0, 'matches' => 0, 'found' => 0, 'settled' => 0, 'left' => 0, 'notes' => []];
    $doTips = $force || wscore_enabled();
    $doStreak = function_exists('streak_auto_on') && ($force || streak_auto_on()) && streak_ready();
    if (!wscore_ready() || (!$doTips && !$doStreak)) { $sum['notes'][] = 'Switched off.'; return $sum; }
    $feeds = wscore_feeds();
    $sum['feeds'] = count($feeds);
    if (!$feeds) { $sum['notes'][] = 'No widget is marked "use for results".'; wscore_save($sum); return $sum; }
    $pdo = db();
    $ms = $doTips ? $pdo->query("SELECT m.id, m.home_team, m.away_team, m.kickoff_at FROM matches m
                       WHERE m.kickoff_at < NOW() - INTERVAL 100 MINUTE AND m.kickoff_at > NOW() - INTERVAL 3 DAY
                         AND (EXISTS (SELECT 1 FROM tips t WHERE t.match_id = m.id AND t.status = 'pending' AND t.deleted_at IS NULL)
                              OR EXISTS (SELECT 1 FROM game_picks g WHERE g.match_id = m.id AND g.status = 'open'))
                       ORDER BY m.kickoff_at ASC LIMIT 60")->fetchAll() : [];
    // the 2 Odds Streak pick: judged 120 minutes after kickoff
    $sd = $doStreak ? $pdo->query("SELECT id, home AS home_team, away AS away_team, kickoff_at, pick FROM streak_days
                       WHERE status = 'pending' AND kickoff_at < NOW() - INTERVAL 120 MINUTE AND kickoff_at > NOW() - INTERVAL 3 DAY
                       ORDER BY kickoff_at ASC LIMIT 5")->fetchAll() : [];
    $sum['matches'] = count($ms) + count($sd);
    if (!$ms && !$sd) { $sum['notes'][] = 'No finished matches are waiting for a result.'; wscore_save($sum); return $sum; }
    $first = [];
    foreach (array_merge($ms, $sd) as $m) { foreach ([$m['home_team'], $m['away_team']] as $t) { $w = explode(' ', wscore_norm_name($t))[0]; $first[$w] = ($first[$w] ?? 0) + 1; } }
    $texts = [];
    foreach ($feeds as $f) {
        $html = wscore_fetch($f['url']);
        if ($html === null) { $sum['notes'][] = 'Could not open "' . $f['title'] . '".'; continue; }
        $t = wscore_text($html);
        if (strlen($t) < 80) { $sum['notes'][] = '"' . $f['title'] . '" has no readable text (its scores are probably drawn by JavaScript).'; continue; }
        $texts[] = $t;
    }
    foreach ($ms as $m) {
        $hf = ($first[explode(' ', wscore_norm_name($m['home_team']))[0]] ?? 0) <= 1;
        $af = ($first[explode(' ', wscore_norm_name($m['away_team']))[0]] ?? 0) <= 1;
        $hv = wscore_variants($m['home_team'], $hf); $av = wscore_variants($m['away_team'], $af);
        $score = null;
        foreach ($texts as $t) { $score = wscore_find($t, $hv, $av); if ($score !== null) { break; } }
        if ($score === null) { $sum['left']++; continue; }
        $sum['found']++;
        $sum['settled'] += wscore_apply($pdo, $m, $score[0], $score[1]);
    }
    foreach ($sd as $m) {
        $hv = wscore_variants($m['home_team'], true); $av = wscore_variants($m['away_team'], true);
        $res = null; $label = '';
        foreach ($texts as $t) {
            $score = wscore_find($t, $hv, $av);
            if ($score !== null) {
                $res = settle_from_score((string)$m['pick'], (string)$m['pick'], $score[0], $score[1], (string)$m['home_team'], (string)$m['away_team'])
                    ?? settle_from_score('1X2', (string)$m['pick'], $score[0], $score[1], (string)$m['home_team'], (string)$m['away_team']);
                $label = $score[0] . '-' . $score[1];
                break;
            }
            if (wscore_find_void($t, $hv, $av)) { $res = 'void'; $label = 'postponed/cancelled'; break; }
        }
        if ($res === null) { $sum['left']++; $sum['notes'][] = 'Streak pick ' . $m['home_team'] . ' vs ' . $m['away_team'] . ' not decided from the widgets: settle it by hand.'; continue; }
        if (streak_settle((int)$m['id'], $res)) {
            $sum['found']++; $sum['settled']++;
            try { $pdo->prepare('INSERT INTO admin_log (admin_id, action, target, details, ip) VALUES (NULL, ?, ?, ?, ?)')->execute(['streak.widgetsettle', 'day#' . $m['id'], substr($m['home_team'] . ' vs ' . $m['away_team'] . ' ' . $label . ' (from widget): pending -> ' . $res, 0, 500), 'widget']); } catch (Throwable $e) { }
        }
    }
    if ($sum['left'] > 0) { $sum['notes'][] = $sum['left'] . ' match(es) not found as finished in the widgets: settle those by hand.'; }
    wscore_save($sum);
    return $sum;
}

/** Same rules as settling by hand: only pending tips, never changes a settled tip. */
function wscore_apply(PDO $pdo, array $m, int $home, int $away): int
{
    try { match_set_final($pdo, (int)$m['id'], $home, $away); } catch (Throwable $e) { error_log('wscore game: ' . $e->getMessage()); }
    $st = $pdo->prepare("SELECT id, market, pick FROM tips WHERE match_id = ? AND status = 'pending' AND deleted_at IS NULL");
    $st->execute([$m['id']]);
    $n = 0;
    foreach ($st->fetchAll() as $t) {
        $r = settle_from_score((string)$t['market'], (string)$t['pick'], $home, $away, (string)$m['home_team'], (string)$m['away_team']);
        if ($r === null) { continue; }     // unusual market: the admin settles it
        $u = $pdo->prepare("UPDATE tips SET status = ?, settled_at = NOW() WHERE id = ? AND status = 'pending'");
        $u->execute([$r, $t['id']]);
        if (!$u->rowCount()) { continue; }
        $n++;
        try { audit_tip((int)$t['id'], 'settle'); } catch (Throwable $e) { }
        try { alerts_tip_settled((int)$t['id'], $r); } catch (Throwable $e) { }
        try {
            $pdo->prepare('INSERT INTO admin_log (admin_id, action, target, details, ip) VALUES (NULL, ?, ?, ?, ?)')
                ->execute(['tip.widgetsettle', 'tip#' . $t['id'], substr($m['home_team'] . ' vs ' . $m['away_team'] . ' ' . $home . '-' . $away . ' (from widget): pending -> ' . $r, 0, 500), 'widget']);
        } catch (Throwable $e) { }
    }
    return $n;
}

function wscore_save(array $sum): void { try { set_setting('widget_scores_last', json_encode($sum)); } catch (Throwable $e) { } }

/** Runs at most every 10 minutes, after the page has been sent, when a visitor loads any page. */
function wscore_maybe_run(): void
{
    try {
        if (!wscore_ready() || (!wscore_enabled() && !(function_exists('streak_auto_on') && streak_auto_on()))) { return; }
        $last = (int)setting('widget_scores_ts', '0');
        if (time() - $last < 600) { return; }
        set_setting('widget_scores_ts', (string)time());
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            @ignore_user_abort(true); @set_time_limit(60);
            try { wscore_run(); } catch (Throwable $e) { error_log('wscore: ' . $e->getMessage()); }
        });
    } catch (Throwable $e) { }
}
