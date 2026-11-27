# SynapseMail on Docker

The image runs SynapseMail with Apache and PHP 8.3 for **amd64 and arm64**. It connects to your existing IMAP and
SMTP server (it is webmail, not a mail server). All data stays in one volume, `/data`.

```
ghcr.io/xcodz/synapsemail:latest     the newest release
ghcr.io/xcodz/synapsemail:3.5.6      one version (also :3.5 for the newest 3.5.x)
```

The image is built by [GitHub Actions](../.github/workflows/docker.yml) from the release zip on
[GitHub Releases](../../../releases). Before anything goes into the image, the build checks the zip's Ed25519
signature with the SynapseCyber release key (the same key the admin panel uses for updates) and every file
against the signed list ([verify-release.php](verify-release.php)).

## Start

With Docker Compose ([compose.yaml](compose.yaml)):

```bash
curl -fsSLO https://raw.githubusercontent.com/xcodz/SynapseMail/main/docker/compose.yaml
docker compose up -d
```

Or with one command:

```bash
docker run -d --name synapsemail --restart unless-stopped -p 127.0.0.1:8080:80 -v synapsemail-data:/data ghcr.io/xcodz/synapsemail:latest
```

Both make SynapseMail reachable only from the server itself, on port 8080. Put an HTTPS reverse proxy in front
(see [HTTPS](#https)), or use `-p 8080:80` on a private network.

## Install

1. Open SynapseMail in your browser (your HTTPS address, or `http://localhost:8080/` on the server).
2. The installer shows a code like `SM-1A2B-3C4D-5E6F`. Save it in the container, which proves that you run it:

   ```bash
   docker exec synapsemail setup-key SM-1A2B-3C4D-5E6F
   ```

3. Click **Continue** and follow the installer: environment check, your mail server (IMAP and SMTP), the admin
   account with 2FA. The installer locks itself when it is done, and the code is removed.

The admin panel address is shown at the end. It is also saved in the volume:

```bash
docker exec synapsemail cat /data/config/ADMIN-URL.txt
```

## HTTPS

Serve SynapseMail over HTTPS. The container speaks HTTP on port 80, so a reverse proxy in front adds HTTPS, for
example nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    client_max_body_size 40m;
}
```

or Caddy: `mail.example.com { reverse_proxy 127.0.0.1:8080 }`.

The container takes the visitor's address (`X-Forwarded-For`) and HTTPS (`X-Forwarded-Proto`) only from
the addresses in `SYNAPSEMAIL_TRUSTED_PROXIES`, and removes these headers from every other request. Sign-in limits
and logs therefore see the real visitor, and nobody else can set them.

| Variable | Default | |
|---|---|---|
| `SYNAPSEMAIL_TRUSTED_PROXIES` | `gateway` | addresses or ranges of your proxy, separated by spaces; `gateway` is the container's Docker gateway; `none` when nothing is in front |

When the proxy runs on the same server and reaches the published port, it connects from the Docker gateway, which
the default covers. When the proxy runs in another container on the same Docker network, set its address, for
example `SYNAPSEMAIL_TRUSTED_PROXIES=172.20.0.5`. Other addresses are never trusted. The proxy must replace the
`X-Forwarded-For` header it receives from the visitor (nginx `$proxy_add_x_forwarded_for` and Caddy do).

## Hardening

[compose.yaml](compose.yaml) starts the container with only the rights Apache and the start script need
(`cap_drop: [ALL]` plus seven capabilities) and with `no-new-privileges`. With `docker run`, add:

```bash
--cap-drop ALL --cap-add CHOWN --cap-add DAC_OVERRIDE --cap-add FOWNER --cap-add SETUID --cap-add SETGID --cap-add NET_BIND_SERVICE --cap-add KILL --security-opt no-new-privileges:true
```

The app files in the image are read-only for the web server, and `/data` belongs to root: the web server can only
write inside `config/`, `db/`, `logs/` and `temp/`. The start script refuses to run when one of them is a link.

## Mail server

SynapseMail connects to your IMAP and SMTP server by host name, like any mail app. When the mail server runs on
the Docker host itself, use the host's public name (the one in its TLS certificate), not `localhost`: inside the
container, `localhost` is the container. Password change (Settings › Password) uses cPanel's API and works when
the mailboxes are on cPanel.

## Updates

```bash
docker compose pull && docker compose up -d
```

or, without Compose, `docker pull ghcr.io/xcodz/synapsemail:latest`, then remove the container and start it again
with the same `docker run` command. The volume keeps your settings, users and admin account. On start the
container updates the database schema when a new version needs it. The image files are read-only, so
**Admin › Updates** shows that it cannot install there: update by pulling the new image.

## Data and backups

| In `/data` | What |
|---|---|
| `config/` | `synapse.inc.php` (settings and keys), `installed.lock`, `ADMIN-URL.txt`; the other files come from the image on every start |
| `db/` | `synapsemail.db` (users, settings, admin account, 2FA) |
| `logs/` | SynapseMail logs (also in **Admin › Logs**) |
| `temp/` | sessions and temporary files |

Back up with **Admin › Backup** (an encrypted file of the database and settings), or copy the volume while the
container is stopped:

```bash
docker run --rm -v synapsemail-data:/data -v "$PWD":/backup debian:stable-slim tar czf /backup/synapsemail-data.tar.gz -C /data .
```

Mail stays on your mail server, so the volume is small.

## Build it yourself

```bash
docker build --build-arg SYNAPSEMAIL_VERSION=3.5.6 -t synapsemail docker/
```

The build downloads `SynapseMail_v3.5.6.zip` from GitHub Releases and stops if the signature or any file does not
match.

## License

SynapseMail is source-available, not open source. Its own parts, including these Docker files, are under
[Creative Commons BY-NC-ND 4.0](../LICENSE-SYNAPSEMAIL.txt): use and share them for free, with credit, but do not
sell them or share changed versions. The image also contains Roundcube (GPL-3.0-or-later, with exceptions for
skins and plugins), the third-party parts listed in `LICENSE-SYNAPSEMAIL.txt`, and the PHP and Apache software of
the base image, each under its own license.
