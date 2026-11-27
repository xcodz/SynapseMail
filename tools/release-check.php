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

// Release packaging checks, used by tools/build_release.sh and tools/build_whm_plugin.sh before anything is signed.
//
//   php tools/release-check.php package <dir> <SYNAPSEMAIL-FILES.txt> <roundcube-files.txt>
//       every file is listed (SynapseMail list or pinned Roundcube list), every listed file is there, unchanged
//       Roundcube files match their pinned SHA-256, and nothing of a reject class is in the package
//   php tools/release-check.php list <dir> <list file>       exactly the listed files, nothing of a reject class
//   php tools/release-check.php docker <docker dir>          the build context (.dockerignore allow-list) is complete
//                                                            and clean, and the Dockerfile copies only from it
//   php tools/release-check.php zip <dir> <out.zip> <unix time>   deterministic zip (sorted, fixed time and modes)
//   php tools/release-check.php key <key file> <public key>  the signing key belongs to that public key
//   php tools/release-check.php sign-files <dir> <version> <date> <notes> <key file>   SYNAPSEMAIL-RELEASE.json + .sig
//   php tools/release-check.php sign-repo <out dir> <version> <date> <zip> <url> <notes> <key file>   release.json + .sig
//   php tools/release-check.php sign-raw <file> <key file> <public key> <out>   raw 64-byte Ed25519 signature
//   php tools/release-check.php normalize <dir> <unix time>   fixed modes and times on every entry
//   php tools/release-check.php sha256 <file>
//
// Exit code 0 when everything passes, 1 with "REJECT: ..." lines otherwise. Private host and address patterns are
// checked by the build scripts (they live outside the repository and are never printed).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MAX_REPORT = 20;

/** Every entry under $dir, relative path => 'f' (file), 'd' (dir), 'l' (link) or 'o' (anything else), sorted */
function entries($dir)
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($dir) + 1);
        $out[$rel] = $f->isLink() ? 'l' : ($f->isDir() ? 'd' : ($f->isFile() ? 'f' : 'o'));
    }
    ksort($out, SORT_STRING);

    return $out;
}

/** Why a path may never ship, or null. $kind: release, whm or docker */
function reject_reason($rel, $type, $kind)
{
    if ($type === 'l') {
        return 'symbolic link';
    }
    if ($type === 'o') {
        return 'not a plain file';
    }
    $base = basename($rel);
    $lower = strtolower($rel);
    foreach (explode('/', $lower) as $seg) {
        if (in_array($seg, ['.git', 'graphify-out', 'node_modules', '.ds_store', '__macosx', '.idea', '.vscode', '.claude', '.github', 'dist'], true)) {
            return "development folder or file ($seg)";
        }
        if (preg_match('/^(synapse_ee|sm_ee)|^enterprise$/', $seg)) {
            return 'other edition';
        }
        if (preg_match('/^\.env(\.|$)/', $seg)) {
            return 'environment file';
        }
    }
    if ($type === 'd') {
        return null;
    }
    // editor sources and build tooling stay in the repository (the plugin ships the built bundle)
    if ($kind === 'release' && strncmp($lower, 'editor/', 7) === 0) {
        return 'editor source';
    }
    if (preg_match('/^(package(-lock)?\.json|tsconfig\.json|(vite|rollup|webpack)\.config\.[a-z]+)$/', strtolower($base)) || preg_match('/\.(tsx?|jsx)$/i', $base)) {
        return 'editor source or build tooling';
    }
    // per-install state, credentials and backups
    if (in_array($base, ['config.local.inc.php', 'synapse.inc.php', 'installed.lock', 'ADMIN-URL.txt', 'install-probe.txt', 'release-signing.key', 'ROLLBACK-NEW-FILES.txt'], true)) {
        return 'per-install file';
    }
    if ($base === 'setup.key' && !($kind === 'release' && $rel === 'setup.key')) {
        return 'ownership key';
    }
    if (preg_match('/\.smbk$|^before-restore-|^restore-.*\.tmp$|^sm-backup-|^sm-update-|^rollback-v.*\.zip$/i', $base)) {
        return 'backup, snapshot or rollback file';
    }
    if (preg_match('/\.(db|db-wal|db-shm|db-journal|sqlite|sqlite3)$/i', $base)) {
        return 'database';
    }
    if (preg_match('/\.(zip|tmp|log|swp|bak|orig|rej|pem|p12|pfx)$|~$/i', $base)) {
        return 'archive, temporary or key file';
    }
    if ($kind === 'release' && preg_match('#^(logs|temp)/#', $rel) && $base !== '.htaccess') {
        return 'runtime file';
    }

    return null;
}

/** Content that looks like a private key or an access token: the name of the first matching rule, or null */
function secret_reason($file)
{
    static $rules = [
        'private key block' => '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY( BLOCK)?-----/',
        'AWS access key' => '/\bAKIA[0-9A-Z]{16}\b/',
        'GitHub token' => '/\b(gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{60,})\b/',
        'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        'GitLab token' => '/\bglpat-[A-Za-z0-9_-]{20,}/',
        'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
        'Stripe live key' => '/\b[rs]k_live_[0-9A-Za-z]{20,}/',
        'Anthropic or OpenAI key' => '/\bsk-(ant-)?[A-Za-z0-9_-]{32,}/',
    ];
    if (filesize($file) > 32 * 1024 * 1024) {
        return 'file too large to check';
    }
    $data = (string) file_get_contents($file);
    foreach ($rules as $name => $re) {
        if (preg_match($re, $data)) {
            return $name;
        }
    }

    return null;
}

/** Path rules on every entry, secret rules on the files in $scan (null: all files) */
function scan($dir, array $entries, $kind, ?array $scan = null)
{
    $errors = [];
    foreach ($entries as $rel => $type) {
        if ($r = reject_reason($rel, $type, $kind)) {
            $errors[] = "$r: $rel";
        } elseif ($type === 'f' && ($scan === null || isset($scan[$rel])) && ($r = secret_reason("$dir/$rel"))) {
            $errors[] = "$r in $rel";
        }
    }
    // the package's setup.key is the example: comments and the placeholder code only, never a real code
    if ($kind === 'release' && isset($entries['setup.key'])) {
        $codes = preg_grep('/^\s*(#|$)/', file("$dir/setup.key", FILE_IGNORE_NEW_LINES) ?: [], PREG_GREP_INVERT);
        if (array_map('trim', array_values($codes)) !== ['SM-XXXX-XXXX-XXXX']) {
            $errors[] = 'setup.key holds something other than the placeholder code';
        }
    }

    return $errors;
}

/** [added], [changed], [removed] sections of app/SYNAPSEMAIL-FILES.txt */
function synapse_list($file)
{
    $out = ['added' => [], 'changed' => [], 'removed' => []];
    $sec = null;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^\[(added|changed|removed)\]$/', $line, $m)) {
            $sec = $m[1];
        } elseif ($sec && !preg_match('#(^/|\.\.)#', $line)) {
            $out[$sec][] = rtrim($line, '/');
        }
    }

    return $out;
}

/** tools/roundcube-files.txt: [version, [path => sha256 or '-']] */
function roundcube_list($file)
{
    $version = null;
    $files = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^roundcube (\S+)$/', $line, $m)) {
            $version = $m[1];
        } elseif (preg_match('/^([0-9a-f]{64}|-)  (\S.*)$/', $line, $m)) {
            $files[$m[2]] = $m[1];
        } else {
            fail(["unreadable line in " . basename($file) . ": $line"]);
        }
    }

    return [$version, $files];
}

function fail(array $errors)
{
    foreach (array_slice($errors, 0, MAX_REPORT) as $e) {
        fwrite(STDERR, "REJECT: $e\n");
    }
    if (count($errors) > MAX_REPORT) {
        fwrite(STDERR, 'REJECT: ... and ' . (count($errors) - MAX_REPORT) . " more\n");
    }
    exit(1);
}

function need_dir($dir)
{
    $dir = rtrim((string) $dir, '/');
    if ($dir === '' || !is_dir($dir)) {
        fail(["not a folder: $dir"]);
    }

    return $dir;
}

function secret_key($file)
{
    $key = base64_decode(trim((string) @file_get_contents($file)), true);
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail(['the signing key is not a base64 Ed25519 secret key']);
    }

    return $key;
}

$cmd = $argv[1] ?? '';
$a = array_slice($argv, 2);

switch ($cmd) {
case 'package':
    [$dir, $listfile, $rcfile] = [need_dir($a[0] ?? ''), $a[1] ?? '', $a[2] ?? ''];
    $list = synapse_list($listfile);
    [$rcv, $pinned] = roundcube_list($rcfile);
    $errors = [];
    if (!$list['added'] || !$pinned) {
        fail(['file lists are empty']);
    }
    $iniset = @file_get_contents("$dir/program/include/iniset.php");
    if (!$iniset || !preg_match("/RCMAIL_VERSION', '([^']+)'/", $iniset, $m) || $m[1] !== $rcv) {
        $errors[] = 'Roundcube version in the package does not match the pinned list (roundcube ' . $rcv . ')';
    }
    $entries = entries($dir);
    $changed = array_flip($list['changed']);
    $removed = array_flip($list['removed']);
    $own = static function ($rel) use ($list) {
        // added by the build: the example ownership file (checked to be the placeholder below)
        if ($rel === 'setup.key') {
            return true;
        }
        foreach ($list['added'] as $p) {
            if ($rel === $p || strncmp($rel, "$p/", strlen($p) + 1) === 0) {
                return true;
            }
        }

        return false;
    };
    $scan = [];
    foreach ($entries as $rel => $type) {
        if ($type !== 'f') {
            continue;
        }
        if (isset($pinned[$rel]) && !isset($changed[$rel]) && !isset($removed[$rel]) && !$own($rel)) {
            // unmodified Roundcube: it must be byte-identical to the pinned file
            if ($pinned[$rel] !== '-' && !hash_equals($pinned[$rel], hash_file('sha256', "$dir/$rel"))) {
                $errors[] = "Roundcube file differs from the pinned list (add it to [changed] if intended): $rel";
                $scan[$rel] = true;
            }
        } elseif (isset($changed[$rel]) || $own($rel)) {
            $scan[$rel] = true;
        } else {
            $errors[] = "not in SYNAPSEMAIL-FILES.txt or the Roundcube list: $rel";
            $scan[$rel] = true;
        }
    }
    foreach ($pinned as $rel => $h) {
        if (!isset($removed[$rel]) && !isset($entries[$rel])) {
            $errors[] = "listed Roundcube file missing: $rel";
        }
    }
    foreach (array_merge($list['added'], $list['changed']) as $rel) {
        if (!isset($entries[$rel])) {
            $errors[] = "listed in SYNAPSEMAIL-FILES.txt but missing: $rel";
        }
    }
    foreach ($list['removed'] as $rel) {
        if (isset($entries[$rel])) {
            $errors[] = "listed as removed but present: $rel";
        }
    }
    $errors = array_merge($errors, scan($dir, $entries, 'release', $scan));
    $errors && fail($errors);
    echo 'release-check: ' . count(array_filter($entries, static fn ($t) => $t === 'f')) . ' files listed, ' . count($scan) . " SynapseMail files scanned, nothing rejected\n";
    break;

case 'list':
    [$dir, $listfile] = [need_dir($a[0] ?? ''), $a[1] ?? ''];
    $want = [];
    foreach (file($listfile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $want[$line] = true;
        }
    }
    $want || fail(['the file list is empty']);
    $entries = entries($dir);
    $errors = scan($dir, $entries, 'whm');
    foreach ($entries as $rel => $type) {
        if ($type === 'f' && !isset($want[$rel])) {
            $errors[] = "not in " . basename($listfile) . ": $rel";
        }
    }
    foreach ($want as $rel => $_) {
        if (($entries[$rel] ?? '') !== 'f') {
            $errors[] = "listed but missing: $rel";
        }
    }
    $errors && fail($errors);
    echo 'release-check: ' . count($want) . " listed files, nothing rejected\n";
    break;

case 'docker':
    $dir = need_dir($a[0] ?? '');
    $allow = [];
    foreach (file("$dir/.dockerignore", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] === '!') {
            $allow[substr($line, 1)] = true;
        } elseif ($line !== '' && $line[0] !== '#' && $line !== '*') {
            fail(["docker/.dockerignore must be an allow-list ('*' and '!file' lines): $line"]);
        }
    }
    $allow || fail(['docker/.dockerignore is missing or has no allowed files']);
    $errors = [];
    $context = [];
    foreach ($allow as $rel => $_) {
        if (!is_file("$dir/$rel") || is_link("$dir/$rel")) {
            $errors[] = "build context file missing: $rel";
        } else {
            $context[$rel] = 'f';
        }
    }
    $errors = array_merge($errors, scan($dir, $context, 'docker'));
    // every local COPY source is part of the context
    foreach (file("$dir/Dockerfile", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*(COPY|ADD)\s+(.*)$/i', $line, $m) && !preg_match('/--from=/i', $m[2])) {
            $parts = array_values(array_filter(preg_split('/\s+/', trim($m[2])), static fn ($p) => $p !== '' && strncmp($p, '--', 2) !== 0));
            foreach (array_slice($parts, 0, -1) as $src) {
                if (!isset($allow[$src])) {
                    $errors[] = "Dockerfile copies a file outside the allow-list: $src";
                }
            }
        }
    }
    $errors && fail($errors);
    echo 'release-check: Docker build context of ' . count($context) . " files, nothing rejected\n";
    break;

case 'zip':
    [$dir, $out, $time] = [need_dir($a[0] ?? ''), $a[1] ?? '', (int) ($a[2] ?? 0)];
    if ($time < 315532800 || !class_exists('ZipArchive')) {
        fail(['zip needs the zip extension and a time after 1980']);
    }
    @unlink($out);
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        fail(["cannot write $out"]);
    }
    $n = 0;
    // sorted by the stored name (folders end in "/"), so the zip's own listing is in byte order
    $names = [];
    foreach (entries($dir) as $rel => $type) {
        $names[$type === 'd' ? "$rel/" : $rel] = [$rel, $type];
    }
    ksort($names, SORT_STRING);
    foreach ($names as [$rel, $type]) {
        if ($type === 'd') {
            $zip->addEmptyDir($rel);
            $name = "$rel/";
            $mode = 040755;
        } elseif ($type === 'f') {
            $zip->addFile("$dir/$rel", $rel);
            $name = $rel;
            $mode = (fileperms("$dir/$rel") & 0111) ? 0100755 : 0100644;
            $n++;
        } else {
            fail(["not a plain file: $rel"]);
        }
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
        $zip->setMtimeName($name, $time);
    }
    $zip->close() || fail(["cannot write $out"]);
    echo "$n files\n";
    break;

case 'normalize':
    // fixed modes (0755 when any execute bit is set, else 0644; folders 0755) and one time on every entry
    [$dir, $time] = [need_dir($a[0] ?? ''), (int) ($a[1] ?? 0)];
    $time > 0 || fail(['normalize needs a time']);
    foreach (array_reverse(entries($dir), true) as $rel => $type) {
        $p = "$dir/$rel";
        $type === 'f' || $type === 'd' || fail(["not a plain file: $rel"]);
        chmod($p, $type === 'd' || (fileperms($p) & 0111) ? 0755 : 0644);
        touch($p, $time);
    }
    chmod($dir, 0755);
    touch($dir, $time);
    break;

case 'key':
    $pub = sodium_crypto_sign_publickey_from_secretkey(secret_key($a[0] ?? ''));
    if (!hash_equals((string) base64_decode((string) ($a[1] ?? ''), true), $pub)) {
        fail(['the signing key does not belong to the release public key']);
    }
    echo "release-check: signing key matches the release public key\n";
    break;

case 'sign-files':
    [$dir, $v, $date, $notes, $keyfile] = [need_dir($a[0] ?? ''), $a[1] ?? '', $a[2] ?? '', $a[3] ?? '', $a[4] ?? ''];
    $key = secret_key($keyfile);
    $files = [];
    foreach (entries($dir) as $rel => $type) {
        if ($type === 'f') {
            $files[$rel] = hash_file('sha256', "$dir/$rel");
        }
    }
    $m = json_encode(['product' => 'SynapseMail', 'version' => $v, 'released' => $date, 'notes' => $notes, 'files' => $files],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents("$dir/SYNAPSEMAIL-RELEASE.json", $m);
    file_put_contents("$dir/SYNAPSEMAIL-RELEASE.sig", base64_encode(sodium_crypto_sign_detached($m, $key)) . "\n");
    echo count($files) . " files signed\n";
    break;

case 'sign-repo':
    [$out, $v, $date, $zipfile, $url, $notes, $keyfile] = array_pad($a, 7, '');
    $key = secret_key($keyfile);
    $m = json_encode(['product' => 'SynapseMail', 'edition' => 'portable', 'version' => $v, 'released' => $date,
        'url' => $url, 'sha256' => hash_file('sha256', $zipfile), 'size' => filesize($zipfile), 'notes' => $notes], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    file_put_contents("$out/release.json", $m);
    file_put_contents("$out/release.json.sig", base64_encode(sodium_crypto_sign_detached($m, $key)) . "\n");
    break;

case 'sign-raw':
    [$file, $keyfile, $pubb64, $out] = array_pad($a, 4, '');
    $data = (string) file_get_contents($file);
    $sig = sodium_crypto_sign_detached($data, secret_key($keyfile));
    if (!sodium_crypto_sign_verify_detached($sig, $data, (string) base64_decode($pubb64, true))) {
        fail(['signature does not match the public key']);
    }
    file_put_contents($out, $sig);
    break;

case 'sha256':
    echo hash_file('sha256', $a[0] ?? ''), "\n";
    break;

default:
    fwrite(STDERR, "usage: php tools/release-check.php package|list|docker|zip|normalize|key|sign-files|sign-repo|sign-raw|sha256 ...\n");
    exit(2);
}
