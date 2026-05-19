#!/usr/bin/env bash
# Asset enqueue must be screen-scoped.
#
# Every wp_enqueue_script() / wp_enqueue_style() call in
# includes/admin/assets.php must have a sibling $screen->id comparison in
# the same function body. Enqueueing unconditionally leaks plugin handles into
# unrelated admin pages and breaks the conditional-enqueue contract.
#
# Invoked by:
#   - .githooks/pre-commit  (SCAN_MODE=staged → scans only staged files)
#   - CI lint job           (SCAN_MODE=full or unset → full-repo scan)
#
# Exit codes: 0 = clean or target file not yet present, 1 = violation found.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

MODE="${SCAN_MODE:-full}"

TARGET="includes/admin/assets.php"

# Nothing to check until the asset class lands.
if [ ! -f "$TARGET" ]; then
    echo "check-asset-enqueue: $TARGET not present yet — skipping."
    exit 0
fi

# In staged mode we only care if the target file itself was touched.
if [ "$MODE" = "staged" ]; then
    STAGED=$(git diff --cached --name-only --diff-filter=ACM | grep -E '^includes/admin/assets\.php$' || true)
    if [ -z "$STAGED" ]; then
        echo "check-asset-enqueue: $TARGET not staged — skipping."
        exit 0
    fi
fi

# Use AWK to walk the file function-by-function, tracking brace depth.
# For each function body:
#   - record whether it contains a wp_enqueue_script/style call
#   - record whether it contains a $screen->id comparison
# At function-end (brace depth returns to the pre-function level) report any
# function that enqueues without the guard.
#
# Uses POSIX-only AWK features for portability (no gawk-only match() group capture).

VIOLATIONS=$(awk '
BEGIN {
    in_func       = 0
    func_name     = ""
    func_start    = 0
    brace_depth   = 0
    entry_depth   = 0
    has_enqueue   = 0
    has_screen_id = 0
    violations    = ""
}

# Detect function declaration lines.
/function[[:space:]]+[a-zA-Z0-9_]+/ {
    if (!in_func) {
        # Extract the function name by stripping everything before and after.
        line = $0
        sub(/.*function[[:space:]]+/, "", line)
        sub(/[^a-zA-Z0-9_].*/, "", line)
        func_name     = line
        func_start    = NR
        in_func       = 1
        entry_depth   = brace_depth
        has_enqueue   = 0
        has_screen_id = 0
    }
}

# Count braces — process every line character by character.
{
    n = split($0, chars, "")
    for (i = 1; i <= n; i++) {
        if (chars[i] == "{") brace_depth++
        if (chars[i] == "}") brace_depth--
    }
}

# Check for enqueue calls.
/wp_enqueue_(script|style)/ {
    if (in_func) has_enqueue = 1
}

# Check for $screen->id comparison (===, !==, ==, or switch arm =>).
/\$screen->id/ {
    if (in_func) has_screen_id = 1
}

# When brace depth falls back to the level before the opening brace,
# the function body is closed.
{
    if (in_func && brace_depth <= entry_depth) {
        if (has_enqueue && !has_screen_id) {
            violations = violations func_name "() at line " func_start "\n"
        }
        in_func       = 0
        func_name     = ""
        has_enqueue   = 0
        has_screen_id = 0
    }
}

END {
    printf "%s", violations
}
' "$TARGET")

if [ -n "$VIOLATIONS" ]; then
    echo "check-asset-enqueue: unconditional enqueue found in $TARGET"
    echo "$VIOLATIONS" | while IFS= read -r line; do
        [ -n "$line" ] && echo "  $TARGET: $line — add \$screen->id check before enqueueing"
    done
    echo
    echo "Asset enqueue must be conditional on \$screen->id. See $TARGET."
    exit 1
fi

echo "check-asset-enqueue: clean (${MODE} mode)."
exit 0
