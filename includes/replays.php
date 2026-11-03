<?php
/** Replays: past games the admin lists with a link for the first half and one for the second half. At most 100 games. */
const REPLAYS_MAX = 100;

function replays_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = function_exists('sk_ensure_table') && sk_ensure_table('replays', 'database-update-37.sql'); }
    return $ok;
}
function replays_count(): int { return replays_ready() ? (int)db()->query('SELECT COUNT(*) FROM replays')->fetchColumn() : 0; }
function replays_link(string $u): string { return function_exists('stream_clean') ? stream_clean($u) : ''; }

function replays_date(string $d): ?string
{
    $d = trim($d);
    if ($d === '') { return null; }
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'j M Y', 'j F Y', 'd.m.Y', 'm/d/Y'] as $f) {
        $x = DateTime::createFromFormat($f, $d);
        if ($x && $x->format($f) === $d) { return $x->format('Y-m-d'); }
    }
    $t = strtotime($d);
    return $t ? date('Y-m-d', $t) : null;
}

/** Reads CSV or pasted text: game name, date played, first-half link, second-half link (one link column is used for both halves). */
function replays_parse(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $rows = []; $bad = [];
    $lines = preg_split('/\r\n|\r|\n/', $text);
    foreach ($lines as $n => $line) {
        if (trim($line) === '') { continue; }
        $f = str_getcsv($line, strpos($line, ';') !== false && strpos($line, ',') === false ? ';' : (strpos($line, "\t") !== false && strpos($line, ',') === false ? "\t" : ','));
        $f = array_map('trim', $f);
        if ($n === 0 && preg_match('/^(game|match|name|title)/i', $f[0] ?? '') && !preg_match('~^https?://~i', $f[2] ?? '')) { continue; }   // header row
        $title = mb_substr((string)($f[0] ?? ''), 0, 160);
        $date = replays_date((string)($f[1] ?? ''));
        $th = function_exists('thumb_clean') ? thumb_clean((string)($f[4] ?? '')) : '';
        $l1 = replays_link((string)($f[2] ?? ''));
        $l2 = replays_link((string)($f[3] ?? ''));
        if ($l1 === '' && $l2 === '') { $bad[] = 'Row ' . ($n + 1) . ': needs a link starting with http:// or https://.'; continue; }
        if (mb_strlen($title) < 3) { $bad[] = 'Row ' . ($n + 1) . ': needs a game name.'; continue; }
        if ($l2 === '') { $l2 = $l1; }
        if ($l1 === '') { $l1 = $l2; }
        $rows[] = ['title' => $title, 'played_on' => $date, 'link1' => $l1, 'link2' => $l2, 'thumb' => $th];
    }
    return [$rows, $bad];
}

/** Adds rows up to the 100-game limit. Returns [added, skipped because the limit was reached]. */
function replays_add_rows(array $rows): array
{
    $room = max(0, REPLAYS_MAX - replays_count());
    $add = array_slice($rows, 0, $room);
    $th = function_exists('thumb_col') && thumb_col('replays');
    $ins = db()->prepare($th ? 'INSERT INTO replays (title, played_on, link1, link2, thumb) VALUES (?,?,?,?,?)' : 'INSERT INTO replays (title, played_on, link1, link2) VALUES (?,?,?,?)');
    foreach ($add as $r) { $v = [$r['title'], $r['played_on'], $r['link1'], $r['link2']]; if ($th) { $v[] = (string)($r['thumb'] ?? ''); } $ins->execute($v); }
    return [count($add), count($rows) - count($add)];
}

function replays_list(): array
{
    if (replays_ready() && function_exists('thumb_col')) { thumb_col('replays'); }
    return replays_ready() ? db()->query('SELECT * FROM replays ORDER BY COALESCE(played_on, DATE(created_at)) DESC, id DESC LIMIT ' . REPLAYS_MAX)->fetchAll() : [];
}
