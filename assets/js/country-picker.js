/* Country chooser with flags: turns <div class="cpick"><select>..</select></div> into a searchable list. Without JS the plain select still works. */
(function () {
  function init(box) {
    var sel = box.querySelector('select');
    if (!sel || box.getAttribute('data-ready')) return;
    box.setAttribute('data-ready', '1');
    var fl = (box.getAttribute('data-flags') || 'https://flagcdn.com/w40/|png').split('|');
    var base = fl[0], ext = fl[1] || 'png';
    function flag(iso) {
      if (!iso) return '<span class="cp-fl cp-none"></span>';
      return '<img class="cp-fl" alt="" width="24" height="18" loading="lazy" src="' + base + iso + '.' + ext + '" onerror="this.outerHTML=\'<span class=&quot;cp-fl cp-t&quot;>' + iso.toUpperCase() + '</span>\'">';
    }
    var opts = [].slice.call(sel.options);
    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'cp-btn'; btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-expanded', 'false');
    var pop = document.createElement('div'); pop.className = 'cp-pop'; pop.hidden = true;
    var q = document.createElement('input'); q.type = 'search'; q.className = 'cp-q'; q.placeholder = 'Search country'; q.setAttribute('aria-label', 'Search country'); q.autocomplete = 'off';
    var ul = document.createElement('ul'); ul.className = 'cp-list'; ul.setAttribute('role', 'listbox');
    opts.forEach(function (o, i) {
      var li = document.createElement('li'); li.setAttribute('role', 'option'); li.setAttribute('data-i', i);
      li.setAttribute('data-t', (o.text + ' ' + (o.getAttribute('data-dial') || '')).toLowerCase());
      li.innerHTML = flag(o.getAttribute('data-iso')) + '<span class="cp-n"></span><span class="cp-d"></span>';
      li.querySelector('.cp-n').textContent = o.text;
      li.querySelector('.cp-d').textContent = o.getAttribute('data-dial') || '';
      ul.appendChild(li);
    });
    pop.appendChild(q); pop.appendChild(ul);
    box.appendChild(btn); box.appendChild(pop);
    box.classList.add('cp-on');
    var cur = -1;
    function show() {
      var o = sel.options[sel.selectedIndex] || opts[0];
      btn.innerHTML = flag(o && o.getAttribute('data-iso')) + '<span class="cp-n"></span><span class="cp-car" aria-hidden="true"></span>';
      btn.querySelector('.cp-n').textContent = o ? o.text : '';
      [].forEach.call(ul.children, function (li) { li.classList.toggle('on', +li.getAttribute('data-i') === sel.selectedIndex); });
    }
    function visible() { return [].filter.call(ul.children, function (li) { return !li.hidden; }); }
    function focusItem(li) {
      [].forEach.call(ul.children, function (x) { x.classList.remove('hot'); });
      if (li) { li.classList.add('hot'); if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest' }); }
    }
    function open() {
      pop.hidden = false; btn.setAttribute('aria-expanded', 'true'); q.value = ''; filter();
      var on = ul.querySelector('li.on'); if (on) { on.scrollIntoView({ block: 'center' }); focusItem(on); }
      q.focus();
    }
    function close() { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
    function choose(li) {
      sel.selectedIndex = +li.getAttribute('data-i');
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      show(); close(); btn.focus();
    }
    function filter() {
      var t = q.value.trim().toLowerCase();
      [].forEach.call(ul.children, function (li) { li.hidden = t !== '' && li.getAttribute('data-t').indexOf(t) < 0; });
      var v = visible(); focusItem(v[0] || null);
    }
    btn.addEventListener('click', function () { pop.hidden ? open() : close(); });
    q.addEventListener('input', filter);
    ul.addEventListener('mousedown', function (e) { var li = e.target.closest('li'); if (li) { e.preventDefault(); choose(li); } });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { if (!pop.hidden) { close(); btn.focus(); e.preventDefault(); } return; }
      if (pop.hidden) { if (e.target === btn && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); open(); } return; }
      var v = visible(), h = ul.querySelector('li.hot'), i = v.indexOf(h);
      if (e.key === 'ArrowDown') { e.preventDefault(); focusItem(v[Math.min(v.length - 1, i + 1)]); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); focusItem(v[Math.max(0, i - 1)]); }
      else if (e.key === 'Enter') { e.preventDefault(); if (h) choose(h); }
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) close(); });
    sel.addEventListener('change', show);
    // a required select that is hidden cannot show the browser's message; keep it focusable-safe
    sel.setAttribute('tabindex', '-1');
    show();
  }
  function all() { [].forEach.call(document.querySelectorAll('.cpick'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
