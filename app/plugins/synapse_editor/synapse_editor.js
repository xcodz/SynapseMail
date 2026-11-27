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
/* SynapseMail: rcube_text_editor implemented with Lexical (window.SynapseEditor from lexical.bundle.js).
 * Only the user's own text is edited in Lexical; the signature and the quoted original
 * (reply/forward) are kept as read-only HTML blocks so their formatting survives untouched. */
function rcube_text_editor(config, id) {
  var ref = this, conf = $.extend({}, rcmail.env.editor_config, config || {}),
    textarea = document.getElementById(id), lx = null, host = null,
    parts = { before: '', sig: null, after: '' };

  // core passes mode '' for message compose, 'identity'/'response' for settings editors
  var compose = !conf.mode || conf.mode == 'compose';
  this.id = id;
  this.editor = null; // kept null: core code checks it for TinyMCE only

  // texts from localization/ (undo is a core label)
  function label(k) { return k == 'undo' ? rcmail.gettext('undo') : rcmail.gettext(k, 'synapse_editor'); }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function baseFont() {
    var f = conf.default_font;
    if (f) {
      var hit = SynapseEditor.FONTS.filter(function (x) { return x[0].toLowerCase() == String(f).split(',')[0].replace(/["']/g, '').trim().toLowerCase(); })[0];
      if (hit) return hit[1];
    }
    return SynapseEditor.defaultFontFor(conf.lang);
  }

  function textToHtml(text) {
    return String(text || '').replace(/\r\n/g, '\n').split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
  }

  // Mail HTML is parsed in a separate, inert document: nothing in it loads or runs while it is split
  var inert = document.implementation.createHTMLDocument('');
  var CONTROLS = 'button,input,select,textarea,output,datalist,option,optgroup,label,form,fieldset,legend';
  var FORM_ATTRS = ['form', 'formaction', 'formmethod', 'formenctype', 'formtarget', 'formnovalidate'];

  // Quoted mail, signatures and drafts sit inside the compose form: turn every form control into text so
  // nothing from a received mail can submit or change the form (the server does the same; this is the backstop)
  function defuse(root) {
    var all = Array.prototype.slice.call(root.querySelectorAll('*')).reverse();
    all.forEach(function (el) {
      FORM_ATTRS.forEach(function (a) { el.removeAttribute(a); });
      if (!el.matches(CONTROLS) || (el.matches('option,optgroup') && el.closest('select,datalist'))) return;
      var tag = el.tagName.toLowerCase(), box = el.ownerDocument.createElement(/^(form|fieldset|legend)$/.test(tag) ? 'div' : 'span');
      ['style', 'class', 'dir', 'lang', 'title'].forEach(function (a) { if (el.hasAttribute(a)) box.setAttribute(a, el.getAttribute(a)); });
      if (tag == 'input') {
        if (!/^(hidden|checkbox|radio|file|image|password|range|color)$/i.test(el.getAttribute('type') || 'text')) box.textContent = el.getAttribute('value') || '';
      } else if (tag == 'select') {
        var opt = el.querySelector('option[selected]') || el.querySelector('option');
        box.textContent = opt ? opt.textContent : '';
      } else if (tag == 'textarea' || tag == 'option') {
        box.textContent = el.textContent;
      } else if (tag != 'datalist') {
        while (el.firstChild) box.appendChild(el.firstChild);
      }
      el.parentNode.replaceChild(box, el);
    });
    return root;
  }

  // HTML for a read-only block on the page: defused, and its own <style> rules limited to that block
  function safeHtml(html, scope) {
    var d = defuse(Object.assign(inert.createElement('div'), { innerHTML: html || '' }));
    d.querySelectorAll('style').forEach(function (s) { s.textContent = '@scope (' + scope + ') {\n' + s.textContent + '\n}'; });
    return d.innerHTML;
  }

  // The compose form is sent only by the app's own Send / Save commands (core calls form.requestSubmit() there);
  // any other submit (a button or Enter in a header field) is dropped
  function guardForm(form) {
    if (!form || form.synapseGuard) return;
    var allowed = false, send = rcmail.submit_messageform;
    form.synapseGuard = true;
    if (typeof send == 'function') {
      rcmail.submit_messageform = function () {
        allowed = true;
        try { return send.apply(this, arguments); } finally { allowed = false; }
      };
    }
    form.addEventListener('submit', function (e) {
      if (!allowed) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
  }

  // ids of our own markers: exact, optionally with core's "v<n>" wash prefix (reopened drafts)
  function topLevel(wrap, re) {
    return Array.prototype.filter.call(wrap.children, function (c) { return re.test(c.id || ''); })[0] || null;
  }

  // Split compose HTML into [quoted-before] user body [signature] [quoted-after]
  function split(html) {
    var wrap = inert.createElement('div'), nodes, kids, i, qs = -1, qe = -1, before = '', after = '', sig = null;
    wrap.innerHTML = html || '';
    defuse(wrap);

    // reopened drafts are washed by core: ids get a "v1" prefix, data-* is dropped and the body
    // gains an outer <div style="font-size…">; unwrap such single wrappers first
    var only;
    while ((only = wrap.children.length == 1 && !wrap.textContent.replace(wrap.children[0].textContent, '').trim() ? wrap.children[0] : null)
      && only.tagName == 'DIV' && !/^(v\d+)?(synapse-body|_rc_sig)$/.test(only.id || '')) {
      $(only).replaceWith(only.childNodes);
    }

    // only our own top-level signature block: a "_rc_sig" inside a quoted mail stays in the quote
    var sigEl = topLevel(wrap, /^(v\d+)?_rc_sig$/);
    if (sigEl) { sig = sigEl.innerHTML; sigEl.parentNode.removeChild(sigEl); }

    // a draft saved by this editor: the body wrapper marks exactly what the user wrote
    var body = topLevel(wrap, /^(v\d+)?synapse-body$/);
    if (body && body.tagName != 'DIV') body = null;
    if (body && compose) {
      var pre = inert.createElement('div'), post = inert.createElement('div'), target = pre;
      Array.prototype.slice.call(wrap.childNodes).forEach(function (k) {
        if (k === body) { target = post; return; }
        target.appendChild(k.cloneNode(true));
      });
      return { before: pre.innerHTML.trim(), body: body.innerHTML, sig: sig, after: post.innerHTML.trim() };
    }
    if (body) { $(body).replaceWith(body.childNodes); }

    if (conf.mode == 'identity' || conf.mode == 'response') return { before: '', body: wrap.innerHTML, sig: null, after: '' };

    kids = Array.prototype.slice.call(wrap.childNodes);
    for (i = 0; i < kids.length; i++) {
      var n = kids[i];
      if (n.nodeType != 1) continue;
      // core reply body: <p id="reply-intro">…</p><blockquote>…</blockquote>; forward: <p>-------- Original Message --------</p><table>…
      var isQuote = n.matches('blockquote') || !!n.querySelector(':scope > blockquote[type="cite"]');
      var isFwd = n.matches('p') && /^-{4,}[^-]+-{4,}$/.test((n.textContent || '').trim());
      if (isQuote || isFwd) {
        qs = i;
        // include the "On ... wrote:" line right before a cite
        for (var j = i - 1; j >= 0; j--) {
          if (kids[j].nodeType == 3 && !kids[j].nodeValue.trim()) continue;
          if (kids[j].nodeType == 1 && (/reply-intro$/.test(kids[j].id) || /:\s*$/.test(kids[j].textContent || '') && (kids[j].textContent || '').length < 300)) qs = j;
          break;
        }
        qe = isFwd ? kids.length - 1 : i;
        break;
      }
    }

    var html_of = function (list) { var d = inert.createElement('div'); list.forEach(function (k) { d.appendChild(k.cloneNode(true)); }); return d.innerHTML; };
    if (qs < 0) return { before: '', body: wrap.innerHTML, sig: sig, after: '' };

    var quote = html_of(kids.slice(qs, qe + 1)), head = kids.slice(0, qs), tail = kids.slice(qe + 1);
    var meaningful = function (list) { return list.some(function (k) { return (k.textContent || '').trim() || (k.nodeType == 1 && k.querySelector('img')); }); };

    // bottom-posting: quote first, the user writes below it
    if (!meaningful(head) && !rcmail.env.top_posting) {
      before = quote; nodes = tail;
    } else {
      after = quote; nodes = head.concat(tail);
    }
    return { before: before, body: html_of(nodes), sig: sig, after: after };
  }

  function quoteBlock(html, where) {
    var box = $('<div class="lx-quoted"/>').addClass('lx-quoted-' + where).attr('hidden', !html);
    $('<button type="button" class="lx-quoted-toggle"/>').text('··· ' + label(where == 'before' ? 'quoted_above' : 'quoted')).appendTo(box)
      .on('click', function () { box.toggleClass('open'); });
    $('<div class="lx-quoted-body"/>').html(safeHtml(html, '.lx-quoted-body')).appendTo(box);
    return box;
  }

  function render() {
    host.find('.lx-quoted, .lx-signature').remove();
    var surface = host.find('.lx-surface');
    if (parts.before) quoteBlock(parts.before, 'before').insertBefore(surface);
    $('<div class="lx-signature"/>').attr('hidden', !parts.sig).html(safeHtml(parts.sig, '.lx-signature')).insertAfter(surface);
    if (parts.after) quoteBlock(parts.after, 'after').appendTo(host);
  }

  function assemble() {
    var font = lx.baseFont, body = lx.getHTML();
    var out = parts.before
      + '<div id="synapse-body" style="font-family: ' + font.replace(/"/g, "'") + ';">' + body + '</div>'
      + (parts.sig != null ? '<div id="_rc_sig">' + parts.sig + '</div>' : '')
      + parts.after;
    return compose ? out : body;
  }

  function sync() { if (lx && textarea) textarea.value = assemble(); }

  function mount(html) {
    var p = split(html);
    parts = { before: p.before, sig: p.sig, after: p.after };
    if (compose) guardForm(textarea.form);
    host = $('<div class="lx-host"/>').addClass('lx-mode-' + (compose ? 'compose' : conf.mode)).insertAfter(textarea);
    $(textarea).hide();
    lx = SynapseEditor.create(host.get(0), {
      lang: conf.lang, font: baseFont(),
      placeholder: textarea.getAttribute('placeholder') || label(conf.mode == 'identity' ? 'placeholder_signature' : 'placeholder_message'),
      labels: ['style', 'font', 'undo', 'redo', 'bold', 'italic', 'underline', 'ltr', 'rtl', 'alignleft', 'aligncenter', 'alignright', 'alignjustify',
        'toolbar', 'body', 'paragraph', 'h1', 'h2', 'h3', 'quote', 'bullet', 'number'].reduce(function (o, k) { o[k] = label(k); return o; }, {}),
      onChange: sync,
    });
    render();
    lx.setHTML(p.body);
    sync();
    if (window.lucide) lucide.createIcons();
  }

  function unmount() {
    if (!lx) return;
    lx.destroy(); host.remove(); lx = null; host = null;
    $(textarea).show();
  }

  this.is_html = function () { return !!lx; };

  this.toggle = function (ishtml) {
    if (ishtml && !lx) {
      mount(textToHtml(textarea.value));
    } else if (!ishtml && lx) {
      var text = lx.getText();
      unmount();
      textarea.value = text;
    }
    return true;
  };

  this.focus = function () { if (lx) lx.focus(); else $(textarea).focus(); };
  this.tabindex = function () { };
  this.save = function () { sync(); };

  this.get_content = function (args) {
    args = $.extend({ refresh: true, selection: false, nosig: false, format: 'html' }, args || {});
    if (!lx) {
      var v = textarea.value;
      if (args.selection) { var s = rcmail.get_input_selection(textarea).text; if (s) return s; }
      if (args.nosig) { var p = v.indexOf('-- \n'); if (p > 0) v = v.substring(0, p); }
      return v;
    }
    if (args.selection) { var t = lx.getSelectedText(); if (t) return args.format == 'html' ? esc(t) : t; }
    if (args.format == 'text') return lx.getText();
    return args.nosig ? lx.getHTML() : assemble();
  };

  this.set_content = function (content) {
    if (!lx) { $(textarea).val(content).focus(); return; }
    var p = split(content);
    parts = { before: p.before, sig: p.sig, after: p.after };
    render(); lx.setHTML(p.body); sync(); lx.focus();
  };

  this.replace = function (input) {
    if (!input) return false;
    if (!lx) {
      var sel = rcmail.get_input_selection(textarea), v = textarea.value, text = $.type(input) == 'object' ? input.text || '' : input;
      textarea.value = v.substring(0, sel.start) + text + v.substring(sel.end);
      rcmail.set_caret_pos(textarea, sel.start + text.length);
      return;
    }
    if ($.type(input) == 'object' && ('html' in input)) lx.insertHTML(input.html);
    else lx.insertText($.type(input) == 'object' ? input.text || '' : input);
    sync();
  };

  this.change_signature = function (id, show_sig) {
    if (!lx) {
      // plain text mode: same behaviour as core (append/replace text signature)
      var msg = textarea.value, old = rcmail.env.signatures && rcmail.env.signatures[rcmail.env.identity], sig = rcmail.env.signatures && rcmail.env.signatures[id];
      if (show_sig && old && old.text) msg = msg.replace(old.text.replace(/\r\n/g, '\n'), '');
      if (show_sig && sig && sig.text) msg = msg.replace(/[\r\n]+$/, '') + '\n\n' + sig.text.replace(/\r\n/g, '\n');
      textarea.value = msg;
      return;
    }
    if (!show_sig) return;
    var s = rcmail.env.signatures && rcmail.env.signatures[id];
    parts.sig = s ? defuse(Object.assign(inert.createElement('div'), { innerHTML: s.html || '' })).innerHTML : '';
    render(); sync();
  };

  // spellchecking is the browser's own (contenteditable spellcheck)
  this.spellcheck_start = this.spellcheck_stop = this.spellcheck_resume = function () { };
  this.spellcheck_state = function () { return false; };
  this.get_language = function () { return conf.lang; };
  this.set_language = function () { };

  if (textarea) {
    var html_mode = compose ? $("[name='_is_html']").val() == '1' : $(textarea).is('.mce_editor');
    if (html_mode) mount(textarea.value);
  }
}
