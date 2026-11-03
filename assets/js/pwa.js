/* SlipKing: registers the service worker, offers "Install app" and optional new-tip alerts (only while the site is open). */
(function () {
  'use strict';
  function TT(x) { return (window.SK_T && window.SK_T[x]) || x; }
  var script = document.currentScript;
  var BASE = script && script.src ? script.src.replace(/assets\/js\/pwa\.js.*$/, '') : '/';
  var KEY = 'sk_last_tip';
  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
  };

  var reg = null;
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(BASE + 'sw.js', { scope: BASE }).then(function (r) { reg = r; }).catch(function () {});
    });
  }

  // Install button: any element with data-pwa-install is hidden until the browser says the app can be installed.
  var deferred = null;
  var installEls = function () { return document.querySelectorAll('[data-pwa-install]'); };
  function showInstall(on) {
    Array.prototype.forEach.call(installEls(), function (el) { el.hidden = !on; });
  }
  document.addEventListener('DOMContentLoaded', function () {
    showInstall(false);
    Array.prototype.forEach.call(installEls(), function (el) {
      el.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (!deferred) { return; }
        deferred.prompt();
        deferred.userChoice.then(function () { deferred = null; showInstall(false); });
      });
    });
    setupAlerts();
  });
  window.addEventListener('beforeinstallprompt', function (ev) { ev.preventDefault(); deferred = ev; showInstall(true); });
  window.addEventListener('appinstalled', function () { deferred = null; showInstall(false); });

  // New-tip alerts: elements with data-pwa-alerts are toggle buttons. Checked every 2 minutes while a tab is open and visible.
  var timer = null;
  function alertsOn() { return store.get('sk_alerts') === '1' && 'Notification' in window && Notification.permission === 'granted'; }
  function poll() {
    if (!alertsOn() || document.hidden) { return; }
    var since = parseInt(store.get(KEY) || '0', 10) || 0;
    fetch(BASE + 'api/latest-tips.php?since=' + since, { credentials: 'omit' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { return; }
        if (since > 0) {                                  // first run only records the newest id, no flood of old alerts
          d.tips.slice().reverse().forEach(function (t) {
            var title = TT(t.vip ? 'New VIP tip' : 'New free tip');
            var opts = { body: t.match + ' (' + t.league + ')', data: { url: t.url }, icon: BASE + 'assets/img/icons/icon-192.png', tag: 'tip-' + t.id };
            if (reg && reg.showNotification) { reg.showNotification(title, opts); } else { try { new Notification(title, opts); } catch (e) {} }
          });
        }
        store.set(KEY, String(d.latest));
      }).catch(function () {});
  }
  function startPolling() {
    if (timer) { clearInterval(timer); timer = null; }
    if (alertsOn()) { poll(); timer = setInterval(poll, 120000); }
  }
  function paint() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-pwa-alerts]'), function (b) {
      b.hidden = !('Notification' in window) || Notification.permission === 'denied';
      b.setAttribute('aria-pressed', alertsOn() ? 'true' : 'false');
      var t = b.querySelector('.fb-t') || b;
      t.textContent = alertsOn() ? TT('Tip alerts: on') : TT('Turn on tip alerts');
    });
  }
  function setupAlerts() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-pwa-alerts]'), function (b) {
      b.addEventListener('click', function () {
        if (alertsOn()) { store.set('sk_alerts', '0'); paint(); startPolling(); return; }
        if (!('Notification' in window)) { return; }
        Notification.requestPermission().then(function (p) {
          if (p === 'granted') { store.set('sk_alerts', '1'); }
          paint(); startPolling();
        });
      });
    });
    paint();
    startPolling();
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
