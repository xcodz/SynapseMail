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
 * SynapseMail: adds a short body text preview to message list rows ('preview' list column).
 * Two IMAP round-trips per page: BODYSTRUCTURE for all rows, then a partial
 * BODY.PEEK of the first text part (grouped by part number).
 */
class synapse_preview extends rcube_plugin
{
    public $task = 'mail';

    private const LENGTH = 140;
    private const FETCH_BYTES = 1500;

    public function init()
    {
        $this->add_hook('messages_list', [$this, 'messages_list']);
    }

    public function messages_list($args)
    {
        if (empty($args['messages']) || !is_array($args['messages'])) {
            return $args;
        }

        $rcmail = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $conn = $storage->conn ?? null;

        if (!$conn || !method_exists($conn, 'fetch')) {
            return $args;
        }

        // group uids by folder (multi-folder search results)
        $folders = [];
        foreach ($args['messages'] as $header) {
            if ($header && !empty($header->uid)) {
                $folders[$header->folder ?: $storage->get_folder()][] = $header->uid;
            }
        }

        $previews = [];
        foreach ($folders as $folder => $uids) {
            try {
                $previews[$folder] = $this->fetch_previews($conn, $folder, $uids);
            } catch (Throwable $e) {
                // a preview is cosmetic, never break the list
                $previews[$folder] = [];
            }
        }

        foreach ($args['messages'] as $header) {
            if ($header && !empty($header->uid)) {
                $folder = $header->folder ?: $storage->get_folder();
                // list_cols are merged into the row data sent to the client (widescreen ignores extra columns)
                $header->list_cols = array_merge((array) ($header->list_cols ?? []), ['preview' => rcube::Q($previews[$folder][$header->uid] ?? '')]);
            }
        }

        return $args;
    }

    private function fetch_previews($conn, $folder, $uids)
    {
        $structs = $conn->fetch($folder, $uids, true, ['BODYSTRUCTURE']);
        if (empty($structs)) {
            return [];
        }

        $parts = []; // part number => [uid => [encoding, charset, is_html]]
        foreach ($structs as $msg) {
            if (empty($msg->bodystructure) || !($found = $this->find_text_part($msg->bodystructure))) {
                continue;
            }
            [$part, $info] = $found;
            $parts[$part][$msg->uid] = $info;
        }

        $result = [];
        foreach ($parts as $part => $msgs) {
            $item = 'BODY.PEEK[' . $part . ']<0.' . self::FETCH_BYTES . '>';
            $bodies = $conn->fetch($folder, array_keys($msgs), true, [$item]);

            foreach ((array) $bodies as $msg) {
                if (empty($msg->bodypart) || !isset($msgs[$msg->uid])) {
                    continue;
                }
                $result[$msg->uid] = $this->to_preview(reset($msg->bodypart), $msgs[$msg->uid]);
            }
        }

        return $result;
    }

    /**
     * Find the first inline text/plain (fallback text/html) part in a raw BODYSTRUCTURE array
     */
    private function find_text_part($struct, $prefix = '')
    {
        $html = null;
        $plain = $this->walk($struct, $prefix, $html);

        return $plain ?: $html;
    }

    private function walk($struct, $prefix, &$html)
    {
        // multipart: list of child arrays followed by subtype string
        if (is_array($struct[0] ?? null)) {
            $n = 1;
            foreach ($struct as $child) {
                if (!is_array($child)) {
                    break;
                }
                $id = $prefix === '' ? (string) $n : $prefix . '.' . $n;
                if ($found = $this->walk($child, $id, $html)) {
                    return $found;
                }
                $n++;
            }

            return null;
        }

        $type = strtolower((string) ($struct[0] ?? ''));
        $subtype = strtolower((string) ($struct[1] ?? ''));
        $disposition = is_array($struct[8] ?? null) ? strtolower((string) ($struct[8][0] ?? '')) : '';

        if ($type != 'text' || $disposition == 'attachment' || !in_array($subtype, ['plain', 'html'])) {
            return null;
        }

        $charset = '';
        if (is_array($struct[2] ?? null)) {
            for ($i = 0; $i < count($struct[2]) - 1; $i += 2) {
                if (strtolower((string) $struct[2][$i]) == 'charset') {
                    $charset = (string) $struct[2][$i + 1];
                }
            }
        }

        $found = [$prefix === '' ? '1' : $prefix, [strtolower((string) ($struct[5] ?? '7bit')), $charset ?: RCUBE_CHARSET, $subtype == 'html']];

        if ($subtype == 'html') {
            $html = $html ?: $found;

            return null;
        }

        return $found;
    }

    private function to_preview($body, $info)
    {
        [$encoding, $charset, $is_html] = $info;

        if ($encoding == 'base64') {
            // partial fetch may cut a base64 quad: keep whole quads only
            $body = preg_replace('/[^A-Za-z0-9+\/=]/', '', $body);
            $body = (string) base64_decode(substr($body, 0, strlen($body) - strlen($body) % 4));
        } elseif ($encoding == 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }

        $body = rcube_charset::convert($body, $charset);

        if ($is_html) {
            $body = preg_replace('#<(style|script|head)\b.*?(</\1>|$)#is', ' ', $body);
            $body = html_entity_decode(strip_tags($body), \ENT_QUOTES | \ENT_HTML5, RCUBE_CHARSET);
        }

        // drop quoted lines and signatures, collapse whitespace
        $body = preg_replace('/^>.*$/m', '', $body);
        $body = preg_replace('/\s+/u', ' ', $body);
        $body = trim($body);

        return mb_strlen($body) > self::LENGTH ? rtrim(mb_substr($body, 0, self::LENGTH)) . '…' : $body;
    }
}
