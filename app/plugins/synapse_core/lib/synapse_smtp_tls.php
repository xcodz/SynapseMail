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
 * Sign-in method for Send As servers: Net_SMTP quietly skips STARTTLS when a server does not offer it
 * (or an attacker removes it), and would then send the password in clear text. This method only signs in
 * when the connection is really encrypted, then uses the best method the server offers.
 * It extends Net_SMTP only to read the (protected) socket of the live connection.
 */
class synapse_smtp_tls extends \Net_SMTP
{
    public const METHOD = 'SYNAPSE-TLS';

    public function __construct()
    {
    }

    public function auth_tls($uid, $pwd, $authz, $conn)
    {
        $fp = $conn->socket->fp ?? null;
        $meta = is_resource($fp) ? stream_get_meta_data($fp) : [];
        if (empty($meta['crypto'])) {
            return PEAR::raiseError('synapse-notls: the connection is not encrypted');
        }
        unset($conn->auth_methods[self::METHOD]);

        return $conn->auth($uid, $pwd, '', false, $authz);
    }
}
