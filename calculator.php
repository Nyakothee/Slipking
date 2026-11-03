<?php
require_once __DIR__ . '/includes/init.php';

$page_title = 'Betting calculator: stake size, Kelly and accumulator odds - ' . SITE_NAME;
$page_desc  = 'Free betting calculator. Work out a sensible stake with the Kelly criterion, flat staking, or the combined odds and stake for an accumulator.';
$active     = '';
$canonical  = url('calculator.php');
$head_extra = '<link rel="stylesheet" href="' . e(url('assets/css/group-c.css')) . '">' . "\n" . '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
$extra_js   = ['assets/js/calculator.js'];
include __DIR__ . '/includes/header.php';
?>
<div class="wrap page-top">
  <div class="crumbs"><a href="<?= e(url()) ?>">Home</a> / Calculator</div>
  <h1 class="mtitle">Betting calculator</h1>
  <p style="color:var(--mute);max-width:680px">Estimate a sensible stake for a single bet, or work out the combined odds and stake for an accumulator. Use the same currency for every amount.</p>
</div>
<section style="padding-top:22px">
  <div class="wrap">
    <div class="note" style="margin-top:0"><strong>Estimates, not guarantees.</strong> The results are only as good as the numbers you put in, and no bet is certain to win. 18+ only. Never stake money you cannot afford to lose. Read our <a href="<?= e(url('responsible-gambling.php')) ?>" style="text-decoration:underline">responsible gambling page</a>.</div>

    <div class="tabs calc-tabs" role="tablist" aria-label="Calculator type">
      <button type="button" role="tab" id="tabSingle" aria-controls="panelSingle" aria-selected="true" class="on" data-mode="single">Single bet</button>
      <button type="button" role="tab" id="tabAcca" aria-controls="panelAcca" aria-selected="false" data-mode="acca">Accumulator</button>
    </div>

    <div id="panelSingle" class="two calc-panel" role="tabpanel" aria-labelledby="tabSingle">
      <form class="box2 calc-form" id="singleForm" novalidate>
        <h3>Your bet</h3>
        <div class="field"><label for="cBank">Bankroll</label><input type="number" id="cBank" inputmode="decimal" min="0" step="any" placeholder="e.g. 10000"><div class="hint">The total amount you set aside for betting.</div></div>
        <div class="field"><label for="cOdds">Decimal odds</label><input type="number" id="cOdds" inputmode="decimal" min="1.01" step="any" placeholder="e.g. 2.10"></div>
        <div class="field"><label for="cMode">How to estimate the win chance</label>
          <select id="cMode"><option value="prob">I will enter my own win probability</option><option value="stars">Use a confidence rating (1 to 5 stars)</option></select></div>
        <div class="field" id="rowProb"><label for="cProb">Win probability (%)</label><input type="number" id="cProb" inputmode="decimal" min="0" max="100" step="any" placeholder="e.g. 55"><div class="hint">Your honest estimate of how often this bet wins.</div></div>
        <div class="field" id="rowStars" hidden><label for="cStars">Confidence</label>
          <select id="cStars"><option value="1">1 star: 35%</option><option value="2">2 stars: 45%</option><option value="3" selected>3 stars: 55%</option><option value="4">4 stars: 65%</option><option value="5">5 stars: 75%</option></select>
          <div class="hint">The percentages are fixed assumptions for this calculator, not our recorded win rates.</div></div>
        <div class="field"><label for="cRisk">Risk profile</label>
          <select id="cRisk"><option value="cautious">Cautious: quarter Kelly, 1% flat</option><option value="balanced" selected>Balanced: half Kelly, 2% flat</option><option value="bold">Bold: full Kelly, 3% flat</option></select></div>
      </form>
      <div class="box2" id="singleOut" aria-live="polite"><h3>Suggested stake</h3><p style="color:var(--mute)">Enter your bankroll, odds and win chance.</p></div>
    </div>

    <div id="panelAcca" class="two calc-panel" role="tabpanel" aria-labelledby="tabAcca" hidden>
      <form class="box2 calc-form" id="accaForm" novalidate>
        <h3>Your selections</h3>
        <div id="legList" class="leg-list"></div>
        <p><button type="button" class="btn dark small" id="addLeg">Add a selection</button></p>
        <div class="field" style="margin-top:14px"><label for="aTarget">Target return (stake plus profit)</label><input type="number" id="aTarget" inputmode="decimal" min="0" step="any" placeholder="e.g. 5000"><div class="hint">Optional. We work out the stake needed to return this much.</div></div>
        <div class="field"><label for="aStake">Or your stake</label><input type="number" id="aStake" inputmode="decimal" min="0" step="any" placeholder="e.g. 100"><div class="hint">Optional. We show what it would return.</div></div>
        <div class="field"><label for="aBank">Bankroll (optional)</label><input type="number" id="aBank" inputmode="decimal" min="0" step="any" placeholder="e.g. 10000"></div>
      </form>
      <div class="box2" id="accaOut" aria-live="polite"><h3>Accumulator result</h3><p style="color:var(--mute)">Enter the odds of each selection.</p></div>
    </div>

    <div class="box2" style="margin-top:26px">
      <h3>How these numbers work</h3>
      <ul>
        <li>Kelly stake = (odds x chance - 1) / (odds - 1) of your bankroll, where chance is your win probability as a fraction. If the result is zero or negative, the bet has no edge and the stake is 0.</li>
        <li>Full Kelly is aggressive and swings a lot. Half and quarter Kelly are smoother and safer if your estimate is a little too optimistic.</li>
        <li>Flat staking puts the same small share of your bankroll (1 to 3 percent) on every bet, whatever the odds.</li>
        <li>An accumulator multiplies the odds of every selection. One loss loses the whole bet.</li>
      </ul>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
