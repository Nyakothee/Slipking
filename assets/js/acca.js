(function () {
  var box = document.querySelector('.calc');
  if (!box) return;
  var odds = parseFloat(box.dataset.odds) || 1;
  var symbol = box.dataset.symbol || '';
  var decimals = parseInt(box.dataset.decimals, 10) || 0;
  var input = document.getElementById('stake');
  var out = document.getElementById('ret');
  var cashBtn = document.getElementById('cashbtn');
  var cashVal = document.getElementById('cashval');
  var cashSub = document.getElementById('cashsub');
  var info = document.getElementById('cashinfo');
  var state = box.dataset.state || 'pending';
  var wonProd = parseFloat(box.dataset.wonprod) || 1;
  var margin = parseFloat(box.dataset.margin) || 0;
  function T(s) { return (window.SK_T && window.SK_T[s]) || s; }
  var fmt = new Intl.NumberFormat(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

  function update() {
    var s = parseFloat(input.value);
    if (!isFinite(s) || s <= 0) { out.textContent = ''; if (cashVal) { cashVal.textContent = '-'; } return; }
    out.textContent = T('Returns') + ' ' + symbol + fmt.format(s * odds);
    if (!cashBtn) return;
    if (state === 'lost') {
      cashVal.textContent = symbol + fmt.format(0); cashSub.textContent = 'Not available: a selection lost.'; cashBtn.disabled = true; return;
    }
    if (state === 'won') {
      cashVal.textContent = symbol + fmt.format(s * odds); cashSub.textContent = 'Ticket won: full return.'; cashBtn.disabled = true; return;
    }
    if (state !== 'pending') { cashVal.textContent = symbol + fmt.format(s); cashSub.textContent = 'Void: stake back.'; cashBtn.disabled = true; return; }
    // cash out = stake x odds of won selections x (1 - margin); pending selections are valued at the odds they were posted with
    cashVal.textContent = symbol + fmt.format(s * wonProd * (1 - margin));
    cashSub.textContent = box.dataset.won + ' won, ' + box.dataset.pend + ' to play';
  }
  if (cashBtn && info) {
    cashBtn.addEventListener('click', function () { info.hidden = !info.hidden; });
  }
  input.addEventListener('input', update);
  update();
})();
