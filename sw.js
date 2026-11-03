/*
 * SlipKing service worker.
 * - Static files (/assets/): cache-first, refreshed quietly in the background.
 * - Pages: network-first. If the network fails, show a saved copy of a few public pages, otherwise offline.html.
 * - Never touched (always go to the network): /admin/ /auth/ /account/ /payments/ /api/ /cron/, go.php, and every non-GET request.
 * Tips, results and VIP pages are NEVER stored, so a logged-out or expired member can not see old VIP picks from this cache.
 * To force everyone to refresh the saved files, change VERSION.
 */
var VERSION = 'v42';
var STATIC_CACHE = 'slipking-static-' + VERSION;
var PAGE_CACHE = 'slipking-pages-' + VERSION;
var BASE = self.registration.scope;                     // e.g. https://example.com/ (works in a sub-folder too)
var NEVER = ['admin/', 'auth/', 'account/', 'payments/', 'api/', 'cron/', 'go.php', 'app-download.php', 'sw.js', 'manifest.php'];
var OFFLINE_OK = ['about.php', 'terms.php', 'privacy.php', 'responsible-gambling.php'];   // public pages with no personal or VIP content
var PRECACHE = ['offline.html', 'assets/css/style.css', 'assets/css/group-a.css', 'assets/js/main.js', 'assets/js/pwa.js',
  'assets/img/icons/icon-192.png', 'assets/img/icons/icon-512.png'];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(STATIC_CACHE).then(function (cache) {
      // one missing file must not break the install
      return Promise.all(PRECACHE.map(function (p) {
        return cache.add(new Request(BASE + p, { cache: 'reload' })).catch(function () {});
      }));
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) {
        return k.indexOf('slipking-') === 0 && k !== STATIC_CACHE && k !== PAGE_CACHE;
      }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

function relPath(url) {
  return url.href.indexOf(BASE) === 0 ? url.href.slice(BASE.length).split('?')[0].split('#')[0] : null;
}

function isNever(rel) {
  for (var i = 0; i < NEVER.length; i++) {
    if (rel === NEVER[i] || (NEVER[i].slice(-1) === '/' && rel.indexOf(NEVER[i]) === 0)) { return true; }
  }
  return false;
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') { return; }                              // POST etc: straight to the network
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }              // fonts, images from other sites: browser handles them
  var rel = relPath(url);
  if (rel === null || isNever(rel)) { return; }

  if (rel.indexOf('assets/') === 0) {                               // cache-first for static files
    event.respondWith(
      caches.open(STATIC_CACHE).then(function (cache) {
        return cache.match(req).then(function (hit) {
          var refresh = fetch(req).then(function (res) {
            if (res && res.ok && res.type === 'basic') { cache.put(req, res.clone()); }
            return res;
          });
          if (hit) { refresh.catch(function () {}); return hit; }
          return refresh;
        });
      })
    );
    return;
  }

  if (req.mode === 'navigate') {                                    // network-first for pages
    event.respondWith(
      fetch(req).then(function (res) {
        if (res && res.ok && !res.redirected && OFFLINE_OK.indexOf(rel) !== -1 && url.search === '') {
          var copy = res.clone();
          caches.open(PAGE_CACHE).then(function (c) { c.put(req, copy); });
        }
        return res;
      }).catch(function () {
        return caches.open(PAGE_CACHE).then(function (c) { return c.match(req); }).then(function (hit) {
          return hit || caches.open(STATIC_CACHE).then(function (c) { return c.match(BASE + 'offline.html'); });
        }).then(function (r) {
          return r || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
        });
      })
    );
  }
});

// Web push (kickoff reminders, tip alerts, results, VIP expiry). The server sends an encrypted JSON {title, body, url}.
self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { try { data = { body: event.data.text() }; } catch (e2) {} }
  var title = data.title || 'SlipKing';
  event.waitUntil(self.registration.showNotification(title, {
    body: data.body || '',
    icon: BASE + 'assets/img/icons/icon-192.png',
    badge: BASE + 'assets/img/icons/icon-192.png',
    data: { url: data.url || '' },
    tag: data.url || undefined
  }));
});

// Tapping a tip alert (shown by pwa.js while the site is open) opens that tip.
self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = BASE;
  try {
    var u = new URL((event.notification.data && event.notification.data.url) || BASE, BASE);
    if (u.href.indexOf(BASE) === 0) { target = u.href; }            // only links inside this site
  } catch (e) {}
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (var i = 0; i < list.length; i++) {
        if (list[i].url === target && 'focus' in list[i]) { return list[i].focus(); }
      }
      return self.clients.openWindow(target);
    })
  );
});
