<?php
/** The Android app asks this at start-up: is there a newer version than mine? Public, no login. */
require_once dirname(__DIR__) . '/includes/init.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600');
$r = app_current();
if (!$r) { echo json_encode(['ok' => true, 'version' => '', 'url' => '']); exit; }
echo json_encode(['ok' => true, 'version' => (string)$r['version'], 'notes' => mb_substr((string)$r['notes'], 0, 300), 'url' => url('download-app.php')]);
