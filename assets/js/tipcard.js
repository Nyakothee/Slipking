/* Tip cards: live score refresh, "starts in" labels, follow bell, copy-all button. */
(function () {
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || window.SK_CSRF || '';
  function rel(ts) {
    var s = ts - Date.now() / 1000; if (s <= 0) return '';
    var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60);
    return h > 0 ? 'starts in ' + h + 'h ' + m + 'm' : 'starts in ' + m + 'm';
  }
  function tick() { var e = document.querySelectorAll('.st-in[data-ts]'); for (var i = 0; i < e.length; i++) e[i].textContent = rel(+e[i].dataset.ts); }
  tick(); setInterval(tick, 30000);

  /* live scores for cards that are in play (refresh every 30 s) */
  function live() {
    var cards = document.querySelectorAll('.slip[data-mid]'), ids = [], now = Date.now() / 1000;
    for (var i = 0; i < cards.length; i++) { var ts = +cards[i].dataset.ts; if (ts && now > ts - 600 && now < ts + 9600) ids.push(cards[i].dataset.mid); }
    ids = ids.filter(function (v, k) { return ids.indexOf(v) === k; }).slice(0, 40);
    if (!ids.length) return;
    fetch((window.SK_BASE || '') + 'api/live.php?ids=' + ids.join(','), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      (j.matches || []).forEach(function (m) {
        document.querySelectorAll('.slip[data-mid="' + m.id + '"]').forEach(function (c) {
          if (m.home === null) return;
          var box = c.querySelector('.lvs');
          if (!box) { box = document.createElement('div'); box.className = 'lvs'; box.innerHTML = '<b class="lvs-s"></b> <span class="lvs-l"></span>'; var body = c.querySelector('.body'); if (body) body.insertBefore(box, body.firstChild); }
          box.classList.toggle('on', !!m.live);
          box.querySelector('.lvs-s').textContent = m.home + ' - ' + m.away;
          box.querySelector('.lvs-l').textContent = m.finished ? 'Full time' : (m.label || 'Live');
        });
      });
    }).catch(function () {});
  }
  live(); setInterval(live, 30000);

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.tf[data-tip]') : null;
    if (b) {
      e.preventDefault();
      var f = new FormData(); f.append('tip', b.dataset.tip); f.append('csrf', csrf);
      fetch((window.SK_BASE || '') + 'api/tipfollow.php', { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.login) { location.href = (window.SK_BASE || '') + 'auth/login.php?next=' + encodeURIComponent(location.pathname + location.search); return; }
        if (j.ok) { b.classList.toggle('on', !!j.on); b.setAttribute('aria-pressed', j.on ? 'true' : 'false'); }
      });
      return;
    }
    var c = e.target.closest ? e.target.closest('[data-copyall]') : null;
    if (c) {
      var lines = [], cards = document.querySelectorAll('.slip[data-copy]');
      for (var i = 0; i < cards.length; i++) lines.push(cards[i].dataset.copy);
      var txt = (c.dataset.title || 'Tips') + '\n' + lines.join('\n') + '\n' + location.href;
      var done = function () { var o = c.textContent; c.textContent = lines.length ? 'Copied ' + lines.length + ' tips' : 'No open tips to copy'; setTimeout(function () { c.textContent = o; }, 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(txt).then(done, done);
      else { var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (x) {} ta.remove(); done(); }
    }
  });
})();
(function () {
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.tp-rx .rxb[data-e]');
    if (!b) { return; }
    var box = b.parentNode, fd = new FormData();
    fd.append('csrf', window.SK_CSRF || ''); fd.append('id', box.getAttribute('data-tip')); fd.append('emoji', b.getAttribute('data-e'));
    fetch((window.SK_BASE || '/') + 'api/tipreact.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { if (d.error) { alert(d.error); } return; }
      [].forEach.call(box.querySelectorAll('.rxb[data-e]'), function (x) {
        var em = x.getAttribute('data-e'); x.classList.toggle('on', d.mine === em); x.querySelector('b').textContent = d.counts[em] ? d.counts[em] : '';
      });
    });
  });
})();
