<?php
/** In-page game player. Shows a saved stream or replay link inside SlipKing (pop-up, full page, or the app) without sending anyone away. */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/chat-box.php';
$dec = function ($raw) { return stream_clean((string)base64_decode(strtr((string)$raw, '-_', '+/'), true)); };
$url = $dec($_GET['u'] ?? '');
$ok = $url !== '' && hash_equals(stream_sig($url), (string)($_GET['s'] ?? ''));
$mode = in_array($_GET['m'] ?? '', ['embed', 'app'], true) ? (string)$_GET['m'] : '';
$title = mb_substr(trim((string)($_GET['t'] ?? '')), 0, 90);
$mid = (int)($_GET['i'] ?? 0);
$kick = (int)($_GET['k'] ?? 0); if ($kick < time() || $kick > time() + 7 * 86400) { $kick = 0; }
$backup = $dec($_GET['b'] ?? ''); if ($backup !== '' && !hash_equals(stream_sig($backup), (string)($_GET['bs'] ?? ''))) { $backup = ''; }
$em = $ok ? stream_embed($url) : null;
if (!$em) { http_response_code(404); }
$frameAttrs = 'allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="origin" sandbox="allow-scripts allow-same-origin allow-presentation allow-forms"';
$player = '';
if ($em) {
    if ($em['kind'] === 'iframe') { $player = '<iframe id="pl" src="' . e($em['src']) . '" title="' . e($title ?: 'Game') . '" ' . $frameAttrs . '></iframe>'; }
    else { $player = '<video id="pl" controls autoplay playsinline ' . ($em['kind'] === 'video' ? 'src="' . e($em['src']) . '"' : '') . '></video>'; }
}
$hls = $em && $em['kind'] === 'hls';
$hlsJs = '<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.8/hls.min.js"></script><script>(function(){var v=document.getElementById("pl"),s=' . json_encode($em['src'] ?? '') . ';if(v.canPlayType("application/vnd.apple.mpegurl")){v.src=s;}else if(window.Hls&&Hls.isSupported()){var h=new Hls();h.loadSource(s);h.attachMedia(v);}else{document.getElementById("pl-note").hidden=false;}})();</script>';
$sig = stream_sig($url);
$ub64 = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
$bUrl = $backup !== '' ? stream_watch_url($backup, $title, $mode, ['mid' => $mid]) : '';
$csrf = csrf_token();
$tools = function (string $cls) use ($url, $bUrl) {
    return '<span class="' . $cls . '"><a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow">Open original link</a> &middot; '
        . '<button type="button" id="rp-btn" class="wt-b">Report broken link</button>'
        . ($bUrl !== '' ? ' &middot; <a href="' . e($bUrl) . '" id="bk-btn">Try backup link</a>' : '') . '<span id="rp-msg"></span></span>';
};
$reportJs = '<script>(function(){var b=document.getElementById("rp-btn");if(!b)return;b.addEventListener("click",function(){b.disabled=true;var f=new FormData();f.append("a","report");f.append("u",' . json_encode($ub64) . ');f.append("s",' . json_encode($sig) . ');f.append("csrf",' . json_encode($csrf) . ');fetch(' . json_encode(url('api/watch.php')) . ',{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){document.getElementById("rp-msg").textContent=" "+(j.message||j.error||"");}).catch(function(){b.disabled=false;});});})();</script>';
$cdHtml = $kick ? '<div id="cd" class="wp-cd"><div><small>Starts in</small><b id="cd-t">--:--:--</b><button type="button" id="cd-go">Play now</button></div></div>' : '';
$cdJs = $kick ? '<script>(function(){var end=' . ($kick * 1000) . ',t=document.getElementById("cd-t"),box=document.getElementById("cd");function p(n){return n<10?"0"+n:n}function tick(){var s=Math.max(0,Math.floor((end-Date.now())/1000));if(s<=0){box.style.display="none";return;}var h=Math.floor(s/3600),m=Math.floor(s%3600/60);t.textContent=(h>99?Math.floor(h/24)+"d "+p(h%24):p(h))+":"+p(m)+":"+p(s%60);setTimeout(tick,1000);}document.getElementById("cd-go").onclick=function(){box.style.display="none";};tick();})();</script>' : '';
$cdCss = '.wp-cd{position:absolute;inset:0;z-index:3;display:flex;align-items:center;justify-content:center;text-align:center;background:radial-gradient(circle at 50% 30%,#155240,#0a2f23);color:#fff}.wp-cd small{display:block;opacity:.8;letter-spacing:.1em;text-transform:uppercase;font-size:12px}.wp-cd b{display:block;font-size:40px;margin:6px 0 12px;font-variant-numeric:tabular-nums}.wp-cd button{background:#f0a91c;border:0;border-radius:99px;padding:9px 20px;font-weight:800;cursor:pointer}';
$extras = '';
if ($mm = ($mid ? watch_match($mid) : null)) { $u = current_user(); ob_start(); ?>
    <div class="lp" id="lp" data-m="<?= (int)$mid ?>"><h3>Live poll: who scores next?</h3><p class="wt" id="lp-s">Loading...</p><div class="lp-b" id="lp-b"></div></div>
    <div style="margin-top:18px"><?= chat_box_html(1000000000 + $mid, $u, 'Watch-party chat', 'talk about the game with everyone watching') ?></div>
    <p class="wt" style="margin-top:8px"><a href="<?= e(url('room.php?id=' . $mid)) ?>">Open the full match discussion room</a></p>
    <script>(function(){var box=document.getElementById("lp"),m=box.dataset.m,api=<?= json_encode(url('api/watch.php')) ?>,csrf=<?= json_encode($csrf) ?>,L={"1":<?= json_encode((string)$mm['home_team']) ?>,"X":"No more goals","2":<?= json_encode((string)$mm['away_team']) ?>};
    function draw(s){var b=document.getElementById("lp-b"),tot=(+s.counts["1"])+(+s.counts.X)+(+s.counts["2"]);b.innerHTML="";["1","X","2"].forEach(function(k){var n=+s.counts[k],bt=document.createElement("button");bt.type="button";bt.className=s.mine===k?"on":"";bt.innerHTML=L[k]+"<small>"+(tot?Math.round(n/tot*100):0)+"% ("+n+")</small>";bt.disabled=!s.open;bt.onclick=function(){var f=new FormData();f.append("a","vote");f.append("m",m);f.append("c",k);f.append("csrf",csrf);fetch(api,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(j.login){location.href=<?= json_encode(url('auth/login.php?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''))) ?>;return;}if(j.ok)draw(j);else document.getElementById("lp-s").textContent=j.error||"";});};b.appendChild(bt);});
      document.getElementById("lp-s").textContent=(s.score?"Score "+s.score+". ":"")+(s.open?"Vote now. A new poll opens after every goal.":"Voting opens when the game is on.");}
    function load(){fetch(api+"?a=poll&m="+m,{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){if(j.ok)draw(j);}).catch(function(){});}
    load();setInterval(load,20000);})();</script>
    <script src="<?= e(url('assets/js/globalchat.js')) ?>?v=3"></script>
<?php $extras = ob_get_clean(); }
if ($mode !== ''): ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($title ?: 'Watch') ?></title>
<?php if ($mode === 'app' && $extras !== ''): ?><link rel="stylesheet" href="<?= e(url('assets/css/discuss-hub.css')) ?>?v=5"><link rel="stylesheet" href="<?= e(url('assets/css/global-chat.css')) ?>?v=8"><?php endif; ?>
<style>html,body{margin:0;background:#000;color:#fff;font-family:system-ui,sans-serif}body{min-height:100%}
.pw{position:relative;width:100%;background:#000}<?= ($mode === 'embed' || $extras === '') ? '.pw{position:fixed;inset:0}' : '.pw{aspect-ratio:16/9}' ?>
#pl{position:absolute;inset:0;width:100%;height:100%;border:0;background:#000}
.nt{position:absolute;left:0;right:0;bottom:0;z-index:4;padding:7px 12px;font-size:12px;background:rgba(0,0,0,.65);text-align:center}.nt a,.wt-b{color:#ffc94d}.wt-b{background:none;border:0;padding:0;font:inherit;cursor:pointer;text-decoration:underline}.err{display:flex;height:100vh;align-items:center;justify-content:center;text-align:center;padding:24px}
.more{background:#f4f7f5;color:#17261f;padding:14px 12px 30px}.more .lp{margin-top:0}.wt{color:#5b6b64;font-size:13px}.lp{background:#fff;border:1px solid #e1e9e3;border-radius:16px;padding:14px;margin-bottom:14px}.lp h3{margin:0 0 4px;font-size:16px}.lp-b{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}.lp-b button{border:1px solid #d6ded9;background:#f7faf8;border-radius:12px;padding:10px 6px;font-weight:700;cursor:pointer}.lp-b button.on{background:#f0a91c;border-color:#f0a91c}.lp-b small{display:block;font-weight:600;color:#5b6b64}
@media (orientation:landscape){.pw{position:fixed!important;inset:0;aspect-ratio:auto!important}.more{display:none}}
<?= $cdCss ?></style></head><body>
<?php if ($em): ?><div class="pw"><?= $cdHtml ?><?= $player ?><div class="nt" id="pl-note"<?= $hls ? ' hidden' : '' ?>><?= $tools('wt') ?></div></div><?= $hls ? $hlsJs : '' ?><?= $cdJs ?><?= $reportJs ?>
<?php if ($mode === 'app' && $extras !== ''): ?><div class="more"><?= $extras ?></div><?php endif; ?>
<?php else: ?><div class="err">This game link is not available.</div><?php endif; ?>
</body></html>
<?php exit; endif;
$page_title = ($title !== '' ? $title . ' - watch - ' : 'Watch - ') . SITE_NAME;
$head_extra = '<meta name="robots" content="noindex">' . ($extras !== '' ? '<link rel="stylesheet" href="' . e(url('assets/css/discuss-hub.css')) . '?v=5"><link rel="stylesheet" href="' . e(url('assets/css/global-chat.css')) . '?v=8">' : '');
$active = 'livestream';
include __DIR__ . '/includes/header.php';
?>
<style><?= $cdCss ?>.wt{font-size:13px;color:var(--mute)}.wt a{text-decoration:underline}.wt-b{background:none;border:0;padding:0;font:inherit;cursor:pointer;text-decoration:underline;color:inherit}
.lp{margin-top:18px;background:#fff;border:1px solid var(--line,#e1e9e3);border-radius:16px;padding:16px}.lp h3{margin:0 0 4px}.lp-b{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}.lp-b button{border:1px solid var(--line,#d6ded9);background:#f7faf8;border-radius:12px;padding:10px 6px;font-weight:700;cursor:pointer}.lp-b button.on{background:#f0a91c;border-color:#f0a91c}.lp-b small{display:block;font-weight:600;color:#5b6b64}</style>
<section><div class="wrap" style="max-width:960px">
  <div class="crumbs"><a href="<?= e(url('livestream.php')) ?>">Livestream</a> / Watch</div>
  <h1 class="mtitle" style="margin:6px 0 4px"><?= e($title !== '' ? $title : 'Watch the game') ?></h1>
  <?php if ($mm): $ph = match_phase($mm + ['kickoff_at' => $mm['kickoff_at']]); ?><p class="wt" style="margin:0 0 10px"><?= live_badge($mm) ?> <?= $mm['home_score'] !== null ? '<b>' . (int)$mm['home_score'] . ' - ' . (int)$mm['away_score'] . '</b>' : '' ?></p><?php endif; ?>
  <?php if ($em): ?>
    <div class="ls-player" style="position:relative;background:#000;border-radius:14px;overflow:hidden"><?= $cdHtml ?><?= str_replace('id="pl"', 'id="pl" style="position:absolute;inset:0;width:100%;height:100%;border:0"', $player) ?></div>
    <p class="wt" id="pl-note" style="margin-top:10px"<?= $hls ? ' hidden' : '' ?>><?= $tools('wt') ?> &middot; Some sites do not allow their video to be shown inside other pages.</p>
    <?= $hls ? $hlsJs : '' ?><?= $cdJs ?><?= $reportJs ?>
    <?= $extras ?>
  <?php else: ?><div class="empty">This game link is not available.</div><?php endif; ?>
</div></section>
<?php include __DIR__ . '/includes/footer.php'; ?>
