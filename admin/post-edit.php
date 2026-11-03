<?php
require_once dirname(__DIR__) . '/includes/init.php';
require_perm('content');
$pdo = db();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$post = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM posts WHERE id = ?');
    $st->execute([$id]);
    $post = $st->fetch();
    if (!$post) {
        set_flash('error', 'Article not found.');
        redirect('admin/posts.php');
    }
}
$f = [
    'title' => $post['title'] ?? '', 'excerpt' => $post['excerpt'] ?? '', 'image_url' => $post['image_url'] ?? '',
    'body' => $post['body'] ?? '', 'status' => $post['status'] ?? 'draft', 'category' => $post['category'] ?? 'News',
];
$errors = [];
$covErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $f['title']     = post_str('title');
        $f['excerpt']   = post_str('excerpt');
        $f['image_url'] = post_str('image_url');
        $f['body']      = trim((string)($_POST['body'] ?? ''));
        $f['status']    = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
        $f['category']  = mb_substr(post_str('category'), 0, 40) ?: 'News';

        if (mb_strlen($f['title']) < 3 || mb_strlen($f['title']) > 160) $errors[] = 'Title must be 3 to 160 characters.';
        if (mb_strlen($f['excerpt']) > 300) $errors[] = 'The short summary can be 300 characters at most.';
        $cov = blog_save_upload($_FILES['cover'] ?? null, $covErr, true);
        if ($cov) { $f['image_url'] = $cov['path']; }
        if ($f['image_url'] !== '' && !blog_media_ok($f['image_url']) && !blog_https($f['image_url'])) $errors[] = 'The cover image must be an uploaded picture or a full https:// link (or leave it empty).';
        if ($f['body'] === '' || mb_strlen($f['body']) > 60000) $errors[] = 'Write the article text.';

        if (!$errors) {
            if ($post) {
                $pdo->prepare('UPDATE posts SET title=?, excerpt=?, image_url=?, body=?, status=? WHERE id=?')
                    ->execute([$f['title'], $f['excerpt'] ?: null, $f['image_url'] ?: null, $f['body'], $f['status'], $id]);
                if (blog_has_category()) { $pdo->prepare('UPDATE posts SET category = ? WHERE id = ?')->execute([$f['category'], $id]); }
                admin_log('post.edit', 'post#' . $id, $f['title'] . ' (' . $f['status'] . ')');
            } else {
                $pdo->prepare('INSERT INTO posts (title, slug, excerpt, image_url, body, status) VALUES (?,?,?,?,?,?)')
                    ->execute([$f['title'], unique_slug('posts', slugify($f['title'])), $f['excerpt'] ?: null, $f['image_url'] ?: null, $f['body'], $f['status']]);
                $newId = (int)$pdo->lastInsertId();
                if (blog_has_category()) { $pdo->prepare('UPDATE posts SET category = ? WHERE id = ?')->execute([$f['category'], $newId]); }
                admin_log('post.add', 'post#' . $newId, $f['title'] . ' (' . $f['status'] . ')');
            }
            set_flash('success', $f['status'] === 'published' ? 'Article saved and published.' : 'Draft saved.');
            redirect('admin/posts.php');
        }
    }
}

$admin_title = $post ? 'Edit article' : 'New article';
$admin_active = 'posts';
include dirname(__DIR__) . '/includes/admin-header.php';
?>
<h1><?= $post ? 'Edit article' : 'New article' ?></h1>
<p class="sub">Write plain text and leave a blank line between paragraphs. Use the buttons above the article box to add pictures, videos, headings and links. The web address is made from the title the first time you save.</p>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" action="<?= e(url('admin/post-edit.php' . ($id ? '?id=' . $id : ''))) ?>">
  <?= csrf_field() ?>
  <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <div class="panel">
    <div class="field"><label for="title">Title</label><input type="text" id="title" name="title" value="<?= e($f['title']) ?>" maxlength="160" required></div>
    <div class="field"><label for="category">Category (shown as a tag and a filter)</label><input type="text" id="category" name="category" value="<?= e($f['category']) ?>" maxlength="40" list="catlist"><datalist id="catlist"><option>News</option><option>Predictions</option><option>Betting tips</option><option>Match previews</option><option>Transfer news</option><option>Bankroll</option><option>Guides</option></datalist></div>
    <div class="field"><label for="excerpt">Short summary (shown in the blog list)</label><input type="text" id="excerpt" name="excerpt" value="<?= e($f['excerpt']) ?>" maxlength="300"></div>
    <div class="field"><label for="cover">Cover picture (optional)</label>
      <?php if (blog_cover($f['image_url']) !== ''): ?><div style="margin-bottom:8px"><img src="<?= e(blog_cover($f['image_url'])) ?>" alt="" style="max-width:240px;border-radius:8px"></div><?php endif; ?>
      <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"><div class="hint">Upload a picture (JPG, PNG or WebP, up to 6 MB), or paste a link below. Use pictures you own or have the right to use.</div>
      <input type="text" id="image_url" name="image_url" value="<?= e($f['image_url']) ?>" maxlength="255" placeholder="https://... (or leave as is)" style="margin-top:6px"></div>
    <div class="field"><label for="body">Article</label>
      <div class="bl-bar" id="bl-bar" data-csrf="<?= e(csrf_token()) ?>" data-upload="<?= e(url('admin/blog-upload.php')) ?>" data-preview="<?= e(url('admin/blog-preview.php')) ?>">
        <button type="button" class="btn dark tiny" id="bl-up">Upload picture or video</button>
        <button type="button" class="btn outline tiny" id="bl-img">Picture link</button>
        <button type="button" class="btn outline tiny" id="bl-vid">YouTube / Vimeo / video link</button>
        <button type="button" class="btn outline tiny" id="bl-h">Heading</button>
        <button type="button" class="btn outline tiny" id="bl-b">Bold</button>
        <button type="button" class="btn outline tiny" id="bl-l">Link</button>
        <button type="button" class="btn outline tiny" id="bl-pv">Preview</button>
        <input type="file" id="bl-file" hidden accept="image/jpeg,image/png,image/webp,video/mp4,video/webm">
        <span id="bl-msg" role="status" aria-live="polite"></span>
      </div>
      <textarea id="body" name="body" style="min-height:320px" required><?= e($f['body']) ?></textarea>
      <div class="hint">Pictures: <code>[img:address|caption]</code>. Videos: <code>[video:address|caption]</code>. Put each on its own line. Large videos: upload them to YouTube or Vimeo and paste the link.</div>
      <div class="bl-preview article" id="bl-preview" hidden></div></div>
    <div class="field"><label for="status">Status</label><select id="status" name="status"><option value="draft"<?= $f['status'] === 'draft' ? ' selected' : '' ?>>Draft (not visible)</option><option value="published"<?= $f['status'] === 'published' ? ' selected' : '' ?>>Published</option></select></div>
  </div>
  <button class="btn gold" type="submit">Save</button>
  <a class="btn outline" href="<?= e(url('admin/posts.php')) ?>">Cancel</a>
</form>
<link rel="stylesheet" href="<?= e(url('assets/css/blog.css?v=1')) ?>">
<script src="<?= e(url('assets/js/blog-editor.js?v=1')) ?>"></script>
<?php include dirname(__DIR__) . '/includes/admin-footer.php'; ?>
