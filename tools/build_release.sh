#!/usr/bin/env bash
#
# SynapseMail release build.
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
#
# Builds one commit (default HEAD) into dist/:
#   dist/SynapseMail_v<V>.zip                      the portable (full tree) package
#   dist/repo/ and dist/repo.zip                   upload to https://repo.synapsecyber.io/synapsemail/
#     releases/SynapseMail_v<V>.zip(.sha256)  SHA256SUMS  index.html  .htaccess
#     release.json + release.json.sig              signed with the release key
#
# How a package is made (each step must pass before the next; nothing is signed before every check passed):
#   1. source: a clean snapshot of the commit (git archive into a temporary folder), never the working folder.
#      The tree must be clean and the commit on a branch or tag, unless DEV=1.
#   2. code: every SynapseMail PHP file parses and the self test passes, on the snapshot.
#   3. file lists: every packaged file is in app/SYNAPSEMAIL-FILES.txt or in tools/roundcube-files.txt (pinned
#      Roundcube files with SHA-256), every listed file is there, and unmodified Roundcube files match their hash.
#   4. reject classes: other-edition paths, per-install files (local configs, lock, setup key, databases),
#      backups, snapshots and rollback zips, development folders, editor sources, links, private key blocks and
#      access tokens, and the private host and address patterns (outside the repo, never printed).
#      The Docker build context (docker/.dockerignore allow-list) is checked the same way.
#   5. sign: SYNAPSEMAIL-RELEASE.json + .sig (sha256 of every file, Ed25519) inside the package; the admin panel
#      installs only zips signed with the release key, which stays outside the repo.
#   6. zip: sorted entries, the commit's time, fixed modes. Two builds of one commit give the same bytes.
#
# Env: COMMIT, DEV, LEAK_PATTERNS, SIGNING_KEY, UNSIGNED=1, TEST_SIGNING_KEY, DIST (see tools/release-lib.sh)
#      RELEASE_NOTES="..." (default: this version's CHANGELOG section)  REPO_URL=https://repo.synapsecyber.io/synapsemail
# A DEV build is written as dist/SynapseMail_v<V>-dev-<commit>.zip, unsigned and without the repo folder.
set -euo pipefail
cd "$(dirname "$0")/.."
. tools/release-lib.sh
REPO_URL=${REPO_URL:-https://repo.synapsecyber.io/synapsemail}

# 1. source
rl_source
rl_patterns
SNAP=$RL_WORK/src
rl_snapshot "$SNAP"
for f in app/SYNAPSEMAIL-FILES.txt tools/roundcube-files.txt docker/.dockerignore tools/setup.key.example; do
  [[ -f $SNAP/$f ]] || die "$f is not in commit $RL_SHORT"
done
APP=$SNAP/app
V=$(sed -n "s/.*const VERSION = '\([0-9.]*\)'.*/\1/p" "$APP/plugins/synapse_core/synapse_core.php")
[[ -n $V ]] || die "cannot read synapse_core::VERSION"
PUB=$(sed -n "s/.*PUBLIC_KEY = '\([A-Za-z0-9+\/=]*\)'.*/\1/p" "$APP/plugins/synapse_core/lib/synapse_update.php")
[[ ${#PUB} -eq 44 ]] || die "release public key not found in synapse_update.php"
rl_signing "$PUB"
NAME=SynapseMail_v$V
ZIPNAME=$NAME${DEV:+-dev-$RL_SHORT}.zip
echo "version: $V"

# 2. code sanity on the snapshot: every SynapseMail PHP file parses, the self test passes
while IFS= read -r f; do
  "$PHP" -l "$f" >/dev/null 2>&1 || die "PHP syntax error in ${f#"$SNAP"/}"
done < <(find "$APP"/plugins/synapse_* "$APP/public_html/install.php" "$APP/public_html/admin.php" "$APP/config/config.inc.php" \( -name '*.php' -o -name '*.inc' \) | sort)
"$PHP" "$APP/plugins/synapse_core/tests/selftest.php" >"$RL_WORK/selftest.txt" 2>&1 || { grep '^FAIL' "$RL_WORK/selftest.txt" >&2 || true; die "self test failed"; }
echo "code: PHP files parse, self test $(grep -c '^ok' "$RL_WORK/selftest.txt") ok"

# stage the package from the snapshot (the demo plugin never ships)
OUT=$RL_WORK/pkg
cp -Rp "$APP" "$OUT"
rm -rf "$OUT/plugins/synapse_demo"
# example ownership file: placeholder code only, the installer never accepts it
grep -qx 'SM-XXXX-XXXX-XXXX' "$SNAP/tools/setup.key.example" || die "tools/setup.key.example lost its placeholder"
cp "$SNAP/tools/setup.key.example" "$OUT/setup.key"
sed "1s/.*/SynapseMail v$V: install/" "$APP/INSTALL-SYNAPSEMAIL.txt" >"$OUT/INSTALL-SYNAPSEMAIL.txt"
# repo-root documents are the single source; the package gets copies
cp "$SNAP/README.md" "$OUT/README.md"
cp "$SNAP/SECURITY.md" "$OUT/SECURITY.md"
cp "$SNAP/CHANGELOG.md" "$OUT/CHANGELOG-SYNAPSEMAIL.md"
cp "$SNAP/LICENSE-SYNAPSEMAIL.txt" "$OUT/LICENSE-SYNAPSEMAIL.txt"

# 3. and 4. file lists and reject classes, before anything is signed
"$PHP" "$RL_CHECK" package "$OUT" "$APP/SYNAPSEMAIL-FILES.txt" "$SNAP/tools/roundcube-files.txt" || die "package check failed"
rl_scan_private "$OUT"
! grep -rqE "synapse_debug|debug_collect" "$OUT/plugins/synapse_core" "$OUT/config" || die "debug collector code found"
! grep -q "synapse_demo'\] = true\|\"synapse_demo\"\] = true" "$OUT/config/config.inc.php" || die "demo switch in config.inc.php"
"$PHP" "$RL_CHECK" docker "$SNAP/docker" || die "Docker build context check failed"
rl_scan_private "$SNAP/docker"

# notes: this version's CHANGELOG section, wrapped lines joined, markdown marks removed
NOTES=${RELEASE_NOTES:-$(awk -v v="## $V" '$0==v{f=1;next} /^## /{f=0} f' "$SNAP/CHANGELOG.md" | sed -e 's/\*\*//g' -e 's/`//g' | awk 'NR>1 && /^  /{sub(/^ +/," ");printf "%s",$0;next} {if(NR>1)printf "\n";printf "%s",$0} END{print ""}' | head -c 3000)}

# 5. signed file list inside the package: the in-app updater installs only zips whose every file matches it
if [[ -n $RL_SIGN ]]; then
  "$PHP" "$RL_CHECK" sign-files "$OUT" "$V" "$RL_DATE" "$NOTES" "$RL_KEY"
  if [[ -z ${TEST_SIGNING_KEY:-} ]]; then
    # the same check the Docker build runs on the published zip
    "$PHP" "$SNAP/docker/verify-release.php" "$OUT" "$V" || die "the signed package does not verify"
  fi
fi

# 6. zip (deterministic)
mkdir -p "$DIST"
rm -f "$DIST/$ZIPNAME"
"$PHP" "$RL_CHECK" zip "$OUT" "$DIST/$ZIPNAME" "$RL_TIME" >/dev/null
echo "$DIST/$ZIPNAME: $(unzip -l "$DIST/$ZIPNAME" | tail -1 | awk '{print $2}') files, $(du -h "$DIST/$ZIPNAME" | cut -f1), sha256 $("$PHP" "$RL_CHECK" sha256 "$DIST/$ZIPNAME")"
if [[ -n $DEV ]]; then
  echo "done: $DIST/$ZIPNAME (DEV build: unsigned, not for publishing)"
  exit 0
fi

# 7. repo site
REPO=$RL_WORK/repo
mkdir -p "$REPO/releases"
cp "$DIST/$ZIPNAME" "$REPO/releases/"
rl_sha256 "$REPO/releases/$NAME.zip" "$NAME.zip" >"$REPO/releases/$NAME.zip.sha256"
cp "$REPO/releases/$NAME.zip.sha256" "$REPO/SHA256SUMS"
# landing page and server rules (placeholders filled in)
for f in index.html .htaccess; do
  [[ -f $SNAP/repo-site/$f ]] && sed -e "s/{{VERSION}}/$V/g" -e "s/{{DATE}}/$RL_DATE/g" -e "s/{{SHA256}}/$(cut -d' ' -f1 "$REPO/releases/$NAME.zip.sha256")/g" "$SNAP/repo-site/$f" >"$REPO/$f"
done
[[ -f $SNAP/installer/install.sh ]] && cp "$SNAP/installer/install.sh" "$REPO/install.sh"
# signed manifest (private key stays outside the repo)
if [[ -n $RL_SIGN ]]; then
  "$PHP" "$RL_CHECK" sign-repo "$REPO" "$V" "$RL_DATE" "$DIST/$ZIPNAME" "$REPO_URL/releases/$NAME.zip" "$NOTES" "$RL_KEY"
  echo "repo/release.json signed for $V"
else
  echo "(unsigned build: release.json not written)"
fi
rl_scan_private "$REPO"
rm -rf "$DIST/repo" "$DIST/repo.zip"
cp -Rp "$REPO" "$DIST/repo"
# one zip of the repo folder: extract its contents into /synapsemail/ on the update server
"$PHP" "$RL_CHECK" zip "$REPO" "$DIST/repo.zip" "$RL_TIME" >/dev/null
echo "$DIST/repo.zip: sha256 $("$PHP" "$RL_CHECK" sha256 "$DIST/repo.zip")"
echo "done: $DIST/$ZIPNAME, $DIST/repo/ and $DIST/repo.zip (upload into https://repo.synapsecyber.io/synapsemail/)"
