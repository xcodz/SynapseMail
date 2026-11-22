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

/* SynapseMail installer: environment web-protection probe, mail server detection, 2FA QR code. */
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  try { var t = localStorage.getItem('synapsemail-theme2'); if (t) document.documentElement.dataset.theme = t; } catch (e) { }

  /* ---------- environment: can the db folder be downloaded? ---------- */
  var probe = $('webProbe');
  if (probe) {
    var set = function (level, text) {
      probe.className = 'check ' + level;
      probe.querySelector('small').textContent = text;
      probe.querySelector('b').textContent = level === 'pass' ? 'OK' : 'FAIL';
      var failed = document.querySelectorAll('.check.fail').length > 0;
      $('webProtected').value = level === 'pass' ? '1' : '';
      if ($('protectHelp')) $('protectHelp').hidden = level === 'pass';
      $('envNext').disabled = failed;
    };
    fetch(probe.dataset.url + '?' + Date.now(), { cache: 'no-store', credentials: 'omit' })
      .then(function (r) { return r.text().then(function (body) { return { status: r.status, body: body }; }); })
      .then(function (r) {
        if (r.status === 200 && r.body.indexOf(probe.dataset.canary) > -1) {
          set('fail', 'Files in db/ can be downloaded from the web. This server ignores .htaccess (e.g. nginx): protect config/, db/, logs/ and temp/ in the server configuration first.');
        } else {
          set('pass', 'db/, config/, logs/ and temp/ are not reachable from the web');
        }
      })
      .catch(function () { set('pass', 'db/, config/, logs/ and temp/ are not reachable from the web'); });
  }

  /* ---------- mail server detection ---------- */
  var app = $('probeApp');
  if (app) {
    var token = app.dataset.token, results = {}, pending = 0;
    var labels = { imap_ssl: ['imap', 'ssl', 'IMAP SSL/TLS 993'], imap_tls: ['imap', 'tls', 'IMAP STARTTLS 143'], smtp_ssl: ['smtp', 'ssl', 'SMTP SSL/TLS 465'], smtp_tls: ['smtp', 'tls', 'SMTP STARTTLS 587'] };

    var render = function () {
      var rows = '', opts = { imap: [], smtp: [] };
      Object.keys(results).forEach(function (host) {
        var res = results[host];
        if (res.error) { rows += '<div class="check warn"><span>' + esc(host) + '</span><small>' + esc(res.error) + '</small><b>SKIP</b></div>'; return; }
        Object.keys(labels).forEach(function (k) {
          var r = res[k], l = labels[k], level = !r.ok ? 'fail' : (r.tls && r.cert_ok ? 'pass' : (r.tls ? 'warn' : 'fail'));
          var detail = !r.ok ? (r.error || 'not reachable') : (r.tls ? (r.cert_ok ? 'TLS, certificate valid for this name' : 'TLS, certificate not valid for this name') : (r.error || 'no TLS'));
          rows += '<div class="check ' + level + '"><span>' + esc(host) + ' · ' + l[2] + '</span><small>' + esc(detail) + '</small><b>' + (level === 'pass' ? 'OK' : level === 'warn' ? 'TLS' : 'NO') + '</b></div>';
          if (r.ok && r.tls) {
            // preference: verified certificate > localhost (same machine) > other unverified hosts; implicit TLS before STARTTLS
            var score = (r.cert_ok ? 100 : (host === 'localhost' ? 50 : 0)) + (l[1] === 'ssl' ? 2 : 1);
            opts[l[0]].push({ value: l[1] + '|' + host + '|' + (r.cert_ok ? 1 : 0), text: l[2].replace(/^(IMAP|SMTP) /, '') + ' · ' + host + (r.cert_ok ? ' · verified' : ' · unverified certificate'), score: score });
          }
        });
      });
      if (pending) rows += '<div class="check pending"><span>Testing ' + pending + ' more host' + (pending > 1 ? 's' : '') + '…</span><small>Please wait</small><b>…</b></div>';
      $('probeRows').innerHTML = rows;
      ['imap', 'smtp'].forEach(function (kind) {
        var sel = $(kind + 'Choice'), keep = sel.value;
        opts[kind].sort(function (a, b) { return b.score - a.score; });
        sel.innerHTML = opts[kind].length ? opts[kind].map(function (o) { return '<option value="' + esc(o.value) + '">' + esc(o.text) + '</option>'; }).join('') : '<option value="">No working connection found yet</option>';
        if (keep && opts[kind].some(function (o) { return o.value === keep; })) sel.value = keep;
      });
    };

    var test = function (host) {
      host = host.trim().toLowerCase();
      if (!host || results[host]) return;
      pending++;
      render();
      fetch('install.php?ajax=probe&t=' + encodeURIComponent(token) + '&host=' + encodeURIComponent(host), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { results[host] = j.error ? { error: j.error } : j.results; })
        .catch(function () { results[host] = { error: 'Test failed (timeout)' }; })
        .then(function () { pending--; render(); });
    };

    JSON.parse(app.dataset.hosts).forEach(test);
    $('customProbe').addEventListener('click', function () { test($('customHost').value); $('customHost').value = ''; });
    $('customHost').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('customProbe').click(); } });
  }

  /* ---------- 2FA: QR code (as an image: no inline styles under the strict CSP) ---------- */
  var qr = $('qr');
  if (qr && window.qrcode) {
    var code = qrcode(0, 'M');
    code.addData(qr.dataset.uri);
    code.make();
    var img = new Image();
    img.alt = 'QR code for your authenticator app';
    img.src = code.createDataURL(5, 12);
    qr.appendChild(img);
  }

  /* ---------- finished install: copy the admin address, unlock the links once it is saved ---------- */
  document.querySelectorAll('[data-copy-text]').forEach(function (b) {
    b.addEventListener('click', function () {
      navigator.clipboard.writeText(b.dataset.copyText).then(function () { b.textContent = 'Copied'; }, function () { b.textContent = 'Select and copy it manually'; });
    });
  });
  document.querySelectorAll('[data-unlock]').forEach(function (a) {
    var box = $(a.dataset.unlock);
    var sync = function () { a.classList.toggle('is-locked', !box.checked); a.setAttribute('aria-disabled', box.checked ? 'false' : 'true'); };
    a.addEventListener('click', function (e) { if (!box.checked) { e.preventDefault(); box.focus(); } });
    box.addEventListener('change', sync);
    sync();
  });

  /* ---------- SynapseMail dropdowns: native <select> stays the form value, a themed list replaces the OS menu ---------- */
  var openUi = null;
  var closeUi = function () {
    if (!openUi) return;
    openUi.wrap.classList.remove('open');
    openUi.menu.hidden = true;
    openUi.btn.setAttribute('aria-expanded', 'false');
    openUi = null;
  };
  document.addEventListener('click', function (e) { if (openUi && !openUi.wrap.contains(e.target) && !openUi.menu.contains(e.target)) closeUi(); });
  // the list floats above the page (fixed), so clipped cards and scroll boxes never cut it off
  window.addEventListener('resize', closeUi);
  document.addEventListener('scroll', function (e) { if (openUi && !openUi.menu.contains(e.target)) closeUi(); }, true);
  window.uiSelect = function (sel) {
    if (sel._ui) return;
    var wrap = document.createElement('div'), btn = document.createElement('button'), menu = document.createElement('ul'), id = 'uis' + Math.random().toString(36).slice(2, 8);
    sel._ui = { wrap: wrap, btn: btn, menu: menu };
    wrap.className = 'ui-select';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('ui-select-native');
    sel.tabIndex = -1;
    btn.type = 'button';
    btn.className = 'ui-select-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-controls', id);
    btn.innerHTML = '<span></span><i aria-hidden="true"></i>';
    menu.className = 'ui-select-menu';
    menu.id = id;
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    wrap.appendChild(btn);
    document.body.appendChild(menu);
    var label = sel.closest('label');
    if (label) label.addEventListener('click', function (e) { if (e.target === label || e.target.classList.contains('field-label')) { e.preventDefault(); btn.focus(); } });

    var render = function () {
      var opt = sel.options[sel.selectedIndex];
      btn.firstChild.textContent = opt ? opt.textContent : (sel.dataset.placeholder || 'Nothing to choose yet');
      btn.disabled = !sel.options.length || sel.disabled;
      menu.innerHTML = '';
      Array.prototype.forEach.call(sel.options, function (o, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', i === sel.selectedIndex ? 'true' : 'false');
        li.tabIndex = -1;
        li.textContent = o.textContent;
        li.dataset.index = i;
        if (o.disabled) li.setAttribute('aria-disabled', 'true');
        menu.appendChild(li);
      });
    };
    var choose = function (i) {
      if (sel.selectedIndex !== i) {
        sel.selectedIndex = i;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      render();
      closeUi();
      btn.focus();
    };
    var focusItem = function (i) {
      var items = menu.querySelectorAll('li');
      if (items.length) items[Math.max(0, Math.min(items.length - 1, i))].focus();
    };
    var open = function () {
      if (btn.disabled) return;
      closeUi();
      render();
      wrap.classList.add('open');
      var r = btn.getBoundingClientRect(), below = innerHeight - r.bottom;
      menu.style.left = r.left + 'px';
      menu.style.width = r.width + 'px';
      menu.style.top = below > 220 || below > r.top ? (r.bottom + 4) + 'px' : 'auto';
      menu.style.bottom = below > 220 || below > r.top ? 'auto' : (innerHeight - r.top + 4) + 'px';
      menu.style.maxHeight = Math.max(160, Math.min(300, (below > 220 || below > r.top ? below : r.top) - 16)) + 'px';
      menu.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      openUi = sel._ui;
      focusItem(Math.max(0, sel.selectedIndex));
    };
    btn.addEventListener('click', function () { if (menu.hidden) open(); else closeUi(); });
    btn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); } });
    menu.addEventListener('click', function (e) { var li = e.target.closest('li'); if (li && li.getAttribute('aria-disabled') !== 'true') choose(+li.dataset.index); });
    menu.addEventListener('keydown', function (e) {
      var cur = +(document.activeElement.dataset.index || 0);
      if (e.key === 'ArrowDown') { e.preventDefault(); focusItem(cur + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); focusItem(cur - 1); }
      else if (e.key === 'Home') { e.preventDefault(); focusItem(0); }
      else if (e.key === 'End') { e.preventDefault(); focusItem(1e9); }
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(cur); }
      else if (e.key === 'Escape' || e.key === 'Tab') { closeUi(); btn.focus(); }
    });
    sel.addEventListener('change', render);
    new MutationObserver(render).observe(sel, { childList: true, subtree: true, characterData: true });
    render();
  };
  document.querySelectorAll('select.setup-select, select[data-ui-select]').forEach(window.uiSelect);

  var copy = $('copyCodes');
  if (copy) {
    copy.addEventListener('click', function () {
      var text = Array.prototype.map.call(document.querySelectorAll('#recoveryCodes code'), function (c) { return c.textContent; }).join('\n');
      navigator.clipboard.writeText(text).then(function () { copy.textContent = 'Copied'; }, function () { copy.textContent = 'Select and copy the codes manually'; });
    });
  }
})();
