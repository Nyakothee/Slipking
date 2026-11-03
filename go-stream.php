<?php
// Sends the member to the watch link added by the admin (and counts the click). Only http(s) links are followed.
require_once __DIR__ . '/includes/init.php';
$id = (int)($_GET['id'] ?? 0);
$row = null;
if ($id && streams_ready()) {
    $st = db()->prepare('SELECT id, url FROM streams WHERE id = ? AND is_active = 1');
    $st->execute([$id]);
    $row = $st->fetch();
}
$to = $row ? stream_clean_url((string)$row['url']) : '';
if ($to === '') { http_response_code(404); require __DIR__ . '/404.php'; exit; }
try { db()->prepare('UPDATE streams SET clicks = clicks + 1 WHERE id = ?')->execute([$id]); } catch (Throwable $e) {}
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Location: ' . $to, true, 302);
exit;
