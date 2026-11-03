<?php
require_once __DIR__ . '/includes/init.php';

$perPage = 9;
$page = max(1, (int)($_GET['page'] ?? 1));
$q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$cat  = mb_substr(trim((string)($_GET['cat'] ?? '')), 0, 40);
$hasCat = blog_has_category();
$cats = blog_categories();

$where = "WHERE status = 'published'"; $args = [];
if ($cat !== '' && $hasCat) { $where .= ' AND category = ?'; $args[] = $cat; }
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where .= ' AND (title LIKE ? OR excerpt LIKE ?)'; array_push($args, $like, $like); }
$st = db()->prepare("SELECT COUNT(*) FROM posts $where"); $st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$sel = 'title, slug, excerpt, image_url, created_at' . ($hasCat ? ', category' : '');
$st = db()->prepare("SELECT $sel FROM posts $where ORDER BY created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$posts = $st->fetchAll();

// The newest article is shown big on the first, unfiltered page.
$featured = null;
if ($page === 1 && $q === '' && $cat === '' && $posts) { $featured = array_shift($posts); }
$latest = db()->query("SELECT title, slug, created_at FROM posts WHERE status = 'published' ORDER BY created_at DESC LIMIT 5")->fetchAll();

$page_title = ($cat !== '' ? $cat . ' - ' : '') . 'Blog: betting guides and match previews - ' . SITE_NAME;
$page_desc  = 'Football predictions, match previews, bankroll advice and how odds work.';
$active     = 'blog';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/blog-modern.css')) . '?v=1">';

function bl_card(array $p, bool $hasCat): void
{
    $img = blog_cover($p['image_url']);
    ?>
    <a class="bl-card" href="<?= e(url('post.php?slug=' . urlencode($p['slug']))) ?>">
      <span class="bl-img"<?= $img !== '' ? ' style="background-image:url(\'' . e($img) . '\')"' : '' ?>><?php if ($hasCat && !empty($p['category'])): ?><span class="bl-tag"><?= e($p['category']) ?></span><?php endif; ?></span>
      <span class="bl-body">
        <strong><?= e($p['title']) ?></strong>
        <?php if (!empty($p['excerpt'])): ?><span class="bl-ex"><?= e($p['excerpt']) ?></span><?php endif; ?>
        <span class="bl-meta"><?= e(date('j M Y', strtotime($p['created_at']))) ?> &middot; <b>Read more</b></span>
      </span>
    </a>
    <?php
}
include __DIR__ . '/includes/header.php';
?>
<?php if ($wb = widgets_block('blog')): ?><div class="wrap" style="padding-top:12px"><?= $wb ?></div><?php endif; ?>
<section class="bl-hero"><div class="wrap">
  <h1>SlipKing Blog</h1>
  <p>Match previews, predictions, bankroll advice and guides, written to help you bet smarter.</p>
  <form class="bl-search" method="get" action="<?= e(url('blog.php')) ?>" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search articles" aria-label="Search articles">
    <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
    <button type="submit">Search</button>
  </form>
  <?php if ($cats): ?><div class="bl-cats">
    <a href="<?= e(url('blog.php')) ?>"<?= $cat === '' ? ' class="on"' : '' ?>>All</a>
    <?php foreach ($cats as $c): ?><a href="<?= e(url('blog.php?cat=' . urlencode($c['name']))) ?>"<?= $cat === $c['name'] ? ' class="on"' : '' ?>><?= e($c['name']) ?> <small><?= (int)$c['n'] ?></small></a><?php endforeach; ?>
  </div><?php endif; ?>
</div></section>

<section class="bl-main"><div class="wrap bl-layout">
  <div class="bl-col">
  <?php if ($featured): $fi = blog_cover($featured['image_url']); ?>
    <a class="bl-feature" href="<?= e(url('post.php?slug=' . urlencode($featured['slug']))) ?>"<?= $fi !== '' ? ' style="background-image:linear-gradient(180deg,rgba(7,42,30,.05),rgba(7,42,30,.88)),url(\'' . e($fi) . '\')"' : '' ?>>
      <span class="bl-tag"><?= $hasCat && !empty($featured['category']) ? e($featured['category']) : 'Featured' ?></span>
      <strong><?= e($featured['title']) ?></strong>
      <?php if (!empty($featured['excerpt'])): ?><span><?= e($featured['excerpt']) ?></span><?php endif; ?>
      <em><?= e(date('j M Y', strtotime($featured['created_at']))) ?> &middot; Read the article</em>
    </a>
  <?php endif; ?>
  <?php if ($posts || $featured): ?>
    <?php if ($posts): ?><div class="bl-grid"><?php foreach ($posts as $p) { bl_card($p, $hasCat); } ?></div><?php endif; ?>
    <?php if ($pages > 1): ?><p class="bl-page">Page <?= (int)$page ?> of <?= (int)$pages ?></p>
      <?= pager($page, $pages, 'blog.php') ?><?php endif; ?>
  <?php else: ?>
    <div class="empty"><?= $q !== '' || $cat !== '' ? 'No articles match your search. <a href="' . e(url('blog.php')) . '">Show all articles</a>.' : 'No articles yet. The first ones are on the way.' ?></div>
  <?php endif; ?>
  </div>
  <aside class="bl-side">
    <div class="bl-box"><h3>Latest articles</h3>
      <?php foreach ($latest as $l): ?><a href="<?= e(url('post.php?slug=' . urlencode($l['slug']))) ?>"><span><?= e($l['title']) ?></span><small><?= e(date('j M Y', strtotime($l['created_at']))) ?></small></a><?php endforeach; ?>
    </div>
    <?php if ($cats): ?><div class="bl-box"><h3>Popular categories</h3>
      <?php foreach ($cats as $c): ?><a href="<?= e(url('blog.php?cat=' . urlencode($c['name']))) ?>"><span><?= e($c['name']) ?></span><small><?= (int)$c['n'] ?></small></a><?php endforeach; ?>
    </div><?php endif; ?>
    <div class="bl-box cta"><h3>Free tips every day</h3><p>Every pick is timestamped before kickoff and stays on the public record.</p><a class="btn gold" href="<?= e(url('tips.php')) ?>">See today's tips</a></div>
  </aside>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
