# 📤 Send As

Copyright (c) 2026 SynapseCyber.io · Developed by Milad Ahmadipour · CC BY-NC-ND 4.0

Send As lets you write email from **all your addresses** in SynapseMail. Each
address has its own **name**, **signature** and **mail server (SMTP)**. One address is your **default**.

## ➕ Add an address

Open **Settings › Send As** and press **+** (Add another address).

1. **Your name and address.** Type your first name, last name and the email address.
2. **Sending server.** SynapseMail looks for the settings and shows where it found them:
   - **Known provider**, for example Gmail, Yahoo, Outlook.com, iCloud, Zoho, Fastmail, GMX, Yandex
   - the **settings file of the domain** (Mail Autoconfig) or the **public provider database** (Thunderbird ISPDB)
   - the **DNS** of the domain (SRV records) or its **mail server** (MX), marked "please check"
   - **our best guess** (`mail.<domain>`, port 465), marked "please check"

   You can change the server, port (465, 587, 25 or 2525), security (SSL/TLS or STARTTLS), user name and password.
   For an address of your own mail server you can also choose **Send through this mail server** (no password).
3. **Test and send code.** SynapseMail connects, signs in and sends a **6-digit code to the new address, through
   its own server**. Open that mailbox and type the code. The code is valid for 15 minutes; you can ask for a new
   one after 60 seconds.
4. **Finish.** Add a signature (optional) and choose if this is your default address.

Nothing is saved until the code is correct.

## ✏️ Change or remove an address

Click the address in the list:
- Change the **name, organization, reply-to, bcc and signature** (rich text) like before.
- **Change server or password** opens the server step again. A new test is needed, but no new code.
- The email address of a verified entry cannot be changed. Add a new address instead.
- Delete it with the bin icon.

**When I reply** (under the list): reply from the address the message was sent to, or always from your default.

## 📥 Read the inbox of an address

You can also read the mail of a Send As address in SynapseMail. Nothing is imported: SynapseMail opens that
address's mailbox **on its own server**, and your main mailbox stays as it is.

1. Settings › Send As › click the address › **Inbox › Turn on**, or tick "Also read its inbox" when you add it.
2. The incoming server (IMAP) is filled in for you (for example `imap.gmail.com`). Change it if needed.
3. Keep "Use the password this address sends with" (App Passwords work for IMAP too), or enter another password.
4. **Test and turn on** signs in once and shows how many messages are unread.

On the mail screen, the **mailbox menu above the folders** lists your own mailbox and every inbox with its unread
count. While another inbox is open, a bar says which one, with **Back to my inbox**. Its own folders are used (Sent,
Drafts and Trash are found automatically), and new mail and replies come from that address. Gmail and Microsoft
keep their own copy of sent mail, so SynapseMail does not add a second one there.

- Only **IMAP over TLS on port 993** is used, the certificate is checked and private networks are blocked.
- Settings, contacts and filters always belong to your own account.
- If the password of that inbox changes, SynapseMail returns to your own inbox and tells you. Enter the new password
  under Inbox in the address settings. **Turn off inbox** removes it (the mail stays on its server).
- Hosting firewalls must allow outgoing port **993** (CSF: add 993 to `TCP_OUT`).

## 🔑 Provider notes (2026)

| Provider | Server | Port / security | Password |
|---|---|---|---|
| Gmail / Google Workspace | `smtp.gmail.com` | 465 SSL/TLS or 587 STARTTLS | **App Password** (needs 2-step verification) |
| Yahoo Mail | `smtp.mail.yahoo.com` | 465 SSL/TLS or 587 STARTTLS | **App Password** |
| iCloud | `smtp.mail.me.com` | 587 STARTTLS | **App-specific password** |
| Microsoft 365 | `smtp.office365.com` | **587 STARTTLS only** | mailbox password, and **SMTP AUTH must be on** for the mailbox |
| Outlook.com / Hotmail | `smtp-mail.outlook.com` | 587 STARTTLS | **OAuth only: not supported yet** |
| Zoho Mail | `smtp.zoho.com` (EU: `smtp.zoho.eu`) | 465 SSL/TLS | App Password if 2FA is on |
| Proton Mail | `smtp.protonmail.ch` | 587 STARTTLS | SMTP token, custom domains on paid plans only |
| cPanel hosting | `mail.<domain>` (or the server name) | 465 SSL/TLS | mailbox password |

**Microsoft 365:** Microsoft turns SMTP AUTH (password sign-in) **off by default at the end of December 2026**.
Your Microsoft 365 admin can turn it on again for a mailbox. OAuth sign-in is planned for a later version.

## 🛡️ How it is protected

- The SMTP password is stored **encrypted** (AES-256-GCM with a key derived from the install key). It is never sent
  back to the browser and never written to a log.
- The password is **only sent over TLS**. If a server does not offer STARTTLS (or someone removes it), SynapseMail
  stops before it signs in.
- **Certificates are checked.** If the certificate has another name, the wizard shows that name so you can use it.
- **No private networks:** server names that point to private, local, link-local, CGNAT or cloud metadata
  addresses are refused (also inside IPv6), only mail ports are allowed, and SynapseMail connects to the checked IP
  address (no DNS rebinding). Administrators of company networks can allow local servers in the admin panel.
- **Limits per user:** 20 settings lookups, 10 server tests and 5 code mails in 15 minutes; 5 wrong codes start the
  wizard again.
- An address of another domain can only use **its own server**. "Send through this mail server" is only for the
  user's domain and the domains the admin lists, so SynapseMail never sends mail that fails DMARC.
- Addresses added before 3.3 keep working through this server and are marked "not verified". Open one and press
  **Verify this address** to move it to the wizard. New addresses can only be added with the wizard.
- The security log (`logs/synapse_security.log`) records added, changed and removed addresses, failed tests and
  wrong codes (never passwords or codes).

## 🧑‍💼 For administrators

**Admin › Security › Send As:**
- turn adding addresses on or off (existing addresses keep working),
- the number of addresses per user (default 20, at most 99),
- **domains this server sends for**: addresses of these domains may use "Send through this mail server",
- allow servers on the local network (off by default; only for company networks).

**Admin › Overview › Outgoing SMTP (Send As)** checks that PHP can reach other mail servers on ports 465 and 587.
Many servers block this. On cPanel/WHM check three things: the CSF firewall's **`TCP_OUT`** must include 465 and
587 (the CSF default has 587 but not 465), CSF **`SMTP_BLOCK`** must be 0 or list the cPanel user in
`SMTP_ALLOWUSER`, and WHM **Tweak Settings › "Restrict outgoing SMTP to root, exim, and mailman"** (Security Center › SMTP Restrictions) must be off. Ask your host if you cannot change it.
See [Troubleshooting](TROUBLESHOOTING.md#send-as) for test commands.

**Admin › Security › Send As › "Users can open the inboxes of their addresses"** turns reading other inboxes on or
off for everyone (on by default).

**Users** shows how many Send As addresses and inboxes each mailbox has. Both are part of the backup.
