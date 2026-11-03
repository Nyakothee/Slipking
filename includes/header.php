<?php
/**
 * Set these BEFORE including this file:
 *   $page_title, $page_desc (optional), $active = 'home'|'tips'|'results'|'vip'|'blog'
 */
$page_title = $page_title ?? SITE_NAME;
$page_desc  = $page_desc ?? 'Free and VIP sports predictions with a public track record.';
$active     = $active ?? '';
$hdr_stats  = site_stats();
$hdr_next   = upcoming_matches(4);
$logged     = is_logged_in();
$isTipAcct  = is_tipster_only();
$inTipArea  = (bool)preg_match('#/(tipster|auth)/[^/]+\.php$|/account/(profile|notifications)\.php$#', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$tipOnly    = $isTipAcct && $inTipArea;     // tipsters see their own menu in their area and the normal site menu everywhere else
if ($logged && !$isTipAcct) { streak_touch((int)current_user()['id']); }   // day streak (once per day per visit)
/** A top-level link with a small drop-down: $subs = [[key, file, label], ...]. The parent is highlighted when any of its pages is active. */
function nav_dd(string $key, string $file, string $label, string $active, array $subs): string
{
    $subs = array_values(array_filter($subs, fn($sb) => !page_file_off($sb[1])));
    if (!$subs && page_file_off($file)) { return ''; }
    $keys = array_merge([$key], array_column($subs, 0));
    $on = in_array($active, $keys, true);
    $h = '<div class="navdd' . ($on ? ' has-on' : '') . '"><a href="' . e(url($file)) . '"' . ($on ? ' class="on"' : '') . '>' . e($label) . '</a>'
        . '<button type="button" class="ddt" aria-label="More under ' . e($label) . '" aria-expanded="false"><svg width="10" height="10" viewBox="0 0 10 10" aria-hidden="true"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
        . '<div class="ddm">';
    foreach ($subs as $sb) { $h .= '<a href="' . e(url($sb[1])) . '"' . ($sb[0] === $active ? ' class="on" aria-current="page"' : '') . '>' . e($sb[2]) . '</a>'; }
    return $h . '</div></div>';
}
function nav_link(string $key, string $file, string $label, string $active): string
{
    if (page_file_off($file)) { return ''; }
    return '<a href="' . e(url($file)) . '"' . ($key === $active ? ' class="on" aria-current="page"' : '') . '>' . e($label) . '</a>';
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_code()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($page_title) ?></title>
<meta name="description" content="<?= e($page_desc) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
<?php if (lang_code() === 'ar'): ?><link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet"><?php endif; ?>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/group-a.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/group-b.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/group-e.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/group-f.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/pay.css?v=2')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/plans-modern.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/auth-modern.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/how-it-works.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/polish2.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/widgets.css?v=1')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/profile.css?v=2')) ?>">
<?php
// Every page gets link-preview tags (title, description, share picture) even if it did not add its own.
if (strpos((string)($head_extra ?? ''), 'og:title') === false) {
    require_once __DIR__ . '/seo.php';
    $head_extra = ($head_extra ?? '') . seo_meta(['title' => (string)($page_title ?? SITE_NAME), 'description' => (string)($page_desc ?? '')]);
}
?>
<?= $head_extra ?? '' ?>
<link rel="stylesheet" href="<?= e(url('assets/css/theme-dark.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/polish.css')) ?>?v=9">
<link rel="stylesheet" href="<?= e(url('assets/css/forms.css')) ?>?v=3">
<link rel="stylesheet" href="<?= e(url('assets/css/nav-extras.css')) ?>?v=6">
<link rel="stylesheet" href="<?= e(url('assets/css/messages.css')) ?>?v=1">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%230a1a14'/%3E%3Cpath fill='%2310b981' d='M5 11l5 4 6-8 6 8 5-4-2 12H7z'/%3E%3C/svg%3E">
<link rel="manifest" href="<?= e(url('manifest.php')) ?>">
<meta name="theme-color" content="#0d3b2c">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/icons/apple-touch-icon.png')) ?>">
<script>try{var t=localStorage.getItem('sk_theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
<?php if (function_exists('is_app') && is_app()): ?>
<script>try{localStorage.removeItem('sk_theme');}catch(e){}document.documentElement.setAttribute('data-theme','<?= app_theme() ?>');</script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=3">
<meta name="theme-color" content="<?= app_theme() === 'light' ? '#0d3b2c' : '#0b2f23' ?>">
<?php endif; ?>
<script src="<?= e(url('assets/js/theme.js')) ?>" defer></script>
<script src="<?= e(url('assets/js/country-picker.js')) ?>?v=1" defer></script>
</head>
<body<?= (function_exists('is_app') && is_app()) ? ' class="is-app"' : '' ?>>
<a class="skip" href="#main">Skip to content</a>
<?php if (function_exists('urgent_banner') && ($ub = urgent_banner()) !== ''): ?><div class="urgent-bn" role="alert"><?= e($ub) ?></div><?php endif; ?>
<header>
  <div class="wrap bar">
    <a class="brand" href="<?= e(url($tipOnly ? 'tipster/index.php' : '')) ?>"><?= brand_html() ?></a>
<?php if (!$tipOnly): ?>
<?php endif; ?>
    <?php if ($tipOnly): ?>
    <nav id="nav" aria-label="Tipster">
      <?= nav_link('tipster-index', 'tipster/index.php', 'Dashboard', $active) ?>
      <?= nav_link('tipster-tips', 'tipster/tips.php', 'My tips', $active) ?>
      <?= nav_link('tipster-plans', 'tipster/plans.php', 'My plans', $active) ?>
      <?= nav_link('tipster-earnings', 'tipster/earnings.php', 'Wallet', $active) ?>
      <?= nav_link('tipster-profile', 'tipster/profile.php', 'Profile', $active) ?>
      <a class="navauth" href="<?= e(url('')) ?>">Main site</a>
      <?= nav_me_html(url('tipster/profile.php'), 'Me') ?>
      <a class="navauth" href="<?= e(url('auth/logout.php')) ?>">Log out</a>
    </nav>
    <?php else: ?>
    <nav id="nav" aria-label="Main">
      <?= nav_link('home', 'index.php', 'Home', $active) ?>
      <?= nav_dd('tips', 'tips.php', "Today's tips", $active, [['tips', 'tips.php', "Today's tips"], ['game', 'game.php', 'Game'], ['jackpot', 'jackpot.php', 'Jackpot'], ['members', 'members.php', 'Top members']]) ?>
      <?= nav_link('accas', 'accumulators.php', 'Accumulators', $active) ?>
      <?= nav_dd('results', 'results.php', 'Results', $active, [['results', 'results.php', 'Results'], ['live', 'live.php', 'Live scores'], ['livestream', 'livestream.php', 'Livestream'], ['replays', 'replays.php', 'Replays'], ['tables', 'widgets.php', 'Tables and stats']]) ?>
      <?= nav_link('football', 'football.php', 'Stats', $active) ?>
      <?= nav_link('vip', 'vip.php', 'VIP', $active) ?>
      <?= nav_link('tipsters', 'tipsters.php', 'Tipsters', $active) ?>
      <?= nav_dd('discuss', 'discuss.php', 'Discussion', $active, [['discuss', 'discuss.php', 'Groups'], ['chat', 'globalchat.php', 'Global Chat']]) ?>
      <?= nav_link('blog', 'blog.php', 'Blog', $active) ?>
      <?= nav_link('channels', 'channels.php', 'Channels', $active) ?>
      <?= app_install_button() ?>
      <?php if ($logged): ?>
        <?= $isTipAcct ? nav_me_html(url('tipster/index.php'), 'Tipster area') : nav_me_html(url('account/dashboard.php'), 'My account') ?>
        <a class="navauth" href="<?= e(url('auth/logout.php')) ?>">Log out</a>
      <?php else: ?>
        <a class="navauth" href="<?= e(url('auth/login.php')) ?>">Log in</a>
        <a class="navauth" href="<?= e(url('auth/register.php')) ?>">Create account</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php $curs = currencies_active(); $curNow = current_currency_code(); if (count($curs) > 1): ?>
    <div class="prefs">
      <details>
        <summary aria-label="Currency"><?= e($curNow) ?></summary>
        <div class="pmenu">
          <?php foreach ($curs as $cur_row): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['cur' => $cur_row['code']]))) ?>"<?= $cur_row['code'] === $curNow ? ' class="on"' : '' ?>><?= e($cur_row['code']) ?> - <?= e($cur_row['name']) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>
    <div class="prefs">
      <details>
        <summary aria-label="Language"><?= e(strtoupper(lang_code())) ?></summary>
        <div class="pmenu">
          <?php foreach (SK_LANGS as $lc => $ln): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['lang' => $lc]))) ?>"<?= $lc === lang_code() ? ' class="on"' : '' ?> hreflang="<?= e($lc) ?>" lang="<?= e($lc) ?>"><?= e($ln) ?></a>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <div class="authbtns">
      <?php if ($tipOnly): ?>
        <a class="btn ghost" href="<?= e(url('auth/logout.php')) ?>">Log out</a>
      <?php elseif ($isTipAcct): ?>
        <?= nav_user_chip(true) ?>
      <?php elseif ($logged): ?>
        <?= nav_user_chip(false) ?>
        <?php if (!is_vip()): ?><a class="btn gold" href="<?= e(url('vip.php')) ?>">Go VIP</a><?php endif; ?>
      <?php else: ?>
        <a class="btn ghost" href="<?= e(url('auth/login.php')) ?>">Log in</a>
        <a class="btn gold" href="<?= e(url('vip.php')) ?>">Go VIP</a>
      <?php endif; ?>
    </div>
    <?= nav_bell_html() ?>
    <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Switch to dark mode">&#9790;</button>
    <button class="menu" type="button" aria-label="Menu" aria-controls="nav" aria-expanded="false">&#9776;</button>
  </div>
  <?php if (!$tipOnly): ?><div class="ticker"><div class="wrap">
    <?php $liveItems = live_stream_items(); if ($liveItems): ?>
    <div class="mq" aria-label="Live games"><div class="mq-track"><?php for ($mqi = 0; $mqi < 2; $mqi++): foreach ($liveItems as $li): ?><a class="mq-i" href="<?= e($li['watch']) ?>" data-play="<?= e($li['play']) ?>" data-title="<?= e($li['name']) ?>"><?= $li['live'] ? '<span class="live-pill"><i></i>LIVE</span>' : '<span class="soon-pill">' . e($li['when']) . '</span>' ?> <?= e($li['name']) ?> <u>Watch</u></a><?php endforeach; endfor; ?></div></div>
    <?php else: ?><span><?= (int)$hdr_stats['tips_today'] ?> tips today</span><?php endif; ?>
    <?php if ($hdr_stats['win_rate'] !== null): ?><span>Last 30 days: <?= (int)$hdr_stats['win_rate'] ?>% won</span><?php endif; ?>
    <?php foreach ($hdr_next as $m): ?>
      <span><?= e($m['home_team']) ?> vs <?= e($m['away_team']) ?>, <?= e(kickoff_label($m['kickoff_at'])) ?></span>
    <?php endforeach; ?>
  </div></div><?php endif; ?>
</header>
<?= widgets_block('ticker') ?>
<?php foreach (get_flash() as $f): ?>
  <div class="flash <?= e($f['type']) ?>" role="status"><div class="wrap"><?= e($f['msg']) ?></div></div>
<?php endforeach; ?>
<?php if (!empty($english_only) && lang_code() !== 'en'): ?>
<div class="flash info" role="status"><div class="wrap">This page is currently available in English only.</div></div>
<?php endif; ?>
<main id="main">
