#!/usr/bin/env bash
#
# SynapseMail CI check: PHP syntax of every tracked SynapseMail PHP file, then the self test.
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
#
# .github/workflows/ci.yml runs this on every push. Locally it runs the same way:
#   tools/ci-check.sh                 with the php on PATH
#   PHP=/path/to/php tools/ci-check.sh
#
set -euo pipefail
cd "$(dirname "$0")/.."
php=${PHP:-php}

"$php" -r 'echo "PHP ", PHP_VERSION, "\n";'

# SynapseMail's own PHP (unmodified Roundcube and app/vendor are not ours to lint)
files=$(git ls-files -- '*.php' | grep -E '^(app/plugins/synapse_[a-z_]+/|app/public_html/(admin|install)\.php$|app/config/config\.inc\.php$|tools/|docker/)')
count=0
bad=0
for f in $files; do
    count=$((count + 1))
    if ! out=$("$php" -d display_errors=1 -d error_reporting=-1 -l "$f" 2>&1); then
        echo "$out"
        bad=$((bad + 1))
    elif [ "$out" != "No syntax errors detected in $f" ]; then
        # parses, but the compiler had something to say (a deprecation, for example)
        echo "$out"
    fi
done
echo "php -l: $count files, $bad with errors"
[ "$count" -gt 0 ] || { echo "no SynapseMail PHP files found"; exit 1; }
[ "$bad" -eq 0 ] || exit 1

"$php" app/plugins/synapse_core/tests/selftest.php
