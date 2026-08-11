/* Super Optik Tech — mouse-reaktiv fiber-optik fon + cursor işıq izi (canvas, asılılıqsız). */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var cv = document.getElementById('fiber-bg');
  if (!cv || !cv.getContext || reduce) return;
  var ctx = cv.getContext('2d');
  var W, H, strands = [], DPR = Math.min(2, window.devicePixelRatio || 1);
  var cols = ['#00E5FF', '#3b82f6', '#6E44FF'];
  var mx = -9999, my = -9999;

  function build() {
    strands = [];
    var n = Math.round(window.innerWidth / 150) + 4;
    for (var i = 0; i < n; i++) {
      var pulses = [], pc = 1 + Math.floor(Math.random() * 2);
      for (var p = 0; p < pc; p++) pulses.push({ t: Math.random(), sp: 0.0009 + Math.random() * 0.0016 });
      strands.push({ y: Math.random() * H, amp: (40 + Math.random() * 120) * DPR,
        k: (0.6 + Math.random() * 1.4) / (200 * DPR), ph: Math.random() * 6.28,
        hue: Math.floor(Math.random() * 3), pulses: pulses, w: (0.6 + Math.random() * 0.7) * DPR });
    }
  }
  function resize() {
    W = cv.width = window.innerWidth * DPR; H = cv.height = window.innerHeight * DPR;
    cv.style.width = window.innerWidth + 'px'; cv.style.height = window.innerHeight + 'px'; build();
  }
  function yAt(s, x) { return s.y + Math.sin(x * s.k + s.ph) * s.amp; }

  function frame() {
    ctx.clearRect(0, 0, W, H);
    var mX = mx * DPR, mY = my * DPR;
    for (var i = 0; i < strands.length; i++) {
      var s = strands[i];
      ctx.beginPath();
      for (var x = 0; x <= W; x += 16 * DPR) {
        var y = yAt(s, x);
        // Mouse cəlbi: kursora yaxın nöqtələr ona doğru əyilir
        var dx = x - mX, dy = y - mY, d2 = dx * dx + dy * dy, R = 180 * DPR;
        if (d2 < R * R) { var f = (1 - Math.sqrt(d2) / R) * 26 * DPR; y += (mY - y) / (Math.abs(mY - y) + 1) * f; }
        if (x === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      }
      ctx.strokeStyle = 'rgba(120,190,255,.06)'; ctx.lineWidth = s.w; ctx.stroke();
      for (var j = 0; j < s.pulses.length; j++) {
        var pl = s.pulses[j]; pl.t += pl.sp; if (pl.t > 1) pl.t -= 1;
        var px = pl.t * W, py = yAt(s, px), c = cols[s.hue];
        var g = ctx.createRadialGradient(px, py, 0, px, py, 55 * DPR);
        g.addColorStop(0, c); g.addColorStop(0.4, c + '55'); g.addColorStop(1, 'transparent');
        ctx.globalCompositeOperation = 'lighter';
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(px, py, 55 * DPR, 0, 6.2832); ctx.fill();
        ctx.fillStyle = c; ctx.beginPath(); ctx.arc(px, py, 2 * DPR, 0, 6.2832); ctx.fill();
        ctx.globalCompositeOperation = 'source-over';
      }
    }
    // Kursor işıq halosu
    if (mX > 0) {
      var gg = ctx.createRadialGradient(mX, mY, 0, mX, mY, 150 * DPR);
      gg.addColorStop(0, 'rgba(0,229,255,.16)'); gg.addColorStop(1, 'transparent');
      ctx.globalCompositeOperation = 'lighter'; ctx.fillStyle = gg;
      ctx.beginPath(); ctx.arc(mX, mY, 150 * DPR, 0, 6.2832); ctx.fill();
      ctx.globalCompositeOperation = 'source-over';
    }
    requestAnimationFrame(frame);
  }
  window.addEventListener('mousemove', function (e) { mx = e.clientX; my = e.clientY; }, { passive: true });
  window.addEventListener('resize', resize, { passive: true });
  resize(); requestAnimationFrame(frame);

  /* ---- Cursor işıq izi (trail) ---- */
  var tc = document.getElementById('cursor-trail');
  if (tc && tc.getContext && window.matchMedia('(hover:hover)').matches) {
    var tx = tc.getContext('2d'), tw, th, trail = [];
    function tr() { tw = tc.width = window.innerWidth * DPR; th = tc.height = window.innerHeight * DPR; tc.style.width = window.innerWidth + 'px'; tc.style.height = window.innerHeight + 'px'; }
    window.addEventListener('resize', tr, { passive: true }); tr();
    window.addEventListener('mousemove', function (e) { trail.push({ x: e.clientX * DPR, y: e.clientY * DPR, l: 1 }); if (trail.length > 22) trail.shift(); }, { passive: true });
    (function td() {
      tx.clearRect(0, 0, tw, th); tx.globalCompositeOperation = 'lighter';
      for (var i = 1; i < trail.length; i++) {
        var a = trail[i - 1], b = trail[i]; a.l *= 0.9;
        tx.strokeStyle = 'rgba(0,229,255,' + (a.l * 0.5) + ')'; tx.lineWidth = a.l * 3 * DPR; tx.lineCap = 'round';
        tx.beginPath(); tx.moveTo(a.x, a.y); tx.lineTo(b.x, b.y); tx.stroke();
      }
      tx.globalCompositeOperation = 'source-over';
      requestAnimationFrame(td);
    })();
  }
})();
