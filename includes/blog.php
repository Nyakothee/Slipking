<?php
/**
 * Blog articles with pictures and videos.
 * The article text is still plain text (so it is always safe), plus a few simple codes:
 *   [img:uploads/blog/b-xxxx.jpg|Caption]     a picture you uploaded (or an https:// picture link); the caption is optional
 *   [video:https://youtu.be/XXXXXXXXXXX|Caption]   YouTube, Vimeo, an uploaded video, or a direct https .mp4/.webm link
 *   ## Heading     ### Smaller heading     - list item     **bold**     *italic*     [link text](https://example.com)
 * Each code goes on its own line. Anything else is shown as plain text.
 */

function blog_media_ok(string $u): bool
{
    return (bool)preg_match('#^uploads/blog/b-[a-f0-9]{16}\.(jpg|png|webp|mp4|webm)$#', $u);
}

function blog_asset_url(string $u): string
{
    return blog_media_ok($u) ? url($u) : $u;
}

/** Is this an https link we are happy to show? */
function blog_https(string $u): bool
{
    return strlen($u) <= 500 && preg_match('#^https://[^\s<>"\']+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL);
}

function blog_figure_img(string $src, string $cap): string
{
    if (!blog_media_ok($src) && !blog_https($src)) {
        return '';
    }
    return '<figure class="bm"><img src="' . e(blog_asset_url($src)) . '" alt="' . e($cap) . '" loading="lazy">' . ($cap !== '' ? '<figcaption>' . e($cap) . '</figcaption>' : '') . '</figure>';
}

function blog_figure_video(string $src, string $cap): string
{
    $cap = trim($cap);
    $inner = '';
    if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\s]*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $src, $m)) {
        $inner = '<div class="vid"><iframe src="https://www.youtube-nocookie.com/embed/' . e($m[1]) . '" title="' . e($cap !== '' ? $cap : 'Video') . '" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen"></iframe></div>';
    } elseif (preg_match('#^https?://(?:www\.)?vimeo\.com/(?:video/)?([0-9]{6,12})#i', $src, $m)) {
        $inner = '<div class="vid"><iframe src="https://player.vimeo.com/video/' . e($m[1]) . '?dnt=1" title="' . e($cap !== '' ? $cap : 'Video') . '" loading="lazy" allowfullscreen allow="fullscreen; picture-in-picture"></iframe></div>';
    } elseif (blog_media_ok($src) && preg_match('/\.(mp4|webm)$/', $src)) {
        $inner = '<video class="bv" controls preload="metadata" playsinline src="' . e(url($src)) . '"></video>';
    } elseif (blog_https($src) && preg_match('/\.(mp4|webm)(\?[^\s]*)?$/i', $src)) {
        $inner = '<video class="bv" controls preload="metadata" playsinline src="' . e($src) . '"></video>';
    }
    if ($inner === '') {
        return '';
    }
    return '<figure class="bm">' . $inner . ($cap !== '' ? '<figcaption>' . e($cap) . '</figcaption>' : '') . '</figure>';
}

/** Escapes a line of text and turns **bold**, *italic* and [text](https://link) into HTML. */
function blog_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace_callback('/\[([^\]\[]{1,120})\]\((https:\/\/[^\s()<>"\']{4,300})\)/', function ($m) {
        return '<a href="' . $m[2] . '" rel="noopener nofollow" target="_blank">' . $m[1] . '</a>';
    }, $s);
    $s = preg_replace('/\*\*([^*\n]{1,200})\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![*\w])\*([^*\n]{1,200})\*(?![*\w])/', '<em>$1</em>', $s);
    return $s;
}

/** The article body as safe HTML. */
function blog_render(string $body): string
{
    $out = '';
    $para = [];
    $list = [];
    $flushP = function () use (&$para, &$out) {
        if ($para) { $out .= '<p>' . implode('<br>', array_map('blog_inline', $para)) . '</p>'; $para = []; }
    };
    $flushL = function () use (&$list, &$out) {
        if ($list) { $out .= '<ul>' . implode('', array_map(function ($i) { return '<li>' . blog_inline($i) . '</li>'; }, $list)) . '</ul>'; $list = []; }
    };
    foreach (preg_split('/\R/', trim($body)) as $line) {
        $t = trim($line);
        if ($t === '') { $flushP(); $flushL(); continue; }
        if (preg_match('/^\[img:([^\]|]+)(?:\|([^\]]*))?\]$/', $t, $m)) {
            $flushP(); $flushL(); $out .= blog_figure_img(trim($m[1]), trim($m[2] ?? '')); continue;
        }
        if (preg_match('/^\[video:([^\]|]+)(?:\|([^\]]*))?\]$/', $t, $m)) {
            $flushP(); $flushL(); $out .= blog_figure_video(trim($m[1]), trim($m[2] ?? '')); continue;
        }
        if (preg_match('/^(#{2,3})\s+(.{1,160})$/', $t, $m)) {
            $flushP(); $flushL(); $tag = strlen($m[1]) === 2 ? 'h2' : 'h3'; $out .= "<$tag>" . blog_inline($m[2]) . "</$tag>"; continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $t, $m)) {
            $flushP(); $list[] = $m[1]; continue;
        }
        $flushL();
        $para[] = $t;
    }
    $flushP(); $flushL();
    return $out;
}

/** The cover picture link as a usable address ('' when none). */
function blog_cover(?string $u): string
{
    $u = (string)$u;
    return ($u !== '' && (blog_media_ok($u) || blog_https($u))) ? blog_asset_url($u) : '';
}

/**
 * Saves an uploaded picture or video into uploads/blog/. Returns ['path' => ..., 'kind' => 'img'|'video'] or null (with $err set).
 * Pictures are re-drawn with GD (max 1600 px wide), which removes hidden data. Videos are checked by their real content type.
 */
function blog_save_upload(?array $file, ?string &$err = null, bool $imageOnly = false): ?array
{
    $err = '';
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $err = 'Choose a file first.';
        return null;
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        $err = 'The file is bigger than this server allows (it allows ' . ini_get('upload_max_filesize') . '). For long videos, paste a YouTube or Vimeo link instead.';
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        $err = 'The upload failed. Please try again.';
        return null;
    }
    if (!function_exists('finfo_open')) {
        $err = 'Uploads need the PHP fileinfo extension on this server.';
        return null;
    }
    $mime = (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
    $dir = dirname(__DIR__) . '/uploads/blog';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $base = 'b-' . bin2hex(random_bytes(8));

    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        if ($file['size'] > 6 * 1024 * 1024) { $err = 'Pictures can be 6 MB at most.'; return null; }
        $make = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'][$mime];
        $info = @getimagesize($file['tmp_name']);
        if (!function_exists('imagecreatetruecolor') || !function_exists($make) || !$info || $info[0] < 20 || $info[1] < 20 || $info[0] > 7000 || $info[1] > 7000 || $info[0] * $info[1] > 30000000) {
            $err = 'That picture could not be read. Use a JPG, PNG or WebP file.';
            return null;
        }
        $src = @$make($file['tmp_name']);
        if (!$src) { $err = 'That picture could not be read. Try another one.'; return null; }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $ex = @exif_read_data($file['tmp_name']);
            $deg = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
            if ($deg !== 0 && function_exists('imagerotate')) { $rot = @imagerotate($src, $deg, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
        }
        $w = imagesx($src); $h = imagesy($src);
        $nw = min($w, 1600); $nh = (int)round($h * $nw / $w);
        $dst = imagecreatetruecolor($nw, $nh);
        $png = $mime === 'image/png';
        if ($png) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
        else { imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $name = $base . ($png ? '.png' : '.jpg');
        $ok = $png ? @imagepng($dst, $dir . '/' . $name, 6) : @imagejpeg($dst, $dir . '/' . $name, 85);
        imagedestroy($src); imagedestroy($dst);
        if (!$ok) { $err = 'The picture could not be saved. The uploads/blog folder must be writable.'; return null; }
        return ['path' => 'uploads/blog/' . $name, 'kind' => 'img'];
    }
    if ($imageOnly) {
        $err = 'Use a JPG, PNG or WebP picture.';
        return null;
    }
    if (in_array($mime, ['video/mp4', 'video/webm'], true)) {
        if ($file['size'] > 60 * 1024 * 1024) { $err = 'Videos can be 60 MB at most. For longer videos paste a YouTube or Vimeo link.'; return null; }
        $name = $base . ($mime === 'video/webm' ? '.webm' : '.mp4');
        if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) { $err = 'The video could not be saved. The uploads/blog folder must be writable.'; return null; }
        @chmod($dir . '/' . $name, 0644);
        return ['path' => 'uploads/blog/' . $name, 'kind' => 'video'];
    }
    $err = 'Use a JPG, PNG or WebP picture, or an MP4 or WebM video.';
    return null;
}

/** True once database-update-30.sql has added posts.category (the blog works without it, just without categories). */
function blog_has_category(): bool
{
    static $has = null;
    if ($has === null) {
        try { db()->query('SELECT category FROM posts LIMIT 1'); $has = true; } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Categories with their published-post counts, biggest first. */
function blog_categories(): array
{
    if (!blog_has_category()) { return []; }
    try { return db()->query("SELECT category AS name, COUNT(*) AS n FROM posts WHERE status = 'published' GROUP BY category ORDER BY n DESC, category LIMIT 12")->fetchAll(); }
    catch (Throwable $e) { return []; }
}
