(function () {
  function ls(k, v) { try { if (v === undefined) { return localStorage.getItem(k); } localStorage.setItem(k, v); } catch (e) { return null; } }
  function modal(title, text, btn, cb) {
    var o = document.createElement('div'); o.className = 'rules-ov';
    var b = document.createElement('div'); b.className = 'rules-box';
    var h = document.createElement('h3'); h.textContent = title;
    var p = document.createElement('p'); p.style.whiteSpace = 'pre-line'; p.textContent = text;
    var k = document.createElement('button'); k.type = 'button'; k.className = 'btn gold'; k.textContent = btn;
    k.onclick = function () { o.remove(); if (cb) { cb(); } };
    b.appendChild(h); b.appendChild(p); b.appendChild(k); o.appendChild(b); document.body.appendChild(o);
  }
  var steps = [
    ['#nav a[href*="tips.php"]', "Today's tips: free picks with odds and analysis. Tap the bell on a tip to follow it."],
    ['#nav a[href*="livestream.php"]', 'Livestream: watch live games right here on the site, no redirects.'],
    ['#nav a[href*="jackpot.php"]', 'Jackpot: free to enter. Predict every game and win.'],
    ['#nav a[href*="vip.php"]', 'VIP: premium picks. Pick a plan that suits you.'],
    ['#nav a[href*="globalchat.php"]', 'Global Chat: talk football with other members.']
  ];
  function tour() {
    var i = 0, tip = document.createElement('div'); tip.className = 'tour-tip'; document.body.appendChild(tip);
    function show() {
      while (i < steps.length && !document.querySelector(steps[i][0])) { i++; }
      if (i >= steps.length) { tip.remove(); ls('sk_tour', '1'); return; }
      var el = document.querySelector(steps[i][0]), r = el.getBoundingClientRect();
      el.scrollIntoView({ block: 'nearest' });
      r = el.getBoundingClientRect();
      tip.innerHTML = '';
      var t = document.createElement('p'); t.textContent = steps[i][1];
      var n = document.createElement('button'); n.type = 'button'; n.className = 'btn gold small'; n.textContent = i < steps.length - 1 ? 'Next' : 'Done';
      var x = document.createElement('button'); x.type = 'button'; x.className = 'btn ghost small'; x.textContent = 'Skip';
      n.onclick = function () { el.classList.remove('tour-on'); i++; show(); };
      x.onclick = function () { el.classList.remove('tour-on'); tip.remove(); ls('sk_tour', '1'); };
      tip.appendChild(t); tip.appendChild(n); tip.appendChild(x);
      el.classList.add('tour-on');
      tip.style.top = Math.min(window.innerHeight - 140, r.bottom + 10) + 'px';
      tip.style.left = Math.max(8, Math.min(window.innerWidth - 280, r.left)) + 'px';
    }
    show();
  }
  window.skTour = tour;
  document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('[data-tour]'); if (a) { e.preventDefault(); tour(); } });
  function whatsNew(next) {
    var w = window.SK_WN;
    if (w && w.id && w.text && ls('sk_wn') !== w.id) { modal("What's new", w.text, 'Got it', function () { ls('sk_wn', w.id); next(); }); return; }
    next();
  }
  whatsNew(function () {
    if (!ls('sk_tour') && document.querySelector('#nav') && !/auth|admin|account/.test(location.pathname) && window.innerWidth > 760) { setTimeout(tour, 1200); }
  });
})();
