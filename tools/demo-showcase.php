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

// Demo build helper: the read-only admin panel's showcase data (fictional users, sessions, deliverability and logs).
// Usage: php tools/demo-showcase.php <demo package dir> <des_key>
if (PHP_SAPI !== 'cli') {
    exit(1);
}
[, $root, $des] = $argv;
require "$root/plugins/synapse_core/lib/synapse_secret.php";
require "$root/plugins/synapse_core/lib/synapse_totp.php";

$file = "$root/db/admin-demo.db";
@unlink($file);
$pdo = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents("$root/SQL/sqlite.initial.sql"));
$pdo->exec('CREATE TABLE synapse_admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username VARCHAR(64) NOT NULL UNIQUE,'
    . ' password_hash VARCHAR(255) NOT NULL, totp_secret TEXT NOT NULL, totp_last_step INTEGER NOT NULL DEFAULT 0,'
    . ' recovery_codes TEXT NOT NULL, created_at INTEGER NOT NULL, last_login INTEGER)');
$pdo->exec('CREATE TABLE synapse_settings (`k` varchar(64) NOT NULL PRIMARY KEY, `v` text NOT NULL)');
$pdo->exec('CREATE TABLE synapse_ratelimit (`k` varchar(64) NOT NULL PRIMARY KEY, `fails` integer NOT NULL DEFAULT 0,'
    . ' `since` integer NOT NULL DEFAULT 0, `blocked_until` integer NOT NULL DEFAULT 0)');
$pdo->prepare('INSERT INTO synapse_admins (username, password_hash, totp_secret, recovery_codes, created_at, last_login) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['admin', password_hash('demo', PASSWORD_DEFAULT), synapse_secret::encrypt(synapse_totp::secret(), $des), '[]', time() - 86400 * 40, time() - 3600]);

// fictional mailboxes: [name, days since created, minutes since last sign-in, 2FA, passcode, locked, sessions]
$people = [
    ['sara@northwind.example', 120, 12, true, true, false, 2], ['daniel@northwind.example', 118, 45, true, true, false, 1],
    ['maya@studio.example', 90, 180, true, false, false, 1], ['reza@pars.example', 80, 25, true, true, false, 1],
    ['layla@desert.example', 75, 600, false, true, false, 0], ['tom@harbor.example', 60, 2880, false, false, false, 0],
    ['aiko@sakura.example', 55, 90, true, true, false, 1], ['omar@desert.example', 40, 4320, false, false, true, 0],
    ['info@northwind.example', 200, 1440, true, false, false, 0], ['billing@northwind.example', 199, 7200, false, false, false, 0],
    ['hr@studio.example', 30, 300, true, true, false, 0], ['support@harbor.example', 20, 5, true, true, false, 1],
];
$u = $pdo->prepare('INSERT INTO users (username, mail_host, created, last_login, language, preferences) VALUES (?, ?, ?, ?, ?, ?)');
$s = $pdo->prepare('INSERT INTO session (sess_id, ip, vars, expires_at) VALUES (?, ?, ?, ?)');
$id = 0;
foreach ($people as [$name, $days, $mins, $otp, $pin, $locked, $sessions]) {
    $p = [];
    if ($otp) {
        $p['synapse_otp'] = ['secret' => 'demo', 'recovery' => [], 'enabled_at' => time() - 86400 * 10, 'last_step' => 0];
    }
    if ($pin) {
        $p['synapse_pin'] = ['hash' => 'demo', 'len' => 6, 'interval' => '7', 'hint' => ''];
    }
    if ($locked) {
        $p['synapse_pin_lock'] = time() + 3600 * 20;
    }
    $u->execute([$name, 'localhost', gmdate('Y-m-d H:i:s', time() - 86400 * $days), gmdate('Y-m-d H:i:s', time() - 60 * $mins), 'en_US', serialize($p)]);
    $id++;
    for ($i = 0; $i < $sessions; $i++) {
        $s->execute([bin2hex(random_bytes(16)), '192.0.2.' . (10 + $id), base64_encode('user_id|i:' . $id . ';username|s:' . strlen($name) . ':"' . $name . '";'),
            gmdate('Y-m-d H:i:s', time() + 86400 * 365)]);
    }
}
// one address and one IP on a 15-minute block, like after a password-guessing attempt
$rl = $pdo->prepare('INSERT INTO synapse_ratelimit (k, fails, since, blocked_until) VALUES (?, ?, ?, ?)');
$rl->execute(['uip:omar@desert.example|198.51.100.7', 10, time() - 600, time() + 86400 * 365]);
$rl->execute(['ip:203.0.113.45', 25, time() - 300, time() + 86400 * 365]);

// deliverability results (sample data, never looked up)
$set = $pdo->prepare('INSERT INTO synapse_settings (k, v) VALUES (?, ?)');
$deliv = static function ($domain, array $checks, $score) {
    return json_encode(['domain' => $domain, 'ip' => '192.0.2.25', 'time' => time() + 86400 * 365, 'checks' => $checks, 'score' => $score,
        'badges' => ['MTA-STS' => $score > 80, 'TLS-RPT' => $score > 80, 'BIMI' => false]]);
};
$good = ['mx' => ['pass', 'mail.%d (priority 10)'], 'ptr' => ['pass', '192.0.2.25 points to mail.%d'], 'spf' => ['pass', 'v=spf1 mx -all includes this server'],
    'dkim' => ['pass', 'DKIM key found (selector default, 2048-bit RSA)'], 'dmarc' => ['pass', 'p=quarantine with reports'],
    'tls' => ['pass', 'IMAP 993 and SMTP 465 use valid certificates'], 'dnsbl' => ['pass', 'Not listed on 4 public blacklists']];
foreach (['northwind.example' => 100, 'studio.example' => 79, 'pars.example' => 100, 'desert.example' => 59, 'harbor.example' => 100, 'sakura.example' => 100] as $d => $score) {
    $c = array_map(static function ($x) use ($d) { return [$x[0], str_replace('%d', $d, $x[1])]; }, $good);
    if ($score === 79) {
        $c['dmarc'] = ['fail', "No DMARC record: add _dmarc.$d", "_dmarc.$d. TXT \"v=DMARC1; p=none; rua=mailto:dmarc@$d\""];
        $c['dkim'] = ['partial', 'DKIM key found but only 1024-bit'];
    }
    if ($score === 59) {
        $c['spf'] = ['partial', 'SPF ends with ~all (softfail)'];
        $c['dkim'] = ['fail', 'No DKIM key for selector default'];
        $c['ptr'] = ['fail', '192.0.2.25 has no reverse DNS name'];
    }
    $set->execute(["deliv:$d", $deliv($d, $c, $score)]);
}
$set->execute(['2fa_policy', 'optional']);

// sample log files (admin process reads logs from here in the demo build)
$logs = "$root/db/admin-demo-logs";
@mkdir($logs, 0750);
$t = static function ($mins) { return gmdate('d-M-Y H:i:s O', time() - $mins * 60); };
file_put_contents("$logs/synapse_security.log", implode("\n", [
    "[{$t(400)}]: passcode set by reza@pars.example",
    "[{$t(300)}]: 2FA enabled by aiko@sakura.example",
    "[{$t(240)}]: passcode wrong (3 of 5) for omar@desert.example from 198.51.100.7",
    "[{$t(236)}]: account omar@desert.example locked for 1 hour after 10 wrong passcodes",
    "[{$t(120)}]: sign-in blocked (rate limit) for IP 203.0.113.45",
    "[{$t(60)}]: admin admin changed 2FA policy to optional (from 192.0.2.1)",
    "[{$t(15)}]: 2FA recovery code used by sara@northwind.example",
]) . "\n");
file_put_contents("$logs/userlogins.log", implode("\n", array_map(static function ($i) use ($t, $people) {
    return "[{$t(5 + $i * 37)}]: <" . substr(md5((string) $i), 0, 8) . '> Successful login for ' . $people[$i % count($people)][0] . ' (ID: ' . (1 + $i % count($people)) . ') from 192.0.2.' . (11 + $i % 12) . ' in session ' . substr(md5("s$i"), 0, 12);
}, range(0, 24))) . "\n");
file_put_contents("$logs/password.log", "[{$t(2000)}]: Password changed for user tom@harbor.example (ID: 6) from 192.0.2.16\n");
file_put_contents("$logs/errors.log", "[{$t(900)}]: <a1b2c3d4> IMAP Error: Login failed for unknown@northwind.example against localhost from 203.0.113.45\n");
echo "admin showcase ready\n";
