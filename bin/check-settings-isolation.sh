#!/usr/bin/env bash
# SET-01 gate: direct option reads/writes against `brl_*` keys must go through the
# Settings\Registry. The only files allowed to call the raw option API with a
# `brl_*` literal are:
#   - includes/settings/        (the Registry + Repository + Defaults + Internal)
#   - includes/activator.php    (seeds defaults at activation; no Registry yet)
#   - includes/deactivator.php  (clears scheduled hook; may touch brl_* options)
#   - uninstall.php             (cannot autoload Registry)
#
# Second invariant: at least 2 distinct `register_setting('brl_settings_*'...)`
# option groups must exist under includes/ (proves per-tab isolation per D-06).
#
# Invoked by:
#   - composer check:settings-isolation   (CI: SCAN_MODE=full)
#   - .githooks/pre-commit                (SCAN_MODE=staged)
#
# Exit codes: 0 = clean, 1 = at least one violation.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

MODE="${SCAN_MODE:-full}"

# Build the file list (mirrors bin/check-banlist.sh).
if [ "$MODE" = "staged" ]; then
    FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$' || true)
    if [ -z "$FILES" ]; then
        echo "settings-isolation: no staged PHP files — skipping."
        exit 0
    fi
else
    FILES=$(find . -type f -name '*.php' \
        -not -path './vendor/*' \
        -not -path './node_modules/*' \
        -not -path './build/*' \
        -not -path './release/*' \
        -not -path './.planning/*' \
        -not -path './.claude/*' \
        -not -path './.git/*' \
        -not -path './.ddev/*' \
        -not -path './wp/*' \
        || true)
fi

if [ -z "$FILES" ]; then
    echo "settings-isolation: no PHP files to scan."
    exit 0
fi

HITS=0

# Invariant 1: no get_option/update_option/add_option/delete_option('brl_*') outside
# the Settings boundary.
RAW_HITS=$(echo "$FILES" \
    | xargs -r grep -lE '(get_option|update_option|add_option|delete_option)[[:space:]]*\([[:space:]]*['"'"'"]brl_' 2>/dev/null \
    | grep -v '^\./includes/settings/' \
    | grep -v '^\./includes/activator\.php$' \
    | grep -v '^\./includes/deactivator\.php$' \
    | grep -v '^\./uninstall\.php$' \
    | grep -v '^\./tests/' \
    | grep -v 'check-settings-isolation\.sh' \
    || true)

if [ -n "$RAW_HITS" ]; then
    echo "settings-isolation HIT — raw brl_* option API call outside Settings boundary (SET-01):"
    echo "$RAW_HITS"
    echo
    echo "Read settings via BetterRestApiLogs\\Settings\\Registry::get_setting() / get_internal()."
    HITS=$((HITS + 1))
fi

# Invariant 2: at least 2 `register_setting('brl_settings_*', ...)` calls under includes/.
# Wave 0 doesn't ship Registry yet, so this invariant is "advisory at scan time" —
# it's a no-op until the Registry lands in Plan 02-05. Skip the count if no
# register_setting calls exist at all (means Registry not yet shipped).
REG_FILES=$(find ./includes -type f -name '*.php' 2>/dev/null || true)
if [ -n "$REG_FILES" ]; then
    # grep returns 1 with no match; pipefail would propagate that. Anchor the
    # pipeline with `|| true` on the grep step so the count stays 0 cleanly.
    REG_COUNT=$(echo "$REG_FILES" \
        | { xargs -r grep -hE "register_setting[[:space:]]*\([[:space:]]*['"'"'"]brl_settings_" 2>/dev/null || true; } \
        | wc -l \
        | tr -d ' ')
    if [ "${REG_COUNT:-0}" -ge 1 ] && [ "${REG_COUNT:-0}" -lt 2 ]; then
        echo "settings-isolation HIT — only ${REG_COUNT} register_setting('brl_settings_*'...) call found."
        echo "Per D-06, each settings tab must have its own option group (≥2 distinct calls)."
        HITS=$((HITS + 1))
    fi
fi

if [ "$HITS" -gt 0 ]; then
    echo
    echo "settings-isolation: ${HITS} violation(s). Commit blocked."
    exit 1
fi

echo "settings-isolation: clean (${MODE} mode)."
exit 0
