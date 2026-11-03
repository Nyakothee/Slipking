/* Chat (global room or a group room): messages, replies, typing, online, emoji, pictures/documents, report and mute.
   All text is inserted with textContent. */
(function () {
  var box = document.getElementById('gc'); if (!box) return;
  var api = box.dataset.api, csrf = box.dataset.csrf, room = box.dataset.room || '0', isStaff = box.dataset.staff === '1', isMod = isStaff;
  var list = document.getElementById('gc-list'), empty = document.getElementById('gc-empty'), act = document.getElementById('gc-active'), typingEl = document.getElementById('gc-typing');
  var form = document.getElementById('gc-form'), inp = document.getElementById('gc-in'), err = document.getElementById('gc-err');
  var fileIn = document.getElementById('gc-file'), pre = document.getElementById('gc-pre'), preI = document.getElementById('gc-pre-i'), preN = document.getElementById('gc-pre-n'), preX = document.getElementById('gc-pre-x');
  var emo = document.getElementById('gc-emo'), ep = document.getElementById('gc-ep');
  var rbar = document.getElementById('gc-reply'), rname = document.getElementById('gc-reply-n'), rx = document.getElementById('gc-reply-x');
  var last = 0, lastDay = '', busy = false, timer = null, picked = null, replyTo = 0, lastTyping = 0, muted = [];
  try { muted = JSON.parse(localStorage.getItem('gc_mute') || '[]'); } catch (e) { muted = []; }
  function saveMute() { try { localStorage.setItem('gc_mute', JSON.stringify(muted)); } catch (e) {} }
  function fmt(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function nearBottom() { return list.scrollHeight - list.scrollTop - list.clientHeight < 140; }
  function post(fields, cb) {
    var fd = new FormData(); fd.append('csrf', csrf); fd.append('room', room);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { if (cb) cb(j); return j; });
  }
  function btn(txt, fn) { var b = document.createElement('button'); b.type = 'button'; b.textContent = txt; b.onclick = fn; return b; }
  function add(m) {
    if (empty) { empty.remove(); empty = null; }
    if (m.day !== lastDay) { lastDay = m.day; var d = document.createElement('div'); d.className = 'gc-day'; d.textContent = m.day; list.appendChild(d); }
    var row = document.createElement('div'); row.className = 'gc-m' + (m.mine ? ' me' : ''); row.dataset.id = m.id; row.dataset.uid = m.uid;
    if (!m.mine && muted.indexOf(m.uid) > -1) row.hidden = true;
    var av = document.createElement('span'); av.className = 'gc-av' + (m.staff ? ' st' : ''); av.textContent = (m.name || '?').charAt(0).toUpperCase();
    var b = document.createElement('div'); b.className = 'gc-b';
    var h = document.createElement('div'); h.className = 'gc-h';
    var n = document.createElement('strong'); n.textContent = m.name; var t = document.createElement('small'); t.textContent = m.time;
    h.appendChild(n); if (m.staff) { var s = document.createElement('em'); s.textContent = 'Admin'; h.appendChild(s); } h.appendChild(t); b.appendChild(h);
    if (m.reply) { var q = document.createElement('div'); q.className = 'gc-quote'; var qn = document.createElement('b'); qn.textContent = m.reply.name; var qt = document.createElement('span'); qt.textContent = m.reply.body; q.appendChild(qn); q.appendChild(qt); b.appendChild(q); }
    if (m.kind === 'i' && m.url) { var a = document.createElement('a'); a.href = m.url; a.target = '_blank'; a.rel = 'noopener'; a.className = 'gc-img'; var im = document.createElement('img'); im.src = m.url; im.alt = 'Picture'; im.loading = 'lazy'; im.onload = function () { if (nearBottom()) list.scrollTop = list.scrollHeight; }; a.appendChild(im); b.appendChild(a); }
    if (m.kind === 'f' && m.url) { var f = document.createElement('a'); f.href = m.url; f.target = '_blank'; f.rel = 'noopener'; f.className = 'gc-doc';
      var ic = document.createElement('span'); ic.className = 'gc-doc-i'; ic.textContent = (m.name_file.split('.').pop() || 'file').slice(0, 4).toUpperCase();
      var tx = document.createElement('span'); var fn = document.createElement('b'); fn.textContent = m.name_file; var fs = document.createElement('small'); fs.textContent = fmt(m.size) + ' · tap to open';
      tx.appendChild(fn); tx.appendChild(fs); f.appendChild(ic); f.appendChild(tx); b.appendChild(f); }
    if (m.body) { var p = document.createElement('p'); p.textContent = m.body; b.appendChild(p); }
    if (inp) {
      var tools = document.createElement('div'); tools.className = 'gc-tools';
      tools.appendChild(btn('Reply', function () { setReply(m); }));
      if (!m.mine) { tools.appendChild(btn('Report', function () { if (confirm('Report this message to the moderators?')) post({ action: 'report', id: m.id }, function (j) { alert(j.ok ? (j.message || 'Reported.') : (j.error || 'Could not report.')); }); }));
        tools.appendChild(btn('Mute', function () { if (confirm('Hide messages from this person on this device?')) { if (muted.indexOf(m.uid) < 0) muted.push(m.uid); saveMute(); [].forEach.call(list.querySelectorAll('.gc-m'), function (r) { if (+r.dataset.uid === m.uid && !r.classList.contains('me')) r.hidden = true; }); } })); }
      if (isMod) tools.appendChild(btn('Delete', function () { post({ action: 'delete', id: m.id }, function (j) { if (j.ok) row.remove(); }); }));
      b.appendChild(tools);
    }
    row.appendChild(av); row.appendChild(b); list.appendChild(row);
    if (m.id > last) last = m.id;
  }
  function setReply(m) { if (!rbar) return; replyTo = m.id; rname.textContent = 'Replying to ' + m.name + ': ' + (m.body || (m.kind === 'i' ? 'Photo' : 'File')).slice(0, 60); rbar.hidden = false; inp.focus(); }
  function clearReply() { replyTo = 0; if (rbar) rbar.hidden = true; }
  if (rx) rx.addEventListener('click', clearReply);
  function poll() {
    if (busy || document.hidden) { schedule(); return; }
    busy = true;
    fetch(api + '?room=' + room + '&after=' + last, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      busy = false;
      if (!j.ok) { if (empty) empty.textContent = j.error || 'Chat is unavailable.'; if (!j.join) schedule(); return; }
      isMod = isMod || !!j.mod;
      var stick = last === 0 || nearBottom();
      j.messages.forEach(add);
      if (empty && !j.messages.length) empty.textContent = 'No messages yet. Say hello!';
      if (act) act.textContent = j.online;
      if (typingEl) { if (j.typing && j.typing.length) { typingEl.textContent = j.typing.join(', ') + (j.typing.length > 1 ? ' are' : ' is') + ' typing…'; typingEl.hidden = false; } else typingEl.hidden = true; }
      if (stick) list.scrollTop = list.scrollHeight;
      schedule();
    }).catch(function () { busy = false; schedule(); });
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(poll, 3000); }
  function showErr(m) { if (err) { err.textContent = m; err.hidden = !m; } }

  if (inp) inp.addEventListener('input', function () { var now = Date.now(); if (inp.value && now - lastTyping > 2500) { lastTyping = now; post({ action: 'typing' }); } });

  if (emo && ep) {
    var E = '😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😎 🤩 🥳 😏 😒 😔 😢 😭 😤 😡 🤬 😱 😴 🤔 🙄 😬 🤗 🤝 👍 👎 👏 🙌 🙏 💪 👀 🔥 💯 ✅ ❌ ⚽ 🏆 🥇 🎯 💰 🍀 ❤️ 💔 🎉 🚀 😈 🤑'.split(' ');
    E.forEach(function (e) { var b = document.createElement('button'); b.type = 'button'; b.textContent = e; b.addEventListener('click', function () {
      var s = inp.selectionStart == null ? inp.value.length : inp.selectionStart, en = inp.selectionEnd == null ? s : inp.selectionEnd;
      inp.value = inp.value.slice(0, s) + e + inp.value.slice(en); inp.focus(); var pos = s + e.length; try { inp.setSelectionRange(pos, pos); } catch (x) {} }); ep.appendChild(b); });
    emo.addEventListener('click', function (ev) { ev.stopPropagation(); ep.hidden = !ep.hidden; });
    document.addEventListener('click', function (ev) { if (!ep.hidden && !ep.contains(ev.target) && ev.target !== emo) ep.hidden = true; });
  }

  function shrink(file, cb) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1200000 || !window.URL || !HTMLCanvasElement.prototype.toBlob) { cb(file); return; }
    var img = new Image(); img.onload = function () {
      var sc = Math.min(1, 1600 / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = Math.round(img.width * sc); c.height = Math.round(img.height * sc);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (bl) { URL.revokeObjectURL(img.src); if (!bl) { cb(file); return; } try { cb(new File([bl], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })); } catch (x) { cb(file); } }, 'image/jpeg', 0.85);
    }; img.onerror = function () { cb(file); }; img.src = URL.createObjectURL(file);
  }
  function clearPick() { picked = null; if (fileIn) fileIn.value = ''; if (pre) pre.hidden = true; if (preI) { preI.textContent = ''; preI.style.backgroundImage = ''; } }
  if (fileIn) fileIn.addEventListener('change', function () {
    var f = fileIn.files && fileIn.files[0]; if (!f) return; showErr('');
    shrink(f, function (g) {
      picked = g; pre.hidden = false; preN.textContent = g.name + ' (' + fmt(g.size) + ')';
      if (/^image\//.test(g.type)) { preI.textContent = ''; preI.style.backgroundImage = 'url(' + URL.createObjectURL(g) + ')'; }
      else { preI.style.backgroundImage = ''; preI.textContent = (g.name.split('.').pop() || 'file').slice(0, 4).toUpperCase(); }
    });
  });
  if (preX) preX.addEventListener('click', clearPick);

  if (form) form.addEventListener('submit', function (ev) {
    ev.preventDefault(); var v = inp.value.trim(); if (!v && !picked) return;
    var fd = new FormData(); fd.append('csrf', csrf); fd.append('room', room); fd.append('action', 'send'); fd.append('body', v); if (replyTo) fd.append('reply_to', replyTo); if (picked) fd.append('file', picked, picked.name);
    showErr(''); inp.disabled = true; form.classList.add('busy');
    fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (txt) {
      var j; try { j = JSON.parse(txt); } catch (x) { j = { ok: false, error: 'The file is too big for this server. Try a smaller one.' }; }
      inp.disabled = false; form.classList.remove('busy');
      if (j.ok) { inp.value = ''; clearPick(); clearReply(); busy = false; poll(); } else { showErr(j.error || 'Could not send.'); }
      inp.focus();
    }).catch(function () { inp.disabled = false; form.classList.remove('busy'); showErr('Network problem. Try again.'); });
  });
  poll();
})();

/* open a notification straight at the message to reply to (#gm123) */
(function () {
  var h = location.hash.match(/^#gm(\d+)$/); if (!h) return; var n = 0;
  var t = setInterval(function () {
    var el = document.querySelector('.gc-m[data-id="' + h[1] + '"]');
    if (el) { el.scrollIntoView({ block: 'center' }); el.classList.add('gc-hl'); clearInterval(t); } else if (++n > 40) clearInterval(t);
  }, 300);
})();
