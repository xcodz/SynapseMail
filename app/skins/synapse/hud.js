/*!
 * SynapseMail
 * Copyright (c) 2026 SynapseCyber.io. All rights reserved.
 * Developed by Milad Ahmadipour (https://milad.io).
 *
 * Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0
 * International License (CC BY-NC-ND 4.0). You may use and share it free of charge
 * with attribution. You may not modify it, distribute modified versions, or sell it.
 * https://creativecommons.org/licenses/by-nc-nd/4.0/
 */
/* SynapseMail background: "cyber-ops" tactical HUD: a live network defence map drawn on canvas.
 * Nodes exchange encrypted packets; intrusion attempts are target-locked and neutralised.
 * Colours follow the active theme (CSS variables), ~24 fps, paused when the tab is hidden. */
(function () {
  var host = document.querySelector('.tactical-atmosphere, .atmosphere');
  if (!host || document.documentElement.classList.contains('iframe')) return;

  var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d');
  canvas.className = 'cyber-hud';
  host.appendChild(canvas);

  var reduced = /^(reduce|off)$/.test(document.documentElement.dataset.motion || '') || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var W = 0, H = 0, dpr = 1, grid = null, nodes = [], edges = [], packets = [], threats = [], colors = {}, last = 0, t0 = performance.now();
  var stats = { pkts: 4200, blocked: 0, uplink: 98 };
  var rand = function (a, b) { return a + Math.random() * (b - a); };
  var pick = function (arr) { return arr[Math.floor(Math.random() * arr.length)]; };

  function readColors() {
    var cs = getComputedStyle(document.body);
    ['green', 'green-2', 'amber', 'red', 'cyan', 'text', 'dim'].forEach(function (k) { colors[k] = cs.getPropertyValue('--' + k).trim() || '#b4cc77'; });
  }

  function alpha(color, a) {
    // supports #rgb/#rrggbb from the theme tokens
    var c = color.replace('#', '');
    if (c.length == 3) c = c.split('').map(function (x) { return x + x; }).join('');
    var n = parseInt(c, 16);
    return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + a + ')';
  }

  function ip() { return '10.' + Math.floor(rand(0, 255)) + '.' + Math.floor(rand(0, 255)) + '.' + Math.floor(rand(2, 254)); }

  function build() {
    dpr = Math.min(window.devicePixelRatio || 1, 1.5);
    W = window.innerWidth; H = window.innerHeight;
    canvas.width = W * dpr; canvas.height = H * dpr;
    canvas.style.width = W + 'px'; canvas.style.height = H + 'px';
    readColors();

    // hex grid, pre-rendered once per size/theme
    grid = document.createElement('canvas');
    grid.width = canvas.width; grid.height = canvas.height;
    var g = grid.getContext('2d'), r = 26, hw = Math.sqrt(3) * r;
    g.scale(dpr, dpr);
    g.strokeStyle = alpha(colors.green, 0.05); g.lineWidth = 1;
    for (var y = 0, row = 0; y < H + r * 2; y += r * 1.5, row++) {
      for (var x = (row % 2) * hw / 2; x < W + hw; x += hw) {
        g.beginPath();
        for (var i = 0; i < 6; i++) {
          var a = Math.PI / 3 * i + Math.PI / 6;
          g[i ? 'lineTo' : 'moveTo'](x + r * Math.cos(a), y + r * Math.sin(a));
        }
        g.closePath(); g.stroke();
      }
    }

    // network topology: denser on the right where the UI leaves room on the login screen
    var count = W < 700 ? 9 : W < 1200 ? 14 : 19;
    nodes = []; edges = [];
    for (var n = 0; n < count; n++) {
      nodes.push({ x: rand(W * 0.08, W * 0.94), y: rand(H * 0.12, H * 0.9), ip: ip(), core: n < 3, pulse: rand(0, 6), alert: 0 });
    }
    nodes.forEach(function (a, i) {
      nodes.map(function (b, j) { return { j: j, d: Math.hypot(a.x - b.x, a.y - b.y) }; })
        .filter(function (o) { return o.j != i; }).sort(function (p, q) { return p.d - q.d; }).slice(0, 2)
        .forEach(function (o) { if (!edges.some(function (e) { return (e[0] == i && e[1] == o.j) || (e[0] == o.j && e[1] == i); })) edges.push([i, o.j]); });
    });
    packets = []; threats = [];
  }

  function spawnPacket(hostile) {
    var e = pick(edges); if (!e) return;
    var fwd = Math.random() < 0.5;
    packets.push({ a: fwd ? e[0] : e[1], b: fwd ? e[1] : e[0], p: 0, speed: rand(0.25, 0.6), hostile: !!hostile });
  }

  function drawFrame(now) {
    var t = (now - t0) / 1000, w = W, h = H;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    // a zero-size grid (page laid out while hidden) would throw and stop the animation loop for good
    if (grid && grid.width && grid.height) ctx.drawImage(grid, 0, 0, w, h);

    // edges
    ctx.lineWidth = 1;
    edges.forEach(function (e) {
      var a = nodes[e[0]], b = nodes[e[1]];
      ctx.strokeStyle = alpha(colors.green, 0.1);
      ctx.setLineDash([2, 6]);
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
    });
    ctx.setLineDash([]);

    // packets with short trails
    packets.forEach(function (k) {
      var a = nodes[k.a], b = nodes[k.b], x = a.x + (b.x - a.x) * k.p, y = a.y + (b.y - a.y) * k.p;
      var tx = a.x + (b.x - a.x) * Math.max(0, k.p - 0.08), ty = a.y + (b.y - a.y) * Math.max(0, k.p - 0.08);
      var c = k.hostile ? colors.red : colors.green;
      var grad = ctx.createLinearGradient(tx, ty, x, y);
      grad.addColorStop(0, alpha(c, 0)); grad.addColorStop(1, alpha(c, k.hostile ? 0.85 : 0.6));
      ctx.strokeStyle = grad; ctx.lineWidth = k.hostile ? 2 : 1.5;
      ctx.beginPath(); ctx.moveTo(tx, ty); ctx.lineTo(x, y); ctx.stroke();
      ctx.fillStyle = alpha(c, 0.9); ctx.fillRect(x - 1.5, y - 1.5, 3, 3);
    });

    // nodes: diamonds with address labels
    ctx.font = '500 9px "IBM Plex Mono", monospace';
    nodes.forEach(function (n) {
      var s = n.core ? 5 : 3.5, c = n.alert > 0 ? colors.red : n.core ? colors.amber : colors.green;
      var glow = 0.35 + 0.25 * Math.sin(t * 2 + n.pulse);
      ctx.save(); ctx.translate(n.x, n.y); ctx.rotate(Math.PI / 4);
      ctx.strokeStyle = alpha(c, 0.55 + glow * 0.3); ctx.lineWidth = 1;
      ctx.strokeRect(-s, -s, s * 2, s * 2);
      ctx.fillStyle = alpha(c, 0.18 + glow * 0.2); ctx.fillRect(-s + 1.5, -s + 1.5, s * 2 - 3, s * 2 - 3);
      ctx.restore();
      ctx.fillStyle = alpha(colors.dim || colors.green, 0.4);
      ctx.fillText((n.core ? 'CORE ' : '') + n.ip, n.x + 9, n.y + 3);
    });

    // threat lock-ons: converging brackets, rings, status label
    threats.forEach(function (th) {
      var n = nodes[th.node], age = t - th.start, lock = Math.min(1, age / 0.9), done = age > 2.2;
      var c = done ? colors.green : colors.red, r = 34 - 18 * lock, a = done ? Math.max(0, 1 - (age - 2.2) / 1.2) : 1;
      ctx.strokeStyle = alpha(c, 0.8 * a); ctx.lineWidth = 1.5;
      [[-1, -1], [1, -1], [1, 1], [-1, 1]].forEach(function (q) {
        var x = n.x + q[0] * r, y = n.y + q[1] * r;
        ctx.beginPath(); ctx.moveTo(x, y - q[1] * 7); ctx.lineTo(x, y); ctx.lineTo(x - q[0] * 7, y); ctx.stroke();
      });
      for (var i = 0; i < 2; i++) {
        var rr = ((age * 40 + i * 22) % 44) + 8;
        ctx.strokeStyle = alpha(c, (1 - rr / 52) * 0.5 * a);
        ctx.beginPath(); ctx.arc(n.x, n.y, rr, 0, Math.PI * 2); ctx.stroke();
      }
      ctx.fillStyle = alpha(c, 0.9 * a);
      ctx.font = '600 9px "IBM Plex Mono", monospace';
      ctx.fillText(done ? 'THREAT NEUTRALIZED' : 'INTRUSION DETECTED // LOCKING', n.x + r + 8, n.y - r + 4);
      ctx.fillStyle = alpha(colors.dim || c, 0.7 * a);
      ctx.font = '500 8px "IBM Plex Mono", monospace';
      ctx.fillText('SRC ' + th.src + '  ·  ' + (done ? 'QUARANTINED' : Math.round(lock * 100) + '%'), n.x + r + 8, n.y - r + 16);
    });

    // thermal-style scan sweep
    var sx = ((t * 70) % (w + 300)) - 150;
    var sweep = ctx.createLinearGradient(sx - 120, 0, sx, 0);
    sweep.addColorStop(0, alpha(colors.green, 0)); sweep.addColorStop(1, alpha(colors.green, 0.06));
    ctx.fillStyle = sweep; ctx.fillRect(sx - 120, 0, 120, h);
    ctx.fillStyle = alpha(colors.green, 0.22); ctx.fillRect(sx, 0, 1, h);

    // HUD frame: corner brackets with ticks + telemetry
    var m = 14, L = 38;
    ctx.strokeStyle = alpha(colors.green, 0.3); ctx.lineWidth = 1;
    [[m, m, 1, 1], [w - m, m, -1, 1], [w - m, h - m, -1, -1], [m, h - m, 1, -1]].forEach(function (k) {
      ctx.beginPath(); ctx.moveTo(k[0], k[1] + k[3] * L); ctx.lineTo(k[0], k[1]); ctx.lineTo(k[0] + k[2] * L, k[1]); ctx.stroke();
      for (var i = 1; i < 4; i++) { ctx.beginPath(); ctx.moveTo(k[0] + k[2] * (L + i * 8), k[1]); ctx.lineTo(k[0] + k[2] * (L + i * 8), k[1] + k[3] * 3); ctx.stroke(); }
    });
    if (w > 700) {
      ctx.font = '500 8px "IBM Plex Mono", monospace';
      var lines = [
        'NET DEFENSE GRID // ACTIVE',
        'PKTS/S ' + stats.pkts.toLocaleString('en-US'),
        'THREATS BLOCKED ' + String(stats.blocked).padStart(3, '0'),
        'UPLINK ' + stats.uplink + '%  ·  TLS 1.3',
      ];
      lines.forEach(function (l, i) {
        ctx.fillStyle = alpha(i ? colors.green : colors.amber, i ? 0.32 : 0.45);
        ctx.fillText(l, w - 210, h - 72 + i * 12);
      });
    }
  }

  function step(now) {
    if (document.hidden || document.documentElement.dataset.look == 'lumen') return requestAnimationFrame(step); // Lumen has no HUD
    if (now - last < 41) return requestAnimationFrame(step);
    var dt = Math.min(0.1, (now - last) / 1000); last = now;
    var t = (now - t0) / 1000;

    packets.forEach(function (k) { k.p += k.speed * dt; });
    packets = packets.filter(function (k) {
      if (k.p < 1) return true;
      // a hostile packet reaching its target starts a lock-on
      if (k.hostile && !threats.some(function (th) { return th.node == k.b; })) {
        threats.push({ node: k.b, start: t, src: ip() });
        nodes[k.b].alert = 1;
      }
      return false;
    });
    threats = threats.filter(function (th) {
      var age = t - th.start;
      if (age > 2.2 && nodes[th.node].alert) { nodes[th.node].alert = 0; stats.blocked++; }
      return age < 3.4;
    });
    if (packets.length < nodes.length * 1.4 && Math.random() < 0.35) spawnPacket(false);
    if (!threats.length && Math.random() < 0.004) spawnPacket(true);
    if (Math.random() < 0.1) { stats.pkts = Math.max(1800, stats.pkts + Math.round(rand(-140, 140))); stats.uplink = Math.min(100, Math.max(94, stats.uplink + Math.round(rand(-1, 1)))); }

    drawFrame(now);
    requestAnimationFrame(step);
  }

  build();
  window.addEventListener('resize', function () { clearTimeout(build.t); build.t = setTimeout(build, 150); });
  // theme switches: re-read colours and redraw the grid
  new MutationObserver(build).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  if (reduced) { for (var i = 0; i < 12; i++) spawnPacket(false); packets.forEach(function (k) { k.p = Math.random(); }); drawFrame(performance.now()); }
  else requestAnimationFrame(step);
})();
