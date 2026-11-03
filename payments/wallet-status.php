<?php
/** The checkout page asks this every few seconds while the customer approves the prompt on their phone. */
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/wallets.php';

$user = current_user();
if (!$user) json_out(['status' => 'error', 'message' => 'Please log in.'], 401);
$ref = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['ref'] ?? '')));
$st = db()->prepare("SELECT * FROM payments WHERE reference = ? AND user_id = ? AND method IN ('mtn','airtel') LIMIT 1");
$st->execute([$ref, $user['id']]);
$p = $st->fetch();
if (!$p) json_out(['status' => 'error', 'message' => 'Payment not found.'], 404);
session_write_close();

$status = $p['status'];
if ($status === 'pending') {
    $status = wallet_confirm($p);
}
$messages = [
    'paid'      => 'Payment received. Your VIP plan is now active.',
    'pending'   => 'Waiting for you to approve the prompt on your phone...',
    'cancelled' => 'The request was not approved in time.',
    'failed'    => 'The payment did not go through. Nothing was taken.',
];
json_out(['status' => $status, 'message' => $messages[$status] ?? '']);
