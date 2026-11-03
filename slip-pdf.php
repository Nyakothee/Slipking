<?php
require_once __DIR__ . '/includes/init.php';
require_login();
$u = current_user();
if (!slips_ready()) { require __DIR__ . '/404.php'; exit; }
$s = slip_get((int)($_GET['id'] ?? 0), (int)$u['id']);
if (!$s || !$s['items']) { require __DIR__ . '/404.php'; exit; }
$pdf = slip_pdf($s, SITE_NAME);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="slip-' . (int)$s['id'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
echo $pdf;
