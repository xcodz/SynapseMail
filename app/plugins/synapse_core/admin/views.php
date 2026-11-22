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
 * Admin panel views: dashboard shell (sidebar, top bar, cards) and one function per section.
 * Built only from the template's tokens (login.css) and fonts; styles in admin.css.
 */

namespace SynapseMail\Admin;

use synapse_core;
use synapse_deliverability;
use synapse_platform;

const NAV = [
    'MONITOR' => ['overview' => ['Overview', 'layout-dashboard'], 'deliverability' => ['Deliverability', 'mail-check'], 'logs' => ['Logs', 'scroll-text']],
    'MANAGE' => ['users' => ['Users', 'users'], 'security' => ['Security', 'shield-check'], 'backup' => ['Backup', 'archive']],
    'ACCOUNT' => ['account' => ['Admin account', 'user-cog']],
];

/* ---------------- building blocks ---------------- */

function icon($name)
{
    return '<i data-lucide="' . h($name) . '" aria-hidden="true"></i>';
}

function badge($tone, $text)
{
    return '<span class="adm-badge ' . h($tone) . '">' . h($text) . '</span>';
}

function card($title, $body, array $o = [])
{
    return '<section class="adm-card' . (isset($o['class']) ? ' ' . $o['class'] : '') . '">'
        . '<header class="adm-card-h">' . (isset($o['icon']) ? icon($o['icon']) : '') . '<h2>' . h($title) . '</h2>'
        . (isset($o['meta']) ? '<div class="adm-card-meta">' . $o['meta'] . '</div>' : '') . '</header>'
        . '<div class="adm-card-b">' . $body . '</div></section>';
}

function kpi($label, $value, $sub, $icon, $tone = '', $pct = null, $href = null)
{
    $tag = $href ? 'a href="' . h($href) . '"' : 'div';

    return '<' . $tag . ' class="adm-kpi ' . h($tone) . '">' . icon($icon) . '<span class="adm-kpi-label">' . h($label) . '</span>'
        . '<strong class="adm-kpi-value">' . h($value) . '</strong>'
        . ($pct !== null ? '<span class="adm-bar"><i data-pct="' . (int) $pct . '"></i></span>' : '')
        . '<span class="adm-kpi-sub">' . h($sub) . '</span></' . ($href ? 'a' : 'div') . '>';
}

function input($label, $name, array $o = [])
{
    $attrs = '';
    foreach (($o['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . $k . ($v === true ? '' : '="' . h($v) . '"');
    }

    return '<label class="adm-field"><span>' . h($label) . '</span><input class="adm-input" name="' . h($name) . '" type="' . h($o['type'] ?? 'text') . '"'
        . (isset($o['value']) ? ' value="' . h($o['value']) . '"' : '') . $attrs . '>'
        . (isset($o['hint']) ? '<small>' . h($o['hint']) . '</small>' : '') . '</label>';
}

function select($label, $name, array $options, $selected, $hint = null)
{
    $opts = '';
    foreach ($options as $k => $text) {
        $opts .= '<option value="' . h($k) . '"' . ((string) $k === (string) $selected ? ' selected' : '') . '>' . h($text) . '</option>';
    }

    return '<label class="adm-field"><span>' . h($label) . '</span><select name="' . h($name) . '" data-ui-select>' . $opts . '</select>'
        . ($hint ? '<small>' . h($hint) . '</small>' : '') . '</label>';
}

function button($text, array $o = [])
{
    $attrs = '';
    foreach (($o['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . $k . '="' . h($v) . '"';
    }

    return '<button class="adm-btn ' . h($o['tone'] ?? 'primary') . (isset($o['size']) ? ' ' . $o['size'] : '') . '" type="' . h($o['type'] ?? 'submit') . '"' . $attrs . '>'
        . (isset($o['icon']) ? icon($o['icon']) : '') . '<span>' . h($text) . '</span></button>';
}

function status_row($level, $label, $detail, $state, $extra = '')
{
    $ico = ['pass' => 'circle-check', 'warn' => 'triangle-alert', 'fail' => 'circle-x', 'pending' => 'circle-dashed'][$level] ?? 'circle';
    $tone = ['pass' => 'ok', 'warn' => 'warn', 'fail' => 'fail', 'pending' => 'off'][$level] ?? 'off';

    return '<li class="adm-status ' . $tone . '">' . icon($ico) . '<div><b>' . h($label) . '</b><small>' . h($detail) . '</small></div>' . badge($tone, $state) . $extra . '</li>';
}

function empty_state($icon, $title, $text)
{
    return '<div class="adm-empty">' . icon($icon) . '<b>' . h($title) . '</b><p>' . h($text) . '</p></div>';
}

/* ---------------- data ---------------- */

function user_stats($db)
{
    $now = time();
    $sessions = session_counts($db);
    $res = $db->query('SELECT `user_id`, `username`, `created`, `last_login`, `preferences` FROM ' . $db->table_name('users', true) . ' ORDER BY `last_login` DESC LIMIT 1000');
    $out = ['total' => 0, 'otp' => 0, 'pin' => 0, 'locked' => 0, 'online' => 0, 'rows' => []];
    while ($u = $db->fetch_assoc($res)) {
        $p = @unserialize((string) $u['preferences'], ['allowed_classes' => false]) ?: [];
        $row = ['id' => (int) $u['user_id'], 'name' => $u['username'], 'last' => $u['last_login'], 'created' => $u['created'],
            'otp' => !empty($p['synapse_otp']['secret']), 'pin' => !empty($p['synapse_pin']['hash']),
            'locked' => (int) ($p['synapse_pin_lock'] ?? 0) > $now, 'sessions' => $sessions[(int) $u['user_id']] ?? 0];
        $out['total']++;
        $out['otp'] += $row['otp'];
        $out['pin'] += $row['pin'];
        $out['locked'] += $row['locked'];
        $out['online'] += $row['sessions'] > 0;
        $out['rows'][] = $row;
    }

    return $out;
}

function blocked_count($db)
{
    return (int) $db->fetch_array($db->query('SELECT COUNT(*) FROM ' . $db->table_name('synapse_ratelimit', true) . ' WHERE `blocked_until` > ?', time()))[0];
}

/** Newest log lines as [time, session, message] */
function log_entries($RC, $log, $limit)
{
    if ($RC->config->get('log_driver', 'file') !== 'file') {
        return null;
    }
    $file = rtrim($RC->config->get('log_dir', INSTALL_PATH . 'logs'), '/') . '/' . $log . $RC->config->get('log_file_ext', '.log');
    if (!is_readable($file)) {
        return [];
    }
    // shortcut: last 128 KB only, add paging if admins need older entries
    $fp = fopen($file, 'r');
    fseek($fp, max(0, filesize($file) - 131072));
    $text = stream_get_contents($fp);
    fclose($fp);
    $out = [];
    foreach (array_reverse(array_filter(explode("\n", $text), 'strlen')) as $line) {
        if (preg_match('/^\[([^\]]+)\]:\s*(?:<([^>]*)>\s*)?(.*)$/s', $line, $m)) {
            $out[] = [$m[1], $m[2], $m[3]];
        } elseif ($out) {
            $out[count($out) - 1][2] = $line . "\n" . $out[count($out) - 1][2]; // stack traces: continuation lines
        }
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

function short_time($t)
{
    $ts = strtotime(preg_replace('/ \+0000$/', ' UTC', $t));

    return $ts ? gmdate('M j, H:i', $ts) : $t;
}

/* ---------------- shell ---------------- */

/** Asset URL with its modification time, so upgrades are never served from an old browser cache */
function asset($path)
{
    return $GLOBALS['BASE'] . 'static.php/' . $path . '?v=' . @filemtime(INSTALL_PATH . $path);
}


function shell($view, $title, $subtitle, $content, $actions = '')
{
    global $A;
    $nav = '';
    foreach (NAV as $group => $items) {
        $nav .= '<div class="adm-nav-group"><span>' . $group . '</span>';
        foreach ($items as $k => [$label, $ico]) {
            $nav .= '<a class="adm-nav-link' . ($k === $view ? ' active' : '') . '" href="' . h(self_url(['view' => $k])) . '"' . ($k === $view ? ' aria-current="page"' : '') . '>' . icon($ico) . '<span>' . h($label) . '</span></a>';
        }
        $nav .= '</div>';
    }
    $base = $GLOBALS['BASE'];

    head('Admin · ' . $title, 'adm-app');
    echo '<div class="adm-shell">'
        . '<aside class="adm-side" id="admSide"><div class="adm-brand"><span class="brand-mark" aria-hidden="true"></span><span><strong>SynapseMail</strong><small>ADMIN CONSOLE</small></span></div>'
        . '<nav class="adm-nav" aria-label="Admin sections">' . $nav . '</nav>'
        . '<div class="adm-side-foot"><a class="adm-nav-link" href="' . h($base) . '" target="_blank" rel="noopener">' . icon('external-link') . '<span>Open webmail</span></a>'
        . form('logout', '<button class="adm-nav-link" type="submit">' . icon('log-out') . '<span>Sign out</span></button>', 'adm-logout')
        . '<small>v' . h(synapse_core::VERSION) . ' · shared edition</small></div></aside>'
        . '<div class="adm-main"><header class="adm-top"><button class="adm-burger" type="button" data-toggle-nav aria-label="Menu">' . icon('menu') . '</button>'
        . '<div class="adm-title"><span class="adm-crumb">ADMIN / ' . h(strtoupper($title)) . '</span><h1>' . h($title) . '</h1>' . ($subtitle ? '<p>' . h($subtitle) . '</p>' : '') . '</div>'
        . '<div class="adm-top-right">' . $actions
        . '<span class="adm-chip" title="Signs out after 15 minutes without activity">' . icon('timer') . '<b data-idle="' . IDLE . '">15:00</b></span>'
        . '<span class="adm-chip">' . icon('user-round') . '<b>' . h($A['user'] ?? '') . '</b></span></div></header>'
        . '<main class="adm-content">' . flash_html() . $content . '</main></div></div>';
    foot();
}

function head($title, $class)
{
    $base = $GLOBALS['BASE'];
    echo '<!DOCTYPE html><html lang="en" data-theme="agency"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>SynapseMail - ' . h($title) . '</title>'
        . '<link rel="icon" type="image/svg+xml" href="' . asset('skins/synapse/assets/synapse-cyber-logo.svg') . '">'
        . '<link rel="stylesheet" href="' . asset('skins/synapse/vendor/fonts.css') . '"><link rel="stylesheet" href="' . asset('skins/synapse/login.css') . '">'
        . '<link rel="stylesheet" href="' . asset('plugins/synapse_core/installer/installer.css') . '"><link rel="stylesheet" href="' . asset('plugins/synapse_core/admin/admin.css') . '"></head>'
        . '<body class="' . $class . '">';
}

function foot($field = false)
{
    $base = $GLOBALS['BASE'];
    echo '<script src="' . asset('skins/synapse/vendor/lucide.min.js') . '"></script>'
        . '<script src="' . asset('plugins/synapse_core/installer/installer.js') . '"></script>'
        . ($field ? '<script src="' . asset('skins/synapse/login-field.js') . '"></script>' : '')
        . '<script src="' . asset('plugins/synapse_core/admin/admin.js') . '"></script></body></html>';
}

/** Sign-in page: the template's access layout with the secure-transit background */
function signin_page($body)
{
    head('Admin · Sign in', 'setup-page admin-page admin-signin');
    echo '<div class="atmosphere" aria-hidden="true"><div class="grid-plane"></div><div class="topo-map"></div><div class="scanline"></div></div><div class="noise" aria-hidden="true"></div>'
        . '<header class="access-header"><span class="brand"><span class="brand-mark" aria-hidden="true"></span><span><strong>SynapseMail</strong><small>ADMIN CONSOLE</small></span></span>'
        . '<div class="gateway-state"><span class="pulse"></span><span>ADMIN</span><strong>LOCKED</strong></div><time>v' . h(synapse_core::VERSION) . '</time></header>'
        . '<main class="login-stage"><section class="mission-brief"><div class="eyebrow"><span>ADMIN</span><i></i><span>SHARED HOSTING EDITION</span></div>'
        . '<h1 class="glitch-title" data-text="CONTROL ROOM.">CONTROL ROOM.</h1><p>Health, users, security policy, deliverability, backups and logs of this SynapseMail install. Separate from every mailbox.</p></section>'
        . '<section class="auth-shell" aria-labelledby="adminTitle"><div class="corner corner-tl"></div><div class="corner corner-tr"></div><div class="corner corner-bl"></div><div class="corner corner-br"></div>'
        . '<div class="auth-heading"><div class="clearance-icon"><span></span></div><div><span>ADMIN // SIGN IN</span><h2 id="adminTitle">SIGN IN</h2></div><b>2FA</b></div>'
        . '<div class="setup-body">' . $body . '</div>'
        . '<footer class="auth-footer"><span>SYNAPSEMAIL ADMIN</span><span>EVERY ACTION IS LOGGED</span></footer></section></main>';
    foot(true);
}

/* ---------------- sections ---------------- */

function view_moved($url, $fallback)
{
    return '<div class="adm-grid"><div class="adm-span-2">' . card('Your admin panel has a new private address', '<p class="adm-lead">From now on the admin panel only answers at this address. Everyone else, including bots that try <code>admin.php</code>, gets "404 Not Found".</p>'
        . '<div class="adm-secret"><code id="newAdminUrl">' . h($url) . '</code>' . button('Copy', ['tone' => 'ghost', 'size' => 'sm', 'type' => 'button', 'icon' => 'copy', 'attrs' => ['data-copy' => $url]]) . '</div>'
        . '<p class="adm-hint">Server without .htaccess support (nginx)? Use <code>' . h($fallback) . '</code>. Both are also saved in <code>config/ADMIN-URL.txt</code> (File Manager).</p>'
        . '<ol class="adm-steps"><li>Copy the address into your password manager or bookmarks.</li><li><a href="' . h($url) . '" target="_blank" rel="noopener">Open it in a new tab</a> to check that it works on this server.</li><li>Confirm below. The old address stops working.</li></ol>'
        . form('confirm_url', '<label class="adm-check"><input type="checkbox" name="saved" value="1" required data-unlock-btn="confirmUrl"><span></span>I saved the new admin address</label>'
            . button('Continue to the dashboard', ['icon' => 'arrow-right', 'attrs' => ['id' => 'confirmUrl', 'disabled' => 'disabled']]), 'adm-form'),
        ['icon' => 'key-round', 'class' => 'adm-highlight']) . '</div></div>';
}

function view_overview($RC, $db)
{
    $st = user_stats($db);
    $sess = count(sessions($db));
    $blocked = blocked_count($db);
    $health = array_merge([update_status($RC)], health($RC, $db));
    $issues = count(array_filter($health, static function ($c) { return in_array($c[0], ['fail', 'warn'], true); }));
    $best = null;
    foreach (mail_domains($db) as $d) {
        $c = json_decode((string) synapse_core::setting('deliv:' . $d), true);
        if ($c && ($best === null || $c['score'] < $best[1])) {
            $best = [$d, $c['score']]; // the weakest domain is the one that needs attention
        }
    }
    $pct = $st['total'] ? (int) round($st['otp'] * 100 / $st['total']) : 0;

    $kpis = '<div class="adm-kpis">'
        . kpi('Mailboxes', (string) $st['total'], $sess . ' active session' . ($sess === 1 ? '' : 's') . ' · ' . $st['online'] . ' online', 'users', '', null, self_url(['view' => 'users']))
        . kpi('2FA adoption', $pct . '%', $st['otp'] . ' of ' . $st['total'] . ' mailboxes · ' . $st['pin'] . ' with passcode', 'shield-check', $pct >= 50 ? 'ok' : 'warn', $pct)
        . kpi('Blocked now', (string) $blocked, $blocked ? 'accounts or IPs on a 15-minute block' : 'no active sign-in blocks', 'shield-alert', $blocked ? 'warn' : 'ok', null, self_url(['view' => 'security']))
        . kpi('Deliverability', $best ? $best[1] . '/100' : '—', $best ? 'lowest: ' . $best[0] : 'not checked yet', 'mail-check', $best ? ($best[1] >= 80 ? 'ok' : ($best[1] >= 50 ? 'warn' : 'fail')) : '', $best ? $best[1] : null, self_url(['view' => 'deliverability']))
        . '</div>';

    $rows = '';
    foreach ($health as [$level, $label, $detail, $state]) {
        $rows .= status_row($level, $label, $detail, $state);
    }
    $health_card = card('System health', '<ul class="adm-statuses">' . $rows . '</ul>'
        . '<p class="adm-hint">Something to fix? Steps for each hosting panel: <a href="' . h(synapse_platform::DOCS) . '" target="_blank" rel="noopener noreferrer">troubleshooting guide</a>.</p>', [
        'icon' => 'activity',
        'meta' => $issues ? badge('warn', $issues . ' to review') : badge('ok', 'all good'),
    ]);

    $events = log_entries($RC, 'synapse_security', 8) ?: [];
    $list = '';
    foreach ($events as [$t, , $msg]) {
        $tone = preg_match('/fail|refused|rate limit|denied/i', $msg) ? 'warn' : (preg_match('/^admin /', $msg) ? 'info' : 'ok');
        $list .= '<li class="adm-event ' . $tone . '"><i></i><div><b>' . h($msg) . '</b><time>' . h(short_time($t)) . '</time></div></li>';
    }
    $events_card = card('Recent security events', $list ? '<ul class="adm-events">' . $list . '</ul>' : empty_state('scroll-text', 'No events yet', 'Sign-ins, blocks and admin actions appear here.'), [
        'icon' => 'radar',
        'meta' => '<a class="adm-link" href="' . h(self_url(['view' => 'logs'])) . '">All logs ' . icon('arrow-right') . '</a>',
    ]);

    $quick = card('Quick actions', '<div class="adm-quick">'
        . '<a href="' . h(self_url(['view' => 'backup'])) . '">' . icon('archive') . '<span>Create a backup</span></a>'
        . '<a href="' . h(self_url(['view' => 'deliverability'])) . '">' . icon('mail-check') . '<span>Check DNS records</span></a>'
        . '<a href="' . h(self_url(['view' => 'users'])) . '">' . icon('user-x') . '<span>Reset a user\'s 2FA</span></a>'
        . '<a href="' . h(self_url(['view' => 'security'])) . '">' . icon('shield-check') . '<span>2FA policy</span></a></div>'
        . form('update_check', button('Check for updates', ['tone' => 'ghost', 'size' => 'sm', 'icon' => 'refresh-cw']), 'adm-inline'), ['icon' => 'zap']);

    return $kpis . '<div class="adm-grid"><div class="adm-span-2">' . $health_card . '</div><div class="adm-stack">' . $quick . $events_card . '</div></div>';
}

function view_users($db)
{
    $st = user_stats($db);
    if (!$st['total']) {
        return card('Mailboxes', empty_state('users', 'No users yet', 'Mailboxes appear here after their first sign-in to SynapseMail.'), ['icon' => 'users']);
    }
    $rows = '';
    foreach ($st['rows'] as $u) {
        $uid = '<input type="hidden" name="uid" value="' . $u['id'] . '">';
        $acts = ($u['sessions'] ? form('end_sessions', $uid . button('End sessions', ['tone' => 'ghost', 'size' => 'sm', 'icon' => 'log-out']), 'adm-inline') : '')
            . ($u['pin'] ? form('reset_pin', $uid . button('Reset passcode', ['tone' => 'ghost', 'size' => 'sm', 'icon' => 'key-round', 'attrs' => ['data-confirm' => 'Remove the passcode of ' . $u['name'] . '? Their sessions end too.']]), 'adm-inline') : '')
            . ($u['otp'] ? form('reset_2fa', $uid . button('Reset 2FA', ['tone' => 'danger', 'size' => 'sm', 'icon' => 'shield-off', 'attrs' => ['data-confirm' => 'Remove 2FA of ' . $u['name'] . '? They can set it up again; their sessions end.']]), 'adm-inline') : '')
            . ($u['locked'] ? form('unlock', $uid . button('Unlock', ['tone' => 'ghost', 'size' => 'sm', 'icon' => 'lock-open']), 'adm-inline') : '');
        [$local, $domain] = array_pad(explode('@', $u['name'], 2), 2, '');
        $rows .= '<tr data-search="' . h(strtolower($u['name'])) . '" data-tags="' . ($u['otp'] ? 'otp ' : 'nootp ') . ($u['sessions'] ? 'online ' : '') . ($u['locked'] ? 'locked' : '') . '">'
            . '<td><div class="adm-user"><span class="adm-avatar">' . h(strtoupper(mb_substr($local, 0, 1))) . '</span><div><b>' . h($local) . '</b><small>@' . h($domain) . '</small></div></div></td>'
            . '<td><span class="adm-mono">' . h($u['last'] ? short_time($u['last'] . ' +0000') : 'never') . '</span></td>'
            . '<td>' . ($u['otp'] ? badge('ok', '2FA on') : badge('off', 'off')) . '</td>'
            . '<td>' . ($u['locked'] ? badge('fail', 'locked') : ($u['pin'] ? badge('ok', 'on') : badge('off', 'none'))) . '</td>'
            . '<td>' . ($u['sessions'] ? badge('info', $u['sessions'] . ' online') : '<span class="adm-dim">—</span>') . '</td>'
            . '<td class="adm-actions"><div class="adm-acts">' . ($acts ?: '<span class="adm-dim">—</span>') . '</div></td></tr>';
    }
    $filters = '';
    foreach (['all' => 'All ' . $st['total'], 'online' => 'Online ' . $st['online'], 'otp' => '2FA on ' . $st['otp'], 'nootp' => 'Without 2FA ' . ($st['total'] - $st['otp']), 'locked' => 'Locked ' . $st['locked']] as $k => $label) {
        $filters .= '<button type="button" class="' . ($k === 'all' ? 'active' : '') . '" data-filter="' . $k . '">' . h($label) . '</button>';
    }

    return '<div class="adm-kpis small">'
        . kpi('Mailboxes', (string) $st['total'], 'signed in at least once', 'users')
        . kpi('Online now', (string) $st['online'], 'with an active session', 'radio', $st['online'] ? 'ok' : '')
        . kpi('2FA', (string) $st['otp'], 'authenticator enabled', 'shield-check', '', $st['total'] ? round($st['otp'] * 100 / $st['total']) : 0)
        . kpi('Locked', (string) $st['locked'], 'after too many wrong passcodes', 'lock', $st['locked'] ? 'warn' : '')
        . '</div>'
        . card('Mailboxes', '<div class="adm-toolbar"><label class="adm-search">' . icon('search') . '<input type="search" placeholder="Search mailboxes" data-table-search="usersTable" aria-label="Search mailboxes"></label>'
            . '<div class="adm-seg" data-table-filter="usersTable">' . $filters . '</div></div>'
            . '<div class="adm-table-wrap"><table class="adm-table" id="usersTable"><thead><tr><th>Mailbox</th><th>Last sign-in</th><th>2FA</th><th>Passcode</th><th>Sessions</th><th class="adm-actions">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p class="adm-noresult" hidden>No mailbox matches.</p></div>'
            . '<p class="adm-hint">Resetting a passcode or 2FA also ends that user\'s sessions. Mail and mailbox passwords are never touched here.</p>', ['icon' => 'users', 'meta' => badge('off', $st['total'] . ' total')]);
}

function view_security($RC, $db)
{
    global $ADMIN_URL;
    $policy = synapse_core::setting('2fa_policy') ?? $RC->config->get('synapse_2fa_policy', 'optional');
    $blocked = blocked_count($db);
    $fallback = preg_replace('#/admin/([^/]+)$#', '/admin.php?k=$1', $ADMIN_URL);

    $brand = card('Login page', form('service_name', input('Mail service name', 'service', ['value' => synapse_core::service_name(), 'hint' => 'Shown on the login page under "Mail service". 2–40 characters.', 'attrs' => ['required' => true, 'minlength' => 2, 'maxlength' => 40, 'spellcheck' => 'false']])
        . '<div class="adm-form-actions">' . button('Save name', ['icon' => 'save']) . '</div>', 'adm-form'), ['icon' => 'monitor-smartphone']);

    $otp = card('Two-factor authentication', form('policy', select('Policy for mailbox users', 'policy', [
            'off' => 'Off · nobody can use 2FA',
            'optional' => 'Optional · users decide in Settings › Security',
            'required' => 'Required · set up at next sign-in',
        ], $policy, 'The admin account always uses 2FA. "Required" asks every user without 2FA to set it up right after signing in.')
        . '<div class="adm-form-actions">' . button('Save policy', ['icon' => 'save']) . '</div>', 'adm-form'), ['icon' => 'smartphone', 'meta' => badge($policy === 'off' ? 'off' : 'ok', $policy)]);

    $limits = card('Sign-in protection', '<div class="adm-big ' . ($blocked ? 'warn' : 'ok') . '"><strong>' . $blocked . '</strong><span>accounts or IPs blocked right now</span></div>'
        . '<ul class="adm-facts"><li><b>5</b> wrong passwords per account and IP</li><li><b>20</b> per IP for any accounts</li><li><b>20</b> per account from any IPs</li><li><b>5</b> password changes per account</li><li>Blocks last <b>15 minutes</b></li></ul>'
        . form('clear_blocks', button('Clear all blocks now', ['tone' => 'danger', 'icon' => 'eraser', 'attrs' => ['data-confirm' => 'Clear every sign-in block and counter?']]), 'adm-form'), ['icon' => 'shield-alert']);

    $access = card('Admin panel address', '<p class="adm-lead">Only this address opens the admin panel; anything else answers "404 Not Found".</p>'
        . '<div class="adm-secret"><code data-reveal>' . h($ADMIN_URL) . '</code>' . button('Show', ['tone' => 'ghost', 'size' => 'sm', 'type' => 'button', 'icon' => 'eye', 'attrs' => ['data-reveal-btn' => '1']]) . button('Copy', ['tone' => 'ghost', 'size' => 'sm', 'type' => 'button', 'icon' => 'copy', 'attrs' => ['data-copy' => $ADMIN_URL]]) . '</div>'
        . '<p class="adm-hint">Without .htaccess support: <code data-reveal>' . h($fallback) . '</code>. Also stored in <code>config/ADMIN-URL.txt</code>.</p>'
        . form('rotate_key', button('Generate a new address', ['tone' => 'ghost', 'icon' => 'refresh-cw', 'attrs' => ['data-confirm' => 'Create a new admin address? The current one stops working immediately; the new one is shown and saved in config/ADMIN-URL.txt.']]), 'adm-form'), ['icon' => 'key-round']);

    return '<div class="adm-grid">' . $access . $otp . $brand . $limits . '</div>';
}

function view_deliverability($RC, $db)
{
    $domains = mail_domains($db);
    if (!$domains) {
        return card('Deliverability', empty_state('mail-check', 'No mail domain yet', 'Domains appear after a mailbox has signed in.'), ['icon' => 'mail-check']);
    }
    $domain = in_array($_GET['domain'] ?? '', $domains, true) ? $_GET['domain'] : $domains[0];
    $r = deliverability($RC, $domain);
    $score = $r['score'];
    $tone = $score === null ? 'off' : ($score >= 80 ? 'ok' : ($score >= 50 ? 'warn' : 'fail'));

    $picker = '<form method="get" class="adm-picker">' . (isset($_GET['k']) && preg_match('#admin\.php$#', strtok($_SERVER['REQUEST_URI'], '?')) ? '<input type="hidden" name="k" value="' . h($_GET['k']) . '">' : '')
        . '<input type="hidden" name="view" value="deliverability">'
        . select('Domain', 'domain', array_combine($domains, $domains), $domain) . '</form>'
        . form('recheck', '<input type="hidden" name="domain" value="' . h($domain) . '">' . button('Check again', ['tone' => 'ghost', 'icon' => 'refresh-cw']), 'adm-inline');

    $badges = '';
    foreach ($r['badges'] as $name => $on) {
        $badges .= badge($on ? 'ok' : 'off', $name);
    }
    $summary = card('Score', '<div class="adm-ring ' . $tone . '" data-pct="' . (int) $score . '"><div><strong>' . ($score === null ? '—' : $score) . '</strong><span>/ 100</span></div></div>'
        . '<dl class="adm-dl"><dt>Domain</dt><dd>' . h($domain) . '</dd><dt>Sending IP</dt><dd>' . h($r['ip'] ?: 'unknown') . '</dd><dt>Checked</dt><dd>' . h(gmdate('M j, H:i', $r['time'])) . ' UTC</dd></dl>'
        . '<div class="adm-badges">' . $badges . '</div>'
        . '<p class="adm-hint">Read-only. Records are changed at your DNS host (cPanel › Zone Editor, Cloudflare…). Checks shown as N/A do not lower the score.</p>', ['icon' => 'gauge', 'class' => 'adm-score-card']);

    $rows = '';
    foreach ($r['checks'] as $k => $c) {
        $level = ['pass' => 'pass', 'partial' => 'warn', 'fail' => 'fail', 'unknown' => 'pending'][$c[0]];
        $state = ['pass' => 'PASS', 'partial' => 'PARTIAL', 'fail' => 'FAIL', 'unknown' => 'N/A'][$c[0]];
        $fix = empty($c[2]) ? '' : '<div class="adm-fix"><code>' . h($c[2]) . '</code>' . button('Copy record', ['tone' => 'ghost', 'size' => 'sm', 'type' => 'button', 'icon' => 'copy', 'attrs' => ['data-copy' => $c[2]]]) . '</div>';
        $rows .= status_row($level, synapse_deliverability::LABELS[$k] . ' · ' . synapse_deliverability::WEIGHTS[$k] . ' pts', $c[1], $state, $fix);
    }
    $checks = card('Checks', '<ul class="adm-statuses wide">' . $rows . '</ul>', ['icon' => 'list-checks']);

    return '<div class="adm-toolbar adm-toolbar-top">' . $picker . '</div><div class="adm-grid adm-grid-deliv">' . $summary . '<div class="adm-span-2">' . $checks . '</div></div>';
}

function view_backup($A)
{
    if (!empty($A['backup_key'])) {
        $create = card('Backup ready', '<p class="adm-lead">Download the file, then store this key somewhere else, for example in a password manager. It is shown only now. Without it, nobody can open the backup, not even us.</p>'
            . '<div class="adm-secret big"><code>' . h($A['backup_key']) . '</code>' . button('Copy key', ['tone' => 'ghost', 'size' => 'sm', 'type' => 'button', 'icon' => 'copy', 'attrs' => ['data-copy' => $A['backup_key']]]) . '</div>'
            . form('backup_download', button('Download ' . size($A['backup']['size']), ['icon' => 'download']), 'adm-form')
            . '<p class="adm-hint">The file can be downloaded once and is deleted from the server within an hour.</p>', ['icon' => 'archive', 'class' => 'adm-highlight']);
    } else {
        $create = card('Create a backup', '<p class="adm-lead">An encrypted copy (AES-256-GCM) of everything SynapseMail stores itself.</p>'
            . '<ul class="adm-facts"><li>Users, settings, identities and contacts</li><li>Passcodes, 2FA and recovery codes</li><li>The admin account and the encryption key</li><li><b>Not included:</b> mail. It stays on the mail server.</li></ul>'
            . form('backup_create', button('Create backup', ['icon' => 'archive']), 'adm-form'), ['icon' => 'archive']);
    }
    $restore = card('Restore', '<p class="adm-lead">Replaces all app data with a backup and signs everyone out. The current data is saved first as <code>db/before-restore-….db</code>; this install\'s mail server settings are kept.</p>'
        . '<form method="post" enctype="multipart/form-data" class="adm-form"><input type="hidden" name="csrf" value="' . h($A['csrf']) . '"><input type="hidden" name="do" value="restore">'
        . '<label class="adm-field"><span>Backup file (.smbk)</span><input class="adm-input adm-file" name="file" type="file" accept=".smbk" required><small>Largest upload PHP allows here: ' . h(ini_get('upload_max_filesize')) . '</small></label>'
        . input('Backup key', 'key', ['attrs' => ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false', 'placeholder' => 'XXXX XXXX XXXX …']])
        . input('Your authenticator code', 'code', ['attrs' => ['required' => true, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'pattern' => '[0-9 ]{6,7}', 'maxlength' => 7, 'placeholder' => '000 000']])
        . '<div class="adm-form-actions">' . button('Restore', ['tone' => 'danger', 'icon' => 'history', 'attrs' => ['data-confirm' => 'Replace all SynapseMail data with this backup and sign everyone out?']]) . '</div></form>', ['icon' => 'history']);

    return '<div class="adm-grid">' . $create . $restore . '</div>';
}

function view_logs($RC)
{
    $log = isset(LOGS[$_GET['log'] ?? '']) ? $_GET['log'] : 'synapse_security';
    $tabs = '';
    foreach (LOGS as $k => $label) {
        $tabs .= '<a class="' . ($k === $log ? 'active' : '') . '" href="' . h(self_url(['view' => 'logs', 'log' => $k])) . '">' . h($label) . '</a>';
    }
    $entries = log_entries($RC, $log, 300);
    if ($entries === null) {
        $body = empty_state('scroll-text', 'Logs go to ' . $RC->config->get('log_driver'), 'Only file logs can be shown here.');
    } elseif (!$entries) {
        $body = empty_state('scroll-text', 'No entries yet', 'This log is empty.');
    } else {
        $rows = '';
        foreach ($entries as [$t, $sid, $msg]) {
            $tone = preg_match('/error|fail|refused|fatal|denied/i', $msg) ? 'warn' : '';
            $rows .= '<tr class="' . $tone . '" data-search="' . h(strtolower($msg . ' ' . $sid)) . '"><td class="adm-mono adm-nowrap">' . h(short_time($t)) . '</td><td class="adm-mono adm-dim">' . h($sid) . '</td><td class="adm-logmsg">' . h($msg) . '</td></tr>';
        }
        $body = '<div class="adm-toolbar"><label class="adm-search">' . icon('search') . '<input type="search" placeholder="Filter entries" data-table-search="logTable" aria-label="Filter entries"></label><span class="adm-dim adm-mono">' . count($entries) . ' newest entries</span></div>'
            . '<div class="adm-table-wrap"><table class="adm-table adm-log" id="logTable"><thead><tr><th>Time (UTC)</th><th>Session</th><th>Entry</th></tr></thead><tbody>' . $rows . '</tbody></table><p class="adm-noresult" hidden>No entry matches.</p></div>';
    }

    return '<nav class="adm-tabs" aria-label="Logs">' . $tabs . '</nav>' . card(LOGS[$log], $body, ['icon' => 'scroll-text']);
}

function view_account($db, $admins, &$A)
{
    $row = admin_row($db, $admins, $A['id']);
    $left = count((array) json_decode($row['recovery_codes'] ?? '[]', true));
    $codes = '';
    if (!empty($A['show_codes'])) {
        foreach ($A['show_codes'] as $c) {
            $codes .= '<code>' . h($c) . '</code>';
        }
        unset($A['show_codes']);
        $left = 8;
        $codes = '<div class="adm-flash ok">' . icon('circle-check') . '<p>New recovery codes. The old ones no longer work. Save these now: they are shown only once.</p></div><div class="setup-codes" id="recoveryCodes">' . $codes . '</div>'
            . '<button type="button" class="adm-btn ghost sm" id="copyCodes">' . icon('copy') . '<span>Copy codes</span></button>';
    }
    $profile = card('Administrator', '<div class="adm-user big"><span class="adm-avatar">' . h(strtoupper(substr($row['username'] ?? '?', 0, 1))) . '</span><div><b>' . h($row['username'] ?? '') . '</b><small>separate from every mailbox · 2FA required</small></div></div>'
        . '<dl class="adm-dl"><dt>Created</dt><dd>' . h($row['created_at'] ? gmdate('M j, Y', (int) $row['created_at']) : '—') . '</dd><dt>Last sign-in</dt><dd>' . h($row['last_login'] ? gmdate('M j, H:i', (int) $row['last_login']) . ' UTC' : '—') . '</dd>'
        . '<dt>Recovery codes</dt><dd>' . $left . ' of 8 left</dd></dl><span class="adm-bar"><i data-pct="' . (int) round($left * 100 / 8) . '"></i></span>', ['icon' => 'user-cog']);
    $password = card('Change password', form('admin_password', input('Current password', 'current', ['type' => 'password', 'attrs' => ['required' => true, 'autocomplete' => 'current-password']])
        . input('New password', 'new', ['type' => 'password', 'hint' => 'At least 12 characters.', 'attrs' => ['required' => true, 'minlength' => 12, 'autocomplete' => 'new-password']])
        . input('Repeat new password', 'new2', ['type' => 'password', 'attrs' => ['required' => true, 'minlength' => 12, 'autocomplete' => 'new-password']])
        . '<div class="adm-form-actions">' . button('Change password', ['icon' => 'key-round']) . '</div>', 'adm-form'), ['icon' => 'key-round']);
    $recovery = card('Recovery codes', $codes . '<p class="adm-lead">Each code signs you in once if you lose your phone. Creating new codes cancels the old ones.</p>'
        . form('admin_recovery', input('Authenticator code', 'code', ['attrs' => ['required' => true, 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'pattern' => '[0-9 ]{6,7}', 'maxlength' => 7, 'placeholder' => '000 000']])
            . '<div class="adm-form-actions">' . button('Create new codes', ['tone' => 'ghost', 'icon' => 'refresh-cw']) . '</div>', 'adm-form'), ['icon' => 'life-buoy']);

    return '<div class="adm-grid">' . $profile . $password . $recovery . '</div>';
}
