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
 * Other inboxes: open the mailbox of a Send As address in SynapseMail. Nothing is imported: while another inbox is
 * open, the mail screens of this session talk to that address's IMAP server (TLS on port 993 only, the same SSRF
 * guard and IP pinning as Send As, certificate checked). Settings, contacts and filters keep using the own mailbox.
 *
 * Session: synapse_inbox = identity id of the open inbox (0/none = own mailbox). The IMAP caches core keeps in the
 * session (namespace, folders, capabilities, current folder, search) are swapped per mailbox.
 */
class synapse_inbox
{
    public const PORT = 993;
    private const COUNTS_TTL = 120;
    // core's per-mailbox session caches; STORAGE_* capability entries are added at runtime
    private const SESSION_KEYS = ['mbox', 'page', 'folders', 'imap_namespace', 'imap_delimiter', 'imap_list_conf', 'imap_vendor', 'search', 'search_request',
        'search_scope', 'search_filter', 'search_interval', 'search_mods', 'list_mod_seq', 'unseen_count', 'last_text_search', 'synapse_demo_box'];
    // providers that keep their own copy of sent mail: appending one would make a second copy
    private const SAVES_SENT = '/(^|\.)(gmail\.com|office365\.com|outlook\.com)$/';

    private $plugin;
    private static $failed = null;

    public function __construct(rcube_plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function init()
    {
        $rcmail = rcmail::get_instance();
        $p = $this->plugin;
        foreach (['switch', 'list', 'save', 'remove'] as $a) {
            $p->register_action('plugin.synapse-inbox-' . $a, [$this, 'action_' . $a]);
        }
        $p->add_hook('storage_connect', [$this, 'storage_connect']);
        $p->add_hook('identity_delete', [$this, 'identity_delete']);

        $iid = self::active();
        if ($rcmail->task === 'mail' && !empty($rcmail->output) && $rcmail->output->type === 'html' && $rcmail->user && $rcmail->user->ID) {
            $rcmail->output->set_env('synapse_inbox', ['on' => self::enabled() && (bool) self::rows($rcmail->user->ID), 'active' => $iid]);
        }
        if (!$iid) {
            return;
        }
        // another inbox is open: its own special folders, no shared IMAP caches, its address for new mail
        $special = $_SESSION['synapse_inbox_special'][$iid] ?? [];
        foreach (['drafts', 'sent', 'junk', 'trash', 'archive'] as $type) {
            $rcmail->config->set($type . '_mbox', $special[$type] ?? '');
        }
        $rcmail->config->set('default_folders', array_values(array_filter(['INBOX', $special['drafts'] ?? null, $special['sent'] ?? null, $special['junk'] ?? null, $special['trash'] ?? null])));
        $rcmail->config->set('imap_cache', null);
        $rcmail->config->set('messages_cache', false);
        $rcmail->config->set('lock_special_folders', true);
        $rcmail->config->set('create_default_folders', false);
        $p->add_hook('identity_select', [$this, 'identity_select']);
    }

    /* ---------------- settings & storage ---------------- */

    public static function enabled()
    {
        return synapse_core::setting('sendas_inbox') !== '0';
    }

    private static function demo()
    {
        return rcmail::get_instance()->config->get('synapse_demo') === true;
    }

    /** Identity id of the open inbox, 0 for the own mailbox (only in the mail task) */
    public static function active()
    {
        $rcmail = rcmail::get_instance();
        $iid = (int) ($_SESSION['synapse_inbox'] ?? 0);

        return $iid && $rcmail->task === 'mail' && self::enabled() && $rcmail->user && $rcmail->user->ID ? $iid : 0;
    }

    private static function db()
    {
        static $ready = false;
        $db = rcube::get_instance()->get_dbh();
        if (!$ready) {
            $db->query('CREATE TABLE IF NOT EXISTS ' . $db->table_name('synapse_inbox', true) . ' ('
                . '`identity_id` integer NOT NULL PRIMARY KEY, `user_id` integer NOT NULL REFERENCES `users` (`user_id`) ON DELETE CASCADE,'
                . ' `host` varchar(255) NOT NULL DEFAULT \'\', `username` varchar(255) NOT NULL DEFAULT \'\', `password` text NOT NULL DEFAULT \'\','
                . ' `created_at` integer NOT NULL DEFAULT 0, `last_ok_at` integer NOT NULL DEFAULT 0)');
            $ready = true;
        }

        return $db;
    }

    /** @return array inboxes of a user keyed by identity id, with the address (deleted identities left out) */
    public static function rows($user_id)
    {
        $db = self::db();
        $res = $db->query('SELECT x.*, i.`email`, i.`name` FROM ' . $db->table_name('synapse_inbox', true) . ' x JOIN ' . $db->table_name('identities', true)
            . ' i ON i.`identity_id` = x.`identity_id` WHERE x.`user_id` = ? AND i.`del` <> 1 ORDER BY i.`email`', $user_id);
        $rows = [];
        while ($res && ($r = $db->fetch_assoc($res))) {
            $rows[(int) $r['identity_id']] = $r;
        }

        return $rows;
    }

    public static function put(array $r)
    {
        $db = self::db();
        $table = $db->table_name('synapse_inbox', true);
        $db->query("DELETE FROM {$table} WHERE `identity_id` = ?", $r['identity_id']);
        $db->query("INSERT INTO {$table} (`identity_id`, `user_id`, `host`, `username`, `password`, `created_at`, `last_ok_at`) VALUES (?, ?, ?, ?, ?, ?, ?)",
            $r['identity_id'], $r['user_id'], $r['host'], $r['username'], $r['password'], (int) ($r['created_at'] ?? time()), (int) ($r['last_ok_at'] ?? 0));
    }

    public static function remove($user_id, $iid)
    {
        $db = self::db();
        $db->query('DELETE FROM ' . $db->table_name('synapse_inbox', true) . ' WHERE `user_id` = ? AND `identity_id` = ?', $user_id, $iid);
    }

    public static function counts()
    {
        $db = self::db();
        $res = $db->query('SELECT `user_id`, COUNT(*) AS n FROM ' . $db->table_name('synapse_inbox', true) . ' GROUP BY `user_id`');
        $out = [];
        while ($res && ($r = $db->fetch_assoc($res))) {
            $out[(int) $r['user_id']] = (int) $r['n'];
        }

        return $out;
    }

    private static function key()
    {
        return rcmail::get_instance()->config->get_crypto_key('des_key');
    }

    /** Password of an inbox: its own, or (empty) the one the address sends with */
    private static function password(array $row)
    {
        if ($row['password'] !== '') {
            return synapse_secret::decrypt($row['password'], self::key());
        }
        $sa = synapse_sendas::row((int) $row['user_id'], (int) $row['identity_id']);

        return $sa && $sa['mode'] === 'smtp' ? synapse_secret::decrypt($sa['password'], self::key()) : null;
    }

    /* ---------------- IMAP connection ---------------- */

    /** Connection options for one server, pinned to the checked IP; error key when the host is not allowed */
    public static function conn_options($host)
    {
        $ips = synapse_sendas::resolve($host);
        if (is_string($ips)) {
            return $ips;
        }
        $ip = $ips[0];

        return ['host' => strpos($ip, ':') !== false ? "[{$ip}]" : $ip, 'port' => self::PORT, 'ssl_mode' => 'ssl', 'timeout' => 10,
            'auth_type' => null, 'auth_cid' => null, 'auth_pw' => null,
            'socket_options' => ['ssl' => ['peer_name' => rcube_utils::idn_to_ascii($host), 'verify_peer' => true, 'verify_peer_name' => true,
                'allow_self_signed' => false, 'SNI_enabled' => true]]];
    }

    /** Sign in once and read the unread count of INBOX: ['ok' => true, 'unseen' => n] or ['ok' => false, 'error' => key] */
    public static function test($host, $user, $password)
    {
        $o = self::conn_options($host);
        if (is_string($o)) {
            return ['ok' => false, 'error' => $o];
        }
        $c = new rcube_imap_generic();
        $ok = $c->connect($o['host'], $user, $password, $o);
        $status = $ok ? $c->status('INBOX', ['UNSEEN']) : null;
        $error = $c->error;
        $c->closeConnection();
        if ($ok && is_array($status)) {
            return ['ok' => true, 'unseen' => (int) ($status['UNSEEN'] ?? 0)];
        }

        return ['ok' => false] + self::why($error, $o['host'], $host);
    }

    /** Error key (and certificate names) for a failed sign-in */
    public static function why($error, $addr, $host)
    {
        $error = (string) $error;
        if (preg_match('/application-specific password|app password|\[ALERT\].*web login|WEBALERT/i', $error)) {
            return ['error' => 'apppass'];
        }
        if (preg_match('/BasicAuthBlocked|basic auth|AUTHENTICATE failed\.?$/i', $error) && preg_match('/office365|outlook/i', $host)) {
            return ['error' => 'msauth'];
        }
        if (preg_match('/AUTHENTICATIONFAILED|LOGIN failed|Invalid (credentials|login)|authentication failed|incorrect|AUTHENTICATE|LOGIN/i', $error)) {
            return ['error' => 'auth'];
        }
        $st = synapse_sendas::probe(trim($addr, '[]'), $host, self::PORT, 'ssl', true);
        if (in_array($st['state'], ['unreachable', 'tlsfail', 'certname', 'certexpired', 'certbad', 'redirected'], true)) {
            return ['error' => $st['state'], 'names' => $st['names'] ?? []];
        }

        return ['error' => 'failed'];
    }

    /** Core's storage_connect hook: while another inbox is open, sign in there instead of the own mailbox */
    public function storage_connect($args)
    {
        $iid = self::active();
        if (!$iid || self::demo()) {
            return $args;
        }
        $rcmail = rcmail::get_instance();
        if ($args['attempt'] > 1) {
            // the other inbox failed: back to the own mailbox from the next request on, and say why
            self::$failed = $iid;
            self::swap(0);
            $args['retry'] = false;
            if (!empty($rcmail->output)) {
                $msg = $this->plugin->gettext('ib_lost');
                $rcmail->output->type === 'html' ? $rcmail->output->set_env('synapse_inbox_failed', $msg) : $rcmail->output->command('plugin.synapse_inbox_failed', ['message' => $msg]);
            }

            return $args;
        }
        $row = self::rows($rcmail->user->ID)[$iid] ?? null;
        $password = $row ? self::password($row) : null;
        $o = $row && $password ? self::conn_options($row['host']) : 'failed';
        if (is_string($o)) {
            $o = ['host' => 'invalid.invalid', 'port' => self::PORT, 'ssl_mode' => 'ssl', 'socket_options' => [], 'timeout' => 1];
        }
        $args = array_merge($args, $o);
        $args['user'] = $row['username'] ?? 'invalid';
        $args['pass'] = (string) $password;
        $args['retry'] = true; // attempt 2 only runs when this sign-in fails

        return $args;
    }

    /** Swap core's per-mailbox session caches and open another mailbox (0 = own) */
    private static function swap($iid)
    {
        $from = (int) ($_SESSION['synapse_inbox'] ?? 0);
        $keys = self::SESSION_KEYS;
        foreach (array_keys($_SESSION) as $k) {
            if (strpos($k, 'STORAGE_') === 0) {
                $keys[] = $k;
            }
        }
        $bags = $_SESSION['synapse_inbox_bags'] ?? [];
        $bags[$from] = [];
        foreach ($keys as $k) {
            if (isset($_SESSION[$k])) {
                $bags[$from][$k] = $_SESSION[$k];
            }
            $_SESSION[$k] = null; // core merges session data on write: clear with null
        }
        foreach ($bags[$iid] ?? [] as $k => $v) {
            $_SESSION[$k] = $v;
        }
        unset($bags[$iid]);
        $_SESSION['synapse_inbox_bags'] = $bags;
        $_SESSION['synapse_inbox'] = $iid ?: null;
        if (!$iid || !isset($_SESSION['mbox'])) {
            $_SESSION['mbox'] = 'INBOX';
        }
    }

    /** Special folders of the open inbox: SPECIAL-USE flags, else common names */
    private static function special_folders($storage, $host)
    {
        $found = [];
        $rcmail = rcmail::get_instance();
        foreach (['drafts', 'sent', 'junk', 'trash', 'archive'] as $type) {
            $rcmail->config->set($type . '_mbox', null);
        }
        $rcmail->config->set('lock_special_folders', false);
        foreach ($storage->get_special_folders(true) as $type => $folder) {
            $found[$type] = $folder;
        }
        $names = ['drafts' => '/^(inbox[.\/])?drafts?$/i', 'sent' => '/^(inbox[.\/])?sent( items| messages| mail)?$/i', 'junk' => '/^(inbox[.\/])?(junk|spam)( e-?mail)?$/i',
            'trash' => '/^(inbox[.\/])?(trash|deleted( items| messages)?|bin)$/i', 'archive' => '/^(inbox[.\/])?archives?$/i'];
        foreach ((array) $storage->list_folders_direct() as $folder) {
            foreach ($names as $type => $re) {
                if (empty($found[$type]) && preg_match($re, $folder)) {
                    $found[$type] = $folder;
                }
            }
        }
        // the provider already keeps sent mail (it would be there twice)
        if (preg_match(self::SAVES_SENT, strtolower($host))) {
            unset($found['sent']);
        }

        return $found;
    }

    /* ---------------- hooks ---------------- */

    /** New mail and replies in another inbox come from its address */
    public function identity_select($args)
    {
        $iid = self::active();
        if ($iid && empty($args['message'])) {
            foreach ($args['identities'] as $i => $ident) {
                if ((int) $ident['identity_id'] === $iid) {
                    $args['selected'] = $i;
                }
            }
        }

        return $args;
    }

    public function identity_delete($args)
    {
        $rcmail = rcmail::get_instance();
        self::remove($rcmail->user->ID, (int) $args['id']);
        if ((int) ($_SESSION['synapse_inbox'] ?? 0) === (int) $args['id']) {
            self::swap(0);
        }

        return $args;
    }

    /* ---------------- actions ---------------- */

    private function fail($key, array $extra = [])
    {
        $this->plugin->json(['ok' => false, 'error' => $key, 'message' => $this->plugin->gettext(['name' => 'ib_err_' . $key, 'vars' => ['seconds' => $extra['wait'] ?? 0]])] + $extra);
    }

    private function input($name)
    {
        return trim((string) rcube_utils::get_input_string('_' . $name, rcube_utils::INPUT_POST));
    }

    /** Mailboxes for the switcher, with unread counts (cached a short time; one connection per inbox) */
    public function action_list()
    {
        $rcmail = rcmail::get_instance();
        $uid = (int) $rcmail->user->ID;
        $active = (int) ($_SESSION['synapse_inbox'] ?? 0);
        $rows = self::enabled() ? self::rows($uid) : [];
        $cache = $_SESSION['synapse_inbox_counts'] ?? [];
        $fresh = !empty($_POST['_fresh']) || ($cache['time'] ?? 0) < time() - self::COUNTS_TTL || array_keys($cache['n'] ?? []) != array_merge([0], array_keys($rows));
        if ($fresh) {
            $n = [0 => null];
            foreach ($rows as $iid => $r) {
                $n[$iid] = null;
            }
            if (self::demo()) {
                foreach (array_keys($n) as $iid) {
                    $n[$iid] = rcube_demo::unseen_of($iid);
                }
            } else {
                @set_time_limit(60);
                foreach ($rows as $iid => $r) {
                    if ($iid !== $active && ($pw = self::password($r))) {
                        $t = self::test($r['host'], $r['username'], $pw);
                        $n[$iid] = $t['ok'] ? $t['unseen'] : null;
                    }
                }
                if ($active) {
                    $n[0] = self::own_unseen();
                }
            }
            $cache = ['time' => time(), 'n' => $n];
            $_SESSION['synapse_inbox_counts'] = $cache;
        }
        $list = [['iid' => 0, 'email' => self::own_email(), 'name' => (string) ($rcmail->user->get_identity()['name'] ?? ''), 'unseen' => $cache['n'][0] ?? null]];
        foreach ($rows as $iid => $r) {
            $list[] = ['iid' => $iid, 'email' => rcube_utils::idn_to_utf8($r['email']), 'name' => $r['name'], 'unseen' => $cache['n'][$iid] ?? null];
        }
        $this->plugin->json(['ok' => true, 'active' => $active, 'inboxes' => $list]);
    }

    private static function own_email()
    {
        $rcmail = rcmail::get_instance();
        $email = (string) ($_SESSION['username'] ?? '');

        return rcube_utils::idn_to_utf8(strpos($email, '@') ? $email : (string) $rcmail->user->get_username('mail'));
    }

    /** Unread count of the own INBOX while another inbox is open (the session's own server settings) */
    private static function own_unseen()
    {
        $rcmail = rcmail::get_instance();
        $c = new rcube_imap_generic();
        $opts = ['port' => $_SESSION['storage_port'] ?? 143, 'ssl_mode' => $_SESSION['storage_ssl'] ?? null, 'timeout' => 6,
            'auth_type' => $rcmail->config->get('imap_auth_type'), 'socket_options' => $rcmail->config->get('imap_conn_options')];
        rcube_utils::parse_socket_options($opts['socket_options'], $_SESSION['storage_host']);
        $ok = $c->connect($_SESSION['storage_host'], $_SESSION['username'], $rcmail->decrypt($_SESSION['password']), $opts);
        $st = $ok ? $c->status('INBOX', ['UNSEEN']) : null;
        $c->closeConnection();

        return is_array($st) ? (int) ($st['UNSEEN'] ?? 0) : null;
    }

    /** Open another inbox (or the own one with _iid=0); the browser reloads the mail screen */
    public function action_switch()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $iid = (int) $this->input('iid');
        $rows = self::rows($rcmail->user->ID);
        if ($iid && (!self::enabled() || !isset($rows[$iid]))) {
            $this->fail('notfound');
        }
        $prev = (int) ($_SESSION['synapse_inbox'] ?? 0);
        if ($iid === $prev) {
            $this->plugin->json(['ok' => true]);
        }
        self::swap($iid);
        if ($iid) {
            // sign in now, so a wrong password is reported here and not on the mail screen
            if (!self::demo()) {
                $pw = self::password($rows[$iid]);
                $t = $pw ? self::test($rows[$iid]['host'], $rows[$iid]['username'], $pw) : ['ok' => false, 'error' => 'key'];
                if (!$t['ok']) {
                    self::swap($prev);
                    $this->fail($t['error']);
                }
                $storage = $rcmail->get_storage();
                if ($storage->is_connected()) {
                    $storage->close();
                }
                if (!$rcmail->storage_connect()) {
                    self::swap($prev);
                    $this->fail('failed');
                }
                $_SESSION['synapse_inbox_special'][$iid] = self::special_folders($storage, $rows[$iid]['host']);
                self::put(['last_ok_at' => time()] + $rows[$iid]);
            } else {
                $_SESSION['synapse_inbox_special'][$iid] = ['drafts' => 'Drafts', 'sent' => 'Sent', 'trash' => 'Trash', 'junk' => 'Junk'];
            }
        }
        $_SESSION['synapse_inbox_counts'] = null;
        synapse_core::security_log('user ' . $rcmail->get_user_name() . ' opened ' . ($iid ? 'the inbox of ' . $rows[$iid]['email'] : 'the own inbox'));
        $this->plugin->json(['ok' => true]);
    }

    /** Turn on (test + save) or change the inbox of a Send As address */
    public function action_save()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $uid = (int) $rcmail->user->ID;
        $iid = (int) $this->input('iid');
        if (!self::enabled()) {
            $this->fail('off');
        }
        $sa = synapse_sendas::row($uid, $iid);
        $ident = $rcmail->user->get_identity($iid);
        if (!$sa || !$ident || empty($sa['verified_at'])) {
            $this->fail('notfound');
        }
        $host = strtolower(rcube_utils::idn_to_ascii($this->input('host')));
        $user = $this->input('user');
        $same = !empty($_POST['_same']) && $sa['mode'] === 'smtp';
        $password = $same ? synapse_secret::decrypt($sa['password'], self::key()) : synapse_sendas::clean_password(rcube_utils::get_input_string('_pass', rcube_utils::INPUT_POST, true));
        $old = self::rows($uid)[$iid] ?? null;
        if (!$same && $password === '' && $old && $old['password'] !== '') {
            $password = synapse_secret::decrypt($old['password'], self::key()); // unchanged: keep the saved one
        }
        if (!synapse_sendas::valid_host($host) || $user === '' || strlen($user) > 255 || (string) $password === '') {
            $this->fail('fields');
        }
        if ($wait = synapse_core::rl_blocked(['sainbox' => $uid])) {
            $this->fail('slow', ['wait' => $wait]);
        }
        $t = self::demo() ? ['ok' => true, 'unseen' => rcube_demo::unseen_of($iid)] : self::test($host, $user, $password);
        if (!$t['ok']) {
            synapse_core::rl_fail(['sainbox' => $uid]);
            $this->fail($t['error'], ['names' => $t['names'] ?? []]);
        }
        self::put(['identity_id' => $iid, 'user_id' => $uid, 'host' => $host, 'username' => $user,
            'password' => $same ? '' : synapse_secret::encrypt($password, self::key()), 'created_at' => $old['created_at'] ?? time(), 'last_ok_at' => time()]);
        $_SESSION['synapse_inbox_counts'] = null;
        synapse_core::security_log('user ' . $rcmail->get_user_name() . ' turned on the inbox of ' . $ident['email'] . " ({$host})");
        $this->plugin->json(['ok' => true, 'unseen' => $t['unseen'], 'inbox' => ['host' => $host, 'user' => $user, 'same' => $same]]);
    }

    public function action_remove()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $iid = (int) $this->input('iid');
        self::remove($rcmail->user->ID, $iid);
        if ((int) ($_SESSION['synapse_inbox'] ?? 0) === $iid) {
            self::swap(0);
        }
        $_SESSION['synapse_inbox_counts'] = null;
        synapse_core::security_log('user ' . $rcmail->get_user_name() . " turned off the inbox of identity {$iid}");
        $this->plugin->json(['ok' => true]);
    }

    /** Data for the Send As page: inbox state of every address and the suggested IMAP host */
    public static function page_env($uid, array $sendas_rows, array $emails)
    {
        require_once __DIR__ . '/synapse_mailconfig.php';
        $rows = self::rows($uid);
        $out = [];
        foreach ($sendas_rows as $iid => $sa) {
            if (empty($sa['verified_at']) || !isset($emails[$iid])) {
                continue;
            }
            $r = $rows[$iid] ?? null;
            $out[$iid] = $r ? ['on' => true, 'host' => $r['host'], 'user' => $r['username'], 'same' => $r['password'] === '', 'last_ok_at' => (int) $r['last_ok_at']]
                : ['on' => false, 'user' => $sa['username'] ?: null] + synapse_mailconfig::imap_host($emails[$iid] ?? '', $sa['mode'] === 'smtp' ? $sa['host'] : '');
        }

        return $out;
    }
}
