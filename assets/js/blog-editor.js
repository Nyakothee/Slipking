(function () {
  var ta = document.getElementById('body');
  var bar = document.getElementById('bl-bar');
  if (!ta || !bar) return;
  var msg = document.getElementById('bl-msg');
  var file = document.getElementById('bl-file');
  var csrf = bar.getAttribute('data-csrf');
  function say(t, bad) { msg.textContent = t; msg.style.color = bad ? 'var(--loss)' : 'var(--mute)'; }
  function insert(text) {
    var s = ta.selectionStart || 0, e = ta.selectionEnd || 0, v = ta.value;
    var pre = (s > 0 && v.charAt(s - 1) !== '\n') ? '\n' : '';
    var post = '\n';
    ta.value = v.slice(0, s) + pre + text + post + v.slice(e);
    var p = s + pre.length + text.length + post.length;
    ta.focus(); ta.setSelectionRange(p, p);
  }
  function wrap(a, b, ph) {
    var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || ph;
    ta.value = ta.value.slice(0, s) + a + sel + b + ta.value.slice(e);
    ta.focus(); ta.setSelectionRange(s + a.length, s + a.length + sel.length);
  }
  document.getElementById('bl-up').addEventListener('click', function () { file.click(); });
  file.addEventListener('change', function () {
    if (!file.files.length) return;
    var fd = new FormData(); fd.append('file', file.files[0]); fd.append('csrf', csrf);
    say('Uploading ' + file.files[0].name + '...');
    fetch(bar.getAttribute('data-upload'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        file.value = '';
        if (!d.ok) { say(d.message || 'Upload failed.', true); return; }
        insert(d.code.replace('|]', '|Write a caption here]'));
        say('Added. Edit the caption after the | sign (or delete it).');
      })
      .catch(function () { file.value = ''; say('Upload failed. Check your connection.', true); });
  });
  document.getElementById('bl-vid').addEventListener('click', function () {
    var u = prompt('Paste a YouTube or Vimeo link, or a direct https link to an .mp4 / .webm file:');
    if (u && /^https:\/\//i.test(u.trim())) insert('[video:' + u.trim() + '|]'); else if (u) say('The link must start with https://', true);
  });
  document.getElementById('bl-img').addEventListener('click', function () {
    var u = prompt('Paste the https:// link of a picture:');
    if (u && /^https:\/\//i.test(u.trim())) insert('[img:' + u.trim() + '|]'); else if (u) say('The link must start with https://', true);
  });
  document.getElementById('bl-h').addEventListener('click', function () { insert('## Heading'); });
  document.getElementById('bl-b').addEventListener('click', function () { wrap('**', '**', 'bold text'); });
  document.getElementById('bl-l').addEventListener('click', function () {
    var u = prompt('Link address (https://...):');
    if (u && /^https:\/\//i.test(u.trim())) wrap('[', '](' + u.trim() + ')', 'link text');
  });
  var pv = document.getElementById('bl-preview'), pb = document.getElementById('bl-pv');
  pb.addEventListener('click', function () {
    if (!pv.hidden) { pv.hidden = true; pb.textContent = 'Preview'; return; }
    var fd = new FormData(); fd.append('body', ta.value); fd.append('csrf', csrf);
    fetch(bar.getAttribute('data-preview'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) { pv.innerHTML = d.html || '<p><em>Nothing to show yet.</em></p>'; pv.hidden = false; pb.textContent = 'Hide preview'; } else say(d.message || 'Preview failed.', true); })
      .catch(function () { say('Preview failed.', true); });
  });
})();
