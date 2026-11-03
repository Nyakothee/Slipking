<?php
/**
 * Stripe (cards in USD/EUR/GBP), MTN MoMo and Airtel Money (mobile money).
 * Same rule as every other gateway here: money is only ever confirmed by asking the provider directly.
 */
require_once __DIR__ . '/gateways.php';

/* ---------------- readiness ---------------- */

function stripe_ready(): bool { return STRIPE_SECRET_KEY !== '' && setting('pay_stripe_on', '1') === '1'; }

function momo_ready(): bool
{
    return MTN_MOMO_SUBSCRIPTION_KEY !== '' && MTN_MOMO_API_USER !== '' && MTN_MOMO_API_KEY !== ''
        && setting('pay_mtn_on', '1') === '1' && wallet_currency_ok(momo_currency());
}

function airtel_ready(): bool
{
    return AIRTEL_CLIENT_ID !== '' && AIRTEL_CLIENT_SECRET !== '' && setting('pay_airtel_on', '1') === '1' && wallet_currency_ok(AIRTEL_CURRENCY);
}

/** The sandbox of MTN MoMo only accepts EUR. */
function momo_currency(): string { return MTN_MOMO_ENV === 'sandbox' ? 'EUR' : strtoupper(MTN_MOMO_CURRENCY); }

/** We can only price in a currency that exists in the currencies table (KES and USD always do). */
function wallet_currency_ok(string $code): bool { return currency_row(strtoupper($code)) !== null; }

/* ---------------- helpers ---------------- */

function wallet_http(string $method, string $url, array $headers, $body = null, ?string $basic = null): array
{
    $ch = curl_init($url);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
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

/** A plan price (KES, after any coupon) in another currency, rounded UP to $step (0.5 for euros, 100 for shillings). */
function local_price_step(int $kes, string $code, float $step, float $min): float
{
    $v = ceil(convert_kes_to((float)$kes, $code) / $step) * $step;
    return round(max($min, $v), 2);
}

/** Digits only, in international format without the plus sign. 0712... + prefix 254 -> 254712... */
function wallet_msisdn(string $input, string $prefix): string
{
    $d = preg_replace('/\D+/', '', $input);
    $d = preg_replace('/^00/', '', (string)$d);
    if ($d === '') {
        return '';
    }
    if (strpos($d, $prefix) === 0 && strlen($d) >= strlen($prefix) + 8) {
        return $d;
    }
    return $prefix . ltrim($d, '0');
}

function wallet_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/** The amount the customer is charged by one provider, in that provider's currency: [amount, currency]. */
function wallet_charge(string $provider, array $plan, int $kes, string $usd, string $stripeCur = 'USD'): array
{
    if ($provider === 'stripe') {
        $cur = in_array($stripeCur, ['USD', 'EUR', 'GBP'], true) ? $stripeCur : 'USD';
        if ($cur === 'USD' && (float)$plan['price_usd'] > 0) {
            return [round((float)$usd, 2), 'USD'];                       // the real PayPal/USD price when the plan has one
        }
        return [local_price_step($kes, $cur, 0.5, 1.0), $cur];
    }
    $cur = $provider === 'mtn' ? momo_currency() : strtoupper(AIRTEL_CURRENCY);
    if ($cur === 'KES') {
        return [(float)$kes, 'KES'];
    }
    $row = currency_row($cur);
    $zero = $row && (int)$row['decimals'] === 0;
    return [$zero ? (float)local_price($kes, $cur, 100, 100) : local_price_step($kes, $cur, 0.5, 1.0), $cur];
}

/* ---------------- Stripe ---------------- */

function stripe_request(string $method, string $path, array $params = []): array
{
    $body = $params ? http_build_query($params) : null;
    return wallet_http($method, 'https://api.stripe.com/v1' . $path, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], $body, STRIPE_SECRET_KEY . ':');
}

/** Asks Stripe what happened to a Checkout Session. Returns 'paid', 'pending', 'failed' or 'cancelled'. */
function stripe_confirm(array $p): string
{
    if ($p['status'] === 'paid') {
        return 'paid';
    }
    if (!preg_match('/^cs_[A-Za-z0-9_]+$/', (string)$p['gateway_ref'])) {
        return 'pending';
    }
    $r = stripe_request('GET', '/checkout/sessions/' . $p['gateway_ref']);
    $d = $r['data'] ?? null;
    if ($r['status'] !== 200 || !is_array($d)) {
        payments_log('stripe-verify-failed', ['payment' => $p['id'], 'http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
        return 'pending';
    }
    if (($d['payment_status'] ?? '') === 'paid') {
        $minor = (int)round((float)$p['amount'] * 100);
        if ((int)($d['amount_total'] ?? 0) !== $minor || strtoupper((string)($d['currency'] ?? '')) !== strtoupper((string)$p['currency'])
            || (string)($d['client_reference_id'] ?? '') !== $p['reference']) {
            payments_log('STRIPE-MISMATCH-CHECK-MANUALLY', ['payment' => $p['id'], 'expected' => $minor, 'got' => $d['amount_total'] ?? '', 'cur' => $d['currency'] ?? '']);
            return 'pending';
        }
        complete_payment((int)$p['id'], ['raw' => json_encode(['stripe_session' => $d['id'] ?? '', 'intent' => $d['payment_intent'] ?? '', 'amount_minor' => $minor])]);
        return 'paid';
    }
    if (($d['status'] ?? '') === 'expired') {
        fail_payment((int)$p['id'], 'cancelled', 'stripe: session expired');
        return 'cancelled';
    }
    return 'pending';
}

/** Checks the Stripe-Signature header (t=timestamp,v1=hmac). */
function stripe_signature_ok(string $raw, string $header): bool
{
    if (STRIPE_WEBHOOK_SECRET === '' || $header === '') {
        return false;
    }
    $t = '';
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) === 2) {
            if ($kv[0] === 't') { $t = $kv[1]; }
            if ($kv[0] === 'v1') { $sigs[] = $kv[1]; }
        }
    }
    if ($t === '' || abs(time() - (int)$t) > 600) {
        return false;
    }
    $want = hash_hmac('sha256', $t . '.' . $raw, STRIPE_WEBHOOK_SECRET);
    foreach ($sigs as $s) {
        if (hash_equals($want, $s)) {
            return true;
        }
    }
    return false;
}

/* ---------------- MTN MoMo ---------------- */

function momo_base(): string { return MTN_MOMO_ENV === 'sandbox' ? 'https://sandbox.momodeveloper.mtn.com' : 'https://proxy.momoapi.mtn.com'; }

function momo_token(): string
{
    static $tok = null;
    if ($tok !== null) {
        return $tok;
    }
    $r = wallet_http('POST', momo_base() . '/collection/token/', ['Ocp-Apim-Subscription-Key: ' . MTN_MOMO_SUBSCRIPTION_KEY, 'Content-Length: 0'], '', MTN_MOMO_API_USER . ':' . MTN_MOMO_API_KEY);
    $tok = (string)($r['data']['access_token'] ?? '');
    if ($tok === '') {
        payments_log('momo-token-failed', ['http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
    }
    return $tok;
}

function momo_headers(string $ref = ''): array
{
    $h = ['Authorization: Bearer ' . momo_token(), 'X-Target-Environment: ' . MTN_MOMO_ENV, 'Ocp-Apim-Subscription-Key: ' . MTN_MOMO_SUBSCRIPTION_KEY];
    if ($ref !== '') {
        $h[] = 'X-Reference-Id: ' . $ref;
    }
    return $h;
}

/** Sends the payment prompt to the customer's phone. Returns [ok, message]. $p['gateway_ref'] must hold the UUID. */
function momo_request_to_pay(array $p, string $msisdn): array
{
    if (momo_token() === '') {
        return [false, 'MTN MoMo could not be reached. Please try again in a moment.'];
    }
    $amount = (float)$p['amount'];
    $r = wallet_http('POST', momo_base() . '/collection/v1_0/requesttopay', array_merge(momo_headers($p['gateway_ref']), ['Content-Type: application/json']), [
        'amount' => (string)((float)$amount == floor($amount) ? (int)$amount : $amount), 'currency' => $p['currency'], 'externalId' => $p['reference'],
        'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn], 'payerMessage' => SITE_NAME . ' VIP', 'payeeNote' => $p['reference'],
    ]);
    if ($r['status'] !== 202) {
        payments_log('momo-request-failed', ['payment' => $p['id'], 'http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
        return [false, 'MTN MoMo did not accept the request. Check the number and try again.'];
    }
    return [true, 'A MoMo prompt has been sent to your phone. Approve it with your PIN.'];
}

function momo_confirm(array $p): string
{
    if ($p['status'] === 'paid') {
        return 'paid';
    }
    if (!preg_match('/^[0-9a-f\-]{36}$/', (string)$p['gateway_ref']) || momo_token() === '') {
        return 'pending';
    }
    $r = wallet_http('GET', momo_base() . '/collection/v1_0/requesttopay/' . $p['gateway_ref'], momo_headers());
    $d = $r['data'] ?? null;
    if ($r['status'] !== 200 || !is_array($d)) {
        return 'pending';
    }
    $st = strtoupper((string)($d['status'] ?? ''));
    if ($st === 'SUCCESSFUL') {
        if ((float)($d['amount'] ?? 0) != (float)$p['amount'] || strtoupper((string)($d['currency'] ?? '')) !== strtoupper((string)$p['currency'])
            || (string)($d['externalId'] ?? '') !== $p['reference']) {
            payments_log('MOMO-MISMATCH-CHECK-MANUALLY', ['payment' => $p['id'], 'expected' => $p['amount'], 'got' => $d['amount'] ?? '', 'cur' => $d['currency'] ?? '']);
            return 'pending';
        }
        complete_payment((int)$p['id'], ['raw' => json_encode(['momo_ref' => $p['gateway_ref'], 'financialTransactionId' => $d['financialTransactionId'] ?? ''])]);
        return 'paid';
    }
    if ($st === 'FAILED') {
        fail_payment((int)$p['id'], 'failed', 'momo: ' . substr((string)($d['reason'] ?? 'failed'), 0, 80));
        return 'failed';
    }
    return 'pending';
}

/* ---------------- Airtel Money ---------------- */

function airtel_base(): string { return AIRTEL_ENV === 'production' ? 'https://openapi.airtel.africa' : 'https://openapiuat.airtel.africa'; }

function airtel_token(): string
{
    static $tok = null;
    if ($tok !== null) {
        return $tok;
    }
    $r = wallet_http('POST', airtel_base() . '/auth/oauth2/token', ['Content-Type: application/json', 'Accept: */*'],
                     ['client_id' => AIRTEL_CLIENT_ID, 'client_secret' => AIRTEL_CLIENT_SECRET, 'grant_type' => 'client_credentials']);
    $tok = (string)($r['data']['access_token'] ?? '');
    if ($tok === '') {
        payments_log('airtel-token-failed', ['http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
    }
    return $tok;
}

function airtel_headers(): array
{
    return ['Authorization: Bearer ' . airtel_token(), 'Accept: */*', 'X-Country: ' . AIRTEL_COUNTRY, 'X-Currency: ' . strtoupper(AIRTEL_CURRENCY)];
}

/** Sends the USSD prompt. $national = number without the country code. Returns [ok, message]. */
function airtel_collect(array $p, string $national): array
{
    if (airtel_token() === '') {
        return [false, 'Airtel Money could not be reached. Please try again in a moment.'];
    }
    $amount = (float)$p['amount'];
    $r = wallet_http('POST', airtel_base() . '/merchant/v2/payments/', array_merge(airtel_headers(), ['Content-Type: application/json']), [
        'reference' => SITE_NAME . ' VIP',
        'subscriber' => ['country' => AIRTEL_COUNTRY, 'currency' => $p['currency'], 'msisdn' => $national],
        'transaction' => ['amount' => ($amount == floor($amount) ? (int)$amount : $amount), 'country' => AIRTEL_COUNTRY, 'currency' => $p['currency'], 'id' => $p['reference']],
    ]);
    if (empty($r['data']['status']['success'])) {
        payments_log('airtel-request-failed', ['payment' => $p['id'], 'http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
        return [false, 'Airtel Money did not accept the request. Check the number and try again.'];
    }
    return [true, 'An Airtel Money prompt has been sent to your phone. Approve it with your PIN.'];
}

function airtel_confirm(array $p): string
{
    if ($p['status'] === 'paid') {
        return 'paid';
    }
    if (airtel_token() === '') {
        return 'pending';
    }
    $r = wallet_http('GET', airtel_base() . '/standard/v1/payments/' . rawurlencode($p['reference']), airtel_headers());
    $tx = $r['data']['data']['transaction'] ?? null;
    if ($r['status'] !== 200 || !is_array($tx)) {
        return 'pending';
    }
    $st = strtoupper((string)($tx['status'] ?? ''));
    if ($st === 'TS') {
        complete_payment((int)$p['id'], ['raw' => json_encode(['airtel_id' => $tx['airtel_money_id'] ?? '', 'message' => $tx['message'] ?? ''])]);
        return 'paid';
    }
    if ($st === 'TF' || $st === 'TE') {
        fail_payment((int)$p['id'], 'failed', 'airtel: ' . $st . ' ' . substr((string)($tx['message'] ?? ''), 0, 80));
        return 'failed';
    }
    return 'pending';
}

/* ---------------- one entry point for the status page and admin ---------------- */

function wallet_confirm(array $p): string
{
    switch ($p['method']) {
        case 'stripe': $r = stripe_confirm($p); break;
        case 'mtn':    $r = momo_confirm($p); break;
        case 'airtel': $r = airtel_confirm($p); break;
        default:       return 'pending';
    }
    if ($r === 'pending' && $p['method'] !== 'stripe' && strtotime($p['created_at']) < time() - 1800) {
        fail_payment((int)$p['id'], 'cancelled', $p['method'] . ': no approval within 30 minutes');
        return 'cancelled';
    }
    return $r;
}

/**
 * Shared checks for the "start a payment" endpoints (POST + JSON reply). Ends the request with a JSON error when something is wrong.
 * Returns [user, plan, coupon|null, pct, kes, usd].
 */
function wallet_preflight(string $method, string $label): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'message' => 'Invalid request.'], 405);
    $user = current_user();
    if (!$user) json_out(['ok' => false, 'message' => 'Please log in first.'], 401);
    if (!csrf_check()) json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
    if (!phone_gate_ok($user)) json_out(['ok' => false, 'message' => 'Please confirm your mobile number with the SMS code first (Account > Profile).'], 403);

    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM plans WHERE slug = ? AND is_active = 1 LIMIT 1');
    $st->execute([preg_replace('/[^a-z0-9\-]/', '', strtolower(post_str('plan')))]);
    $plan = $st->fetch();
    if ($plan) { $plan = tipster_fee_plan_for($plan, $user); }
    if (!$plan) json_out(['ok' => false, 'message' => 'That plan is not available.'], 400);
    $lb = limits_payment_block((int)$user['id'], (int)$plan['price_kes']);
    if ($lb !== '') json_out(['ok' => false, 'message' => $lb], 403);

    $cres = find_coupon(post_str('coupon'), (int)$user['id']);
    if (!empty($plan['tipster_id'])) { $cres = ['ok' => true, 'coupon' => null, 'message' => '']; }
    if (!$cres['ok']) json_out(['ok' => false, 'message' => $cres['message']], 400);
    $coupon = $cres['coupon'];
    $pct = $coupon ? (int)$coupon['percent_off'] : 0;

    $st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE user_id = ? AND method = ? AND created_at > NOW() - INTERVAL 10 MINUTE');
    $st->execute([$user['id'], $method]);
    if ((int)$st->fetchColumn() >= 6) json_out(['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.'], 429);

    $kes = $pct ? discounted_kes((int)$plan['price_kes'], $pct) : (int)$plan['price_kes'];
    $usd = $pct ? discounted_usd((float)$plan['price_usd'], $pct) : number_format((float)$plan['price_usd'], 2, '.', '');
    return [$user, $plan, $coupon, $pct, $kes, $usd];
}

/* ---------------- Airtel Money disbursements (paying tipsters) ---------------- */

/** Can we send money OUT through Airtel? Needs the collection keys plus the disbursement PIN, and the admin switch. */
function airtel_payout_ready(): bool
{
    return AIRTEL_CLIENT_ID !== '' && AIRTEL_CLIENT_SECRET !== '' && AIRTEL_DISBURSE_PIN !== ''
        && setting('airtel_payouts_on', '0') === '1' && function_exists('openssl_public_encrypt');
}

/** Airtel's public key, used to encrypt the PIN. Returns a PEM string or ''. */
function airtel_public_key(): string
{
    $tok = airtel_token();
    if ($tok === '') {
        return '';
    }
    $r = wallet_http('GET', airtel_base() . '/v1/rsa/encryption-keys', ['Authorization: Bearer ' . $tok, 'Accept: */*']);
    $k = (string)($r['data']['data']['key'] ?? '');
    if ($k === '') {
        payments_log('airtel-key-failed', ['http' => $r['status'], 'body' => substr($r['raw'], 0, 200)]);
        return '';
    }
    if (strpos($k, 'BEGIN') !== false) {
        return $k;
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $k), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function airtel_encrypt_pin(): string
{
    $pem = airtel_public_key();
    if ($pem === '') {
        return '';
    }
    $out = '';
    if (!@openssl_public_encrypt(AIRTEL_DISBURSE_PIN, $out, $pem, OPENSSL_PKCS1_PADDING)) {
        return '';
    }
    return base64_encode($out);
}

/** Splits an Airtel number into the part without the country code. */
function airtel_national(string $input): string
{
    $full = wallet_msisdn($input, AIRTEL_MSISDN_PREFIX);
    return strpos($full, AIRTEL_MSISDN_PREFIX) === 0 ? substr($full, strlen(AIRTEL_MSISDN_PREFIX)) : $full;
}

/**
 * Sends money to a tipster's Airtel Money number. $ref is our own unique id for the payout.
 * @return array{ok:bool,message:string,pending:bool}  ok=false means nothing was sent
 */
function airtel_disburse(string $ref, string $number, float $amount): array
{
    $pin = airtel_encrypt_pin();
    if ($pin === '') {
        return ['ok' => false, 'pending' => false, 'message' => 'Airtel Money could not be reached.'];
    }
    $r = wallet_http('POST', airtel_base() . '/standard/v2/disbursements/', array_merge(airtel_headers(), ['Content-Type: application/json']), [
        'payee' => ['msisdn' => airtel_national($number), 'wallet_type' => 'NORMAL'],
        'reference' => substr(SITE_NAME . ' payout', 0, 40),
        'pin' => $pin,
        'transaction' => ['amount' => ($amount == floor($amount) ? (int)$amount : round($amount, 2)), 'id' => $ref, 'type' => 'B2C'],
    ]);
    $ok = !empty($r['data']['status']['success']);
    $code = strtoupper((string)($r['data']['data']['transaction']['status'] ?? ''));
    if (!$ok || $code === 'TF') {
        payments_log('airtel-disburse-failed', ['ref' => $ref, 'http' => $r['status'], 'body' => substr($r['raw'], 0, 300)]);
        return ['ok' => false, 'pending' => false, 'message' => 'Airtel Money refused the payment (HTTP ' . $r['status'] . ').'];
    }
    return ['ok' => true, 'pending' => $code !== 'TS', 'message' => ''];
}

/** @return string 'paid' | 'failed' | 'pending' */
function airtel_disburse_status(string $ref): string
{
    if (airtel_token() === '') {
        return 'pending';
    }
    $r = wallet_http('GET', airtel_base() . '/standard/v2/disbursements/' . rawurlencode($ref), airtel_headers());
    $tx = $r['data']['data']['transaction'] ?? null;
    if ($r['status'] !== 200 || !is_array($tx)) {
        return 'pending';
    }
    $st = strtoupper((string)($tx['status'] ?? ''));
    return $st === 'TS' ? 'paid' : ($st === 'TF' ? 'failed' : 'pending');
}
