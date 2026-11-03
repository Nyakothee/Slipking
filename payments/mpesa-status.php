<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/subscription.php';
require_once dirname(__DIR__) . '/includes/mpesa.php';

$user = current_user();
if (!$user) {
    json_out(['status' => 'error', 'message' => 'Please log in.'], 401);
}
$ref = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['ref'] ?? '')));
$st  = db()->prepare("SELECT * FROM payments WHERE reference = ? AND user_id = ? AND method = 'mpesa' LIMIT 1");
$st->execute([$ref, $user['id']]);
$p = $st->fetch();
if (!$p) {
    json_out(['status' => 'error', 'message' => 'Payment not found.'], 404);
}
session_write_close();           // do not block other page loads while we wait on Safaricom

$status = $p['status'];
// If the callback has not arrived, ask Safaricom directly (at most once every 8 seconds per payment)
if ($status === 'pending' && !empty($p['mpesa_checkout_id']) && strtotime($p['created_at']) < time() - 15) {
    $key  = 'mq_' . $p['id'];
    $last = $_SESSION[$key] ?? 0;
    if ($last < time() - 8) {
        @session_start();
        $_SESSION[$key] = time();
        session_write_close();
        $status = resolve_mpesa_payment($p);
    }
}

$messages = [
    'paid'      => 'Payment received. Your VIP plan is now active.',
    'pending'   => 'Waiting for you to enter your PIN...',
    'cancelled' => 'You cancelled the request on your phone.',
    'failed'    => 'The payment did not go through. Nothing was taken, or it will be refunded by M-Pesa.',
];
json_out(['status' => $status, 'message' => $messages[$status] ?? '']);
