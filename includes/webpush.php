<?php
/**
 * Browser push notifications without any library: Web Push (RFC 8030), message encryption aes128gcm (RFC 8291), VAPID (RFC 8292).
 * Needs PHP openssl + curl, and the site on HTTPS. Keys are created in Admin > Push notifications.
 */

function b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    $r = base64_decode($s, true);
    return $r === false ? '' : $r;
}

/** Wraps a raw 65-byte uncompressed P-256 point into a PEM public key (SPKI). */
function webpush_point_to_pem(string $point): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Wraps a raw 32-byte P-256 private scalar into a PEM private key (the public half is worked out by OpenSSL). */
function webpush_scalar_to_pem(string $d): string
{
    $der = hex2bin('30310201010420') . $d . hex2bin('a00a06082a8648ce3d030107');
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/** Raw 65-byte public point of a private key resource/PEM. */
function webpush_public_point($key): string
{
    $res = is_string($key) ? openssl_pkey_get_private($key) : $key;
    $d = $res ? openssl_pkey_get_details($res) : null;
    if (!$d || empty($d['ec']['x']) || empty($d['ec']['y'])) {
        return '';
    }
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function webpush_supported(): bool
{
    return function_exists('openssl_pkey_derive') && function_exists('openssl_pkey_new') && function_exists('curl_init') && function_exists('hash_hkdf');
}

/** Creates the site's VAPID key pair. Returns [privatePem, publicB64u] or null when OpenSSL cannot do it. */
function webpush_generate_keys(): ?array
{
    if (!webpush_supported()) {
        return null;
    }
    $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) {
        return null;
    }
    $pem = '';
    if (!@openssl_pkey_export($res, $pem)) {
        return null;
    }
    $pub = webpush_public_point($res);
    return strlen($pub) === 65 ? [$pem, b64u_enc($pub)] : null;
}

function webpush_ready(): bool
{
    return webpush_supported() && setting('vapid_private_pem', '') !== '' && setting('vapid_public', '') !== '';
}

function webpush_public_key(): string
{
    return (string)setting('vapid_public', '');
}

/**
 * Encrypts a payload for one subscription (RFC 8291). $uaPublic = 65 raw bytes (p256dh), $authSecret = 16 raw bytes.
 * $asPem and $salt are only passed by the self-test, normally random.
 * Returns the full request body (header + one encrypted record) or null on failure.
 */
function webpush_encrypt(string $payload, string $uaPublic, string $authSecret, ?string $asPem = null, ?string $salt = null): ?string
{
    if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) < 8 || strlen($payload) > 3800) {
        return null;
    }
    if ($asPem === null) {
        $k = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$k) {
            return null;
        }
        $asPem = '';
        openssl_pkey_export($k, $asPem);
    }
    $asKey = openssl_pkey_get_private($asPem);
    $asPub = $asKey ? webpush_public_point($asKey) : '';
    $uaKey = openssl_pkey_get_public(webpush_point_to_pem($uaPublic));
    if (!$asKey || strlen($asPub) !== 65 || !$uaKey) {
        return null;
    }
    $secret = openssl_pkey_derive($uaKey, $asKey, 32);
    if ($secret === false) {
        return null;
    }
    $salt = $salt ?? random_bytes(16);
    $prk  = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPub, $authSecret);
    $cek  = hash_hkdf('sha256', $prk, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $prk, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/** Converts an OpenSSL DER ECDSA signature to the raw 64-byte r||s form that JWT wants. */
function webpush_der_to_raw(string $der): string
{
    $o = 2;
    if (ord($der[1]) & 0x80) {
        $o += ord($der[1]) & 0x7f;
    }
    $rl = ord($der[$o + 1]);
    $r = substr($der, $o + 2, $rl);
    $o2 = $o + 2 + $rl;
    $sl = ord($der[$o2 + 1]);
    $s = substr($der, $o2 + 2, $sl);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $r . $s;
}

/** VAPID Authorization header value for an endpoint. */
function webpush_vapid_header(string $endpoint, string $privPem, string $pubB64u, string $subject): ?string
{
    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) {
        return null;
    }
    $aud = $p['scheme'] . '://' . $p['host'] . (!empty($p['port']) ? ':' . $p['port'] : '');
    $head = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    $sig = '';
    if (!openssl_sign($head . '.' . $claims, $sig, $privPem, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    return 'vapid t=' . $head . '.' . $claims . '.' . b64u_enc(webpush_der_to_raw($sig)) . ', k=' . $pubB64u;
}

/** Only real browser push services are accepted as endpoints (stops people aiming the server at other sites). */
function webpush_endpoint_ok(string $endpoint): bool
{
    $p = parse_url($endpoint);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['port']) && (int)$p['port'] !== 443) {
        return false;
    }
    $h = strtolower($p['host']);
    foreach (['googleapis.com', 'mozilla.com', 'windows.com', 'apple.com', 'push.microsoft.com'] as $suffix) {
        if ($h === $suffix || substr($h, -strlen($suffix) - 1) === '.' . $suffix) {
            return true;
        }
    }
    return false;
}

/**
 * Sends one push. Returns the HTTP status (201 = accepted, 404/410 = the browser unsubscribed), or 0 when it could not be sent.
 */
function webpush_send(array $sub, string $json, int $ttl = 43200): int
{
    if (!webpush_ready() || !webpush_endpoint_ok((string)$sub['endpoint'])) {
        return 0;
    }
    $body = webpush_encrypt($json, b64u_dec((string)$sub['p256dh']), b64u_dec((string)$sub['auth']));
    $auth = webpush_vapid_header((string)$sub['endpoint'], (string)setting('vapid_private_pem', ''), webpush_public_key(),
                                 'mailto:' . (filter_var(SITE_EMAIL, FILTER_VALIDATE_EMAIL) ? SITE_EMAIL : 'admin@localhost'));
    if ($body === null || $auth === null) {
        return 0;
    }
    $ch = curl_init((string)$sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
                               'TTL: ' . $ttl, 'Urgency: normal', 'Content-Length: ' . strlen($body)]]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}
