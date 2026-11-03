/* SlipKing live match centre: polls api/live.php and updates scores, minute, tip results (and the events timeline on a match page). No page reload. */
(function () {
  'use strict';
  var list = document.getElementById('lv-list');
  var panel = document.getElementById('lv-panel');
  var root = list || panel;
  if (!root) { return; }
  var apiUrl = root.getAttribute('data-url');
  var state = document.getElementById('lv-state');
  var timer = null, failing = 0, anyLive = true;
  var ICON = { goal: '⚽', own_goal: '⚽', penalty: '⚽', yellow: '🟨', red: '🟥', sub: '🔄', 'var': '📺', info: 'ℹ️' };

  function ids() {
    var out = [];
    if (list) {
      Array.prototype.forEach.call(list.querySelectorAll('.lv-card'), function (c) { out.push(c.getAttribute('data-id')); });
    } else { out.push(panel.getAttribute('data-id')); }
    return out;
  }
  function setState(txt, bad) { if (state) { state.textContent = txt; state.className = 'lv-state' + (bad ? ' bad' : ''); } }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  function updateCard(m) {
    var card = list.querySelector('.lv-card[data-id="' + m.id + '"]');
    if (!card) { return; }
    var lab = card.querySelector('[data-label]');
    if (lab && m.label) { lab.textContent = m.label; }
    card.classList.toggle('is-live', !!m.live);
    var h = card.querySelector('[data-home]'), a = card.querySelector('[data-away]');
    if (h) { h.textContent = m.home === null ? '-' : m.home; }
    if (a) { a.textContent = m.away === null ? '-' : m.away; }
    (m.tips || []).forEach(function (t) {
      var el = card.querySelector('[data-tip="' + t.id + '"]');
      if (!el) { return; }
      el.className = 'lv-tip st-' + t.status;
      var r = el.querySelector('[data-res]');
      if (r) { r.textContent = t.status === 'pending' ? '' : t.status.charAt(0).toUpperCase() + t.status.slice(1); }
    });
  }

  function updatePanel(m) {
    var sc = document.getElementById('lv-scoreline'), lb = document.getElementById('lv-label'), tl = document.getElementById('lv-events');
    if (sc) { sc.textContent = (m.home === null ? '-' : m.home) + ' : ' + (m.away === null ? '-' : m.away); }
    if (lb) { lb.textContent = m.label || ''; lb.className = 'lv-pill' + (m.live ? ' live' : ''); }
    if (tl && m.events) {
      if (!m.events.length) { tl.innerHTML = '<li class="lv-none">No events yet.</li>'; }
      else {
        tl.innerHTML = m.events.map(function (e) {
          var min = e.minute + (e.extra ? '+' + e.extra : '') + "'";
          return '<li class="lv-ev ' + (e.team === 'away' ? 'away' : 'home') + '"><span class="lv-min">' + esc(min) + '</span><span class="lv-ico">' + (ICON[e.type] || '•') + '</span><span>' + esc(e.player) + (e.detail ? ' <small>' + esc(e.detail) + '</small>' : '') + '</span></li>';
        }).join('');
      }
    }
    var tp = document.getElementById('lv-tipstatus');
    if (tp && m.tips) {
      tp.innerHTML = m.tips.map(function (t) {
        return '<span class="lv-tip st-' + esc(t.status) + '">' + (t.pick ? esc(t.market) + ': <b>' + esc(t.pick) + '</b> @ ' + esc(t.odds) : '<b>Premium tip</b> (VIP)') + (t.status !== 'pending' ? ' <span class="lv-res">' + esc(t.status) + '</span>' : '') + '</span>';
      }).join('');
    }
  }

  function poll() {
    if (document.hidden) { schedule(30000); return; }
    var url = apiUrl + '?ids=' + ids().join(',') + (panel ? '&full=1' : '');
    var x = new XMLHttpRequest();
    x.open('GET', url, true);
    x.onload = function () {
      try {
        var j = JSON.parse(x.responseText);
        failing = 0; anyLive = false;
        (j.matches || []).forEach(function (m) { if (m.live) { anyLive = true; } if (list) { updateCard(m); } else { updatePanel(m); } });
        setState(anyLive ? 'Live, updating every few seconds' : 'Up to date (checked ' + (j.now || '') + ')', false);
      } catch (e) { failing++; setState('Could not update', true); }
      schedule(anyLive ? 20000 : 60000);
    };
    x.onerror = function () { failing++; setState('Connection problem, retrying', true); schedule(Math.min(120000, 20000 * (failing + 1))); };
    x.send();
  }
  function schedule(ms) { clearTimeout(timer); timer = setTimeout(poll, ms); }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { schedule(500); } });
  poll();
})();
