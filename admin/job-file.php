<?php
/** Streams one private job-application document to an admin. Nobody else can open these files. */
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/jobs.php';
require_perm('jobs');
$cols = ['photo' => 'photo_file', 'idscan' => 'id_file', 'cv' => 'cv_file', 'certs' => 'cert_file', 'work' => 'work_file'];
$ref = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string)($_GET['ref'] ?? '')));
$f = (string)($_GET['f'] ?? '');
if (!isset($cols[$f])) { http_response_code(404); exit; }
$st = db()->prepare('SELECT * FROM job_applications WHERE ref = ? LIMIT 1');
$st->execute([$ref]);
$row = $st->fetch();
$path = $row && !empty($row[$cols[$f]]) ? job_file_path((string)$row[$cols[$f]]) : null;
if (!$path) { http_response_code(404); echo 'File not found.'; exit; }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$inline = in_array($ext, ['pdf', 'jpg', 'png', 'webp'], true);
admin_log('job.file_view', $ref, $f);
header('Content-Type: ' . $types[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'; sandbox');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ref . '-' . $f . '.' . $ext . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
