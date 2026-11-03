<?php
/** Shows the article the way visitors will see it (the same renderer as the public page). */
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) json_out(['ok' => false, 'message' => 'Your session expired. Refresh the page.'], 403);
json_out(['ok' => true, 'html' => blog_render(mb_substr((string)($_POST['body'] ?? ''), 0, 60000))]);
