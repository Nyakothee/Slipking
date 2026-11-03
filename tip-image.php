<?php
/** Share picture for a tip (PNG 1200x630): used for WhatsApp/X link previews, "share as image" and "save to gallery" in the app. ?id=&dl=1 downloads. */
define('NO_TRANSLATE', true);
require_once __DIR__ . '/includes/init.php';
$id = (int)($_GET['id'] ?? 0);
$t = $id ? fetch_tip($id) : null;
if (!$t || !function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) { http_response_code(404); exit; }
$locked = !empty($t['is_vip']) && $t['status'] === 'pending';          // premium picks are never drawn while pending
$key = $id . '-' . ($locked ? 'l' : $t['status'] . '-' . (int)($t['odds'] * 100)) . '-' . substr(md5($t['pick'] . $t['home_team'] . $t['away_team'] . $t['kickoff_at']), 0, 6);
$dir = __DIR__ . '/cache/tipimg';
$file = $dir . '/' . $key . '.png';
if (!is_file($file)) {
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $W = 1200; $H = 630;
    $im = imagecreatetruecolor($W, $H);
    $bg = imagecolorallocate($im, 14, 22, 32); $card = imagecolorallocate($im, 24, 36, 52); $gold = imagecolorallocate($im, 245, 179, 1);
    $white = imagecolorallocate($im, 255, 255, 255); $mute = imagecolorallocate($im, 150, 165, 185);
    $green = imagecolorallocate($im, 67, 160, 71); $red = imagecolorallocate($im, 229, 57, 53);
    imagefilledrectangle($im, 0, 0, $W, $H, $bg);
    imagefilledrectangle($im, 40, 40, $W - 40, $H - 40, $card);
    imagefilledrectangle($im, 40, 40, 52, $H - 40, $gold);
    $fb = __DIR__ . '/assets/fonts/DejaVuSans-Bold.ttf'; $fr = __DIR__ . '/assets/fonts/DejaVuSans.ttf';
    $fit = function (string $s, int $size, int $max) use ($fb) { while ($size > 18) { $bb = imagettfbbox($size, 0, $fb, $s); if (($bb[2] - $bb[0]) <= $max) { break; } $size -= 2; } return $size; };
    imagettftext($im, 26, 0, 90, 105, $gold, $fb, strtoupper(SITE_NAME));
    imagettftext($im, 22, 0, 90, 150, $mute, $fr, mb_substr($t['league'] . '  |  ' . date('D j M, H:i', strtotime($t['kickoff_at'])), 0, 70));
    $teams = $t['home_team'] . '  vs  ' . $t['away_team'];
    $sz = $fit($teams, 54, 1020);
    imagettftext($im, $sz, 0, 90, 260, $white, $fb, $teams);
    if ($locked) {
        imagettftext($im, 40, 0, 90, 380, $gold, $fb, ($t['tipster_slug'] ?? '') !== '' ? 'PREMIUM TIP' : 'VIP TIP');
        imagettftext($im, 26, 0, 90, 440, $mute, $fr, 'Pick and odds are for members until the match ends.');
    } else {
        $pick = mb_substr($t['pick'], 0, 60);
        imagettftext($im, $fit($pick, 52, 760), 0, 90, 380, $gold, $fb, $pick);
        imagettftext($im, 26, 0, 90, 440, $mute, $fr, $t['market']);
        imagefilledrectangle($im, 880, 320, 1110, 420, $bg);
        imagettftext($im, 54, 0, 900, 395, $white, $fb, number_format((float)$t['odds'], 2));
        if (in_array($t['status'], ['won', 'lost'], true)) {
            $c = $t['status'] === 'won' ? $green : $red;
            imagefilledrectangle($im, 90, 480, 300, 540, $c);
            imagettftext($im, 30, 0, 118, 523, $white, $fb, strtoupper($t['status']));
        }
    }
    imagettftext($im, 22, 0, 90, 585, $mute, $fr, preg_replace('~^https?://~', '', url('tip.php?id=' . $id)));
    imagepng($im, $file, 6);
    imagedestroy($im);
    // keep the cache folder small
    $files = glob($dir . '/*.png') ?: [];
    if (count($files) > 400) { usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); }); foreach (array_slice($files, 0, 100) as $old) { @unlink($old); } }
}
header('Content-Type: image/png');
header('Cache-Control: public, max-age=600');
if (!empty($_GET['dl'])) { header('Content-Disposition: attachment; filename="slipking-tip-' . $id . '.png"'); }
readfile($file);
