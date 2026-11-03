<?php
/**
 * Thumbnail pictures for tips (matches), 2 Odds Streak picks and Replays.
 * A thumbnail is either an uploaded picture (saved small in uploads/thumbs/) or a pasted picture link.
 * The `thumb` column is created the first time it is needed.
 */
function thumb_col(string $table): bool
{
    static $ok = [];
    if (isset($ok[$table])) { return $ok[$table]; }
    if (!in_array($table, ['matches', 'streak_days', 'replays'], true)) { return $ok[$table] = false; }
    try {
        if (setting('thumb_col_' . $table, '0') === '1') { return $ok[$table] = true; }
        $r = sk_ensure_column($table, 'thumb', "VARCHAR(400) NOT NULL DEFAULT ''");
        if ($r) { set_setting('thumb_col_' . $table, '1'); }
        return $ok[$table] = $r;
    } catch (Throwable $e) { return $ok[$table] = false; }
}

/** Keeps only an http(s) picture link or one of our own uploads/thumbs/ files. */
function thumb_clean(string $v): string
{
    $v = trim($v);
    if ($v === '') { return ''; }
    if (preg_match('#^uploads/thumbs/[A-Za-z0-9._-]+$#', $v)) { return $v; }
    return preg_match('~^https?://[^\s<>"\']{4,380}$~i', $v) ? $v : '';
}

/** Address to put in <img src>. */
function thumb_src(?string $v): string
{
    $v = thumb_clean((string)$v);
    if ($v === '') { return ''; }
    return strpos($v, 'uploads/') === 0 ? url($v) : $v;
}

/** An <img> for a thumbnail, or '' when there is none. */
function thumb_img(?string $v, string $cls = 'thumb', string $alt = ''): string
{
    $s = thumb_src($v);
    return $s === '' ? '' : '<img class="' . e($cls) . '" src="' . e($s) . '" alt="' . e($alt) . '" loading="lazy">';
}

/** Saves an uploaded JPG/PNG/WebP as a small picture (max 640 px wide). Returns the stored path, or null with $err set (empty $err = nothing uploaded). */
function thumb_save(?array $file, ?string &$err = null): ?string
{
    $err = '';
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) { return null; }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) { $err = 'The picture is bigger than this server allows (' . ini_get('upload_max_filesize') . ').'; return null; }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { $err = 'The picture upload failed. Please try again.'; return null; }
    if ($file['size'] > 5 * 1024 * 1024) { $err = 'Thumbnails can be 5 MB at most.'; return null; }
    if (!function_exists('finfo_open') || !function_exists('imagecreatetruecolor')) { $err = 'Picture uploads need the PHP fileinfo and GD extensions. You can paste a picture link instead.'; return null; }
    $mime = (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
    $make = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime] ?? '';
    $info = @getimagesize($file['tmp_name']);
    if ($make === '' || !function_exists($make) || !$info || $info[0] < 20 || $info[1] < 20 || $info[0] > 7000 || $info[1] > 7000) { $err = 'Use a JPG, PNG or WebP picture.'; return null; }
    $src = @$make($file['tmp_name']);
    if (!$src) { $err = 'That picture could not be read. Try another one.'; return null; }
    $w = imagesx($src); $h = imagesy($src);
    $nw = min($w, 640); $nh = (int)round($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = dirname(__DIR__) . '/uploads/thumbs';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $name = 't-' . bin2hex(random_bytes(8)) . '.jpg';
    $ok = @imagejpeg($dst, $dir . '/' . $name, 82);
    imagedestroy($src); imagedestroy($dst);
    if (!$ok) { $err = 'The picture could not be saved. The uploads/thumbs folder must be writable.'; return null; }
    return 'uploads/thumbs/' . $name;
}

/**
 * Reads the thumbnail fields of a form: returns [value to store (or null = leave as it is), error].
 * Fields: thumb (file), thumb_url (link), thumb_remove (checkbox).
 */
function thumb_from_post(): array
{
    if (!empty($_POST['thumb_remove'])) { return ['', '']; }
    $err = '';
    $p = thumb_save($_FILES['thumb'] ?? null, $err);
    if ($err !== '') { return [null, $err]; }
    if ($p !== null) { return [$p, '']; }
    $u = trim((string)($_POST['thumb_url'] ?? ''));
    if ($u === '') { return [null, '']; }
    $c = thumb_clean($u);
    return $c === '' ? [null, 'The picture link must start with http:// or https://'] : [$c, ''];
}

/** Form fields for the admin pages. */
function thumb_field(string $current = ''): string
{
    $h = '<div class="field full"><label>Thumbnail picture (optional)</label>';
    if (thumb_src($current) !== '') { $h .= '<div style="margin:6px 0">' . thumb_img($current, 'thumb', '') . '</div>'; }
    $h .= '<input type="file" name="thumb" accept="image/jpeg,image/png,image/webp"> <span class="hint">or paste a link:</span> <input type="url" name="thumb_url" maxlength="380" placeholder="https://..." style="max-width:360px">';
    if (thumb_src($current) !== '') { $h .= '<label style="display:block;margin-top:6px"><input type="checkbox" name="thumb_remove" value="1"> Remove the current picture</label>'; }
    return $h . '<div class="hint">JPG, PNG or WebP. Shown on the card in the site and the app.</div></div>';
}
