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
 * All inboxes: the own INBOX list also shows the INBOX of every other inbox (Send As address). Nothing is copied:
 * messages of another inbox carry the folder name "@sm#<identity id>:<folder>" and every storage call for such a
 * folder goes to that address's own IMAP server (synapse_inbox::kid_imap), with its own session caches.
 * Core lists them like a multi-folder search (uids "<uid>-<folder>"), so preview, flags, delete and reply just work.
 */
trait synapse_router
{
    /** @var array identity id => storage object, or null when that inbox cannot be opened */
    protected $sm_kids = [];
    /** @var bool true while the own mailbox is asked directly (no merging) */
    protected $sm_raw = false;

    /** Connected storage for another inbox, or null */
    abstract protected function sm_open($iid);

    public static function sm_folder($iid, $folder = 'INBOX')
    {
        return synapse_inbox::folder_of($iid, $folder);
    }

    /** [identity id, real folder] of a virtual folder name, null for own folders */
    public static function sm_parse($folder)
    {
        return synapse_inbox::parse_folder($folder);
    }

    protected function sm_f($folder)
    {
        return is_string($folder) && strlen($folder) ? $folder : $this->folder;
    }

    /** The merged INBOX list applies here */
    protected function sm_all($folder)
    {
        return !$this->sm_raw && synapse_inbox::$all && synapse_inbox::$others && $folder === 'INBOX' && empty($this->search_set);
    }

    protected function sm_kid($iid)
    {
        if (!array_key_exists($iid, $this->sm_kids)) {
            $this->sm_kids[$iid] = isset(synapse_inbox::$others[$iid]) ? synapse_inbox::in_bag($iid, function () use ($iid) { return $this->sm_open($iid); }) : null;
            if (!$this->sm_kids[$iid] && isset(synapse_inbox::$others[$iid])) {
                synapse_inbox::unreachable($iid);
            }
        }

        return $this->sm_kids[$iid];
    }

    /** Run $fn($storage, $real_folder) against the inbox of a virtual folder; $fail when it is not available */
    protected function sm_on($folder, callable $fn, $fail = false)
    {
        [$iid, $real] = self::sm_parse($folder);
        $kid = $this->sm_kid($iid);

        return $kid ? synapse_inbox::in_bag($iid, static function () use ($kid, $real, $fn) {
            $kid->set_folder($real);

            return $fn($kid, $real);
        }) : $fail;
    }

    /** Run against the own mailbox only */
    protected function sm_own(callable $fn)
    {
        $was = $this->sm_raw;
        $this->sm_raw = true;
        try {
            return $fn();
        } finally {
            $this->sm_raw = $was;
        }
    }

    protected static function sm_tag($iid, $headers)
    {
        foreach ((array) $headers as $h) {
            if ($h) {
                $h->folder = self::sm_folder($iid, $h->folder ?: 'INBOX');
            }
        }

        return (array) $headers;
    }

    /** Own unread or total count of INBOX, without the other inboxes */
    public function sm_own_count($mode = 'UNSEEN')
    {
        return $this->sm_own(function () use ($mode) { return parent::count('INBOX', $mode); });
    }

    /* ---------------- listing ---------------- */

    public function get_search_set()
    {
        // core shows a list as multi-folder (uid-folder ids, folder per message) when the search set says so
        if ($this->sm_all($this->folder)) {
            $folders = ['INBOX'];
            foreach (array_keys(synapse_inbox::$others) as $iid) {
                $folders[] = self::sm_folder($iid);
            }

            return [null, new rcube_result_multifolder($folders), null, null, false];
        }

        return parent::get_search_set();
    }

    public function get_threading()
    {
        return $this->sm_all($this->folder) || self::sm_parse($this->folder) ? false : parent::get_threading();
    }

    public function count($folder = null, $mode = 'ALL', $force = false, $status = true)
    {
        $folder = $this->sm_f($folder);
        if (self::sm_parse($folder)) {
            return (int) $this->sm_on($folder, static function ($k, $r) use ($mode, $force, $status) { return $k->count($r, $mode === 'THREADS' ? 'ALL' : $mode, $force, $status); }, 0);
        }
        if (!$this->sm_all($folder)) {
            return parent::count($folder, $mode, $force, $status);
        }
        $mode = $mode === 'THREADS' ? 'ALL' : $mode;
        $n = (int) $this->sm_own(function () use ($folder, $mode, $force, $status) { return parent::count($folder, $mode, $force, $status); });
        foreach (array_keys(synapse_inbox::$others) as $iid) {
            $n += (int) $this->count(self::sm_folder($iid), $mode, $force, $status);
        }

        return $n;
    }

    public function list_messages($folder = null, $page = null, $sort_field = null, $sort_order = null, $slice = 0)
    {
        $folder = $this->sm_f($folder);
        $size = $this->page_size;
        if ($v = self::sm_parse($folder)) {
            $page = $page ?: $this->list_page;

            return self::sm_tag($v[0], $this->sm_on($folder, static function ($k, $r) use ($page, $size, $sort_field, $sort_order, $slice) {
                $k->set_pagesize($size);

                return $k->list_messages($r, $page, $sort_field, $sort_order, $slice);
            }, []));
        }
        if (!$this->sm_all($folder)) {
            return parent::list_messages($folder, $page, $sort_field, $sort_order, $slice);
        }

        // the first $page * $size of every inbox, merged and cut to the page
        $page = $page ?: $this->list_page;
        $need = $page * $size;
        $threads = $this->threading;
        $this->threading = false;
        $this->set_pagesize($need);
        $all = (array) $this->sm_own(function () use ($folder, $sort_field, $sort_order) { return parent::list_messages($folder, 1, $sort_field, $sort_order); });
        $this->set_pagesize($size);
        $this->threading = $threads;
        $field = (string) ($this->sort_field ?: $sort_field);
        $desc = strtoupper((string) ($this->sort_order ?: $sort_order ?: 'DESC')) === 'DESC';
        foreach (array_keys(synapse_inbox::$others) as $iid) {
            $all = array_merge($all, self::sm_tag($iid, $this->sm_on(self::sm_folder($iid), static function ($k, $r) use ($need, $field, $desc) {
                $k->set_pagesize($need);

                return $k->list_messages($r, 1, $field, $desc ? 'DESC' : 'ASC');
            }, [])));
        }
        $key = static function ($h) use ($field) {
            switch ($field) {
                case 'subject':
                case 'from':
                case 'to':
                    return mb_strtolower((string) rcube_mime::decode_header((string) $h->{$field}, $h->charset));
                case 'size':
                    return (int) $h->size;
                case 'arrival':
                case '':
                    return (int) (rcube_utils::strtotime((string) $h->internaldate) ?: $h->timestamp);
                default:
                    return (int) ($h->timestamp ?: rcube_utils::strtotime((string) $h->date));
            }
        };
        usort($all, static function ($a, $b) use ($key, $desc) {
            $c = $key($a) <=> $key($b);

            return $desc ? -$c : $c;
        });
        $all = array_slice(array_values(array_filter($all)), ($page - 1) * $size, $size);

        return $slice ? array_slice($all, -$slice, $slice) : $all;
    }

    public function folder_status($folder = null, &$diff = [])
    {
        $folder = $this->sm_f($folder);
        if (self::sm_parse($folder)) {
            return (int) $this->sm_on($folder, static function ($k, $r) { return $k->folder_status($r); }, 0);
        }
        $status = parent::folder_status($folder, $diff);
        if ($this->sm_all($folder)) {
            foreach (array_keys(synapse_inbox::$others) as $iid) {
                $status |= $this->folder_status(self::sm_folder($iid));
            }
        }

        return $status;
    }

    /* ---------------- one message ---------------- */

    public function get_message_headers($uid, $folder = null, $force = false)
    {
        $folder = $this->sm_f($folder);
        if ($v = self::sm_parse($folder)) {
            $h = $this->sm_on($folder, static function ($k, $r) use ($uid, $force) { return $k->get_message_headers($uid, $r, $force); });

            return $h ? self::sm_tag($v[0], [$h])[0] : $h;
        }

        return parent::get_message_headers($uid, $folder, $force);
    }

    public function get_message($uid, $folder = null)
    {
        $folder = $this->sm_f($folder);
        if ($v = self::sm_parse($folder)) {
            $h = $this->sm_on($folder, static function ($k, $r) use ($uid) { return $k->get_message($uid, $r); });

            return $h ? self::sm_tag($v[0], [$h])[0] : $h;
        }

        return parent::get_message($uid, $folder);
    }

    public function get_message_part($uid, $part, $o_part = null, $print = null, $fp = null, $skip_charset_conv = false, $max_bytes = 0, $formatted = true)
    {
        if (self::sm_parse($this->folder)) {
            return $this->sm_on($this->folder, static function ($k) use ($uid, $part, $o_part, $print, $fp, $skip_charset_conv, $max_bytes, $formatted) {
                return $k->get_message_part($uid, $part, $o_part, $print, $fp, $skip_charset_conv, $max_bytes, $formatted);
            });
        }

        return parent::get_message_part($uid, $part, $o_part, $print, $fp, $skip_charset_conv, $max_bytes, $formatted);
    }

    public function get_raw_body($uid, $fp = null, $part = null)
    {
        return self::sm_parse($this->folder) ? $this->sm_on($this->folder, static function ($k) use ($uid, $fp, $part) { return $k->get_raw_body($uid, $fp, $part); })
            : parent::get_raw_body($uid, $fp, $part);
    }

    public function get_raw_headers($uid, $part = null)
    {
        return self::sm_parse($this->folder) ? $this->sm_on($this->folder, static function ($k) use ($uid, $part) { return $k->get_raw_headers($uid, $part); })
            : parent::get_raw_headers($uid, $part);
    }

    public function print_raw_body($uid, $formatted = true)
    {
        return self::sm_parse($this->folder) ? $this->sm_on($this->folder, static function ($k) use ($uid, $formatted) { return $k->print_raw_body($uid, $formatted); })
            : parent::print_raw_body($uid, $formatted);
    }

    public function index($folder = null, $sort_field = null, $sort_order = null, $no_threads = false, $no_search = false)
    {
        $folder = $this->sm_f($folder);
        if (self::sm_parse($folder)) {
            return $this->sm_on($folder, static function ($k, $r) use ($sort_field, $sort_order) { return $k->index($r, $sort_field, $sort_order, true, true); }, new rcube_result_index($folder));
        }

        return parent::index($folder, $sort_field, $sort_order, $no_threads, $no_search);
    }

    public function list_flags($folder, $uids, $mod_seq = null)
    {
        return self::sm_parse($folder) ? (array) $this->sm_on($folder, static function ($k, $r) use ($uids, $mod_seq) { return $k->list_flags($r, $uids, $mod_seq); }, [])
            : parent::list_flags($folder, $uids, $mod_seq);
    }

    /* ---------------- changes ---------------- */

    public function set_flag($uids, $flag, $folder = null, $skip_cache = false)
    {
        $folder = $this->sm_f($folder);
        if (self::sm_parse($folder)) {
            return (bool) $this->sm_on($folder, static function ($k, $r) use ($uids, $flag) { return $k->set_flag($uids, $flag, $r); });
        }
        $ok = parent::set_flag($uids, $flag, $folder, $skip_cache);
        if ($uids === '*' && $this->sm_all($folder)) {
            // "mark all as read" in All inboxes
            foreach (array_keys(synapse_inbox::$others) as $iid) {
                $this->set_flag('*', $flag, self::sm_folder($iid));
            }
        }

        return $ok;
    }

    /** Within one other inbox only; its own Trash, Junk and Archive stand in for the own ones */
    public function move_message($uids, $to_mbox, $from_mbox = '')
    {
        $from = $this->sm_f($from_mbox);
        $src = self::sm_parse($from);
        $dst = self::sm_parse($to_mbox);
        if (!$src && !$dst) {
            return parent::move_message($uids, $to_mbox, $from_mbox);
        }
        if ($src && !$dst) {
            $rcmail = rcmail::get_instance();
            foreach (['trash', 'junk', 'archive'] as $type) {
                if ($to_mbox !== '' && $to_mbox === $rcmail->config->get($type . '_mbox')) {
                    $dst = [$src[0], synapse_inbox::special_of($src[0], $this->sm_kid($src[0]), $type)];
                }
            }
        }
        if (!$src || !$dst || $src[0] !== $dst[0] || empty($dst[1])) {
            return false; // between two mail servers: not supported
        }

        return (bool) $this->sm_on($from, static function ($k, $r) use ($uids, $dst) { return $k->move_message($uids, $dst[1], $r); });
    }

    public function copy_message($uids, $to_mbox, $from_mbox = '')
    {
        $src = self::sm_parse($this->sm_f($from_mbox));
        $dst = self::sm_parse($to_mbox);
        if (!$src && !$dst) {
            return parent::copy_message($uids, $to_mbox, $from_mbox);
        }

        return $src && $dst && $src[0] === $dst[0]
            && $this->sm_on($this->sm_f($from_mbox), static function ($k, $r) use ($uids, $dst) { return $k->copy_message($uids, $dst[1], $r); });
    }

    public function delete_message($uids, $folder = null)
    {
        $folder = $this->sm_f($folder);

        return self::sm_parse($folder) ? (bool) $this->sm_on($folder, static function ($k, $r) use ($uids) { return $k->delete_message($uids, $r); })
            : parent::delete_message($uids, $folder);
    }

    public function expunge_message($uids, $folder = null, $clear_cache = true)
    {
        $folder = $this->sm_f($folder);

        return self::sm_parse($folder) ? (bool) $this->sm_on($folder, static function ($k, $r) use ($uids) { return $k->expunge_message($uids, $r); })
            : parent::expunge_message($uids, $folder, $clear_cache);
    }

    /* ---------------- folder facts ---------------- */

    public function folder_exists($folder, $subscription = false)
    {
        return self::sm_parse($folder) ? (bool) $this->sm_on($folder, static function ($k, $r) { return $k->folder_exists($r); }) : parent::folder_exists($folder, $subscription);
    }

    public function folder_info($folder)
    {
        return self::sm_parse($folder) ? (array) $this->sm_on($folder, static function ($k, $r) { return $k->folder_info($r); }, []) : parent::folder_info($folder);
    }

    public function folder_data($folder)
    {
        return self::sm_parse($folder) ? (array) $this->sm_on($folder, static function ($k, $r) { return $k->folder_data($r); }, []) : parent::folder_data($folder);
    }

    public function folder_sync($folder)
    {
        return self::sm_parse($folder) ? true : parent::folder_sync($folder);
    }

    public function my_rights($folder)
    {
        return self::sm_parse($folder) ? $this->sm_on($folder, static function ($k, $r) { return $k->my_rights($r); }) : parent::my_rights($folder);
    }
}

/** IMAP storage with All inboxes ($config['storage_driver'] = 'synapse', set by synapse_inbox) */
class rcube_synapse extends rcube_imap
{
    use synapse_router;

    protected function sm_open($iid)
    {
        return synapse_inbox::kid_imap($iid);
    }
}

// the demo build: the same with the sample mailboxes
if (class_exists('rcube_demo')) {
    class rcube_demo_all extends rcube_demo
    {
        use synapse_router;

        protected function sm_open($iid)
        {
            $kid = new rcube_demo();
            $kid->dir = 'mail-studio';
            $kid->connect(synapse_demo::DEMO_EMAIL, synapse_demo::DEMO_EMAIL, (string) rcube::get_instance()->config->get('synapse_demo_pass', 'demo'));

            return $kid;
        }
    }
}
