<?php
/**
 * Email: every message goes out as multipart/alternative (plain text + a clean branded HTML version).
 * Driver is chosen in includes/config-mail.php: PHP mail() or a small built-in SMTP client (with fallback to mail()).
 * Failures (or DEBUG on) are written to logs/mail.log.
 */
$__cfg = __DIR__ . '/config-mail.php';
if (is_file($__cfg)) {
    require_once $__cfg;
}
foreach (['MAIL_DRIVER' => 'mail', 'MAIL_SMTP_HOST' => '', 'MAIL_SMTP_PORT' => 587, 'MAIL_SMTP_SECURE' => 'tls', 'MAIL_SMTP_USER' => '', 'MAIL_SMTP_PASS' => ''] as $__n => $__v) {
    if (!defined($__n)) {
        define($__n, $__v);
    }
}

/** Reason for the latest send_mail() problem ('' if none). Used by the admin mail test. */
function mail_last_error(?string $set = null): string
{
    static $err = '';
    if ($set !== null) {
        $err = $set;
    }
    return $err;
}

function mail_log_line(string $line): void
{
    // never write the SMTP password, whatever the error text says
    if (MAIL_SMTP_PASS !== '') {
        $line = str_replace(MAIL_SMTP_PASS, '[password]', $line);
    }
    @file_put_contents(dirname(__DIR__) . '/logs/mail.log', $line, FILE_APPEND | LOCK_EX);
}

/** Display name safe for a From header (quoted, encoded if it has non-ASCII characters). */
function mail_name_header(string $name): string
{
    $name = str_replace(["\r", "\n", '"', '\\'], ' ', $name);
    if (preg_match('/[^\x20-\x7E]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
    return '"' . $name . '"';
}

function mail_encode_subject(string $subject): string
{
    if (!preg_match('/[^\x20-\x7E]/', $subject)) {
        return $subject;
    }
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

/** Turns plain text into safe HTML: escaped, links made clickable, line breaks kept. */
function mail_linkify(string $text): string
{
    $h = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $h = preg_replace_callback('~https?://(?:(?!&quot;|&#039;|&lt;|&gt;)[^\s<])+~i', function ($m) {
        $u = $m[0];
        $tail = '';
        while ($u !== '' && strpos('.,;:!?)]', substr($u, -1)) !== false) {
            $tail = substr($u, -1) . $tail;
            $u = substr($u, 0, -1);
        }
        return '<a href="' . $u . '" style="color:#155240;font-weight:600">' . $u . '</a>' . $tail;
    }, $h);
    return nl2br($h, false);
}

/** The branded HTML version of a plain-text message. */
function mail_html(string $subject, string $body): string
{
    $name   = htmlspecialchars(SITE_NAME, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $title  = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $prefs  = htmlspecialchars(url('account/profile.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $site   = htmlspecialchars(url(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $inner  = mail_linkify(str_replace(["\r\n", "\r"], "\n", $body));
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f6f7f4;font-family:Arial,Helvetica,sans-serif;color:#17201c">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f7f4"><tr><td align="center" style="padding:20px 10px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border:1px solid #dfe3dc;border-radius:8px">'
        . '<tr><td style="background:#0d3b2c;color:#ffffff;padding:18px 24px;border-radius:8px 8px 0 0;border-bottom:4px solid #f0a91c">'
        . '<a href="' . $site . '" style="color:#ffffff;text-decoration:none;font-size:24px;font-weight:bold;letter-spacing:.5px">' . $name . '</a></td></tr>'
        . '<tr><td style="padding:24px;font-size:16px;line-height:1.55">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 24px;border-top:1px solid #dfe3dc;font-size:12px;line-height:1.5;color:#5f6b65">'
        . 'You are receiving this email because of your ' . $name . ' account. To stop reminder emails, change your email preferences in your profile: '
        . '<a href="' . $prefs . '" style="color:#155240">' . $prefs . '</a><br>Bet responsibly. 18+ only.'
        . '</td></tr></table></td></tr></table></body></html>';
}

/**
 * Builds the complete message. Returns ['subject' => encoded subject, 'headers' => [...lines], 'body' => string].
 * Pure function (no network, no database), so it can be tested on its own.
 */
function mail_build(string $to, string $subject, string $body, ?string $replyTo, string $from, string $boundary = '', ?string $htmlOverride = null, ?string $unsubUrl = null): array
{
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    if ($boundary === '') {
        $boundary = '=_sk_' . bin2hex(random_bytes(12));
    }
    $text = str_replace(["\r\n", "\r"], "\n", $body);
    $text = str_replace("\n", "\r\n", $text);
    $html = str_replace("\n", "\r\n", $htmlOverride ?? mail_html($subject, $body));
    $host = preg_replace('/^.*@/', '', $from);

    $headers = [
        'From: ' . mail_name_header(SITE_NAME) . ' <' . $from . '>',
        'Reply-To: ' . ($replyTo ?? $from),
        'List-Unsubscribe: <' . ($unsubUrl ?? url('account/alerts.php')) . '>',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $msg = '--' . $boundary . "\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($text), 76, "\r\n")
         . '--' . $boundary . "\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($html), 76, "\r\n")
         . '--' . $boundary . "--\r\n";
    return ['subject' => mail_encode_subject($subject), 'headers' => $headers, 'body' => $msg];
}

/* ---------------- SMTP client ---------------- */

/** Reads one (possibly multi-line) SMTP reply. Returns [code, text] or throws on timeout / closed connection. */
function smtp_read($fp, float $deadline): array
{
    $text = '';
    $code = 0;
    while (true) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('SMTP server too slow (timeout)');
        }
        $line = fgets($fp, 1024);
        if ($line === false) {
            $meta = stream_get_meta_data($fp);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP read timeout' : 'SMTP connection closed');
        }
        $text .= $line;
        if (strlen($line) >= 4 && ctype_digit(substr($line, 0, 3)) && $line[3] === ' ') {
            $code = (int)substr($line, 0, 3);
            break;
        }
        if (strlen($line) < 4 || !ctype_digit(substr($line, 0, 3))) {
            throw new RuntimeException('SMTP bad reply');
        }
    }
    return [$code, trim($text)];
}

/** Sends one command and checks the reply code is in $expect. */
function smtp_cmd($fp, string $cmd, array $expect, float $deadline, ?string $logCmd = null): string
{
    if (@fwrite($fp, $cmd . "\r\n") === false) {
        throw new RuntimeException('SMTP write failed');
    }
    [$code, $text] = smtp_read($fp, $deadline);
    if (!in_array($code, $expect, true)) {
        throw new RuntimeException('SMTP ' . ($logCmd ?? strtok($cmd, ' ')) . ' got ' . $code . ': ' . substr($text, 0, 200));
    }
    return $text;
}

/** SMTP dot-stuffing: any line starting with "." gets one more ".". */
function smtp_dot_stuff(string $data): string
{
    $data = str_replace(["\r\n", "\r"], "\n", $data);
    $data = str_replace("\n", "\r\n", $data);
    return preg_replace('/^\./m', '..', $data);
}

/**
 * Delivers a finished message through an SMTP server. Returns null on success or an error text.
 * $cfg: host, port, secure ('tls'|'ssl'|''), user, pass, timeout (seconds), verify (bool), helo.
 * Every wait is limited by a timeout and by an overall deadline, so it can never hang a page.
 */
function smtp_deliver(array $cfg, string $from, string $to, string $rawMessage): ?string
{
    $fp = null;
    try {
        $timeout  = (int)($cfg['timeout'] ?? 10);
        $deadline = microtime(true) + $timeout * 3;
        $secure   = (string)($cfg['secure'] ?? '');
        $verify   = !isset($cfg['verify']) || $cfg['verify'];
        if ($cfg['host'] === '') {
            return 'SMTP host is empty';
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $cfg['host']]]);
        $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . (int)$cfg['port'], $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')';
        }
        stream_set_timeout($fp, $timeout);
        stream_set_blocking($fp, true);
        $helo = (string)($cfg['helo'] ?? 'localhost');

        [$c, $t] = smtp_read($fp, $deadline);
        if ($c !== 220) {
            throw new RuntimeException('SMTP greeting ' . $c . ': ' . substr($t, 0, 200));
        }
        $caps = smtp_cmd($fp, 'EHLO ' . $helo, [250], $deadline);
        if ($secure === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220], $deadline);
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            }
            if (!@stream_socket_enable_crypto($fp, true, $method)) {
                throw new RuntimeException('SMTP STARTTLS handshake failed');
            }
            $caps = smtp_cmd($fp, 'EHLO ' . $helo, [250], $deadline);
        }
        if ((string)$cfg['user'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334], $deadline);
            smtp_cmd($fp, base64_encode((string)$cfg['user']), [334], $deadline, 'AUTH(user)');
            smtp_cmd($fp, base64_encode((string)$cfg['pass']), [235], $deadline, 'AUTH(password)');
        }
        smtp_cmd($fp, 'MAIL FROM:<' . $from . '>', [250], $deadline);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251], $deadline);
        smtp_cmd($fp, 'DATA', [354], $deadline);
        $data = smtp_dot_stuff($rawMessage);
        if (substr($data, -2) !== "\r\n") {
            $data .= "\r\n";
        }
        if (@fwrite($fp, $data . ".\r\n") === false) {
            throw new RuntimeException('SMTP write failed');
        }
        [$c, $t] = smtp_read($fp, $deadline);
        if ($c !== 250) {
            throw new RuntimeException('SMTP message rejected ' . $c . ': ' . substr($t, 0, 200));
        }
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return null;
    } catch (Throwable $e) {
        if (is_resource($fp)) {
            @fclose($fp);
        }
        return $e->getMessage();
    }
}

/* ---------------- public API ---------------- */

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null, ?string $html = null, ?string $unsubUrl = null): bool
{
    mail_last_error('');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_last_error('Invalid recipient address');
        return false;
    }
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $from    = filter_var(SITE_EMAIL, FILTER_VALIDATE_EMAIL) ? SITE_EMAIL : 'noreply@localhost';
    if ($replyTo !== null && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $replyTo = null;                    // never let a bad address into the mail headers
    }
    $m  = mail_build($to, $subject, $body, $replyTo, $from, '', $html, $unsubUrl);
    $ok = false;

    if (MAIL_DRIVER === 'smtp') {
        $host = (string)(parse_url(SITE_URL, PHP_URL_HOST) ?: 'localhost');
        $raw  = implode("\r\n", $m['headers']) . "\r\nTo: " . $to . "\r\nSubject: " . $m['subject'] . "\r\n\r\n" . $m['body'];
        $err  = smtp_deliver([
            'host' => MAIL_SMTP_HOST, 'port' => MAIL_SMTP_PORT, 'secure' => MAIL_SMTP_SECURE,
            'user' => MAIL_SMTP_USER, 'pass' => MAIL_SMTP_PASS, 'timeout' => 10, 'helo' => $host,
            'verify' => !defined('MAIL_SMTP_VERIFY_PEER') || MAIL_SMTP_VERIFY_PEER,
        ], $from, $to, $raw);
        if ($err === null) {
            $ok = true;
        } else {
            mail_last_error('SMTP failed, tried mail(): ' . $err);
            mail_log_line('[' . date('Y-m-d H:i:s') . '] SMTP-ERROR to ' . $to . ' | ' . $err . " (falling back to mail())\n");
        }
    }
    if (!$ok) {
        $ok = @mail($to, $m['subject'], $m['body'], implode("\r\n", $m['headers']));
        if ($ok && MAIL_DRIVER !== 'smtp') {
            mail_last_error('');
        } elseif (!$ok) {
            mail_last_error(mail_last_error() !== '' ? mail_last_error() . '; mail() also failed' : 'mail() failed');
        }
    }

    if (!$ok || DEBUG) {
        mail_log_line('[' . date('Y-m-d H:i:s') . '] ' . ($ok ? 'SENT' : 'FAILED') . " to $to | $subject\n$body\n---\n");
    }
    return $ok;
}

/** Sends a test email. Returns ['ok' => bool, 'driver' => 'mail'|'smtp', 'error' => text]. Used by admin/mail-test.php. */
function mail_test(string $to): array
{
    $ok = send_mail($to, 'Test email from ' . SITE_NAME,
        "This is a test email from " . SITE_NAME . ".\n\nIf you can read this, email sending works.\nSent at " . date('Y-m-d H:i:s') . ".\n\nSite: " . url());
    return ['ok' => $ok, 'driver' => MAIL_DRIVER === 'smtp' ? 'smtp' : 'mail', 'error' => mail_last_error()];
}

/** Last $n lines of logs/mail.log as text ('' if none). */
function mail_log_tail(int $n = 20): string
{
    $f = dirname(__DIR__) . '/logs/mail.log';
    if (!is_file($f)) {
        return '';
    }
    $size = (int)filesize($f);
    $fp = @fopen($f, 'rb');
    if (!$fp) {
        return '';
    }
    if ($size > 65536) {
        fseek($fp, -65536, SEEK_END);
    }
    $raw = (string)stream_get_contents($fp);
    fclose($fp);
    $lines = explode("\n", rtrim($raw));
    return implode("\n", array_slice($lines, -$n));
}

function send_verification_email(int $userId, string $email, string $name): bool
{
    return send_mail($email, 'Confirm your email for ' . SITE_NAME,
        "Hi $name,\n\nPlease confirm your email address by opening this link (valid for 3 days):\n\n" .
        verify_link($userId) . "\n\nIf you did not create an account, ignore this message.\n\n" . SITE_NAME);
}
