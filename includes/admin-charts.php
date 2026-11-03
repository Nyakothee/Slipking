<?php
/**
 * Small inline-SVG charts for the admin dashboard. No libraries, no external requests.
 * Each chart has a <title>/<desc> and a table of the same numbers inside <details> for screen readers.
 */

/** Turns an amount in $code into KES using the rates saved in Admin > Currencies. */
function admin_to_kes(float $amount, string $code, array $rates): float
{
    if ($code === 'KES') {
        return $amount;
    }
    $k = (float)($rates['KES'] ?? 0);
    $c = (float)($rates[$code] ?? 0);
    return ($k > 0 && $c > 0) ? $amount / $c * $k : 0.0;
}

/** Rates per US dollar keyed by currency code. */
function admin_rates(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT code, rate_per_usd FROM currencies')->fetchAll() as $r) {
        $out[$r['code']] = (float)$r['rate_per_usd'];
    }
    return $out;
}

/** The last $days dates (oldest first) as Y-m-d strings. */
function admin_last_days(int $days): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[] = date('Y-m-d', strtotime("-$i day"));
    }
    return $out;
}

/** Rounds a maximum up to a tidy axis top (1, 2, 5 x 10^n). */
function admin_nice_max(float $v): float
{
    if ($v <= 0) {
        return 1;
    }
    $p = pow(10, floor(log10($v)));
    foreach ([1, 2, 5, 10] as $m) {
        if ($v <= $m * $p) {
            return $m * $p;
        }
    }
    return 10 * $p;
}

/**
 * Bar chart. $labels and $values are parallel lists. $unit is shown in the table header, $dec the decimals.
 */
function admin_bar_chart(string $title, string $desc, array $labels, array $values, string $unit = '', int $dec = 0): string
{
    $n   = max(1, count($values));
    $w   = 640; $h = 200; $padL = 44; $padB = 26; $padT = 10;
    $max = admin_nice_max(max($values ?: [0]));
    $bw  = ($w - $padL - 8) / $n;
    $svg = '<svg class="achart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="t' . md5($title) . '"><title id="t' . md5($title) . '">' . e($title) . '</title><desc>' . e($desc) . '</desc>';
    foreach ([0, 0.5, 1] as $g) {
        $y = $padT + ($h - $padB - $padT) * (1 - $g);
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - 4) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" class="grid"/>';
        $svg .= '<text x="' . ($padL - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" class="ax">' . e(number_format($max * $g, $dec)) . '</text>';
    }
    foreach ($values as $i => $v) {
        $bh = ($h - $padB - $padT) * ($max > 0 ? $v / $max : 0);
        $x  = $padL + $i * $bw + $bw * 0.15;
        $svg .= '<rect class="bar" x="' . round($x, 1) . '" y="' . round($h - $padB - $bh, 1) . '" width="' . round($bw * 0.7, 1) . '" height="' . round(max($bh, 0), 1) . '"><title>' . e($labels[$i] . ': ' . number_format($v, $dec)) . '</title></rect>';
        if ($n <= 12 || $i % 5 === 0 || $i === $n - 1) {
            $svg .= '<text x="' . round($x + $bw * 0.35, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="ax">' . e($labels[$i]) . '</text>';
        }
    }
    return $svg . '</svg>' . admin_chart_table($labels, $values, $unit, $dec);
}

/** Line chart; $values may contain null for "no data" (the line breaks). Y axis 0..$yMax (default 100 for percentages). */
function admin_line_chart(string $title, string $desc, array $labels, array $values, string $unit = '%', float $yMax = 100.0, int $dec = 0): string
{
    $n = max(2, count($values));
    $w = 640; $h = 200; $padL = 44; $padB = 26; $padT = 10;
    $svg = '<svg class="achart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-labelledby="t' . md5($title) . '"><title id="t' . md5($title) . '">' . e($title) . '</title><desc>' . e($desc) . '</desc>';
    foreach ([0, 0.5, 1] as $g) {
        $y = $padT + ($h - $padB - $padT) * (1 - $g);
        $svg .= '<line x1="' . $padL . '" x2="' . ($w - 4) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" class="grid"/>';
        $svg .= '<text x="' . ($padL - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" class="ax">' . e(number_format($yMax * $g, $dec)) . '</text>';
    }
    $path = ''; $pen = false; $dots = '';
    foreach ($values as $i => $v) {
        if ($v === null) { $pen = false; continue; }
        $x = $padL + ($w - $padL - 10) * ($i / ($n - 1));
        $y = $padT + ($h - $padB - $padT) * (1 - min(1, max(0, $v / $yMax)));
        $path .= ($pen ? 'L' : 'M') . round($x, 1) . ' ' . round($y, 1) . ' ';
        $pen = true;
        $dots .= '<circle class="dot" cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="3"><title>' . e($labels[$i] . ': ' . number_format($v, $dec) . $unit) . '</title></circle>';
    }
    if ($path !== '') {
        $svg .= '<path class="ln" d="' . trim($path) . '"/>' . $dots;
    }
    foreach ($labels as $i => $lab) {
        if ($i % 5 === 0 || $i === count($labels) - 1) {
            $x = $padL + ($w - $padL - 10) * ($i / ($n - 1));
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="ax">' . e($lab) . '</text>';
        }
    }
    return $svg . '</svg>' . admin_chart_table($labels, $values, $unit, $dec);
}

function admin_chart_table(array $labels, array $values, string $unit, int $dec): string
{
    $rows = '';
    foreach ($labels as $i => $lab) {
        $v = $values[$i] ?? null;
        $rows .= '<tr><td>' . e($lab) . '</td><td>' . ($v === null ? '-' : e(number_format($v, $dec)) . e($unit)) . '</td></tr>';
    }
    return '<details class="achart-data"><summary>Show the numbers</summary><table class="tbl"><thead><tr><th>Day</th><th>Value</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';
}
