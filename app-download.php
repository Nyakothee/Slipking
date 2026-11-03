<?php
/** Hands the current Android app file to the visitor (resumable, so a dropped mobile connection can continue). */
require_once __DIR__ . '/includes/init.php';
$r = app_current();
if (!$r) { http_response_code(404); echo 'The app is not available yet.'; exit; }
$pdo = db();
if ($r['file_name'] === '') {
    if (preg_match('~^https://~i', (string)$r['external_url'])) {
        $pdo->prepare('UPDATE app_releases SET downloads = downloads + 1 WHERE id = ?')->execute([(int)$r['id']]);
        header('Location: ' . $r['external_url'], true, 302);
    } else { http_response_code(404); }
    exit;
}
$path = app_dir() . '/' . $r['file_name'];
if (!preg_match('/^app-[a-f0-9]{16}\.apk$/', $r['file_name']) || !is_file($path)) { http_response_code(404); echo 'File missing.'; exit; }
$size = filesize($path); $start = 0; $end = $size - 1; $code = 200;
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); } else { $start = (int)$m[1]; if ($m[2] !== '') { $end = min($end, (int)$m[2]); } }
    if ($start > $end || $start >= $size) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
    $code = 206;
}
if ($start === 0) { $pdo->prepare('UPDATE app_releases SET downloads = downloads + 1 WHERE id = ?')->execute([(int)$r['id']]); app_log_download((int)$r['id'], (string)($_GET['src'] ?? '')); }
while (ob_get_level() > 0) { @ob_end_clean(); }
@set_time_limit(0);
http_response_code($code);
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . app_download_name($r) . '"');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
if ($code === 206) { header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size); }
$h = fopen($path, 'rb'); fseek($h, $start); $left = $end - $start + 1;
while ($left > 0 && !feof($h) && !connection_aborted()) { $buf = fread($h, min(65536, $left)); if ($buf === false) { break; } echo $buf; $left -= strlen($buf); flush(); }
fclose($h);
