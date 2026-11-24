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

/* Admin panel behaviour (no inline handlers under the strict CSP): icons, confirmations, copy buttons,
 * table search/filters, bars and score rings, idle countdown, mobile navigation. */
(function () {
  var all = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  if (window.lucide) lucide.createIcons();

  // destructive actions ask first
  document.addEventListener('submit', function (e) {
    var btn = e.submitter || e.target.querySelector('[data-confirm]');
    if (btn && btn.dataset.confirm && !window.confirm(btn.dataset.confirm)) { e.preventDefault(); return; }
    // long actions (update install): show progress and stop a second click
    var busy = e.target.querySelector('[data-busy]');
    if (busy) {
      setTimeout(function () { busy.disabled = true; busy.setAttribute('aria-busy', 'true'); busy.querySelector('span').textContent = busy.dataset.busy; }, 0);
    }
  });

  // a file over PHP's upload limit would arrive as an empty form: refuse it here with the real reason
  all('input[type=file][data-max]').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0];
      inp.setCustomValidity(f && f.size > +inp.dataset.max ? inp.dataset.maxMsg : '');
      if (f && f.size > +inp.dataset.max) inp.reportValidity();
    });
  });

  // light / dark for this admin browser (cookie read by the server, so the first paint is right)
  all('[data-admin-theme]').forEach(function (b) {
    b.addEventListener('click', function () {
      var next = document.documentElement.dataset.theme === 'soft' ? 'agency' : 'soft';
      document.documentElement.dataset.theme = next;
      document.cookie = 'synapse_admin_theme=' + next + '; path=/; max-age=31536000; SameSite=Strict' + (location.protocol === 'https:' ? '; Secure' : '');
    });
  });

  // copy buttons
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy]');
    if (!btn) return;
    var label = btn.querySelector('span') || btn, before = label.textContent;
    navigator.clipboard.writeText(btn.dataset.copy).then(function () { label.textContent = 'Copied'; }, function () { label.textContent = 'Copy failed'; });
    setTimeout(function () { label.textContent = before; }, 1800);
  });

  // secrets stay blurred until asked for
  all('[data-reveal-btn]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var on = document.body.classList.toggle('reveal-secrets');
      btn.querySelector('span').textContent = on ? 'Hide' : 'Show';
    });
  });

  // checkbox unlocks its button
  all('[data-unlock-btn]').forEach(function (box) {
    var btn = document.getElementById(box.dataset.unlockBtn);
    var sync = function () { btn.disabled = !box.checked; };
    box.addEventListener('change', sync);
    sync();
  });

  // bars and score rings: values set through CSSOM (inline style attributes are blocked by the CSP)
  all('[data-pct]').forEach(function (el) { el.style.setProperty('--p', Math.max(0, Math.min(100, +el.dataset.pct || 0))); });

  // domain picker: switch on change
  all('.adm-picker select').forEach(function (sel) { sel.addEventListener('change', function () { sel.form.submit(); }); });

  // table search + segmented filters
  var apply = function (id) {
    var table = document.getElementById(id), q = '', tag = 'all';
    if (!table) return;
    var input = document.querySelector('[data-table-search="' + id + '"]'), seg = document.querySelector('[data-table-filter="' + id + '"] .active');
    if (input) q = input.value.trim().toLowerCase();
    if (seg) tag = seg.dataset.filter;
    var shown = 0;
    all('tbody tr', table).forEach(function (tr) {
      var ok = (!q || (tr.dataset.search || '').indexOf(q) > -1) && (tag === 'all' || (' ' + (tr.dataset.tags || '') + ' ').indexOf(' ' + tag + ' ') > -1);
      tr.hidden = !ok;
      shown += ok;
    });
    var none = table.parentNode.querySelector('.adm-noresult');
    if (none) none.hidden = shown > 0;
  };
  all('[data-table-search]').forEach(function (input) { input.addEventListener('input', function () { apply(input.dataset.tableSearch); }); });
  all('[data-table-filter]').forEach(function (seg) {
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      all('button', seg).forEach(function (x) { x.classList.toggle('active', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      apply(seg.dataset.tableFilter);
    });
  });

  all('[data-clear-filter]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.dataset.clearFilter, input = document.querySelector('[data-table-search="' + id + '"]'), first = document.querySelector('[data-table-filter="' + id + '"] button');
      if (input) input.value = '';
      if (first) first.click(); else apply(id);
    });
  });

  // idle countdown (server signs out after the same time)
  var idle = document.querySelector('[data-idle]');
  if (idle) {
    var end = Date.now() + idle.dataset.idle * 1000;
    var tick = function () {
      var left = Math.max(0, Math.round((end - Date.now()) / 1000));
      idle.textContent = Math.floor(left / 60) + ':' + ('0' + left % 60).slice(-2);
      idle.parentNode.classList.toggle('warn', left < 120);
      if (!left) location.reload();
    };
    tick();
    setInterval(tick, 1000);
  }

  // mobile navigation
  all('[data-toggle-nav]').forEach(function (b) {
    b.addEventListener('click', function () { b.setAttribute('aria-expanded', document.body.classList.toggle('nav-open') ? 'true' : 'false'); });
  });
  document.addEventListener('click', function (e) {
    if (document.body.classList.contains('nav-open') && !e.target.closest('.adm-side, [data-toggle-nav]')) document.body.classList.remove('nav-open');
  });
})();
