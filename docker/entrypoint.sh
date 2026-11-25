#!/bin/bash
# SynapseMail container start: prepares /data, the reverse proxy settings and the database schema, then runs Apache.
# Copyright (c) 2026 SynapseCyber.io. Licensed under CC BY-NC-ND 4.0.
set -euo pipefail

APP=/var/www/synapsemail
DIST=/usr/local/share/synapsemail
D=/data
V=$(cat "$DIST/VERSION")

mkdir -p "$D/config" "$D/db" "$D/logs" "$D/temp"
# shipped config files come from the image on every start (an image update updates them);
# per-install files (synapse.inc.php, config.local.inc.php, installed.lock, ADMIN-URL.txt) are never touched
cp -a "$DIST/config/." "$D/config/"
chown www-data:www-data "$D"
chmod 750 "$D"
chown www-data:www-data "$D/config" "$D/db" "$D/logs" "$D/temp"
chmod 750 "$D/config" "$D/db" "$D/logs" "$D/temp"
chown -R www-data:www-data "$D/db" "$D/logs" "$D/temp"
find "$D/config" -mindepth 1 -maxdepth 1 ! -name '.htaccess' ! -name 'config.inc.php' ! -name 'defaults.inc.php' \
  ! -name 'mimetypes.php' ! -name 'config.inc.php.sample' -exec chown www-data:www-data {} +

installed=0
[[ -e $D/config/installed.lock || -e $D/config/synapse.inc.php ]] && installed=1

# Reverse proxy: trust X-Forwarded-For / X-Forwarded-Proto only from these addresses, then drop the headers,
# so PHP sees the real client address and HTTPS, and clients cannot set them.
proxies=${SYNAPSEMAIL_TRUSTED_PROXIES:-}
conf=/etc/apache2/conf-enabled/synapsemail-proxy.conf
{
  echo "# written by synapsemail-entrypoint from SYNAPSEMAIL_TRUSTED_PROXIES"
  if [[ -n $proxies && $proxies != none ]]; then
    expr=""
    for p in $proxies; do
      [[ $p =~ ^[0-9A-Fa-f:.]+(/[0-9]{1,3})?$ ]] || { echo "synapsemail: SYNAPSEMAIL_TRUSTED_PROXIES: not an address or range: $p" >&2; exit 1; }
      expr+="${expr:+ || }%{CONN_REMOTE_ADDR} -ipmatch '$p'"
    done
    echo "RemoteIPHeader X-Forwarded-For"
    echo "RemoteIPInternalProxy $proxies"
    echo "SetEnvIfExpr \"%{req_novary:X-Forwarded-Proto} == 'https' && ($expr)\" HTTPS=on"
  fi
  for h in X-Forwarded-For X-Forwarded-Proto X-Forwarded-Port X-Forwarded-Host X-Real-IP; do
    echo "RequestHeader unset $h"
  done
} >"$conf"

if [[ $installed == 1 ]]; then
  # the installer code is not needed any more
  rm -f "$D/setup.key"
  # database schema of the bundled Roundcube (no-op when nothing changed)
  if [[ -f $D/db/synapsemail.db ]]; then
    runuser -u www-data -- php "$APP/bin/updatedb.sh" --package=roundcube --dir="$APP/SQL" \
      || echo "synapsemail: database schema update failed (see above)" >&2
  fi
  echo "synapsemail: SynapseMail $V is installed. Data folder: /data"
else
  echo "synapsemail: SynapseMail $V is not installed yet."
  echo "synapsemail: open the site in a browser; the installer shows a code SM-XXXX-XXXX-XXXX. Save it with:"
  echo "synapsemail:   docker exec <container> setup-key SM-XXXX-XXXX-XXXX"
  # the installer cannot delete setup.key here (it is a link in the read-only app folder): remove it once installed
  ( until [[ -e $D/config/installed.lock ]]; do sleep 3; done; rm -f "$D/setup.key" ) &
fi

exec "$@"
