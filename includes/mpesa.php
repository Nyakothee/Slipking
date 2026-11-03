<?php
/** M-Pesa Express (STK Push) helpers for Safaricom Daraja. Needs the PHP cURL extension. */

function mpesa_ready(): bool
{
    return MPESA_CONSUMER_KEY !== '' && MPESA_CONSUMER_SECRET !== '' && MPESA_SHORTCODE !== '' && MPESA_PASSKEY !== '';
}

function mpesa_base(): string
{
    return MPESA_ENV === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}

/** Daraja posts the payment result here. The secret in the address stops strangers faking a callback. */
function mpesa_callback_key(): string
{
    return substr(hash_hmac('sha256', 'mpesa-callback', app_secret()), 0, 32);
}

function mpesa_callback_url(): string
{
    return url('payments/mpesa-callback.php?k=' . mpesa_callback_key());
}

function mpesa_request(string $method, string $path, ?array $json = null, ?string $bearer = null, ?string $basic = null): array
{
    $headers = ['Accept: application/json'];
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    if ($basic)  $headers[] = 'Authorization: Basic ' . $basic;

    $ch = curl_init(mpesa_base() . $path);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw    = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'data' => is_string($raw) ? json_decode($raw, true) : null, 'raw' => (string)$raw, 'error' => $error];
}

/** Access token, cached in logs/ until it is about to expire (tokens last about an hour). */
function mpesa_token(): ?string
{
    $file = dirname(__DIR__) . '/logs/mpesa-token-' . MPESA_ENV . '.json';
    if (is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && !empty($c['token']) && ($c['exp'] ?? 0) > time() + 60) {
            return $c['token'];
        }
    }
    $r = mpesa_request('GET', '/oauth/v1/generate?grant_type=client_credentials', null, null,
                       base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET));
    if (empty($r['data']['access_token'])) {
        payments_log('mpesa-token-failed', ['http' => $r['status'], 'error' => $r['error'], 'body' => substr($r['raw'], 0, 300)]);
        return null;
    }
    $ttl = (int)($r['data']['expires_in'] ?? 3599);
    @file_put_contents($file, json_encode(['token' => $r['data']['access_token'], 'exp' => time() + $ttl]), LOCK_EX);
    return $r['data']['access_token'];
}

/** Sends the PIN prompt to the customer's phone. $phone must be 2547XXXXXXXX. */
function mpesa_stk_push(string $phone, int $amount, string $accountRef, string $desc): array
{
    if (MPESA_ENV === 'live' && strpos(SITE_URL, 'https://') !== 0) {
        return ['ok' => false, 'message' => 'The site must use https:// for live M-Pesa payments.', 'raw' => ''];
    }
    $token = mpesa_token();
    if (!$token) {
        return ['ok' => false, 'message' => 'Could not reach M-Pesa. Please try again in a moment.', 'raw' => ''];
    }
    $ts = date('YmdHis');
    $r  = mpesa_request('POST', '/mpesa/stkpush/v1/processrequest', [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $ts),
        'Timestamp'         => $ts,
        'TransactionType'   => MPESA_TXN_TYPE,
        'Amount'            => $amount,
        'PartyA'            => $phone,
        'PartyB'            => MPESA_PARTY_B !== '' ? MPESA_PARTY_B : MPESA_SHORTCODE,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => mpesa_callback_url(),
        'AccountReference'  => substr($accountRef, 0, 12),
        'TransactionDesc'   => substr($desc, 0, 13),
    ], $token);

    $d  = $r['data'] ?? [];
    $ok = (string)($d['ResponseCode'] ?? '') === '0' && !empty($d['CheckoutRequestID']);
    if (!$ok) {
        payments_log('mpesa-push-failed', ['http' => $r['status'], 'error' => $r['error'], 'body' => substr($r['raw'], 0, 500)]);
    }
    return [
        'ok'          => $ok,
        'checkout_id' => $d['CheckoutRequestID'] ?? null,
        'message'     => $ok ? 'Request sent' : 'M-Pesa could not send the prompt. Please check the phone number and try again.',
        'raw'         => substr($r['raw'], 0, 1000),
    ];
}

/** Asks Safaricom what happened to a request. state: paid | cancelled | failed | pending */
function mpesa_stk_query(string $checkoutId): array
{
    $token = mpesa_token();
    if (!$token) {
        return ['state' => 'pending', 'message' => 'token'];
    }
    $ts = date('YmdHis');
    $r  = mpesa_request('POST', '/mpesa/stkpushquery/v1/query', [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $ts),
        'Timestamp'         => $ts,
        'CheckoutRequestID' => $checkoutId,
    ], $token);

    $d = $r['data'] ?? [];
    if (isset($d['ResultCode'])) {            // an "errorCode" reply with no ResultCode means: still being processed
        $code = (string)$d['ResultCode'];
        $state = $code === '0' ? 'paid' : ($code === '1032' ? 'cancelled' : 'failed');
        return ['state' => $state, 'message' => (string)($d['ResultDesc'] ?? ''), 'raw' => substr($r['raw'], 0, 500)];
    }
    return ['state' => 'pending', 'message' => (string)($d['errorMessage'] ?? ''), 'raw' => ''];
}

/** Checks a pending M-Pesa payment with Safaricom and updates it. Returns the payment's status. */
function resolve_mpesa_payment(array $p): string
{
    if ($p['status'] !== 'pending' || empty($p['mpesa_checkout_id'])) {
        return $p['status'];
    }
    $q = mpesa_stk_query($p['mpesa_checkout_id']);
    if ($q['state'] === 'paid') {
        // The query does not return the receipt number; the customer has it in their M-Pesa SMS.
        complete_payment((int)$p['id'], ['raw' => 'confirmed by query: ' . ($q['raw'] ?? '')]);
        return 'paid';
    }
    if ($q['state'] === 'cancelled' || $q['state'] === 'failed') {
        fail_payment((int)$p['id'], $q['state'], $q['message']);
        return $q['state'];
    }
    return 'pending';
}
