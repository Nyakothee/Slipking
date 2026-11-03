/* Light/dark toggle. The choice is remembered in this browser only. */
(function () {
  'use strict';
  var root = document.documentElement;
  function stored() { try { return localStorage.getItem('sk_theme'); } catch (e) { return null; } }
  function save(v) { try { localStorage.setItem('sk_theme', v); } catch (e) { /* private mode: still works for this page */ } }
  function isDark() {
    var t = root.getAttribute('data-theme');
    if (t) { return t === 'dark'; }
    return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  }
  var s = stored();
  if (s === 'dark' || s === 'light') { root.setAttribute('data-theme', s); }
  function label(btn) {
    var d = isDark();
    btn.textContent = d ? '☀' : '☾';
    var name = d ? 'Switch to light mode' : 'Switch to dark mode';
    btn.setAttribute('aria-label', (window.SK_T && window.SK_T[name]) || name);
    btn.setAttribute('title', btn.getAttribute('aria-label'));
  }
  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('theme-toggle');
    if (!btn) { return; }
    label(btn);
    btn.addEventListener('click', function () {
      var next = isDark() ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      save(next);
      label(btn);
    });
  });
})();
