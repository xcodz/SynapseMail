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
 * TOTP (RFC 6238, SHA-1, 30 s, 6 digits) as used by Google Authenticator and compatible apps,
 * plus one-time recovery codes. No dependencies.
 */
class synapse_totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** New random secret, base32 (160 bits, the RFC 4226 recommended length) */
    public static function secret()
    {
        return self::base32_encode(random_bytes(20));
    }

    public static function code($secret, $time = null)
    {
        $counter = intdiv($time ?? time(), self::PERIOD);
        $hash = hash_hmac('sha1', pack('J', $counter), self::base32_decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Check a code, allowing one step of clock drift either way.
     * Returns the matched time step so callers can refuse re-use of the same code, or false.
     */
    public static function verify($secret, $code, $time = null)
    {
        $code = preg_replace('/\s+/', '', (string) $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $time = $time ?? time();
        foreach ([0, -1, 1] as $drift) {
            $t = $time + $drift * self::PERIOD;
            if (hash_equals(self::code($secret, $t), $code)) {
                return intdiv($t, self::PERIOD);
            }
        }

        return false;
    }

    /** otpauth:// URI for authenticator apps (QR code content) */
    public static function uri($issuer, $account, $secret)
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** @return string[] human-friendly one-time recovery codes, e.g. "4F7K-2Q9M" */
    public static function recovery_codes($count = 8)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $c = '';
            for ($j = 0; $j < 8; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
        }

        return $codes;
    }

    public static function base32_encode($bin)
    {
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32_decode($b32)
    {
        $b32 = strtoupper(preg_replace('/[\s=]+/', '', (string) $b32));
        $bits = '';
        foreach (str_split($b32) as $ch) {
            $pos = strpos(self::B32, $ch);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) == 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
