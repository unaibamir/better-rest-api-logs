#!/usr/bin/env bash
# PRIV-04 gate: capture and logger code must make zero outbound network calls.
#
# Grep-scans includes/capture/ and includes/logger/ for any PHP function that
# opens a socket or HTTP connection. Exits 1 on any hit; 0 when clean.
#
# Invoked by:
#   - composer check:no-external-calls   (CI: SCAN_MODE=full)
#   - .githooks/pre-commit               (SCAN_MODE=staged)
#
# Patterns enforced (PRIV-04 / D-23 / PROJECT.md hard constraint):
#   - wp_remote_{get,post,head,request,retrieve_*}(
#   - curl_{init,exec,setopt,setopt_array,multi_init,multi_exec}(
#   - fsockopen(
#   - pfsockopen(
#   - stream_socket_client(
#   - file_get_contents('https?:   or   file_get_contents("https?:
#   - file_get_contents('ftp:      or   file_get_contents("ftp:
#   - fopen('https?:               or   fopen("https?:
#   - fopen('ftp:                  or   fopen("ftp:
#
# Exit codes: 0 = clean, 1 = at least one violation.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

MODE="${SCAN_MODE:-full}"

# Build the file list — mirrors bin/check-banlist.sh exactly.
if [ "$MODE" = "staged" ]; then
    FILES=$(git diff --cached --name-only --diff-filter=ACM | grep -E '\.php$' || true)
    if [ -z "$FILES" ]; then
        echo "no-external-calls: no staged PHP files — skipping."
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
    echo "no-external-calls: no PHP files to scan."
    exit 0
fi

# Narrow the scan to the two directories PRIV-04 protects.
# Phase 4 admin UI may legitimately call wp_remote_get for Site Health checks,
# so we do NOT scan the whole repo — only the hot-path capture/logger code.
SCAN_TARGETS="includes/capture includes/logger"
FILES=$(echo "$FILES" | grep -E "^\./?(includes/capture|includes/logger)/" || true)

if [ -z "$FILES" ]; then
    echo "no-external-calls: clean (${MODE} mode — no files in scan targets yet)."
    exit 0
fi

# Single extended-regex that covers every outbound-network pattern.
REGEX='wp_remote_(get|post|head|request|retrieve_(body|headers|response_code))[[:space:]]*\(|\bcurl_(init|exec|setopt|setopt_array|multi_init|multi_exec)[[:space:]]*\(|\bfsockopen[[:space:]]*\(|\bpfsockopen[[:space:]]*\(|\bstream_socket_client[[:space:]]*\(|\bfile_get_contents[[:space:]]*\([[:space:]]*['"'"'"]https?:|\bfile_get_contents[[:space:]]*\([[:space:]]*['"'"'"]ftp:|\bfopen[[:space:]]*\([[:space:]]*['"'"'"]https?:|\bfopen[[:space:]]*\([[:space:]]*['"'"'"]ftp:'

HITS=$(echo "$FILES" \
    | xargs -r grep -nHE -- "$REGEX" 2>/dev/null \
    | grep -v 'bin/check-no-external-calls\.sh:' \
    | grep -vE '^\s*(//|#|\*)' \
    | grep -vE '^[^:]+:[0-9]+:[[:space:]]*(//|#|\*)' \
    || true)

if [ -n "$HITS" ]; then
    echo "PRIV-04 violation — outbound network call in capture/logger path:"
    echo "$HITS"
    echo
    echo "Captured data must never leave the site (PROJECT.md hard constraint / D-23)."
    echo "Remove the call or move it outside includes/capture/ and includes/logger/."
    exit 1
fi

echo "no-external-calls: clean (${MODE} mode)."
exit 0
