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
 * Encrypted backup of the app data: a consistent SQLite snapshot (without caches, sessions and counters) plus
 * config/synapse.inc.php (des_key: needed to read stored 2FA secrets). AES-256-GCM under a random 256-bit key
 * that is shown to the admin once and never stored.
 *
 * File: "SMBK1" | iv (12) | tag (16) | gzip(JSON {meta, db, config}) encrypted.
 */
class synapse_backup
{
    private const MAGIC = 'SMBK1';
    // rebuilt by the app; leaving them out keeps backups small and free of live session data
    public const SKIP_TABLES = ['session', 'cache', 'cache_shared', 'cache_index', 'cache_thread', 'cache_messages', 'uploads', 'synapse_ratelimit'];

    /** @return array [encrypted file contents, key for humans] */
    public static function create($db_file, $cfg_file, $version)
    {
        $snap = self::snapshot($db_file, dirname($db_file) . '/backup-' . bin2hex(random_bytes(6)) . '.tmp');
        try {
            $pdo = new PDO('sqlite:' . $snap, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (self::tables($pdo) as $t) {
                if (in_array($t, self::SKIP_TABLES, true)) {
                    $pdo->exec('DELETE FROM "' . $t . '"');
                }
            }
            $pdo->exec('VACUUM');
            $pdo = null;
            // shortcut: whole snapshot in memory, fine for app data (mail stays on the mail server); stream if DBs grow past ~100 MB
            $payload = gzencode(json_encode([
                'meta' => ['format' => 1, 'created' => time(), 'version' => $version, 'host' => $_SERVER['HTTP_HOST'] ?? gethostname()],
                'db' => base64_encode(file_get_contents($snap)),
                'config' => base64_encode((string) @file_get_contents($cfg_file)),
            ]), 6);
        } finally {
            @unlink($snap);
        }
        $key = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);

        return [self::MAGIC . $iv . $tag . $ct, self::key_to_text($key)];
    }

    /** Decrypt and validate a backup; throws RuntimeException with a message for the admin */
    public static function open($data, $key_text)
    {
        $key = self::key_from_text($key_text);
        if ($key === null) {
            throw new RuntimeException('The key is not valid: it has 52 letters and digits (spaces and dashes do not matter).');
        }
        if (strncmp((string) $data, self::MAGIC, 5) !== 0 || strlen($data) < 34) {
            throw new RuntimeException('This is not a SynapseMail backup file.');
        }
        $plain = openssl_decrypt(substr($data, 33), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($data, 5, 12), substr($data, 17, 16), self::MAGIC);
        $json = $plain === false ? null : json_decode((string) @gzdecode($plain), true);
        if (!is_array($json) || ($json['meta']['format'] ?? 0) !== 1) {
            throw new RuntimeException('Wrong key, or the file is damaged.');
        }

        return $json;
    }

    /**
     * Replace the app data with the backup inside one transaction on the live database (WAL-safe, no file swap).
     * Tables of the backup are copied column by column (columns both sides know), so a newer Roundcube schema
     * still restores. Returns the path of the automatic snapshot taken before restoring.
     */
    public static function restore(array $backup, $db_file, $cfg_file)
    {
        $dir = dirname($db_file);
        $before = self::snapshot($db_file, $dir . '/before-restore-' . gmdate('Ymd-His') . '.db');
        $src = $dir . '/restore-' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($src, base64_decode($backup['db']));
        try {
            $check = new PDO('sqlite:' . $src, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || !in_array('users', self::tables($check), true)) {
                throw new RuntimeException('The database inside the backup is damaged.');
            }
            $check = null;

            $pdo = new PDO('sqlite:' . $db_file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec("ATTACH DATABASE " . $pdo->quote($src) . " AS r");
            $live = self::tables($pdo);
            $pdo->beginTransaction();
            foreach ($live as $t) {
                if (!in_array($t, ['synapse_ratelimit', 'session'], true)) {
                    $pdo->exec('DELETE FROM main."' . $t . '"');
                }
            }
            foreach (self::tables($pdo, 'r') as $t) {
                if (!in_array($t, $live, true)) {
                    $sql = $pdo->query("SELECT sql FROM r.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
                    $pdo->exec(preg_replace('/^CREATE TABLE\s+("?' . preg_quote($t, '/') . '"?)/i', 'CREATE TABLE main.$1', $sql));
                }
                $cols = array_values(array_intersect(self::columns($pdo, 'main', $t), self::columns($pdo, 'r', $t)));
                if ($cols) {
                    $list = '"' . implode('", "', $cols) . '"';
                    $pdo->exec("INSERT INTO main.\"{$t}\" ({$list}) SELECT {$list} FROM r.\"{$t}\"");
                }
            }
            $pdo->exec('DELETE FROM main."session"'); // everyone signs in again under the restored data
            $pdo->commit();
            $pdo->exec('DETACH DATABASE r');
            $pdo = null;
        } finally {
            @unlink($src);
        }

        // des_key decides whether stored 2FA secrets can be read: take the backup's; keep this server's mail settings
        if (preg_match('/\$config\[\'des_key\'\]\s*=\s*(\'[^\']*\');/', (string) base64_decode($backup['config']), $m)) {
            $cfg = (string) file_get_contents($cfg_file);
            $new = preg_replace('/\$config\[\'des_key\'\]\s*=\s*\'[^\']*\';/', '$config[\'des_key\'] = ' . $m[1] . ';', $cfg, 1, $n);
            $tmp = $cfg_file . '.tmp';
            if ($n !== 1 || file_put_contents($tmp, $new, LOCK_EX) === false || !@chmod($tmp, 0640) || !rename($tmp, $cfg_file)) {
                @unlink($tmp);
                throw new RuntimeException('Data restored, but the encryption key could not be written to config/synapse.inc.php.');
            }
        }

        return $before;
    }

    /** Consistent copy of a live (WAL) database */
    public static function snapshot($db_file, $to)
    {
        $pdo = new PDO('sqlite:' . $db_file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('VACUUM INTO ' . $pdo->quote($to));
        @chmod($to, 0640);

        return $to;
    }

    public static function key_to_text($key)
    {
        return trim(chunk_split(synapse_totp::base32_encode($key), 4, ' '));
    }

    public static function key_from_text($text)
    {
        $b32 = strtoupper(preg_replace('/[\s-]+/', '', (string) $text));
        if (!preg_match('/^[A-Z2-7]{52}$/', $b32)) {
            return null;
        }
        $key = synapse_totp::base32_decode($b32);

        return strlen($key) === 32 ? $key : null;
    }

    private static function tables(PDO $pdo, $schema = 'main')
    {
        return $pdo->query("SELECT name FROM {$schema}.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function columns(PDO $pdo, $schema, $table)
    {
        return array_column($pdo->query("PRAGMA {$schema}.table_info(\"{$table}\")")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
}
