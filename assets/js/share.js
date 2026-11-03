/* Share buttons: opens the phone's own share sheet (WhatsApp, Telegram, Facebook, ...). Where the browser has none, shows a small menu of apps. */
(function () {
  var menu = null;
  function close() { if (menu) { menu.remove(); menu = null; } }
  function abs(u) { try { return new URL(u, location.href).href; } catch (e) { return u; } }
  function mk(href, label, cls) { var a = document.createElement('a'); a.href = href; a.target = '_blank'; a.rel = 'noopener'; a.className = cls; a.textContent = label; return a; }
  function open(btn, url, title) {
    close();
    menu = document.createElement('div'); menu.className = 'sk-share'; menu.setAttribute('role', 'dialog'); menu.setAttribute('aria-label', 'Share');
    var u = encodeURIComponent(url), t = encodeURIComponent(title + ' ' + url);
    var h = document.createElement('div'); h.className = 'sk-share-h'; h.textContent = 'Share via'; menu.appendChild(h);
    var g = document.createElement('div'); g.className = 'sk-share-g';
    [['WhatsApp', 'https://wa.me/?text=' + t, 'wa'], ['Telegram', 'https://t.me/share/url?url=' + u + '&text=' + encodeURIComponent(title), 'tg'], ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' + u, 'fb'],
     ['X', 'https://twitter.com/intent/tweet?url=' + u + '&text=' + encodeURIComponent(title), 'x'], ['Email', 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + t, 'em'], ['SMS', 'sms:?&body=' + t, 'sm']].forEach(function (s) {
      var a = mk(s[1], '', 'sk-sh ' + s[2]); var i = document.createElement('i'); i.textContent = s[0].charAt(0); var sp = document.createElement('span'); sp.textContent = s[0]; a.appendChild(i); a.appendChild(sp); g.appendChild(a); });
    menu.appendChild(g);
    var c = document.createElement('button'); c.type = 'button'; c.className = 'sk-share-c'; c.textContent = 'Copy link';
    c.onclick = function () { if (navigator.clipboard) navigator.clipboard.writeText(url); c.textContent = 'Link copied'; setTimeout(close, 900); };
    menu.appendChild(c);
    var x = document.createElement('button'); x.type = 'button'; x.className = 'sk-share-x'; x.textContent = 'Close'; x.onclick = close; menu.appendChild(x);
    document.body.appendChild(menu);
    var back = document.createElement('div'); back.className = 'sk-share-bg'; back.onclick = close; menu.parentNode.insertBefore(back, menu); menu._bg = back;
    var rm = close; close = function () { if (menu) { if (menu._bg) menu._bg.remove(); menu.remove(); menu = null; } };
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-share]') : null; if (!b) return;
    ev.preventDefault();
    var url = abs(b.getAttribute('data-url') || location.href), title = b.getAttribute('data-title') || document.title;
    if (navigator.share) { navigator.share({ title: title, text: title, url: url }).catch(function () {}); return; }
    open(b, url, title);
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
