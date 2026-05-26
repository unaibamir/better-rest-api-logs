#!/usr/bin/env bash
# Bidirectional hook coverage gate (HOOK-02, HOOK-03).
#
# Fails with exit 1 if:
#   - any do_action/apply_filters call site for a brl_* hook in includes/ has
#     no matching entry in includes/hooks.php  (undocumented call site)
#   - any Filter/Action entry in includes/hooks.php has no matching call site
#     anywhere in includes/  (phantom / dead documentation)
#
# Exit codes: 0 = clean, 1 = at least one mismatch.

set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
cd "$REPO_ROOT"

# Collect the set of brl_* hook names actually fired in includes/.
fired=$(grep -rhoE "(do_action|apply_filters)\(\s*'brl_[a-z_]+'" includes/ \
        | sed -E "s/.*'(brl_[a-z_]+)'/\1/" \
        | sort -u)

# Collect the set of hook names documented in includes/hooks.php.
documented=$(grep -oE "(Filter|Action): brl_[a-z_]+" includes/hooks.php \
        | sed -E 's/.*(brl_[a-z_]+)/\1/' \
        | sort -u)

if [ -z "$fired" ]; then
    echo "check-hooks-documented: no brl_* call sites found in includes/ — check paths."
    exit 1
fi

if [ -z "$documented" ]; then
    echo "check-hooks-documented: no Filter/Action entries found in includes/hooks.php."
    exit 1
fi

HITS=0

# Fired but not documented → undocumented call site.
undocumented=$(comm -23 <(echo "$fired") <(echo "$documented") || true)
if [ -n "$undocumented" ]; then
    echo "check-hooks-documented: FIRED but not documented in hooks.php:"
    echo "$undocumented" | sed 's/^/  /'
    echo "  → Add a matching Action:/Filter: block for each name above to includes/hooks.php."
    HITS=$((HITS + 1))
fi

# Documented but not fired → phantom hook (dead documentation).
phantom=$(comm -13 <(echo "$fired") <(echo "$documented") || true)
if [ -n "$phantom" ]; then
    echo "check-hooks-documented: DOCUMENTED but never fired in includes/:"
    echo "$phantom" | sed 's/^/  /'
    echo "  → Either add the call site or remove the docblock from includes/hooks.php."
    HITS=$((HITS + 1))
fi

if [ "$HITS" -gt 0 ]; then
    echo
    echo "check-hooks-documented: ${HITS} direction(s) mismatched. Fix before committing."
    exit 1
fi

echo "check-hooks-documented: clean — $(echo "$fired" | wc -l | tr -d ' ') hooks fired and documented."
exit 0
