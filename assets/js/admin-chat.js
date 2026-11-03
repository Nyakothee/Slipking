(function () {
  var root = document.getElementById('achat');
  if (!root) return;
  var api = root.dataset.api, csrf = root.dataset.csrf, userUrl = root.dataset.userurl;
  var listEl = document.getElementById('athreads'), msgsEl = document.getElementById('amsgs');
  var headEl = document.getElementById('ahead'), form = document.getElementById('areply');
  var current = parseInt(root.dataset.open, 10) || 0, lastId = 0, baseTitle = document.title;

  function get(params) {
    return fetch(api + '?' + params, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
  }
  function post(fields) {
    var fd = new FormData(); fd.append('csrf', csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function loadThreads() {
    get('action=threads').then(function (d) {
      if (!d.ok) return;
      listEl.textContent = '';
      var unread = 0;
      if (!d.threads.length) { listEl.textContent = 'No conversations yet.'; }
      d.threads.forEach(function (t) {
        unread += t.unread ? 1 : 0;
        var a = document.createElement('a');
        a.href = '#'; a.className = 'chatitem' + (t.id === current ? ' on' : '') + (t.unread ? ' unread' : '');
        var n = document.createElement('strong'); n.textContent = t.name + (t.status === 'closed' ? ' (closed)' : '') + (t.unread ? '  \u25CF' : '');
        var p = document.createElement('span'); p.textContent = t.last;
        var w = document.createElement('small'); w.textContent = t.when;
        a.appendChild(n); a.appendChild(p); a.appendChild(w);
        a.addEventListener('click', function (e) { e.preventDefault(); openThread(t.id); });
        listEl.appendChild(a);
      });
      document.title = (unread ? '(' + unread + ') ' : '') + baseTitle;
    }).catch(function () {});
  }

  function addMsg(m) {
    var d = document.createElement('div'); d.className = 'am ' + (m.from === 'admin' ? 'me' : 'them');
    var b = document.createElement('div'); b.textContent = m.body;
    var s = document.createElement('small'); s.textContent = m.time;
    d.appendChild(b); d.appendChild(s); msgsEl.appendChild(d);
  }

  function loadMessages(first) {
    if (!current) return;
    get('action=messages&thread=' + current + '&after=' + lastId).then(function (d) {
      if (!d.ok) return;
      var stick = msgsEl.scrollTop + msgsEl.clientHeight >= msgsEl.scrollHeight - 60;
      d.messages.forEach(function (m) { addMsg(m); lastId = Math.max(lastId, m.id); });
      if (first || (d.messages.length && stick)) msgsEl.scrollTop = msgsEl.scrollHeight;
      if (first) {
        headEl.textContent = '';
        var t = d.thread;
        var h = document.createElement('strong'); h.textContent = t.name;
        headEl.appendChild(h);
        if (t.email) { var e = document.createElement('span'); e.textContent = ' - ' + t.email; headEl.appendChild(e); }
        if (t.user_id) { var l = document.createElement('a'); l.href = userUrl + t.user_id; l.textContent = ' (member)'; l.style.textDecoration = 'underline'; headEl.appendChild(l); }
        var c = document.createElement('button'); c.type = 'button'; c.className = 'btn outline tiny'; c.style.marginInlineStart = '12px';
        c.textContent = t.status === 'closed' ? 'Reopen' : 'Close chat';
        c.addEventListener('click', function () { post({ action: t.status === 'closed' ? 'reopen' : 'close', thread: current }).then(function () { openThread(current); loadThreads(); }); });
        headEl.appendChild(c);
      }
    }).catch(function () {});
  }

  function openThread(id) {
    current = id; lastId = 0; msgsEl.textContent = ''; form.hidden = false;
    history.replaceState(null, '', '?thread=' + id);
    loadMessages(true); loadThreads();
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var body = form.body.value.trim();
    if (!body || !current) return;
    post({ action: 'send', thread: current, body: body }).then(function (d) {
      if (d.ok) { form.body.value = ''; loadMessages(false); loadThreads(); }
      else { alert(d.message || 'Could not send.'); }
    });
  });
  form.body.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true })); }
  });

  loadThreads();
  if (current) { form.hidden = false; loadMessages(true); }
  setInterval(loadThreads, 8000);
  setInterval(function () { loadMessages(false); }, 4000);
})();
