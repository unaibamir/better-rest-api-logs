#!/usr/bin/env bash
# STOR-04 gate: {$wpdb->prefix} . 'brl_*' table-name interpolation must only appear
# in includes/db/database.php (the centralized accessor) and uninstall.php (which
# cannot autoload plugin classes). Any hit elsewhere fails the build.
#
# Invoked by:
#   - composer check:table-naming   (CI: SCAN_MODE=full)
#   - .githooks/pre-commit          (SCAN_MODE=staged)
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
        echo "table-naming: no staged PHP files — skipping."
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
    echo "table-naming: no PHP files to scan."
    exit 0
fi

# Grep for `$wpdb->prefix . 'brl_` or `$wpdb->prefix . "brl_` (with optional whitespace
# around the concat operator). The Database accessor and uninstall.php are allowed.
# Tests may reference the pattern in fixture strings — strip tests/ too.
HITS=$(echo "$FILES" \
    | xargs -r grep -lE '\$wpdb->prefix[[:space:]]*\.[[:space:]]*['"'"'"]brl_' 2>/dev/null \
    | grep -v 'includes/db/database\.php$' \
    | grep -v 'uninstall\.php$' \
    | grep -v '^\./tests/' \
    | grep -v 'check-table-naming\.sh' \
    || true)

if [ -n "$HITS" ]; then
    echo "table-naming HIT — \$wpdb->prefix . 'brl_*' interpolation outside Database accessor (STOR-04):"
    echo "$HITS"
    echo
    echo "Compose table names via BetterRestApiLogs\\DB\\Database::logs_table() / bodies_table() instead."
    exit 1
fi

echo "table-naming: clean (${MODE} mode)."
exit 0
