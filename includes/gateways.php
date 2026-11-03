<?php
/** Paystack (naira) and Razorpay (rupees). Money is only ever confirmed by asking the gateway directly. */
require_once __DIR__ . '/subscription.php';

function gateway_http(string $method, string $url, array $headers, ?array $json = null, ?string $basic = null): array
{
    $ch = curl_init($url);
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
    }
    if ($basic !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basic);
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => is_string($raw) ? json_decode($raw, true) : null, 'raw' => (string)$raw, 'error' => $err];
}

/* ---------------- Paystack ---------------- */

function paystack_request(string $method, string $path, ?array $json = null): array
{
    return gateway_http($method, 'https://api.paystack.co' . $path, ['Accept: application/json', 'Authorization: Bearer ' . PAYSTACK_SECRET_KEY], $json);
}

/** Asks Paystack what happened to a payment and updates ours. Returns 'paid', 'pending', 'failed' or 'cancelled'. */
function paystack_confirm(array $p): string
{
    if ($p['status'] === 'paid') {
        return 'paid';
    }
    $r = paystack_request('GET', '/transaction/verify/' . rawurlencode($p['reference']));
    $d = $r['data']['data'] ?? null;
    if ($r['status'] !== 200 || !is_array($d)) {
        payments_log('paystack-verify-failed', ['payment' => $p['id'], 'http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
        return 'pending';
    }
    $st = (string)($d['status'] ?? '');
    if ($st === 'success') {
        $paidKobo = (int)($d['amount'] ?? 0);
        if ($paidKobo !== (int)round((float)$p['amount'] * 100) || strtoupper((string)($d['currency'] ?? '')) !== 'NGN' || (string)($d['reference'] ?? '') !== $p['reference']) {
            payments_log('PAYSTACK-MISMATCH-CHECK-MANUALLY', ['payment' => $p['id'], 'expected' => $p['amount'], 'got' => $paidKobo, 'cur' => $d['currency'] ?? '']);
            return 'pending';
        }
        complete_payment((int)$p['id'], ['raw' => json_encode(['paystack_id' => $d['id'] ?? '', 'channel' => $d['channel'] ?? '', 'amount_kobo' => $paidKobo])]);
        return 'paid';
    }
    if (in_array($st, ['failed', 'reversed'], true)) {
        fail_payment((int)$p['id'], 'failed', 'paystack: ' . $st);
        return 'failed';
    }
    if ($st === 'abandoned' && strtotime($p['created_at']) < time() - 1800) {
        fail_payment((int)$p['id'], 'cancelled', 'paystack: abandoned');
        return 'cancelled';
    }
    return 'pending';
}

/* ---------------- Razorpay ---------------- */

function razorpay_request(string $method, string $path, ?array $json = null): array
{
    return gateway_http($method, 'https://api.razorpay.com/v1' . $path, ['Accept: application/json'], $json, RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET);
}

/** Checks one Razorpay payment id against our payment record. Returns 'paid' or 'pending'. */
function razorpay_confirm(array $p, string $rzpPaymentId): string
{
    if ($p['status'] === 'paid') {
        return 'paid';
    }
    if (!preg_match('/^pay_[A-Za-z0-9]+$/', $rzpPaymentId)) {
        return 'pending';
    }
    $paise = (int)round((float)$p['amount'] * 100);
    $r = razorpay_request('GET', '/payments/' . $rzpPaymentId);
    $d = $r['data'] ?? null;
    if ($r['status'] !== 200 || !is_array($d) || ($d['order_id'] ?? '') !== $p['gateway_ref'] || (int)($d['amount'] ?? 0) !== $paise || strtoupper((string)($d['currency'] ?? '')) !== 'INR') {
        payments_log('razorpay-verify-failed', ['payment' => $p['id'], 'http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
        return 'pending';
    }
    if (($d['status'] ?? '') === 'authorized') {            // some accounts need the payment to be captured explicitly
        $c = razorpay_request('POST', '/payments/' . $rzpPaymentId . '/capture', ['amount' => $paise, 'currency' => 'INR']);
        if ($c['status'] === 200 && ($c['data']['status'] ?? '') === 'captured') {
            $d['status'] = 'captured';
        }
    }
    if (($d['status'] ?? '') === 'captured') {
        complete_payment((int)$p['id'], ['raw' => json_encode(['razorpay_payment' => $rzpPaymentId, 'method' => $d['method'] ?? '', 'amount_paise' => $paise])]);
        return 'paid';
    }
    return 'pending';
}
