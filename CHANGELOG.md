# SynapseMail changelog

## 3.5.5
- **Security fixes.** Several fixes for sign-in, Send As, backups and updates. Update every copy.
- **Roundcube core 1.7.7**, with the security fixes of Roundcube 1.7.6 and 1.7.7.
- Send As: the 6-digit code now always comes from your own mail server, sent from your sign-in address. Moving an
  address to or from "Send through this mail server" needs a new code.
- Sending refuses a From address that is not one of your addresses. Add other addresses with Settings › Send As.
- Updates are downloaded over https only.
- **Docker:** hardened data folder and start script. The container trusts only the Docker gateway as a reverse proxy
  by default; a proxy in another container needs its address in `SYNAPSEMAIL_TRUSTED_PROXIES`. The compose file
  drops the capabilities the container does not need.

## 3.5.4
- **Installer: the ownership check can no longer be skipped.** Each installer step now needs the steps before it
  in the same browser session. Before, a visitor who opened the installer of a copy that was not installed yet could
  send the later steps directly and finish the install without the `setup.key` code. Installed copies are not
  affected (the installer locks itself after the install), but install new copies with 3.5.4.

## 3.5.3
- **Choose how a message opens.** New in Settings › Appearance › Reading: **Full view** (the reading pane fills the
  mail area, the new default), **Split view** (next to the message list) or **Window** (a separate window you can
  move). It applies to a click, a double-click and Enter. In Window mode the arrow keys only select, so moving
  through the list does not open a window for every message. On phones and small screens a message still opens full
  screen.
- After a click on a message, the Enter key no longer goes to the last button you used (for example Back to inbox).
- **Message list checkboxes work again.** Ticking a message's checkbox selected the row and then undid it, so the
  selection stayed empty. Ticking several messages now selects them for bulk actions, and the star and read toggle no
  longer select or open the message.
- **Admin › Updates and Backup: clear message when a file is too big for PHP.** A zip larger than `post_max_size`
  reached the panel as an empty form and showed "The form expired". The panel now names the limit (the smaller of
  `upload_max_filesize` and `post_max_size`), the browser refuses such a file before uploading, and the page shows
  the real limit.

## 3.5.2
- **Fix: "Connection to storage server failed" with All inboxes.** On servers where SynapseMail reaches its own mail
  server as `ssl://localhost:993` (common on cPanel), turning on another inbox made the own mailbox skip its IMAP
  connection settings, so the certificate check failed. The own mailbox now always uses its configured settings.

## 3.5.1
- **All inboxes.** Your inbox now shows the mail of all your addresses together, newest first, with a small label
  on mail that came to another address. Nothing is copied: each message stays on its own server, and reading,
  replying (from the right address), flags and delete work there. The mailbox menu still switches to a single
  inbox: All inboxes, only your own mailbox, or one of your addresses. If one inbox cannot be reached, the others
  are still listed and you see which one is missing.

## 3.5
- **Read the inboxes of your Send As addresses.** Open any of your addresses from the new mailbox menu above the
  folders and read its mail on that address's own server (IMAP over TLS, port 993). Nothing is imported or copied
  into your main mailbox; "Back to my inbox" returns at once. Unread counts for every mailbox, its own folders
  (Sent, Drafts, Trash found automatically), replies and new mail from that address. Turn it on per address in
  Settings › Send As (one click when the address sends with the same password), or right after adding an address.
  Settings, contacts and filters always stay with your own account. If the password of that inbox changes,
  SynapseMail goes back to your own inbox and tells you why.
- **Updates from the admin panel.** New page Admin › Updates: check the update server, install with one click, or
  upload the release zip. Every release zip is signed (a signed list with the SHA-256 of every file); unsigned,
  changed or older zips are refused, path tricks and links are blocked. Config secrets, the database, logs and temp
  files are never written, cPanel's PHP handler lines in .htaccess are kept, only changed files are written, and the
  replaced files are saved in db/ first. The Roundcube database schema is updated when needed. Update history with
  the rollback copy of each update. The update server address can be changed in the panel.
- **Settings › Appearance (new):** theme cards, animations (match system, reduced, off), background effects,
  compact message list, text size, higher contrast and underlined links. Applied at once, saved to the account and
  set before the page draws (no flash).
- **Soft Light theme:** a calm grey-blue light theme (not stark white) with readable contrast, for the webmail, the
  login page and the admin panel. "Match system" uses Soft Light by day and your dark theme by night.
- **Admin panel redesign:** calmer layout with readable sentence-case labels, settings in rows (no more gaps between
  cards), a numbers strip, skin-drawn checkboxes, clear button states, a light/dark switch, skip link and better
  mobile layout. The Users page shows other inboxes per user.
- **Settings controls:** checkboxes and radio buttons are drawn in the skin (no more browser-default check marks),
  with focus rings; settings labels are readable text instead of tiny uppercase code font.
- Theme colours in the skin's own styles now follow every theme (some olive tints showed in the other themes).

## 3.4
- **Compose: a natural keyboard order.** Tab goes From → To → Subject → message text → attachments and Send. Before,
  every field had `tabindex="1"`, so after Subject the Tab key jumped back to the drawer buttons and through 13 editor
  buttons before the message text.
- **Cc and Bcc shortcuts:** Ctrl+Shift+C and Ctrl+Shift+B (⌘+Shift on Mac) open the field and put the cursor in it
  (shown in the button tooltips). The Cc / Bcc / Reply-To buttons are no longer extra Tab stops.
- **Editor toolbar** is one Tab stop (Shift+Tab from the message text); the arrow keys move between its buttons.
- **Recipient autocomplete is quiet:** typing an address no longer shows a "Searching..." toast for every letter.
  The suggestion list is the feedback.

## 3.3
- **Send As** (Settings › Send As, was "Identities"): send from other addresses, each with its own SMTP server,
  name, signature and a default address. See `docs/SEND_AS.md`.
- **Setup wizard:** finds the SMTP settings (known providers, Mail Autoconfig, Thunderbird ISPDB, DNS SRV, MX, a
  guess) and shows where they came from; every setting can be changed. Tips for Gmail/Yahoo App Passwords,
  Microsoft 365 SMTP AUTH and Outlook.com (OAuth, not supported yet).
- **Verification:** a 6-digit code is sent to the new address through its own server (15 minutes, 5 tries, new
  code after 60 seconds). Nothing is saved before the code is correct.
- **Security:** passwords encrypted (AES-256-GCM), password only sent over TLS (stops when a server does not offer
  STARTTLS), certificates checked (the wizard offers the certificate's name), private and local addresses blocked
  (also inside IPv6) with the IP pinned against DNS rebinding, mail ports only, rate limits per user, security log.
- "Send through this mail server" only for the user's domain and the domains the admin allows (DMARC).
- Replies come from the address the message was sent to, or always from the default address (new option).
- The old "add identity" form can no longer add another address without the wizard. Existing identities keep
  working and can be verified with "Verify this address".
- **Hosting firewall detection:** when an outside server cannot be reached, the wizard checks the same port on
  Gmail and Microsoft 365 and reads the greeting. If the hosting blocks or redirects outgoing mail (CSF `TCP_OUT` /
  `SMTP_BLOCK`, WHM "Restrict outgoing SMTP to root, exim, and mailman"), it says so with the fix, instead of a
  general error. The admin "Outgoing SMTP" check does the same.
- **App Passwords** shown as `abcd efgh ijkl mnop` work with or without the spaces.
- Only failed server tests count toward the limit (15 in 15 minutes), and the wait time counts down live.
- Clear messages when sending through a Send As server fails ("Could not send as ...: the server did not accept
  this user name or password").
- **Admin panel:** Send As settings (on/off, addresses per user, domains this server sends for, local network
  servers), a Send As column in Users, and an "Outgoing SMTP" health check with the cPanel SMTP Restrictions tip.
- **Demo:** every visitor has a sample Send As address; the wizard works with the built-in provider list and the
  code 123456, without any network connection.

## 3.2
- **Public demo build** (`tools/build_demo.sh`, see `docs/DEMO.md`): the real interface with a sample mailbox and
  no mail server. Sign in with demo / demo, 2FA 123456, passcode 1234. Every visitor gets a private temporary
  user, sending is blocked, and a notice at the top says it is a demo. The admin panel demo is read-only and shows
  sample data. The demo plugin never ships in the release zip.
- The admin panel and the 2FA check accept the fixed demo code only when the demo build switches it on.

## 3.1
- **Installer: hosting panel detection.** The Environment step detects cPanel, Plesk, DirectAdmin, HestiaCP/VestaCP,
  CloudPanel, aaPanel, ISPConfig or a plain Linux server from the install path, shows the web server, and lets you
  pick another panel if the guess is wrong.
- **Installer: "How to fix on <panel>"** under every failed or warning check (PHP version, extensions, folder
  permissions, HTTPS), and a mail-server hint on the Mail step.
- **nginx guard:** when `db/` can be downloaded (nginx ignores `.htaccess`), the installer shows ready nginx rules
  with your folder name and a copy button, and stays blocked until the test passes.
- **Fix:** the db/ protection test now fetches the install folder's `db/` (it used to test `public_html/db/`, so
  an nginx install opened at `.../public_html/install.php` could pass without protection).
- **Honest login text:** "END-TO-END ENCRYPTED" is now "TLS ENCRYPTED CONNECTION" and the login text no longer
  says "encrypted mailbox" (English, Persian, Arabic).
- **README** rewritten with screenshots.
- **Docs:** `docs/TROUBLESHOOTING.md` and `docs/HOSTING-PANELS.md` (generated from the installer's help by
  `tools/panel-docs.php`). The admin panel's health card shows the detected hosting and links to the guide.

## 3.0 (first public release)
- **Public repository** with the complete package under `app/`, the editor source under `editor/` and the build
  tools under `tools/`. `app/SYNAPSEMAIL-FILES.txt` lists every SynapseMail file and every change to Roundcube.
- **Update check support** in the code: a signed `release.json` (the public update server is not live yet;
  new versions are published on GitHub Releases).
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
