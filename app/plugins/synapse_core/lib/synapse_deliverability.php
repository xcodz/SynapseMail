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
 * Read-only deliverability checks in plain PHP (DNS through the system resolver, no external services).
 * Each check returns [state, detail, fix?] with state pass | partial | fail | unknown; "unknown" never lowers
 * the score. Weights follow the design: MX 10, PTR 15, SPF 15, DKIM 20, DMARC 15, TLS 10, blacklists 15.
 */
class synapse_deliverability
{
    public const WEIGHTS = ['mx' => 10, 'ptr' => 15, 'spf' => 15, 'dkim' => 20, 'dmarc' => 15, 'tls' => 10, 'dnsbl' => 15];
    public const LABELS = ['mx' => 'MX records', 'ptr' => 'Reverse DNS (PTR)', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC',
        'tls' => 'TLS on IMAP/SMTP', 'dnsbl' => 'Blacklists'];
    // shortcut: public DNSBLs only; Spamhaus refuses big shared resolvers, add a DQS key option if admins ask
    public const DNSBL = ['bl.spamcop.net', 'psbl.surriel.com', 'dnsbl-1.uceprotect.net', 'zen.spamhaus.org'];

    /**
     * @param string   $domain mail domain
     * @param string   $ip     sending IP (the server's outgoing address)
     * @param string[] $tls    [label => [host, port, proto, implicit]]
     */
    public static function run($domain, $ip, array $tls)
    {
        $r = [];
        $r['mx'] = self::mx($domain);
        $r['ptr'] = self::ptr($ip);
        $r['spf'] = self::spf($domain, $ip);
        $r['dkim'] = self::dkim($domain);
        $r['dmarc'] = self::dmarc($domain);
        $r['tls'] = self::tls($tls);
        $r['dnsbl'] = self::dnsbl($ip);
        $badges = [];
        foreach (['MTA-STS' => '_mta-sts.' . $domain, 'TLS-RPT' => '_smtp._tls.' . $domain, 'BIMI' => 'default._bimi.' . $domain] as $name => $host) {
            $badges[$name] = (bool) self::txt($host);
        }

        return ['domain' => $domain, 'ip' => $ip, 'time' => time(), 'checks' => $r, 'score' => self::score($r), 'badges' => $badges];
    }

    /** 0–100 over the checks that ran; partial counts half */
    public static function score(array $checks)
    {
        $got = $max = 0;
        foreach ($checks as $k => $c) {
            if ($c[0] === 'unknown') {
                continue;
            }
            $max += self::WEIGHTS[$k];
            $got += self::WEIGHTS[$k] * ['pass' => 1, 'partial' => .5, 'fail' => 0][$c[0]];
        }

        return $max ? (int) round($got * 100 / $max) : null;
    }

    public static function mx($domain)
    {
        $mx = @dns_get_record($domain, DNS_MX);
        if ($mx === false) {
            return ['unknown', 'DNS lookup failed'];
        }
        if (!$mx) {
            return ['fail', 'No MX record: other servers do not know where to deliver mail for ' . $domain, "{$domain}. MX 0 mail.{$domain}."];
        }
        usort($mx, static function ($a, $b) { return $a['pri'] <=> $b['pri']; });
        $bad = [];
        foreach ($mx as $m) {
            if (!@dns_get_record($m['target'], DNS_A) && !@dns_get_record($m['target'], DNS_AAAA)) {
                $bad[] = $m['target'];
            }
        }
        $list = implode(', ', array_map(static function ($m) { return $m['pri'] . ' ' . $m['target']; }, $mx));

        return $bad ? ['partial', "$list · no address for " . implode(', ', $bad)] : ['pass', $list];
    }

    public static function ptr($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['unknown', 'No public sending IP to check'];
        }
        $name = @gethostbyaddr($ip);
        if (!$name || $name === $ip) {
            return ['fail', "$ip has no PTR record. Ask your hosting provider (the IP owner) to set one."];
        }
        $fwd = array_merge(array_column((array) @dns_get_record($name, DNS_A), 'ip'), array_column((array) @dns_get_record($name, DNS_AAAA), 'ipv6'));
        $match = in_array(inet_pton($ip), array_map('inet_pton', array_filter($fwd)), true);

        return $match ? ['pass', "$ip → $name → $ip"] : ['partial', "$ip → $name, but $name does not resolve back to $ip"];
    }

    public static function spf($domain, $ip)
    {
        $recs = array_values(array_filter(self::txt($domain), static function ($t) { return stripos($t, 'v=spf1') === 0; }));
        $suggest = "{$domain}. TXT \"v=spf1 +a +mx" . ($ip ? (strpos($ip, ':') ? ' +ip6:' : ' +ip4:') . $ip : '') . ' ~all"';
        if (!$recs) {
            return ['fail', 'No SPF record', $suggest];
        }
        if (count($recs) > 1) {
            return ['fail', count($recs) . ' SPF records: receivers treat this as an error. Merge them into one.', $suggest];
        }
        if (!$ip) {
            return ['partial', $recs[0] . ' (no sending IP to test)'];
        }
        $lookups = 0;
        // fix for an existing record: allow this IP before its final "all"
        $mech = (strpos($ip, ':') ? 'ip6:' : 'ip4:') . $ip;
        $suggest = "{$domain}. TXT \"" . (preg_match('/\s[-~?+]?all\s*$/i', $recs[0]) ? preg_replace('/\s([-~?+]?all)\s*$/i', " +{$mech} $1", $recs[0]) : $recs[0] . " +{$mech}") . '"';
        $res = self::spf_eval($domain, $ip, $lookups, 0);
        $detail = $recs[0] . " · $lookups DNS lookups";
        if ($lookups > 10) {
            return ['fail', "$detail: more than 10, receivers return permerror", $suggest];
        }
        if ($res === 'pass') {
            return ['pass', "$detail · $ip allowed"];
        }

        return ['fail', "$detail · $ip is not allowed (result: " . ($res ?? 'neutral') . ')', $suggest];
    }

    /** Minimal SPF evaluation (RFC 7208 mechanisms ip4/ip6/a/mx/include/redirect/all; exists/ptr count but do not match) */
    public static function spf_eval($domain, $ip, &$lookups, $depth)
    {
        if ($depth > 10) {
            return 'permerror';
        }
        $rec = null;
        foreach (self::txt($domain) as $t) {
            if (stripos($t, 'v=spf1') === 0) {
                $rec = $t;
            }
        }
        if ($rec === null) {
            return $depth ? 'permerror' : 'none';
        }
        $redirect = $result = null; // keep walking after a match so every DNS lookup is counted (limit 10)
        foreach (preg_split('/\s+/', trim(substr($rec, 6))) as $term) {
            if ($term === '') {
                continue;
            }
            if (stripos($term, 'redirect=') === 0) {
                $redirect = substr($term, 9);
                continue;
            }
            if (strpos($term, '=') !== false) {
                continue; // other modifiers (exp=)
            }
            $q = in_array($term[0], ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $term = ltrim($term, '+-~?');
            $cidr = null;
            if (preg_match('/^(ip4|ip6):(.+)$/i', $term, $m)) {
                [$mech, $arg] = [strtolower($m[1]), $m[2]];
            } elseif (preg_match('#^([a-z]+)(?::([^/]+))?(?:/(\d+))?(?://(\d+))?$#i', $term, $m)) {
                $mech = strtolower($m[1]);
                $arg = ($m[2] ?? '') !== '' ? $m[2] : null;
                $c = $m[strpos($ip, ':') !== false ? 4 : 3] ?? ''; // a/24//64: IPv4 and IPv6 prefix lengths
                $cidr = $c !== '' ? (int) $c : null;
            } else {
                return 'permerror';
            }
            $match = false;
            switch ($mech) {
                case 'all':
                    $match = true;
                    break;
                case 'ip4':
                case 'ip6':
                    $match = self::ip_in($ip, (string) $arg);
                    break;
                case 'a':
                    $lookups++;
                    $match = self::host_has_ip($arg ?: $domain, $ip, $cidr);
                    break;
                case 'mx':
                    $lookups++;
                    foreach ((array) @dns_get_record($arg ?: $domain, DNS_MX) as $mx) {
                        if (self::host_has_ip($mx['target'], $ip, $cidr)) {
                            $match = true;
                            break;
                        }
                    }
                    break;
                case 'include':
                    $lookups++;
                    $match = self::spf_eval((string) $arg, $ip, $lookups, $depth + 1) === 'pass';
                    break;
                case 'exists':
                case 'ptr':
                    $lookups++;
                    break;
            }
            if ($match && $result === null) {
                $result = ['+' => 'pass', '-' => 'fail', '~' => 'softfail', '?' => 'neutral'][$q];
            }
            if ($lookups > 10) {
                return 'permerror';
            }
        }
        if ($result !== null) {
            return $result;
        }
        if ($redirect) {
            $lookups++;

            return self::spf_eval($redirect, $ip, $lookups, $depth + 1);
        }

        return 'neutral';
    }

    /** Is $ip inside $net ("1.2.3.0/24", "2001:db8::/32" or a single address)? */
    public static function ip_in($ip, $net)
    {
        [$addr, $bits] = array_pad(explode('/', $net, 2), 2, null);
        $a = @inet_pton($ip);
        $b = @inet_pton($addr);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : max(0, min(strlen($a) * 8, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if (!$rest) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    private static function host_has_ip($host, $ip, $cidr)
    {
        $v6 = strpos($ip, ':') !== false;
        $addrs = $v6 ? array_column((array) @dns_get_record($host, DNS_AAAA), 'ipv6') : array_column((array) @dns_get_record($host, DNS_A), 'ip');
        foreach ($addrs as $a) {
            if (self::ip_in($ip, $a . ($cidr !== null ? '/' . $cidr : ''))) {
                return true;
            }
        }

        return false;
    }

    public static function dkim($domain, $selector = 'default')
    {
        $host = "{$selector}._domainkey.{$domain}";
        $rec = implode('', array_filter(self::txt($host), static function ($t) { return stripos($t, 'p=') !== false; }));
        if ($rec === '') {
            return ['fail', "No DKIM key at $host. Enable DKIM in cPanel > Email Deliverability, then publish the record it shows."];
        }
        if (!preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=\s]*)/', $rec, $m) || trim($m[1]) === '') {
            return ['fail', "The DKIM key at $host is empty (revoked)."];
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $m[1]), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        $bits = $key ? (int) (openssl_pkey_get_details($key)['bits'] ?? 0) : 0;
        if (!$bits) {
            return ['partial', "DKIM record found at $host but the key could not be read"];
        }

        return $bits >= 2048 ? ['pass', "$host · RSA $bits bits"] : ['partial', "$host · RSA $bits bits: use 2048 bits or more"];
    }

    public static function dmarc($domain)
    {
        $suggest = "_dmarc.{$domain}. TXT \"v=DMARC1; p=quarantine; rua=mailto:dmarc@{$domain}\"";
        $recs = array_values(array_filter(self::txt('_dmarc.' . $domain), static function ($t) { return stripos($t, 'v=DMARC1') === 0; }));
        if (count($recs) !== 1) {
            return ['fail', $recs ? 'More than one DMARC record' : 'No DMARC record', $suggest];
        }
        $tags = [];
        foreach (explode(';', $recs[0]) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $tags[strtolower(trim($k))] = trim($v);
        }
        $p = strtolower($tags['p'] ?? '');
        if (!in_array($p, ['none', 'quarantine', 'reject'], true)) {
            return ['fail', "Invalid policy: {$recs[0]}", $suggest];
        }
        if ($p === 'none' || empty($tags['rua'])) {
            return ['partial', $recs[0] . ($p === 'none' ? ' · p=none only monitors' : '') . (empty($tags['rua']) ? ' · no rua report address' : ''), $suggest];
        }

        return ['pass', $recs[0]];
    }

    public static function tls(array $targets)
    {
        $bad = $ok = [];
        foreach ($targets as $label => [$host, $port, $proto, $implicit]) {
            $r = synapse_probe::tls($host, $port, $proto, $implicit);
            if ($r['tls'] && $r['cert_ok']) {
                $ok[] = "$label $host:$port";
            } else {
                $bad[] = "$label $host:$port " . ($r['tls'] ? '(certificate not valid for this name)' : '(' . ($r['error'] ?: 'no TLS') . ')');
            }
        }
        if (!$ok && !$bad) {
            return ['unknown', 'No mail server configured'];
        }

        return $bad ? [$ok ? 'partial' : 'fail', implode(' · ', array_merge($bad, $ok))] : ['pass', implode(' · ', $ok) . ' · valid certificates'];
    }

    public static function dnsbl($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['unknown', 'Blacklists are checked for a public IPv4 sending address only'];
        }
        $rev = implode('.', array_reverse(explode('.', $ip)));
        $listed = $clean = $skipped = [];
        foreach (self::DNSBL as $zone) {
            $a = array_column((array) @dns_get_record("$rev.$zone", DNS_A), 'ip');
            if (!$a) {
                $clean[] = $zone;
            } elseif (preg_grep('/^127\.255\.255\./', $a)) {
                $skipped[] = $zone; // resolver refused (Spamhaus blocks public/shared resolvers)
            } else {
                $listed[] = $zone;
            }
        }
        $detail = ($listed ? 'Listed on ' . implode(', ', $listed) . '. ' : 'Not listed on ' . implode(', ', $clean) . '. ')
            . ($skipped ? 'Could not check ' . implode(', ', $skipped) . ' from this server.' : '');
        if (!$listed && !$clean) {
            return ['unknown', trim($detail)];
        }

        return [$listed ? 'fail' : 'pass', trim($detail)];
    }

    /** TXT strings of a name (multi-string records joined) */
    public static function txt($name)
    {
        $out = [];
        foreach ((array) @dns_get_record($name, DNS_TXT) as $r) {
            $out[] = isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? '');
        }

        return $out;
    }
}
