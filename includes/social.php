<?php
/**
 * Social helpers: Telegram posting, follow links, share buttons.
 * Pages only need: require_once __DIR__ . '/includes/social.php';
 * Nothing here ever throws; failures are written to logs/social.log.
 */
$__cfg = __DIR__ . '/config-social.php';
if (is_file($__cfg)) {
    require_once $__cfg;
}
foreach (['TELEGRAM_BOT_TOKEN', 'TELEGRAM_FREE_CHANNEL', 'TELEGRAM_VIP_CHANNEL', 'TELEGRAM_VIP_INVITE_LINK', 'WHATSAPP_CHANNEL_LINK', 'WHATSAPP_NUMBER'] as $__n) {
    if (!defined($__n)) {
        define($__n, '');
    }
}
require_once __DIR__ . '/telegram.php';

/** Appends one line to logs/social.log. The bot token is always removed from the text. */
function social_log(string $msg): void
{
    if (TELEGRAM_BOT_TOKEN !== '') {
        $msg = str_replace(TELEGRAM_BOT_TOKEN, '[token]', $msg);
    }
    @file_put_contents(dirname(__DIR__) . '/logs/social.log', '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $msg) . "\n", FILE_APPEND | LOCK_EX);
}

/** Only plain https links are ever printed as links (never javascript: etc). */
function social_https(string $u): string
{
    return preg_match('#^https://[^\s"\'<>]+$#i', $u) ? $u : '';
}

/** "@name" -> https://t.me/name. Numeric ids have no public link, so '' is returned. */
function telegram_channel_url(string $chat): string
{
    return preg_match('/^@([A-Za-z0-9_]{5,32})$/', $chat, $m) ? 'https://t.me/' . $m[1] : '';
}

function whatsapp_follow_url(): string
{
    $l = social_https(WHATSAPP_CHANNEL_LINK);
    if ($l !== '') {
        return $l;
    }
    $n = preg_replace('/\D+/', '', WHATSAPP_NUMBER);
    return $n !== '' ? 'https://wa.me/' . $n : '';
}

/**
 * Plain links to share a page on WhatsApp, Telegram and X. No scripts, no trackers.
 * $title and $url are escaped here.
 */
function share_buttons(string $title, string $url): string
{
    $t = rawurlencode($title);
    $u = rawurlencode($url);
    $links = [
        'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($title . ' ' . $url),
        'Telegram' => 'https://t.me/share/url?url=' . $u . '&text=' . $t,
        'X'        => 'https://twitter.com/intent/tweet?text=' . $t . '&url=' . $u,
    ];
    $h = '<div class="share" role="group" aria-label="Share"><span class="share-l">Share:</span>';
    foreach ($links as $name => $href) {
        $h .= ' <a class="share-b share-' . strtolower($name) . '" href="' . e($href) . '" target="_blank" rel="noopener noreferrer">' . e($name) . '</a>';
    }
    return $h . '</div>';
}
