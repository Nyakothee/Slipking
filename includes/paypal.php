<?php
/** PayPal Checkout (Orders v2) helpers. Needs the PHP cURL extension. Amounts are charged in US dollars. */

function paypal_ready(): bool
{
    return PAYPAL_CLIENT_ID !== '' && PAYPAL_SECRET !== '';
}

function paypal_base(): string
{
    return PAYPAL_ENV === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

/** $body: array/object (sent as JSON), string (sent as is), or null. */
function paypal_request(string $method, string $path, $body = null, ?string $bearer = null, ?string $basic = null, array $extra = [], bool $form = false): array
{
    $headers = array_merge(['Accept: application/json'], $extra);
    if ($bearer) $headers[] = 'Authorization: Bearer ' . $bearer;
    if ($basic)  $headers[] = 'Authorization: Basic ' . $basic;

    $ch = curl_init(paypal_base() . $path);
    if ($body !== null) {
        $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
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

function paypal_token(): ?string
{
    $file = dirname(__DIR__) . '/logs/paypal-token-' . PAYPAL_ENV . '.json';
    if (is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && !empty($c['token']) && ($c['exp'] ?? 0) > time() + 60) {
            return $c['token'];
        }
    }
    $r = paypal_request('POST', '/v1/oauth2/token', 'grant_type=client_credentials', null,
                        base64_encode(PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET), [], true);
    if (empty($r['data']['access_token'])) {
        payments_log('paypal-token-failed', ['http' => $r['status'], 'error' => $r['error'], 'body' => substr($r['raw'], 0, 300)]);
        return null;
    }
    @file_put_contents($file, json_encode(['token' => $r['data']['access_token'], 'exp' => time() + (int)($r['data']['expires_in'] ?? 3000)]), LOCK_EX);
    return $r['data']['access_token'];
}

/** Creates the PayPal order. $usd like "12.00". Our payment reference goes in invoice_id and custom_id. */
function paypal_create_order(string $reference, string $usd, string $desc): array
{
    $token = paypal_token();
    if (!$token) {
        return ['ok' => false, 'message' => 'Could not reach PayPal. Please try again in a moment.'];
    }
    $r = paypal_request('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'invoice_id'  => $reference,
            'custom_id'   => $reference,
            'description' => substr($desc, 0, 120),
            'amount'      => ['currency_code' => 'USD', 'value' => $usd],
        ]],
        'application_context' => [
            'brand_name'          => SITE_NAME,
            'shipping_preference' => 'NO_SHIPPING',
            'user_action'         => 'PAY_NOW',
        ],
    ], $token, null, ['PayPal-Request-Id: create-' . $reference]);

    $id = $r['data']['id'] ?? '';
    if (!in_array($r['status'], [200, 201], true) || $id === '') {
        payments_log('paypal-create-failed', ['http' => $r['status'], 'body' => substr($r['raw'], 0, 500)]);
        return ['ok' => false, 'message' => 'PayPal could not start the payment. Please try again.'];
    }
    return ['ok' => true, 'order_id' => $id];
}

function paypal_capture_order(string $orderId, string $reference): array
{
    $token = paypal_token();
    if (!$token) {
        return ['status' => 0, 'data' => null, 'raw' => '', 'error' => 'token'];
    }
    return paypal_request('POST', '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', new stdClass(), $token, null,
                          ['PayPal-Request-Id: capture-' . $reference]);
}

function paypal_get_order(string $orderId): ?array
{
    $token = paypal_token();
    if (!$token) {
        return null;
    }
    $r = paypal_request('GET', '/v2/checkout/orders/' . rawurlencode($orderId), null, $token);
    return ($r['status'] === 200 && is_array($r['data'])) ? $r['data'] : null;
}

/**
 * Checks a PayPal order against OUR payment record and, if everything matches, gives the VIP days.
 * Works from PayPal's own data (capture reply or a fresh lookup), never from what the browser says.
 * Returns the payment's status afterwards: 'paid' or 'pending'.
 */
function paypal_finalize(array $p, array $order): string
{
    $unit = $order['purchase_units'][0] ?? [];
    $cap  = $unit['payments']['captures'][0] ?? null;

    if (($order['status'] ?? '') !== 'COMPLETED' || !is_array($cap) || ($cap['status'] ?? '') !== 'COMPLETED') {
        return 'pending';
    }
    $cur = (string)($cap['amount']['currency_code'] ?? '');
    $val = (float)($cap['amount']['value'] ?? 0);
    if ($cur !== $p['currency'] || abs($val - (float)$p['amount']) > 0.001) {
        payments_log('PAYPAL-AMOUNT-MISMATCH-CHECK-MANUALLY', ['payment' => $p['id'], 'expected' => $p['amount'] . ' ' . $p['currency'], 'got' => $val . ' ' . $cur]);
        return 'pending';
    }
    $tag = $unit['custom_id'] ?? ($cap['custom_id'] ?? null);
    if ($tag !== null && $tag !== $p['reference']) {
        payments_log('PAYPAL-REFERENCE-MISMATCH-CHECK-MANUALLY', ['payment' => $p['id'], 'expected' => $p['reference'], 'got' => $tag]);
        return 'pending';
    }
    complete_payment((int)$p['id'], ['raw' => json_encode(['order' => $order['id'] ?? '', 'capture' => $cap['id'] ?? '', 'amount' => $val . ' ' . $cur])]);

    $st = db()->prepare('SELECT status FROM payments WHERE id = ?');
    $st->execute([$p['id']]);
    return $st->fetchColumn() === 'paid' ? 'paid' : 'pending';
}

/** Confirms that a webhook really came from PayPal. Needs PAYPAL_WEBHOOK_ID. */
function paypal_verify_webhook(string $rawBody): bool
{
    if (PAYPAL_WEBHOOK_ID === '') {
        return false;
    }
    $token = paypal_token();
    if (!$token) {
        return false;
    }
    $h = function ($k) { return json_encode((string)($_SERVER['HTTP_' . $k] ?? '')); };
    $payload = '{"auth_algo":' . $h('PAYPAL_AUTH_ALGO') . ',"cert_url":' . $h('PAYPAL_CERT_URL') .
               ',"transmission_id":' . $h('PAYPAL_TRANSMISSION_ID') . ',"transmission_sig":' . $h('PAYPAL_TRANSMISSION_SIG') .
               ',"transmission_time":' . $h('PAYPAL_TRANSMISSION_TIME') . ',"webhook_id":' . json_encode(PAYPAL_WEBHOOK_ID) .
               ',"webhook_event":' . $rawBody . '}';
    $r = paypal_request('POST', '/v1/notifications/verify-webhook-signature', $payload, $token);
    return ($r['data']['verification_status'] ?? '') === 'SUCCESS';
}
