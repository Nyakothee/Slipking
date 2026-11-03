/* SlipKing: turn browser push alerts on/off for this device. Used on account/alerts.php. */
(function () {
  'use strict';
  var box = document.getElementById('push-box');
  if (!box) { return; }
  var btn = document.getElementById('push-toggle');
  var msg = document.getElementById('push-msg');
  var key = box.getAttribute('data-key');
  var api = box.getAttribute('data-url');
  var csrf = box.getAttribute('data-csrf');
  function say(t, bad) { msg.textContent = t; msg.className = 'push-msg' + (bad ? ' bad' : ''); }
  function b64uToBytes(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) { s += '='; }
    var raw = atob(s), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
    return out;
  }
  function post(action, sub) {
    var j = sub.toJSON ? sub.toJSON() : {};
    var body = new URLSearchParams();
    body.set('csrf', csrf); body.set('action', action); body.set('endpoint', sub.endpoint);
    if (j.keys) { body.set('p256dh', j.keys.p256dh); body.set('auth', j.keys.auth); }
    return fetch(api, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
  }
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    btn.disabled = true; say('This browser does not support push alerts. On iPhone, add the site to your Home Screen first.', true); return;
  }
  if (!key) { btn.disabled = true; say('Push alerts are not set up on this site yet.', true); return; }
  if (location.protocol !== 'https:' && location.hostname !== 'localhost') { btn.disabled = true; say('Push alerts need a secure (https) site.', true); return; }

  function refresh() {
    navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
      btn.textContent = sub ? 'Turn off alerts on this device' : 'Turn on alerts on this device';
      btn.setAttribute('data-on', sub ? '1' : '0');
      if (Notification.permission === 'denied') { say('Notifications are blocked in your browser settings for this site.', true); }
    });
  }
  btn.addEventListener('click', function () {
    btn.disabled = true; say('Working...', false);
    navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (sub) {
          return post('unsubscribe', sub).then(function () { return sub.unsubscribe(); }).then(function () { say('Alerts are off on this device.', false); });
        }
        return Notification.requestPermission().then(function (p) {
          if (p !== 'granted') { throw new Error('Permission was not given.'); }
          return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(key) });
        }).then(function (s) { return post('subscribe', s); }).then(function (r) {
          if (!r.ok) { throw new Error(r.error || 'Could not save.'); }
          say('Done. You will get alerts on this device.', false);
        });
      });
    }).catch(function (e) { say(e && e.message ? e.message : 'Could not change alerts.', true); })
      .then(function () { btn.disabled = false; refresh(); });
  });
  refresh();
})();
