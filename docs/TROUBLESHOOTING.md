# SynapseMail troubleshooting

Copyright (c) 2026 SynapseCyber.io · Developed by Milad Ahmadipour · CC BY-NC-ND 4.0

Steps for your hosting panel (cPanel, Plesk, DirectAdmin, HestiaCP, CloudPanel, aaPanel, ISPConfig or a plain
Linux server) are in [HOSTING-PANELS.md](HOSTING-PANELS.md). The web installer shows the same steps next to each
failed check.

## The installer

**"setup.key was not found" / "still has the example code"**
`setup.key` belongs in the SynapseMail folder, next to `config/`, `db/` and `public_html/`. Hidden files can be
off in the file manager (cPanel: File Manager → Settings → Show Hidden Files). Put only the code shown in the
installer in the file, then save.

**A red check on the Environment step**
Open "How to fix on <panel>" under the check. If the panel shown at the top is wrong, pick yours and press CHANGE.
Reload the installer after the fix.

**"Installer locked"**
SynapseMail is already installed. To run the installer again, remove `config/installed.lock` and
`config/synapse.inc.php`. This resets the mail server settings and the admin account; mail stays on the mail server.

## nginx

nginx does not read `.htaccess`, so `config/`, `db/`, `logs/` and `temp/` could be downloaded. The installer tests
this (it writes `db/install-probe.txt` and tries to fetch it) and will not continue until the test passes.

Best fix: point the document root of a domain or subdomain (for example `mail.example.com`) to the `public_html`
folder inside SynapseMail. Nothing outside it can then be reached.

Otherwise, add these rules to the site's nginx configuration (inside the `server { }` block; the installer shows
them with your real folder name in place of `/mail`) and reload nginx:

```nginx
# SynapseMail: nginx ignores .htaccess, so block the private folders here
location ^~ /mail/config/ { return 404; }
location ^~ /mail/db/ { return 404; }
location ^~ /mail/logs/ { return 404; }
location ^~ /mail/temp/ { return 404; }
location ^~ /mail/bin/ { return 404; }
location ^~ /mail/SQL/ { return 404; }
location ^~ /mail/program/ { return 404; }
location ^~ /mail/vendor/ { return 404; }
location ^~ /mail/plugins/ { return 404; }
location ^~ /mail/skins/ { return 404; }
location ~ ^/mail/[^/]+\.(key|txt|md|json|lock|sql)$ { return 404; }
# then open SynapseMail at /mail/public_html/
```

`^~` rules win over the PHP `location ~ \.php$` block, so their position in the file does not matter. Where to
paste them: Plesk "Additional nginx directives", CloudPanel "Vhost", aaPanel "Config", ISPConfig "nginx Directives".

## Apache or LiteSpeed but the protection test fails

`.htaccess` is switched off for the folder. The server needs `AllowOverride All` for the SynapseMail folder and
`mod_rewrite` (Debian/Ubuntu: `a2enmod rewrite`, then reload Apache). Also check that the `.htaccess` files in the
SynapseMail folder and in `db/`, `config/`, `logs/` and `temp/` were uploaded: some FTP clients skip hidden files.

## The mail server is not found

The installer tests `localhost`, the server's host name, `mail.<domain>` and the domain itself on ports 993, 143,
465 and 587. If none work:

- Enter the host shown by your panel (cPanel: Email Accounts → Connect Devices) in "Other host" and press TEST.
- A host with "unverified certificate" works but the certificate does not match the name; prefer the host name
  the certificate was issued for (often the server's host name).
- On your own server, make sure outgoing connections to those ports are allowed (`nc -vz mail.example.com 993`).

## After install

**Admin panel address lost:** it is in `config/ADMIN-URL.txt`.
**Health checks:** the admin panel's overview lists failed checks; the fixes are the same as above.
**Logs:** `logs/` (errors, sign-ins, admin actions). Never publish them: they contain addresses and IPs.

Still stuck? Open an issue at https://github.com/xcodz/SynapseMail/issues with the failing check and your panel
(no passwords, keys or log files with addresses).
