<?php
/** Safaricom posts the B2C payout result (t=result) or a queue time-out (t=timeout) here. Protected by the secret key in the address and by matching the conversation id of a payout we marked "processing". */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/config-payments.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/offers.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa-b2c.php';

header('Content-Type: application/json');
$accepted = '{"ResultCode":0,"ResultDesc":"Accepted"}';
if (!hash_equals(b2c_key(), (string)($_GET['k'] ?? ''))) { http_response_code(403); exit('{"ResultCode":1,"ResultDesc":"Rejected"}'); }
$body = (string)file_get_contents('php://input');
payments_log('b2c-in', substr($body, 0, 3000));
$res = json_decode($body, true)['Result'] ?? null;
if (!is_array($res) || empty($res['OriginatorConversationID'])) { exit($accepted); }

$pdo = db();
$st = $pdo->prepare("SELECT po.*, ti.user_id AS tuser FROM tipster_payouts po JOIN tipsters ti ON ti.id = po.tipster_id WHERE po.b2c_conversation = ? AND po.status = 'processing' LIMIT 1");
$st->execute([substr((string)$res['OriginatorConversationID'], 0, 60)]);
$po = $st->fetch();
if (!$po) { exit($accepted); }

if (($_GET['t'] ?? '') === 'timeout') {
    $pdo->prepare("UPDATE tipster_payouts SET b2c_note = ? WHERE id = ? AND status = 'processing'")->execute(['Safaricom timed out. Check your M-Pesa portal before retrying.', $po['id']]);
    exit($accepted);
}
if ((string)($res['ResultCode'] ?? '') === '0') {
    $receipt = (string)($res['TransactionID'] ?? '');
    foreach ((array)($res['ResultParameters']['ResultParameter'] ?? []) as $it) {
        if (($it['Key'] ?? '') === 'TransactionReceipt' && !empty($it['Value'])) { $receipt = (string)$it['Value']; }
    }
    $u = $pdo->prepare("UPDATE tipster_payouts SET status = 'paid', reference = ?, processed_at = NOW(), b2c_note = NULL WHERE id = ? AND status = 'processing'");
    $u->execute([substr(strtoupper($receipt), 0, 60), $po['id']]);
    if ($u->rowCount()) {
        $pdo->prepare('INSERT INTO admin_log (admin_id, action, target, details, ip) VALUES (NULL, ?, ?, ?, ?)')->execute(['tipster.payout_b2c', 'payout#' . $po['id'], 'KES ' . $po['amount_kes'] . ' receipt ' . $receipt, 'safaricom']);
        notify_user((int)$po['tuser'], 'Payout sent', 'We paid KES ' . number_format((float)$po['amount_kes'], 0) . ' to your M-Pesa. Receipt: ' . $receipt);
    }
} else {
    $why = substr('M-Pesa failed: ' . (string)($res['ResultDesc'] ?? $res['ResultCode']), 0, 200);
    if (strpos((string)$po['b2c_conversation'], 'SKW') !== 0) { $pdo->prepare("UPDATE tipster_payouts SET status = 'requested', b2c_note = ? WHERE id = ? AND status = 'processing'")->execute([$why, $po['id']]); }
    else {
        $u = $pdo->prepare("UPDATE tipster_payouts SET status = 'rejected', note = ?, processed_at = NOW() WHERE id = ? AND status = 'processing'");
        $u->execute([$why, $po['id']]);
        if ($u->rowCount()) { notify_user((int)$po['tuser'], 'Withdrawal failed', 'Your M-Pesa withdrawal did not go through (' . (string)($res['ResultDesc'] ?? 'failed') . '). The money is back in your balance and you can try again.'); }
    }
}
exit($accepted);
