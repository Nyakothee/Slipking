<?php
require_once __DIR__ . '/includes/init.php';
$english_only = true;
require_login();
$u = current_user();
if (!slips_ready()) { set_flash('error', 'Slip Builder is not installed yet. Import database-update-19.sql.'); redirect('slips.php'); }
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$slip = $id ? slip_get($id, (int)$u['id']) : null;
if ($id && !$slip) { require __DIR__ . '/404.php'; exit; }
$errors = [];
$title = $slip['title'] ?? 'My slip'; $stake = $slip['stake'] ?? '';
$items = $slip['items'] ?? [['home' => '', 'away' => '', 'league' => '', 'market' => '', 'kickoff' => '', 'outcome' => '', 'odds' => '']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    else {
        $title = mb_substr(post_str('title'), 0, 80) ?: 'My slip';
        $stake = post_str('stake');
        [$posted, $errors] = slip_items_from_post();
        if ($stake !== '' && (!is_numeric($stake) || (float)$stake < 0 || (float)$stake > 100000000)) { $errors[] = 'Enter a valid stake or leave it empty.'; }
        if ($posted) { $items = $posted; }
        if (!$errors) {
            if (!$id) { $c = db()->prepare('SELECT COUNT(*) FROM slips WHERE user_id = ?'); $c->execute([$u['id']]); if ((int)$c->fetchColumn() >= 100) { $errors[] = 'You can keep up to 100 slips.'; } }
            if (!$errors) {
                $new = slip_save((int)$u['id'], $id ?: null, $title, $stake === '' ? 0.0 : (float)$stake, $items);
                set_flash('success', 'Slip saved.'); redirect('slip.php?id=' . $new);
            }
        }
    }
}
$page_title = 'Slip builder - ' . SITE_NAME;
$active = 'game';
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/slips.css')) . '?v=1">' . "\n";
include __DIR__ . '/includes/header.php';
$quick = ['Home win', 'Draw', 'Away win', 'Over 2.5', 'Under 2.5', 'BTTS Yes', 'BTTS No', '1X', 'X2'];
$row = function (array $i) use ($quick) {
    ob_start(); ?>
    <div class="sb-row">
      <span class="sb-n" aria-hidden="true"></span>
      <div class="sb-main">
        <div class="sb-teams">
          <input name="home[]" placeholder="Home team" value="<?= e($i['home']) ?>" maxlength="80" aria-label="Home team">
          <span class="sb-vs">vs</span>
          <input name="away[]" placeholder="Away team" value="<?= e($i['away']) ?>" maxlength="80" aria-label="Away team">
        </div>
        <div class="sb-meta">
          <?= slip_league_select('league[]', (string)($i['league'] ?? ''), 'sb-sel') ?>
          <input type="datetime-local" name="kickoff[]" value="<?= e(str_replace(' ', 'T', (string)($i['kickoff'] ?? ''))) ?>" aria-label="Date and time of play">
        </div>
        <div class="sb-meta">
          <select name="market[]" class="sb-sel" aria-label="Market"><option value="">Market</option><?php foreach (slip_markets() as $m): ?><option<?= ($i['market'] ?? '') === $m ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
        </div>
        <div class="sb-pick">
          <input name="outcome[]" placeholder="Your outcome, e.g. Home win" value="<?= e($i['outcome']) ?>" maxlength="60" aria-label="Outcome">
          <div class="sb-odds"><label>Odds</label><input name="odds[]" type="number" step="0.01" min="1.01" placeholder="1.85" value="<?= e($i['odds']) ?>" aria-label="Odds"></div>
        </div>
        <div class="sb-chips" aria-label="Quick outcomes"><?php foreach ($quick as $q): ?><button type="button" class="sb-chip" data-fill="<?= e($q) ?>"><?= e($q) ?></button><?php endforeach; ?></div>
      </div>
      <button type="button" class="sb-del" title="Remove selection" aria-label="Remove selection">&times;</button>
    </div>
    <?php return ob_get_clean();
};
?>
<div class="wrap page-top sb">
  <div class="crumbs"><a href="<?= e(url('slips.php')) ?>">My slips</a> / <?= $id ? 'Edit' : 'New slip' ?></div>
  <h1><?= $id ? 'Edit slip' : 'Slip Builder Studio' ?></h1>
  <?php foreach ($errors as $er): ?><div class="alert error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post" id="sl-form" class="sb-layout"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="sb-left">
      <div class="sb-card sb-name"><label for="st">Slip name</label><input id="st" name="title" value="<?= e($title) ?>" maxlength="80" placeholder="Saturday accumulator"></div>
      <div id="sl-rows" class="sb-rows"><?php foreach ($items as $i) { echo $row($i); } ?></div>
      <button type="button" class="sb-add" id="sl-add"><span>+</span> Add another match</button>
      <template id="sl-tpl"><?= $row(['home' => '', 'away' => '', 'league' => '', 'market' => '', 'kickoff' => '', 'outcome' => '', 'odds' => '']) ?></template>
    </div>
    <aside class="sb-right">
      <div class="sb-ticket">
        <div class="sb-th"><strong>Your slip</strong><span id="sl-nb" class="sb-count">0</span></div>
        <ul class="sb-lines" id="sl-lines"></ul>
        <div class="sb-sum">
          <div class="sb-l"><span>Selections</span><b id="sl-n">0</b></div>
          <div class="sb-l big"><span>Total odds</span><b id="sl-t">0.00</b></div>
          <div class="sb-l stake"><label for="sk">Stake</label><input id="sk" name="stake" type="number" step="0.01" min="0" value="<?= e($stake) ?>" placeholder="0.00"></div>
          <div class="sb-l ret"><span>Potential return</span><b id="sl-r">0.00</b></div>
        </div>
        <div class="sb-btns">
          <button class="btn gold" type="submit">Save slip</button>
          <?php if ($id): ?><a class="btn ghostd" href="<?= e(url('slip-pdf.php?id=' . $id)) ?>">Download PDF</a>
          <button class="btn ghostd" type="button" id="sl-png">Download image</button><?php endif; ?>
        </div>
        <p class="sb-note">Odds are entered by you and are not guaranteed. For fun and planning only. 18+.</p>
      </div>
    </aside>
  </form>
</div>
<script>(function(){
  var box=document.getElementById('sl-rows'),tpl=document.getElementById('sl-tpl');
  function rows(){return box.querySelectorAll('.sb-row');}
  function calc(){var t=1,n=0,lines='';
    rows().forEach(function(r){var o=parseFloat(r.querySelector('[name="odds[]"]').value);var h=r.querySelector('[name="home[]"]').value||'?',a=r.querySelector('[name="away[]"]').value||'?',x=r.querySelector('[name="outcome[]"]').value||'-';
      if(o>=1.01){t*=o;n++;lines+='<li><span>'+esc(h)+' vs '+esc(a)+'<em>'+esc(x)+'</em></span><b>'+o.toFixed(2)+'</b></li>';}});
    var s=parseFloat(document.getElementById('sk').value)||0;
    document.getElementById('sl-n').textContent=n;document.getElementById('sl-nb').textContent=n;
    document.getElementById('sl-t').textContent=n?t.toFixed(2):'0.00';document.getElementById('sl-r').textContent=n?(t*s).toFixed(2):'0.00';
    document.getElementById('sl-lines').innerHTML=lines||'<li class="e">Add odds to see your selections here.</li>';}
  function esc(s){return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  document.getElementById('sl-add').onclick=function(){if(rows().length>=30)return;box.appendChild(tpl.content.cloneNode(true));calc();var l=rows();l[l.length-1].querySelector('input').focus();};
  box.addEventListener('click',function(e){
    var d=e.target.closest('.sb-del');if(d){var r=d.closest('.sb-row');if(rows().length>1){r.remove();}else{r.querySelectorAll('input').forEach(function(i){if(i.type!=='hidden')i.value='';});}calc();return;}
    var c=e.target.closest('.sb-chip');if(c){var r2=c.closest('.sb-row'),inp=r2.querySelector('[name="outcome[]"]');inp.value=c.getAttribute('data-fill');
      r2.querySelectorAll('.sb-chip').forEach(function(x){x.classList.toggle('on',x===c);});calc();}
  });
  document.getElementById('sl-form').addEventListener('input',calc);calc();})();</script>
<script src="<?= e(url('assets/js/slip-image.js')) ?>?v=1"></script>
<script>(function(){var b=document.getElementById('sl-png');if(!b)return;
b.onclick=function(){var items=[];document.querySelectorAll('#sl-rows .sb-row').forEach(function(r){var g=function(n){var e=r.querySelector('[name="'+n+'[]"]');return e?e.value:'';};
 if(parseFloat(g('odds'))>=1.01&&g('home')){items.push({home:g('home'),away:g('away'),league:g('league'),market:g('market'),kickoff:g('kickoff'),outcome:g('outcome'),odds:parseFloat(g('odds'))});}});
 if(!items.length){alert('Add at least one selection with odds first.');return;}
 var t=1;items.forEach(function(i){t*=i.odds;});t=Math.round(t*100)/100;var st=parseFloat(document.getElementById('sk').value)||0;
 var d=new Date(),p2=function(n){return (n<10?'0':'')+n;};
 var cv=window.slipImage({items:items,total:t,stake:st,ret:t*st,ref:<?= json_encode($id ? slip_ref($slip) : 'SK-DRAFT') ?>,created:d.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric'})+', '+p2(d.getHours())+':'+p2(d.getMinutes()),verify:location.host,},null,location.host);
 var a=document.createElement('a');a.download='slipking-<?= $id ? (int)$id : 'slip' ?>.png';a.href=cv.toDataURL('image/png');document.body.appendChild(a);a.click();a.remove();};})();</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
