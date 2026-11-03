<?php
/** Chunked APK upload (JSON). The browser sends the file in 2 MB pieces so the server's upload limit does not matter. */
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('settings');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$out = function (array $a, int $code = 200) { http_response_code($code); echo json_encode($a); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { $out(['error' => 'POST only'], 405); }
if (!csrf_check()) { $out(['error' => 'Your session expired. Reload the page and try again.'], 403); }
if (!app_ready()) { $out(['error' => 'Import database-update-26.sql first.'], 500); }
$dir = app_dir();
if (!is_dir($dir) || !is_writable($dir)) { $out(['error' => 'The folder private-uploads/app is missing or not writable on the server.'], 500); }
foreach (glob($dir . '/tmp-*.part') ?: [] as $old) { if (@filemtime($old) < time() - 86400) { @unlink($old); } }   // abandoned uploads
$id = (string)($_POST['id'] ?? '');
if (!preg_match('/^[a-f0-9]{16,32}$/', $id)) { $out(['error' => 'Bad upload id.'], 400); }
$part = $dir . '/tmp-' . $id . '.part';
$act = (string)($_POST['action'] ?? '');

if ($act === 'chunk') {
    $offset = (int)($_POST['offset'] ?? 0);
    if (empty($_FILES['chunk']) || ($_FILES['chunk']['error'] ?? 1) !== UPLOAD_ERR_OK) { $out(['error' => 'The piece did not arrive (server upload limit under 2 MB?).'], 400); }
    if ($offset === 0 && is_file($part)) { @unlink($part); }
    $have = is_file($part) ? filesize($part) : 0;
    if ($have !== $offset) { $out(['error' => 'Out of order', 'have' => $have], 409); }
    $data = file_get_contents($_FILES['chunk']['tmp_name']);
    if ($data === false || $have + strlen($data) > APP_MAX_BYTES) { @unlink($part); $out(['error' => 'The file is bigger than ' . app_size_text(APP_MAX_BYTES) . '.'], 413); }
    if (file_put_contents($part, $data, FILE_APPEND | LOCK_EX) === false) { $out(['error' => 'Could not write to the server disk (full?).'], 500); }
    clearstatcache(true, $part);
    $out(['ok' => true, 'size' => filesize($part)]);
}

if ($act === 'finish') {
    $version = trim((string)($_POST['version'] ?? ''));
    if (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-+ ]{0,29}$/', $version)) { @unlink($part); $out(['error' => 'Give the version like 1.0.0.'], 400); }
    if (!is_file($part)) { $out(['error' => 'No uploaded file found. Try again.'], 400); }
    $size = filesize($part);
    if ($size < 100000 || !app_looks_like_apk($part)) { @unlink($part); $out(['error' => 'That does not look like an Android APK file.'], 400); }
    $name = 'app-' . bin2hex(random_bytes(8)) . '.apk';
    if (!@rename($part, $dir . '/' . $name)) { $out(['error' => 'Could not save the file.'], 500); }
    $sha = hash_file('sha256', $dir . '/' . $name);
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000);
    $minA = mb_substr(trim((string)($_POST['min_android'] ?? '')), 0, 20);
    $pdo = db();
    $pdo->prepare('INSERT INTO app_releases (version, notes, min_android, file_name, file_size, sha256, is_current) VALUES (?,?,?,?,?,?,0)')->execute([$version, $notes, $minA, $name, $size, $sha]);
    $rid = (int)$pdo->lastInsertId();
    if (!empty($_POST['make_current'])) { $pdo->exec('UPDATE app_releases SET is_current = 0'); $pdo->prepare('UPDATE app_releases SET is_current = 1 WHERE id = ?')->execute([$rid]); }
    admin_log('app.upload', (string)$rid, 'v' . $version . ' ' . app_size_text($size));
    $out(['ok' => true, 'id' => $rid, 'size' => $size, 'sha256' => $sha]);
}
$out(['error' => 'Unknown action'], 400);
