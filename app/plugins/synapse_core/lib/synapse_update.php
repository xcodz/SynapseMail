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
 * Update check against a signed release manifest (release.json + release.json.sig, Ed25519 via libsodium).
 * Only reads: shared hosting installs are updated by uploading the new zip. The manifest's sha256 lets the
 * admin confirm the zip they downloaded is the one that was signed.
 */
class synapse_update
{
    // release signing public key; the private key never leaves the release machine
    public const PUBLIC_KEY = 'xOVkjBQXNYQw8ER4+vako4ed+cDYrj0LJPYfMqaTyxM=';

    /** @return array ['latest' => manifest, 'newer' => bool] or ['error' => text] */
    public static function check($url, $current)
    {
        if (!preg_match('#^https://#i', (string) $url)) {
            return ['error' => 'Update check not configured (synapse_update_url).'];
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return ['error' => 'PHP sodium extension missing: signatures cannot be checked.'];
        }
        $json = self::fetch($url);
        $sig = self::fetch($url . '.sig');
        if ($json === null || $sig === null) {
            return ['error' => 'Could not reach the update server.'];
        }
        $m = self::verify($json, $sig, self::PUBLIC_KEY);
        if ($m === null) {
            return ['error' => 'The release manifest is not correctly signed: ignored.'];
        }

        return ['latest' => $m, 'newer' => version_compare($m['version'], $current, '>')];
    }

    /** Parsed manifest when the signature and the fields are valid, else null */
    public static function verify($json, $sig_b64, $public_b64)
    {
        $sig = base64_decode(trim((string) $sig_b64), true);
        $pub = base64_decode($public_b64, true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen((string) $pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig, (string) $json, $pub)) {
            return null;
        }
        $m = json_decode((string) $json, true);
        if (!is_array($m) || ($m['product'] ?? '') !== 'SynapseMail' || !preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($m['version'] ?? ''))
            || !preg_match('#^https://#', (string) ($m['url'] ?? '')) || !preg_match('/^[0-9a-f]{64}$/', (string) ($m['sha256'] ?? ''))) {
            return null;
        }

        return $m;
    }

    /** Small HTTPS GET with certificate checks */
    private static function fetch($url)
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => 6, 'follow_location' => 1, 'max_redirects' => 3, 'header' => "User-Agent: SynapseMail-update-check\r\n", 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $data = @file_get_contents($url, false, $ctx, 0, 65536);

        return $data === false ? null : $data;
    }
}
