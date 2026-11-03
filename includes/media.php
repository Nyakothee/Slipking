<?php
/**
 * Pictures kept in the DATABASE (table media_blobs), served by media.php.
 * Unlike files in uploads/, they survive hosts that wipe or rebuild the disk, so profile photos and post pictures stay for good.
 * Stored path looks like "media.php?id=123". Older "uploads/..." paths keep working.
 */

function media_ready(): bool
{
    return function_exists('sk_ensure_table') && sk_ensure_table('media_blobs', 'database-update-20.sql');
}

/** True for a stored picture path of the database kind. */
function media_is_path(?string $p): bool
{
    return (bool)preg_match('#^media\.php\?id=\d{1,12}$#', (string)$p);
}

function media_id(?string $p): int
{
    return preg_match('#^media\.php\?id=(\d{1,12})$#', (string)$p, $m) ? (int)$m[1] : 0;
}

function media_exists(?string $p): bool
{
    $id = media_id($p);
    if (!$id || !media_ready()) { return false; }
    try { $q = db()->prepare('SELECT 1 FROM media_blobs WHERE id = ?'); $q->execute([$id]); return (bool)$q->fetchColumn(); } catch (Throwable $e) { return false; }
}

function media_delete(?string $p): void
{
    $id = media_id($p);
    if ($id && media_ready()) { try { db()->prepare('DELETE FROM media_blobs WHERE id = ?')->execute([$id]); } catch (Throwable $e) {} }
}

/**
 * Checks an uploaded picture, re-draws it (removes hidden data) and stores it in the database.
 * $mode 'square' = 400x400 centred crop (profile photos); 'fit' = at most 1400 px on the long side (post pictures).
 * Returns "media.php?id=N", or null: $err stays '' when no file was chosen, otherwise it holds the reason.
 */
function media_store(?array $file, string $kind, int $owner, string $mode, ?string &$err = null, int $maxBytes = 4194304): ?string
{
    $err = '';
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) ? 'The picture is too big.' : 'The picture could not be uploaded. Please try again.';
        return null;
    }
    if ($file['size'] > $maxBytes) { $err = 'The picture is too big (' . (int)($maxBytes / 1048576) . ' MB maximum).'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $err = 'The picture could not be uploaded. Please try again.'; return null; }
    $k = picture_kind($file['tmp_name']);
    if ($k === '') { $err = 'Use a JPG, PNG or WebP picture, at least 50 x 50 pixels.'; return null; }
    if (!media_ready()) { return null; }     // caller falls back to a file on disk
    $make = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$k];
    $data = ''; $mime = '';
    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists($make)) {
        $src = @$make($file['tmp_name']);
        if ($src) {
            if ($k === 'jpg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
                $ex = @exif_read_data($file['tmp_name']);
                $deg = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
                if ($deg !== 0) { $rot = @imagerotate($src, $deg, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
            }
            $w = imagesx($src); $h = imagesy($src);
            if ($mode === 'square') {
                $side = min($w, $h);
                $dst = imagecreatetruecolor(400, 400);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 400, 400, $side, $side);
            } else {
                $sc = min(1, 1400 / max($w, $h));
                $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
                $dst = imagecreatetruecolor($nw, $nh);
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            }
            ob_start(); $ok = @imagejpeg($dst, null, 84); $data = (string)ob_get_clean();
            imagedestroy($src); imagedestroy($dst);
            if ($ok && $data !== '') { $mime = 'image/jpeg'; } else { $data = ''; }
        }
    }
    if ($data === '') {
        $raw = (string)@file_get_contents($file['tmp_name']);
        if ($raw === '' || stripos($raw, '<?php') !== false || stripos($raw, '<script') !== false) { $err = 'That picture could not be read. Try another one.'; return null; }
        $data = $raw; $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$k];
    }
    try {
        $st = db()->prepare('INSERT INTO media_blobs (kind, owner_id, mime, size_bytes, data) VALUES (?,?,?,?,?)');
        $st->bindValue(1, substr($kind, 0, 20));
        $st->bindValue(2, $owner, PDO::PARAM_INT);
        $st->bindValue(3, $mime);
        $st->bindValue(4, strlen($data), PDO::PARAM_INT);
        $st->bindValue(5, $data, PDO::PARAM_LOB);
        $st->execute();
        return 'media.php?id=' . (int)db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('media_store: ' . $e->getMessage());
        $err = 'The picture could not be saved. Try a smaller one.';
        return null;
    }
}

/** Any stored picture path (database or old file) as a full link. '' when empty. */
function media_src(?string $p): string
{
    $p = (string)$p;
    if ($p === '') { return ''; }
    return (media_is_path($p) || strpos($p, 'uploads/') === 0) ? url($p) : $p;
}

/** Document types members may attach in chat: extension => [stored code, real mime, magic check]. */
function media_doc_types(): array
{
    return [
        'pdf'  => ['doc:pdf',  'application/pdf'],
        'doc'  => ['doc:doc',  'application/msword'],
        'docx' => ['doc:docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['doc:xls',  'application/vnd.ms-excel'],
        'xlsx' => ['doc:xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt'  => ['doc:ppt',  'application/vnd.ms-powerpoint'],
        'pptx' => ['doc:pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt'  => ['doc:txt',  'text/plain; charset=utf-8'],
    ];
}

function media_doc_mime(string $code): ?string
{
    foreach (media_doc_types() as $t) { if ($t[0] === $code) { return $t[1]; } }
    return null;
}

/**
 * Stores a document (PDF, Word, Excel, PowerPoint, text) in the database. Returns ["path" => "media.php?id=N", "name" => clean name, "size" => bytes]
 * or null with $err set. The type is decided by the extension AND the first bytes of the file.
 */
function media_store_doc(?array $file, string $kind, int $owner, ?string &$err = null, int $maxBytes = 5242880): ?array
{
    $err = '';
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    if ($file['error'] !== UPLOAD_ERR_OK) { $err = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) ? 'That file is too big for this server.' : 'The file could not be uploaded. Please try again.'; return null; }
    if ($file['size'] > $maxBytes) { $err = 'The file is too big (' . (int)($maxBytes / 1048576) . ' MB maximum).'; return null; }
    if (!is_uploaded_file($file['tmp_name'])) { $err = 'The file could not be uploaded. Please try again.'; return null; }
    $name = preg_replace('/[^A-Za-z0-9._ \-]/', '', basename((string)($file['name'] ?? 'document')));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = media_doc_types();
    if (!isset($types[$ext])) { $err = 'Allowed files: PDF, Word, Excel, PowerPoint, text, and JPG/PNG/WebP pictures.'; return null; }
    $raw = (string)@file_get_contents($file['tmp_name']);
    if ($raw === '') { $err = 'That file is empty.'; return null; }
    $head = substr($raw, 0, 8);
    $ok = false;
    if ($ext === 'pdf') { $ok = strncmp($head, '%PDF', 4) === 0; }
    elseif (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) { $ok = strncmp($head, "PK", 2) === 0; }
    elseif (in_array($ext, ['doc', 'xls', 'ppt'], true)) { $ok = strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0; }
    else { $ok = strpos(substr($raw, 0, 4096), "\0") === false; }
    if (!$ok) { $err = 'That file does not look like a real .' . $ext . ' file.'; return null; }
    if (!media_ready()) { $err = 'Files cannot be stored yet.'; return null; }
    try {
        $st = db()->prepare('INSERT INTO media_blobs (kind, owner_id, mime, size_bytes, data) VALUES (?,?,?,?,?)');
        $st->bindValue(1, substr($kind, 0, 20)); $st->bindValue(2, $owner, PDO::PARAM_INT); $st->bindValue(3, $types[$ext][0]);
        $st->bindValue(4, strlen($raw), PDO::PARAM_INT); $st->bindValue(5, $raw, PDO::PARAM_LOB);
        $st->execute();
        return ['path' => 'media.php?id=' . (int)db()->lastInsertId(), 'name' => mb_substr($name !== '' ? $name : ('document.' . $ext), 0, 100), 'size' => strlen($raw)];
    } catch (Throwable $e) { error_log('media_store_doc: ' . $e->getMessage()); $err = 'The file could not be saved. Try a smaller one.'; return null; }
}
