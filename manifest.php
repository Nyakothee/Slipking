<?php
/** Web app manifest (makes the site installable). Served as application/manifest+json. No session, no database. */
require_once __DIR__ . '/includes/config.php';

$base  = rtrim(SITE_URL, '/') . '/';
$short = function_exists('mb_substr') ? mb_substr(SITE_NAME, 0, 12) : substr(SITE_NAME, 0, 12);
$icon  = function (string $file, string $size, string $purpose) use ($base) {
    return ['src' => $base . 'assets/img/icons/' . $file, 'sizes' => $size, 'type' => 'image/png', 'purpose' => $purpose];
};

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'id'               => $base,
    'name'             => SITE_NAME,
    'short_name'       => $short,
    'description'      => 'Free and VIP sports predictions with a public track record.',
    'start_url'        => $base,
    'scope'            => $base,
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'background_color' => '#f6f7f4',
    'theme_color'      => '#0d3b2c',
    'icons'            => [
        $icon('icon-192.png', '192x192', 'any'),
        $icon('icon-512.png', '512x512', 'any'),
        $icon('maskable-512.png', '512x512', 'maskable'),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
