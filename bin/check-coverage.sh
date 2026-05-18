#!/usr/bin/env bash
# SC #5 gate: PHPUnit line coverage on includes/{domain,support,settings,db}/
# must be ≥ THRESHOLD percent. Threshold defaults to 80.
#
# Invoked by:
#   - composer test:unit:coverage-check
#   - CI (final step of the test job)
#
# Argument: $1 = threshold percentage (e.g., 80). Default 80.
#
# Note: Wave 0 will currently exit non-zero because the covered code (Domain DTOs,
# Settings Registry, etc.) does not yet exist. The gate is wired now; Plan 02-08
# enforces the pass once Plans 02–07 land the production code.
#
# Exit codes: 0 = coverage ≥ threshold, 1 = coverage < threshold or parse failure.

set -euo pipefail

THRESHOLD="${1:-80}"

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

if [ ! -x vendor/bin/phpunit ]; then
    echo "coverage: vendor/bin/phpunit not found — run 'composer install' first." >&2
    exit 1
fi

# Run unit suite with coverage-text. Let it fail silently — we parse output either way.
COVERAGE_OUTPUT=$(vendor/bin/phpunit --testsuite=unit --coverage-text 2>&1 || true)

# PHPUnit emits ANSI colour codes in coverage-text output even when stdout is
# not a TTY; strip them before grepping. Pattern matches CSI sequences like
# `^[[1;37;40m` and `^[[0m`.
COVERAGE_STRIPPED=$(echo "$COVERAGE_OUTPUT" | sed -E $'s/\033\\[[0-9;]*m//g')

# Parse the first "Lines:  XX.XX%" summary line (skip per-class breakdown).
LINE_PCT=$(echo "$COVERAGE_STRIPPED" \
    | grep -E '^[[:space:]]*Lines:[[:space:]]+[0-9.]+%' \
    | head -n1 \
    | sed -E 's/.*Lines:[[:space:]]*([0-9.]+)%.*/\1/')

if [ -z "$LINE_PCT" ]; then
    echo "coverage: could not parse 'Lines: XX.XX%' from phpunit output." >&2
    echo "          (no coverage driver active, or unit suite errored out)" >&2
    echo "          threshold was ${THRESHOLD}% — FAIL"
    exit 1
fi

if awk -v p="$LINE_PCT" -v t="$THRESHOLD" 'BEGIN { exit (p+0 >= t+0) ? 0 : 1 }'; then
    echo "coverage: ${LINE_PCT}% >= ${THRESHOLD}% — PASS"
    exit 0
fi

echo "coverage: ${LINE_PCT}% < ${THRESHOLD}% — FAIL"
exit 1
