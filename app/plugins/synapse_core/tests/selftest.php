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

// CLI self-check for the 2FA and secret helpers: php plugins/synapse_core/tests/selftest.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../lib/synapse_totp.php';
require __DIR__ . '/../lib/synapse_secret.php';
require __DIR__ . '/../lib/synapse_probe.php';
require __DIR__ . '/../lib/synapse_deliverability.php';
require __DIR__ . '/../lib/synapse_backup.php';
require __DIR__ . '/../lib/synapse_update.php';

$fail = 0;
$check = static function ($ok, $what) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

// RFC 6238 appendix B, SHA-1 vectors (last 6 of the 8-digit values)
$secret = synapse_totp::base32_encode('12345678901234567890');
foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $t => $want) {
    $check(synapse_totp::code($secret, $t) === $want, "RFC 6238 T={$t}");
}
$check(synapse_totp::base32_decode(synapse_totp::base32_encode('any bytes!')) === 'any bytes!', 'base32 round trip');
$check(synapse_totp::verify($secret, '287082', 59 + 30) !== false, 'accepts previous step (clock drift)');
$check(synapse_totp::verify($secret, '287082', 59 + 90) === false, 'rejects codes two steps old');
$check(synapse_totp::verify($secret, 'abc123', 59) === false, 'rejects non-numeric input');
$check(count(array_unique(synapse_totp::recovery_codes())) === 8, 'eight distinct recovery codes');

$enc = synapse_secret::encrypt('TOTPSECRET', 'install-key');
$check(synapse_secret::decrypt($enc, 'install-key') === 'TOTPSECRET', 'secret round trip');
$check(synapse_secret::decrypt($enc, 'other-key') === null, 'wrong key is refused');
$check(synapse_secret::decrypt(substr($enc, 0, -2) . 'AA', 'install-key') === null, 'tampering is detected');

$in = [['192.0.2.7', '192.0.2.0/24', true], ['192.0.3.7', '192.0.2.0/24', false], ['192.0.2.7', '192.0.2.7', true], ['10.1.2.3', '10.0.0.0/9', true],
    ['10.200.2.3', '10.0.0.0/9', false], ['2001:db8::1', '2001:db8::/32', true], ['2001:db9::1', '2001:db8::/32', false], ['192.0.2.7', '2001:db8::/32', false]];
foreach ($in as [$ip, $net, $want]) {
    $check(synapse_deliverability::ip_in($ip, $net) === $want, "SPF range $ip in $net");
}
$check(synapse_deliverability::score(['mx' => ['pass', ''], 'dkim' => ['partial', ''], 'dnsbl' => ['unknown', '']]) === 67, 'score skips unknown, partial counts half');

// backup: round trip through a throw-away database, restore into a second one
$dir = sys_get_temp_dir() . '/smtest-' . bin2hex(random_bytes(4));
mkdir($dir);
$mk = static function ($file, $user) {
    $pdo = new PDO('sqlite:' . $file);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, username TEXT)');
    $pdo->exec('CREATE TABLE session (sess_id TEXT, vars TEXT)');
    $pdo->exec("INSERT INTO users (username) VALUES ('$user')");
    $pdo->exec("INSERT INTO session VALUES ('s1', 'x')");
};
$mk("$dir/a.db", 'alice@example.com');
$mk("$dir/b.db", 'bob@example.com');
file_put_contents("$dir/a.cfg", "<?php\n\$config['des_key'] = 'AAAAAAAAAAAAAAAAAAAAAAAA';\n");
file_put_contents("$dir/b.cfg", "<?php\n\$config['des_key'] = 'BBBBBBBBBBBBBBBBBBBBBBBB';\n\$config['imap_host'] = 'keep';\n");
[$file, $key] = synapse_backup::create("$dir/a.db", "$dir/a.cfg", 'test');
$check(strlen(preg_replace('/\s/', '', $key)) === 52, 'backup key is 52 characters');
$check(strpos($file, 'alice') === false, 'backup file is encrypted');
$bad = false;
try { synapse_backup::open($file, synapse_backup::key_to_text(random_bytes(32))); } catch (RuntimeException $e) { $bad = true; }
$check($bad, 'wrong backup key is refused');
$data = synapse_backup::open($file, strtolower($key));
$check(strpos(base64_decode($data['db']), 'alice') !== false, 'backup opens with its key (any case)');
synapse_backup::restore($data, "$dir/b.db", "$dir/b.cfg");
$pdo = new PDO("sqlite:$dir/b.db");
$check($pdo->query('SELECT group_concat(username) FROM users')->fetchColumn() === 'alice@example.com', 'restore replaces users');
$check((int) $pdo->query('SELECT COUNT(*) FROM session')->fetchColumn() === 0, 'restore ends all sessions');
$cfg = file_get_contents("$dir/b.cfg");
$check(strpos($cfg, 'AAAAAAAAAAAAAAAAAAAAAAAA') !== false && strpos($cfg, "'keep'") !== false, 'restore takes des_key, keeps mail settings');
$check(count(glob("$dir/before-restore-*.db")) === 1, 'snapshot taken before restore');
array_map('unlink', glob("$dir/*"));
rmdir($dir);

// update manifest signatures
$kp = sodium_crypto_sign_keypair();
$pub = base64_encode(sodium_crypto_sign_publickey($kp));
$man = json_encode(['product' => 'SynapseMail', 'version' => '9.1', 'url' => 'https://example.com/a.zip', 'sha256' => str_repeat('a', 64)]);
$sig = base64_encode(sodium_crypto_sign_detached($man, sodium_crypto_sign_secretkey($kp)));
$check(synapse_update::verify($man, $sig, $pub)['version'] === '9.1', 'signed manifest accepted');
$check(synapse_update::verify(str_replace('9.1', '9.2', $man), $sig, $pub) === null, 'altered manifest refused');
$check(synapse_update::verify($man, $sig, synapse_update::PUBLIC_KEY) === null, 'manifest signed by another key refused');
$check(synapse_update::check('http://example.com/release.json', '1.0')['error'] !== '', 'plain http update URL refused');

exit($fail ? 1 : 0);
