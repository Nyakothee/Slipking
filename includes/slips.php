<?php
/** Slip Builder Studio: manual slips with combined odds, saved per member, downloadable as PDF. */

require_once __DIR__ . '/leagues.php';

function slips_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $ok = sk_ensure_table('slips', 'database-update-19.sql') && sk_ensure_table('slip_items', 'database-update-19.sql');
    if ($ok) {
        try {
            $have = db()->query("SHOW COLUMNS FROM slip_items LIKE 'league'")->fetch();
            if (!$have) {
                db()->exec("ALTER TABLE slip_items ADD COLUMN league VARCHAR(80) NOT NULL DEFAULT '' AFTER away, ADD COLUMN market VARCHAR(40) NOT NULL DEFAULT '' AFTER league");
            }
        } catch (Throwable $e) { /* leave as is; reads below fall back */ }
    }
    return $ok;
}

/** Slip reference such as SK-20261026-0042 (date the slip was created + its number). */
function slip_ref(array $s): string
{
    $t = strtotime((string)($s['created_at'] ?? $s['updated_at'] ?? 'now')) ?: time();
    return 'SK-' . date('Ymd', $t) . '-' . str_pad((string)((int)($s['id'] ?? 0) % 10000), 4, '0', STR_PAD_LEFT);
}

/** Turns a stored kickoff ("2026-10-31 15:00" or free text) into ['Sat, 31 Oct 2026', '15:00']. */
function slip_when(string $k): array
{
    $k = trim($k);
    if ($k !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $k) && ($t = strtotime($k))) {
        return [date('D, j M Y', $t), strlen($k) > 10 ? date('H:i', $t) : ''];
    }
    return [$k, ''];
}

function slip_total_odds(array $items): float
{
    $t = 1.0;
    foreach ($items as $i) { $t *= (float)$i['odds']; }
    return $items ? round($t, 2) : 0.0;
}

function slip_get(int $id, int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM slips WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $s = $st->fetch();
    if (!$s) { return null; }
    $it = db()->prepare('SELECT home, away, league, market, kickoff, outcome, odds FROM slip_items WHERE slip_id = ? ORDER BY sort_no, id');
    $it->execute([$id]);
    $s['items'] = $it->fetchAll();
    $s['total'] = slip_total_odds($s['items']);
    $s['return'] = round($s['total'] * (float)$s['stake'], 2);
    return $s;
}

/** Reads the posted selection rows. Returns [items, errors]. */
function slip_items_from_post(): array
{
    $items = []; $errors = [];
    $h = (array)($_POST['home'] ?? []); $a = (array)($_POST['away'] ?? []); $k = (array)($_POST['kickoff'] ?? []); $o = (array)($_POST['outcome'] ?? []); $od = (array)($_POST['odds'] ?? []); $lg = (array)($_POST['league'] ?? []); $mk = (array)($_POST['market'] ?? []);
    foreach ($h as $i => $home) {
        $home = trim((string)$home); $away = trim((string)($a[$i] ?? '')); $out = trim((string)($o[$i] ?? '')); $odds = (string)($od[$i] ?? '');
        if ($home === '' && $away === '' && $out === '' && $odds === '') { continue; }
        if ($home === '' || $away === '' || $out === '') { $errors[] = 'Row ' . ($i + 1) . ': enter both teams and the outcome.'; continue; }
        if (!is_numeric($odds) || (float)$odds < 1.01 || (float)$odds > 1000) { $errors[] = 'Row ' . ($i + 1) . ': odds must be between 1.01 and 1000.'; continue; }
        $items[] = ['home' => mb_substr($home, 0, 80), 'away' => mb_substr($away, 0, 80), 'league' => mb_substr(trim((string)($lg[$i] ?? '')), 0, 80), 'market' => mb_substr(trim((string)($mk[$i] ?? '')), 0, 40), 'kickoff' => mb_substr(str_replace('T', ' ', trim((string)($k[$i] ?? ''))), 0, 20), 'outcome' => mb_substr($out, 0, 60), 'odds' => round((float)$odds, 2)];
    }
    if (!$items && !$errors) { $errors[] = 'Add at least one selection.'; }
    if (count($items) > 30) { $errors[] = 'A slip can hold up to 30 selections.'; }
    return [$items, $errors];
}

function slip_save(int $userId, ?int $id, string $title, float $stake, array $items): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id) {
            $pdo->prepare('UPDATE slips SET title = ?, stake = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')->execute([$title, $stake, $id, $userId]);
            $pdo->prepare('DELETE FROM slip_items WHERE slip_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO slips (user_id, title, stake) VALUES (?,?,?)')->execute([$userId, $title, $stake]);
            $id = (int)$pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO slip_items (slip_id, sort_no, home, away, league, market, kickoff, outcome, odds) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach (array_values($items) as $n => $i) { $ins->execute([$id, $n, $i['home'], $i['away'], (string)($i['league'] ?? ''), (string)($i['market'] ?? ''), (string)($i['kickoff'] ?? ''), $i['outcome'], $i['odds']]); }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    return $id;
}

/** ASCII-safe text for the built-in PDF fonts. */
function pdf_txt(string $s): string
{
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $t = $t === false ? preg_replace('/[^\x20-\x7e]/', '?', $s) : preg_replace('/[^\x20-\x7e]/', '', $t);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
}

/** Builds a branded multi-page PDF for a slip (Helvetica, vector logo, faint SLIPKING watermark), no external library. */
function slip_pdf(array $s, string $site): string
{
    $W = 595; $H = 842;
    $txt = function (string $t, float $x, float $y, float $size = 10, bool $bold = false, array $rgb = [0.07, 0.12, 0.10], string $align = 'l') {
        $t = pdf_txt($t);
        if ($align !== 'l') {
            $raw = str_replace(['\\\\', '\\(', '\\)'], ['\\', '(', ')'], $t);
            $w = strlen($raw) * $size * ($bold ? 0.56 : 0.5);
            $x = $align === 'r' ? $x - $w : $x - $w / 2;
        }
        return sprintf("%.3f %.3f %.3f rg BT /%s %.1f Tf %.1f %.1f Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, $t);
    };
    $rect = function (float $x, float $y, float $w, float $h, array $rgb) {
        return sprintf("%.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    };
    $circle = function (float $cx, float $cy, float $r, array $rgb) {
        $k = 0.5523 * $r;
        return sprintf("%.3f %.3f %.3f rg %.1f %.1f m %.1f %.1f %.1f %.1f %.1f %.1f c %.1f %.1f %.1f %.1f %.1f %.1f c %.1f %.1f %.1f %.1f %.1f %.1f c %.1f %.1f %.1f %.1f %.1f %.1f c f\n",
            $rgb[0], $rgb[1], $rgb[2], $cx + $r, $cy,
            $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r,
            $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy,
            $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r,
            $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy);
    };
    $navy = [0.043, 0.102, 0.2]; $green = [0.051, 0.231, 0.173]; $gold = [0.941, 0.663, 0.110]; $ink = [0.07, 0.12, 0.10]; $mute = [0.40, 0.45, 0.43]; $white = [1, 1, 1];
    $created = strtotime((string)($s['created_at'] ?? $s['updated_at'])) ?: time();
    $made = date('j M Y, H:i', $created);
    $ref = slip_ref($s);
    $host = preg_replace('~^https?://~', '', rtrim(url(''), '/'));
    $n = count($s['items']);
    $crown = function (float $x, float $y, float $sz, array $rgb) {
        // x,y = bottom-left of the crown in PDF space
        return sprintf("%.3f %.3f %.3f rg %.1f %.1f m %.1f %.1f l %.1f %.1f l %.1f %.1f l %.1f %.1f l %.1f %.1f l %.1f %.1f l h f %.1f %.1f %.1f %.1f re f\n", $rgb[0], $rgb[1], $rgb[2],
            $x, $y + $sz * .2, $x - $sz * .1, $y + $sz * .8, $x + $sz * .22, $y + $sz * .5, $x + $sz * .5, $y + $sz, $x + $sz * .78, $y + $sz * .5, $x + $sz * 1.1, $y + $sz * .8, $x + $sz, $y + $sz * .2,
            $x, $y - $sz * .02, $sz, $sz * .14);
    };
    $pages = []; $cur = ''; $y = 0; $pageNo = 0;
    $newPage = function () use (&$cur, &$y, &$pageNo, $W, $H, $txt, $rect, $crown, $navy, $gold, $mute, $white, $ref, $made, $n, $host) {
        $pageNo++;
        $cur .= $rect(0, 0, $W, $H, [0.933, 0.949, 0.937]);
        $cur .= $rect(0, $H - 130, $W, 130, $navy) . $rect(0, $H - 133, $W, 3, $gold);
        $cur .= $crown(36, $H - 82, 34, $gold);
        $cur .= $txt('SLIP', 86, $H - 72, 28, true, $white) . $txt('KING', 86 + 62, $H - 72, 28, true, $gold);
        $cur .= $txt('SMARTER SLIPS. HONEST RESULTS.', 87, $H - 90, 7.5, false, [0.72, 0.77, 0.87]);
        $cur .= $txt('BETSLIP', $W - 36, $H - 66, 30, true, $gold, 'r');
        $cur .= $txt('OFFICIAL BETTING SLIP', $W - 36, $H - 86, 9, true, $white, 'r');
        // info card
        $cur .= $rect(24, $H - 190, $W - 48, 70, $white);
        $cur .= $txt('BETSLIP ID', 40, $H - 144, 7.5, true, $mute) . $txt($ref, 40, $H - 168, 15, true, [0.07, 0.12, 0.10]);
        $cur .= $txt('DATE & TIME', 235, $H - 144, 7.5, true, $mute) . $txt($made, 235, $H - 168, 14, true, [0.07, 0.12, 0.10]);
        $cur .= $txt('TYPE', 430, $H - 144, 7.5, true, $mute) . $txt(($n > 1 ? 'Multiple' : 'Single') . ' (' . $n . ')', 430, $H - 168, 14, true, [0.07, 0.12, 0.10]);
        // footer
        $cur .= $rect(0, 0, $W, 46, $navy) . $crown(30, 14, 18, $gold);
        $cur .= $txt('SLIPKING', 58, 17, 13, true, $white) . $txt('BETTING MADE SIMPLE', $W / 2 + 20, 17, 8.5, true, $gold, 'c') . $txt($host, $W - 30, 17, 10, true, $white, 'r');
        $cur .= $txt('For entertainment only. Odds are entered by the member and are not guaranteed. 18+ only. Page ' . $pageNo, $W / 2, 54, 7, false, $mute, 'c');
        $y = $H - 222;
    };
    $newPage();
    $tableHead = function () use (&$cur, &$y, $rect, $txt, $navy, $white) {
        $cur .= $rect(24, $y - 8, 547, 26, $navy);
        $cur .= $txt('#', 34, $y, 8.5, true, $white) . $txt('SELECTION', 54, $y, 8.5, true, $white) . $txt('MARKET', 300, $y, 8.5, true, $white) . $txt('ODDS', 458, $y, 8.5, true, $white, 'c') . $txt('STATUS', 524, $y, 8.5, true, $white, 'c');
        $y -= 34;
    };
    $tableHead();
    foreach ($s['items'] as $k => $i) {
        if ($y < 300) { $pages[] = $cur; $cur = ''; $newPage(); $tableHead(); }
        $cur .= $rect(24, $y - 20, 547, 46, $white);
        $cur .= $circle(40, $y + 3, 8, $navy) . $txt((string)($k + 1), 40, $y, 8.5, true, $white, 'c');
        $cur .= $txt(mb_substr($i['home'] . ' vs ' . $i['away'], 0, 34), 56, $y + 8, 10.5, true, $ink);
        [$d, $tm] = slip_when((string)$i['kickoff']);
        $sub = trim(($i['league'] ?? '') . (($i['league'] ?? '') !== '' && $d !== '' ? '  |  ' : '') . $d . ($tm !== '' ? ', ' . $tm : ''));
        $cur .= $txt(mb_substr($sub, 0, 52), 56, $y - 8, 7.8, false, $mute);
        $cur .= $txt(mb_substr((string)($i['market'] ?? '') ?: 'Selection', 0, 26), 300, $y + 8, 7.8, false, $mute);
        $cur .= $txt(mb_substr($i['outcome'], 0, 26), 300, $y - 8, 10, true, $green);
        $cur .= $txt(number_format((float)$i['odds'], 2), 458, $y - 1, 13, true, $ink, 'c');
        $cur .= $rect(494, $y - 6, 60, 18, [1, 0.953, 0.839]) . $txt('PENDING', 524, $y - 1, 7.5, true, [0.647, 0.416, 0], 'c');
        $y -= 52;
    }
    if ($y < 250) { $pages[] = $cur; $cur = ''; $newPage(); }
    $y -= 6;
    $cur .= $rect(24, $y - 52, 267, 62, $white) . $rect(304, $y - 52, 267, 62, $white);
    $cur .= $txt('STAKE', 38, $y - 2, 8, true, $mute) . $txt((float)$s['stake'] > 0 ? number_format((float)$s['stake'], 2) : '-', 38, $y - 34, 20, true, $ink);
    $cur .= $txt('TOTAL ODDS', 318, $y - 2, 8, true, $mute) . $txt(number_format($s['total'], 2), 318, $y - 34, 20, true, $ink);
    $y -= 70;
    $cur .= $rect(24, $y - 62, 547, 74, $green) . $rect(24, $y - 62, 6, 74, $gold);
    $cur .= $txt('POTENTIAL RETURN', 46, $y - 6, 9, true, [0.75, 0.94, 0.83]) . $txt((float)$s['stake'] > 0 ? number_format($s['return'], 2) : '-', 46, $y - 44, 30, true, $white);
    $cur .= $circle(520, $y - 25, 24, $gold) . $txt('*', 520, $y - 38, 40, true, $green, 'c');
    $y -= 92;
    $cur .= $rect(24, $y - 52, 547, 62, $white) . $rect(38, $y - 44, 46, 46, $navy) . $crown(46, $y - 30, 28, $gold);
    $cur .= $txt('VERIFY THIS SLIP', 98, $y - 10, 10, true, $ink) . $txt($host, 98, $y - 26, 8.5, false, $mute) . $txt('Slip ' . $ref, 98, $y - 40, 8.5, true, $green);
    $cur .= $txt('Play Smart. Win Big.', 556, $y - 26, 15, true, $gold, 'r');
    $pages[] = $cur;

    $objs = []; // 1 catalog, 2 pages, 3 font, 4 bold font, then page/content pairs
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    $kids = []; $n = 5;
    foreach ($pages as $content) {
        $pg = $n++; $ct = $n++;
        $kids[] = "$pg 0 R";
        $objs[$pg] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $ct 0 R >>";
        $objs[$ct] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objs);
    $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $num => $body) { $off[$num] = strlen($out); $out .= "$num 0 obj\n$body\nendobj\n"; }
    $x = strlen($out);
    $out .= 'xref' . "\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($objs as $num => $_) { $out .= sprintf("%010d 00000 n \n", $off[$num]); }
    return $out . 'trailer << /Size ' . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$x\n%%EOF";
}
