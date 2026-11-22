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
// SynapseMail compose editor: Lexical rich text with block direction (LTR/RTL), alignment and fonts.
// Built with esbuild into skins/synapse/lexical/editor.bundle.js (window.SynapseEditor).
import {
  createEditor, $getRoot, $getSelection, $isRangeSelection, $createParagraphNode, $isElementNode, $isRootOrShadowRoot,
  FORMAT_TEXT_COMMAND, FORMAT_ELEMENT_COMMAND, UNDO_COMMAND, REDO_COMMAND, CAN_UNDO_COMMAND, CAN_REDO_COMMAND,
  SELECTION_CHANGE_COMMAND, COMMAND_PRIORITY_LOW, $insertNodes,
} from 'lexical';
import { registerRichText, HeadingNode, QuoteNode, $createHeadingNode, $createQuoteNode, $isHeadingNode, $isQuoteNode } from '@lexical/rich-text';
import { registerHistory, createEmptyHistoryState } from '@lexical/history';
import { $patchStyleText, $getSelectionStyleValueForProperty, $setBlocksType, $copyBlockFormatIndent } from '@lexical/selection';
import { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';
import { $findMatchingParent, mergeRegister } from '@lexical/utils';
import {
  ListNode, ListItemNode, registerList, $isListNode, INSERT_ORDERED_LIST_COMMAND, INSERT_UNORDERED_LIST_COMMAND, REMOVE_LIST_COMMAND,
} from '@lexical/list';
import { LinkNode, AutoLinkNode } from '@lexical/link';

export const FONTS = [
  ['Inter', 'Inter, Arial, sans-serif'],
  ['Tahoma', 'Tahoma, Geneva, sans-serif'],
  ['Arial', 'Arial, Helvetica, sans-serif'],
  ['Verdana', 'Verdana, Geneva, sans-serif'],
  ['Georgia', 'Georgia, serif'],
  ['Times New Roman', '"Times New Roman", Times, serif'],
  ['Courier New', '"Courier New", Courier, monospace'],
  ['IBM Plex Mono', '"IBM Plex Mono", "Courier New", monospace'],
];

const BLOCKS = [
  ['paragraph', 'Paragraph'], ['h1', 'Heading 1'], ['h2', 'Heading 2'], ['h3', 'Heading 3'],
  ['quote', 'Quote'], ['bullet', 'Bullet list'], ['number', 'Numbered list'],
];

const RTL_LANGS = /^(fa|ar|he|ur|ps|ku|ckb|yi|dv|sd|ug)([_-]|$)/i;

export function isRtlLanguage(lang) { return RTL_LANGS.test(lang || ''); }
export function defaultFontFor(lang) { return /^(fa|ar)([_-]|$)/i.test(lang || '') ? FONTS[1][1] : FONTS[0][1]; }

function el(tag, attrs, children) {
  const node = document.createElement(tag);
  Object.entries(attrs || {}).forEach(([k, v]) => {
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v);
  });
  (children || []).forEach((c) => node.appendChild(c));
  return node;
}

// Top-level block of the selection anchor (list items resolve to their list item)
function $anchorBlock(selection) {
  const anchor = selection.anchor.getNode();
  if (anchor.getKey() === 'root') return null;
  return $findMatchingParent(anchor, (n) => $isElementNode(n) && !n.isInline() && n.getParent() && ($isRootOrShadowRoot(n.getParent()) || $isListNode(n.getParent())))
    || anchor.getTopLevelElement();
}

function $selectedBlocks(selection) {
  const blocks = new Set();
  selection.getNodes().forEach((node) => {
    const block = $isElementNode(node) && !node.isInline() ? node : $findMatchingParent(node, (n) => $isElementNode(n) && !n.isInline());
    if (block && block.getKey() !== 'root') blocks.add(block.getLatest());
  });
  const anchorBlock = $anchorBlock(selection);
  if (anchorBlock) blocks.add(anchorBlock.getLatest());
  return [...blocks];
}

/**
 * create(host, { lang, placeholder, onChange, icons: (name) => html, labels })
 * Returns { editor, getHTML, setHTML, insertHTML, insertText, getText, focus, isEmpty, destroy }
 */
export function create(host, opts = {}) {
  const lang = opts.lang || document.documentElement.lang || 'en';
  const baseFont = opts.font || defaultFontFor(lang);
  const icon = opts.icons || ((name) => `<i data-lucide="${name}"></i>`);
  const label = (k, d) => (opts.labels && opts.labels[k]) || d;

  const editor = createEditor({
    namespace: 'SynapseMailCompose',
    nodes: [HeadingNode, QuoteNode, ListNode, ListItemNode, LinkNode, AutoLinkNode],
    onError: (e) => { console.error(e); },
    theme: {
      paragraph: 'lx-p', quote: 'lx-quote',
      heading: { h1: 'lx-h1', h2: 'lx-h2', h3: 'lx-h3' },
      list: { ul: 'lx-ul', ol: 'lx-ol', listitem: 'lx-li', nested: { listitem: 'lx-nested' } },
      link: 'lx-link',
      text: { bold: 'lx-bold', italic: 'lx-italic', underline: 'lx-underline', strikethrough: 'lx-strike' },
    },
  });

  /* ---------- toolbar: [Style] [Font] [Undo] [Redo] [B] [I] [U] | [LTR] [RTL] | [L] [C] [R] [J] ---------- */
  const btn = (name, title, iconName, onclick) => {
    const b = el('button', { type: 'button', class: 'lx-btn', title, 'aria-label': title, 'data-cmd': name, onmousedown: (e) => e.preventDefault(), onclick });
    b.innerHTML = icon(iconName);
    return b;
  };
  const sep = () => el('span', { class: 'lx-sep', 'aria-hidden': 'true' });

  const blockSelect = el('select', { class: 'lx-select lx-block', title: label('style', 'Text style'), 'aria-label': label('style', 'Text style') });
  BLOCKS.forEach(([v, t]) => blockSelect.appendChild(el('option', { value: v, text: label(v, t) })));
  blockSelect.addEventListener('change', () => { setBlock(blockSelect.value); editor.focus(); });

  const fontSelect = el('select', { class: 'lx-select lx-font', title: label('font', 'Font'), 'aria-label': label('font', 'Font') });
  FONTS.forEach(([name, stack]) => fontSelect.appendChild(el('option', { value: stack, text: name, style: `font-family:${stack}` })));
  fontSelect.value = baseFont;
  fontSelect.addEventListener('change', () => {
    editor.update(() => {
      const sel = $getSelection();
      if ($isRangeSelection(sel)) $patchStyleText(sel, { 'font-family': fontSelect.value === baseFont ? null : fontSelect.value });
    });
    editor.focus();
  });

  const buttons = {
    undo: btn('undo', label('undo', 'Undo'), 'undo-2', () => editor.dispatchCommand(UNDO_COMMAND, undefined)),
    redo: btn('redo', label('redo', 'Redo'), 'redo-2', () => editor.dispatchCommand(REDO_COMMAND, undefined)),
    bold: btn('bold', label('bold', 'Bold'), 'bold', () => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold')),
    italic: btn('italic', label('italic', 'Italic'), 'italic', () => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic')),
    underline: btn('underline', label('underline', 'Underline'), 'underline', () => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline')),
    ltr: btn('ltr', label('ltr', 'Left-to-right'), 'pilcrow-right', () => setDirection('ltr')),
    rtl: btn('rtl', label('rtl', 'Right-to-left'), 'pilcrow-left', () => setDirection('rtl')),
    left: btn('left', label('alignleft', 'Align left'), 'align-left', () => editor.dispatchCommand(FORMAT_ELEMENT_COMMAND, 'left')),
    center: btn('center', label('aligncenter', 'Align center'), 'align-center', () => editor.dispatchCommand(FORMAT_ELEMENT_COMMAND, 'center')),
    right: btn('right', label('alignright', 'Align right'), 'align-right', () => editor.dispatchCommand(FORMAT_ELEMENT_COMMAND, 'right')),
    justify: btn('justify', label('alignjustify', 'Justify'), 'align-justify', () => editor.dispatchCommand(FORMAT_ELEMENT_COMMAND, 'justify')),
  };
  buttons.undo.disabled = buttons.redo.disabled = true;

  const toolbar = el('div', { class: 'lx-toolbar', role: 'toolbar', 'aria-label': label('toolbar', 'Formatting') }, [
    blockSelect, fontSelect, buttons.undo, buttons.redo, buttons.bold, buttons.italic, buttons.underline,
    sep(), buttons.ltr, buttons.rtl, sep(), buttons.left, buttons.center, buttons.right, buttons.justify,
  ]);

  const content = el('div', { class: 'lx-content', contenteditable: 'true', spellcheck: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': label('body', 'Message body') });
  content.style.fontFamily = baseFont;
  const placeholder = el('div', { class: 'lx-placeholder', text: opts.placeholder || 'Write your message…' });
  placeholder.style.fontFamily = baseFont;
  const surface = el('div', { class: 'lx-surface' }, [content, placeholder]);
  host.classList.add('lx-editor');
  host.append(toolbar, surface);
  if (isRtlLanguage(lang)) placeholder.dir = 'auto';

  editor.setRootElement(content);

  function setDirection(dir) {
    editor.update(() => {
      const sel = $getSelection();
      if (!$isRangeSelection(sel)) return;
      // direction lives on the block node itself (serialized as `direction`, exported as dir="")
      $selectedBlocks(sel).forEach((block) => {
        block.setDirection(dir);
        // a list item's direction must also be on its list so markers flip
        if ($isListNode(block.getParent())) block.getParent().setDirection(dir);
      });
    });
    editor.focus();
  }

  function setBlock(type) {
    editor.update(() => {
      const sel = $getSelection();
      if (!$isRangeSelection(sel)) return;
      if (type === 'bullet') return editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined);
      if (type === 'number') return editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined);
      const block = $anchorBlock(sel);
      if (block && $isListNode(block.getParent())) editor.dispatchCommand(REMOVE_LIST_COMMAND, undefined);
      const factory = type === 'quote' ? () => $createQuoteNode() : /^h[1-3]$/.test(type) ? () => $createHeadingNode(type) : () => $createParagraphNode();
      // default copies alignment/indent only: carry the block direction across the type change too
      $setBlocksType(sel, factory, (src, dest) => { $copyBlockFormatIndent(src, dest); dest.setDirection(src.getDirection()); });
    });
  }

  function updateToolbar() {
    editor.getEditorState().read(() => {
      const sel = $getSelection();
      if (!$isRangeSelection(sel)) return;
      const block = $anchorBlock(sel);
      let blockType = 'paragraph', align = '', dir = null;
      if (block) {
        const list = $isListNode(block.getParent()) ? block.getParent() : null;
        blockType = list ? (list.getListType() === 'number' ? 'number' : 'bullet') : $isHeadingNode(block) ? block.getTag() : $isQuoteNode(block) ? 'quote' : 'paragraph';
        align = block.getFormatType();
        dir = block.getDirection();
        if (!dir) {
          // auto direction: reflect what the browser resolved for this block
          const dom = editor.getElementByKey(block.getKey());
          dir = dom ? getComputedStyle(dom).direction : 'ltr';
        }
      }
      blockSelect.value = blockType;
      const font = $getSelectionStyleValueForProperty(sel, 'font-family', baseFont);
      fontSelect.value = FONTS.some(([, s]) => s === font) ? font : baseFont;
      const active = {
        bold: sel.hasFormat('bold'), italic: sel.hasFormat('italic'), underline: sel.hasFormat('underline'),
        ltr: dir === 'ltr', rtl: dir === 'rtl',
        left: align === 'left' || align === 'start' && dir !== 'rtl', center: align === 'center', right: align === 'right' || align === 'end' && dir !== 'rtl', justify: align === 'justify',
      };
      Object.keys(active).forEach((k) => { buttons[k].classList.toggle('active', !!active[k]); buttons[k].setAttribute('aria-pressed', String(!!active[k])); });
    });
  }

  const unregister = mergeRegister(
    registerRichText(editor),
    registerHistory(editor, createEmptyHistoryState(), 300),
    registerList(editor),
    editor.registerCommand(CAN_UNDO_COMMAND, (v) => { buttons.undo.disabled = !v; return false; }, COMMAND_PRIORITY_LOW),
    editor.registerCommand(CAN_REDO_COMMAND, (v) => { buttons.redo.disabled = !v; return false; }, COMMAND_PRIORITY_LOW),
    editor.registerCommand(SELECTION_CHANGE_COMMAND, () => { updateToolbar(); return false; }, COMMAND_PRIORITY_LOW),
    editor.registerUpdateListener(({ dirtyElements, dirtyLeaves }) => {
      updateToolbar();
      placeholder.hidden = !isEmpty();
      if ((dirtyElements.size || dirtyLeaves.size) && opts.onChange) opts.onChange();
    }),
  );

  function isEmpty() {
    return editor.getEditorState().read(() => {
      const root = $getRoot();
      return root.getTextContent().trim() === '' && root.getChildrenSize() <= 1 && !root.getChildren().some((c) => $isListNode(c));
    });
  }

  function getHTML() {
    let out = '';
    editor.getEditorState().read(() => { out = $generateHtmlFromNodes(editor, null); });
    // blocks without an explicit direction keep the browser's bidi detection in mail clients
    const wrap = document.createElement('div');
    wrap.innerHTML = out;
    [...wrap.children].forEach((block) => { if (!block.hasAttribute('dir')) block.setAttribute('dir', 'auto'); });
    wrap.querySelectorAll('[class]').forEach((n) => { if (/^lx-|\blx-/.test(n.className)) n.removeAttribute('class'); });
    return wrap.innerHTML;
  }

  function nodesFromHTML(html) {
    const dom = new DOMParser().parseFromString(`<body>${html || ''}</body>`, 'text/html');
    // dir="auto" is how we export undirected blocks: import them as undirected again
    dom.querySelectorAll('[dir="auto"]').forEach((n) => n.removeAttribute('dir'));
    return $generateNodesFromDOM(editor, dom);
  }

  function setHTML(html) {
    editor.update(() => {
      const root = $getRoot();
      root.clear();
      const nodes = nodesFromHTML(html).filter(Boolean);
      // inline nodes at top level must live inside a paragraph
      let para = null;
      nodes.forEach((node) => {
        if ($isElementNode(node) && !node.isInline()) { para = null; root.append(node); }
        else { if (!para) { para = $createParagraphNode(); root.append(para); } para.append(node); }
      });
      if (root.getChildrenSize() === 0) root.append($createParagraphNode());
    }, { tag: 'history-merge', discrete: true }); // discrete: commit now so a save/send right after sees it
  }

  function insertHTML(html) {
    editor.update(() => {
      let sel = $getSelection();
      if (!$isRangeSelection(sel)) { $getRoot().selectEnd(); sel = $getSelection(); }
      $insertNodes(nodesFromHTML(html));
    }, { discrete: true });
  }

  function insertText(text) {
    editor.update(() => {
      let sel = $getSelection();
      if (!$isRangeSelection(sel)) { $getRoot().selectEnd(); sel = $getSelection(); }
      sel.insertRawText(text);
    }, { discrete: true });
  }

  function getText() {
    return editor.getEditorState().read(() => $getRoot().getTextContent());
  }

  function getSelectedText() {
    return editor.getEditorState().read(() => { const s = $getSelection(); return s ? s.getTextContent() : ''; });
  }

  return {
    editor, toolbar, content, baseFont,
    getHTML, setHTML, insertHTML, insertText, getText, getSelectedText, isEmpty,
    focus: (atEnd) => { editor.focus(() => {}, { defaultSelection: atEnd ? 'rootEnd' : 'rootStart' }); },
    setDirection, destroy: () => { unregister(); editor.setRootElement(null); },
  };
}
