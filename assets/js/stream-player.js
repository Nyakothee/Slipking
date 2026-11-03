/* Opens game links in a player on top of the current page. Links with data-play open the signed watch.php (bare mode) in a frame. */
(function () {
  var box, frame, ttl;
  function build() {
    box = document.createElement('div');
    box.className = 'sp-box'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.hidden = true;
    box.innerHTML = '<div class="sp-in"><div class="sp-bar"><strong class="sp-t"></strong><a class="sp-full" href="#">Chat &amp; poll</a><button type="button" class="sp-x" aria-label="Close player">&times;</button></div><div class="sp-v"><iframe class="sp-f" title="Game player" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen></iframe></div></div>';
    document.body.appendChild(box);
    frame = box.querySelector('.sp-f'); ttl = box.querySelector('.sp-t');
    box.addEventListener('click', function (e) { if (e.target === box || e.target.classList.contains('sp-x')) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) close(); });
  }
  function open(src, title, full) {
    if (!box) build();
    var fl = box.querySelector('.sp-full'); fl.href = full || '#'; fl.style.display = full ? '' : 'none';
    ttl.textContent = title || 'Watch live';
    frame.src = src; box.hidden = false; document.documentElement.classList.add('sp-open');
    box.querySelector('.sp-x').focus();
  }
  function close() { frame.src = 'about:blank'; box.hidden = true; document.documentElement.classList.remove('sp-open'); }
  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-play]') : null;
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) return;
    e.preventDefault(); open(a.getAttribute('data-play'), a.getAttribute('data-title'), /[?&]i=\d+/.test(a.href) ? a.href : '');
  });
  /* "starts in" labels on watch buttons */
  function cds() {
    var els = document.querySelectorAll('.wl-cd[data-ts]'), now = Date.now() / 1000;
    for (var i = 0; i < els.length; i++) {
      var s = parseInt(els[i].getAttribute('data-ts'), 10) - now;
      if (s <= 0) { els[i].textContent = ''; continue; }
      var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60);
      els[i].textContent = '(starts in ' + (h >= 24 ? Math.floor(h / 24) + 'd ' + (h % 24) + 'h' : h > 0 ? h + 'h ' + m + 'm' : m + 'm') + ')';
    }
  }
  cds(); setInterval(cds, 30000);
})();
