#!/usr/bin/env bash
#
# Acceptance tests for the release packaging rules (tools/build_release.sh, tools/build_whm_plugin.sh,
# tools/release-check.php). Everything runs in a throwaway clone with a throwaway signing key, throwaway private
# patterns and a throwaway HOME, so the real release key and pattern files are never read.
#   tools/test-release-build.sh        (PHP=/path/to/php to pick the interpreter)
# Uncommitted changes of this checkout are applied to the clone, so the tests cover the files as they are now.
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
export LC_ALL=C TZ=UTC
PHP=${PHP:-php}
T=$(mktemp -d "${TMPDIR:-/tmp}/synapsemail-buildtest.XXXXXX")
trap 'rm -rf "$T"' EXIT
pass=0
failed=0
ok() { echo "ok   $*"; pass=$((pass + 1)); }
no() { echo "FAIL $*"; failed=$((failed + 1)); }

# throwaway key, patterns and home (no fallback to ~/.synapsemail is possible)
mkdir -p "$T/home"
"$PHP" -r '$k = sodium_crypto_sign_keypair(); file_put_contents($argv[1], base64_encode(sodium_crypto_sign_secretkey($k)) . "\n");' "$T/test.key"
TESTPUB=$("$PHP" -r 'echo base64_encode(sodium_crypto_sign_publickey_from_secretkey(base64_decode(trim(file_get_contents($argv[1])))));' "$T/test.key")
printf '# throwaway pattern for the tests\nprivate-test-host\\.invalid\n' >"$T/patterns.txt"
unset COMMIT DEV UNSIGNED RELEASE_NOTES
export HOME=$T/home SIGNING_KEY=$T/test.key TEST_SIGNING_KEY=1 LEAK_PATTERNS=$T/patterns.txt DIST=$T/dist PHP

# the clone: this checkout's HEAD plus its uncommitted and untracked (not ignored) files, committed
R=$T/repo
git clone -q --no-hardlinks "$ROOT" "$R"
git -C "$ROOT" diff --binary HEAD >"$T/wip.patch"
if [[ -s $T/wip.patch ]]; then git -C "$R" apply --binary "$T/wip.patch"; fi
(cd "$ROOT" && git ls-files -o --exclude-standard -z | xargs -0 tar -cf - --) | tar -xf - -C "$R" 2>/dev/null || true
g() { git -C "$R" -c user.name=build-test -c user.email=build-test@example.invalid -c commit.gpgsign=false "$@"; }
g add -A
g commit -q --allow-empty -m "build test base"
BASE=$(g rev-parse HEAD)

reset() { g reset -q --hard "$BASE"; g clean -qfdx; rm -rf "$DIST"; }
commit() { g add -A -f; g commit -q -m "plant"; }
release() { (cd "$R" && "$@" tools/build_release.sh) >"$T/out.txt" 2>&1; }
whm() { (cd "$R" && "$@" tools/build_whm_plugin.sh) >"$T/out.txt" 2>&1; }
signed() { grep -q 'files signed' "$T/out.txt" || [[ -f $DIST/whm-plugin-only/SHA256SUMS.sig ]]; }
anyzip() { ls "$DIST"/*.zip >/dev/null 2>&1; }

# expect_reject <label> <message regex> <builder> <plant command...>: the build fails with the message, unsigned, no zip
expect_reject() {
  local label=$1 re=$2 builder=$3; shift 3
  reset
  "$@"
  commit
  if $builder env; then
    no "$label: the build passed"
  elif ! grep -qE "$re" "$T/out.txt"; then
    no "$label: failed for another reason: $(grep -E 'ABORT|REJECT' "$T/out.txt" | head -2 | tr '\n' ' ')"
  elif signed || anyzip; then
    no "$label: something was signed or zipped"
  else
    ok "$label: rejected before signing ($(grep -E 'REJECT|ABORT' "$T/out.txt" | grep -E "$re" | head -1 | cut -c1-90))"
  fi
}

put() { mkdir -p "$R/$(dirname "$1")"; printf '%s\n' "${2:-x}" >"$R/$1"; }
key_block() { printf -- '-----BEGIN %s-----\nMC4CAQAwBQYDK2VwBCIEIA==\n-----END %s-----\n' "PRIVATE KEY" "PRIVATE KEY" >"$R/$1"; }
token() { printf '<?php // %s\n' "gh""p_$(printf 'a%.0s' $(seq 36))" >"$R/$1"; }
append() { printf '%s\n' "$2" >>"$R/$1"; }

echo "== reject classes (release package)"
expect_reject "other edition" 'other edition' release put app/plugins/synapse_ee_audit/synapse_ee_audit.php '<?php'
expect_reject "local config" 'per-install file: config/config.local.inc.php' release put app/config/config.local.inc.php '<?php'
expect_reject "install config" 'per-install file: config/synapse.inc.php' release put app/config/synapse.inc.php '<?php'
expect_reject "install lock" 'per-install file: config/installed.lock' release put app/config/installed.lock
expect_reject "setup key" 'ownership key' release put app/plugins/synapse_core/setup.key SM-1234-5678-9ABC
expect_reject "setup key with a real code" 'setup.key holds something other than the placeholder' release append tools/setup.key.example SM-1234-5678-9ABC
expect_reject "database" 'database: db/sm.db' release put app/db/sm.db
expect_reject "database WAL" 'database: db/sm.db-wal' release put app/db/sm.db-wal
expect_reject "database SHM" 'database: db/sm.db-shm' release put app/db/sm.db-shm
expect_reject "backup" 'backup, snapshot or rollback file' release put app/plugins/synapse_core/sm-backup-1.smbk
expect_reject "restore snapshot" 'backup, snapshot or rollback file: db/before-restore' release put app/db/before-restore-20261127-000000.db
expect_reject "rollback zip" 'backup, snapshot or rollback file: db/rollback-v' release put app/db/rollback-v3.5.5-20261127-000000.zip
expect_reject "graph output" 'development folder or file \(graphify-out\)' release put app/graphify-out/graph.json '{}'
expect_reject ".DS_Store" 'development folder or file \(.ds_store\)' release put app/plugins/.DS_Store
expect_reject "node_modules" 'development folder or file \(node_modules\)' release put app/plugins/synapse_editor/node_modules/x/index.js
expect_reject "editor source" 'editor source or build tooling' release put app/plugins/synapse_editor/src/editor.ts 'export {}'
expect_reject "editor package file" 'editor source or build tooling' release put app/plugins/synapse_editor/package.json '{}'
expect_reject "private key block" 'private key block' release key_block app/plugins/synapse_core/lib/key.txt
expect_reject "access token" 'GitHub token' release token app/plugins/synapse_core/lib/token.php
expect_reject "private pattern" 'private host or address pattern found' release put app/plugins/synapse_core/lib/host.txt 'imap.private-test-host.invalid'
expect_reject "symbolic link" 'symbolic link' release ln -s /etc/hosts "$R/app/plugins/synapse_core/hosts"

echo "== file lists"
expect_reject "unlisted file" 'not in SYNAPSEMAIL-FILES.txt or the Roundcube list: plugins/extra/extra.php' release put app/plugins/extra/extra.php '<?php'
expect_reject "listed Roundcube file missing" 'listed Roundcube file missing: program/js/app.js' release rm "$R/app/program/js/app.js"
expect_reject "Roundcube file changed" 'differs from the pinned list' release append app/program/lib/Roundcube/rcube.php '// changed'
expect_reject "listed SynapseMail file missing" 'listed in SYNAPSEMAIL-FILES.txt but missing: plugins/synapse_preview' release rm -r "$R/app/plugins/synapse_preview"

echo "== Docker build context"
expect_reject "context file missing" 'build context file missing: php.ini' release rm "$R/docker/php.ini"
expect_reject "copy from outside the allow-list" 'outside the allow-list: extra.conf' release append docker/Dockerfile 'COPY extra.conf /etc/extra.conf'
plant_ctx() { append docker/.dockerignore '!state.db'; put docker/state.db; }
expect_reject "database in the context" 'database: state.db' release plant_ctx

echo "== source rules"
reset
put app/plugins/synapse_core/lib/wip.txt
if release env; then no "dirty tree: the build passed"; elif grep -q 'uncommitted or untracked' "$T/out.txt"; then ok "dirty tree: refused"; else no "dirty tree: other failure"; fi
if release env DEV=1 && ! signed && ! unzip -l "$DIST"/SynapseMail_v*-dev-*.zip | grep -q SYNAPSEMAIL-RELEASE.sig; then
  ok "dirty tree with DEV=1: built and not signed, although a key was given"
else no "dirty tree with DEV=1: $(tail -2 "$T/out.txt" | tr '\n' ' ')"; fi
reset
g commit -q --allow-empty -m "dangling"
DANGLING=$(g rev-parse HEAD)
g reset -q --hard "$BASE"
if release env COMMIT="$DANGLING"; then no "commit on no branch: the build passed"; elif grep -q 'not on any branch or tag' "$T/out.txt"; then ok "commit on no branch: refused"; else no "commit on no branch: other failure"; fi
reset
if release env LEAK_PATTERNS=; then no "no pattern file: the release build passed"; elif grep -q 'no private pattern file' "$T/out.txt"; then ok "no pattern file: release build refused"; else no "no pattern file: other failure"; fi
if release env LEAK_PATTERNS= DEV=1 && grep -q 'WARNING: no private pattern file' "$T/out.txt"; then ok "no pattern file: dev build warns"; else no "no pattern file: dev build"; fi
reset
if release env TEST_SIGNING_KEY=; then no "test key as release key: the build passed"; elif grep -q 'not the release key' "$T/out.txt"; then ok "a key that is not the release key: refused"; else no "release key check: other failure"; fi
reset
if release env SIGNING_KEY="$T/none.key"; then no "missing key: the build passed"; elif grep -q 'no signing key' "$T/out.txt"; then ok "missing key: refused"; else no "missing key: other failure"; fi
# a .git folder cannot be committed, so the checker is tested on a staged copy
mkdir -p "$T/gitcase" && cp -Rp "$R/whm-plugin/payload/." "$T/gitcase/" && mkdir "$T/gitcase/.git" && put ../gitcase/.git/config
if "$PHP" "$R/tools/release-check.php" list "$T/gitcase" "$R/whm-plugin/PAYLOAD-FILES.txt" 2>"$T/out.txt"; then no ".git folder: passed"; elif grep -q 'development folder or file (.git)' "$T/out.txt"; then ok ".git folder: rejected"; else no ".git folder: other failure"; fi

echo "== normal build and determinism"
reset
if release env; then
  cp "$T/out.txt" "$T/out1.txt"
  Z=$(ls "$DIST"/SynapseMail_v*.zip)
  mkdir "$T/unz" && unzip -q "$Z" -d "$T/unz"
  if "$PHP" -r 'exit(sodium_crypto_sign_verify_detached(base64_decode(trim(file_get_contents($argv[1]."/SYNAPSEMAIL-RELEASE.sig"))), file_get_contents($argv[1]."/SYNAPSEMAIL-RELEASE.json"), base64_decode($argv[2])) ? 0 : 1);' "$T/unz" "$TESTPUB"; then
    ok "normal build of HEAD: passes, package signature verifies ($(grep -c . <<<"$(unzip -Z1 "$Z")") entries)"
  else no "normal build: signature does not verify"; fi
  [[ ! -e $T/unz/plugins/synapse_demo && $(grep -v '^#' "$T/unz/setup.key" | grep .) == SM-XXXX-XXXX-XXXX ]] && ok "no demo plugin, setup.key is the placeholder" || no "package content"
  [[ -f $DIST/repo/release.json.sig && -f $DIST/repo.zip ]] && ok "repo folder and release.json signed" || no "repo folder"
  [[ $(unzip -Z1 "$Z") == $(unzip -Z1 "$Z" | sort) ]] && ok "zip entries are sorted" || no "zip entries are not sorted"
  H1=$("$PHP" "$R/tools/release-check.php" sha256 "$Z"); R1=$("$PHP" "$R/tools/release-check.php" sha256 "$DIST/repo.zip")
  sleep 2
  release env || no "second build failed"
  H2=$("$PHP" "$R/tools/release-check.php" sha256 "$Z"); R2=$("$PHP" "$R/tools/release-check.php" sha256 "$DIST/repo.zip")
  [[ $H1 == "$H2" && $R1 == "$R2" ]] && ok "two builds of one commit: identical zip ($H1) and repo.zip" || no "builds differ: $H1 / $H2, $R1 / $R2"
else
  no "normal build of HEAD failed: $(grep -E 'ABORT|REJECT' "$T/out.txt" | head -3 | tr '\n' ' ')"
fi

echo "== WHM package"
reset
if whm env; then
  "$PHP" -r 'exit(sodium_crypto_sign_verify_detached(file_get_contents($argv[1]."/SHA256SUMS.sig"), file_get_contents($argv[1]."/SHA256SUMS"), base64_decode($argv[2])) ? 0 : 1);' "$DIST/whm-plugin-only" "$TESTPUB" &&
    ok "WHM build of HEAD: passes, SHA256SUMS signature verifies" || no "WHM signature"
  W1=$("$PHP" "$R/tools/release-check.php" sha256 "$DIST/whm-plugin-only.zip"); P1=$(cat "$DIST/whm-plugin-only/SHA256SUMS")
  sleep 2
  whm env || no "second WHM build failed"
  W2=$("$PHP" "$R/tools/release-check.php" sha256 "$DIST/whm-plugin-only.zip"); P2=$(cat "$DIST/whm-plugin-only/SHA256SUMS")
  [[ $W1 == "$W2" && $P1 == "$P2" ]] && ok "two WHM builds of one commit: identical" || no "WHM builds differ"
else
  no "WHM build of HEAD failed: $(grep -E 'ABORT|REJECT' "$T/out.txt" | head -3 | tr '\n' ' ')"
fi
expect_reject "WHM unlisted file" 'not in PAYLOAD-FILES.txt: whm/extra.txt' whm put whm-plugin/payload/whm/extra.txt
expect_reject "WHM listed file missing" 'listed but missing: whm/banner.webp' whm rm "$R/whm-plugin/payload/whm/banner.webp"
expect_reject "WHM database" 'database: whm/cache.db' whm put whm-plugin/payload/whm/cache.db
expect_reject "WHM private pattern" 'private host or address pattern found' whm append whm-plugin/README.md 'see host.private-test-host.invalid'
expect_reject "WHM private key block" 'private key block' whm key_block whm-plugin/payload/lib/ui.sh

echo "$pass ok, $failed FAIL"
exit $((failed > 0))
