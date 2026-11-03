<?php
require_once __DIR__ . '/includes/init.php';
header('Content-Type: application/xml; charset=utf-8');

$urls = [];
foreach (['' => 1.0, 'tips.php' => 0.9, 'accumulators.php' => 0.8, 'performance.php' => 0.6, 'results.php' => 0.8, 'vip.php' => 0.8, 'blog.php' => 0.7, 'calculator.php' => 0.6, 'how-it-works.php' => 0.6, 'about.php' => 0.3, 'contact.php' => 0.3,
          'terms.php' => 0.2, 'privacy.php' => 0.2, 'responsible-gambling.php' => 0.3] as $path => $prio) {
    $urls[] = [url($path), date('Y-m-d'), $prio];
}
// Tips from the last 30 days (free tips and settled VIP tips only, so nothing paid is exposed)
$rows = db()->query("SELECT t.id, m.kickoff_at FROM tips t JOIN matches m ON m.id = t.match_id
                     WHERE t.deleted_at IS NULL AND t.tipster_id IS NULL AND (t.is_vip = 0 OR t.status <> 'pending') AND m.kickoff_at > NOW() - INTERVAL 30 DAY
                     ORDER BY m.kickoff_at DESC LIMIT 500")->fetchAll();
foreach ($rows as $r) {
    $urls[] = [url('tip.php?id=' . (int)$r['id']), date('Y-m-d', strtotime($r['kickoff_at'])), 0.5];
}
require_once __DIR__ . '/includes/seo.php';
foreach (sitemap_extra_urls() as $x) {
    $urls[] = $x;
}
foreach (db()->query("SELECT slug, created_at FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 500")->fetchAll() as $r) {
    $urls[] = [url('post.php?slug=' . urlencode($r['slug'])), date('Y-m-d', strtotime($r['created_at'])), 0.6];
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url><loc><?= e($u[0]) ?></loc><lastmod><?= e($u[1]) ?></lastmod><priority><?= number_format($u[2], 1) ?></priority></url>
<?php endforeach; ?>
</urlset>
