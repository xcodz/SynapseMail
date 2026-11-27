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
 * Security state that parallel requests must not race: attempt counters, the last accepted authenticator time step
 * and one-time recovery codes. Every change is one conditional statement whose affected row count decides who won,
 * never "read, change in PHP, write back".
 *
 * $db is Roundcube's rcube_db (or anything with query(), fetch_assoc(), affected_rows() and $db_provider);
 * $table is the quoted table name.
 */
class synapse_atomic
{
    /* ---------------- attempt counters (table synapse_ratelimit: k, fails, since, blocked_until) ---------------- */

    /** One more attempt in the window (a new window starts at 1); creates the row when needed */
    private static function count($db, $table, $k, $now, $window, $limit = null)
    {
        // all SET expressions read the old row, so the window test is the same for both columns
        $sql = "UPDATE {$table} SET `fails` = CASE WHEN `since` > ? THEN `fails` + 1 ELSE 1 END,"
            . " `since` = CASE WHEN `since` > ? THEN `since` ELSE ? END WHERE `k` = ?";
        $args = [$now - $window, $now - $window, $now, $k];
        if ($limit !== null) {
            // a slot only while not blocked and below the limit (an expired window always has room)
            $sql .= ' AND `blocked_until` <= ? AND (`since` <= ? OR `fails` < ?)';
            array_push($args, $now, $now - $window, $limit);
        }
        for ($try = 0; $try < 2; $try++) {
            if ($db->affected_rows($db->query($sql, ...$args)) > 0) {
                return true;
            }
            $insert = ($db->db_provider ?? '') === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT';
            if ($db->affected_rows($db->query("{$insert} INTO {$table} (`k`, `fails`, `since`, `blocked_until`) VALUES (?, 1, ?, 0)", $k, $now)) > 0) {
                return true;
            }
            // the row exists (maybe created a moment ago by a parallel request): try the update once more
        }

        // with a limit: the key is blocked or every slot of the window is taken
        return false;
    }

    /** Block the key when it reached the limit; true when this call set the block */
    private static function block_if_full($db, $table, $k, $now, $limit, $block_seconds)
    {
        $res = $db->query("UPDATE {$table} SET `blocked_until` = ?, `fails` = 0, `since` = ? WHERE `k` = ? AND `fails` >= ?",
            $now + $block_seconds, $now, $k, $limit);

        return $db->affected_rows($res) > 0;
    }

    /** A failure that happened (sign-in, password change, ...): counted, and blocks at the limit */
    public static function rl_fail($db, $table, $k, $now, $window, $limit, $block_seconds)
    {
        self::count($db, $table, $k, $now, $window);

        return self::block_if_full($db, $table, $k, $now, $limit, $block_seconds);
    }

    /**
     * Take a slot before checking a guess (passcode, code, admin sign-in): false when the key is blocked or all
     * slots of the window are taken, so parallel requests never get more checks than the limit allows.
     * Settle afterwards with rl_settle().
     */
    public static function rl_take($db, $table, $k, $now, $window, $limit)
    {
        return self::count($db, $table, $k, $now, $window, $limit);
    }

    /** After a taken attempt: a success gives the slot back, a failure keeps it and blocks at the limit */
    public static function rl_settle($db, $table, $k, $ok, $now, $limit, $block_seconds)
    {
        if ($ok) {
            $db->query("UPDATE {$table} SET `fails` = `fails` - 1 WHERE `k` = ? AND `fails` > 0", $k);

            return false;
        }

        return self::block_if_full($db, $table, $k, $now, $limit, $block_seconds);
    }

    /** Seconds until the key's block ends (0 = not blocked) */
    public static function rl_wait($db, $table, $k, $now)
    {
        $row = $db->fetch_assoc($db->query("SELECT `blocked_until` FROM {$table} WHERE `k` = ?", $k));

        return $row ? max(0, (int) $row['blocked_until'] - $now) : 0;
    }

    /* ---------------- user preferences (users.preferences, a serialized array) ---------------- */

    /**
     * Change user preferences as one compare-and-swap: $change gets the stored preferences and returns the keys
     * to set (null removes a key), or null to change nothing. On a concurrent write it runs again on the new
     * state. Returns [changes, serialized preferences], or null when $change declined or the swap kept failing.
     */
    public static function prefs($db, $users_table, $user_id, callable $change, $tries = 5)
    {
        for ($try = 0; $try < $tries; $try++) {
            $row = $db->fetch_assoc($db->query("SELECT `preferences` FROM {$users_table} WHERE `user_id` = ?", $user_id));
            if (!$row) {
                return null;
            }
            $old = $row['preferences'];
            $prefs = (string) $old !== '' ? @unserialize((string) $old, ['allowed_classes' => false]) : [];
            $prefs = is_array($prefs) ? $prefs : [];
            $changes = $change($prefs);
            if (!is_array($changes)) {
                return null;
            }
            foreach ($changes as $key => $value) {
                if ($value === null) {
                    unset($prefs[$key]);
                } else {
                    $prefs[$key] = $value;
                }
            }
            unset($prefs['language']);
            $new = serialize($prefs);
            if ($new === (string) $old) {
                return [$changes, $new];
            }
            $res = $old === null
                ? $db->query("UPDATE {$users_table} SET `preferences` = ? WHERE `user_id` = ? AND `preferences` IS NULL", $new, $user_id)
                : $db->query("UPDATE {$users_table} SET `preferences` = ? WHERE `user_id` = ? AND `preferences` = ?", $new, $user_id, $old);
            if ($db->affected_rows($res) > 0) {
                return [$changes, $new];
            }
        }

        return null;
    }

    /* ---------------- admin accounts (table synapse_admins) ---------------- */

    /** Accept an authenticator time step only if it moves the stored one forward; exactly one request wins */
    public static function admin_step($db, $admins_table, $admin_id, $step)
    {
        $res = $db->query("UPDATE {$admins_table} SET `totp_last_step` = ? WHERE `id` = ? AND `totp_last_step` < ?", (int) $step, $admin_id, (int) $step);

        return $db->affected_rows($res) > 0;
    }

    /** Use one recovery code (format XXXX-XXXX): removed by compare-and-swap, so it works exactly once */
    public static function admin_recovery($db, $admins_table, $admin_id, $plain, $tries = 5)
    {
        for ($try = 0; $try < $tries; $try++) {
            $row = $db->fetch_assoc($db->query("SELECT `recovery_codes` FROM {$admins_table} WHERE `id` = ?", $admin_id));
            if (!$row) {
                return false;
            }
            $codes = (array) json_decode((string) $row['recovery_codes'], true);
            $hit = null;
            foreach ($codes as $i => $hash) {
                if (is_string($hash) && password_verify($plain, $hash)) {
                    $hit = $i;
                    break;
                }
            }
            if ($hit === null) {
                return false;
            }
            unset($codes[$hit]);
            $res = $db->query("UPDATE {$admins_table} SET `recovery_codes` = ? WHERE `id` = ? AND `recovery_codes` = ?",
                json_encode(array_values($codes)), $admin_id, $row['recovery_codes']);
            if ($db->affected_rows($res) > 0) {
                return true;
            }
        }

        return false;
    }
}
