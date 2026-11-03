<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/wallets.php';

$ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['reference'] ?? ''));
$st = db()->prepare("SELECT * FROM payments WHERE reference = ? AND method = 'stripe' LIMIT 1");
$st->execute([$ref]);
$p = $st->fetch();
if (!$p) {
    redirect('account/payments.php');
}
$status = stripe_confirm($p);
redirect($status === 'failed' || $status === 'cancelled' ? 'payments/failed.php' : 'payments/success.php?ref=' . urlencode($ref));
