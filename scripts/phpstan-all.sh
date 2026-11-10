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
SCANNED=0
SKIPPED=()

# ── Worktree-aware resolution, same shape as arch-guardrails.sh (#203) ───────
#
# PLUGINS_DIR assumes a sibling directory named after each plugin. In a git
# worktree the checkout root IS the plugin and is named after the worktree, so
# that path does not exist — every plugin then "skipped", and this script still
# printed PASS. The plugin's own entry file is what identifies it.
plugin_root() {
    local plugin="$1"

    if [[ -d "$PLUGINS_DIR/$plugin" ]]; then
        printf '%s\n' "$PLUGINS_DIR/$plugin"
        return 0
    fi

    local self_root
    self_root="$(cd "$SCRIPT_DIR/.." && pwd)"
    if [[ -f "$self_root/$plugin.php" ]]; then
        printf '%s\n' "$self_root"
        return 0
    fi

    return 1
}

for plugin in "${PLUGINS[@]}"; do
    if ! dir="$(plugin_root "$plugin")"; then
        SKIPPED+=("$plugin")
        printf "${YELLOW}SKIP${NC}  %-40s (plugin not found)\n" "$plugin"
        continue
    fi

    config="$dir/phpstan.neon"

    # ⚠ BOTH SPELLINGS. composer installs `vendor/bin/phpstan`; the `.phar`
    # alias is not always there. Looking only for `.phar` made this script skip
    # a plugin whose PHPStan was installed and working — and then report PASS.
    phpstan=""
    for candidate in "$dir/vendor/bin/phpstan" "$dir/vendor/bin/phpstan.phar"; do
        [[ -f "$candidate" ]] && { phpstan="$candidate"; break; }
    done

    if [[ -z "$phpstan" || ! -f "$config" ]]; then
        SKIPPED+=("$plugin")
        printf "${YELLOW}SKIP${NC}  %-40s (no phpstan installation)\n" "$plugin"
        continue
    fi

    SCANNED=$((SCANNED + 1))
    printf "→ %-40s " "$plugin"
    # `-1`, not 2G: at a bounded limit the parallel worker can die and report a
    # *severe error* with an INCOMPLETE result, which does not read as a type
    # error. See docs/running-phpstan-locally.md.
    output="$(cd "$dir" && php "$phpstan" analyse --memory-limit=-1 --no-progress 2>&1)"
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
# ⚠ ORDER MATTERS. "no plugin reported errors" is also true of a run that
# analysed NOTHING, so that case is tested FIRST. This script used to print
# PASS and exit 0 after skipping every plugin — the same false-green class
# as #203, in the PHPStan runner.
if [[ $SCANNED -eq 0 ]]; then
    echo -e "${RED}FAIL${NC}: analysed NOTHING — ${#SKIPPED[@]} plugin(s) skipped"
    echo    "       A run that analysed no plugin is not a pass. Install the dev"
    echo    "       dependencies (see docs/running-phpstan-locally.md), or run from"
    echo    "       the plugins layout."
elif [[ $FAILED -eq 0 ]]; then
    echo -e "${GREEN}PASS${NC}: $SCANNED plugin(s) clean at their configured PHPStan level"
else
    echo -e "${RED}FAIL${NC}: $FAILED of $SCANNED plugin(s) reported errors"
fi
if [[ ${#SKIPPED[@]} -gt 0 ]]; then
    echo -e "${YELLOW}Skipped${NC}: ${SKIPPED[*]}"
fi
echo "──────────────────────────────────────────"

# Non-zero when a plugin reported errors OR when nothing was analysed at all.
exit $( [[ $FAILED -eq 0 && $SCANNED -gt 0 ]] && echo 0 || echo 1 )
