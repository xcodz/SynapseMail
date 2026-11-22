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

$config = [];

// Per-install values (encryption key, database, mail server) are written by the installer to
// config/synapse.inc.php. Until it exists, every web request goes to the installer.
if (is_file(__DIR__ . '/synapse.inc.php')) {
    include __DIR__ . '/synapse.inc.php';
} elseif (PHP_SAPI !== 'cli') {
    header('Location: install.php', true, 302);
    exit;
}

// self-service password change through cPanel's own webmail API (port 2096): the request is authenticated
// with the user's current password, so no API key is stored and only that mailbox can be changed.
// The host must match the server's TLS certificate; the installer detects it, otherwise the server hostname.
if (empty($config['password_cpanel_host'])) {
    $config['password_cpanel_host'] = gethostname();
}
$config['password_driver'] = 'cpanel';
$config['password_cpanel_port'] = 2096;
$config['password_confirm_current'] = true;
$config['password_minimum_length'] = 12;
$config['password_minimum_score'] = 2; // must contain a digit and a symbol
$config['password_log'] = true;

$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';
$config['product_name'] = 'SynapseMail';
$config['support_url'] = '';
$config['skin'] = 'synapse';
$config['skins_allowed'] = ['synapse'];
$config['layout'] = 'widescreen';
$config['htmleditor'] = 1;
// empty: the editor picks Tahoma for Persian/Arabic UI languages, Inter otherwise
$config['default_font'] = '';
$config['login_autocomplete'] = 2;
// ceiling for "remember this device" (15 days, in minutes); normal sessions use a 30 min idle timeout (synapse_core)
$config['session_lifetime'] = 21600;
$config['mail_pagesize'] = 50;
// two-factor authentication with an authenticator app: 'off', 'optional' (users choose) or 'required'
$config['synapse_2fa_policy'] = 'optional';

// Signed release manifest for the admin panel's update check (https; '' turns the check off in config/synapse.inc.php)
$config['synapse_update_url'] ??= 'https://repo.synapsecyber.io/synapsemail/release.json';
$config['plugins'] = ['synapse_core', 'archive', 'zipdownload', 'synapse_preview', 'synapse_editor', 'password'];
$config['dont_override'] = ['htmleditor', 'skin', 'layout', 'synapse_2fa_policy'];

// developer override (not shipped)
if (is_file(__DIR__ . '/config.local.inc.php')) {
    include __DIR__ . '/config.local.inc.php';
}
