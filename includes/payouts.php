<?php
/**
 * Tipster wallet withdrawals, paid AUTOMATICALLY.
 *  - A tipster's earnings (after the holding period) are their wallet balance on the site.
 *  - They press "Withdraw": the money is sent at once to their M-Pesa (Safaricom B2C) or PayPal (PayPal Payouts). No one has to pay by hand.
 *  - Only ONE withdrawal is allowed every N days (Admin > Tipsters > "Days between withdrawals", default 14).
 *    Failed or rejected withdrawals do not count, so the tipster can try again.
 *  - The payout row is written and locked ("processing") before any money is sent, so a double click can never pay twice.
 */
require_once __DIR__ . '/mpesa-b2c.php';
require_once __DIR__ . '/paypal.php';
require_once __DIR__ . '/wallets.php';

function payout_window_days(): int
{
    return max(1, min(90, (int)setting('tipster_withdraw_days', '14')));
}

/** Unix time when this tipster may withdraw again, or 0 if they can withdraw now. */
function payout_next_allowed(int $tipsterId): int
{
    $st = db()->prepare("SELECT MAX(requested_at) FROM tipster_payouts WHERE tipster_id = ? AND status IN ('requested','processing','paid')");
    $st->execute([$tipsterId]);
    $last = $st->fetchColumn();
    if (!$last) {
        return 0;
    }
    $next = strtotime((string)$last) + payout_window_days() * 86400;
    return $next > time() ? $next : 0;
}

/** Can money for this method be sent automatically right now? */
function payout_auto_ready(string $method): bool
{
    if (setting('tipster_auto_payout', '1') !== '1') {
        return false;
    }
    if ($method === 'mpesa') {
        return b2c_ready();
    }
    if ($method === 'paypal') {
        return paypal_ready() && setting('paypal_payouts_on', '0') === '1';
    }
    if ($method === 'airtel') {
        return airtel_payout_ready();
    }
    return false;
}

/** If automatic sending is not possible, may the withdrawal wait in a queue for an admin to pay by hand? (off by default) */
function payout_manual_fallback(): bool
{
    return setting('tipster_manual_fallback', '0') === '1';
}

/** KES to US dollars for PayPal, rounded DOWN to the cent. */
function payout_kes_to_usd(float $kes): float
{
    return floor(convert_kes_to($kes, 'USD') * 100) / 100;
}

/** Why a method cannot be paid at the moment, in plain words ('' when it can). */
function payout_unavailable_reason(string $method): string
{
    if (payout_auto_ready($method)) {
        return '';
    }
    if (payout_manual_fallback()) {
        return '';
    }
    return 'Automatic withdrawals to ' . payout_method_label($method) . ' are not switched on yet. Please try again later.';
}

function payout_method_label(string $m): string
{
    return ['paypal' => 'PayPal', 'airtel' => 'Airtel Money', 'mpesa' => 'M-Pesa'][$m] ?? 'M-Pesa';
}

/** The <option> list for choosing how to be paid. */
function payout_method_options(string $selected): string
{
    $h = '';
    foreach (['mpesa', 'airtel', 'paypal'] as $m) {
        $h .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . e(payout_method_label($m)) . '</option>';
    }
    return $h;
}

/** Reads the chosen method from a form. */
function payout_method_post(string $key = 'method'): string
{
    $m = (string)($_POST[$key] ?? '');
    return in_array($m, ['paypal', 'airtel'], true) ? $m : 'mpesa';
}

/** KES to the Airtel country's currency, rounded DOWN (whole units for KES, TZS, UGX, RWF ...). */
function payout_kes_to_airtel(float $kes): float
{
    $cur = strtoupper(AIRTEL_CURRENCY);
    $v = $cur === 'KES' ? $kes : convert_kes_to($kes, $cur);
    return (float)floor($v);
}

/**
 * Withdraws the tipster's whole available balance. Returns ['ok' => bool, 'message' => string].
 */
function payout_withdraw(array $t): array
{
    $pdo = db();
    $min = max(0, (int)setting('tipster_min_payout_kes', '1000'));
    if ($t['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Your tipster profile is not active.'];
    }
    $method = (string)$t['payout_method'];
    $details = (string)$t['payout_details'];
    if (tipster_payout_valid($method, $details) === null) {
        return ['ok' => false, 'message' => 'Fix your payout details first (Profile).'];
    }
    $why = payout_unavailable_reason($method);
    if ($why !== '') {
        return ['ok' => false, 'message' => $why];
    }
    $auto = payout_auto_ready($method);

    try {
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM tipsters WHERE id = ? FOR UPDATE')->execute([$t['id']]);   // two quick clicks cannot both pass
        $next = payout_next_allowed((int)$t['id']);
        if ($next > 0) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'You can withdraw once every ' . payout_window_days() . ' days. Your next withdrawal opens on ' . date('j F Y, H:i', $next) . '.'];
        }
        $bal = tipster_balance((int)$t['id']);
        if ($bal['available'] < max($min, 1)) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'You can withdraw once your available balance is at least KES ' . number_format($min, 0) . '.'];
        }
        $amount = (float)$bal['available'];
        $orig = 'SKW' . (int)$t['id'] . 'X' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare("INSERT INTO tipster_payouts (tipster_id, amount_kes, method, details, status, b2c_conversation) VALUES (?,?,?,?,?,?)")
            ->execute([$t['id'], $amount, $method, $details, $auto ? 'processing' : 'requested', $auto ? $orig : null]);
        $payoutId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('withdraw: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not start the withdrawal. Please try again.'];
    }

    if (!$auto) {
        return ['ok' => true, 'message' => 'Your withdrawal of KES ' . number_format($amount, 0) . ' is queued and will be paid by our team.'];
    }

    if ($method === 'mpesa') {
        $r = b2c_send($orig, preg_replace('/\D/', '', $details), (int)floor($amount), 'Wallet withdrawal ' . SITE_NAME);
        if (!$r['ok']) {
            payout_fail($payoutId, $r['message']);
            return ['ok' => false, 'message' => 'The payment could not be sent, so nothing was taken from your balance. Please try again later. (' . $r['message'] . ')'];
        }
        return ['ok' => true, 'message' => 'Withdrawal of KES ' . number_format(floor($amount), 0) . ' sent to your M-Pesa. You will get an M-Pesa message in a moment.'];
    }

    if ($method === 'airtel') {
        $amt = payout_kes_to_airtel($amount);
        if ($amt < 1) {
            payout_fail($payoutId, 'Amount too small for Airtel Money.');
            return ['ok' => false, 'message' => 'The amount is too small to send by Airtel Money.'];
        }
        $r = airtel_disburse($orig, $details, $amt);
        if (!$r['ok']) {
            payout_fail($payoutId, $r['message']);
            return ['ok' => false, 'message' => 'The payment could not be sent, so nothing was taken from your balance. Please try again later. (' . $r['message'] . ')'];
        }
        $pdo->prepare("UPDATE tipster_payouts SET b2c_note = ? WHERE id = ? AND status = 'processing'")->execute([substr('Airtel ' . strtoupper(AIRTEL_CURRENCY) . ' ' . number_format($amt, 0), 0, 190), $payoutId]);
        if (!$r['pending']) {
            payout_settle_airtel(['id' => $payoutId, 'tipster_id' => $t['id'], 'status' => 'processing', 'method' => 'airtel', 'b2c_conversation' => $orig, 'amount_kes' => $amount]);
        }
        return ['ok' => true, 'message' => 'Withdrawal sent to your Airtel Money (' . strtoupper(AIRTEL_CURRENCY) . ' ' . number_format($amt, 0) . '). You will get an Airtel message in a moment.'];
    }

    // PayPal Payouts (US dollars)
    $usd = payout_kes_to_usd($amount);
    if ($usd < 1) {
        payout_fail($payoutId, 'Amount too small for PayPal.');
        return ['ok' => false, 'message' => 'The amount is too small to send by PayPal.'];
    }
    $tok = paypal_token();
    if (!$tok) {
        payout_fail($payoutId, 'No PayPal access token.');
        return ['ok' => false, 'message' => 'PayPal could not be reached. Nothing was taken from your balance. Please try again later.'];
    }
    $r = paypal_request('POST', '/v1/payments/payouts', [
        'sender_batch_header' => ['sender_batch_id' => $orig, 'email_subject' => 'Your ' . SITE_NAME . ' earnings', 'email_message' => 'Your tipster earnings from ' . SITE_NAME . '.'],
        'items' => [['recipient_type' => 'EMAIL', 'receiver' => $details, 'amount' => ['value' => number_format($usd, 2, '.', ''), 'currency' => 'USD'],
                     'note' => 'Wallet withdrawal ' . SITE_NAME, 'sender_item_id' => $orig]],
    ], $tok);
    $batch = (string)($r['data']['batch_header']['payout_batch_id'] ?? '');
    if (!in_array($r['status'], [200, 201], true) || $batch === '') {
        payments_log('paypal-payout-failed', ['payout' => $payoutId, 'http' => $r['status'], 'body' => substr($r['raw'], 0, 400)]);
        payout_fail($payoutId, 'PayPal refused: HTTP ' . $r['status']);
        return ['ok' => false, 'message' => 'PayPal did not accept the payment, so nothing was taken from your balance. Check your PayPal email in Profile, or try again later.'];
    }
    $pdo->prepare("UPDATE tipster_payouts SET b2c_conversation = ?, b2c_note = ? WHERE id = ? AND status = 'processing'")->execute([substr($batch, 0, 60), 'PayPal USD ' . number_format($usd, 2), $payoutId]);
    return ['ok' => true, 'message' => 'Withdrawal sent to your PayPal (about USD ' . number_format($usd, 2) . '). It usually arrives within minutes.'];
}

/** A payout that did not go through: the money returns to the balance and it does not count towards the waiting period. */
function payout_fail(int $payoutId, string $reason): void
{
    db()->prepare("UPDATE tipster_payouts SET status = 'rejected', note = ?, processed_at = NOW() WHERE id = ? AND status IN ('processing','requested')")
        ->execute([substr($reason, 0, 250), $payoutId]);
}

/** Asks PayPal how a payout batch ended and updates ours. Returns 'paid', 'rejected' or 'pending'. */
function payout_settle_paypal(array $po): string
{
    if ($po['status'] !== 'processing' || $po['method'] !== 'paypal' || (string)$po['b2c_conversation'] === '' || !paypal_ready()) {
        return 'pending';
    }
    $tok = paypal_token();
    if (!$tok) {
        return 'pending';
    }
    $batch = preg_replace('/[^A-Za-z0-9]/', '', (string)$po['b2c_conversation']);
    $r = paypal_request('GET', '/v1/payments/payouts/' . $batch . '?page_size=5', null, $tok);
    if ($r['status'] !== 200 || !is_array($r['data'])) {
        return 'pending';
    }
    $item = $r['data']['items'][0] ?? null;
    $ts = strtoupper((string)($item['transaction_status'] ?? ''));
    $bs = strtoupper((string)($r['data']['batch_header']['batch_status'] ?? ''));
    $tu = db()->prepare('SELECT ti.user_id FROM tipsters ti WHERE ti.id = ?');
    $tu->execute([$po['tipster_id']]);
    $uid = (int)$tu->fetchColumn();
    if ($ts === 'SUCCESS') {
        $ref = strtoupper(substr((string)($item['transaction_id'] ?? $item['payout_item_id'] ?? $batch), 0, 60));
        $u = db()->prepare("UPDATE tipster_payouts SET status = 'paid', reference = ?, processed_at = NOW(), b2c_note = NULL WHERE id = ? AND status = 'processing'");
        $u->execute([$ref, $po['id']]);
        if ($u->rowCount() && $uid) {
            notify_user($uid, 'Withdrawal sent', 'We paid KES ' . number_format((float)$po['amount_kes'], 0) . ' to your PayPal. Reference: ' . $ref);
        }
        return 'paid';
    }
    if (in_array($ts, ['FAILED', 'DENIED', 'BLOCKED', 'RETURNED', 'REFUNDED', 'REVERSED'], true) || in_array($bs, ['DENIED', 'CANCELED'], true)) {
        payout_fail((int)$po['id'], 'PayPal: ' . ($ts !== '' ? $ts : $bs));
        if ($uid) {
            notify_user($uid, 'Withdrawal failed', 'Your PayPal withdrawal did not go through (' . ($ts !== '' ? $ts : $bs) . '). The money is back in your balance and you can try again.');
        }
        return 'rejected';
    }
    return 'pending';
}

/** Asks Airtel how a withdrawal ended and updates ours. Returns 'paid', 'rejected' or 'pending'. */
function payout_settle_airtel(array $po): string
{
    if ($po['status'] !== 'processing' || $po['method'] !== 'airtel' || (string)$po['b2c_conversation'] === '' || !AIRTEL_CLIENT_ID) {
        return 'pending';
    }
    $res = airtel_disburse_status((string)$po['b2c_conversation']);
    if ($res === 'pending') {
        return 'pending';
    }
    $tu = db()->prepare('SELECT user_id FROM tipsters WHERE id = ?');
    $tu->execute([$po['tipster_id']]);
    $uid = (int)$tu->fetchColumn();
    if ($res === 'paid') {
        $u = db()->prepare("UPDATE tipster_payouts SET status = 'paid', reference = ?, processed_at = NOW(), b2c_note = NULL WHERE id = ? AND status = 'processing'");
        $u->execute([strtoupper(substr((string)$po['b2c_conversation'], 0, 60)), $po['id']]);
        if ($u->rowCount() && $uid) {
            notify_user($uid, 'Withdrawal sent', 'We paid KES ' . number_format((float)$po['amount_kes'], 0) . ' to your Airtel Money.');
        }
        return 'paid';
    }
    payout_fail((int)$po['id'], 'Airtel Money: failed');
    if ($uid) {
        notify_user($uid, 'Withdrawal failed', 'Your Airtel Money withdrawal did not go through. The money is back in your balance and you can try again.');
    }
    return 'rejected';
}

/** Settles any kind of waiting withdrawal that is checked by asking the provider. */
function payout_settle(array $po): string
{
    if ($po['method'] === 'paypal') { return payout_settle_paypal($po); }
    if ($po['method'] === 'airtel') { return payout_settle_airtel($po); }
    return 'pending';
}
