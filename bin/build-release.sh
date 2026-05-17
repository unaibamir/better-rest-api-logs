#!/usr/bin/env bash
# Minimal release builder — Phase 1 scope.
# Phase 7 will harden with version bumping, .pot regen, signing, and SVN deploy hooks.
#
# Produces release/better-rest-api-logs.zip suitable for plugin-check + (eventually) SVN deploy.
# Invoked by:
#   - Plan 06 CI `dist` job (which feeds the artifact into plugin-check)
#   - Local developer smoke-tests
#
# The script restores dev-deps in the source tree after building, so the working
# environment is never left with a prod-only vendor/ tree.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

SLUG="better-rest-api-logs"

rm -rf build release
mkdir -p "build/${SLUG}" release

# Rsync everything that .distignore does NOT exclude — but force-include
# composer.json + composer.lock temporarily so `composer install` can run in
# the build dir. They are stripped after install completes, per STACK.md
# "What NOT to Use" (composer.json must NOT ship in the WP.org zip).
rsync -a \
    --include='/composer.json' \
    --include='/composer.lock' \
    --exclude-from='.distignore' \
    --exclude='.distignore' \
    --exclude='.git/' \
    ./ "build/${SLUG}/"

# Composer install without dev deps + classmap optimised.
( cd "build/${SLUG}" && composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet )

# Strip composer.json + composer.lock from the shipped zip per STACK.md "What NOT to Use".
rm -f "build/${SLUG}/composer.json" "build/${SLUG}/composer.lock"

( cd build && zip -rq "../release/${SLUG}.zip" "${SLUG}" )

# Restore dev-deps in the source tree so the developer environment isn't left
# with a prod-only vendor/ tree after a local build.
composer install --no-interaction --quiet

echo "Built release/${SLUG}.zip"
