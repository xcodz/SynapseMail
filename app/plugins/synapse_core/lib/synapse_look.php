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
 * The two designs of the skin: Cyber (the HUD themes) and Lumen (glass). A theme's design is its "lumen-" prefix;
 * ui.js and the page head script (templates/includes/layout.html) use the same lists.
 */
class synapse_look
{
    public const CYBER = ['warfare', 'blackops', 'night', 'agency', 'soft', 'auto'];
    public const LUMEN = ['lumen-blush', 'lumen-lagoon', 'lumen-dusk', 'lumen-auto'];

    /**
     * An all-upper-case HUD text in Lumen's sentence case: "01 // VERIFYING ACCOUNT" gives "Verifying account",
     * "PASSWORD ACCEPTED // SECOND FACTOR" gives "Password accepted · Second factor". Names and acronyms keep their
     * case. Texts with lower-case letters, and scripts without case (Arabic, Persian), are returned unchanged.
     */
    public static function text(string $text): string
    {
        if (!preg_match('/\p{Lu}.*\p{Lu}/su', $text) || preg_match('/\p{Ll}/u', preg_replace('/\$\w+/', '', $text))) {
            return $text;
        }
        $text = preg_replace('~^(?:\d+|XX|SYNAPSEMAIL)\s*//?\s+~u', '', $text);
        $parts = array_map(static function ($part) {
            $part = mb_strtolower($part, 'UTF-8');

            return mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($part, 1, null, 'UTF-8');
        }, preg_split('~\s+//\s+~u', $text));
        $text = implode(' · ', $parts);
        $keep = ['SynapseMail', 'SynapseCyber', 'IMAP', 'SMTP', '2FA', 'TOTP', 'PIN', 'IP', 'URL', 'DNS', 'SSL', 'TLS', 'HTML', 'PDF', 'OK', 'ID', 'QR', 'UTC', 'MB', 'GB', 'KB', 'CC', 'BCC', 'VIP', 'API'];
        $map = array_combine(array_map('strtolower', $keep), $keep);

        return preg_replace_callback('/(?<![\p{L}\$])(' . implode('|', array_map('preg_quote', array_keys($map))) . ')(?![\p{L}])/iu', static function ($m) use ($map) {
            return $map[strtolower($m[1])];
        }, $text);
    }
}
