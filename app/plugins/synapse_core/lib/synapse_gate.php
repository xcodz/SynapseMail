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
 * Rules of the sign-in gate and of security changes, without Roundcube state (the self test checks them).
 * $pin_state: null | 'pending' | 'locked'.  $otp_state: null | 'pending' (an authenticator is enrolled) | 'enroll'
 * (the server requires one and the account has none yet).
 */
class synapse_gate
{
    /** How long a confirmed password or authenticator code allows changes to the sign-in factors */
    public const STEPUP_SECONDS = 300;

    /**
     * The factor the session must clear next: 'otp', 'enroll', 'pin' or null (signed in).
     * An enrolled authenticator comes before the passcode. A first enrolment comes after the passcode,
     * so someone with only the password cannot enrol their own authenticator on an account with a passcode.
     */
    public static function next_factor($pin_state, $otp_state)
    {
        if ($pin_state === 'locked') {
            return 'pin';
        }
        if ($otp_state === 'pending') {
            return 'otp';
        }
        if ($pin_state === 'pending') {
            return 'pin';
        }

        return $otp_state === 'enroll' ? 'enroll' : null;
    }

    /** The passcode may be checked (or its hint shown, or it may be erased) only when it is the current step */
    public static function pin_step($pin_state, $otp_state)
    {
        return $pin_state === 'pending' && self::next_factor($pin_state, $otp_state) === 'pin';
    }

    /** A first enrolment in the gate, only when every other factor has passed */
    public static function enroll_step($pin_state, $otp_state)
    {
        return self::next_factor($pin_state, $otp_state) === 'enroll';
    }

    /** A step-up is valid until its time */
    public static function stepup_valid($until, $now)
    {
        return (int) $until > (int) $now;
    }

    /** The password given for a step-up matches the one this session signed in with */
    public static function password_matches($session_password, $given)
    {
        return is_string($session_password) && is_string($given) && $given !== '' && hash_equals($session_password, $given);
    }
}
