<?php
/**
 * Mobile numbers: country list, international formatting, and SMS confirmation codes.
 *
 * A member's number is stored in users.mobile as digits only in international form (for example 254712345678),
 * with users.country = 2-letter code and users.mobile_verified = 1 once the SMS code was typed correctly.
 * users.phone stays the Kenyan M-Pesa number used to pre-fill M-Pesa payments.
 */
require_once __DIR__ . '/config-sms.php';
require_once __DIR__ . '/countries.php';

/** @return array<string,array{name:string,dial:string}> keyed by ISO code, sorted by name */
function countries(): array
{
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (explode("\n", countries_raw()) as $line) {
            $p = explode('|', trim($line));
            if (count($p) === 3) {
                $list[$p[0]] = ['name' => $p[1], 'dial' => $p[2]];
            }
        }
        uasort($list, function ($a, $b) { return strcmp($a['name'], $b['name']); });
    }
    return $list;
}

function country_default(): string
{
    $c = strtoupper((string)DEFAULT_COUNTRY);
    return isset(countries()[$c]) ? $c : 'KE';
}

/** Dial code shown with a "+" and a space after the NANP area code, e.g. +1 242. */
function dial_label(string $dial): string
{
    return '+' . (strlen($dial) === 4 && $dial[0] === '1' ? '1 ' . substr($dial, 1) : $dial);
}

/** <option> list of every country, with dial code. */
function country_options(string $selected): string
{
    $h = '';
    foreach (countries() as $iso => $c) {
        $h .= '<option value="' . e($iso) . '"' . ($iso === $selected ? ' selected' : '') . '>' . e($c['name'] . ' (' . dial_label($c['dial']) . ')') . '</option>';
    }
    return $h;
}

/** Where flag pictures come from: your own assets/flags/xx.svg files if you added them, otherwise the free flagcdn.com service. */
function flag_base(): string
{
    if (glob(dirname(__DIR__) . '/assets/flags/*.svg')) {
        return url('assets/flags/') . '|svg';
    }
    return 'https://flagcdn.com/w40/|png';
}

/**
 * A country chooser with flags. Works as a normal <select> without JavaScript; assets/js/country-picker.js turns it into a searchable list with flags.
 * $o: by = 'iso' (value is the 2-letter code, default) or 'name' (value is the country name); empty = label for "none" (adds an empty choice);
 *     required = bool; autocomplete = string.
 */
function country_picker(string $id, string $name, string $selected, array $o = []): string
{
    $by = ($o['by'] ?? 'iso') === 'name' ? 'name' : 'iso';
    $h = '<div class="cpick" data-flags="' . e(flag_base()) . '"><select id="' . e($id) . '" name="' . e($name) . '"' . (!empty($o['required']) ? ' required' : '')
        . (!empty($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '') . '>';
    if (isset($o['empty'])) {
        $h .= '<option value="">' . e($o['empty']) . '</option>';
    }
    foreach (countries() as $iso => $c) {
        $val = $by === 'name' ? $c['name'] : $iso;
        $sel = $by === 'name' ? (strcasecmp($selected, $c['name']) === 0) : ($iso === strtoupper($selected));
        $h .= '<option value="' . e($val) . '" data-iso="' . e(strtolower($iso)) . '" data-dial="' . e(dial_label($c['dial'])) . '"' . ($sel ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return $h . '</select></div>';
}

/**
 * Checks a number typed in a country's own format (0712 345 678, 712345678, +254 712 345 678 ...).
 * @return array{ok:bool,mobile:string,error:string}  mobile = digits with country code, no "+"
 */
function phone_parse(string $iso, string $input): array
{
    $iso = strtoupper($iso);
    $c = countries()[$iso] ?? null;
    if (!$c) {
        return ['ok' => false, 'mobile' => '', 'error' => 'Choose your country.'];
    }
    $input = trim($input);
    $hadPlus = ($input !== '' && ($input[0] === '+' || strpos($input, '00') === 0));
    $d = preg_replace('/\D/', '', $input);
    if ($hadPlus) {
        $d = preg_replace('/^00/', '', $d);
        if (strpos($d, $c['dial']) === 0) {
            $d = substr($d, strlen($c['dial']));
        }
    }
    // Small +1 territories (Bahamas +1 242...): accept the number typed with or without its area code.
    $nanp = strlen($c['dial']) === 4 && $c['dial'][0] === '1';
    if ($nanp) {
        $area = substr($c['dial'], 1);
        if (strlen($d) === 11 && $d[0] === '1') { $d = substr($d, 1); }
        if (strlen($d) === 10 && strpos($d, $area) === 0) { $d = substr($d, 3); }
    } elseif ($c['dial'] === '1' && strlen($d) === 11 && $d[0] === '1') {
        $d = substr($d, 1);
    }
    // Local numbers often start with a trunk "0" that is dropped internationally (Italy keeps it).
    if (!in_array($iso, ['IT', 'VA', 'SM'], true)) {
        $d = ltrim($d, '0');
    }
    $len = [
        'KE' => [9, 9], 'NG' => [10, 10], 'GH' => [9, 9], 'TZ' => [9, 9], 'UG' => [9, 9], 'RW' => [9, 9], 'ET' => [9, 9],
        'ZA' => [9, 9], 'US' => [10, 10], 'CA' => [10, 10], 'GB' => [10, 10], 'IN' => [10, 10],
    ][$iso] ?? ($nanp ? [7, 7] : [4, 12]);
    // Typed with the country code in front but without a "+" (254712345678)?
    if (!$hadPlus && strpos($d, $c['dial']) === 0 && strlen($d) > $len[1] && strlen($d) - strlen($c['dial']) >= $len[0] && strlen($d) - strlen($c['dial']) <= $len[1]) {
        $d = substr($d, strlen($c['dial']));
    }
    if ($iso === 'KE' && !preg_match('/^[17]\d{8}$/', $d)) {
        return ['ok' => false, 'mobile' => '', 'error' => 'Enter a valid Kenyan number, like 0712 345 678.'];
    }
    if ($d === '' || strlen($d) < $len[0] || strlen($d) > $len[1] || strlen($c['dial'] . $d) > 15) {
        return ['ok' => false, 'mobile' => '', 'error' => 'Enter a valid mobile number for ' . $c['name'] . ' (without the country code).'];
    }
    return ['ok' => true, 'mobile' => $c['dial'] . $d, 'error' => ''];
}

/** 254712345678 -> +254 *** ***678 (for showing on screen and in emails). */
function phone_mask(string $mobile): string
{
    $n = strlen($mobile);
    return $n < 7 ? '+' . $mobile : '+' . substr($mobile, 0, 3) . str_repeat('*', max(3, $n - 6)) . substr($mobile, -3);
}

function sms_enabled(): bool
{
    return in_array(SMS_DRIVER, ['africastalking', 'twilio', 'log'], true);
}

/** Is this member allowed to pay? Not if SMS confirmation is on and their (new) mobile number is unconfirmed. Older members with no number are not blocked. */
function phone_gate_ok(?array $user): bool
{
    if (!$user || !SMS_REQUIRE_FOR_PURCHASE || !sms_enabled() || (function_exists('is_staff') && is_staff())) {
        return true;
    }
    return empty($user['mobile']) || !empty($user['mobile_verified']);
}

function sms_log(string $line): void
{
    $f = dirname(__DIR__) . '/logs/sms.log';
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

/** Sends one SMS. $mobile = digits with country code. Returns true when the provider accepted it. Error text in $err. */
function sms_send(string $mobile, string $text, ?string &$err = null): bool
{
    $err = '';
    if (!preg_match('/^\d{8,15}$/', $mobile)) {
        $err = 'bad number';
        return false;
    }
    if (SMS_DRIVER === 'log') {
        sms_log('TEST to +' . $mobile . ': ' . $text);
        return true;
    }
    if (!function_exists('curl_init')) {
        $err = 'PHP cURL is not enabled on this server';
        sms_log('FAIL ' . phone_mask($mobile) . ': ' . $err);
        return false;
    }
    $ch = curl_init();
    if (SMS_DRIVER === 'africastalking') {
        if (AT_USERNAME === '' || AT_API_KEY === '') { $err = 'Africa\'s Talking keys are empty'; sms_log('FAIL: ' . $err); return false; }
        $host = AT_USERNAME === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
        $post = ['username' => AT_USERNAME, 'to' => '+' . $mobile, 'message' => $text];
        if (SMS_SENDER !== '') { $post['from'] = SMS_SENDER; }
        curl_setopt_array($ch, [CURLOPT_URL => $host . '/version1/messaging', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_HTTPHEADER => ['apiKey: ' . AT_API_KEY, 'Accept: application/json']]);
    } elseif (SMS_DRIVER === 'twilio') {
        if (TWILIO_SID === '' || TWILIO_TOKEN === '' || (TWILIO_FROM === '' && TWILIO_MSG_SERVICE === '')) { $err = 'Twilio settings are empty'; sms_log('FAIL: ' . $err); return false; }
        $post = ['To' => '+' . $mobile, 'Body' => $text];
        if (TWILIO_MSG_SERVICE !== '') { $post['MessagingServiceSid'] = TWILIO_MSG_SERVICE; } else { $post['From'] = TWILIO_FROM; }
        curl_setopt_array($ch, [CURLOPT_URL => 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(TWILIO_SID) . '/Messages.json', CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN]);
    } else {
        curl_close($ch);
        $err = 'SMS is switched off';
        return false;
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        $err = 'network error: ' . $cerr;
    } elseif (SMS_DRIVER === 'twilio') {
        if ($http !== 201) { $j = json_decode((string)$body, true); $err = 'Twilio HTTP ' . $http . ' ' . ($j['message'] ?? ''); }
    } else {
        $j = json_decode((string)$body, true);
        $r = $j['SMSMessageData']['Recipients'][0] ?? null;
        $ok = $r && (($r['status'] ?? '') === 'Success' || in_array((int)($r['statusCode'] ?? 0), [100, 101, 102], true));
        if (!$ok) { $err = 'Africa\'s Talking HTTP ' . $http . ' ' . ($r['status'] ?? ($j['SMSMessageData']['Message'] ?? '')); }
    }
    if ($err !== '') {
        sms_log('FAIL ' . phone_mask($mobile) . ': ' . $err);
        return false;
    }
    return true;
}

/**
 * Creates and sends a 6-digit code to the member's saved mobile number.
 * @return array{ok:bool,message:string,wait:int}
 */
function phone_code_send(int $userId, string $mobile): array
{
    if (!sms_enabled()) {
        return ['ok' => false, 'message' => 'SMS confirmation is not switched on for this site.', 'wait' => 0];
    }
    $pdo = db();
    $ip = client_ip();
    $st = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM phone_codes WHERE user_id = ?');
    $st->execute([$userId]);
    $since = $st->fetchColumn();
    if ($since !== null && $since !== false && (int)$since < 60) {
        return ['ok' => false, 'message' => 'Please wait a minute before asking for another code.', 'wait' => 60 - (int)$since];
    }
    $limits = [
        ['SELECT COUNT(*) FROM phone_codes WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', $userId, 5],
        ['SELECT COUNT(*) FROM phone_codes WHERE mobile = ? AND created_at > NOW() - INTERVAL 1 HOUR', $mobile, 3],
        ['SELECT COUNT(*) FROM phone_codes WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', $ip, 15],
    ];
    foreach ($limits as $l) {
        $st = $pdo->prepare($l[0]);
        $st->execute([$l[1]]);
        if ((int)$st->fetchColumn() >= $l[2]) {
            return ['ok' => false, 'message' => 'Too many codes requested. Please try again in an hour.', 'wait' => 0];
        }
    }
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE phone_codes SET used = 1 WHERE user_id = ? AND used = 0')->execute([$userId]);   // older codes stop working
    $pdo->prepare('INSERT INTO phone_codes (user_id, mobile, code_hash, expires_at, ip) VALUES (?,?,?, NOW() + INTERVAL 10 MINUTE, ?)')
        ->execute([$userId, $mobile, phone_code_hash($userId, $mobile, $code), $ip]);
    $err = '';
    if (!sms_send($mobile, $code . ' is your ' . SITE_NAME . ' confirmation code. It expires in 10 minutes. Do not share it.', $err)) {
        return ['ok' => false, 'message' => 'We could not send the SMS right now. Please try again shortly.', 'wait' => 0];
    }
    return ['ok' => true, 'message' => 'Code sent to ' . phone_mask($mobile) . '.', 'wait' => 60];
}

function phone_code_hash(int $userId, string $mobile, string $code): string
{
    return hash('sha256', $userId . '|' . $mobile . '|' . $code . '|' . (defined('DB_PASS') ? DB_PASS : '') . (defined('DB_NAME') ? DB_NAME : ''));
}

/** @return array{ok:bool,message:string} */
function phone_code_check(int $userId, string $mobile, string $code): array
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return ['ok' => false, 'message' => 'Enter the 6-digit code from the SMS.'];
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM phone_codes WHERE user_id = ? AND mobile = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $st->execute([$userId, $mobile]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'That code has expired. Ask for a new one.'];
    }
    if ((int)$row['attempts'] >= 5) {
        $pdo->prepare('UPDATE phone_codes SET used = 1 WHERE id = ?')->execute([$row['id']]);
        return ['ok' => false, 'message' => 'Too many wrong tries. Ask for a new code.'];
    }
    if (!hash_equals((string)$row['code_hash'], phone_code_hash($userId, $mobile, $code))) {
        $pdo->prepare('UPDATE phone_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
        return ['ok' => false, 'message' => 'That code is not correct.'];
    }
    // One confirmed number belongs to one account.
    $st = $pdo->prepare('SELECT 1 FROM users WHERE mobile = ? AND mobile_verified = 1 AND id <> ? LIMIT 1');
    $st->execute([$mobile, $userId]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'message' => 'This number is already confirmed on another account.'];
    }
    $pdo->prepare('UPDATE phone_codes SET used = 1 WHERE id = ?')->execute([$row['id']]);
    $pdo->prepare('UPDATE users SET mobile_verified = 1, mobile_verified_at = NOW() WHERE id = ? AND mobile = ?')->execute([$userId, $mobile]);
    return ['ok' => true, 'message' => 'Your mobile number is confirmed.'];
}
