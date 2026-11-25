#!/usr/bin/env bash
#
# Builds the SynapseMail WHM plugin repo folder:
#   dist/whm-plugin-only/   install.sh, smwhmplugin-<v>.tar.gz, SHA256SUMS, SHA256SUMS.sig, index.html, .htaccess
#   dist/whm-plugin-only.zip
# Upload the folder's contents to https://repo.synapsecyber.io/synapsemail/whm-plugin-only/
# SHA256SUMS is signed with the SynapseMail release key (~/.synapsemail/release-signing.key, never in git).
# Copyright (c) 2026 SynapseCyber.io. Licensed under CC BY-NC-ND 4.0.
set -euo pipefail
cd "$(dirname "$0")/.."

die() { echo "build_whm_plugin: $*" >&2; exit 1; }
SRC=whm-plugin
OUT=dist/whm-plugin-only
KEY=${SIGNING_KEY:-$HOME/.synapsemail/release-signing.key}
V=$(tr -d '[:space:]' <"$SRC/payload/VERSION")
[[ $V =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "bad VERSION"
grep -q "^SM_VERSION=$V\$" "$SRC/payload/smwhmplugin" || die "SM_VERSION in smwhmplugin is not $V"
PUB=$(sed -n "s/.*PUBLIC_KEY = '\([A-Za-z0-9+\/=]*\)'.*/\1/p" app/plugins/synapse_core/lib/synapse_update.php)
[[ ${#PUB} -eq 44 ]] || die "release public key not found"
[[ -f $KEY ]] || die "no signing key at $KEY"

rm -rf "$OUT" "$OUT.zip" dist/.whm-stage
mkdir -p "$OUT" dist/.whm-stage/smwhmplugin
cp -R "$SRC/payload/." dist/.whm-stage/smwhmplugin/
find dist/.whm-stage -name '.DS_Store' -delete
chmod 755 dist/.whm-stage/smwhmplugin/smwhmplugin dist/.whm-stage/smwhmplugin/hooks/postupcp dist/.whm-stage/smwhmplugin/whm/index.cgi
# the release key, for -latest (update check)
sed -i.bak "s|^SM_PUBKEY='__PUBKEY__'|SM_PUBKEY='$PUB'|" dist/.whm-stage/smwhmplugin/smwhmplugin && rm -f dist/.whm-stage/smwhmplugin/smwhmplugin.bak
grep -q "^SM_PUBKEY='$PUB'" dist/.whm-stage/smwhmplugin/smwhmplugin || die "public key not written into smwhmplugin"
PKG=smwhmplugin-$V.tar.gz
# plain ustar archive: root-owned names, no macOS extended attributes (GNU tar on the server warns about those)
if tar --version 2>/dev/null | grep -q GNU; then
  tar --format=ustar --owner=0 --group=0 --numeric-owner -C dist/.whm-stage -czf "$OUT/$PKG" smwhmplugin
else
  COPYFILE_DISABLE=1 tar --format ustar --uid 0 --gid 0 --uname root --gname root --no-xattrs --no-acls --no-fflags --no-mac-metadata \
    -C dist/.whm-stage -czf "$OUT/$PKG" smwhmplugin
fi
rm -rf dist/.whm-stage

sed "s|__PUBKEY__|$PUB|" "$SRC/install.sh" >"$OUT/install.sh"
grep -q "local pubkey='$PUB'" "$OUT/install.sh" || die "public key not written into install.sh"

( cd "$OUT" && shasum -a 256 "$PKG" install.sh >SHA256SUMS )
# shellcheck disable=SC2016  # PHP code, not shell
php -r '
  $sig = sodium_crypto_sign_detached(file_get_contents($argv[1]), base64_decode(trim(file_get_contents($argv[2]))));
  $pub = base64_decode($argv[3]);
  if (!sodium_crypto_sign_verify_detached($sig, file_get_contents($argv[1]), $pub)) { fwrite(STDERR, "signature does not match the public key\n"); exit(1); }
  file_put_contents($argv[4], $sig);
' "$OUT/SHA256SUMS" "$KEY" "$PUB" "$OUT/SHA256SUMS.sig"
[[ $(wc -c <"$OUT/SHA256SUMS.sig" | tr -d ' ') == 64 ]] || die "signature is not 64 bytes"

cp "$SRC/site/index.html" "$SRC/site/.htaccess" "$OUT/"
sed -i.bak "s/{{VERSION}}/$V/g" "$OUT/index.html" && rm -f "$OUT/index.html.bak"
( cd dist && zip -qr -X whm-plugin-only.zip whm-plugin-only )
echo "dist/whm-plugin-only/ (v$V):"
ls -la "$OUT"
echo "Upload the contents of dist/whm-plugin-only/ (or unzip dist/whm-plugin-only.zip) into https://repo.synapsecyber.io/synapsemail/whm-plugin-only/"
