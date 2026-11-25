#!/usr/bin/env bash
if [ -z "${BASH_VERSION:-}" ]; then echo "Please run this with bash: bash <(curl -fsSL https://repo.synapsecyber.io/synapsemail/whm-plugin-only/install.sh)" >&2; exit 1; fi
#
# SynapseMail WHM plugin installer.
#   bash <(curl -fsSL https://repo.synapsecyber.io/synapsemail/whm-plugin-only/install.sh)
# Options: --url https://your-domain/mail   -y (no questions)   --plain (no full-screen view)
#
# Downloads the plugin package, checks its SHA-256 against SHA256SUMS and the Ed25519 signature of SHA256SUMS
# against the SynapseCyber release key pinned below, then runs the installer from the package.
# Copyright (c) 2026 SynapseCyber.io. All rights reserved. Licensed under CC BY-NC-ND 4.0.

sm_install() {
  set -Eeuo pipefail
  umask 022

  local base=${SM_REPO:-https://repo.synapsecyber.io/synapsemail/whm-plugin-only}
  [[ $base =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._~/-]*)?$ ]] || { echo "SM_REPO must be an https address." >&2; exit 1; }
  # SynapseCyber release key (the same key that signs SynapseMail updates), raw Ed25519, base64
  local pubkey='__PUBKEY__'
  local work pkg sum

  say() { printf '  %s\n' "$*"; }
  die() { printf '\n  x %s\n\n' "$*" >&2; exit 1; }

  [[ ${EUID:-$(id -u)} -eq 0 ]] || die "Please run this as root."
  [[ -x /usr/local/cpanel/bin/whmapi1 ]] || die "cPanel & WHM was not found on this server."
  local c
  for c in curl tar openssl sha256sum mktemp awk; do command -v "$c" >/dev/null 2>&1 || die "Missing command: $c"; done

  work=$(mktemp -d /tmp/smwhmplugin.XXXXXXXX)
  trap 'rm -rf -- "$work"' EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM

  fetch() { curl --proto '=https' --tlsv1.2 -fsSL --retry 3 --retry-delay 2 --connect-timeout 15 --max-time 300 -o "$2" "$1" || die "Download failed: $1"; }

  printf '\n  SynapseMail WHM plugin: downloading...\n'
  fetch "$base/SHA256SUMS" "$work/SHA256SUMS"
  fetch "$base/SHA256SUMS.sig" "$work/SHA256SUMS.sig"

  # 1. the manifest is signed by SynapseCyber
  printf -- '-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEA%s\n-----END PUBLIC KEY-----\n' "$pubkey" >"$work/release.pub"
  openssl pkeyutl -verify -pubin -rawin -inkey "$work/release.pub" -sigfile "$work/SHA256SUMS.sig" -in "$work/SHA256SUMS" >/dev/null 2>&1 \
    || die "The package signature is not valid. Nothing was installed."

  # 2. the package matches the signed manifest
  pkg=$(awk '$2 ~ /^\*?smwhmplugin-[0-9.]+\.tar\.gz$/ { sub(/^\*/, "", $2); print $2; exit }' "$work/SHA256SUMS")
  [[ $pkg =~ ^smwhmplugin-[0-9.]+\.tar\.gz$ ]] || die "No package listed in SHA256SUMS."
  fetch "$base/$pkg" "$work/$pkg"
  sum=$(awk -v f="$pkg" '{ n = $2; sub(/^\*/, "", n) } n == f { print $1; exit }' "$work/SHA256SUMS")
  [[ $sum =~ ^[0-9a-f]{64}$ ]] || die "No checksum for $pkg."
  [[ $(sha256sum "$work/$pkg" | awk '{print $1}') == "$sum" ]] || die "Checksum mismatch for $pkg. Nothing was installed."

  # 3. unpack (only plain files and folders under smwhmplugin/)
  tar -tzf "$work/$pkg" >"$work/names" || die "Could not read $pkg."
  tar -tvzf "$work/$pkg" >"$work/types" || die "Could not read $pkg."
  if grep -vE '^smwhmplugin(/[A-Za-z0-9._-]+)*/?$' "$work/names" >/dev/null; then die "Unexpected file names in $pkg."; fi
  if grep -vE '^[-d]' "$work/types" >/dev/null; then die "Unexpected file types (links or devices) in $pkg."; fi
  tar -xzf "$work/$pkg" -C "$work" --no-same-owner --no-same-permissions
  [[ -f $work/smwhmplugin/smwhmplugin && -f $work/smwhmplugin/lib/ui.sh ]] || die "The package is incomplete."

  trap - EXIT
  exec bash "$work/smwhmplugin/smwhmplugin" -install --from "$work/smwhmplugin" --verified --cleanup "$@"
}

sm_install "$@"
