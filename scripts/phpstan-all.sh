#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────────────────
# BCC PHPStan — run static analysis across all BCC plugins.
#
# Each plugin has its own vendor/bin/phpstan.phar and phpstan.neon. This
# script invokes them in sequence and fails fast on the first plugin that
# reports errors, so CI / pre-commit stop at the real offender rather than
# churning through the rest.
#
# Usage:
#   bash scripts/phpstan-all.sh              # all plugins
#   bash scripts/phpstan-all.sh bcc-core     # single plugin
# ──────────────────────────────────────────────────────────────────────────────

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGINS_DIR="$(cd "$SCRIPT_DIR/../../" && pwd)"

# All BCC plugins. Keep in sync with arch-guardrails.sh.
ALL_PLUGINS=(
    bcc-core
    bcc-trust
    bcc-search
    blue-collar-crypto-peepso-integration
)

TARGET="${1:-}"
if [[ -n "$TARGET" ]]; then
    PLUGINS=("$TARGET")
else
    PLUGINS=("${ALL_PLUGINS[@]}")
fi

# ── Colours ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; NC=''
fi

FAILED=0
SKIPPED=()

for plugin in "${PLUGINS[@]}"; do
    dir="$PLUGINS_DIR/$plugin"
    phpstan="$dir/vendor/bin/phpstan.phar"
    config="$dir/phpstan.neon"

    if [[ ! -f "$phpstan" || ! -f "$config" ]]; then
        SKIPPED+=("$plugin")
        printf "${YELLOW}SKIP${NC}  %-40s (no phpstan installation)\n" "$plugin"
        continue
    fi

    printf "→ %-40s " "$plugin"
    output="$(cd "$dir" && php vendor/bin/phpstan.phar analyse --memory-limit=2G --no-progress 2>&1)"
    if echo "$output" | grep -q "\[OK\] No errors"; then
        printf "${GREEN}PASS${NC}\n"
    else
        printf "${RED}FAIL${NC}\n"
        echo "$output"
        FAILED=$((FAILED + 1))
    fi
done

echo ""
echo "──────────────────────────────────────────"
if [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}PASS${NC}: all plugins clean at their configured PHPStan level"
else
    echo -e "${RED}FAIL${NC}: $FAILED plugin(s) reported errors"
fi
if [[ ${#SKIPPED[@]} -gt 0 ]]; then
    echo -e "${YELLOW}Skipped${NC}: ${SKIPPED[*]}"
fi
echo "──────────────────────────────────────────"

exit $FAILED
