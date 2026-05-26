#!/usr/bin/env bash
# .pot drift gate (I18N-02, D-15).
#
# Regenerates the .pot to a temp file using the same flags as the `composer pot`
# script (including the pinned empty POT-Creation-Date header so output is
# bit-identical across runs), then diffs it against the committed copy.
# Fails with exit 1 if they differ, reminding the developer to run `composer pot`.
#
# Skips gracefully when:
#   - `wp` is not on PATH (local pre-commit without WP-CLI; CI always has it)
#   - The committed .pot does not exist yet (run `composer pot` first)
#
# Exit codes: 0 = clean or skipped, 1 = drift detected.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

POT_FILE="languages/better-rest-api-logs.pot"

# Skip if WP-CLI is not available (local environments without the tool).
if ! command -v wp &>/dev/null; then
    echo "check-pot: wp not found on PATH — skipping (CI always provides WP-CLI)."
    exit 0
fi

# Fail with a helpful message if the committed .pot does not exist yet.
if [ ! -f "$POT_FILE" ]; then
    echo "check-pot: $POT_FILE does not exist."
    echo "  → Run \`composer pot\` to generate it, then commit the result."
    exit 1
fi

TMP_POT="$(mktemp /tmp/brl-check-pot-XXXXXX.pot)"
trap 'rm -f "$TMP_POT"' EXIT

# Regenerate to the temp file with the identical flags used by `composer pot`.
wp i18n make-pot . "$TMP_POT" \
    --slug=better-rest-api-logs \
    --domain=better-rest-api-logs \
    --exclude=tests,bin,.ddev,.planning,.githooks,.wordpress-org,docs,vendor,wp \
    --headers='{"POT-Creation-Date":""}' \
    --quiet 2>/dev/null || true

if diff -q "$POT_FILE" "$TMP_POT" > /dev/null 2>&1; then
    echo "check-pot: clean — committed .pot matches a fresh generation."
    exit 0
fi

echo "check-pot: DRIFT detected between committed .pot and a fresh generation."
diff "$POT_FILE" "$TMP_POT" | head -40 || true
echo
echo "  → Run \`composer pot\` and commit the updated languages/better-rest-api-logs.pot."
exit 1
