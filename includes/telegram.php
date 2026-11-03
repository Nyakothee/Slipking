<?php
/** Telegram Bot API posting. Plain text only (no parse_mode), so no characters can break the message. */

/** Last error text from tg_send(), for the admin test button. */
function tg_last_error(?string $set = null): string
{
    static $err = '';
    if ($set !== null) {
        $err = $set;
    }
    return $err;
}

/** True when a bot token and at least one channel are set. */
function tg_ready(): bool
{
    return TELEGRAM_BOT_TOKEN !== '' && (TELEGRAM_FREE_CHANNEL !== '' || TELEGRAM_VIP_CHANNEL !== '') && function_exists('curl_init');
}

function tg_autopost_on(): bool
{
    return tg_ready() && setting('telegram_autopost', '0') === '1';
}

/** Sends plain text to a chat id such as "@name" or "-1001234567890". Returns true on success. Never throws. */
function tg_send(string $chatId, string $text): bool
{
    tg_last_error('');
    try {
        if (TELEGRAM_BOT_TOKEN === '' || !function_exists('curl_init')) {
            tg_last_error('Telegram is not configured.');
            return false;
        }
        if (!preg_match('/^(@[A-Za-z0-9_]{5,32}|-?\d{5,20})$/', $chatId)) {
            tg_last_error('Bad channel id.');
            social_log('tg_send: bad chat id "' . substr($chatId, 0, 40) . '"');
            return false;
        }
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3990) . '...';
        }
        $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw    = curl_exec($ch);
        $cerr   = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status === 200 && is_array($data) && !empty($data['ok'])) {
            return true;
        }
        $why = is_array($data) && isset($data['description']) ? (string)$data['description'] : ($cerr !== '' ? $cerr : 'HTTP ' . $status);
        tg_last_error($why);
        social_log('tg_send to ' . $chatId . ' failed: ' . $why);
        return false;
    } catch (Throwable $e) {
        tg_last_error('Unexpected error.');
        social_log('tg_send exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Posts a newly published tip.
 * Free tip -> free channel, full pick.
 * VIP tip  -> VIP channel gets the full pick; free channel gets a teaser with NO pick and NO odds.
 * A locked pick is never sent to a public channel. Returns true if at least one message was sent.
 */
function tip_to_telegram(int $tipId): bool
{
    try {
        if (!tg_ready()) {
            return false;
        }
        $t = fetch_tip($tipId);
        if (!$t || $t['status'] !== 'pending') {
            return false;
        }
        $match = $t['home_team'] . ' vs ' . $t['away_team'];
        $sent  = false;
        if ((int)$t['is_vip'] === 1) {
            if (TELEGRAM_VIP_CHANNEL !== '') {
                $sent = tg_send(TELEGRAM_VIP_CHANNEL, tg_full_text($t, $match, true)) || $sent;
            }
            if (TELEGRAM_FREE_CHANNEL !== '') {
                $teaser = 'New VIP tip posted: ' . $t['league'] . ', ' . $match . "\n" . 'Unlock: ' . url('vip.php');
                $sent = tg_send(TELEGRAM_FREE_CHANNEL, $teaser) || $sent;
            }
        } elseif (TELEGRAM_FREE_CHANNEL !== '') {
            $sent = tg_send(TELEGRAM_FREE_CHANNEL, tg_full_text($t, $match, false));
        }
        return $sent;
    } catch (Throwable $e) {
        social_log('tip_to_telegram exception: ' . $e->getMessage());
        return false;
    }
}

function tg_full_text(array $t, string $match, bool $vip): string
{
    return ($vip ? 'VIP TIP' : 'FREE TIP') . ' - ' . $t['league'] . "\n"
         . $match . "\n"
         . 'Kickoff: ' . kickoff_label($t['kickoff_at']) . "\n"
         . 'Market: ' . $t['market'] . "\n"
         . 'Pick: ' . $t['pick'] . ' @ ' . number_format((float)$t['odds'], 2) . "\n"
         . 'Confidence: ' . (int)$t['confidence'] . '/5' . "\n"
         . 'Details: ' . url('tip.php?id=' . (int)$t['id']) . "\n"
         . 'Bet responsibly. 18+';
}
