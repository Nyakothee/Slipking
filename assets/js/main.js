document.addEventListener('DOMContentLoaded', function () {
  var btn = document.querySelector('.menu');
  var nav = document.getElementById('nav');
  if (btn && nav) {
    btn.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  document.querySelectorAll('.flash').forEach(function (f) {
    setTimeout(function () { f.style.display = 'none'; }, 7000);
  });
});

/* nav drop-down arrows: tap to open on touch screens and the mobile menu */
(function () {
  document.addEventListener('click', function (e) {
    var t = e.target.closest('.navdd .ddt');
    var all = document.querySelectorAll('.navdd.open');
    if (t) {
      var dd = t.closest('.navdd'), was = dd.classList.contains('open');
      all.forEach(function (x) { x.classList.remove('open'); x.querySelector('.ddt').setAttribute('aria-expanded', 'false'); });
      if (!was) { dd.classList.add('open'); t.setAttribute('aria-expanded', 'true'); }
      return;
    }
    if (!e.target.closest('.navdd')) all.forEach(function (x) { x.classList.remove('open'); });
  });
})();
