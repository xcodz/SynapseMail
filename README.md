# SynapseMail

A security-focused webmail built on [Roundcube Webmail](https://roundcube.net) 1.7, with its own interface,
a rich-text editor with LTR/RTL support, and sign-in protection that Roundcube alone does not have.
It runs on ordinary PHP hosting (cPanel, Plesk, DirectAdmin and others) or on your own Linux server, and
connects to the mail server you already have.

Copyright (c) 2026 SynapseCyber.io · Developed by Milad Ahmadipour · Powered by Roundcube

## Install

**One line** (Linux server or SSH hosting account):

```bash
bash <(curl -fsSL https://repo.synapsecyber.io/synapsemail)
```

The installer checks the release signature before installing anything. Prefer to read it first:
`curl -fsSLO https://repo.synapsecyber.io/synapsemail/install.sh`, read it, then `bash install.sh`.

**Manual** (any hosting): download `SynapseMail_v<version>.zip` from
[Releases](../../releases) or https://repo.synapsecyber.io/synapsemail/releases/, extract it into a folder such
as `public_html/mail`, open `https://your-domain/mail/` and follow the web installer. Details:
[app/INSTALL-SYNAPSEMAIL.txt](app/INSTALL-SYNAPSEMAIL.txt).

**Requirements:** PHP 8.1+ with pdo_sqlite, intl, mbstring, openssl, dom and xml; sodium for the update check;
an IMAP/SMTP mail server. No database server is needed (SQLite).

## Features

- **Interface:** the SynapseCyber design for mail, contacts and settings, with four colour themes, desktop,
  tablet and phone layouts, floating reading windows and a command palette (⌘K).
- **Editor:** Lexical-based, with fonts, LTR/RTL per paragraph and formatting controls. Persian and Arabic
  default to Tahoma.
- **Sign-in protection for the webmail:**
  - optional numeric passcode after the password (every sign-in or every 3, 7 or 30 days per device)
  - TOTP two-factor authentication with one-time recovery codes, with an admin policy (off, optional, required)
  - "remember this device", rate limits per account and per IP
- **Password change** through cPanel's webmail API, with the current password required and every attempt logged.
- **Privacy:** no third-party requests (icons, fonts and scripts are bundled), Content-Security-Policy and
  security headers. HTML mail is shown with automatic contrast repair or in its original colours.
- **Web installer:** proof of hosting-account ownership (`setup.key`), environment and mail-server checks, an
  admin account with mandatory 2FA. It locks itself after use.
- **Admin panel** at a private address (`/admin/<key>`), with a separate admin account and 2FA: health and
  signed update checks, users (reset passcode or 2FA, end sessions), security policy, deliverability score
  (MX, PTR, SPF, DKIM, DMARC, TLS, blacklists), encrypted backup and restore, logs.

## Security model

The passcode and 2FA protect **this webmail**. The mailbox password is still checked by your mail server, so
mail apps that use IMAP, POP or SMTP, and any other webmail on the same server, sign in with the password alone.
To protect the mailbox itself, use strong unique passwords and the security options of your mail server or
hosting panel. The server edition (planned, for WHM) will let administrators make SynapseMail the only webmail
on a server. See [SECURITY.md](SECURITY.md) for the full design and how to report a vulnerability.

## Updating

The admin panel checks a signed release manifest (`release.json` with an Ed25519 signature) and shows a new
version only after the signature checks out. To update, extract the new zip over the install folder, or run
the one-line installer again and choose **Update**. Configuration and data are kept.

## Repository layout

| Path | Contents |
|---|---|
| `app/` | the complete package: Roundcube 1.7.5 with SynapseMail. `app/SYNAPSEMAIL-FILES.txt` lists every SynapseMail file and every change to Roundcube. |
| `editor/` | source of the Lexical editor bundle (`npm install && npm run build` writes `app/plugins/synapse_editor/lexical.bundle.js`) |
| `installer/` | the one-line installer |
| `repo-site/` | the download site served at repo.synapsecyber.io/synapsemail |
| `tools/` | release build (`build_release.sh`) and the file-list generator |
| `docs/` | installation help and troubleshooting |

**Running locally:** serve `app/public_html` with PHP 8.1+ (`php -S localhost:8091 -t app/public_html`) and open
it: the web installer starts. Local overrides go in `app/config/config.local.inc.php` (never committed).
**Self-test:** `php app/plugins/synapse_core/tests/selftest.php`.

## Editions

| | Portable (this repository) | Server edition (planned) |
|---|---|---|
| Installs | in a hosting account or on a Linux server | server-wide from WHM |
| Admin | private `/admin/<key>` address in the app | WHM plugin |

## License

SynapseMail is **source-available, not open source**.

- **SynapseMail components** (`skins/synapse`, `plugins/synapse_*`, the installer and admin entry points,
  `config/config.inc.php`, `editor/`, `installer/`, `repo-site/`, `tools/`): Creative Commons BY-NC-ND 4.0. You
  may use and share them free of charge with attribution. You may not sell them or distribute modified versions.
  See [LICENSE-SYNAPSEMAIL.txt](LICENSE-SYNAPSEMAIL.txt).
- **Roundcube files:** keep their GPL-3.0 license (with the skin and plugin exception). See
  [app/README-ROUNDCUBE.md](app/README-ROUNDCUBE.md) and [app/docs/LICENSE.md](app/docs/LICENSE.md).
- **Third-party:** Lexical (MIT), Lucide icons (ISC), anime.js (MIT), fonts (SIL OFL), as listed in
  LICENSE-SYNAPSEMAIL.txt.

**Contributing:** bug reports and feature requests are welcome as issues. Because of the NoDerivatives term,
pull requests with code changes cannot be accepted.
