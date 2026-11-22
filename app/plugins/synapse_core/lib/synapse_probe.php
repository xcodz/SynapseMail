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

/** Mail server probes shared by the installer, the admin panel and the deliverability checks. */
class synapse_probe
{
    /**
     * Connect, read the greeting and (for STARTTLS ports) upgrade to TLS. Reports whether TLS works and whether
     * the certificate is valid for the host name.
     */
    public static function tls($host, $port, $proto, $implicit)
    {
        $r = ['port' => $port, 'ok' => false, 'tls' => false, 'cert_ok' => false, 'error' => null];
        foreach ([true, false] as $verify) {
            $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host, 'SNI_enabled' => true]]);
            $errno = 0;
            $errstr = '';
            $fp = @stream_socket_client(($implicit ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
            if (!$fp) {
                if ($implicit && $verify) {
                    continue; // maybe only the certificate failed: try again without verification
                }
                $r['error'] = $errstr ?: 'connection refused or timed out';

                return $r;
            }
            stream_set_timeout($fp, 4);
            $greeting = $proto === 'smtp' ? self::smtp_reply($fp) : (string) fgets($fp, 512);
            if (($proto === 'imap' && strpos($greeting, '* OK') !== 0) || ($proto === 'smtp' && strpos($greeting, '220') !== 0)) {
                fclose($fp);
                $r['error'] = 'unexpected greeting';

                return $r;
            }
            if (!$implicit) {
                if ($proto === 'imap') {
                    fwrite($fp, "a1 STARTTLS\r\n");
                    $line = (string) fgets($fp, 512);
                    $ok = strpos($line, 'a1 OK') === 0;
                } else {
                    fwrite($fp, "EHLO synapsemail-setup\r\n");
                    self::smtp_reply($fp);
                    fwrite($fp, "STARTTLS\r\n");
                    $ok = strpos(self::smtp_reply($fp), '220') === 0;
                }
                if (!$ok || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($fp);
                    if ($verify && $ok) {
                        continue;
                    }
                    $r['ok'] = true;
                    $r['error'] = 'STARTTLS not available';

                    return $r;
                }
            }
            fclose($fp);
            $r['ok'] = true;
            $r['tls'] = true;
            $r['cert_ok'] = $verify;

            return $r;
        }
        $r['error'] = 'TLS handshake failed';

        return $r;
    }

    /** Read a complete (possibly multi-line "220-…") SMTP reply; returns its last line */
    public static function smtp_reply($fp)
    {
        $line = '';
        for ($i = 0; $i < 50; $i++) {
            $line = (string) fgets($fp, 512);
            if ($line === '' || !isset($line[3]) || $line[3] !== '-') {
                break;
            }
        }

        return $line;
    }
}
