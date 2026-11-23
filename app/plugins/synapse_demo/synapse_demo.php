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
 * Public demo mode (demo build only, never in the release package).
 *
 * - Sign-in with demo / demo, then 2FA 123456 and passcode 1234 (real SynapseMail screens).
 * - Every visitor gets a private, temporary user, so settings, contacts, passcode and 2FA changes never reach
 *   other visitors. Mail comes from the sample mailbox (rcube_demo storage, kept in the session).
 * - Sending mail is blocked; no IMAP/SMTP/network connection is ever made.
 * - Send As: one sample address is ready; the wizard uses only the built-in provider list, simulates the server
 *   test and accepts the code 123456. Its inbox can be opened (sample mailbox in mail-studio/).
 * - Temporary users are removed after DEMO_TTL.
 */
class synapse_demo extends rcube_plugin
{
    public const DEMO_TTL = 12 * 3600;
    public const DEMO_EMAIL = 'demo@synapsemail.test';

    public $task = '.*';

    public function init()
    {
        $rcmail = rcmail::get_instance();
        if (!$rcmail->config->get('synapse_demo')) {
            return; // inert unless the demo build switches it on
        }
        require_once __DIR__ . '/rcube_demo.php';

        $this->add_texts('localization/');
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('user_create', [$this, 'user_create']);
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('message_before_send', [$this, 'block_send']);
        $this->add_hook('render_page', [$this, 'banner']);
        $this->include_stylesheet('demo.css');
        $this->include_script('demo.js'); // fills the demo account only where the sign-in form exists
    }

    /** demo / demo becomes a fresh private user for this visitor */
    public function authenticate($args)
    {
        $rcmail = rcmail::get_instance();
        $this->gc();
        unset($_POST['_remember']); // demo sessions never outlive the idle timeout
        if (hash_equals((string) $rcmail->config->get('synapse_demo_user', 'demo'), strtolower((string) $args['user']))
            && hash_equals((string) $rcmail->config->get('synapse_demo_pass', 'demo'), (string) $args['pass'])) {
            $args['user'] = 'demo-' . bin2hex(random_bytes(6));
        } else {
            $args['abort'] = true;
            $args['error'] = $this->gettext('wrongdemo');
        }

        return $args;
    }

    public function user_create($args)
    {
        $args['user_name'] = 'Demo User';
        $args['user_email'] = self::DEMO_EMAIL;

        return $args;
    }

    /** Runs before synapse_core's login_after (plugin order): give the new user 2FA, a passcode and contacts */
    public function login_after($args)
    {
        $rcmail = rcmail::get_instance();
        $user = $rcmail->user;
        $_SESSION['username'] = self::DEMO_EMAIL; // what the interface shows; the row is the private demo-xxxx user

        if (empty($user->get_prefs()['synapse_demo_ready'])) {
            $codes = ['DEMO-2FA1', 'DEMO-2FA2', 'DEMO-2FA3', 'DEMO-2FA4', 'DEMO-2FA5', 'DEMO-2FA6', 'DEMO-2FA7', 'DEMO-2FA8'];
            $user->save_prefs([
                'synapse_demo_ready' => true,
                'synapse_otp' => [
                    'secret' => synapse_secret::encrypt(synapse_totp::secret(), $rcmail->config->get_crypto_key('des_key')),
                    'recovery' => array_map(static function ($c) { return password_hash($c, PASSWORD_DEFAULT); }, $codes),
                    'enabled_at' => time(), 'last_step' => 0,
                ],
                'synapse_pin' => ['hash' => password_hash('1234', PASSWORD_DEFAULT), 'len' => 4, 'hint' => $this->gettext('pinhint'),
                    'interval' => 'login', 'set_at' => time()],
                'synapse_pin_prompt' => ['skips' => 0, 'next' => time() + 86400 * 30, 'off' => true],
            ]);
            $rcmail->config->set_user_prefs($user->get_prefs());
            $this->seed_contacts($rcmail);
            $this->seed_sendas($rcmail);
        }

        return $args;
    }

    protected function seed_contacts($rcmail)
    {
        $book = new rcube_contacts($rcmail->get_dbh(), $rcmail->user->ID);
        $people = [
            ['Sara', 'Collins', 'sara@northwind.example', 'Northwind (sample)', 'Security lead'],
            ['Daniel', 'Park', 'daniel@northwind.example', 'Northwind (sample)', 'DevOps engineer'],
            ['Maya', 'Lopez', 'maya@studio.example', 'Studio Example', 'Designer'],
            ['Reza', 'Karimi', 'reza@pars.example', 'Pars Example', 'Project manager'],
            ['Layla', 'Hassan', 'layla@desert.example', 'Desert Example', 'Design lead'],
            ['Tom', 'Becker', 'tom@harbor.example', 'Harbor Example', 'Sales'],
            ['Aiko', 'Tanaka', 'aiko@sakura.example', 'Sakura Example', 'Translator'],
            ['Omar', 'Nasser', 'omar@desert.example', 'Desert Example', 'Developer'],
        ];
        foreach ($people as [$first, $last, $email, $org, $job]) {
            $book->insert(['name' => "$first $last", 'firstname' => $first, 'surname' => $last, 'email' => [$email],
                'organization' => $org, 'jobtitle' => $job]);
        }
    }

    /** One ready Send As address, so visitors see the result without running the wizard */
    protected function seed_sendas($rcmail)
    {
        $iid = (int) $rcmail->user->insert_identity(['name' => 'Studio Example', 'email' => 'hello@studio.example', 'standard' => 0, 'organization' => 'Studio Example',
            'reply-to' => '', 'bcc' => '', 'signature' => "Studio Example\nDesign and branding", 'html_signature' => 0]);
        if ($iid) {
            synapse_sendas::put(['identity_id' => $iid, 'user_id' => $rcmail->user->ID, 'first_name' => 'Studio', 'last_name' => 'Example', 'mode' => 'smtp',
                'host' => 'smtp.studio.example', 'port' => 465, 'security' => 'ssl', 'username' => 'hello@studio.example',
                'password' => synapse_secret::encrypt('demo', $rcmail->config->get_crypto_key('des_key')), 'verified_at' => time() - 86400 * 3,
                'last_ok_at' => time() - 7200, 'created_at' => time() - 86400 * 3]);
            // its inbox is ready too (sample mailbox in mail-studio/, no connection)
            synapse_inbox::put(['identity_id' => $iid, 'user_id' => $rcmail->user->ID, 'host' => 'imap.studio.example', 'username' => 'hello@studio.example',
                'password' => '', 'created_at' => time() - 86400 * 3, 'last_ok_at' => time() - 7200]);
        }
    }

    public function block_send($args)
    {
        $args['abort'] = true;
        $args['result'] = false;
        $args['error'] = ['label' => 'synapse_demo.nosend', 'vars' => []];

        return $args;
    }

    /**
     * The demo notice at the top: on the sign-in pages a banner with the demo details, inside the app the
     * "LIVE" status strip says DEMO instead (no overlap with the interface).
     */
    public function banner($args)
    {
        $rcmail = rcmail::get_instance();
        if (!empty($_GET['_framed']) || !empty($_POST['_framed']) || strpos((string) $args['content'], '<body') === false) {
            return $args;
        }
        $demo = rcube::Q($this->gettext('demo'));
        $count = 0;
        $args['content'] = preg_replace('#<span class="live-tag">[^<]*</span><strong>[^<]*</strong>#',
            '<span class="live-tag synapse-demo-tag">' . $demo . '</span><strong class="synapse-demo-text">' . rcube::Q($this->gettext('banner')) . '</strong>',
            $args['content'], 1, $count);
        if ($count) {
            // phones hide the status strip: a small tag keeps the notice visible
            $args['content'] = preg_replace('/(<body[^>]*>)/i', '$1<div class="synapse-demo-chip" role="note"><b>' . $demo . '</b> '
                . rcube::Q($this->gettext('chip')) . '</div>', $args['content'], 1);
        } else {
            $login = $rcmail->task === 'login' || $rcmail->task === 'logout' || empty($_SESSION['user_id']);
            $html = '<div class="synapse-demo-banner' . ($login ? ' is-login' : '') . '" role="note"><b>' . $demo . '</b><span>'
                . ($login ? $this->gettext('bannerlogin') : rcube::Q($this->gettext('banner'))) . '</span>'
                . '<a href="https://github.com/xcodz/SynapseMail" target="_blank" rel="noopener">GitHub</a></div>';
            $args['content'] = preg_replace('/(<body[^>]*>)/i', '$1' . $html, $args['content'], 1);
        }

        return $args;
    }

    /** Remove temporary demo users (and with them their contacts, identities and prefs) */
    protected function gc()
    {
        $db = rcmail::get_instance()->get_dbh();
        $db->query('DELETE FROM ' . $db->table_name('users', true) . " WHERE `username` LIKE 'demo-%' AND `created` < " . $db->now(-self::DEMO_TTL));
    }
}
