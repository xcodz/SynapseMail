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
 * SynapseMail admin panel (shared-hosting edition).
 *
 * Separate from every mailbox: the admin account created by the installer signs in with its own password
 * plus a mandatory authenticator (or one-time recovery) code. Own PHP session in temp/, 15-minute idle timeout,
 * CSRF token on every change, sign-in rate limits, every action written to logs/synapse_security.log.
 */

namespace SynapseMail\Admin;

use rcube;
use RuntimeException;
use synapse_backup;
use synapse_core;
use synapse_deliverability;
use synapse_platform;
use synapse_secret;
use synapse_totp;
use synapse_update;

const IDLE = 900;
const ABSOLUTE = 8 * 3600;
const VIEWS = ['overview' => 'Overview', 'users' => 'Users', 'security' => 'Security', 'deliverability' => 'Deliverability', 'backup' => 'Backup', 'logs' => 'Logs', 'account' => 'Admin account'];
const LOGS = ['synapse_security' => 'Security events', 'errors' => 'Errors', 'password' => 'Password changes', 'userlogins' => 'Sign-ins'];

define('INSTALL_PATH', realpath(__DIR__ . '/../../..') . '/');
require_once INSTALL_PATH . 'program/include/iniset.php';
$RC = rcube::get_instance(rcube::INIT_WITH_DB);
require_once INSTALL_PATH . 'plugins/synapse_core/synapse_core.php';
require_once INSTALL_PATH . 'plugins/synapse_core/lib/synapse_probe.php';
require_once INSTALL_PATH . 'plugins/synapse_core/lib/synapse_deliverability.php';
require_once INSTALL_PATH . 'plugins/synapse_core/lib/synapse_backup.php';
require_once INSTALL_PATH . 'plugins/synapse_core/lib/synapse_update.php';
require_once INSTALL_PATH . 'plugins/synapse_core/lib/synapse_platform.php';
require_once __DIR__ . '/views.php';

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/* ---------------- secret address: <base>/admin/<key> (also admin.php/<key> and admin.php?k=<key>) ---------------- */

$https = rcube_https();
$BASE = preg_replace('#admin(?:\.php)?(?:/[^/]*)?/?$#', '', strtok($_SERVER['REQUEST_URI'] ?? '/', '?')) ?: '/';
$KEY = admin_key($RC, $BASE);
// installs from before 2.28 keep the old address until the admin has signed in and saved the new one
$PENDING = (string) synapse_core::setting('admin_key_pending') === '1' || $KEY === '';
$given = (string) ($_GET['k'] ?? trim((string) ($_SERVER['PATH_INFO'] ?? ''), '/'));
if (!$PENDING && !hash_equals($KEY, $given)) {
    not_found();
}
$ADMIN_URL = admin_url($KEY);

/* ---------------- session (separate from webmail sessions) ---------------- */

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_save_path(INSTALL_PATH . 'temp');
session_name('synapse_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => $BASE,
    'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$A = &$_SESSION['synapse_admin'];
if (!is_array($A)) {
    $A = ['csrf' => bin2hex(random_bytes(16))];
}
if (!empty($A['id']) && (time() - $A['seen'] > IDLE || time() - $A['since'] > ABSOLUTE)) {
    $A = ['csrf' => bin2hex(random_bytes(16)), 'flash' => ['warn', 'Signed out after inactivity.']];
}

$db = $RC->get_dbh();
$admins = $db->table_name('synapse_admins', true);
$ip = \rcube_utils::remote_ip();

/* ---------------- actions (POST only, CSRF checked) ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($A['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        flash('fail', 'The form expired. Please try again.');
        back();
    }
    $do = (string) ($_POST['do'] ?? '');

    if ($do === 'login') {
        login($db, $admins, $ip);
        back();
    }
    if (empty($A['id'])) {
        back();
    }
    $A['seen'] = time();

    switch ($do) {
        case 'confirm_url':
            if (!empty($_POST['saved']) && $KEY !== '') {
                synapse_core::set_setting('admin_key_pending', '0');
                audit('confirmed the new admin address');
                flash('ok', 'The admin panel now answers only at its private address.');
                header('Location: ' . admin_url($KEY, true), true, 303);
                exit;
            }
            back();

        case 'rotate_key':
            $new = synapse_secret::admin_key();
            if (set_admin_key($new)) {
                synapse_secret::write_admin_note(INSTALL_PATH, admin_url($new));
                audit('generated a new admin address');
                $A['new_url'] = admin_url($new);
                flash('ok', 'New admin address active. The old one now answers 404.');
                $go = admin_url($new, true);
                header('Location: ' . $go . (strpos($go, '?') ? '&' : '?') . 'view=security', true, 303);
                exit;
            }
            flash('fail', 'config/synapse.inc.php is not writable: the address was not changed.');
            back('security');

        case 'logout':
            audit('signed out');
            $A = ['csrf' => bin2hex(random_bytes(16)), 'flash' => ['ok', 'Signed out.']];
            session_regenerate_id(true);
            back();

        case 'reset_pin':
        case 'reset_2fa':
        case 'unlock':
            $uid = (int) ($_POST['uid'] ?? 0);
            $drop = ['reset_pin' => ['synapse_pin', 'synapse_pin_lock', 'synapse_pin_fails'], 'reset_2fa' => ['synapse_otp'], 'unlock' => ['synapse_pin_lock', 'synapse_pin_fails']][$do];
            if ($name = user_prefs_drop($db, $uid, $drop)) {
                $n = end_sessions($db, $uid);
                audit(['reset_pin' => 'reset passcode of', 'reset_2fa' => 'reset 2FA of', 'unlock' => 'unlocked'][$do] . ' ' . $name . " ($n sessions ended)");
                flash('ok', ['reset_pin' => 'Passcode removed for ', 'reset_2fa' => '2FA removed for ', 'unlock' => 'Unlocked '][$do] . $name . ". $n session(s) ended.");
            }
            back('users');

        case 'end_sessions':
            $uid = (int) ($_POST['uid'] ?? 0);
            $row = $db->fetch_assoc($db->query('SELECT `username` FROM ' . $db->table_name('users', true) . ' WHERE `user_id` = ?', $uid));
            if ($row) {
                $n = end_sessions($db, $uid);
                audit("ended $n sessions of {$row['username']}");
                flash('ok', "$n session(s) ended for {$row['username']}.");
            }
            back('users');

        case 'policy':
            $policy = (string) ($_POST['policy'] ?? '');
            if (in_array($policy, synapse_core::OTP_POLICIES, true)) {
                synapse_core::set_setting('2fa_policy', $policy);
                audit("set 2FA policy to $policy");
                flash('ok', "2FA policy set to \"$policy\".");
            }
            back('security');

        case 'recheck':
            $domain = strtolower((string) ($_POST['domain'] ?? ''));
            if (in_array($domain, mail_domains($db), true)) {
                $last = json_decode((string) synapse_core::setting('deliv:' . $domain), true);
                if ($last && time() - $last['time'] < 300) {
                    flash('warn', 'Checked less than 5 minutes ago. Try again in ' . (5 - intdiv(time() - $last['time'], 60)) . ' minutes.');
                } else {
                    deliverability($RC, $domain, true);
                    audit("rechecked deliverability of $domain");
                }
            }
            back('deliverability', ['domain' => $domain]);

        case 'backup_create':
            if (!db_file($RC)) {
                flash('fail', 'Backups work with the SQLite database of the shared edition only.');
                back('backup');
            }
            foreach (glob(INSTALL_PATH . 'temp/sm-backup-*.smbk') ?: [] as $old) {
                if (filemtime($old) < time() - 3600) {
                    @unlink($old);
                }
            }
            [$data, $key] = synapse_backup::create(db_file($RC), INSTALL_PATH . 'config/synapse.inc.php', synapse_core::VERSION);
            $path = INSTALL_PATH . 'temp/sm-backup-' . bin2hex(random_bytes(8)) . '.smbk';
            file_put_contents($path, $data, LOCK_EX);
            @chmod($path, 0600);
            $A['backup'] = ['path' => $path, 'name' => 'synapsemail-backup-' . gmdate('Ymd-His') . '.smbk', 'size' => strlen($data)];
            $A['backup_key'] = $key;
            audit('created a backup (' . size(strlen($data)) . ')');
            back('backup');

        case 'backup_download':
            $b = $A['backup'] ?? null;
            if (!$b || !is_file($b['path'])) {
                flash('fail', 'This backup is no longer available. Create a new one.');
                back('backup');
            }
            unset($A['backup']);
            session_write_close();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $b['name'] . '"');
            header('Content-Length: ' . filesize($b['path']));
            readfile($b['path']);
            @unlink($b['path']);
            audit('downloaded backup ' . $b['name']);
            exit;

        case 'restore':
            $row = admin_row($db, $admins, $A['id']);
            $secret = $row ? synapse_secret::decrypt($row['totp_secret'], $RC->config->get_crypto_key('des_key')) : null;
            $step = $secret ? synapse_totp::verify($secret, $_POST['code'] ?? '') : false;
            $up = $_FILES['file'] ?? null;
            if ($step === false || $step <= (int) $row['totp_last_step']) {
                flash('fail', 'Authenticator code is incorrect.');
            } elseif (!db_file($RC)) {
                flash('fail', 'Restore works with the SQLite database of the shared edition only.');
            } elseif (!$up || $up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
                flash('fail', 'Upload failed' . ($up && in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? ': the file is larger than PHP allows (upload_max_filesize).' : '.'));
            } else {
                $db->query("UPDATE {$admins} SET `totp_last_step` = ? WHERE `id` = ?", $step, $row['id']);
                try {
                    $backup = synapse_backup::open(file_get_contents($up['tmp_name']), $_POST['key'] ?? '');
                    $db->closeConnection();
                    $before = synapse_backup::restore($backup, db_file($RC), INSTALL_PATH . 'config/synapse.inc.php');
                    audit('restored backup from ' . gmdate('Y-m-d H:i', $backup['meta']['created']) . ' UTC (v' . $backup['meta']['version'] . '); previous data saved as db/' . basename($before));
                    $A = ['csrf' => bin2hex(random_bytes(16)), 'flash' => ['ok', 'Backup restored. Everyone, including you, must sign in again (the admin account is the one from the backup). The previous data was saved as db/' . basename($before) . '.']];
                    session_regenerate_id(true);
                    back();
                } catch (RuntimeException $e) {
                    audit('backup restore failed: ' . $e->getMessage());
                    flash('fail', $e->getMessage());
                }
            }
            back('backup');

        case 'update_check':
            update_status($RC, true);
            audit('checked for updates');
            back();

        case 'service_name':
            $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($_POST['service'] ?? ''))));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 40) {
                flash('fail', 'The mail service name needs 2–40 characters.');
            } else {
                synapse_core::set_setting('service_name', $name);
                audit("set the mail service name to \"$name\"");
                flash('ok', "Login page now shows \"$name\".");
            }
            back('security');

        case 'clear_blocks':
            $n = $db->affected_rows($db->query('DELETE FROM ' . $db->table_name('synapse_ratelimit', true)));
            audit("cleared all rate-limit counters ($n)");
            flash('ok', 'All sign-in blocks and counters cleared.');
            back('security');

        case 'admin_password':
            $row = admin_row($db, $admins, $A['id']);
            $new = (string) ($_POST['new'] ?? '');
            if (!$row || !password_verify((string) ($_POST['current'] ?? ''), $row['password_hash'])) {
                flash('fail', 'Current password is incorrect.');
            } elseif (strlen($new) < 12 || $new !== (string) ($_POST['new2'] ?? '')) {
                flash('fail', 'The new password needs 12+ characters and both entries must match.');
            } else {
                $db->query("UPDATE {$admins} SET `password_hash` = ? WHERE `id` = ?", password_hash($new, PASSWORD_DEFAULT), $row['id']);
                session_regenerate_id(true);
                audit('changed the admin password');
                flash('ok', 'Admin password changed.');
            }
            back('account');

        case 'admin_recovery':
            $row = admin_row($db, $admins, $A['id']);
            $secret = $row ? synapse_secret::decrypt($row['totp_secret'], $RC->config->get_crypto_key('des_key')) : null;
            $step = $secret ? synapse_totp::verify($secret, $_POST['code'] ?? '') : false;
            if ($step === false || $step <= (int) $row['totp_last_step']) {
                flash('fail', 'Authenticator code is incorrect.');
            } else {
                $codes = synapse_totp::recovery_codes();
                $db->query("UPDATE {$admins} SET `recovery_codes` = ?, `totp_last_step` = ? WHERE `id` = ?",
                    json_encode(array_map(static function ($c) { return password_hash($c, PASSWORD_DEFAULT); }, $codes)), $step, $row['id']);
                $A['show_codes'] = $codes;
                audit('created new admin recovery codes');
            }
            back('account');
    }
    back();
}

/* ---------------- pages ---------------- */

if (empty($A['id'])) {
    signin_page(flash_html('setup') . form('login', '<label class="field"><span class="field-label">ADMIN USERNAME</span><span class="field-control setup-input"><input name="username" type="text" autocomplete="username" required maxlength="32" spellcheck="false" autofocus></span></label>'
        . '<label class="field"><span class="field-label">PASSWORD</span><span class="field-control setup-input"><input name="password" type="password" autocomplete="current-password" required></span></label>'
        . '<label class="field"><span class="field-label">AUTHENTICATOR OR RECOVERY CODE</span><span class="field-control setup-input"><input name="code" type="text" autocomplete="one-time-code" required maxlength="12" spellcheck="false"></span></label>'
        . '<button class="access-button" type="submit"><span>SIGN IN</span><b aria-hidden="true"></b></button>')
        . '<p class="setup-hint">This is the SynapseMail administrator, not a mailbox. Lost both the authenticator and the recovery codes? Run the installer again (see INSTALL-SYNAPSEMAIL.txt).</p>');
    exit;
}

$A['seen'] = time();

// first sign-in after the upgrade that introduced the private address: show it until the admin confirms
if ($PENDING && $KEY !== '') {
    shell('', 'New admin address', 'Save it before you continue', view_moved(admin_url($KEY), preg_replace('#/admin/([^/]+)$#', '/admin.php?k=$1', admin_url($KEY))));
    exit;
}

$view = isset(VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'overview';
$subtitles = [
    'overview' => 'Health and activity of this SynapseMail install',
    'users' => 'Mailboxes that have signed in, their 2FA and passcode state',
    'security' => 'Admin address, 2FA policy, login page and sign-in protection',
    'deliverability' => 'DNS and reputation checks for your mail domains (read-only)',
    'backup' => 'Encrypted backup and restore of the app data',
    'logs' => 'Security events, errors, password changes and sign-ins',
    'account' => 'Your administrator account',
];
switch ($view) {
    case 'users': $content = view_users($db); break;
    case 'security': $content = view_security($RC, $db); break;
    case 'deliverability': $content = view_deliverability($RC, $db); break;
    case 'backup': $content = view_backup($A); unset($A['backup_key']); break;
    case 'logs': $content = view_logs($RC); break;
    case 'account': $content = view_account($db, $admins, $A); break;
    default: $content = view_overview($RC, $db);
}
shell($view, VIEWS[$view], $subtitles[$view], $content);

/* ---------------- sign-in ---------------- */

function login($db, $admins, $ip)
{
    global $A, $RC;
    $user = strtolower(trim((string) ($_POST['username'] ?? '')));
    $keys = ['admip' => $ip, 'admin' => $user];
    if ($wait = synapse_core::rl_blocked($keys)) {
        rcube::write_log('synapse_security', "admin sign-in refused (rate limit) for $user from $ip");
        flash('fail', 'Too many failed attempts. Try again in ' . max(1, (int) ceil($wait / 60)) . ' minutes.');
        return;
    }
    $row = $db->fetch_assoc($db->query("SELECT * FROM {$admins} WHERE LOWER(`username`) = ?", $user));
    // same work whether or not the name exists
    $pass_ok = password_verify((string) ($_POST['password'] ?? ''), $row['password_hash'] ?? '$2y$10$TmmjNQXdqiNoFfa9WQwKT.zl.TweH0o6v.0ELOQk4Z/.38zwuNita');
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $ok = false;
    if ($row && $pass_ok) {
        if (preg_match('/^\d{3}\s?\d{3}$/', $code)) {
            $secret = synapse_secret::decrypt($row['totp_secret'], $RC->config->get_crypto_key('des_key'));
            $step = $secret ? synapse_totp::verify($secret, $code) : false;
            if ($step !== false && $step > (int) $row['totp_last_step']) {
                $db->query("UPDATE {$admins} SET `totp_last_step` = ? WHERE `id` = ?", $step, $row['id']);
                $ok = true;
            }
        } elseif (strlen($plain = preg_replace('/[^A-Z0-9]/', '', $code)) === 8) {
            $plain = substr($plain, 0, 4) . '-' . substr($plain, 4);
            $codes = (array) json_decode($row['recovery_codes'], true);
            foreach ($codes as $i => $hash) {
                if (password_verify($plain, $hash)) {
                    unset($codes[$i]);
                    $db->query("UPDATE {$admins} SET `recovery_codes` = ? WHERE `id` = ?", json_encode(array_values($codes)), $row['id']);
                    $ok = 'recovery';
                    break;
                }
            }
        }
    }
    if (!$ok) {
        synapse_core::rl_fail($keys);
        rcube::write_log('synapse_security', "admin sign-in failed for $user from $ip");
        flash('fail', 'Sign-in failed. Check the username, password and code.');
        return;
    }
    session_regenerate_id(true);
    synapse_core::rl_clear('admin', $user);
    $db->query("UPDATE {$admins} SET `last_login` = ? WHERE `id` = ?", time(), $row['id']);
    $A = ['csrf' => bin2hex(random_bytes(16)), 'id' => (int) $row['id'], 'user' => $row['username'], 'since' => time(), 'seen' => time()];
    audit('signed in' . ($ok === 'recovery' ? ' with a recovery code' : ''));
    if ($ok === 'recovery') {
        flash('warn', 'You used a recovery code. Create new codes under Admin account if you are running low.');
    }
}

/* ---------------- users ---------------- */


/** Remove preference keys of one user; returns the username or null */
function user_prefs_drop($db, $uid, array $keys)
{
    $table = $db->table_name('users', true);
    $u = $db->fetch_assoc($db->query("SELECT `username`, `preferences` FROM {$table} WHERE `user_id` = ?", $uid));
    if (!$u) {
        return null;
    }
    $p = @unserialize((string) $u['preferences'], ['allowed_classes' => false]) ?: [];
    foreach ($keys as $k) {
        unset($p[$k]);
    }
    $db->query("UPDATE {$table} SET `preferences` = ? WHERE `user_id` = ?", serialize($p), $uid);

    return $u['username'];
}

/** Active webmail sessions per user id (session data is base64 of PHP session format) */
function session_counts($db)
{
    $out = [];
    foreach (sessions($db) as $uid) {
        $out[$uid] = ($out[$uid] ?? 0) + 1;
    }

    return $out;
}

function sessions($db)
{
    $res = $db->query('SELECT `sess_id`, `vars` FROM ' . $db->table_name('session', true) . ' WHERE `expires_at` > ' . $db->now());
    $out = [];
    while ($r = $db->fetch_assoc($res)) {
        if (preg_match('/(?:^|;|\})user_id\|i:(\d+);/', (string) base64_decode($r['vars']), $m)) {
            $out[$r['sess_id']] = (int) $m[1];
        }
    }

    return $out;
}

function end_sessions($db, $uid)
{
    $n = 0;
    foreach (sessions($db) as $sid => $owner) {
        if ($owner === $uid) {
            $db->query('DELETE FROM ' . $db->table_name('session', true) . ' WHERE `sess_id` = ?', $sid);
            $n++;
        }
    }

    return $n;
}

/* ---------------- updates (signed manifest, read-only) ---------------- */

/** Overview row; checks the network when asked or when the last check is older than a day */
function update_status($RC, $fresh = false)
{
    $url = (string) $RC->config->get('synapse_update_url');
    $last = json_decode((string) synapse_core::setting('update'), true);
    if ($fresh || !$last || time() - $last['time'] > 86400 || ($last['url'] ?? null) !== $url) {
        $last = synapse_update::check($url, synapse_core::VERSION) + ['time' => time(), 'url' => $url];
        synapse_core::set_setting('update', json_encode($last));
    }
    $when = ' · checked ' . gmdate('Y-m-d H:i', $last['time']) . ' UTC';
    if (!empty($last['error'])) {
        return ['pending', 'Updates', $last['error'] . $when, 'N/A'];
    }
    $m = $last['latest'];
    if (!$last['newer'] || version_compare($m['version'], synapse_core::VERSION, '<=')) {
        return ['pass', 'Updates', 'Up to date (latest signed release ' . $m['version'] . ')' . $when, 'OK'];
    }

    return ['warn', 'Update available: SynapseMail ' . $m['version'], 'Download ' . $m['url'] . ' · SHA-256 ' . $m['sha256']
        . (!empty($m['notes']) ? ' · ' . $m['notes'] : '') . ' · signature verified', 'NEW'];
}

/* ---------------- health ---------------- */

function health($RC, $db)
{
    $c = [];
    $c[] = ['pass', 'SynapseMail ' . synapse_core::VERSION, 'Roundcube ' . RCMAIL_VERSION . ' · PHP ' . PHP_VERSION, 'OK'];
    $missing = array_filter(['pdo_sqlite', 'intl', 'mbstring', 'openssl', 'json', 'fileinfo'], static function ($e) { return !extension_loaded($e); });
    $c[] = [$missing ? 'fail' : 'pass', 'PHP extensions', $missing ? 'Missing: ' . implode(', ', $missing) : 'All required extensions loaded', $missing ? 'NO' : 'OK'];
    $c[] = [rcube_https() ? 'pass' : 'fail', 'HTTPS', rcube_https() ? 'This page was served over HTTPS' : 'Not HTTPS: passwords travel in clear text', rcube_https() ? 'OK' : 'NO'];
    foreach (['temp', 'logs', 'db'] as $dir) {
        $w = is_writable(INSTALL_PATH . $dir);
        $c[] = [$w ? 'pass' : 'fail', "Folder $dir/", $w ? 'Writable' : 'Not writable by PHP', $w ? 'OK' : 'NO'];
    }
    $ws = synapse_platform::web_server($_SERVER['SERVER_SOFTWARE'] ?? '');
    $c[] = ['pass', 'Hosting', synapse_platform::PANELS[synapse_platform::panel(realpath(INSTALL_PATH))] . ' (detected) · web server ' . $ws, 'INFO'];
    $locked = is_file(INSTALL_PATH . 'config/installed.lock');
    $c[] = [$locked ? 'pass' : 'warn', 'Installer', $locked ? 'Locked' : 'config/installed.lock is missing', $locked ? 'OK' : 'CHECK'];
    // updates bring back the example file; only a real code left behind is worth a warning
    $key = is_file(INSTALL_PATH . 'setup.key') && !preg_match('/^SM-XXXX-XXXX-XXXX$/m', (string) file_get_contents(INSTALL_PATH . 'setup.key', false, null, 0, 4096));
    $c[] = [$key ? 'warn' : 'pass', 'setup.key', $key ? 'Still holds an installer code: delete it in File Manager' : 'Removed or example only', $key ? 'CHECK' : 'OK'];
    foreach (['imap_host' => 'IMAP', 'smtp_host' => 'SMTP'] as $k => $label) {
        $c[] = probe($RC->config->get($k), $label);
    }
    $pw = $RC->config->get('password_cpanel_host');
    $c[] = [$pw ? 'pass' : 'warn', 'Password change host', $pw ? $pw . ':2096' : 'Not set: password change uses the server hostname', $pw ? 'OK' : 'CHECK'];
    $dbfile = INSTALL_PATH . 'db/synapsemail.db';
    $free = @disk_free_space(INSTALL_PATH);
    $c[] = ['pass', 'Storage', 'Database ' . size(@filesize($dbfile)) . ($free !== false ? ' · free disk ' . size($free) : ''), 'INFO'];
    $users = (int) $db->fetch_array($db->query('SELECT COUNT(*) FROM ' . $db->table_name('users', true)))[0];
    $c[] = ['pass', 'Users', $users . ' known mailboxes · ' . count(sessions($db)) . ' active sessions', 'INFO'];

    return $c;
}

function probe($host, $label)
{
    $host = is_array($host) ? reset($host) : (string) $host;
    $url = parse_url(strpos($host, '://') ? $host : 'tcp://' . $host);
    $name = $url['host'] ?? '';
    $port = $url['port'] ?? ($label === 'IMAP' ? 143 : 587);
    $t = microtime(true);
    $fp = $name ? @fsockopen($name, $port, $errno, $errstr, 4) : false;
    if (!$fp) {
        return ['fail', "$label server", "$name:$port not reachable" . (!empty($errstr) ? " ($errstr)" : ''), 'NO'];
    }
    fclose($fp);

    return ['pass', "$label server", "$name:$port reachable in " . (int) ((microtime(true) - $t) * 1000) . ' ms', 'OK'];
}

/* ---------------- deliverability (read-only) ---------------- */

/** Domains of mailboxes that use this install */
function mail_domains($db)
{
    $res = $db->query('SELECT DISTINCT `username` FROM ' . $db->table_name('users', true));
    $out = [];
    while ($r = $db->fetch_assoc($res)) {
        if ($d = strtolower((string) substr(strrchr($r['username'], '@') ?: '', 1))) {
            $out[$d] = $d;
        }
    }
    sort($out);

    return array_values($out);
}

/** Cached result (1 hour) or a fresh run */
function deliverability($RC, $domain, $fresh = false)
{
    $cached = json_decode((string) synapse_core::setting('deliv:' . $domain), true);
    if (!$fresh && $cached && time() - $cached['time'] < 3600) {
        return $cached;
    }
    $tls = [];
    foreach (['imap_host' => ['IMAP', 'imap', 993, 143], 'smtp_host' => ['SMTP', 'smtp', 465, 587]] as $k => [$label, $proto, $ssl_port, $tls_port]) {
        $u = parse_url(($h = (string) $RC->config->get($k)) && strpos($h, '://') ? $h : 'tcp://' . $h);
        $implicit = ($u['scheme'] ?? '') === 'ssl';
        $host = $u['host'] ?? '';
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            $host = 'mail.' . $domain; // the name mail apps and other servers use
        }
        $tls[$label] = [$host, $u['port'] ?? ($implicit ? $ssl_port : $tls_port), $proto, $implicit];
    }
    $result = synapse_deliverability::run($domain, sending_ip($RC, $tls['SMTP'][0]), $tls);
    synapse_core::set_setting('deliv:' . $domain, json_encode($result));

    return $result;
}

/** Best guess at the public address mail leaves from: the SMTP host, else this server */
function sending_ip($RC, $smtp_host)
{
    foreach ([$smtp_host, gethostname()] as $h) {
        $ip = gethostbyname((string) $h);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    $ip = $_SERVER['SERVER_ADDR'] ?? '';

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : '';
}


/* ---------------- logs ---------------- */


/* ---------------- secret address ---------------- */

/** Key from config; installs from before 2.28 get one now (pending until the admin confirms it after signing in) */
function admin_key($RC, $base)
{
    $key = (string) $RC->config->get('synapse_admin_key');
    if (preg_match('/^[a-z0-9-]{16,64}$/', $key)) {
        return $key;
    }
    $key = synapse_secret::admin_key();
    if (!set_admin_key($key)) {
        return ''; // config not writable: keep the old address working
    }
    synapse_core::set_setting('admin_key_pending', '1');
    synapse_secret::write_admin_note(INSTALL_PATH, (rcube_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . 'admin/' . $key);
    rcube::write_log('synapse_security', 'admin panel moved to a private address (see config/ADMIN-URL.txt)');

    return $key;
}

/** Write (or replace) synapse_admin_key in config/synapse.inc.php, atomically */
function set_admin_key($key)
{
    $file = INSTALL_PATH . 'config/synapse.inc.php';
    $cfg = (string) @file_get_contents($file);
    if ($cfg === '') {
        return false;
    }
    $line = "\$config['synapse_admin_key'] = " . var_export($key, true) . ';';
    $cfg = preg_match("/^\\\$config\['synapse_admin_key'\].*$/m", $cfg)
        ? preg_replace("/^\\\$config\['synapse_admin_key'\].*$/m", $line, $cfg)
        : rtrim($cfg) . "\n// admin panel address: <base>/admin/<key>\n" . $line . "\n";
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $cfg, LOCK_EX) === false || !@chmod($tmp, 0640) || !rename($tmp, $file)) {
        @unlink($tmp);

        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }

    return true;
}

/**
 * Full address of the panel. $reachable: the form that works on this server right now
 * (admin/<key> needs the .htaccess rewrite: Apache or LiteSpeed; otherwise admin.php?k=<key>).
 */
function admin_url($key, $reachable = false)
{
    $root = (rcube_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $GLOBALS['BASE'];
    $rewrite = preg_match('#/admin/[^/]+$#', strtok($_SERVER['REQUEST_URI'], '?')) || preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? '');

    return $reachable && !$rewrite ? $root . 'admin.php?k=' . $key : $root . 'admin/' . $key;
}

function not_found()
{
    http_response_code(404);
    header('Content-Type: text/html; charset=iso-8859-1');
    echo "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><head>\n<title>404 Not Found</title>\n</head><body>\n<h1>Not Found</h1>\n<p>The requested URL was not found on this server.</p>\n</body></html>\n";
    exit;
}

/** Current address of this page (keeps ?k= when the server has no rewrite) plus parameters */
function self_url(array $params = [])
{
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    if (isset($_GET['k']) && preg_match('#admin\.php$#', $path)) {
        $params = ['k' => $_GET['k']] + $params; // no rewrite: the key travels in the query
    }

    return $path . ($params ? '?' . http_build_query($params) : '');
}

/* ---------------- helpers ---------------- */

/** Path of the SQLite database file, or null for other databases */
function db_file($RC)
{
    $dsn = (string) $RC->config->get('db_dsnw');

    return preg_match('#^sqlite:///(.+?)(\?.*)?$#', $dsn, $m) ? $m[1] : null;
}

function admin_row($db, $admins, $id)
{
    return $db->fetch_assoc($db->query("SELECT * FROM {$admins} WHERE `id` = ?", $id));
}

function audit($what)
{
    global $A, $ip;
    rcube::write_log('synapse_security', 'admin ' . ($A['user'] ?? '?') . " $what (from $ip)");
}

function rcube_https()
{
    return \rcube_utils::https_check();
}

function size($b)
{
    if ($b === false || $b === null) {
        return '?';
    }
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
        if ($b < 1024) {
            return round($b, 1) . " $u";
        }
        $b /= 1024;
    }

    return round($b, 1) . ' PB';
}


function flash($level, $text)
{
    $GLOBALS['A']['flash'] = [$level, $text];
}

function flash_html($style = 'adm')
{
    global $A;
    if (empty($A['flash'])) {
        return '';
    }
    [$level, $text] = $A['flash'];
    unset($A['flash']);
    if ($style === 'setup') {
        return '<div class="setup-note' . ($level === 'ok' ? ' ok' : '') . ($level === 'fail' ? ' admin-fail' : '') . '" role="status"><p>' . h($text) . '</p></div>';
    }
    $tone = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'fail'][$level] ?? 'info';

    return '<div class="adm-flash ' . $tone . '" role="status">' . icon(['ok' => 'circle-check', 'warn' => 'triangle-alert', 'fail' => 'circle-x'][$level] ?? 'info') . '<p>' . h($text) . '</p></div>';
}

function back($view = null, array $more = [])
{
    header('Location: ' . self_url(($view ? ['view' => $view] : []) + $more), true, 303);
    exit;
}

function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function form($do, $inner, $class = 'setup-form')
{
    return '<form method="post" class="' . $class . '"><input type="hidden" name="csrf" value="' . h($GLOBALS['A']['csrf']) . '"><input type="hidden" name="do" value="' . h($do) . '">' . $inner . '</form>';
}

