#!/bin/sh
# Writes the installer's ownership code into /data/setup.key:
#   docker exec synapsemail setup-key SM-XXXX-XXXX-XXXX
# Copyright (c) 2026 SynapseCyber.io. Licensed under CC BY-NC-ND 4.0.
set -eu
code=$(printf '%s' "${1:-}" | tr '[:lower:]' '[:upper:]')
if ! printf '%s' "$code" | grep -Eqx 'SM-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}' || [ "$code" = SM-XXXX-XXXX-XXXX ]; then
  echo "usage: setup-key SM-XXXX-XXXX-XXXX (the code shown on the installer page)" >&2
  exit 2
fi
if [ -e /data/config/installed.lock ] || [ -e /data/config/synapse.inc.php ]; then
  echo "SynapseMail is already installed: no setup key needed." >&2
  exit 1
fi
printf '%s\n' "$code" > /data/setup.key
chmod 644 /data/setup.key
echo "setup.key saved. Click CONTINUE on the installer page."
