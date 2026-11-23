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

class synapse_core extends rcube_plugin
{
    // bump on every release (3.x from the first public release)
    public const VERSION = '3.4';
    public const THEMES = ['warfare', 'blackops', 'night', 'agency'];

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
        'pwuser' => 5, // password change attempts per account (protects against guessing the current password)
        'sadetect' => 30, // Send As: settings lookups per user
        'satest' => 15, // Send As: failed server tests per user (each one connects to another server)
        'sacode' => 5, // Send As: code mails per user
    ];

    // the skin saves the chosen theme with rcmail.save_pref() (core reads this before init())
    public $allowed_prefs = ['synapse_theme'];

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

        foreach (['gate', 'pin-status', 'pin-verify', 'pin-forgot', 'pin-save', 'pin-remove', 'pin-prompt', 'security',
            'otp-verify', 'otp-enroll', 'otp-recovery', 'otp-disable'] as $action) {
            $this->register_action('plugin.synapse-' . $action, [$this, 'action_' . str_replace('-', '_', $action)]);
        }
        require_once __DIR__ . '/lib/synapse_sendas.php';
        (new synapse_sendas($this))->init();

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
        $this->rl_clear('uip', mb_strtolower((string) $rcmail->get_user_name()) . '|' . rcube_utils::remote_ip());

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

        if (empty($_SESSION['user_id']) || in_array($args['task'], ['login', 'logout'])) {
            return $args;
        }

        // password change (password plugin): limit attempts per account and log them
        if ($args['task'] === 'settings' && $args['action'] === 'plugin.password-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $user = mb_strtolower((string) $rcmail->get_user_name());
            if ($wait = $this->rl_blocked(['pwuser' => $user])) {
                rcube::write_log('synapse_security', sprintf('password change refused (rate limit) for %s from %s', $user, rcube_utils::remote_ip()));
                $rcmail->output->show_message($this->gettext(['name' => 'pwratelimit', 'vars' => ['n' => max(1, (int) ceil($wait / 60))]]), 'error');
                $args['action'] = 'plugin.password';
            } else {
                $this->rl_fail(['pwuser' => $user]);
                rcube::write_log('synapse_security', sprintf('password change attempt for %s from %s', $user, rcube_utils::remote_ip()));
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

        $ip = rcube_utils::remote_ip();
        if ($this->rl_blocked(['pinip' => $ip])) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'message' => $this->gettext('pin_netlimit')]);
        }

        $code = rcube_utils::get_input_string('_pin', rcube_utils::INPUT_POST);

        if (preg_match('/^\d{4,6}$/', $code) && password_verify($code, $pin['hash'])) {
            $_SESSION['synapse_pin_state'] = null; $_SESSION['synapse_pin_tries'] = null;
            $_SESSION['synapse_pin_ok_at'] = time();
            $this->save_prefs(['synapse_pin_fails' => 0]);
            if ($pin['interval'] !== 'login') {
                $this->set_device_cookie((int) $pin['interval'] * 86400);
            }
            $this->json(['ok' => true, 'next' => $this->next_gate()]);
        }

        $this->rl_fail(['pinip' => $ip]);
        $tries = ++$_SESSION['synapse_pin_tries'];
        $fails = (int) $rcmail->config->get('synapse_pin_fails', 0) + 1;
        $prefs = ['synapse_pin_fails' => $fails];

        // too many wrong codes on this account (across sign-ins): temporary lock
        if ($fails >= self::PIN_FAILS_BEFORE_LOCK) {
            $prefs['synapse_pin_fails'] = 0;
            $prefs['synapse_pin_lock'] = time() + self::PIN_FAIL_LOCK_SECONDS;
        }
        $this->save_prefs($prefs);

        if (!empty($prefs['synapse_pin_lock']) || $tries >= self::PIN_TRIES_PER_SESSION) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $prefs['synapse_pin_lock'] ?? null,
                'message' => !empty($prefs['synapse_pin_lock']) ? $this->gettext('pin_locked') : $this->gettext('pin_signin')]);
        }

        $this->json(['ok' => false, 'left' => self::PIN_TRIES_PER_SESSION - $tries, 'message' => $this->gettext('pin_wrong')]);
    }

    public function action_pin_forgot()
    {
        $rcmail = rcmail::get_instance();
        $this->require_post();
        $pin = $this->pin();

        if (($_SESSION['synapse_pin_state'] ?? null) !== 'pending' || !$pin) {
            $this->json(['ok' => false]);
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

    public function action_pin_save()
    {
        $this->require_post();
        $current = $this->pin();
        $code = (string) rcube_utils::get_input_string('_pin', rcube_utils::INPUT_POST);
        $len = (int) rcube_utils::get_input_string('_len', rcube_utils::INPUT_POST);
        $hint = trim((string) rcube_utils::get_input_string('_hint', rcube_utils::INPUT_POST));
        $interval = (string) rcube_utils::get_input_string('_interval', rcube_utils::INPUT_POST);

        // changing an existing passcode (or only its settings) needs the current one
        if ($current && !password_verify((string) rcube_utils::get_input_string('_current', rcube_utils::INPUT_POST), $current['hash'])) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_current')]);
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
        if (!$current || !password_verify((string) rcube_utils::get_input_string('_current', rcube_utils::INPUT_POST), $current['hash'])) {
            $this->json(['ok' => false, 'message' => $this->gettext('pin_current')]);
        }
        $this->save_prefs(['synapse_pin' => null, 'synapse_pin_prompt' => ['skips' => 0, 'next' => time() + self::PROMPT_EVERY_SECONDS, 'off' => false]]);
        $this->json(['ok' => true]);
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

        if ($pin_state !== 'locked' && $otp_state) {
            return [
                'state' => 'pending',
                'factor' => $otp_state === 'enroll' ? 'enroll' : 'otp',
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

    private function save_prefs(array $prefs)
    {
        $rcmail = rcmail::get_instance();
        $rcmail->user->save_prefs($prefs);
        foreach ($prefs as $k => $v) {
            $rcmail->config->set($k, $v);
        }
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

        $ip = rcube_utils::remote_ip();
        if ($this->rl_blocked(['otpip' => $ip])) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'message' => $this->gettext('otp_netlimit')]);
        }

        $used_recovery = false;
        if ($new = $this->otp_check($otp, rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST), $used_recovery)) {
            $_SESSION['synapse_otp_state'] = null; $_SESSION['synapse_otp_tries'] = null;
            $this->save_prefs(['synapse_otp' => $new, 'synapse_otp_fails' => 0]);
            $this->json(['ok' => true, 'recovery_used' => $used_recovery, 'recovery_left' => count($new['recovery']), 'next' => $this->next_gate()]);
        }

        $this->rl_fail(['otpip' => $ip]);
        $tries = (int) ($_SESSION['synapse_otp_tries'] ?? 0) + 1;
        $_SESSION['synapse_otp_tries'] = $tries;
        $fails = (int) $rcmail->config->get('synapse_otp_fails', 0) + 1;
        $prefs = ['synapse_otp_fails' => $fails];
        // same account lock as the passcode: many wrong codes across sign-ins lock the account for an hour
        if ($fails >= self::OTP_FAILS_BEFORE_LOCK) {
            $prefs['synapse_otp_fails'] = 0;
            $prefs['synapse_pin_lock'] = time() + self::PIN_FAIL_LOCK_SECONDS;
        }
        $this->save_prefs($prefs);

        if (!empty($prefs['synapse_pin_lock']) || $tries >= self::OTP_TRIES_PER_SESSION) {
            $rcmail->kill_session();
            $this->json(['ok' => false, 'signedout' => true, 'locked_until' => $prefs['synapse_pin_lock'] ?? null,
                'message' => !empty($prefs['synapse_pin_lock']) ? $this->gettext('otp_locked') : $this->gettext('otp_signin')]);
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
        $gate = ($_SESSION['synapse_otp_state'] ?? null) === 'enroll';

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
            $secret = synapse_totp::secret();
            $_SESSION['synapse_otp_new'] = synapse_secret::encrypt($secret, $this->install_key());
            $this->json(['ok' => true, 'secret' => $secret, 'uri' => synapse_totp::uri('SynapseMail', (string) $rcmail->get_user_name(), $secret)]);
        }

        $ip = rcube_utils::remote_ip();
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
        $this->save_prefs([
            'synapse_otp' => ['secret' => $enc, 'recovery' => self::hash_codes($codes), 'enabled_at' => time(), 'last_step' => $step],
            'synapse_otp_fails' => 0,
        ]);
        $_SESSION['synapse_otp_new'] = null;
        if ($gate) {
            $_SESSION['synapse_otp_state'] = null;
        }

        $this->json(['ok' => true, 'codes' => $codes, 'next' => $gate ? $this->next_gate() : null]);
    }

    /** Settings: new set of recovery codes (needs a current authenticator or recovery code) */
    public function action_otp_recovery()
    {
        $otp = $this->otp_settings_guard();
        $used = false;
        if (!($new = $this->otp_check($otp, rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST), $used))) {
            $this->rl_fail(['otpip' => rcube_utils::remote_ip()]);
            $this->json(['ok' => false, 'message' => $this->gettext('otp_invalid')]);
        }
        $codes = synapse_totp::recovery_codes();
        $new['recovery'] = self::hash_codes($codes);
        $this->save_prefs(['synapse_otp' => $new]);
        $this->json(['ok' => true, 'codes' => $codes]);
    }

    /** Settings: turn 2FA off (not allowed while the server requires it) */
    public function action_otp_disable()
    {
        $otp = $this->otp_settings_guard();
        if ($this->otp_policy() === 'required') {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_required')]);
        }
        $used = false;
        if (!$this->otp_check($otp, rcube_utils::get_input_string('_code', rcube_utils::INPUT_POST), $used)) {
            $this->rl_fail(['otpip' => rcube_utils::remote_ip()]);
            $this->json(['ok' => false, 'message' => $this->gettext('otp_invalid')]);
        }
        $this->save_prefs(['synapse_otp' => null, 'synapse_otp_fails' => 0]);
        $this->json(['ok' => true]);
    }

    private function otp_settings_guard()
    {
        $this->require_post();
        if (!empty($_SESSION['synapse_otp_state']) || !empty($_SESSION['synapse_pin_state'])) {
            $this->json(['ok' => false, 'message' => $this->gettext('finishsignin')], 403);
        }
        if (!($otp = $this->otp())) {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_notenabled')]);
        }
        if ($this->rl_blocked(['otpip' => rcube_utils::remote_ip()])) {
            $this->json(['ok' => false, 'message' => $this->gettext('otp_netlimit')]);
        }

        return $otp;
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
        rcube::write_log('synapse_security', sprintf('password changed for %s from %s', $rcmail->get_user_name(), rcube_utils::remote_ip()));

        return $args;
    }

    public function authenticate($args)
    {
        if ($this->rl_blocked($this->rl_login_keys($args['user'] ?? ''))) {
            $args['abort'] = true;
            $args['error'] = rcmail::ERROR_RATE_LIMIT;
        }

        return $args;
    }

    public function login_failed($args)
    {
        // only real credential failures count (not CSRF/cookie problems or requests we already refused)
        if (!in_array($args['code'], [rcmail::ERROR_INVALID_REQUEST, rcmail::ERROR_COOKIES_DISABLED, rcmail::ERROR_RATE_LIMIT], true)) {
            $this->rl_fail($this->rl_login_keys($args['user'] ?? ''));
        }

        return $args;
    }

    private function rl_login_keys($user)
    {
        $user = mb_strtolower(trim((string) $user));
        $ip = rcube_utils::remote_ip();
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

    public static function rl_fail(array $keys)
    {
        $db = self::rl_db();
        $table = $db->table_name('synapse_ratelimit', true);
        $now = time();

        foreach ($keys as $scope => $value) {
            $k = self::rl_key($scope, $value);
            $row = $db->fetch_assoc($db->query("SELECT `fails`, `since` FROM {$table} WHERE `k` = ?", $k));
            $fails = ($row && (int) $row['since'] > $now - self::RL_WINDOW) ? (int) $row['fails'] + 1 : 1;
            $since = $fails == 1 ? $now : (int) $row['since'];
            $blocked = 0;
            if ($fails >= self::RL_LIMITS[$scope]) {
                $blocked = $now + self::RL_BLOCK_SECONDS;
                $fails = 0;
                $since = $now;
            }
            if ($row) {
                $db->query("UPDATE {$table} SET `fails` = ?, `since` = ?, `blocked_until` = CASE WHEN ? > 0 THEN ? ELSE `blocked_until` END WHERE `k` = ?",
                    $fails, $since, $blocked, $blocked, $k);
            } else {
                $db->query("INSERT INTO {$table} (`k`, `fails`, `since`, `blocked_until`) VALUES (?, ?, ?, ?)", $k, $fails, $since, $blocked);
            }
        }

        // occasional cleanup of expired rows
        if (random_int(1, 50) === 1) {
            $db->query("DELETE FROM {$table} WHERE `blocked_until` < ? AND `since` < ?", $now, $now - self::RL_WINDOW);
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
        rcube::write_log('synapse_security', $message . ' from ' . rcube_utils::remote_ip());
    }

    public function require_post()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !rcmail::get_instance()->check_request(rcube_utils::INPUT_POST)) {
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
