/* Shows kickoff times in the visitor's own time zone when it differs from the site's.
   Markup: <time class="lt" datetime="2026-10-28T16:30:00+03:00">Today 16:30</time> (the text inside is the fallback). */
(function () {
  var els = document.querySelectorAll('time.lt[datetime]');
  if (!els.length) return;
  var D = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], M = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function p(n) { return n < 10 ? '0' + n : '' + n; }
  function day(d) { return d.getFullYear() * 10000 + d.getMonth() * 100 + d.getDate(); }
  var now = new Date(), tm = new Date(now.getTime() + 864e5), ys = new Date(now.getTime() - 864e5);
  for (var i = 0; i < els.length; i++) {
    var s = els[i].getAttribute('datetime'), d = new Date(s);
    if (isNaN(d.getTime())) continue;
    var m = /([+-])(\d\d):?(\d\d)$/.exec(s);
    var siteOffset = m ? (m[1] === '-' ? 1 : -1) * (parseInt(m[2], 10) * 60 + parseInt(m[3], 10)) : null;
    if (siteOffset === d.getTimezoneOffset()) continue;   // same zone as the site: keep the server text
    var hm = p(d.getHours()) + ':' + p(d.getMinutes()), k = day(d);
    els[i].textContent = k === day(now) ? 'Today ' + hm : k === day(tm) ? 'Tomorrow ' + hm : k === day(ys) ? 'Yesterday ' + hm : D[d.getDay()] + ' ' + d.getDate() + ' ' + M[d.getMonth()] + ', ' + hm;
    els[i].title = 'Shown in your time zone';
  }
})();
