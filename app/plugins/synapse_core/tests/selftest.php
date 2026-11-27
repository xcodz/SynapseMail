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
require __DIR__ . '/../lib/synapse_platform.php';
require __DIR__ . '/../../../program/lib/Roundcube/rcube_utils.php';
require __DIR__ . '/../lib/synapse_sendas.php';
require __DIR__ . '/../lib/synapse_mailconfig.php';

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
// a crafted backup: extra SQL in its schema, an unknown table, a PHP object in the settings
$mk("$dir/c.db", 'carol@example.com');
$pdo = new PDO("sqlite:$dir/c.db");
$pdo->exec('ALTER TABLE users ADD COLUMN preferences TEXT');
$pdo->exec("CREATE TABLE evil (x TEXT)");
$pdo->exec("INSERT INTO evil VALUES ('<?php echo 1;')");
$pdo = null;
[$file, $key] = synapse_backup::create("$dir/c.db", "$dir/a.cfg", 'test');
$data = synapse_backup::open($file, $key);
sleep(1); // the before-restore snapshot is named by the second
synapse_backup::restore($data, "$dir/b.db", "$dir/b.cfg");
$pdo = new PDO("sqlite:$dir/b.db");
$check(!in_array('evil', $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN), true), 'restore skips tables this server does not have');
$check($pdo->query('SELECT group_concat(username) FROM users')->fetchColumn() === 'carol@example.com', 'restore copies shared columns');
$pdo->exec('ALTER TABLE users ADD COLUMN preferences TEXT');
$pdo = null;
$pdo = new PDO("sqlite:$dir/c.db");
$pdo->exec("UPDATE users SET preferences = " . $pdo->quote(serialize(['x' => new ArrayObject()])));
$pdo = null;
[$file, $key] = synapse_backup::create("$dir/c.db", "$dir/a.cfg", 'test');
$refused = false;
sleep(1);
try { synapse_backup::restore(synapse_backup::open($file, $key), "$dir/b.db", "$dir/b.cfg"); } catch (RuntimeException $e) { $refused = true; }
$pdo = new PDO("sqlite:$dir/b.db");
$check($refused && $pdo->query('SELECT group_concat(username) FROM users')->fetchColumn() === 'carol@example.com', 'backup with PHP objects in settings refused, data unchanged');
$pdo = null;
$check(!synapse_backup::plain_serialized(serialize(new ArrayObject())) && synapse_backup::plain_serialized(serialize(['a' => [1, 'b']])), 'settings may hold arrays, not objects');
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
$check(!empty(synapse_update::check('file:///etc/passwd', '1.0')['error']), 'non-web update URL refused');
$check(!empty(synapse_update::check('http://example.com/release.json', '1.0')['error']), 'http update URL refused');
$check(synapse_update::verify(str_replace('https:', 'http:', $man), base64_encode(sodium_crypto_sign_detached(str_replace('https:', 'http:', $man), sodium_crypto_sign_secretkey($kp))), $pub) === null, 'signed manifest with an http download refused');

// signed release zips: install, keep per-install files, refuse tampering
$sk = sodium_crypto_sign_secretkey($kp);
$pkgdir = sys_get_temp_dir() . '/sm-upd-' . bin2hex(random_bytes(4));
mkdir("$pkgdir/site/config", 0700, true);
mkdir("$pkgdir/site/db", 0700);
file_put_contents("$pkgdir/site/config/synapse.inc.php", 'SECRET');
file_put_contents("$pkgdir/site/db/synapsemail.db", 'DATA');
file_put_contents("$pkgdir/site/old.txt", 'old');
file_put_contents("$pkgdir/site/.htaccess", "Old\n# php -- BEGIN cPanel-generated handler, do not edit\nAddHandler x\n# php -- END cPanel-generated handler, do not edit\n");
$mkzip = static function ($name, array $files, $version = '9.9', array $extra = []) use ($pkgdir, $sk) {
    $list = [];
    foreach ($files as $p => $c) {
        $list[$p] = hash('sha256', $c);
    }
    $man = json_encode(['product' => 'SynapseMail', 'version' => $version, 'files' => $list]);
    $z = new ZipArchive();
    $z->open("$pkgdir/$name", ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files + $extra as $p => $c) {
        $z->addFromString($p, $c);
    }
    $z->addFromString(synapse_update::PACKAGE_MANIFEST, $man);
    $z->addFromString(synapse_update::PACKAGE_SIG, base64_encode(sodium_crypto_sign_detached($man, $sk)));
    $z->close();

    return "$pkgdir/$name";
};
$good = ['old.txt' => 'new', 'plugins/x/new.php' => '<?php', 'config/synapse.inc.php' => 'EVIL', 'db/synapsemail.db' => 'EVIL', '.htaccess' => "New\n"];
$pkg = synapse_update::open($mkzip('good.zip', $good), '3.4', $pub);
$res = synapse_update::apply($pkg, "$pkgdir/site", "$pkgdir/rollback.zip");
$check($res['changed'] === 2 && $res['added'] === 1 && file_get_contents("$pkgdir/site/old.txt") === 'new' && is_file("$pkgdir/site/plugins/x/new.php"), 'signed update installs changed and new files');
$check(file_get_contents("$pkgdir/site/config/synapse.inc.php") === 'SECRET' && file_get_contents("$pkgdir/site/db/synapsemail.db") === 'DATA', 'update never touches config secrets or the database');
$check(strpos(file_get_contents("$pkgdir/site/.htaccess"), "New\n") === 0 && strpos(file_get_contents("$pkgdir/site/.htaccess"), 'AddHandler x') !== false, 'update keeps the cPanel PHP handler in .htaccess');
$rb = new ZipArchive();
$check($rb->open("$pkgdir/rollback.zip") === true && $rb->getFromName('old.txt') === 'old' && trim($rb->getFromName('ROLLBACK-NEW-FILES.txt')) === 'plugins/x/new.php', 'rollback copy holds the replaced files');
$rb->close();
$refused = static function ($fn) { try { $fn(); return false; } catch (RuntimeException $e) { return true; } };
$check($refused(static function () use ($mkzip, $pub) { synapse_update::open($mkzip('old.zip', ['a' => 'b'], '3.4'), '3.4', $pub); }), 'same or older version refused');
$check($refused(static function () use ($mkzip) { synapse_update::open($mkzip('other.zip', ['a' => 'b']), '3.4', synapse_update::PUBLIC_KEY); }), 'zip signed by another key refused');
$check($refused(static function () use ($mkzip, $pub) { synapse_update::open($mkzip('extra.zip', ['a' => 'b'], '9.9', ['evil.php' => 'x']), '3.4', $pub); }), 'file outside the signed list refused');
$check($refused(static function () use ($mkzip, $pub) { synapse_update::open($mkzip('slip.zip', ['../escape.php' => 'x']), '3.4', $pub); }), 'path traversal refused');
$tamper = $mkzip('tamper.zip', ['a.txt' => 'good']);
$z = new ZipArchive();
$z->open($tamper);
$z->addFromString('a.txt', 'evil');
$z->close();
$check($refused(static function () use ($tamper, $pub, $pkgdir) { synapse_update::apply(synapse_update::open($tamper, '3.4', $pub), "$pkgdir/site", "$pkgdir/rb2.zip"); }), 'changed file content refused');
$unsigned = new ZipArchive();
$unsigned->open("$pkgdir/unsigned.zip", ZipArchive::CREATE);
$unsigned->addFromString('a.txt', 'x');
$unsigned->close();
$check($refused(static function () use ($pkgdir, $pub) { synapse_update::open("$pkgdir/unsigned.zip", '3.4', $pub); }), 'unsigned zip refused');
exec('rm -rf ' . escapeshellarg($pkgdir));

// hosting panel detection from install paths
foreach ([
    '/home/alice/public_html/mail' => 'cpanel', '/home2/bob/public_html' => 'cpanel',
    '/home/alice/domains/example.com/public_html/mail' => 'directadmin',
    '/home/alice/web/example.com/public_html/mail' => 'hestia',
    '/home/site/htdocs/example.com/mail' => 'cloudpanel',
    '/var/www/vhosts/example.com/httpdocs/mail' => 'plesk',
    '/var/www/clients/client1/web3/web/mail' => 'ispconfig',
    '/www/wwwroot/example.com/mail' => 'aapanel',
    '/var/www/html/mail' => 'plain', '/srv/synapsemail' => 'plain',
] as $path => $want) {
    $check(synapse_platform::panel($path) === $want, "panel $path -> $want");
}
$check(synapse_platform::web_server('nginx/1.24.0') === 'nginx' && synapse_platform::web_server('LiteSpeed') === 'litespeed'
    && synapse_platform::web_server('Apache/2.4.58 (Ubuntu)') === 'apache', 'web server detection');
$check(synapse_platform::base_url('/mail/public_html/install.php') === '/mail' && synapse_platform::base_url('/install.php') === '', 'install base URL');
$snip = synapse_platform::nginx_snippet('/mail');
$check(strpos($snip, 'location ^~ /mail/db/ { return 404;') !== false && strpos($snip, 'location ^~ /mail/config/') !== false, 'nginx rules deny db and config');
foreach (array_keys(synapse_platform::PANELS) as $p) {
    foreach (['php', 'ext', 'perms', 'https', 'protect', 'mail'] as $topic) {
        if (synapse_platform::help($topic, $p, 'intl') === '') {
            $check(false, "help text $p/$topic");
        }
    }
}
$check(strpos(synapse_platform::help('ext', 'plain', 'pdo_sqlite'), 'php8.3-sqlite3') !== false, 'extension package names');

// Send As: the network guard refuses every private, local and reserved address, also when hidden inside IPv6
foreach (['127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '198.18.0.1', '224.0.0.1', '255.255.255.255',
    '::1', '::', 'fe80::1', 'fc00::1', 'fd12::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1', '2002:7f00:1::', '::127.0.0.1'] as $ip) {
    $check(!synapse_sendas::is_public($ip), "send as blocks $ip");
}
foreach (['8.8.8.8', '142.250.1.108', '2a00:1450:4001::6d', '::ffff:8.8.8.8', '64:ff9b::808:808'] as $ip) {
    $check(synapse_sendas::is_public($ip), "send as allows $ip");
}
$check(synapse_sendas::resolve('127.0.0.1', false) === 'blocked' && synapse_sendas::resolve('10.0.0.5', true) === ['10.0.0.5'], 'local servers only with the admin switch');
$check(synapse_sendas::resolve('bad host!', false) === 'host' && !synapse_sendas::valid_host('mail.local') && !synapse_sendas::valid_host('localhost')
    && synapse_sendas::valid_host('smtp.gmail.com'), 'server names are checked');
$check(in_array(465, synapse_sendas::PORTS, true) && !in_array(22, synapse_sendas::PORTS, true) && !in_array(6379, synapse_sendas::PORTS, true), 'only mail ports');
$check(synapse_sendas::name_match('mail.example.com', '*.example.com') && !synapse_sendas::name_match('a.b.example.com', '*.example.com')
    && !synapse_sendas::name_match('example.com', '*.example.com') && synapse_sendas::name_match('smtp.x.io', 'smtp.x.io'), 'certificate name rules');
foreach ([['535-5.7.8 Username and Password not accepted', 'auth'], ['534-5.7.9 Application-specific password required', 'apppass'],
    ['535 5.7.139 Authentication unsuccessful, basic authentication is disabled', 'msauth'], ['554 5.7.60 Client does not have permissions to send as this sender', 'sendas'],
    ['synapse-notls: the connection is not encrypted', 'notls']] as [$reply, $want]) {
    $check(synapse_sendas::error_key(['label' => 'smtpautherror', 'vars' => ['msg' => $reply]]) === $want, "SMTP reply -> $want");
}
$check(synapse_sendas::error_key(['label' => 'smtpconnerror', 'vars' => ['msg' => 'Failed to connect socket']]) === 'connect', 'connection errors are probed');
$xml = '<?xml version="1.0"?><clientConfig version="1.1"><emailProvider id="example.com"><outgoingServer type="smtp"><hostname>plain.example.com</hostname><port>25</port>'
    . '<socketType>plain</socketType><authentication>password-cleartext</authentication></outgoingServer><outgoingServer type="smtp"><hostname>smtp.%EMAILDOMAIN%</hostname>'
    . '<port>587</port><socketType>STARTTLS</socketType><authentication>password-cleartext</authentication><username>%EMAILLOCALPART%</username></outgoingServer></emailProvider></clientConfig>';
$got = synapse_mailconfig::parse_xml($xml, 'sara@example.com');
$check($got && $got['host'] === 'smtp.example.com' && $got['port'] === 587 && $got['security'] === 'starttls' && $got['username'] === '%EMAILLOCALPART%', 'autoconfig: first encrypted SMTP server');
$check(synapse_mailconfig::parse_xml('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><clientConfig><emailProvider><outgoingServer type="smtp">'
    . '<hostname>&e;</hostname><port>465</port><socketType>SSL</socketType></outgoingServer></emailProvider></clientConfig>', 'a@b.com') === null, 'autoconfig refuses entities (XXE)');
$check(synapse_mailconfig::parse_xml(str_replace(['STARTTLS', 'plain'], 'plain', $xml), 'a@example.com') === null, 'autoconfig never uses plain text servers');
$p = synapse_mailconfig::provider_by_domain('yahoo.co.uk');
$check($p && $p['host'] === 'smtp.mail.yahoo.com' && synapse_mailconfig::provider_by_domain('hotmail.de')['tip'] === 'oauth', 'known providers');
$check(synapse_mailconfig::provider_by_mx('example-com.mail.protection.outlook.com.')['key'] === 'microsoft365' && synapse_mailconfig::provider_by_mx('aspmx.l.google.com')['key'] === 'gmail', 'providers by MX');
$check(synapse_mailconfig::base_domain('mx1.example.co.uk') === 'example.co.uk' && synapse_mailconfig::base_domain('mx.mail.example.com') === 'example.com', 'MX base domain');
$check(synapse_sendas::clean_password('abcd efgh ijkl mnop') === 'abcdefghijklmnop' && synapse_sendas::clean_password("abcd\u{00A0}efgh ijkl mnop\n") === 'abcdefghijklmnop'
    && synapse_sendas::clean_password('my pass phrase 1') === 'my pass phrase 1' && synapse_sendas::clean_password("Secret 9\r\n") === 'Secret 9', 'App Password spaces removed, other passwords kept');
$src = (string) file_get_contents(__DIR__ . '/../lib/synapse_sendas.php');
$inbox = (string) file_get_contents(__DIR__ . '/../lib/synapse_inbox.php');
preg_match_all("/rl_(?:fail|blocked)\(\['(\w+)'/", $src . $inbox, $m);
$core = (string) file_get_contents(__DIR__ . '/../synapse_core.php');
$check($m[1] && !array_filter(array_unique($m[1]), static function ($k) use ($core) { return !preg_match("/'$k' => \d+/", $core); }), 'send as rate limits are registered');
$check(strpos($src, "'smtp_auth_type' => synapse_smtp_tls::METHOD") !== false, 'send as signs in only after TLS');
$check(strpos($src, "'verify_peer' => true, 'verify_peer_name' => true") !== false, 'send as checks server certificates');
$check(strpos($inbox, 'public const PORT = 993;') !== false && strpos($inbox, "'ssl_mode' => 'ssl'") !== false, 'other inboxes use IMAP over TLS on port 993 only');
$check(strpos($inbox, "'verify_peer' => true, 'verify_peer_name' => true") !== false && strpos($inbox, 'synapse_sendas::resolve($host)') !== false, 'other inboxes check certificates and block private addresses');
$check(strpos($inbox, "\$rcmail->task === 'mail'") !== false, 'other inboxes only replace the mailbox on the mail screens');
// All inboxes: folder names of other inboxes, and every message call routed to the right server
require_once __DIR__ . '/../lib/synapse_inbox.php';
$check(synapse_inbox::parse_folder(synapse_inbox::folder_of(12, 'Sent Items')) === [12, 'Sent Items'] && synapse_inbox::parse_folder('INBOX') === null
    && synapse_inbox::parse_folder('@sm#x:INBOX') === null && synapse_inbox::parse_folder('@sm#3:') === null, 'all inboxes: folder names of other inboxes round-trip, own folders stay own');
$router = (string) file_get_contents(__DIR__ . '/../lib/synapse_router.php');
$routed = ['count', 'list_messages', 'get_message_headers', 'get_message', 'get_message_part', 'get_raw_body', 'get_raw_headers', 'print_raw_body', 'set_flag',
    'move_message', 'copy_message', 'delete_message', 'expunge_message', 'list_flags', 'index', 'folder_status', 'folder_info', 'folder_data', 'folder_exists'];
$check(!array_filter($routed, static function ($f) use ($router) { return strpos($router, "public function {$f}(") === false; }), 'all inboxes: every message call of another inbox goes to its own server');
// core reads the connection settings by driver name (imap_conn_options, imap_auth_type...): the driver name must not change
$check(strpos($inbox, "config->set('storage_driver'") === false && strpos($inbox, "add_hook('storage_init'") !== false, 'all inboxes: own mailbox keeps the imap_* connection settings');
$check(strpos($router, 'isset(synapse_inbox::$others[$iid])') !== false && strpos($inbox, 'self::rows($rcmail->user->ID)') !== false, 'all inboxes: only the signed-in user\'s own inboxes can be opened');

// appearance preferences: the reading choice (double-click) passes the server check, unknown values do not
$core_src = (string) file_get_contents(__DIR__ . '/../synapse_core.php');
$ui_re = preg_match("/preg_match\('(\/\^\(\(motion[^']+)'/", $core_src, $mm) ? $mm[1] : null;
$check($ui_re && preg_match($ui_re, 'open-split') && preg_match($ui_re, 'motion-off.open-window') && !preg_match($ui_re, 'open-full') && !preg_match($ui_re, 'open-popup'), 'appearance: double-click choice is validated (full view is the default)');

// the public demo switches stay off in a normal install (only the demo build turns them on)
$cfg = (string) file_get_contents(__DIR__ . '/../../../config/config.inc.php');
$check(!preg_match('/synapse_demo|storage_driver/', $cfg), 'config.inc.php does not enable the demo');
$check(strpos((string) file_get_contents(__DIR__ . '/../synapse_core.php'), "get('synapse_demo') === true") !== false, 'demo 2FA code needs the explicit demo switch');

// compose: quoted and reopened mail sits inside the compose form, so no form control from a mail may reach it
require_once __DIR__ . '/../../../vendor/autoload.php';
spl_autoload_register(static function ($class) {
    $file = __DIR__ . '/../../../program/lib/Roundcube/' . $class . '.php';
    if (preg_match('/^rcube_\w+$/', $class) && is_file($file)) {
        require_once $file;
    }
});
require_once __DIR__ . '/../../synapse_editor/synapse_compose_guard.php';
defined('RCUBE_CHARSET') || define('RCUBE_CHARSET', 'UTF-8');
$wash =static function ($html) {
    $w = new rcube_washtml(['allow_remote' => false, 'html_elements' => [], 'container_id' => 'replybody1', 'css_prefix' => 'v1']);

    return $w->wash('<html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
};
$quoted = $wash('<p>Hello</p><button type="submit" name="_bcc" value="x@example.test" style="opacity:0">Open the invoice</button>'
    . '<input name="_to" value="y@example.test"><input type="hidden" name="h" value="hidden-value"><input type="submit" value="Go">'
    . '<textarea name="_subject">s</textarea><select name="_from"><option>first</option><option>second</option></select>'
    . '<label for="_bcc">label text</label><fieldset><legend>legend text</legend><output name="o">out</output></fieldset>'
    . '<a href="https://example.test/" formaction="/x">link</a><div id="_rc_sig">quoted signature</div>');
$check(preg_match('/<button\b[^>]*type="submit"/', $quoted) === 1, 'compose: washed mail still holds a submit button (test input is real)');
$safe = synapse_compose_guard::defuse($quoted);
$check(!preg_match('/<\s*(button|input|select|textarea|label|fieldset|legend|output|form)\b/i', $safe), 'compose: no form control survives in quoted mail');
$check(!preg_match('/\sform(action|method|enctype|target|novalidate)?\s*=/i', $safe), 'compose: form attributes are removed');
$check(strpos($safe, 'Open the invoice') !== false && strpos($safe, 'label text') !== false && strpos($safe, 'legend text') !== false && strpos($safe, '>first<') !== false, 'compose: the text of replaced controls stays visible');
$check(strpos($safe, 'hidden-value') === false && strpos($safe, 'x@example.test') === false, 'compose: hidden values and button values are not carried over');
$check(strpos($safe, 'id="v1_rc_sig"') !== false && strpos($safe, 'href="https://example.test/"') !== false, 'compose: ids and links of the quote are kept');
$rtl = '<p dir="rtl">سلام دنیا</p><p>日本語 é</p><blockquote type="cite"><div id="replybody1"><p>quote</p></div></blockquote>';
$check(synapse_compose_guard::defuse($rtl) === $rtl, 'compose: mail without form controls is left byte for byte');
$check(synapse_compose_guard::defuse($rtl . '<button>b</button>') === $rtl . '<span>b</span>', 'compose: non-Latin text survives the rewrite');
$editor_src = (string) file_get_contents(__DIR__ . '/../../synapse_editor/synapse_editor.js');
$check(strpos((string) file_get_contents(__DIR__ . '/../../synapse_editor/synapse_editor.php'), "add_hook('message_compose_body'") !== false, 'compose: the server-side guard is registered');
$check(strpos($editor_src, "addEventListener('submit'") !== false && strpos($editor_src, 'rcmail.submit_messageform = function') !== false, 'compose: the form is sent only by the Send and Save commands');
$check(strpos($editor_src, '[id$="_rc_sig"]') === false && strpos($editor_src, 'topLevel(wrap, /^(v\d+)?_rc_sig$/)') !== false, 'compose: only a top-level own signature is taken as the signature');
$check(strpos($editor_src, 'document.createElement(\'div\'), nodes') === false && strpos($editor_src, 'createHTMLDocument') !== false, 'compose: mail HTML is split in an inert document');
$check(preg_match('/\.lx-signature, \.lx-quoted-body \{[^}]*contain: paint/', (string) file_get_contents(__DIR__ . '/../../../skins/synapse/synapse.css')) === 1, 'compose: quote and signature boxes clip their content');

// sign-in gate and security changes (SEC-R02)
require_once __DIR__ . '/../lib/synapse_gate.php';
$check(synapse_gate::next_factor('pending', 'pending') === 'otp', 'gate: an enrolled authenticator comes before the passcode');
$check(synapse_gate::next_factor('pending', 'enroll') === 'pin', 'gate: a first enrolment waits until the passcode is entered');
$check(synapse_gate::next_factor(null, 'enroll') === 'enroll' && synapse_gate::next_factor(null, null) === null && synapse_gate::next_factor('locked', 'pending') === 'pin', 'gate: enrolment last, nothing owed when signed in, lock first');
$check(!synapse_gate::pin_step('pending', 'pending') && synapse_gate::pin_step('pending', null) && synapse_gate::pin_step('pending', 'enroll'), 'gate: passcode, hint and forgot-passcode only after a pending authenticator code');
$check(!synapse_gate::enroll_step('pending', 'enroll') && synapse_gate::enroll_step(null, 'enroll') && !synapse_gate::enroll_step(null, 'pending'), 'gate: enrolment in the gate only as the last step');
$check(synapse_gate::stepup_valid(time() + 10, time()) && !synapse_gate::stepup_valid(time() - 1, time()) && !synapse_gate::stepup_valid(0, time()), 'step-up: valid only inside its window');
$check(synapse_gate::STEPUP_SECONDS > 0 && synapse_gate::STEPUP_SECONDS <= 600, 'step-up: the window is short (at most ten minutes)');
$check(synapse_gate::password_matches('mail pass', 'mail pass') && !synapse_gate::password_matches('mail pass', 'mail') && !synapse_gate::password_matches('', '') && !synapse_gate::password_matches(null, 'x'), 'step-up: only the session password matches, never an empty one');
$body = static function ($name) use ($core_src) {
    return preg_match('/function ' . $name . '\(.*?(?=\n    (public|private|protected) (static )?function |\n}\s*$)/s', $core_src, $m) ? $m[0] : '';
};
$check(preg_match("/'stepup'\] as \\\$action/", $core_src) === 1 && !preg_match("/GATE_ACTIONS = \[[^\]]*stepup/s", $core_src), 'step-up: action registered, and not allowed while the gate is up');
$check(strpos($body('action_stepup'), 'rl_take') !== false && strpos($body('action_stepup'), 'rl_settle') !== false && strpos($body('action_stepup'), "\$_SESSION['password']") !== false, 'step-up: checks the session password and is rate limited');
$check(preg_match('/if \(!\$current\) \{\s*\$this->require_stepup\(\);/', $body('action_pin_save')) === 1, 'step-up: a first passcode needs it (a change needs the current passcode)');
$check(preg_match("/=== 'start'\) \{\s*if \(!\\\$gate\) \{\s*\\\$this->require_stepup\(\);/", $body('action_otp_enroll')) === 1, 'step-up: authenticator enrolment from Settings needs it');
$check(strpos($body('action_otp_recovery'), 'otp_consume') !== false && strpos($body('action_otp_disable'), 'otp_consume') !== false && strpos($body('action_pin_remove'), 'current_pin_ok') !== false, 'factor changes: new codes and 2FA off need a current code, passcode removal the passcode');
$check(strpos($body('action_pin_forgot'), 'synapse_gate::pin_step') !== false && strpos($body('action_pin_verify'), 'synapse_gate::pin_step') !== false, 'gate: forgot-passcode and passcode checks follow the gate order');
$check(strpos($body('gate_step_done'), 'regenerate_id(true)') !== false && substr_count($core_src, 'gate_step_done()') >= 4, 'gate: a new session id once the last gate step passes');
$inst = (string) file_get_contents(__DIR__ . '/../installer/installer.php');
$check(strpos($inst, "\$S['admin']['last_step'] = \$step") !== false && preg_match('/INSERT INTO synapse_admins \([^)]*totp_last_step/', $inst) === 1, 'installer: the setup code cannot be used again to sign in');
$ui_src = (string) file_get_contents(__DIR__ . '/../../../skins/synapse/ui.js');
$check(strpos($ui_src, "api('stepup'") !== false && strpos($ui_src, 'if (!st.has_pin) stepUp(show)') !== false, 'step-up: Settings asks for it before a first passcode or an authenticator');

// parallel requests: counters, authenticator steps and recovery codes are changed atomically (SEC-R03)
require_once __DIR__ . '/../lib/synapse_atomic.php';
if (!class_exists('synapse_selftest_db')) {
    // the three calls synapse_atomic needs from rcube_db, on a plain PDO connection
    final class synapse_selftest_db
    {
        public $db_provider = 'sqlite';
        private $pdo;

        public function __construct($file)
        {
            $this->pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }

        public function query($sql, ...$args)
        {
            $st = $this->pdo->prepare($sql);
            $st->execute($args);

            return $st;
        }

        public function fetch_assoc($st)
        {
            return $st->fetch(PDO::FETCH_ASSOC) ?: false;
        }

        public function affected_rows($st)
        {
            return $st->rowCount();
        }
    }
}
$rdir = sys_get_temp_dir() . '/smrace-' . bin2hex(random_bytes(4));
mkdir($rdir);
$rfile = "$rdir/race.db";
$A = new synapse_selftest_db($rfile);
$B = new synapse_selftest_db($rfile);
$A->query('CREATE TABLE rl (`k` varchar(64) NOT NULL PRIMARY KEY, `fails` integer NOT NULL DEFAULT 0, `since` integer NOT NULL DEFAULT 0, `blocked_until` integer NOT NULL DEFAULT 0)');
$A->query('CREATE TABLE users (`user_id` integer PRIMARY KEY, `preferences` text)');
$A->query('CREATE TABLE admins (`id` integer PRIMARY KEY, `totp_last_step` integer NOT NULL DEFAULT 0, `recovery_codes` text NOT NULL)');
$now = time();
// counters: two connections, interleaved, lose nothing; the limit blocks exactly once
for ($i = 0; $i < 3; $i++) {
    synapse_atomic::rl_fail($A, 'rl', 'k1', $now, 900, 100, 900);
    synapse_atomic::rl_fail($B, 'rl', 'k1', $now, 900, 100, 900);
}
$check((int) $A->fetch_assoc($A->query('SELECT fails FROM rl WHERE k = ?', 'k1'))['fails'] === 6, 'race: failures from two connections are all counted');
$blocks = 0;
for ($i = 0; $i < 6; $i++) {
    $blocks += synapse_atomic::rl_fail($i % 2 ? $A : $B, 'rl', 'k2', $now, 900, 5, 900) ? 1 : 0;
}
$check($blocks === 1 && synapse_atomic::rl_wait($A, 'rl', 'k2', $now) > 0, 'race: reaching the limit blocks once, and the block is seen');
$taken = 0;
for ($i = 0; $i < 6; $i++) {
    $taken += synapse_atomic::rl_take($i % 2 ? $A : $B, 'rl', 'k3', $now, 900, 3) ? 1 : 0;
}
$check($taken === 3, 'race: only as many attempts can be taken as the limit allows');
synapse_atomic::rl_settle($A, 'rl', 'k3', true, $now, 3, 900);
$check(synapse_atomic::rl_take($B, 'rl', 'k3', $now, 900, 3) && !synapse_atomic::rl_take($B, 'rl', 'k3', $now, 900, 3), 'race: a correct guess gives its attempt back');
$check(synapse_atomic::rl_take($A, 'rl', 'k3', $now + 901, 900, 3), 'race: a new window has room again');
// preferences: a write from another connection between read and write is never lost
$A->query('INSERT INTO users (user_id, preferences) VALUES (1, ?)', serialize(['n' => 0, 'keep' => 'x']));
$first = true;
$r = synapse_atomic::prefs($A, 'users', 1, static function (array $p) use (&$first, $B) {
    if ($first) {
        $first = false;
        synapse_atomic::prefs($B, 'users', 1, static function (array $q) { return ['n' => $q['n'] + 1, 'b' => 1]; });
    }

    return ['n' => $p['n'] + 1, 'a' => 1];
});
$stored = unserialize($A->fetch_assoc($A->query('SELECT preferences FROM users WHERE user_id = 1'))['preferences']);
$check($r !== null && $stored === ['n' => 2, 'keep' => 'x', 'b' => 1, 'a' => 1], 'race: preference changes from two connections are merged, the counter counts both');
// a one-time code in the preferences: the second request sees it used and fails
$A->query('UPDATE users SET preferences = ? WHERE user_id = 1', serialize(['codes' => ['c1', 'c2']]));
$use = static function (array $p) { return in_array('c1', $p['codes'], true) ? ['codes' => array_values(array_diff($p['codes'], ['c1']))] : null; };
$first = true;
$second = null;
$won = synapse_atomic::prefs($A, 'users', 1, static function (array $p) use (&$first, &$second, $B, $use) {
    if ($first) {
        $first = false;
        $second = synapse_atomic::prefs($B, 'users', 1, $use);
    }

    return $use($p);
});
$check($second !== null && $won === null, 'race: a recovery code read by two requests is used by exactly one');
// admin table: the time step moves forward once; a recovery code works once
$A->query('INSERT INTO admins (id, totp_last_step, recovery_codes) VALUES (1, 10, ?)', json_encode([password_hash('ABCD-EFGH', PASSWORD_DEFAULT, ['cost' => 4])]));
$check(synapse_atomic::admin_step($A, 'admins', 1, 11) && !synapse_atomic::admin_step($B, 'admins', 1, 11) && !synapse_atomic::admin_step($B, 'admins', 1, 9), 'race: an admin code time step is accepted once, older steps never');
$check(synapse_atomic::admin_recovery($B, 'admins', 1, 'ABCD-EFGH') && !synapse_atomic::admin_recovery($A, 'admins', 1, 'ABCD-EFGH'), 'race: an admin recovery code works once');
// real parallel processes on one SQLite file (WAL), started together
$parallel = static function ($n, $op) use ($rfile) {
    if (!function_exists('proc_open')) {
        return null;
    }
    $code = 'require ' . var_export(__DIR__ . '/../lib/synapse_atomic.php', true) . ';'
        . 'class D { public $db_provider = "sqlite"; public $p; function __construct($f) { $this->p = new PDO("sqlite:" . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 20]); }'
        . ' function query($s, ...$a) { $t = $this->p->prepare($s); $t->execute($a); return $t; } function fetch_assoc($t) { return $t->fetch(PDO::FETCH_ASSOC) ?: false; } function affected_rows($t) { return $t->rowCount(); } }'
        . '$db = new D($argv[1]); time_sleep_until((float) $argv[2]); echo (' . $op . ') ? 1 : 0;';
    $start = microtime(true) + 0.6;
    $procs = [];
    for ($i = 0; $i < $n; $i++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $code, $rfile, (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $outs[] = $pipes;
    }
    $wins = 0;
    foreach ($procs as $i => $p) {
        $wins += (int) trim(stream_get_contents($outs[$i][1]));
        proc_close($p);
    }

    return $wins;
};
$A->query('UPDATE admins SET totp_last_step = 100, recovery_codes = ? WHERE id = 1', json_encode([password_hash('WXYZ-2345', PASSWORD_DEFAULT, ['cost' => 4])]));
$w = $parallel(10, 'synapse_atomic::admin_step($db, "admins", 1, 101)');
$check($w === null || $w === 1, 'race: 10 parallel processes with one admin code time step, exactly one wins' . ($w === null ? ' (skipped: no proc_open)' : ''));
$w = $parallel(10, 'synapse_atomic::admin_recovery($db, "admins", 1, "WXYZ-2345")');
$check($w === null || $w === 1, 'race: 10 parallel processes with one admin recovery code, exactly one wins');
$A->query('UPDATE users SET preferences = ? WHERE user_id = 1', serialize(['otp' => ['last_step' => 5, 'recovery' => ['r1', 'r2']]]));
$w = $parallel(10, 'synapse_atomic::prefs($db, "users", 1, function ($p) { return $p["otp"]["last_step"] < 6 ? ["otp" => ["last_step" => 6] + $p["otp"]] : null; })');
$check($w === null || $w === 1, 'race: 10 parallel processes with one user code time step, exactly one wins');
$w = $parallel(10, 'synapse_atomic::prefs($db, "users", 1, function ($p) { return in_array("r1", $p["otp"]["recovery"], true) ? ["otp" => ["recovery" => array_values(array_diff($p["otp"]["recovery"], ["r1"]))] + $p["otp"]] : null; })');
$check($w === null || $w === 1, 'race: 10 parallel processes with one user recovery code, exactly one wins');
$w = $parallel(12, 'synapse_atomic::rl_take($db, "rl", "k9", time(), 900, 4)');
$check($w === null || $w === 4, 'race: 12 parallel guesses against a limit of 4, exactly 4 are checked');
$A = $B = null;
array_map('unlink', glob("$rdir/*"));
rmdir($rdir);
$check(substr_count($core_src, 'synapse_atomic::') >= 4 && !preg_match('/\$fails = \(int\) \$rcmail->config->get\(\'synapse_(pin|otp)_fails/', $core_src), 'race: passcode and code counters are no longer read, increased and written back');
$check(strpos($core_src, 'private function otp_consume') !== false && substr_count($core_src, '$this->otp_consume(') >= 4 && substr_count($core_src, '$this->otp_check(') === 1, 'race: every authenticator or recovery code is used up atomically (sign-in, step-up, new codes, 2FA off)');
$admin_src = (string) file_get_contents(__DIR__ . '/../admin/admin.php');
$check(substr_count($admin_src, 'synapse_atomic::admin_step(') >= 4 && strpos($admin_src, 'synapse_atomic::admin_recovery(') !== false && strpos($admin_src, 'SET `totp_last_step` = ? WHERE `id` = ?"') === false, 'race: admin sign-in, code checks and recovery codes use the atomic updates');

exit($fail ? 1 : 0);
