# SynapseMail changelog

## 3.0 (first public release)
- **Public repository** with the complete package under `app/`, the editor source under `editor/` and the build
  tools under `tools/`. `app/SYNAPSEMAIL-FILES.txt` lists every SynapseMail file and every change to Roundcube.
- **Updates** are checked by default against `https://repo.synapsecyber.io/synapsemail/release.json` (signed).
- **Security wording:** the passcode and 2FA are described as protecting this webmail. Mail apps that use
  IMAP/POP/SMTP still sign in with the mailbox password alone.
- **Removed:** the development debug log collector (`synapse_debug_collect`, `debug.js`).
- **Release build** (`tools/build_release.sh`): checks PHP syntax, blocks per-install files and secret-looking
  content, verifies the file list, and writes the signed manifest for the repo site.

## 2.30
- Page titles use " - " instead of " :: " ("SynapseMail - Inbox"), including titles the app changes later and the
  admin and installer pages.
- Sign-in page title: "SynapseMail - Welcome to <mail service name>" (the name set in Admin › Security).

## 2.29
- Admin › Users: the Actions column lines up with the rest of each row (the cell stayed a table cell; the
  buttons sit in an inner row).

## 2.28
- **Admin panel redesigned as a dashboard:**
  - side navigation and a top bar with a session countdown
  - overview: KPI cards (mailboxes, 2FA adoption, sign-in blocks, deliverability), system health, quick
    actions and recent security events
  - users: searchable, filterable table
  - deliverability: score ring
  - logs: filterable table
  - card-based security, backup and account pages
  - works on phones
- **Private admin address:** `/mail/admin/<key>` instead of `admin.php`; everything else answers 404.
  - The installer shows the address once and needs "I saved the admin address" ticked before its links
    unlock. It is also saved in `config/ADMIN-URL.txt`.
  - Installs from before 2.28 move after the admin's next sign-in at `admin.php`.
  - Admin › Security can generate a new address.
- **Dropdowns** in the installer and the admin panel use SynapseMail styling instead of the system menus.
- **Admin and installer assets** carry a version stamp, so an upgrade is never shown with an old cached
  stylesheet.

## 2.27
- **Background:** the "secure transit" scene is now used only on the login page, the installer and the admin
  panel. The app is back to its earlier HUD.
- **Default theme:** Secure Blue. A theme is now remembered only when a user picks it; themes saved by earlier
  versions are ignored once, and account themes still apply after sign-in.
- **Mail service name** on the login page (was always "SYNAPSE CLOUD"):
  - the installer asks for it in the mail server step
  - it can be changed in Admin > Security
  - without one, the site's host name is shown
- **Messages that arrive with a page** now appear as toasts, including "Successfully saved" after a password
  change. Before, they were missed.

## 2.26
- New background ("secure transit"), used on the login page, the app, the installer and the admin panel:
  - a relay mesh of MX, relay, IMAPS, SMTP, DKIM and vault nodes that carries mail hop by hop
  - TLS handshake rings at each hop, and a cipher-text trail behind each message
  - mail from outside occasionally rejected at an MX gateway (SPF, DKIM, DMARC, TLS, RBL)
  It replaces the template's radar, contour and scanline layers and the earlier "intrusion lock" HUD. It
  follows the colour theme, runs at 30 fps or less, pauses in hidden tabs and shows one still frame for
  reduced-motion users.

## 2.25
- The zip ships an example `setup.key` (placeholder SM-XXXX-XXXX-XXXX, explained in comments): replace the
  code instead of creating the file. The placeholder is never accepted.

## 2.24
- Installer: the session cookie now uses the browser's URL path. Behind the root .htaccess (/mail/x served
  from /mail/public_html/x) every step failed with "The form expired" and the setup.key code changed on every
  click.
- Installer: error messages use the full width.


## 2.23
- Interface strings are localizable. English is unchanged. Persian (fa_IR) and Arabic (ar_SA) translations
  are included as drafts for native review.
- Signed update check in the admin panel: an Ed25519-signed `release.json` and the SHA-256 of the zip.
- Docs: README, SECURITY.md and this changelog. The license notice now lists the bundled assets.
- Development: the debug log collector (`synapse_debug_collect`) is off in releases, and the build refuses to
  ship it on.

## 2.22
- Admin panel: encrypted backup and restore (AES-256-GCM, key shown once). Restore saves the previous data
  first and needs an authenticator code.

## 2.21
- Admin panel: read-only deliverability score per mail domain: MX, PTR, SPF (with lookup count), DKIM key
  size, DMARC, TLS certificates and public blacklists, plus MTA-STS, TLS-RPT and BIMI badges. Failing checks
  come with ready-to-paste DNS records.

## 2.20
- Admin panel (`admin.php`):
  - separate admin account with mandatory 2FA
  - health overview
  - users: end sessions, reset passcode or 2FA, unlock
  - 2FA policy and clearing sign-in blocks
  - log viewer
  - admin password change and new recovery codes

## 2.19
- Self-service password change through cPanel (port 2096, TLS verified):
  - the current password is required
  - 12+ characters with a digit and a symbol
  - 5 attempts per 15 minutes
  - a new session ID after a change
  - every attempt logged

## 2.18
- TOTP two-factor authentication with recovery codes, next to the passcode. Admin policy: off, optional or
  required.

## 2.17
- Web installer: ownership proof, environment and mail-server checks, admin account with 2FA, self-lock.
  The zip no longer ships a database or per-install config.

## 2.16
- Security hardening: bundled icons, fonts and scripts, strict CSP and security headers, rate limits on
  sign-in and passcode.

## 2.15
- Roundcube core 1.7.5.
