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
 * Demo mail storage: replaces IMAP with the sample mailbox in plugins/synapse_demo/mail/.
 * Never opens a network connection. Every change (flags, moves, deletes, drafts, folders) lives in the
 * visitor's own session, so visitors never see each other's changes and everything resets on sign-out.
 * Enabled only by the demo build: $config['storage_driver'] = 'demo'.
 */
class rcube_demo extends rcube_storage
{
    public const SPECIAL = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash', 'Archive'];

    protected $connected = false;
    protected $sort_field = 'date';
    protected $sort_order = 'DESC';
    protected $search_string = null;
    protected $search_set = null;
    protected $search_sort_field = null;
    protected $parsed = [];

    /* ---------------- connection ---------------- */

    public function connect($host, $user, $pass, $port = 143, $use_ssl = null)
    {
        // the private per-visitor user (demo-xxxx) at sign-in, the display address afterwards; never a network call
        $cfg = rcube::get_instance()->config;
        $this->connected = (preg_match('/^demo-[0-9a-f]{12}$/', (string) $user) || $user === synapse_demo::DEMO_EMAIL)
            && hash_equals((string) $cfg->get('synapse_demo_pass', 'demo'), (string) $pass);

        return $this->connected;
    }

    public function close()
    {
        $this->connected = false;
    }

    public function is_connected()
    {
        return $this->connected;
    }

    public function check_connection()
    {
        return $this->connected;
    }

    public function get_error_code()
    {
        return 0;
    }

    public function get_error_str()
    {
        return '';
    }

    public function get_response_code()
    {
        return self::UNKNOWN;
    }

    public function set_debug($dbg = true) {}

    public function get_capability($cap)
    {
        return false;
    }

    public function check_permflag($flag)
    {
        return true;
    }

    public function get_hierarchy_delimiter()
    {
        return '/';
    }

    public function get_namespace($name = null)
    {
        $ns = ['personal' => [['', '/']], 'other' => null, 'shared' => null, 'prefix_in' => '', 'prefix_out' => ''];

        return $name ? ($ns[$name === 'prefix' ? 'prefix_in' : $name] ?? null) : $ns;
    }

    /* ---------------- visitor state (session) ---------------- */

    /** The visitor's mailbox: [folder => [uid => ['src' => file | null, 'raw' => string | null, 'flags' => [...]]]] */
    protected function &box()
    {
        if (empty($_SESSION['synapse_demo_box'])) {
            // another inbox (Send As address) open: its own sample mailbox
            $_SESSION['synapse_demo_box'] = self::fresh_box(empty($_SESSION['synapse_inbox']) ? 'mail' : 'mail-studio');
        }

        return $_SESSION['synapse_demo_box'];
    }

    protected static function fresh_box($dir)
    {
        $box = ['base' => time(), 'next' => 1000, 'folders' => []];
        foreach (self::SPECIAL as $f) {
            $box['folders'][$f] = [];
            foreach (glob(__DIR__ . '/' . $dir . '/' . $f . '/*.eml') ?: [] as $i => $file) {
                $raw = (string) file_get_contents($file, false, null, 0, 65536);
                $flags = preg_match('/^X-Demo-Flags: (.*)$/mi', $raw, $m) ? array_fill_keys(preg_split('/\s+/', trim($m[1])), true) : [];
                $box['folders'][$f][$i + 1] = ['src' => $dir . '/' . $f . '/' . basename($file), 'raw' => null, 'flags' => $flags];
            }
        }

        return $box;
    }

    /** Unread INBOX messages of a demo mailbox (0 = own) for the inbox switcher */
    public static function unseen_of($iid)
    {
        $active = (int) ($_SESSION['synapse_inbox'] ?? 0);
        $box = $iid === $active ? ($_SESSION['synapse_demo_box'] ?? null) : ($_SESSION['synapse_inbox_bags'][$iid]['synapse_demo_box'] ?? null);
        $box = $box ?: self::fresh_box($iid ? 'mail-studio' : 'mail');

        return count(array_filter($box['folders']['INBOX'] ?? [], static function ($m) { return empty($m['flags']['SEEN']); }));
    }

    protected function folder_name($folder)
    {
        return is_string($folder) && strlen($folder) ? $folder : $this->folder;
    }

    protected function &messages($folder)
    {
        $box = &$this->box();
        $folder = $this->folder_name($folder);
        if (!isset($box['folders'][$folder])) {
            $box['folders'][$folder] = [];
        }

        return $box['folders'][$folder];
    }

    /** uid list from '*', '1:3,5', [1, 2] */
    protected function uid_list($uids, $folder)
    {
        $all = array_keys($this->messages($folder));
        if ($uids === '*' || $uids === null) {
            return $all;
        }
        $out = [];
        foreach (is_array($uids) ? $uids : explode(',', (string) $uids) as $part) {
            if (preg_match('/^(\d+):(\d+|\*)$/', trim((string) $part), $m)) {
                $hi = $m[2] === '*' ? PHP_INT_MAX : (int) $m[2];
                foreach ($all as $u) {
                    if ($u >= (int) $m[1] && $u <= $hi) {
                        $out[] = $u;
                    }
                }
            } elseif (is_numeric($part)) {
                $out[] = (int) $part;
            }
        }

        return array_values(array_intersect(array_unique($out), $all));
    }

    /** Raw message with the date placeholders turned into real dates */
    protected function raw($folder, $uid)
    {
        $msg = $this->messages($folder)[$uid] ?? null;
        if (!$msg) {
            return null;
        }
        if ($msg['raw'] !== null) {
            return $msg['raw'];
        }
        $raw = (string) file_get_contents(__DIR__ . '/' . (strpos($msg['src'], 'mail') === 0 ? '' : 'mail/') . $msg['src']);
        $base = $this->box()['base'];

        return preg_replace_callback('/^Date: @@(\d+)\r?$/m', static function ($m) use ($base) {
            return 'Date: ' . date('r', $base - (int) $m[1] * 60) . "\r";
        }, $raw);
    }

    protected function parsed($folder, $uid)
    {
        $key = $this->folder_name($folder) . "\0" . $uid;
        if (!isset($this->parsed[$key])) {
            $raw = $this->raw($folder, $uid);
            $this->parsed[$key] = $raw === null ? null : rcube_mime::parse_message($raw);
        }

        return $this->parsed[$key];
    }

    /* ---------------- headers and listing ---------------- */

    public function get_message_headers($uid, $folder = null, $force = false)
    {
        $folder = $this->folder_name($folder);
        $raw = $this->raw($folder, $uid);
        if ($raw === null) {
            return false;
        }
        $struct = $this->parsed($folder, $uid);
        $hd = $struct->headers;
        $one = static function ($k) use ($hd) {
            $v = $hd[$k] ?? null;

            return is_array($v) ? (string) reset($v) : (string) $v;
        };

        $h = new rcube_message_header();
        $h->id = $h->uid = (int) $uid;
        $h->folder = $folder;
        $h->subject = $one('subject');
        $h->from = $one('from');
        $h->to = $one('to');
        $h->cc = $one('cc');
        $h->replyto = $one('reply-to');
        $h->in_reply_to = $one('in-reply-to');
        $h->date = $h->internaldate = $one('date');
        $h->timestamp = rcube_utils::strtotime($h->date);
        $h->messageID = $one('message-id');
        $h->size = strlen($raw);
        $h->charset = 'UTF-8';
        $h->ctype = strtolower($struct->mimetype);
        $h->flags = $this->messages($folder)[$uid]['flags'];

        return $h;
    }

    public function fetch_headers($folder, $msgs, $sort = true, $force = false)
    {
        $out = [];
        foreach ($this->uid_list($msgs, $folder) as $uid) {
            if ($h = $this->get_message_headers($uid, $folder)) {
                $out[$uid] = $h;
            }
        }

        return $out;
    }

    public function list_flags($folder, $uids, $mod_seq = null)
    {
        $out = [];
        foreach ($this->uid_list($uids, $folder) as $uid) {
            $out[$uid] = $this->messages($folder)[$uid]['flags'];
        }

        return $out;
    }

    /** Sorted uids of a folder (all, or only the search matches) */
    protected function sorted($folder, $uids, $field, $order)
    {
        $field = $field ?: 'date';
        $keys = [];
        foreach ($uids as $uid) {
            $h = $this->get_message_headers($uid, $folder);
            switch ($field) {
                case 'subject': $keys[$uid] = mb_strtolower((string) rcube_mime::decode_header($h->subject)); break;
                case 'from': $keys[$uid] = mb_strtolower((string) rcube_mime::decode_header($h->from)); break;
                case 'to': $keys[$uid] = mb_strtolower((string) rcube_mime::decode_header($h->to)); break;
                case 'size': $keys[$uid] = $h->size; break;
                default: $keys[$uid] = $h->timestamp;
            }
        }
        strtoupper((string) $order) === 'DESC' ? arsort($keys) : asort($keys);

        return array_keys($keys);
    }

    public function index($folder = null, $sort_field = null, $sort_order = null, $no_threads = false, $no_search = false)
    {
        $folder = $this->folder_name($folder);
        $uids = (!$no_search && $this->search_string && $this->search_set && $this->search_set->get_parameters('MAILBOX') === $folder)
            ? $this->search_set->get() : array_keys($this->messages($folder));
        $uids = $this->sorted($folder, array_intersect($uids, array_keys($this->messages($folder))), $sort_field ?? $this->sort_field, $sort_order ?? $this->sort_order);

        return new rcube_result_index($folder, '* SORT ' . implode(' ', $uids));
    }

    public function list_messages($folder = null, $page = null, $sort_field = null, $sort_order = null, $slice = 0)
    {
        $folder = $this->folder_name($folder);
        if ($sort_field) {
            $this->sort_field = $sort_field;
        }
        if ($sort_order) {
            $this->sort_order = $sort_order;
        }
        $uids = $this->index($folder, $this->sort_field, $this->sort_order)->get();
        $page = $page ?: $this->list_page;
        $uids = array_slice($uids, ($page - 1) * $this->page_size, $this->page_size);
        if ($slice) {
            $uids = array_slice($uids, -$slice, $slice);
        }

        return array_values($this->fetch_headers($folder, $uids));
    }

    public function count($folder = null, $mode = 'ALL', $force = false, $status = true)
    {
        $msgs = $this->messages($folder);
        $mode = strtoupper((string) $mode);
        if ($mode === 'UNSEEN') {
            return count(array_filter($msgs, static function ($m) { return empty($m['flags']['SEEN']); }));
        }
        if ($mode === 'RECENT') {
            return 0;
        }
        if ($this->search_string && $this->search_set && $this->search_set->get_parameters('MAILBOX') === $this->folder_name($folder) && $mode !== 'EXISTS') {
            return $this->search_set->count();
        }

        return count($msgs);
    }

    /* ---------------- search ---------------- */

    /** Small IMAP SEARCH subset: quoted terms match headers and text; UNSEEN / FLAGGED / UNANSWERED / DELETED filters */
    protected function match($folder, $uid, $str)
    {
        $msg = $this->messages($folder)[$uid];
        $s = strtoupper($str);
        foreach (['UNSEEN' => ['SEEN', false], 'FLAGGED' => ['FLAGGED', true], 'UNANSWERED' => ['ANSWERED', false], 'UNDELETED' => ['DELETED', false]] as $kw => [$flag, $want]) {
            if (preg_match('/(^|[\s(])' . $kw . '([\s)]|$)/', $s) && !empty($msg['flags'][$flag]) !== $want) {
                return false;
            }
        }
        if (!preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $str, $m)) {
            return true;
        }
        $terms = array_unique(array_filter(array_map('stripslashes', $m[1]), 'strlen'));
        if (!$terms) {
            return true;
        }
        $h = $this->get_message_headers($uid, $folder);
        $hay = mb_strtolower(rcube_mime::decode_header($h->subject . ' ' . $h->from . ' ' . $h->to . ' ' . $h->cc) . ' ' . $this->text_of($this->parsed($folder, $uid)));
        foreach ($terms as $t) {
            if (mb_strpos($hay, mb_strtolower($t)) !== false) {
                return true; // Roundcube ORs the selected fields
            }
        }

        return false;
    }

    protected function text_of($part)
    {
        $out = '';
        if (!empty($part->parts)) {
            foreach ($part->parts as $p) {
                $out .= ' ' . $this->text_of($p);
            }
        } elseif ($part && $part->ctype_primary === 'text') {
            $out = strip_tags((string) $part->body);
        }

        return $out;
    }

    protected function run_search($folder, $str)
    {
        $folder = $this->folder_name($folder);
        $uids = [];
        foreach (array_keys($this->messages($folder)) as $uid) {
            if ($this->match($folder, $uid, (string) $str)) {
                $uids[] = $uid;
            }
        }

        return new rcube_result_index($folder, '* SEARCH ' . implode(' ', $uids));
    }

    public function search($folder = null, $str = 'ALL', $charset = null, $sort_field = null)
    {
        $folder = is_array($folder) ? (reset($folder) ?: $this->folder) : $this->folder_name($folder);
        $this->search_string = $str ?: 'ALL';
        $this->search_sort_field = $sort_field;
        $this->search_set = $this->run_search($folder, $this->search_string);

        return $this->search_set;
    }

    public function search_once($folder = null, $str = 'ALL')
    {
        return $this->run_search($folder, $str);
    }

    public function refresh_search()
    {
        if ($this->search_string && $this->search_set) {
            $this->search_set = $this->run_search($this->search_set->get_parameters('MAILBOX'), $this->search_string);
        }

        return $this->get_search_set();
    }

    public function set_search_set($set)
    {
        $set = (array) $set;
        $this->search_string = $set[0] ?? null;
        $this->search_set = $set[1] ?? null;
        $this->search_sort_field = $set[3] ?? null;
    }

    public function get_search_set()
    {
        return $this->search_set ? [$this->search_string, $this->search_set, 'UTF-8', $this->search_sort_field, false] : null;
    }

    /* ---------------- message content ---------------- */

    public function get_message($uid, $folder = null)
    {
        $folder = $this->folder_name($folder);
        $h = $this->get_message_headers($uid, $folder);
        if (!$h) {
            return false;
        }
        $struct = clone $this->parsed($folder, $uid);
        if (empty($struct->parts)) {
            $struct->mime_id = '1';
        }
        $h->structure = $struct;

        return $h;
    }

    protected function find_part($part, $id)
    {
        if ((string) $part->mime_id === (string) $id) {
            return $part;
        }
        foreach ((array) $part->parts as $p) {
            if ($found = $this->find_part($p, $id)) {
                return $found;
            }
        }

        return null;
    }

    public function get_message_part($uid, $part, $o_part = null, $print = null, $fp = null, $skip_charset_conv = false, $max_bytes = 0, $formatted = true)
    {
        $struct = $this->parsed(null, $uid);
        if (!$struct) {
            return false;
        }
        if (empty($struct->parts) && ($part === '1' || $part === 1 || !$part)) {
            $p = $struct;
        } else {
            $p = $this->find_part($struct, $part);
        }
        if (!$p) {
            return false;
        }
        $body = (string) $p->body;
        if ($max_bytes) {
            $body = substr($body, 0, $max_bytes);
        }
        if ($fp) {
            fwrite($fp, $body);

            return true;
        }
        if ($print) {
            echo $body;

            return true;
        }

        return $body;
    }

    public function get_raw_body($uid, $fp = null, $part = null)
    {
        $raw = $this->raw(null, $uid);
        if ($raw === null) {
            return false;
        }
        if ($fp) {
            fwrite($fp, $raw);

            return true;
        }

        return $raw;
    }

    public function get_raw_headers($uid, $part = null)
    {
        $raw = (string) $this->raw(null, $uid);
        $pos = strpos($raw, "\r\n\r\n");

        return $pos === false ? $raw : substr($raw, 0, $pos);
    }

    public function print_raw_body($uid, $formatted = true)
    {
        echo (string) $this->raw(null, $uid);
    }

    /* ---------------- changes (session only) ---------------- */

    public function set_flag($uids, $flag, $folder = null, $skip_cache = false)
    {
        $msgs = &$this->messages($folder);
        $flag = strtoupper((string) $flag);
        $unset = strpos($flag, 'UN') === 0 && $flag !== 'UNKNOWN';
        $name = $unset ? substr($flag, 2) : $flag;
        foreach ($this->uid_list($uids, $folder) as $uid) {
            if ($unset) {
                unset($msgs[$uid]['flags'][$name]);
            } else {
                $msgs[$uid]['flags'][$name] = true;
            }
        }

        return true;
    }

    public function save_message($folder, &$message, $headers = '', $is_file = false, $flags = [], $date = null, $binary = false)
    {
        $raw = $is_file ? (string) file_get_contents($message) : (string) $message;
        if ($headers) {
            $raw = rtrim($headers, "\r\n") . "\r\n" . $raw;
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            return false; // demo: keep sessions small
        }
        $box = &$this->box();
        $uid = ++$box['next'];
        $f = [];
        foreach ((array) $flags as $fl) {
            $f[strtoupper(str_replace(['\\', '$'], '', $fl))] = true;
        }
        $msgs = &$this->messages($folder);
        $msgs[$uid] = ['src' => null, 'raw' => $raw, 'flags' => $f];

        return $uid;
    }

    public function move_message($uids, $to, $from = null)
    {
        if (!$this->copy_message($uids, $to, $from)) {
            return false;
        }

        return $this->delete_message($uids, $from);
    }

    public function copy_message($uids, $to, $from = null)
    {
        $box = &$this->box();
        if (!isset($box['folders'][$to])) {
            return false;
        }
        $src = $this->messages($from);
        foreach ($this->uid_list($uids, $from) as $uid) {
            $box['folders'][$to][++$box['next']] = $src[$uid];
        }

        return true;
    }

    public function delete_message($uids, $folder = null)
    {
        $msgs = &$this->messages($folder);
        foreach ($this->uid_list($uids, $folder) as $uid) {
            unset($msgs[$uid]);
        }
        if ($this->search_set) {
            $this->refresh_search();
        }

        return true;
    }

    public function expunge_message($uids, $folder = null, $clear_cache = true)
    {
        $msgs = $this->messages($folder);
        $gone = [];
        foreach ($this->uid_list($uids ?: '*', $folder) as $uid) {
            if (!empty($msgs[$uid]['flags']['DELETED'])) {
                $gone[] = $uid;
            }
        }

        return $gone ? $this->delete_message($gone, $folder) : true;
    }

    public function annotate_message($annotation, $uids, $folder = null)
    {
        return false;
    }

    /* ---------------- folders ---------------- */

    protected function folder_names()
    {
        $names = array_keys($this->box()['folders']);
        $special = array_values(array_intersect(self::SPECIAL, $names));
        $other = array_values(array_diff($names, self::SPECIAL));
        sort($other, SORT_NATURAL | SORT_FLAG_CASE);

        return array_merge($special, $other);
    }

    public function list_folders_subscribed($root = '', $name = '*', $filter = null, $rights = null, $skip_sort = false)
    {
        return $this->folder_names();
    }

    public function list_folders($root = '', $name = '*', $filter = null, $rights = null, $skip_sort = false)
    {
        return $this->folder_names();
    }

    public function subscribe($folders)
    {
        return true;
    }

    public function unsubscribe($folders)
    {
        return true;
    }

    public function create_folder($folder, $subscribe = false, $type = null, $noselect = false)
    {
        $box = &$this->box();
        if (isset($box['folders'][$folder]) || count($box['folders']) >= 30 || strlen($folder) > 100) {
            return false;
        }
        $box['folders'][$folder] = [];

        return true;
    }

    public function rename_folder($folder, $new_name)
    {
        $box = &$this->box();
        if (in_array($folder, self::SPECIAL, true) || !isset($box['folders'][$folder]) || isset($box['folders'][$new_name])) {
            return false;
        }
        $box['folders'][$new_name] = $box['folders'][$folder];
        unset($box['folders'][$folder]);

        return true;
    }

    public function delete_folder($folder)
    {
        $box = &$this->box();
        if (in_array($folder, self::SPECIAL, true)) {
            return false;
        }
        unset($box['folders'][$folder]);

        return true;
    }

    public function folder_exists($folder, $subscription = false)
    {
        return isset($this->box()['folders'][$folder]);
    }

    public function folder_size($folder)
    {
        $size = 0;
        foreach (array_keys($this->messages($folder)) as $uid) {
            $size += strlen((string) $this->raw($folder, $uid));
        }

        return $size;
    }

    public function folder_namespace($folder)
    {
        return 'personal';
    }

    public function folder_attributes($folder, $force = false)
    {
        return [];
    }

    public function folder_data($folder)
    {
        return ['EXISTS' => count($this->messages($folder)), 'UIDNEXT' => $this->box()['next'] + 1, 'UIDVALIDITY' => 1, 'PERMANENTFLAGS' => ['\\*']];
    }

    public function folder_info($folder)
    {
        $special = in_array($folder, self::SPECIAL, true);

        return [
            'name' => $folder, 'attributes' => [], 'namespace' => 'personal', 'special' => $special,
            'noselect' => false, 'norename' => $special, 'is_root' => false, 'rights' => ['l', 'r', 's', 'w', 'i', 'p', 'k', 'x', 't', 'e', 'a'],
        ];
    }

    public function folder_status($folder = null, &$diff = [])
    {
        return 0;
    }

    public function folder_sync($folder)
    {
        return true;
    }

    public function mod_folder($folder, $mode = 'out')
    {
        return $folder;
    }

    public function get_special_folders($forced = false)
    {
        return ['drafts' => 'Drafts', 'sent' => 'Sent', 'junk' => 'Junk', 'trash' => 'Trash', 'archive' => 'Archive'];
    }

    public function get_quota($folder = null)
    {
        $used = 0;
        foreach ($this->folder_names() as $f) {
            $used += $this->folder_size($f);
        }
        $used = (int) ceil($used / 1024);
        $total = 1024 * 1024; // 1 GB in KB, like a typical hosting mailbox

        return ['used' => $used, 'total' => $total, 'percent' => min(100, round($used / $total * 100)), 'free' => 100 - min(100, round($used / $total * 100)),
            'all' => ['' => ['storage' => ['used' => $used, 'total' => $total]]]];
    }

    /* ---------------- ACL and metadata: not available ---------------- */

    public function set_acl($folder, $user, $acl)
    {
        return false;
    }

    public function delete_acl($folder, $user)
    {
        return false;
    }

    public function get_acl($folder)
    {
        return false;
    }

    public function list_rights($folder, $user)
    {
        return false;
    }

    public function my_rights($folder)
    {
        return ['l', 'r', 's', 'w', 'i', 'p', 'k', 'x', 't', 'e', 'a'];
    }

    public function set_metadata($folder, $entries)
    {
        return false;
    }

    public function delete_metadata($folder, $entries)
    {
        return false;
    }

    public function get_metadata($folder, $entries, $options = [], $force = false)
    {
        return [];
    }
}
