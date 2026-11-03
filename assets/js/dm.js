(function () {
  var boot = document.getElementById('dm-boot');
  if (!boot) { return; }
  var api = boot.dataset.api, csrf = boot.dataset.csrf, withId = boot.dataset.with;
  var box = document.getElementById('dm-msgs'), form = document.getElementById('dm-form'), body = document.getElementById('dm-body');
  var st = document.getElementById('dm-st'), errEl = document.getElementById('dm-err'), repBox = document.getElementById('dm-rep');
  var lastId = 0, replyTo = 0, searching = false, pickedFile = null, lastTyping = 0, timer = null;
  var EMOJI = ['👍', '❤️', '😂', '😮', '🔥', '⚽'];

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function req(method, params, fd) {
    var url = api + (method === 'GET' ? '?' + new URLSearchParams(params).toString() : '');
    var opt = { method: method, credentials: 'same-origin' };
    if (method === 'POST') {
      fd = fd || new FormData();
      fd.append('csrf', csrf);
      for (var k in params) { fd.append(k, params[k]); }
      opt.body = fd;
    }
    return fetch(url, opt).then(function (r) { return r.json(); });
  }
  function showErr(m) { if (!errEl) { return; } errEl.textContent = m || ''; errEl.hidden = !m; }
  function msgHtml(m) {
    var h = '<div class="dm-m' + (m.mine ? ' me' : '') + '" data-id="' + m.id + '">';
    if (m.reply) { h += '<div class="dm-q">' + esc(m.reply.mine ? 'You' : 'Them') + ': ' + esc(m.reply.body) + '</div>'; }
    h += '<div class="dm-b">';
    if (m.kind === 'i' && m.url) { h += '<a href="' + esc(m.url) + '" target="_blank" rel="noopener"><img src="' + esc(m.url) + '" alt="Photo" loading="lazy" style="max-width:220px;border-radius:8px;display:block"></a>'; }
    if (m.kind === 'a' && m.url) { h += '<audio controls preload="none" src="' + esc(m.url) + '"></audio>'; }
    if (m.body) { h += esc(m.body).replace(/\n/g, '<br>'); }
    h += '</div>';
    var rx = '';
    for (var e in m.reactions) { rx += '<button type="button" class="dm-rx' + (m.my_reaction === e ? ' on' : '') + '" data-rx="' + esc(e) + '">' + e + ' ' + m.reactions[e] + '</button>'; }
    h += '<small>' + esc(m.time) + (m.mine && m.seen ? ' &middot; seen' : '') + ' <button type="button" class="dm-more" aria-label="Message options">&middot;&middot;&middot;</button></small>';
    if (rx) { h += '<div class="dm-rxs">' + rx + '</div>'; }
    return h + '</div>';
  }
  function atBottom() { return box.scrollHeight - box.scrollTop - box.clientHeight < 80; }
  function render(msgs, replace) {
    var stick = atBottom() || replace;
    if (replace) { box.innerHTML = ''; lastId = 0; }
    if (replace && !msgs.length) { box.innerHTML = '<p class="gp-muted">' + (searching ? 'Nothing found.' : 'Say hello.') + '</p>'; }
    msgs.forEach(function (m) {
      var old = box.querySelector('[data-id="' + m.id + '"]');
      var w = document.createElement('div'); w.innerHTML = msgHtml(m);
      if (old) { old.replaceWith(w.firstChild); } else { box.appendChild(w.firstChild); }
      if (!searching && m.id > lastId) { lastId = m.id; }
    });
    if (stick) { box.scrollTop = box.scrollHeight; }
  }
  function status(d) {
    if (!st) { return; }
    st.textContent = d.typing ? 'typing…' : (d.online ? '● online' : '');
    st.className = 'dm-st' + (d.online ? ' on' : '');
    var mb = document.getElementById('dm-mute'); if (mb) { mb.textContent = d.muted ? 'Unmute' : 'Mute'; mb.dataset.m = d.muted ? '1' : '0'; }
  }
  function load(full) {
    if (searching) { return; }
    req('GET', { a: 'thread', with: withId, after: full ? 0 : lastId }).then(function (d) {
      if (!d.ok) { return; }
      if (full) { render(d.messages, true); } else if (d.messages.length) { render(d.messages, false); }
      status(d);
    }).catch(function () {});
  }
  function setReply(m) {
    replyTo = m ? m.id : 0;
    if (repBox) { repBox.hidden = !m; document.getElementById('dm-rep-t').textContent = m ? 'Replying: ' + m.text : ''; }
  }
  box.addEventListener('click', function (e) {
    var wrap = e.target.closest('.dm-m'); if (!wrap) { return; }
    var id = wrap.dataset.id;
    var rx = e.target.closest('.dm-rx');
    if (rx) { req('POST', { a: 'react', id: id, emoji: rx.dataset.rx }).then(function () { load(true); }); return; }
    if (e.target.closest('.dm-more')) {
      var old = wrap.querySelector('.dm-menu'); if (old) { old.remove(); return; }
      var mn = document.createElement('div'); mn.className = 'dm-menu';
      mn.innerHTML = EMOJI.map(function (x) { return '<button type="button" data-e="' + x + '">' + x + '</button>'; }).join('') +
        '<button type="button" data-do="reply">Reply</button><button type="button" data-do="hide">Delete for me</button>' + (wrap.classList.contains('me') ? '<button type="button" data-do="del">Delete for everyone</button>' : '');
      wrap.appendChild(mn);
      mn.addEventListener('click', function (ev) {
        var b = ev.target.closest('button'); if (!b) { return; }
        if (b.dataset.e) { req('POST', { a: 'react', id: id, emoji: b.dataset.e }).then(function () { load(true); }); }
        else if (b.dataset.do === 'reply') { var t = wrap.querySelector('.dm-b').textContent.trim() || 'attachment'; setReply({ id: id, text: t.slice(0, 50) }); if (body) { body.focus(); } }
        else if (b.dataset.do === 'hide') { req('POST', { a: 'hide', id: id }).then(function () { wrap.remove(); }); }
        else if (b.dataset.do === 'del' && confirm('Delete this message for both of you?')) {
          var fd = new FormData(); fd.append('csrf', csrf); fd.append('action', 'delete'); fd.append('to', withId); fd.append('id', id);
          fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { wrap.remove(); });
        }
        mn.remove();
      });
    }
  });
  var rx = document.getElementById('dm-rep-x'); if (rx) { rx.onclick = function () { setReply(null); }; }

  function send(fd) {
    fd = fd || new FormData();
    fd.append('body', body ? body.value : '');
    if (replyTo) { fd.append('reply_to', replyTo); }
    showErr('');
    return req('POST', { a: 'send', with: withId }, fd).then(function (d) {
      if (!d.ok) { showErr(d.error || 'Could not send.'); return; }
      if (body) { body.value = ''; body.style.height = ''; }
      pickedFile = null; setReply(null); load(false);
    }).catch(function () { showErr('No connection.'); });
  }
  if (form) {
    form.addEventListener('submit', function (e) { e.preventDefault(); if ((body.value || '').trim() === '' && !pickedFile) { return; } send(); });
    body.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.dispatchEvent(new Event('submit', { cancelable: true })); } });
    body.addEventListener('input', function () {
      var now = Date.now();
      if (now - lastTyping > 3000) { lastTyping = now; req('POST', { a: 'typing', with: withId }); }
    });
    var f = document.getElementById('dm-file');
    f.addEventListener('change', function () { if (f.files[0]) { var fd = new FormData(); fd.append('file', f.files[0]); send(fd); f.value = ''; } });
    var mic = document.getElementById('dm-mic'), rec = null, chunks = [];
    mic.addEventListener('click', function () {
      if (rec) { rec.stop(); return; }
      if (!navigator.mediaDevices || !window.MediaRecorder) { showErr('Voice notes are not supported on this browser.'); return; }
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        rec = new MediaRecorder(stream); chunks = [];
        rec.ondataavailable = function (ev) { chunks.push(ev.data); };
        rec.onstop = function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          mic.classList.remove('rec'); mic.innerHTML = '&#127908;';
          var blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' }); rec = null;
          var ext = blob.type.indexOf('mp4') > -1 ? 'm4a' : (blob.type.indexOf('ogg') > -1 ? 'ogg' : 'webm');
          var fd = new FormData(); fd.append('voice', blob, 'voice.' + ext); send(fd);
        };
        rec.start(); mic.classList.add('rec'); mic.innerHTML = '&#9209;';
        setTimeout(function () { if (rec && rec.state === 'recording') { rec.stop(); } }, 120000);
      }).catch(function () { showErr('Allow the microphone to record.'); });
    });
  }
  var q = document.getElementById('dm-q'), qt = null;
  if (q) { q.addEventListener('input', function () {
    clearTimeout(qt);
    qt = setTimeout(function () {
      var v = q.value.trim();
      if (!v) { searching = false; load(true); return; }
      searching = true;
      req('GET', { a: 'thread', with: withId, q: v }).then(function (d) { if (d.ok) { render(d.messages, true); } });
    }, 350);
  }); }
  var mu = document.getElementById('dm-mute');
  if (mu) { mu.addEventListener('click', function () { req('POST', { a: 'mute', with: withId }).then(function (d) { status({ muted: d.muted, online: st.classList.contains('on'), typing: false }); }); }); }

  // chat rules the first time
  try {
    if (!localStorage.getItem('sk_dm_rules') && boot.dataset.rules) {
      var ov = document.createElement('div'); ov.className = 'rules-ov';
      ov.innerHTML = '<div class="rules-box"><h3>Chat rules</h3><p>' + esc(boot.dataset.rules).replace(/\n/g, '<br>') + '</p><button type="button" class="btn gold">I agree</button></div>';
      document.body.appendChild(ov);
      ov.querySelector('button').onclick = function () { try { localStorage.setItem('sk_dm_rules', '1'); } catch (e) {} ov.remove(); };
    }
  } catch (e) {}
  load(true);
  timer = setInterval(function () { if (!document.hidden) { load(false); } }, 3000);
})();
