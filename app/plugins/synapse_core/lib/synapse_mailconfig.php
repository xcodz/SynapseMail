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
 * Finds the outgoing (SMTP) server of an email address, in this order:
 * known providers → Mail Autoconfig (the domain's own file, then Thunderbird's ISPDB) → DNS SRV
 * (RFC 6186 / 8314) → MX → a guess. Every result is only a suggestion: the wizard shows it and the
 * user can change it. All web requests go through the same SSRF guard as the SMTP test.
 */
class synapse_mailconfig
{
    public const ISPDB = 'https://autoconfig.thunderbird.net/v1.1/';
    public const CACHE_SECONDS = 86400;
    private const TIME_BUDGET = 12; // seconds for all network lookups of one address
    private const MAX_BYTES = 65536;

    /**
     * Known providers. tip: apppass (needs an App Password), ms365 (admin must allow SMTP AUTH),
     * oauth (only OAuth sign-in, not supported yet), enable (sending must be switched on), proton (Proton Bridge).
     */
    public const PROVIDERS = [
        'gmail' => ['name' => 'Gmail / Google Workspace', 'domains' => ['gmail.com', 'googlemail.com'], 'mx' => '/(^|\.)(google|googlemail)\.com$/',
            'host' => 'smtp.gmail.com', 'port' => 465, 'security' => 'ssl', 'tip' => 'apppass', 'link' => 'https://myaccount.google.com/apppasswords'],
        'microsoft365' => ['name' => 'Microsoft 365', 'domains' => [], 'mx' => '/\.mail\.protection\.outlook\.com$/',
            'host' => 'smtp.office365.com', 'port' => 587, 'security' => 'starttls', 'tip' => 'ms365', 'link' => 'https://learn.microsoft.com/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission'],
        'outlook' => ['name' => 'Outlook.com', 'domains' => ['outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'passport.com'], 'match' => '/^(hotmail|outlook|live|windowslive)\.[a-z.]+$/',
            'host' => 'smtp-mail.outlook.com', 'port' => 587, 'security' => 'starttls', 'tip' => 'oauth', 'link' => ''],
        'yahoo' => ['name' => 'Yahoo Mail', 'domains' => ['yahoo.com', 'ymail.com', 'rocketmail.com'], 'match' => '/^yahoo\.[a-z.]+$/', 'mx' => '/(^|\.)yahoodns\.net$/',
            'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'security' => 'ssl', 'tip' => 'apppass', 'link' => 'https://login.yahoo.com/account/security'],
        'aol' => ['name' => 'AOL Mail', 'domains' => ['aol.com', 'aim.com'], 'host' => 'smtp.aol.com', 'port' => 465, 'security' => 'ssl', 'tip' => 'apppass',
            'link' => 'https://login.aol.com/account/security'],
        'icloud' => ['name' => 'iCloud Mail', 'domains' => ['icloud.com', 'me.com', 'mac.com'], 'host' => 'smtp.mail.me.com', 'port' => 587, 'security' => 'starttls',
            'tip' => 'apppass', 'link' => 'https://account.apple.com'],
        'zoho' => ['name' => 'Zoho Mail', 'domains' => ['zoho.com', 'zohomail.com'], 'mx' => '/(^|\.)zoho(mail)?\.com$/', 'host' => 'smtp.zoho.com', 'port' => 465,
            'security' => 'ssl', 'tip' => 'apppass2fa', 'link' => 'https://accounts.zoho.com/home#security/app_password'],
        'zoho_eu' => ['name' => 'Zoho Mail (EU)', 'domains' => ['zoho.eu', 'zohomail.eu'], 'mx' => '/(^|\.)zoho(mail)?\.eu$/', 'host' => 'smtp.zoho.eu', 'port' => 465,
            'security' => 'ssl', 'tip' => 'apppass2fa', 'link' => 'https://accounts.zoho.eu/home#security/app_password'],
        'fastmail' => ['name' => 'Fastmail', 'domains' => ['fastmail.com', 'fastmail.fm'], 'mx' => '/(^|\.)messagingengine\.com$/', 'host' => 'smtp.fastmail.com',
            'port' => 465, 'security' => 'ssl', 'tip' => 'apppass', 'link' => 'https://app.fastmail.com/settings/security/apps'],
        'gmx' => ['name' => 'GMX', 'domains' => ['gmx.net', 'gmx.de', 'gmx.at', 'gmx.ch'], 'host' => 'mail.gmx.net', 'port' => 587, 'security' => 'starttls', 'tip' => 'enable',
            'link' => ''],
        'gmx_com' => ['name' => 'GMX / mail.com', 'domains' => ['gmx.com', 'gmx.us', 'mail.com'], 'host' => 'mail.gmx.com', 'port' => 587, 'security' => 'starttls',
            'tip' => 'enable', 'link' => ''],
        'yandex' => ['name' => 'Yandex Mail', 'domains' => ['yandex.com', 'yandex.ru', 'ya.ru'], 'mx' => '/(^|\.)yandex\.(net|ru)$/', 'host' => 'smtp.yandex.com',
            'port' => 465, 'security' => 'ssl', 'tip' => 'apppass', 'link' => 'https://id.yandex.com/security/app-passwords'],
        'proton' => ['name' => 'Proton Mail', 'domains' => ['proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me'], 'mx' => '/(^|\.)protonmail\.ch$/',
            'host' => 'smtp.protonmail.ch', 'port' => 587, 'security' => 'starttls', 'tip' => 'proton', 'link' => 'https://proton.me/support/smtp-submission'],
    ];

    private static $deadline = 0;

    /**
     * @param bool $network false: only the provider list and a guess (demo site, tests)
     * @return array host, port, security, username, source (provider|autoconfig|ispdb|dns|mx|guess), confirm (less sure),
     *               provider (name, tip, link) or null
     */
    public static function detect($email, $network = true)
    {
        $email = strtolower(trim($email));
        $domain = rcube_utils::idn_to_ascii(substr($email, strrpos($email, '@') + 1));

        if ($p = self::provider_by_domain($domain)) {
            return self::username(self::from_provider($p, 'provider'), $email);
        }
        if ($network) {
            $cache = synapse_core::setting('mc:' . sha1($domain));
            $cache = $cache ? json_decode($cache, true) : null;
            if (is_array($cache) && ($cache['time'] ?? 0) > time() - self::CACHE_SECONDS) {
                return self::username($cache['result'], $email);
            }
            self::$deadline = time() + self::TIME_BUDGET;
            $result = self::lookup($domain, $email);
            if ($result['source'] !== 'guess') {
                synapse_core::set_setting('mc:' . sha1($domain), json_encode(['time' => time(), 'result' => $result]));
            }

            return self::username($result, $email);
        }

        return self::guess($domain, $email, false);
    }

    private static function lookup($domain, $email)
    {
        // 1. the domain's own autoconfig file, 2. Thunderbird's ISPDB
        foreach (["https://autoconfig.{$domain}/mail/config-v1.1.xml?emailaddress=" . rawurlencode($email),
            "https://{$domain}/.well-known/autoconfig/mail/config-v1.1.xml"] as $url) {
            if ($r = self::parse_xml(self::https_get($url), $email)) {
                return $r + ['source' => 'autoconfig', 'confirm' => false, 'provider' => null];
            }
        }
        if ($r = self::parse_xml(self::https_get(self::ISPDB . $domain), $email)) {
            return $r + ['source' => 'ispdb', 'confirm' => false, 'provider' => null];
        }
        // 3. DNS SRV: implicit TLS (465) first
        foreach (['_submissions._tcp' => 'ssl', '_submission._tcp' => 'starttls'] as $name => $security) {
            if ($srv = self::srv("{$name}.{$domain}")) {
                return ['host' => $srv['target'], 'port' => $srv['port'], 'security' => $security, 'username' => '%EMAILADDRESS%', 'source' => 'dns',
                    'confirm' => !self::in_domain($srv['target'], $domain), 'provider' => null];
            }
        }
        // 4. MX: a known provider, or the ISPDB entry of the mail host's domain
        $mx = self::mx($domain);
        foreach ($mx as $host) {
            if ($p = self::provider_by_mx($host)) {
                return self::from_provider($p, 'mx', true);
            }
        }
        if ($mx && ($base = self::base_domain($mx[0])) && $base !== $domain
            && ($r = self::parse_xml(self::https_get(self::ISPDB . $base), $email))) {
            return $r + ['source' => 'mx', 'confirm' => true, 'provider' => null];
        }

        // 5. a guess (cPanel and most hosts use mail.<domain>)
        return self::guess($domain, $email, true);
    }

    private static function guess($domain, $email, $dns)
    {
        $host = 'mail.' . $domain;
        if ($dns && !self::resolves($host) && self::resolves('smtp.' . $domain)) {
            $host = 'smtp.' . $domain;
        }

        return ['host' => $host, 'port' => 465, 'security' => 'ssl', 'username' => $email, 'source' => 'guess', 'confirm' => true, 'provider' => null];
    }

    public static function provider_by_domain($domain)
    {
        foreach (self::PROVIDERS as $key => $p) {
            if (in_array($domain, $p['domains'], true) || (!empty($p['match']) && preg_match($p['match'], $domain))) {
                return ['key' => $key] + $p;
            }
        }

        return null;
    }

    public static function provider_by_mx($host)
    {
        $host = rtrim(strtolower($host), '.');
        foreach (self::PROVIDERS as $key => $p) {
            if (!empty($p['mx']) && preg_match($p['mx'], $host)) {
                return ['key' => $key] + $p;
            }
        }

        return null;
    }

    private static function from_provider(array $p, $source, $confirm = false)
    {
        return ['host' => $p['host'], 'port' => $p['port'], 'security' => $p['security'], 'username' => '%EMAILADDRESS%', 'source' => $source, 'confirm' => $confirm,
            'provider' => ['key' => $p['key'], 'name' => $p['name'], 'tip' => $p['tip'], 'link' => $p['link']]];
    }

    private static function username(array $r, $email)
    {
        $r['username'] = self::fill($r['username'] ?: '%EMAILADDRESS%', $email);

        return $r;
    }

    private static function fill($text, $email)
    {
        $at = strrpos($email, '@');

        return str_replace(['%EMAILADDRESS%', '%EMAILLOCALPART%', '%EMAILDOMAIN%'], [$email, substr($email, 0, $at), substr($email, $at + 1)], (string) $text);
    }

    /**
     * Reads the first usable outgoingServer of a Mail Autoconfig file (SSL or STARTTLS, password sign-in).
     * A DOCTYPE is refused, so no entity tricks are possible.
     */
    public static function parse_xml($xml, $email)
    {
        if (!is_string($xml) || $xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($prev);
        if (!$doc || !isset($doc->emailProvider)) {
            return null;
        }
        $oauth = false;
        foreach ($doc->emailProvider->outgoingServer as $s) {
            if ((string) $s['type'] !== 'smtp') {
                continue;
            }
            $socket = strtoupper(trim((string) $s->socketType));
            $security = $socket === 'SSL' ? 'ssl' : ($socket === 'STARTTLS' ? 'starttls' : null);
            $auth = [];
            foreach ($s->authentication as $a) {
                $auth[] = strtolower(trim((string) $a));
            }
            $password = !$auth || array_intersect($auth, ['password-cleartext', 'password-encrypted', 'plain', 'secure']);
            $oauth = $oauth || in_array('oauth2', $auth, true);
            $host = strtolower(self::fill(trim((string) $s->hostname), $email));
            $port = (int) $s->port;
            if (!$security || !$password || !synapse_sendas::valid_host($host)) {
                continue;
            }
            if (!in_array($port, synapse_sendas::PORTS, true)) {
                $port = $security === 'ssl' ? 465 : 587;
            }

            return ['host' => $host, 'port' => $port, 'security' => $security, 'username' => trim((string) $s->username) ?: '%EMAILADDRESS%'];
        }

        return null;
    }

    /** HTTPS GET with the SSRF guard: checked IP, certificate checked, 64 KB, at most 2 HTTPS redirects */
    public static function https_get($url, $redirects = 2)
    {
        if (time() >= self::$deadline) {
            return null;
        }
        $u = parse_url($url);
        if (($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || (isset($u['port']) && (int) $u['port'] !== 443)) {
            return null;
        }
        $ips = synapse_sendas::resolve($u['host'], false);
        if (is_string($ips)) {
            return null;
        }
        $addr = strpos($ips[0], ':') !== false ? "[{$ips[0]}]" : $ips[0];
        $ctx = stream_context_create(['ssl' => ['peer_name' => $u['host'], 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $timeout = max(1, min(4, self::$deadline - time()));
        $fp = @stream_socket_client("ssl://{$addr}:443", $no, $str, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            return null;
        }
        stream_set_timeout($fp, $timeout);
        $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        fwrite($fp, "GET {$path} HTTP/1.0\r\nHost: {$u['host']}\r\nUser-Agent: SynapseMail\r\nAccept: application/xml, text/xml\r\nConnection: close\r\n\r\n");
        $data = '';
        while (!feof($fp) && strlen($data) <= self::MAX_BYTES + 8192 && time() < self::$deadline + 1) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($fp)['timed_out'])) {
                break;
            }
            $data .= $chunk;
        }
        fclose($fp);
        [$head, $body] = array_pad(explode("\r\n\r\n", $data, 2), 2, '');
        if (!preg_match('#^HTTP/1\.[01] (\d{3})#', $head, $m)) {
            return null;
        }
        $status = (int) $m[1];
        if (in_array($status, [301, 302, 303, 307, 308], true) && $redirects > 0 && preg_match('/^Location:\s*(https:\/\/\S+)/mi', $head, $loc)) {
            return self::https_get(trim($loc[1]), $redirects - 1);
        }

        return $status === 200 && strlen($body) <= self::MAX_BYTES ? $body : null;
    }

    private static function srv($name)
    {
        $best = null;
        foreach ((array) @dns_get_record($name, DNS_SRV) as $r) {
            $target = rtrim(strtolower($r['target'] ?? ''), '.');
            if ($target === '' || !synapse_sendas::valid_host($target)) {
                continue; // "." means the service is not offered
            }
            if (!$best || $r['pri'] < $best['pri'] || ($r['pri'] == $best['pri'] && $r['weight'] > $best['weight'])) {
                $best = ['target' => $target, 'port' => in_array((int) $r['port'], synapse_sendas::PORTS, true) ? (int) $r['port'] : 0, 'pri' => $r['pri'], 'weight' => $r['weight']];
            }
        }
        if ($best && !$best['port']) {
            $best['port'] = strpos($name, '_submissions.') === 0 ? 465 : 587;
        }

        return $best;
    }

    private static function mx($domain)
    {
        $hosts = [];
        foreach ((array) @dns_get_record($domain, DNS_MX) as $r) {
            if (!empty($r['target'])) {
                $hosts[strtolower(rtrim($r['target'], '.'))] = (int) $r['pri'];
            }
        }
        asort($hosts);

        return array_keys($hosts);
    }

    private static function resolves($host)
    {
        return (bool) (@dns_get_record($host, DNS_A) ?: @dns_get_record($host, DNS_AAAA));
    }

    private static function in_domain($host, $domain)
    {
        return $host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain;
    }

    /** example.co.uk for mx1.example.co.uk (a short list of two-part endings is enough here) */
    public static function base_domain($host)
    {
        $parts = explode('.', rtrim(strtolower($host), '.'));
        $n = count($parts);
        if ($n < 2) {
            return null;
        }
        $two = $parts[$n - 2] . '.' . $parts[$n - 1];
        $take = $n >= 3 && preg_match('/^(co|com|net|org|ac|gov|edu)\.[a-z]{2}$/', $two) ? 3 : 2;

        return implode('.', array_slice($parts, -$take));
    }
}
