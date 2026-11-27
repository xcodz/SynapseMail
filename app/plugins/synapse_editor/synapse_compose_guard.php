<?php

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

/**
 * Quoted and reopened mail is shown inside the compose form (Lexical keeps it as read-only HTML next to the editor).
 * Washed mail may still hold form controls, and those would belong to the compose form: a quoted submit button
 * could send the reply with its own field values. This turns every form control into plain text before the body
 * reaches the page, so nothing from a received mail can submit or change the compose form.
 */
class synapse_compose_guard
{
    // controls that submit, carry a value or point at a form
    private const CONTROLS = ['button', 'input', 'select', 'textarea', 'output', 'datalist', 'option', 'optgroup', 'label', 'form', 'fieldset', 'legend'];
    private const FORM_ATTRS = ['form', 'formaction', 'formmethod', 'formenctype', 'formtarget', 'formnovalidate'];
    // kept on the text that replaces a control, so the quote keeps its look
    private const KEEP_ATTRS = ['style', 'class', 'dir', 'lang', 'title'];

    /** True when the HTML may hold a form control or a form attribute (cheap check before parsing) */
    public static function needed($html)
    {
        return (bool) preg_match('/<\s*(?:' . implode('|', self::CONTROLS) . ')\b|\sform(?:action|method|enctype|target|novalidate)?\s*=/i', (string) $html);
    }

    /** The HTML with every form control turned into text; unchanged when there is none */
    public static function defuse($html)
    {
        $html = (string) $html;
        if ($html === '' || !self::needed($html) || !class_exists('DOMDocument')) {
            return $html;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // the wrapper keeps fragments intact; the XML declaration makes libxml read UTF-8
        $ok = $doc->loadHTML('<?xml encoding="UTF-8"?><div id="synapse-guard-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $ok ? self::root($doc) : null;
        if (!$root) {
            // could not parse: drop the controls' tags by pattern rather than pass them through
            return self::strip($html);
        }

        $xpath = new DOMXPath($doc);
        // innermost first, so nested controls are handled before their parents
        $nodes = [];
        foreach ($xpath->query('.//*', $root) as $el) {
            $nodes[] = $el;
        }
        foreach (array_reverse($nodes) as $el) {
            foreach (self::FORM_ATTRS as $a) {
                if ($el->hasAttribute($a)) {
                    $el->removeAttribute($a);
                }
            }
            $tag = strtolower($el->nodeName);
            // options go together with their list (the list shows the chosen one)
            if (in_array($tag, ['option', 'optgroup'], true) && self::in_list($el)) {
                continue;
            }
            if (in_array($tag, self::CONTROLS, true)) {
                self::replace($doc, $el);
            }
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function root(DOMDocument $doc)
    {
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === 'synapse-guard-root') {
                return $div;
            }
        }

        return null;
    }

    /** Swap a control for a <span> (a <div> for form/fieldset) with what the reader saw */
    private static function replace(DOMDocument $doc, DOMElement $el)
    {
        $tag = strtolower($el->nodeName);
        $box = $doc->createElement(in_array($tag, ['form', 'fieldset', 'legend'], true) ? 'div' : 'span');
        foreach (self::KEEP_ATTRS as $a) {
            if ($el->hasAttribute($a)) {
                $box->setAttribute($a, $el->getAttribute($a));
            }
        }

        if ($tag === 'input') {
            $type = strtolower($el->getAttribute('type') ?: 'text');
            if (!in_array($type, ['hidden', 'checkbox', 'radio', 'file', 'image', 'password', 'range', 'color'], true)) {
                $box->appendChild($doc->createTextNode($el->getAttribute('value')));
            }
        } elseif ($tag === 'select') {
            $box->appendChild($doc->createTextNode(self::selected_text($el)));
        } elseif ($tag === 'textarea' || $tag === 'option') {
            $box->appendChild($doc->createTextNode($el->textContent));
        } elseif ($tag !== 'datalist') {
            while ($el->firstChild) {
                $box->appendChild($el->firstChild);
            }
        }

        $el->parentNode->replaceChild($box, $el);
    }

    private static function in_list(DOMElement $el)
    {
        for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
            if (in_array(strtolower($p->nodeName), ['select', 'datalist'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function selected_text(DOMElement $select)
    {
        $first = null;
        foreach ($select->getElementsByTagName('option') as $opt) {
            if ($opt->hasAttribute('selected')) {
                return $opt->textContent;
            }
            $first = $first ?? $opt->textContent;
        }

        return (string) $first;
    }

    /** Fallback without a parser: remove the controls' tags (their text stays) and form attributes */
    private static function strip($html)
    {
        $html = preg_replace('/<\/?\s*(?:' . implode('|', self::CONTROLS) . ')\b[^>]*>/i', '', $html);

        return preg_replace('/\sform(?:action|method|enctype|target|novalidate)?\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    }
}
