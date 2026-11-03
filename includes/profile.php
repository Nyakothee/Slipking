<?php
/**
 * Member profile pictures and nationality flags.
 *  - Pictures are stored in uploads/avatars/ (re-drawn as 400x400 JPEG when GD is available; otherwise checked and stored as it is).
 *  - The flag is the member's nationality (chosen on the profile page); if none was chosen, the country of their mobile number is used.
 *    Members can switch their flag off.
 *  - Flag images come from the free flagcdn.com service, unless you drop your own files named like assets/flags/ke.svg (or .png) on the server.
 * Every function below keeps working (shows initials, no flag) when update-14 has not been imported yet.
 */

/** Have the update-14 columns been added to the users table? */
function profile_cols(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT avatar, nationality, show_flag FROM users LIMIT 0');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Extra columns to add to a SELECT that already has the users table under $alias. Starts with a comma, or is empty. */
function profile_sql(string $alias = 'u'): string
{
    return profile_cols() ? ", $alias.avatar, $alias.nationality, $alias.show_flag, $alias.country" : '';
}

/** Same columns for a GROUP BY. */
function profile_group_sql(string $alias = 'u'): string
{
    return profile_cols() ? ", $alias.avatar, $alias.nationality, $alias.show_flag, $alias.country" : '';
}

/** The ISO code to show as a flag for this member row, or '' (none chosen, hidden by the member, or not a known country). */
function flag_iso(array $u): string
{
    if (array_key_exists('show_flag', $u) && (int)$u['show_flag'] === 0) {
        return '';
    }
    $c = strtoupper((string)(!empty($u['nationality']) ? $u['nationality'] : ($u['country'] ?? '')));
    return ($c !== '' && isset(countries()[$c])) ? $c : '';
}

/** A small flag picture (4:3). */
function flag_html(string $iso, int $h = 14): string
{
    $iso = strtoupper($iso);
    if ($iso === '' || !isset(countries()[$iso])) {
        return '';
    }
    $name = countries()[$iso]['name'];
    $lc = strtolower($iso);
    $w = (int)round($h * 4 / 3);
    foreach (['svg', 'png'] as $ext) {
        if (is_file(dirname(__DIR__) . '/assets/flags/' . $lc . '.' . $ext)) {
            return '<img class="fl" src="' . e(url('assets/flags/' . $lc . '.' . $ext)) . '" alt="' . e($name) . '" title="' . e($name) . '" width="' . $w . '" height="' . $h . '" loading="lazy">';
        }
    }
    $px = $h <= 15 ? 20 : ($h <= 22 ? 40 : 80);
    return '<img class="fl" src="https://flagcdn.com/w' . $px . '/' . $lc . '.png" srcset="https://flagcdn.com/w' . ($px * 2) . '/' . $lc . '.png 2x" alt="' . e($name) . '" title="' . e($name)
        . '" width="' . $w . '" height="' . $h . '" loading="lazy" referrerpolicy="no-referrer" onerror="this.outerHTML=\'<span class=&quot;fl fl-t&quot;>' . e($iso) . '</span>\'">';
}

/** Round picture of a member, or their initial on a colour when they have none. */
function user_avatar_html(array $u, int $size = 40): string
{
    $name = (string)($u['name'] ?? '?');
    if (!empty($u['avatar']) && (media_is_path((string)$u['avatar']) || preg_match('#^uploads/(avatars/u|tipsters/tp)-[a-f0-9]{16}\.(jpg|png|webp)$#', (string)$u['avatar']))) {
        return '<img class="uav" src="' . e(url($u['avatar'])) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy">';
    }
    $hue = hexdec(substr(md5(strtolower($name)), 0, 4)) % 360;
    return '<span class="uav uav-ini" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . (int)($size * 0.45) . 'px;background:hsl(' . $hue . ',45%,38%)" aria-hidden="true">'
        . e(mb_strtoupper(mb_substr(trim($name) === '' ? '?' : trim($name), 0, 1))) . '</span>';
}

/** Picture, shown name and flag in one line. $text is the already-masked name to show. */
function user_chip_html(array $u, string $text, int $size = 26): string
{
    return '<span class="uchip">' . user_avatar_html($u, $size) . '<span class="uname">' . e($text) . '</span>' . flag_html(flag_iso($u), max(12, (int)round($size * 0.5))) . '</span>';
}

/** What kind of picture is this file? Returns 'jpg', 'png', 'webp' or '' (not a picture). Needs neither GD nor fileinfo. */
function picture_kind(string $tmp): string
{
    $head = (string)@file_get_contents($tmp, false, null, 0, 16);
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) { $kind = 'jpg'; }
    elseif (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) { $kind = 'png'; }
    elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') { $kind = 'webp'; }
    else { return ''; }
    $info = @getimagesize($tmp);       // core PHP, no extension needed
    if (!$info || $info[0] < 50 || $info[1] < 50 || $info[0] > 8000 || $info[1] > 8000) {
        return '';
    }
    return $kind;
}

/** Makes sure a pictures folder exists and cannot run scripts. */
function picture_dir(string $sub): string
{
    $dir = dirname(__DIR__) . '/uploads/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# Uploaded pictures only. Never run scripts from here.\n<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n<FilesMatch \"\\.(php|phtml|php[0-9]|phar|pl|py|cgi|sh)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\nOptions -Indexes\n");
    }
    return $dir;
}

/**
 * Shared picture saver for members and tipsters. Works on every server:
 *  - With PHP GD the picture is re-drawn as a clean 400x400 JPEG (best: removes hidden data, makes files small, fixes phone rotation).
 *  - Without GD (some cheap hosts) the picture is checked by its real content and stored as it is (still a JPG, PNG or WebP, 2 MB at most).
 * Returns the file name inside $sub, or null with $err set (empty $err = no file chosen).
 */
function picture_store(?array $file, string $sub, string $prefix, ?string &$err = null): ?string
{
    $err = '';
    if (!$file || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'The picture is too big (2 MB maximum).' : 'The picture could not be uploaded. Please try again.';
        return null;
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        $err = 'The picture is too big (2 MB maximum).';
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        $err = 'The picture could not be uploaded. Please try again.';
        return null;
    }
    $kind = picture_kind($file['tmp_name']);
    if ($kind === '') {
        $err = 'Use a JPG, PNG or WebP picture, at least 50 x 50 pixels.';
        return null;
    }
    $dir = picture_dir($sub);
    $base = $prefix . bin2hex(random_bytes(8));
    $make = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$kind];

    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg') && function_exists($make)) {
        $src = @$make($file['tmp_name']);
        if ($src) {
            if ($kind === 'jpg' && function_exists('exif_read_data') && function_exists('imagerotate')) {
                $ex = @exif_read_data($file['tmp_name']);
                $deg = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
                if ($deg !== 0) {
                    $rot = @imagerotate($src, $deg, 0);
                    if ($rot) { imagedestroy($src); $src = $rot; }
                }
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $dst = imagecreatetruecolor(400, 400);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 400, 400, $side, $side);
            $ok = @imagejpeg($dst, $dir . '/' . $base . '.jpg', 85);
            imagedestroy($src);
            imagedestroy($dst);
            if ($ok) {
                return $base . '.jpg';
            }
        }
    }
    // No GD (or GD could not handle it): store the checked file itself. A real picture never contains script tags.
    $raw = (string)@file_get_contents($file['tmp_name']);
    if ($raw === '' || stripos($raw, '<?php') !== false || stripos($raw, '<script') !== false) {
        $err = 'That picture could not be read. Try another one.';
        return null;
    }
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $base . '.' . $kind)) {
        if (@file_put_contents($dir . '/' . $base . '.' . $kind, $raw) === false) {
            $err = 'The picture could not be saved. The uploads/' . $sub . ' folder must be writable (permission 755 or 775).';
            return null;
        }
    }
    return $base . '.' . $kind;
}

/**
 * Saves an uploaded profile picture as uploads/avatars/u-xxxx.(jpg|png|webp). Returns the stored path or null ($err empty = no file chosen).
 */
function avatar_save(?array $file, ?string &$err = null): ?string
{
    $cu = current_user();
    $m = media_store($file, 'avatar', $cu ? (int)$cu['id'] : 0, 'square', $err, 2097152);      // kept in the database
    if ($m !== null || $err !== '') { return $m; }
    $n = picture_store($file, 'avatars', 'u-', $err);
    return $n === null ? null : 'uploads/avatars/' . $n;
}

function avatar_delete(?string $path): void
{
    if (media_is_path($path)) { media_delete($path); return; }
    if ($path && preg_match('#^uploads/avatars/u-[a-f0-9]{16}\.(jpg|png|webp)$#', $path)) {
        @unlink(dirname(__DIR__) . '/' . $path);
    }
}

/** The picture, flag and label shown at the top of the page for the logged-in person (picture only; the flag shows elsewhere on the site, not in the menu bar). */
function nav_me_html(string $href, string $label, string $class = 'navauth navme'): string
{
    $u = current_user() ?: [];
    if (empty($u['avatar']) && !empty($u['is_tipster_account']) && function_exists('tipster_for_user')) {
        $tp = tipster_for_user((int)($u['id'] ?? 0));
        if ($tp && !empty($tp['avatar_url'])) { $u['avatar'] = $tp['avatar_url']; }
    }
    return '<a class="' . e($class) . '" href="' . e($href) . '" aria-label="' . e($label) . '" title="' . e($label) . '">' . user_avatar_html($u, 28) . '<span>' . e($label) . '</span>' . (($ucnt = (!empty($u['id']) && function_exists('notif_unread_count')) ? notif_unread_count((int)$u['id']) : 0) ? '<b class="nbadge" aria-label="' . $ucnt . ' unread">' . ($ucnt > 99 ? '99+' : $ucnt) . '</b>' : '') . '</a>';
}

/** Crown mark used in the logo. */
function crown_svg(int $size = 30): string
{
    return '<svg class="crown" viewBox="0 0 32 28" width="' . $size . '" height="' . round($size * 28 / 32) . '" aria-hidden="true" focusable="false">'
        . '<path fill="currentColor" d="M2.2 7.4a2 2 0 0 1 3.2-.4l5 4.6 4.1-8.2a2 2 0 0 1 3.6 0l4.1 8.2 5-4.6a2 2 0 0 1 3.2.4 2 2 0 0 1 .1 1.8L27.4 21a2 2 0 0 1-1.9 1.4H6.5A2 2 0 0 1 4.6 21L2.1 9.2a2 2 0 0 1 .1-1.8z"/>'
        . '<rect fill="currentColor" x="6" y="23.8" width="20" height="3" rx="1.5"/></svg>';
}

/** Logo: crown plus the site name, with "King" highlighted when the name is Slip + King. */
function brand_html(): string
{
    $n = (string)SITE_NAME;
    $text = preg_match('/^(Slip)(King)$/i', $n, $m) ? e($m[1]) . '<b>' . e($m[2]) . '</b>' : e($n);
    return crown_svg(32) . '<span class="bname">' . $text . '</span>';
}

/** Account chip for the top bar: picture, name, status line and a small menu. */
function nav_user_chip(bool $tipsterAcct): string
{
    $u = current_user();
    if (!$u) { return ''; }
    if (empty($u['avatar']) && !empty($u['is_tipster_account']) && function_exists('tipster_for_user')) {
        $tp = tipster_for_user((int)$u['id']);
        if ($tp && !empty($tp['avatar_url'])) { $u['avatar'] = $tp['avatar_url']; }
    }
    $first = mb_substr(trim((string)strtok((string)$u['name'], ' ')), 0, 14);
    $sub = $tipsterAcct ? 'Tipster' : (is_vip() ? 'Premium' : 'Member');
    $home = $tipsterAcct ? url('tipster/index.php') : url('account/dashboard.php');
    return '<div class="prefs userchip"><details><summary aria-label="Account menu">' . user_avatar_html($u, 32)
        . '<span class="uc-t"><b>' . e($first) . '</b><small class="' . ($sub === 'Premium' ? 'prem' : '') . '">' . e($sub) . '</small></span></summary>'
        . '<div class="pmenu uc-menu"><a href="' . e($home) . '">Dashboard</a><a href="' . e(url('account/notifications.php')) . '">Messages</a>'
        . ($tipsterAcct ? '' : '<a href="' . e(url('account/profile.php')) . '">Profile</a>')
        . '<a href="' . e(url('auth/logout.php')) . '">Log out</a></div></details></div>';
}

/** Latest messages for the navbar bell, plus the unread count. Never throws. */
function nav_bell_data(): array
{
    static $d = null;
    if ($d !== null) { return $d; }
    $d = ['unread' => 0, 'items' => []];
    $u = current_user();
    if (!$u) { return $d; }
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $st->execute([$u['id']]);
        $d['unread'] = (int)$st->fetchColumn();
        $st = db()->prepare('SELECT title, message, is_read, user_id, created_at FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 6');
        $st->execute([$u['id']]);
        $d['items'] = $st->fetchAll();
    } catch (Throwable $e) { /* the bell just stays empty */ }
    return $d;
}

/** The notification bell with a small dropdown. Empty string when logged out. */
function nav_bell_html(): string
{
    if (!current_user()) { return ''; }
    $b = nav_bell_data();
    $all = url('account/notifications.php');
    $h = '<div class="prefs bell"><details><summary aria-label="Notifications' . ($b['unread'] ? ' (' . $b['unread'] . ' unread)' : '') . '">'
       . '<svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>'
       . ($b['unread'] ? '<b class="bell-n">' . ($b['unread'] > 9 ? '9+' : (int)$b['unread']) . '</b>' : '')
       . '</summary><div class="pmenu bell-menu"><div class="bell-h">Notifications</div>';
    if (!$b['items']) { $h .= '<p class="bell-empty">No notifications yet.</p>'; }
    foreach ($b['items'] as $n) {
        $unread = $n['user_id'] !== null && !$n['is_read'];
        $h .= '<a href="' . e($all) . '" class="bell-i' . ($unread ? ' unread' : '') . '"><strong>' . e($n['title']) . '</strong><span>' . e(mb_substr((string)$n['message'], 0, 90)) . '</span><small>' . e(date('j M, H:i', strtotime($n['created_at']))) . '</small></a>';
    }
    return $h . '<a class="bell-all" href="' . e($all) . '">View all messages</a></div></details></div>';
}
