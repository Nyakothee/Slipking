/* ---- Payment method buttons: show one panel at a time ---- */
(function () {
  var wrap = document.getElementById('paypanels');
  var btns = document.querySelectorAll('.paybtn');
  if (!wrap || !btns.length) return;
  wrap.className += ' is-js';
  function show(slug, scroll) {
    Array.prototype.forEach.call(btns, function (b) {
      var on = b.getAttribute('data-pay') === slug;
      b.className = b.className.replace(/\s*\bon\b/g, '') + (on ? ' on' : '');
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    Array.prototype.forEach.call(wrap.querySelectorAll('.paypanel'), function (p) {
      p.className = p.className.replace(/\s*\bon\b/g, '') + (p.id === 'panel-' + slug ? ' on' : '');
    });
    document.dispatchEvent(new CustomEvent('pay:show', { detail: slug }));
    if (scroll && window.innerWidth < 800) { var el = document.getElementById('panel-' + slug); if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  }
  Array.prototype.forEach.call(btns, function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-pay'), true); }); });
  show(wrap.getAttribute('data-first'), false);
})();

(function () {
  var form = document.getElementById('mpesaForm');
  if (!form) return;
  var box = document.getElementById('mpesaMsg');
  var btn = form.querySelector('button[type=submit]');
  var timer = null;
  function T(s) { return (window.SK_T && window.SK_T[s]) || s; }

  function say(text, kind) {
    box.textContent = T(text);
    box.className = 'msg show ' + (kind || 'info');
  }
  function stop(enable) {
    if (timer) { clearInterval(timer); timer = null; }
    if (enable) btn.disabled = false;
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    btn.disabled = true;
    say('Sending the request to your phone...', 'info');

    fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { say(d.message || 'Something went wrong.', 'bad'); btn.disabled = false; return; }
        say(d.message + ' ' + T('Waiting for confirmation...'), 'info');
        poll(d.ref);
      })
      .catch(function () { say('Could not connect. Check your internet and try again.', 'bad'); btn.disabled = false; });
  });

  function poll(ref) {
    var tries = 0;
    timer = setInterval(function () {
      tries++;
      fetch(form.dataset.status + '?ref=' + encodeURIComponent(ref), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.status === 'paid') {
            stop(false);
            say(d.message, 'ok');
            window.location.href = form.dataset.success + '?ref=' + encodeURIComponent(ref);
          } else if (d.status === 'failed' || d.status === 'cancelled') {
            stop(true);
            say(d.message, 'bad');
          } else if (tries >= 40) {
            stop(true);
            say('We have not received confirmation yet. If you entered your PIN, your plan will switch on by itself within a few minutes. You can check the Payments page in your account.', 'info');
          }
        })
        .catch(function () { /* keep trying */ });
    }, 3000);
  }
})();

/* ---- PayPal buttons ---- */
(function () {
  var holder = document.getElementById('paypal-buttons');
  var box = document.getElementById('paypalMsg');
  if (!holder) return;
  function boot() {

  function T(s) { return (window.SK_T && window.SK_T[s]) || s; }
  function say(text, kind) {
    if (!box) return;
    box.textContent = T(text);
    box.className = 'msg show ' + (kind || 'info');
  }
  if (!window.paypal || !window.paypal.Buttons) {
    say('PayPal could not load. Check your connection and refresh the page.', 'bad');
    return;
  }

  function post(url, fields) {
    var fd = new FormData();
    fd.append('csrf', holder.dataset.csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  window.paypal.Buttons({
    style: { layout: 'vertical', shape: 'rect', label: 'pay' },
    createOrder: function () {
      say('Opening PayPal...', 'info');
      return post(holder.dataset.create, { plan: holder.dataset.plan, coupon: holder.dataset.coupon || '' }).then(function (d) {
        if (!d.ok) { throw new Error(d.message || 'Could not start the payment.'); }
        return d.orderID;
      });
    },
    onApprove: function (data) {
      say('Confirming your payment...', 'info');
      return post(holder.dataset.capture, { orderID: data.orderID }).then(function (d) {
        if (d.ok) {
          say('Payment received. Your VIP plan is now active.', 'ok');
          window.location.href = holder.dataset.success + '?ref=' + encodeURIComponent(d.ref);
        } else {
          say(d.message || 'The payment could not be confirmed.', 'bad');
        }
      });
    },
    onCancel: function () { say('Payment cancelled. You have not been charged.', 'info'); },
    onError: function (err) { say((err && err.message) ? err.message : 'PayPal ran into a problem. Please try again.', 'bad'); }
  }).render('#paypal-buttons');
  }
  if (holder.offsetParent) { boot(); }
  else { document.addEventListener('pay:show', function h(e) { if (e.detail === 'paypal') { document.removeEventListener('pay:show', h); boot(); } }); }
})();


/* ---- Paystack (naira) and Razorpay (rupees) ---- */
(function () {
  function T(s) { return (window.SK_T && window.SK_T[s]) || s; }
  function post(url, box, fields) {
    var fd = new FormData();
    fd.append('csrf', box.dataset.csrf);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function note(el, text, kind) { if (el) { el.textContent = T(text); el.className = 'msg show ' + (kind || 'info'); } }

  var pBox = document.getElementById('paystack-box');
  if (pBox) {
    var pBtn = document.getElementById('paystack-btn'), pMsg = document.getElementById('paystackMsg');
    pBtn.addEventListener('click', function () {
      pBtn.disabled = true;
      note(pMsg, 'Starting the payment...', 'info');
      post(pBox.dataset.init, pBox, { plan: pBox.dataset.plan, coupon: pBox.dataset.coupon || '' })
        .then(function (d) {
          if (d.ok && d.url) { note(pMsg, 'Redirecting to Paystack...', 'info'); window.location.href = d.url; }
          else { note(pMsg, d.message || 'Something went wrong.', 'bad'); pBtn.disabled = false; }
        })
        .catch(function () { note(pMsg, 'Could not connect. Check your internet and try again.', 'bad'); pBtn.disabled = false; });
    });
  }

  var rBox = document.getElementById('razorpay-box');
  if (rBox) {
    var rBtn = document.getElementById('razorpay-btn'), rMsg = document.getElementById('razorpayMsg');
    rBtn.addEventListener('click', function () {
      if (!window.Razorpay) { note(rMsg, 'Razorpay could not load. Check your connection and refresh the page.', 'bad'); return; }
      rBtn.disabled = true;
      note(rMsg, 'Starting the payment...', 'info');
      post(rBox.dataset.create, rBox, { plan: rBox.dataset.plan, coupon: rBox.dataset.coupon || '' })
        .then(function (d) {
          if (!d.ok) { note(rMsg, d.message || 'Something went wrong.', 'bad'); rBtn.disabled = false; return; }
          var rz = new window.Razorpay({
            key: d.key, amount: d.amount, currency: 'INR', name: d.name, description: d.description, order_id: d.order_id,
            prefill: { email: d.email, contact: d.contact },
            handler: function (resp) {
              note(rMsg, 'Verifying your payment...', 'info');
              post(rBox.dataset.verify, rBox, resp).then(function (v) {
                if (v.ok) { window.location.href = rBox.dataset.success + '?ref=' + encodeURIComponent(v.ref); }
                else { note(rMsg, v.message || 'The payment could not be confirmed.', 'bad'); rBtn.disabled = false; }
              }).catch(function () { note(rMsg, 'Could not connect. Check your internet and try again.', 'bad'); rBtn.disabled = false; });
            },
            modal: { ondismiss: function () { note(rMsg, 'Payment window closed. You have not been charged.', 'info'); rBtn.disabled = false; } }
          });
          rz.open();
        })
        .catch(function () { note(rMsg, 'Could not connect. Check your internet and try again.', 'bad'); rBtn.disabled = false; });
    });
  }
})();


/* ---- Stripe (cards), MTN MoMo and Airtel Money ---- */
(function () {
  function T(x) { return (window.SK_T && window.SK_T[x]) || x; }
  function note(el, text, kind) { if (el) { el.textContent = T(text); el.className = el.className.replace(/\s*\b(show|ok|bad|info)\b/g, '') + ' msg show ' + (kind || 'info'); } }

  /* Stripe: pick a currency, then go to Stripe's secure page */
  var sBox = document.getElementById('stripe-box');
  if (sBox) {
    var sBtn = document.getElementById('stripe-btn'), sMsg = document.getElementById('stripeMsg');
    var radios = sBox.querySelectorAll('input[name=stripecur]');
    function cur() { var c = sBox.querySelector('input[name=stripecur]:checked'); return c ? c.value : 'USD'; }
    Array.prototype.forEach.call(radios, function (r) {
      r.addEventListener('change', function () { sBtn.querySelector('span:last-child').textContent = r.getAttribute('data-label'); });
    });
    sBtn.addEventListener('click', function () {
      sBtn.disabled = true;
      note(sMsg, 'Starting the payment...', 'info');
      var fd = new FormData();
      fd.append('csrf', sBox.dataset.csrf); fd.append('plan', sBox.dataset.plan); fd.append('coupon', sBox.dataset.coupon || ''); fd.append('currency', cur());
      fetch(sBox.dataset.init, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok && d.url) { note(sMsg, 'Redirecting to Stripe...', 'info'); window.location.href = d.url; }
          else { note(sMsg, d.message || 'Something went wrong.', 'bad'); sBtn.disabled = false; }
        })
        .catch(function () { note(sMsg, 'Could not connect. Check your internet and try again.', 'bad'); sBtn.disabled = false; });
    });
  }

  /* MTN MoMo and Airtel Money: send the prompt, then wait for approval */
  Array.prototype.forEach.call(document.querySelectorAll('.walletForm'), function (form) {
    var msg = form.querySelector('.walletMsg'), btn = form.querySelector('button[type=submit]'), timer = null;
    function stop(enable) { if (timer) { clearInterval(timer); timer = null; } if (enable) btn.disabled = false; }
    function poll(ref) {
      var tries = 0;
      timer = setInterval(function () {
        tries++;
        fetch(form.dataset.status + '?ref=' + encodeURIComponent(ref), { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.status === 'paid') { stop(false); note(msg, d.message, 'ok'); window.location.href = form.dataset.success + '?ref=' + encodeURIComponent(ref); }
            else if (d.status === 'failed' || d.status === 'cancelled') { stop(true); note(msg, d.message, 'bad'); }
            else if (tries >= 36) { stop(true); note(msg, 'We have not received confirmation yet. If you approved the prompt, your plan will switch on by itself within a few minutes. You can check the Payments page in your account.', 'info'); }
          })
          .catch(function () { /* keep trying */ });
      }, 4000);
    }
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      btn.disabled = true;
      note(msg, 'Sending the request to your phone...', 'info');
      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { note(msg, d.message || 'Something went wrong.', 'bad'); btn.disabled = false; return; }
          note(msg, d.message + ' ' + T('Waiting for confirmation...'), 'info');
          poll(d.ref);
        })
        .catch(function () { note(msg, 'Could not connect. Check your internet and try again.', 'bad'); btn.disabled = false; });
    });
  });
})();
