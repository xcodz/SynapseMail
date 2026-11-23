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
 * Send As: extra sending addresses, each with its own SMTP server (like Gmail's "Send mail as").
 *
 * An address is a Roundcube identity (name, signature, default flag) plus a row in synapse_sendas
 * (server, port, TLS, user name, encrypted password). New addresses are only added with the wizard:
 * settings lookup, server test, then a 6-digit code mailed to the address through its own server.
 * Identities from before 3.3 (no row) keep sending through the normal server.
 */
class synapse_sendas
{
    public const PORTS = [465, 587, 25, 2525];
    public const SECURITY = ['ssl', 'starttls'];
    public const MAX_DEFAULT = 20;
    public const CODE_TTL = 900;
    public const CODE_TRIES = 5;
    public const RESEND_WAIT = 60;
    public const DEMO_CODE = '123456';

    /** @var rcube_plugin */
    private $plugin;

    /** SMTP settings for the connection of this request (null = the normal server) */
    private static $route = null;
    /** identity id of the Send As address that this request sends from */
    private static $sending = null;
    /** true while the wizard sends its own code mail (the sending hooks stay out of the way) */
    private static $wizard = false;

    public function __construct(rcube_plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function init()
    {
        $p = $this->plugin;
        $rcmail = rcmail::get_instance();

        // sending hooks always run: addresses that already exist keep their server even when the admin turns adding off
        $p->add_hook('smtp_connect', [$this, 'smtp_connect']);
        $p->add_hook('message_before_send', [$this, 'before_send']);
        $p->add_hook('message_sent', [$this, 'sent']);
        $p->add_hook('message_send_error', [$this, 'send_error']);
        $p->add_hook('identity_select', [$this, 'identity_select']);
        $p->add_hook('identity_form', [$this, 'identity_form']);
        $p->add_hook('identity_create', [$this, 'identity_create']);
        $p->add_hook('identity_update', [$this, 'identity_update']);
        $p->add_hook('identity_delete', [$this, 'identity_delete']);

        foreach (['detect', 'test', 'resend', 'verify', 'finish', 'reply'] as $a) {
            $p->register_action('plugin.synapse-sendas-' . $a, [$this, 'action_' . $a]);
        }

        if ($rcmail->task === 'settings' && $rcmail->action === 'identities' && !empty($rcmail->output) && $rcmail->user->ID) {
            $rows = self::rows($rcmail->user->ID);
            $idents = [];
            foreach ($rcmail->user->list_identities() as $i) {
                $r = $rows[(int) $i['identity_id']] ?? null;
                $idents[(int) $i['identity_id']] = ['email' => rcube_utils::idn_to_utf8($i['email']), 'name' => $i['name'], 'default' => !empty($i['standard']),
                    'sa' => $r ? ['mode' => $r['mode'], 'host' => $r['host'], 'port' => (int) $r['port'], 'security' => $r['security'], 'username' => $r['username'],
                        'first' => $r['first_name'], 'last' => $r['last_name'], 'verified_at' => (int) $r['verified_at'], 'last_ok_at' => (int) $r['last_ok_at']] : null];
            }
            // the page is called "Send As" now
            $p->add_hook('send_page', static function ($args) use ($rcmail, $p) {
                $args['content'] = preg_replace('#(<title>[^<]*?)' . preg_quote(rcube::Q($rcmail->gettext('identities')), '#') . '#', '${1}' . rcube::Q($p->gettext('sendas')), $args['content'], 1);

                return $args;
            });
            require_once __DIR__ . '/synapse_inbox.php';
            $inbox = synapse_inbox::page_env($rcmail->user->ID, $rows, array_map(static function ($i) { return $i['email']; }, $idents));
            $rcmail->output->set_env('synapse_sendas', ['on' => self::enabled(), 'idents' => $idents, 'max' => self::max(),
                'inbox' => $inbox, 'inbox_on' => synapse_inbox::enabled(),
                'reply' => $rcmail->config->get('synapse_reply_from') === 'default' ? 'default' : 'same',
                'login' => self::login_email(), 'demo' => self::demo(), 'ports' => self::PORTS]);
        }
    }

    /* ---------------- settings ---------------- */

    public static function enabled()
    {
        return synapse_core::setting('sendas') !== '0';
    }

    public static function max()
    {
        $n = (int) synapse_core::setting('sendas_max');

        return $n > 0 ? min($n, 99) : self::MAX_DEFAULT;
    }

    /** Domains that may use "send through this mail server": the login domain plus the admin's list */
    public static function server_domains()
    {
        $list = preg_split('/[\s,]+/', strtolower((string) synapse_core::setting('sendas_domains')), -1, PREG_SPLIT_NO_EMPTY);
        $login = self::login_email();
        if ($login && strpos($login, '@')) {
            $list[] = substr($login, strrpos($login, '@') + 1);
        }

        return array_values(array_unique($list));
    }

    public static function server_ok($email)
    {
        return in_array(substr(strtolower($email), strrpos($email, '@') + 1), self::server_domains(), true);
    }

    /** The address the user signs in with (not the default identity, which the user can change) */
    private static function login_email()
    {
        $rcmail = rcmail::get_instance();
        $email = (string) ($_SESSION['username'] ?? '');
        if (strpos($email, '@') === false && $rcmail->user && $rcmail->user->ID) {
            $email = (string) $rcmail->user->get_username('mail');
        }

        return strtolower(rcube_utils::idn_to_utf8($email));
    }

    private static function demo()
    {
        return rcmail::get_instance()->config->get('synapse_demo') === true;
    }

    private static function key()
    {
        return rcmail::get_instance()->config->get_crypto_key('des_key');
    }

    /* ---------------- storage ---------------- */

    private static function db()
    {
        static $ready = false;
        $db = rcube::get_instance()->get_dbh();
        if (!$ready) {
            $db->query('CREATE TABLE IF NOT EXISTS ' . $db->table_name('synapse_sendas', true) . ' ('
                . '`identity_id` integer NOT NULL PRIMARY KEY, `user_id` integer NOT NULL REFERENCES `users` (`user_id`) ON DELETE CASCADE,'
                . ' `first_name` varchar(64) NOT NULL DEFAULT \'\', `last_name` varchar(64) NOT NULL DEFAULT \'\','
                . ' `mode` varchar(8) NOT NULL DEFAULT \'smtp\', `host` varchar(255) NOT NULL DEFAULT \'\', `port` integer NOT NULL DEFAULT 0,'
                . ' `security` varchar(8) NOT NULL DEFAULT \'\', `username` varchar(255) NOT NULL DEFAULT \'\', `password` text NOT NULL DEFAULT \'\','
                . ' `verified_at` integer NOT NULL DEFAULT 0, `last_ok_at` integer NOT NULL DEFAULT 0, `created_at` integer NOT NULL DEFAULT 0)');
            $ready = true;
        }

        return $db;
    }

    /** @return array rows of one user, keyed by identity id (deleted identities left out) */
    public static function rows($user_id)
    {
        $db = self::db();
        $res = $db->query('SELECT s.* FROM ' . $db->table_name('synapse_sendas', true) . ' s JOIN ' . $db->table_name('identities', true)
            . ' i ON i.`identity_id` = s.`identity_id` WHERE s.`user_id` = ? AND i.`del` <> 1', $user_id);
        $rows = [];
        while ($res && ($r = $db->fetch_assoc($res))) {
            $rows[(int) $r['identity_id']] = $r;
        }

        return $rows;
    }

    public static function row($user_id, $iid)
    {
        return self::rows($user_id)[(int) $iid] ?? null;
    }

    public static function put(array $r)
    {
        $db = self::db();
        $table = $db->table_name('synapse_sendas', true);
        $db->query("DELETE FROM {$table} WHERE `identity_id` = ?", $r['identity_id']);
        $db->query("INSERT INTO {$table} (`identity_id`, `user_id`, `first_name`, `last_name`, `mode`, `host`, `port`, `security`, `username`,"
            . ' `password`, `verified_at`, `last_ok_at`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $r['identity_id'], $r['user_id'], $r['first_name'], $r['last_name'], $r['mode'], $r['host'], (int) $r['port'], $r['security'],
            $r['username'], $r['password'], (int) $r['verified_at'], (int) ($r['last_ok_at'] ?? 0), (int) ($r['created_at'] ?? time()));
    }

    public static function remove($user_id, $iid)
    {
        $db = self::db();
        $db->query('DELETE FROM ' . $db->table_name('synapse_sendas', true) . ' WHERE `user_id` = ? AND `identity_id` = ?', $user_id, $iid);
    }

    /** Number of Send As addresses per user id (admin panel) */
    public static function counts()
    {
        $db = self::db();
        $res = $db->query('SELECT `user_id`, COUNT(*) AS n FROM ' . $db->table_name('synapse_sendas', true) . ' GROUP BY `user_id`');
        $out = [];
        while ($res && ($r = $db->fetch_assoc($res))) {
            $out[(int) $r['user_id']] = (int) $r['n'];
        }

        return $out;
    }

    /* ---------------- network guard (SSRF) ---------------- */

    public static function valid_host($host)
    {
        $host = rcube_utils::idn_to_ascii(strtolower(trim((string) $host)));

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host) && !preg_match('/\.(local|localhost|internal|lan|home|arpa)$/', $host);
    }

    /**
     * Every address of the host must be public (no private, loopback, link-local, CGNAT, metadata, multicast or
     * reserved range, also not hidden inside IPv6), unless the admin allows the local network.
     *
     * @return array|string list of IPs, or an error key
     */
    public static function resolve($host, $allow_local = null)
    {
        $allow_local = $allow_local ?? synapse_core::setting('sendas_local') === '1';
        $host = rcube_utils::idn_to_ascii(strtolower(trim((string) $host)));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            if (!self::valid_host($host)) {
                return 'host';
            }
            $ips = [];
            foreach ([DNS_A, DNS_AAAA] as $type) {
                foreach ((array) @dns_get_record($host, $type) as $rec) {
                    $ips[] = $rec['ip'] ?? $rec['ipv6'] ?? null;
                }
            }
            $ips = array_values(array_unique(array_filter($ips)));
            if (!$ips && ($v4 = @gethostbynamel($host))) {
                $ips = $v4;
            }
        }
        if (!$ips) {
            return 'nohost';
        }
        foreach ($ips as $ip) {
            if (!$allow_local && !self::is_public($ip)) {
                return 'blocked';
            }
        }
        usort($ips, static function ($a, $b) { return (strpos($a, ':') !== false) <=> (strpos($b, ':') !== false); }); // IPv4 first

        return $ips;
    }

    public static function is_public($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $bin = inet_pton($ip);
        if (strlen($bin) === 16) {
            // IPv4 inside IPv6 (mapped, NAT64, 6to4): the IPv4 part decides
            if (strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0 || strncmp($bin, "\0\x64\xff\x9b" . str_repeat("\0", 8), 12) === 0) {
                return self::is_public(inet_ntop(substr($bin, 12)));
            }
            if (strncmp($bin, "\x20\x02", 2) === 0) {
                return self::is_public(inet_ntop(substr($bin, 2, 4)));
            }
            if ($bin[0] === "\xff" || strncmp($bin, str_repeat("\0", 12), 12) === 0) {
                return false; // multicast, IPv4-compatible and unspecified
            }
        } else {
            foreach (['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/3'] as $cidr) {
                if (self::in_cidr($bin, $cidr)) {
                    return false;
                }
            }
        }

        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function in_cidr($bin, $cidr)
    {
        [$net, $bits] = explode('/', $cidr);
        $net = inet_pton($net);
        $bytes = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;
        if (strncmp($bin, $net, $bytes) !== 0) {
            return false;
        }

        return $rest === 0 || ((ord($bin[$bytes]) ^ ord($net[$bytes])) & (0xff << (8 - $rest)) & 0xff) === 0;
    }

    /* ---------------- SMTP route ---------------- */

    /** smtp_connect settings for one server, pinned to the checked IP (no second DNS answer can redirect it) */
    public static function route_config(array $r, $password, $ip)
    {
        require_once __DIR__ . '/synapse_smtp_tls.php';
        $addr = strpos($ip, ':') !== false ? "[{$ip}]" : $ip;

        return [
            'smtp_host' => ($r['security'] === 'ssl' ? 'ssl://' : 'tls://') . $addr . ':' . (int) $r['port'],
            'smtp_user' => $r['username'],
            'smtp_pass' => $password,
            'smtp_auth_cid' => null,
            'smtp_auth_pw' => null,
            'smtp_auth_type' => synapse_smtp_tls::METHOD,
            'smtp_auth_callbacks' => [['name' => synapse_smtp_tls::METHOD, 'function' => [new synapse_smtp_tls(), 'auth_tls'], 'prepend' => false]],
            'smtp_timeout' => 10,
            'smtp_conn_options' => ['ssl' => ['peer_name' => rcube_utils::idn_to_ascii($r['host']), 'verify_peer' => true, 'verify_peer_name' => true,
                'allow_self_signed' => false, 'SNI_enabled' => true]],
            'gssapi_context' => null,
            'gssapi_cn' => null,
        ];
    }

    /** @return array|string the route, or an error key */
    private static function route_for(array $r, $password = null)
    {
        $password = $password ?? synapse_secret::decrypt($r['password'], self::key());
        if ($password === null || $password === '') {
            return 'key';
        }
        $ips = self::resolve($r['host']);
        if (is_string($ips)) {
            return $ips;
        }

        return self::route_config($r, $password, $ips[0]);
    }

    private static function reset_smtp()
    {
        $rcmail = rcmail::get_instance();
        if (is_object($rcmail->smtp)) {
            $rcmail->smtp->disconnect();
        }
        $rcmail->smtp = null;
    }

    public function smtp_connect($args)
    {
        return self::$route ? array_merge($args, self::$route) : $args;
    }

    public function before_send($args)
    {
        if (self::$wizard || !empty($args['abort'])) {
            return $args;
        }
        $rcmail = rcmail::get_instance();
        $from = strtolower((string) $args['from']);
        $rows = self::rows($rcmail->user->ID);
        // the compose form names the identity; read receipts and redirects only give the address
        $iid = (int) rcube_utils::get_input_string('_from', rcube_utils::INPUT_POST);
        $pick = null;
        foreach ($rows as $id => $r) {
            $ident = $rcmail->user->get_identity($id);
            if ($ident && strtolower($ident['email']) === $from && (!$pick || $id === $iid)) {
                $pick = $r;
            }
        }
        if (self::$route || self::$sending) {
            self::reset_smtp(); // an earlier send in this request used another server
        }
        self::$route = null;
        self::$sending = null;
        if (!$pick || $pick['mode'] !== 'smtp') {
            return $args;
        }
        $route = self::route_for($pick);
        if (is_string($route)) {
            $args['abort'] = true;
            $args['result'] = false;
            $args['error'] = ['label' => 'synapse_core.sa_senderr', 'vars' => ['email' => $from, 'reason' => $this->plugin->gettext('sa_err_' . $route)]];

            return $args;
        }
        self::$route = $route;
        self::$sending = (int) $pick['identity_id'];
        self::reset_smtp();

        return $args;
    }

    public function sent($args)
    {
        if (self::$sending && !self::$wizard) {
            $db = self::db();
            $db->query('UPDATE ' . $db->table_name('synapse_sendas', true) . ' SET `last_ok_at` = ? WHERE `identity_id` = ?', time(), self::$sending);
        }

        return $args;
    }

    /** A failed send through a Send As server: say what went wrong in plain words */
    public function send_error($args)
    {
        if (self::$sending && !self::$wizard) {
            $rcmail = rcmail::get_instance();
            $key = self::error_key($args['error'] ?? [], is_object($rcmail->smtp) ? $rcmail->smtp->get_response() : []);
            if ($key === 'connect') {
                $key = 'unreachable';
            }
            $args['error'] = ['label' => 'synapse_core.sa_senderr', 'vars' => ['email' => strtolower((string) $args['from']), 'reason' => $this->plugin->gettext('sa_err_' . $key)]];
            synapse_core::security_log("sendas: sending failed ({$key}) for " . $rcmail->get_user_name());
        }

        return $args;
    }

    /** SMTP reply → a key for a friendly message (never the raw server text) */
    public static function error_key($error, $response = [])
    {
        $error = is_array($error) ? $error : ['vars' => ['msg' => (string) $error]];
        $text = implode(' ', (array) $response) . ' ' . ($error['vars']['msg'] ?? '') . ' ' . ($error['vars']['code'] ?? '');
        $map = [
            'notls' => '/synapse-notls/',
            'apppass' => '/5\.7\.9\b|application-specific password|app password/i',
            'msauth' => '/5\.7\.139|5\.7\.30|basic auth|SmtpClientAuthentication|security defaults/i',
            'sendas' => '/5\.7\.60|not (allowed|authori[sz]ed|permitted) to send|send ?as permission/i',
            'noauth' => '/does not support authentication|AUTH (command )?not (supported|available)/i',
            'auth' => '/\b535\b|5\.7\.8\b|5\.7\.3\b|authentication (failed|unsuccessful|credentials invalid)|invalid (credentials|login)|username and password/i',
        ];
        foreach ($map as $key => $re) {
            if (preg_match($re, $text)) {
                return $key;
            }
        }
        $label = $error['label'] ?? '';
        if ($label === 'smtpautherror') {
            return 'auth';
        }
        if ($label === 'smtpconnerror') {
            return 'connect';
        }
        if ($label === 'smtpfromerror') {
            return 'sendas';
        }

        return 'failed';
    }

    /**
     * Why did a connection fail? Connects again without checking the certificate, only to read it:
     * unreachable, no STARTTLS, TLS broken, certificate for another name (with the names it is for),
     * expired or not trusted.
     */
    public static function probe($ip, $host, $port, $security, $imap = false)
    {
        $addr = strpos($ip, ':') !== false ? "[{$ip}]" : $ip;
        $banner = '';
        $open = static function ($verify) use ($addr, $host, $port, $security, $imap, &$fp, &$banner) {
            $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'capture_peer_cert' => true,
                'SNI_enabled' => true, 'peer_name' => $host, 'allow_self_signed' => false]]);
            $fp = @stream_socket_client("tcp://{$addr}:{$port}", $no, $str, 6, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) {
                return 'unreachable';
            }
            stream_set_timeout($fp, 6);
            if ($security === 'starttls') {
                if (strpos($banner = self::smtp_read($fp), '220') !== 0) {
                    return 'unreachable';
                }
                fwrite($fp, 'EHLO ' . (rcube_utils::server_name() ?: 'localhost') . "\r\n");
                if (!preg_match('/^250[ -]STARTTLS/mi', self::smtp_read($fp))) {
                    return 'notls';
                }
                fwrite($fp, "STARTTLS\r\n");
                if (strpos(self::smtp_read($fp), '220') !== 0) {
                    return 'notls';
                }
            }
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);

            if (!@stream_socket_enable_crypto($fp, true, $method)) {
                return 'tlsfail';
            }
            if ($security === 'ssl' && !$imap) {
                $banner = self::smtp_read($fp);
            }

            return 'ok';
        };
        $fp = null;
        $state = $open(false);
        $cert = $state === 'ok' ? (stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null) : null;
        if (is_resource($fp)) {
            fclose($fp);
        }
        if ($state !== 'ok') {
            return ['state' => $state];
        }
        $info = $cert ? openssl_x509_parse($cert) : null;
        if (!$info) {
            return ['state' => 'tlsfail'];
        }
        $names = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $n) {
            if (stripos(trim($n), 'DNS:') === 0) {
                $names[] = strtolower(substr(trim($n), 4));
            }
        }
        if (!$names && !empty($info['subject']['CN'])) {
            $names[] = strtolower((string) $info['subject']['CN']);
        }
        $match = false;
        foreach ($names as $n) {
            $match = $match || self::name_match(strtolower($host), $n);
        }
        // the hosting firewall sent the connection to this server's own mail server instead (cPanel SMTP Restrictions)
        if (!$match && self::is_own_server($names, $banner)) {
            return ['state' => 'redirected'];
        }
        if (!$match) {
            $plain = array_values(array_filter($names, static function ($n) { return strpos($n, '*') === false && self::valid_host($n); }));

            return ['state' => 'certname', 'names' => array_slice($plain, 0, 3)];
        }
        if (($info['validTo_time_t'] ?? 0) < time()) {
            return ['state' => 'certexpired'];
        }
        // name and date are fine: is the certificate trusted?
        $state = $open(true);
        if (is_resource($fp)) {
            fclose($fp);
        }

        return ['state' => $state === 'ok' ? 'ok' : 'certbad'];
    }

    /** Names of this server: a certificate or greeting with one of them means the connection never left this server */
    private static function own_names()
    {
        $rc = rcube::get_instance();
        $names = [gethostname(), php_uname('n'), $_SERVER['SERVER_NAME'] ?? ''];
        foreach (['smtp_host', 'imap_host'] as $k) {
            $h = $rc->config->get($k);
            $h = is_array($h) ? (string) reset($h) : (string) $h;
            $names[] = parse_url(strpos($h, '://') ? $h : 'tcp://' . $h, PHP_URL_HOST);
        }

        return array_values(array_filter(array_unique(array_map('strtolower', array_filter($names))), static function ($n) {
            return strpos($n, '.') !== false && !in_array($n, ['localhost', '127.0.0.1'], true);
        }));
    }

    private static function is_own_server(array $cert_names, $banner)
    {
        foreach (self::own_names() as $own) {
            if (stripos((string) $banner, $own) !== false) {
                return true;
            }
            foreach ($cert_names as $n) {
                if (self::name_match($own, $n)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Can this server open outgoing mail connections at all? Checks two big providers and reads their greeting,
     * so a firewall that blocks (CSF SMTP_BLOCK) or redirects (cPanel SMTP Restrictions) is told apart.
     *
     * @return array ['open' => [host:port, ...], 'blocked' => [...], 'redirected' => [...]]
     */
    public static function outbound_status($timeout = 4, array $ports = [465, 587])
    {
        $out = ['open' => [], 'blocked' => [], 'redirected' => []];
        // Gmail answers on 465 and 587, Microsoft 365 only on 587
        $targets = ['smtp.gmail.com' => ['/\bgoogle\b|gsmtp|smtp\.gmail\.com/i', [465, 587]], 'smtp.office365.com' => ['/microsoft|outlook/i', [587]]];
        foreach ($targets as $host => [$want, $host_ports]) {
            foreach (array_intersect($host_ports, $ports) as $port) {
                if ($port === 465) {
                    $fp = @stream_socket_client("tls://{$host}:465", $no, $str, $timeout); // implicit TLS: the greeting comes after the handshake
                    $banner = $fp ? (stream_set_timeout($fp, $timeout) ? self::smtp_read($fp) : '') : '';
                    if ($fp) {
                        fclose($fp);
                    }
                    $key = !$fp || $banner === '' ? 'blocked' : (preg_match($want, $banner) ? 'open' : 'redirected');
                    $out[$key][] = "{$host}:{$port}";
                    continue;
                }
                $fp = @stream_socket_client("tcp://{$host}:{$port}", $no, $str, $timeout);
                $banner = $fp ? (stream_set_timeout($fp, $timeout) ? self::smtp_read($fp) : '') : '';
                if ($fp) {
                    fclose($fp);
                }
                $key = !$fp || $banner === '' ? 'blocked' : (preg_match($want, $banner) ? 'open' : 'redirected');
                $out[$key][] = "{$host}:{$port}";
            }
        }

        return $out;
    }

    public static function name_match($host, $pattern)
    {
        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 1);

            return substr($host, -strlen($suffix)) === $suffix && strpos(substr($host, 0, -strlen($suffix)), '.') === false && strlen($host) > strlen($suffix);
        }

        return $host === $pattern;
    }

    private static function smtp_read($fp)
    {
        $out = '';
        for ($i = 0; $i < 50 && ($line = fgets($fp, 1024)) !== false; $i++) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return $out;
    }

    /**
     * Connect and sign in; with $mail also send it (to the address itself).
     *
     * @return array ['ok' => true] or ['ok' => false, 'error' => key, 'names' => [certificate names]]
     */
    private function try_server(array $r, $password, $mail = null)
    {
        $rcmail = rcmail::get_instance();
        $ips = null;
        if ($r['mode'] === 'smtp') {
            $ips = self::resolve($r['host']);
            if (is_string($ips)) {
                return ['ok' => false, 'error' => $ips];
            }
            self::$route = self::route_config($r, $password, $ips[0]);
        } else {
            self::$route = null;
        }
        self::$wizard = true;
        self::reset_smtp();
        $error = null;
        if ($mail) {
            $ok = $rcmail->deliver_message($mail, $r['email'], $r['email'], $error);
            $response = is_object($rcmail->smtp) ? $rcmail->smtp->get_response() : [];
        } else {
            $smtp = new rcube_smtp();
            $ok = $smtp->connect();
            $error = $smtp->get_error();
            $response = $smtp->get_response();
            $smtp->disconnect();
        }
        self::reset_smtp();
        self::$route = null;
        self::$wizard = false;
        if ($ok) {
            return ['ok' => true];
        }
        $key = self::error_key($error, $response);
        $out = ['ok' => false, 'error' => $key];
        if ($key === 'connect' || $key === 'failed') {
            if ($ips) {
                $probe = self::probe($ips[0], $r['host'], $r['port'], $r['security']);
                $out['error'] = $probe['state'] === 'ok' ? 'failed' : $probe['state'];
                $out['names'] = $probe['names'] ?? [];
                if ($out['error'] === 'redirected') {
                    $out['error'] = 'hostblock';
                } elseif ($out['error'] === 'unreachable' && self::is_public($ips[0])) {
                    // only this server, or every outside mail server? the second one is the hosting's firewall
                    $st = in_array((int) $r['port'], [465, 587], true) ? self::outbound_status(3, [(int) $r['port']]) : ['open' => [1]];
                    if (!$st['open']) {
                        $out['error'] = 'hostblock';
                    }
                }
            } elseif ($key === 'connect') {
                $out['error'] = 'unreachable';
            }
        }

        return $out;
    }

    /* ---------------- the code mail ---------------- */

    private function code_mail(array $st, $code)
    {
        $rcmail = rcmail::get_instance();
        $name = trim($st['first'] . ' ' . $st['last']);
        $service = synapse_core::service_name();
        $vars = ['code' => $code, 'service' => $service, 'email' => $st['email'], 'minutes' => self::CODE_TTL / 60];
        $from = format_email_recipient(rcube_utils::idn_to_ascii($st['email']), $name);

        $mime = new Mail_mime("\r\n");
        $mime->headers([
            'Date' => $rcmail->user_date(),
            'From' => $from,
            'To' => $from,
            'Subject' => $this->plugin->gettext(['name' => 'sa_mail_subject', 'vars' => $vars]),
            'Message-ID' => $rcmail->gen_message_id($st['email']),
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'All',
        ]);
        $text = $this->plugin->gettext(['name' => 'sa_mail_body', 'vars' => $vars]);
        $mime->setTXTBody($text);
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2420;max-width:520px">'
            . '<p>' . nl2br(rcube::Q($this->plugin->gettext(['name' => 'sa_mail_intro', 'vars' => $vars]))) . '</p>'
            . '<p style="font-size:30px;font-weight:bold;letter-spacing:8px;font-family:monospace;margin:18px 0">' . rcube::Q($code) . '</p>'
            . '<p style="color:#5c655f;font-size:13px">' . nl2br(rcube::Q($this->plugin->gettext(['name' => 'sa_mail_note', 'vars' => $vars]))) . '</p></div>';
        $mime->setHTMLBody($html);
        $mime->setParam('head_charset', RCUBE_CHARSET);
        $mime->setParam('text_charset', RCUBE_CHARSET . ";\r\n format=flowed");
        $mime->setParam('html_charset', RCUBE_CHARSET);
        $mime->setParam('text_encoding', 'quoted-printable');
        $mime->setParam('html_encoding', 'quoted-printable');
        $mime->setParam('head_encoding', 'quoted-printable');

        return $mime;
    }

    private static function code_hash($code, $email)
    {
        return hash_hmac('sha256', $code . '|' . strtolower($email), self::key() . '|synapse-sendas-code');
    }

    /** New code for the wizard state and the mail with it; false when the mail could not be sent */
    private function send_code(array &$st)
    {
        $user = (int) rcmail::get_instance()->user->ID;
        if ($wait = synapse_core::rl_blocked(['sacode' => $user])) {
            return ['ok' => false, 'error' => 'slow', 'wait' => $wait];
        }
        $code = self::demo() ? self::DEMO_CODE : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $st['code'] = self::code_hash($code, $st['email']);
        $st['code_exp'] = time() + self::CODE_TTL;
        $st['tries'] = 0;
        $st['sent_at'] = time();
        $res = self::demo() ? ['ok' => true] : $this->try_server($st, self::state_password($st), $this->code_mail($st, $code));
        if ($res['ok']) {
            synapse_core::rl_fail(['sacode' => $user]); // counts mails that were really sent (protects the mailbox from floods)
        }

        return $res;
    }

    /**
     * App Passwords (Google, Yahoo, ...) are shown as "abcd efgh ijkl mnop": the spaces are only for reading.
     * They are removed when the rest is exactly 16 letters; other passwords keep their spaces. A pasted line
     * break is never part of a password.
     */
    public static function clean_password($password)
    {
        $password = str_replace(["\r", "\n"], '', (string) $password);
        $compact = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $password);

        return preg_match('/^[a-z]{16}$/i', (string) $compact) ? $compact : $password;
    }

    private static function state_password(array $st)
    {
        return $st['mode'] === 'smtp' ? (string) synapse_secret::decrypt($st['password'], self::key()) : '';
    }

    /* ---------------- wizard actions ---------------- */

    private function input($name)
    {
        return trim((string) rcube_utils::get_input_string('_' . $name, rcube_utils::INPUT_POST));
    }

    private function fail($key, array $extra = [])
    {
        $vars = ['max' => self::max(), 'seconds' => $extra['wait'] ?? 0];
        $this->plugin->json(['ok' => false, 'error' => $key, 'message' => $this->plugin->gettext(['name' => 'sa_err_' . $key, 'vars' => $vars])] + $extra);
    }

    /** Address checks shared by the wizard steps: valid, not already added, not over the limit */
    private function check_email($email, $iid)
    {
        $rcmail = rcmail::get_instance();
        if (!self::enabled()) {
            $this->fail('off');
        }
        if (!rcube_utils::check_email(rcube_utils::idn_to_ascii($email)) || strlen($email) > 128) {
            $this->fail('email');
        }
        $all = $rcmail->user->list_identities();
        foreach ($all as $ident) {
            if (strtolower($ident['email']) === $email && (int) $ident['identity_id'] !== $iid) {
                $this->fail('exists');
            }
        }
        if (!$iid && count($all) >= self::max()) {
            $this->fail('limit');
        }
        if ($iid && !$rcmail->user->get_identity($iid)) {
            $this->fail('failed');
        }
    }

    public function action_detect()
    {
        $this->plugin->require_post();
        $user = (int) rcmail::get_instance()->user->ID;
        $email = strtolower($this->input('email'));
        $iid = (int) $this->input('iid');
        $this->check_email($email, $iid);
        if ($wait = synapse_core::rl_blocked(['sadetect' => $user])) {
            $this->fail('slow', ['wait' => $wait]);
        }
        synapse_core::rl_fail(['sadetect' => $user]);

        require_once __DIR__ . '/synapse_mailconfig.php';
        $cfg = synapse_mailconfig::detect($email, !self::demo());
        $this->plugin->json(['ok' => true, 'config' => $cfg, 'server_ok' => self::server_ok($email), 'server_name' => synapse_core::service_name()]);
    }

    public function action_test()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $user = (int) $rcmail->user->ID;
        $email = strtolower($this->input('email'));
        $iid = (int) $this->input('iid');
        $this->check_email($email, $iid);

        $st = ['email' => $email, 'iid' => $iid, 'first' => mb_substr($this->input('first'), 0, 64), 'last' => mb_substr($this->input('last'), 0, 64),
            'mode' => $this->input('mode') === 'server' ? 'server' : 'smtp', 'host' => '', 'port' => 0, 'security' => '', 'username' => '', 'password' => ''];
        if ($st['first'] === '') {
            $this->fail('name');
        }
        $old = $iid ? self::row($user, $iid) : null;
        if ($st['mode'] === 'server') {
            if (!self::server_ok($email)) {
                $this->fail('serverdomain');
            }
        } else {
            $st['host'] = rcube_utils::idn_to_ascii(strtolower($this->input('host')));
            $st['port'] = (int) $this->input('port');
            $st['security'] = $this->input('security');
            $st['username'] = mb_substr($this->input('username'), 0, 255);
            $password = (string) rcube_utils::get_input_string('_password', rcube_utils::INPUT_POST, true);
            $password = self::clean_password($password);
            if (!self::valid_host($st['host']) && !filter_var($st['host'], FILTER_VALIDATE_IP)) {
                $this->fail('host');
            }
            if (!in_array($st['port'], self::PORTS, true)) {
                $this->fail('port');
            }
            if (!in_array($st['security'], self::SECURITY, true)) {
                $this->fail('security');
            }
            if ($st['username'] === '') {
                $this->fail('username');
            }
            // edit: an empty password keeps the saved one, but only for the same server and user name
            if ($password === '' && $old && $old['mode'] === 'smtp' && $old['host'] === $st['host'] && $old['username'] === $st['username']) {
                $password = (string) synapse_secret::decrypt($old['password'], self::key());
            }
            if ($password === '') {
                $this->fail('password');
            }
            $st['password'] = synapse_secret::encrypt($password, self::key());
        }
        if ($wait = synapse_core::rl_blocked(['satest' => $user])) {
            $this->fail('slow', ['wait' => $wait]);
        }

        // same verified address, new server or password: a working connection is enough
        $ident = $iid ? $rcmail->user->get_identity($iid) : null;
        if ($old && $old['verified_at'] && $ident && strtolower($ident['email']) === $email) {
            $res = self::demo() ? ['ok' => true] : $this->try_server($st, self::state_password($st));
            if (!$res['ok']) {
                synapse_core::rl_fail(['satest' => $user]); // only failed tests count: trying ports and modes is normal
                synapse_core::security_log("sendas: server test failed ({$res['error']}) for " . $rcmail->get_user_name());
                $this->fail($res['error'], ['names' => $res['names'] ?? []]);
            }
            $this->save($st, (int) $old['verified_at']);
            synapse_core::security_log("sendas: server changed for {$email} by " . $rcmail->get_user_name());
            $this->plugin->json(['ok' => true, 'done' => true, 'iid' => $iid]);
        }

        $res = $this->send_code($st);
        if (!$res['ok']) {
            if ($res['error'] !== 'slow') {
                synapse_core::rl_fail(['satest' => $user]);
                synapse_core::security_log("sendas: server test failed ({$res['error']}) for " . $rcmail->get_user_name());
            }
            $this->fail($res['error'], ['names' => $res['names'] ?? [], 'wait' => $res['wait'] ?? 0]);
        }
        $_SESSION['synapse_sa'] = $st;
        $this->plugin->json(['ok' => true, 'step' => 'code', 'email' => $email, 'resend' => self::RESEND_WAIT, 'demo' => self::demo()]);
    }

    public function action_resend()
    {
        $this->plugin->require_post();
        $st = $_SESSION['synapse_sa'] ?? null;
        if (!$st) {
            $this->fail('restart');
        }
        if (($wait = $st['sent_at'] + self::RESEND_WAIT - time()) > 0) {
            $this->fail('wait', ['wait' => $wait]);
        }
        $res = $this->send_code($st);
        if (!$res['ok']) {
            $this->fail($res['error'], ['wait' => $res['wait'] ?? 0]);
        }
        $_SESSION['synapse_sa'] = $st;
        $this->plugin->json(['ok' => true, 'resend' => self::RESEND_WAIT]);
    }

    public function action_verify()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $st = $_SESSION['synapse_sa'] ?? null;
        if (!$st || empty($st['code'])) {
            $this->fail('restart', ['restart' => true]);
        }
        if ($st['code_exp'] < time()) {
            $_SESSION['synapse_sa'] = null;
            $this->fail('expired', ['restart' => true]);
        }
        $code = preg_replace('/\D/', '', $this->input('code'));
        if (!hash_equals($st['code'], self::code_hash($code, $st['email']))) {
            $st['tries']++;
            $_SESSION['synapse_sa'] = $st['tries'] >= self::CODE_TRIES ? null : $st;
            synapse_core::security_log("sendas: wrong code for {$st['email']} by " . $rcmail->get_user_name());
            $this->fail($st['tries'] >= self::CODE_TRIES ? 'tries' : 'code', ['restart' => $st['tries'] >= self::CODE_TRIES]);
        }
        $_SESSION['synapse_sa'] = null;
        // a new address could have been added in another tab meanwhile
        $this->check_email($st['email'], $st['iid']);
        $iid = $this->save($st, time());
        synapse_core::security_log("sendas: {$st['email']} added by " . $rcmail->get_user_name());
        $this->plugin->json(['ok' => true, 'iid' => $iid]);
    }

    /** Saves the identity (new or changed) and its server row */
    private function save(array $st, $verified_at)
    {
        $rcmail = rcmail::get_instance();
        $data = ['name' => trim($st['first'] . ' ' . $st['last']), 'email' => rcube_utils::idn_to_ascii($st['email'])];
        if ($st['iid']) {
            $iid = $st['iid'];
            $rcmail->user->update_identity($iid, $data);
        } else {
            $iid = (int) $rcmail->user->insert_identity($data + ['standard' => 0, 'signature' => '', 'html_signature' => 0,
                'organization' => '', 'reply-to' => '', 'bcc' => '']);
        }
        $old = self::row($rcmail->user->ID, $iid);
        self::put(['identity_id' => $iid, 'user_id' => $rcmail->user->ID, 'first_name' => $st['first'], 'last_name' => $st['last'],
            'mode' => $st['mode'], 'host' => $st['host'], 'port' => $st['port'], 'security' => $st['security'], 'username' => $st['username'],
            'password' => $st['password'], 'verified_at' => $verified_at, 'last_ok_at' => $old['last_ok_at'] ?? 0, 'created_at' => $old['created_at'] ?? time()]);

        return $iid;
    }

    /** Last wizard step: signature and default address */
    public function action_finish()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $iid = (int) $this->input('iid');
        if (!self::row($rcmail->user->ID, $iid)) {
            $this->fail('failed');
        }
        $signature = mb_substr((string) rcube_utils::get_input_string('_signature', rcube_utils::INPUT_POST, true), 0, 4000);
        $rcmail->user->update_identity($iid, ['signature' => $signature, 'html_signature' => 0]);
        if ($this->input('default') === '1') {
            $rcmail->user->set_default($iid);
        }
        // suggestion for "also open its inbox here"
        $row = self::row($rcmail->user->ID, $iid);
        require_once __DIR__ . '/synapse_mailconfig.php';
        $imap = $row['mode'] === 'smtp' ? synapse_mailconfig::imap_host((string) ($rcmail->user->get_identity($iid)['email'] ?? ''), $row['host']) : ['host' => '', 'provider' => null];
        $this->plugin->json(['ok' => true, 'imap_host' => $imap['host'], 'imap_provider' => $imap['provider']]);
    }

    /** "When I reply": from the address the message was sent to, or always from the default address */
    public function action_reply()
    {
        $this->plugin->require_post();
        $rcmail = rcmail::get_instance();
        $mode = $this->input('mode') === 'default' ? 'default' : 'same';
        $rcmail->user->save_prefs(['synapse_reply_from' => $mode]);
        $this->plugin->json(['ok' => true]);
    }

    /* ---------------- identity hooks ---------------- */

    public function identity_select($args)
    {
        $rcmail = rcmail::get_instance();
        $reply = rcube_utils::get_input_string('_reply_uid', rcube_utils::INPUT_GET) || rcube_utils::get_input_string('_forward_uid', rcube_utils::INPUT_GET);
        if ($reply && $rcmail->config->get('synapse_reply_from') === 'default') {
            $args['selected'] = 0; // the default identity is always first
        }

        return $args;
    }

    /** Identity form: the sending server box; verified addresses can only change by running the wizard again */
    public function identity_form($args)
    {
        $rcmail = rcmail::get_instance();
        $iid = (int) ($args['record']['identity_id'] ?? 0);
        if (!$iid) {
            return $args;
        }
        $r = self::row($rcmail->user->ID, $iid);
        $p = $this->plugin;
        if ($r) {
            $args['form']['addressing']['content']['email']['readonly'] = true;
            $via = $r['mode'] === 'server' ? $p->gettext('sa_via_server') : rcube::Q($r['host']) . ':' . (int) $r['port'] . ' · ' . ($r['security'] === 'ssl' ? 'SSL/TLS' : 'STARTTLS');
            $lines = [rcube::Q($p->gettext('sa_sendsvia')) . ' <b>' . ($r['mode'] === 'server' ? rcube::Q($via) : $via) . '</b>'];
            if ($r['mode'] === 'smtp') {
                $lines[] = rcube::Q($p->gettext('sa_username')) . ': <b>' . rcube::Q($r['username']) . '</b> · ' . rcube::Q($p->gettext('sa_passwordsaved'));
            }
            $lines[] = rcube::Q($p->gettext(['name' => 'sa_verifiedon', 'vars' => ['date' => $rcmail->format_date((int) $r['verified_at'])]]));
            $button = $p->gettext('sa_changeserver');
        } elseif (strtolower((string) $args['record']['email']) === self::login_email()) {
            return $args; // the account's own address always sends through this server
        } else {
            $lines = [rcube::Q($p->gettext('sa_legacy'))];
            $button = $p->gettext('sa_verifynow');
        }
        $html = '<div class="sendas-box"><p>' . implode('<br>', $lines) . '</p>'
            . (self::enabled() ? '<button type="button" class="ghost-btn" onclick="return parent.UI && parent.UI.sendAs(' . $iid . ')">' . rcube::Q($button) . '</button>' : '')
            . '</div>';
        $inbox = '';
        if ($r && !empty($r['verified_at']) && synapse_inbox::enabled()) {
            $ib = synapse_inbox::rows($rcmail->user->ID)[$iid] ?? null;
            $inbox = '<div class="sendas-box"><p>' . ($ib ? rcube::Q($p->gettext('ib_opens')) . ' <b>' . rcube::Q($ib['host']) . ':993</b> · SSL/TLS<br>' . rcube::Q($p->gettext('sa_username')) . ': <b>' . rcube::Q($ib['username']) . '</b>'
                : rcube::Q($p->gettext('ib_form_off'))) . '</p><button type="button" class="ghost-btn" onclick="return parent.UI && parent.UI.inboxSetup(' . $iid . ')">'
                . rcube::Q($p->gettext($ib ? 'ib_change' : 'ib_turnon')) . '</button></div>';
        }
        // right after the address fields
        $form = [];
        foreach ($args['form'] as $k => $v) {
            $form[$k] = $v;
            if ($k === 'addressing') {
                $form['synapse_sendas'] = ['name' => $p->gettext('sa_server_section'), 'content' => $html];
                if ($inbox) {
                    $form['synapse_inbox'] = ['name' => $p->gettext('ib_section'), 'content' => $inbox];
                }
            }
        }
        $args['form'] = $form;

        return $args;
    }

    /** The old "new identity" form only adds the account's own address; others go through the wizard */
    public function identity_create($args)
    {
        if (!empty($args['login'])) {
            return $args;
        }
        if (strtolower(rcube_utils::idn_to_utf8((string) ($args['record']['email'] ?? ''))) !== self::login_email()) {
            $args['abort'] = true;
            $args['result'] = false;
            $args['message'] = 'synapse_core.sa_usewizard';
        }

        return $args;
    }

    public function identity_update($args)
    {
        $rcmail = rcmail::get_instance();
        if (!isset($args['record']['email']) || !($ident = $rcmail->user->get_identity($args['id']))) {
            return $args;
        }
        $new = strtolower(rcube_utils::idn_to_utf8((string) $args['record']['email']));
        $current = strtolower(rcube_utils::idn_to_utf8((string) $ident['email']));
        if ($new === $current) {
            return $args;
        }
        if (self::row($rcmail->user->ID, $args['id'])) {
            $args['record']['email'] = $ident['email']; // verified address: unchanged
        } elseif ($new !== self::login_email()) {
            $args['abort'] = true;
            $args['result'] = false;
            $args['message'] = 'synapse_core.sa_usewizard';
        }

        return $args;
    }

    public function identity_delete($args)
    {
        $rcmail = rcmail::get_instance();
        if ($r = self::row($rcmail->user->ID, $args['id'])) {
            self::remove($rcmail->user->ID, $args['id']);
            synapse_core::security_log('sendas: an address was removed by ' . $rcmail->get_user_name());
        }

        return $args;
    }
}
