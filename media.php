<?php
/**
 * Serves a picture stored in the database: media.php?id=123 .
 * Deliberately NOT built on includes/init.php: that file starts sessions, may translate or redirect the page, and any stray
 * space or notice before the first byte would corrupt a picture. Here only the config and the database are loaded, and every
 * byte of output produced while loading is thrown away before the picture is sent.
 */
ob_start();
@ini_set('display_errors', '0');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/media.php';
$id = (int)($_GET['id'] ?? 0);
$row = null;
if ($id > 0) {
    try {
        $q = db()->prepare('SELECT mime, data FROM media_blobs WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch();
    } catch (Throwable $e) { error_log('media.php: ' . $e->getMessage()); $row = null; }
}
while (ob_get_level() > 0) { ob_end_clean(); }
if (!$row) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Picture not found'; exit; }
$data = (string)$row['data'];
$etag = '"m' . $id . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=31536000, immutable');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
$docMime = strpos((string)$row['mime'], 'doc:') === 0 ? media_doc_mime((string)$row['mime']) : null;
if ($docMime !== null) {
    $dn = preg_replace('/[^A-Za-z0-9._ \-]/', '', (string)($_GET['n'] ?? ''));
    if ($dn === '') { $dn = 'document.' . substr((string)$row['mime'], 4); }
    header('Content-Type: ' . $docMime);
    header('Content-Disposition: ' . ($row['mime'] === 'doc:pdf' ? 'inline' : 'attachment') . '; filename="' . $dn . '"');
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($data));
    echo $data; exit;
}
$mime = in_array($row['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $row['mime'] : 'image/jpeg';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . strlen($data));
echo $data;
