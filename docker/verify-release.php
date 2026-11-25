<?php

/*!
 * SynapseMail
 * Copyright (c) 2026 SynapseCyber.io. All rights reserved.
 *
 * Licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0
 * International License (CC BY-NC-ND 4.0). You may use and share it free of charge
 * with attribution. You may not modify it, distribute modified versions, or sell it.
 * https://creativecommons.org/licenses/by-nc-nd/4.0/
 */

// Checks an unpacked SynapseMail release before it goes into the image, like the in-panel updater does:
// the Ed25519 signature of SYNAPSEMAIL-RELEASE.json, the version, and that the folder holds exactly the signed
// files with the signed SHA-256 (no extra files, no links).
// usage: php verify-release.php <folder> <version>

// SynapseCyber release key (the same key as synapse_update::PUBLIC_KEY)
const PUBLIC_KEY = 'xOVkjBQXNYQw8ER4+vako4ed+cDYrj0LJPYfMqaTyxM=';

function fail($msg)
{
    fwrite(STDERR, "verify-release: $msg\n");
    exit(1);
}

[$dir, $version] = [rtrim((string) ($argv[1] ?? ''), '/'), (string) ($argv[2] ?? '')];
if ($dir === '' || !is_dir($dir) || !preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
    fail('usage: php verify-release.php <folder> <version>');
}
$json = @file_get_contents("$dir/SYNAPSEMAIL-RELEASE.json");
$sig = base64_decode(trim((string) @file_get_contents("$dir/SYNAPSEMAIL-RELEASE.sig")), true);
if ($json === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
    fail('release manifest or signature missing');
}
if (!sodium_crypto_sign_verify_detached($sig, $json, base64_decode(PUBLIC_KEY))) {
    fail('the signature of SYNAPSEMAIL-RELEASE.json is not valid');
}
$m = json_decode($json, true);
if (!is_array($m) || ($m['product'] ?? '') !== 'SynapseMail' || ($m['version'] ?? '') !== $version || !is_array($m['files'] ?? null)) {
    fail('the signed manifest is not SynapseMail ' . $version);
}

$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($dir) + 1);
    if ($f->isLink()) {
        fail("link in the release: $rel");
    }
    if ($f->isDir()) {
        continue;
    }
    if (!$f->isFile()) {
        fail("not a plain file: $rel");
    }
    if ($rel === 'SYNAPSEMAIL-RELEASE.json' || $rel === 'SYNAPSEMAIL-RELEASE.sig') {
        continue;
    }
    if (!isset($m['files'][$rel])) {
        fail("file not in the signed list: $rel");
    }
    if (!hash_equals((string) $m['files'][$rel], hash_file('sha256', $f->getPathname()))) {
        fail("file does not match the signed list: $rel");
    }
    $found[$rel] = true;
}
$missing = array_diff_key($m['files'], $found);
if ($missing) {
    fail('missing from the release: ' . implode(', ', array_slice(array_keys($missing), 0, 5)));
}
echo 'verify-release: SynapseMail ' . $version . ' signature and ' . count($found) . " files OK\n";
