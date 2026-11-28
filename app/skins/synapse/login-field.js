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
/* SynapseMail login / installer / admin background: "secure transit" field drawn on canvas (the app keeps hud.js).
 * A relay mesh (MX / relay / IMAPS / vault nodes) carries mail hop by hop: every hop shows a TLS handshake
 * ring, messages trail cipher text, and now and then mail from outside is rejected at the edge (SPF/DKIM/DMARC).
 * Theme colours come from CSS variables and follow theme changes. ≤30 fps, paused in hidden tabs,
 * one still frame for prefers-reduced-motion. */
(function () {
  var host = document.querySelector('.atmosphere');
  if (!host || document.documentElement.classList.contains('iframe')) return;

  var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d');
  canvas.className = 'secure-field';
  host.appendChild(canvas);
  host.classList.add('field-on');

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var W = 0, H = 0, dpr = 1, lattice = null, nodes = [], edges = [], msgs = [], rings = [], rejects = [];
  var C = {}, last = 0, start = performance.now(), nextMsg = 0, nextReject = 0, mouse = { x: 0, y: 0, tx: 0, ty: 0 };
  var HEX = '0123456789ABCDEF', REASONS = ['SPF FAIL', 'DKIM INVALID', 'DMARC REJECT', 'TLS REQUIRED', 'RBL LISTED'];
  var rand = function (a, b) { return a + Math.random() * (b - a); };
  var pick = function (a) { return a[Math.floor(Math.random() * a.length)]; };
  var ease = function (t) { return t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };

  /* ---------- colours: any CSS colour → [r,g,b] through the canvas parser ---------- */
  function rgb(value, fallback) {
    ctx.fillStyle = fallback;
    ctx.fillStyle = value || fallback;
    var s = ctx.fillStyle, m;
    if (s[0] === '#') return [parseInt(s.substr(1, 2), 16), parseInt(s.substr(3, 2), 16), parseInt(s.substr(5, 2), 16)];
    m = s.match(/[\d.]+/g);
    return m ? [+m[0], +m[1], +m[2]] : [180, 204, 119];
  }
  function readColors() {
    var cs = getComputedStyle(document.body), v = function (n) { return cs.getPropertyValue('--' + n).trim(); };
    C.accent = rgb(v('accent') || v('green'), '#b4cc77');
    C.text = rgb(v('text'), '#e8eadf');
    C.amber = rgb(v('amber'), '#e5aa4f');
    C.red = rgb(v('red'), '#e26b56');
    lattice = null;
  }
  var col = function (c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + Math.max(0, Math.min(1, a)).toFixed(3) + ')'; };

  /* ---------- layout: jittered grid of nodes, 3 nearest links each ---------- */
  function build() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    W = host.clientWidth || innerWidth;
    H = host.clientHeight || innerHeight;
    canvas.width = W * dpr;
    canvas.height = H * dpr;
    canvas.style.width = W + 'px';
    canvas.style.height = H + 'px';
    lattice = null;
    nodes = [];
    edges = [];
    msgs = [];
    rings = [];
    rejects = [];
    var cols = Math.max(3, Math.round(W / 230)), rows = Math.max(3, Math.round(H / 210)), cw = W / cols, ch = H / rows, n = 0;
    for (var r = 0; r < rows; r++) {
      for (var c = 0; c < cols; c++) {
        if (Math.random() < .22) continue;
        var edge = c === 0 || c === cols - 1;
        var kind = edge ? 'MX' : pick(['RELAY', 'RELAY', 'IMAPS', 'SMTP', 'VAULT', 'DKIM']);
        nodes.push({ x: (c + rand(.2, .8)) * cw, y: (r + rand(.2, .8)) * ch, kind: kind, edge: edge,
          label: Math.random() < .45 ? kind + '-' + ('0' + (++n)).slice(-2) : '', glow: 0, adj: [] });
      }
    }
    nodes.forEach(function (a, i) {
      nodes.map(function (b, j) { return { j: j, d: (a.x - b.x) * (a.x - b.x) + (a.y - b.y) * (a.y - b.y) }; })
        .filter(function (o) { return o.j !== i; }).sort(function (p, q) { return p.d - q.d; }).slice(0, 3)
        .forEach(function (o) {
          if (a.adj.indexOf(o.j) < 0) {
            a.adj.push(o.j);
            nodes[o.j].adj.push(i);
            edges.push({ a: i, b: o.j, heat: 0 });
          }
        });
    });
  }

  function edgeOf(i, j) {
    for (var k = 0; k < edges.length; k++) if ((edges[k].a === i && edges[k].b === j) || (edges[k].a === j && edges[k].b === i)) return edges[k];
    return null;
  }

  /* shortest hop path (BFS) */
  function route(from, to) {
    var prev = {}, queue = [from], seen = {};
    seen[from] = true;
    while (queue.length) {
      var u = queue.shift();
      if (u === to) break;
      nodes[u].adj.forEach(function (v) { if (!seen[v]) { seen[v] = true; prev[v] = u; queue.push(v); } });
    }
    if (!seen[to]) return null;
    var path = [to];
    while (path[0] !== from) path.unshift(prev[path[0]]);
    return path;
  }

  function spawnMessage() {
    if (nodes.length < 4 || msgs.length >= 8) return;
    var a = Math.floor(Math.random() * nodes.length), b = a, tries = 0;
    while ((b === a || Math.hypot(nodes[a].x - nodes[b].x, nodes[a].y - nodes[b].y) < Math.min(W, H) * .45) && tries++ < 20) b = Math.floor(Math.random() * nodes.length);
    var path = route(a, b);
    if (!path || path.length < 3) return;
    msgs.push({ path: path, hop: 0, t: 0, speed: rand(150, 230), trail: [], glyphs: [], done: 0 });
  }

  function spawnReject() {
    var gates = nodes.filter(function (n) { return n.edge; });
    if (!gates.length) return;
    var g = pick(gates), fromLeft = g.x < W / 2;
    rejects.push({ node: g, x: fromLeft ? -20 : W + 20, y: g.y + rand(-120, 120), sx: 0, sy: 0, t: 0, phase: 0, reason: pick(REASONS), trail: [] });
    var r = rejects[rejects.length - 1];
    r.sx = r.x;
    r.sy = r.y;
  }

  /* ---------- static layers: dot lattice + vignette, cached per size/theme ---------- */
  function buildLattice() {
    lattice = document.createElement('canvas');
    lattice.width = canvas.width;
    lattice.height = canvas.height;
    var l = lattice.getContext('2d'), s = 30;
    l.scale(dpr, dpr);
    for (var y = s / 2; y < H; y += s) {
      for (var x = s / 2; x < W; x += s) {
        var major = Math.round(x / s) % 6 === 0 && Math.round(y / s) % 6 === 0;
        l.fillStyle = col(C.text, major ? .14 : .045);
        if (major) {
          l.fillRect(x - 3, y - .5, 7, 1);
          l.fillRect(x - .5, y - 3, 1, 7);
        } else {
          l.fillRect(x - .5, y - .5, 1, 1);
        }
      }
    }
    var v = l.createRadialGradient(W * .5, H * .45, Math.min(W, H) * .2, W * .5, H * .5, Math.max(W, H) * .75);
    v.addColorStop(0, 'rgba(0,0,0,0)');
    v.addColorStop(1, 'rgba(0,0,0,.55)');
    l.fillStyle = v;
    l.fillRect(0, 0, W, H);
  }

  /* ---------- drawing ---------- */
  function draw(now, dt) {
    if (!lattice) buildLattice();
    var intro = Math.min(1, (now - start) / 1600), k = ease(intro);
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.globalAlpha = k;
    ctx.drawImage(lattice, 0, 0);
    ctx.globalAlpha = 1;
    mouse.x += (mouse.tx - mouse.x) * .05;
    mouse.y += (mouse.ty - mouse.y) * .05;
    ctx.setTransform(dpr, 0, 0, dpr, mouse.x * dpr, mouse.y * dpr);

    // slow scanning band across the field
    var band = ((now / 16000) % 1.4 - .2) * W, g = ctx.createLinearGradient(band - 260, 0, band + 260, 0);
    g.addColorStop(0, col(C.accent, 0));
    g.addColorStop(.5, col(C.accent, .035 * k));
    g.addColorStop(1, col(C.accent, 0));
    ctx.fillStyle = g;
    ctx.fillRect(band - 260, 0, 520, H);

    // links: hairlines that warm up while mail crosses them
    ctx.lineWidth = 1;
    edges.forEach(function (e) {
      e.heat = Math.max(0, e.heat - dt * .7);
      var a = nodes[e.a], b = nodes[e.b], near = Math.max(0, 1 - Math.abs((a.x + b.x) / 2 - band) / 420);
      ctx.strokeStyle = col(C.accent, (.07 + near * .05 + e.heat * .28) * k);
      ctx.beginPath();
      ctx.moveTo(a.x, a.y);
      ctx.lineTo(b.x, b.y);
      ctx.stroke();
    });

    // handshake rings
    rings = rings.filter(function (r) {
      r.t += dt;
      var p = r.t / r.life;
      if (p >= 1) return false;
      ctx.strokeStyle = col(r.color, (1 - p) * .55);
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.arc(r.x, r.y, 4 + ease(p) * r.size, 0, Math.PI * 2);
      ctx.stroke();
      return true;
    });

    // nodes
    ctx.font = '500 8px "IBM Plex Mono", ui-monospace, monospace';
    nodes.forEach(function (n) {
      n.glow = Math.max(0, n.glow - dt * 1.2);
      var a = (.32 + n.glow * .6) * k, s = n.edge ? 4 : 3;
      ctx.fillStyle = col(C.accent, a);
      ctx.fillRect(n.x - s / 2, n.y - s / 2, s, s);
      ctx.strokeStyle = col(C.accent, (.16 + n.glow * .4) * k);
      ctx.strokeRect(n.x - s - 2.5, n.y - s - 2.5, s * 2 + 5, s * 2 + 5);
      if (n.edge) {
        ctx.beginPath();
        ctx.arc(n.x, n.y, 11, 0, Math.PI * 2);
        ctx.strokeStyle = col(C.accent, (.08 + n.glow * .2) * k);
        ctx.stroke();
      }
      if (n.label) {
        ctx.fillStyle = col(C.text, (.2 + n.glow * .35) * k);
        ctx.fillText(n.label, n.x + 10, n.y - 7);
      }
    });

    // mail in transit: bright head, fading trail, cipher text left behind
    msgs.forEach(function (m) {
      for (var i = 1; i < m.trail.length; i++) {
        var p = i / m.trail.length;
        ctx.strokeStyle = col(C.accent, p * .7 * (1 - m.done));
        ctx.lineWidth = .6 + p * 1.4;
        ctx.beginPath();
        ctx.moveTo(m.trail[i - 1].x, m.trail[i - 1].y);
        ctx.lineTo(m.trail[i].x, m.trail[i].y);
        ctx.stroke();
      }
      m.glyphs.forEach(function (gl) {
        ctx.fillStyle = col(C.accent, gl.a * (1 - m.done));
        ctx.fillText(gl.c, gl.x, gl.y);
      });
      var h = m.trail[m.trail.length - 1];
      if (h && !m.done) {
        var rg = ctx.createRadialGradient(h.x, h.y, 0, h.x, h.y, 9);
        rg.addColorStop(0, col(C.accent, .55));
        rg.addColorStop(1, col(C.accent, 0));
        ctx.fillStyle = rg;
        ctx.fillRect(h.x - 9, h.y - 9, 18, 18);
        ctx.fillStyle = col(C.text, .95);
        ctx.fillRect(h.x - 1.5, h.y - 1.5, 3, 3);
      }
    });

    // rejected at the edge: inbound mail stops at the MX gateway behind a shield
    rejects.forEach(function (r) {
      var c = r.phase ? C.red : C.amber, fade = r.phase === 2 ? Math.max(0, 1 - r.t / .9) : 1;
      for (var i = 1; i < r.trail.length; i++) {
        ctx.strokeStyle = col(c, (i / r.trail.length) * .45 * fade);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(r.trail[i - 1].x, r.trail[i - 1].y);
        ctx.lineTo(r.trail[i].x, r.trail[i].y);
        ctx.stroke();
      }
      if (r.phase) {
        var n = r.node, rad = 17, p = Math.min(1, r.t / .35);
        ctx.strokeStyle = col(C.red, .55 * fade);
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        for (var s = 0; s <= 6; s++) {
          var ang = Math.PI / 6 + s * Math.PI / 3;
          ctx[s ? 'lineTo' : 'moveTo'](n.x + Math.cos(ang) * rad * ease(p), n.y + Math.sin(ang) * rad * ease(p));
        }
        ctx.stroke();
        ctx.fillStyle = col(C.red, .5 * fade);
        ctx.fillText('REJECTED · ' + r.reason, n.x + 22, n.y + 16);
      } else {
        ctx.fillStyle = col(C.amber, .8);
        ctx.fillRect(r.x - 1.5, r.y - 1.5, 3, 3);
      }
    });
  }

  /* ---------- simulation ---------- */
  function update(now, dt) {
    if (now > nextMsg) {
      spawnMessage();
      nextMsg = now + rand(500, 1300);
    }
    if (now > nextReject) {
      if (nextReject) spawnReject();
      nextReject = now + rand(7000, 12000);
    }
    msgs = msgs.filter(function (m) {
      if (m.done) {
        m.done = Math.min(1, m.done + dt * 1.6);
        return m.done < 1;
      }
      var a = nodes[m.path[m.hop]], b = nodes[m.path[m.hop + 1]], len = Math.hypot(b.x - a.x, b.y - a.y) || 1;
      m.t += dt * m.speed / len;
      var e = edgeOf(m.path[m.hop], m.path[m.hop + 1]);
      if (e) e.heat = 1;
      if (m.t >= 1) {
        m.hop++;
        m.t = 0;
        b.glow = 1;
        var final = m.hop >= m.path.length - 1;
        rings.push({ x: b.x, y: b.y, t: 0, life: final ? 1.1 : .7, size: final ? 22 : 13, color: C.accent });
        if (final) {
          m.done = .01;
          return true;
        }
        a = nodes[m.path[m.hop]];
        b = nodes[m.path[m.hop + 1]];
      }
      var q = ease(Math.min(1, m.t)), x = a.x + (b.x - a.x) * q, y = a.y + (b.y - a.y) * q;
      m.trail.push({ x: x, y: y });
      if (m.trail.length > 18) m.trail.shift();
      if (Math.random() < .35) m.glyphs.push({ x: x + rand(-6, 6), y: y + rand(-6, 6), c: pick(HEX) + pick(HEX), a: .45 });
      m.glyphs = m.glyphs.filter(function (gl) {
        gl.a -= dt * .5;
        if (Math.random() < .08) gl.c = pick(HEX) + pick(HEX);
        return gl.a > 0;
      });
      return true;
    });
    rejects = rejects.filter(function (r) {
      r.t += dt;
      if (!r.phase) {
        var p = Math.min(1, r.t / 2.2), q = ease(p), n = r.node, dx = n.x - r.sx, dy = n.y - r.sy, d = Math.hypot(dx, dy) || 1;
        r.x = r.sx + dx * q * (1 - 20 / d);
        r.y = r.sy + dy * q * (1 - 20 / d);
        r.trail.push({ x: r.x, y: r.y });
        if (r.trail.length > 22) r.trail.shift();
        if (p >= 1) {
          r.phase = 1;
          r.t = 0;
          rings.push({ x: n.x, y: n.y, t: 0, life: .9, size: 26, color: C.red });
        }
      } else if (r.phase === 1 && r.t > 2.2) {
        r.phase = 2;
        r.t = 0;
      } else if (r.phase === 2 && r.t > .9) {
        return false;
      }
      return true;
    });
  }

  function frame(now) {
    requestAnimationFrame(frame);
    if (document.hidden || now - last < 33 || document.documentElement.dataset.look == 'lumen') return; // Lumen has no field
    var dt = Math.min(.1, (now - last) / 1000);
    last = now;
    update(now, dt);
    draw(now, dt);
  }

  readColors();
  build();
  new MutationObserver(readColors).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
  new MutationObserver(readColors).observe(document.body, { attributes: true, attributeFilter: ['class', 'data-theme'] });
  window.addEventListener('resize', function () { clearTimeout(build.t); build.t = setTimeout(build, 150); });
  if (!reduced) {
    window.addEventListener('pointermove', function (e) { mouse.tx = (e.clientX / W - .5) * -14; mouse.ty = (e.clientY / H - .5) * -10; }, { passive: true });
  }

  if (reduced) {
    // one still frame: the mesh with a few messages mid-route
    start = -1e6;
    for (var i = 0; i < 6; i++) spawnMessage();
    for (var s = 0; s < 40; s++) update(performance.now(), .05);
    draw(performance.now(), 0);
  } else {
    requestAnimationFrame(frame);
  }
})();
