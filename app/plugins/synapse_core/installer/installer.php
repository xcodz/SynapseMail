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
 * SynapseMail web installer (shared-hosting edition).
 *
 * Steps: prove ownership (setup.key) → environment → mail server detection → admin account + 2FA → install.
 * Nothing is written until the last step. Afterwards the installer locks itself for good:
 * it refuses to run while config/synapse.inc.php or config/installed.lock exists.
 */

namespace SynapseMail\Installer;

use synapse_platform;
use synapse_probe;
use synapse_secret;
use synapse_totp;

// placeholder in the shipped setup.key; never accepted
const KEY_EXAMPLE = 'SM-XXXX-XXXX-XXXX';
const STEPS = ['own' => 'Ownership', 'env' => 'Environment', 'mail' => 'Mail server', 'admin' => 'Admin & 2FA', 'done' => 'Install'];

$ROOT = realpath(__DIR__ . '/../../..') . '/';
$CFG_FILE = $ROOT . 'config/synapse.inc.php';
$LOCK_FILE = $ROOT . 'config/installed.lock';
$KEY_FILE = $ROOT . 'setup.key';
$DB_FILE = $ROOT . 'db/synapsemail.db';
$CANARY = $ROOT . 'db/install-probe.txt';

require __DIR__ . '/../lib/synapse_totp.php';
require __DIR__ . '/../lib/synapse_secret.php';
require __DIR__ . '/../lib/synapse_probe.php';
require __DIR__ . '/../lib/synapse_platform.php';

/* ---------------- headers ---------------- */

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

/* ---------------- permanent lock ---------------- */

if (is_file($LOCK_FILE) || is_file($CFG_FILE)) {
    http_response_code(403);
    page('Installer locked', '<div class="setup-note"><b>SynapseMail is already installed.</b><p>The installer is locked permanently. To run it again, remove <code>config/installed.lock</code> and <code>config/synapse.inc.php</code> in your file manager (this resets the mail server settings and the admin account; mailbox data stays on the mail server).</p></div><a class="access-button setup-link" href="./"><span>OPEN SYNAPSEMAIL</span><b aria-hidden="true"></b></a>', null);
    exit;
}

/* ---------------- installer session (files in temp/, never web-readable) ---------------- */

ini_set('session.use_strict_mode', '1');
session_save_path($ROOT . 'temp');
session_name('synapse_setup');
// cookie path from the URL, not SCRIPT_NAME: .htaccess maps /mail/x to /mail/public_html/x
session_set_cookie_params(['lifetime' => 0, 'path' => preg_replace('#/[^/]*$#', '/', strtok($_SERVER['REQUEST_URI'] ?? '/', '?')), 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$S = &$_SESSION['synapse_setup'];
if (!is_array($S) || ($S['started'] ?? 0) < time() - 3 * 3600) {
    session_regenerate_id(true);
    $S = [
        'started' => time(),
        'step' => 'own',
        'csrf' => bin2hex(random_bytes(16)),
        'challenge' => 'SM-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(6))), 4)),
    ];
}
$S['panel'] = $S['panel'] ?? synapse_platform::panel($ROOT);

$reached = array_search($S['step'], array_keys(STEPS), true);
$step = $_GET['step'] ?? $S['step'];
if (!isset(STEPS[$step]) || array_search($step, array_keys(STEPS), true) > $reached) {
    $step = $S['step'];
}
$error = null;

/* ---------------- AJAX: mail server probe ---------------- */

if (($_GET['ajax'] ?? '') === 'probe') {
    header('Content-Type: application/json; charset=utf-8');
    if ($reached < 2 || !hash_equals($S['csrf'], (string) ($_GET['t'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    $host = strtolower(trim((string) ($_GET['host'] ?? '')));
    if (!valid_host($host)) {
        echo json_encode(['error' => 'Invalid host name.']);
        exit;
    }
    @set_time_limit(60);
    echo json_encode(['host' => $host, 'results' => [
        'imap_ssl' => probe($host, 993, 'imap', true),
        'imap_tls' => probe($host, 143, 'imap', false),
        'smtp_ssl' => probe($host, 465, 'smtp', true),
        'smtp_tls' => probe($host, 587, 'smtp', false),
    ]]);
    exit;
}

/* ---------------- POST actions ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // each action needs its step reached in this session, so no step (above all Ownership) can be skipped
    $needs = ['env' => 'env', 'mail' => 'mail', 'admin' => 'admin', 'totp' => 'admin', 'admin_reset' => 'admin', 'recovery' => 'admin', 'install' => 'done'];
    $need = $needs[$_POST['do'] ?? ''] ?? null;
    if (!hash_equals($S['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'The form expired. Please try again.';
    } elseif ($need !== null && array_search($need, array_keys(STEPS), true) > $reached) {
        $error = 'Complete the steps in order.';
    } else {
        switch ($_POST['do'] ?? '') {
            case 'own':
                $content = is_file($KEY_FILE) ? key_code((string) file_get_contents($KEY_FILE, false, null, 0, 4096)) : null;
                if ($content === null) {
                    $error = 'setup.key was not found in the SynapseMail folder. Create it next to the config folder.';
                } elseif ($content === KEY_EXAMPLE) {
                    $error = 'setup.key still has the example code ' . KEY_EXAMPLE . '. Replace it with the code shown on this page and save the file.';
                } elseif (!hash_equals($S['challenge'], strtoupper($content))) {
                    $error = 'setup.key does not contain the code shown on this page.';
                } else {
                    advance($S, 'env');
                }
                break;

            case 'panel':
                if (isset(synapse_platform::PANELS[$_POST['panel'] ?? ''])) {
                    $S['panel'] = $_POST['panel'];
                }
                break;

            case 'env':
                if (array_filter(env_checks($ROOT, $https, $S['panel']), static function ($c) { return $c['level'] === 'fail'; })) {
                    $error = 'Fix the failed checks first, then reload this page.';
                } elseif (empty($_POST['web_protected'])) {
                    $error = 'The protection test of the db folder did not pass (see below).';
                } else {
                    advance($S, 'mail');
                }
                break;

            case 'mail':
                $imap = parse_choice($_POST['imap'] ?? '', ['ssl' => 993, 'tls' => 143]);
                $smtp = parse_choice($_POST['smtp'] ?? '', ['ssl' => 465, 'tls' => 587]);
                $service = service_name($_POST['service'] ?? '');
                if (!$imap || !$smtp) {
                    $error = 'Choose a working IMAP and SMTP connection.';
                } elseif ($service === '') {
                    $error = 'Enter the mail service name (2–40 characters) shown on the login page.';
                } else {
                    $S['mail'] = ['imap' => $imap, 'smtp' => $smtp, 'service' => $service];
                    advance($S, 'admin');
                }
                break;

            case 'admin':
                $user = strtolower(trim((string) ($_POST['username'] ?? '')));
                $pass = (string) ($_POST['password'] ?? '');
                if (!preg_match('/^[a-z0-9._-]{3,32}$/', $user)) {
                    $error = 'Username: 3–32 characters, letters, digits, dot, dash or underscore.';
                } elseif (($why = weak_password($pass, $user)) !== null) {
                    $error = $why;
                } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
                    $error = 'The two passwords are different.';
                } else {
                    $S['admin'] = ['user' => $user, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'totp' => synapse_totp::secret(), 'verified' => false];
                    $S['admin_stage'] = 'totp';
                }
                break;

            case 'totp':
                if (empty($S['admin']['totp'])) {
                    break;
                }
                if (synapse_totp::verify($S['admin']['totp'], $_POST['code'] ?? '') === false) {
                    $error = 'That code is not valid. Check the time on your phone and enter the current code.';
                } else {
                    $S['admin']['verified'] = true;
                    $S['admin']['recovery'] = synapse_totp::recovery_codes();
                    $S['admin_stage'] = 'recovery';
                }
                break;

            case 'admin_reset':
                unset($S['admin'], $S['admin_stage']);
                break;

            case 'recovery':
                if (empty($S['admin']['verified'])) {
                    break;
                }
                if (empty($_POST['saved'])) {
                    $error = 'Confirm that you saved the recovery codes.';
                } else {
                    advance($S, 'done');
                }
                break;

            case 'install':
                if ($S['step'] !== 'done' || empty($S['admin']['verified']) || empty($S['mail'])) {
                    $error = 'Complete all steps first.';
                    break;
                }
                try {
                    $admin_url = install($ROOT, $S, $CFG_FILE, $LOCK_FILE, $DB_FILE);
                } catch (\Throwable $e) {
                    $error = 'Installation failed: ' . $e->getMessage();
                    break;
                }
                @unlink($KEY_FILE);
                @unlink($CANARY);
                $_SESSION = [];
                session_destroy();
                page('Installed', '<div class="setup-note ok"><b>SynapseMail is installed.</b><p>The installer is now locked and <code>setup.key</code> was removed. Mailboxes can sign in now.</p></div>'
                    . '<p class="setup-lead">Your admin panel has a private address. Anyone without it gets "404 Not Found". Save it now, for example in your password manager:</p>'
                    . '<div class="setup-code setup-url" id="adminUrl">' . h($admin_url) . '</div>'
                    . '<button type="button" class="setup-text-btn" data-copy-text="' . h($admin_url) . '">Copy address</button>'
                    . '<p class="setup-hint">Lost it later? It is also in <code>config/ADMIN-URL.txt</code> (File Manager).</p>'
                    . '<label class="secure-check setup-check"><input type="checkbox" id="savedUrl"><span></span>I SAVED THE ADMIN ADDRESS</label>'
                    . '<a class="access-button setup-link is-locked" data-unlock="savedUrl" href="' . h($admin_url) . '" aria-disabled="true"><span>OPEN ADMIN PANEL</span><b aria-hidden="true"></b></a>'
                    . '<a class="setup-text-btn setup-link is-locked" data-unlock="savedUrl" href="./" aria-disabled="true">Open SynapseMail</a>', 'done', true);
                exit;
        }
        $step = $S['step'];
    }
}

/* ---------------- views ---------------- */

$csrf = h($S['csrf']);
$body = '';

if ($step === 'own') {
    $body = '<p class="setup-lead">Prove that you control this hosting account. In your file manager, open <code>setup.key</code> in the SynapseMail folder (the one that contains <code>config</code>, <code>db</code> and <code>public_html</code>; enable "Show Hidden Files" if needed), replace the example code <code>' . KEY_EXAMPLE . '</code> with this code and save. If the file is missing, create it with just this code:</p>'
        . '<div class="setup-code" id="challenge">' . h($S['challenge']) . '</div>'
        . '<p class="setup-hint">Only someone with access to your files can do this, so nobody else can take over the installer.' . (is_file($DB_FILE) ? ' An existing SynapseMail database was found: it will be kept (upgrade).' : '') . '</p>'
        . form('own', '<button class="access-button" type="submit"><span>I SAVED SETUP.KEY</span><b aria-hidden="true"></b></button>');
} elseif ($step === 'env') {
    @file_put_contents($CANARY, $S['challenge']);
    $panel = $S['panel'];
    $rows = '';
    foreach (env_checks($ROOT, $https, $panel) as $c) {
        $rows .= '<div class="check ' . $c['level'] . '"><span>' . h($c['label']) . '</span><small>' . h($c['detail']) . '</small><b>' . strtoupper($c['level'] === 'pass' ? 'ok' : $c['level']) . '</b>'
            . ($c['level'] !== 'pass' && $c['help'] !== '' ? fix_help($c['help'], $panel) : '') . '</div>';
    }
    $rows .= '<div class="check pending" id="webProbe" data-url="' . h(synapse_platform::base_url($_SERVER['SCRIPT_NAME'] ?? '') . '/db/install-probe.txt') . '" data-canary="' . h($S['challenge']) . '"><span>Data folders hidden from the web</span><small>Testing whether db/ can be downloaded…</small><b>…</b>'
        . '<div class="check-help" id="protectHelp" hidden><p>' . help_text(synapse_platform::help('protect', $panel)) . '</p>'
        . '<pre class="setup-pre">' . h($snippet = synapse_platform::nginx_snippet(synapse_platform::base_url($_SERVER['SCRIPT_NAME'] ?? ''))) . '</pre>'
        . '<button type="button" class="setup-text-btn" data-copy-text="' . h($snippet) . '">Copy nginx rules</button>'
        . '<p>Then reload this page. More help: <a href="' . h(synapse_platform::DOCS) . '" target="_blank" rel="noopener noreferrer">troubleshooting guide</a>.</p></div></div>';
    $body = '<p class="setup-lead">Checking this server.</p>' . panel_picker($panel) . '<div class="checks">' . $rows . '</div>'
        . form('env', '<input type="hidden" name="web_protected" id="webProtected" value=""><button class="access-button" type="submit" id="envNext" disabled><span>CONTINUE</span><b aria-hidden="true"></b></button>');
} elseif ($step === 'mail') {
    $hosts = h(json_encode(candidate_hosts()));
    $body = '<p class="setup-lead">Finding the mail server (IMAP for reading, SMTP for sending). Connections with a certificate that matches the host name are preferred.</p>'
        . '<div id="probeApp" data-hosts="' . $hosts . '" data-token="' . $csrf . '"><div class="checks" id="probeRows"><div class="check pending"><span>Testing connections…</span><small>This can take up to a minute.</small><b>…</b></div></div>'
        . fix_help(synapse_platform::help('mail', $S['panel']), $S['panel'], 'Mail server not found? Where to look on ')
        . '<div class="setup-custom"><input id="customHost" type="text" placeholder="Other host, e.g. mail.example.com" autocomplete="off" spellcheck="false"><button type="button" id="customProbe">TEST</button></div></div>'
        . form('mail', '<label class="field"><span class="field-label">IMAP (READING MAIL)</span><select name="imap" id="imapChoice" class="setup-select" required></select></label>'
            . '<label class="field"><span class="field-label">SMTP (SENDING MAIL)</span><select name="smtp" id="smtpChoice" class="setup-select" required></select></label>'
            . '<label class="field"><span class="field-label">MAIL SERVICE NAME (SHOWN ON THE LOGIN PAGE)</span><span class="field-control setup-input"><input name="service" type="text" required minlength="2" maxlength="40" spellcheck="false" value="' . h($S['mail']['service'] ?? default_service_name()) . '"></span></label>'
            . '<button class="access-button" type="submit"><span>USE THESE SETTINGS</span><b aria-hidden="true"></b></button>');
} elseif ($step === 'admin') {
    $stage = $S['admin_stage'] ?? 'account';
    if ($stage === 'account') {
        $body = '<p class="setup-lead">Create the SynapseMail administrator. This account is separate from every mailbox; two-factor authentication is required.</p>'
            . form('admin', '<label class="field"><span class="field-label">ADMIN USERNAME</span><span class="field-control setup-input"><input name="username" type="text" autocomplete="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" spellcheck="false" value="' . h($_POST['username'] ?? 'admin') . '"></span></label>'
                . '<label class="field"><span class="field-label">PASSWORD (12+ CHARACTERS)</span><span class="field-control setup-input"><input name="password" type="password" autocomplete="new-password" required minlength="12"></span></label>'
                . '<label class="field"><span class="field-label">REPEAT PASSWORD</span><span class="field-control setup-input"><input name="password2" type="password" autocomplete="new-password" required minlength="12"></span></label>'
                . '<button class="access-button" type="submit"><span>CONTINUE TO 2FA</span><b aria-hidden="true"></b></button>');
    } elseif ($stage === 'totp') {
        $uri = synapse_totp::uri('SynapseMail', $S['admin']['user'] . '@' . preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'synapsemail'), $S['admin']['totp']);
        $body = '<p class="setup-lead">Scan this code with Google Authenticator (or any TOTP app), then enter the 6-digit code it shows.</p>'
            . '<div class="setup-qr" id="qr" data-uri="' . h($uri) . '"></div>'
            . '<p class="setup-hint">Can\'t scan? Enter this key manually: <code class="setup-secret">' . h(trim(chunk_split($S['admin']['totp'], 4, ' '))) . '</code></p>'
            . form('totp', '<label class="field"><span class="field-label">6-DIGIT CODE</span><span class="field-control setup-input"><input name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></span></label>'
                . '<button class="access-button" type="submit"><span>VERIFY</span><b aria-hidden="true"></b></button>')
            . form('admin_reset', '<button class="setup-text-btn" type="submit">Start over with a different username or password</button>');
    } else {
        $codes = '';
        foreach ($S['admin']['recovery'] as $c) {
            $codes .= '<code>' . h($c) . '</code>';
        }
        $body = '<p class="setup-lead">Two-factor authentication works. Save these recovery codes somewhere safe: each one signs you in once if you lose your phone. They are shown only now.</p>'
            . '<div class="setup-codes" id="recoveryCodes">' . $codes . '</div>'
            . '<button type="button" class="setup-text-btn" id="copyCodes">Copy codes</button>'
            . form('recovery', '<label class="secure-check setup-check"><input type="checkbox" name="saved" value="1" required><span></span>I SAVED MY RECOVERY CODES</label><button class="access-button" type="submit"><span>CONTINUE</span><b aria-hidden="true"></b></button>');
    }
} else {
    $m = $S['mail'];
    $body = '<p class="setup-lead">Ready to install. This writes the configuration, ' . (is_file($DB_FILE) ? 'upgrades the existing database' : 'creates the database') . ', creates the admin account and locks the installer.</p>'
        . '<div class="checks">'
        . '<div class="check pass"><span>IMAP</span><small>' . h(conn_label($m['imap'])) . '</small><b>OK</b></div>'
        . '<div class="check pass"><span>SMTP</span><small>' . h(conn_label($m['smtp'])) . '</small><b>OK</b></div>'
        . '<div class="check pass"><span>Admin</span><small>' . h($S['admin']['user']) . ' · 2FA verified</small><b>OK</b></div>'
        . '</div>'
        . (is_file($DB_FILE) ? '<p class="setup-hint">Upgrade note: a new encryption key is created, so users signed in before the upgrade have to sign in again.</p>' : '')
        . form('install', '<button class="access-button" type="submit"><span>INSTALL SYNAPSEMAIL</span><b aria-hidden="true"></b></button>');
}

page(STEPS[$step], ($error ? '<div class="auth-error visible setup-error" role="alert"><span><b>CHECK FAILED</b><small>' . h($error) . '</small></span></div>' : '') . $body, $step);

/* ======================================================================== */

function advance(&$S, $to)
{
    $S['step'] = $to;
    session_regenerate_id(true);
}

/** Login page label for this mail service: plain text, 2–40 characters ('' when invalid) */
function service_name($text)
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)));

    return mb_strlen($text) >= 2 && mb_strlen($text) <= 40 ? $text : '';
}

function default_service_name()
{
    return strtoupper(preg_replace('/^(www|mail|webmail)\./i', '', preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'SynapseMail'))) . ' MAIL';
}

/** The code in setup.key: first line that is not empty and not a # comment */
function key_code($text)
{
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            return $line;
        }
    }

    return '';
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Escaped help text; `backticks` become code */
function help_text($text)
{
    return preg_replace('/`([^`]+)`/', '<code>$1</code>', h($text));
}

function fix_help($text, $panel, $prefix = 'How to fix on ')
{
    return '<details class="check-help"><summary>' . h($prefix . synapse_platform::PANELS[$panel]) . '</summary><p>' . help_text($text) . '</p></details>';
}

/** Detected hosting panel + web server, with a manual override */
function panel_picker($panel)
{
    $opts = '';
    foreach (synapse_platform::PANELS as $id => $name) {
        $opts .= '<option value="' . $id . '"' . ($id === $panel ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    $ws = ['apache' => 'Apache', 'litespeed' => 'LiteSpeed', 'nginx' => 'nginx', 'other' => 'unknown'][synapse_platform::web_server($_SERVER['SERVER_SOFTWARE'] ?? '')];

    return '<form method="post" action="install.php" class="setup-panel"><input type="hidden" name="csrf" value="' . h($_SESSION['synapse_setup']['csrf']) . '"><input type="hidden" name="do" value="panel">'
        . '<span>HOSTING</span><select name="panel" class="setup-select" aria-label="Hosting panel">' . $opts . '</select><button type="submit" class="setup-text-btn">CHANGE</button>'
        . '<small>Web server: ' . h($ws) . '. The fix steps below follow this choice.</small></form>';
}

function form($do, $inner)
{
    return '<form method="post" action="install.php" class="setup-form"><input type="hidden" name="csrf" value="' . h($_SESSION['synapse_setup']['csrf']) . '"><input type="hidden" name="do" value="' . h($do) . '">' . $inner . '</form>';
}

function valid_host($host)
{
    return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)
        || (strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host));
}

function candidate_hosts()
{
    $hosts = ['localhost'];
    $name = strtolower((string) gethostname());
    if (valid_host($name)) {
        $hosts[] = $name;
    }
    $http = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    $domain = preg_replace('/^(www|mail|webmail)\./', '', $http);
    foreach (['mail.' . $domain, $domain] as $h) {
        if (valid_host($h)) {
            $hosts[] = $h;
        }
    }

    return array_values(array_unique($hosts));
}

function probe($host, $port, $proto, $implicit)
{
    return synapse_probe::tls($host, $port, $proto, $implicit);
}

/** First host (not localhost) that completes a verified TLS handshake on cPanel's webmail port 2096 */
function cpanel_https_host(array $hosts)
{
    foreach (array_unique(array_filter($hosts, static function ($h) { return $h !== 'localhost' && valid_host($h); })) as $host) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $errno = 0;
        $errstr = '';
        if ($fp = @stream_socket_client('ssl://' . $host . ':2096', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx)) {
            fclose($fp);

            return $host;
        }
    }

    return null;
}

function parse_choice($value, $ports)
{
    // value: "<mode>|<host>|<cert_ok 0/1>", e.g. "ssl|mail.example.com|1"
    $p = explode('|', (string) $value);
    if (count($p) !== 3 || !isset($ports[$p[0]]) || !valid_host($p[1])) {
        return null;
    }

    return ['mode' => $p[0], 'host' => $p[1], 'port' => $ports[$p[0]], 'verify' => $p[2] === '1'];
}

function conn_label($c)
{
    return ($c['mode'] === 'ssl' ? 'SSL/TLS' : 'STARTTLS') . ' ' . $c['host'] . ':' . $c['port'] . ($c['verify'] ? ' · certificate verified' : ' · certificate not verified');
}

function weak_password($pass, $user)
{
    if (strlen($pass) < 12) {
        return 'The password needs at least 12 characters.';
    }
    if (stripos($pass, $user) !== false) {
        return 'The password must not contain the username.';
    }
    $classes = preg_match('/[a-z]/', $pass) + preg_match('/[A-Z]/', $pass) + preg_match('/\d/', $pass) + preg_match('/[^a-zA-Z\d]/', $pass);
    if ($classes < 3) {
        return 'Use at least three of: lowercase, uppercase, digits, symbols.';
    }

    return null;
}

function env_checks($root, $https, $panel = 'plain')
{
    $c = [];
    $add = static function ($level, $label, $detail, $topic, $arg = '') use (&$c, $panel) {
        $help = synapse_platform::help($topic, $panel, $arg);
        $c[] = compact('level', 'label', 'detail', 'help');
    };
    $add(PHP_VERSION_ID >= 80100 ? 'pass' : 'fail', 'PHP 8.1 or newer', 'This server runs PHP ' . PHP_VERSION, 'php');
    foreach (['pdo_sqlite' => 'SQLite database', 'intl' => 'Internationalization', 'mbstring' => 'Multibyte strings', 'openssl' => 'Encryption (TLS)', 'dom' => 'HTML processing', 'xml' => 'XML', 'json' => 'JSON', 'session' => 'Sessions', 'ctype' => 'Character types', 'filter' => 'Input filtering'] as $ext => $label) {
        $add(extension_loaded($ext) ? 'pass' : 'fail', $label, 'PHP extension ' . $ext, 'ext', $ext);
    }
    foreach (['fileinfo' => 'File type detection', 'zip' => 'Download attachments as zip', 'gd' => 'Image thumbnails'] as $ext => $label) {
        $add(extension_loaded($ext) ? 'pass' : 'warn', $label, 'Optional PHP extension ' . $ext, 'ext', $ext);
    }
    foreach (['config', 'db', 'temp', 'logs'] as $dir) {
        $add(is_writable($root . $dir) ? 'pass' : 'fail', 'Folder ' . $dir . '/ writable', is_writable($root . $dir) ? 'OK' : 'Set permissions to 755 in your file manager', 'perms', $dir . '/');
    }
    $add($https ? 'pass' : 'warn', 'HTTPS', $https ? 'This page is served over HTTPS' : 'Serve SynapseMail over HTTPS (enable SSL / AutoSSL in your hosting panel)', 'https');

    return $c;
}

function install($root, $S, $cfg_file, $lock_file, $db_file)
{
    $des_key = '';
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for ($i = 0; $i < 24; $i++) {
        $des_key .= $chars[random_int(0, strlen($chars) - 1)];
    }

    // database: create (fresh) or keep (upgrade), then SynapseMail tables
    $fresh = !is_file($db_file);
    $pdo = new \PDO('sqlite:' . $db_file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    if ($fresh) {
        $pdo->exec(file_get_contents($root . 'SQL/sqlite.initial.sql'));
    }
    @chmod($db_file, 0640);
    $pdo->exec('CREATE TABLE IF NOT EXISTS synapse_admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(64) NOT NULL UNIQUE,'
        . ' password_hash VARCHAR(255) NOT NULL, totp_secret TEXT NOT NULL, totp_last_step INTEGER NOT NULL DEFAULT 0,'
        . ' recovery_codes TEXT NOT NULL, created_at INTEGER NOT NULL, last_login INTEGER)');
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM synapse_admins');
    $st = $pdo->prepare('INSERT INTO synapse_admins (username, password_hash, totp_secret, recovery_codes, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute([
        $S['admin']['user'],
        $S['admin']['hash'],
        synapse_secret::encrypt($S['admin']['totp'], $des_key),
        json_encode(array_map(static function ($c) { return password_hash($c, PASSWORD_DEFAULT); }, $S['admin']['recovery'])),
        time(),
    ]);
    $pdo->commit();

    // configuration (written atomically)
    $conn = static function ($c, $scheme_ssl, $scheme_tls) {
        return var_export(($c['mode'] === 'ssl' ? $scheme_ssl : $scheme_tls) . $c['host'] . ':' . $c['port'], true);
    };
    $opts = static function ($c) {
        return $c['verify'] ? 'null' : "['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]";
    };
    $php = "<?php\n// Written by the SynapseMail installer on " . gmdate('Y-m-d H:i') . " UTC. Keep this file private.\n"
        . "\$config['des_key'] = " . var_export($des_key, true) . ";\n"
        . "\$config['db_dsnw'] = 'sqlite:///' . dirname(__DIR__) . '/db/synapsemail.db?mode=0640';\n"
        . "\$config['imap_host'] = " . $conn($S['mail']['imap'], 'ssl://', 'tls://') . ";\n"
        . "\$config['smtp_host'] = " . $conn($S['mail']['smtp'], 'ssl://', 'tls://') . ";\n"
        . ($S['mail']['imap']['verify'] ? '' : "// certificate not valid for this host name: verification off (localhost connection)\n")
        . "\$config['imap_conn_options'] = " . $opts($S['mail']['imap']) . ";\n"
        . "\$config['smtp_conn_options'] = " . $opts($S['mail']['smtp']) . ";\n";
    $php .= "\$config['synapse_service_name'] = " . var_export($S['mail']['service'] ?? default_service_name(), true) . ";\n";
    // password change goes to cPanel's webmail API on 2096: pick a host whose certificate verifies there
    $pw_host = cpanel_https_host(array_merge([$S['mail']['imap']['host'], $S['mail']['smtp']['host']], candidate_hosts()));
    $php .= $pw_host
        ? "\$config['password_cpanel_host'] = " . var_export($pw_host, true) . ";\n"
        : "// no host with a valid certificate answered on port 2096: password change uses the server hostname\n";
    // admin panel at a secret address: <base>/admin/<key>
    $key = synapse_secret::admin_key();
    $php .= "\$config['synapse_admin_key'] = " . var_export($key, true) . ";\n";
    $base = preg_replace('#[^/]*$#', '', strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
    $admin_url = ($GLOBALS['https'] ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . 'admin/' . $key;
    $tmp = $cfg_file . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false || !@chmod($tmp, 0640) || !rename($tmp, $cfg_file)) {
        @unlink($tmp);
        throw new \RuntimeException('could not write config/synapse.inc.php');
    }
    if (file_put_contents($lock_file, 'installed ' . gmdate('c') . "\n", LOCK_EX) === false) {
        throw new \RuntimeException('could not write config/installed.lock');
    }
    synapse_secret::write_admin_note($root, $admin_url);

    return $admin_url;
}

function page($title, $body, $step, $finished = false)
{
    $version = '';
    $core = __DIR__ . '/../synapse_core.php';
    if (preg_match("/const VERSION = '([^']+)'/", (string) @file_get_contents($core), $m)) {
        $version = $m[1];
    }
    $steps = '';
    $keys = array_keys(STEPS);
    $cur = $step === null ? count($keys) : array_search($step, $keys, true);
    foreach ($keys as $i => $k) {
        $state = ($finished || $i < $cur) ? 'OK' : ($i === $cur ? 'ACTIVE' : 'WAIT');
        $steps .= '<div class="' . strtolower($state) . '"><time>0' . ($i + 1) . '</time><span>' . h(STEPS[$k]) . '</span><b' . ($state === 'ACTIVE' ? ' class="standby"' : '') . '>' . $state . '</b></div>';
    }
    echo '<!DOCTYPE html><html lang="en" data-theme="agency"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>SynapseMail - Setup · ' . h($title) . '</title>'
        . '<link rel="icon" type="image/svg+xml" href="static.php/skins/synapse/assets/synapse-cyber-logo.svg?v=' . @filemtime($GLOBALS['ROOT'] . 'skins/synapse/assets/synapse-cyber-logo.svg') . '">'
        . '<link rel="stylesheet" href="static.php/skins/synapse/vendor/fonts.css?v=' . @filemtime($GLOBALS['ROOT'] . 'skins/synapse/vendor/fonts.css') . '"><link rel="stylesheet" href="static.php/skins/synapse/login.css?v=' . @filemtime($GLOBALS['ROOT'] . 'skins/synapse/login.css') . '">'
        . '<link rel="stylesheet" href="static.php/plugins/synapse_core/installer/installer.css?v=' . @filemtime($GLOBALS['ROOT'] . 'plugins/synapse_core/installer/installer.css') . '"></head>'
        . '<body class="setup-page"><div class="atmosphere" aria-hidden="true"><div class="grid-plane"></div><div class="topo-map"></div><div class="scanline"></div></div><div class="noise" aria-hidden="true"></div>'
        . '<header class="access-header"><span class="brand"><span class="brand-mark" aria-hidden="true"></span><span><strong>SynapseMail</strong><small>SECURE SETUP</small></span></span>'
        . '<div class="gateway-state"><span class="pulse"></span><span>INSTALLER</span><strong>' . ($finished ? 'LOCKED' : 'ACTIVE') . '</strong></div><time>v' . h($version) . '</time></header>'
        . '<main class="login-stage"><section class="mission-brief"><div class="eyebrow"><span>SETUP</span><i></i><span>SHARED HOSTING EDITION</span></div>'
        . '<h1 class="glitch-title" data-text="INSTALL SYNAPSEMAIL.">INSTALL SYNAPSEMAIL.</h1><p>A guided, one-time setup. Nothing is written to the server until the final step, and the installer locks itself afterwards.</p>'
        . '<div class="boot-log setup-steps">' . $steps . '</div></section>'
        . '<section class="auth-shell" aria-labelledby="setupTitle"><div class="corner corner-tl"></div><div class="corner corner-tr"></div><div class="corner corner-bl"></div><div class="corner corner-br"></div>'
        . '<div class="auth-heading"><div class="clearance-icon"><span></span></div><div><span>SETUP // ' . h(strtoupper($title)) . '</span><h2 id="setupTitle">' . h(strtoupper($title)) . '</h2></div><b>0' . (min($cur, count($keys) - 1) + 1) . '</b></div>'
        . '<div class="setup-body">' . $body . '</div>'
        . '<footer class="auth-footer"><span>SYNAPSEMAIL INSTALLER</span><span>NO DATA SENT ELSEWHERE</span></footer></section></main>'
        . '<script src="static.php/skins/synapse/vendor/qrcode.js?v=' . @filemtime($GLOBALS['ROOT'] . 'skins/synapse/vendor/qrcode.js') . '"></script><script src="static.php/plugins/synapse_core/installer/installer.js?v=' . @filemtime($GLOBALS['ROOT'] . 'plugins/synapse_core/installer/installer.js') . '"></script><script src="static.php/skins/synapse/login-field.js?v=' . @filemtime($GLOBALS['ROOT'] . 'skins/synapse/login-field.js') . '"></script></body></html>';
}
