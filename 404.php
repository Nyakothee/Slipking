<?php
require_once __DIR__ . '/includes/init.php';
http_response_code(404);
$page_title = 'Page not found - ' . SITE_NAME;
$active = '';
include __DIR__ . '/includes/header.php';
?>
<section><div class="wrap center">
  <h2 style="font-size:64px;font-weight:800">Page not found</h2>
  <p style="margin:12px 0 22px;color:var(--mute)">The page you asked for does not exist or has moved.</p>
  <a class="btn dark" href="<?= e(url()) ?>">Back to the home page</a>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
