#!/usr/bin/env bash
#
# SynapseMail release build.
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
#
# Builds from app/ into dist/:
#   dist/SynapseMail_v<V>.zip                      the portable (full tree) package
#   dist/repo/                                     upload to https://repo.synapsecyber.io/synapsemail/
#     releases/SynapseMail_v<V>.zip(.sha256)  SHA256SUMS  index.php  install.sh  .htaccess
#     release.json + release.json.sig              signed when ~/.synapsemail/release-signing.key exists
#
# Env: RELEASE_NOTES="..."  REPO_URL=https://repo.synapsecyber.io/synapsemail (default)
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
APP=app
PHP=${PHP:-php}
REPO_URL=${REPO_URL:-https://repo.synapsecyber.io/synapsemail}
KEY=${SIGNING_KEY:-$HOME/.synapsemail/release-signing.key}

die() { echo "ABORT: $*" >&2; exit 1; }
command -v "$PHP" >/dev/null || die "php not found (set PHP=/path/to/php)"
command -v zip >/dev/null || die "zip not found"

V=$(sed -n "s/.*const VERSION = '\([0-9.]*\)'.*/\1/p" $APP/plugins/synapse_core/synapse_core.php)
[[ -n $V ]] || die "cannot read synapse_core::VERSION"
NAME=SynapseMail_v$V
OUT=dist/$NAME

# 1. code sanity: every SynapseMail PHP file parses
while IFS= read -r f; do
  "$PHP" -l "$f" >/dev/null 2>&1 || die "PHP syntax error in $f"
done < <(find $APP/plugins/synapse_* $APP/public_html/install.php $APP/public_html/admin.php $APP/config/config.inc.php -name '*.php' -o -name '*.inc' | sort)

# 2. stage the package (per-install and dev files never ship)
rm -rf "$OUT" "$OUT.zip" dist/repo
mkdir -p dist
rsync -a --exclude '.DS_Store' \
  --exclude 'db/*.db' --exclude 'db/*.db-*' --exclude 'db/*.tmp' --exclude 'db/install-probe.txt' \
  --exclude 'config/synapse.inc.php' --exclude 'config/installed.lock' --exclude 'config/ADMIN-URL.txt' \
  --exclude 'config/*.tmp' --exclude 'config/config.local.inc.php' --exclude 'setup.key' \
  --exclude 'logs/*' --exclude 'temp/*' --exclude 'plugins/synapse_demo' \
  $APP/ "$OUT/"
cp $APP/logs/.htaccess "$OUT/logs/"
cp $APP/temp/.htaccess "$OUT/temp/"
# example ownership file: placeholder code only, the installer never accepts it
grep -qx 'SM-XXXX-XXXX-XXXX' tools/setup.key.example || die "tools/setup.key.example lost its placeholder"
cp tools/setup.key.example "$OUT/setup.key"
sed "1s/.*/SynapseMail v$V: install/" $APP/INSTALL-SYNAPSEMAIL.txt > "$OUT/INSTALL-SYNAPSEMAIL.txt"
# repo-root documents are the single source; the package gets copies
cp README.md "$OUT/README.md"
cp SECURITY.md "$OUT/SECURITY.md"
cp CHANGELOG.md "$OUT/CHANGELOG-SYNAPSEMAIL.md"
cp LICENSE-SYNAPSEMAIL.txt "$OUT/LICENSE-SYNAPSEMAIL.txt"

# 3. leak gates: per-install files and anything that looks like a secret
leak=$(cd "$OUT" && find . \( -name '*.db' -o -name '*.db-*' -o -name 'synapse.inc.php' -o -name 'installed.lock' \
  -o -name 'ADMIN-URL.txt' -o -name 'config.local.inc.php' -o -name 'release-signing.key' -o -path './logs/*.log' \) -print)
[[ -z $leak ]] || die "per-install files in the package: $leak"
# private patterns (test hosts, test accounts, personal addresses) live outside the repo, one regex per line
PATTERNS=${SECRET_PATTERNS:-$HOME/.synapsemail/secret-patterns.txt}
[[ -f $PATTERNS ]] || echo "(no $PATTERNS: only generic secret checks run)"
secrets=$( { [[ -f $PATTERNS ]] && grep -rIlE -f "$PATTERNS" "$OUT" || true
  grep -rIlE -e 'BEGIN [A-Z ]*PRIVATE KEY' -e 'AKIA[0-9A-Z]{16}' -e 'gh[pousr]_[A-Za-z0-9]{36}' -e 'xox[baprs]-[A-Za-z0-9-]{10,}' "$OUT" --exclude-dir=vendor --exclude-dir=enigma || true; } )
[[ -z $secrets ]] || die "secret-looking content in: $secrets"
grep -rqE "synapse_debug|debug_collect" "$OUT/plugins/synapse_core" "$OUT/config" && die "debug collector code found"
# the public demo plugin and its switches never ship in the release
[[ -e $OUT/plugins/synapse_demo ]] && die "demo plugin in the release package"
grep -q "synapse_demo'\] = true\|\"synapse_demo\"\] = true" "$OUT/config/config.inc.php" && die "demo switch in config.inc.php"
# every file listed as SynapseMail's own must be in the package (manifest used by the overlay/WHM editions)
missing=$(awk '/^\[added\]/{a=1;next} /^\[/{a=0} a&&NF' $APP/SYNAPSEMAIL-FILES.txt | while read -r p; do [[ -e "$OUT/$p" ]] || echo "$p"; done)
[[ -z $missing ]] || die "listed in SYNAPSEMAIL-FILES.txt but missing: $missing"

# 4. zip
(cd "$OUT" && zip -qr -X "../$NAME.zip" .)
echo "dist/$NAME.zip: $(unzip -l "dist/$NAME.zip" | tail -1 | awk '{print $2}') files, $(du -h "dist/$NAME.zip" | cut -f1)"

# 5. repo site
mkdir -p dist/repo/releases
cp "dist/$NAME.zip" dist/repo/releases/
(cd dist/repo/releases && shasum -a 256 "$NAME.zip" > "$NAME.zip.sha256" && cat ./*.sha256 > ../SHA256SUMS)
[[ -d repo-site ]] && cp -R repo-site/. dist/repo/
[[ -f installer/install.sh ]] && cp installer/install.sh dist/repo/install.sh

# 6. signed manifest (private key stays outside the repo)
if [[ -f $KEY ]]; then
  "$PHP" -r '
    [, $v, $zip, $url, $key, $notes] = $argv;
    $m = json_encode(["product" => "SynapseMail", "edition" => "portable", "version" => $v, "released" => gmdate("Y-m-d"),
      "url" => $url, "sha256" => hash_file("sha256", $zip), "notes" => $notes], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    file_put_contents("dist/repo/release.json", $m);
    file_put_contents("dist/repo/release.json.sig", base64_encode(sodium_crypto_sign_detached($m, base64_decode(trim(file_get_contents($key))))) . "\n");
  ' "$V" "dist/$NAME.zip" "$REPO_URL/releases/$NAME.zip" "$KEY" "${RELEASE_NOTES:-}"
  echo "dist/repo/release.json signed for $V"
else
  echo "(no signing key at $KEY: release.json not written)"
fi
echo "done: dist/$NAME.zip and dist/repo/"
