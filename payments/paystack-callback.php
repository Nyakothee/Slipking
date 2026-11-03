<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/gateways.php';

$ref = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['reference'] ?? $_GET['trxref'] ?? ''));
$st = db()->prepare("SELECT * FROM payments WHERE reference = ? AND method = 'paystack' LIMIT 1");
$st->execute([$ref]);
$p = $st->fetch();
if (!$p) {
    redirect('account/payments.php');
}
$status = paystack_confirm($p);
redirect($status === 'failed' || $status === 'cancelled' ? 'payments/failed.php' : 'payments/success.php?ref=' . urlencode($ref));
