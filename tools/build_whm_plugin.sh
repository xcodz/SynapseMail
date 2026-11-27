#!/usr/bin/env bash
#
# Builds the SynapseMail WHM plugin repo folder from one commit (default HEAD):
#   dist/whm-plugin-only/   install.sh, smwhmplugin-<v>.tar.gz, SHA256SUMS, SHA256SUMS.sig, index.html, .htaccess
#   dist/whm-plugin-only.zip
# Upload the folder's contents to https://repo.synapsecyber.io/synapsemail/whm-plugin-only/
# SHA256SUMS is signed with the SynapseMail release key (~/.synapsemail/release-signing.key, never in git).
#
# Same rules as tools/build_release.sh: a clean snapshot of the commit, never the working folder; the payload must
# hold exactly the files in whm-plugin/PAYLOAD-FILES.txt; reject classes and private patterns are checked before
# signing; the archive is deterministic (sorted, the commit's time, root-owned, fixed modes, no gzip time).
# Env: COMMIT, DEV, LEAK_PATTERNS, SIGNING_KEY, TEST_SIGNING_KEY, DIST (see tools/release-lib.sh).
# A DEV build goes to dist/whm-plugin-only-dev/ and is never signed.
# Copyright (c) 2026 SynapseCyber.io. Licensed under CC BY-NC-ND 4.0.
set -euo pipefail
cd "$(dirname "$0")/.."
. tools/release-lib.sh

rl_source
rl_patterns
SNAP=$RL_WORK/src
rl_snapshot "$SNAP" whm-plugin app/plugins/synapse_core/lib/synapse_update.php
SRC=$SNAP/whm-plugin
[[ -f $SRC/PAYLOAD-FILES.txt ]] || die "whm-plugin/PAYLOAD-FILES.txt is not in commit $RL_SHORT"
V=$(tr -d '[:space:]' <"$SRC/payload/VERSION")
[[ $V =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "bad VERSION"
grep -q "^SM_VERSION=$V\$" "$SRC/payload/smwhmplugin" || die "SM_VERSION in smwhmplugin is not $V"
PUB=$(sed -n "s/.*PUBLIC_KEY = '\([A-Za-z0-9+\/=]*\)'.*/\1/p" "$SNAP/app/plugins/synapse_core/lib/synapse_update.php")
[[ ${#PUB} -eq 44 ]] || die "release public key not found"
rl_signing "$PUB"
[[ -n $RL_SIGN || -n $DEV ]] || die "the WHM package is always signed (DEV=1 for an unsigned dev build)"
echo "version: $V"

# checks before anything is built or signed
"$PHP" "$RL_CHECK" list "$SRC/payload" "$SRC/PAYLOAD-FILES.txt" || die "payload check failed"
rl_scan_private "$SRC"

OUTNAME=whm-plugin-only${DEV:+-dev}
STAGE=$RL_WORK/stage
mkdir -p "$STAGE/smwhmplugin" "$RL_WORK/out/$OUTNAME"
OUT=$RL_WORK/out/$OUTNAME
cp -Rp "$SRC/payload/." "$STAGE/smwhmplugin/"
chmod 755 "$STAGE/smwhmplugin/smwhmplugin" "$STAGE/smwhmplugin/hooks/postupcp" "$STAGE/smwhmplugin/whm/index.cgi"
# the release key, for -latest (update check)
sed -i.bak "s|^SM_PUBKEY='__PUBKEY__'|SM_PUBKEY='$PUB'|" "$STAGE/smwhmplugin/smwhmplugin" && rm -f "$STAGE/smwhmplugin/smwhmplugin.bak"
grep -q "^SM_PUBKEY='$PUB'" "$STAGE/smwhmplugin/smwhmplugin" || die "public key not written into smwhmplugin"
"$PHP" "$RL_CHECK" normalize "$STAGE" "$RL_TIME"

PKG=smwhmplugin-$V.tar.gz
# plain ustar archive in sorted order: root-owned names, no macOS extended attributes, no gzip name or time
(cd "$STAGE" && find smwhmplugin | sort) >"$RL_WORK/entries"
if tar --version 2>/dev/null | grep -q GNU; then
  tar --format=ustar --owner=0 --group=0 --numeric-owner --mtime="@$RL_TIME" --no-recursion -C "$STAGE" -T "$RL_WORK/entries" -cf - | gzip -n -9 >"$OUT/$PKG"
else
  COPYFILE_DISABLE=1 tar --format ustar --uid 0 --gid 0 --uname root --gname root --no-xattrs --no-acls --no-fflags --no-mac-metadata \
    -n -C "$STAGE" -T "$RL_WORK/entries" -cf - | gzip -n -9 >"$OUT/$PKG"
fi

sed "s|__PUBKEY__|$PUB|" "$SRC/install.sh" >"$OUT/install.sh"
grep -q "local pubkey='$PUB'" "$OUT/install.sh" || die "public key not written into install.sh"
{ rl_sha256 "$OUT/$PKG" "$PKG"; rl_sha256 "$OUT/install.sh" install.sh; } >"$OUT/SHA256SUMS"
cp "$SRC/site/index.html" "$SRC/site/.htaccess" "$OUT/"
sed -i.bak "s/{{VERSION}}/$V/g" "$OUT/index.html" && rm -f "$OUT/index.html.bak"
rl_scan_private "$OUT"

if [[ -n $RL_SIGN ]]; then
  # a test key is checked against its own public key; a release build against the release key
  SIGPUB=$PUB
  [[ -z ${TEST_SIGNING_KEY:-} ]] || SIGPUB=$("$PHP" -r 'echo base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode(trim(file_get_contents($argv[1])))));' "$RL_KEY")
  "$PHP" "$RL_CHECK" sign-raw "$OUT/SHA256SUMS" "$RL_KEY" "$SIGPUB" "$OUT/SHA256SUMS.sig" || die "signing failed"
  [[ $(wc -c <"$OUT/SHA256SUMS.sig" | tr -d ' ') == 64 ]] || die "signature is not 64 bytes"
fi

"$PHP" "$RL_CHECK" normalize "$RL_WORK/out" "$RL_TIME"
mkdir -p "$DIST"
rm -rf "${DIST:?}/$OUTNAME" "$DIST/$OUTNAME.zip"
cp -Rp "$OUT" "$DIST/$OUTNAME"
"$PHP" "$RL_CHECK" zip "$RL_WORK/out" "$DIST/$OUTNAME.zip" "$RL_TIME" >/dev/null
echo "$DIST/$OUTNAME/ (v$V${DEV:+, DEV build: unsigned}):"
(cd "$DIST/$OUTNAME" && for f in *; do echo "  $f  $("$PHP" "$RL_CHECK" sha256 "$f")"; done)
echo "$DIST/$OUTNAME.zip: sha256 $("$PHP" "$RL_CHECK" sha256 "$DIST/$OUTNAME.zip")"
[[ -n $DEV ]] || echo "Upload the contents of $DIST/$OUTNAME/ (or unzip $DIST/$OUTNAME.zip) into https://repo.synapsecyber.io/synapsemail/whm-plugin-only/"
