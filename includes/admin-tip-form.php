<?php
/**
 * Shared tip form. Expects: $f (values), $errors, $locked (bool), $fixedMatch (bool), $leagues, $formAction, $submitLabel, $showAgain (bool)
 * Disabled fields are not sent by the browser, and the pages ignore them on the server too.
 */
$dm = ($locked || $fixedMatch) ? ' disabled' : '';
$dt = $locked ? ' disabled' : '';
$markets = ['1X2', 'Double chance', 'Over/Under', 'BTTS', 'Handicap', 'Correct score', 'Acca', 'Set betting', 'Total games'];
?>
<?php if ($errors): ?><div class="errors" role="alert"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <?php if (!empty($f['match_id'])): ?><input type="hidden" name="match" value="<?= (int)$f['match_id'] ?>"><?php endif; ?>

  <div class="panel">
    <h2>Match</h2>
    <?php if ($fixedMatch): ?>
      <p><strong><?= e($f['home']) ?> vs <?= e($f['away']) ?></strong> &middot; <?= e($f['league_name'] ?? '') ?> &middot; <?= e(date('D j M Y, H:i', strtotime($f['kickoff']))) ?></p>
    <?php else: ?>
      <div class="formgrid">
        <div class="field full"><label for="league_id">League</label>
          <select id="league_id" name="league_id"<?= $dm ?>>
            <?php $cur = ''; foreach ($leagues as $l): if ($cur !== $l['sport']): if ($cur !== '') echo '</optgroup>'; $cur = $l['sport']; echo '<optgroup label="' . e($cur) . '">'; endif; ?>
              <option value="<?= (int)$l['id'] ?>"<?= (int)$f['league_id'] === (int)$l['id'] ? ' selected' : '' ?>><?= e($l['name']) ?></option>
            <?php endforeach; if ($cur !== '') echo '</optgroup>'; ?>
          </select></div>
        <div class="field"><label for="home">Home team / player</label><input type="text" id="home" name="home" value="<?= e($f['home']) ?>" maxlength="80"<?= $dm ?>></div>
        <div class="field"><label for="away">Away team / player</label><input type="text" id="away" name="away" value="<?= e($f['away']) ?>" maxlength="80"<?= $dm ?>></div>
        <div class="field"><label for="kickoff">Kickoff (server time)</label><input type="datetime-local" id="kickoff" name="kickoff" value="<?= e($f['kickoff']) ?>"<?= $dm ?>>
          <div class="hint">Must be in the future.</div></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Tip</h2>
    <div class="formgrid">
      <div class="field"><label for="market">Market</label><input type="text" id="market" name="market" list="markets" value="<?= e($f['market']) ?>" maxlength="40"<?= $dt ?>>
        <datalist id="markets"><?php foreach ($markets as $mk): ?><option value="<?= e($mk) ?>"><?php endforeach; ?></datalist></div>
      <div class="field"><label for="pick">Pick</label><input type="text" id="pick" name="pick" value="<?= e($f['pick']) ?>" maxlength="120" placeholder="e.g. Over 2.5 goals"<?= $dt ?>></div>
      <div class="field"><label for="odds">Odds</label><input type="number" id="odds" name="odds" value="<?= e($f['odds']) ?>" step="0.01" min="1.01" max="1000"<?= $dt ?>></div>
      <div class="field"><label for="confidence">Confidence</label>
        <select id="confidence" name="confidence"<?= $dt ?>>
          <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= (int)$f['confidence'] === $i ? ' selected' : '' ?>><?= $i ?> of 5</option><?php endfor; ?>
        </select></div>
      <div class="field check full"><label><input type="checkbox" name="is_vip" value="1"<?= !empty($f['is_vip']) ? ' checked' : '' ?><?= $dt ?>> This is a VIP tip (hidden from free members until the match is settled)</label></div>
      <div class="field full"><label for="stream_url">Live stream link (optional)</label><input type="url" id="stream_url" name="stream_url" value="<?= e($f['stream_url'] ?? '') ?>" maxlength="400" placeholder="https://..."><div class="hint">If you add a link, a "Watch live" button shows under this tip. Leave empty and no button appears. Only http(s) links are accepted.</div></div>
      <div class="field full"><label for="stream_widget">...or paste widget / embed code instead of a link (optional)</label><textarea id="stream_widget" name="stream_widget" rows="3" style="font-family:monospace" placeholder="<iframe src=&quot;https://...&quot;></iframe> or the provider's widget code"></textarea><div class="hint">It plays inside the same player (pop-ups and redirects are blocked). If you fill this, it replaces the main link.</div></div>
      <div class="field full"><label for="stream_url2">Backup stream link (optional)</label><input type="url" id="stream_url2" name="stream_url2" value="<?= e($f['stream_url2'] ?? '') ?>" maxlength="400" placeholder="https://..."><div class="hint">Used automatically if members report the main link as broken, and offered as "Try backup" in the player.</div></div>
      <?= sources_form($f['stream_extra'] ?? '') ?>
      <?= thumb_field((string)($f['thumb'] ?? '')) ?>
      <div class="field full"><label for="analysis">Analysis (shown to members who can see the tip)</label><textarea id="analysis" name="analysis" maxlength="2000"><?= e($f['analysis']) ?></textarea></div>
    </div>
  </div>

  <button class="btn gold" type="submit"><?= e($submitLabel) ?></button>
  <?php if (!empty($showAgain)): ?><button class="btn dark" type="submit" name="again" value="1">Save and add another tip for this match</button><?php endif; ?>
  <a class="btn outline" href="<?= e(url('admin/tips.php')) ?>">Cancel</a>
</form>
