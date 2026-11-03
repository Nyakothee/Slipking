/* SlipKing betting calculator. Plain JavaScript, no libraries. Estimates only, not guarantees. */
(function (root) {
  'use strict';

  var STARS = { 1: 0.35, 2: 0.45, 3: 0.55, 4: 0.65, 5: 0.75 };
  var RISK = {
    cautious: { kelly: 'quarter', frac: 0.25, flat: 0.01 },
    balanced: { kelly: 'half', frac: 0.5, flat: 0.02 },
    bold:     { kelly: 'full', frac: 1, flat: 0.03 }
  };

  function r2(n) { return Math.round(n * 100) / 100; }

  /** odds = decimal odds, p = win chance as a fraction (0..1). Negative edge is clamped to 0. */
  function kelly(odds, p) {
    var b = odds - 1;
    var edge = odds * p - 1;                       // expected profit per 1 staked
    var f = b > 0 ? edge / b : 0;                  // full Kelly fraction
    var hasEdge = f > 0;
    return { fraction: hasEdge ? f : 0, edge: edge, hasEdge: hasEdge };
  }

  /** Stake amounts for a bankroll. */
  function single(bank, odds, p, riskKey) {
    var k = kelly(odds, p);
    var risk = RISK[riskKey] || RISK.balanced;
    var full = k.fraction * bank;
    var out = {
      hasEdge: k.hasEdge, edge: k.edge, impliedProb: 1 / odds,
      fullFraction: k.fraction,
      full: r2(full), half: r2(full / 2), quarter: r2(full / 4),
      recommended: r2(full * risk.frac), recommendedName: risk.kelly,
      flat: r2(bank * risk.flat), flatPct: risk.flat * 100
    };
    out.profitRecommended = r2(out.recommended * (odds - 1));
    out.profitFlat = r2(out.flat * (odds - 1));
    return out;
  }

  /** Combined odds, stake for a target return, return for a stake. */
  function accumulator(oddsList, target, stake) {
    var combined = 1, implied = 1, i;
    for (i = 0; i < oddsList.length; i++) { combined *= oddsList[i]; implied /= oddsList[i]; }
    var out = { legs: oddsList.length, combined: combined, impliedProb: implied, oneIn: implied > 0 ? 1 / implied : Infinity };
    if (target > 0 && combined > 0) { out.stakeForTarget = r2(target / combined); }
    if (stake > 0) { out.returnForStake = r2(stake * combined); out.profitForStake = r2(stake * combined - stake); }
    return out;
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { kelly: kelly, single: single, accumulator: accumulator, STARS: STARS, RISK: RISK };
  }
  root.SKCalc = { kelly: kelly, single: single, accumulator: accumulator };

  /* ---------------- page wiring (browser only) ---------------- */
  if (typeof document === 'undefined') { return; }

  function T(s) { return (root.SK_T && root.SK_T[s]) || s; }
  function $(id) { return document.getElementById(id); }
  function num(id) { var v = parseFloat($(id).value); return isFinite(v) ? v : NaN; }
  function money(n) { return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function pct(n) { return (Math.round(n * 10) / 10).toString() + '%'; }

  function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }
  function add(el, tag, text, cls) {
    var n = document.createElement(tag);
    if (text !== undefined && text !== null) { n.textContent = text; }
    if (cls) { n.className = cls; }
    el.appendChild(n);
    return n;
  }
  function row(el, label, value, strong) {
    var r = add(el, 'div', null, 'calc-row');
    add(r, 'span', label);
    add(r, strong ? 'strong' : 'span', value);
  }

  function renderSingle() {
    var out = $('singleOut');
    clear(out);
    add(out, 'h3', T('Suggested stake'));
    var bank = num('cBank'), odds = num('cOdds'), p;
    if ($('cMode').value === 'stars') { p = STARS[$('cStars').value] || 0.55; }
    else { p = num('cProb') / 100; }
    if (!(bank > 0) || !(odds > 1) || !isFinite(p)) {
      add(out, 'p', T('Enter your bankroll, odds and win chance.'), 'calc-muted');
      return;
    }
    if (p < 0 || p > 1) { add(out, 'p', T('Win probability must be between 0 and 100.'), 'calc-warn'); return; }
    var r = single(bank, odds, p, $('cRisk').value);

    row(out, T('Bookmaker implied chance'), pct(r.impliedProb * 100));
    row(out, T('Your estimated chance'), pct(p * 100));
    if (!r.hasEdge) {
      add(out, 'p', T('No edge: at these odds your estimated chance does not beat the bookmaker price, so the Kelly stake is 0. Skipping this bet is the sensible choice.'), 'calc-warn');
    } else {
      add(out, 'h4', T('Kelly criterion'));
      row(out, T('Full Kelly'), money(r.full) + ' (' + pct(r.fullFraction * 100) + ')');
      row(out, T('Half Kelly'), money(r.half) + ' (' + pct(r.fullFraction * 50) + ')');
      row(out, T('Quarter Kelly'), money(r.quarter) + ' (' + pct(r.fullFraction * 25) + ')');
      row(out, T('Suggested for your risk profile'), money(r.recommended), true);
      row(out, T('Profit if it wins'), money(r.profitRecommended));
      if (r.fullFraction > 0.25) {
        add(out, 'p', T('Full Kelly is more than a quarter of your bankroll. That is very aggressive and your win estimate may be too optimistic. Consider half or quarter Kelly.'), 'calc-warn');
      }
    }
    add(out, 'h4', T('Flat staking'));
    row(out, T('Flat stake') + ' (' + r.flatPct + '%)', money(r.flat), true);
    row(out, T('Profit if it wins'), money(r.profitFlat));
    add(out, 'p', T('These are estimates, not guarantees. Bets can and do lose. 18+ only.'), 'calc-muted');
  }

  /* accumulator legs */
  var legCount = 0;
  function addLeg(value) {
    if ($('legList').children.length >= 20) { return; }
    legCount++;
    var wrap = document.createElement('div');
    wrap.className = 'leg-row';
    var lab = document.createElement('label');
    lab.setAttribute('for', 'leg' + legCount);
    lab.textContent = T('Odds of selection') + ' ' + ($('legList').children.length + 1);
    var inp = document.createElement('input');
    inp.type = 'number'; inp.id = 'leg' + legCount; inp.min = '1.01'; inp.step = 'any';
    inp.setAttribute('inputmode', 'decimal'); inp.className = 'leg-odds';
    if (value) { inp.value = value; }
    inp.addEventListener('input', renderAcca);
    var rm = document.createElement('button');
    rm.type = 'button'; rm.className = 'btn ghost small leg-rm'; rm.textContent = T('Remove');
    rm.addEventListener('click', function () {
      if ($('legList').children.length > 2) { $('legList').removeChild(wrap); relabel(); renderAcca(); }
    });
    wrap.appendChild(lab); wrap.appendChild(inp); wrap.appendChild(rm);
    $('legList').appendChild(wrap);
  }
  function relabel() {
    var rows = $('legList').children;
    for (var i = 0; i < rows.length; i++) {
      rows[i].querySelector('label').textContent = T('Odds of selection') + ' ' + (i + 1);
    }
  }

  function riskText(res, bank, stake) {
    var n = res.legs;
    var s = T('Every selection must win. If just one loses, you lose your whole stake.') + ' ';
    if (res.oneIn >= 2) {
      s += T('Even if the odds are fair, the chance of all selections winning is about 1 in') + ' ' + (res.oneIn < 10 ? (Math.round(res.oneIn * 10) / 10) : Math.round(res.oneIn)) + ' (' + pct(res.impliedProb * 100) + '). ';
    }
    if (n >= 6) { s += T('With this many selections a win is rare. Treat it as a small-stake lottery ticket, not a plan.') + ' '; }
    else if (n >= 4) { s += T('Four or more selections is high risk. Keep the stake small.') + ' '; }
    var base = stake > 0 ? stake : res.stakeForTarget;
    if (bank > 0 && base > 0 && base / bank > 0.02) {
      s += T('This stake is more than 2% of your bankroll, which is a lot for a bet that usually loses.');
    }
    return s;
  }

  function renderAcca() {
    var out = $('accaOut');
    clear(out);
    add(out, 'h3', T('Accumulator result'));
    var inputs = document.querySelectorAll('.leg-odds'), list = [], i, v;
    for (i = 0; i < inputs.length; i++) {
      v = parseFloat(inputs[i].value);
      if (isFinite(v)) { if (v <= 1) { add(out, 'p', T('Each odds value must be above 1.00.'), 'calc-warn'); return; } list.push(v); }
    }
    if (list.length < 2) { add(out, 'p', T('Enter the odds of at least two selections.'), 'calc-muted'); return; }
    var target = num('aTarget'), stake = num('aStake'), bank = num('aBank');
    var res = accumulator(list, isFinite(target) ? target : 0, isFinite(stake) ? stake : 0);
    row(out, T('Selections'), String(res.legs));
    row(out, T('Combined odds'), money(res.combined), true);
    if (res.stakeForTarget) {
      row(out, T('Stake needed for your target return'), money(res.stakeForTarget), true);
      row(out, T('Profit at that stake'), money(r2(target - res.stakeForTarget)));
    }
    if (res.returnForStake) {
      row(out, T('Return for your stake'), money(res.returnForStake), true);
      row(out, T('Profit if every selection wins'), money(res.profitForStake));
    }
    add(out, 'p', riskText(res, isFinite(bank) ? bank : 0, isFinite(stake) && stake > 0 ? stake : (res.stakeForTarget || 0)), 'calc-warn');
    add(out, 'p', T('These are estimates, not guarantees. 18+ only.'), 'calc-muted');
  }

  function setMode(mode) {
    var single = mode === 'single';
    $('panelSingle').hidden = !single; $('panelAcca').hidden = single;
    $('tabSingle').className = single ? 'on' : ''; $('tabAcca').className = single ? '' : 'on';
    $('tabSingle').setAttribute('aria-selected', single ? 'true' : 'false');
    $('tabAcca').setAttribute('aria-selected', single ? 'false' : 'true');
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (!$('singleForm')) { return; }
    ['cBank', 'cOdds', 'cProb', 'cStars', 'cRisk'].forEach(function (id) {
      $(id).addEventListener('input', renderSingle); $(id).addEventListener('change', renderSingle);
    });
    $('cMode').addEventListener('change', function () {
      var stars = $('cMode').value === 'stars';
      $('rowStars').hidden = !stars; $('rowProb').hidden = stars; renderSingle();
    });
    ['aTarget', 'aStake', 'aBank'].forEach(function (id) { $(id).addEventListener('input', renderAcca); });
    $('addLeg').addEventListener('click', function () { addLeg(); relabel(); });
    addLeg(); addLeg();
    $('tabSingle').addEventListener('click', function () { setMode('single'); });
    $('tabAcca').addEventListener('click', function () { setMode('acca'); });
    ['singleForm', 'accaForm'].forEach(function (id) { $(id).addEventListener('submit', function (e) { e.preventDefault(); }); });
  });
})(typeof window !== 'undefined' ? window : this);
