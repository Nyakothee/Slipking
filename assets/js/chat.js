(function () {
  var root = document.getElementById('skchat');
  if (!root) return;
  function T(s) { return (window.SK_T && window.SK_T[s]) || s; }

  var api = root.dataset.api, csrf = root.dataset.csrf;
  var btn = root.querySelector('.skchat-btn');
  var badge = root.querySelector('.skchat-badge');
  var panel = document.getElementById('skchat-panel');
  var box = document.getElementById('skchat-msgs');
  var form = document.getElementById('skchat-form');
  var who = document.getElementById('skchat-who');
  var err = document.getElementById('skchat-err');
  var lastId = 0, has = false, open = false, timer = null;

  function showErr(t) { if (t) { err.textContent = T(t); err.hidden = false; } else { err.hidden = true; } }

  function add(m) {
    var d = document.createElement('div');
    var adm = m.from === 'admin';
    d.className = 'skchat-m ' + (adm ? 'admin' : 'visitor');
    var b = document.createElement('div'); b.className = 'skchat-b'; b.textContent = m.body;
    var s = document.createElement('small'); s.textContent = (adm ? T('Support') + ' \u00b7 ' : '') + m.time;
    var w = document.createElement('div'); w.className = 'skchat-w';
    w.appendChild(b); w.appendChild(s);
    if (adm) { var a = document.createElement('span'); a.className = 'skchat-mav'; a.textContent = 'SK'; d.appendChild(a); }
    d.appendChild(w);
    var hello = box.querySelector('.skchat-hello'); if (hello) hello.style.display = 'none';
    box.appendChild(d);
  }

  function poll() {
    fetch(api + '?action=poll&after=' + lastId + (open ? '&open=1' : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        has = d.has;
        if (who) who.hidden = has;
        var stick = box.scrollTop + box.clientHeight >= box.scrollHeight - 40;
        d.messages.forEach(function (m) { add(m); lastId = Math.max(lastId, m.id); });
        if (d.messages.length && (stick || open)) box.scrollTop = box.scrollHeight;
        if (!open && d.unread > 0) { badge.textContent = d.unread; badge.hidden = false; }
        else { badge.hidden = true; }
        schedule();
      })
      .catch(function () { schedule(); });
  }

  function schedule() {
    clearTimeout(timer);
    var wait = open ? 4000 : (has ? 30000 : 0);
    if (wait) timer = setTimeout(poll, wait);
  }

  function setOpen(v) {
    open = v;
    panel.hidden = !v;
    btn.setAttribute('aria-expanded', v ? 'true' : 'false');
    if (v) { badge.hidden = true; poll(); setTimeout(function () { form.body.focus(); }, 50); box.scrollTop = box.scrollHeight; }
    else { schedule(); }
  }

  btn.addEventListener('click', function () { setOpen(!open); });
  root.querySelector('.skchat-x').addEventListener('click', function () { setOpen(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && open) setOpen(false); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    showErr('');
    var body = form.body.value.trim();
    if (!body) return;
    var fd = new FormData(form);
    fd.append('action', 'send');
    fd.append('csrf', csrf);
    var sendBtn = form.querySelector('button[type=submit]');
    sendBtn.disabled = true;
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        sendBtn.disabled = false;
        if (!d.ok) { showErr(d.message || 'Something went wrong. Please try again.'); return; }
        form.body.value = '';
        has = true;
        if (who) who.hidden = true;
        poll();
      })
      .catch(function () { sendBtn.disabled = false; showErr('Could not connect. Check your internet and try again.'); });
  });

  form.body.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })); }
  });

  poll();   // learn whether this visitor already has a conversation
})();
