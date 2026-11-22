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
 * Authenticated encryption (AES-256-GCM) for small secrets stored in the database, such as 2FA keys.
 * The key is derived from the install's des_key, so a database copy alone does not reveal them.
 */
class synapse_secret
{
    private const PREFIX = 'sm1:';

    public static function encrypt($plain, $install_key)
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt((string) $plain, 'aes-256-gcm', self::key($install_key), OPENSSL_RAW_DATA, $iv, $tag);

        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /** @return string|null null when the value was tampered with or the key is wrong */
    public static function decrypt($stored, $install_key)
    {
        if (strpos((string) $stored, self::PREFIX) !== 0 || ($raw = base64_decode(substr($stored, strlen(self::PREFIX)), true)) === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key($install_key), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    /** Secret part of the admin panel address, e.g. "k7f2-9xq4-m3pz-8w1d" (about 79 bits; no 0/o/1/l/i) */
    public static function admin_key()
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $key = '';
        for ($i = 0; $i < 16; $i++) {
            $key .= ($i && $i % 4 === 0 ? '-' : '') . $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $key;
    }

    /** config/ADMIN-URL.txt: where the admin finds the panel address in the file manager */
    public static function write_admin_note($root, $url)
    {
        $fallback = preg_replace('#/admin/([^/]+)$#', '/admin.php?k=$1', $url);
        $text = "SynapseMail admin panel\n=======================\n\n" . $url . "\n\n"
            . "If your server ignores .htaccess (e.g. nginx), use:\n" . $fallback . "\n\n"
            . "Keep this address private: without it the admin panel answers \"404 Not Found\".\n"
            . "A new address can be generated in Admin > Security. Written " . gmdate('Y-m-d H:i') . " UTC.\n";
        $file = $root . 'config/ADMIN-URL.txt';
        $ok = file_put_contents($file, $text, LOCK_EX) !== false;
        @chmod($file, 0640);

        return $ok;
    }

    private static function key($install_key)
    {
        return hash_hkdf('sha256', (string) $install_key, 32, 'synapsemail-secret-v1');
    }
}
