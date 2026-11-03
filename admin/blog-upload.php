<?php
/** Editor upload: one picture or video in, a ready-to-paste article code out (JSON). */
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'message' => 'Invalid request.'], 405);
if (!csrf_check()) json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.'], 403);
$r = blog_save_upload($_FILES['file'] ?? null, $err, ($_POST['only'] ?? '') === 'image');
if (!$r) json_out(['ok' => false, 'message' => $err], 400);
admin_log('post.upload', $r['path'], '');
json_out(['ok' => true, 'path' => $r['path'], 'kind' => $r['kind'], 'url' => url($r['path']),
          'code' => '[' . ($r['kind'] === 'video' ? 'video' : 'img') . ':' . $r['path'] . '|]']);
