# SynapseMail public demo

Copyright (c) 2026 SynapseCyber.io · Developed by Milad Ahmadipour · CC BY-NC-ND 4.0

The demo shows the real SynapseMail interface with sample data. It has **no mail server**: nothing is sent and no
connection to IMAP or SMTP is ever made.

| | Sign in | Codes |
|---|---|---|
| 📬 Webmail: [sm-demo.synapsecyber.io](https://sm-demo.synapsecyber.io) | `demo` / `demo` | 2FA `123456`, passcode `1234` |
| 🧑‍💼 Admin panel: [sm-demo.synapsecyber.io/admin/synapsemail-demo](https://sm-demo.synapsecyber.io/admin/synapsemail-demo) | `admin` / `demo` | 2FA `123456` |

## How it works

- `plugins/synapse_demo` replaces IMAP with a sample mailbox (`plugins/synapse_demo/mail/*.eml`).
- Every visitor gets a **private temporary user**: settings, contacts, passcode and 2FA changes, and mail actions
  (read, flag, move, delete, drafts) are never shared with other visitors. Temporary users are removed after 12 hours.
- **Blocked:** sending mail, password change, mail filters, spell check (external service), update checks.
- Wrong codes cannot lock the shared demo account; limits per IP address stay on.
- The **admin panel is read-only** and shows sample data from its own showcase database (`db/admin-demo.db`) and
  sample logs. It never shows real visitors.
- A notice at the top of every page says it is a demo.

The demo plugin and its switches are **never** part of the release zip (the release build checks this).

## Build

```bash
tools/build_demo.sh
```

Output: `dist/SynapseMail_demo_v<version>.zip` with a ready configuration (new secret key every build), database
and showcase data.

## Upload (for example `sm-demo.synapsecyber.io`)

1. Create the subdomain in your hosting panel.
2. Extract the zip into the subdomain's folder.
3. Best: set the subdomain's **document root to the `public_html` folder** inside it. Otherwise the included
   `.htaccess` files route requests into `public_html` (Apache or LiteSpeed).
4. Turn on HTTPS (AutoSSL or Let's Encrypt).
5. Open the site and sign in with `demo` / `demo`.

To reset everything, upload a fresh build. To refresh the sample dates in the admin panel, rebuild.
