#!/bin/bash
#
# check-coverage-doc.sh
#
# Verifies that docs/coverage-tracking.md is up to date with the current
# set of covered source files (src/*.php, excluding src/index.php).
#
# The doc embeds a <!-- src-hash: <md5> --> fingerprint.  When any covered
# file is added, removed, or modified that fingerprint drifts and this script
# exits non-zero so CI (composer lint) catches it.
#
# To update the doc after a source change:
#   1. Run this script — it prints the new hash.
#   2. Replace the src-hash value in docs/coverage-tracking.md.
#   3. Update the coverage table in the same file.
#   4. Commit both together.

set -euo pipefail

DOC="docs/coverage-tracking.md"

if [ ! -f "$DOC" ]; then
    echo "ERROR: $DOC not found" >&2
    exit 1
fi

STORED=$(grep -oP '(?<=src-hash: )\w+' "$DOC" || true)
if [ -z "$STORED" ]; then
    echo "ERROR: No src-hash found in $DOC" >&2
    exit 1
fi

CURRENT=$(find src -maxdepth 1 -name '*.php' ! -name 'index.php' | sort | xargs md5sum | md5sum | awk '{print $1}')

if [ "$STORED" != "$CURRENT" ]; then
    echo "ERROR: $DOC is outdated." >&2
    echo "  Stored hash:  $STORED" >&2
    echo "  Current hash: $CURRENT" >&2
    echo "" >&2
    echo "Update the src-hash in $DOC to: $CURRENT" >&2
    echo "Then update the coverage table and commit both together." >&2
    exit 1
fi

echo "OK: $DOC is up to date (hash: $CURRENT)"
