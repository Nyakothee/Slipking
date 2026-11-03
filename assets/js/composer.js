/* Post box helpers: emoji picker and picture preview. Works on every form that contains .cmp-tools. */
(function () {
  var EMOJI = ['😀','😁','😂','🤣','😊','😍','😎','🤩','🥳','😅','😉','🙂','🤔','😬','😢','😭','😡','🤬','🙄','😴','🤯','🥶','🥵','😱',
    '👍','👎','👏','🙌','💪','🙏','👀','🔥','💯','✅','❌','⚠️','⭐','🏆','🥇','🥈','🥉','🎯','💰','🤑','📈','📉','🍀','🎉',
    '⚽','🥅','🧤','🟨','🟥','🏟️','📣','🚩','🔴','🔵','⚪','⚫','🟢','🟡','❤️','💔','🤝','🤞','😤','🫡','🐐','👑','🦁','🐺'];
  var panel = null, target = null;
  function build() {
    panel = document.createElement('div');
    panel.className = 'cmp-ep'; panel.hidden = true; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Emoji');
    EMOJI.forEach(function (e) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = e; b.setAttribute('aria-label', e);
      panel.appendChild(b);
    });
    document.body.appendChild(panel);
    panel.addEventListener('click', function (ev) {
      var b = ev.target.closest('button'); if (!b || !target) return;
      var ta = target, s = ta.selectionStart || 0, en = ta.selectionEnd || 0, v = ta.value;
      var max = parseInt(ta.getAttribute('maxlength') || '0', 10);
      if (max && v.length + b.textContent.length > max) return;
      ta.value = v.slice(0, s) + b.textContent + v.slice(en);
      var pos = s + b.textContent.length; ta.focus(); try { ta.setSelectionRange(pos, pos); } catch (x) {}
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    });
  }
  function closePanel() { if (panel) panel.hidden = true; }
  document.addEventListener('click', function (ev) {
    var eb = ev.target.closest('.cmp-eb');
    if (eb) {
      var form = eb.closest('form'); var ta = form && form.querySelector('textarea[name=body]');
      if (!ta) return;
      if (!panel) build();
      if (!panel.hidden && target === ta) { closePanel(); return; }
      target = ta; panel.hidden = false;
      var r = eb.getBoundingClientRect();
      var w = Math.min(300, window.innerWidth - 16);
      panel.style.width = w + 'px';
      panel.style.left = Math.max(8, Math.min(window.scrollX + r.left, window.scrollX + window.innerWidth - w - 8)) + 'px';
      panel.style.top = (window.scrollY + r.bottom + 6) + 'px';
      return;
    }
    if (panel && !panel.hidden && !ev.target.closest('.cmp-ep')) closePanel();
    var rm = ev.target.closest('.cmp-rm');
    if (rm) {
      var f = rm.closest('form'), inp = f.querySelector('input[type=file]');
      if (inp) inp.value = '';
      var pv = f.querySelector('.cmp-pv'); if (pv) { pv.hidden = true; pv.querySelector('img').removeAttribute('src'); }
      var fn = f.querySelector('.cmp-fn'); if (fn) fn.hidden = true;
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePanel(); });
  // Pictures are shrunk in the browser first (max 1400 px, JPEG). Phone photos are often 3-8 MB, more than many hosts accept,
  // so the upload would fail; a shrunk picture is a few hundred KB and always goes through.
  function shrink(file) {
    return new Promise(function (resolve) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.HTMLCanvasElement) { resolve(file); return; }
      function draw(src, w, h) {
        var sc = Math.min(1, 1400 / Math.max(w, h));
        if (sc === 1 && file.size < 700 * 1024 && file.type === 'image/jpeg') { resolve(file); return; }
        var c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w * sc)); c.height = Math.max(1, Math.round(h * sc));
        var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.drawImage(src, 0, 0, c.width, c.height);
        c.toBlob(function (b) {
          if (!b || (b.size >= file.size && file.type === 'image/jpeg')) { resolve(file); return; }
          try { resolve(new File([b], (file.name || 'picture').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' })); } catch (e) { resolve(file); }
        }, 'image/jpeg', 0.85);
      }
      if (window.createImageBitmap) {
        createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bm) { draw(bm, bm.width, bm.height); }, function () { viaImg(); });
      } else { viaImg(); }
      function viaImg() {
        var im = new Image(), u = URL.createObjectURL(file);
        im.onload = function () { draw(im, im.naturalWidth, im.naturalHeight); URL.revokeObjectURL(u); };
        im.onerror = function () { resolve(file); };
        im.src = u;
      }
    });
  }
  document.addEventListener('change', function (ev) {
    var inp = ev.target; if (!inp.matches || !inp.matches('.cmp-tools input[type=file]')) return;
    var f = inp.form, x = inp.files && inp.files[0]; if (!f || !x) return;
    var fn = f.querySelector('.cmp-fn'); if (fn) { fn.textContent = 'Preparing picture...'; fn.hidden = false; }
    f._busy = shrink(x).then(function (out) {
      if (out !== x && window.DataTransfer) { try { var dt = new DataTransfer(); dt.items.add(out); inp.files = dt.files; } catch (e) {} }
      var cur = inp.files && inp.files[0] ? inp.files[0] : out;
      if (cur.size > 3.5 * 1024 * 1024) { alert('That picture is still too big. Please choose a smaller one.'); inp.value = ''; if (fn) fn.hidden = true; return; }
      var pv = f.querySelector('.cmp-pv'), img = pv && pv.querySelector('img');
      if (img) { try { img.src = URL.createObjectURL(cur); pv.hidden = false; } catch (e) {} }
      if (fn) { fn.textContent = cur.name + ' (' + Math.max(1, Math.round(cur.size / 1024)) + ' KB)'; fn.hidden = false; }
    }).then(function () { f._busy = null; });
  });
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (f && f._busy) { ev.preventDefault(); f._busy.then(function () { if (f.requestSubmit) f.requestSubmit(); else f.submit(); }); }
  }, true);
})();
