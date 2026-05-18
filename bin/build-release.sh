#!/usr/bin/env bash
# Minimal release builder — Phase 1 scope.
# Phase 7 will harden with version bumping, .pot regen, signing, and SVN deploy hooks.
#
# Produces release/better-rest-api-logs.zip suitable for plugin-check + (eventually) SVN deploy.
# Invoked by:
#   - CI `dist` job (which feeds the artifact into plugin-check)
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
# composer.json + composer.lock so `composer install` can run in the build
# dir, and so the shipped zip has a `composer.json` next to `vendor/`
# (plugin-check requires it; the Yoast pattern).
rsync -a \
    --include='/composer.json' \
    --include='/composer.lock' \
    --exclude-from='.distignore' \
    --exclude='.distignore' \
    --exclude='.git/' \
    ./ "build/${SLUG}/"

# Composer install without dev deps + classmap optimised.
( cd "build/${SLUG}" && composer install --no-dev --classmap-authoritative --no-interaction --no-progress --quiet )

# Strip composer.lock from the shipped zip (no value without composer.json's
# dev section, and plugin-check doesn't need it). Keep composer.json so
# plugin-check sees vendor/ has a manifest.
rm -f "build/${SLUG}/composer.lock"

# Strip ALL hidden files/directories from the build tree (plugin-check rejects
# them: ".gitkeep", ".editorconfig" etc. "Hidden files are not permitted").
# The .distignore catches most of these, but rsync can resurface them via
# parent-dir traversal — sweep defensively.
find "build/${SLUG}" -name '.*' -not -name '.' -not -name '..' -print0 \
    | xargs -0 rm -rf 2>/dev/null || true

( cd build && zip -rq "../release/${SLUG}.zip" "${SLUG}" )

# Restore dev-deps in the source tree so the developer environment isn't left
# with a prod-only vendor/ tree after a local build.
composer install --no-interaction --quiet

echo "Built release/${SLUG}.zip"
