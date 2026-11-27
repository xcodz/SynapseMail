# Shared steps of tools/build_release.sh and tools/build_whm_plugin.sh (sourced, not run).
# Copyright (c) 2026 SynapseCyber.io. Developed by Milad Ahmadipour (https://milad.io). CC BY-NC-ND 4.0.
#
# Packages are built from a clean snapshot of one commit (git archive), never from the working folder.
#   COMMIT=<rev>          commit to build (default HEAD)
#   DEV=1                 allow a dirty tree or a commit on no branch; such a build is never signed
#   LEAK_PATTERNS=<file>  private host and address patterns, one extended regex per line (# comments allowed);
#                         default ~/.synapsemail/leak-patterns.txt (then the older secret-patterns.txt). A release
#                         build fails without it, a dev build warns. The patterns are never printed.
#   SIGNING_KEY=<file>    Ed25519 secret key, base64 (default ~/.synapsemail/release-signing.key)
#   TEST_SIGNING_KEY=1    accept a key that is not the release key (tests only: the updater refuses such packages)
#   DIST=<dir>            output folder (default dist)

export LC_ALL=C TZ=UTC
PHP=${PHP:-php}
DIST=${DIST:-dist}
DEV=${DEV:-}
RL_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
RL_CHECK=$RL_DIR/release-check.php

die() { echo "ABORT: $*" >&2; exit 1; }
command -v "$PHP" >/dev/null || die "php not found (set PHP=/path/to/php)"
command -v git >/dev/null || die "git not found"

# temporary work folder, removed on exit
RL_WORK=$(mktemp -d "${TMPDIR:-/tmp}/synapsemail-build.XXXXXX")
trap 'rm -rf "$RL_WORK"' EXIT

# rl_source: resolve and check the commit. Sets RL_COMMIT, RL_SHORT, RL_TIME (unix) and RL_DATE (YYYY-MM-DD, UTC).
rl_source() {
  local want=${COMMIT:-HEAD}
  RL_COMMIT=$(git rev-parse --verify --quiet "$want^{commit}") || die "not a commit: $want"
  RL_SHORT=$(git rev-parse --short=12 "$RL_COMMIT")
  if [[ -z $DEV ]]; then
    [[ -z $(git status --porcelain --untracked-files=normal) ]] ||
      die "the working tree has uncommitted or untracked files: commit or remove them (DEV=1 builds an unsigned dev package)"
    [[ -n $(git for-each-ref --count=1 --contains "$RL_COMMIT" refs/heads refs/tags) ]] ||
      die "commit $RL_SHORT is not on any branch or tag"
  fi
  RL_TIME=$(git show -s --format=%ct "$RL_COMMIT")
  RL_DATE=$(git show -s --format=%cd --date=format-local:%Y-%m-%d "$RL_COMMIT")
  echo "source: commit $RL_SHORT ($RL_DATE)${DEV:+, DEV build (never signed)}"
}

# rl_snapshot <dest> [paths...]: the commit's files (or only the given paths) in a fresh folder
rl_snapshot() {
  local dest=$1; shift
  mkdir -p "$dest"
  git archive --format=tar "$RL_COMMIT" -- "$@" | tar -x -C "$dest" || die "git archive failed"
}

# rl_patterns: find the private pattern file. Sets RL_PATTERNS (empty when a dev build has none).
rl_patterns() {
  RL_PATTERNS=${LEAK_PATTERNS:-}
  if [[ -z $RL_PATTERNS ]]; then
    for f in "$HOME/.synapsemail/leak-patterns.txt" "$HOME/.synapsemail/secret-patterns.txt"; do
      [[ -f $f ]] && { RL_PATTERNS=$f; break; }
    done
  fi
  if [[ -z $RL_PATTERNS || ! -f $RL_PATTERNS ]]; then
    [[ -n $DEV ]] || die "no private pattern file (LEAK_PATTERNS, default ~/.synapsemail/leak-patterns.txt): a release build needs it"
    echo "WARNING: no private pattern file, private hosts and addresses are not checked (dev build only)"
    RL_PATTERNS=
    return 0
  fi
  grep -qvE '^[[:space:]]*(#|$)' "$RL_PATTERNS" || die "the private pattern file has no patterns"
}

# rl_scan_private <path>...: no private host or address anywhere in the given files or folders
rl_scan_private() {
  [[ -n $RL_PATTERNS ]] || return 0
  local hits rc=0
  hits=$(grep -rIliE -f <(grep -vE '^[[:space:]]*(#|$)' "$RL_PATTERNS") -- "$@" 2>/dev/null) || rc=$?
  ((rc <= 1)) || die "the private pattern file could not be used (check its regex syntax)"
  [[ -z $hits ]] || die "private host or address pattern found (patterns not shown) in: $(echo "$hits" | sed "s#^$RL_WORK/[^/]*/##" | tr '\n' ' ')"
  echo "private patterns: nothing found"
}

# rl_signing <public key>: decide whether to sign. Sets RL_SIGN (1 or empty) and RL_KEY.
rl_signing() {
  local pub=$1
  RL_KEY=${SIGNING_KEY:-$HOME/.synapsemail/release-signing.key}
  RL_SIGN=
  if [[ -n $DEV ]]; then
    echo "signing: off (DEV build)"
  elif [[ -n ${UNSIGNED:-} ]]; then
    echo "signing: off (UNSIGNED=1, the updater refuses this package)"
  else
    [[ -f $RL_KEY ]] || die "no signing key (SIGNING_KEY, default ~/.synapsemail/release-signing.key; UNSIGNED=1 builds an unsigned zip)"
    if [[ -n ${TEST_SIGNING_KEY:-} ]]; then
      echo "signing: TEST key, not for publishing (the updater refuses it)"
    else
      "$PHP" "$RL_CHECK" key "$RL_KEY" "$pub" || die "the signing key is not the release key"
    fi
    RL_SIGN=1
  fi
}

# rl_sha256 <file> <name>: one SHA256SUMS line
rl_sha256() { echo "$("$PHP" "$RL_CHECK" sha256 "$1")  $2"; }
