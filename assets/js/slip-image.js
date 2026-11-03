/* SlipKing betslip image: draws the official-style slip on a canvas and downloads it as PNG. */
(function () {
  function rr(c, x, y, w, h, r) { c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r); c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath(); }
  function txt(c, t, x, y, size, color, weight, align) { c.font = (weight || '600') + ' ' + size + 'px "Segoe UI",Helvetica,Arial,sans-serif'; c.fillStyle = color; c.textAlign = align || 'left'; c.fillText(t, x, y); }
  function fit(c, t, max) { while (t.length > 3 && c.measureText(t).width > max) { t = t.slice(0, -2); } return t; }
  function crown(c, x, y, s, col) { c.fillStyle = col; c.beginPath(); c.moveTo(x, y + s * .8); c.lineTo(x - s * .1, y + s * .2); c.lineTo(x + s * .22, y + s * .5); c.lineTo(x + s * .5, y); c.lineTo(x + s * .78, y + s * .5); c.lineTo(x + s * 1.1, y + s * .2); c.lineTo(x + s, y + s * .8); c.closePath(); c.fill(); c.fillRect(x, y + s * .86, s, s * .16); }
  function when(k) { var m = /^(\d{4})-(\d{2})-(\d{2})[ T]?(\d{2}:\d{2})?/.exec(k || ''); if (!m) { return [k || '', '']; } var d = new Date(+m[1], +m[2] - 1, +m[3]); return [d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }), m[4] || '']; }
  window.slipImage = function (data, logoUrl, host) {
    var W = 1080, rowH = 118, items = data.items, H = 1130 + items.length * rowH;
    var cv = document.createElement('canvas'); cv.width = W; cv.height = H; var c = cv.getContext('2d');
    var navy = '#0b1a33', gold = '#f0a91c', green = '#0d3b2c', ink = '#10241b', mute = '#64766e';
    c.fillStyle = '#eef2ef'; c.fillRect(0, 0, W, H);
    // header
    var g = c.createLinearGradient(0, 0, W, 260); g.addColorStop(0, '#0a1630'); g.addColorStop(1, '#13264a'); c.fillStyle = g; c.fillRect(0, 0, W, 260);
    c.fillStyle = gold; c.fillRect(0, 256, W, 6);
    crown(c, 60, 62, 70, gold);
    txt(c, 'SLIP', 156, 112, 54, '#fff', '800'); txt(c, 'KING', 156 + c.measureText('SLIP').width + 2, 112, 54, gold, '800');
    txt(c, 'SMARTER SLIPS. HONEST RESULTS.', 158, 148, 17, '#b8c4dd', '600');
    txt(c, 'BETSLIP', W - 60, 112, 56, gold, '800', 'right'); txt(c, 'OFFICIAL BETTING SLIP', W - 60, 148, 18, '#fff', '700', 'right');
    // info card
    rr(c, 40, 200, W - 80, 150, 20); c.fillStyle = '#fff'; c.shadowColor = 'rgba(0,0,0,.15)'; c.shadowBlur = 18; c.fill(); c.shadowBlur = 0;
    var cols = [['BETSLIP ID', data.ref], ['DATE & TIME', data.created], ['TYPE', (items.length > 1 ? 'Multiple' : 'Single') + ' (' + items.length + ')']];
    cols.forEach(function (k, i) { var x = 80 + i * 330; txt(c, k[0], x, 262, 16, mute, '700'); txt(c, k[1], x, 310, 30, ink, '800'); });
    // table head
    var y = 390; c.fillStyle = navy; rr(c, 40, y, W - 80, 56, 12); c.fill();
    txt(c, '#', 64, y + 36, 18, '#fff', '800'); txt(c, 'SELECTION', 110, y + 36, 18, '#fff', '800'); txt(c, 'MARKET', 560, y + 36, 18, '#fff', '800'); txt(c, 'ODDS', 840, y + 36, 18, '#fff', '800', 'center'); txt(c, 'STATUS', 960, y + 36, 18, '#fff', '800', 'center');
    y += 66;
    items.forEach(function (it, n) {
      rr(c, 40, y, W - 80, rowH - 10, 14); c.fillStyle = '#fff'; c.fill(); c.strokeStyle = '#dde5e0'; c.lineWidth = 1; c.stroke();
      c.beginPath(); c.arc(70, y + 50, 18, 0, 7); c.fillStyle = navy; c.fill(); txt(c, String(n + 1), 70, y + 57, 18, '#fff', '800', 'center');
      c.font = '800 24px "Segoe UI",Arial,sans-serif'; txt(c, fit(c, it.home + ' vs ' + it.away, 420), 110, y + 40, 24, ink, '800');
      var w = when(it.kickoff), sub = (it.league || '') + (w[0] ? (it.league ? '  |  ' : '') + w[0] + (w[1] ? ', ' + w[1] : '') : '');
      c.font = '600 17px "Segoe UI",Arial,sans-serif'; txt(c, fit(c, sub, 420), 110, y + 72, 17, mute, '600');
      c.font = '600 17px "Segoe UI",Arial,sans-serif'; txt(c, fit(c, it.market || 'Selection', 250), 560, y + 40, 17, mute, '600');
      c.font = '800 22px "Segoe UI",Arial,sans-serif'; txt(c, fit(c, it.outcome, 250), 560, y + 72, 22, green, '800');
      txt(c, Number(it.odds).toFixed(2), 840, y + 62, 32, ink, '800', 'center');
      rr(c, 900, y + 36, 120, 38, 19); c.fillStyle = '#fff3d6'; c.fill(); txt(c, 'PENDING', 960, y + 61, 16, '#a56a00', '800', 'center');
      y += rowH;
    });
    y += 20;
    // totals
    var bw = (W - 80 - 30) / 2;
    rr(c, 40, y, bw, 120, 18); c.fillStyle = '#fff'; c.fill(); c.strokeStyle = '#dde5e0'; c.stroke();
    txt(c, 'STAKE', 70, y + 42, 18, mute, '700'); txt(c, data.stake > 0 ? data.stake.toFixed(2) : '-', 70, y + 92, 44, ink, '800');
    rr(c, 40 + bw + 30, y, bw, 120, 18); c.fillStyle = '#fff'; c.fill(); c.stroke();
    txt(c, 'TOTAL ODDS', 70 + bw + 30, y + 42, 18, mute, '700'); txt(c, data.total.toFixed(2), 70 + bw + 30, y + 92, 44, ink, '800');
    y += 150;
    var gg = c.createLinearGradient(0, y, W, y + 150); gg.addColorStop(0, '#0d3b2c'); gg.addColorStop(1, '#1a7a4f'); rr(c, 40, y, W - 80, 150, 22); c.fillStyle = gg; c.fill();
    txt(c, 'POTENTIAL RETURN', 80, y + 52, 20, '#bff0d4', '700'); txt(c, data.stake > 0 ? data.ret.toFixed(2) : '-', 80, y + 118, 62, '#fff', '800');
    c.fillStyle = gold; c.beginPath(); c.arc(W - 150, y + 75, 50, 0, 7); c.fill(); txt(c, '★', W - 150, y + 96, 60, green, '800', 'center');
    y += 190;
    // verify
    rr(c, 40, y, W - 80, 150, 18); c.fillStyle = '#fff'; c.fill(); c.strokeStyle = '#dde5e0'; c.stroke();
    rr(c, 70, y + 25, 100, 100, 12); c.fillStyle = navy; c.fill(); crown(c, 92, y + 52, 56, gold);
    txt(c, 'VERIFY THIS SLIP', 200, y + 58, 22, ink, '800'); txt(c, data.verify, 200, y + 92, 18, mute, '600'); txt(c, 'Slip ' + data.ref, 200, y + 122, 18, green, '800');
    txt(c, 'Play Smart. Win Big.', W - 70, y + 92, 30, gold, '800', 'right');
    y += 180;
    txt(c, 'For entertainment only. Odds are entered by the member and are not guaranteed. 18+ only.', W / 2, y + 6, 15, mute, '600', 'center');
    y += 30; c.fillStyle = navy; c.fillRect(0, y, W, H - y);
    crown(c, 60, y + 30, 40, gold); txt(c, 'SLIPKING', 114, y + 62, 30, '#fff', '800');
    txt(c, 'BETTING MADE SIMPLE', W / 2 + 40, y + 62, 18, gold, '800', 'center'); txt(c, host, W - 60, y + 62, 22, '#fff', '700', 'right');
    var out = cv;
    return out;
  };
})();
