<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/seo.php';
$r = app_current();
$play = trim((string)setting('app_play_url', ''));
$page_title = 'Download the ' . SITE_NAME . ' Android app';
$page_desc = 'Get free and VIP predictions, alerts and the community on your Android phone.';
$head_extra = seo_meta(['title' => $page_title, 'description' => $page_desc, 'url' => url('download-app.php')]);
$active = '';
include __DIR__ . '/includes/header.php';
$direct = $r && $r['file_name'] !== '';
?>
<section><div class="wrap" style="max-width:760px">
  <div class="head"><h2>Get the <?= e(SITE_NAME) ?> Android app</h2></div>
  <?php if ($r): ?>
  <div class="box2" style="text-align:center;padding:26px 18px">
    <div style="font-size:44px">&#129302;</div>
    <h3 style="margin:6px 0">Version <?= e($r['version']) ?></h3>
    <p style="color:var(--mute);margin:0 0 16px"><?= $direct ? e(app_size_text((int)$r['file_size'])) . ' &middot; ' : '' ?>Updated <?= e(date('j M Y', strtotime($r['created_at']))) ?><?= $r['min_android'] !== '' ? ' &middot; Android ' . e($r['min_android']) : '' ?></p>
    <a class="btn gold" style="font-size:18px;padding:14px 28px" href="<?= e(url('app-download.php?src=page')) ?>"><?= $direct ? 'Download the app (APK)' : 'Get the app' ?></a>
    <?php if ($play !== ''): ?><p style="margin:14px 0 0"><a class="btn ghost" href="<?= e($play) ?>" target="_blank" rel="noopener">Get it on Google Play</a></p><?php endif; ?>
  </div>
  <?php if (trim((string)$r['notes']) !== ''): ?><div class="box2" style="margin-top:14px"><h3>What is new</h3><p style="white-space:pre-line;margin:0"><?= e($r['notes']) ?></p></div><?php endif; ?>
  <?php if ($direct): ?>
  <div class="box2" style="margin-top:14px"><h3>How to install</h3>
    <ol style="line-height:1.7;padding-left:20px;margin:0">
      <li>Tap <b>Download the app</b>. If Chrome warns that this type of file can be harmful, tap <b>Download anyway</b>. This is normal for apps that are not from Google Play.</li>
      <li>Open the downloaded file. If your phone asks, allow your browser to <b>install unknown apps</b> (Settings &rsaquo; Apps &rsaquo; your browser &rsaquo; Install unknown apps).</li>
      <li>Tap <b>Install</b>, then <b>Open</b>. Log in with your usual account.</li>
    </ol>
    <p style="color:var(--mute);font-size:13px;margin:12px 0 0">Only download the app from <?= e(parse_url(SITE_URL, PHP_URL_HOST) ?: SITE_NAME) ?>. Never install it from a link someone sends you in a message. You can check the file is untouched: its SHA-256 fingerprint is <code style="word-break:break-all"><?= e($r['sha256']) ?></code>.</p>
  </div>
  <?php endif; ?>
  <?php else: ?>
  <div class="box2" style="text-align:center;padding:26px 18px"><div style="font-size:44px">&#128241;</div><h3>The Android app is coming soon</h3>
    <p style="color:var(--mute)">While you wait, you can install the website as an app: open <?= e(SITE_NAME) ?> in Chrome, tap the menu (&#8942;) and choose <b>Install app</b> or <b>Add to Home screen</b>.</p>
    <a class="btn ghost" href="<?= e(url('')) ?>">Back to the home page</a></div>
  <?php endif; ?>
  <p style="color:var(--mute);font-size:13px;margin-top:14px">18+ only. Tips are opinions, not guarantees. Bet responsibly.</p>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
