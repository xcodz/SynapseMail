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
/* SynapseMail skin: template behaviour (SynapseCyber_Template app.js / login.js) wired to the Roundcube rcmail API */
var UI = (function () {
  var $ = window.jQuery, rc = window.rcmail, doc = document, html = doc.documentElement;
  var byId = function (id) { return doc.getElementById(id); };
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var framed = html.classList.contains('iframe');
  // texts: plugins/synapse_core/localization (sent by synapse_core); vars fill $name placeholders
  function t(key, vars) { return rc ? rc.gettext(key, 'synapse_core', vars) : key; }
  var themeLabels = { warfare: t('theme_warfare'), blackops: t('theme_blackops'), night: t('theme_night'), agency: t('theme_agency') };

  function store(key, value) { try { if (value === undefined) return localStorage.getItem(key); if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); } catch (e) { return null; } }
  function icons() { enhanceButtons(); if (window.lucide) lucide.createIcons(); }

  // Roundcube's template parser cannot take markup in button attributes: buttons carry data-icon/label/detail
  function enhanceButtons() {
    doc.querySelectorAll('[data-icon]:not([data-enhanced])').forEach(function (el) {
      var d = el.dataset, ic = '<i data-lucide="' + d.icon + '"></i>', l = d.label ? rc.gettext(d.label) : '';
      d.enhanced = 1;
      if (el.classList.contains('theme-choice')) el.innerHTML = ic + '<span><b>' + esc(l) + '</b><small>' + esc(d.detail ? rc.gettext(d.detail) : '') + '</small></span><em>' + esc(d.key || '') + '</em>';
      else if (d.wrap) el.innerHTML = ic + '<span>' + esc(l) + '</span>';
      else el.innerHTML = ic + (l ? ' ' + esc(l) : '') + (d.badge ? '<span id="' + d.badge + '" hidden></span>' : '');
      if (!el.getAttribute('aria-label') && el.title) el.setAttribute('aria-label', el.title);
    });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function pad(n, len) { return String(n).padStart(len || 2, '0'); }
  function initials(name) { return String(name || '').replace(/[<>"@].*$/, '').trim().split(/[\s._-]+/).filter(Boolean).map(function (w) { return w[0]; }).slice(0, 2).join('').toUpperCase() || 'SM'; }
  function topRc() { try { return parent !== window && parent.rcmail ? parent.rcmail : rc; } catch (e) { return rc; } }
  function topUI() { try { return parent !== window && parent.UI ? parent.UI : null; } catch (e) { return null; } }

  function updateClock() {
    var clock = byId('localClock'); if (!clock) return;
    var now = new Date();
    clock.textContent = [now.getHours(), now.getMinutes(), now.getSeconds()].map(function (v) { return pad(v); }).join(':');
    if (clock.tagName == 'TIME') clock.dateTime = now.toISOString();
  }

  /* ---------- notifications: rcmail's message stack is mirrored into themed HUD toasts ---------- */
  var toastIcons = { confirmation: 'shield-check', notice: 'radio-tower', warning: 'triangle-alert', error: 'shield-alert', loading: 'loader' };
  var toastTitles = { confirmation: t('toast_confirmation'), notice: t('toast_notice'), warning: t('toast_warning'), error: t('toast_error'), loading: t('toast_loading') };
  function toastHost() {
    var h = byId('synapseToasts');
    if (!h) { h = doc.createElement('div'); h.id = 'synapseToasts'; h.setAttribute('aria-live', 'polite'); doc.body.appendChild(h); }
    return h;
  }
  function createToast(message, type, timeout) {
    type = toastIcons[type] ? type : 'notice';
    var el = doc.createElement('div');
    el.className = 'hud-toast ' + type;
    el.setAttribute('role', type == 'error' ? 'alert' : 'status');
    el.innerHTML = '<span class="hud-toast-icon"><i data-lucide="' + toastIcons[type] + '"></i></span><span class="hud-toast-copy"><b>' + toastTitles[type]
      + '</b><span></span></span><em>' + new Date().toTimeString().slice(0, 8) + '</em>' + (timeout ? '<i class="hud-toast-timer" style="animation-duration:' + timeout + 'ms"></i>' : '');
    el.querySelector('.hud-toast-copy span').textContent = message;
    toastHost().appendChild(el);
    if (window.lucide) lucide.createIcons({ nameAttr: 'data-lucide', root: el });
    return el;
  }
  function dismissToast(el) {
    if (!el || el.classList.contains('leaving')) return;
    el.classList.add('leaving');
    setTimeout(function () { el.remove(); }, 380);
  }
  function toast(message, type) {
    var el = createToast(String(message), type || 'confirmation', 2600);
    el.addEventListener('click', function () { dismissToast(el); });
    setTimeout(function () { dismissToast(el); }, 2600);
  }
  function mirrorMessages() {
    var stack = byId('messagestack'); if (!stack || !window.MutationObserver) return;
    var mirror = function (n) {
      if (n.nodeType != 1 || n._hud) return;
      var type = (n.className.match(/\b(confirmation|notice|warning|error|loading)\b/) || [])[1] || 'notice';
      var text = n.textContent.trim(); if (!text) return;
      var hud = n._hud = createToast(text, type, type == 'loading' ? 0 : rc.message_time * (type == 'error' || type == 'warning' ? 2 : 1));
      hud.addEventListener('click', function () { $(n).trigger('click'); dismissToast(hud); });
      // core hides with fadeOut/remove: follow it
      new MutationObserver(function () { if (!n.isConnected || n.style.display == 'none') dismissToast(hud); }).observe(n, { attributes: true, attributeFilter: ['style'] });
    };
    // messages sent with the page (e.g. "Successfully saved" after a password change) are already in the stack
    Array.prototype.forEach.call(stack.children, function (n) { if (n.style.display != 'none') mirror(n); });
    new MutationObserver(function (list) {
      list.forEach(function (m) {
        m.addedNodes.forEach(mirror);
        m.removedNodes.forEach(function (n) { if (n._hud) dismissToast(n._hud); });
      });
    }).observe(stack, { childList: true });
  }

  function themeCookie() { var m = /(?:^|;\s*)synapsemail_theme2=([a-z]+)/.exec(doc.cookie); return m ? m[1] : null; }

  // persist: cookie (login page, before any session), local storage, and the account (server pref) when signed in
  function persistTheme(theme) {
    store('synapsemail-theme2', theme);
    doc.cookie = 'synapsemail_theme2=' + theme + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol == 'https:' ? '; Secure' : '');
  }

  function applyTheme(theme, announce, save) {
    if (!themeLabels[theme]) theme = 'agency';
    html.dataset.theme = theme;
    if (announce || save) persistTheme(theme); // only a user's choice is remembered, so the default can change
    doc.querySelectorAll('.theme-dot, .theme-choice[data-theme]').forEach(function (el) { el.classList.toggle('active', el.dataset.theme === theme); });
    // keep framed views (preview, compose, settings forms) on the same theme
    doc.querySelectorAll('iframe').forEach(function (f) { try { f.contentDocument.documentElement.dataset.theme = theme; } catch (e) { } });
    if (save) {
      var trc = topRc();
      if (trc && trc.env.task && trc.env.task != 'login' && trc.env.task != 'logout' && trc.env.synapse_theme != theme) trc.save_pref({ name: 'synapse_theme', value: theme, env: 'synapse_theme' });
    }
    closeMenus();
    if (announce) toast(t('themeloaded', { theme: themeLabels[theme] }));
  }


  /* ---------- themed dropdowns: every <select> gets a skin-styled control (native select stays as the source of truth) ---------- */
  var openSelect = null;
  function closeSelectMenu() {
    if (!openSelect) return;
    openSelect.menu.remove(); openSelect.wrap.classList.remove('open');
    openSelect.trigger.setAttribute('aria-expanded', 'false');
    openSelect = null;
  }
  function enhanceSelect(sel) {
    if (sel._sx || sel.multiple || sel.size > 1 || sel.closest('.sx-native')) return;
    sel._sx = true;
    var wrap = doc.createElement('span'), trigger = doc.createElement('button'), label = doc.createElement('span');
    wrap.className = 'sx-select ' + (sel.className || '');
    trigger.type = 'button'; trigger.className = 'sx-trigger'; trigger.setAttribute('aria-haspopup', 'listbox'); trigger.setAttribute('aria-expanded', 'false');
    if (sel.title) trigger.title = sel.title;
    if (sel.getAttribute('aria-label')) trigger.setAttribute('aria-label', sel.getAttribute('aria-label'));
    label.className = 'sx-label';
    trigger.appendChild(label);
    trigger.insertAdjacentHTML('beforeend', '<i class="sx-chevron" aria-hidden="true"></i>');
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel); wrap.appendChild(trigger);
    sel.tabIndex = -1; sel.setAttribute('aria-hidden', 'true');

    function refresh() {
      var o = sel.options[sel.selectedIndex];
      label.textContent = o ? o.text : '';
      label.style.fontFamily = o && o.style.fontFamily ? o.style.fontFamily : '';
      wrap.classList.toggle('disabled', sel.disabled);
      trigger.disabled = sel.disabled;
      wrap.hidden = sel.hidden || sel.style.display == 'none';
    }
    // keep the control in sync with programmatic changes (value/selectedIndex setters, option list updates)
    ['value', 'selectedIndex'].forEach(function (prop) {
      var d = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
      Object.defineProperty(sel, prop, { configurable: true, get: function () { return d.get.call(this); }, set: function (v) { d.set.call(this, v); refresh(); } });
    });
    new MutationObserver(refresh).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'style', 'hidden', 'selected'] });
    sel.addEventListener('change', refresh);

    function choose(index) {
      if (index < 0 || index == sel.selectedIndex) return closeSelectMenu();
      sel.selectedIndex = index;
      closeSelectMenu();
      sel.dispatchEvent(new Event('input', { bubbles: true }));
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      trigger.focus();
    }

    function open() {
      if (sel.disabled) return;
      closeSelectMenu();
      var menu = doc.createElement('div'), list = doc.createElement('div'), search = null, active = sel.selectedIndex, items = [];
      menu.className = 'sx-menu'; list.className = 'sx-list'; list.setAttribute('role', 'listbox');
      if (sel.options.length > 12) {
        search = doc.createElement('input'); search.type = 'text'; search.className = 'sx-search'; search.placeholder = t('filter'); search.setAttribute('autocomplete', 'off');
        menu.appendChild(search);
      }
      menu.appendChild(list);
      function render(q) {
        list.innerHTML = ''; items = [];
        Array.prototype.forEach.call(sel.children, function (node) {
          var opts = node.tagName == 'OPTGROUP' ? node.children : [node];
          if (node.tagName == 'OPTGROUP') { var g = doc.createElement('div'); g.className = 'sx-group'; g.textContent = node.label; list.appendChild(g); }
          Array.prototype.forEach.call(opts, function (o) {
            if (q && o.text.toLowerCase().indexOf(q) < 0) return;
            var it = doc.createElement('div');
            it.className = 'sx-option' + (o.index == sel.selectedIndex ? ' selected' : '') + (o.disabled ? ' disabled' : '');
            it.setAttribute('role', 'option'); it.dataset.index = o.index; it.textContent = o.text;
            if (o.style.fontFamily) it.style.fontFamily = o.style.fontFamily;
            list.appendChild(it); if (!o.disabled) items.push(it);
          });
        });
        mark(items.findIndex(function (i) { return +i.dataset.index == active; }));
      }
      function mark(i) {
        items.forEach(function (it) { it.classList.remove('active'); });
        if (items[i]) { items[i].classList.add('active'); items[i].scrollIntoView({ block: 'nearest' }); active = +items[i].dataset.index; }
      }
      list.addEventListener('mousedown', function (e) { e.preventDefault(); });
      list.addEventListener('click', function (e) { var it = e.target.closest('.sx-option'); if (it && !it.classList.contains('disabled')) choose(+it.dataset.index); });
      list.addEventListener('mousemove', function (e) { var it = e.target.closest('.sx-option'); if (it && !it.classList.contains('active')) mark(items.indexOf(it)); });
      menu.addEventListener('keydown', function (e) {
        var cur = items.findIndex(function (i) { return i.classList.contains('active'); });
        if (e.key == 'ArrowDown') { e.preventDefault(); mark(Math.min(items.length - 1, cur + 1)); }
        else if (e.key == 'ArrowUp') { e.preventDefault(); mark(Math.max(0, cur - 1)); }
        else if (e.key == 'Enter') { e.preventDefault(); if (items[cur]) choose(+items[cur].dataset.index); }
        else if (e.key == 'Escape') { e.preventDefault(); e.stopPropagation(); closeSelectMenu(); trigger.focus(); }
        else if (e.key == 'Tab') closeSelectMenu();
      });
      if (search) search.addEventListener('input', function () { render(search.value.trim().toLowerCase()); });
      render('');
      doc.body.appendChild(menu);
      // place under the control, flip up when there is no room
      var r = trigger.getBoundingClientRect(), mh = Math.min(menu.scrollHeight, 300), below = window.innerHeight - r.bottom - 8;
      menu.style.minWidth = Math.max(r.width, 160) + 'px';
      menu.style.left = Math.max(6, Math.min(r.left, window.innerWidth - Math.max(r.width, 160) - 6)) + 'px';
      if (below < mh && r.top > below) { menu.style.bottom = (window.innerHeight - r.top + 4) + 'px'; menu.classList.add('up'); }
      else menu.style.top = (r.bottom + 4) + 'px';
      openSelect = { menu: menu, wrap: wrap, trigger: trigger };
      wrap.classList.add('open'); trigger.setAttribute('aria-expanded', 'true');
      (search || menu).focus && (search ? search.focus() : (menu.tabIndex = -1, menu.focus()));
    }

    trigger.addEventListener('click', function (e) { e.stopPropagation(); openSelect && openSelect.wrap == wrap ? closeSelectMenu() : open(); });
    trigger.addEventListener('mousedown', function (e) { if (sel.closest('.lx-toolbar')) e.preventDefault(); });
    trigger.addEventListener('keydown', function (e) {
      if (e.key == 'ArrowDown' || e.key == 'ArrowUp') {
        e.preventDefault();
        var i = sel.selectedIndex + (e.key == 'ArrowDown' ? 1 : -1);
        while (sel.options[i] && sel.options[i].disabled) i += e.key == 'ArrowDown' ? 1 : -1;
        if (sel.options[i]) choose(i);
      } else if (e.key == 'Enter' || e.key == ' ') { e.preventDefault(); open(); }
    });
    refresh();
  }
  function enhanceSelects(root) {
    (root || doc).querySelectorAll('select').forEach(enhanceSelect);
  }
  function initSelects() {
    enhanceSelects();
    new MutationObserver(function (list) {
      list.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType == 1) { if (n.tagName == 'SELECT') enhanceSelect(n); else if (n.querySelector && n.querySelector('select')) enhanceSelects(n); } }); });
    }).observe(doc.body, { childList: true, subtree: true });
    doc.addEventListener('mousedown', function (e) { if (openSelect && !e.target.closest('.sx-menu') && !openSelect.wrap.contains(e.target)) closeSelectMenu(); });
    window.addEventListener('resize', closeSelectMenu);
    window.addEventListener('blur', closeSelectMenu);
    doc.addEventListener('scroll', function (e) { if (openSelect && !(e.target.closest && e.target.closest('.sx-menu'))) closeSelectMenu(); }, true);
  }


  /* ---------- no browser autofill / form history outside the login form ---------- */
  // unknown autocomplete tokens make browsers skip both history and saved-credential suggestions;
  // the data-* flags keep password-manager extensions (1Password, LastPass, Dashlane) away too
  function hardenInput(el) {
    if (el._sxHard || el.closest('#loginForm')) return;
    var type = (el.type || '').toLowerCase();
    if (/^(hidden|checkbox|radio|submit|button|file|image|reset|range|color)$/.test(type)) return;
    el._sxHard = true;
    el.setAttribute('autocomplete', type == 'password' ? 'new-password' : 'synapse-off');
    el.setAttribute('data-lpignore', 'true');
    el.setAttribute('data-1p-ignore', '');
    el.setAttribute('data-form-type', 'other');
  }
  function hardenInputs(root) {
    (root || doc).querySelectorAll('input, textarea').forEach(hardenInput);
    (root || doc).querySelectorAll('form:not(#loginForm)').forEach(function (f) { f.setAttribute('autocomplete', 'off'); });
  }
  function initNoAutofill() {
    hardenInputs();
    new MutationObserver(function (list) {
      list.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType == 1) { if (/^(INPUT|TEXTAREA)$/.test(n.tagName)) hardenInput(n); else if (n.querySelector) hardenInputs(n); } }); });
    }).observe(doc.body, { childList: true, subtree: true });
  }

  /* ---------- popovers / menus (template .theme-popover) ---------- */
  function closeMenus(except) {
    doc.querySelectorAll('.theme-popover.open').forEach(function (p) {
      if (p !== except) { p.classList.remove('open'); p.setAttribute('aria-hidden', 'true'); }
    });
  }

  function toggleMenu(id, anchor, force) {
    var menu = byId(id); if (!menu) return false;
    var open = force !== undefined ? force : !menu.classList.contains('open');
    closeMenus(menu);
    if (open && anchor && !menu.classList.contains('account-popover') && id != 'themePopover') {
      var r = anchor.getBoundingClientRect(), w = Math.min(menu.offsetWidth || 292, window.innerWidth - 16);
      menu.style.top = Math.min(r.bottom + 4, window.innerHeight - 120) + 'px';
      menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
      menu.style.right = 'auto';
    }
    menu.classList.toggle('open', open);
    menu.setAttribute('aria-hidden', String(!open));
    return false;
  }

  /* ---------- command palette ---------- */
  var commands = [], commandIndex = 0;
  function buildCommands() {
    var list = [];
    var add = function (title, detail, icon, key, terms, action) { list.push({ title: title, detail: detail, icon: icon, key: key, terms: terms, action: action }); };
    add(t('composemail'), t('cmd_compose_desc'), 'square-pen', 'C', 'new email write compose', function () { rc.command('compose'); });
    add(rc.gettext('inbox'), t('cmd_inbox_desc'), 'inbox', 'G I', 'all inbox reset primary', function () { goFolder('INBOX'); });
    if (rc.env.task == 'mail' && rc.message_list) {
      add(t('cmd_unread'), t('cmd_unread_desc'), 'mail', 'G U', 'unread new', function () { setFilter('UNSEEN'); });
      add(t('cmd_flagged'), t('cmd_flagged_desc'), 'star', 'G P', 'priority starred flagged important', function () { setFilter('FLAGGED'); });
      add(t('cmd_markallread'), rc.gettext('currentfolder'), 'mail-check', 'M A', 'mark all read', function () { rc.command('mark-all-read'); });
      add(t('cmd_refresh'), t('cmd_refresh_desc'), 'refresh-cw', 'R', 'refresh check new sync', function () { rc.command('checkmail'); });
      add(t('cmd_sort'), t('sortview_desc'), 'arrow-down-wide-narrow', 'S', 'sort order thread list', function () { listOptions(); });
    }
    add(rc.gettext('contacts'), t('contacts_desc'), 'contact-round', 'G C', 'contacts address book people', function () { rc.command('addressbook'); });
    add(rc.gettext('settings'), t('settings_desc'), 'settings', 'G S', 'settings preferences options', function () { rc.command('settings'); });
    add(rc.gettext('managefolders'), t('cmd_folders_desc'), 'folder-cog', 'G F', 'folders manage create', function () { rc.goto_url('settings/folders'); });
    add(rc.gettext('identities'), t('cmd_identities_desc'), 'id-card', 'G D', 'identities signature sender', function () { rc.goto_url('settings/identities'); });
    add(t('cmd_density'), t('cmd_density_desc'), 'rows-3', 'D', 'compact dense rows', toggleDensity);
    add(t('cmd_loadtheme', { theme: t('themename_warfare') }), t('theme_warfare_desc'), 'shield', '01', 'theme olive dark green', function () { applyTheme('warfare', true, true); });
    add(t('cmd_loadtheme', { theme: t('themename_blackops') }), t('theme_blackops_desc'), 'flame', '02', 'theme orange amber cyber', function () { applyTheme('blackops', true, true); });
    add(t('cmd_loadtheme', { theme: t('themename_night') }), t('theme_night_desc'), 'scan-eye', '03', 'theme terminal green', function () { applyTheme('night', true, true); });
    add(t('cmd_loadtheme', { theme: t('themename_agency') }), t('theme_agency_desc'), 'mail-check', '04', 'theme blue secure email', function () { applyTheme('agency', true, true); });
    add(t('aboutsynapsemail'), t('cmd_about_desc'), 'info', '?', 'about version credits developer', function () { about(true); });
    add(t('signout'), t('signout_desc'), 'log-out', 'Q', 'logout sign out exit', function () { rc.command('logout'); });
    return list;
  }
  function matchingCommands() {
    var q = byId('commandInput').value.trim().toLowerCase();
    return commands.filter(function (c) { return (c.title + ' ' + c.detail + ' ' + c.terms).toLowerCase().indexOf(q) > -1; });
  }
  function renderCommands() {
    var matches = matchingCommands(), listEl = byId('commandList');
    if (commandIndex >= matches.length) commandIndex = Math.max(0, matches.length - 1);
    listEl.innerHTML = matches.length ? matches.map(function (c, i) {
      return '<button class="command-item ' + (i === commandIndex ? 'active' : '') + '" role="option" aria-selected="' + (i === commandIndex) + '" data-command-index="' + commands.indexOf(c) + '"><i data-lucide="' + c.icon + '"></i><span><b>' + esc(c.title) + '</b><small>' + esc(c.detail) + '</small></span><kbd>' + c.key + '</kbd></button>';
    }).join('') : '<div class="command-empty">' + esc(t('palette_empty')) + '</div>';
    icons();
  }
  function openPalette() {
    var p = byId('commandPalette'); if (!p) return;
    closeMenus();
    p.classList.add('open'); p.setAttribute('aria-hidden', 'false');
    byId('commandInput').value = ''; commandIndex = 0; renderCommands();
    setTimeout(function () { byId('commandInput').focus(); }, 80);
  }
  function closePalette() { var p = byId('commandPalette'); if (p) { p.classList.remove('open'); p.setAttribute('aria-hidden', 'true'); } }
  function runCommand(c) { if (c) { closePalette(); c.action(); } }

  function toggleDensity() {
    var on = doc.body.classList.toggle('compact');
    store('synapsemail-density', on ? 'compact' : null);
    toast(t(on ? 'density_on' : 'density_off'));
  }

  /* ---------- scrim / overlays ---------- */
  function scrim(on) { var s = byId('scrim'); if (s) s.classList.toggle('visible', !!on); }
  function closeOverlays() {
    doc.body.classList.remove('mobile-nav-open');
    closeMenus(); closePalette();
    var ab = byId('aboutDialog'); if (ab && ab.classList.contains('open')) { ab.classList.remove('open'); ab.setAttribute('aria-hidden', 'true'); }
    if (readerOpen()) closeReader();
    var c = byId('composeDrawer');
    if (!(c && c.classList.contains('open') && !c.classList.contains('minimized'))) scrim(false);
  }


  /* ---------- security passcode: shared helpers + themed keypad ---------- */
  var coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  function api(action, data, token) {
    var opts = { method: data ? 'POST' : 'GET', credentials: 'same-origin', headers: {} };
    if (data) {
      var body = new URLSearchParams(data);
      if (token) { body.set('_token', token); opts.headers['X-Roundcube-Request'] = token; }
      opts.body = body;
    }
    return fetch('./?_task=' + (rc.env.task == 'settings' ? 'settings' : 'mail') + '&_action=plugin.synapse-' + action, opts)
      .then(function (r) { return r.json(); });
  }
  // QR code for authenticator apps (vendor/qrcode.js), as an image
  function renderQr(host, uri) {
    if (!window.qrcode) { host.textContent = t('qrunavailable'); return; }
    var q = qrcode(0, 'M'); q.addData(uri); q.make();
    var img = new Image(); img.alt = t('qralt'); img.src = q.createDataURL(4, 8);
    host.innerHTML = ''; host.appendChild(img);
  }
  // one-time display of recovery codes; done() runs after the user confirms they saved them
  function showRecoveryCodes(host, codes, done, label) {
    host.hidden = false;
    host.innerHTML = '<b>' + esc(t('saverecovery')) + '</b><small>' + esc(t('saverecovery_note')) + '</small>'
      + '<div class="otp-codes">' + codes.map(function (c) { return '<code>' + esc(c) + '</code>'; }).join('') + '</div>'
      + '<div class="otp-code-actions"><button type="button" class="ghost-btn" data-copy>' + esc(t('copy_caps')) + '</button><button type="button" class="solid-btn" data-done>' + esc(label || t('savedcodes')) + '</button></div>';
    host.querySelector('[data-copy]').onclick = function (e) {
      var b = e.currentTarget;
      navigator.clipboard.writeText(codes.join('\n')).then(function () { b.textContent = t('copied'); }, function () { b.textContent = t('copymanually'); });
    };
    host.querySelector('[data-done]').onclick = done;
  }
  function fmtDate(ts) {
    try { return new Date(ts * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return new Date(ts * 1000).toString(); }
  }

  // numeric pad: on desktop a themed keypad that lights up for physical key presses; on touch devices the system numeric keyboard
  function createKeypad(host, opts) {
    var len = opts.len || 6, value = '', busy = false;
    host.innerHTML = '<div class="pin-pad' + (coarse ? ' touch' : '') + '">'
      + '<div class="pin-slots" aria-hidden="true"></div>'
      + '<input class="pin-input" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore aria-label="' + esc(opts.label || t('passcode')) + '">'
      + '<div class="pin-keys">' + ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'clear', '0', 'back'].map(function (k) {
        return '<button type="button" tabindex="-1" class="pin-key' + (k.length > 1 ? ' pin-fn' : '') + '" data-key="' + k + '">' + (k == 'back' ? '<i data-lucide="delete"></i>' : k == 'clear' ? esc(t('clr')) : k) + '</button>';
      }).join('') + '</div></div>';
    var pad = host.firstChild, slots = pad.querySelector('.pin-slots'), input = pad.querySelector('.pin-input');
    input._sxHard = true;
    function render() {
      var html = '';
      for (var i = 0; i < len; i++) html += '<i class="' + (i < value.length ? 'filled' : '') + (i == value.length && !busy ? ' current' : '') + '"></i>';
      slots.innerHTML = html; input.value = value;
    }
    function press(k) {
      var b = pad.querySelector('[data-key="' + k + '"]'); if (!b) return;
      b.classList.remove('pressed'); void b.offsetWidth; b.classList.add('pressed');
      setTimeout(function () { b.classList.remove('pressed'); }, 180);
    }
    function complete() { if (value.length == len && opts.onComplete) opts.onComplete(value); }
    function put(k) {
      if (busy) return;
      if (/^\d$/.test(k)) { if (value.length >= len) return; value += k; render(); complete(); }
      else if (k == 'back') { value = value.slice(0, -1); render(); }
      else if (k == 'clear') { value = ''; render(); }
      if (opts.onChange) opts.onChange(value);
    }
    pad.querySelector('.pin-keys').addEventListener('mousedown', function (e) { e.preventDefault(); });
    pad.querySelector('.pin-keys').addEventListener('click', function (e) {
      var b = e.target.closest('.pin-key'); if (!b) return;
      press(b.dataset.key); put(b.dataset.key);
      if (coarse) input.focus();
    });
    input.addEventListener('input', function () {
      if (busy) { input.value = value; return; }
      var v = input.value.replace(/\D/g, '').slice(0, len);
      if (v.length > value.length) press(v.slice(-1));
      value = v; render(); complete();
      if (opts.onChange) opts.onChange(value);
    });
    function onKey(e) {
      if (!pad.isConnected || pad.offsetParent === null || e.metaKey || e.ctrlKey || e.altKey) return;
      var key = /^\d$/.test(e.key) ? e.key : e.key == 'Backspace' ? 'back' : e.key == 'Delete' || e.key == 'Escape' ? 'clear' : null;
      if (!key) return;
      if (e.target === input) { press(key); return; } // the input event updates the value
      if (/INPUT|TEXTAREA|SELECT/.test(e.target.tagName) || e.target.isContentEditable) return;
      e.preventDefault(); press(key); put(key);
    }
    doc.addEventListener('keydown', onKey);
    render(); icons();
    return {
      reset: function () { value = ''; busy = false; render(); },
      shake: function () { pad.classList.remove('shake'); void pad.offsetWidth; pad.classList.add('shake'); },
      setBusy: function (b) { busy = b; pad.classList.toggle('busy', b); render(); },
      focus: function () { if (coarse) input.focus(); else pad.focus && pad.querySelector('.pin-keys').focus(); },
      destroy: function () { doc.removeEventListener('keydown', onKey); host.innerHTML = ''; }
    };
  }

  /* ================= LOGIN ================= */
  function initLogin() {
    var form = byId('loginForm'), user = byId('rcmloginuser'), pass = byId('rcmloginpwd'),
      authShell = byId('authShell'), authError = byId('authError'), accessButton = byId('accessButton'),
      processing = byId('processingScreen'), failed = 0, busy = false;

    function setError(message, code) {
      failed++;
      authError.querySelector('small').textContent = message;
      byId('errorCode').textContent = code || 'ERR-AUTH-401';
      authError.classList.add('visible');
      doc.body.classList.remove('error-state'); void doc.body.offsetWidth; doc.body.classList.add('error-state');
      setTimeout(function () { doc.body.classList.remove('error-state'); }, 650);
      // glitch the access panel and stamp "ACCESS DENIED" over it
      var denied = authShell.querySelector('.denied-glitch');
      if (!denied) { denied = doc.createElement('div'); denied.className = 'denied-glitch'; denied.setAttribute('aria-hidden', 'true'); authShell.appendChild(denied); }
      denied.textContent = denied.dataset.text = t('accessdenied');
      authShell.classList.remove('glitch-fail'); void authShell.offsetWidth; authShell.classList.add('glitch-fail');
      clearTimeout(setError.t); setError.t = setTimeout(function () { authShell.classList.remove('glitch-fail'); }, 1500);
      if (window.anime && !reduced) anime({ targets: authShell, translateX: [0, -10, 9, -6, 4, 0], duration: 520, easing: 'easeInOutSine' });
      accessButton.querySelector('span').textContent = t('retrysignin', { n: pad(failed) });
      pass.select();
    }
    function clearError() { authError.classList.remove('visible'); accessButton.querySelector('span').textContent = t('signin_caps'); }
    // verification badges only turn VERIFIED after the server accepted the credentials
    function setVerification(id, state) {
      var el = byId(id), labels = { verified: t('verified'), checking: t('checking'), denied: t('denied'), blocked: t('blocked'), pending: t('pending') };
      el.textContent = labels[state || 'verified'];
      el.className = state && state != 'verified' ? state : 'verified';
    }
    var accepted = false, waiting = false, authAnim = null;
    function stopWaiting() { waiting = false; if (authAnim) authAnim.pause(); }
    function updateProgress(value) {
      var v = Math.min(100, Math.round(value)), stage = byId('progressStage'), detail = byId('processingDetail');
      byId('progressBar').style.width = v + '%';
      byId('progressValue').textContent = pad(v, 3) + '%';
      if (!accepted) {
        // waiting for the mail server: nothing is verified yet
        if (v < 35) { stage.textContent = t('stage_verifying'); detail.textContent = t('detail_submitting'); setVerification('verifyIdentity', 'checking'); }
        else { stage.textContent = t('stage_checking'); detail.textContent = t('detail_waiting'); setVerification('verifyClearance', 'checking'); }
        return;
      }
      setVerification('verifyIdentity');
      if (v < 90) { stage.textContent = t('stage_encrypting'); detail.textContent = t('detail_negotiating'); setVerification('verifyClearance'); }
      else { stage.textContent = t('stage_opening'); detail.textContent = t('detail_loading'); setVerification('verifyClearance'); setVerification('verifyUplink'); }
    }
    function denyProgress(done) {
      stopWaiting();
      byId('progressStage').textContent = t('stage_denied');
      byId('processingDetail').textContent = t('detail_rejected');
      setVerification('verifyIdentity', 'denied'); setVerification('verifyClearance', 'blocked'); setVerification('verifyUplink', 'blocked');
      processing.classList.add('denied');
      setTimeout(function () { processing.classList.remove('denied'); done(); }, 900);
    }
    function beginAuthentication() {
      busy = true; waiting = true; clearError();
      accessButton.querySelector('span').textContent = t('verifyingcredentials');
      doc.body.classList.add('processing');
      processing.classList.add('active'); processing.setAttribute('aria-hidden', 'false');
      // the sign-in request runs in the background; progress eases towards 70% until the server answers
      var progress = { value: 0 };
      if (window.anime && !reduced) {
        anime.timeline({ easing: 'easeOutExpo' })
          .add({ targets: processing, opacity: [0, 1], duration: 350 })
          .add({ targets: '.processing-core > *', opacity: [0, 1], translateY: [12, 0], delay: anime.stagger(55), duration: 450 }, '-=180');
        authAnim = anime({ targets: progress, value: 70, duration: 3100, easing: 'easeInOutQuart', update: function () { updateProgress(progress.value); } });
      } else {
        var started = performance.now();
        (function step(now) { var e = Math.min(1, (now - started) / 2900); if (!waiting) return; updateProgress(e * 70); if (e < 1) requestAnimationFrame(step); })(started);
      }
    }
    function boot() {
      doc.body.classList.remove('booting');
      var logs = doc.querySelectorAll('.boot-log div');
      if (window.anime && !reduced) {
        anime.timeline({ easing: 'easeOutExpo' })
          .add({ targets: '.auth-shell', opacity: [0, 1], translateY: [18, 0], duration: 850 })
          .add({ targets: '.mission-brief > *', opacity: [0, 1], translateY: [12, 0], delay: anime.stagger(70), duration: 650 }, '-=700')
          .add({ targets: logs, opacity: [0, 1], translateY: [5, 0], delay: anime.stagger(110), duration: 450 }, '-=420');
      } else {
        authShell.style.opacity = '1'; authShell.style.transform = 'none';
        logs.forEach(function (l) { l.style.opacity = '1'; l.style.transform = 'none'; });
      }
    }

    function endProcessing() {
      busy = false;
      doc.body.classList.remove('processing');
      processing.classList.remove('active'); processing.setAttribute('aria-hidden', 'true'); processing.style.opacity = '';
      ['verifyIdentity', 'verifyClearance', 'verifyUplink'].forEach(function (id) { setVerification(id, 'pending'); });
      accepted = false;
      byId('progressBar').style.width = '0%';
      accessButton.disabled = false;
    }
    function finishProgress(done) {
      stopWaiting(); accepted = true;
      var from = parseInt(byId('progressValue').textContent, 10) || 0, start = performance.now();
      (function step(now) { var e = Math.min(1, (now - start) / 450); updateProgress(from + (100 - from) * e); if (e < 1) requestAnimationFrame(step); else setTimeout(done, 260); })(start);
    }


    /* second factor: passcode keypad inside the processing screen (no page reload) */
    var keypad = null;
    function refreshLoginToken() {
      return fetch('./?_task=login', { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
        var t = /name="_token" value="([^"]+)"/.exec(html);
        if (t && form.elements._token) form.elements._token.value = t[1];
      });
    }
    function leavePinStage(message, code) {
      if (keypad) { keypad.destroy(); keypad = null; }
      byId('pinStage').hidden = true; processing.classList.remove('pin-mode');
      refreshLoginToken().then(function () { denyProgress(function () { endProcessing(); setError(message, code); }); });
    }
    function showPinStage(info, token, target) {
      stopWaiting();
      var stageEl = byId('pinStage'), msg = byId('pinMessage');
      processing.classList.add('pin-mode'); stageEl.hidden = false;
      setVerification('verifyIdentity');
      setVerification('verifyClearance', 'checking');
      byId('progressBar').style.width = '55%'; byId('progressValue').textContent = '055%';
      byId('pinHint').hidden = true; byId('pinErase').hidden = true; msg.textContent = '';
      byId('pinSignOut').onclick = function () { location.href = './?_task=logout&_token=' + encodeURIComponent(token); };
      if (info.state == 'locked') {
        byId('processingTitle').textContent = t('accountlocked');
        byId('progressStage').textContent = t('stage_locked');
        byId('processingDetail').textContent = t('lockeduntil', { date: fmtDate(info.locked_until) });
        setVerification('verifyClearance', 'denied'); setVerification('verifyUplink', 'blocked');
        byId('pinPadHost').innerHTML = ''; byId('pinForgot').hidden = true; byId('pinTries').textContent = t('locked_caps');
        return;
      }
      byId('processingTitle').textContent = t('securitypasscode_caps');
      stageHead(t('stagehead_pin'));
      processing.querySelector('.processing-kicker').textContent = t('passwordaccepted_kicker');
      byId('progressStage').textContent = t('stage_secondfactor');
      byId('processingDetail').textContent = t('enterpasscode', { len: info.len });
      byId('pinTries').textContent = t('triesleft', { n: info.tries_left });
      byId('pinForgot').hidden = false;
      keypad = createKeypad(byId('pinPadHost'), { len: info.len, label: t('securitypasscode'), onChange: function () { msg.textContent = ''; }, onComplete: function (code) {
        keypad.setBusy(true);
        api('pin-verify', { _pin: code }, token).then(function (res) {
          if (res.ok) {
            setVerification('verifyClearance');
            byId('progressStage').textContent = t('stage_encrypting');
            stageEl.hidden = true; processing.classList.remove('pin-mode');
            keypad.destroy(); keypad = null;
            gateContinue(res.next, target);
          } else if (res.signedout) {
            leavePinStage(res.message || t('pin_toomany'), res.locked_until ? 'ERR-LOCK-423' : 'ERR-PIN-401');
          } else {
            keypad.shake(); keypad.reset();
            msg.textContent = (res.message || t('pin_wrong')).toUpperCase() + (res.left ? ' · ' + t('triesleft', { n: res.left }) : '');
            byId('pinTries').textContent = t('triesleft', { n: res.left || 0 });
          }
        }, function () { keypad.reset(); msg.textContent = t('connectionerror'); });
      } });
      keypad.focus();
      byId('pinForgot').onclick = function () {
        api('pin-forgot', { _do: 'hint' }, token).then(function (res) {
          var h = byId('pinHint');
          h.hidden = false;
          h.innerHTML = '<b>' + esc(t('yourhint')) + '</b><span></span><small>' + esc(t('hint_forgot')) + '</small>';
          h.querySelector('span').textContent = res.hint || '-';
          byId('pinErase').hidden = false; byId('pinForgot').hidden = true;
        });
      };
      byId('pinErase').onclick = function () {
        var b = byId('pinErase');
        if (!b.classList.contains('confirm')) { b.classList.add('confirm'); b.textContent = t('confirmerase'); return; }
        api('pin-forgot', { _do: 'erase' }, token).then(function (res) {
          leavePinStage(t('passcodeerased', { date: fmtDate(res.locked_until) }), 'ERR-LOCK-423');
        });
      };
    }

    /* second factor: authenticator app (TOTP), enrolment when the server requires it */
    function stageHead(text) { var h = doc.querySelector('#pinStage .pin-stage-head span'); if (h) h.textContent = text; }
    function showGate(info, token, target) {
      if (info.factor == 'otp') return showOtpStage(info, token, target);
      if (info.factor == 'enroll') return showEnrollStage(info, token, target);
      showPinStage(info, token, target);
    }
    function gateContinue(next, target) {
      if (keypad) { keypad.destroy(); keypad = null; }
      if (next && next.state) return showGate(next, next.token, target);
      setVerification('verifyClearance');
      byId('progressStage').textContent = t('stage_encrypting');
      byId('pinStage').hidden = true; processing.classList.remove('pin-mode');
      accepted = true; finishProgress(function () { location.href = target; });
    }
    function otpStageBase(title, detail, head) {
      stopWaiting();
      processing.classList.add('pin-mode'); byId('pinStage').hidden = false;
      setVerification('verifyIdentity'); setVerification('verifyClearance', 'checking');
      byId('progressBar').style.width = '55%'; byId('progressValue').textContent = '055%';
      byId('processingTitle').textContent = title;
      processing.querySelector('.processing-kicker').textContent = t('passwordaccepted_kicker');
      byId('progressStage').textContent = t('stage_secondfactor');
      byId('processingDetail').textContent = detail;
      stageHead(head);
      byId('pinMessage').textContent = ''; byId('pinHint').hidden = true; byId('pinHint').innerHTML = '';
      byId('pinMessage').after(byId('pinHint')); // default place: under the keypad (enrolment moves it above)
      byId('pinErase').hidden = true; byId('pinForgot').hidden = true; byId('pinTries').textContent = '';
    }
    function showOtpStage(info, token, target) {
      var msg = byId('pinMessage');
      otpStageBase(t('authenticatorcode_caps'), t('enterotp'), t('stagehead_otp'));
      byId('pinTries').textContent = t('triesleft', { n: info.tries_left });
      byId('pinSignOut').onclick = function () { location.href = './?_task=logout&_token=' + encodeURIComponent(token); };
      var submit = function (code, done) {
        api('otp-verify', { _code: code }, token).then(function (res) {
          if (res.ok) {
            if (res.recovery_used) toast(t('recoveryused', { n: res.recovery_left }));
            return gateContinue(res.next, target);
          }
          if (res.signedout) return leavePinStage(res.message || t('otp_toomany'), res.locked_until ? 'ERR-LOCK-423' : 'ERR-2FA-401');
          msg.textContent = (res.message || t('otp_wrong')).toUpperCase() + (res.left ? ' · ' + t('triesleft', { n: res.left }) : '');
          byId('pinTries').textContent = t('triesleft', { n: res.left || 0 });
          done(false);
        }, function () { msg.textContent = t('connectionerror'); done(false); });
      };
      keypad = createKeypad(byId('pinPadHost'), { len: 6, label: t('authenticatorcode'), onChange: function () { msg.textContent = ''; }, onComplete: function (code) {
        keypad.setBusy(true);
        submit(code, function () { keypad.shake(); keypad.reset(); });
      } });
      keypad.focus();
      // lost phone: one-time recovery code instead of the keypad
      var alt = byId('pinForgot');
      alt.hidden = false; alt.textContent = t('userecovery');
      alt.onclick = function () {
        var h = byId('pinHint');
        h.hidden = false; alt.hidden = true;
        h.innerHTML = '<b>' + esc(t('recoverycode_caps')) + '</b><input class="otp-recovery-input" type="text" maxlength="9" autocomplete="off" spellcheck="false" placeholder="XXXX-XXXX" aria-label="' + esc(t('recoverycode')) + '"><button type="button" class="solid-btn">' + esc(t('usecode')) + '</button><small>' + esc(t('recovery_note')) + '</small>';
        var input = h.querySelector('input'), btn = h.querySelector('button');
        var go = function () { if (!input.value.trim()) return; btn.disabled = true; submit(input.value, function () { btn.disabled = false; input.select(); }); };
        btn.onclick = go; input.onkeydown = function (e) { if (e.key === 'Enter') go(); };
        input.focus();
      };
    }
    function showEnrollStage(info, token, target) {
      var msg = byId('pinMessage'), hint = byId('pinHint');
      otpStageBase(t('setup2fa'), t('enroll_detail'), t('stagehead_enroll'));
      byId('pinSignOut').onclick = function () { location.href = './?_task=logout&_token=' + encodeURIComponent(token); };
      byId('pinPadHost').innerHTML = '';
      api('otp-enroll', { _do: 'start' }, token).then(function (res) {
        if (!res.ok) { msg.textContent = (res.message || t('setupunavailable')).toUpperCase(); return; }
        hint.hidden = false;
        byId('pinPadHost').before(hint); // scan first, then type: QR above the keypad
        hint.innerHTML = '<b>' + esc(t('scanapp')) + '</b><div class="otp-qr"></div><small>' + esc(t('cantscan')) + ' <code class="otp-secret"></code></small>';
        hint.querySelector('.otp-secret').textContent = res.secret.replace(/(.{4})/g, '$1 ').trim();
        renderQr(hint.querySelector('.otp-qr'), res.uri);
        keypad = createKeypad(byId('pinPadHost'), { len: 6, label: t('authenticatorcode'), onChange: function () { msg.textContent = ''; }, onComplete: function (code) {
          keypad.setBusy(true);
          api('otp-enroll', { _do: 'confirm', _code: code }, token).then(function (r) {
            if (!r.ok) { keypad.shake(); keypad.reset(); msg.textContent = (r.message || t('otp_wrong')).toUpperCase(); if (r.restart) showEnrollStage(info, token, target); return; }
            keypad.destroy(); keypad = null; byId('pinPadHost').innerHTML = '';
            showRecoveryCodes(hint, r.codes, function () { gateContinue(r.next, target); });
          }, function () { keypad.reset(); msg.textContent = t('connectionerror'); });
        } });
        keypad.focus();
      });
    }

    // sign in with a background request so the page (and its animations) never reloads mid-check
    function signIn() {
      var started = Date.now(), wait = function (fn) { setTimeout(fn, Math.max(0, 1400 - (Date.now() - started))); };
      fetch(form.getAttribute('action') || location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin', redirect: 'manual' })
        .then(function (r) {
          // success is the core's redirect to the mailbox
          if (r.type == 'opaqueredirect' || (r.redirected && !/_task=login/.test(r.url))) {
            var target = byId('rcmloginurl').value;
            target = './?' + (target && !/_task=log(in|out)/.test(target) ? target : '_task=mail');
            // password accepted: a security passcode (or an account lock) may still stand in the way
            return api('pin-status').then(function (info) {
              wait(function () {
                if (info && info.state) showGate(info, info.token, target);
                else finishProgress(function () { location.href = target; });
              });
            }, function () { wait(function () { finishProgress(function () { location.href = target; }); }); });
          }
          return r.text().then(function (html) {
            // failed: take the fresh request token and the server's message from the returned login page
            var token = /name="_token" value="([^"]+)"/.exec(html), msg = /display_message\("((?:[^"\\]|\\.)*)",\s*"(warning|error)"/.exec(html);
            if (token && form.elements._token) form.elements._token.value = token[1];
            var text = msg ? JSON.parse('"' + msg[1] + '"') : t('signinnotaccepted');
            var code = /too many|locked|rate/i.test(text) ? 'ERR-RATE-429' : /login|auth|fail|invalid/i.test(text) ? 'ERR-AUTH-401' : (/cookie/i.test(text) ? 'ERR-COOKIE-403' : 'ERR-GATEWAY-' + r.status);
            wait(function () { denyProgress(function () { endProcessing(); setError(text, code); }); });
          });
        })
        .catch(function () { form.submit(); });
    }

    form.addEventListener('submit', function (event) {
      // the core login handler (disable button + loading message) is not used: we post in the background
      event.preventDefault(); event.stopImmediatePropagation();
      if (busy) return;
      if (!user.value.trim() || !pass.value) {
        setError(t('bothrequired'), 'ERR-INPUT-002');
        (!user.value.trim() ? user : pass).focus();
        return;
      }
      user.value = user.value.trim();
      store('synapsemail-user', byId('rememberStation').checked ? user.value : null);
      accessButton.disabled = true;
      beginAuthentication();
      signIn();
    });

    byId('togglePassword').addEventListener('click', function (e) {
      var visible = pass.type === 'text';
      pass.type = visible ? 'password' : 'text';
      e.currentTarget.setAttribute('aria-label', t(visible ? 'showpassword' : 'hidepassword'));
      e.currentTarget.innerHTML = '<i data-lucide="' + (visible ? 'eye' : 'eye-off') + '"></i>';
      icons(); pass.focus();
    });
    byId('recoveryButton').addEventListener('click', function () { toast(t('contactadmin')); });
    doc.querySelectorAll('.theme-dot').forEach(function (d) { d.addEventListener('click', function () { applyTheme(d.dataset.theme, false, false); persistTheme(d.dataset.theme); }); });
    [user, pass].forEach(function (i) { i.addEventListener('input', clearError); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !busy) { clearError(); pass.value = ''; } });

    // server-side login errors arrive as rcmail messages
    rc.addEventListener('message', function (p) {
      if (p.type == 'error' || p.type == 'warning') {
        var code = /too many|locked|rate/i.test(p.message) ? 'ERR-RATE-429' : /login|auth|fail|invalid/i.test(p.message) ? 'ERR-AUTH-401' : (/cookie/i.test(p.message) ? 'ERR-COOKIE-403' : 'ERR-GATEWAY-503');
        setTimeout(function () { setError(p.message, code); }, 900);
        p.object && $(p.object).hide();
      } else if (p.type == 'loading' && p.object) {
        $(p.object).hide();
      } else if (p.message) {
        // e.g. "session terminated" after logout: the core message stack is hidden on this page
        setTimeout(function () { toast(p.message); }, 900);
      }
    });

    var remembered = store('synapsemail-user');
    if (remembered && !user.value) { user.value = remembered; byId('rememberStation').checked = true; }
    var tag = byId('sessionTag');
    if (tag) tag.textContent = Array.from({ length: 3 }, function () { return Math.floor(Math.random() * 256).toString(16).toUpperCase().padStart(2, '0'); }).join(':');
    try {
      var nav = performance.getEntriesByType('navigation')[0];
      if (nav && nav.responseStart) byId('latencyValue').textContent = Math.max(1, Math.round(nav.responseStart - nav.requestStart)) + ' MS';
    } catch (e) { }
    updateClock(); setInterval(updateClock, 1000);
    if (doc.readyState == 'complete') boot(); else window.addEventListener('load', boot);

    // full-page gate (a signed-in session that still owes its passcode)
    if (rc.env.synapse_gate && rc.env.synapse_gate.state) {
      busy = true;
      doc.body.classList.add('processing');
      processing.classList.add('active'); processing.setAttribute('aria-hidden', 'false'); processing.style.opacity = '1';
      showGate(rc.env.synapse_gate, rc.env.synapse_gate.token, './?_task=mail');
    }
  }

  /* ================= MAIL ================= */
  var specialIcons = { inbox: 'inbox', drafts: 'file-pen-line', sent: 'send', junk: 'shield-alert', trash: 'trash-2', archive: 'archive', outbox: 'send-horizontal' };
  var markers = ['amber', 'cyan', 'red', 'green', 'white', 'gray'];

  function decorateFolders() {
    var ul = byId('mailboxlist'); if (!ul) return;
    var custom = 0;
    $(ul).find('li.mailbox').each(function () {
      var li = this, a = $(li).children('a').get(0); if (!a || a.dataset.synapse) return;
      a.dataset.synapse = 1;
      var type = Object.keys(specialIcons).filter(function (k) { return li.classList.contains(k); })[0];
      // wrap the folder name text node so it lines up with the template grid
      $(a).contents().filter(function () { return this.nodeType === 3 && this.nodeValue.trim(); }).wrap('<span class="folder-name"></span>');
      if (type || !$(li).parent().is(ul)) {
        a.classList.add('folder-link');
        $(a).prepend('<i data-lucide="' + (specialIcons[type] || 'folder') + '"></i>');
      } else {
        a.classList.add('label-link');
        $(a).prepend('<span class="label-marker ' + markers[custom++ % markers.length] + '"></span>');
      }
    });
    if (custom && !$(ul).children('.nav-divider').length) {
      $(ul).children('li.mailbox').filter(function () { return $(this).children('a').hasClass('label-link'); }).first()
        .before('<li class="nav-divider" role="presentation"><span>' + esc(t('smartlabels')) + '</span><button type="button" aria-label="' + esc(t('createfolder')) + '" title="' + esc(t('createfolder')) + '" onclick="rcmail.goto_url(\'settings/folders\')"><i data-lucide="plus"></i></button></li>');
    }
    icons();
  }

  function decorateDirectory() {
    $('#directorylist li, #savedsearchlist li').each(function () {
      var a = $(this).children('a').get(0); if (!a || a.dataset.synapse) return;
      a.dataset.synapse = 1;
      var group = this.classList.contains('contactgroup'), search = $(this).closest('#savedsearchlist').length;
      $(a).contents().filter(function () { return this.nodeType === 3 && this.nodeValue.trim(); }).wrap('<span class="folder-name"></span>');
      $(a).addClass(group || search ? 'label-link' : 'folder-link')
        .prepend(group || search ? '<span class="label-marker ' + (search ? 'amber' : 'cyan') + '"></span>' : '<i data-lucide="book-user"></i>');
    });
    icons();
  }

  function goFolder(mbox) {
    if (rc.env.task == 'mail' && rc.message_list) {
      if (rc.env.search_request || byId('searchInput') && byId('searchInput').value) { byId('searchInput').value = ''; rc.command('reset-search'); }
      var tab = doc.querySelector('.category-tab.active');
      closeOverlays();
      if (tab && tab.dataset.filter != 'ALL') { setFilter('ALL'); if (rc.env.mailbox == mbox) return; }
      rc.command('list', mbox);
    } else location.href = './?_task=mail&_mbox=' + encodeURIComponent(mbox);
  }

  function cell(row, name) {
    for (var i = 0; i < row.cols.length; i++) if (row.cols[i].className.split(' ')[0] == name) return row.cols[i].innerHTML;
    return '';
  }

  // Render each list row with the template's .message-row structure (rows stay <tr> for rcube_list_widget)
  function messageRow(row, uid, message) {
    var tr = doc.createElement('tr'), subject = cell(row, 'subject'), from = cell(row, 'fromto') || cell(row, 'from'),
      preview = message.preview || '', date = cell(row, 'date'), attach = cell(row, 'attachment'), folder = cell(row, 'folder'),
      senderText = $('<div>' + from + '</div>').text().trim() || ' ',
      flags = [], badge = '';
    tr.id = row.id; tr.uid = row.uid;
    tr.className = row.className + ' message-row' + (message.prio && message.prio < 3 ? ' priority' : '');
    if (row.style) $.extend(tr.style, row.style);
    if (message.replied) flags.push('replied');
    if (message.forwarded) flags.push('forwarded');
    if (folder) badge = '<span class="channel-badge">' + folder + '</span>';
    else if (message.prio && message.prio < 3) badge = '<span class="channel-badge CYBER">' + esc(t('badge_urgent')) + '</span>';
    else if (message.replied) badge = '<span class="channel-badge">' + esc(t('badge_replied')) + '</span>';
    else if (message.forwarded) badge = '<span class="channel-badge COMMAND">' + esc(t('badge_fwd')) + '</span>';
    // the msgicn/flagicn ids let rcmail keep these icons in sync and handle clicks on them
    tr.innerHTML = '<td class="row-cells">'
      + '<label class="tactical-check row-check" aria-label="' + esc(t('selectmessage')) + '"><input type="checkbox" tabindex="-1"><span></span></label>'
      + '<button class="star-btn" type="button" tabindex="-1" aria-label="' + esc(t('toggleflag')) + '"><span id="flagicn' + row.id + '"></span><i data-lucide="star"></i></button>'
      + '<button class="pin-btn" type="button" tabindex="-1" aria-label="' + esc(t('toggleread')) + '"><span id="msgicn' + row.id + '"></span><i data-lucide="chevrons-right"></i></button>'
      + '<div class="sender">' + (message.depth ? '<span class="thread-indent" style="width:' + (message.depth * 12) + 'px"></span>' : '')
      + (message.has_children ? '<span id="rcmexpando' + row.id + '" class="thread-toggle ' + (message.expanded ? 'expanded' : 'collapsed') + '"></span>' : '')
      + '<span class="sender-name" title="' + esc(senderText) + '">' + esc(senderText) + '</span></div>'
      + '<div class="message-content">' + badge + '<span class="subject">' + subject + '</span>'
      + (preview ? '<span class="preview">· ' + preview + '</span>' : '')
      + (attach && /class="(attachment|report|encrypted)"/.test(attach) ? '<span class="attachment-mark"><i data-lucide="paperclip"></i></span>' : '')
      + '</div><time class="message-time">' + date + '</time></td>';
    return tr;
  }

  function updateBulk() {
    var list = rc.message_list; if (!list) return;
    var n = list.get_selection(false).length, total = list.rowcount;
    byId('selectedCount').textContent = t('selectedcount', { n: n });
    byId('bulkActions').classList.toggle('visible', n > 0);
    var all = byId('selectAll');
    all.checked = total > 0 && n >= total;
    all.indeterminate = n > 0 && n < total;
  }

  function updateRange() {
    var label = byId('rangeLabel'); if (!label) return;
    var count = rc.env.messagecount || 0, size = rc.env.pagesize || 50, page = rc.env.current_page || 1,
      first = count ? (page - 1) * size + 1 : 0, last = Math.min(count, page * size);
    label.textContent = count ? t('rangeof', { a: pad(first) + '–' + pad(last), b: count.toLocaleString('en-US') }) : t('rangeof', { a: '00', b: 0 });
    var empty = byId('emptyState');
    if (empty) empty.hidden = !!(rc.message_list && rc.message_list.rowcount);
  }

  function setFilter(filter) {
    doc.querySelectorAll('.category-tab').forEach(function (t) { t.classList.toggle('active', t.dataset.filter == filter); });
    rc.filter_mailbox(filter);
  }

  function updateUnread() {
    var n = (rc.env.unread_counts || {}).INBOX || 0, badge = byId('railUnread'), status = byId('statusUnread'), info = byId('tabUnreadInfo');
    if (badge) { badge.hidden = !n; badge.textContent = n > 99 ? '99+' : n; }
    if (status) status.textContent = n;
    var cur = (rc.env.unread_counts || {})[rc.env.mailbox] || 0;
    if (info) info.textContent = cur ? t('unreadinfolder', { n: cur }) : t('nounread');
  }

  function setQuota(q) {
    // only the mail sidebar has the storage card; this runs from framed pages too, so never throw
    if (!q || !q.total || !byId('quotaState')) return;
    var pct = q.percent || 0, bars = doc.querySelectorAll('#quotaBars i'), lit = Math.max(1, Math.ceil(pct / 20));
    bars.forEach(function (b, i) { b.style.background = i < lit ? (pct > 90 ? 'var(--red)' : pct > 75 ? 'var(--amber)' : 'var(--green)') : '#30362e'; });
    byId('quotaState').textContent = t('quotaused', { pct: pct });
    byId('quotaText').textContent = (q.title || '').replace(/\s*\(.*\)$/, '') || t('accountverified');
  }

  /* reader pane (preview frame) */
  function readerOpen() { var s = byId('mailStage'); return s && s.classList.contains('reader-active') && !s.classList.contains('reader-full'); }
  function openReader(cls) {
    var stage = byId('mailStage'); if (!stage || stage.classList.contains('reader-full')) return;
    stage.classList.add('reader-active');
    byId('readerDrawer').setAttribute('aria-hidden', 'false');
    if (cls) byId('readerClass').textContent = cls;
    if (window.innerWidth <= 1100) scrim(true);
  }
  function closeReader() {
    var stage = byId('mailStage'); if (!stage || stage.classList.contains('reader-full')) return;
    stage.classList.remove('reader-active', 'reader-max');
    byId('readerDrawer').setAttribute('aria-hidden', 'true');
    if (rc.message_list) rc.message_list.clear_selection();
    if (rc.env.contentframe) rc.show_contentframe(false);
    scrim(false);
  }

  /* compose drawer */

  /* ---------- desktop reading: maximize the reader, or pop messages out into draggable windows ---------- */
  var desktop = function () { return window.innerWidth > 1100; };
  var msgWindows = [], windowZ = 90, windowSeq = 0;

  function readerMaximize(force) {
    var stage = byId('mailStage'); if (!stage) return;
    var on = stage.classList.toggle('reader-max', force !== undefined ? force : !stage.classList.contains('reader-max'));
    var b = byId('readerMaximize');
    if (b) { b.title = on ? t('restoresplit') : t('maximize'); b.innerHTML = '<i data-lucide="' + (on ? 'minimize-2' : 'maximize-2') + '"></i>'; icons(); }
  }

  function raiseWindow(w) { w.style.zIndex = ++windowZ; msgWindows.forEach(function (x) { x.classList.toggle('focused', x === w); }); }

  function popOut(uid, mbox) {
    if (!uid) return false;
    mbox = mbox || rc.get_message_mailbox(uid) || rc.env.mailbox;
    // the same message is already open: bring it forward
    var existing = msgWindows.filter(function (w) { return w.dataset.uid == uid && w.dataset.mbox == mbox; })[0];
    if (existing) { existing.classList.remove('minimized'); raiseWindow(existing); return false; }
    if (msgWindows.length >= 6) closeWindow(msgWindows[0]);

    var n = ++windowSeq, w = doc.createElement('section'), offset = (msgWindows.length % 5) * 28;
    var width = Math.min(680, window.innerWidth - 120), height = Math.min(Math.round(window.innerHeight * 0.72), window.innerHeight - 110);
    w.className = 'msg-window loading';
    w.dataset.uid = uid; w.dataset.mbox = mbox;
    w.setAttribute('role', 'dialog'); w.setAttribute('aria-label', t('emailwindow'));
    w.style.width = width + 'px'; w.style.height = height + 'px';
    w.style.left = Math.max(12, Math.round((window.innerWidth - width) / 2) - 60 + offset) + 'px';
    w.style.top = Math.max(76, 90 + offset) + 'px';
    var tMin = esc(t('minimize')), tMax = esc(t('maximize')), tClose = esc(rc.gettext('close'));
    w.innerHTML = '<div class="drawer-header"><div><span>' + esc(t('emailwindow_kicker', { n: pad(n) })) + '</span><h2 class="msg-window-title">' + esc(t('loading_caps')) + '</h2></div><div>'
      + '<button type="button" data-act="min" title="' + tMin + '" aria-label="' + tMin + '"><i data-lucide="minus"></i></button>'
      + '<button type="button" data-act="max" title="' + tMax + '" aria-label="' + tMax + '"><i data-lucide="maximize-2"></i></button>'
      + '<button type="button" data-act="close" title="' + tClose + '" aria-label="' + tClose + '"><i data-lucide="x"></i></button></div></div>'
      + '<div class="msg-window-body"><div class="compose-loader" aria-hidden="true"><div class="scan-emblem"><div></div><span><i data-lucide="mail-open"></i></span></div><span class="processing-kicker">' + esc(t('securechannel')) + '</span><strong>' + esc(t('decrypting')) + '</strong><div class="progress-track"><i></i></div></div>'
      + '<iframe class="msg-window-frame" title="' + esc(rc.gettext('email')) + '"></iframe></div>';
    doc.body.appendChild(w);
    msgWindows.push(w);
    raiseWindow(w);
    icons();

    var frame = w.querySelector('iframe');
    frame.addEventListener('load', function () {
      w.classList.remove('loading');
      try {
        var d = frame.contentDocument;
        d.addEventListener('pointerdown', function () { raiseWindow(w); });
        var subj = d.getElementById('readerSubject');
        w.querySelector('.msg-window-title').textContent = (subj ? subj.textContent.trim() : d.title.replace(/^.*?::\s*/, '')) || t('email_caps');
      } catch (e) { }
    });
    frame.src = rc.url('mail/preview', { _uid: uid, _mbox: mbox, _framed: 1 });

    w.addEventListener('pointerdown', function () { raiseWindow(w); });
    w.querySelector('.drawer-header').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-act]'); if (!b) return;
      if (b.dataset.act == 'close') return closeWindow(w);
      if (b.dataset.act == 'min') { w.classList.remove('maximized'); w.classList.toggle('minimized'); }
      if (b.dataset.act == 'max') {
        w.classList.remove('minimized');
        var on = w.classList.toggle('maximized');
        b.innerHTML = '<i data-lucide="' + (on ? 'minimize-2' : 'maximize-2') + '"></i>'; b.title = on ? rc.gettext('restore') : t('maximize'); icons();
      }
    });
    w.querySelector('.drawer-header').addEventListener('dblclick', function (e) { if (!e.target.closest('button')) w.querySelector('[data-act="max"]').click(); });
    dragWindow(w);
    return false;
  }

  function closeWindow(w) {
    msgWindows = msgWindows.filter(function (x) { return x !== w; });
    w.classList.add('closing');
    setTimeout(function () { w.remove(); }, 220);
  }

  // drag by the header; iframes are made inert while dragging so they do not swallow the pointer
  function dragWindow(w) {
    var head = w.querySelector('.drawer-header');
    head.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 || e.target.closest('button') || w.classList.contains('maximized')) return;
      var r = w.getBoundingClientRect(), dx = e.clientX - r.left, dy = e.clientY - r.top;
      head.setPointerCapture(e.pointerId);
      doc.body.classList.add('window-dragging');
      function move(ev) {
        var x = Math.min(Math.max(ev.clientX - dx, 8 - r.width + 120), window.innerWidth - 120);
        var y = Math.min(Math.max(ev.clientY - dy, 4), window.innerHeight - 54);
        w.style.left = x + 'px'; w.style.top = y + 'px';
      }
      function up() {
        doc.body.classList.remove('window-dragging');
        head.removeEventListener('pointermove', move); head.removeEventListener('pointerup', up); head.removeEventListener('pointercancel', up);
      }
      head.addEventListener('pointermove', move); head.addEventListener('pointerup', up); head.addEventListener('pointercancel', up);
    });
  }

  function initReaderWindows() {
    if (!byId('readerPopout')) return;
    byId('readerMaximize').addEventListener('click', function () { readerMaximize(); });
    byId('readerPopout').addEventListener('click', function () {
      var uid = rc.message_list && rc.message_list.get_single_selection();
      if (!uid) return;
      popOut(uid);
      closeReader();
    });
    // desktop: double-click opens the message in its own window instead of the full page view
    var dbl = rc.msglist_dbl_click;
    rc.msglist_dbl_click = function (list) {
      var uid = list.get_single_selection();
      if (desktop() && uid && rc.get_message_mailbox(uid) != rc.env.drafts_mailbox) {
        if (rc.preview_timer) clearTimeout(rc.preview_timer);
        popOut(uid);
        return;
      }
      return dbl.apply(rc, arguments);
    };
  }

  function composeWindow() { var f = byId('composeFrame'); try { return f && f.contentWindow; } catch (e) { return null; } }
  function openCompose(url) {
    var drawer = byId('composeDrawer'); if (!drawer) return rc.redirect(url);
    closeMenus(); closePalette();
    doc.body.classList.remove('mobile-nav-open');
    if (readerOpen() && window.innerWidth <= 1100) closeReader();
    var frame = byId('composeFrame');
    clearTimeout(openCompose.guard);
    openCompose.guard = setTimeout(composeReady, 20000);
    url += (url.indexOf('?') > -1 ? '&' : '?') + '_framed=1';
    byId('composeKicker').textContent = t(/_reply_uid/.test(url) ? 'kicker_reply' : /_forward_uid/.test(url) ? 'kicker_forward' : /_draft_uid/.test(url) ? 'kicker_draft' : 'kicker_new');
    frame.src = url;
    drawer.classList.remove('minimized');
    drawer.classList.add('open', 'loading');
    drawer.setAttribute('aria-hidden', 'false');
    scrim(true);
  }
  function composeReady() { var d = byId('composeDrawer'); if (d) d.classList.remove('loading'); clearTimeout(openCompose.guard); }
  function composeDirty() {
    var w = composeWindow();
    try { return w && w.rcmail && w.rcmail.env.action == 'compose' && !w.rcmail.compose_skip_unsavedcheck && w.rcmail.cmp_hash != w.rcmail.compose_field_hash(); } catch (e) { return false; }
  }
  function closeCompose(force, refresh) {
    var drawer = byId('composeDrawer'); if (!drawer || !drawer.classList.contains('open')) return;
    if (!force && composeDirty()) {
      return confirmBox(t('discardtitle'), t('discardtext'), [
        { label: t('discard_caps'), action: function () { closeCompose(true); } },
        { label: t('savedraft_caps'), primary: true, action: function () { var w = composeWindow(); w.rcmail.command('savedraft'); UI._closeAfterSave = true; } }
      ]);
    }
    drawer.classList.remove('open', 'minimized', 'expanded');
    drawer.setAttribute('aria-hidden', 'true');
    scrim(false);
    setTimeout(function () {
      byId('composeFrame').src = 'about:blank';
      // requests started from inside the closing frame never finish and leave rcmail busy: refresh from this window
      if (refresh && rc.env.task == 'mail' && rc.message_list) rc.command('checkmail');
    }, 300);
  }

  /* template-styled confirm (compose drawer chrome) */
  function confirmBox(title, text, buttons) {
    var box = byId('synapseConfirm');
    if (!box) { box = doc.createElement('section'); box.id = 'synapseConfirm'; box.className = 'compose-drawer synapse-dialog'; doc.body.appendChild(box); }
    box.innerHTML = '<div class="drawer-header"><div><span>' + esc(t('confirm_kicker')) + '</span><h2>' + esc(title) + '</h2></div><div><button data-close aria-label="' + esc(rc.gettext('close')) + '"><i data-lucide="x"></i></button></div></div>'
      + '<div class="dialog-copy">' + esc(text) + '</div><div class="compose-footer">' + buttons.map(function (b, i) {
        return '<button type="button" data-i="' + i + '" class="' + (b.primary ? 'transmit-btn' : 'attach-btn') + '">' + esc(b.label) + '</button>';
      }).join('') + '</div>';
    box.classList.add('open');
    icons();
    box.onclick = function (e) {
      var b = e.target.closest('button'); if (!b) return;
      box.classList.remove('open');
      if (b.dataset.i) buttons[b.dataset.i].action();
    };
    return false;
  }

  function about(open) {
    var d = byId('aboutDialog');
    if (!d) { rc.goto_url('settings/about'); return false; }
    open = open !== false && !d.classList.contains('open');
    closeMenus();
    d.classList.toggle('open', open); d.setAttribute('aria-hidden', String(!open));
    scrim(open);
    return false;
  }

  function listOptions() {
    var sort = byId('listoptions-sortcol'); if (!sort) return;
    sort.value = rc.env.sort_col || '';
    byId('listoptions-sortord').value = rc.env.sort_order || 'DESC';
    if (byId('listoptions-threads')) byId('listoptions-threads').value = rc.env.threading ? 'threads' : 'list';
    toggleMenu('listOptionsMenu', byId('moreButton'), true);
  }
  function saveListOptions() {
    var t = byId('listoptions-threads');
    rc.set_list_options(null, byId('listoptions-sortcol').value, byId('listoptions-sortord').value, t && t.value == 'threads' ? 1 : 0);
    closeMenus();
  }

  function search() {
    if (rc.env.task == 'settings') return false;
    rc.command('search');
    return false;
  }

  function initSearchMenu() {
    var mods = byId('searchMods'); if (!mods) return;
    var sync = function () {
      var m = (rc.env.search_mods || {})[rc.env.mailbox] || (rc.env.search_mods || {})['*'] || {};
      mods.querySelectorAll('input').forEach(function (i) { i.checked = !!m[i.value]; });
      byId('s_scope').value = rc.env.search_scope || 'base';
      if (byId('s_interval')) byId('s_interval').value = rc.env.search_interval || '';
    };
    mods.addEventListener('change', function (e) { rc.set_searchmods && rc.set_searchmods(e.target.value ? collectMods() : {}); });
    function collectMods() { var m = {}; mods.querySelectorAll('input:checked').forEach(function (i) { m[i.value] = 1; }); return m; }
    byId('s_scope').addEventListener('change', function () { rc.set_searchscope(this.value); });
    if (byId('s_interval')) byId('s_interval').addEventListener('change', function () { rc.set_searchinterval(this.value); });
    byId('searchOptionsButton').addEventListener('click', function (e) { e.stopPropagation(); sync(); toggleMenu('searchMenu', byId('searchForm')); });
  }

  function initMailList() {
    var list = rc.message_list;

    // row controls: checkbox / star / pin
    $(byId('messagelist')).on('mousedown click', '.row-check, .star-btn, .pin-btn', function (e) {
      e.stopPropagation();
      if (e.type != 'click') return;
      e.preventDefault();
      var tr = this.closest('tr'), uid = tr && tr.uid;
      if (!uid) return;
      if (this.classList.contains('row-check')) list.select_row(uid, CONTROL_KEY, true);
      else if (this.classList.contains('star-btn')) rc.command('toggle_flag', uid);
      else rc.command('toggle_status', uid);
    });

    list.addEventListener('select', function () { updateBulk(); });
    rc.addEventListener('listupdate', function () { updateBulk(); updateRange(); icons(); });
    rc.addEventListener('insertrow', function () { clearTimeout(initMailList.t); initMailList.t = setTimeout(icons, 30); });
    rc.addEventListener('selectfolder', function () {
      doc.querySelectorAll('.category-tab').forEach(function (t) { t.classList.toggle('active', t.dataset.filter == 'ALL'); });
      closeReader();
      if (window.innerWidth <= 680) { doc.body.classList.remove('mobile-nav-open'); scrim(false); }
    });

    byId('selectAll').addEventListener('change', function () { rc.command(this.checked ? 'select-all' : 'select-none', this.checked ? 'page' : null); });
    doc.querySelectorAll('.category-tab').forEach(function (t) { t.addEventListener('click', function () { setFilter(t.dataset.filter); }); });
    byId('clearFilters').addEventListener('click', function () { byId('searchInput').value = ''; rc.command('reset-search'); setFilter('ALL'); });
    byId('closeReader').addEventListener('click', closeReader);
    byId('moreButton').addEventListener('click', function (e) { e.stopPropagation(); toggleMenu('moreMenu', this); });
    byId('readerMore').addEventListener('click', function (e) { e.stopPropagation(); toggleMenu('moreMenu', this); });

    var orig_show = rc.show_contentframe;
    rc.show_contentframe = function (show) { orig_show.apply(rc, arguments); if (!show) { var s = byId('mailStage'); s && s.classList.remove('reader-active'); scrim(false); } };

    if (rc.env.search_filter && rc.env.search_filter != 'ALL') doc.querySelectorAll('.category-tab').forEach(function (t) { t.classList.toggle('active', t.dataset.filter == rc.env.search_filter); });
    byId('mailNode').textContent = location.hostname.toUpperCase();
    initSearchMenu();
  }

  function initCompose() {
    var drawer = byId('composeDrawer'); if (!drawer) return;
    var frame = byId('composeFrame');
    if (frame) {
      rc.open_compose_step = function (p) { openCompose(rc.url('mail/compose', p)); };
      // the loader stays until the compose page reports ready (editor mounted), see initFramed()
      frame.addEventListener('load', function () {
        var w = composeWindow();
        try { if (w.rcmail && w.rcmail.env.action != 'compose' && w.location.href != 'about:blank') { composeReady(); closeCompose(true); } } catch (e) { composeReady(); }
      });
    }
    if (frame) byId('closeCompose').addEventListener('click', function () { closeCompose(); });
    byId('minimizeCompose').addEventListener('click', function () { drawer.classList.toggle('minimized'); scrim(!drawer.classList.contains('minimized')); });
    byId('expandCompose').addEventListener('click', function () {
      var on = drawer.classList.toggle('expanded');
      this.innerHTML = '<i data-lucide="' + (on ? 'minimize-2' : 'maximize-2') + '"></i>'; icons();
    });
  }

  /* ---------- shared app chrome ---------- */
  function initChrome() {
    commands = buildCommands();
    var user = byId('accountPopover') ? byId('accountPopover').querySelector('.popover-heading small').textContent : '';
    if (byId('avatarInitials')) byId('avatarInitials').textContent = initials(user);
    if (byId('railIp') && rc.env.synapse_ip) byId('railIp').textContent = 'IP ' + rc.env.synapse_ip;

    byId('menuToggle').addEventListener('click', function () {
      if (window.innerWidth <= 680) { var o = doc.body.classList.toggle('mobile-nav-open'); scrim(o); }
      else { var c = byId('appShell').classList.toggle('sidebar-collapsed'); store('synapsemail-sidebar', c ? 'collapsed' : null); }
    });
    if (store('synapsemail-sidebar') && window.innerWidth > 680) byId('appShell').classList.add('sidebar-collapsed');
    if (store('synapsemail-density')) doc.body.classList.add('compact');
    if (byId('densityButton')) byId('densityButton').addEventListener('click', toggleDensity);
    byId('themeMenuButton').addEventListener('click', function (e) { e.stopPropagation(); toggleMenu('themePopover'); });
    byId('avatarButton').addEventListener('click', function (e) { e.stopPropagation(); toggleMenu('accountPopover'); });
    byId('settingsButton').addEventListener('click', openPalette);
    doc.querySelectorAll('.theme-choice[data-theme]').forEach(function (c) { c.addEventListener('click', function () { applyTheme(c.dataset.theme, true, true); }); });
    byId('scrim').addEventListener('click', function () {
      var c = byId('composeDrawer'), composing = c && c.classList.contains('open') && !c.classList.contains('minimized');
      // the mobile menu sits above everything: a tap outside only closes the menu
      if (doc.body.classList.contains('mobile-nav-open')) { doc.body.classList.remove('mobile-nav-open'); scrim(composing); return; }
      if (byId('aboutDialog') && byId('aboutDialog').classList.contains('open')) { about(false); scrim(composing); return; }
      if (composing) closeCompose(); else closeOverlays();
    });

    var input = byId('commandInput');
    input.addEventListener('input', function () { commandIndex = 0; renderCommands(); });
    input.addEventListener('keydown', function (e) {
      var m = matchingCommands();
      if (e.key === 'ArrowDown') { e.preventDefault(); commandIndex = Math.min(m.length - 1, commandIndex + 1); renderCommands(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); commandIndex = Math.max(0, commandIndex - 1); renderCommands(); }
      if (e.key === 'Enter') { e.preventDefault(); runCommand(m[commandIndex]); }
    });
    byId('commandList').addEventListener('click', function (e) { var i = e.target.closest('.command-item'); if (i) runCommand(commands[+i.dataset.commandIndex]); });
    byId('commandPalette').addEventListener('click', function (e) { if (e.target === this) closePalette(); });

    doc.addEventListener('keydown', function (e) {
      var typing = /INPUT|TEXTAREA|SELECT/.test(doc.activeElement.tagName) || doc.activeElement.isContentEditable;
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); byId('commandPalette').classList.contains('open') ? closePalette() : openPalette(); return; }
      if (e.key === 'Escape') { if (byId('commandPalette').classList.contains('open')) closePalette(); else closeOverlays(); return; }
      if (typing || e.metaKey || e.ctrlKey || e.altKey) return;
      if (e.key === '/') { e.preventDefault(); byId('searchInput').focus(); }
      if (e.key.toLowerCase() === 'c') { e.preventDefault(); rc.command('compose'); }
    });

    rc.addEventListener('set-unread-count', updateUnread);
    rc.addEventListener('setquota', setQuota);
    if (rc.env.quota_content) setQuota(rc.env.quota_content);
    if (rc.env.task == 'settings' || rc.env.task == 'addressbook') {
      byId('searchInput') && rc.env.task == 'settings' && byId('searchInput').addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        doc.querySelectorAll('.settings-nav .folder-link, .settings-nav .label-link').forEach(function (a) { a.hidden = q && a.textContent.toLowerCase().indexOf(q) < 0; });
      });
    }
    updateClock(); setInterval(updateClock, 1000);
  }


  /* ---------- passcode recommendation widget (sidebar card; floating card on phones) ---------- */
  function initPinPrompt() {
    var p = rc.env.synapse_pin_prompt; if (!p || byId('pinPrompt')) return;
    var anchor = doc.querySelector('.mail-sidebar .clearance-card'); if (!anchor) return;
    var card = doc.createElement('div');
    card.id = 'pinPrompt'; card.className = 'clearance-card pin-prompt';
    card.innerHTML = '<div class="clearance-top"><span>' + esc(t('security_caps')) + '</span><b>' + esc(t('recommended_caps')) + '</b></div>'
      + '<div class="pin-prompt-body"><i data-lucide="shield-plus"></i><p><strong>' + esc(t('prompt_title')) + '</strong>' + esc(t('prompt_text')) + '</p></div>'
      + (p.last ? '<p class="pin-prompt-warn">' + esc(t('prompt_warn')) + '</p>' : '')
      + '<div class="pin-prompt-actions"><a class="solid-btn" href="./?_task=settings&_action=plugin.synapse-security&_setup=1">' + esc(t('setup_caps')) + '</a>'
      + '<button type="button" class="ghost-btn" id="pinPromptSkip">' + esc(t(p.last ? 'dontshowagain' : 'skip_caps')) + '</button></div>';
    // phones / small tablets: the sidebar is hidden or icon-only, so float the card over the list
    var small = window.matchMedia('(max-width: 900px)');
    var place = function () {
      card.classList.toggle('floating', small.matches);
      if (small.matches) doc.body.appendChild(card); else anchor.parentNode.insertBefore(card, anchor);
    };
    place();
    small.addEventListener('change', place);
    icons();
    byId('pinPromptSkip').addEventListener('click', function () {
      api('pin-prompt', { _do: 'skip' }, rc.env.request_token).then(function (res) {
        card.classList.add('leaving');
        setTimeout(function () { card.remove(); }, 300);
        toast(t(res.off ? 'prompt_off' : 'prompt_snoozed'), 'warning');
      });
    });
  }

  /* ---------- Settings › Security (passcode + remembered device) ---------- */
  function initSecurity() {
    var app = byId('securityApp'); if (!app) return;
    var st = rc.env.synapse_security || {}, token = rc.env.request_token, keypad = null;
    var intervals = { login: t('every_signin'), 3: t('every_days', { n: 3 }), 7: t('every_days', { n: 7 }), 30: t('every_days', { n: 30 }) };
    function select(name, value) {
      return '<select name="' + name + '" class="synapse-select">' + Object.keys(intervals).map(function (k) { return '<option value="' + k + '"' + (String(value) == k ? ' selected' : '') + '>' + esc(intervals[k]) + '</option>'; }).join('') + '</select>';
    }
    function cleanup() { if (keypad) { keypad.destroy(); keypad = null; } }
    function overview() {
      cleanup();
      var remembered = st.remembered_until && st.remembered_until * 1000 > Date.now();
      app.innerHTML = '<div class="sec-grid">'
        + '<section class="sec-card ' + (st.has_pin ? 'ok' : 'warn') + '"><div class="sec-card-head"><i data-lucide="' + (st.has_pin ? 'shield-check' : 'shield-alert') + '"></i><div><span>' + esc(t('securitypasscode_caps')) + '</span><b>' + esc(t(st.has_pin ? 'active_caps' : 'notset_caps')) + '</b></div></div>'
        + (st.has_pin
          ? '<div class="data-block">' + esc(t('sec_length')) + '&nbsp;&nbsp;&nbsp;&nbsp;' + esc(t('digits', { n: st.len })) + '<br>' + esc(t('sec_requested')) + '&nbsp;' + esc(intervals[st.interval] || st.interval).toUpperCase() + '<br>' + esc(t('sec_hint')) + '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' + esc(st.hint) + (st.set_at ? '<br>' + esc(t('sec_updated')) + '&nbsp;&nbsp;&nbsp;' + esc(fmtDate(st.set_at)) : '') + '</div>'
            + '<div class="sec-actions"><button class="solid-btn" data-go="change">' + esc(t('changepasscode')) + '</button><button class="ghost-btn" data-go="settings">' + esc(t('edithint')) + '</button><button class="ghost-btn danger" data-go="remove">' + esc(t('remove_caps')) + '</button></div>'
          : '<p>' + esc(t('passcode_intro')) + '</p><div class="sec-actions"><button class="solid-btn" data-go="setup">' + esc(t('setuppasscode')) + '</button></div>')
        + '</section>'
        + otpCard()
        + '<section class="sec-card"><div class="sec-card-head"><i data-lucide="monitor-smartphone"></i><div><span>' + esc(t('thisdevice')) + '</span><b>' + esc(t(remembered ? 'remembered_caps' : 'notremembered_caps')) + '</b></div></div>'
        + '<p>' + esc(remembered ? t('remembered_text', { days: st.remember_days, date: fmtDate(st.remembered_until) })
          : t('notremembered_text', { days: st.remember_days })) + '</p></section>'
        + '</div>';
      app.querySelectorAll('[data-go]').forEach(function (b) { b.addEventListener('click', function () { wizard(b.dataset.go); }); });
      app.querySelectorAll('[data-otp]').forEach(function (b) { b.addEventListener('click', function () { otpFlow(b.dataset.otp); }); });
      icons();
    }
    function wizard(mode) {
      // steps: [current] -> [length] -> code -> confirm -> details   (settings: current -> details; remove: current)
      var data = { len: 6, current: '', code: '' }, steps = [];
      if (st.has_pin) steps.push('current');
      if (mode == 'setup' || mode == 'change') steps.push('length', 'code', 'confirm', 'details');
      if (mode == 'settings') steps.push('details');
      var i = 0;
      function frame(title, sub, body) {
        cleanup();
        app.innerHTML = '<section class="sec-card sec-wizard"><div class="sec-steps">' + steps.map(function (s, n) { return '<i class="' + (n < i ? 'done' : n == i ? 'on' : '') + '"></i>'; }).join('') + '</div>'
          + '<div class="reader-kicker">' + esc(sub) + '</div><h2>' + esc(title) + '</h2>' + body
          + '<p class="pin-message" id="secMessage" role="alert"></p>'
          + '<div class="sec-actions"><button class="ghost-btn" id="secBack">' + esc(t(i ? 'back_caps' : 'cancel_caps')) + '</button><span class="spacer"></span><button class="solid-btn" id="secNext" hidden>' + esc(t('continue_caps')) + '</button></div></section>';
        byId('secBack').onclick = function () { if (i) { i--; show(); } else overview(); };
        icons();
      }
      function say(t) { byId('secMessage').textContent = t ? String(t).toUpperCase() : ''; }
      function next() { i++; show(); }
      function show() {
        var step = steps[i];
        if (step == 'current' || step == 'code' || step == 'confirm') {
          var len = step == 'current' ? st.len : data.len;
          frame(step == 'current' ? t('entercurrent') : step == 'code' ? t('choosepasscode', { len: len }) : t('repeatpasscode'),
            step == 'current' ? t('verifyyou') : t('passcodestep', { n: i + 1 }), '<div class="sec-pad" id="secPad"></div>');
          keypad = createKeypad(byId('secPad'), { len: len, onChange: function () { say(''); }, onComplete: function (code) {
            if (step == 'current') {
              data.current = code;
              if (mode == 'remove') {
                keypad.setBusy(true);
                return api('pin-remove', { _current: code }, token).then(function (res) {
                  if (!res.ok) { keypad.shake(); keypad.reset(); return say(res.message); }
                  st.has_pin = false; toast(t('passcoderemoved'), 'warning'); overview(); setNavState();
                });
              }
              return next();
            }
            if (step == 'code') {
              if (/^(\d)\1+$/.test(code) || '0123456789'.indexOf(code) > -1 || '9876543210'.indexOf(code) > -1) { keypad.shake(); keypad.reset(); return say(t('tooeasy')); }
              data.code = code; return next();
            }
            if (code !== data.code) { keypad.shake(); keypad.reset(); return say(t('nomatch')); }
            next();
          } });
          keypad.focus();
        } else if (step == 'length') {
          frame(t('howlong'), t('passcodestep', { n: i + 1 }),
            '<div class="sec-choice"><button type="button" data-len="6" class="' + (data.len == 6 ? 'active' : '') + '"><b>' + esc(t('digits', { n: 6 })) + '</b><small>' + esc(t('len6_desc')) + '</small></button>'
            + '<button type="button" data-len="4" class="' + (data.len == 4 ? 'active' : '') + '"><b>' + esc(t('digits', { n: 4 })) + '</b><small>' + esc(t('len4_desc')) + '</small></button></div>');
          app.querySelectorAll('[data-len]').forEach(function (b) { b.onclick = function () { data.len = +b.dataset.len; next(); }; });
        } else if (step == 'details') {
          frame(t('hintschedule'), t(mode == 'settings' ? 'passcode_settings_kicker' : 'passcode_final_kicker'),
            '<div class="settings-form sec-form"><label class="sec-field"><span>' + esc(t('sec_hint')) + '</span><input type="text" id="secHint" maxlength="120" placeholder="' + esc(t('hint_placeholder')) + '" value="' + esc(mode == 'settings' ? st.hint : '') + '"></label>'
            + '<label class="sec-field"><span>' + esc(t('askforit')) + '</span>' + select('secInterval', st.interval || 'login') + '</label>'
            + '<p class="sec-note">' + esc(t('hint_note')) + '</p></div>');
          var nb = byId('secNext'); nb.hidden = false; nb.textContent = t('savepasscode');
          nb.onclick = function () {
            nb.disabled = true;
            api('pin-save', { _current: data.current, _pin: data.code, _len: data.len, _hint: byId('secHint').value, _interval: app.querySelector('[name=secInterval]').value }, token).then(function (res) {
              nb.disabled = false;
              if (!res.ok) return say(res.message);
              st.has_pin = true; st.hint = byId('secHint').value.trim(); st.interval = app.querySelector('[name=secInterval]').value;
              if (data.code) { st.len = data.len; st.set_at = Math.floor(Date.now() / 1000); }
              toast(t(mode == 'settings' ? 'passcodesaved' : 'passcodeactive'), 'confirmation');
              overview(); setNavState();
            });
          };
          byId('secHint').focus();
        }
      }
      show();
    }
    /* two-factor authentication (authenticator app) */
    function otpCard() {
      var o = st.otp || {}; if (o.policy == 'off') return '';
      var required = o.policy == 'required';
      return '<section class="sec-card ' + (o.enabled ? 'ok' : 'warn') + '"><div class="sec-card-head"><i data-lucide="' + (o.enabled ? 'smartphone' : 'smartphone-nfc') + '"></i><div><span>' + esc(t('twofactor_caps')) + '</span><b>' + esc(t(o.enabled ? 'on_caps' : 'off_caps')) + (required ? ' · ' + esc(t('required_caps')) : '') + '</b></div></div>'
        + (o.enabled
          ? '<div class="data-block">' + esc(t('otp_app')) + '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' + esc(t('otp_app_value')) + (o.enabled_at ? '<br>' + esc(t('otp_since')) + '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' + esc(fmtDate(o.enabled_at)) : '') + '<br>' + esc(t('otp_recovery')) + '&nbsp;&nbsp;' + esc(t(o.recovery_left == 1 ? 'codeleft' : 'codesleft', { n: o.recovery_left })) + '</div>'
            + (o.recovery_left < 3 ? '<p class="sec-note">' + esc(t('fewcodes')) + '</p>' : '')
            + '<div class="sec-actions"><button class="solid-btn" data-otp="recovery">' + esc(t('newcodes_caps')) + '</button>' + (required ? '' : '<button class="ghost-btn danger" data-otp="disable">' + esc(t('turnoff_caps')) + '</button>') + '</div>'
          : '<p>' + esc(t('otp_intro')) + '</p><div class="sec-actions"><button class="solid-btn" data-otp="setup">' + esc(t('setup2fa')) + '</button></div>')
        + '</section>';
    }
    function otpFlow(mode) {
      cleanup();
      var title = t('otptitle_' + mode);
      app.innerHTML = '<section class="sec-card sec-wizard"><div class="reader-kicker">' + esc(t('twofactor_kicker')) + ' // ' + esc(t(mode == 'setup' ? 'authenticatorapp_caps' : 'verifyyou')) + '</div><h2>' + esc(title) + '</h2>'
        + '<div class="otp-setup" id="otpSetup"></div><div class="sec-pad" id="secPad"></div><p class="pin-message" id="secMessage" role="alert"></p><div class="pin-hint" id="otpCodes" hidden></div>'
        + '<div class="sec-actions"><button class="ghost-btn" id="secBack">' + esc(t('cancel_caps')) + '</button></div></section>';
      byId('secBack').onclick = overview;
      var say = function (t) { byId('secMessage').textContent = t ? String(t).toUpperCase() : ''; };
      var finishCodes = function (codes, message) {
        cleanup(); byId('secPad').innerHTML = ''; byId('otpSetup').innerHTML = ''; byId('secBack').hidden = true;
        showRecoveryCodes(byId('otpCodes'), codes, function () { toast(message, 'confirmation'); overview(); });
      };
      var pad = function (onCode) {
        keypad = createKeypad(byId('secPad'), { len: 6, label: t('authenticatorcode'), onChange: function () { say(''); }, onComplete: function (code) { keypad.setBusy(true); onCode(code); } });
        keypad.focus();
      };
      var fail = function (res) { keypad.shake(); keypad.reset(); say(res.message || t('otp_wrong')); };
      if (mode == 'setup') {
        api('otp-enroll', { _do: 'start' }, token).then(function (res) {
          if (!res.ok) return say(res.message);
          var box = byId('otpSetup');
          box.innerHTML = '<p>' + esc(t('otpstep1')) + '</p><div class="otp-qr"></div><p class="sec-note">' + esc(t('cantscan')) + ' <code class="otp-secret"></code></p><p>' + esc(t('otpstep2')) + '</p>';
          box.querySelector('.otp-secret').textContent = res.secret.replace(/(.{4})/g, '$1 ').trim();
          renderQr(box.querySelector('.otp-qr'), res.uri);
          pad(function (code) {
            api('otp-enroll', { _do: 'confirm', _code: code }, token).then(function (r) {
              if (!r.ok) return r.restart ? otpFlow('setup') : fail(r);
              st.otp.enabled = true; st.otp.enabled_at = Math.floor(Date.now() / 1000); st.otp.recovery_left = r.codes.length;
              finishCodes(r.codes, t('otpenabled'));
            });
          });
        });
        icons();
        return;
      }
      byId('otpSetup').innerHTML = '<p>' + esc(t('otpentercurrent')) + '</p>';
      pad(function (code) {
        api(mode == 'recovery' ? 'otp-recovery' : 'otp-disable', { _code: code }, token).then(function (r) {
          if (!r.ok) return fail(r);
          if (mode == 'recovery') { st.otp.recovery_left = r.codes.length; return finishCodes(r.codes, t('newcodescreated')); }
          st.otp.enabled = false; cleanup(); toast(t('otpdisabled'), 'warning'); overview();
        });
      });
      icons();
    }
    function setNavState() { var b = byId('navPinState'); if (b) b.textContent = st.has_pin ? t('on_caps') : ''; }
    setNavState();
    if (/[?&]_setup=1/.test(location.search) && !st.has_pin) wizard('setup'); else overview();
  }

  /* ---------- settings: grouped navigation + preference sections in the content frame ---------- */
  function initSettings() {
    var nav = doc.querySelector('.settings-nav'); if (!nav) return;
    var params = new URLSearchParams(location.search), action = rc.env.action || 'preferences';
    var section = params.get('_section') || 'general';
    var key = action == 'preferences' || action == '' ? 'section:' + section : action.replace(/^edit-/, '');
    var setActive = function (k) {
      nav.querySelectorAll('[data-nav]').forEach(function (a) { a.classList.toggle('active', a.dataset.nav == k); });
      var cur = nav.querySelector('[data-nav="' + k + '"] span');
      if (cur && byId('settingsTitle') && (action == 'preferences' || action == '')) byId('settingsTitle').textContent = cur.textContent.toUpperCase();
    };
    setActive(key);

    if (action == 'preferences' || action == '') {
      var load = function (sec) {
        var win = rc.get_frame_window(rc.env.contentframe);
        if (win) rc.location_href({ _action: 'edit-prefs', _section: sec, _framed: 1 }, win, true);
        setActive('section:' + sec);
        try { history.replaceState(null, '', rc.url('settings/preferences', { _section: sec })); } catch (e) { }
      };
      nav.querySelectorAll('[data-section]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); load(a.dataset.section); if (window.innerWidth <= 680) { doc.body.classList.remove('mobile-nav-open'); scrim(false); } });
      });
      load(section);
    }

    // plugin settings tabs (core ones are already in the grouped navigation)
    var ext = byId('settingsExtensionList'), core = /^settingstab(preferences|folders|identities|responses|pluginpassword)$/;
    if (ext) {
      $(ext).children('[id^="settingstab"]').each(function () {
        if (core.test(this.id)) return $(this).remove();
        var a = $(this).find('a').first();
        a.addClass('folder-link').html('<i data-lucide="puzzle"></i><span>' + esc(a.text().trim()) + '</span><b></b>');
        $(this).replaceWith(a.attr('data-nav', this.id.replace('settingstab', '')));
      });
      if ($(ext).children().length) { byId('settingsExtensions').hidden = false; setActive(key); }
    }
    // plugin forms (e.g. password) render a plain Roundcube button: give it the template's primary button
    $('.settings-form button.mainaction').addClass('solid-btn');
    icons();
  }

  // list + detail pages (identities, responses, folders, contacts): on narrow screens the detail pane slides in on demand
  function initDetailPane() {
    var stage = doc.querySelector('.settings-stage:not(.reader-full)'); if (!stage) return;
    var frame = stage.querySelector('iframe.reader-frame'), header = stage.querySelector('.reader-pane .reader-header');
    stage.classList.remove('reader-active');
    $('<button type="button" class="reader-back" title="' + esc(rc.gettext('back')) + '"><i data-lucide="panel-right-close"></i></button>').prependTo(header)
      .on('click', function () { stage.classList.remove('reader-active'); scrim(false); });
    frame.addEventListener('load', function () {
      var blank = /watermark\.html|about:blank/.test(frame.contentWindow.location.href);
      stage.classList.toggle('reader-active', !blank);
      if (!blank && window.innerWidth <= 1100) scrim(true);
    });
    icons();
  }

  function frameCommand(cmd) {
    var w = rc.get_frame_window(rc.env.contentframe);
    try { if (w && w.rcmail) w.rcmail.command(cmd, '', w.document.body); } catch (e) { }
    return false;
  }

  /* ---------- inside the preview frame ---------- */

  /* ---------- HTML mail readability ----------
   * Senders often paint their own (light) panels but leave text colour to the browser default, or set dark text
   * with no background. On the themed sheet either case becomes unreadable. Each text element is checked
   * against the background actually behind it; only text below a readable contrast is re-coloured, the rest of
   * the sender's design is untouched. "Original colours" shows the mail exactly as authored on a white page. */
  var colorProbe = null;
  function rgba(str) {
    if (!str || str == 'transparent') return null;
    var m = /rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)/.exec(str);
    if (!m) {
      var c = /color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+%?))?\)/.exec(str);
      if (!c) return null;
      m = [0, c[1] * 255, c[2] * 255, c[3] * 255, c[4]];
    }
    var a = m[4] === undefined ? 1 : (String(m[4]).indexOf('%') > -1 ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
    return [+m[1], +m[2], +m[3], a];
  }
  function blend(top, under) {
    if (!top) return under;
    var a = top[3];
    return [top[0] * a + under[0] * (1 - a), top[1] * a + under[1] * (1 - a), top[2] * a + under[2] * (1 - a), 1];
  }
  function luminance(c) {
    var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
  }
  function contrast(a, b) { var x = luminance(a), y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }

  function repairMailContrast(part) {
    var win = part.ownerDocument.defaultView, cache = new Map();
    // what the sheet itself looks like (its translucent theme colour over the page)
    var page = rgba(win.getComputedStyle(part.ownerDocument.body).backgroundColor) || [13, 17, 14, 1];
    if (page[3] < 1) page = blend(page, rgba(win.getComputedStyle(part.ownerDocument.documentElement).backgroundColor) || [13, 17, 14, 1]);
    var sheet = blend(rgba(win.getComputedStyle(part).backgroundColor), [page[0], page[1], page[2], 1]);
    function backdrop(el) {
      if (el === part) return sheet;
      if (cache.has(el)) return cache.get(el);
      var cs = win.getComputedStyle(el), parent = backdrop(el.parentElement || part), own = rgba(cs.backgroundColor), res;
      // a background image (or unknown parent) means we cannot judge contrast: leave that subtree alone
      if (parent === null || (cs.backgroundImage && cs.backgroundImage != 'none' && !(own && own[3] >= 1))) res = null;
      else res = own && own[3] > 0 ? blend(own, parent) : parent;
      cache.set(el, res);
      return res;
    }
    var fixed = 0;
    part.querySelectorAll('*').forEach(function (el) {
      if (/^(IMG|SVG|PICTURE|VIDEO|STYLE|SCRIPT|BR|HR)$/i.test(el.tagName) || el.closest('svg')) return;
      var hasText = Array.prototype.some.call(el.childNodes, function (n) { return n.nodeType === 3 && n.nodeValue.trim(); });
      if (!hasText) return;
      var bg = backdrop(el); if (!bg) return;
      var cs = win.getComputedStyle(el), fg = rgba(cs.color); if (!fg) return;
      fg = blend(fg, bg);
      var size = parseFloat(cs.fontSize) || 14, bold = parseInt(cs.fontWeight, 10) >= 600;
      var need = size >= 24 || (size >= 18.6 && bold) ? 3 : 4.5;
      if (contrast(fg, bg) >= need) return;
      // pick the readable side for this background
      var light = luminance(bg) > 0.4;
      if (!el.hasAttribute('data-sx-color')) el.setAttribute('data-sx-color', el.style.getPropertyValue('color') + '|' + el.style.getPropertyPriority('color'));
      el.style.setProperty('color', light ? '#1f2421' : '#e7eadf', 'important');
      fixed++;
    });
    part.dataset.sxContrast = fixed;
  }
  function restoreMailColors(part) {
    part.querySelectorAll('[data-sx-color]').forEach(function (el) {
      var v = el.getAttribute('data-sx-color').split('|');
      if (v[0]) el.style.setProperty('color', v[0], v[1]); else el.style.removeProperty('color');
      el.removeAttribute('data-sx-color');
    });
  }
  function initMailColors() {
    var parts = doc.querySelectorAll('.reader-copy .message-htmlpart'), btn = byId('toggleOriginal');
    if (!parts.length) { if (btn) btn.hidden = true; return; }
    var apply = function (original) {
      parts.forEach(function (part) {
        part.classList.toggle('mail-original', original);
        restoreMailColors(part);
        if (!original) repairMailContrast(part);
      });
      if (btn) btn.innerHTML = original ? '<i data-lucide="contrast"></i> ' + esc(t('adaptivecolors')) : '<i data-lucide="palette"></i> ' + esc(t('originalcolors'));
      icons();
    };
    var original = store('synapsemail-mail-colors') == 'original';
    apply(original);
    // late layout (web fonts, images with dimensions) can change computed styles: re-check once loaded
    window.addEventListener('load', function () { if (!original) apply(false); });
    if (btn) btn.addEventListener('click', function () {
      original = !original;
      store('synapsemail-mail-colors', original ? 'original' : null);
      apply(original);
    });
  }

  function initPreview() {
    var from = byId('readerFrom'); if (!from) return;
    initMailColors();
    var link = from.querySelector('a[href^="mailto:"]') || from.querySelector('a');
    var email = link ? (link.getAttribute('title') || link.getAttribute('href') || '').replace(/^mailto:/, '').replace(/\?.*$/, '') : from.textContent.trim();
    var name = link ? link.textContent.trim() : from.textContent.trim();
    if (!email || email == name) { var m = /<([^>]+)>/.exec(from.textContent); if (m) email = m[1]; }
    byId('readerSender').innerHTML = from.innerHTML;
    byId('readerEmail').textContent = email && email != name ? '<' + email + '>' : '';
    byId('readerAvatar').textContent = initials(name || email);
    byId('toggleHeaders').addEventListener('click', function () { var h = byId('readerHeaders'); h.hidden = !h.hidden; });
    var cls = t(rc.env.is_draft ? 'draft_caps' : 'encryptedemail') + ' // ' + (rc.env.mailbox || 'INBOX').toUpperCase();
    var tui = topUI();
    var inWindow = false;
    try { inWindow = !!(window.frameElement && window.frameElement.classList.contains('msg-window-frame')); } catch (e) { }
    if (inWindow) { if (byId('readerClass')) byId('readerClass').textContent = cls; }
    else if (framed && tui && tui.openReader) tui.openReader(cls);
    else if (byId('readerClass')) byId('readerClass').textContent = cls;
  }

  /* ---------- compose form helpers (drawer frame or standalone page) ---------- */
  function initComposeForm() {
    ['cc', 'bcc', 'replyto', 'followupto'].forEach(function (h) {
      var input = byId('_' + h);
      if (input && input.value.trim()) toggleHeader(h, true);
    });
    // grow recipient textareas with their content
    $('#compose_to textarea, #compose_cc textarea, #compose_bcc textarea, #compose_replyto textarea').on('input', function () {
      this.style.height = '44px'; this.style.height = Math.max(44, this.scrollHeight) + 'px';
    });
    rc.addEventListener('menu-open', function (p) {
      if (p && p.name == 'attachmentmenu') { toggleMenu('attachmentmenu', p.originalEvent && p.originalEvent.target, true); return false; }
    });
    $('#responseslist').on('click', 'a', function () { closeMenus(); });
  }
  function toggleHeader(h, force) {
    var row = byId('compose_' + h); if (!row) return false;
    var show = force !== undefined ? force : row.classList.contains('hidden');
    row.classList.toggle('hidden', !show);
    $('.compose-addons [data-header="' + h + '"]').toggleClass('active', show);
    if (show && force === undefined) $(row).find('input, textarea').focus();
    return false;
  }
  var SynapseCompose = window.SynapseCompose = {
    toggle: toggleHeader,
    options: function () { var o = byId('composeOptions'); o.hidden = !o.hidden; return false; },
    responses: function (btn) { toggleMenu('responses-menu', btn); return false; }
  };

  /* ---------- framed pages: route compose to the parent drawer ---------- */
  function initFramed() {
    var tui = topUI();
    if (tui && tui.openCompose && rc.env.action != 'compose') {
      rc.open_compose_step = function (p) { tui.openCompose(rc.url('mail/compose', p)); };
    }
    if (rc.env.action == 'compose' && tui) {
      // editor is mounted during rcmail.init(): drop the drawer loader now
      if (tui.composeReady) setTimeout(tui.composeReady, 60);
      var sent = rc.sent_successfully;
      rc.sent_successfully = function (type, msg, folders, save_error) {
        rc.compose_skip_unsavedcheck = true;
        if (save_error) return sent.apply(rc, arguments);
        var prc = topRc();
        prc.display_message(msg, type);
        tui.closeCompose(true, true);
      };
      rc.addEventListener('responseaftersend-doc', function () { });
      rc.addEventListener('message', function (p) {
        if (tui._closeAfterSave && p.type == 'confirmation') { tui._closeAfterSave = false; tui.closeCompose(true); }
      });
    }
  }

  function init() {
    enhanceButtons();
    rc.add_label({ refreshing: t('autocheck'), checkingmail: t('autocheck') });
    initSelects();
    initNoAutofill();
    if (rc.env.task != 'login' && rc.env.task != 'logout') mirrorMessages();
    doc.addEventListener('click', function (e) { if (!e.target.closest('.theme-popover, .synapse-dialog')) closeMenus(); });
    // core popup menus (folder selector, …) are closed by the skin: outside press, or after picking an item
    var hideCore = function (menu, e) { if (rc.menu_stack.length) rc.hide_menu(menu.id, e); else $(menu).hide(); };
    doc.addEventListener('mousedown', function (e) {
      $('.popupmenu:visible').each(function () { if (!this.contains(e.target)) hideCore(this, e); });
    });
    $(doc).on('click', '.popupmenu a.active', function (e) { var m = this.closest('.popupmenu'); setTimeout(function () { hideCore(m, e); }, 0); });
    // server pref wins once signed in; the cookie carries it to the login page
    // titles set later by the app (open message, folder) use " - " like the first page load
    var setTitle = rc.set_pagetitle;
    rc.set_pagetitle = function (title) { return setTitle.call(rc, String(title || '').replace(' :: ', ' - ')); };

    // v2 keys: earlier versions stored the theme on every visit, so old values are not a user's choice
    var accountTheme = rc.env.synapse_theme || (topRc().env || {}).synapse_theme;
    applyTheme(accountTheme || themeCookie() || store('synapsemail-theme2') || 'agency');
    if (accountTheme) persistTheme(accountTheme); // the login page shows the account's theme next time
    if (rc.env.task == 'login' || rc.env.task == 'logout') { icons(); initLogin(); return; }
    if (rc.env.action == 'compose') initComposeForm();
    if (framed) {
      initFramed();
      if (rc.env.task == 'mail' && (rc.env.action == 'preview' || rc.env.action == 'show')) initPreview();
      icons();
      return;
    }
    if (byId('appShell')) {
      initChrome();
      if (rc.env.task == 'mail' && rc.message_list) { initMailList(); initReaderWindows(); }
      if (rc.env.task == 'mail' && (rc.env.action == 'show' || rc.env.action == 'preview')) initPreview();
      initCompose();
      decorateFolders();
      if (rc.env.task == 'settings') { initSettings(); initSecurity(); }
      if (rc.env.task == 'mail') initPinPrompt();
      initDetailPane();
      if (rc.env.task == 'addressbook') {
        decorateDirectory();
        ['group_insert', 'group_update', 'listupdate'].forEach(function (ev) { rc.addEventListener(ev, function () { setTimeout(decorateDirectory, 0); }); });
      }
    }
    icons();
  }

  // core template objects take no <roundcube:label> in attributes: localize them now, before rcmail.init() (the editor reads the placeholder)
  function localizeObjects() {
    var set = function (id, attr, key) { var el = byId(id); if (el) el.setAttribute(attr, t(key)); };
    if (rc.env.task == 'mail') { set('searchInput', 'placeholder', 'searchmail'); set('searchInput', 'aria-label', 'searchmessages'); }
    else if (rc.env.task == 'addressbook') { set('searchInput', 'placeholder', 'searchcontacts'); set('searchInput', 'aria-label', 'searchcontacts'); }
    set('messagelist', 'aria-label', 'messages');
    set('compose-subject', 'placeholder', 'emailsubject');
    set('composebody', 'placeholder', 'writemessage');
  }

  // run after rcmail.init() so gui objects and lists exist
  if (rc) {
    localizeObjects();
    rc.widescreen_message_row = messageRow;
    // keep the synapse_preview text (sent as an extra column) on the message record for messageRow()
    var addRow = rc.add_message_row;
    rc.add_message_row = function (uid, cols, flags) {
      if (cols && cols.preview != null) (rc.env.messages[uid] = rc.env.messages[uid] || {}).preview = cols.preview;
      return addRow.apply(rc, arguments);
    };
    rc.addEventListener('init', init);
  }

  return {
    toast: toast, applyTheme: applyTheme, palette: function () { openPalette(); return false; }, about: about,
    openReader: openReader, closeReader: closeReader, openCompose: openCompose, closeCompose: closeCompose, composeReady: composeReady, popOut: popOut,
    closeMenus: closeMenus, toggleMenu: toggleMenu, listOptions: listOptions, saveListOptions: saveListOptions,
    search: search, confirm: confirmBox, frameCommand: frameCommand, icons: icons, decorateFolders: decorateFolders
  };
})();
