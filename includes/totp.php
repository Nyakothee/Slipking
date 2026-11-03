<?php
/**
 * Time-based one-time passwords (RFC 6238, HMAC-SHA1, 6 digits, 30 second step) for admin two-factor login.
 * Works with Google Authenticator, Microsoft Authenticator, Aegis, 1Password, Authy and similar apps.
 * The pure functions (base32, totp_code, totp_verify) need no database.
 */

const TOTP_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode_str(string $bin): string
{
    $bits = '';
    $n = strlen($bin);
    for ($i = 0; $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;                              // no "=" padding: authenticator apps do not need it
}

/** Returns the raw bytes, or null if the text is not valid base32. Spaces, dashes, "=" and case are ignored. */
function base32_decode_str(string $b32): ?string
{
    $b32 = strtoupper(preg_replace('/[\s=\-]+/', '', $b32));
    if ($b32 === '' || preg_match('/[^A-Z2-7]/', $b32)) {
        return null;
    }
    $bits = '';
    $n = strlen($b32);
    for ($i = 0; $i < $n; $i++) {
        $bits .= str_pad(decbin(strpos(TOTP_B32, $b32[$i])), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** A new random secret: 20 bytes (160 bits) as base32 text (32 characters). */
function totp_new_secret(): string
{
    return base32_encode_str(random_bytes(20));
}

/** The code for a given secret (base32) and Unix time. */
function totp_code(string $secretB32, ?int $time = null, int $digits = 6, int $period = 30): string
{
    $key = base32_decode_str($secretB32);
    if ($key === null) {
        return str_repeat('0', $digits);
    }
    $counter = intdiv($time ?? time(), $period);
    $bin  = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);   // 8-byte big-endian, safe on 32-bit PHP
    $hash = hash_hmac('sha1', $bin, $key, true);
    $off  = ord($hash[19]) & 0x0F;
    $num  = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad((string)($num % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** True if $code is right for now, one step earlier or one step later (clock drift). Constant-time comparison. */
function totp_verify(string $secretB32, string $code, ?int $time = null, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $time = $time ?? time();
    $ok = false;
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretB32, $time + $i * 30), $code)) {
            $ok = true;                        // keep looping so timing does not reveal which step matched
        }
    }
    return $ok;
}

function totp_uri(string $secretB32, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretB32
         . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/** "ABCD EFGH ..." for easier typing. */
function totp_secret_grouped(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}

/* ---------------- database helpers ---------------- */

/** The user's stored secret if two-factor is on, otherwise null. Returns null (feature off) if the columns do not exist yet. */
function totp_secret_for(int $userId): ?string
{
    try {
        $st = db()->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $r = $st->fetch();
        return ($r && (int)$r['totp_enabled'] === 1 && !empty($r['totp_secret'])) ? (string)$r['totp_secret'] : null;
    } catch (Throwable $e) {
        error_log('totp_secret_for: ' . $e->getMessage());
        return null;
    }
}

function recovery_hash(string $code): string
{
    return hash_hmac('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)), app_secret());
}

/** Replaces all recovery codes with 8 new ones and returns them in plain text (shown once, stored hashed). */
function recovery_codes_generate(int $userId): array
{
    $pdo = db();
    $codes = [];
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('DELETE FROM admin_recovery_codes WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO admin_recovery_codes (user_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $c) {
            $ins->execute([$userId, recovery_hash($c)]);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $codes;
}

function recovery_codes_left(int $userId): int
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM admin_recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Uses up one recovery code. True only if it was valid and not used before (atomic). */
function recovery_code_use(int $userId, string $code): bool
{
    if (!preg_match('/^[A-Za-z0-9]{5}-?[A-Za-z0-9]{5}$/', trim($code))) {
        return false;
    }
    $st = db()->prepare('UPDATE admin_recovery_codes SET used_at = NOW() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1');
    $st->execute([$userId, recovery_hash($code)]);
    return $st->rowCount() === 1;
}

/** Accepts either an authenticator code or an unused recovery code. */
function second_factor_ok(int $userId, string $secret, string $input): bool
{
    $input = trim($input);
    if (preg_match('/^\d{3}\s?\d{3}$/', $input)) {
        return totp_verify($secret, $input);
    }
    return recovery_code_use($userId, $input);
}
