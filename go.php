<?php
/**
 * Affiliate redirect: go.php?b=<bookmaker slug>&t=<tip id, optional>&p=<page label>
 * Logs the click (hashed IP only, bots ignored, one per visitor+bookmaker per 10 minutes) and sends the visitor on.
 */
define('NO_TRANSLATE', true);
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/bookmakers.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$slug = substr((string)preg_replace('/[^a-z0-9\-]/', '', strtolower((string)($_GET['b'] ?? ''))), 0, 60);
$bm = null;
if ($slug !== '') {
    try {
        $st = db()->prepare('SELECT id, affiliate_url FROM bookmakers WHERE slug = ? AND is_active = 1 LIMIT 1');
        $st->execute([$slug]);
        $bm = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $bm = null;
    }
}
if (!$bm || bookmaker_is_placeholder($bm['affiliate_url']) || !bookmaker_valid_url($bm['affiliate_url'])) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$ua    = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $ua === ''
    || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|curl|wget|python|java\/|headless|monitor|scan|fetch|httpclient/i', $ua);

if (!$isBot) {
    try {
        $pdo    = db();
        $hash   = hash_hmac('sha256', client_ip(), app_secret());      // the raw IP is never stored
        $page   = substr((string)preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($_GET['p'] ?? ''))), 0, 60);
        $tipId  = (int)($_GET['t'] ?? 0);
        if ($tipId) {
            $st = $pdo->prepare('SELECT id FROM tips WHERE id = ?');
            $st->execute([$tipId]);
            $tipId = (int)$st->fetchColumn();
        }
        $st = $pdo->prepare('SELECT 1 FROM bookmaker_clicks WHERE bookmaker_id = ? AND ip_hash = ? AND clicked_at > NOW() - INTERVAL 10 MINUTE LIMIT 1');
        $st->execute([$bm['id'], $hash]);
        if (!$st->fetchColumn()) {
            $u = current_user();
            $pdo->prepare('INSERT INTO bookmaker_clicks (bookmaker_id, tip_id, user_id, ip_hash, page) VALUES (?, ?, ?, ?, ?)')
                ->execute([$bm['id'], $tipId ?: null, $u ? (int)$u['id'] : null, $hash, $page]);
        }
    } catch (Throwable $e) {
        error_log('go.php click log failed: ' . $e->getMessage());     // never block the visitor
    }
}

header('Location: ' . $bm['affiliate_url'], true, 302);
exit;
