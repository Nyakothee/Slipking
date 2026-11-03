<?php
/** Dependency-free SVG charts for the admin pages. $series: ['Name' => ['2026-10-01' => 5, ...]], $colors: ['#f0a91c', ...]. */

function chart_lab(string $l): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $l) ? date('j M', strtotime($l)) : $l; }

function chart_nice_max(float $m): float
{
    if ($m <= 5) { return 5; }
    $p = pow(10, floor(log10($m)));
    foreach ([1, 2, 2.5, 5, 10] as $k) { if ($k * $p >= $m) { return $k * $p; } }
    return 10 * $p;
}

/** Smooth area/line chart for one or more series. */
function chart_lines(array $series, array $colors, int $h = 240): string
{
    static $n = 0; $n++;
    $W = 760; $padL = 42; $padR = 12; $padT = 14; $padB = 30;
    $labels = array_keys(reset($series) ?: []);
    $cnt = count($labels);
    if ($cnt < 2) { return '<p class="hint">Not enough data yet.</p>'; }
    $max = 0;
    foreach ($series as $s) { $max = max($max, max($s ?: [0])); }
    $top = chart_nice_max((float)$max);
    $iw = $W - $padL - $padR; $ih = $h - $padT - $padB;
    $x = function ($i) use ($padL, $iw, $cnt) { return $padL + $iw * $i / ($cnt - 1); };
    $y = function ($v) use ($padT, $ih, $top) { return $padT + $ih - $ih * ($v / $top); };
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $h . '" role="img" aria-label="Line chart">';
    for ($g = 0; $g <= 4; $g++) {
        $gy = $padT + $ih * $g / 4; $gv = $top * (4 - $g) / 4;
        $o .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . round($gy, 1) . '" y2="' . round($gy, 1) . '" stroke="#e3e8e2" stroke-dasharray="' . ($g === 4 ? '0' : '4 4') . '"/>';
        $o .= '<text x="' . ($padL - 8) . '" y="' . round($gy + 4, 1) . '" text-anchor="end" font-size="11" fill="#64736c">' . (floor($gv) == $gv ? number_format($gv) : number_format($gv, 1)) . '</text>';
    }
    $step = max(1, (int)ceil($cnt / 8));
    foreach ($labels as $i => $lb) {
        if ($i % $step === 0 || $i === $cnt - 1) { $o .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" font-size="11" fill="#64736c">' . e(chart_lab((string)$lb)) . '</text>'; }
    }
    $si = 0;
    foreach ($series as $name => $s) {
        $c = $colors[$si % count($colors)];
        $pts = []; $i = 0;
        foreach ($s as $v) { $pts[] = [$x($i), $y($v)]; $i++; }
        $d = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
        for ($k = 1; $k < count($pts); $k++) {
            $cx = ($pts[$k - 1][0] + $pts[$k][0]) / 2;
            $d .= ' C' . round($cx, 1) . ',' . round($pts[$k - 1][1], 1) . ' ' . round($cx, 1) . ',' . round($pts[$k][1], 1) . ' ' . round($pts[$k][0], 1) . ',' . round($pts[$k][1], 1);
        }
        $area = $d . ' L' . round(end($pts)[0], 1) . ',' . ($padT + $ih) . ' L' . round($pts[0][0], 1) . ',' . ($padT + $ih) . ' Z';
        $gid = 'cg' . $n . '_' . $si;
        $o .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $c . '" stop-opacity=".28"/><stop offset="1" stop-color="' . $c . '" stop-opacity="0"/></linearGradient></defs>';
        if ($si === 0 || count($series) <= 2) { $o .= '<path d="' . $area . '" fill="url(#' . $gid . ')"/>'; }
        $o .= '<path d="' . $d . '" fill="none" stroke="' . $c . '" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>';
        $i = 0;
        foreach ($s as $lb => $v) {
            $o .= '<circle cx="' . round($pts[$i][0], 1) . '" cy="' . round($pts[$i][1], 1) . '" r="' . ($cnt > 40 ? 0 : 3.2) . '" fill="#fff" stroke="' . $c . '" stroke-width="2"><title>' . e($name . ' - ' . chart_lab((string)$lb) . ': ' . number_format($v)) . '</title></circle>';
            $i++;
        }
        $si++;
    }
    $o .= '</svg>';
    if (count($series) > 1) {
        $o .= '<div class="chart-legend">'; $si = 0;
        foreach ($series as $name => $_) { $o .= '<span><i style="background:' . $colors[$si % count($colors)] . '"></i>' . e($name) . '</span>'; $si++; }
        $o .= '</div>';
    }
    return $o;
}

/** Grouped vertical bars: one or two series. */
function chart_bars(array $series, array $colors, int $h = 240): string
{
    $W = 760; $padL = 42; $padR = 12; $padT = 14; $padB = 30;
    $labels = array_keys(reset($series) ?: []);
    $cnt = count($labels);
    if ($cnt < 1) { return '<p class="hint">Not enough data yet.</p>'; }
    $max = 0;
    foreach ($series as $s) { $max = max($max, max($s ?: [0])); }
    $top = chart_nice_max((float)$max);
    $iw = $W - $padL - $padR; $ih = $h - $padT - $padB;
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $h . '" role="img" aria-label="Bar chart">';
    for ($g = 0; $g <= 4; $g++) {
        $gy = $padT + $ih * $g / 4; $gv = $top * (4 - $g) / 4;
        $o .= '<line x1="' . $padL . '" x2="' . ($W - $padR) . '" y1="' . round($gy, 1) . '" y2="' . round($gy, 1) . '" stroke="#e3e8e2" stroke-dasharray="' . ($g === 4 ? '0' : '4 4') . '"/>';
        $o .= '<text x="' . ($padL - 8) . '" y="' . round($gy + 4, 1) . '" text-anchor="end" font-size="11" fill="#64736c">' . (floor($gv) == $gv ? number_format($gv) : number_format($gv, 1)) . '</text>';
    }
    $slot = $iw / $cnt; $ns = count($series); $bw = min(26, $slot * 0.72 / $ns);
    $step = max(1, (int)ceil($cnt / 10));
    foreach ($labels as $i => $lb) {
        $cx = $padL + $slot * ($i + 0.5);
        if ($i % $step === 0) { $o .= '<text x="' . round($cx, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" font-size="11" fill="#64736c">' . e(chart_lab((string)$lb)) . '</text>'; }
        $si = 0;
        foreach ($series as $name => $s) {
            $v = (float)($s[$lb] ?? 0);
            $bh = max($v > 0 ? 2 : 0, $ih * $v / $top);
            $bx = $cx - ($ns * $bw) / 2 + $si * $bw;
            $o .= '<rect x="' . round($bx, 1) . '" y="' . round($padT + $ih - $bh, 1) . '" width="' . round($bw - 2, 1) . '" height="' . round($bh, 1) . '" rx="4" fill="' . $colors[$si % count($colors)] . '"><title>' . e($name . ' - ' . chart_lab((string)$lb) . ': ' . number_format($v)) . '</title></rect>';
            $si++;
        }
    }
    $o .= '</svg>';
    if ($ns > 1) {
        $o .= '<div class="chart-legend">'; $si = 0;
        foreach ($series as $name => $_) { $o .= '<span><i style="background:' . $colors[$si % count($colors)] . '"></i>' . e($name) . '</span>'; $si++; }
        $o .= '</div>';
    }
    return $o;
}

/** Horizontal bar rows from ['label' => count]. */
function chart_hbars(array $data, int $limit = 8): string
{
    if (!$data) { return '<p class="hint">No data yet.</p>'; }
    arsort($data); $data = array_slice($data, 0, $limit, true);
    $m = max($data) ?: 1; $o = '';
    foreach ($data as $k => $v) { $o .= '<div class="hbar"><span>' . e($k) . '</span><div class="tr"><i style="width:' . max(2, round($v / $m * 100)) . '%"></i></div><b>' . number_format($v) . '</b></div>'; }
    return $o;
}
