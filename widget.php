<?php
/** Shows a saved widget / embed code inside a sandbox: scripts may run, but pop-ups, redirects of this page and form posts to other sites cannot. */
require_once __DIR__ . '/includes/init.php';
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: frame-ancestors 'self'");
$id = (int)($_GET['id'] ?? 0);
$html = '';
if ($id > 0 && hash_equals(stream_sig('widget:' . $id), (string)($_GET['s'] ?? '')) && stream_widgets_ready()) {
    $st = db()->prepare('SELECT html FROM stream_widgets WHERE id = ?'); $st->execute([$id]); $html = (string)$st->fetchColumn();
}
if ($html === '') { http_response_code(404); echo '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#000;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh">This widget is not available.'; exit; }
$doc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;height:100%;background:#000;color:#fff}iframe,video{max-width:100%}</style></head><body>' . $html . '</body></html>';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><style>html,body{margin:0;height:100%;background:#000}iframe{position:absolute;inset:0;width:100%;height:100%;border:0}</style></head>
<body><iframe title="Widget" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="origin" sandbox="allow-scripts allow-presentation allow-forms" srcdoc="<?= e($doc) ?>"></iframe></body></html>
