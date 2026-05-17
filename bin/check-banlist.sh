#!/usr/bin/env bash
# Single source of truth for ban-list patterns (CONTEXT.md D-09).
#
# Invoked by:
#   - .githooks/pre-commit  (SCAN_MODE=staged → scans only files staged in git)
#   - CI banlist job        (SCAN_MODE=full or unset → scans entire repo)
#
# Patterns enforced (D-09):
#   1. eval(
#   2. create_function(
#   3. base64_decode(...) adjacent to eval( in the same file
#   4. __return_true as a value for permission_callback
#
# Exit codes: 0 = clean, 1 = at least one pattern hit.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

MODE="${SCAN_MODE:-full}"

# Build the file list.
if [ "$MODE" = "staged" ]; then
    FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$' || true)
    if [ -z "$FILES" ]; then
        echo "banlist: no staged PHP files — skipping."
        exit 0
    fi
else
    # Full-repo scan — exclude vendor, node_modules, build dirs, planning dirs.
    FILES=$(find . -type f -name '*.php' \
        -not -path './vendor/*' \
        -not -path './node_modules/*' \
        -not -path './build/*' \
        -not -path './release/*' \
        -not -path './.planning/*' \
        -not -path './.claude/*' \
        -not -path './.git/*' \
        -not -path './.ddev/*' \
        || true)
fi

if [ -z "$FILES" ]; then
    echo "banlist: no PHP files to scan."
    exit 0
fi

declare -a PATTERNS=(
    'eval\s*\('
    'create_function\s*\('
)

HITS=0

scan_pattern() {
    local pattern="$1"
    local label="$2"
    local matches
    # grep -n: line number. -E: extended regex. -H: filename.
    if matches=$(echo "$FILES" | xargs -r grep -nHE -- "$pattern" 2>/dev/null); then
        # Filter out our own banlist regex declarations (this script + the test fixtures).
        matches=$(echo "$matches" | grep -v 'check-banlist\.sh' | grep -vE 'tests/.*banlist' || true)
        if [ -n "$matches" ]; then
            echo "banlist HIT — ${label}:"
            echo "$matches"
            HITS=$((HITS + 1))
        fi
    fi
}

scan_pattern "${PATTERNS[0]}" "eval()"
scan_pattern "${PATTERNS[1]}" "create_function()"

# Compound: base64_decode(...) inside the same file as eval(.  We grep for both, intersect filenames.
b64_files=$(echo "$FILES" | xargs -r grep -lE 'base64_decode\s*\(' 2>/dev/null | grep -v 'check-banlist\.sh' || true)
eval_files=$(echo "$FILES" | xargs -r grep -lE 'eval\s*\(' 2>/dev/null | grep -v 'check-banlist\.sh' || true)
if [ -n "$b64_files" ] && [ -n "$eval_files" ]; then
    both=$(comm -12 <(echo "$b64_files" | sort -u) <(echo "$eval_files" | sort -u) | grep -v '^$' || true)
    if [ -n "$both" ]; then
        echo "banlist HIT — base64_decode() adjacent to eval() in files:"
        echo "$both"
        HITS=$((HITS + 1))
    fi
fi

# permission_callback with __return_true — REST routes with no auth (both quote variants).
if matches=$(echo "$FILES" | xargs -r grep -nHE "['\"]permission_callback['\"][[:space:]]*=>[[:space:]]*['\"]__return_true['\"]" 2>/dev/null); then
    matches=$(echo "$matches" | grep -v 'check-banlist\.sh' || true)
    if [ -n "$matches" ]; then
        echo "banlist HIT — __return_true used as permission_callback:"
        echo "$matches"
        HITS=$((HITS + 1))
    fi
fi

if [ "$HITS" -gt 0 ]; then
    echo
    echo "banlist: ${HITS} pattern hit(s). Commit blocked."
    exit 1
fi

echo "banlist: clean (${MODE} mode)."
exit 0
