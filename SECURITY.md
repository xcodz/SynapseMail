# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub: **Security › Report a vulnerability** on this repository.
Do not open a public issue. Include the version (shown in the footer and the admin panel), steps to reproduce,
and the impact. We aim to confirm within 7 days.

Vulnerabilities in Roundcube itself belong upstream: https://roundcube.net/support/. Please also tell us, so we
can ship the fixed core.

## Supported versions

Only the latest 3.x release receives fixes. New versions are published on GitHub Releases
(https://github.com/xcodz/SynapseMail/releases).

## Security design

- **Sign-in:**
  - The mailbox password is checked by the mail server (IMAP). SynapseMail never stores it, except encrypted in
    the session, as Roundcube does.
  - Optional second steps: a numeric passcode and TOTP 2FA. TOTP secrets are encrypted with AES-256-GCM under a
    key derived from the install's `des_key`. Recovery codes are stored as bcrypt hashes. A TOTP code is
    accepted only once.
  - PIN and 2FA protect webmail access only. IMAP/SMTP clients are not affected.
- **Rate limits:** sign-in, passcode, 2FA, password change and admin sign-in, per account and per IP. Counters
  are stored as hashes, never as plain addresses or IPs.
- **Password change:** cPanel UAPI over HTTPS on port 2096 with certificate verification, signed in as the
  mailbox itself. No API tokens are stored. The current password is required, the session ID is renewed after
  a change, and each attempt is logged.
- **Admin panel:**
  - reachable only at a private address (`/admin/<key>`, about 79 random bits); other paths answer 404,
    and the key can be replaced at any time
  - a separate account with mandatory 2FA
  - its own session (SameSite=Strict, 15-minute idle timeout)
  - CSRF tokens on every change
  - every action logged to `logs/synapse_security.log`
  - restoring a backup also needs a fresh authenticator code
- **Headers:**
  - Content-Security-Policy limited to this origin (the core still needs `unsafe-inline`/`unsafe-eval` for
    scripts), `frame-ancestors 'self'`
  - `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`
  - no third-party requests
  - HSTS is left to the hosting (cPanel/AutoSSL), because it applies to the whole domain
- **Installer:** requires proof of hosting-account ownership (`setup.key`) and locks itself permanently.
  `config/`, `db/`, `logs/` and `temp/` are blocked from the web, and the installer checks this.
- **Backups:** AES-256-GCM with a random key per backup that is shown once and never stored on the server.
- **Updates:** the release manifest is signed with Ed25519. The public key is built into the app; the private
  key never leaves the release machine.
- **Logs:** no passwords, codes or message content are logged. Releases contain no debug logging.
- **Releases:** every build is checked for per-install files (config, database, logs) and secret-looking content
  before it is packaged.
