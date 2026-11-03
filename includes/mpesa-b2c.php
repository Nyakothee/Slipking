<?php
/**
 * M-Pesa B2C: pay tipsters straight from the admin payouts page (optional, off until configured).
 * Needs a B2C-enabled shortcode and API operator from Safaricom (Daraja "Go Live"), plus their public certificate file.
 * TEST IN THE SANDBOX FIRST (MPESA_ENV = sandbox). A request is marked "processing" before it is sent, so it can never be sent twice.
 */
require_once __DIR__ . '/config-data.php';
require_once __DIR__ . '/mpesa.php';

function b2c_ready(): bool
{
    return setting('b2c_enabled', '0') === '1' && B2C_SHORTCODE !== '' && B2C_INITIATOR !== '' && B2C_INITIATOR_PASSWORD !== ''
        && B2C_CERT_PATH !== '' && is_readable(B2C_CERT_PATH) && MPESA_CONSUMER_KEY !== '' && MPESA_CONSUMER_SECRET !== '';
}

function b2c_key(): string
{
    return substr(hash_hmac('sha256', 'b2c-callback', app_secret()), 0, 32);
}

function b2c_url(string $type): string
{
    return url('payments/b2c-result.php?k=' . b2c_key() . '&t=' . $type);
}

/** Initiator password encrypted with Safaricom's public certificate (RSA, PKCS#1 v1.5), base64. Null on failure. */
function b2c_security_credential(): ?string
{
    $cert = @file_get_contents(B2C_CERT_PATH);
    if ($cert === false) { return null; }
    $pub = @openssl_pkey_get_public($cert);
    if (!$pub) { return null; }
    $enc = '';
    if (!@openssl_public_encrypt(B2C_INITIATOR_PASSWORD, $enc, $pub, OPENSSL_PKCS1_PADDING)) { return null; }
    return base64_encode($enc);
}

/**
 * Sends one payout. Returns ['ok'=>bool,'message'=>string]. ok means Safaricom accepted the request (the final result arrives later on the result URL).
 */
function b2c_send(string $originatorId, string $phone254, int $amount, string $remarks): array
{
    $tok = mpesa_token();
    if (!$tok) { return ['ok' => false, 'message' => 'Could not get an M-Pesa access token.']; }
    $cred = b2c_security_credential();
    if (!$cred) { return ['ok' => false, 'message' => 'Could not read the Safaricom certificate (B2C_CERT_PATH).']; }
    $r = mpesa_request('POST', '/mpesa/b2c/v1/paymentrequest', [
        'OriginatorConversationID' => $originatorId,
        'InitiatorName'      => B2C_INITIATOR,
        'SecurityCredential' => $cred,
        'CommandID'          => 'BusinessPayment',
        'Amount'             => $amount,
        'PartyA'             => B2C_SHORTCODE,
        'PartyB'             => $phone254,
        'Remarks'            => substr($remarks, 0, 100),
        'QueueTimeOutURL'    => b2c_url('timeout'),
        'ResultURL'          => b2c_url('result'),
        'Occasion'           => 'Tipster payout',
    ], $tok);
    $d = $r['data'];
    if ($r['status'] === 200 && is_array($d) && (string)($d['ResponseCode'] ?? '') === '0') {
        return ['ok' => true, 'message' => (string)($d['ResponseDescription'] ?? 'Accepted')];
    }
    $msg = is_array($d) ? (string)($d['errorMessage'] ?? $d['ResponseDescription'] ?? $d['ResultDesc'] ?? '') : '';
    return ['ok' => false, 'message' => 'M-Pesa refused: HTTP ' . $r['status'] . ' ' . ($msg !== '' ? $msg : $r['error'])];
}
