(function () {
  function get() { var m = document.cookie.match(/(?:^|; )sk_consent=([^;]*)/); return m ? decodeURIComponent(m[1]) : ''; }
  function set(v) { document.cookie = 'sk_consent=' + encodeURIComponent(v) + '; max-age=' + (86400 * 365) + '; path=/; samesite=lax'; }
  if (get() !== '' && !window.location.hash.match(/cookies/)) { return; }
  var b = document.createElement('div');
  b.className = 'ck-bar'; b.setAttribute('role', 'dialog'); b.setAttribute('aria-label', 'Cookies');
  b.innerHTML = '<p><b>Cookies.</b> We use essential cookies to keep you logged in. Optional ones count visits (analytics) and remember your language and currency (preferences).</p>' +
    '<div class="ck-opts" hidden><label><input type="checkbox" checked disabled> Essential</label> <label><input type="checkbox" id="ck-a"> Analytics</label> <label><input type="checkbox" id="ck-p" checked> Preferences</label></div>' +
    '<div class="ck-btns"><button type="button" class="btn gold small" data-k="all">Accept all</button> <button type="button" class="btn dark small" data-k="min">Essential only</button> <button type="button" class="btn ghost small" data-k="pick">Choose</button></div>';
  document.body.appendChild(b);
  b.addEventListener('click', function (e) {
    var k = e.target.getAttribute && e.target.getAttribute('data-k');
    if (!k) { return; }
    if (k === 'pick') {
      var o = b.querySelector('.ck-opts');
      if (o.hidden) { o.hidden = false; e.target.textContent = 'Save choices'; return; }
      set((b.querySelector('#ck-a').checked ? 'a' : '') + (b.querySelector('#ck-p').checked ? 'p' : '') + 'x');
    } else { set(k === 'all' ? 'apx' : 'x'); }
    b.parentNode.removeChild(b);
  });
})();
