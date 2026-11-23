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
 * Updates: a signed release manifest on the update server (release.json + release.json.sig) says which version is
 * newest, and every release zip carries a signed list of its files (SYNAPSEMAIL-RELEASE.json + .sig, Ed25519 via
 * libsodium). An update, downloaded or uploaded by the admin, is installed only when that signature is valid, the
 * version is newer and every file matches its hash. The signature protects the files, so the server may use http.
 */
class synapse_update
{
    // release signing public key; the private key never leaves the release machine
    public const PUBLIC_KEY = 'xOVkjBQXNYQw8ER4+vako4ed+cDYrj0LJPYfMqaTyxM=';
    public const PACKAGE_MANIFEST = 'SYNAPSEMAIL-RELEASE.json';
    public const PACKAGE_SIG = 'SYNAPSEMAIL-RELEASE.sig';
    public const MAX_ZIP = 64 * 1048576;
    public const MAX_FILE = 24 * 1048576;
    public const MAX_TOTAL = 256 * 1048576;
    public const MAX_FILES = 20000;
    // per-install files and data: an update never writes them
    public const KEEP = '#^(config/(synapse\.inc\.php|config\.local\.inc\.php|installed\.lock|ADMIN-URL\.txt)|setup\.key|db/(?!\.htaccess$).+|logs/(?!\.htaccess$).+|temp/(?!\.htaccess$).+)$#';

    /** @return array ['latest' => manifest, 'newer' => bool] or ['error' => text] */
    public static function check($url, $current)
    {
        if (!preg_match('#^https?://[^/\s]+/#i', (string) $url)) {
            return ['error' => 'Update server not set.'];
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return ['error' => 'PHP sodium extension missing: signatures cannot be checked.'];
        }
        $json = self::fetch($url);
        $sig = self::fetch($url . '.sig');
        if ($json === null || $sig === null) {
            return ['error' => 'Could not reach the update server.'];
        }
        $m = self::verify($json, $sig, self::PUBLIC_KEY);
        if ($m === null) {
            return ['error' => 'The release manifest is not correctly signed: ignored.'];
        }

        return ['latest' => $m, 'newer' => version_compare($m['version'], $current, '>')];
    }

    /** Parsed manifest when the signature and the fields are valid, else null */
    public static function verify($json, $sig_b64, $public_b64)
    {
        if (!self::signed($json, $sig_b64, $public_b64)) {
            return null;
        }
        $m = json_decode((string) $json, true);
        if (!is_array($m) || ($m['product'] ?? '') !== 'SynapseMail' || !preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($m['version'] ?? ''))
            || !preg_match('#^https?://#', (string) ($m['url'] ?? '')) || !preg_match('/^[0-9a-f]{64}$/', (string) ($m['sha256'] ?? ''))) {
            return null;
        }

        return $m;
    }

    private static function signed($data, $sig_b64, $public_b64)
    {
        $sig = base64_decode(trim((string) $sig_b64), true);
        $pub = base64_decode((string) $public_b64, true);

        return $sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES && strlen((string) $pub) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            && sodium_crypto_sign_verify_detached($sig, (string) $data, (string) $pub);
    }

    /** Small GET with certificate checks (https) */
    private static function fetch($url, $max = 65536, $timeout = 6, $to = null)
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'follow_location' => 1, 'max_redirects' => 3, 'header' => "User-Agent: SynapseMail-update\r\n", 'ignore_errors' => false],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        if ($to === null) {
            $data = @file_get_contents($url, false, $ctx, 0, $max);

            return $data === false ? null : $data;
        }
        $in = @fopen($url, 'rb', false, $ctx);
        $out = $in ? @fopen($to, 'wb') : false;
        $n = $in && $out ? stream_copy_to_stream($in, $out, $max + 1) : false;
        $in && fclose($in);
        $out && fclose($out);

        return $n === false || $n > $max ? null : $to;
    }

    /** Download the release named by a verified manifest into $dir; the file must match the signed sha256 */
    public static function download(array $m, $dir)
    {
        $file = rtrim($dir, '/') . '/sm-update-' . bin2hex(random_bytes(6)) . '.zip';
        if (self::fetch($m['url'], self::MAX_ZIP, 120, $file) === null) {
            @unlink($file);
            throw new RuntimeException('Could not download the update. Try again later or upload the zip.');
        }
        if (!hash_equals($m['sha256'], hash_file('sha256', $file))) {
            @unlink($file);
            throw new RuntimeException('The downloaded file does not match the signed release: not installed.');
        }

        return $file;
    }

    /**
     * Open a release zip and check it completely before anything is written.
     *
     * @return array ['zip' => ZipArchive, 'version' => string, 'files' => [path => sha256], 'notes' => string]
     */
    public static function open($file, $current, $public_b64 = self::PUBLIC_KEY)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is missing: install updates by extracting the zip in File Manager.');
        }
        if (!is_file($file) || filesize($file) > self::MAX_ZIP) {
            throw new RuntimeException('Not a SynapseMail update (missing or larger than 64 MB).');
        }
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Not a zip file.');
        }
        $json = $zip->getFromName(self::PACKAGE_MANIFEST, 4 * 1048576);
        $sig = $zip->getFromName(self::PACKAGE_SIG, 1024);
        if ($json === false || $sig === false) {
            throw new RuntimeException('This zip has no SynapseMail signature. Use the zip from GitHub Releases or the update server.');
        }
        if (!self::signed($json, $sig, $public_b64)) {
            throw new RuntimeException('The signature of this zip is not valid: it was changed or not made by SynapseCyber.');
        }
        $m = json_decode($json, true);
        if (!is_array($m) || ($m['product'] ?? '') !== 'SynapseMail' || !preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($m['version'] ?? ''))
            || !is_array($m['files'] ?? null) || count($m['files']) > self::MAX_FILES) {
            throw new RuntimeException('The release list in this zip is not valid.');
        }
        if (!version_compare($m['version'], $current, '>')) {
            throw new RuntimeException("This is version {$m['version']}; version {$current} is installed. Only newer versions can be installed.");
        }
        foreach ($m['files'] as $path => $sha) {
            if (!self::safe_path($path) || !preg_match('/^[0-9a-f]{64}$/', (string) $sha)) {
                throw new RuntimeException('The release list contains an unsafe path: ' . substr((string) $path, 0, 80));
            }
        }
        // every entry must be listed (or be a folder or the signature itself) and be a plain file of sane size
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = $st['name'];
            if (substr($name, -1) === '/' || $name === self::PACKAGE_MANIFEST || $name === self::PACKAGE_SIG) {
                continue;
            }
            if (!isset($m['files'][$name])) {
                throw new RuntimeException('The zip contains a file that is not in the signed list: ' . substr($name, 0, 80));
            }
            if ($zip->getExternalAttributesIndex($i, $os, $attr) && $os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('The zip contains a link: ' . substr($name, 0, 80));
            }
            $total += $st['size'];
            if ($st['size'] > self::MAX_FILE || $total > self::MAX_TOTAL) {
                throw new RuntimeException('The zip unpacks to more data than a SynapseMail release.');
            }
        }

        return ['zip' => $zip, 'version' => $m['version'], 'files' => $m['files'], 'notes' => (string) ($m['notes'] ?? '')];
    }

    public static function safe_path($path)
    {
        $path = (string) $path;

        return $path !== '' && strlen($path) < 400 && $path[0] !== '/' && strpos($path, '\\') === false && strpos($path, "\0") === false
            && !preg_match('#(^|/)\.\.?(/|$)#', $path) && !preg_match('#//#', $path) && !preg_match('/[\x00-\x1f]/', $path);
    }

    /**
     * Install an opened package into $root. Writes only files that differ, never per-install files, keeps
     * cPanel's PHP handler block in .htaccess files and saves the replaced files to $rollback_zip first.
     * On a write error the replaced files are put back.
     *
     * @return array ['changed' => n, 'added' => n, 'rollback' => path]
     */
    public static function apply(array $pkg, $root, $rollback_zip)
    {
        $root = rtrim($root, '/') . '/';
        $zip = $pkg['zip'];
        $plan = [];
        foreach ($pkg['files'] as $path => $sha) {
            if (preg_match(self::KEEP, $path)) {
                continue;
            }
            $data = $zip->getFromName($path);
            if ($data === false || !hash_equals($sha, hash('sha256', $data))) {
                throw new RuntimeException('A file in the zip does not match the signed list: ' . $path);
            }
            $target = $root . $path;
            $old = is_file($target) ? (string) file_get_contents($target) : null;
            if ($old !== null && basename($path) === '.htaccess') {
                $data = self::keep_handler($data, $old);
            }
            if ($old !== null && hash_equals(hash('sha256', $old), hash('sha256', $data))) {
                continue;
            }
            $plan[$path] = [$data, $old];
        }
        // every target must be writable before the first write
        $blocked = [];
        foreach ($plan as $path => [, $old]) {
            $target = $root . $path;
            $dir = dirname($target);
            while (!is_dir($dir) && $dir !== dirname($dir)) {
                $dir = dirname($dir);
            }
            if ($old !== null ? !is_writable($target) : !is_writable($dir)) {
                $blocked[] = $path;
            }
        }
        if ($blocked) {
            throw new RuntimeException('PHP cannot write ' . count($blocked) . ' file(s), e.g. ' . implode(', ', array_slice($blocked, 0, 3))
                . '. Fix the file owner/permissions or extract the zip in File Manager. Nothing was changed.');
        }
        if (!$plan) {
            return ['changed' => 0, 'added' => 0, 'rollback' => null];
        }
        // rollback copy of what is replaced, plus the list of new files
        $rb = new ZipArchive();
        if ($rb->open($rollback_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the rollback copy (' . basename($rollback_zip) . '). Nothing was changed.');
        }
        $added = [];
        foreach ($plan as $path => [, $old]) {
            if ($old === null) {
                $added[] = $path;
            } else {
                $rb->addFromString($path, $old);
            }
        }
        $rb->addFromString('ROLLBACK-NEW-FILES.txt', implode("\n", $added) . "\n");
        if (!$rb->close()) {
            throw new RuntimeException('Cannot write the rollback copy. Nothing was changed.');
        }
        @chmod($rollback_zip, 0600);

        $done = [];
        foreach ($plan as $path => [$data, $old]) {
            $target = $root . $path;
            $tmp = $target . '.sm-' . bin2hex(random_bytes(4));
            $ok = (is_dir(dirname($target)) || @mkdir(dirname($target), 0755, true))
                && @file_put_contents($tmp, $data) === strlen($data) && @chmod($tmp, 0644) && @rename($tmp, $target);
            if (!$ok) {
                @unlink($tmp);
                foreach ($done as $p => $prev) {
                    $prev === null ? @unlink($root . $p) : @file_put_contents($root . $p, $prev);
                }
                throw new RuntimeException("Writing {$path} failed. The previous files were put back.");
            }
            $done[$path] = $old;
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['changed' => count($plan) - count($added), 'added' => count($added), 'rollback' => $rollback_zip];
    }

    /** cPanel MultiPHP writes its PHP version handler into .htaccess: keep it when the file is replaced */
    public static function keep_handler($new, $old)
    {
        if (preg_match('/\n?# php -- BEGIN cPanel-generated handler.*?# php -- END cPanel-generated handler[^\n]*\n?/s', $old, $m)
            && strpos($new, '# php -- BEGIN cPanel-generated handler') === false) {
            return rtrim($new, "\n") . "\n\n" . trim($m[0], "\n") . "\n";
        }

        return $new;
    }
}
