<?php
/**
 * Embeddable widgets: live scores, league tables, upcoming matches, team stats, score tickers, live trackers.
 * The admin pastes the embed code that a football widget provider gives them. No API key is used.
 *
 * Safety: a pasted <iframe src="..."> is shown as a plain iframe. Anything else (script snippets) is shown inside a
 * sandboxed iframe with no access to SlipKing's cookies or pages, so a widget can never read a member's session.
 */

function widgets_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            db()->query('SELECT 1 FROM widgets LIMIT 1'); $ok = true;
            try { db()->query('SELECT no_scroll, crop_top, crop_bottom FROM widgets LIMIT 1'); }
            catch (Throwable $e2) {
                foreach (['no_scroll TINYINT(1) NOT NULL DEFAULT 0', 'crop_top SMALLINT UNSIGNED NOT NULL DEFAULT 0', 'crop_bottom SMALLINT UNSIGNED NOT NULL DEFAULT 0'] as $col) {
                    try { db()->exec('ALTER TABLE widgets ADD COLUMN ' . $col); } catch (Throwable $e3) { }
                }
            }
        } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Where a widget can appear. */
function widget_slots(): array
{
    return [
        'live'       => 'Live page (live scores)',
        'ticker'     => 'Score ticker strip (top of every page)',
        'home'       => 'Home page',
        'results'    => 'Results page',
        'tips'       => 'Tips page',
        'match'      => 'Match page (under each match: stats, H2H, tracker)',
        'tip'        => 'Single tip page',
        'accas'      => 'Accumulators page',
        'vip'        => 'VIP plans page',
        'blog'       => 'Blog page',
        'footer'     => 'Above the footer (every page)',
        'livestream' => 'Livestream page',
        'page'       => 'Its own page (league tables, stats...)',
    ];
}

function widgets_for(string $slot, string $pageSlug = ''): array
{
    if (!widgets_ready()) { return []; }
    try {
        if ($slot === 'page') {
            $st = db()->prepare("SELECT * FROM widgets WHERE slot = 'page' AND page_slug = ? AND is_active = 1 ORDER BY sort_order, id");
            $st->execute([$pageSlug]);
        } else {
            $st = db()->prepare('SELECT * FROM widgets WHERE slot = ? AND is_active = 1 ORDER BY sort_order, id');
            $st->execute([$slot]);
        }
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/** The custom pages that have at least one active widget. */
function widget_pages(): array
{
    if (!widgets_ready()) { return []; }
    try {
        return db()->query("SELECT page_slug, MAX(page_title) AS page_title, COUNT(*) AS n FROM widgets WHERE slot = 'page' AND is_active = 1 AND page_slug <> '' GROUP BY page_slug ORDER BY MIN(sort_order), MIN(id)")->fetchAll();
    } catch (Throwable $e) { return []; }
}

function widget_slug(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim((string)$s, '-'), 0, 60);
}

/** ['type' => 'url'|'html', 'src' => ...] for a stored code. */
function widget_parse(string $code): array
{
    $code = trim($code);
    if (preg_match('~^https?://[^\s<>"\']+$~i', $code)) {
        return ['type' => 'url', 'src' => $code];
    }
    // A lone <iframe src="https://..."> (nothing else important) is shown directly.
    if (preg_match('~^\s*<iframe\b[^>]*\ssrc\s*=\s*["\'](https?://[^"\']+)["\'][^>]*>\s*</iframe>\s*$~is', $code, $m)) {
        return ['type' => 'url', 'src' => html_entity_decode($m[1], ENT_QUOTES)];
    }
    return ['type' => 'html', 'html' => $code];
}

function widget_doc(string $html, int $id = 0): string
{
    $js = '(function(){var id=' . $id . ';function send(){var h=Math.max(document.documentElement.scrollHeight,document.body.scrollHeight);'
        . 'try{parent.postMessage({skw:id,h:h},"*");}catch(e){}try{if(window.SKW)SKW.h(h);}catch(e){}}'
        . 'if(window.ResizeObserver){new ResizeObserver(send).observe(document.body);}window.addEventListener("load",send);setInterval(send,1200);'
        . 'window.addEventListener("message",function(e){var d=e.data;if(d&&d.skTheme){document.documentElement.style.colorScheme=d.skTheme;document.body.style.color=d.fg||"";}});send();})();';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><base target="_blank">'
        . '<style>html,body{margin:0;padding:0;background:transparent!important;overflow:hidden;font-family:"Source Sans 3",system-ui,sans-serif}'
        . 'table{max-width:100%}img{max-width:100%}</style></head><body>' . $html . '<script>' . $js . '</script></body></html>';
}

/** Parent-side helper, printed once per page: resizes script widgets to their content and shares the site theme with them. */
function widget_runtime_js(): string
{
    static $done = false;
    if ($done) { return ''; }
    $done = true;
    return '<script>(function(){var fr=function(){return document.querySelectorAll("iframe.wgt-frame[data-wid]")};'
        . 'window.addEventListener("message",function(e){var d=e.data;if(!d||typeof d.skw==="undefined"||!d.h)return;'
        . 'Array.prototype.forEach.call(fr(),function(f){if(f.contentWindow===e.source){f.style.height=Math.min(Math.max(parseInt(d.h,10)||0,60),4000)+"px";}});});'
        . 'function theme(){var cs=getComputedStyle(document.body);var dark=document.documentElement.getAttribute("data-theme")==="dark";'
        . 'Array.prototype.forEach.call(fr(),function(f){try{f.contentWindow.postMessage({skTheme:dark?"dark":"light",fg:cs.color},"*");}catch(x){}});}'
        . 'document.addEventListener("load",function(e){if(e.target&&e.target.classList&&e.target.classList.contains("wgt-frame"))theme();},true);'
        . 'new MutationObserver(theme).observe(document.documentElement,{attributes:true,attributeFilter:["data-theme"]});})();</script>';
}

function widget_render(array $w): string
{
    $p = widget_parse((string)$w['code']);
    $h = max(60, min(2000, (int)$w['height']));
    $label = e($w['title']);
    $noScroll = !empty($w['no_scroll']);
    if ($p['type'] === 'url') {
        $ct = max(0, min(600, (int)($w['crop_top'] ?? 0))); $cb = max(0, min(600, (int)($w['crop_bottom'] ?? 0)));
        $frame = '<iframe class="wgt-frame" src="' . e($p['src']) . '" title="' . $label . '" style="height:' . $h . 'px" loading="lazy" referrerpolicy="no-referrer-when-downgrade"'
            . ($noScroll ? ' scrolling="no"' : '') . ' allowtransparency="true"'
            . ' sandbox="allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms"></iframe>';
        if ($ct === 0 && $cb === 0) { return $frame; }
        // Crop away the provider's own header band or footer so the widget blends into the page.
        $frame = str_replace('style="height:' . $h . 'px"', 'style="height:' . $h . 'px;margin-top:-' . $ct . 'px"', $frame);
        return '<div class="wgt-crop" style="height:' . max(40, $h - $ct - $cb) . 'px">' . $frame . '</div>';
    }
    return '<iframe class="wgt-frame" data-wid="' . (int)$w['id'] . '" srcdoc="' . e(widget_doc($p['html'], (int)$w['id'])) . '" title="' . $label . '" style="height:' . $h . 'px" loading="lazy" scrolling="no" allowtransparency="true"'
        . ' sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms"></iframe>';
}

/** All widgets for a slot, as page HTML ('' when there are none). */
function widgets_block(string $slot, string $pageSlug = '', bool $withTitles = true): string
{
    $ws = widgets_for($slot, $pageSlug);
    if (!$ws) { return ''; }
    $out = '<div class="wgt-wrap wgt-' . e($slot) . '">';
    foreach ($ws as $w) {
        $out .= '<section class="wgt">' . ($withTitles && $slot !== 'ticker' ? '<h3 class="wgt-t">' . e($w['title']) . '</h3>' : '') . widget_render($w) . '</section>';
    }
    return $out . '</div>' . widget_runtime_js();
}

/** For the app: the same widgets as JSON-ready rows. */
function widgets_json(string $slot, string $pageSlug = ''): array
{
    $o = [];
    foreach (widgets_for($slot, $pageSlug) as $w) {
        $p = widget_parse((string)$w['code']);
        $o[] = ['id' => (int)$w['id'], 'title' => $w['title'], 'height' => max(60, min(2000, (int)$w['height'])),
                'type' => $p['type'], 'src' => $p['type'] === 'url' ? $p['src'] : '', 'no_scroll' => !empty($w['no_scroll']), 'crop_top' => (int)($w['crop_top'] ?? 0), 'crop_bottom' => (int)($w['crop_bottom'] ?? 0), 'html' => $p['type'] === 'html' ? widget_doc($p['html'], (int)$w['id']) : ''];
    }
    return $o;
}


/** Ready-made starting points shown in the admin form: [label, suggested slot, height, hint, code]. */
function widget_presets(): array
{
    return [
        'ticker'   => ['Score ticker (compact live scores strip)', 'ticker', 80, 'Look for a "scores ticker" or "livescore bar" widget at your provider.', ''],
        'live'     => ['Live scores (all matches)', 'live', 800, 'A "livescores" or "fixtures and results" widget.', ''],
        'tracker'  => ['Live match tracker / match centre', 'match', 600, 'A "match widget" or "live match tracker" that takes a match or fixture id.', ''],
        'table'    => ['League table / standings', 'page', 600, 'A "standings" or "league table" widget. Use "Its own page" with the title League tables.', ''],
        'fixtures' => ['Upcoming fixtures', 'page', 600, 'A "fixtures" or "upcoming matches" widget.', ''],
        'results'  => ['Latest results', 'results', 600, 'A "results" widget for a league or team.', ''],
        'team'     => ['Team stats and form', 'page', 600, 'A "team" or "team form" widget.', ''],
        'h2h'      => ['Head to head', 'match', 500, 'A "head to head" widget that takes two teams.', ''],
        'scorers'  => ['Top scorers', 'page', 500, 'A "top scorers" or "player stats" widget.', ''],
        'odds'     => ['Odds comparison', 'tips', 500, 'Only use an odds widget from a partner whose terms allow it, and keep the 18+ notice.', ''],
        'youtube'  => ['YouTube video or playlist', 'livestream', 420, 'Paste a YouTube embed link, for example https://www.youtube.com/embed/VIDEO_ID or https://www.youtube.com/embed/videoseries?list=PLAYLIST_ID', ''],
        'x'        => ['X (Twitter) timeline', 'home', 600, 'On publish.twitter.com choose "Embed a timeline" and paste the code it gives you.', ''],
        'banner'   => ['Banner or promo (HTML)', 'home', 120, 'Plain HTML such as an image link. Example below is filled in for you.', '<a href="https://example.com" target="_blank" rel="noopener sponsored"><img src="https://images.unsplash.com/photo-1522778119026-d647f0596c20?auto=format&fit=crop&w=1200&q=60" alt="Promo" style="width:100%;height:110px;object-fit:cover;border-radius:10px"></a>'],
        'countdown' => ['Countdown to a kickoff', 'home', 110, 'Change the date in the code (YYYY-MM-DDTHH:MM:SS) and the match name.', '<div style="font:700 20px system-ui;text-align:center;padding:16px;background:#0d3b2c;color:#f0a91c;border-radius:12px"><div style="font-size:13px;color:#fff;font-weight:600">Next big match</div><div id="c" style="margin-top:6px"></div></div><script>var t=new Date("2026-11-08T17:30:00").getTime();setInterval(function(){var d=t-Date.now();if(d<0){document.getElementById("c").textContent="Kicked off";return}var h=Math.floor(d/36e5),m=Math.floor(d%36e5/6e4),s=Math.floor(d%6e4/1e3);document.getElementById("c").textContent=Math.floor(h/24)+"d "+(h%24)+"h "+m+"m "+s+"s"},1000)</script>'],
        'custom'   => ['Other (any embed code)', 'home', 500, 'Anything a provider gives you.', ''],
    ];
}
