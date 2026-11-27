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
 * SynapseMail core: product version, client IP, per-account colour theme, "remember this device"
 * sessions and the security passcode (a numeric second step after the IMAP password).
 */
require_once __DIR__ . '/lib/synapse_totp.php';
require_once __DIR__ . '/lib/synapse_secret.php';
require_once __DIR__ . '/lib/synapse_gate.php';
require_once __DIR__ . '/lib/synapse_atomic.php';

class synapse_core extends rcube_plugin
{
    // bump on every release (3.x from the first public release)
    public const VERSION = '3.5.6';
    public const THEMES = ['warfare', 'blackops', 'night', 'agency', 'soft', 'auto'];

    // "remember this device": sliding window, renewed while the account is used
    public const REMEMBER_SECONDS = 15 * 86400;
    // normal sessions: idle timeout (config session_lifetime is the 15-day ceiling for remembered ones)
    public const IDLE_SECONDS = 30 * 60;

    // passcode policy
    public const PIN_INTERVALS = ['login', '3', '7', '30'];
    public const PIN_TRIES_PER_SESSION = 5;
    public const PIN_FAILS_BEFORE_LOCK = 10;
    public const PIN_FAIL_LOCK_SECONDS = 3600;

    // authenticator (TOTP) 2FA; policy comes from config synapse_2fa_policy
    public const OTP_POLICIES = ['off', 'optional', 'required'];
    public const OTP_TRIES_PER_SESSION = 5;
    public const OTP_FAILS_BEFORE_LOCK = 10;
    public const PIN_FORGOT_LOCK_SECONDS = 86400;
    public const PROMPT_EVERY_SECONDS = 3 * 86400;
    public const PROMPT_MAX_SKIPS = 3;
    public const DEVICE_COOKIE = 'synapse_pin_dev';

    // sign-in throttling, on top of core's per-account 3 failures/minute (which only covers known accounts)
    public const RL_WINDOW = 900;
    public const RL_BLOCK_SECONDS = 900;
    public const RL_LIMITS = [
        'uip' => 5,    // one account from one IP
        'ip' => 20,    // any accounts from one IP (password spraying)
        'user' => 20,  // one account from many IPs (distributed guessing)
        'pinip' => 20, // wrong passcodes from one IP, across sessions
        'otpip' => 20, // wrong authenticator codes from one IP, across sessions
        'admin' => 5, // admin panel sign-in failures per admin name
        'admip' => 10, // admin panel sign-in failures per IP
        'admstep' => 5, // admin panel: wrong authenticator codes when confirming a restore, update, backup or new codes
        'pwuser' => 5, // password change attempts per account (protects against guessing the current password)
        'sadetect' => 30, // Send As: settings lookups per user
        'satest' => 15, // Send As: failed server tests per user (each one connects to another server)
        'sacode' => 5, // Send As: code mails per user
        'sainbox' => 15, // other inboxes: failed sign-in tests per user
        'tgtacct' => 5, // Send As and other-inbox tests: failed sign-ins to one target account, across all users
        'tgtip' => 20, // the same tests: failed sign-ins from one client IP, any target
        'stepup' => 5, // wrong passwords or codes when confirming a security change, per user
    ];

    // sign-in attempt taken in authenticate(), settled by login_after() or login_failed()
    private $rl_login_taken = null;

    // the skin saves the chosen theme with rcmail.save_pref() (core reads this before init())
    public $allowed_prefs = ['synapse_theme', 'synapse_ui'];

    // requests allowed while the passcode gate is up
    private const GATE_ACTIONS = ['plugin.synapse-gate', 'plugin.synapse-pin-status', 'plugin.synapse-pin-verify', 'plugin.synapse-pin-forgot',
        'plugin.synapse-otp-verify', 'plugin.synapse-otp-enroll'];

    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('login_failed', [$this, 'login_failed']);
        $this->add_hook('password_change', [$this, 'password_changed']);
        $this->add_hook('send_page', [$this, 'render_title']);
        $this->security_headers();
        $this->init_session_lifetime($rcmail);

        foreach (['gate', 'pin-status', 'pin-verify', 'pin-forgot', 'pin-save', 'pin-remove', 'pin-prompt', 'security', 'appearance',
            'otp-verify', 'otp-enroll', 'otp-recovery', 'otp-disable', 'stepup'] as $action) {
            $this->register_action('plugin.synapse-' . $action, [$this, 'action_' . str_replace('-', '_', $action)]);
        }
        require_once __DIR__ . '/lib/synapse_sendas.php';
        require_once __DIR__ . '/lib/synapse_inbox.php';
        (new synapse_sendas($this))->init();
        (new synapse_inbox($this))->init();

        if (empty($rcmail->output)) {
            return;
        }

        // skin + plugin texts, also sent to the browser (ui.js reads them with rcmail.gettext)
        $this->add_texts('localization/', true);
        $rcmail->output->set_env('synapse_version', self::VERSION);
        $rcmail->output->add_handler('synapseservicename', [$this, 'service_name_html']);
        if ($rcmail->task === 'login' && $rcmail->output->type === 'html') {
            // "Welcome to <mail service name>" instead of the product name
            $rcmail->output->set_pagetitle($rcmail->gettext(['name' => 'welcome', 'vars' => ['product' => self::service_name()]]));
        }
        $rcmail->output->set_env('synapse_ip', rcube_utils::remote_ip());

        if ($rcmail->user && $rcmail->user->ID) {
            $theme = $rcmail->config->get('synapse_theme');
            if (in_array($theme, self::THEMES, true)) {
                $rcmail->output->set_env('synapse_theme', $theme);
            }
            // appearance settings: only known keys and values reach the page (ui.js whitelists them again)
            $ui = (string) $rcmail->config->get('synapse_ui', '');
            if (preg_match('/^((motion-(reduce|off)|effects-off|density-compact|text-(small|large|larger)|contrast-more|links-underline|open-(split|window))(\.|$))*$/', $ui)) {
                $rcmail->output->set_env('synapse_ui', $ui);
            }
            if ($prompt = $this->prompt_state()) {
                $rcmail->output->set_env('synapse_pin_prompt', $prompt);
            }
        }
    }

    /** Mail service name on the login page: admin panel setting, else the installer's, else the host name */
    public static function service_name()
    {
        $name = self::setting('service_name') ?? rcube::get_instance()->config->get('synapse_service_name');

        return (string) ($name ?: strtoupper(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'SynapseMail')));
    }

    /** Page titles read "SynapseMail - Inbox" instead of core's "SynapseMail :: Inbox" */
    public function render_title($args)
    {
        $args['content'] = preg_replace('#(<title>[^<]*?) :: #', '$1 - ', $args['content'], 1);

        return $args;
    }

    public function service_name_html()
    {
        return rcube::Q(self::service_name());
    }

    /* ---------------- sessions ---------------- */
    // note: core merges stored session data on write, so keys are cleared with null (unset() would come back)

    /**
     * Runs before core validates the session (index.php): remembered sessions get the long lifetime,
     * everything else the short idle timeout.
     */
    private function init_session_lifetime($rcmail)
    {
        if (empty($rcmail->session)) {
            return;
        }

        $until = (int) ($_SESSION['synapse_remember'] ?? 0);

        if ($until > time()) {
            $rcmail->session->set_lifetime(self::REMEMBER_SECONDS);
            // sliding window: every use renews the 15 days (written at most hourly)
            if ($until < time() + self::REMEMBER_SECONDS - 3600) {
                $_SESSION['synapse_remember'] = time() + self::REMEMBER_SECONDS;
            }
        } else {
            if (isset($_SESSION['synapse_remember'])) {
                $_SESSION['synapse_remember'] = null;
            }
            $rcmail->session->set_lifetime(self::IDLE_SECONDS);
        }

        // core always sends browser-session cookies; re-issue them as persistent ones for remembered logins
        header_register_callback(static function () {
            $until = (int) ($_SESSION['synapse_remember'] ?? 0);
            if ($until > time() && session_id() !== '') {
                rcube_utils::setcookie(session_name(), session_id(), $until);
                if (!empty($_COOKIE['roundcube_sessauth'])) {
                    rcube_utils::setcookie('roundcube_sessauth', $_COOKIE['roundcube_sessauth'], $until);
                }
            }
        });
    }

    public function login_after($args)
    {
        $rcmail = rcmail::get_instance();
        if ($this->rl_login_taken) {
            $this->rl_settle($this->rl_login_taken, true);
            $this->rl_login_taken = null;
        }
        $this->rl_clear('uip', mb_strtolower((string) $rcmail->get_user_name()) . '|' . rcube_utils::remote_addr());

        if (rcube_utils::get_input_string('_remember', rcube_utils::INPUT_POST) === '1') {
            $_SESSION['synapse_remember'] = time() + self::REMEMBER_SECONDS;
            // the auth cookie is bound to the lifetime time slot: re-issue it for the long lifetime
            $rcmail->session->set_lifetime(self::REMEMBER_SECONDS);
            $rcmail->session->set_auth_cookie();
        }

        $_SESSION['synapse_pin_state'] = null; $_SESSION['synapse_pin_tries'] = null;

        if ($this->locked_until() > time()) {
            $_SESSION['synapse_pin_state'] = 'locked';
        } elseif ($pin = $this->pin()) {
            if ($pin['interval'] !== 'login' && $this->device_verified_within((int) $pin['interval'] * 86400)) {
                $_SESSION['synapse_pin_ok_at'] = time();
            } else {
                $_SESSION['synapse_pin_state'] = 'pending';
                $_SESSION['synapse_pin_tries'] = 0;
            }
        }

        // authenticator code before the passcode; under a "required" policy, enrolment before anything else
        $_SESSION['synapse_otp_state'] = null; $_SESSION['synapse_otp_tries'] = 0;
        $policy = $this->otp_policy();
        if (($_SESSION['synapse_pin_state'] ?? null) !== 'locked' && $policy !== 'off') {
            if ($this->otp()) {
                $_SESSION['synapse_otp_state'] = 'pending';
            } elseif ($policy === 'required') {
                $_SESSION['synapse_otp_state'] = 'enroll';
            }
        }

        return $args;
    }

    /**
     * Passcode gate: while a passcode is pending (or the account is locked) every request is routed to the gate.
     */
    public function startup($args)
    {
        $rcmail = rcmail::get_instance();

        // logout ends the session before any action runs; the login task only signs in or shows the form
        if (empty($_SESSION['user_id']) || $args['task'] === 'logout' || ($args['task'] === 'login' && in_array($args['action'], ['', 'login'], true))) {
            return $args;
        }
        // any other action under _task=login would skip core's auth cookie check and this gate
        if ($args['task'] === 'login') {
            $args['task'] = 'mail';
        }

        // password change (password plugin): limit attempts per account and log them
        if ($args['task'] === 'settings' && $args['action'] === 'plugin.password-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $user = mb_strtolower((string) $rcmail->get_user_name());
            if ($wait = $this->rl_blocked(['pwuser' => $user])) {
                self::security_log('password change refused (rate limit) for ' . $user);
                $rcmail->output->show_message($this->gettext(['name' => 'pwratelimit', 'vars' => ['n' => max(1, (int) ceil($wait / 60))]]), 'error');
                $args['action'] = 'plugin.password';
            } else {
                $this->rl_fail(['pwuser' => $user]);
                self::security_log('password change attempt for ' . $user);
            }
        }

        // long-lived (remembered) sessions re-verify after the chosen interval
        $pin = $this->pin();
        if (empty($_SESSION['synapse_pin_state']) && $pin && $pin['interval'] !== 'login'
            && (int) ($_SESSION['synapse_pin_ok_at'] ?? 0) < time() - (int) $pin['interval'] * 86400
        ) {
            $_SESSION['synapse_pin_state'] = 'pending';
            $_SESSION['synapse_pin_tries'] = 0;
        }

        if ((empty($_SESSION['synapse_pin_state']) && empty($_SESSION['synapse_otp_state'])) || in_array($args['action'], self::GATE_ACTIONS)) {
            return $args;
        }

        if (!empty($rcmail->output->ajax_call)) {
            $rcmail->output->redirect(['_task' => 'mail', '_action' => 'plugin.synapse-gate']);
            exit;
        }

        $args['task'] = 'mail';
        $args['action'] = 'plugin.synapse-gate';

        return $args;
    }

    /* ---------------- gate (login page in "passcode" mode) ---------------- */

    public function action_gate()
    {
        $rcmail = rcmail::get_instance();

        if (empty($_SESSION['synapse_pin_state']) && empty($_SESSION['synapse_otp_state'])) {
            $rcmail->output->redirect(['_task' => 'mail']);
        }

        $rcmail->output->set_env('task', 'login');
        $rcmail->output->set_env('synapse_gate', $this->gate_info() + ['token' => $rcmail->get_request_token()]);
        $rcmail->output->set_pagetitle($this->gettext('securitycheck'));
        $rcmail->output->send('login');
    }

    public function action_pin_status()
    {
        $this->json($this->gate_info() + ['token' => rcmail::get_instance()->get_request_token()]);
    }

    public function action_pin_verify()
    {
        $rcmail = rcmail::get_instance();
        $this->require_post();

        if (($_SESSION['synapse_pin_state'] ?? null) !== 'pending' || !($pin = $this->pin())) {
            $this->json(['ok' => empty($_SESSION['synapse_pin_state']), 'state' => $_SESSION['synapse_pin_state'] ?? null]);
        }
        // an enrolled authenticator is checked first: the passcode cannot be tried (or locked) before it
        if (!synapse_gate::pin_step($_SESSION['synapse_pin_state'], $_SESSION['synapse_otp_state'] ?? null)) {
            $this->json(['ok' => false, 'next' => $this->next_gate(), 'message' => $this->gettext('finishsignin')], 403);
        }

        // attempts are taken before the check (network and account), so parallel requests get no extra guesses
        $ip = ['pinip' => rcube_utils::remote_addr()];
        if ($this->rl_take($ip)) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'message' => $this->gettext('pin_netlimit')]);
        }
        if (!$this->take_attempt('synapse_pin_fails')) {
            $this->rl_settle($ip, true);
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $this->locked_until() ?: null, 'message' => $this->gettext('pin_locked')]);
        }

        $code = rcube_utils::get_input_string('_pin', rcube_utils::INPUT_POST);

        if (preg_match('/^\d{4,6}$/', $code) && password_verify($code, $pin['hash'])) {
            $this->rl_settle($ip, true);
            $_SESSION['synapse_pin_state'] = null; $_SESSION['synapse_pin_tries'] = null;
            $_SESSION['synapse_pin_ok_at'] = time();
            $this->save_prefs(['synapse_pin_fails' => 0]);
            if ($pin['interval'] !== 'login') {
                $this->set_device_cookie((int) $pin['interval'] * 86400);
            }
            $this->json(['ok' => true, 'next' => $this->gate_step_done()]);
        }

        $this->rl_settle($ip, false);
        $tries = ++$_SESSION['synapse_pin_tries'];
        // too many wrong codes on this account (across sign-ins): temporary lock
        $lock = $this->lock_if_full('synapse_pin_fails');

        if ($lock || $tries >= self::PIN_TRIES_PER_SESSION) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $lock ?: null,
                'message' => $lock ? $this->gettext('pin_locked') : $this->gettext('pin_signin')]);
        }

        $this->json(['ok' => false, 'left' => self::PIN_TRIES_PER_SESSION - $tries, 'message' => $this->gettext('pin_wrong')]);
    }

    public function action_pin_forgot()
    {
        $rcmail = rcmail::get_instance();
        $this->require_post();
        $pin = $this->pin();

        // only as the passcode step itself: never while an enrolled authenticator is still owed
        if (!$pin || !synapse_gate::pin_step($_SESSION['synapse_pin_state'] ?? null, $_SESSION['synapse_otp_state'] ?? null)) {
            $this->json(['ok' => false, 'message' => $this->gettext('finishsignin')], 403);
        }

        if (rcube_utils::get_input_string('_do', rcube_utils::INPUT_POST) !== 'erase') {
            $this->json(['ok' => true, 'hint' => $pin['hint']]);
        }

        // erase the passcode; the account stays locked for 24h, then the user can sign in and set a new one
        $until = time() + self::PIN_FORGOT_LOCK_SECONDS;
        $this->save_prefs(['synapse_pin' => null, 'synapse_pin_fails' => 0, 'synapse_pin_lock' => $until,
            'synapse_pin_prompt' => ['skips' => 0, 'next' => $until, 'off' => false]]);
        $rcmail->kill_session();
        $this->json(['ok' => true, 'signedout' => true, 'locked_until' => $until]);
    }

    /* ---------------- settings: Security ---------------- */

    public function action_security()
    {
        $rcmail = rcmail::get_instance();
        $pin = $this->pin();

        $rcmail->output->set_env('synapse_security', [
            'has_pin' => (bool) $pin,
            'len' => $pin['len'] ?? 6,
            'hint' => $pin['hint'] ?? '',
            'interval' => $pin['interval'] ?? 'login',
            'set_at' => $pin['set_at'] ?? null,
            'remembered_until' => $_SESSION['synapse_remember'] ?? null,
            'remember_days' => self::REMEMBER_SECONDS / 86400,
            'stepup_until' => synapse_gate::stepup_valid($_SESSION['synapse_stepup_until'] ?? 0, time()) ? (int) $_SESSION['synapse_stepup_until'] : 0,
            'otp' => [
                'policy' => $this->otp_policy(),
                'enabled' => (bool) ($otp = $this->otp()),
                'enabled_at' => $otp['enabled_at'] ?? null,
                'recovery_left' => $otp ? count((array) $otp['recovery']) : 0,
            ],
        ]);
        $rcmail->output->add_handlers([
            'plugin.pagetitle' => static function () { return rcmail::get_instance()->gettext('security', 'synapse_core'); },
            'plugin.body' => static function () { return '<div id="securityApp" class="security-app"></div>'; },
        ]);
        $rcmail->output->set_pagetitle($this->gettext('security'));
        $rcmail->output->send('plugin');
    }

    /** Settings > Appearance: theme, animations and display options (the page is built by ui.js, saved with save_pref) */
    public function action_appearance()
    {
        $rcmail = rcmail::get_instance();
        $rcmail->output->add_handlers([
            'plugin.pagetitle' => static function () { return rcmail::get_instance()->gettext('appearance', 'synapse_core'); },
            'plugin.body' => static function () { return '<div id="appearanceApp" class="appearance-app"></div>'; },
        ]);
        $rcmail->output->set_pagetitle($this->gettext('appearance'));
        $rcmail->output->send('plugin');
    }

    public function action_pin_save()
    {
        $this->require_post();
        $current = $this->pin();
        $code = (string) rcube_utils::get_input_string('_pin', rcube_utils::INPUT_POST);
        $len = (int) rcube_utils::get_input_string('_len', rcube_utils::INPUT_POST);
        $hint = trim((string) rcube_utils::get_input_string('_hint', rcube_utils::INPUT_POST));
        $interval = (string) rcube_utils::get_input_string('_interval', rcube_utils::INPUT_POST);

        // changing an existing passcode (or only its settings) needs the current one; a first one a fresh step-up
        if ($current && !$this->current_pin_ok($current)) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_current')]);
        }
        if (!$current) {
            $this->require_stepup();
        }
        if (!in_array($interval, self::PIN_INTERVALS, true)) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_interval')]);
        }
        if ($code === '' && $current) {
            $new = ['hash' => $current['hash'], 'len' => $current['len']];
        } elseif (in_array($len, [4, 6], true) && preg_match('/^\d{' . $len . '}$/', $code)) {
            $new = ['hash' => password_hash($code, \PASSWORD_DEFAULT), 'len' => $len];
        } else {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_digits')]);
        }
        if ($hint === '' || mb_strlen($hint) > 120) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_hint')]);
        }
        if ($code !== '' && strpos($hint, $code) !== false) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_hintcode')]);
        }

        $this->save_prefs(['synapse_pin' => $new + ['hint' => $hint, 'interval' => $interval, 'set_at' => time()], 'synapse_pin_fails' => 0]);
        $_SESSION['synapse_pin_ok_at'] = time();
        if ($interval !== 'login') {
            $this->set_device_cookie((int) $interval * 86400);
        }
        $this->json(['ok' => true]);
    }

    public function action_pin_remove()
    {
        $this->require_post();
        $current = $this->pin();
        if (!$current || !$this->current_pin_ok($current)) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_current')]);
        }
        $this->save_prefs(['synapse_pin' => null, 'synapse_pin_prompt' => ['skips' => 0, 'next' => time() + self::PROMPT_EVERY_SECONDS, 'off' => false]]);
        $this->json(['ok' => true]);
    }

    /** Settings: the current passcode (_current), counted like gate attempts so it cannot be guessed here instead */
    private function current_pin_ok(array $pin)
    {
        $rcmail = rcmail::get_instance();
        $ip = ['pinip' => rcube_utils::remote_addr()];
        if ($this->rl_take($ip)) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_netlimit')]);
        }
        if (!$this->take_attempt('synapse_pin_fails')) {
            $this->rl_settle($ip, true);
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $this->locked_until() ?: null, 'message' => $this->gettext('pin_locked')]);
        }
        if (password_verify((string) rcube_utils::get_input_string('_current', rcube_utils::INPUT_POST), $pin['hash'])) {
            $this->rl_settle($ip, true);
            $this->give_back_attempt('synapse_pin_fails');

            return true;
        }

        $this->rl_settle($ip, false);
        if (!($until = $this->lock_if_full('synapse_pin_fails'))) {
            return false;
        }
        $this->security_log('passcode locked after wrong current passcodes in Settings for ' . $rcmail->get_user_name());
        $rcmail->kill_session();
        $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $until, 'message' => $this->gettext('pin_locked')]);
    }

    /* ---------------- recommendation widget ---------------- */

    private function prompt_state()
    {
        if ($this->pin() || $this->locked_until() > time()) {
            return null;
        }
        $p = (array) rcmail::get_instance()->config->get('synapse_pin_prompt', []);
        if (!empty($p['off']) || (int) ($p['next'] ?? 0) > time()) {
            return null;
        }
        $skips = (int) ($p['skips'] ?? 0);

        return ['skips' => $skips, 'last' => $skips >= self::PROMPT_MAX_SKIPS - 1];
    }

    public function action_pin_prompt()
    {
        $this->require_post();
        $p = (array) rcmail::get_instance()->config->get('synapse_pin_prompt', []);
        $skips = (int) ($p['skips'] ?? 0) + 1;
        $this->save_prefs(['synapse_pin_prompt' => ['skips' => $skips, 'next' => time() + self::PROMPT_EVERY_SECONDS, 'off' => $skips >= self::PROMPT_MAX_SKIPS]]);
        $this->json(['ok' => true, 'off' => $skips >= self::PROMPT_MAX_SKIPS]);
    }

    /* ---------------- helpers ---------------- */

    private function pin()
    {
        $pin = rcmail::get_instance()->config->get('synapse_pin');

        return is_array($pin) && !empty($pin['hash']) ? $pin + ['interval' => 'login', 'len' => 6, 'hint' => ''] : null;
    }

    private function locked_until()
    {
        return (int) rcmail::get_instance()->config->get('synapse_pin_lock', 0);
    }

    /**
     * What the session still owes, in order: account lock, authenticator enrolment / code, passcode.
     * factor: 'pin' | 'otp' | 'enroll'
     */
    private function gate_info()
    {
        $pin_state = $_SESSION['synapse_pin_state'] ?? null;
        $otp_state = $_SESSION['synapse_otp_state'] ?? null;
        $pin = $this->pin();

        $factor = synapse_gate::next_factor($pin_state, $otp_state);
        if ($factor === 'otp' || $factor === 'enroll') {
            return [
                'state' => 'pending',
                'factor' => $factor,
                'len' => synapse_totp::DIGITS,
                'locked_until' => null,
                'tries_left' => self::OTP_TRIES_PER_SESSION - (int) ($_SESSION['synapse_otp_tries'] ?? 0),
            ];
        }

        return [
            'state' => $pin_state,
            'factor' => 'pin',
            'len' => (int) ($pin['len'] ?? 6),
            'locked_until' => $pin_state == 'locked' ? $this->locked_until() : null,
            'tries_left' => self::PIN_TRIES_PER_SESSION - (int) ($_SESSION['synapse_pin_tries'] ?? 0),
        ];
    }

    /** gate info for the next step, or null when the session is fully unlocked */
    private function next_gate()
    {
        $info = $this->gate_info();

        return $info['state'] ? $info + ['token' => rcmail::get_instance()->get_request_token()] : null;
    }

    /**
     * After a gate step succeeded: the next step, or null when the gate is fully passed. Then the session gets a new
     * id (the old one, known before the gate, stops working) and a new auth cookie.
     */
    private function gate_step_done()
    {
        $next = $this->next_gate();
        if (!$next) {
            $rcmail = rcmail::get_instance();
            $rcmail->session->regenerate_id(true);
            $rcmail->session->set_auth_cookie();
        }

        return $next;
    }

    /* ---------------- step-up: confirm it's you before changing a sign-in factor ---------------- */

    /** Stops the request unless the session confirmed the password or an authenticator code a moment ago */
    private function require_stepup()
    {
        if (!synapse_gate::stepup_valid($_SESSION['synapse_stepup_until'] ?? 0, time())) {
            $this->json(['ok' => false, 'stepup' => true, 'message' => $this->gettext('stepup_needed')]);
        }
    }

    /**
     * Settings: _secret is the mailbox password this session signed in with, or (when 2FA is on) a current
     * authenticator code. Opens a short window for setting a first passcode or enrolling an authenticator.
     */
    public function action_stepup()
    {
        $rcmail = rcmail::get_instance();
        $this->require_post();
        if (!empty($_SESSION['synapse_otp_state']) || !empty($_SESSION['synapse_pin_state'])) {
            $this->json(['ok' => false, 'message' => $this->gettext('finishsignin')], 403);
        }
        $user = mb_strtolower((string) $rcmail->get_user_name());
        $keys = ['stepup' => $user];
        if ($wait = $this->rl_take($keys)) {
            $this->json(['ok' => false, 'message' => $this->gettext(['name' => 'stepup_limit', 'vars' => ['n' => max(1, (int) ceil($wait / 60))]])]);
        }

        $given = (string) rcube_utils::get_input_string('_secret', rcube_utils::INPUT_POST, true);
        $ok = synapse_gate::password_matches($rcmail->decrypt((string) ($_SESSION['password'] ?? '')), $given);
        if (!$ok && $this->otp() && preg_match('/^\d{3}\s?\d{3}$/', trim($given))) {
            // a time step is used up atomically, like at sign-in
            $used = false;
            $ok = $this->otp_consume($given, $used) !== null;
        }
        $this->rl_settle($keys, $ok);
        if (!$ok) {
            $this->security_log('security change not confirmed (wrong password or code) for ' . $user);
            $this->json(['ok' => false, 'message' => $this->gettext('stepup_wrong')]);
        }

        $_SESSION['synapse_stepup_until'] = time() + synapse_gate::STEPUP_SECONDS;
        $this->json(['ok' => true, 'until' => $_SESSION['synapse_stepup_until']]);
    }

    /** Save security preferences, merged into the stored ones as one compare-and-swap (no lost parallel writes) */
    private function save_prefs(array $prefs)
    {
        return $this->update_prefs(static function () use ($prefs) { return $prefs; }) !== null;
    }

    /**
     * Read-modify-write of the user's preferences without races: $change gets the stored preferences and returns
     * the keys to set (null removes one), or null to change nothing. Returns the changes, or null.
     */
    private function update_prefs(callable $change)
    {
        $rcmail = rcmail::get_instance();
        $db = $rcmail->get_dbh();
        $result = synapse_atomic::prefs($db, $db->table_name('users', true), $rcmail->user->ID, $change);
        if (!$result) {
            return null;
        }
        [$changes, $serialized] = $result;
        // this request continues with the new state (rcube_user rebuilds its cache from data)
        $rcmail->user->data['preferences'] = $serialized;
        $rcmail->user->prefs = null;
        foreach ($changes as $k => $v) {
            $rcmail->config->set($k, $v);
        }

        return $changes;
    }

    /**
     * Account-wide attempt for a passcode or authenticator code, taken before the check: refused while the account
     * is locked or while parallel requests already hold every attempt left before the lock.
     */
    private function take_attempt($counter)
    {
        $limit = $counter === 'synapse_otp_fails' ? self::OTP_FAILS_BEFORE_LOCK : self::PIN_FAILS_BEFORE_LOCK;

        return $this->update_prefs(static function (array $p) use ($counter, $limit) {
            if ((int) ($p['synapse_pin_lock'] ?? 0) > time() || (int) ($p[$counter] ?? 0) >= $limit) {
                return null;
            }

            return [$counter => (int) ($p[$counter] ?? 0) + 1];
        }) !== null;
    }

    /** After a correct code: the attempt taken for it no longer counts */
    private function give_back_attempt($counter)
    {
        $this->update_prefs(static function (array $p) use ($counter) {
            return (int) ($p[$counter] ?? 0) > 0 ? [$counter => (int) $p[$counter] - 1] : [];
        });
    }

    /** After a wrong code (the attempt is already counted): lock the account when the counter reached the limit */
    private function lock_if_full($counter)
    {
        $limit = $counter === 'synapse_otp_fails' ? self::OTP_FAILS_BEFORE_LOCK : self::PIN_FAILS_BEFORE_LOCK;
        $changes = $this->update_prefs(static function (array $p) use ($counter, $limit) {
            return (int) ($p[$counter] ?? 0) >= $limit ? [$counter => 0, 'synapse_pin_lock' => time() + self::PIN_FAIL_LOCK_SECONDS] : [];
        });

        return (int) ($changes['synapse_pin_lock'] ?? 0);
    }

    private function device_sig($uid, $ts)
    {
        return hash_hmac('sha256', $uid . '|' . $ts, rcmail::get_instance()->config->get_crypto_key('des_key'));
    }

    private function set_device_cookie($ttl)
    {
        $uid = rcmail::get_instance()->user->ID;
        $ts = time();
        rcube_utils::setcookie(self::DEVICE_COOKIE, $uid . '.' . $ts . '.' . $this->device_sig($uid, $ts), $ts + $ttl);
    }

    private function device_verified_within($seconds)
    {
        $parts = explode('.', (string) ($_COOKIE[self::DEVICE_COOKIE] ?? ''));
        if (count($parts) != 3) {
            return false;
        }
        [$uid, $ts, $sig] = $parts;

        return $uid == rcmail::get_instance()->user->ID && (int) $ts > time() - $seconds
            && hash_equals($this->device_sig($uid, $ts), $sig);
    }

    /* ---------------- authenticator (TOTP) 2FA ---------------- */

    /** Gate step: authenticator code or one-time recovery code */
    public function action_otp_verify()
    {
        $rcmail = rcmail::get_instance();
        $this->require_post();

        if (($_SESSION['synapse_otp_state'] ?? null) !== 'pending' || !($otp = $this->otp())) {
            $this->json(['ok' => empty($_SESSION['synapse_otp_state']), 'next' => $this->next_gate()]);
        }

        // attempts are taken before the check (network and account), so parallel requests get no extra guesses
        $ip = ['otpip' => rcube_utils::remote_addr()];
        if ($this->rl_take($ip)) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'message' => $this->gettext('otp_netlimit')]);
        }
        if (!$this->take_attempt('synapse_otp_fails')) {
            $this->rl_settle($ip, true);
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $this->locked_until() ?: null, 'message' => $this->gettext('otp_locked')]);
        }

        // the code is used up in the same atomic write that accepts it: one time step or recovery code, one sign-in
        $used_recovery = false;
        $new = $this->otp_consume(rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST), $used_recovery,
            static function (array $otp) { return ['synapse_otp' => $otp, 'synapse_otp_fails' => 0]; });
        if ($new) {
            $this->rl_settle($ip, true);
            $_SESSION['synapse_otp_state'] = null; $_SESSION['synapse_otp_tries'] = null;
            $this->json(['ok' => true, 'recovery_used' => $used_recovery, 'recovery_left' => count($new['recovery']), 'next' => $this->gate_step_done()]);
        }

        $this->rl_settle($ip, false);
        $tries = (int) ($_SESSION['synapse_otp_tries'] ?? 0) + 1;
        $_SESSION['synapse_otp_tries'] = $tries;
        // same account lock as the passcode: many wrong codes across sign-ins lock the account for an hour
        $lock = $this->lock_if_full('synapse_otp_fails');

        if ($lock || $tries >= self::OTP_TRIES_PER_SESSION) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $lock ?: null,
                'message' => $lock ? $this->gettext('otp_locked') : $this->gettext('otp_signin')]);
        }

        $this->json(['ok' => false, 'left' => self::OTP_TRIES_PER_SESSION - $tries, 'message' => $this->gettext('otp_wrong')]);
    }

    /**
     * Turn on 2FA: _do=start returns a new secret (kept encrypted in the session until confirmed),
     * _do=confirm checks a code from the app and returns the recovery codes (shown once).
     * Works from Settings, and from the gate when the policy requires enrolment.
     */
    public function action_otp_enroll()
    {
        $rcmail = rcmail::get_instance();
        $this->require_post();
        // in the gate only as the last step (after the passcode); from Settings only after a fresh step-up
        $gate = synapse_gate::enroll_step($_SESSION['synapse_pin_state'] ?? null, $_SESSION['synapse_otp_state'] ?? null);

        if (!$gate && (!empty($_SESSION['synapse_otp_state']) || !empty($_SESSION['synapse_pin_state']))) {
            $this->json(['ok' => false, 'message' => $this->gettext('finishsignin')], 403);
        }
        if ($this->otp_policy() === 'off') {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_serveroff')]);
        }
        if (!$gate && $this->otp()) {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_already')]);
        }

        if (rcube_utils::get_input_string('_do', rcube_utils::INPUT_POST) === 'start') {
            if (!$gate) {
                $this->require_stepup();
            }
            $secret = synapse_totp::secret();
            $_SESSION['synapse_otp_new'] = synapse_secret::encrypt($secret, $this->install_key());
            $this->json(['ok' => true, 'secret' => $secret, 'uri' => synapse_totp::uri('SynapseMail', (string) $rcmail->get_user_name(), $secret)]);
        }

        $ip = rcube_utils::remote_addr();
        if ($this->rl_blocked(['otpip' => $ip])) {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_netlimit')]);
        }
        $enc = $_SESSION['synapse_otp_new'] ?? null;
        $secret = $enc ? synapse_secret::decrypt($enc, $this->install_key()) : null;
        if (!$secret) {
            $this->json(['ok' => false, 'restart' => true, 'message' => $this->gettext('otp_expired')]);
        }
        $code = rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST);
        $step = self::demo_code($code) ? intdiv(time(), 30) : synapse_totp::verify($secret, $code);
        if ($step === false) {
            $this->rl_fail(['otpip' => $ip]);
            $this->json(['ok' => false, 'message' => $this->gettext('otp_invalidtime')]);
        }

        $codes = synapse_totp::recovery_codes();
        $record = ['secret' => $enc, 'recovery' => self::hash_codes($codes), 'enabled_at' => time(), 'last_step' => $step];
        // stored only while the account has no authenticator: of two parallel confirmations exactly one enrols
        $saved = $this->update_prefs(static function (array $p) use ($record) {
            return empty($p['synapse_otp']['secret']) ? ['synapse_otp' => $record, 'synapse_otp_fails' => 0] : null;
        });
        $_SESSION['synapse_otp_new'] = null;
        if (!$saved) {
            if ($gate) {
                // enrolled by a parallel request: this session now needs a code from that authenticator
                $_SESSION['synapse_otp_state'] = 'pending';
            }
            $this->json(['ok' => false, 'restart' => !$gate, 'next' => $gate ? $this->next_gate() : null, 'message' => $this->gettext('otp_already')]);
        }
        if ($gate) {
            $_SESSION['synapse_otp_state'] = null;
        }

        $this->json(['ok' => true, 'codes' => $codes, 'next' => $gate ? $this->gate_step_done() : null]);
    }

    /** Settings: new set of recovery codes (needs a current authenticator or recovery code) */
    public function action_otp_recovery()
    {
        $ip = $this->otp_settings_guard();
        $used = false;
        $codes = synapse_totp::recovery_codes();
        $hashes = self::hash_codes($codes);
        // the code is checked and the new set stored in one atomic write
        if (!$this->otp_consume(rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST), $used,
            static function (array $otp) use ($hashes) { return ['synapse_otp' => ['recovery' => $hashes] + $otp]; })
        ) {
            $this->rl_settle($ip, false);
            $this->json(['ok' => false, 'message' => $this->gettext('otp_invalid')]);
        }
        $this->rl_settle($ip, true);
        $this->json(['ok' => true, 'codes' => $codes]);
    }

    /** Settings: turn 2FA off (not allowed while the server requires it) */
    public function action_otp_disable()
    {
        if ($this->otp_policy() === 'required') {
            $this->require_post();
            $this->json(['ok' => false, 'message' => $this->gettext('otp_required')]);
        }
        $ip = $this->otp_settings_guard();
        $used = false;
        if (!$this->otp_consume(rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST), $used,
            static function () { return ['synapse_otp' => null, 'synapse_otp_fails' => 0]; })
        ) {
            $this->rl_settle($ip, false);
            $this->json(['ok' => false, 'message' => $this->gettext('otp_invalid')]);
        }
        $this->rl_settle($ip, true);
        $this->json(['ok' => true]);
    }

    private function otp_settings_guard()
    {
        $this->require_post();
        if (!empty($_SESSION['synapse_otp_state']) || !empty($_SESSION['synapse_pin_state'])) {
            $this->json(['ok' => false, 'message' => $this->gettext('finishsignin')], 403);
        }
        if (!$this->otp()) {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_notenabled')]);
        }
        // one attempt taken before the code is checked; the caller settles it
        $ip = ['otpip' => rcube_utils::remote_addr()];
        if ($this->rl_take($ip)) {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_netlimit')]);
        }

        return $ip;
    }

    /** Public demo only ($config['synapse_demo'], set by the demo build): the fixed code 123456 */
    private static function demo_code($code)
    {
        return rcmail::get_instance()->config->get('synapse_demo') === true && preg_replace('/\s+/', '', (string) $code) === '123456';
    }

    private function otp_policy()
    {
        $policy = self::setting('2fa_policy') ?? rcmail::get_instance()->config->get('synapse_2fa_policy', 'optional');

        return in_array($policy, self::OTP_POLICIES, true) ? $policy : 'optional';
    }

    private function otp()
    {
        $otp = rcmail::get_instance()->config->get('synapse_otp');

        return is_array($otp) && !empty($otp['secret']) ? $otp + ['recovery' => [], 'last_step' => 0] : null;
    }

    private function install_key()
    {
        return rcmail::get_instance()->config->get_crypto_key('des_key');
    }

    private static function hash_codes(array $codes)
    {
        return array_map(static function ($c) { return password_hash($c, PASSWORD_DEFAULT); }, $codes);
    }

    /**
     * Accept an authenticator or recovery code and use it up in one compare-and-swap on the stored preferences:
     * the code is checked against the stored record, and two parallel requests with one code cannot both win.
     * $then(updated record) returns the preferences to save (default: the record). Returns the record, or null.
     */
    private function otp_consume($code, &$used_recovery, ?callable $then = null)
    {
        $accepted = null;
        $used = false;
        $saved = $this->update_prefs(function (array $p) use ($code, $then, &$accepted, &$used) {
            $otp = $p['synapse_otp'] ?? null;
            $used = false;
            $accepted = null;
            if (!is_array($otp) || empty($otp['secret']) || !($new = $this->otp_check($otp + ['recovery' => [], 'last_step' => 0], $code, $used))) {
                return null;
            }
            $accepted = $new;

            return $then ? $then($new) : ['synapse_otp' => $new];
        });
        $used_recovery = $saved !== null && $used;

        return $saved !== null ? $accepted : null;
    }

    /**
     * Check an authenticator code (refusing re-use of an already accepted time step) or a recovery code
     * (consumed on use). Returns the updated record to save, or null.
     */
    private function otp_check(array $otp, $code, &$used_recovery)
    {
        $code = strtoupper(trim((string) $code));
        if (self::demo_code($code)) {
            return $otp;
        }

        if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
            $secret = synapse_secret::decrypt($otp['secret'], $this->install_key());
            $step = $secret ? synapse_totp::verify($secret, $code) : false;
            if ($step === false || $step <= (int) $otp['last_step']) {
                return null;
            }
            $otp['last_step'] = $step;

            return $otp;
        }

        $plain = preg_replace('/[^A-Z0-9]/', '', $code);
        if (strlen($plain) !== 8) {
            return null;
        }
        $plain = substr($plain, 0, 4) . '-' . substr($plain, 4);
        foreach ((array) $otp['recovery'] as $i => $hash) {
            if (password_verify($plain, $hash)) {
                unset($otp['recovery'][$i]);
                $otp['recovery'] = array_values($otp['recovery']);
                $used_recovery = true;

                return $otp;
            }
        }

        return null;
    }

    /* ---------------- security headers ---------------- */

    /**
     * Lock the app to its own origin. Roundcube core needs inline scripts, inline event handlers and eval()
     * (server responses carry JS commands), so script-src keeps 'unsafe-inline' 'unsafe-eval'; everything
     * else allows only this origin. Remote images stay possible (https:) because users may allow them in mail.
     * No HSTS here: in the shared edition the host name is the customer's website, not ours to pin.
     */
    private function security_headers()
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }

        header('Content-Security-Policy: ' . implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "media-src 'self' data: blob:",
            "frame-src 'self' blob: data:",
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin');
    }

    /* ---------------- sign-in rate limiting ---------------- */

    /** After a successful password change: new session id (the old one was issued under the old password) */
    public function password_changed($args)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->session->regenerate_id(false);
        $rcmail->session->set_auth_cookie();
        self::security_log('password changed for ' . $rcmail->get_user_name());

        return $args;
    }

    /** Sign-in: one attempt is taken on every limit before core checks the password (no extra parallel guesses) */
    public function authenticate($args)
    {
        $keys = $this->rl_login_keys($args['user'] ?? '');
        if ($this->rl_take($keys)) {
            $args['abort'] = true;
            $args['error'] = rcmail::ERROR_RATE_LIMIT;
        } else {
            $this->rl_login_taken = $keys;
        }

        return $args;
    }

    public function login_failed($args)
    {
        // only real credential failures count (not CSRF/cookie problems); refused requests took nothing
        if ($this->rl_login_taken) {
            $this->rl_settle($this->rl_login_taken, in_array($args['code'], [rcmail::ERROR_INVALID_REQUEST, rcmail::ERROR_COOKIES_DISABLED], true));
            $this->rl_login_taken = null;
        }

        return $args;
    }

    private function rl_login_keys($user)
    {
        $user = mb_strtolower(trim((string) $user));
        $ip = rcube_utils::remote_addr();
        $keys = ['ip' => $ip];
        if ($user !== '') {
            $keys['uip'] = $user . '|' . $ip;
            $keys['user'] = $user;
        }

        return $keys;
    }

    private static function rl_db()
    {
        static $ready = false;
        $db = rcube::get_instance()->get_dbh();
        if (!$ready) {
            $db->query('CREATE TABLE IF NOT EXISTS ' . $db->table_name('synapse_ratelimit', true)
                . ' (`k` varchar(64) NOT NULL PRIMARY KEY, `fails` integer NOT NULL DEFAULT 0,'
                . ' `since` integer NOT NULL DEFAULT 0, `blocked_until` integer NOT NULL DEFAULT 0)');
            $ready = true;
        }

        return $db;
    }

    /** Server-wide settings changed in the admin panel (table synapse_settings); null = not set, use config */
    public static function setting($name)
    {
        static $all = null;
        if ($all === null) {
            $all = [];
            $db = self::settings_db();
            $res = $db->query('SELECT `k`, `v` FROM ' . $db->table_name('synapse_settings', true));
            while ($res && ($row = $db->fetch_assoc($res))) {
                $all[$row['k']] = $row['v'];
            }
        }

        return $all[$name] ?? null;
    }

    public static function set_setting($name, $value)
    {
        $db = self::settings_db();
        $table = $db->table_name('synapse_settings', true);
        $db->query("DELETE FROM {$table} WHERE `k` = ?", $name);
        $db->query("INSERT INTO {$table} (`k`, `v`) VALUES (?, ?)", $name, (string) $value);
    }

    private static function settings_db()
    {
        static $ready = false;
        $db = rcube::get_instance()->get_dbh();
        if (!$ready) {
            $db->query('CREATE TABLE IF NOT EXISTS ' . $db->table_name('synapse_settings', true) . ' (`k` varchar(64) NOT NULL PRIMARY KEY, `v` text NOT NULL)');
            $ready = true;
        }

        return $db;
    }

    private static function rl_key($scope, $value)
    {
        // hashed: the table never stores addresses or IPs in clear
        return hash('sha256', $scope . '|' . $value);
    }

    /** @return int seconds until the longest active block ends (0 = not blocked) */
    public static function rl_blocked(array $keys)
    {
        $db = self::rl_db();
        $now = time();
        $wait = 0;
        foreach ($keys as $scope => $value) {
            $res = $db->query('SELECT `blocked_until` FROM ' . $db->table_name('synapse_ratelimit', true) . ' WHERE `k` = ?', self::rl_key($scope, $value));
            if ($row = $db->fetch_assoc($res)) {
                $wait = max($wait, (int) $row['blocked_until'] - $now);
            }
        }

        return $wait;
    }

    /** Count a failure on each key (one atomic statement each); a key blocks when it reaches its limit */
    public static function rl_fail(array $keys)
    {
        $db = self::rl_db();
        $table = $db->table_name('synapse_ratelimit', true);
        $now = time();
        foreach ($keys as $scope => $value) {
            synapse_atomic::rl_fail($db, $table, self::rl_key($scope, $value), $now, self::RL_WINDOW, self::RL_LIMITS[$scope], self::RL_BLOCK_SECONDS);
        }

        // occasional cleanup of expired rows
        if (random_int(1, 50) === 1) {
            $db->query("DELETE FROM {$table} WHERE `blocked_until` < ? AND `since` < ?", $now, $now - self::RL_WINDOW);
        }
    }

    /**
     * Before checking a guess: take one attempt on every key. Returns 0 when taken, else the seconds to wait (the key
     * is blocked, or parallel requests hold every attempt the limit allows). Settle with rl_settle() afterwards.
     */
    public static function rl_take(array $keys)
    {
        $db = self::rl_db();
        $table = $db->table_name('synapse_ratelimit', true);
        $now = time();
        $taken = [];
        foreach ($keys as $scope => $value) {
            $k = self::rl_key($scope, $value);
            if (!synapse_atomic::rl_take($db, $table, $k, $now, self::RL_WINDOW, self::RL_LIMITS[$scope])) {
                // give back what this request already took
                foreach ($taken as $t) {
                    synapse_atomic::rl_settle($db, $table, $t, true, $now, 0, 0);
                }

                return max(1, synapse_atomic::rl_wait($db, $table, $k, $now));
            }
            $taken[] = $k;
        }

        return 0;
    }

    /** After rl_take(): a correct guess gives the attempts back, a wrong one keeps them (and blocks at the limit) */
    public static function rl_settle(array $keys, $ok)
    {
        $db = self::rl_db();
        $table = $db->table_name('synapse_ratelimit', true);
        foreach ($keys as $scope => $value) {
            synapse_atomic::rl_settle($db, $table, self::rl_key($scope, $value), (bool) $ok, time(), self::RL_LIMITS[$scope], self::RL_BLOCK_SECONDS);
        }
    }

    public static function rl_clear($scope, $value)
    {
        $db = self::rl_db();
        $db->query('DELETE FROM ' . $db->table_name('synapse_ratelimit', true) . ' WHERE `k` = ?', self::rl_key($scope, $value));
    }

    /** One line in logs/synapse_security.log (never passwords or codes) */
    public static function security_log($message)
    {
        rcube::write_log('synapse_security', self::log_safe($message . ' from ' . rcube_utils::remote_ip()));
    }

    /**
     * Text for a log line: line breaks, other control characters, Unicode line separators and direction marks are
     * written escaped (\\x0a), so a user name, host or message can never start a new (forged) entry.
     */
    public static function log_safe($text)
    {
        return preg_replace_callback('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8E\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]/',
            static function ($m) { return '\\x' . bin2hex($m[0]); }, (string) $text);
    }

    /**
     * State-changing and outward-connecting actions: POST with this session's request token (header or _token).
     * The token is required explicitly, because core's check lets an empty POST through.
     */
    public function require_post()
    {
        $rcmail = rcmail::get_instance();
        $given = (string) (rcube_utils::request_header('X-Roundcube-Request') ?: ($_POST['_token'] ?? ''));
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $given === '' || !hash_equals((string) $rcmail->get_request_token(), $given)
            || !$rcmail->check_request(rcube_utils::INPUT_POST)
        ) {
            $this->json(['ok' => false, 'message' => $this->gettext('invalidrequest')], 403);
        }
    }

    public function json(array $data, $status = 200)
    {
        while (ob_get_level()) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
        exit;
    }
}
